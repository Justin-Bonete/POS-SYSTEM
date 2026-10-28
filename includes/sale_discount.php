<?php

function saleDiscountToFloat($value): float
{
    if (is_float($value) || is_int($value)) {
        return round((float) $value, 2);
    }

    if ($value === null) {
        return 0.0;
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($normalized === null || $normalized === '' || $normalized === '-' || $normalized === '.') {
        return 0.0;
    }

    return round((float) $normalized, 2);
}

function saleDiscountNormalizeMode($mode): string
{
    $normalized = strtolower(trim((string) $mode));
    if (in_array($normalized, ['retail', 'wholesale', 'cost'], true)) {
        return $normalized;
    }

    return 'retail';
}

function saleDiscountNormalizePercent($value): float
{
    $percent = saleDiscountToFloat($value);
    if ($percent < 0) {
        return 0.0;
    }
    if ($percent > 100) {
        return 100.0;
    }

    return round($percent, 2);
}

function saleDiscountComputeTotals(array $items, $requestedDiscountPercent = null, $requestedDiscountAmount = 0): array
{
    $subtotal = 0.0;
    $eligibleRetailSubtotal = 0.0;

    foreach ($items as $item) {
        $lineSubtotal = saleDiscountToFloat($item['subtotal'] ?? 0);
        $subtotal += $lineSubtotal;

        $mode = saleDiscountNormalizeMode($item['price_mode'] ?? 'retail');
        $isBalanceItem = !empty($item['is_balance_item']) || (int) ($item['product_id'] ?? 0) <= 0;

        if (!$isBalanceItem && $mode === 'retail') {
            $eligibleRetailSubtotal += $lineSubtotal;
        }
    }

    $percentWasProvided = $requestedDiscountPercent !== null && $requestedDiscountPercent !== '';
    if ($percentWasProvided) {
        $discountPercent = saleDiscountNormalizePercent($requestedDiscountPercent);
        $discountAmount = round($eligibleRetailSubtotal * ($discountPercent / 100), 2);
    } else {
        $requestedDiscount = max(0.0, saleDiscountToFloat($requestedDiscountAmount));
        $discountAmount = min($requestedDiscount, $eligibleRetailSubtotal);
        $discountPercent = $eligibleRetailSubtotal > 0
            ? round(($discountAmount / $eligibleRetailSubtotal) * 100, 2)
            : 0.0;
    }

    $discountAmount = min($discountAmount, $eligibleRetailSubtotal);
    $totalAmount = max($subtotal - $discountAmount, 0.0);

    return [
        'subtotal' => round($subtotal, 2),
        'eligible_retail_subtotal' => round($eligibleRetailSubtotal, 2),
        'discount_percent' => round($discountPercent, 2),
        'discount_amount' => round($discountAmount, 2),
        'total_amount' => round($totalAmount, 2),
    ];
}

function saleDiscountEnsureSalesColumn(PDO $db): void
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'discount_amount'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            $db->exec("ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount");
        }

        $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'discount_percent'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            $db->exec("ALTER TABLE sales ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER discount_amount");
        }
    } catch (Throwable $e) {
        error_log('Unable to ensure sales discount columns: ' . $e->getMessage());
    }
}
