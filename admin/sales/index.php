<?php
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');

$db = cvcarts_db();
$user = cvcarts_user();
$alerts = cvcarts_admin_alerts($db);

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$period = is_string($_GET['period'] ?? null) ? $_GET['period'] : 'specific';
$dateRange = [
    'week' => "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    'month' => "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)",
    'year' => "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)",
];

if ($period !== 'specific' && !isset($dateRange[$period])) {
    $period = 'specific';
}

$monthInput = $_GET['month'] ?? null;
$month = is_string($monthInput) || is_int($monthInput)
    ? filter_var($monthInput, FILTER_VALIDATE_INT)
    : false;
if ($month === false || $month < 1 || $month > 12) {
    $month = $currentMonth;
}

$yearQuery = $db->query('SELECT DISTINCT YEAR(created_at) FROM orders WHERE created_at IS NOT NULL ORDER BY YEAR(created_at) DESC');
$reportYears = array_map('intval', $yearQuery->fetchAll(PDO::FETCH_COLUMN));
if (!in_array($currentYear, $reportYears, true)) {
    array_unshift($reportYears, $currentYear);
}
$reportYears = array_values(array_unique($reportYears));
$yearInput = $_GET['year'] ?? null;
$year = is_string($yearInput) || is_int($yearInput)
    ? filter_var($yearInput, FILTER_VALIDATE_INT)
    : false;
if ($year === false || !in_array($year, $reportYears, true)) {
    $year = $currentYear;
}

$queryParams = [];
$whereParts = ['o.payment_status = "Paid"', 'o.order_status <> "Cancelled"'];
if ($period === 'specific') {
    $rangeStart = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $rangeEnd = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
        ->modify('first day of next month')
        ->format('Y-m-d 00:00:00');
    $whereParts[] = 'o.created_at >= :range_start AND o.created_at < :range_end';
    $queryParams = ['range_start' => $rangeStart, 'range_end' => $rangeEnd];
} else {
    $whereParts[] = $dateRange[$period];
}
$where = implode(' AND ', $whereParts);

$statsStmt = $db->prepare(
    'SELECT COUNT(*) AS order_count, COALESCE(SUM(o.total_amount), 0) AS revenue
     FROM orders o WHERE ' . $where
);
$statsStmt->execute($queryParams);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$paymentStmt = $db->prepare(
    'SELECT o.payment_method, COUNT(*) AS count, COALESCE(SUM(o.total_amount), 0) AS revenue
     FROM orders o
     WHERE ' . $where . '
     GROUP BY o.payment_method
     ORDER BY revenue DESC'
);
$paymentStmt->execute($queryParams);
$paymentMethods = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

$transactionSql = '
    SELECT o.reference_number, o.payment_method, o.total_amount, o.created_at,
           u.first_name, u.last_name
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE ' . $where . '
    ORDER BY o.created_at DESC';

$stmt = $db->prepare($transactionSql . ' LIMIT 50');
$stmt->execute($queryParams);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
$periodLabel = $period === 'specific'
    ? date('F', mktime(0, 0, 0, (int)$month, 1)) . ' ' . $year
    : [
        'week' => 'Last 7 days',
        'month' => 'Last month',
        'year' => 'Last year',
    ][$period];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Sales</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))); ?>">
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/admin/sales.css')); ?>">
</head>
<body>
    <?php cvcarts_admin_header($user, $alerts); ?>

    <div class="app-layout">
        <?php cvcarts_admin_sidebar('sales'); ?>

        <main class="main-content">
            <div class="sales-page-heading">
                <div class="page-heading">
                    <div>
                        <h1>Sales Report</h1>
                        <p>Recognized revenue from paid, non-cancelled orders.</p>
                    </div>
                </div>
                <p class="sales-print-period">Report period: <?php echo cvcarts_h($periodLabel); ?></p>
                <form method="get" class="sales-toolbar" data-filter-form>
                    <label class="sales-period-field" for="sales-period">Reporting period
                        <select class="form-select" id="sales-period" name="period">
                            <option value="specific" <?php echo $period === 'specific' ? 'selected' : ''; ?>>Specific month and year</option>
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Last month</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Last year</option>
                        </select>
                    </label>
                    <label class="sales-period-field" for="sales-month">Month
                        <select class="form-select" id="sales-month" name="month" <?php echo $period !== 'specific' ? 'disabled' : ''; ?>>
                            <?php foreach ([
                                1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                                9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
                            ] as $monthNumber => $monthName): ?>
                                <option value="<?php echo $monthNumber; ?>" <?php echo (int)$month === $monthNumber ? 'selected' : ''; ?>><?php echo $monthName; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="sales-period-field" for="sales-year">Year
                        <select class="form-select" id="sales-year" name="year" <?php echo $period !== 'specific' ? 'disabled' : ''; ?>>
                            <?php foreach ($reportYears as $reportYear): ?>
                                <option value="<?php echo (int)$reportYear; ?>" <?php echo (int)$year === (int)$reportYear ? 'selected' : ''; ?>><?php echo (int)$reportYear; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button class="btn" type="submit" data-loading-label="Applying…">Apply Filters</button>
                        <a href="<?php echo cvcarts_h(cvcarts_url('/admin/sales/index.php')); ?>" class="btn btn-secondary" data-filter-reset>Reset Filters</a>
                        <button class="btn btn-secondary" type="button" onclick="window.print()">
                            <i class="bi bi-printer" aria-hidden="true"></i> Print Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- Revenue and paid-order summary -->
            <section class="sales-stats" aria-label="Sales overview">
                <article class="stat-card stat-card-emerald">
                    <div class="stat-icon"><i class="bi bi-currency-exchange" aria-hidden="true"></i></div>
                    <p>Total Revenue</p>
                    <h2><?php echo cvcarts_format_money((float) $stats['revenue']); ?></h2>
                </article>

                <article class="stat-card stat-card-blue">
                    <div class="stat-icon"><i class="bi bi-receipt" aria-hidden="true"></i></div>
                    <p>Paid Orders</p>
                    <h2><?php echo number_format((int) $stats['order_count']); ?></h2>
                </article>
            </section>

            <!-- Payment method and revenue breakdown -->
            <section class="sales-grid" aria-label="Sales breakdown">
                <article class="panel sales-breakdown-panel">
                    <div class="panel-heading">
                        <h2>Sales by Payment Method</h2>
                    </div>

                    <div class="info-list">
                        <?php if (!$paymentMethods): ?>
                            <div class="info-row">
                                <strong>No paid sales for this period.</strong>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($paymentMethods as $method): ?>
                            <div class="info-row payment-method-row">
                                <div>
                                    <strong><?php echo cvcarts_h($method['payment_method'] ?: 'Unspecified'); ?></strong>
                                    <small><?php echo number_format((int) $method['count']); ?> transactions</small>
                                </div>
                                <span class="stock-pill"><?php echo cvcarts_format_money((float) $method['revenue']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="panel sales-breakdown-panel">
                    <div class="panel-heading">
                        <h2>Sales Summary</h2>
                    </div>

                    <div class="sales-summary">
                        <div class="sales-summary-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></div>
                        <div>
                            <strong><?php echo cvcarts_format_money((float) $stats['revenue']); ?></strong>
                            <p>Revenue recognized in the selected period.</p>
                        </div>
                    </div>
                </article>
            </section>

            <!-- Recent transactions -->
            <section class="panel sales-transactions">
                <div class="panel-heading">
                    <h2>Transaction History</h2>
                    <span class="sales-result-count">Latest <?php echo count($transactions); ?> transactions</span>
                </div>

                <div class="table-wrapper">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Order Code</th>
                                <th>Student</th>
                                <th>Payment Method</th>
                                <th class="currency-cell">Total</th>
                                <th>Transaction Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$transactions): ?>
                                <tr>
                                    <td colspan="5" class="sales-empty-state">No paid transactions found for this period.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?php echo cvcarts_h($transaction['reference_number']); ?></td>
                                    <td><?php echo cvcarts_h($transaction['first_name'] . ' ' . $transaction['last_name']); ?></td>
                                    <td><?php echo cvcarts_h($transaction['payment_method'] ?: '—'); ?></td>
                                    <td class="currency-cell"><?php echo cvcarts_format_money((float) $transaction['total_amount']); ?></td>
                                    <td><?php echo cvcarts_h(date('M j, Y', strtotime($transaction['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script>
        (function () {
            const period = document.getElementById('sales-period');
            const month = document.getElementById('sales-month');
            const year = document.getElementById('sales-year');

            if (period && month && year) {
                function updateDateFields() {
                    const disabled = period.value !== 'specific';
                    month.disabled = disabled;
                    year.disabled = disabled;
                }

                period.addEventListener('change', updateDateFields);
                updateDateFields();
            }
        }());
    </script>
</body>
</html>
