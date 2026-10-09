<?php
/**
 * Doctor Access History & Audit Trail
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "My Access History";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Fetch audit logs for this doctor
$stmt = $pdo->prepare("SELECT * FROM `audit_logs` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 100");
$stmt->execute([$userId]);
$logs = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Doctor Access History & Audit Trail</h3>
        <p class="text-secondary small mb-0">Complete, tamper-evident log of all clinical actions, consent requests, and patient record inspections.</p>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <span class="fw-bold fs-5"><i class="bi bi-clock-history me-2 text-primary"></i>Activity Log Stream (<?= count($logs) ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-journal-text display-4 text-secondary mb-2 d-block"></i>
                <p class="mb-0">No recorded activities yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action Type</th>
                            <th>Details & Context</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td>
                                    <div class="small fw-semibold text-dark"><?= format_date($l['created_at']) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($l['action']) ?></span>
                                </td>
                                <td>
                                    <div class="small text-secondary"><?= e($l['details']) ?></div>
                                </td>
                                <td>
                                    <small class="text-muted font-monospace"><?= e($l['ip_address']) ?></small>
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
