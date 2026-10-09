<?php
/**
 * Patient Health Records Management
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "My Health Records";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();
$patientId = get_patient_profile_id($pdo);

// Fetch all health records for this patient
$stmt = $pdo->prepare("SELECT * FROM `health_records` WHERE `patient_id` = ? ORDER BY `record_date` DESC, `id` DESC");
$stmt->execute([$patientId]);
$records = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">My Health Records Repository</h3>
        <p class="text-secondary small mb-0">Decentralized patient vault. You control all digital medical artifacts.</p>
    </div>
    <div class="d-flex gap-2 mt-2 mt-md-0">
        <a href="<?= BASE_URL ?>patient/add_record.php" class="btn btn-primary shadow-sm fw-semibold">
            <i class="bi bi-plus-lg me-1"></i> Add Health Record
        </a>
    </div>
</div>

<div class="card card-custom mb-4">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <span class="fw-bold fs-5">All Stored Records (<?= count($records) ?>)</span>
            </div>
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0 table-search-input" data-table-target="recordsTable" placeholder="Search by title, diagnosis, doctor, or UID...">
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($records)): ?>
            <div class="text-center py-5">
                <i class="bi bi-folder-plus display-3 text-muted mb-3 d-block"></i>
                <h5 class="text-secondary">No Health Records Stored Yet</h5>
                <p class="text-muted small">Upload your prescriptions, lab reports, discharge summaries, or diagnostic scans.</p>
                <a href="<?= BASE_URL ?>patient/add_record.php" class="btn btn-primary px-4 fw-semibold mt-2">
                    <i class="bi bi-plus-circle me-1"></i> Add Health Record
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="recordsTable">
                    <thead>
                        <tr>
                            <th>Record ID / Title</th>
                            <th>Record Type</th>
                            <th>Doctor / Hospital</th>
                            <th>Record Date</th>
                            <th>Attachment</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($r['title']) ?></div>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($r['record_uid']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border"><?= e($r['record_type']) ?></span>
                                </td>
                                <td>
                                    <div class="text-dark small fw-semibold"><?= e($r['doctor_hospital_name'] ?: 'Self / Independent') ?></div>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= e(date('d M Y', strtotime($r['record_date']))) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($r['file_attachment'])): ?>
                                        <a href="<?= BASE_URL ?>includes/view_document.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success fw-semibold py-1 px-2" title="Secure View & Download">
                                            <i class="bi bi-file-earmark-arrow-down me-1"></i> Document
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <!-- View Details Modal Trigger -->
                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#modalView<?= $r['id'] ?>" title="View Complete Record">
                                        <i class="bi bi-eye"></i> Details
                                    </button>
                                    <!-- Edit Record -->
                                    <a href="<?= BASE_URL ?>patient/edit_record.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="Edit Record">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <!-- Delete Record -->
                                    <form method="POST" action="<?= BASE_URL ?>patient/delete_record.php" class="d-inline form-delete-record">
                                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                        <input type="hidden" name="record_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Record">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>

                            <!-- Record Details Modal -->
                            <div class="modal fade" id="modalView<?= $r['id'] ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header bg-primary text-white">
                                            <h5 class="modal-title fw-bold">
                                                <i class="bi bi-file-earmark-medical me-2"></i><?= e($r['title']) ?>
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <div class="row g-3 mb-3 border-bottom pb-3">
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">DEPA Record Artifact ID</small>
                                                    <span class="font-monospace fw-bold text-primary"><?= e($r['record_uid']) ?></span>
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Record Date</small>
                                                    <span class="fw-semibold"><?= e(date('d F Y', strtotime($r['record_date']))) ?></span>
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Category / Type</small>
                                                    <span class="badge bg-light text-dark border"><?= e($r['record_type']) ?></span>
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Attending Doctor / Hospital</small>
                                                    <span class="fw-semibold"><?= e($r['doctor_hospital_name'] ?: 'Not Specified') ?></span>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <h6 class="fw-bold text-dark"><i class="bi bi-heart-pulse me-1 text-danger"></i> Clinical Diagnosis</h6>
                                                <div class="p-3 bg-light rounded-3 border">
                                                    <?= nl2br(e($r['diagnosis'])) ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($r['prescription'])): ?>
                                                <div class="mb-3">
                                                    <h6 class="fw-bold text-dark"><i class="bi bi-capsule me-1 text-primary"></i> Prescription & Dosage</h6>
                                                    <div class="p-3 bg-light rounded-3 border font-monospace small">
                                                        <?= nl2br(e($r['prescription'])) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($r['medical_notes'])): ?>
                                                <div class="mb-3">
                                                    <h6 class="fw-bold text-dark"><i class="bi bi-journal-text me-1 text-secondary"></i> Medical Notes & Advice</h6>
                                                    <div class="p-3 bg-light rounded-3 border text-secondary small">
                                                        <?= nl2br(e($r['medical_notes'])) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($r['file_attachment'])): ?>
                                                <div class="p-3 bg-success bg-opacity-10 rounded-3 border border-success border-opacity-25 d-flex justify-content-between align-items-center mt-3">
                                                    <div>
                                                        <i class="bi bi-file-earmark-pdf-fill text-success fs-4 me-2"></i>
                                                        <strong><?= e($r['file_original_name'] ?: 'Medical Attachment') ?></strong>
                                                        <span class="text-muted small ms-2">(<?= round($r['file_size'] / 1024, 1) ?> KB)</span>
                                                    </div>
                                                    <a href="<?= BASE_URL ?>includes/view_document.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-success btn-sm px-3 fw-semibold">
                                                        <i class="bi bi-download me-1"></i> View Document
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="modal-footer">
                                            <a href="<?= BASE_URL ?>patient/edit_record.php?id=<?= $r['id'] ?>" class="btn btn-outline-secondary">Edit Record</a>
                                            <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
