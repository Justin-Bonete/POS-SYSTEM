<?php
// helpers.php - Main helper functions file for POS System

// ========== AUTH FUNCTIONS ==========

// ADD THIS MISSING FUNCTION FIRST
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin() {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    }
}

if (!function_exists('requireAuth')) {
    function requireAuth() {
        if (!isLoggedIn()) {
            header('Location: /pos-system/views/auth/login.php');
            exit();
        }
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        requireAuth();
        if (!isAdmin()) {
            header('Location: /pos-system/views/dashboard/index.php');
            exit();
        }
    }
}

if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        if (isLoggedIn()) {
            return [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'] ?? '',
                'role' => $_SESSION['user_role'] ?? 'cashier',
                'full_name' => $_SESSION['full_name'] ?? ''
            ];
        }
        return null;
    }
}

// ========== FORMATTING FUNCTIONS ==========

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '₱' . number_format($amount, 2);
    }
}

if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M d, Y') {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
}

if (!function_exists('formatDateTime')) {
    function formatDateTime($datetime, $format = 'M d, Y h:i A') {
        if (empty($datetime)) return '';
        return date($format, strtotime($datetime));
    }
}

if (!function_exists('productImageUrl')) {
    function productImageUrl($path) {
        $path = trim((string) ($path ?? ''));
        if ($path === '') {
            return '';
        }

        $path = str_replace('\\', '/', $path);

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (strpos($path, '/pos-system/') === 0) {
            return $path;
        }

        $path = ltrim($path, '/');
        if (strpos($path, 'pos-system/') === 0) {
            return '/' . $path;
        }

        return '/pos-system/' . $path;
    }
}

// ========== INVOICE FUNCTIONS ==========

if (!function_exists('generateInvoiceNumber')) {
    function generateInvoiceNumber() {
        return 'INV-' . date('Ymd') . '-' . strtoupper(uniqid());
    }
}

// ========== TIME FUNCTIONS ==========

if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $time = strtotime($datetime);
        $now = time();
        $diff = $now - $time;
        
        if ($diff < 60) {
            return $diff . ' seconds ago';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M d, Y', $time);
        }
    }
}

// Alias for backward compatibility
if (!function_exists('getTimeAgo')) {
    function getTimeAgo($datetime) {
        return timeAgo($datetime);
    }
}

// ========== UTILITY FUNCTIONS ==========

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map('sanitizeInput', $data);
        }
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}

if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: $url");
        exit();
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse($success, $data = null, $message = '', $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => $success,
            'data' => $data,
            'message' => $message
        ]);
        exit();
    }
}

if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
}

if (!function_exists('generateRandomString')) {
    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}

if (!function_exists('startsWith')) {
    function startsWith($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('endsWith')) {
    function endsWith($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return substr($haystack, -$length) === $needle;
    }
}

if (!function_exists('createSlug')) {
    function createSlug($string) {
        $string = strtolower($string);
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        return trim($string, '-');
    }
}

if (!function_exists('getPercentage')) {
    function getPercentage($percentage, $total) {
        return ($percentage / 100) * $total;
    }
}

if (!function_exists('calculatePercentage')) {
    function calculatePercentage($part, $total, $decimals = 2) {
        if ($total == 0) {
            return 0;
        }
        return round(($part / $total) * 100, $decimals);
    }
}

if (!function_exists('logActivity')) {
    function logActivity($message, $type = 'info') {
        $logFile = __DIR__ . '/../logs/activity.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $user = isset($_SESSION['username']) ? $_SESSION['username'] : 'System';
        $logEntry = "[$timestamp] [$type] [$user] $message" . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('getClientIP')) {
    function getClientIP() {
        $ipaddress = '';
        if (isset($_SERVER['HTTP_CLIENT_IP']))
            $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
        else if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
        else if (isset($_SERVER['HTTP_X_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
        else if (isset($_SERVER['HTTP_FORWARDED_FOR']))
            $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
        else if (isset($_SERVER['HTTP_FORWARDED']))
            $ipaddress = $_SERVER['HTTP_FORWARDED'];
        else if (isset($_SERVER['REMOTE_ADDR']))
            $ipaddress = $_SERVER['REMOTE_ADDR'];
        else
            $ipaddress = 'UNKNOWN';
        
        return $ipaddress;
    }
}

if (!function_exists('isValidEmail')) {
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('getCurrentURL')) {
    function getCurrentURL() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];
        return $protocol . '://' . $host . $uri;
    }
}

if (!function_exists('flash')) {
    function flash($name = '', $message = '', $class = 'alert alert-success') {
        if (!empty($name)) {
            if (!empty($message) && empty($_SESSION[$name])) {
                $_SESSION[$name] = $message;
                $_SESSION[$name . '_class'] = $class;
            } elseif (empty($message) && !empty($_SESSION[$name])) {
                $class = !empty($_SESSION[$name . '_class']) ? $_SESSION[$name . '_class'] : $class;
                echo '<div class="' . $class . '" id="msg-flash">' . $_SESSION[$name] . '</div>';
                unset($_SESSION[$name]);
                unset($_SESSION[$name . '_class']);
            }
        }
    }
}

// ========== STATUS FUNCTIONS ==========

if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        $badges = [
            'active' => 'success',
            'inactive' => 'secondary',
            'paid' => 'success',
            'pending' => 'warning',
            'cancelled' => 'danger',
            'refunded' => 'info',
            'cash' => 'primary',
            'card' => 'info',
            'online' => 'warning'
        ];
        
        return $badges[strtolower($status)] ?? 'secondary';
    }
}

// ========== DEBUG FUNCTIONS ==========

if (!function_exists('dd')) {
    function dd($data) {
        echo '<pre style="background: #f4f4f4; padding: 10px; border: 1px solid #ccc; margin: 10px;">';
        print_r($data);
        echo '</pre>';
        die();
    }
}

?>
