<?php
/**
 * Administrator Master Dashboard (with eKYC, SSO, and DEPA Ecosystem Metrics)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Admin Console";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

// Auto expire passed consents
auto_expire_consents($pdo);

// Global Ecosystem Counts
$totalPatients  = (int)$pdo->query("SELECT COUNT(*) FROM `patients`")->fetchColumn();
$totalDoctors   = (int)$pdo->query("SELECT COUNT(*) FROM `doctors`")->fetchColumn();
$totalHospitals = (int)$pdo->query("SELECT COUNT(*) FROM `hospitals`")->fetchColumn();
$totalRecords   = (int)$pdo->query("SELECT COUNT(*) FROM `health_records`")->fetchColumn();
$totalConsents  = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests`")->fetchColumn();
$totalLogs      = (int)$pdo->query("SELECT COUNT(*) FROM `audit_logs`")->fetchColumn();

// eKYC & SSO & eSign Stats
$totalEkycVerified = (int)$pdo->query("SELECT COUNT(*) FROM `ekyc_verifications` WHERE `status` = 'Verified'")->fetchColumn();
$totalEkycPending  = (int)$pdo->query("SELECT COUNT(*) FROM `ekyc_verifications` WHERE `status` = 'Pending'")->fetchColumn();
$activeSsoSessions = (int)$pdo->query("SELECT COUNT(*) FROM `sso_sessions` WHERE `is_active` = 1")->fetchColumn();
$totalEsignRecords = (int)$pdo->query("SELECT COUNT(*) FROM `esign_records` WHERE `status` = 'Signed'")->fetchColumn();

// Consent Status Breakdown
$approvedCount = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests` WHERE `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()")->fetchColumn();
$pendingCount  = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests` WHERE `status` = 'Pending'")->fetchColumn();
$revokedCount  = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests` WHERE `status` = 'Revoked'")->fetchColumn();
$expiredCount  = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests` WHERE `status` = 'Expired' OR (`status` IN ('Approved', 'Active') AND `expiry_date` < NOW())")->fetchColumn();
$rejectedCount = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests` WHERE `status` = 'Rejected'")->fetchColumn();

// Recent Audit Logs (Top 8)
$stmtLogs = $pdo->query("SELECT * FROM `audit_logs` ORDER BY `created_at` DESC LIMIT 8");
$recentLogs = $stmtLogs->fetchAll();

// Recent Consent Flows
$stmtRecentConsents = $pdo->query("SELECT cr.*, p.full_name AS patient_name, p.patient_uid,
                                          CASE 
                                              WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                              WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                              ELSE u.username 
                                          END AS requester_display_name,
                                          hr.title AS record_title,
                                          es.esign_uid
                                   FROM `consent_requests` cr
                                   JOIN `patients` p ON cr.patient_id = p.id
                                   JOIN `users` u ON cr.requester_user_id = u.id
                                   LEFT JOIN `doctors` d ON u.id = d.user_id
                                   LEFT JOIN `hospitals` h ON u.id = h.user_id
                                   LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                                   LEFT JOIN `esign_records` es ON cr.id = es.consent_id
                                   ORDER BY cr.id DESC LIMIT 5");
$recentConsents = $stmtRecentConsents->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<!-- Admin Banner -->
<div class="card card-custom border-0 bg-dark text-white shadow-sm p-4 mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <span class="badge bg-warning text-dark px-3 py-1 rounded-pill fw-bold mb-2">
                <i class="bi bi-shield-lock-fill me-1"></i> DEPA Registry Controller & Ecosystem Admin
            </span>
            <h2 class="fw-bold mb-1">Health Exchange System Administration</h2>
            <p class="text-white-50 mb-3" style="max-width: 600px;">
                Manage participant registries, verify eKYC sandbox identities, inspect Single Sign-On (SSO) active sessions, monitor real-time eSigned consent lifecycles, and audit system compliance.
            </p>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>admin/ekyc.php" class="btn btn-outline-success btn-sm px-3 fw-semibold">
                    <i class="bi bi-patch-check me-1"></i> eKYC Directory (<?= $totalEkycVerified ?>)
                </a>
                <a href="<?= BASE_URL ?>admin/registry.php" class="btn btn-primary btn-sm px-3 fw-semibold">
                    <i class="bi bi-card-checklist me-1"></i> Central Registry Directory
                </a>
                <a href="<?= BASE_URL ?>admin/consents.php" class="btn btn-outline-light btn-sm px-3 fw-semibold">
                    <i class="bi bi-shield-check me-1"></i> Consent & eSign Monitor
                </a>
                <a href="<?= BASE_URL ?>admin/audit_logs.php" class="btn btn-outline-warning btn-sm px-3 fw-semibold">
                    <i class="bi bi-journal-text me-1"></i> Full Audit Trail
                </a>
            </div>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0 d-none d-md-block">
            <div class="bg-white bg-opacity-10 p-3 rounded-4 border border-white border-opacity-25 text-center d-inline-block" style="min-width: 220px;">
                <div class="small text-white-50 text-uppercase fw-bold">Ecosystem Status</div>
                <div class="h4 fw-bold text-success mb-0"><i class="bi bi-circle-fill fs-6 me-1"></i>ONLINE</div>
                <div class="small text-white-50 mt-1"><?= date('d M Y, h:i A') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Primary Metric Counters (8 Cards) -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-lg-3">
        <div class="stat-card success p-3 text-center">
            <div class="h3 fw-bold text-success mb-0"><?= $totalEkycVerified ?></div>
            <div class="text-secondary small fw-semibold">eKYC Verified Users</div>
            <a href="<?= BASE_URL ?>admin/ekyc.php" class="stretched-link"></a>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg-3">
        <div class="stat-card primary p-3 text-center">
            <div class="h3 fw-bold text-primary mb-0"><?= $activeSsoSessions ?></div>
            <div class="text-secondary small fw-semibold">Active SSO Sessions</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg-3">
        <div class="stat-card purple p-3 text-center">
            <div class="h3 fw-bold mb-0" style="color: #6366f1;"><?= $totalEsignRecords ?></div>
            <div class="text-secondary small fw-semibold">eSign Artifacts</div>
            <a href="<?= BASE_URL ?>admin/consents.php" class="stretched-link"></a>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg-3">
        <div class="stat-card danger p-3 text-center">
            <div class="h3 fw-bold text-danger mb-0"><?= $totalRecords ?></div>
            <div class="text-secondary small fw-semibold">Health Records</div>
            <a href="<?= BASE_URL ?>admin/records.php" class="stretched-link"></a>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card primary p-3 text-center">
            <div class="h4 fw-bold text-primary mb-0"><?= $totalPatients ?></div>
            <div class="text-secondary small fw-semibold">Patients (ABHA)</div>
            <a href="<?= BASE_URL ?>admin/patients.php" class="stretched-link"></a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card purple p-3 text-center">
            <div class="h4 fw-bold text-purple mb-0" style="color: #6366f1;"><?= $totalDoctors ?></div>
            <div class="text-secondary small fw-semibold">Doctors (MCI)</div>
            <a href="<?= BASE_URL ?>admin/doctors.php" class="stretched-link"></a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card success p-3 text-center">
            <div class="h4 fw-bold text-success mb-0"><?= $totalHospitals ?></div>
            <div class="text-secondary small fw-semibold">Hospitals (NABH)</div>
            <a href="<?= BASE_URL ?>admin/hospitals.php" class="stretched-link"></a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card warning p-3 text-center">
            <div class="h4 fw-bold text-warning mb-0"><?= $totalConsents ?></div>
            <div class="text-secondary small fw-semibold">Total Consents</div>
            <a href="<?= BASE_URL ?>admin/consents.php" class="stretched-link"></a>
        </div>
    </div>
</div>

<!-- Consent Lifecycle Breakdown Bar -->
<div class="card card-custom p-4 mb-4 shadow-sm border-0">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0"><i class="bi bi-shield-shaded me-2 text-primary"></i>DEPA Electronic Consent & eSign Status Distribution</h5>
        <a href="<?= BASE_URL ?>admin/consents.php" class="small fw-semibold">Detailed Monitor</a>
    </div>
    <div class="row g-3 text-center">
        <div class="col">
            <div class="p-3 bg-success bg-opacity-10 rounded-3 border border-success border-opacity-25">
                <div class="h4 fw-bold text-success mb-0"><?= $approvedCount ?></div>
                <div class="small fw-semibold text-secondary">Active & eSigned</div>
            </div>
        </div>
        <div class="col">
            <div class="p-3 bg-warning bg-opacity-10 rounded-3 border border-warning border-opacity-25">
                <div class="h4 fw-bold text-warning-emphasis mb-0"><?= $pendingCount ?></div>
                <div class="small fw-semibold text-secondary">Pending eSign</div>
            </div>
        </div>
        <div class="col">
            <div class="p-3 bg-danger bg-opacity-10 rounded-3 border border-danger border-opacity-25">
                <div class="h4 fw-bold text-danger mb-0"><?= $revokedCount ?></div>
                <div class="small fw-semibold text-secondary">Revoked by Patient</div>
            </div>
        </div>
        <div class="col">
            <div class="p-3 bg-secondary bg-opacity-10 rounded-3 border border-secondary border-opacity-25">
                <div class="h4 fw-bold text-secondary mb-0"><?= $expiredCount ?></div>
                <div class="small fw-semibold text-secondary">Expired Validity</div>
            </div>
        </div>
        <div class="col">
            <div class="p-3 bg-dark bg-opacity-10 rounded-3 border border-dark border-opacity-25">
                <div class="h4 fw-bold text-dark mb-0"><?= $rejectedCount ?></div>
                <div class="small fw-semibold text-secondary">Rejected</div>
            </div>
        </div>
    </div>
</div>

<!-- 2-Column Split: Recent Consent Flows & Real-time Audit Stream -->
<div class="row g-4 mb-4">
    <!-- Recent Consents -->
    <div class="col-lg-6">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-shield-check text-primary me-2"></i>Recent Consent & eSign Flows</span>
                <a href="<?= BASE_URL ?>admin/consents.php" class="small fw-semibold">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>Handle / Patient</th>
                                <th>Requester</th>
                                <th>Status / eSign</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentConsents as $rc): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?= e($rc['patient_name']) ?></div>
                                        <span class="badge bg-light text-primary border font-monospace"><?= e($rc['consent_uid']) ?></span>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?= e($rc['requester_display_name']) ?></div>
                                        <span class="badge badge-<?= e($rc['requester_role']) ?> text-uppercase" style="font-size: 0.65rem;"><?= e($rc['requester_role']) ?></span>
                                    </td>
                                    <td>
                                        <?= render_status_badge($rc['status']) ?>
                                        <?php if (!empty($rc['esign_uid'])): ?>
                                            <div class="small text-success font-monospace" style="font-size: 0.68rem;">
                                                <i class="bi bi-pen me-1"></i><?= e($rc['esign_uid']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted"><?= format_date($rc['requested_date'], 'd M, H:i') ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Audit Stream -->
    <div class="col-lg-6">
        <div class="card card-custom h-100 shadow-sm border-0">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold"><i class="bi bi-journal-text text-warning me-2"></i>Real-time Audit Stream</span>
                <a href="<?= BASE_URL ?>admin/audit_logs.php" class="small fw-semibold">Full Logs</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-custom mb-0">
                        <thead>
                            <tr>
                                <th>User / Role</th>
                                <th>Action</th>
                                <th>Details</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark small"><?= e($log['user_name'] ?: 'System') ?></div>
                                        <span class="badge badge-<?= e($log['user_role'] ?: 'admin') ?>" style="font-size: 0.65rem;"><?= e($log['user_role'] ?: 'system') ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-primary border font-monospace" style="font-size: 0.72rem;"><?= e($log['action']) ?></span>
                                    </td>
                                    <td>
                                        <small class="text-secondary d-inline-block text-truncate" style="max-width: 170px;" title="<?= e($log['details']) ?>">
                                            <?= e($log['details']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= date('H:i:s', strtotime($log['created_at'])) ?></small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
