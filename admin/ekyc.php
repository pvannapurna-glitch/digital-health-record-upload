<?php
/**
 * eKYC Master Verification & Audit Ledger (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "eKYC Directory & Verifications";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$statusFilter = $_GET['status'] ?? '';

// 1. Fetch Aadhaar Document eKYC verifications
$queryAadhaar = "SELECT a.*, u.username, u.email, p.patient_uid, p.full_name AS patient_name 
                 FROM `aadhaar_ekyc` a
                 JOIN `users` u ON a.user_id = u.id
                 LEFT JOIN `patients` p ON u.id = p.user_id";
$paramsA = [];
if (!empty($statusFilter)) {
    $queryAadhaar .= " WHERE a.verification_status = ?";
    $paramsA[] = strtoupper($statusFilter);
}
$queryAadhaar .= " ORDER BY a.id DESC";
$stmtA = $pdo->prepare($queryAadhaar);
$stmtA->execute($paramsA);
$aadhaarVerifications = $stmtA->fetchAll();

// 2. Fetch Sandbox / Legacy eKYC verifications
$query = "SELECT k.*, u.username, u.email, u.role AS user_role,
                 CASE 
                     WHEN u.role = 'patient' THEN p.patient_uid 
                     WHEN u.role = 'doctor' THEN d.doctor_uid 
                     WHEN u.role = 'hospital' THEN h.hospital_uid 
                     ELSE 'ADMIN-001' 
                 END AS entity_uid
          FROM `ekyc_verifications` k
          JOIN `users` u ON k.user_id = u.id
          LEFT JOIN `patients` p ON u.id = p.user_id
          LEFT JOIN `doctors` d ON u.id = d.user_id
          LEFT JOIN `hospitals` h ON u.id = h.user_id";

$params = [];
if (!empty($statusFilter)) {
    $query .= " WHERE k.status = ?";
    $params[] = ucfirst(strtolower($statusFilter));
}

$query .= " ORDER BY k.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$verifications = $stmt->fetchAll();

// Counts
$totalAadhaar       = (int)$pdo->query("SELECT COUNT(*) FROM `aadhaar_ekyc`")->fetchColumn();
$verifiedAadhaar    = (int)$pdo->query("SELECT COUNT(*) FROM `aadhaar_ekyc` WHERE `verification_status` = 'VERIFIED'")->fetchColumn();
$failedAadhaar      = (int)$pdo->query("SELECT COUNT(*) FROM `aadhaar_ekyc` WHERE `verification_status` = 'FAILED'")->fetchColumn();

$totalLegacy        = (int)$pdo->query("SELECT COUNT(*) FROM `ekyc_verifications`")->fetchColumn();
$verifiedLegacy     = (int)$pdo->query("SELECT COUNT(*) FROM `ekyc_verifications` WHERE `status` = 'Verified'")->fetchColumn();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">eKYC Identity Verification Directory</h3>
        <p class="text-secondary small mb-0">Inspect Aadhaar card document OCR verifications, demo identity authentications, and accredited participant records.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>admin/registry.php?type=EKYC" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-card-checklist me-1"></i> Central Registry View
        </a>
    </div>
</div>

<!-- eKYC Counter Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat-card primary p-3 text-center">
            <div class="h3 fw-bold text-primary mb-0"><?= $totalAadhaar ?></div>
            <div class="text-secondary small fw-semibold">Aadhaar Doc Uploads</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card success p-3 text-center">
            <div class="h3 fw-bold text-success mb-0"><?= $verifiedAadhaar ?></div>
            <div class="text-secondary small fw-semibold">Verified Aadhaar Cards</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card danger p-3 text-center">
            <div class="h3 fw-bold text-danger mb-0"><?= $failedAadhaar ?></div>
            <div class="text-secondary small fw-semibold">Failed Aadhaar Uploads</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card purple p-3 text-center">
            <div class="h3 fw-bold text-purple mb-0" style="color: #6366f1;"><?= $verifiedLegacy + $verifiedAadhaar ?></div>
            <div class="text-secondary small fw-semibold">Total Verified Participants</div>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 1. Aadhaar Card Document Upload Verifications Table (NEW) -->
<!-- ========================================================================= -->
<div class="card card-custom mb-4 shadow-sm border-0 overflow-hidden">
    <div class="card-header bg-white py-3">
        <div class="row align-items-center g-3">
            <div class="col-md-6">
                <span class="fw-bold fs-5 text-dark"><i class="bi bi-person-vcard me-2 text-primary"></i>Aadhaar Card Document Upload Verifications (<?= count($aadhaarVerifications) ?>)</span>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2 justify-content-md-end">
                    <select class="form-select form-select-sm" style="max-width: 180px;" onchange="location = this.value;">
                        <option value="<?= BASE_URL ?>admin/ekyc.php" <?= empty($statusFilter) ? 'selected' : '' ?>>All Statuses</option>
                        <option value="<?= BASE_URL ?>admin/ekyc.php?status=VERIFIED" <?= strtoupper($statusFilter) === 'VERIFIED' ? 'selected' : '' ?>>Verified</option>
                        <option value="<?= BASE_URL ?>admin/ekyc.php?status=FAILED" <?= strtoupper($statusFilter) === 'FAILED' ? 'selected' : '' ?>>Failed</option>
                    </select>
                    <input type="text" class="form-control form-control-sm table-search-input" data-table-target="aadhaarTable" placeholder="Search Aadhaar eKYC..." style="max-width: 200px;">
                </div>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($aadhaarVerifications)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-person-vcard display-5 text-secondary d-block mb-2"></i>
                <p class="mb-0">No Aadhaar document upload records found.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="aadhaarTable">
                    <thead>
                        <tr>
                            <th>Handle</th>
                            <th>Patient / ABHA ID</th>
                            <th>Doc Type</th>
                            <th>Status</th>
                            <th>Extracted Masked Demographics</th>
                            <th>Timestamp</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aadhaarVerifications as $av): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace">
                                        EKYC-AADHAAR-<?= str_pad($av['id'], 4, '0', STR_PAD_LEFT) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($av['patient_name'] ?: $av['username']) ?></div>
                                    <small class="text-muted font-monospace"><?= e($av['patient_uid'] ?: 'ABHA-PAT-' . $av['user_id']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border font-monospace"><?= e($av['document_type'] ?: 'PDF') ?></span>
                                </td>
                                <td>
                                    <?php if ($av['verification_status'] === 'VERIFIED'): ?>
                                        <span class="badge bg-success text-white"><i class="bi bi-check2-circle me-1"></i>VERIFIED</span>
                                    <?php elseif ($av['verification_status'] === 'FAILED'): ?>
                                        <span class="badge bg-danger text-white"><i class="bi bi-x-circle me-1"></i>FAILED</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>PENDING</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($av['verification_status'] === 'VERIFIED'): ?>
                                        <div class="small fw-semibold text-dark">
                                            Name: ******** | DOB: ******** | Gender: ********
                                        </div>
                                        <small class="text-success"><i class="bi bi-shield-check me-1"></i>Match: <?= e($av['verified_name']) ?> (<?= e($av['verified_gender']) ?>)</small>
                                    <?php else: ?>
                                        <small class="text-danger"><?= e($av['fail_reason'] ?: 'Unreadable document') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small text-muted"><?= format_date($av['verified_at'] ?: $av['created_at']) ?></div>
                                </td>
                                <td class="text-end">
                                    <?php if (!empty($av['document_reference'])): ?>
                                        <a href="<?= BASE_URL ?>patient/view_aadhaar_doc.php?id=<?= $av['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" title="Inspect Secure Document">
                                            <i class="bi bi-file-earmark-lock"></i> View
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 2. Sandbox OTP / Legacy Verification Ledger -->
<!-- ========================================================================= -->
<div class="card card-custom mb-4 shadow-sm border-0 overflow-hidden">
    <div class="card-header bg-white py-3">
        <span class="fw-bold fs-5 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Sandbox Identity Simulation Ledger (<?= count($verifications) ?>)</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($verifications)): ?>
            <div class="text-center py-5 text-muted">
                <p class="mb-0">No legacy sandbox verification records found.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>eKYC UID</th>
                            <th>Participant / Role</th>
                            <th>Identity Proof Type</th>
                            <th>Masked ID</th>
                            <th>OTP Status</th>
                            <th>Verification Status</th>
                            <th>Verified At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($verifications as $v): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($v['ekyc_uid']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($v['full_name']) ?></div>
                                    <span class="badge badge-<?= e($v['user_role']) ?>" style="font-size: 0.65rem;"><?= strtoupper($v['user_role']) ?></span>
                                    <small class="text-muted font-monospace ms-1"><?= e($v['entity_uid']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= e($v['doc_type']) ?></span>
                                </td>
                                <td>
                                    <span class="font-monospace text-secondary"><?= e($v['doc_number_masked']) ?></span>
                                </td>
                                <td>
                                    <?php if ($v['otp_verified']): ?>
                                        <span class="badge bg-success-subtle text-success border"><i class="bi bi-check2-circle me-1"></i>OTP Valid</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-subtle text-danger border">Unverified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_status_badge($v['status']) ?>
                                </td>
                                <td>
                                    <div class="small text-muted"><?= format_date($v['verified_at'] ?: $v['created_at']) ?></div>
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
