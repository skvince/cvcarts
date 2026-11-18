<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');

$db = cvcarts_db();
$admin = cvcarts_user();
$route = '/admin/users/index.php';
$alerts = cvcarts_admin_alerts($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cvcarts_require_csrf();
    $studentId = filter_var($_POST['student_id'] ?? '', FILTER_VALIDATE_INT);
    $action = $_POST['account_action'] ?? '';
    if (!$studentId || !in_array($action, ['activate', 'suspend'], true)) {
        cvcarts_flash('error', 'Choose a valid student account action.');
        cvcarts_redirect(cvcarts_url($route));
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare("SELECT id, email_verified_at, is_verified, is_active, account_status FROM users WHERE id = :id AND role = 'student' FOR UPDATE");
        $query->execute(['id' => $studentId]);
        $student = $query->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            $db->rollBack();
            cvcarts_flash('error', 'The student account was not found.');
            cvcarts_redirect(cvcarts_url($route));
        }
        if ($action === 'activate' && ((int)$student['is_verified'] !== 1 || !$student['email_verified_at'])) {
            $db->rollBack();
            cvcarts_flash('error', 'An email-unverified student account cannot be activated.');
            cvcarts_redirect(cvcarts_url($route));
        }
        $active = $action === 'activate' ? 1 : 0;
        $status = $active ? 'active' : 'suspended';
        $db->prepare('UPDATE users SET is_active = :is_active, account_status = :account_status, auth_version = auth_version + 1, updated_at = NOW() WHERE id = :id')
            ->execute(['is_active' => $active, 'account_status' => $status, 'id' => $studentId]);
        $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
            ->execute([
                'admin_id' => $admin['id'],
                'action' => $active ? 'Student account activated' : 'Student account suspended',
                'details' => 'Student ID ' . $studentId . ' account marked ' . $status . '.',
            ]);
        cvcarts_emit_realtime_event($db, 'users.updated', 'admin');
        $db->commit();
        cvcarts_flash('success', $active ? 'Student account activated.' : 'Student account suspended.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    cvcarts_redirect(cvcarts_url($route));
}

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$dateFrom = is_string($_GET['date_from'] ?? null) ? $_GET['date_from'] : '';
$dateTo = is_string($_GET['date_to'] ?? null) ? $_GET['date_to'] : '';
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest';
$sortOptions = ['newest' => 'u.created_at DESC', 'oldest' => 'u.created_at ASC', 'name_asc' => 'u.first_name ASC, u.last_name ASC', 'name_desc' => 'u.first_name DESC, u.last_name DESC', 'orders_desc' => 'order_count DESC, u.created_at DESC'];
if (!isset($sortOptions[$sort])) {
    $sort = 'newest';
}
$pageSizeInput = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT);
$pageSize = in_array($pageSizeInput, [10, 25, 50], true) ? $pageSizeInput : 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = ["u.role = 'student'"];
$params = [];
if (in_array($statusFilter, ['active', 'suspended', 'pending_verification'], true)) {
    $where[] = 'u.account_status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(u.first_name LIKE :first_name OR u.last_name LIKE :last_name OR u.email LIKE :email)';
    $term = '%' . $search . '%';
    $params += ['first_name' => $term, 'last_name' => $term, 'email' => $term];
}
if ($dateFrom !== '' || $dateTo !== '') {
    $dateFromValid = $dateFrom === '' || (DateTime::createFromFormat('!Y-m-d', $dateFrom) && DateTime::createFromFormat('!Y-m-d', $dateFrom)->format('Y-m-d') === $dateFrom);
    $dateToValid = $dateTo === '' || (DateTime::createFromFormat('!Y-m-d', $dateTo) && DateTime::createFromFormat('!Y-m-d', $dateTo)->format('Y-m-d') === $dateTo);
    if (!$dateFromValid || !$dateToValid || ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo)) {
        cvcarts_flash('error', 'Choose a valid registration date range.');
        $dateFrom = $dateTo = '';
    }
}
if ($dateFrom !== '') {
    $where[] = 'DATE(u.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(u.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$countQuery = $db->prepare('SELECT COUNT(*) FROM users u' . $whereSql);
$countQuery->execute($params);
$total = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $totalPages);
$studentQuery = $db->prepare(
    'SELECT u.id, u.first_name, u.middle_name, u.last_name, u.email, u.is_active, u.is_verified, u.account_status,
        u.email_verified_at, u.created_at, u.last_login_at, COUNT(o.id) AS order_count
     FROM users u LEFT JOIN orders o ON o.user_id = u.id' . $whereSql . '
     GROUP BY u.id ORDER BY ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $studentQuery->bindValue(':' . $key, $value);
}
$studentQuery->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$studentQuery->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$studentQuery->execute();
$students = $studentQuery->fetchAll(PDO::FETCH_ASSOC);
$flashes = cvcarts_get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Student Accounts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')) ?>">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/admin/users.css')) ?>">
</head>
<body>
    <?php cvcarts_admin_header($admin, $alerts); ?>
    <div class="app-layout">
        <?php cvcarts_admin_sidebar('users'); ?>
        <main class="main-content">
            <div class="page-heading"><div><h1>Student Accounts</h1><p>Review verified accounts and manage access.</p></div></div>
            <?php foreach ($flashes as $flash): ?><div class="flash-message <?= $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warning' ? 'warning' : '') ?>" data-app-alert="true" data-alert-type="<?= cvcarts_h($flash['type']) ?>" data-alert-title="<?= $flash['type'] === 'error' ? 'Something Went Wrong' : ($flash['type'] === 'warning' ? 'Warning' : 'Success') ?>" role="status"><?= cvcarts_h($flash['message']) ?></div><?php endforeach; ?>
            <section class="panel">
                <form class="filter-form" data-filter-form method="get" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
                    <label>Search<input class="form-control" type="search" name="q" value="<?= cvcarts_h($search) ?>" placeholder="Name or school email"></label>
                    <label>Account status<select class="select-control" name="status"><option value="">All statuses</option>
                        <?php foreach (['active', 'suspended', 'pending_verification'] as $status): ?><option value="<?= cvcarts_h($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= cvcarts_h(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?>
                    </select></label>
                    <label>Registered from<input class="form-control" type="date" name="date_from" value="<?= cvcarts_h($dateFrom) ?>"></label>
                    <label>Registered to<input class="form-control" type="date" name="date_to" value="<?= cvcarts_h($dateTo) ?>"></label>
                    <label>Sort<select class="select-control" name="sort"><option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option><option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A–Z</option><option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z–A</option><option value="orders_desc" <?= $sort === 'orders_desc' ? 'selected' : '' ?>>Most orders</option></select></label>
                    <div class="filter-actions">
                        <button class="btn" type="submit" data-loading-label="Applying…">Apply Filters</button>
                        <a class="btn btn-secondary" data-filter-reset href="<?= cvcarts_h(cvcarts_url($route)) ?>">Reset Filters</a>
                    </div>
                </form>
                <div class="table-wrapper"><table class="users-table">
                    <thead><tr><th>Student</th><th>Email</th><th>Verification</th><th>Account</th><th>Orders</th><th>Last login</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php if (!$students): ?><tr><td colspan="7">No student accounts found.</td></tr><?php endif; ?>
                    <?php foreach ($students as $student): ?>
                        <?php $verified = (int)$student['is_verified'] === 1 && $student['email_verified_at'] !== null; ?>
                        <tr>
                            <td><?= cvcarts_h(trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name'])) ?></td>
                            <td><?= cvcarts_h($student['email']) ?></td>
                            <td><?= $verified ? 'Verified' : 'Pending' ?></td>
                            <td><?= cvcarts_status_badge(ucwords(str_replace('_', ' ', (string)$student['account_status']))) ?></td>
                            <td class="numeric-cell"><?= (int)$student['order_count'] ?></td>
                            <td><?= $student['last_login_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($student['last_login_at']))) : '—' ?></td>
                            <td>
                                <button class="btn btn-small btn-secondary" type="button" data-modal-open="student-details-<?= (int)$student['id'] ?>">View Details</button>
                                <dialog id="student-details-<?= (int)$student['id'] ?>" class="app-modal" aria-labelledby="student-details-title-<?= (int)$student['id'] ?>">
                                    <div class="modal-shell">
                                        <header class="modal-header">
                                            <div><h2 id="student-details-title-<?= (int)$student['id'] ?>"><?= cvcarts_h(trim($student['first_name'] . ' ' . ($student['middle_name'] ?? '') . ' ' . $student['last_name'])) ?></h2><p>Student account details.</p></div>
                                            <button class="modal-close" type="button" data-modal-close aria-label="Close">×</button>
                                        </header>
                                        <div class="modal-body">
                                            <div class="summary-grid">
                                                <div><span>Email</span><strong><?= cvcarts_h($student['email']) ?></strong></div>
                                                <div><span>Email verification</span><strong><?= $verified ? 'Verified' : 'Pending' ?></strong></div>
                                                <div><span>Account status</span><strong><?= cvcarts_h(ucwords(str_replace('_', ' ', (string)$student['account_status']))) ?></strong></div>
                                                <div><span>Registered</span><strong><?= cvcarts_h(date('M j, Y g:i A', strtotime($student['created_at']))) ?></strong></div>
                                                <div><span>Orders</span><strong><?= (int)$student['order_count'] ?></strong></div>
                                                <div><span>Last login</span><strong><?= $student['last_login_at'] ? cvcarts_h(date('M j, Y g:i A', strtotime($student['last_login_at']))) : 'Never' ?></strong></div>
                                            </div>
                                        </div>
                                        <footer class="modal-footer"><button class="btn btn-small btn-secondary" type="button" data-modal-close>Close</button></footer>
                                    </div>
                                </dialog>
                                <?php if ($verified): ?>
                                    <form method="post" action="<?= cvcarts_h(cvcarts_url($route)) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= cvcarts_h(cvcarts_csrf_token()) ?>"><input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                                        <?php if ((int)$student['is_active'] === 1): ?><button class="btn btn-small btn-danger" name="account_action" value="suspend" type="submit" data-confirm="Suspend <?= cvcarts_h(trim($student['first_name'] . ' ' . $student['last_name'])) ?>? The student will no longer be able to sign in." data-confirm-title="Suspend student?" data-confirm-label="Suspend Account" data-confirm-type="error">Suspend</button>
                                        <?php else: ?><button class="btn btn-small" name="account_action" value="activate" type="submit" data-confirm="Activate <?= cvcarts_h(trim($student['first_name'] . ' ' . $student['last_name'])) ?>?">Activate</button><?php endif; ?>
                                    </form>
                                <?php else: ?>Email verification required<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php $accountParams = ['q' => $search, 'status' => $statusFilter, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'sort' => $sort, 'per_page' => $pageSize]; ?>
                <nav class="pagination" aria-label="Student account pages">
                    <span>Page <?= $page ?> of <?= $totalPages ?> · <?= number_format($total) ?> accounts</span>
                    <div class="pagination-pages">
                        <?php if ($page > 1): ?><a class="btn btn-small" href="<?= cvcarts_h(cvcarts_url($route . '?' . http_build_query($accountParams + ['page' => $page - 1]))) ?>">Previous</a><?php endif; ?>
                        <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?><a class="btn btn-small <?= $pageNumber === $page ? 'active' : '' ?>" href="<?= cvcarts_h(cvcarts_url($route . '?' . http_build_query($accountParams + ['page' => $pageNumber]))) ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a><?php endfor; ?>
                        <?php if ($page < $totalPages): ?><a class="btn btn-small" href="<?= cvcarts_h(cvcarts_url($route . '?' . http_build_query($accountParams + ['page' => $page + 1]))) ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            </section>
        </main>
    </div>
</body>
</html>
