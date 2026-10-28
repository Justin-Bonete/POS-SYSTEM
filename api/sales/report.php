<?php
// api/sales/report.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../../includes/functions.php';
require_once '../../includes/constants.php';
require_once '../../includes/auth.php';
require_once '../../classes/SalesManager.php';

// Require authentication (only managers and admins)
requireRole('admin');

function salesReportDateRange($period) {
    $today = new DateTimeImmutable('today');

    switch ($period) {
        case 'today':
            $start = $end = $today;
            break;
        case 'yesterday':
            $start = $end = $today->modify('-1 day');
            break;
        case 'this_week':
            $start = $today->modify('monday this week');
            $end = $today;
            break;
        case 'this_month':
            $start = $today->modify('first day of this month');
            $end = $today;
            break;
        case 'this_year':
            $start = $today->setDate((int) $today->format('Y'), 1, 1);
            $end = $today;
            break;
        default:
            return null;
    }

    return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
}

function isSalesReportDate($date) {
    if (!is_string($date)) {
        return false;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

// Get parameters
$type = isset($_GET['type']) ? $_GET['type'] : 'summary';
$period = isset($_GET['period']) ? $_GET['period'] : 'today';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;

// Determine date range
if ($startDate !== null || $endDate !== null) {
    // Custom range provided
    if (!isSalesReportDate($startDate) || !isSalesReportDate($endDate) || $startDate > $endDate) {
        jsonResponse(false, null, 'Provide a valid start and end date in YYYY-MM-DD order', 400);
    }
    $range = ['start' => $startDate, 'end' => $endDate];
} else {
    // Use predefined period
    $range = salesReportDateRange($period);
    
    if (!$range) {
        jsonResponse(false, null, 'Invalid period specified', 400);
    }
}

// Generate report
$salesManager = new SalesManager();
$result = $salesManager->generateReport($range['start'], $range['end'], $type);

if ($result['success']) {
    // Add metadata
    $result['data']['period_type'] = $period;
    $result['data']['generated_at'] = date(DATETIME_FORMAT);
    $result['data']['generated_by'] = $_SESSION['username'] ?? 'System';
    
    jsonResponse(true, $result['data']);
} else {
    jsonResponse(false, null, $result['message'], 400);
}
?>