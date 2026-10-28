<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'POST';
require 'api/sales/complete-order.php';
?>
