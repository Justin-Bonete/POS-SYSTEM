<?php
// includes/constants.php

// Payment methods
define('PAYMENT_METHODS', [
    'cash' => 'Cash',
    'card' => 'Credit/Debit Card',
    'online' => 'Online Payment',
    'other' => 'Other'
]);

// Payment statuses
define('PAYMENT_STATUSES', [
    'paid' => 'Paid',
    'pending' => 'Pending',
    'cancelled' => 'Cancelled',
    'refunded' => 'Refunded'
]);

// Product statuses
define('PRODUCT_STATUSES', [
    'active' => 'Active',
    'inactive' => 'Inactive'
]);

// Category statuses
define('CATEGORY_STATUSES', [
    'active' => 'Active',
    'inactive' => 'Inactive'
]);

// Report periods
define('REPORT_PERIODS', [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'this_week' => 'This Week',
    'this_month' => 'This Month',
    'this_year' => 'This Year',
    'custom' => 'Custom Range'
]);

// Date formats
define('DATE_FORMAT', 'Y-m-d');
define('DATETIME_FORMAT', 'Y-m-d H:i:s');
define('DISPLAY_DATE_FORMAT', 'M d, Y');
define('DISPLAY_DATETIME_FORMAT', 'M d, Y h:i A');

// Pagination
define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// Tax rate (if applicable)
define('TAX_RATE', 0.00);

// Currency
define('CURRENCY_SYMBOL', '$');
define('CURRENCY_CODE', 'USD');

// User roles
define('USER_ROLES', [
    'admin' => 'Administrator',
    'manager' => 'Manager',
    'cashier' => 'Cashier'
]);
?>