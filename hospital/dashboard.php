<?php
/**
 * Hospital Master Dashboard (with eKYC, SSO, and Institutional Authorizations)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Hospital Dashboard";
require_once __DIR__ . '/../config/config.php';

require_role('hospital');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Auto expire passed consents
auto_expire_consents($pdo);

// Fetch Hospital Profile
$stmtHosp = $pdo->prepare("SELECT * FROM `hospitals` WHERE `user_id` = ?");
$stmtHosp->execute([$userId]);
$hospital = $stmtHosp->fetch();

if (!$hospital) {
    die("Hospital profile not found. Please contact administrator.");
}

// Module Counts
$stmtAuth = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` IN ('Approved', 'Active') AND `is_esigned` = 1 AND `expiry_date` >= NOW()");
$stmtAuth->execute([$userId]);
$activeAuthCount = (int)$stmtAuth->fetchColumn();

$stmtPending = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `requester_user_id` = ? AND `status` = 'Pending'");
$stmtPending->execute([$userId]);
$pendingCount = (int)$stmtPending->fetchColumn();

$totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM `patients`")->fetchColumn();
$totalDoctors = (int)$pdo->query("SELECT COUNT(*) FROM `doctors`")->fetchColumn();

$isEkycVerified = is_user_ekyc_verified($pdo, $userId);

// Fetch Recent Authorized Records for Hospital (Top 5)
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

// Fetch Hospital Recent Activity Logs
$stmtLogs = $pdo->prepare("SELECT * FROM `audit_logs` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 5");
$stmtLogs->execute([$userId]);
$recentLogs = $stmtLogs->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<!-- Hospital Profile Banner -->
<div class="card card-custom border-0 bg-dark text-white shadow-sm p-4 mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge bg-success px-3 py-1 rounded-pill fw-bold">
                    <i class="bi bi-hospital me-1"></i> Institutional Data Consumer (Hospital)
                </span>
                <?php if ($isEkycVerified): ?>
                    <span class="badge bg-success text-white px-2 py-1"><i class="bi bi-patch-check-fill me-1"></i>eKYC Verified</span>
                <?php endif; ?>
            </div>
            <h2 class="fw-bold mb-1"><?= e($hospital['hospital_name']) ?></h2>
            <p class="text-white-50 mb-3">
                Superintendent: <span class="text-light fw-semibold"><?= e($hospital['contact_person']) ?></span> | 
                License: <span class="font-monospace text-warning"><?= e($hospital['license_number']) ?></span> | 
                Est: <?= e($hospital['established_year'] ?: '1990') ?>
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>hospital/search_patients.php" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="bi bi-search me-1"></i> Patient Directory & Consent
                </a>
                <a href="<?= BASE_URL ?>hospital/authorized_records.php" class="btn btn-outline-light btn-sm px-3 fw-semibold">
                    <i class="bi bi-folder2-open me-1"></i> Authorized Patient Records (<?= $activeAuthCount ?>)
                </a>
                <a href="<?= BASE_URL ?>ekyc/verify.php" class="btn btn-outline-success btn-sm px-3 fw-semibold">
                    <i class="bi bi-patch-check me-1"></i> eKYC Status
                </a>
            </div>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0 d-none d-md-block">
            <div class="bg-white bg-opacity-10 p-3 rounded-4 border border-white border-opacity-25 text-center d-inline-block" style="min-width: 200px;">
                <div class="small text-white-50 text-uppercase fw-semibold">Facility UID</div>
                <div class="h5 fw-bold font-monospace text-warning mb-0"><?= e($hospital['hospital_uid']) ?></div>
                <div class="small text-success mt-1"><i class="bi bi-shield-check me-1"></i>NABH Accredited</div>
            </div>
        </div>
    </div>
</div>

<!-- 6 Module Status Cards -->
<div class="row g-3 mb-4">
    <!-- 1. eKYC Status -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card <?= $isEkycVerified ? 'success' : 'warning' ?> p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">eKYC License</span>
                <i class="bi <?= $isEkycVerified ? 'bi-patch-check-fill text-success' : 'bi-exclamation-circle-fill text-warning' ?>"></i>
            </div>
            <div class="h5 fw-bold <?= $isEkycVerified ? 'text-success' : 'text-warning-emphasis' ?> mb-1">
                <?= $isEkycVerified ? 'Verified' : 'Pending' ?>
            </div>
            <a href="<?= BASE_URL ?>ekyc/verify.php" class="small fw-semibold text-decoration-none">
                <?= $isEkycVerified ? 'Certificate' : 'Verify Facility' ?> &rarr;
            </a>
        </div>
    </div>

    <!-- 2. SSO Gateway -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card primary p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">SSO Portal</span>
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
            <a href="<?= BASE_URL ?>hospital/authorized_records.php" class="small fw-semibold text-decoration-none">Patient Records &rarr;</a>
        </div>
    </div>

    <!-- 4. Pending Requests -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card warning p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Pending Requests</span>
                <i class="bi bi-hourglass-split text-warning"></i>
            </div>
            <div class="h4 fw-bold text-warning-emphasis mb-0"><?= $pendingCount ?></div>
            <a href="<?= BASE_URL ?>hospital/my_requests.php" class="small fw-semibold text-decoration-none">Request Tracker &rarr;</a>
        </div>
    </div>

    <!-- 5. Registry Patients -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card purple p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Patients</span>
                <i class="bi bi-people-fill" style="color: #6366f1;"></i>
            </div>
            <div class="h4 fw-bold mb-0" style="color: #6366f1;"><?= $totalPatients ?></div>
            <a href="<?= BASE_URL ?>hospital/search_patients.php" class="small fw-semibold text-decoration-none">Lookup &rarr;</a>
        </div>
    </div>

    <!-- 6. Ecosystem Doctors -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card danger p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Doctors</span>
                <i class="bi bi-heart-pulse text-danger"></i>
            </div>
            <div class="h4 fw-bold text-danger mb-0"><?= $totalDoctors ?></div>
            <span class="small text-muted">Affiliated Clinicians</span>
        </div>
    </div>
</div>

<!-- 2-Column: Active Authorized Records & Hospital Activity Stream -->
<div class="row g-4 mb-4">
    <!-- Active Authorized Records -->
    <div class="col-lg-7">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6"><i class="bi bi-hospital text-success me-2"></i>Institutional Patient Access Ledger</span>
                <a href="<?= BASE_URL ?>hospital/authorized_records.php" class="small fw-semibold">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentAuth)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-shield-lock display-5 text-secondary d-block mb-2"></i>
                        <p class="mb-0">No active institutional consent authorizations.</p>
                        <a href="<?= BASE_URL ?>hospital/search_patients.php" class="btn btn-sm btn-primary mt-2">Search Patient to Request Access</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Patient</th>
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
                                            <a href="<?= BASE_URL ?>hospital/view_record.php?consent_id=<?= $ra['id'] ?><?= $ra['record_real_id'] ? '&record_id=' . $ra['record_real_id'] : '' ?>" class="btn btn-sm btn-success px-3 fw-semibold">
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

    <!-- Hospital Activity Log -->
    <div class="col-lg-5">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6"><i class="bi bi-journal-text text-warning me-2"></i>Institutional Activity Trail</span>
                <a href="<?= BASE_URL ?>hospital/history.php" class="small fw-semibold">Full Logs</a>
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
