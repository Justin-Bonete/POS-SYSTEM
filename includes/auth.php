<?php
// includes/auth.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged in user
 */
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? '',
            'role' => $_SESSION['user_role'] ?? 'cashier'
        ];
    }
    return null;
}

/**
 * Get user role
 */
function getUserRole() {
    return $_SESSION['user_role'] ?? 'guest';
}

/**
 * Check if user has specific role
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $userRole = $_SESSION['user_role'] ?? '';
    
    if (is_array($roles)) {
        return in_array($userRole, $roles);
    }
    
    return $userRole === $roles;
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return hasRole('admin');
}

/**
 * Check if user is manager
 */
function isManager() {
    return hasRole('manager');
}

/**
 * Check if user is cashier
 */
function isCashier() {
    return hasRole('cashier');
}

/**
 * Check if user can access a specific module
 */
function canAccess($module) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $role = $_SESSION['user_role'] ?? '';
    
    // Define permissions for each role
    $permissions = [
        'admin' => [
            'dashboard', 'sales', 'products', 'products_view', 'products_add', 'products_edit', 'products_delete',
            'categories', 'categories_add', 'categories_edit', 'categories_delete',
            'inventory', 'inventory_view', 'inventory_manage',
            'reports', 'users', 'users_manage', 'settings', 'history', 'sales_history', 'orders', 'analytics', 'all'
        ],
        'manager' => [
            // Limited manager (inventory) access
            'dashboard',
            'products', 'products_add',
            'inventory_manage'
        ],
        'cashier' => [
            'dashboard', 'sales', 'sales_history', 'orders'
        ]
    ];
    
    // Admin has access to everything
    if ($role === 'admin') {
        return true;
    }
    
    // Check if role has permission for this module
    return isset($permissions[$role]) && in_array($module, $permissions[$role]);
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: /pos-system/views/auth/login.php');
        exit();
    }
}

/**
 * Require specific role(s)
 */
function requireRole($roles) {
    requireAuth();
    
    if (!hasRole($roles)) {
        // Redirect to dashboard with error
        $_SESSION['error'] = 'You do not have permission to access this page.';
        header('Location: /pos-system/views/dashboard/index.php');
        exit();
    }
}

/**
 * Require admin role
 */
function requireAdmin() {
    requireRole('admin');
}

/**
 * Require manager role
 */
function requireManager() {
    requireRole('manager');
}

/**
 * Require cashier role
 */
function requireCashier() {
    requireRole('cashier');
}

/**
 * Require module access
 */
function requireAccess($module) {
    requireAuth();
    
    if (!canAccess($module)) {
        $_SESSION['error'] = 'Access denied. You do not have permission for this module.';
        header('Location: /pos-system/views/dashboard/index.php');
        exit();
    }
}

/**
 * Logout user
 */
function logout() {
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
    
    header('Location: /pos-system/views/auth/login.php');
    exit();
}

/**
 * Get error message
 */
function getError() {
    $error = $_SESSION['error'] ?? '';
    unset($_SESSION['error']);
    return $error;
}

/**
 * Get success message
 */
function getSuccess() {
    $success = $_SESSION['success'] ?? '';
    unset($_SESSION['success']);
    return $success;
}
