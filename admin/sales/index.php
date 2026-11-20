<?php
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');

$db = cvcarts_db();
$user = cvcarts_user();
$alerts = cvcarts_admin_alerts($db);

$period = is_string($_GET['period'] ?? null) ? $_GET['period'] : 'month';
$dateRange = [
    'week' => "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)",
    'month' => "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)",
    'year' => "o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)",
];

if (!isset($dateRange[$period])) {
    $period = 'month';
}

$where = 'o.payment_status = "Paid" AND o.order_status <> "Cancelled" AND ' . $dateRange[$period];

$statsStmt = $db->query(
    'SELECT COUNT(*) AS order_count, COALESCE(SUM(o.total_amount), 0) AS revenue
     FROM orders o WHERE ' . $where
);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

$paymentStmt = $db->query(
    'SELECT o.payment_method, COUNT(*) AS count, COALESCE(SUM(o.total_amount), 0) AS revenue
     FROM orders o
     WHERE ' . $where . '
     GROUP BY o.payment_method
     ORDER BY revenue DESC'
);
$paymentMethods = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

$transactionSql = '
    SELECT o.reference_number, o.payment_method, o.total_amount, o.created_at,
           u.first_name, u.last_name
    FROM orders o
    JOIN users u ON u.id = o.user_id
    WHERE ' . $where . '
    ORDER BY o.created_at DESC';

$stmt = $db->prepare($transactionSql . ' LIMIT 50');
$stmt->execute();
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportStmt = $db->prepare($transactionSql);
    $exportStmt->execute();
    $exportTransactions = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cvcarts-sales-' . $period . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Order Reference', 'Student', 'Payment Method', 'Amount', 'Transaction Date']);

    foreach ($exportTransactions as $transaction) {
        fputcsv($output, [
            $transaction['reference_number'],
            $transaction['first_name'] . ' ' . $transaction['last_name'],
            $transaction['payment_method'],
            number_format((float)$transaction['total_amount'], 2, '.', ''),
            $transaction['created_at'],
        ]);
    }

    fclose($output);
    exit;
}

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
    <link rel="stylesheet" href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')); ?>">
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
                <form method="get" class="sales-toolbar" data-filter-form>
                    <label class="sales-period-field" for="sales-period">Reporting period
                        <select class="form-select" id="sales-period" name="period">
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>Last month</option>
                            <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>Last year</option>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button class="btn" type="submit" data-loading-label="Applying…">Apply Filters</button>
                        <a href="<?php echo cvcarts_h(cvcarts_url('/admin/sales/index.php')); ?>" class="btn btn-secondary" data-filter-reset>Reset Filters</a>
                        <a href="<?php echo cvcarts_h(cvcarts_url('/admin/sales/index.php?period=' . rawurlencode($period) . '&export=csv')); ?>" class="btn btn-secondary">Export CSV</a>
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
</body>
</html>
