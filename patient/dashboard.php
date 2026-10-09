<?php
/**
 * Patient Master Dashboard (with eKYC, SSO, DEPA Consent, and eSign Status)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Patient Dashboard";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Auto expire any consents that passed expiry
auto_expire_consents($pdo);

// Fetch patient demographics
$stmtPatient = $pdo->prepare("SELECT * FROM `patients` WHERE `user_id` = ?");
$stmtPatient->execute([$userId]);
$patient = $stmtPatient->fetch();

if (!$patient) {
    die("Patient profile not found. Please contact administrator.");
}

$patientId = $patient['id'];

// Get Module Statistics
$stmtTotalRecords = $pdo->prepare("SELECT COUNT(*) FROM `health_records` WHERE `patient_id` = ?");
$stmtTotalRecords->execute([$patientId]);
$totalRecords = (int)$stmtTotalRecords->fetchColumn();

$stmtPending = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `patient_id` = ? AND `status` = 'Pending'");
$stmtPending->execute([$patientId]);
$pendingCount = (int)$stmtPending->fetchColumn();

$stmtActiveConsents = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `patient_id` = ? AND `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()");
$stmtActiveConsents->execute([$patientId]);
$activeConsentsCount = (int)$stmtActiveConsents->fetchColumn();

$stmtEsignCount = $pdo->prepare("SELECT COUNT(*) FROM `esign_records` WHERE `user_id` = ?");
$stmtEsignCount->execute([$userId]);
$totalEsignCount = (int)$stmtEsignCount->fetchColumn();

// eKYC Info
$ekycInfo = get_user_ekyc_info($pdo, $userId);
$isEkycVerified = is_user_ekyc_verified($pdo, $userId);
$latestAadhaar = AadhaarEkycService::getLatestAadhaarEkyc($pdo, $userId);
$isAadhaarVerified = ($latestAadhaar && $latestAadhaar['verification_status'] === 'VERIFIED');

// Fetch Pending Requests (Top 5)
$stmtReq = $pdo->prepare("SELECT cr.*, u.username AS requester_username, 
                                 CASE 
                                     WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                     WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                     ELSE u.username 
                                 END AS requester_display_name,
                                 d.specialization, d.license_number AS doc_license,
                                 h.license_number AS hosp_license,
                                 hr.title AS record_title, hr.record_uid
                          FROM `consent_requests` cr
                          JOIN `users` u ON cr.requester_user_id = u.id
                          LEFT JOIN `doctors` d ON u.id = d.user_id
                          LEFT JOIN `hospitals` h ON u.id = h.user_id
                          LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                          WHERE cr.patient_id = ? AND cr.status = 'Pending'
                          ORDER BY cr.id DESC LIMIT 5");
$stmtReq->execute([$patientId]);
$pendingRequests = $stmtReq->fetchAll();

// Fetch Recent Records (Top 5)
$stmtRec = $pdo->prepare("SELECT * FROM `health_records` WHERE `patient_id` = ? ORDER BY `record_date` DESC, `id` DESC LIMIT 5");
$stmtRec->execute([$patientId]);
$recentRecords = $stmtRec->fetchAll();

// Fetch Recent Activity Logs for Patient
$stmtLogs = $pdo->prepare("SELECT * FROM `audit_logs` WHERE `user_id` = ? ORDER BY `created_at` DESC LIMIT 6");
$stmtLogs->execute([$userId]);
$recentLogs = $stmtLogs->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<!-- Welcome Banner & ABHA Identity Locker Card -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card card-custom h-100 p-4 border-0 bg-white shadow-sm d-flex flex-column justify-content-between">
            <div>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-1 rounded-pill fw-semibold">
                        <i class="bi bi-shield-check me-1"></i> Data Principal (DEPA Ecosystem)
                    </span>
                    <span class="text-muted small"><i class="bi bi-clock me-1"></i><?= date('l, d F Y') ?></span>
                </div>
                <h2 class="fw-bold text-dark mb-1">Hello, <?= e($patient['full_name']) ?>!</h2>
                <p class="text-secondary mb-3">
                    Welcome to your central digital health locker. You maintain sovereign control over your medical records, eKYC status, and electronic consent authorizations.
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2 pt-2">
                <a href="<?= BASE_URL ?>patient/add_record.php" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="bi bi-plus-circle me-1"></i> Add Health Record
                </a>
                <a href="<?= BASE_URL ?>patient/consent_requests.php" class="btn btn-outline-primary btn-sm px-3 fw-semibold position-relative">
                    <i class="bi bi-shield-check me-1"></i> Consent Requests
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-danger rounded-pill ms-1"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= BASE_URL ?>patient/sharing_history.php" class="btn btn-outline-secondary btn-sm px-3 fw-semibold">
                    <i class="bi bi-pen me-1"></i> Sharing & eSigns
                </a>
                <a href="<?= BASE_URL ?>ekyc/verify_aadhaar.php" class="btn btn-<?= $isAadhaarVerified ? 'outline-success' : 'warning' ?> btn-sm px-3 fw-semibold">
                    <i class="bi bi-shield-check me-1"></i> Verify Aadhaar
                </a>
            </div>
        </div>
    </div>

    <!-- ABHA Health ID Widget Card -->
    <div class="col-lg-5">
        <div class="card card-custom h-100 border-0 abha-card text-white p-4 d-flex flex-column justify-content-between position-relative shadow">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <span class="badge bg-white bg-opacity-25 text-white px-2 py-1 mb-2 font-monospace" style="font-size: 0.7rem;">NATIONAL HEALTH AUTHORITY</span>
                    <h5 class="fw-bold mb-0">Ayushman Bharat Health Account (ABHA)</h5>
                </div>
                <div class="bg-white p-1 rounded-2">
                    <i class="bi bi-qr-code text-dark fs-3"></i>
                </div>
            </div>
            <div class="my-3">
                <div class="small text-white-50 text-uppercase fw-semibold">ABHA Health ID Number</div>
                <div class="h4 fw-bold font-monospace tracking-wide mb-1 text-warning"><?= e($patient['patient_uid']) ?></div>
                <div class="fw-semibold fs-5"><?= e($patient['full_name']) ?></div>
            </div>
            <div class="d-flex justify-content-between align-items-end pt-2 border-top border-white border-opacity-25 small text-white-50">
                <div>
                    <span>DOB: <?= date('d-m-Y', strtotime($patient['dob'])) ?></span> | 
                    <span>Gender: <?= e($patient['gender']) ?></span>
                </div>
                <div>
                    <span class="badge bg-danger text-white border border-white border-opacity-50">Blood: <?= e($patient['blood_group']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Key 6 Modules Dashboard Status Cards -->
<div class="row g-3 mb-4">
    <!-- 1. Aadhaar Verification Status Card -->
    <div class="col-md-4 col-lg-2">
        <?php $pAadhaarStatus = AadhaarEkycService::getAadhaarVerificationStatus($pdo, $userId); ?>
        <div class="stat-card <?= $isAadhaarVerified ? 'success' : ($pAadhaarStatus === 'FAILED' ? 'danger' : 'warning') ?> p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Aadhaar Status</span>
                <i class="bi <?= $isAadhaarVerified ? 'bi-patch-check-fill text-success' : ($pAadhaarStatus === 'FAILED' ? 'bi-x-circle-fill text-danger' : 'bi-shield-exclamation text-warning') ?>"></i>
            </div>
            <div class="h5 fw-bold <?= $isAadhaarVerified ? 'text-success' : ($pAadhaarStatus === 'FAILED' ? 'text-danger' : 'text-warning-emphasis') ?> mb-1">
                <?= $isAadhaarVerified ? 'VERIFIED ✓' : ($pAadhaarStatus === 'FAILED' ? 'FAILED' : 'NOT VERIFIED') ?>
            </div>
            <a href="<?= BASE_URL ?>ekyc/verify_aadhaar.php" class="small fw-semibold text-decoration-none d-block">
                <?= $isAadhaarVerified ? 'View Aadhaar' : 'Verify Aadhaar' ?> &rarr;
            </a>
        </div>
    </div>

    <!-- 2. SSO Login Status Card -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card primary p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">SSO Gateway</span>
                <i class="bi bi-key-fill text-primary"></i>
            </div>
            <div class="h5 fw-bold text-primary mb-1">Active</div>
            <div class="small text-muted font-monospace" style="font-size: 0.7rem;">Session Token Verified</div>
        </div>
    </div>

    <!-- 3. Health Records Card -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card danger p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Health Records</span>
                <i class="bi bi-folder2-open text-danger"></i>
            </div>
            <div class="h4 fw-bold text-danger mb-0"><?= $totalRecords ?></div>
            <a href="<?= BASE_URL ?>patient/my_records.php" class="small fw-semibold text-decoration-none">Vault Ledger &rarr;</a>
        </div>
    </div>

    <!-- 4. Active DEPA Consents Card -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card success p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Active Grants</span>
                <i class="bi bi-shield-check text-success"></i>
            </div>
            <div class="h4 fw-bold text-success mb-0"><?= $activeConsentsCount ?></div>
            <a href="<?= BASE_URL ?>patient/sharing_history.php?status=Approved" class="small fw-semibold text-decoration-none">View Active &rarr;</a>
        </div>
    </div>

    <!-- 5. Pending Consent Requests Card -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card warning p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">Pending Requests</span>
                <i class="bi bi-bell text-warning"></i>
            </div>
            <div class="h4 fw-bold text-warning-emphasis mb-0"><?= $pendingCount ?></div>
            <a href="<?= BASE_URL ?>patient/consent_requests.php" class="small fw-semibold text-decoration-none">Review Requests &rarr;</a>
        </div>
    </div>

    <!-- 6. eSign Digital Signatures Card -->
    <div class="col-md-4 col-lg-2">
        <div class="stat-card purple p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="text-secondary small fw-semibold">eSign Artifacts</span>
                <i class="bi bi-pen-fill" style="color: #6366f1;"></i>
            </div>
            <div class="h4 fw-bold mb-0" style="color: #6366f1;"><?= $totalEsignCount ?></div>
            <span class="small text-muted">Digitally Signed</span>
        </div>
    </div>
</div>

<!-- 2-Column Section: Pending Consent Requests & Recent Health Records -->
<div class="row g-4 mb-4">
    <!-- Pending Requests with eSign Action -->
    <div class="col-lg-6">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6">
                    <i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Consent Requests
                </span>
                <a href="<?= BASE_URL ?>patient/consent_requests.php" class="small fw-semibold">View All (<?= $pendingCount ?>)</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($pendingRequests)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-shield-check display-5 text-success d-block mb-2"></i>
                        <p class="mb-0">No pending consent requests waiting for decision.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Requester</th>
                                    <th>Scope / Purpose</th>
                                    <th class="text-end">Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingRequests as $req): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= e($req['requester_display_name']) ?></div>
                                            <span class="badge badge-<?= e($req['requester_role']) ?> text-uppercase" style="font-size: 0.65rem;"><?= e($req['requester_role']) ?></span>
                                            <span class="badge bg-light text-primary border font-monospace ms-1" style="font-size: 0.65rem;"><?= e($req['consent_uid']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold text-dark"><?= $req['record_title'] ? e($req['record_title']) : 'Full Medical History' ?></div>
                                            <small class="text-secondary text-truncate d-inline-block" style="max-width: 180px;" title="<?= e($req['purpose']) ?>"><?= e($req['purpose']) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>patient/esign_consent.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-success px-2 fw-semibold">
                                                <i class="bi bi-pen-fill me-1"></i> eSign
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

    <!-- Recent Health Records -->
    <div class="col-lg-6">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-6">
                    <i class="bi bi-folder2-open me-2 text-primary"></i>Recent Health Records
                </span>
                <a href="<?= BASE_URL ?>patient/my_records.php" class="small fw-semibold">All Records (<?= $totalRecords ?>)</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentRecords)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-journal-medical display-5 text-secondary d-block mb-2"></i>
                        <p class="mb-0">No health records uploaded yet.</p>
                        <a href="<?= BASE_URL ?>patient/add_record.php" class="btn btn-sm btn-primary mt-2">Upload First Record</a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Record UID / Title</th>
                                    <th>Category</th>
                                    <th>Date</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentRecords as $rec): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-primary border font-monospace" style="font-size: 0.7rem;"><?= e($rec['record_uid']) ?></span>
                                            <div class="fw-bold text-dark text-truncate" style="max-width: 200px;"><?= e($rec['title']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-subtle text-secondary border"><?= e($rec['record_type']) ?></span>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= date('d M Y', strtotime($rec['record_date'])) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!empty($rec['file_attachment'])): ?>
                                                <a href="<?= BASE_URL ?>includes/view_document.php?id=<?= $rec['id'] ?>" target="_blank" class="btn btn-sm btn-outline-success py-0 px-2" title="View Document">
                                                    <i class="bi bi-file-earmark-pdf"></i> Doc
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
    </div>
</div>

<!-- Recent Activity Audit Log Stream -->
<div class="card card-custom shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <span class="fw-bold fs-6"><i class="bi bi-journal-text me-2 text-warning"></i>My Recent System Activity & Security Stream</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentLogs)): ?>
            <div class="text-center py-4 text-muted small">No recent activity logged.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Action Event</th>
                            <th>Details</th>
                            <th>Timestamp</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentLogs as $log): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($log['action']) ?></span>
                                </td>
                                <td>
                                    <span class="small text-secondary"><?= e($log['details']) ?></span>
                                </td>
                                <td>
                                    <small class="text-muted"><?= format_date($log['created_at']) ?></small>
                                </td>
                                <td>
                                    <small class="text-muted font-monospace"><?= e($log['ip_address']) ?></small>
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
