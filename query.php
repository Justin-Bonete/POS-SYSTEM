<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require 'config/database.php';
$db = ConfigDatabase::getInstance()->getConnection();
$id = 69;
$stmt = $db->prepare('select id,payment_status,total_amount,cash_amount from sales where id=?');
$stmt->execute([$id]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
