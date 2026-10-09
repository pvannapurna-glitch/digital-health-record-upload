<?php
/**
 * Hospital Directory & Registry View (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Hospital Directory & Governance";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$stmt = $pdo->query("SELECT h.*, u.username, u.email AS account_email, u.status AS account_status, u.ekyc_status,
                            (SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = u.id) AS total_requests,
                            (SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = u.id AND `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()) AS active_grants
                     FROM `hospitals` h
                     JOIN `users` u ON h.user_id = u.id
                     ORDER BY h.id DESC");
$hospitals = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Registered Healthcare Facilities & Hospitals</h3>
        <p class="text-secondary small mb-0">Manage accredited hospital participants, inspect facility licenses, and manage facility access.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>admin/registry.php?type=HOSPITAL" class="btn btn-outline-success btn-sm fw-semibold">
            <i class="bi bi-card-checklist me-1"></i> Hospital Master Registry
        </a>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <span class="fw-bold fs-5">All Healthcare Facilities (<?= count($hospitals) ?>)</span>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm table-search-input ms-md-auto" data-table-target="hospTable" placeholder="Search hospitals..." style="max-width: 250px;">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($hospitals)): ?>
            <div class="text-center py-5 text-muted">
                <p class="mb-0">No hospitals registered in the system yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="hospTable">
                    <thead>
                        <tr>
                            <th>Hospital ID</th>
                            <th>Hospital Name</th>
                            <th>Registration Date</th>
                            <th>Account Status</th>
                            <th>Facility Contact</th>
                            <th>Consents</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($hospitals as $h): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-success border font-monospace"><?= e($h['hospital_uid']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($h['hospital_name']) ?></div>
                                    <span class="badge bg-light text-dark border font-monospace">NABH: <?= e($h['license_number']) ?></span>
                                </td>
                                <td>
                                    <div class="small text-muted"><?= date('d M Y', strtotime($h['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?= render_status_badge($h['account_status']) ?>
                                    <?php if ($h['ekyc_status'] === 'Verified'): ?>
                                        <span class="badge bg-success-subtle text-success border" style="font-size: 0.65rem;">eKYC</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark small"><?= e($h['contact_person']) ?></div>
                                    <small class="text-muted"><?= e($h['phone']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border me-1"><?= $h['total_requests'] ?> Sent</span>
                                    <span class="badge bg-success-subtle text-success border"><?= $h['active_grants'] ?> Active</span>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="<?= BASE_URL ?>admin/toggle_status.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                        <input type="hidden" name="user_id" value="<?= $h['user_id'] ?>">
                                        <input type="hidden" name="redirect" value="admin/hospitals.php">
                                        <?php if ($h['account_status'] === 'active'): ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate Hospital" onclick="return confirm('Deactivate this hospital facility?');">
                                                <i class="bi bi-building-x"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Activate Hospital">
                                                <i class="bi bi-building-check"></i> Activate
                                            </button>
                                        <?php endif; ?>
                                    </form>
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
