<?php
$pageTitle = 'Reports';
$pageScripts = ['https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Sale.php';

requireAuth();

$db = ConfigDatabase::getInstance()->getConnection();

// Date ranges
$today = date('Y-m-d');
$startOfWeek = date('Y-m-d', strtotime('monday this week'));
$endOfWeek = $today;
$startOfLastWeek = date('Y-m-d', strtotime('monday last week'));
$endOfLastWeek = date('Y-m-d', strtotime('sunday last week'));

$startOfMonth = date('Y-m-01');
$endOfMonth = date('Y-m-t');
$startOfLastMonth = date('Y-m-01', strtotime('first day of last month'));
$endOfLastMonth = date('Y-m-t', strtotime('last day of last month'));

function getPeriodSummary(PDO $db, $startDate, $endDate)
{
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0) AS revenue,
            COUNT(*) AS transactions,
            COALESCE(AVG(total_amount), 0) AS avg_ticket
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
          AND payment_status = 'paid'
    ");
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $base = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['revenue' => 0, 'transactions' => 0, 'avg_ticket' => 0];

    $mixStmt = $db->prepare("
        SELECT payment_method, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
          AND payment_status = 'paid'
        GROUP BY payment_method
    ");
    $mixStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $mix = [];
    while ($row = $mixStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['payment_method'] ?: 'other';
        $mix[$key] = (float)$row['total'];
    }

    return [
        'revenue' => (float)$base['revenue'],
        'transactions' => (int)$base['transactions'],
        'avg_ticket' => (float)$base['avg_ticket'],
        'payment_mix' => $mix,
    ];
}

function getDailyTotals(PDO $db, $startDate, $endDate)
{
    $stmt = $db->prepare("
        SELECT DATE(created_at) AS day, COALESCE(SUM(total_amount), 0) AS total
        FROM sales
        WHERE DATE(created_at) BETWEEN :start AND :end
          AND payment_status = 'paid'
        GROUP BY DATE(created_at)
        ORDER BY day
    ");
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $series = [];
    $cursor = new DateTime($startDate);
    $end = new DateTime($endDate);
    for ($date = clone $cursor; $date <= $end; $date->modify('+1 day')) {
        $series[$date->format('Y-m-d')] = 0;
    }
    foreach ($rows as $row) {
        $series[$row['day']] = (float)$row['total'];
    }

    $labels = [];
    $data = [];
    foreach ($series as $day => $value) {
        $labels[] = date('M j', strtotime($day));
        $data[] = $value;
    }

    return ['labels' => $labels, 'data' => $data];
}

function getTopProducts(PDO $db, $startDate, $endDate, $limit = 5)
{
    $stmt = $db->prepare("
        SELECT 
            si.product_name,
            SUM(si.quantity) AS qty,
            SUM(si.subtotal) AS revenue
        FROM sale_items si
        INNER JOIN sales s ON si.sale_id = s.id
        WHERE DATE(s.created_at) BETWEEN :start AND :end
          AND s.payment_status = 'paid'
        GROUP BY si.product_id, si.product_name
        ORDER BY revenue DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function calcGrowth($current, $previous)
{
    if ($previous <= 0) {
        return $current > 0 ? 100 : 0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

$weeklySummary = getPeriodSummary($db, $startOfWeek, $endOfWeek);
$lastWeekSummary = getPeriodSummary($db, $startOfLastWeek, $endOfLastWeek);
$monthlySummary = getPeriodSummary($db, $startOfMonth, $endOfMonth);
$lastMonthSummary = getPeriodSummary($db, $startOfLastMonth, $endOfLastMonth);

$weeklyGrowth = calcGrowth($weeklySummary['revenue'], $lastWeekSummary['revenue']);
$monthlyGrowth = calcGrowth($monthlySummary['revenue'], $lastMonthSummary['revenue']);

$weeklyTrend = getDailyTotals($db, $startOfWeek, $endOfWeek);
$monthlyTrend = getDailyTotals($db, $startOfMonth, $endOfMonth);

$weeklyTopProducts = getTopProducts($db, $startOfWeek, $endOfWeek);
$monthlyTopProducts = getTopProducts($db, $startOfMonth, $endOfMonth);

include __DIR__ . '/../layouts/header.php';
?>

<div class="reports-page">
    <div class="reports-hero">
        <div>
            <p class="eyebrow">Reports &amp; Analytics</p>
            <h1>Performance Summary</h1>
            <p class="subtext">
                Weekly: <?php echo date('M j', strtotime($startOfWeek)); ?> - <?php echo date('M j, Y', strtotime($endOfWeek)); ?>
                &nbsp;|&nbsp;
                Monthly: <?php echo date('M j', strtotime($startOfMonth)); ?> - <?php echo date('M j, Y', strtotime($endOfMonth)); ?>
            </p>
        </div>
        <div class="hero-actions">
            <a class="ghost-btn" href="/pos-system/views/dashboard/index.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <button class="primary-btn" onclick="window.print()"><i class="fas fa-print"></i> Export / Print</button>
        </div>
    </div>

    <div class="kpi-wrap">
        <div class="kpi-card highlight">
            <div class="kpi-label">Weekly Revenue</div>
            <div class="kpi-value"><?php echo formatCurrency($weeklySummary['revenue']); ?></div>
            <div class="kpi-foot">
                <span class="<?php echo $weeklyGrowth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                    <?php echo $weeklyGrowth >= 0 ? '&uarr;' : '&darr;'; ?> <?php echo abs($weeklyGrowth); ?>% vs last week
                </span>
                <span><?php echo $weeklySummary['transactions']; ?> transactions</span>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Weekly Avg Ticket</div>
            <div class="kpi-value"><?php echo formatCurrency($weeklySummary['avg_ticket']); ?></div>
            <div class="kpi-foot">Active days: <?php echo count(array_filter($weeklyTrend['data'], function ($v) { return $v > 0; })); ?>/<?php echo count($weeklyTrend['data']); ?></div>
        </div>
        <div class="kpi-card highlight alt">
            <div class="kpi-label">Monthly Revenue</div>
            <div class="kpi-value"><?php echo formatCurrency($monthlySummary['revenue']); ?></div>
            <div class="kpi-foot">
                <span class="<?php echo $monthlyGrowth >= 0 ? 'trend-up' : 'trend-down'; ?>">
                    <?php echo $monthlyGrowth >= 0 ? '&uarr;' : '&darr;'; ?> <?php echo abs($monthlyGrowth); ?>% vs last month
                </span>
                <span><?php echo $monthlySummary['transactions']; ?> transactions</span>
            </div>
        </div>
        <div class="kpi-card">
            <div class="kpi-label">Monthly Avg Ticket</div>
            <div class="kpi-value"><?php echo formatCurrency($monthlySummary['avg_ticket']); ?></div>
            <div class="kpi-foot">
                Payment leaders:
                <?php
                $mix = $monthlySummary['payment_mix'];
                arsort($mix);
                $topMethod = key($mix) ?: 'n/a';
                echo htmlspecialchars($topMethod);
                ?>
            </div>
        </div>
    </div>

    <div class="grid two-col">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Weekly Snapshot</p>
                    <h3>Operational pulse</h3>
                </div>
                <span class="pill"><?php echo date('M j', strtotime($startOfWeek)); ?> - <?php echo date('M j', strtotime($endOfWeek)); ?></span>
            </div>
            <div class="mini-stats">
                <div>
                    <p class="label">Revenue</p>
                    <p class="value"><?php echo formatCurrency($weeklySummary['revenue']); ?></p>
                </div>
                <div>
                    <p class="label">Transactions</p>
                    <p class="value"><?php echo number_format($weeklySummary['transactions']); ?></p>
                </div>
                <div>
                    <p class="label">Avg Ticket</p>
                    <p class="value"><?php echo formatCurrency($weeklySummary['avg_ticket']); ?></p>
                </div>
            </div>
            <canvas id="weeklyChart" height="140"></canvas>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Monthly Snapshot</p>
                    <h3>Month-to-date trajectory</h3>
                </div>
                <span class="pill"><?php echo date('M 1', strtotime($startOfMonth)); ?> - <?php echo date('M j', strtotime($endOfMonth)); ?></span>
            </div>
            <div class="mini-stats">
                <div>
                    <p class="label">Revenue</p>
                    <p class="value"><?php echo formatCurrency($monthlySummary['revenue']); ?></p>
                </div>
                <div>
                    <p class="label">Transactions</p>
                    <p class="value"><?php echo number_format($monthlySummary['transactions']); ?></p>
                </div>
                <div>
                    <p class="label">Avg Ticket</p>
                    <p class="value"><?php echo formatCurrency($monthlySummary['avg_ticket']); ?></p>
                </div>
            </div>
            <canvas id="monthlyChart" height="140"></canvas>
        </div>
    </div>

    <div class="grid two-col">
        <div class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Payment Mix (Month)</p>
                    <h3>Channel Distribution</h3>
                </div>
            </div>
            <canvas id="paymentChart" height="220"></canvas>
            <div class="legend">
                <?php foreach ($monthlySummary['payment_mix'] as $method => $total): ?>
                    <div class="legend-item">
                        <span class="dot"></span>
                        <span class="legend-label"><?php echo htmlspecialchars(ucfirst($method)); ?></span>
                        <span class="legend-value"><?php echo formatCurrency($total); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($monthlySummary['payment_mix'])): ?>
                    <p class="empty-state">No paid sales recorded for this month yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div>
                    <p class="eyebrow">Top Products</p>
                    <h3>Weekly vs Monthly</h3>
                </div>
            </div>
            <div class="top-products">
                <div>
                    <p class="label">This Week</p>
                    <ul>
                        <?php foreach ($weeklyTopProducts as $item): ?>
                            <li>
                                <span><?php echo htmlspecialchars($item['product_name']); ?></span>
                                <span><?php echo (int)$item['qty']; ?> sold · <?php echo formatCurrency($item['revenue']); ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($weeklyTopProducts)): ?>
                            <li class="empty-state">No weekly product data</li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div>
                    <p class="label">This Month</p>
                    <ul>
                        <?php foreach ($monthlyTopProducts as $item): ?>
                            <li>
                                <span><?php echo htmlspecialchars($item['product_name']); ?></span>
                                <span><?php echo (int)$item['qty']; ?> sold · <?php echo formatCurrency($item['revenue']); ?></span>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($monthlyTopProducts)): ?>
                            <li class="empty-state">No monthly product data</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layouts/scripts.php'; ?>
<script>
const weeklyTrend = <?php echo json_encode($weeklyTrend); ?>;
const monthlyTrend = <?php echo json_encode($monthlyTrend); ?>;
const paymentMix = <?php echo json_encode($monthlySummary['payment_mix']); ?>;

function buildLineChart(ctxId, labels, data, gradientStart, gradientStop) {
    const ctx = document.getElementById(ctxId);
    if (!ctx) return;
    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 180);
    gradient.addColorStop(0, gradientStart);
    gradient.addColorStop(1, gradientStop);

    return new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data,
                fill: true,
                backgroundColor: gradient,
                borderColor: gradientStart,
                borderWidth: 2,
                tension: 0.35,
                pointRadius: 3,
                pointBackgroundColor: '#fff',
                pointBorderColor: gradientStart
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false } },
                y: { grid: { color: 'rgba(0,0,0,0.05)' } }
            }
        }
    });
}

function buildDoughnut(ctxId, labels, data) {
    const ctx = document.getElementById(ctxId);
    if (!ctx || labels.length === 0) return;
    const palette = ['#4361ee', '#06d6a0', '#ff9f43', '#f72585', '#6c757d'];
    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data,
                backgroundColor: palette.slice(0, labels.length),
                borderWidth: 0
            }]
        },
        options: {
            cutout: '60%',
            plugins: { legend: { display: false } }
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    buildLineChart('weeklyChart', weeklyTrend.labels, weeklyTrend.data, '#4361ee', 'rgba(67, 97, 238, 0.08)');
    buildLineChart('monthlyChart', monthlyTrend.labels, monthlyTrend.data, '#06d6a0', 'rgba(6, 214, 160, 0.08)');
    const labels = Object.keys(paymentMix || {});
    const data = Object.values(paymentMix || {});
    buildDoughnut('paymentChart', labels, data);
});
</script>

<style>
.reports-page {
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}
.reports-hero {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #1e1f36, #1f2b55);
    color: #fff;
    padding: 20px 24px;
    border-radius: 16px;
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.18);
}
.reports-hero h1 { margin: 6px 0; }
.reports-hero .subtext { color: rgba(255,255,255,0.75); }
.hero-actions { display: flex; gap: 10px; }
.primary-btn, .ghost-btn {
    border: none;
    outline: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.primary-btn { background: #06d6a0; color: #0b1727; }
.primary-btn:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(6,214,160,0.25); }
.ghost-btn { background: rgba(255,255,255,0.12); color: #fff; border: 1px solid rgba(255,255,255,0.25); }
.ghost-btn:hover { background: rgba(255,255,255,0.2); }
.eyebrow { text-transform: uppercase; letter-spacing: 0.12em; font-size: 12px; color: #6b7280; margin: 0; }
.subtext { margin: 0; font-size: 14px; color: #6b7280; }
.kpi-wrap {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
    gap: 14px;
}
.kpi-card {
    background: #fff;
    border-radius: 14px;
    padding: 14px 16px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.06);
    border: 1px solid #e5e7eb;
}
.kpi-card.highlight { border-color: #4361ee33; box-shadow: 0 10px 24px rgba(67,97,238,0.12); }
.kpi-card.highlight.alt { border-color: #06d6a033; box-shadow: 0 10px 24px rgba(6,214,160,0.12); }
.kpi-label { font-size: 13px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.08em; }
.kpi-value { font-size: 26px; font-weight: 700; margin: 6px 0; color: #1f2937; }
.kpi-foot { display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #6b7280; }
.trend-up { color: #06d6a0; font-weight: 600; }
.trend-down { color: #f97316; font-weight: 600; }
.grid.two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.panel {
    background: #fff;
    border-radius: 16px;
    padding: 16px 18px;
    box-shadow: 0 6px 22px rgba(0,0,0,0.08);
    border: 1px solid #e5e7eb;
}
.panel-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.panel h3 { margin: 2px 0 0; color: #111827; }
.pill {
    background: #f1f5f9;
    color: #475569;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px;
    border: 1px solid #e2e8f0;
}
.mini-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 10px 0; }
.mini-stats .label { color: #6b7280; font-size: 12px; margin: 0; }
.mini-stats .value { margin: 2px 0 0; font-size: 18px; font-weight: 700; color: #111827; }
.legend { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 8px; margin-top: 12px; }
.legend-item { display: flex; justify-content: space-between; align-items: center; background: #f9fafb; padding: 10px; border-radius: 10px; border: 1px solid #e5e7eb; }
.legend-label { color: #374151; }
.legend-value { color: #111827; font-weight: 600; }
.dot { width: 10px; height: 10px; border-radius: 50%; background: #4361ee; display: inline-block; margin-right: 8px; }
.top-products { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.top-products ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.top-products li { display: flex; justify-content: space-between; padding: 10px; border: 1px solid #e5e7eb; border-radius: 10px; background: #f9fafb; }
.empty-state { color: #9ca3af; font-style: italic; }
@media (max-width: 768px) {
    .reports-hero { flex-direction: column; align-items: flex-start; gap: 10px; }
    .hero-actions { width: 100%; flex-wrap: wrap; }
}
</style>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
