<?php
// sidebar.php
require_once __DIR__ . '/../../includes/auth.php';

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Helper function to check if menu item is active
function isActive($pages, $dirs = []) {
    global $currentPage, $currentDir;
    
    if (is_array($pages)) {
        if (in_array($currentPage, $pages)) return true;
    } else {
        if ($currentPage === $pages) return true;
    }
    
    if (!empty($dirs)) {
        if (is_array($dirs)) {
            if (in_array($currentDir, $dirs)) return true;
        } else {
            if ($currentDir === $dirs) return true;
        }
    }
    
    return false;
}
?>