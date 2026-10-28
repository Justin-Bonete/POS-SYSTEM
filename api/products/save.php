<?php
// api/products/save.php
// Handles create/update from views/products/add.php (multipart/form-data) and
// guarantees a barcode/product_code exists by generating one when missing.

// Prevent PHP warnings/notices from corrupting JSON (they often start with "<br />...")
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';

function json_fail($message, $httpCode = 400) {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function table_has_column(PDO $db, $table, $column) {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function should_expose_error_details() {
    $debug = getenv('POS_DEBUG');
    if ($debug !== false && $debug !== '' && $debug !== '0') {
        return true;
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '127.0.0.1' || $remote === '::1') {
        return true;
    }

    if (function_exists('isAdmin') && isAdmin()) {
        return true;
    }

    return false;
}

function ensure_image_path_column(PDO $db) {
    static $attempted = false;

    if ($attempted) {
        return table_has_column($db, 'products', 'image_path');
    }

    $attempted = true;

    if (table_has_column($db, 'products', 'image_path')) {
        return true;
    }

    try {
        $db->exec("ALTER TABLE products ADD COLUMN image_path VARCHAR(255) NULL AFTER wholesale_price");
    } catch (Throwable $e) {
        error_log('Failed to add products.image_path column: ' . $e->getMessage());
    }

    return table_has_column($db, 'products', 'image_path');
}

function normalize_name_for_code($name) {
    $name = strtoupper((string)$name);
    $name = preg_replace('/[^A-Z0-9\s]/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return $name;
}

function build_name_code_prefix($name) {
    $normalized = normalize_name_for_code($name);
    if ($normalized === '') {
        return 'PRD';
    }

    $words = array_values(array_filter(explode(' ', $normalized)));
    $prefix = '';

    if (!empty($words[0])) {
        $prefix .= substr($words[0], 0, 2);
    }
    if (!empty($words[1])) {
        $prefix .= substr($words[1], 0, 1);
    }

    $compact = preg_replace('/[^A-Z0-9]/', '', $normalized);
    $i = 0;
    while (strlen($prefix) < 3 && $i < strlen($compact)) {
        $char = $compact[$i];
        if (strpos($prefix, $char) === false || strlen($prefix) < 2) {
            $prefix .= $char;
        }
        $i++;
    }

    return substr(str_pad($prefix, 3, 'X'), 0, 3);
}

function build_name_code_candidate($name, $attempt = 0) {
    $prefix = build_name_code_prefix($name);
    $hashBase = normalize_name_for_code($name) . '|' . $attempt;
    $hash = strtoupper(base_convert(sprintf('%u', crc32($hashBase)), 10, 36));
    $suffix = substr(str_pad($hash, 3, '0', STR_PAD_LEFT), -3);
    return substr($prefix . $suffix, 0, 6);
}

function generate_product_code(PDO $db, $name = '', $maxAttempts = 100) {
    for ($i = 0; $i < $maxAttempts; $i++) {
        $candidate = build_name_code_candidate($name, $i);

        $stmt = $db->prepare('SELECT id FROM products WHERE product_code = :code LIMIT 1');
        $stmt->execute([':code' => $candidate]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            return $candidate;
        }
    }

    json_fail('Failed to generate unique product code. Please try again.', 500);
}

function normalize_str($v) {
    return trim((string)($v ?? ''));
}

function parse_decimal($v) {
    $v = normalize_str($v);
    if ($v === '') return null;
    $clean = preg_replace('/[^0-9.\-]/', '', $v);
    if ($clean === '' || $clean === '-' || $clean === '.' || $clean === '-.') return null;
    return (float)$clean;
}

function parse_int($v) {
    $v = normalize_str($v);
    if ($v === '') return null;
    return (int)$v;
}

function current_user_id() {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    return $_SESSION['user_id'] ?? null;
}

function values_differ_for_history($oldValue, $newValue, $field) {
    if (in_array($field, ['price', 'cost_price', 'wholesale_price'], true)) {
        return (float)($oldValue ?? 0) != (float)($newValue ?? 0);
    }

    if ($field === 'quantity') {
        return (int)($oldValue ?? 0) !== (int)($newValue ?? 0);
    }

    return normalize_str($oldValue) !== normalize_str($newValue);
}

function format_history_value($value, $field) {
    if ($value === null || $value === '') {
        return '(blank)';
    }

    if (in_array($field, ['price', 'cost_price', 'wholesale_price'], true)) {
        $number = (float)$value;
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    if ($field === 'quantity') {
        return (string)(int)$value;
    }

    if ($field === 'image_path') {
        return $value ? 'changed' : '(none)';
    }

    return normalize_str($value);
}

function build_product_change_notes(array $oldProduct, array $newProduct, array $fields) {
    $labels = [
        'product_code' => 'product code',
        'name' => 'name',
        'description' => 'description',
        'price' => 'selling price',
        'quantity' => 'quantity',
        'category' => 'category',
        'cost_price' => 'cost price',
        'wholesale_price' => 'wholesale price',
        'status' => 'status',
        'image_path' => 'image',
    ];

    $changed = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $oldProduct) && array_key_exists($field, $newProduct)
            && values_differ_for_history($oldProduct[$field], $newProduct[$field], $field)) {
            $label = $labels[$field] ?? $field;
            $oldValue = format_history_value($oldProduct[$field], $field);
            $newValue = format_history_value($newProduct[$field], $field);
            $changed[] = "{$label}: {$oldValue} -> {$newValue}";
        }
    }

    if (empty($changed)) {
        return null;
    }

    return 'Product updated: ' . implode(', ', $changed);
}

function log_product_history(PDO $db, $productId, $actionType, array $oldProduct = null, array $newProduct = null, $notes = null) {
    try {
        $colsStmt = $db->query("SHOW COLUMNS FROM product_history");
        $historyColumns = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
        if (empty($historyColumns)) {
            return false;
        }

        $oldQty = $oldProduct ? (int)($oldProduct['quantity'] ?? 0) : 0;
        $newQty = $newProduct ? (int)($newProduct['quantity'] ?? 0) : 0;
        $quantityChange = $newQty - $oldQty;

        $insertCols = [];
        $placeholders = [];
        $params = [];

        $add = function ($column, $placeholder, $value) use (&$insertCols, &$placeholders, &$params, $historyColumns) {
            if (in_array($column, $historyColumns, true)) {
                $insertCols[] = $column;
                $placeholders[] = $placeholder;
                if ($placeholder[0] === ':') {
                    $params[$placeholder] = $value;
                }
            }
        };

        $add('product_id', ':product_id', $productId);
        $add('action_type', ':action_type', $actionType);
        $add('quantity_change', ':quantity_change', $quantityChange);
        $add('previous_quantity', ':previous_quantity', $oldQty);
        $add('new_quantity', ':new_quantity', $newQty);
        $add('reference_type', ':reference_type', 'manual');
        $add('reference_id', ':reference_id', null);
        $add('notes', ':notes', $notes);
        $add('old_data', ':old_data', $oldProduct ? json_encode($oldProduct) : null);
        $add('new_data', ':new_data', $newProduct ? json_encode($newProduct) : null);
        $add('user_id', ':user_id', current_user_id());

        if (in_array('created_at', $historyColumns, true)) {
            $insertCols[] = 'created_at';
            $placeholders[] = 'NOW()';
        }

        if (empty($insertCols)) {
            return false;
        }

        $sql = 'INSERT INTO product_history (' . implode(', ', $insertCols) . ')
                VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('Product history log failed: ' . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Invalid request method', 405);
}

$db = getDB();
if (!$db) {
    json_fail('Database connection failed', 500);
}

// Optional auth guard (prevents unauthenticated writes if the app uses sessions).
require_once __DIR__ . '/../../includes/auth.php';
if (!isLoggedIn()) {
    json_fail('Unauthorized', 401);
}
$requestId = null;
try {
    $requestId = bin2hex(random_bytes(8));
} catch (Throwable $e) {
    $requestId = substr(md5(uniqid('', true)), 0, 16);
}

$supportsImagePath = table_has_column($db, 'products', 'image_path');
$supportsStatus = table_has_column($db, 'products', 'status');
$supportsUpdatedAt = table_has_column($db, 'products', 'updated_at');
$supportsCostPrice = table_has_column($db, 'products', 'cost_price');
$supportsWholesalePrice = table_has_column($db, 'products', 'wholesale_price');

$id = parse_int($_POST['id'] ?? null);
$originalQuantity = parse_int($_POST['original_quantity'] ?? null);
if (!canAccess($id ? 'products_edit' : 'products_add')) {
    json_fail('Forbidden', 403);
}
$name = normalize_str($_POST['name'] ?? null);
$description = normalize_str($_POST['description'] ?? null);
$category = normalize_str($_POST['category'] ?? null);
$status = normalize_str($_POST['status'] ?? 'active');
$productCode = normalize_str($_POST['product_code'] ?? null);

$price = parse_decimal($_POST['price'] ?? null);
$quantity = parse_int($_POST['quantity'] ?? null);
$costPrice = parse_decimal($_POST['cost_price'] ?? null);
$wholesalePrice = parse_decimal($_POST['wholesale_price'] ?? null);

if ($name === '') json_fail('Product name is required');
if ($price === null || $price <= 0) json_fail('Selling price must be greater than 0');
if ($quantity === null || $quantity < 0) json_fail('Quantity cannot be negative');
if ($costPrice !== null && $costPrice < 0) json_fail('Cost price cannot be negative');
if ($wholesalePrice !== null && $wholesalePrice < 0) json_fail('Wholesale price cannot be negative');
if ($status !== 'active' && $status !== 'inactive') $status = 'active';

// Optional image upload.
$imagePath = null;
if (isset($_FILES['image']) && is_array($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    if (!$supportsImagePath) {
        $supportsImagePath = ensure_image_path_column($db);
    }

    if (!$supportsImagePath) {
        json_fail('Image uploads are unavailable because the image column could not be created', 500);
    }

    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_fail('Image upload failed');
    }

    $originalName = (string)($_FILES['image']['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        json_fail('Invalid image type. Allowed: jpg, jpeg, png, gif, webp');
    }

    $uploadDir = __DIR__ . '/../../assets/images/products';
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true)) {
        json_fail('Failed to create upload directory', 500);
    }

    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Throwable $e) {
        $suffix = substr(md5(uniqid('', true)), 0, 8);
    }
    $fileBase = 'product_' . date('Ymd_His') . '_' . $suffix;
    $targetFs = $uploadDir . '/' . $fileBase . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetFs)) {
        json_fail('Failed to save uploaded image', 500);
    }

    // Stored as a path relative to /pos-system, as expected by views/products/add.php
    $imagePath = str_replace('\\', '/', 'assets/images/products/' . basename($targetFs));
}

try {
    if ($id) {
        if ($originalQuantity === null || $originalQuantity < 0) {
            json_fail('Reload the product before saving it so its current stock can be verified.', 409);
        }

        // Update path
        $check = $db->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $check->execute([':id' => $id]);
        $oldProduct = $check->fetch(PDO::FETCH_ASSOC);
        if (!$oldProduct) {
            json_fail('Product not found', 404);
        }

        if ($productCode === '') {
            $productCode = generate_product_code($db, $name);
        } else {
            // Ensure uniqueness if user edited barcode.
            $dup = $db->prepare('SELECT id FROM products WHERE product_code = :code AND id <> :id LIMIT 1');
            $dup->execute([':code' => $productCode, ':id' => $id]);
            if ($dup->fetch(PDO::FETCH_ASSOC)) {
                json_fail('Product code already exists. Please use a unique barcode.');
            }
        }

        $fields = [
            'product_code' => $productCode,
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'quantity' => $quantity,
            'category' => ($category === '' ? null : $category),
        ];
        if ($supportsCostPrice && $costPrice !== null) {
            $fields['cost_price'] = $costPrice;
        }
        if ($supportsWholesalePrice && $wholesalePrice !== null) {
            $fields['wholesale_price'] = $wholesalePrice;
        }
        if ($supportsStatus) {
            $fields['status'] = $status;
        }

        $setParts = [];
        $params = [':id' => $id];
        foreach ($fields as $col => $val) {
            $setParts[] = "$col = :$col";
            $params[":$col"] = $val;
        }

        if ($supportsImagePath && $imagePath !== null) {
            $setParts[] = "image_path = :image_path";
            $params[':image_path'] = $imagePath;
        }

        if ($supportsUpdatedAt) {
            $setParts[] = "updated_at = NOW()";
        }

        $sql = 'UPDATE products SET ' . implode(', ', $setParts) . ' WHERE id = :id AND quantity = :original_quantity';
        $params[':original_quantity'] = $originalQuantity;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $currentQuantityStmt = $db->prepare('SELECT quantity FROM products WHERE id = :id LIMIT 1');
            $currentQuantityStmt->execute([':id' => $id]);
            $currentQuantity = $currentQuantityStmt->fetchColumn();
            if ($currentQuantity === false) {
                json_fail('Product not found', 404);
            }
            if ((int) $currentQuantity !== $originalQuantity) {
                json_fail('Stock changed on another workstation. Reload the product and review the current quantity before saving.', 409);
            }
        }

        $afterStmt = $db->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $afterStmt->execute([':id' => $id]);
        $updatedProduct = $afterStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $historyFields = array_keys($fields);
        if ($supportsImagePath && $imagePath !== null) {
            $historyFields[] = 'image_path';
        }
        $historyNotes = build_product_change_notes($oldProduct, $updatedProduct, $historyFields);
        if ($historyNotes !== null) {
            log_product_history($db, $id, 'update', $oldProduct, $updatedProduct, $historyNotes);
        }

        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode([
            'success' => true,
            'message' => 'Product updated successfully',
            'product_code' => $productCode,
            'id' => $id
        ]);
        exit;
    }

    // Create path
    if ($productCode === '') {
        $productCode = generate_product_code($db, $name);
    } else {
        $dup = $db->prepare('SELECT id FROM products WHERE product_code = :code LIMIT 1');
        $dup->execute([':code' => $productCode]);
        if ($dup->fetch(PDO::FETCH_ASSOC)) {
            json_fail('Product code already exists. Please use a unique barcode.');
        }
    }

    $cols = ['product_code', 'name', 'description', 'price', 'quantity', 'category'];
    $placeholders = [':product_code', ':name', ':description', ':price', ':quantity', ':category'];
    $params = [
        ':product_code' => $productCode,
        ':name' => $name,
        ':description' => ($description === '' ? null : $description),
        ':price' => $price,
        ':quantity' => $quantity,
        ':category' => ($category === '' ? null : $category),
    ];

    if ($supportsCostPrice) {
        $cols[] = 'cost_price';
        $placeholders[] = ':cost_price';
        $params[':cost_price'] = ($costPrice === null ? 0 : $costPrice);
    }
    if ($supportsWholesalePrice) {
        $cols[] = 'wholesale_price';
        $placeholders[] = ':wholesale_price';
        $params[':wholesale_price'] = ($wholesalePrice === null ? 0 : $wholesalePrice);
    }

    if ($supportsImagePath) {
        $cols[] = 'image_path';
        $placeholders[] = ':image_path';
        $params[':image_path'] = $imagePath;
    }
    if ($supportsStatus) {
        $cols[] = 'status';
        $placeholders[] = ':status';
        $params[':status'] = $status;
    }

    $sql = 'INSERT INTO products (' . implode(', ', $cols) . ')
            VALUES (' . implode(', ', $placeholders) . ')';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $newProductId = (int)$db->lastInsertId();

    $newStmt = $db->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
    $newStmt->execute([':id' => $newProductId]);
    $newProduct = $newStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    log_product_history($db, $newProductId, 'create', null, $newProduct, 'Product created');

    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode([
        'success' => true,
        'message' => 'Product created successfully',
        'product_code' => $productCode,
        'id' => $newProductId
    ]);
} catch (PDOException $e) {
    error_log('Product save error [' . $requestId . ']: ' . $e->getMessage());
    if (should_expose_error_details()) {
        json_fail('Database error while saving product: ' . $e->getMessage() . ' (Ref: ' . $requestId . ')', 500);
    }
    json_fail('Database error while saving product (Ref: ' . $requestId . '). Check the server error log for details.', 500);
} catch (Throwable $e) {
    error_log('Product save fatal [' . $requestId . ']: ' . $e->getMessage());
    json_fail('Server error while saving product (Ref: ' . $requestId . ')', 500);
}
