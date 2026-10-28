<?php
// api/products/export.php
// Export product stocks to Excel (XLSX)

// Minimal error output to avoid corrupting binary stream
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../vendor/autoload.php';

require_once __DIR__ . '/../../includes/auth.php';
if (!isLoggedIn()) {
    http_response_code(401);
    exit('Unauthorized');
}
if (!canAccess('products')) {
    http_response_code(403);
    exit('Forbidden');
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

try {
    $product = new Product();
    // Get all active products (no limit)
    $products = $product->getAll(['status' => 'active'], 1, 0);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Header
    $sheet->setCellValue('A1', 'Product Name');
    $sheet->setCellValue('B1', 'Cost Price');
    $sheet->setCellValue('C1', 'Wholesale Price');
    $sheet->setCellValue('D1', 'Retail Price');
    $sheet->setCellValue('E1', 'Stock Count');

    // Rows
    $row = 2;
    foreach ($products as $p) {
        $sheet->setCellValue('A' . $row, $p['name'] ?? '');
        $sheet->setCellValue('B' . $row, isset($p['cost_price']) ? $p['cost_price'] : '');
        $sheet->setCellValue('C' . $row, isset($p['wholesale_price']) ? $p['wholesale_price'] : '');
        $sheet->setCellValue('D' . $row, isset($p['price']) ? $p['price'] : '');
        $sheet->setCellValue('E' . $row, isset($p['quantity']) ? $p['quantity'] : '');
        $row++;
    }

    // Auto-size columns A-E
    foreach (range('A', 'E') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = 'product_stocks_' . date('Ymd_His') . '.xlsx';

    // Clear any buffered output before sending binary
    if (ob_get_length()) ob_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo 'Export failed: ' . $e->getMessage();
    exit;
}
