<?php
/**
 * System Audit Logs Stream & Compliance Inspector (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Audit Trail & Compliance";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$roleFilter   = $_GET['role'] ?? '';
$actionFilter = $_GET['action'] ?? '';
$userFilter   = $_GET['user'] ?? '';
$startDate    = $_GET['start_date'] ?? '';
$endDate      = $_GET['end_date'] ?? '';
$searchQuery  = trim($_GET['q'] ?? '');

$query = "SELECT * FROM `audit_logs` WHERE 1=1";
$params = [];

if (!empty($roleFilter)) {
    $query .= " AND `user_role` = ?";
    $params[] = $roleFilter;
}

if (!empty($actionFilter)) {
    $query .= " AND `action` = ?";
    $params[] = $actionFilter;
}

if (!empty($userFilter)) {
    $query .= " AND (`user_id` = ? OR `user_name` LIKE ?)";
    $params[] = $userFilter;
    $params[] = "%{$userFilter}%";
}

if (!empty($startDate)) {
    $query .= " AND DATE(`created_at`) >= ?";
    $params[] = $startDate;
}

if (!empty($endDate)) {
    $query .= " AND DATE(`created_at`) <= ?";
    $params[] = $endDate;
}

if (!empty($searchQuery)) {
    $query .= " AND (`details` LIKE ? OR `user_name` LIKE ? OR `action` LIKE ? OR `ip_address` LIKE ?)";
    $like = "%{$searchQuery}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$query .= " ORDER BY `created_at` DESC, `id` DESC LIMIT 300";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Distinct actions and users for dropdown filters
$distinctActions = $pdo->query("SELECT DISTINCT `action` FROM `audit_logs` ORDER BY `action` ASC")->fetchAll(PDO::FETCH_COLUMN);
$distinctUsers = $pdo->query("SELECT DISTINCT `user_name` FROM `audit_logs` WHERE `user_name` IS NOT NULL AND `user_name` != '' ORDER BY `user_name` ASC")->fetchAll(PDO::FETCH_COLUMN);

// Total counts
$totalLogCount = (int)$pdo->query("SELECT COUNT(*) FROM `audit_logs`")->fetchColumn();
$todayLogCount = (int)$pdo->query("SELECT COUNT(*) FROM `audit_logs` WHERE DATE(`created_at`) = CURDATE()")->fetchColumn();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">DEPA Security & Audit Trail</h3>
        <p class="text-secondary small mb-0">Immutable, timestamped event log for regulatory compliance and forensic trace analysis across all system interactions.</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-light text-dark border p-2">Total Events: <strong><?= $totalLogCount ?></strong></span>
        <span class="badge bg-success-subtle text-success border p-2">Today's Events: <strong><?= $todayLogCount ?></strong></span>
    </div>
</div>

<!-- Multi-Field Filter Card -->
<div class="card card-custom shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3">
        <span class="fw-bold fs-6"><i class="bi bi-funnel me-2 text-primary"></i>Search & Filter Audit Events</span>
    </div>
    <div class="card-body p-3 p-md-4">
        <form method="GET" action="" class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold small text-muted">Keyword Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Action, ID, details, IP..." value="<?= e($searchQuery) ?>">
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small text-muted">Filter by Role</label>
                <select name="role" class="form-select form-select-sm">
                    <option value="">All Roles</option>
                    <option value="patient" <?= $roleFilter === 'patient' ? 'selected' : '' ?>>Patient</option>
                    <option value="doctor" <?= $roleFilter === 'doctor' ? 'selected' : '' ?>>Doctor</option>
                    <option value="hospital" <?= $roleFilter === 'hospital' ? 'selected' : '' ?>>Hospital</option>
                    <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="system" <?= $roleFilter === 'system' ? 'selected' : '' ?>>System</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small text-muted">Filter by Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($distinctActions as $act): ?>
                        <option value="<?= e($act) ?>" <?= ($actionFilter === $act) ? 'selected' : '' ?>><?= e($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-semibold small text-muted">Filter by User</label>
                <select name="user" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    <?php foreach ($distinctUsers as $u): ?>
                        <option value="<?= e($u) ?>" <?= ($userFilter === $u) ? 'selected' : '' ?>><?= e($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small text-muted">Date Range (From - To)</label>
                <div class="input-group input-group-sm">
                    <input type="date" name="start_date" class="form-control" value="<?= e($startDate) ?>" title="Start Date">
                    <input type="date" name="end_date" class="form-control" value="<?= e($endDate) ?>" title="End Date">
                </div>
            </div>
            <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                <button type="submit" class="btn btn-primary btn-sm px-4 fw-semibold">
                    <i class="bi bi-funnel me-1"></i> Apply Filters
                </button>
                <?php if (!empty($searchQuery) || !empty($roleFilter) || !empty($actionFilter) || !empty($userFilter) || !empty($startDate) || !empty($endDate)): ?>
                    <a href="<?= BASE_URL ?>admin/audit_logs.php" class="btn btn-outline-secondary btn-sm px-3">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset Filters
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Audit Log Stream Table -->
<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold fs-5"><i class="bi bi-journal-text me-2 text-warning"></i>Audit Events Trail (<?= count($logs) ?>)</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print();">
            <i class="bi bi-printer me-1"></i> Print / Export Audit
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-search display-4 text-secondary d-block mb-2"></i>
                <p class="mb-0">No audit records match your search and filter criteria.</p>
                <a href="<?= BASE_URL ?>admin/audit_logs.php" class="btn btn-sm btn-outline-primary mt-2">Reset Filters</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Timestamp</th>
                            <th>User Principal</th>
                            <th>Role</th>
                            <th>Action Event</th>
                            <th>Details & Context</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td>
                                    <small class="text-muted font-monospace">#<?= (int)$l['id'] ?></small>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-dark"><?= format_date($l['created_at']) ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark small"><?= e($l['user_name'] ?: 'System Process') ?></div>
                                    <?php if ($l['user_id']): ?>
                                        <small class="text-muted font-monospace" style="font-size: 0.65rem;">UID: <?= (int)$l['user_id'] ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    $r = $l['user_role'] ?: 'system';
                                    echo '<span class="badge badge-' . e($r) . ' text-uppercase" style="font-size: 0.68rem;">' . e($r) . '</span>';
                                    ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($l['action']) ?></span>
                                </td>
                                <td>
                                    <div class="small text-secondary" style="max-width: 380px;">
                                        <?= e($l['details']) ?>
                                    </div>
                                </td>
                                <td>
                                    <small class="text-muted font-monospace"><?= e($l['ip_address'] ?: '127.0.0.1') ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
