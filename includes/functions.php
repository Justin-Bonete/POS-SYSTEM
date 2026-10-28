<?php
/**
 * functions.php - Backward compatibility layer
 * All common functions have been moved to helpers.php
 */

// Use __DIR__ to get absolute path
require_once __DIR__ . '/helpers.php';

// ========== FUNCTIONS UNIQUE TO THIS FILE ==========
// Only keep functions that are NOT in helpers.php

/**
 * Check if request is AJAX
 * @return bool True if AJAX request, false otherwise
 */
if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
}

/**
 * Generate a random string
 * @param int $length Length of random string
 * @return string Random string
 */
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

/**
 * Format phone number
 * @param string $phone Phone number to format
 * @return string Formatted phone number
 */
if (!function_exists('formatPhoneNumber')) {
    function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) == 10) {
            return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
        } elseif (strlen($phone) == 7) {
            return substr($phone, 0, 3) . '-' . substr($phone, 3, 4);
        } else {
            return $phone;
        }
    }
}

/**
 * Get file extension
 * @param string $filename Filename
 * @return string File extension
 */
if (!function_exists('getFileExtension')) {
    function getFileExtension($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}

/**
 * Check if string starts with a substring
 * @param string $haystack The string to search in
 * @param string $needle The substring to search for
 * @return bool True if starts with needle, false otherwise
 */
if (!function_exists('startsWith')) {
    function startsWith($haystack, $needle) {
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

/**
 * Check if string ends with a substring
 * @param string $haystack The string to search in
 * @param string $needle The substring to search for
 * @return bool True if ends with needle, false otherwise
 */
if (!function_exists('endsWith')) {
    function endsWith($haystack, $needle) {
        $length = strlen($needle);
        if ($length == 0) {
            return true;
        }
        return substr($haystack, -$length) === $needle;
    }
}

/**
 * Convert string to slug
 * @param string $string String to convert
 * @return string URL-friendly slug
 */
if (!function_exists('createSlug')) {
    function createSlug($string) {
        $string = strtolower($string);
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        return trim($string, '-');
    }
}

/**
 * Get percentage of a number
 * @param float $percentage Percentage value
 * @param float $total Total value
 * @return float Calculated percentage
 */
if (!function_exists('getPercentage')) {
    function getPercentage($percentage, $total) {
        return ($percentage / 100) * $total;
    }
}

/**
 * Calculate percentage between two numbers
 * @param float $part The part value
 * @param float $total The total value
 * @param int $decimals Number of decimal places
 * @return float Percentage value
 */
if (!function_exists('calculatePercentage')) {
    function calculatePercentage($part, $total, $decimals = 2) {
        if ($total == 0) {
            return 0;
        }
        return round(($part / $total) * 100, $decimals);
    }
}

/**
 * Log activity to file
 * @param string $message Log message
 * @param string $type Log type (info, error, warning)
 */
if (!function_exists('logActivity')) {
    function logActivity($message, $type = 'info') {
        $logFile = __DIR__ . '/../../logs/activity.log';
        $logDir = dirname($logFile);
        
        // Create logs directory if it doesn't exist
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$type] $message" . PHP_EOL;
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}

/**
 * Get client IP address
 * @return string Client IP address
 */
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

/**
 * Check if value is valid email
 * @param string $email Email to validate
 * @return bool True if valid email, false otherwise
 */
if (!function_exists('isValidEmail')) {
    function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

/**
 * Check if value is valid URL
 * @param string $url URL to validate
 * @return bool True if valid URL, false otherwise
 */
if (!function_exists('isValidURL')) {
    function isValidURL($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

/**
 * Convert array to object
 * @param array $array Array to convert
 * @return object Converted object
 */
if (!function_exists('arrayToObject')) {
    function arrayToObject($array) {
        return json_decode(json_encode($array));
    }
}

/**
 * Convert object to array
 * @param object $object Object to convert
 * @return array Converted array
 */
if (!function_exists('objectToArray')) {
    function objectToArray($object) {
        return json_decode(json_encode($object), true);
    }
}

/**
 * Get current page URL
 * @return string Current page URL
 */
if (!function_exists('getCurrentURL')) {
    function getCurrentURL() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $uri = $_SERVER['REQUEST_URI'];
        return $protocol . '://' . $host . $uri;
    }
}

/**
 * Flash message helper
 * @param string $name Message name
 * @param string $message Message content
 * @param string $class CSS class
 * @return string|null Flash message
 */
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

// Remove these functions as they're now in helpers.php:
// - generateInvoiceNumber() 
// - getTimeAgo() 
// - sanitizeInput() 
// - redirect()
?>