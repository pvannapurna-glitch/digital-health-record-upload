<?php
/**
 * Central eSign Status & Verification Hub (DEPA Digital Signature)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Digital Signature & eSign Status Monitor";
require_once __DIR__ . '/../config/config.php';

require_login();

$pdo = get_db_connection();
$userId = get_logged_user_id();
$userRole = get_user_role();

// Auto expire passed consents
auto_expire_consents($pdo);

$statusFilter = $_GET['status'] ?? '';
$esignFilter = $_GET['esign_status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

$query = "SELECT cr.*, p.full_name AS patient_name, p.patient_uid, p.blood_group,
                 u.username AS requester_username,
                 CASE 
                     WHEN cr.requester_role = 'doctor' THEN d.full_name 
                     WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                     ELSE u.username 
                 END AS requester_display_name,
                 d.specialization, d.license_number AS doc_license,
                 h.license_number AS hosp_license,
                 hr.title AS record_title, hr.record_uid, hr.record_type,
                 es.esign_uid, es.signature_hash, es.signature_type, es.signature_method, es.signed_at AS esign_timestamp
          FROM `consent_requests` cr
          JOIN `patients` p ON cr.patient_id = p.id
          JOIN `users` u ON cr.requester_user_id = u.id
          LEFT JOIN `doctors` d ON u.id = d.user_id
          LEFT JOIN `hospitals` h ON u.id = h.user_id
          LEFT JOIN `health_records` hr ON cr.record_id = hr.id
          LEFT JOIN `esign_records` es ON cr.id = es.consent_id
          WHERE 1=1";

$params = [];

// Role-based scope isolation
if ($userRole === 'patient') {
    $patientId = get_patient_profile_id($pdo);
    $query .= " AND cr.patient_id = ?";
    $params[] = $patientId;
} elseif ($userRole === 'doctor' || $userRole === 'hospital') {
    $query .= " AND cr.requester_user_id = ?";
    $params[] = $userId;
}
// Admin can view all

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

if (!empty($searchQuery)) {
    $query .= " AND (cr.consent_uid LIKE ? OR p.full_name LIKE ? OR p.patient_uid LIKE ? OR cr.purpose LIKE ? OR es.esign_uid LIKE ?)";
    $like = "%{$searchQuery}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
}

$query .= " ORDER BY cr.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$consents = $stmt->fetchAll();

// Calculate counts
$signedCount = 0;
$pendingCount = 0;
$notStartedCount = 0;
$failedCount = 0;

foreach ($consents as $item) {
    if ($item['esign_status'] === 'Signed') $signedCount++;
    elseif ($item['esign_status'] === 'Pending') $pendingCount++;
    elseif ($item['esign_status'] === 'Failed') $failedCount++;
    else $notStartedCount++;
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">
            <i class="bi bi-patch-check-fill text-primary me-2"></i>Digital Signature Status Monitor
        </h3>
        <p class="text-secondary small mb-0">Real-time status tracking for DEPA Consent electronic signatures and cryptographic certificates.</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-success-subtle text-success border border-success-subtle p-2">Signed: <strong><?= $signedCount ?></strong></span>
        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle p-2">Pending: <strong><?= $pendingCount ?></strong></span>
        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle p-2">Not Started: <strong><?= $notStartedCount ?></strong></span>
    </div>
</div>

<!-- Academic Disclaimer Callout -->
<div class="alert alert-info border-info d-flex align-items-center shadow-sm mb-4" role="alert">
    <i class="bi bi-mortarboard-fill fs-3 text-info-emphasis me-3"></i>
    <div>
        <strong>Digital Signature Demo Notice:</strong>
        <span class="text-secondary small d-block">
            Demonstrating DEPA Electronic Signature states (<code>Not Started</code>, <code>Pending</code>, <code>Signed</code>, <code>Failed</code>) with local patient signature image and SHA-256 digital signature hashes. 
            For college project evaluation; distinct from government-certified digital signatures.
        </span>
    </div>
</div>

<!-- Filter & Search Toolbar -->
<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <form method="GET" action="" class="row align-items-center g-3">
            <div class="col-md-3">
                <span class="fw-bold fs-5 text-dark">
                    <i class="bi bi-table text-primary me-1"></i>eSign Ledger (<?= count($consents) ?>)
                </span>
            </div>
            <div class="col-md-9">
                <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search consent ID, patient, purpose..." value="<?= e($searchQuery) ?>" style="max-width: 220px;">

                    <select name="status" class="form-select form-select-sm" style="max-width: 170px;" onchange="this.form.submit();">
                        <option value="">All Consent Statuses</option>
                        <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Revoked" <?= $statusFilter === 'Revoked' ? 'selected' : '' ?>>Revoked</option>
                        <option value="Expired" <?= $statusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <select name="esign_status" class="form-select form-select-sm" style="max-width: 160px;" onchange="this.form.submit();">
                        <option value="">All eSign Statuses</option>
                        <option value="Signed" <?= $esignFilter === 'Signed' ? 'selected' : '' ?>>Signed</option>
                        <option value="Pending" <?= $esignFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Not Started" <?= $esignFilter === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                        <option value="Failed" <?= $esignFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
                    </select>

                    <button type="submit" class="btn btn-sm btn-primary px-3 fw-semibold">Filter</button>
                    <?php if (!empty($searchQuery) || !empty($statusFilter) || !empty($esignFilter)): ?>
                        <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($consents)): ?>
            <div class="text-center py-5">
                <i class="bi bi-patch-question display-4 text-muted mb-3 d-block"></i>
                <h5 class="text-secondary">No Records Found</h5>
                <p class="text-muted small">No consent or eSign records match the selected filters.</p>
                <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-outline-primary btn-sm">Reset Filters</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Consent ID</th>
                            <th>Patient</th>
                            <th>Requester</th>
                            <th>Purpose</th>
                            <th>Consent Status</th>
                            <th>eSign Status</th>
                            <th>Signed Date/Time</th>
                            <th class="text-end">Verification & Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consents as $c): ?>
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
                                    <div class="small text-secondary" style="max-width: 220px;">
                                        <?= e($c['purpose']) ?>
                                    </div>
                                    <?php if ($c['record_id']): ?>
                                        <small class="text-primary d-block font-monospace">Scope: <?= e($c['record_title']) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted d-block">Scope: Full Health Vault</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_status_badge($c['status']) ?>
                                    <?php if (!empty($c['workflow_step'])): ?>
                                        <div class="mt-1">
                                            <?php if ($c['workflow_step'] === 'WAITING_FOR_DOCTOR_SIGN'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle" style="font-size: 0.68rem;"><i class="bi bi-pen me-1"></i>Doctor Sign Pending</span>
                                            <?php elseif ($c['workflow_step'] === 'WAITING_FOR_APPROVER'): ?>
                                                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle" style="font-size: 0.68rem;"><i class="bi bi-hourglass-split me-1"></i>Approver Co-Sign Pending</span>
                                            <?php elseif ($c['workflow_step'] === 'FULLY_SIGNED'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.68rem;"><i class="bi bi-patch-check-fill me-1"></i>Dual emSigner Signed</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" style="font-size: 0.68rem;"><?= e($c['workflow_step']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_esign_status_badge($c['esign_status']) ?>
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
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary px-2" title="View Consent Agreement PDF">
                                            <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                                        </a>

                                        <?php if (!empty($c['esign_uid']) || !empty($c['signed_document_hash'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success px-2" data-bs-toggle="modal" data-bs-target="#modalCert<?= $c['id'] ?>" title="View eSign Certificate">
                                                <i class="bi bi-patch-check-fill me-1"></i> Cert
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($userRole === 'patient' && $c['status'] === 'Pending'): ?>
                                            <a href="<?= BASE_URL ?>patient/esign_consent.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-success fw-bold px-2">
                                                <i class="bi bi-pen-fill me-1"></i> eSign
                                            </a>
                                        <?php elseif ($userRole === 'doctor' && $c['status'] === 'Approved' && empty($c['doctor_signed_at'])): ?>
                                            <a href="<?= BASE_URL ?>doctor/esign_request.php?consent_id=<?= $c['id'] ?>" class="btn btn-sm btn-warning text-dark fw-bold px-2" title="Sign with emSigner">
                                                <i class="bi bi-pen me-1"></i> Sign
                                            </a>
                                        <?php elseif (($userRole === 'admin' || $userRole === 'doctor' || $userRole === 'hospital') && $c['workflow_step'] === 'WAITING_FOR_APPROVER' && !empty($c['approver_token'])): ?>
                                            <a href="<?= BASE_URL ?>esign/approver_review.php?token=<?= e($c['approver_token']) ?>" target="_blank" class="btn btn-sm btn-info text-dark fw-bold px-2" title="Review & Co-Sign">
                                                <i class="bi bi-person-check-fill me-1"></i> Review
                                            </a>
                                        <?php elseif (($userRole === 'doctor' || $userRole === 'hospital') && in_array($c['status'], ['Active', 'Approved']) && strtotime($c['expiry_date']) >= time()): ?>
                                            <?php if ($c['record_id']): ?>
                                                <a href="<?= BASE_URL ?><?= $userRole ?>/view_record.php?id=<?= $c['record_id'] ?>" class="btn btn-sm btn-primary px-2">
                                                    <i class="bi bi-file-earmark-medical me-1"></i> View Record
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= BASE_URL ?><?= $userRole ?>/authorized_records.php?patient_id=<?= $c['patient_id'] ?>" class="btn btn-sm btn-primary px-2">
                                                    <i class="bi bi-folder2-open me-1"></i> Open Vault
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Certificate Modal -->
                            <?php if (!empty($c['esign_uid']) || !empty($c['signed_document_hash'])): ?>
                                <div class="modal fade" id="modalCert<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered modal-lg">
                                        <div class="modal-content border-0 shadow">
                                            <div class="modal-header bg-dark text-white">
                                                <h5 class="modal-title fw-bold">
                                                    <i class="bi bi-patch-check-fill text-success me-2"></i>Digital Signature Verification Certificate
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <div class="p-3 bg-light rounded-3 border mb-3">
                                                    <div class="row g-2 small">
                                                        <div class="col-sm-6">
                                                            <strong class="text-muted d-block">Consent ID:</strong>
                                                            <span class="font-monospace fw-bold text-dark"><?= e($c['consent_uid']) ?></span>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong class="text-muted d-block">Workflow State:</strong>
                                                            <span class="fw-bold text-primary"><?= e($c['workflow_step'] ?? 'N/A') ?></span>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong class="text-muted d-block">Signer 1 (Data Principal):</strong>
                                                            <span><?= e($c['patient_name']) ?> (Signed: <?= format_date($c['decision_date'] ?: $c['requested_date']) ?>)</span>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong class="text-muted d-block">Signer 2 (Attending Doctor):</strong>
                                                            <span><?= e($c['requester_display_name']) ?> <?= !empty($c['doctor_signed_at']) ? '✓ (Signed: ' . format_date($c['doctor_signed_at']) . ')' : '<span class="text-warning fst-italic">Pending</span>' ?></span>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong class="text-muted d-block">Signer 3 (Reviewing Approver):</strong>
                                                            <span><?= e($c['approver_name'] ?? 'Medical Superintendent') ?> <?= !empty($c['approver_signed_at']) ? '✓ (Signed: ' . format_date($c['approver_signed_at']) . ')' : '<span class="text-warning fst-italic">Pending</span>' ?></span>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <strong class="text-muted d-block">emSigner Gateway Engine:</strong>
                                                            <span>eMudhra Multi-Party PKI / DSC Suite</span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <?php if (!empty($c['pre_sign_document_hash'])): ?>
                                                    <label class="form-label fw-bold small text-muted">Pre-Signing Document SHA-256 Hash</label>
                                                    <div class="p-2 bg-dark text-warning rounded font-monospace small text-break mb-3">
                                                        <?= e($c['pre_sign_document_hash']) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($c['signed_document_hash'])): ?>
                                                    <label class="form-label fw-bold small text-muted">Final Signed Document SHA-256 Hash</label>
                                                    <div class="p-2 bg-dark text-success rounded font-monospace small text-break mb-3">
                                                        <?= e($c['signed_document_hash']) ?>
                                                    </div>
                                                <?php elseif (!empty($c['signature_hash'])): ?>
                                                    <label class="form-label fw-bold small text-muted">Patient Digital Signature Hash</label>
                                                    <div class="p-2 bg-dark text-light rounded font-monospace small text-break mb-3">
                                                        <?= e($c['signature_hash']) ?>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="small text-muted fst-italic">
                                                    Environment: eMudhra emSigner Gateway Integration | Protocol: DEPA Health Data Exchange Dual Cryptographic Audit
                                                </div>
                                            </div>
                                            <div class="modal-footer bg-light">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
