<?php
/**
 * Patient Management & Registry View (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Patient Directory & Management";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$stmt = $pdo->query("SELECT p.*, u.username, u.email, u.status AS account_status, u.ekyc_status,
                            (SELECT COUNT(*) FROM `health_records` WHERE `patient_id` = p.id) AS total_records,
                            (SELECT COUNT(*) FROM `consent_requests` WHERE `patient_id` = p.id AND `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()) AS active_grants
                     FROM `patients` p
                     JOIN `users` u ON p.user_id = u.id
                     ORDER BY p.id DESC");
$patients = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Registered Patients (Data Principals)</h3>
        <p class="text-secondary small mb-0">Manage patient accounts, inspect ABHA identities, verify eKYC compliance, and monitor active data grants.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>admin/registry.php?type=PATIENT" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-card-checklist me-1"></i> Patient Master Registry
        </a>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <span class="fw-bold fs-5">All Registered Patients (<?= count($patients) ?>)</span>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm table-search-input ms-md-auto" data-table-target="patientTable" placeholder="Search patients..." style="max-width: 250px;">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($patients)): ?>
            <div class="text-center py-5 text-muted">
                <p class="mb-0">No patients registered in the system yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="patientTable">
                    <thead>
                        <tr>
                            <th>Patient ID (ABHA)</th>
                            <th>Patient Full Name</th>
                            <th>Registration Date</th>
                            <th>eKYC Status</th>
                            <th>Account Status</th>
                            <th>Records / Grants</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $p): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($p['patient_uid']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($p['full_name']) ?></div>
                                    <small class="text-muted"><?= e($p['username']) ?> (<?= e($p['email']) ?>)</small>
                                </td>
                                <td>
                                    <div class="small text-muted"><?= date('d M Y', strtotime($p['created_at'])) ?></div>
                                </td>
                                <td>
                                    <?php if ($p['ekyc_status'] === 'Verified'): ?>
                                        <span class="badge bg-success text-white"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>
                                    <?php elseif ($p['ekyc_status'] === 'Failed'): ?>
                                        <span class="badge bg-danger text-white">Failed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_status_badge($p['account_status']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-primary-subtle text-primary border me-1"><?= $p['total_records'] ?> Records</span>
                                    <span class="badge bg-success-subtle text-success border"><?= $p['active_grants'] ?> Active</span>
                                </td>
                                <td class="text-end">
                                    <form method="POST" action="<?= BASE_URL ?>admin/toggle_status.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                        <input type="hidden" name="user_id" value="<?= $p['user_id'] ?>">
                                        <input type="hidden" name="redirect" value="admin/patients.php">
                                        <?php if ($p['account_status'] === 'active'): ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate Patient" onclick="return confirm('Deactivate this patient account?');">
                                                <i class="bi bi-person-x"></i> Deactivate
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Activate Patient">
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
