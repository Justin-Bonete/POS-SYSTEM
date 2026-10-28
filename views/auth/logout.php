<?php
// logout.php
session_start();
session_destroy();
header('Location: /pos-system/views/auth/login.php');
exit();
?>