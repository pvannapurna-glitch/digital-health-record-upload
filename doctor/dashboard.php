<?php
/**
 * Doctor Master Dashboard (with eKYC, SSO, and DEPA Authorization metrics)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Doctor Dashboard";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Auto expire passed consents
auto_expire_consents($pdo);

// Fetch Doctor Profile
$stmtDoc = $pdo->prepare("SELECT * FROM `doctors` WHERE `user_id` = ?");
$stmtDoc->execute([$userId]);
$doctor = $stmtDoc->fetch();

if (!$doctor) {
    die("Doctor profile not found. Please contact administrator.");
}

// Module Counts
$stmtAuth = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` IN ('Approved', 'Active') AND `is_esigned` = 1 AND `expiry_date` >= NOW()");
$stmtAuth->execute([$userId]);
$activeAuthCount = (int)$stmtAuth->fetchColumn();

$stmtPending = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` = 'Pending'");
$stmtPending->execute([$userId]);
$pendingCount = (int)$stmtPending->fetchColumn();

// 4 Sequential Signing Stages
$stmtDocSign = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` = 'Approved' AND `doctor_signed_at` IS NULL");
$stmtDocSign->execute([$userId]);
$pendingDoctorSignCount = (int)$stmtDocSign->fetchColumn();

$stmtDoctorSigned = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `doctor_signed_at` IS NOT NULL");
$stmtDoctorSigned->execute([$userId]);
$doctorSignedCount = (int)$stmtDoctorSigned->fetchColumn();

$stmtAwaitingApprover = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` = 'approver_pending'");
$stmtAwaitingApprover->execute([$userId]);
$awaitingApproverCount = (int)$stmtAwaitingApprover->fetchColumn();

$stmtCompletedActive = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` = 'Active' AND `expiry_date` >= NOW()");
$stmtCompletedActive->execute([$userId]);
$completedActiveCount = (int)$stmtCompletedActive->fetchColumn();

$totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM `patients`")->fetchColumn();
$totalRecordsInVault = (int)$pdo->query("SELECT COUNT(*) FROM `health_records`")->fetchColumn();

$isEkycVerified = is_user_ekyc_verified($pdo, $userId);
$docAadhaarStatus = AadhaarEkycService::getAadhaarVerificationStatus($pdo, $userId);
$isAadhaarVerified = ($docAadhaarStatus === 'VERIFIED');
$isDoctorEkycVerified = (($doctor['kyc_status'] ?? '') === 'VERIFIED');

// Fetch Recent Authorized Records (Top 5)
$stmtRecentAuth = $pdo->prepare("SELECT cr.*, p.full_name AS patient_name, p.patient_uid, p.blood_group,
                                        hr.title AS record_title, hr.record_uid, hr.record_type, hr.id AS record_real_id
                                 FROM `consent_requests` cr
                                 JOIN `patients` p ON cr.patient_id = p.id
                                 LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                                 WHERE cr.requester_user_id = ? 
                                   AND cr.status IN ('Approved', 'Active')
                                   AND cr.is_esigned = 1
                                   AND cr.expiry_date >= NOW()
                                 ORDER BY cr.decision_date DESC LIMIT 5");
$stmtRecentAuth->execute([$userId]);
$recentAuth = $stmtRecentAuth->fetchAll();

// Fetch Doctor's Recent Activity
$stmtLogs = $pdo->prepare("SELECT * FROM `audit_logs` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 5");
$stmtLogs->execute([$userId]);
$recentLogs = $stmtLogs->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<!-- Doctor Profile Banner -->
<div class="card card-custom border-0 bg-dark text-white shadow-sm p-4 mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-purple px-3 py-1 rounded-pill fw-bold" style="background: #6366f1;">
                    <i class="bi bi-heart-pulse-fill me-1"></i> Data Consumer (Medical Practitioner)
                </span>
                <?php if ($isDoctorEkycVerified): ?>
                    <span class="badge bg-success text-white px-2 py-1"><i class="bi bi-patch-check-fill me-1"></i>eMudhra eKYC Verified ✓</span>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>doctor/ekyc.php" class="badge bg-warning text-dark px-2 py-1 text-decoration-none"><i class="bi bi-shield-exclamation me-1"></i>eMudhra eKYC Required</a>
                <?php endif; ?>
                <?php if ($isAadhaarVerified || $isEkycVerified): ?>
                    <span class="badge bg-info text-dark px-2 py-1"><i class="bi bi-shield-lock-fill me-1"></i>Aadhaar Linked</span>
                <?php endif; ?>
            </div>
            <h2 class="fw-bold mb-1"><?= e($doctor['full_name']) ?></h2>
            <p class="text-white-50 mb-3">
                <span class="text-light fw-semibold"><?= e($doctor['specialization']) ?></span> | 
                License: <span class="font-monospace text-warning"><?= e($doctor['license_number']) ?></span> | 
                Affiliation: <?= e($doctor['hospital_affiliation'] ?: 'Independent Practice') ?>
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>doctor/search_patients.php" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="bi bi-search me-1"></i> Search Registry & Request Consent
                </a>
                <a href="<?= BASE_URL ?>doctor/my_requests.php" class="btn btn-<?= $pendingDoctorSignCount > 0 ? 'warning text-dark' : 'outline-light' ?> btn-sm px-3 fw-semibold">
                    <i class="bi bi-pen-fill me-1"></i> My Requests <?= $pendingDoctorSignCount > 0 ? "({$pendingDoctorSignCount} Awaiting Signature)" : "" ?>
                </a>
                <a href="<?= BASE_URL ?>doctor/ekyc.php" class="btn btn-<?= $isDoctorEkycVerified ? 'outline-success' : 'success' ?> btn-sm px-3 fw-semibold">
                    <i class="bi bi-person-bounding-box me-1"></i> eMudhra eKYC Portal
                </a>
                <a href="<?= BASE_URL ?>doctor/authorized_records.php" class="btn btn-outline-light btn-sm px-3 fw-semibold">
                    <i class="bi bi-file-earmark-medical me-1"></i> View Authorized Records (<?= $activeAuthCount ?>)
                </a>
            </div>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0 d-none d-md-block">
            <div class="bg-white bg-opacity-10 p-3 rounded-4 border border-white border-opacity-25 text-center d-inline-block" style="min-width: 200px;">
                <div class="small text-white-50 text-uppercase fw-semibold">Practitioner UID</div>
                <div class="h5 fw-bold font-monospace text-warning mb-0"><?= e($doctor['doctor_uid']) ?></div>
                <div class="small text-<?= $isDoctorEkycVerified ? 'success' : 'warning' ?> mt-1">
                    <i class="bi <?= $isDoctorEkycVerified ? 'bi-shield-check' : 'bi-shield-exclamation' ?> me-1"></i>
                    <?= $isDoctorEkycVerified ? 'eKYC Certified' : 'eKYC Pending' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($pendingDoctorSignCount > 0): ?>
<div class="alert alert-warning border-start border-warning border-4 shadow-sm mb-4 d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
        <div class="bg-warning text-dark rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;">
            <i class="bi bi-pen-fill fs-5"></i>
        </div>
        <div>
            <h6 class="mb-0 fw-bold">Signatures Pending on Approved Consents</h6>
            <span class="small text-muted">You have <strong><?= $pendingDoctorSignCount ?></strong> patient consent(s) approved awaiting your <strong>emSigner Co-Signature</strong> before records can be decrypted.</span>
        </div>
    </div>
    <a href="<?= BASE_URL ?>doctor/my_requests.php" class="btn btn-dark btn-sm fw-bold px-3">
        Sign with emSigner &rarr;
    </a>
</div>
<?php endif; ?>

<!-- DEPA Sequential Consent Signing Pipeline Tracker -->
<div class="card card-custom border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0 text-dark">
            <i class="bi bi-diagram-3-fill text-primary me-2"></i>DEPA Sequential Consent Signing Pipeline
        </h6>
        <span class="badge bg-light text-secondary border">Two-Party Authorization (Doctor & Approver)</span>
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3 border bg-warning-subtle text-warning-emphasis text-center h-100">
                    <div class="small fw-bold text-uppercase mb-1">Awaiting Doctor Action</div>
                    <div class="h3 fw-bold mb-1"><?= $pendingDoctorSignCount ?></div>
                    <a href="<?= BASE_URL ?>doctor/my_requests.php" class="btn btn-sm btn-dark w-100 fw-semibold" style="font-size: 0.75rem;">
                        <i class="bi bi-pen me-1"></i> Sign Now
                    </a>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3 border bg-light text-center h-100">
                    <div class="small fw-bold text-secondary text-uppercase mb-1">Doctor Signed</div>
                    <div class="h3 fw-bold text-primary mb-1"><?= $doctorSignedCount ?></div>
                    <span class="small text-muted d-block">Intermediate Sealed</span>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3 border bg-info-subtle text-info-emphasis text-center h-100">
                    <div class="small fw-bold text-uppercase mb-1">Awaiting Approver</div>
                    <div class="h3 fw-bold mb-1"><?= $awaitingApproverCount ?></div>
                    <a href="<?= BASE_URL ?>doctor/my_requests.php?status=approver_pending" class="small text-info-emphasis fw-bold text-decoration-none">
                        Track Review &rarr;
                    </a>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="p-3 rounded-3 border bg-success-subtle text-success-emphasis text-center h-100">
                    <div class="small fw-bold text-uppercase mb-1">Completed / Active</div>
                    <div class="h3 fw-bold mb-1"><?= $completedActiveCount ?></div>
                    <a href="<?= BASE_URL ?>doctor/authorized_records.php" class="btn btn-sm btn-success w-100 fw-semibold" style="font-size: 0.75rem;">
                        <i class="bi bi-shield-check me-1"></i> Access Records
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 6 Module Status Cards -->
<div class="row g-3 mb-4">
    <!-- 1. Aadhaar Verification Status -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card <?= $isAadhaarVerified ? 'success' : ($docAadhaarStatus === 'FAILED' ? 'danger' : 'warning') ?> p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Aadhaar Status</span>
                <i class="bi <?= $isAadhaarVerified ? 'bi-patch-check-fill text-success' : ($docAadhaarStatus === 'FAILED' ? 'bi-x-circle-fill text-danger' : 'bi-shield-exclamation text-warning') ?>"></i>
            </div>
            <div class="h5 fw-bold <?= $isAadhaarVerified ? 'text-success' : ($docAadhaarStatus === 'FAILED' ? 'text-danger' : 'text-warning-emphasis') ?> mb-1">
                <?= $isAadhaarVerified ? 'VERIFIED ✓' : ($docAadhaarStatus === 'FAILED' ? 'FAILED' : 'NOT VERIFIED') ?>
            </div>
            <a href="<?= BASE_URL ?>ekyc/verify_aadhaar.php" class="small fw-semibold text-decoration-none">
                <?= $isAadhaarVerified ? 'View Aadhaar' : 'Verify Aadhaar' ?> &rarr;
            </a>
        </div>
    </div>

    <!-- 2. SSO Gateway -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card primary p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">SSO Session</span>
                <i class="bi bi-key-fill text-primary"></i>
            </div>
            <div class="h5 fw-bold text-primary mb-1">Active</div>
            <span class="small text-muted font-monospace" style="font-size: 0.7rem;">Session Token Valid</span>
        </div>
    </div>

    <!-- 3. Active Authorizations -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card success p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Active Grants</span>
                <i class="bi bi-shield-check text-success"></i>
            </div>
            <div class="h4 fw-bold text-success mb-0"><?= $activeAuthCount ?></div>
            <a href="<?= BASE_URL ?>doctor/authorized_records.php" class="small fw-semibold text-decoration-none">Examine &rarr;</a>
        </div>
    </div>

    <!-- 4. Pending Requests -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card warning p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Pending Consent</span>
                <i class="bi bi-hourglass-split text-warning"></i>
            </div>
            <div class="h4 fw-bold text-warning-emphasis mb-0"><?= $pendingCount ?></div>
            <a href="<?= BASE_URL ?>doctor/my_requests.php" class="small fw-semibold text-decoration-none">Track Status &rarr;</a>
        </div>
    </div>

    <!-- 5. Registry Patients -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card purple p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Registry Patients</span>
                <i class="bi bi-people-fill" style="color: #6366f1;"></i>
            </div>
            <div class="h4 fw-bold mb-0" style="color: #6366f1;"><?= $totalPatients ?></div>
            <a href="<?= BASE_URL ?>doctor/search_patients.php" class="small fw-semibold text-decoration-none">Search &rarr;</a>
        </div>
    </div>

    <!-- 6. Total Records in Vault -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card danger p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Global Vault</span>
                <i class="bi bi-folder2-open text-danger"></i>
            </div>
            <div class="h4 fw-bold text-danger mb-0"><?= $totalRecordsInVault ?></div>
            <span class="small text-muted">Records Indexed</span>
        </div>
    </div>
</div>

<!-- 2-Column: Active Authorized Records & Recent Activity Log -->
<div class="row g-4 mb-4">
    <!-- Active Authorized Records -->
    <div class="col-lg-7">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6"><i class="bi bi-shield-check text-success me-2"></i>Actively Authorized Health Records</span>
                <a href="<?= BASE_URL ?>doctor/authorized_records.php" class="small fw-semibold">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentAuth)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-shield-lock display-5 text-secondary d-block mb-2"></i>
                        <p class="mb-0">No active consent authorizations yet.</p>
                        <a href="<?= BASE_URL ?>doctor/search_patients.php" class="btn btn-sm btn-primary mt-2">Search Patient to Request Consent</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Patient (ABHA)</th>
                                    <th>Record Scope</th>
                                    <th>Valid Until</th>
                                    <th class="text-end">Examine</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentAuth as $ra): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= e($ra['patient_name']) ?></div>
                                            <span class="badge bg-light text-primary border font-monospace" style="font-size: 0.68rem;"><?= e($ra['patient_uid']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark"><?= $ra['record_title'] ? e($ra['record_title']) : 'Full Medical Record' ?></div>
                                            <small class="text-muted font-monospace"><?= e($ra['consent_uid']) ?></small>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-success"><?= format_date($ra['expiry_date'], 'd M Y') ?></div>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>doctor/view_record.php?consent_id=<?= $ra['id'] ?><?= $ra['record_real_id'] ? '&record_id=' . $ra['record_real_id'] : '' ?>" class="btn btn-sm btn-primary px-3 fw-semibold">
                                                <i class="bi bi-eye me-1"></i> View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Doctor Activity Stream -->
    <div class="col-lg-5">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6"><i class="bi bi-journal-text text-warning me-2"></i>My Access Trail</span>
                <a href="<?= BASE_URL ?>doctor/history.php" class="small fw-semibold">Full Logs</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentLogs)): ?>
                    <div class="text-center py-5 text-muted small">No recent activity logged.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Event</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-primary border font-monospace" style="font-size: 0.7rem;"><?= e($log['action']) ?></span>
                                            <div class="small text-secondary text-truncate" style="max-width: 220px;" title="<?= e($log['details']) ?>"><?= e($log['details']) ?></div>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= date('d M, H:i', strtotime($log['created_at'])) ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
