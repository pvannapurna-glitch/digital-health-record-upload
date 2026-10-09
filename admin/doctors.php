<?php
/**
 * Doctor Directory & Registry View (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Doctor Directory & Verification";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$stmt = $pdo->query("SELECT d.*, u.username, u.email, u.status AS account_status, u.ekyc_status,
                            (SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = u.id) AS total_requests,
                            (SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = u.id AND `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()) AS active_grants
                     FROM `doctors` d
                     JOIN `users` u ON d.user_id = u.id
                     ORDER BY d.id DESC");
$doctors = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Registered Doctors (Data Consumers)</h3>
        <p class="text-secondary small mb-0">Inspect medical council registrations, specialization profiles, and manage practitioner access.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>admin/registry.php?type=DOCTOR" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-card-checklist me-1"></i> Doctor Master Registry
        </a>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <span class="fw-bold fs-5">All Medical Practitioners (<?= count($doctors) ?>)</span>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm table-search-input ms-md-auto" data-table-target="doctorTable" placeholder="Search doctors..." style="max-width: 250px;">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($doctors)): ?>
            <div class="text-center py-5 text-muted">
                <p class="mb-0">No doctors registered in the system yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="doctorTable">
                    <thead>
                        <tr>
                            <th>Doctor ID</th>
                            <th>Doctor Name</th>
                            <th>Specialization</th>
                            <th>Registration Date</th>
                            <th>Account Status</th>
                            <th>Requests / Active Grants</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doctors as $d): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($d['doctor_uid']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($d['full_name']) ?></div>
                                    <small class="text-muted"><?= e($d['email']) ?> (Ph: <?= e($d['phone']) ?>)</small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($d['specialization']) ?></div>
                                    <span class="badge bg-light text-success border font-monospace">Lic: <?= e($d['license_number']) ?></span>
                                </td>
                                <td>
                                    <div class="small text-muted"><?= date('d M Y', strtotime($d['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?= render_status_badge($d['account_status']) ?>
                                    <?php if ($d['ekyc_status'] === 'Verified'): ?>
                                        <span class="badge bg-success-subtle text-success border" style="font-size: 0.65rem;">eKYC</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border me-1"><?= $d['total_requests'] ?> Sent</span>
                                    <span class="badge bg-success-subtle text-success border"><?= $d['active_grants'] ?> Active</span>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="<?= BASE_URL ?>admin/toggle_status.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                        <input type="hidden" name="user_id" value="<?= $d['user_id'] ?>">
                                        <input type="hidden" name="redirect" value="admin/doctors.php">
                                        <?php if ($d['account_status'] === 'active'): ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate Doctor" onclick="return confirm('Deactivate this doctor account?');">
                                                <i class="bi bi-person-x"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Activate Doctor">
                                                <i class="bi bi-person-check"></i> Activate
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
