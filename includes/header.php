<?php
/**
 * Global Header & Navigation (with SSO & eKYC integration)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../config/config.php';
}

$isLoggedIn = is_logged_in();
$userRole   = get_user_role();
$userName   = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
$userId     = get_logged_user_id();
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
$ssoToken   = $_SESSION['sso_token'] ?? '';

// Check eKYC status
$isEkycVerified = false;
if ($isLoggedIn) {
    $pdo = get_db_connection();
    $isEkycVerified = is_user_ekyc_verified($pdo, $userId);
}

// If logged in as patient, get pending consent count for notification badge
$pendingConsentCount = 0;
if ($isLoggedIn && $userRole === 'patient') {
    $patientId = get_patient_profile_id($pdo);
    if ($patientId) {
        $stmtBadge = $pdo->prepare("SELECT COUNT(*) FROM `consent_requests` WHERE `patient_id` = ? AND `status` = 'Pending'");
        $stmtBadge->execute([$patientId]);
        $pendingConsentCount = (int)$stmtBadge->fetchColumn();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= e(APP_NAME) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Custom Theme CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top py-2 shadow-sm">
    <div class="container-fluid px-lg-4">
        <a class="navbar-brand d-flex align-items-center" href="<?= BASE_URL ?>">
            <div class="bg-primary text-white rounded-3 p-2 me-2 d-inline-flex align-items-center justify-content-center shadow-sm" style="width: 38px; height: 38px;">
                <i class="bi bi-shield-lock-fill fs-5"></i>
            </div>
            <div>
                <div class="fw-bold fs-5 lh-1"><?= e(APP_SHORT_NAME) ?></div>
                <small class="text-info-emphasis fs-8 opacity-75" style="font-size: 0.72rem;">DEPA Consent, eKYC & eSign Exchange</small>
            </div>
        </a>
        
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ps-lg-3">
                <?php if (!$isLoggedIn): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'index.php') !== false || $currentUri === '/' ? 'active' : '' ?>" href="<?= BASE_URL ?>">
                            <i class="bi bi-house-door me-1"></i> Home
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'depa_explainer.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>depa_explainer.php">
                            <i class="bi bi-diagram-3 me-1"></i> DEPA Architecture
                        </a>
                    </li>
                <?php elseif ($userRole === 'patient'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'patient/dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>patient/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'patient/my_records.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>patient/my_records.php">
                            <i class="bi bi-folder2-open me-1"></i> My Health Records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'patient/add_record.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>patient/add_record.php">
                            <i class="bi bi-plus-circle me-1"></i> Add Record
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link position-relative <?= strpos($currentUri, 'patient/consent_requests.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>patient/consent_requests.php">
                            <i class="bi bi-shield-check me-1"></i> Consent Requests
                            <?php if ($pendingConsentCount > 0): ?>
                                <span class="badge rounded-pill bg-danger ms-1"><?= $pendingConsentCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'patient/sharing_history.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>patient/sharing_history.php">
                            <i class="bi bi-clock-history me-1"></i> Sharing History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'esign/status.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>esign/status.php">
                            <i class="bi bi-patch-check me-1"></i> eSign Monitor
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= (strpos($currentUri, 'verify_aadhaar.php') !== false || strpos($currentUri, 'aadhaar_ekyc.php') !== false) ? 'active' : '' ?>" href="<?= BASE_URL ?>ekyc/verify_aadhaar.php">
                            <i class="bi bi-shield-check me-1"></i> Verify Aadhaar
                        </a>
                    </li>
                <?php elseif ($userRole === 'doctor'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'doctor/dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>doctor/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'doctor/search_patients.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>doctor/search_patients.php">
                            <i class="bi bi-search me-1"></i> Search Patients
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'doctor/request_consent.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>doctor/request_consent.php">
                            <i class="bi bi-shield-plus me-1"></i> Request Consent
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'doctor/my_requests.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>doctor/my_requests.php">
                            <i class="bi bi-list-check me-1"></i> Consent Tracker
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'esign/status.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>esign/status.php">
                            <i class="bi bi-patch-check me-1"></i> eSign Status
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'doctor/authorized_records.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>doctor/authorized_records.php">
                            <i class="bi bi-file-earmark-medical me-1"></i> Authorized Records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'doctor/history.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>doctor/history.php">
                            <i class="bi bi-clock-history me-1"></i> Access History
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'verify_aadhaar.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>ekyc/verify_aadhaar.php">
                            <i class="bi bi-shield-check me-1"></i> Verify Aadhaar
                        </a>
                    </li>
                <?php elseif ($userRole === 'hospital'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'hospital/dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>hospital/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'hospital/search_patients.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>hospital/search_patients.php">
                            <i class="bi bi-search me-1"></i> Search Registry
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'hospital/request_consent.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>hospital/request_consent.php">
                            <i class="bi bi-shield-plus me-1"></i> Request Consent
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'hospital/my_requests.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>hospital/my_requests.php">
                            <i class="bi bi-list-check me-1"></i> Request Status
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'esign/status.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>esign/status.php">
                            <i class="bi bi-patch-check me-1"></i> eSign Status
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'hospital/authorized_records.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>hospital/authorized_records.php">
                            <i class="bi bi-hospital me-1"></i> Authorized Records
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'hospital/history.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>hospital/history.php">
                            <i class="bi bi-clock-history me-1"></i> Access History
                        </a>
                    </li>
                <?php elseif ($userRole === 'admin'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/dashboard.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/ekyc.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/ekyc.php">
                            <i class="bi bi-patch-check me-1"></i> eKYC Directory
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/patients.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/patients.php">
                            <i class="bi bi-people me-1"></i> Patients
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/doctors.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/doctors.php">
                            <i class="bi bi-person-badge me-1"></i> Doctors
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/hospitals.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/hospitals.php">
                            <i class="bi bi-hospital me-1"></i> Hospitals
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/consents.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/consents.php">
                            <i class="bi bi-shield-check me-1"></i> Consents
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'esign/status.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>esign/status.php">
                            <i class="bi bi-patch-check me-1"></i> eSign Monitor
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/registry.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/registry.php">
                            <i class="bi bi-card-checklist me-1"></i> Registry
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= strpos($currentUri, 'admin/audit_logs.php') !== false ? 'active' : '' ?>" href="<?= BASE_URL ?>admin/audit_logs.php">
                            <i class="bi bi-journal-text me-1"></i> Audit Logs
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center">
                <?php if (!$isLoggedIn): ?>
                    <li class="nav-item me-2">
                        <a class="btn btn-sm btn-outline-light px-3" href="<?= BASE_URL ?>login.php">
                            <i class="bi bi-box-arrow-in-right me-1"></i> SSO Sign In
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-sm btn-primary px-3 shadow-sm" href="<?= BASE_URL ?>register.php">
                            <i class="bi bi-person-plus me-1"></i> Register
                        </a>
                    </li>
                <?php else: ?>
                    <!-- eKYC Status Pill -->
                    <li class="nav-item me-2 d-none d-md-block">
                        <?php if ($isEkycVerified): ?>
                            <a href="<?= BASE_URL ?>ekyc/verify.php" class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-1 text-decoration-none" title="eKYC Verified Identity">
                                <i class="bi bi-patch-check-fill me-1"></i>eKYC Verified
                            </a>
                        <?php else: ?>
                            <a href="<?= BASE_URL ?>ekyc/verify.php" class="badge bg-warning bg-opacity-25 text-warning-emphasis border border-warning px-2 py-1 text-decoration-none" title="Click to Complete eKYC">
                                <i class="bi bi-exclamation-circle-fill me-1"></i>eKYC: Pending Verify
                            </a>
                        <?php endif; ?>
                    </li>

                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center bg-white bg-opacity-10 rounded-pill px-3 py-1 text-white" href="#" id="userMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="badge badge-<?= e($userRole) ?> text-uppercase me-2" style="font-size: 0.7rem;"><?= e($userRole) ?></span>
                            <span class="fw-semibold text-truncate" style="max-width: 140px;"><?= e($userName) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" aria-labelledby="userMenu">
                            <li class="dropdown-header">
                                <div class="fw-bold"><?= e($userName) ?></div>
                                <small class="text-muted text-capitalize"><?= e($userRole) ?> Portal</small>
                                <div class="mt-1">
                                    <span class="badge bg-light text-primary border font-monospace" style="font-size: 0.65rem;">
                                        <i class="bi bi-key-fill me-1"></i>SSO Active
                                    </span>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= BASE_URL ?>ekyc/verify.php">
                                    <i class="bi bi-patch-check me-2 text-primary"></i>eKYC Verification Status
                                </a>
                            </li>
                            <?php if ($userRole === 'patient'): ?>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>ekyc/verify_aadhaar.php"><i class="bi bi-shield-check me-2 text-success"></i>Verify Aadhaar</a></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>patient/profile.php"><i class="bi bi-person me-2"></i>My Profile & ABHA</a></li>
                            <?php elseif ($userRole === 'doctor'): ?>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>ekyc/verify_aadhaar.php"><i class="bi bi-shield-check me-2 text-success"></i>Verify Aadhaar</a></li>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>doctor/profile.php"><i class="bi bi-person me-2"></i>Doctor Profile</a></li>
                            <?php elseif ($userRole === 'hospital'): ?>
                                <li><a class="dropdown-item" href="<?= BASE_URL ?>hospital/profile.php"><i class="bi bi-building me-2"></i>Hospital Profile</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign Out (SSO)</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="py-4">
    <div class="container-fluid px-lg-4">
        <?= render_flash() ?>

        <?php if ($isLoggedIn && !$isEkycVerified && $userRole !== 'admin' && strpos($_SERVER['SCRIPT_NAME'] ?? '', 'verify.php') === false && strpos($_SERVER['SCRIPT_NAME'] ?? '', 'verify_aadhaar.php') === false && strpos($_SERVER['SCRIPT_NAME'] ?? '', 'aadhaar_ekyc.php') === false): ?>
            <div class="alert alert-warning border-warning d-flex align-items-center justify-content-between shadow-sm mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="bi bi-shield-exclamation fs-3 text-warning-emphasis me-3"></i>
                    <div>
                        <strong>Aadhaar Verification:</strong> Your account is currently in <em>Pending</em> verification state. Complete offline XML Aadhaar document verification to enable full data exchange privileges.
                    </div>
                </div>
                <a href="<?= BASE_URL ?>ekyc/verify_aadhaar.php" class="btn btn-sm btn-warning fw-bold px-3 text-dark ms-3 text-nowrap">
                    <i class="bi bi-shield-check me-1"></i> Verify Aadhaar
                </a>
            </div>
        <?php endif; ?>
