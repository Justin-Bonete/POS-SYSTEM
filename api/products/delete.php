<?php
// api/products/delete.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/database.php';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit();
}

require_once __DIR__ . '/../../includes/auth.php';
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canAccess('products_delete')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$db = getDB();

function isTruthy($value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value === 1;
    if (is_string($value)) {
        $v = strtolower(trim($value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
    return false;
}

function normalizeIds($rawIds): array {
    if (is_string($rawIds)) {
        $rawIds = trim($rawIds);
        if ($rawIds === '') {
            return [];
        }

        $decoded = json_decode($rawIds, true);
        if (is_array($decoded)) {
            $rawIds = $decoded;
        } else {
            $rawIds = explode(',', $rawIds);
        }
    }

    if (!is_array($rawIds)) {
        return [];
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function buildInPlaceholders(array $ids): string {
    return implode(',', array_fill(0, count($ids), '?'));
}

function tableExists(PDO $db, string $tableName): bool {
    try {
        $stmt = $db->prepare("SHOW TABLES LIKE :t");
        $stmt->execute([':t' => $tableName]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Ensure the products table has a deleted_at column.
 * Returns true if the column exists or was successfully created.
 */
function ensureDeletedAtColumnExists(PDO $db): bool {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
        if ($stmt && $stmt->fetch()) {
            return true;
        }
        $db->exec("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL AFTER updated_at");
        return true;
    } catch (PDOException $e) {
        error_log("Could not ensure deleted_at column exists: " . $e->getMessage());
        return false;
    }
}

// Get DELETE data (support JSON body and query parameters)
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);
if (!is_array($data)) {
    $data = [];
}

// Also support query parameters for DELETE (some clients don't send a body)
$data['all'] = $data['all'] ?? ($_REQUEST['all'] ?? null);
$data['id'] = $data['id'] ?? ($_REQUEST['id'] ?? null);
$data['ids'] = $data['ids'] ?? ($_REQUEST['ids'] ?? null);
$data['permanent'] = $data['permanent'] ?? ($_REQUEST['permanent'] ?? null);

// Determine scope: single product or all products
$all = isset($data['all']) && ($data['all'] === true || $data['all'] === '1' || $data['all'] === 1);

// Check if ID is provided when not deleting all
$targetIds = [];
if (!$all) {
    $targetIds = normalizeIds($data['ids'] ?? null);

    if (empty($targetIds)) {
        $singleId = isset($data['id']) ? (int) $data['id'] : 0;
        if ($singleId > 0) {
            $targetIds = [$singleId];
        }
    }

    if (empty($targetIds)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Product ID is required'
        ]);
        exit();
    }
}

try {
    // Start transaction
    $db->beginTransaction();

    $permanent = isset($data['permanent']) && isTruthy($data['permanent']);

    // Ensure deleted_at column exists for soft delete operations
    $hasDeletedAt = ensureDeletedAtColumnExists($db);

    $products = [];
    $product = null;

    if (!$all) {
        // Check if product exists and can be deleted
        $placeholders = buildInPlaceholders($targetIds);
        $checkQuery = "SELECT * FROM products WHERE id IN ($placeholders)";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute($targetIds);

        $products = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($products) === 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Product not found'
            ]);
            if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
            exit();
        }

        $foundIds = array_map('intval', array_column($products, 'id'));
        $missingIds = array_values(array_diff($targetIds, $foundIds));
        if (!empty($missingIds)) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Some selected products were not found: ' . implode(', ', $missingIds)
            ]);
            if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
            exit();
        }

        if (count($products) === 1) {
            $product = $products[0];
        }
    }

    // Option 1: Hard delete (permanent deletion)
    $deleteParams = [];
    if ($permanent) {
        if ($all) {
            // Remove dependent data that would block product deletion (e.g., sale_items FK)
            if (tableExists($db, 'sale_items')) {
                $db->exec("DELETE FROM sale_items");
            }
            if (tableExists($db, 'stock_additions')) {
                $db->exec("DELETE FROM stock_additions");
            }
            if (tableExists($db, 'product_history')) {
                $db->exec("DELETE FROM product_history");
            }

            $deleteQuery = "DELETE FROM products";
            $message = 'All products permanently deleted';
        } else {
            $placeholders = buildInPlaceholders($targetIds);
            if (tableExists($db, 'sale_items')) {
                $stmt = $db->prepare("DELETE FROM sale_items WHERE product_id IN ($placeholders)");
                $stmt->execute($targetIds);
            }
            if (tableExists($db, 'stock_additions')) {
                $stmt = $db->prepare("DELETE FROM stock_additions WHERE product_id IN ($placeholders)");
                $stmt->execute($targetIds);
            }
            if (tableExists($db, 'product_history')) {
                $stmt = $db->prepare("DELETE FROM product_history WHERE product_id IN ($placeholders)");
                $stmt->execute($targetIds);
            }
            $deleteQuery = "DELETE FROM products WHERE id IN ($placeholders)";
            $deleteParams = $targetIds;
            $message = count($targetIds) === 1
                ? 'Product permanently deleted'
                : count($targetIds) . ' products permanently deleted';
        }
    }
    // Option 2: Soft delete (just mark as inactive)
    else {
        if ($all) {
            if ($hasDeletedAt) {
                $deleteQuery = "UPDATE products SET status = 'inactive', deleted_at = NOW()";
            } else {
                $deleteQuery = "UPDATE products SET status = 'inactive'";
            }
            $message = 'All products deactivated successfully';
        } else {
            $placeholders = buildInPlaceholders($targetIds);
            if ($hasDeletedAt) {
                $deleteQuery = "UPDATE products SET status = 'inactive', deleted_at = NOW() WHERE id IN ($placeholders)";
            } else {
                $deleteQuery = "UPDATE products SET status = 'inactive' WHERE id IN ($placeholders)";
            }
            $deleteParams = $targetIds;
            $message = count($targetIds) === 1
                ? 'Product deactivated successfully'
                : count($targetIds) . ' products deactivated successfully';
        }
    }

    $deleteStmt = $db->prepare($deleteQuery);
    
    if ($deleteStmt->execute($deleteParams)) {
        $db->commit();
        $response = [
            'success' => true,
            'message' => $message,
        ];
        if (!$all && $product) {
            $response['product'] = $product;
        }
        if (!$all && count($products) > 1) {
            $response['products'] = $products;
        }
        echo json_encode($response);
    } else {
        if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
        echo json_encode(['success' => false, 'message' => 'Failed to delete product']);
    }
    
} catch(PDOException $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
