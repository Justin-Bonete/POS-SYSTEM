<?php
require 'views/school/_bootstrap.php';
$conn = schoolStoreConnect(true);
$res = $conn->query("SHOW TABLES LIKE 'school_inventory'");
echo ($res && $res->num_rows > 0) ? 'school_inventory_ready' : 'school_inventory_missing';
