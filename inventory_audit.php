<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';
$db = ConfigDatabase::getInstance()->getConnection();

function queryAll($db, $sql) {
    $stmt = $db->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$summary = [];
$summary[] = ['label' => 'products', 'value' => $db->query('SELECT COUNT(*) FROM products')->fetchColumn()];
$summary[] = ['label' => 'product_history', 'value' => $db->query('SELECT COUNT(*) FROM product_history')->fetchColumn()];

$rows = queryAll($db, "SELECT p.id, p.name, p.quantity, COALESCE(SUM(ph.quantity_change),0) AS history_net, COALESCE(SUM(CASE WHEN ph.action_type='stock_in' THEN ph.quantity_change WHEN ph.action_type='stock_out' THEN -ph.quantity_change ELSE ph.quantity_change END), 0) AS history_adj FROM products p LEFT JOIN product_history ph ON ph.product_id = p.id GROUP BY p.id ORDER BY ABS(p.quantity - COALESCE(SUM(ph.quantity_change),0)) DESC LIMIT 20");

// fallback if quantity_change not stored consistently
$rows2 = queryAll($db, "SELECT p.id, p.name, p.quantity, COUNT(ph.id) AS history_count, SUM(ph.quantity_change IS NULL) AS null_changes FROM products p LEFT JOIN product_history ph ON ph.product_id = p.id GROUP BY p.id ORDER BY history_count DESC LIMIT 20");

foreach ($summary as $item) {
    echo $item['label'] . ': ' . $item['value'] . PHP_EOL;
}

echo PHP_EOL . "Top 20 product quantity differences (quantity vs history_net):" . PHP_EOL;
foreach ($rows as $row) {
    echo sprintf("id=%s name=%s qty=%s history_net=%s diff=%s\n", $row['id'], $row['name'], $row['quantity'], $row['history_net'], $row['quantity'] - $row['history_net']);
}

echo PHP_EOL . "Product history null/zero summary:" . PHP_EOL;
foreach ($rows2 as $row) {
    echo sprintf("id=%s name=%s qty=%s history_count=%s null_changes=%s\n", $row['id'], $row['name'], $row['quantity'], $row['history_count'], $row['null_changes']);
}
