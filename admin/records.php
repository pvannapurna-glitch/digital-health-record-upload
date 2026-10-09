<?php
/**
 * Health Record Master Catalog (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Health Records Master Catalog";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$stmt = $pdo->query("SELECT hr.*, p.full_name AS patient_name, p.patient_uid, u.username AS creator_username,
                            (SELECT COUNT(*) FROM `consent_requests` WHERE `record_id` = hr.id AND `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()) AS active_grants_count
                     FROM `health_records` hr
                     JOIN `patients` p ON hr.patient_id = p.id
                     JOIN `users` u ON hr.created_by_user_id = u.id
                     ORDER BY hr.id DESC");
$records = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Health Records Master Catalog</h3>
        <p class="text-secondary small mb-0">Overview of all health record artifacts registered in the ecosystem registry.</p>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <span class="fw-bold fs-5">All Registered Health Artifacts (<?= count($records) ?>)</span>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm table-search-input ms-md-auto" data-table-target="recordCatalogTable" placeholder="Search record catalog..." style="max-width: 250px;">
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
            <div class="text-center py-5 text-muted">
                <p class="mb-0">No health records stored in system yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="recordCatalogTable">
                    <thead>
                        <tr>
                            <th>Record Handle</th>
                            <th>Record Title & Category</th>
                            <th>Patient (Data Principal)</th>
                            <th>Attending Doctor/Hospital</th>
                            <th>Record Date</th>
                            <th>Attachment</th>
                            <th>Active Grants</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($r['record_uid']) ?></span>
                                    <div class="small text-muted mt-1"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($r['title']) ?></div>
                                    <span class="badge bg-secondary-subtle text-secondary border"><?= e($r['record_type']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($r['patient_name']) ?></div>
                                    <small class="text-muted font-monospace"><?= e($r['patient_uid']) ?></small>
                                </td>
                                <td>
                                    <small class="text-secondary"><?= e($r['doctor_hospital_name'] ?: 'Self / Independent') ?></small>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= e(date('d M Y', strtotime($r['record_date']))) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($r['file_attachment'])): ?>
                                        <a href="<?= BASE_URL ?>includes/view_document.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success py-1 px-2 fw-semibold">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> Doc (<?= round($r['file_size'] / 1024, 1) ?> KB)
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">None</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-success-subtle text-success border"><?= $r['active_grants_count'] ?> Active</span>
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
