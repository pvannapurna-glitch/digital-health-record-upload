<?php
/**
 * Consent Requests Status Tracker (Doctor)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "My Consent Requests & eSign Tracker";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Auto expire passed consents
auto_expire_consents($pdo);

$statusFilter = $_GET['status'] ?? '';
$esignFilter = $_GET['esign_status'] ?? '';

$query = "SELECT cr.*, p.full_name AS patient_name, p.patient_uid, p.phone, p.blood_group,
                 hr.title AS record_title, hr.record_uid, hr.record_type,
                 es.esign_uid, es.signature_hash, es.signature_type, es.signature_method, es.signed_at AS esign_timestamp
          FROM `consent_requests` cr
          JOIN `patients` p ON cr.patient_id = p.id
          LEFT JOIN `health_records` hr ON cr.record_id = hr.id
          LEFT JOIN `esign_records` es ON cr.id = es.consent_id
          WHERE cr.requester_user_id = ?";

$params = [$userId];

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

$query .= " ORDER BY cr.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">DEPA Consent Requests & eSign Tracker</h3>
        <p class="text-secondary small mb-0">Track real-time authorization states, digital signatures, validity windows, and patient access grants.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>doctor/request_consent.php" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-plus-lg me-1"></i> New Consent Request
        </a>
        <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-patch-check me-1"></i> Central eSign Monitor
        </a>
    </div>
</div>

<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-5">
                <span class="fw-bold fs-5 text-dark">
                    <i class="bi bi-list-check text-primary me-2"></i>Dispatched Consent Requests (<?= count($requests) ?>)
                </span>
            </div>
            <div class="col-md-7">
                <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                    <select class="form-select form-select-sm" style="max-width: 170px;" onchange="location = this.value;">
                        <option value="<?= BASE_URL ?>doctor/my_requests.php" <?= empty($statusFilter) ? 'selected' : '' ?>>All Consent Statuses</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?status=Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active (Dual-Signed)</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?status=approver_pending" <?= $statusFilter === 'approver_pending' ? 'selected' : '' ?>>Awaiting Approver</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?status=Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending Patient</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?status=Revoked" <?= $statusFilter === 'Revoked' ? 'selected' : '' ?>>Revoked</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?status=Expired" <?= $statusFilter === 'Expired' ? 'selected' : '' ?>>Expired</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?status=Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <select class="form-select form-select-sm" style="max-width: 150px;" onchange="location = this.value;">
                        <option value="<?= BASE_URL ?>doctor/my_requests.php" <?= empty($esignFilter) ? 'selected' : '' ?>>All eSigns</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?esign_status=Signed" <?= $esignFilter === 'Signed' ? 'selected' : '' ?>>Signed</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?esign_status=Pending" <?= $esignFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?esign_status=Not Started" <?= $esignFilter === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                        <option value="<?= BASE_URL ?>doctor/my_requests.php?esign_status=Failed" <?= $esignFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
                    </select>

                    <input type="text" class="form-control form-control-sm table-search-input" data-table-target="requestsTable" placeholder="Search requests..." style="max-width: 180px;">
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox display-4 text-muted mb-3 d-block"></i>
                <h5 class="text-secondary">No Consent Requests Found</h5>
                <p class="text-muted small">No requests match your active filter.</p>
                <a href="<?= BASE_URL ?>doctor/request_consent.php" class="btn btn-primary btn-sm mt-2">Send Consent Request</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="requestsTable">
                    <thead>
                        <tr>
                            <th>Consent ID</th>
                            <th>Patient (Data Principal)</th>
                            <th>Scope / Requested Record</th>
                            <th>Purpose</th>
                            <th>Consent Status</th>
                            <th>eSign Status</th>
                            <th>Signed Date/Time</th>
                            <th>Validity Period</th>
                            <th class="text-end">Clinical Access</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($r['consent_uid']) ?></span>
                                    <div class="small text-muted mt-1"><?= format_date($r['requested_date']) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($r['patient_name']) ?></div>
                                    <small class="text-muted font-monospace"><?= e($r['patient_uid']) ?> (<?= e($r['blood_group']) ?>)</small>
                                </td>
                                <td>
                                    <?php if ($r['record_id']): ?>
                                        <div class="fw-semibold text-dark"><?= e($r['record_title']) ?></div>
                                        <small class="text-muted font-monospace"><?= e($r['record_uid']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-primary text-white">Full Health Vault (All Records)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-secondary d-inline-block text-truncate" style="max-width: 170px;" title="<?= e($r['purpose']) ?>">
                                        <?= e($r['purpose']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= render_status_badge($r['status']) ?>
                                    <?php if ($r['status'] === 'Rejected' && !empty($r['patient_notes'])): ?>
                                        <small class="text-danger d-block mt-1" title="<?= e($r['patient_notes']) ?>">Reason: <?= e($r['patient_notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_esign_status_badge($r['esign_status']) ?>
                                    <?php if (!empty($r['esign_uid'])): ?>
                                        <div class="mt-1">
                                            <span class="badge bg-success-subtle text-success border font-monospace" style="font-size: 0.68rem;" title="SHA-256: <?= e($r['signature_hash']) ?>">
                                                <?= e($r['esign_uid']) ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['esign_timestamp'])): ?>
                                        <div class="small fw-semibold text-dark"><?= format_date($r['esign_timestamp']) ?></div>
                                    <?php elseif ($r['esign_status'] === 'Signed' && !empty($r['decision_date'])): ?>
                                        <div class="small fw-semibold text-dark"><?= format_date($r['decision_date']) ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small fst-italic">Not Signed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <?php if (in_array($r['status'], ['Active', 'Approved'])): ?>
                                            <span class="text-success fw-semibold">Valid till: <?= format_date($r['expiry_date']) ?></span>
                                        <?php elseif ($r['status'] === 'Revoked'): ?>
                                            <span class="text-danger">Revoked on: <?= format_date($r['revoked_date']) ?></span>
                                        <?php elseif ($r['status'] === 'Expired'): ?>
                                            <span class="text-muted">Expired on: <?= format_date($r['expiry_date']) ?></span>
                                        <?php else: ?>
                                            <span>Expiry: <?= format_date($r['expiry_date']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex flex-wrap justify-content-end gap-1">
                                        <?php if (in_array($r['status'], ['Active', 'Approved']) && $r['is_esigned'] == 1 && strtotime($r['expiry_date']) >= time()): ?>
                                            <?php if ($r['record_id']): ?>
                                                <a href="<?= BASE_URL ?>doctor/view_record.php?id=<?= $r['record_id'] ?>" class="btn btn-sm btn-success fw-semibold px-2 shadow-sm">
                                                    <i class="bi bi-file-earmark-medical me-1"></i> Record
                                                </a>
                                            <?php else: ?>
                                                <a href="<?= BASE_URL ?>doctor/authorized_records.php?patient_id=<?= $r['patient_id'] ?>" class="btn btn-sm btn-success fw-semibold px-2 shadow-sm">
                                                    <i class="bi bi-folder2-open me-1"></i> Vault
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>

                                        <?php if (empty($r['doctor_signed_at']) && !in_array($r['status'], ['Revoked', 'Expired', 'Rejected'])): ?>
                                            <a href="<?= BASE_URL ?>doctor/esign_request.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-primary fw-bold px-2 shadow-sm" title="Digitally sign with emSigner">
                                                <i class="bi bi-pen-fill me-1"></i> Sign with emSigner
                                            </a>
                                        <?php elseif (!empty($r['doctor_signed_at']) && empty($r['approver_signed_at'])): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle p-2" title="Doctor signed. Waiting for Medical Superintendent / Approver.">
                                                <i class="bi bi-hourglass-split me-1"></i>Awaiting Approver
                                            </span>
                                        <?php elseif ($r['status'] === 'Revoked'): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Revoked</span>
                                        <?php elseif ($r['status'] === 'Expired'): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border">Expired</span>
                                        <?php elseif ($r['status'] === 'Pending'): ?>
                                            <span class="badge bg-light text-muted border">Pending Patient</span>
                                        <?php endif; ?>

                                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary px-2" title="View Consent Agreement PDF">
                                            <i class="bi bi-file-earmark-pdf"></i>
                                        </a>
                                    </div>
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
