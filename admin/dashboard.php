<?php
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/pages/admin_chrome.php';

cvcarts_require_login('admin');

$db = cvcarts_db();
$user = cvcarts_user();

$alerts = cvcarts_admin_alerts($db);
$flashes = cvcarts_get_flash();

/* =========================================
   GLOBAL MONTH AND YEAR FILTER
========================================= */

$currentDate = new DateTimeImmutable('today');
$currentYear = (int)$currentDate->format('Y');
$currentMonth = (int)$currentDate->format('n');

$firstOrderYear = $db->query(
    'SELECT MIN(YEAR(created_at)) FROM orders'
)->fetchColumn();

$firstYear = $firstOrderYear === null
    ? $currentYear
    : min((int)$firstOrderYear, $currentYear);

$monthInput = filter_var(
    $_GET['month'] ?? $currentMonth,
    FILTER_VALIDATE_INT
);

$yearInput = filter_var(
    $_GET['year'] ?? $currentYear,
    FILTER_VALIDATE_INT
);

$selectedMonth = (
    $monthInput !== false &&
    $monthInput >= 1 &&
    $monthInput <= 12
) ? $monthInput : $currentMonth;

$selectedYear = (
    $yearInput !== false &&
    $yearInput >= $firstYear &&
    $yearInput <= $currentYear
) ? $yearInput : $currentYear;

$monthStart = new DateTimeImmutable(
    sprintf('%04d-%02d-01', $selectedYear, $selectedMonth)
);

$monthEnd = $monthStart->modify('+1 month');

$rangeStart = $monthStart->format('Y-m-d H:i:s');
$rangeEnd = $monthEnd->format('Y-m-d H:i:s');

$selectedMonthName = $monthStart->format('F');

/* =========================================
   DASHBOARD STATISTICS
========================================= */

$statsQuery = $db->prepare(
    "SELECT
        COUNT(*) AS total_orders,

        COALESCE(
            SUM(CASE
                WHEN order_status = 'Pending'
                THEN 1 ELSE 0
            END), 0
        ) AS pending_orders,

        COALESCE(
            SUM(CASE
                WHEN payment_status = 'Paid'
                  AND order_status NOT IN ('Cancelled', 'Payment Rejected')
                THEN total_amount ELSE 0
            END), 0
        ) AS revenue

     FROM orders

     WHERE created_at >= :start
       AND created_at < :end"
);

$statsQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd
]);

$monthlyOrderStats = $statsQuery->fetch(PDO::FETCH_ASSOC) ?: [];

$stats = [
    'students' => (int)$db->query(
        "SELECT COUNT(*) FROM users WHERE role = 'student'"
    )->fetchColumn(),

    'products' => (int)$db->query(
        "SELECT COUNT(*) FROM products WHERE is_active = 1"
    )->fetchColumn(),

    'orders' => (int)($monthlyOrderStats['total_orders'] ?? 0),

    'pending' => (int)($monthlyOrderStats['pending_orders'] ?? 0),

    'verified' => 0,

    'revenue' => (float)($monthlyOrderStats['revenue'] ?? 0),

    'ready' => 0,

    'claimed' => 0,
];

/* VERIFIED PAYMENTS */

$verifiedQuery = $db->prepare(
    "SELECT COUNT(*)
     FROM orders
     WHERE payment_status = 'Paid'
       AND order_status <> 'Cancelled'
       AND verified_at >= :start
       AND verified_at < :end"
);

$verifiedQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd
]);

$stats['verified'] = (int)$verifiedQuery->fetchColumn();

/* READY TO CLAIM */

$readyQuery = $db->prepare(
    "SELECT COUNT(*)
     FROM orders
     WHERE payment_status = 'Paid'
       AND order_status = 'Ready to Claim'
       AND verified_at >= :start
       AND verified_at < :end"
);

$readyQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd
]);

$stats['ready'] = (int)$readyQuery->fetchColumn();

/* CLAIMED ORDERS */

$claimedQuery = $db->prepare(
    "SELECT COUNT(*)
     FROM orders
     WHERE order_status = 'Claimed'
       AND claim_date >= :start
       AND claim_date < :end"
);

$claimedQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd
]);

$stats['claimed'] = (int)$claimedQuery->fetchColumn();

/* =========================================
   MONTHLY SALES AND ORDERS OVERVIEW
========================================= */

$weeksInMonth = (int)ceil((int)$monthStart->format('t') / 7);
$weeklyOverview = array_fill(1, $weeksInMonth, [
    'orders' => 0,
    'revenue' => 0.0,
]);

$weeklyOverviewQuery = $db->prepare(
    "SELECT
        FLOOR((DAYOFMONTH(created_at) - 1) / 7) + 1 AS week_number,
        COUNT(*) AS order_count,
        COALESCE(
            SUM(CASE
                WHEN payment_status = 'Paid'
                  AND order_status NOT IN ('Cancelled', 'Payment Rejected')
                THEN total_amount ELSE 0
            END), 0
        ) AS revenue
     FROM orders
     WHERE created_at >= :start
       AND created_at < :end
     GROUP BY week_number
     ORDER BY week_number"
);
$weeklyOverviewQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd,
]);

foreach ($weeklyOverviewQuery->fetchAll(PDO::FETCH_ASSOC) as $week) {
    $weekNumber = (int)$week['week_number'];
    if (isset($weeklyOverview[$weekNumber])) {
        $weeklyOverview[$weekNumber] = [
            'orders' => (int)$week['order_count'],
            'revenue' => (float)$week['revenue'],
        ];
    }
}

$monthlyOrdersTotal = 0;
$monthlyRevenueTotal = 0.0;
$maxWeeklyOrders = 0;
$maxWeeklyRevenue = 0.0;

foreach ($weeklyOverview as $week) {
    $monthlyOrdersTotal += $week['orders'];
    $monthlyRevenueTotal += $week['revenue'];
    $maxWeeklyOrders = max($maxWeeklyOrders, $week['orders']);
    $maxWeeklyRevenue = max($maxWeeklyRevenue, $week['revenue']);
}

$chartSeries = [
    [
        'key' => 'revenue',
        'title' => 'Paid Sales',
        'total' => cvcarts_format_money($monthlyRevenueTotal),
        'max' => $maxWeeklyRevenue,
        'class' => 'sales',
        'format' => static function ($value): string {
            return cvcarts_format_money((float)$value);
        },
    ],
    [
        'key' => 'orders',
        'title' => 'Orders Placed',
        'total' => number_format($monthlyOrdersTotal),
        'max' => $maxWeeklyOrders,
        'class' => 'orders',
        'format' => static function ($value): string {
            return number_format((int)$value);
        },
    ],
];

/* =========================================
   RECENT ORDERS
========================================= */

$recentOrdersQuery = $db->prepare(
    "SELECT
        o.id,
        o.reference_number,
        u.first_name,
        u.middle_name,
        u.last_name,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.created_at,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT CONCAT(p.name, ' × ', oi.quantity)
                ORDER BY p.name
                SEPARATOR ', '
            ),
            'No items'
        ) AS items
     FROM orders o
     INNER JOIN users u ON u.id = o.user_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     LEFT JOIN products p ON p.id = oi.product_id
     WHERE o.created_at >= :start
       AND o.created_at < :end
     GROUP BY
        o.id,
        o.reference_number,
        u.first_name,
        u.middle_name,
        u.last_name,
        o.payment_method,
        o.payment_status,
        o.order_status,
        o.created_at
     ORDER BY
        CASE
            WHEN o.payment_status = 'Pending Verification'
              OR (o.payment_method = 'Cash' AND o.payment_status = 'Unpaid' AND o.order_status = 'Pending')
            THEN 0 ELSE 1
        END,
        o.created_at DESC,
        o.id DESC
     LIMIT 6"
);
$recentOrdersQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd,
]);
$recentOrders = $recentOrdersQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   TOP 3 BEST-SELLING PRODUCTS
========================================= */

$bestProductsQuery = $db->prepare(
    "SELECT
        p.id,
        p.name,
        SUM(oi.quantity) AS quantity_sold,
        COUNT(DISTINCT o.id) AS order_count

     FROM orders o

     INNER JOIN order_items oi
        ON oi.order_id = o.id

     INNER JOIN products p
        ON p.id = oi.product_id

     WHERE o.payment_status = 'Paid'
       AND o.order_status NOT IN (
            'Cancelled',
            'Payment Rejected'
       )
       AND o.created_at >= :start
       AND o.created_at < :end

     GROUP BY
        p.id,
        p.name

     ORDER BY
        quantity_sold DESC,
        order_count DESC,
        p.name ASC

     LIMIT 3"
);

$bestProductsQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd
]);

$bestProducts = $bestProductsQuery->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   TOP 3 STUDENT BUYERS
========================================= */

$topBuyersQuery = $db->prepare(
    "SELECT
        u.id,
        u.first_name,
        u.middle_name,
        u.last_name,

        COUNT(o.id) AS orders_placed,

        COALESCE(
            SUM(item_totals.quantity), 0
        ) AS quantity_purchased,

        COALESCE(
            SUM(o.total_amount), 0
        ) AS amount_spent

     FROM orders o

     INNER JOIN users u
        ON u.id = o.user_id
        AND u.role = 'student'

     LEFT JOIN (
        SELECT
            order_id,
            SUM(quantity) AS quantity
        FROM order_items
        GROUP BY order_id
     ) item_totals
        ON item_totals.order_id = o.id

     WHERE o.payment_status = 'Paid'
       AND o.order_status NOT IN (
            'Cancelled',
            'Payment Rejected'
       )
       AND o.created_at >= :start
       AND o.created_at < :end

     GROUP BY
        u.id,
        u.first_name,
        u.middle_name,
        u.last_name

     ORDER BY
        amount_spent DESC,
        orders_placed DESC,
        quantity_purchased DESC,
        u.id ASC

     LIMIT 3"
);

$topBuyersQuery->execute([
    'start' => $rangeStart,
    'end' => $rangeEnd
]);

$leaderboardTop = [];

foreach ($topBuyersQuery->fetchAll(PDO::FETCH_ASSOC) as $index => $buyer) {
    $buyer['rank'] = $index + 1;
    $leaderboardTop[] = $buyer;
}

$statCards = [
    [
        'label' => 'Total Students',
        'value' => number_format($stats['students']),
        'scope' => '',
        'icon' => 'bi-people',
        'tone' => 'neutral',
    ],
    [
        'label' => 'Total Products',
        'value' => number_format($stats['products']),
        'scope' => '',
        'icon' => 'bi-box-seam',
        'tone' => 'neutral',
    ],
    [
        'label' => 'Total Orders',
        'value' => number_format($stats['orders']),
        'scope' => '',
        'icon' => 'bi-bag-check',
        'tone' => 'neutral',
    ],
    [
        'label' => 'Pending Orders',
        'value' => number_format($stats['pending']),
        'scope' => '',
        'icon' => 'bi-hourglass-split',
        'tone' => 'amber',
    ],
    [
        'label' => 'Verified Payments',
        'value' => number_format($stats['verified']),
        'scope' => '',
        'icon' => 'bi-check-circle',
        'tone' => 'emerald',
    ],
    [
        'label' => 'Total Revenue (₱)',
        'value' => cvcarts_format_money($stats['revenue']),
        'scope' => '',
        'icon' => 'bi-currency-exchange',
        'tone' => 'emerald',
    ],
    [
        'label' => 'Ready to Claim',
        'value' => number_format($stats['ready']),
        'scope' => '',
        'icon' => 'bi-box2-heart',
        'tone' => 'blue',
    ],
    [
        'label' => 'Claimed Orders',
        'value' => number_format($stats['claimed']),
        'scope' => '',
        'icon' => 'bi-bag-check-fill',
        'tone' => 'emerald',
    ],
];

?>
<!DOCTYPE html>
<html lang="en" class="admin-responsive">

<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CVCarts | Admin Dashboard</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="<?php echo cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')); ?>"
    >

    <link
        rel="stylesheet"
        href="<?php echo cvcarts_h(cvcarts_url('/assets/css/admin/dashboard.css')); ?>"
    >
</head>

<body class="admin-dashboard-page">

<?php cvcarts_admin_header($user, $alerts); ?>

<div class="app-layout">

    <?php cvcarts_admin_sidebar('dashboard'); ?>

    <main class="main-content">

        <!-- =====================================
             PAGE HEADING
        ====================================== -->

        <div class="page-heading">

            <div class="dashboard-heading-copy">
                <h1>Admin Dashboard</h1>

                <p>
                    Welcome back,
                    <?php
                    echo cvcarts_h(
                        trim($user['first_name'] . ' ' . $user['last_name'])
                    );
                    ?>.
                </p>
            </div>

            <!-- GLOBAL MONTH AND YEAR FILTER -->

            <form
                class="dashboard-filter"
                data-filter-form
                method="get"
                action="<?php echo cvcarts_h(cvcarts_url('/admin/dashboard.php')); ?>"
            >

                <label
                    class="dashboard-filter-field"
                    for="dashboard-month"
                >
                    <span>Month</span>

                    <select
                        class="select-control"
                        id="dashboard-month"
                        name="month"
                    >

                        <?php for ($month = 1; $month <= 12; $month++): ?>

                            <option
                                value="<?php echo $month; ?>"
                                <?php echo $selectedMonth === $month ? 'selected' : ''; ?>
                            >
                                <?php
                                echo cvcarts_h(
                                    date('F', mktime(0, 0, 0, $month, 1))
                                );
                                ?>
                            </option>

                        <?php endfor; ?>

                    </select>
                </label>

                <label
                    class="dashboard-filter-field"
                    for="dashboard-year"
                >
                    <span>Year</span>

                    <select
                        class="select-control"
                        id="dashboard-year"
                        name="year"
                    >

                        <?php for ($year = $currentYear; $year >= $firstYear; $year--): ?>

                            <option
                                value="<?php echo $year; ?>"
                                <?php echo $selectedYear === $year ? 'selected' : ''; ?>
                            >
                                <?php echo $year; ?>
                            </option>

                        <?php endfor; ?>

                    </select>
                </label>

                <div class="filter-actions">
                    <button class="btn" type="submit" data-loading-label="Applying…">Apply Filters</button>
                    <a class="btn btn-secondary" data-filter-reset href="<?php echo cvcarts_h(cvcarts_url('/admin/dashboard.php')); ?>">Reset Filters</a>
                </div>

            </form>

        </div>

        <!-- =====================================
             FLASH ALERTS
        ====================================== -->

        <?php foreach ($flashes as $flash): ?>

            <div
                class="note-box"
                data-app-alert="true"
                data-alert-type="<?php echo cvcarts_h($flash['type']); ?>"
                data-alert-title="<?php
                    echo $flash['type'] === 'error'
                        ? 'Something Went Wrong'
                        : ($flash['type'] === 'warning' ? 'Warning' : 'Success');
                ?>"
                data-alert-message="<?php echo cvcarts_h($flash['message']); ?>"
                role="status"
            >

                <div class="note-icon">✓</div>

                <div>
                    <h3><?php echo cvcarts_h(ucfirst($flash['type'])); ?></h3>

                    <p><?php echo cvcarts_h($flash['message']); ?></p>
                </div>

            </div>

        <?php endforeach; ?>

        <section class="dashboard-statistics" aria-labelledby="statistics-title">
            <div class="section-heading">
                <div>
                    <h2 id="statistics-title">Performance Overview</h2>
                    <p>
                        Students and products are all-time/current totals.
                        All other metrics show <?php echo cvcarts_h($selectedMonthName . ' ' . $selectedYear); ?>.
                    </p>
                </div>
            </div>

            <div class="stats-grid">
                <?php foreach ($statCards as $card): ?>
                    <article class="stat-card stat-card-<?php echo cvcarts_h($card['tone']); ?>">
                        <div class="stat-card-heading">
                            <p><?php echo cvcarts_h($card['label']); ?></p>
                            <span class="stat-card-icon" aria-hidden="true">
                                <i class="bi <?php echo cvcarts_h($card['icon']); ?>"></i>
                            </span>
                        </div>
                        <div class="stat-card-value">
                            <h3><?php echo cvcarts_h($card['value']); ?></h3>
                            <?php if ($card['scope'] !== ''): ?>
                                <span class="stat-scope"><?php echo cvcarts_h($card['scope']); ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="dashboard-content-grid">
            <div class="dashboard-left-column">
                <section class="panel dashboard-panel leaderboard-panel" aria-labelledby="products-ranking-title">
                    <div class="panel-heading dashboard-panel-heading">
                        <div>
                            <h2 id="products-ranking-title">Top 3 Best-Selling Products</h2>
                            <p><?php echo cvcarts_h($selectedMonthName . ' ' . $selectedYear); ?></p>
                        </div>
                        <span class="panel-heading-icon" aria-hidden="true"><i class="bi bi-trophy"></i></span>
                    </div>

                    <div class="ranking-list">
                        <?php for ($rank = 1; $rank <= 3; $rank++): ?>
                            <?php $product = $bestProducts[$rank - 1] ?? null; ?>
                            <article class="ranking-row <?php echo $product ? '' : 'ranking-row-empty'; ?>">
                                <span class="ranking-badge ranking-badge-<?php echo $rank; ?>">#<?php echo $rank; ?></span>
                                <?php if ($product): ?>
                                    <div class="ranking-main">
                                        <strong><?php echo cvcarts_h($product['name']); ?></strong>
                                        <span>
                                            <?php echo number_format((int)$product['quantity_sold']); ?> items
                                            <span aria-hidden="true">·</span>
                                            <?php echo number_format((int)$product['order_count']); ?> orders
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="ranking-main">
                                        <strong><?php echo $rank === 1 && !$bestProducts ? 'No sales yet' : 'No ranked product'; ?></strong>
                                        <span>For this month</span>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endfor; ?>
                    </div>
                </section>

                <section class="panel dashboard-panel leaderboard-panel" aria-labelledby="buyers-ranking-title">
                    <div class="panel-heading dashboard-panel-heading">
                        <div>
                            <h2 id="buyers-ranking-title">Top 3 Student Buyers</h2>
                            <p><?php echo cvcarts_h($selectedMonthName . ' ' . $selectedYear); ?></p>
                        </div>
                        <span class="panel-heading-icon" aria-hidden="true"><i class="bi bi-award"></i></span>
                    </div>

                    <div class="ranking-list">
                        <?php for ($rank = 1; $rank <= 3; $rank++): ?>
                            <?php $buyer = $leaderboardTop[$rank - 1] ?? null; ?>
                            <article class="ranking-row <?php echo $buyer ? '' : 'ranking-row-empty'; ?>">
                                <span class="ranking-badge ranking-badge-<?php echo $rank; ?>">#<?php echo $rank; ?></span>
                                <?php if ($buyer): ?>
                                    <?php
                                    $buyerName = trim(
                                        $buyer['first_name'] . ' ' .
                                        (!empty($buyer['middle_name']) ? $buyer['middle_name'] . ' ' : '') .
                                        $buyer['last_name']
                                    );
                                    ?>
                                    <div class="ranking-main">
                                        <strong><?php echo cvcarts_h($buyerName); ?></strong>
                                        <span>
                                            <?php echo number_format((int)$buyer['orders_placed']); ?> orders
                                            <span aria-hidden="true">·</span>
                                            <?php echo number_format((int)$buyer['quantity_purchased']); ?> items
                                        </span>
                                    </div>
                                    <strong class="ranking-total currency-cell"><?php echo cvcarts_h(cvcarts_format_money((float)$buyer['amount_spent'])); ?></strong>
                                <?php else: ?>
                                    <div class="ranking-main">
                                        <strong><?php echo $rank === 1 && !$leaderboardTop ? 'No buyers yet' : 'No ranked buyer'; ?></strong>
                                        <span>For this month</span>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endfor; ?>
                    </div>
                </section>
            </div>

            <div class="dashboard-right-column">
                <section class="panel dashboard-panel monthly-overview-panel" aria-labelledby="monthly-overview-title">
                    <div class="panel-heading dashboard-panel-heading">
                        <div>
                            <h2 id="monthly-overview-title">Monthly Sales &amp; Orders Overview</h2>
                            <p><?php echo cvcarts_h($selectedMonthName . ' ' . $selectedYear); ?></p>
                        </div>
                        <span class="panel-heading-icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></span>
                    </div>

                    <div
                        class="monthly-chart-scroll"
                        tabindex="0"
                        role="region"
                        aria-label="Weekly paid sales and orders for <?php echo cvcarts_h($selectedMonthName . ' ' . $selectedYear); ?>"
                    >
                        <?php if ($monthlyOrdersTotal === 0): ?>
                            <p class="monthly-chart-empty">No orders were placed in this month.</p>
                        <?php else: ?>
                            <div class="monthly-chart-inner">
                                <?php foreach ($chartSeries as $series): ?>
                                    <section class="monthly-chart-series monthly-chart-series-<?php echo cvcarts_h($series['class']); ?>">
                                        <div class="monthly-series-heading">
                                            <h3><?php echo cvcarts_h($series['title']); ?></h3>
                                            <span><?php echo cvcarts_h($series['total']); ?></span>
                                        </div>
                                        <div class="monthly-chart-plot">
                                            <div class="monthly-chart-gridlines" aria-hidden="true">
                                                <?php for ($line = 0; $line <= 4; $line++): ?>
                                                    <span style="bottom: <?php echo $line * 25; ?>%;"></span>
                                                <?php endfor; ?>
                                            </div>
                                            <div
                                                class="monthly-chart-bars"
                                                style="grid-template-columns: repeat(<?php echo count($weeklyOverview); ?>, minmax(0, 1fr));"
                                            >
                                                <?php foreach ($weeklyOverview as $weekNumber => $week): ?>
                                                    <?php
                                                    $value = $week[$series['key']];
                                                    $barHeight = $series['max'] > 0
                                                        ? ($value / $series['max']) * 100
                                                        : 0;
                                                    $formattedValue = ($series['format'])($value);
                                                    ?>
                                                    <div
                                                        class="monthly-chart-column"
                                                        role="img"
                                                        aria-label="<?php
                                                            echo cvcarts_h(
                                                                'Week ' . $weekNumber . ': ' . $formattedValue
                                                            );
                                                        ?>"
                                                        title="<?php
                                                            echo cvcarts_h(
                                                                'Week ' . $weekNumber . ': ' . $formattedValue
                                                            );
                                                        ?>"
                                                    >
                                                        <span
                                                            class="monthly-chart-value"
                                                            style="bottom: <?php echo number_format($barHeight, 2, '.', ''); ?>%;"
                                                            aria-hidden="true"
                                                        ><?php echo cvcarts_h($formattedValue); ?></span>
                                                        <span
                                                            class="monthly-chart-bar"
                                                            style="height: <?php echo number_format($barHeight, 2, '.', ''); ?>%;"
                                                            aria-hidden="true"
                                                        ></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div
                                            class="monthly-chart-labels"
                                            style="grid-template-columns: repeat(<?php echo count($weeklyOverview); ?>, minmax(0, 1fr));"
                                            aria-hidden="true"
                                        >
                                            <?php foreach ($weeklyOverview as $weekNumber => $week): ?>
                                                <span>Week <?php echo (int)$weekNumber; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="panel dashboard-panel recent-orders-panel" aria-labelledby="recent-orders-title">
                    <div class="panel-heading dashboard-panel-heading">
                        <div>
                            <h2 id="recent-orders-title">Recent Orders / Action Needed</h2>
                            <p>Orders from <?php echo cvcarts_h($selectedMonthName . ' ' . $selectedYear); ?></p>
                        </div>
                        <span class="panel-heading-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
                    </div>

                    <div class="recent-orders-scroll">
                        <table class="recent-orders-table">
                            <thead>
                                <tr>
                                    <th scope="col">Order ID</th>
                                    <th scope="col">Student</th>
                                    <th scope="col">Item</th>
                                    <th scope="col">Status</th>
                                    <th scope="col"><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recentOrders): ?>
                                    <tr>
                                        <td colspan="5" class="recent-orders-empty">
                                            No orders for this month.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <?php
                                        $studentName = trim(
                                            $order['first_name'] . ' ' .
                                            (!empty($order['middle_name']) ? $order['middle_name'] . ' ' : '') .
                                            $order['last_name']
                                        );
                                        $needsAction = $order['payment_status'] === 'Pending Verification'
                                            || (
                                                $order['payment_method'] === 'Cash'
                                                && $order['payment_status'] === 'Unpaid'
                                                && $order['order_status'] === 'Pending'
                                            );
                                        $orderViewUrl = cvcarts_url(
                                            '/admin/order-status/index.php?view=' . (int)$order['id']
                                        );
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo cvcarts_h($order['reference_number']); ?></strong>
                                            </td>
                                            <td><?php echo cvcarts_h($studentName); ?></td>
                                            <td class="recent-order-item" title="<?php echo cvcarts_h($order['items']); ?>">
                                                <?php echo cvcarts_h($order['items']); ?>
                                            </td>
                                            <td class="status-cell"><?php echo cvcarts_status_badge($order['order_status']); ?></td>
                                            <td class="action-cell">
                                                <a
                                                    class="recent-order-action <?php echo $needsAction ? 'recent-order-action-needed' : ''; ?>"
                                                    href="<?php echo cvcarts_h($orderViewUrl); ?>"
                                                ><?php echo $needsAction ? 'Review' : 'View'; ?></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

    </main>

</div>

</body>
</html>