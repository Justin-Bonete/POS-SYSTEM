<?php
/**
 * Product Management Page - Add/Edit with Stock Search + Bulk Import
 * Resizable split view: form on left, product list on right.
 * Now includes Cost Price and Wholesale Price.
 */

// -------------------------------------------------------------------
// 1. Initial Setup & Authentication
// -------------------------------------------------------------------
require_once __DIR__ . '/../../includes/auth.php';
requireAccess('products');

require_once __DIR__ . '/../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Category.php';

// Load PhpSpreadsheet (Composer autoload)
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$product = new Product();
$category = new Category();

// Get all categories for the constant dropdown in import modal
$allCategories = $category->getAll();
$categoryNames = [];
foreach ($allCategories as $cat) {
    $categoryNames[] = is_array($cat) ? $cat['name'] : $cat;
}

// -------------------------------------------------------------------
// 2. AJAX Handlers
// -------------------------------------------------------------------

// Handler: Get product by barcode (product_code) or by ID (active products only)
if (isset($_GET['action']) && $_GET['action'] === 'get_by_code') {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    $code = $_GET['code'] ?? '';
    $byId = isset($_GET['by_id']) && $_GET['by_id'] == '1';
    if (empty($code)) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'No code provided']);
        exit;
    }
    if ($byId) {
        // Fetch by ID
        $productId = (int)$code;
        $products = $product->getAll(['id' => $productId, 'status' => 'active'], 1, 1);
        if (!empty($products)) {
            $prod = $products[0];
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => true,
                'product' => [
                    'id'               => $prod['id'],
                    'name'             => $prod['name'],
                    'product_code'     => $prod['product_code'],
                    'price'            => $prod['price'],
                    'cost_price'       => $prod['cost_price'] ?? 0,
                    'wholesale_price'  => $prod['wholesale_price'] ?? 0,
                    'quantity'         => $prod['quantity'],
                    'category'         => $prod['category'] ?? '',
                    'image_path'       => $prod['image_path'] ?? '',
                    'status'           => $prod['status'] ?? 'active'
                ]
            ]);
        } else {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Product not found']);
        }
    } else {
        // Search by product_code
        $products = $product->getAll(['product_code' => $code, 'status' => 'active'], 1, 1);
        if (!empty($products)) {
            $prod = $products[0];
            if (ob_get_length()) ob_clean();
            echo json_encode([
                'success' => true,
                'product' => [
                    'id'               => $prod['id'],
                    'name'             => $prod['name'],
                    'product_code'     => $prod['product_code'],
                    'price'            => $prod['price'],
                    'cost_price'       => $prod['cost_price'] ?? 0,
                    'wholesale_price'  => $prod['wholesale_price'] ?? 0,
                    'quantity'         => $prod['quantity'],
                    'category'         => $prod['category'] ?? '',
                    'image_path'       => $prod['image_path'] ?? '',
                    'status'           => $prod['status'] ?? 'active'
                ]
            ]);
        } else {
            if (ob_get_length()) ob_clean();
            echo json_encode(['success' => false, 'message' => 'Product not found']);
        }
    }
    exit;
}

// Handler: Add a new category (used by main form and import modal)
if (isset($_GET['action']) && $_GET['action'] === 'add_category') {
    header('Content-Type: application/json');
    if (!canAccess('categories_add')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to add categories']);
        exit;
    }
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    $categoryName = trim($_POST['name'] ?? '');
    if (empty($categoryName)) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Category name is required']);
        exit;
    }

    // Check if category already exists (case‑insensitive)
    $existing = null;
    try {
        $all = $category->getAll();
        foreach ($all as $c) {
            $n = is_array($c) ? ($c['name'] ?? '') : (string)$c;
            if ($n !== '' && strtolower(trim($n)) === strtolower($categoryName)) {
                $existing = $c;
                break;
            }
        }
    } catch (Throwable $e) {
        $existing = null;
    }

    if ($existing) {
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'category' => ['id' => $existing['id'] ?? null, 'name' => $existing['name'] ?? $categoryName]
        ]);
        exit;
    }

    // Create new category
    $createRes = $category->create(['name' => $categoryName, 'description' => '']);
    if (is_array($createRes) && !empty($createRes['success']) && !empty($createRes['id'])) {
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'category' => ['id' => $createRes['id'], 'name' => $categoryName]
        ]);
    } else {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'message' => 'Failed to create category']);
    }
    exit;
}

// Handler: Import Preview (extract all rows)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && !isset($_POST['process_import'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    try {
        $file = $_FILES['import_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));

        $header = [];
        $rows = [];
        $totalRows = 0;

        if ($ext === 'csv') {
            $handle = fopen($file, 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                while (($data = fgetcsv($handle)) !== false) {
                    $rows[] = $data;
                }
                fclose($handle);
                $totalRows = count($rows);
            } else {
                throw new Exception('Could not open CSV file.');
            }
        } else {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Header row (row 1)
            for ($col = 1; $col <= $colCount; $col++) {
                $coord = Coordinate::stringFromColumnIndex($col) . '1';
                $cell = $sheet->getCell($coord);
                $header[] = $cell->getCalculatedValue() ?: '';
            }

            // All data rows (row 2 to end)
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $colCount; $col++) {
                    $coord = Coordinate::stringFromColumnIndex($col) . $row;
                    $cell = $sheet->getCell($coord);
                    $rowData[] = $cell->getCalculatedValue() ?: '';
                }
                $rows[] = $rowData;
            }
            $totalRows = $highestRow - 1;
        }

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'header' => $header,
            'rows' => $rows,
            'total' => $totalRows
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Import handler fatal: ' . $e->getMessage());
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Handler: Process mapping and return extracted data (no DB insert yet)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_import'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    try {
        $file = $_FILES['import_file']['tmp_name'];
        $mappings = json_decode($_POST['mappings'], true);
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));

        $extracted = [];
        $header = [];
        $dataRows = [];

        if ($ext === 'csv') {
            $handle = fopen($file, 'r');
            if ($handle) {
                $header = fgetcsv($handle);
                while (($row = fgetcsv($handle)) !== false) {
                    $dataRows[] = $row;
                }
                fclose($handle);
            }
        } else {
            $spreadsheet = IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestRow();
            $highestColumn = $sheet->getHighestColumn();
            $colCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Header
            for ($col = 1; $col <= $colCount; $col++) {
                $coord = Coordinate::stringFromColumnIndex($col) . '1';
                $header[] = $sheet->getCell($coord)->getCalculatedValue() ?: '';
            }

            // Data rows
            for ($row = 2; $row <= $highestRow; $row++) {
                $rowData = [];
                for ($col = 1; $col <= $colCount; $col++) {
                    $coord = Coordinate::stringFromColumnIndex($col) . $row;
                    $rowData[] = $sheet->getCell($coord)->getCalculatedValue() ?: '';
                }
                $dataRows[] = $rowData;
            }
        }

        // Apply mappings
        foreach ($dataRows as $rowIndex => $row) {
            $item = [];
            foreach ($mappings as $map) {
                $field = $map['field'];
                if (isset($map['colIndex'])) {
                    // Column mapping
                    $colIdx = $map['colIndex'];
                    $item[$field] = isset($row[$colIdx]) ? trim($row[$colIdx]) : '';
                } elseif (isset($map['constantValue'])) {
                    // Constant value mapping
                    $item[$field] = $map['constantValue'];
                }
            }
            // Only add rows that have at least a product_code or name
            if (!empty($item['product_code']) || !empty($item['name'])) {
                $extracted[] = $item;
            }
        }

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'data' => $extracted,
            'count' => count($extracted)
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Import handler fatal: ' . $e->getMessage());
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------
// Helper function to generate a unique barcode
// -------------------------------------------------------------------
function generateUniqueBarcode($product, array &$reservedCodes = []) {
    do {
        $candidate = strtoupper(substr(uniqid('PRD', true), 0, 20));
        $candidate = preg_replace('/[^A-Z0-9]/', '', $candidate);
        if (strlen($candidate) > 20) {
            $candidate = substr($candidate, 0, 20);
        }
        if (in_array($candidate, $reservedCodes, true)) {
            continue;
        }
        $existing = $product->getAll(['product_code' => $candidate], 1, 1);
    } while (!empty($existing));
    $reservedCodes[] = $candidate;
    return $candidate;
}

function normalizeImportText($v) {
    return strtolower(trim((string)($v ?? '')));
}

function findProductByCode(PDO $db, $code) {
    $code = trim((string)$code);
    if ($code === '') return null;

    $stmt = $db->prepare("SELECT * FROM products WHERE product_code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function findProductByNameCategory(PDO $db, $name, $category) {
    $nameNorm = normalizeImportText($name);
    if ($nameNorm === '') return null;

    $categoryRaw = trim((string)($category ?? ''));
    $sql = "SELECT * FROM products
            WHERE LOWER(name) = :name
              AND (
                    (:category = '' AND (category IS NULL OR category = ''))
                    OR category = :category
                  )
            LIMIT 2";
    $stmt = $db->prepare($sql);
    $stmt->execute([':name' => $nameNorm, ':category' => $categoryRaw]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) return $rows[0];
    if (count($rows) > 1) return ['__ambiguous' => true, '__count' => count($rows)];
    return null;
}

function productCodeExistsForOther(PDO $db, $code, $excludeId) {
    $stmt = $db->prepare("SELECT id FROM products WHERE product_code = :code AND id <> :id LIMIT 1");
    $stmt->execute([':code' => $code, ':id' => (int)$excludeId]);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensureCategoryExists(PDO $db, $categoryName) {
    $categoryName = trim((string)$categoryName);
    if ($categoryName === '' || !canAccess('categories_add')) return;

    $stmt = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = :name LIMIT 1");
    $stmt->execute([':name' => strtolower($categoryName)]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) return;

    try {
        $ins = $db->prepare("INSERT INTO categories (name, description) VALUES (:name, :description)");
        $ins->execute([':name' => $categoryName, ':description' => '']);
    } catch (Throwable $e) {
        // ignore duplicates or schema differences
    }
}

// Handler: Actually save imported data to database (with cost/wholesale + auto‑generate barcode)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_import'])) {
    header('Content-Type: application/json');
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ob_start();
    if (!canAccess('products_add')) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to import products.']);
        exit;
    }
    try {
        $db = getDB();
        if (!$db) {
            throw new Exception('Database connection failed');
        }

        $reservedCodes = [];

        $data = json_decode($_POST['import_data'], true);
        if (!is_array($data)) {
            throw new Exception('Invalid import data');
        }

        $allowNegative = !empty($_POST['allow_negative']);

        // Prevent importing negative quantities unless user explicitly allows
        if (!$allowNegative) {
            foreach ($data as $index => $item) {
                if (isset($item['quantity']) && trim((string)$item['quantity']) !== '') {
                    $qty = floatval(str_replace(',', '', (string)$item['quantity']));
                    if ($qty < 0) {
                        throw new Exception('Negative quantity detected in row ' . ($index + 1) . '. Please fix or choose to proceed with negatives.');
                    }
                }
            }
        }

        $results = [
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => []
        ];

        foreach ($data as $index => $item) {
            // Basic validation: need product_code or name
            if (empty($item['product_code']) && empty($item['name'])) {
                $results['skipped']++;
                continue;
            }

            $rawCost = isset($item['cost_price']) ? trim((string)$item['cost_price']) : '';
            $rawWholesale = isset($item['wholesale_price']) ? trim((string)$item['wholesale_price']) : '';

            // Prepare product data (sanitize)
            $productData = [
                'product_code'     => isset($item['product_code']) ? trim($item['product_code']) : '',
                'name'             => isset($item['name']) ? trim($item['name']) : '',
                'price'            => isset($item['price']) ? floatval(preg_replace('/[^0-9.]/', '', $item['price'])) : 0,
                'quantity'         => isset($item['quantity']) ? intval($item['quantity']) : 0,
                'category'         => isset($item['category']) ? trim($item['category']) : '',
                'qr_code_path'     => '',
                'status'           => 'active'
            ];

            // Only update these on re-import when the Excel cell is non-empty.
            if ($rawCost !== '') {
                $productData['cost_price'] = floatval(preg_replace('/[^0-9.]/', '', $rawCost));
            }
            if ($rawWholesale !== '') {
                $productData['wholesale_price'] = floatval(preg_replace('/[^0-9.]/', '', $rawWholesale));
            }

            // AUTO‑GENERATE BARCODE IF EMPTY
            // Ensure category exists (best-effort)
            ensureCategoryExists($db, $productData['category']);

            $incomingNameNorm = normalizeImportText($productData['name']);
            $incomingCodeRaw = trim($productData['product_code']);
            $incomingCodeNorm = normalizeImportText($incomingCodeRaw);

            // Treat barcode as invalid if missing or equal to product name.
            $importSuppliedBarcode = ($incomingCodeRaw !== '' && ($incomingNameNorm === '' || $incomingCodeNorm !== $incomingNameNorm));

            // Find existing product
            $existingRow = null;
            if ($importSuppliedBarcode) {
                $existingRow = findProductByCode($db, $incomingCodeRaw);
            }
            if (!$existingRow) {
                $existingRow = findProductByNameCategory($db, $productData['name'], $productData['category']);
            }
            if (is_array($existingRow) && !empty($existingRow['__ambiguous'])) {
                $results['errors'][] = "Row " . ($index + 1) . ": multiple existing products match name+category; please fix duplicates before importing";
                $results['skipped']++;
                continue;
            }

            if ($existingRow) {
                $prodId = (int)$existingRow['id'];
                if (!canAccess('products_edit')) {
                    $results['errors'][] = "Row " . ($index + 1) . ": updating existing products requires edit permission";
                    $results['skipped']++;
                    continue;
                }

                $barcodeChanged = false;

                // If Excel now provides a real barcode and it differs, update it.
                if ($importSuppliedBarcode && $incomingCodeRaw !== '' && $incomingCodeRaw !== ($existingRow['product_code'] ?? '')) {
                    if (productCodeExistsForOther($db, $incomingCodeRaw, $prodId)) {
                        $results['errors'][] = "Row " . ($index + 1) . ": barcode already used by another product ({$incomingCodeRaw})";
                        $results['skipped']++;
                        continue;
                    }
                    $productData['product_code'] = $incomingCodeRaw;
                    $barcodeChanged = true;
                } else {
                    // Keep existing barcode if the import doesn't supply a real one
                    $productData['product_code'] = $existingRow['product_code'] ?? $productData['product_code'];
                }
                $productData['expected_quantity'] = (int) ($existingRow['quantity'] ?? 0);

                if ($product->update($prodId, $productData)) {
                    $results['updated']++;

                    // QR code generation intentionally disabled
                } else {
                    $errorMsg = "Failed to update product (ID: $prodId); stock may have changed during import";
                    $results['errors'][] = $errorMsg;
                    error_log($errorMsg);
                }
            } else {
                // New product insert: generate code if missing/invalid; otherwise keep supplied barcode.
                if (!$importSuppliedBarcode) {
                    $productData['product_code'] = generateUniqueBarcode($product, $reservedCodes);
                } else {
                    $productData['product_code'] = $incomingCodeRaw;
                }

                $newId = $product->create($productData);
                if ($newId) {
                    $results['inserted']++;
                } else {
                    $errorMsg = "Failed to insert product (Code: {$productData['product_code']})";
                    $results['errors'][] = $errorMsg;
                    error_log($errorMsg);
                }
            }
        }

        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'results' => $results
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Import handler fatal: ' . $e->getMessage());
        if (ob_get_length()) ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// -------------------------------------------------------------------
// 3. Load all products for the list (only active)
// -------------------------------------------------------------------
// Removed the hard 1000 limit so all active products are visible after bulk import.
$allProducts = $product->getAll(['status' => 'active'], 1, 0);
$products = $allProducts;

$pageTitle = 'Manage Products';
$pageScripts = [];
include __DIR__ . '/../layouts/header.php';
?>

<div style="padding:12px 18px;">
    <a href="/pos-system/views/dashboard/index.php" class="btn btn-secondary" style="background:#4b5563;color:#fff;border-radius:10px;padding:10px 16px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- Include Split.js for resizable panels -->
<script src="https://cdn.jsdelivr.net/npm/split.js"></script>
<!-- Bootstrap JS (needed for modal) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
/* ==================== Resizable Split Layout ==================== */
.manage-container {
    display: flex;
    width: 100%;
    height: calc(100vh - 120px);
    overflow: hidden;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    background: white;
}

/* Left Panel - Form */
.panel-left {
    width: 40%;
    min-width: 350px;
    max-width: 70%;
    background: #f8f9fa;
    padding: 1.5rem;
    overflow-y: auto;
    box-sizing: border-box;
}

/* Right Panel - Product List */
.panel-right {
    flex: 1;
    background: white;
    padding: 1.5rem;
    overflow-y: auto;
    box-sizing: border-box;
}

/* Draggable Gutter */
.gutter {
    background: #dee2e6;
    cursor: col-resize;
    width: 5px;
    transition: background 0.2s;
}
.gutter:hover {
    background: #4361ee;
}

/* ==================== Form Styles ==================== */
.form-group {
    margin-bottom: 1.2rem;
}
.form-group label {
    display: block;
    margin-bottom: 0.4rem;
    font-weight: 600;
    color: #495057;
}
.form-control {
    width: 100%;
    padding: 0.6rem 0.8rem;
    border: 1px solid #ced4da;
    border-radius: 8px;
    font-size: 1rem;
}
.form-control:focus {
    border-color: #4361ee;
    outline: none;
    box-shadow: 0 0 0 3px rgba(67,97,238,0.1);
}
.btn {
    padding: 0.7rem 1.5rem;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-primary {
    background: #4361ee;
    color: white;
}
.btn-primary:hover {
    background: #3046c0;
}
.btn-secondary {
    background: #6c757d;
    color: white;
}
.btn-secondary:hover {
    background: #5a6268;
}
.btn-danger {
    background: #f72585;
    color: white;
}
.btn-danger:hover {
    background: #d1146a;
}
.btn-success {
    background: #06d6a0;
    color: white;
}
.btn-success:hover {
    background: #05b586;
}
.form-actions {
    display: flex;
    gap: 0.8rem;
    margin-top: 1.5rem;
}

/* ==================== Product List Grid ==================== */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}
.product-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    padding: 1rem;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.product-card:hover {
    transform: translateY(-2px);
    border-color: #4361ee;
    box-shadow: 0 5px 15px rgba(67,97,238,0.15);
}
.product-card.selected {
    border: 2px solid #4361ee;
    background: #eef2ff;
}
.product-card.bulk-selected {
    border: 2px solid #dc2626;
    background: #fef2f2;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.14);
}
/* Highlight for potential duplicate products (red) */
.product-card.duplicate-highlight {
    border: 2px solid #f03e3e;
    background-color: #ffe3e3;
    box-shadow: 0 0 0 3px rgba(240, 62, 62, 0.2);
    transform: scale(1.02);
    transition: all 0.2s ease;
}
/* Highlight for search matches (blue) */
.product-card.match-highlight {
    border: 2px solid #4361ee;
    background-color: #eef2ff;
}
.product-name {
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #212529;
}
.product-code {
    font-size: 0.85rem;
    color: #6c757d;
    margin-bottom: 0.5rem;
}
.product-price {
    font-weight: 700;
    color: #4361ee;
    margin-bottom: 0.3rem;
}
.product-stock {
    font-size: 0.9rem;
    color: #2b2d42;
}
.stock-low {
    color: #f72585;
}

/* Stock Search Section */
.stock-search-section {
    margin-bottom: 1.5rem;
    background: white;
    padding: 1rem;
    border-radius: 12px;
    border: 1px solid #e9ecef;
}
.stock-search-input-group {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.stock-search-input-group input {
    flex: 1;
    padding: 0.7rem 1rem;
    border: 1px solid #ced4da;
    border-radius: 8px;
    font-size: 1rem;
}
.stock-search-input-group button {
    background: #4361ee;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 0.7rem 1.5rem;
    cursor: pointer;
    font-weight: 600;
    transition: background 0.2s;
}
.stock-search-input-group button:hover {
    background: #3046c0;
}
.stock-search-input-group button:active {
    transform: scale(0.98);
}
.stock-search-hint {
    margin-top: 0.3rem;
    font-size: 0.85rem;
    color: #6c757d;
}
.clear-stock-search {
    background: #6c757d;
}
.clear-stock-search:hover {
    background: #5a6268;
}
.stock-filter-active {
    background: #fef3c7;
    border-left: 4px solid #f59e0b;
    padding: 0.5rem;
    margin-bottom: 1rem;
    border-radius: 8px;
    font-size: 0.9rem;
    color: #92400e;
}

/* Notifications */
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    z-index: 9999;
    animation: slideIn 0.3s ease;
    max-width: 350px;
}
.notification-success { border-left: 4px solid #06d6a0; }
.notification-error { border-left: 4px solid #f72585; }
.notification-warning { border-left: 4px solid #ffb703; }
.notification-info { border-left: 4px solid #4361ee; }
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Import Modal Specific Styles */
.mapping-row { background: #f8f9fa; padding: 10px; border-radius: 6px; margin-bottom: 8px; }
.preview-table { font-size: 0.9rem; }
.preview-table th { background-color: #e9ecef; position: sticky; top: 0; background: white; box-shadow: 0 2px 2px -1px rgba(0,0,0,0.1); }
.result-table { font-size: 0.9rem; margin-top: 20px; }
.scrollable-preview { max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.25rem; margin-bottom: 15px; }
.bulk-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
    flex-wrap: wrap;
}
.bulk-selection-hint {
    color: #6c757d;
    font-size: 0.9rem;
}
.category-row {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}
.category-row .form-control {
    flex: 1;
}
</style>

<!-- ==================== Main Layout ==================== -->
<div class="manage-container" id="manage-container">
    <!-- LEFT PANEL: Form -->
    <div class="panel-left" id="panel-left">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0"><i class="fas fa-cube mr-2"></i>Add / Edit Product</h2>
            <div>
                <button class="btn btn-success btn-sm" id="bulkImportBtn">
                    <i class="fas fa-upload"></i> Bulk Import
                </button>
                <?php if (canAccess('products_delete')): ?>
                <button class="btn btn-danger btn-sm ml-2" type="button" onclick="deleteAllProductsFromImport()">
                    <i class="fas fa-trash-alt"></i> Delete All
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Stock Search Section (replaces barcode scanner) -->
        <div class="stock-search-section">
            <div class="stock-search-input-group">
                <i class="fas fa-boxes"></i>
                <input type="number" id="stock-search-input" class="form-control" placeholder="Enter max stock quantity (e.g., 20)" min="0" step="1">
                <button type="button" id="search-stock-btn">
                    <i class="fas fa-search"></i> Search
                </button>
                <button type="button" id="clear-stock-search" class="clear-stock-search" title="Clear Search">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
            <div class="stock-search-hint">
                <i class="fas fa-info-circle"></i> Search for products with stock quantity ≤ entered value. Shows products that need restocking.
            </div>
            <div id="stock-filter-badge" class="stock-filter-active" style="display: none;">
                <i class="fas fa-filter"></i> Filtering products with stock ≤ <span id="filter-max-qty"></span>
            </div>
        </div>

        <!-- Product Form (Description removed) -->
        <form id="product-form" method="POST" action="/pos-system/api/products/save.php" enctype="multipart/form-data">
            <input type="hidden" id="product-id" name="id">
            <input type="hidden" id="original-quantity" name="original_quantity">
            
            <div class="form-group">
                <label>Product Code *</label>
                <input type="text" id="product-code" name="product_code" class="form-control" required>
                <small style="display:block; margin-top:6px; color:#64748b;">Auto-generated from product name, 6 characters.</small>
            </div>

            <div class="form-group">
                <label>Product Name *</label>
                <input type="text" id="product-name" name="name" class="form-control" required>
            </div>

            <!-- Cost Price -->
            <div class="form-group">
                <label>Cost Price</label>
                <input type="number" id="product-cost-price" name="cost_price" step="0.01" class="form-control">
            </div>

            <!-- Wholesale Price -->
            <div class="form-group">
                <label>Wholesale Price</label>
                <input type="number" id="product-wholesale-price" name="wholesale_price" step="0.01" class="form-control">
            </div>

            <!-- Selling Price -->
            <div class="form-group">
                <label>Selling Price *</label>
                <input type="number" id="product-price" name="price" step="0.01" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Quantity *</label>
                <input type="number" id="product-quantity" name="quantity" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Category</label>
                <div class="category-row">
                    <select id="product-category" name="category" class="form-control">
                        <option value="" data-category-id="">-- Select Category --</option>
                        <?php foreach ($allCategories as $cat): ?>
                        <?php
                            $catName = is_array($cat) ? ($cat['name'] ?? '') : (string)$cat;
                            $catId = is_array($cat) ? ($cat['id'] ?? '') : '';
                        ?>
                        <option value="<?= htmlspecialchars($catName) ?>" data-category-id="<?= htmlspecialchars((string)$catId) ?>"><?= htmlspecialchars($catName) ?></option>
                        <?php endforeach; ?>
                        <!-- Other option -->
                        <option value="other" data-category-id="">Other (specify)</option>
                    </select>
                    <button type="button" class="btn btn-outline-danger" id="delete-category-btn" disabled>
                        <i class="fas fa-trash mr-1"></i> Delete Category
                    </button>
                </div>
                <small style="display:block; margin-top:6px; color:#64748b;">Select an existing category to delete it from this page.</small>
            </div>

            <div class="form-group">
                <label>Image</label>
                <input type="file" id="product-image" name="image" class="form-control" accept="image/*">
                <div id="current-image" style="margin-top: 0.5rem;"></div>
            </div>

            <div class="form-group">
                <label>Status</label>
                <select id="product-status" name="status" class="form-control">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="save-btn"><i class="fas fa-save mr-2"></i>Save Product</button>
                <button type="button" class="btn btn-secondary" id="clear-btn"><i class="fas fa-undo mr-2"></i>Clear Form</button>
                <?php if (canAccess('products_delete')): ?>
                <button type="button" class="btn btn-danger" id="delete-btn" style="display: none;"><i class="fas fa-trash mr-2"></i>Delete</button>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- RIGHT PANEL: Product List -->
    <div class="panel-right" id="panel-right">
        <h2 class="mb-3"><i class="fas fa-list mr-2"></i>All Products</h2>
        <input type="text" id="product-search" class="form-control mb-3" placeholder="Search by name or barcode...">
        <div class="bulk-actions">
            <small id="bulk-selection-hint" class="bulk-selection-hint">Hold Ctrl/Cmd or Shift to select multiple products.</small>
            <div>
                <button type="button" class="btn btn-success btn-sm" id="export-stocks-btn" onclick="window.location.href='/pos-system/api/products/export.php'">
                    <i class="fas fa-file-excel"></i> Export Stocks
                </button>
                <?php if (canAccess('products_delete')): ?>
                <button type="button" class="btn btn-danger btn-sm" id="bulk-delete-btn" disabled>
                    <i class="fas fa-trash mr-1"></i> Delete Selected
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="product-grid" id="product-grid">
            <?php foreach ($products as $p): ?>
            <div class="product-card" data-id="<?= $p['id'] ?>" data-code="<?= htmlspecialchars($p['product_code']) ?>" data-quantity="<?= $p['quantity'] ?>">
                <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                <div class="product-code"><?= htmlspecialchars($p['product_code']) ?></div>
                <div class="product-price"><?= formatCurrency($p['price']) ?></div>
                <div class="product-stock <?= $p['quantity'] <= 5 ? 'stock-low' : '' ?>">
                    Stock: <?= $p['quantity'] ?>
                </div>
                <!-- Optionally display cost/wholesale if needed -->
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ==================== Bulk Import Modal ==================== -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importModalLabel"><i class="fas fa-file-import"></i> Bulk Import Products</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Step 1: Upload -->
                <div id="step1">
                    <h6>Step 1: Upload Excel or CSV File</h6>
                    <form id="importUploadForm" enctype="multipart/form-data">
                        <div class="mb-3">
                            <input type="file" class="form-control" id="importFile" name="import_file" accept=".csv,.xlsx,.xls" required>
                            <div class="form-text">First row must contain column headers. Only .csv, .xlsx, .xls files.</div>
                        </div>
                        <button type="submit" class="btn btn-primary" id="uploadBtn">
                            <i class="fas fa-upload"></i> Upload & Preview
                        </button>
                    </form>
                </div>

                <!-- Step 2: Preview & Mapping (hidden initially) -->
                <div id="step2" style="display: none;">
                    <h6>Step 2: Preview & Map Columns</h6>
                    <div class="scrollable-preview">
                        <table class="table table-bordered table-sm preview-table">
                            <thead class="table-dark" id="previewHeader"></thead>
                            <tbody id="previewBody"></tbody>
                        </table>
                    </div>
                    <p class="text-muted small" id="fileInfo"></p>

                    <hr>
                    <h6>Map Columns to Database Fields</h6>
                    <div id="columnMapping" class="mb-3"></div>

                    <button class="btn btn-success" id="processImportBtn">
                        <i class="fas fa-play"></i> Preview Import Data
                    </button>
                    <button class="btn btn-outline-secondary" id="backToUploadBtn">
                        <i class="fas fa-arrow-left"></i> Back
                    </button>
                </div>

                <!-- Step 3: Preview Extracted Data & Confirm (hidden initially) -->
                <div id="step3" style="display: none;">
                    <h6>Step 3: Review Extracted Data</h6>
                    <div id="importSummary" class="alert alert-info"></div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm result-table">
                            <thead class="table-light" id="resultHeader"></thead>
                            <tbody id="resultBody"></tbody>
                        </table>
                    </div>
                    <button class="btn btn-success" id="confirmImportBtn">
                        <i class="fas fa-check"></i> Confirm & Save to Database
                    </button>
                    <button class="btn btn-outline-secondary" id="backToMappingBtn">
                        <i class="fas fa-arrow-left"></i> Back to Mapping
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript -->
<script>
// ---------- Notification System ----------
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
            <span>${message}</span>
        </div>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    document.body.appendChild(notification);
    setTimeout(() => { if (notification.parentElement) notification.remove(); }, 3000);
}

// ---------- Helper ----------
function escapeHtml(text) {
    if (!text) return text;
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
}

async function readJsonResponse(response) {
    const text = await response.text();
    if (!text) {
        throw new Error(`Server returned empty response (HTTP ${response.status}).`);
    }
    try {
        return JSON.parse(text);
    } catch (e) {
        const snippet = text ? text.slice(0, 200) : '';
        throw new Error(`Server returned non-JSON (HTTP ${response.status}). ${snippet}`);
    }
}

// ---------- Global ----------
const canEditProducts = <?= json_encode(canAccess('products_edit')) ?>;
const canDeleteProducts = <?= json_encode(canAccess('products_delete')) ?>;
const canAddCategories = <?= json_encode(canAccess('categories_add')) ?>;
const canDeleteCategories = <?= json_encode(canAccess('categories_delete')) ?>;
let currentProductId = null;
let uploadedFile = null;
let extractedData = null;
let currentMappings = [];
let formReadOnly = false;
let productCodeManuallyEdited = false;
let selectedProductIds = new Set();
let selectionAnchorId = null;
let currentStockFilter = null; // Store current stock filter value

function setFormReadOnly(readOnly) {
    formReadOnly = !!readOnly;

    const idsToToggle = [
        'product-code',
        'product-name',
        'product-price',
        'product-cost-price',
        'product-wholesale-price',
        'product-quantity',
        'product-category',
        'product-description',
        'product-status',
        'product-image'
    ];

    idsToToggle.forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.disabled = formReadOnly;
    });

    // Keep Clear/Delete available, but prevent saving in read-only mode.
    const saveBtn = document.getElementById('save-btn');
    if (saveBtn) saveBtn.disabled = formReadOnly;
}

function getAllProductCards() {
    return Array.from(document.querySelectorAll('.product-card'));
}

function getVisibleProductCards() {
    return getAllProductCards().filter((card) => card.style.display !== 'none');
}

function getRangeSelectionIds(startId, endId) {
    const visibleCards = getVisibleProductCards();
    const ids = visibleCards.map((card) => String(card.getAttribute('data-id') || ''));
    const startIndex = ids.indexOf(String(startId || ''));
    const endIndex = ids.indexOf(String(endId || ''));

    if (startIndex === -1 || endIndex === -1) {
        return [String(endId || '')].filter(Boolean);
    }

    const from = Math.min(startIndex, endIndex);
    const to = Math.max(startIndex, endIndex);
    return ids.slice(from, to + 1).filter(Boolean);
}

function updateBulkSelectionUI() {
    const selectedIds = new Set(Array.from(selectedProductIds, (id) => String(id)));
    const count = selectedIds.size;
    const deleteBtn = document.getElementById('bulk-delete-btn');
    const hint = document.getElementById('bulk-selection-hint');
    const singleDeleteBtn = document.getElementById('delete-btn');

    getAllProductCards().forEach((card) => {
        const productId = String(card.getAttribute('data-id') || '');
        card.classList.toggle('bulk-selected', selectedIds.has(productId));
    });

    if (deleteBtn) {
        deleteBtn.disabled = count === 0;
        deleteBtn.innerHTML = count > 0
            ? `<i class="fas fa-trash mr-1"></i> Delete Selected (${count})`
            : '<i class="fas fa-trash mr-1"></i> Delete Selected';
    }

    if (hint) {
        hint.textContent = count > 0
            ? `${count} product${count === 1 ? '' : 's'} selected. Hold Ctrl/Cmd or Shift to adjust the selection.`
            : 'Hold Ctrl/Cmd or Shift to select multiple products.';
    }

    if (count > 0) {
        document.querySelectorAll('.product-card').forEach((card) => card.classList.remove('selected'));
        if (singleDeleteBtn) singleDeleteBtn.style.display = 'none';
    } else {
        if (currentProductId) {
            const activeCard = document.querySelector(`.product-card[data-id="${currentProductId}"]`);
            if (activeCard) activeCard.classList.add('selected');
        }
        if (singleDeleteBtn && currentProductId) singleDeleteBtn.style.display = 'inline-block';
    }
}

function clearBulkSelection(options = {}) {
    selectedProductIds.clear();
    if (!options.preserveAnchor) {
        selectionAnchorId = null;
    }
    updateBulkSelectionUI();
}

function getSelectedCategoryOption() {
    const catSelect = document.getElementById('product-category');
    if (!catSelect || catSelect.selectedIndex < 0) {
        return null;
    }

    return catSelect.options[catSelect.selectedIndex] || null;
}

function updateCategoryDeleteButton() {
    const deleteCategoryBtn = document.getElementById('delete-category-btn');
    const catSelect = document.getElementById('product-category');
    if (!deleteCategoryBtn || !catSelect) return;

    const selectedOption = getSelectedCategoryOption();
    const categoryName = String(catSelect.value || '').trim();
    const categoryId = String(selectedOption?.dataset.categoryId || '').trim();
    const canDelete = canDeleteCategories && categoryName !== '' && categoryName !== 'other' && categoryId !== '';

    deleteCategoryBtn.disabled = !canDelete;
    deleteCategoryBtn.title = canDelete
        ? `Delete category "${categoryName}"`
        : 'Select an existing category to delete';
}

function addCategoryOption(selectElement, category) {
    if (!selectElement || !category || !category.name) return null;

    let option = Array.from(selectElement.options).find((opt) => opt.value === category.name);
    if (!option) {
        option = document.createElement('option');
        option.value = category.name;
        option.text = category.name;

        const otherOption = Array.from(selectElement.options).find((opt) => opt.value === 'other');
        if (otherOption) {
            selectElement.insertBefore(option, otherOption);
        } else {
            selectElement.add(option);
        }
    }

    option.dataset.categoryId = String(category.id || option.dataset.categoryId || '');
    return option;
}

async function deleteSelectedCategory() {
    if (!canDeleteCategories) {
        showNotification('You do not have permission to delete categories.', 'error');
        return;
    }

    const catSelect = document.getElementById('product-category');
    const deleteCategoryBtn = document.getElementById('delete-category-btn');
    const selectedOption = getSelectedCategoryOption();
    const categoryId = String(selectedOption?.dataset.categoryId || '').trim();
    const categoryName = String(catSelect?.value || '').trim();

    if (!categoryId || !categoryName || categoryName === 'other') {
        showNotification('Select an existing category first', 'warning');
        updateCategoryDeleteButton();
        return;
    }

    const confirmed = typeof Swal !== 'undefined'
        ? await Swal.fire({
            title: 'Delete category?',
            text: `Delete category "${categoryName}"? If products still use it, the category may be archived instead.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete Category',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then((result) => result.isConfirmed)
        : confirm(`Delete category "${categoryName}"?`);

    if (!confirmed) {
        return;
    }

    const originalText = deleteCategoryBtn ? deleteCategoryBtn.innerHTML : '';
    if (deleteCategoryBtn) {
        deleteCategoryBtn.disabled = true;
        deleteCategoryBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Deleting...';
    }

    try {
        const response = await fetch('/pos-system/api/categories/delete.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: categoryId })
        });
        const result = await readJsonResponse(response);
        if (!result.success) {
            throw new Error(result.message || 'Failed to delete category');
        }

        showNotification(result.message || 'Category deleted successfully', 'success');
        setTimeout(() => location.reload(), 1200);
    } catch (error) {
        showNotification('Error: ' + error.message, 'error');
        if (deleteCategoryBtn) {
            deleteCategoryBtn.innerHTML = originalText;
        }
        updateCategoryDeleteButton();
    }
}

function normalizeNameForProductCode(name) {
    return String(name || '')
        .toUpperCase()
        .replace(/[^A-Z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function buildProductCodePrefix(name) {
    const normalized = normalizeNameForProductCode(name);
    if (!normalized) return 'PRD';

    const words = normalized.split(' ').filter(Boolean);
    let prefix = '';

    if (words[0]) prefix += words[0].slice(0, 2);
    if (words[1]) prefix += words[1].slice(0, 1);

    const compact = normalized.replace(/[^A-Z0-9]/g, '');
    let i = 0;
    while (prefix.length < 3 && i < compact.length) {
        const char = compact[i];
        if (!prefix.includes(char) || prefix.length < 2) {
            prefix += char;
        }
        i++;
    }

    return prefix.padEnd(3, 'X').slice(0, 3);
}

function buildProductCodeSuggestion(name, attempt = 0) {
    const prefix = buildProductCodePrefix(name);
    const normalized = normalizeNameForProductCode(name);
    if (!normalized) return '';

    let hash = 0;
    const source = `${normalized}|${attempt}`;
    for (let i = 0; i < source.length; i++) {
        hash = ((hash * 31) + source.charCodeAt(i)) >>> 0;
    }
    const suffix = hash.toString(36).toUpperCase().padStart(3, '0').slice(-3);
    return `${prefix}${suffix}`.slice(0, 6);
}

function autoGenerateProductCode(force = false) {
    const productNameInput = document.getElementById('product-name');
    const productCodeInput = document.getElementById('product-code');
    if (!productNameInput || !productCodeInput) return;
    if (currentProductId && !force) return;
    if (productCodeManuallyEdited && !force) return;

    productCodeInput.value = buildProductCodeSuggestion(productNameInput.value);
}

// ---------- Stock Search Filter Functionality (Filters products like search box) ----------
function applyStockFilter(maxQuantity) {
    const productCards = getAllProductCards();
    let matchCount = 0;
    
    // First, clear any existing text search highlight when applying stock filter
    const searchInput = document.getElementById('product-search');
    const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
    
    productCards.forEach(card => {
        const quantity = parseInt(card.getAttribute('data-quantity') || '0', 10);
        const matchesStock = quantity <= maxQuantity;
        
        // Also check text search if active
        let matchesText = true;
        if (searchTerm !== '') {
            const name = card.querySelector('.product-name')?.textContent.toLowerCase() || '';
            const code = card.querySelector('.product-code')?.textContent.toLowerCase() || '';
            matchesText = name.includes(searchTerm) || code.includes(searchTerm);
        }
        
        const shouldShow = matchesStock && matchesText;
        
        if (shouldShow) {
            matchCount++;
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
        
        // Add visual indication that stock filter is active
        if (matchesStock) {
            card.classList.add('stock-search-match');
        } else {
            card.classList.remove('stock-search-match');
        }
    });
    
    // Update filter badge
    const filterBadge = document.getElementById('stock-filter-badge');
    const filterMaxQtySpan = document.getElementById('filter-max-qty');
    if (filterMaxQtySpan) {
        filterMaxQtySpan.textContent = maxQuantity;
    }
    if (filterBadge) {
        filterBadge.style.display = 'block';
    }
    
    // Update no results message if needed
    setStockNoResultsMessage(matchCount === 0);
    
    showNotification(`Showing ${matchCount} product(s) with stock ≤ ${maxQuantity}`, 'info');
}

function clearStockFilter() {
    const productCards = getAllProductCards();
    
    // Reset display based on text search only
    const searchInput = document.getElementById('product-search');
    const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
    
    productCards.forEach(card => {
        // Remove stock highlight
        card.classList.remove('stock-search-match');
        
        // Apply text search filter if any
        if (searchTerm !== '') {
            const name = card.querySelector('.product-name')?.textContent.toLowerCase() || '';
            const code = card.querySelector('.product-code')?.textContent.toLowerCase() || '';
            const matches = name.includes(searchTerm) || code.includes(searchTerm);
            card.style.display = matches ? 'block' : 'none';
        } else {
            card.style.display = 'block';
        }
    });
    
    // Hide filter badge
    const filterBadge = document.getElementById('stock-filter-badge');
    if (filterBadge) {
        filterBadge.style.display = 'none';
    }
    
    currentStockFilter = null;
    
    // Update no results message for text search
    if (searchInput && searchInput.value.trim() !== '') {
        const visibleCount = getVisibleProductCards().length;
        setNoResultsMessage(visibleCount === 0);
    } else {
        setNoResultsMessage(false);
    }
    
    showNotification('Stock filter cleared', 'info');
}

function performStockSearch() {
    const searchInput = document.getElementById('stock-search-input');
    const maxQuantity = parseInt(searchInput.value, 10);
    
    if (isNaN(maxQuantity) || maxQuantity < 0) {
        showNotification('Please enter a valid quantity (0 or greater)', 'warning');
        return;
    }
    
    currentStockFilter = maxQuantity;
    applyStockFilter(maxQuantity);
}

function clearStockSearch() {
    const searchInput = document.getElementById('stock-search-input');
    searchInput.value = '';
    clearStockFilter();
}

function setStockNoResultsMessage(show) {
    const grid = document.getElementById('product-grid');
    if (!grid) return;
    
    let noResultsMsg = document.getElementById('stock-no-results');
    if (show) {
        if (!noResultsMsg) {
            noResultsMsg = document.createElement('div');
            noResultsMsg.id = 'stock-no-results';
            noResultsMsg.className = 'no-products-message';
            noResultsMsg.innerHTML = '<i class="fas fa-boxes"></i><p>No products match the stock filter.</p>';
            grid.appendChild(noResultsMsg);
        }
    } else if (noResultsMsg) {
        noResultsMsg.remove();
    }
}

// ---------- Load product into form (updated with cost/wholesale) ----------
function loadProductIntoForm(product) {
    if (!canEditProducts) {
        showNotification('You do not have permission to edit products.', 'error');
        return;
    }

    clearBulkSelection();
    document.getElementById('product-id').value = product.id || '';
    document.getElementById('original-quantity').value = product.quantity ?? 0;
    document.getElementById('product-code').value = product.product_code || '';
    document.getElementById('product-name').value = product.name || '';
    document.getElementById('product-price').value = product.price || '';
    document.getElementById('product-cost-price').value = product.cost_price || '';
    document.getElementById('product-wholesale-price').value = product.wholesale_price || '';
    document.getElementById('product-quantity').value = product.quantity ?? 0;
    
    // Handle category: if product.category exists and is not in the dropdown, add it
    const catSelect = document.getElementById('product-category');
    if (product.category) {
        let optionExists = false;
        for (let i = 0; i < catSelect.options.length; i++) {
            if (catSelect.options[i].value === product.category) {
                optionExists = true;
                break;
            }
        }
        if (!optionExists) {
            const newOption = document.createElement('option');
            newOption.value = product.category;
            newOption.text = product.category;
            catSelect.add(newOption);
        }
        catSelect.value = product.category;
    } else {
        catSelect.value = '';
    }
    updateCategoryDeleteButton();

    document.getElementById('product-status').value = product.status || 'active';
    productCodeManuallyEdited = true;

    const imgDiv = document.getElementById('current-image');
    if (product.image_path) {
        imgDiv.innerHTML = `<img src="${normalizeProductImageUrl(product.image_path)}" style="max-width: 100px; max-height: 100px; border-radius: 8px;">`;
    } else {
        imgDiv.innerHTML = '';
    }

    const deleteBtn = document.getElementById('delete-btn');
    if (product.id) {
        deleteBtn.style.display = 'inline-block';
        deleteBtn.setAttribute('data-id', product.id);
        currentProductId = product.id;
    } else {
        deleteBtn.style.display = 'none';
        currentProductId = null;
    }

    highlightSelectedProduct(product.id);
    document.querySelectorAll('.product-card.duplicate-highlight').forEach(c => 
        c.classList.remove('duplicate-highlight')
    );

    // Allow editing when a product is loaded.
    setFormReadOnly(false);

    const nameInput = document.getElementById('product-name');
    if (nameInput) nameInput.focus();
}

// ---------- Clear form (updated) ----------
function clearForm() {
    clearBulkSelection();
    document.getElementById('product-id').value = '';
    document.getElementById('original-quantity').value = '';
    document.getElementById('product-code').value = '';
    document.getElementById('product-name').value = '';
    document.getElementById('product-price').value = '';
    document.getElementById('product-cost-price').value = '';
    document.getElementById('product-wholesale-price').value = '';
    document.getElementById('product-quantity').value = '';
    document.getElementById('product-category').value = '';
    document.getElementById('product-status').value = 'active';
    document.getElementById('current-image').innerHTML = '';
    const deleteButton = document.getElementById('delete-btn');
    if (deleteButton) deleteButton.style.display = 'none';
    currentProductId = null;
    productCodeManuallyEdited = false;
    updateCategoryDeleteButton();

    document.querySelectorAll('.product-card').forEach(c => c.classList.remove('selected'));
    document.querySelectorAll('.product-card.duplicate-highlight').forEach(c => 
        c.classList.remove('duplicate-highlight')
    );

    // New product entry should be editable.
    setFormReadOnly(false);
}

function normalizeProductImageUrl(path) {
    let value = String(path || '').trim();
    if (!value) return '';

    value = value.replace(/\\/g, '/');

    if (/^https?:\/\//i.test(value)) {
        return value;
    }

    if (value.startsWith('/pos-system/')) {
        return value;
    }

    if (value.startsWith('pos-system/')) {
        return '/' + value;
    }

    return '/pos-system/' + value.replace(/^\/+/, '');
}

// ---------- Highlight selected product ----------
function highlightSelectedProduct(productId) {
    document.querySelectorAll('.product-card').forEach(c => c.classList.remove('selected'));
    if (selectedProductIds.size === 0 && productId) {
        const card = document.querySelector(`.product-card[data-id="${productId}"]`);
        if (card) card.classList.add('selected');
    }
}

// ---------- Click handler using PRODUCT ID ----------
function initProductListClick() {
    const grid = document.getElementById('product-grid');
    grid.addEventListener('click', async (e) => {
        const card = e.target.closest('.product-card');
        if (!card) return;
        const productId = String(card.getAttribute('data-id') || '').trim();
        if (!productId) return;
        if (!canEditProducts) {
            showNotification('You do not have permission to edit products.', 'error');
            return;
        }

        if (e.shiftKey || e.ctrlKey || e.metaKey) {
            e.preventDefault();

            if (e.shiftKey && selectionAnchorId) {
                if (!e.ctrlKey && !e.metaKey) {
                    selectedProductIds.clear();
                }
                getRangeSelectionIds(selectionAnchorId, productId).forEach((id) => selectedProductIds.add(id));
            } else if (selectedProductIds.has(productId)) {
                selectedProductIds.delete(productId);
            } else {
                selectedProductIds.add(productId);
            }

            selectionAnchorId = productId;
            updateBulkSelectionUI();
            return;
        }

        clearBulkSelection();
        selectionAnchorId = productId;

        try {
            const response = await fetch(`?action=get_by_code&by_id=1&code=${productId}`);
            const result = await readJsonResponse(response);
            if (result.success) {
                loadProductIntoForm(result.product);
                showNotification(`Loaded: ${result.product.name}`, 'success');
            } else {
                showNotification('Error loading product', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification('Failed to load product', 'error');
        }
    });
}

// ---------- Form submission ----------
document.getElementById('product-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const saveBtn = document.getElementById('save-btn');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: formData
        });
        const result = await readJsonResponse(response);
        if (result.success) {
            showNotification('Product saved successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(result.message || 'Save failed');
        }
    } catch (error) {
        showNotification('Error: ' + error.message, 'error');
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
    }
});

// ---------- Delete button ----------
const singleDeleteButton = document.getElementById('delete-btn');
if (singleDeleteButton) singleDeleteButton.addEventListener('click', async function() {
    if (!currentProductId) return;
    if (!confirm('Delete this product?')) return;
    const deleteBtn = this;
    deleteBtn.disabled = true;
    const originalText = deleteBtn.innerHTML;
    deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Deleting...';

    try {
        const response = await fetch('/pos-system/api/products/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: currentProductId, permanent: true })
        });
        const result = await readJsonResponse(response);
        if (result.success) {
            showNotification('Product deleted', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            throw new Error(result.message || 'Delete failed');
        }
    } catch (error) {
        showNotification('Error: ' + error.message, 'error');
        deleteBtn.disabled = false;
        deleteBtn.innerHTML = originalText;
    }
});

async function deleteSelectedProducts() {
    const ids = Array.from(selectedProductIds, (id) => parseInt(String(id), 10)).filter((id) => Number.isInteger(id) && id > 0);
    if (ids.length === 0) {
        showNotification('No products selected', 'warning');
        return;
    }

    if (!confirm(`Delete ${ids.length} selected product${ids.length === 1 ? '' : 's'}?`)) {
        return;
    }

    const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
    const originalText = bulkDeleteBtn ? bulkDeleteBtn.innerHTML : '';
    if (bulkDeleteBtn) {
        bulkDeleteBtn.disabled = true;
        bulkDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Deleting...';
    }

    try {
        const response = await fetch('/pos-system/api/products/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids, permanent: true })
        });
        const result = await readJsonResponse(response);
        if (result.success) {
            showNotification(result.message || 'Selected products deleted', 'success');
            setTimeout(() => location.reload(), 1200);
        } else {
            throw new Error(result.message || 'Delete failed');
        }
    } catch (error) {
        showNotification('Error: ' + error.message, 'error');
        if (bulkDeleteBtn) {
            bulkDeleteBtn.disabled = false;
            bulkDeleteBtn.innerHTML = originalText;
        }
        updateBulkSelectionUI();
    }
}

const bulkDeleteButton = document.getElementById('bulk-delete-btn');
if (bulkDeleteButton) bulkDeleteButton.addEventListener('click', deleteSelectedProducts);
document.getElementById('delete-category-btn').addEventListener('click', deleteSelectedCategory);

// ---------- Clear button ----------
document.getElementById('clear-btn').addEventListener('click', clearForm);

// ---------- Real-time Search / Filter ----------
function debounce(func, wait) {
    let timeout;
    return function(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

let productCardIndex = null;
let filterRunToken = 0;
let duplicateRunToken = 0;

function getProductCardIndex() {
    if (productCardIndex) return productCardIndex;

    const cards = Array.from(document.querySelectorAll('.product-card'));
    productCardIndex = cards.map(card => {
        const nameEl = card.querySelector('.product-name');
        const codeEl = card.querySelector('.product-code');
        return {
            card,
            name: (nameEl ? nameEl.textContent : '').toLowerCase(),
            code: (codeEl ? codeEl.textContent : '').toLowerCase(),
            quantity: parseInt(card.getAttribute('data-quantity') || '0', 10),
            visible: true,
            matchHighlighted: false,
            duplicateHighlighted: card.classList.contains('duplicate-highlight')
        };
    });

    return productCardIndex;
}

function setNoResultsMessage(show) {
    const grid = document.getElementById('product-grid');
    if (!grid) return;

    // Don't show if stock filter is active (it has its own message)
    if (currentStockFilter !== null) return;
    
    let noResultsMsg = document.getElementById('no-search-results');
    if (show) {
        if (!noResultsMsg) {
            noResultsMsg = document.createElement('div');
            noResultsMsg.id = 'no-search-results';
            noResultsMsg.className = 'no-products-message';
            noResultsMsg.innerHTML = '<i class="fas fa-search"></i><p>No products match your search.</p>';
            grid.appendChild(noResultsMsg);
        }
    } else if (noResultsMsg) {
        noResultsMsg.remove();
    }
}

function initSearchFilter() {
    const searchInput = document.getElementById('product-search');
    if (!searchInput) return;

    const indexedCards = getProductCardIndex();
    const BATCH_SIZE = 250;

    const applyFilter = (searchTerm) => {
        const token = ++filterRunToken;
        let anyMatch = false;
        let i = 0;

        const processBatch = () => {
            if (token !== filterRunToken) return;

            const end = Math.min(i + BATCH_SIZE, indexedCards.length);
            for (; i < end; i++) {
                const entry = indexedCards[i];
                
                // Check text search
                const matchesText = searchTerm === '' || entry.name.includes(searchTerm) || entry.code.includes(searchTerm);
                // Check stock filter
                const matchesStock = currentStockFilter === null || entry.quantity <= currentStockFilter;
                const matches = matchesText && matchesStock;
                const shouldHighlight = matchesText && searchTerm !== '';

                if (entry.visible !== matches) {
                    entry.card.style.display = matches ? 'block' : 'none';
                    entry.visible = matches;
                }
                if (entry.matchHighlighted !== shouldHighlight) {
                    entry.card.classList.toggle('match-highlight', shouldHighlight);
                    entry.matchHighlighted = shouldHighlight;
                }

                if (matches) anyMatch = true;
            }

            if (i < indexedCards.length) {
                requestAnimationFrame(processBatch);
            } else {
                setNoResultsMessage(!anyMatch && searchTerm !== '');
                if (currentStockFilter !== null && !anyMatch && searchTerm !== '') {
                    setStockNoResultsMessage(true);
                } else if (currentStockFilter !== null && !anyMatch && searchTerm === '') {
                    setStockNoResultsMessage(true);
                } else {
                    setStockNoResultsMessage(false);
                }
            }
        };

        requestAnimationFrame(processBatch);
    };

    const filterProducts = debounce(function() {
        const raw = searchInput.value.trim().toLowerCase();
        const searchTerm = raw.length > 64 ? raw.slice(0, 64) : raw;
        applyFilter(searchTerm);
    }, 300);

    searchInput.addEventListener('input', filterProducts);
}

// ---------- Duplicate Check while Typing ----------
function initDuplicateCheck() {
    const nameInput = document.getElementById('product-name');
    const codeInput = document.getElementById('product-code');
    if (!nameInput && !codeInput) return;

    const indexedCards = getProductCardIndex();
    const BATCH_SIZE = 300;

    const runDuplicateCheck = debounce(function() {
        const searchName = nameInput ? nameInput.value.trim().toLowerCase() : '';
        const searchCode = codeInput ? codeInput.value.trim().toLowerCase() : '';
        const token = ++duplicateRunToken;

        let i = 0;
        const processBatch = () => {
            if (token !== duplicateRunToken) return;

            const end = Math.min(i + BATCH_SIZE, indexedCards.length);
            for (; i < end; i++) {
                const entry = indexedCards[i];
                const shouldHighlight = !!(
                    (searchName && entry.name.includes(searchName)) ||
                    (searchCode && entry.code.includes(searchCode))
                );

                if (entry.duplicateHighlighted !== shouldHighlight) {
                    entry.card.classList.toggle('duplicate-highlight', shouldHighlight);
                    entry.duplicateHighlighted = shouldHighlight;
                }
            }

            if (i < indexedCards.length) {
                requestAnimationFrame(processBatch);
            }
        };

        requestAnimationFrame(processBatch);
    }, 350);

    if (nameInput) nameInput.addEventListener('input', runDuplicateCheck);
    if (codeInput) codeInput.addEventListener('input', runDuplicateCheck);
}

// ---------- Split.js ----------
Split(['#panel-left', '#panel-right'], {
    sizes: [40, 60],
    minSize: [350, 300],
    gutterSize: 5,
    cursor: 'col-resize',
    gutter: () => {
        const gutter = document.createElement('div');
        gutter.className = 'gutter';
        return gutter;
    }
});

// ---------- Bulk Import Modal Logic (with immediate "other" prompt) ----------
function initImportModal() {
    console.log('Initializing import modal...');
    const modalEl = document.getElementById('importModal');
    if (!modalEl) {
        console.error('Import modal element not found!');
        return;
    }
    const modal = new bootstrap.Modal(modalEl);
    const step1 = document.getElementById('step1');
    const step2 = document.getElementById('step2');
    const step3 = document.getElementById('step3');
    const uploadForm = document.getElementById('importUploadForm');
    const backToUploadBtn = document.getElementById('backToUploadBtn');
    const backToMappingBtn = document.getElementById('backToMappingBtn');
    const processBtn = document.getElementById('processImportBtn');
    const confirmBtn = document.getElementById('confirmImportBtn');
    const mappingDiv = document.getElementById('columnMapping');
    const previewHeader = document.getElementById('previewHeader');
    const previewBody = document.getElementById('previewBody');
    const fileInfo = document.getElementById('fileInfo');
    const resultHeader = document.getElementById('resultHeader');
    const resultBody = document.getElementById('resultBody');
    const importSummary = document.getElementById('importSummary');

    // Store constant categories list (from PHP)
    const constantCategories = <?php echo json_encode($categoryNames); ?>;

    // Show modal on button click
    document.getElementById('bulkImportBtn').addEventListener('click', () => {
        console.log('Bulk import button clicked');
        modal.show();
        resetModal();
    });

    function resetModal() {
        step1.style.display = 'block';
        step2.style.display = 'none';
        step3.style.display = 'none';
        uploadForm.reset();
        uploadedFile = null;
        extractedData = null;
        currentMappings = [];
    }

    // Upload & Preview
    uploadForm.addEventListener('submit', function(e) {
        e.preventDefault();
        uploadedFile = document.getElementById('importFile').files[0];
        if (!uploadedFile) return;

        let formData = new FormData();
        formData.append('import_file', uploadedFile);

        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(readJsonResponse)
        .then(data => {
            Swal.close();
            if (data.error) {
                Swal.fire('Error', data.error, 'error');
                return;
            }

            console.log('Preview data received:', data);

            // Fill preview table
            previewHeader.innerHTML = '';
            previewBody.innerHTML = '';

            data.header.forEach((col, idx) => {
                let th = document.createElement('th');
                th.textContent = col ? col : `(Column ${idx+1})`;
                previewHeader.appendChild(th);
            });

            data.rows.forEach(row => {
                let tr = document.createElement('tr');
                row.forEach(cell => {
                    let td = document.createElement('td');
                    td.textContent = cell !== null && cell !== undefined ? cell : '';
                    tr.appendChild(td);
                });
                previewBody.appendChild(tr);
            });

            fileInfo.textContent = `Total rows: ${data.total}`;

            // Build mapping UI with fields (description removed)
            mappingDiv.innerHTML = '';
            const posFields = [
                { name: 'product_code', label: 'Product Code (Barcode)' },
                { name: 'name', label: 'Product Name' },
                { name: 'cost_price', label: 'Cost Price' },
                { name: 'wholesale_price', label: 'Wholesale Price' },
                { name: 'price', label: 'Selling Price' },
                { name: 'quantity', label: 'Quantity' },
                { name: 'category', label: 'Category' }
            ];

            posFields.forEach(field => {
                let div = document.createElement('div');
                div.className = 'mapping-row';

                if (field.name === 'category') {
                    // Category dropdown: constant values only
                    let selectHtml = `<label class="fw-bold">${field.label}:</label>
                        <select class="form-select form-select-sm map-select" data-field="${field.name}">
                            <option value="">-- Skip this field --</option>`;

                    // Column mapping options (so sequential mapping like B,C,D... works)
                    data.header.forEach((col, idx) => {
                        selectHtml += `<option value="${idx}">Column ${idx+1}: ${col ? col : '(empty)'}</option>`;
                    });

                    // Constant category options (same source as product form)
                    selectHtml += `<option value="" disabled>-- Categories --</option>`;
                    constantCategories.forEach(cat => {
                        selectHtml += `<option value="const-${cat}">${cat}</option>`;
                    });
                    selectHtml += `<option value="other">Other (specify new)…</option>`;
                    selectHtml += `</select>`;
                    div.innerHTML = selectHtml;
                } else {
                    // Normal dropdown with column numbers
                    let selectHtml = `<label class="fw-bold">${field.label}:</label>
                        <select class="form-select form-select-sm map-select" data-field="${field.name}">
                            <option value="">-- Skip this field --</option>`;
                    data.header.forEach((col, idx) => {
                        selectHtml += `<option value="${idx}">Column ${idx+1}: ${col ? col : '(empty)'}</option>`;
                    });
                    selectHtml += `</select>`;
                    div.innerHTML = selectHtml;
                }
                mappingDiv.appendChild(div);
            });

            // Sequential autofill: choosing the barcode column sets the next fields to the following columns.
            // Example: Barcode=B then Name=C, Cost=D, Wholesale=E, Price=F, Qty=G, Category=H (if present).
            const barcodeSelect = mappingDiv.querySelector('.map-select[data-field="product_code"]');
            if (barcodeSelect) {
                barcodeSelect.addEventListener('change', () => {
                    const start = parseInt(barcodeSelect.value, 10);
                    if (Number.isNaN(start)) return;

                    const fieldOrder = posFields.map(f => f.name);
                    for (let offset = 1; offset < fieldOrder.length; offset++) {
                        const fieldName = fieldOrder[offset];
                        if (fieldName === 'category') continue; // user wants category mapped manually
                        const sel = mappingDiv.querySelector(`.map-select[data-field="${fieldName}"]`);
                        if (!sel || sel.value !== '') continue; // don't override user choices

                        const idx = start + offset;
                        if (idx < 0 || idx >= data.header.length) break;

                        const idxStr = String(idx);
                        if (Array.from(sel.options).some(o => o.value === idxStr)) {
                            sel.value = idxStr;
                        }
                    }
                });
            }

            // Attach change event to category select for "other"
            const catSelect = mappingDiv.querySelector('.map-select[data-field="category"]');
            if (catSelect) {
                catSelect.addEventListener('change', async function(e) {
                    if (this.value === 'other') {
                        const { value: newCategory } = await Swal.fire({
                            title: 'Specify new category',
                            input: 'text',
                            inputLabel: 'Enter the new category name',
                            inputPlaceholder: 'e.g., Electronics',
                            target: modalEl,
                            showCancelButton: true,
                            didOpen: () => {
                                const input = Swal.getInput();
                                if (input) input.focus();
                            },
                            inputValidator: (value) => {
                                if (!value) return 'You need to enter a category name!';
                            }
                        });
                        if (newCategory) {
                            try {
                                const formData = new FormData();
                                formData.append('name', newCategory);
                                const res = await fetch('?action=add_category', {
                                    method: 'POST',
                                    body: formData
                                });
                                const result = await readJsonResponse(res);
                                if (result.success) {
                                    // Add to constantCategories list
                                    constantCategories.push(result.category.name);
                                    // Add new option to select and set it
                                    const newOption = document.createElement('option');
                                    newOption.value = `const-${result.category.name}`;
                                    newOption.text = result.category.name;
                                    // Insert before the "other" option
                                    const otherOption = Array.from(catSelect.options).find(opt => opt.value === 'other');
                                    catSelect.insertBefore(newOption, otherOption);
                                    catSelect.value = `const-${result.category.name}`;
                                    showNotification(`Category "${result.category.name}" created`, 'success');
                                } else {
                                    Swal.fire('Error', result.message, 'error');
                                }
                            } catch (error) {
                                console.error('Error adding category:', error);
                                Swal.fire('Error', 'Failed to create category', 'error');
                            }
                        } else {
                            // Reset to empty if cancelled
                            this.value = '';
                        }
                    }
                });
            }

            step1.style.display = 'none';
            step2.style.display = 'block';
        })
        .catch(err => {
            Swal.close();
            console.error('Upload error:', err);
            Swal.fire('Error', err.message, 'error');
        });
    });

    // Back to Upload
    backToUploadBtn.addEventListener('click', () => {
        step2.style.display = 'none';
        step1.style.display = 'block';
    });

    // Process Mapping (Preview Extracted Data)
    processBtn.addEventListener('click', async function() {
        let mappings = [];
        const selects = document.querySelectorAll('.map-select');
        for (let select of selects) {
            let field = select.dataset.field;
            let selectedValue = select.value;
            
            if (field === 'category') {
                if (selectedValue.startsWith('const-')) {
                    // Constant existing category
                    let catName = selectedValue.substring(6);
                    mappings.push({ field: field, constantValue: catName });
                } else if (selectedValue !== '' && !Number.isNaN(parseInt(selectedValue, 10))) {
                    // Column mapping
                    mappings.push({ field: field, colIndex: parseInt(selectedValue, 10) });
                } // else: skip / "other" handled by change event
            } else {
                if (selectedValue !== '') {
                    mappings.push({ field: field, colIndex: parseInt(selectedValue) });
                }
            }
        }

        if (mappings.length === 0) {
            Swal.fire('Warning', 'Please map at least one field.', 'warning');
            return;
        }

        if (!uploadedFile) {
            Swal.fire('Error', 'No file available. Please upload again.', 'error');
            return;
        }

        currentMappings = mappings;

        let formData = new FormData();
        formData.append('import_file', uploadedFile);
        formData.append('process_import', '1');
        formData.append('mappings', JSON.stringify(mappings));

        Swal.fire({ title: 'Processing...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(readJsonResponse)
        .then(data => {
            Swal.close();
            if (data.error) {
                Swal.fire('Error', data.error, 'error');
                return;
            }

            console.log('Processed data:', data);
            extractedData = data.data;

            // Build result table
            resultHeader.innerHTML = '';
            resultBody.innerHTML = '';

            // Header from mapped fields
            let tr = document.createElement('tr');
            mappings.forEach(m => {
                let th = document.createElement('th');
                th.textContent = m.field;
                tr.appendChild(th);
            });
            resultHeader.appendChild(tr);

            // Data rows
            data.data.forEach(item => {
                let tr = document.createElement('tr');
                mappings.forEach(m => {
                    let td = document.createElement('td');
                    td.textContent = item[m.field] !== undefined ? item[m.field] : '';
                    tr.appendChild(td);
                });
                resultBody.appendChild(tr);
            });

            importSummary.innerHTML = `✅ Found <strong>${data.count}</strong> rows with data.`;

            step2.style.display = 'none';
            step3.style.display = 'block';
        })
        .catch(err => {
            Swal.close();
            console.error('Process error:', err);
            Swal.fire('Error', err.message, 'error');
        });
    });

    // Back to Mapping
    backToMappingBtn.addEventListener('click', () => {
        step3.style.display = 'none';
        step2.style.display = 'block';
    });

    // Confirm & Save to Database
    confirmBtn.addEventListener('click', function() {
        if (!extractedData || extractedData.length === 0) {
            Swal.fire('Error', 'No data to import.', 'error');
            return;
        }

        const negativeRows = extractedData.reduce((acc, item, idx) => {
            if (item && item.quantity !== undefined && item.quantity !== '') {
                const q = parseFloat(String(item.quantity).replace(/,/g, ''));
                if (!isNaN(q) && q < 0) acc.push(idx + 1);
            }
            return acc;
        }, []);

        if (negativeRows.length) {
            Swal.fire({
                title: 'Negative quantities found',
                html: `Row(s) ${negativeRows.join(', ')} contain negative quantities.<br>Do you want to proceed and import them as-is?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Proceed anyway',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    performSave(true);
                }
            });
            return;
        }

        Swal.fire({
            title: 'Confirm Import',
            text: `Import ${extractedData.length} products? This will insert/update records.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, import'
        }).then((result) => {
            if (result.isConfirmed) {
                performSave(false);
            }
        });
    });

    function performSave(allowNegative = false) {
        let formData = new FormData();
        formData.append('save_import', '1');
        formData.append('import_data', JSON.stringify(extractedData));
        formData.append('allow_negative', allowNegative ? '1' : '0');

        Swal.fire({ title: 'Saving...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(readJsonResponse)
        .then(data => {
            Swal.close();
            if (data.error) {
                Swal.fire('Error', data.error, 'error');
                return;
            }
            const r = data.results;
            let errorHtml = '';
            if (r.errors && r.errors.length) {
                errorHtml = `<div class="mt-3 text-danger">Errors:<br>${r.errors.join('<br>')}</div>`;
            }
            Swal.fire({
                title: 'Import Complete',
                html: `
                    <p>✅ Inserted: ${r.inserted}</p>
                    <p>🔄 Updated: ${r.updated}</p>
                    <p>⏭️ Skipped: ${r.skipped}</p>
                    ${errorHtml}
                `,
                icon: r.errors.length ? 'warning' : 'success',
                showDenyButton: canDeleteProducts,
                confirmButtonText: 'Close',
                denyButtonText: 'Delete All Products',
                denyButtonColor: '#d33'
            }).then((result) => {
                if (result.isDenied) {
                    deleteAllProductsFromImport();
                } else {
                    modal.hide();
                    location.reload(); // Refresh product list
                }
            });
        })
        .catch(err => {
            Swal.close();
            console.error('Save error:', err);
            Swal.fire('Error', err.message, 'error');
        });
    }
}

async function deleteAllProductsFromImport() {
    const result = await Swal.fire({
        title: 'Delete All Products',
        text: 'This will permanently delete all products from the database. This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, delete all',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#d33'
    });

    if (!result.isConfirmed) {
        return;
    }

    Swal.fire({ title: 'Deleting...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        // Use query params because some servers drop DELETE bodies; API also checks $_REQUEST.
        const res = await fetch('/pos-system/api/products/delete.php?all=1&permanent=1', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await readJsonResponse(res);
        Swal.close();

        if (data.success) {
            Swal.fire('Done', data.message || 'All products were permanently deleted.', 'success').then(() => {
                const modalInstance = bootstrap.Modal.getInstance(document.getElementById('importModal'));
                if (modalInstance) modalInstance.hide();
                location.reload();
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to delete all products.', 'error');
        }
    } catch (err) {
        Swal.close();
        Swal.fire('Error', err.message || 'Failed to delete all products.', 'error');
    }
}

// ---------- Main Form Category "Other" handling ----------
function initCategoryOther() {
    const catSelect = document.getElementById('product-category');
    catSelect.addEventListener('change', function() {
        updateCategoryDeleteButton();
        if (this.value === 'other') {
            Swal.fire({
                title: 'Specify new category',
                input: 'text',
                inputLabel: 'Enter the new category name',
                inputPlaceholder: 'e.g., Electronics',
                showCancelButton: true,
                didOpen: () => {
                    const input = Swal.getInput();
                    if (input) input.focus();
                },
                inputValidator: (value) => {
                    if (!value) {
                        return 'You need to enter a category name!';
                    }
                }
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const newCategory = result.value.trim();
                    // Save to database via AJAX
                    try {
                        const formData = new FormData();
                        formData.append('name', newCategory);
                        const res = await fetch('?action=add_category', {
                            method: 'POST',
                            body: formData
                        });
                        const data = await readJsonResponse(res);
                        if (data.success) {
                            addCategoryOption(catSelect, data.category);
                            catSelect.value = data.category.name;
                            showNotification(`Category "${data.category.name}" created`, 'success');
                            updateCategoryDeleteButton();
                        } else {
                            Swal.fire('Error', data.message, 'error');
                        }
                    } catch (error) {
                        console.error('Error adding category:', error);
                        Swal.fire('Error', 'Failed to create category', 'error');
                    }
                } else {
                    // Reset to empty
                    catSelect.value = '';
                    updateCategoryDeleteButton();
                }
            });
        }
    });
}

// ---------- On page load ----------
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, initializing...');
    
    // Initialize stock search
    const searchStockBtn = document.getElementById('search-stock-btn');
    const clearStockSearchBtn = document.getElementById('clear-stock-search');
    const stockSearchInput = document.getElementById('stock-search-input');
    
    if (searchStockBtn) {
        searchStockBtn.addEventListener('click', performStockSearch);
    }
    
    if (clearStockSearchBtn) {
        clearStockSearchBtn.addEventListener('click', clearStockSearch);
    }
    
    if (stockSearchInput) {
        stockSearchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performStockSearch();
            }
        });
    }
    
    initSearchFilter();
    initDuplicateCheck();
    initProductListClick();
    initImportModal();
    initCategoryOther();
    setFormReadOnly(false);
    updateBulkSelectionUI();
    updateCategoryDeleteButton();
    const productNameInput = document.getElementById('product-name');
    const productCodeInput = document.getElementById('product-code');
    if (productNameInput) {
        productNameInput.addEventListener('input', () => autoGenerateProductCode(false));
    }
    if (productCodeInput) {
        productCodeInput.addEventListener('input', () => {
            if (currentProductId) {
                productCodeManuallyEdited = true;
                return;
            }
            const generated = buildProductCodeSuggestion(document.getElementById('product-name')?.value || '');
            const currentValue = productCodeInput.value.trim();
            productCodeManuallyEdited = currentValue !== '' && currentValue !== generated;
        });
    }
    
    // Focus on stock search input initially
    if (stockSearchInput) {
        stockSearchInput.focus();
    }
});
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>