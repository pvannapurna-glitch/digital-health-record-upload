<?php
/**
 * Patient Health Data Sharing History & Active Grants (with eSign Information)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Data Sharing History & Grants";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();
$patientId = get_patient_profile_id($pdo);

// Auto expire passed consents
auto_expire_consents($pdo);

$statusFilter = $_GET['status'] ?? '';
$esignFilter = $_GET['esign_status'] ?? '';

$query = "SELECT cr.*, p.full_name AS patient_name, p.patient_uid,
                 u.username AS requester_username,
                 CASE 
                     WHEN cr.requester_role = 'doctor' THEN d.full_name 
                     WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                     ELSE u.username 
                 END AS requester_display_name,
                 d.specialization, d.license_number AS doc_license,
                 h.license_number AS hosp_license,
                 hr.title AS record_title, hr.record_uid,
                 es.esign_uid, es.signature_hash, es.signature_type, es.signature_method, es.signed_at AS esign_timestamp
          FROM `consent_requests` cr
          JOIN `patients` p ON cr.patient_id = p.id
          JOIN `users` u ON cr.requester_user_id = u.id
          LEFT JOIN `doctors` d ON u.id = d.user_id
          LEFT JOIN `hospitals` h ON u.id = h.user_id
          LEFT JOIN `health_records` hr ON cr.record_id = hr.id
          LEFT JOIN `esign_records` es ON cr.id = es.consent_id
          WHERE cr.patient_id = ?";

$params = [$patientId];

if (!empty($statusFilter)) {
    if ($statusFilter === 'Active') {
        $query .= " AND cr.status IN ('Active', 'Approved')";
    } else {
        $query .= " AND cr.status = ?";
        $params[] = $statusFilter;
    }
}

if (!empty($esignFilter)) {
    $query .= " AND cr.esign_status = ?";
    $params[] = $esignFilter;
}

$query .= " ORDER BY cr.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$history = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Health Data Sharing Grants & eSign History</h3>
        <p class="text-secondary small mb-0">Immutable record of all DEPA consent authorizations, digital signatures, and patient revocation controls.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>patient/consent_requests.php" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-bell me-1"></i> Pending Requests
        </a>
        <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-patch-check me-1"></i> Central eSign Monitor
        </a>
    </div>
</div>

<!-- eSign Status Section Table -->
<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-5">
                <span class="fw-bold fs-5 text-dark">
                    <i class="bi bi-journal-check text-primary me-2"></i>Consent & eSign Status Ledger (<?= count($history) ?>)
                </span>
            </div>
            <div class="col-md-7">
                <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                    <select class="form-select form-select-sm" style="max-width: 170px;" onchange="location = this.value;">
                        <option value="<?= BASE_URL ?>patient/sharing_history.php" <?= empty($statusFilter) ? 'selected' : '' ?>>All Consent Statuses</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?status=Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active Grants</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?status=Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?status=Revoked" <?= $statusFilter === 'Revoked' ? 'selected' : '' ?>>Revoked</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?status=Expired" <?= $statusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?status=Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <select class="form-select form-select-sm" style="max-width: 160px;" onchange="location = this.value;">
                        <option value="<?= BASE_URL ?>patient/sharing_history.php" <?= empty($esignFilter) ? 'selected' : '' ?>>All eSigns</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?esign_status=Signed" <?= $esignFilter === 'Signed' ? 'selected' : '' ?>>Signed</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?esign_status=Pending" <?= $esignFilter === 'Pending' ? 'selected' : '' ?>>Pending eSign</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?esign_status=Not Started" <?= $esignFilter === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                        <option value="<?= BASE_URL ?>patient/sharing_history.php?esign_status=Failed" <?= $esignFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
                    </select>

                    <input type="text" class="form-control form-control-sm table-search-input" data-table-target="historyTable" placeholder="Search ledger..." style="max-width: 180px;">
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($history)): ?>
            <div class="text-center py-5">
                <i class="bi bi-clock-history display-4 text-muted mb-3 d-block"></i>
                <h5 class="text-secondary">No Consent Records Found</h5>
                <p class="text-muted small">No data sharing requests match your filter.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="historyTable">
                    <thead>
                        <tr>
                            <th>Consent ID</th>
                            <th>Patient (Data Principal)</th>
                            <th>Requester (Data Consumer)</th>
                            <th>Scope / Record</th>
                            <th>Purpose</th>
                            <th>Consent Status</th>
                            <th>eSign Status</th>
                            <th>Signed Date/Time</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $c): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($c['consent_uid']) ?></span>
                                    <div class="small text-muted mt-1"><?= format_date($c['requested_date']) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($c['patient_name']) ?></div>
                                    <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.68rem;"><?= e($c['patient_uid']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($c['requester_display_name']) ?></div>
                                    <span class="badge badge-<?= e($c['requester_role']) ?> text-uppercase" style="font-size: 0.65rem;"><?= e($c['requester_role']) ?></span>
                                </td>
                                <td>
                                    <?php if ($c['record_id']): ?>
                                        <div class="fw-semibold text-dark"><?= e($c['record_title']) ?></div>
                                        <small class="text-muted font-monospace"><?= e($c['record_uid']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-primary text-white">Full Health History</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-secondary d-inline-block text-truncate" style="max-width: 170px;" title="<?= e($c['purpose']) ?>">
                                        <?= e($c['purpose']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= render_status_badge($c['status']) ?>
                                    <?php if (in_array($c['status'], ['Active', 'Approved'])): ?>
                                        <div class="small text-success mt-1" style="font-size: 0.72rem;">Till: <?= date('d M Y', strtotime($c['expiry_date'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_esign_status_badge($c['esign_status']) ?>
                                    <?php if (!empty($c['esign_uid'])): ?>
                                        <div class="mt-1">
                                            <button type="button" class="btn btn-link p-0 text-decoration-none font-monospace small" style="font-size: 0.7rem;" data-bs-toggle="modal" data-bs-target="#modalEsign<?= $c['id'] ?>">
                                                <i class="bi bi-shield-check me-1"></i><?= e($c['esign_uid']) ?>
                                            </button>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-1">
                                            <button type="button" class="btn btn-link p-0 text-decoration-none small" style="font-size: 0.7rem;" data-bs-toggle="modal" data-bs-target="#modalEsign<?= $c['id'] ?>">
                                                <i class="bi bi-shield-check me-1"></i>Verify Signatures
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c['esign_timestamp'])): ?>
                                        <div class="small fw-semibold text-dark"><?= format_date($c['esign_timestamp']) ?></div>
                                        <small class="text-muted font-monospace" style="font-size: 0.68rem;"><?= e($c['signature_type'] ?: 'OTP Verified') ?></small>
                                    <?php elseif ($c['esign_status'] === 'Signed' && !empty($c['decision_date'])): ?>
                                        <div class="small fw-semibold text-dark"><?= format_date($c['decision_date']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Not Signed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if (in_array($c['status'], ['Active', 'Approved'])): ?>
                                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary px-2 fw-semibold shadow-sm me-1" title="View / Download Final Signed PDF">
                                            <i class="bi bi-file-earmark-pdf-fill me-1"></i> View/Download Final PDF
                                        </a>
                                        <form method="POST" action="<?= BASE_URL ?>patient/revoke_consent.php" class="d-inline" onsubmit="return confirm('DEPA SECURITY NOTICE:\n\nAre you sure you want to REVOKE access immediately?\nThe requester will no longer be able to view this health record.');">
                                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                            <input type="hidden" name="consent_id" value="<?= $c['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-danger px-2 fw-semibold shadow-sm" title="Revoke Authorization">
                                                <i class="bi bi-shield-slash me-1"></i> Revoke
                                            </button>
                                        </form>
                                    <?php elseif ($c['status'] === 'approver_pending'): ?>
                                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary px-2 fw-semibold shadow-sm me-1" title="View Intermediate PDF">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> Intermediate PDF
                                        </a>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">Awaiting Approver</span>
                                    <?php elseif ($c['status'] === 'Pending'): ?>
                                        <a href="<?= BASE_URL ?>patient/esign_consent.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-success px-2 fw-bold shadow-sm">
                                            <i class="bi bi-pen-fill me-1"></i> eSign
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary px-2 me-1" title="View Agreement PDF">
                                            <i class="bi bi-file-earmark-pdf"></i> PDF
                                        </a>
                                        <span class="badge bg-light text-muted border">Archived</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <!-- Modal: eSign Certificate Inspector -->
                            <div class="modal fade" id="modalEsign<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered modal-lg">
                                    <div class="modal-content border-0 shadow">
                                        <div class="modal-header bg-dark text-white">
                                            <h5 class="modal-title fw-bold">
                                                <i class="bi bi-patch-check-fill text-success me-2"></i>Consent Digital Signature & Integrity Verification
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <div class="alert alert-info py-2 small mb-3">
                                                <i class="bi bi-shield-check me-1"></i>
                                                <strong>Local Digital Signature + SHA-256 Document Integrity Verification</strong>
                                                <div class="text-secondary mt-1">SHA-256 cryptographic hashing verifies document integrity and guarantees the agreement has not been altered since execution.</div>
                                            </div>

                                            <div class="bg-light p-3 rounded-3 border mb-3">
                                                <div class="row g-3 small">
                                                    <div class="col-sm-4">
                                                        <strong class="text-muted d-block">Status:</strong>
                                                        <span class="badge bg-<?= ($c['status'] === 'Active' || $c['status'] === 'Approved') ? 'success' : 'warning text-dark' ?> fs-6 mt-1">
                                                            <?= strtoupper(e($c['status'])) ?>
                                                        </span>
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <strong class="text-muted d-block">Doctor Signature:</strong>
                                                        <span class="badge bg-<?= !empty($c['doctor_signed_at']) ? 'success' : 'secondary' ?> fs-6 mt-1">
                                                            <?= !empty($c['doctor_signed_at']) ? 'Completed' : 'Pending' ?>
                                                        </span>
                                                        <?php if (!empty($c['doctor_signed_at'])): ?>
                                                            <div class="small text-muted mt-1"><?= format_date($c['doctor_signed_at']) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="col-sm-4">
                                                        <strong class="text-muted d-block">Approver Signature:</strong>
                                                        <span class="badge bg-<?= !empty($c['approver_signed_at']) ? 'success' : 'secondary' ?> fs-6 mt-1">
                                                            <?= !empty($c['approver_signed_at']) ? 'Completed' : 'Pending' ?>
                                                        </span>
                                                        <?php if (!empty($c['approver_signed_at'])): ?>
                                                            <div class="small text-muted mt-1"><?= format_date($c['approver_signed_at']) ?></div>
                                                            <div class="small text-muted font-monospace"><?= e($c['approver_name'] ?: 'Medical Superintendent') ?></div>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="col-sm-6 border-top pt-2">
                                                        <strong class="text-muted d-block">Consent Reference:</strong>
                                                        <span class="font-monospace fw-bold text-primary"><?= e($c['consent_uid']) ?></span>
                                                    </div>
                                                    <div class="col-sm-6 border-top pt-2">
                                                        <strong class="text-muted d-block">Data Requester:</strong>
                                                        <span class="fw-semibold text-dark"><?= e($c['requester_display_name']) ?></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold small text-muted mb-1">
                                                    <i class="bi bi-hash me-1"></i>Canonical Document SHA-256 Hash
                                                </label>
                                                <div class="p-2 bg-light border rounded font-monospace small text-break">
                                                    <?= e($c['pre_sign_document_hash'] ?: 'Calculated upon initial document generation') ?>
                                                </div>
                                                <small class="text-muted">Pre-signing digest representing canonical DEPA consent terms.</small>
                                            </div>

                                            <?php if (!empty($c['intermediate_document_hash'])): ?>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-muted mb-1">
                                                        <i class="bi bi-file-earmark-check me-1"></i>Intermediate Doctor-Signed PDF SHA-256 Hash
                                                    </label>
                                                    <div class="p-2 bg-light border rounded font-monospace small text-break">
                                                        <?= e($c['intermediate_document_hash']) ?>
                                                    </div>
                                                    <small class="text-muted">Computed after Attending Physician's signature is sealed.</small>
                                                </div>
                                            <?php endif; ?>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold small text-muted mb-1">
                                                    <i class="bi bi-patch-check-fill text-success me-1"></i>Final Signed Document SHA-256 Hash
                                                </label>
                                                <div class="p-2 bg-dark text-light rounded font-monospace small text-break">
                                                    <?= e($c['signed_document_hash'] ?: 'Will be generated after both Doctor and Approver sign') ?>
                                                </div>
                                                <small class="text-muted">Verifies cryptographic integrity of the dual-signed active consent document.</small>
                                            </div>

                                            <div class="small text-muted fst-italic">
                                                Verification Notice: This agreement utilizes Local Digital Signature + SHA-256 Document Integrity Verification for project demonstration. Real external eMudhra PKI gateway is switchable.
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light d-flex justify-content-between">
                                            <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-primary btn-sm fw-semibold">
                                                <i class="bi bi-file-earmark-pdf-fill me-1"></i> View / Download Final PDF
                                            </a>
                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
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
