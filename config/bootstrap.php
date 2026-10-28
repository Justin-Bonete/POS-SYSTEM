<?php
// config/bootstrap.php
session_start();

define('BASE_PATH', dirname(__DIR__));
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('CLASSES_PATH', BASE_PATH . '/classes');
define('CONFIG_PATH', BASE_PATH . '/config');
define('VIEWS_PATH', BASE_PATH . '/views');

// Load core files
require_once INCLUDES_PATH . '/helpers.php';
require_once CONFIG_PATH . '/database.php';

// Auto-load classes (optional)
spl_autoload_register(function ($class) {
    $file = CLASSES_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
?>