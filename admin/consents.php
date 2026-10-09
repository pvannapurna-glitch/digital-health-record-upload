<?php
/**
 * DEPA Global Consent Monitor (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "DEPA Consent & eSign Monitor";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

// Auto expire passed consents
auto_expire_consents($pdo);

$statusFilter = $_GET['status'] ?? '';
$esignFilter = $_GET['esign_status'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

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
          WHERE 1=1";

$params = [];

if (!empty($statusFilter)) {
    if ($statusFilter === 'Active' || $statusFilter === 'Approved') {
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

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">DEPA Electronic Consent & eSign Monitor</h3>
        <p class="text-secondary small mb-0">Ecosystem-wide real-time tracking of consent handles, authorizations, digital signatures, and revocations.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-patch-check me-1"></i> Dedicated eSign Monitor
        </a>
        <a href="<?= BASE_URL ?>admin/registry.php" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-card-checklist me-1"></i> Central Registry Directory
        </a>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <form method="GET" action="" class="row align-items-center g-3">
            <div class="col-md-3">
                <span class="fw-bold fs-5 text-dark">Consent Flows (<?= count($consents) ?>)</span>
            </div>
            <div class="col-md-9">
                <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Search consent, patient, ID..." value="<?= e($searchQuery) ?>" style="max-width: 180px;">

                    <select name="status" class="form-select form-select-sm" style="max-width: 160px;" onchange="this.form.submit();">
                        <option value="">All Consent Statuses</option>
                        <option value="Active" <?= in_array($statusFilter, ['Active', 'Approved']) ? 'selected' : '' ?>>Active</option>
                        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Revoked" <?= $statusFilter === 'Revoked' ? 'selected' : '' ?>>Revoked</option>
                        <option value="Expired" <?= $statusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <select name="esign_status" class="form-select form-select-sm" style="max-width: 150px;" onchange="this.form.submit();">
                        <option value="">All eSigns</option>
                        <option value="Signed" <?= $esignFilter === 'Signed' ? 'selected' : '' ?>>Signed</option>
                        <option value="Pending" <?= $esignFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="Not Started" <?= $esignFilter === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                        <option value="Failed" <?= $esignFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
                    </select>

                    <button type="submit" class="btn btn-sm btn-primary px-3">Filter</button>
                    <?php if (!empty($searchQuery) || !empty($statusFilter) || !empty($esignFilter)): ?>
                        <a href="<?= BASE_URL ?>admin/consents.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($consents)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-inbox display-4 text-secondary d-block mb-2"></i>
                <p class="mb-0">No consent artifacts match the selected criteria.</p>
                <a href="<?= BASE_URL ?>admin/consents.php" class="btn btn-sm btn-outline-primary mt-2">Reset Filters</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="consentsTable">
                    <thead>
                        <tr>
                            <th>Consent Handle</th>
                            <th>Data Principal (Patient)</th>
                            <th>Data Consumer (Requester)</th>
                            <th>Scope / Record</th>
                            <th>Consent Status</th>
                            <th>eSign Status</th>
                            <th>Signed Date/Time</th>
                            <th>Validity / Expiry</th>
                            <th class="text-end">DEPA Inspector</th>
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
                                    <small class="text-muted font-monospace"><?= e($c['patient_uid']) ?></small>
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
                                        <span class="badge bg-primary text-white">Full Health Vault</span>
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
                                    <?php if (!empty($c['esign_uid'])): ?>
                                        <small class="font-monospace text-muted d-block"><?= e($c['esign_uid']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($c['esign_timestamp'])): ?>
                                        <div class="small fw-semibold"><?= format_date($c['esign_timestamp']) ?></div>
                                    <?php elseif ($c['esign_status'] === 'Signed' && !empty($c['decision_date'])): ?>
                                        <div class="small fw-semibold"><?= format_date($c['decision_date']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Not Signed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <?php if (in_array($c['status'], ['Active', 'Approved'])): ?>
                                            <span class="text-success fw-semibold">Expires: <?= format_date($c['expiry_date']) ?></span>
                                        <?php elseif ($c['status'] === 'Revoked'): ?>
                                            <span class="text-danger">Revoked: <?= format_date($c['revoked_date']) ?></span>
                                        <?php elseif ($c['status'] === 'Expired'): ?>
                                            <span class="text-muted">Expired: <?= format_date($c['expiry_date']) ?></span>
                                        <?php else: ?>
                                            <span>Target: <?= format_date($c['expiry_date']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $c['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary px-2" title="View Consent PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                        <?php if ($c['workflow_step'] === 'WAITING_FOR_APPROVER' && !empty($c['approver_token'])): ?>
                                            <a href="<?= BASE_URL ?>esign/approver_review.php?token=<?= e($c['approver_token']) ?>" target="_blank" class="btn btn-sm btn-outline-info px-2" title="Open Approver Review Portal">
                                                <i class="bi bi-person-check-fill"></i> Review
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-dark px-2" data-bs-toggle="modal" data-bs-target="#modalConsent<?= $c['id'] ?>" title="Inspect Artifact">
                                            <i class="bi bi-code-square"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- DEPA Consent Artifact Inspector Modal -->
                            <div class="modal fade" id="modalConsent<?= $c['id'] ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header bg-dark text-white">
                                            <h5 class="modal-title fw-bold">
                                                <i class="bi bi-shield-check me-2 text-warning"></i>DEPA Electronic Consent & eSign Artifact (<?= e($c['consent_uid']) ?>)
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <div class="row g-3 mb-3">
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Consent ID</small>
                                                    <span class="font-monospace fw-bold text-primary"><?= e($c['consent_uid']) ?></span>
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Current Status</small>
                                                    <div class="d-flex gap-1">
                                                        <?= render_status_badge($c['status']) ?>
                                                        <?= render_esign_status_badge($c['esign_status']) ?>
                                                    </div>
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Data Principal (Patient)</small>
                                                    <span class="fw-bold"><?= e($c['patient_name']) ?></span> (<?= e($c['patient_uid']) ?>)
                                                </div>
                                                <div class="col-sm-6">
                                                    <small class="text-muted d-block">Data Consumer (Requester)</small>
                                                    <span class="fw-bold"><?= e($c['requester_display_name']) ?></span> (<?= ucfirst($c['requester_role']) ?>)
                                                </div>
                                                <?php if (!empty($c['esign_uid'])): ?>
                                                    <div class="col-sm-6">
                                                        <small class="text-muted d-block">eSign Handle</small>
                                                        <span class="fw-bold text-success font-monospace"><?= e($c['esign_uid']) ?></span>
                                                    </div>
                                                    <div class="col-sm-6">
                                                        <small class="text-muted d-block">Signature Method</small>
                                                        <span><?= e($c['signature_method'] ?: ($c['signature_type'] ?: 'LOCAL_SIGNATURE_IMAGE')) ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label fw-bold">Stated Clinical Purpose</label>
                                                <div class="p-2 bg-light rounded border small">
                                                    <?= e($c['purpose']) ?>
                                                </div>
                                            </div>

                                            <?php if (!empty($c['patient_notes'])): ?>
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Patient Remarks / Notes</label>
                                                    <div class="p-2 bg-light rounded border small text-secondary">
                                                        <?= e($c['patient_notes']) ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <label class="form-label fw-bold">Raw DEPA Consent & Digital Signature Artifact JSON</label>
                                            <div class="bg-dark text-light p-3 rounded font-monospace small">
<pre class="mb-0 text-light"><?= e(json_encode([
    'consent_id'              => $c['consent_uid'],
    'workflow_step'           => $c['workflow_step'] ?? 'N/A',
    'data_principal'          => ['name' => $c['patient_name'], 'patient_uid' => $c['patient_uid']],
    'data_consumer'           => ['name' => $c['requester_display_name'], 'role' => $c['requester_role']],
    'record_scope'            => $c['record_id'] ? ['record_id' => $c['record_id'], 'record_uid' => $c['record_uid'], 'title' => $c['record_title']] : 'ALL_PATIENT_RECORDS',
    'purpose'                 => $c['purpose'],
    'consent_status'          => $c['status'],
    'esign_status'            => $c['esign_status'],
    'pre_sign_document_hash'  => $c['pre_sign_document_hash'] ?? null,
    'signed_document_hash'    => $c['signed_document_hash'] ?? null,
    'patient_signature_hash'  => $c['signature_hash'] ?? null,
    'doctor_signed_at'        => $c['doctor_signed_at'] ?? null,
    'approver_signed_at'      => $c['approver_signed_at'] ?? null,
    'approver_name'           => $c['approver_name'] ?? null,
    'requested_timestamp'     => $c['requested_date'],
    'expiry_timestamp'        => $c['expiry_date'],
    'signed_timestamp'        => $c['esign_timestamp'] ?? $c['decision_date'],
    'revocation_timestamp'    => $c['revoked_date']
], JSON_PRETTY_PRINT)) ?></pre>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
