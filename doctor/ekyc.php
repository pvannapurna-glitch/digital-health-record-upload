<?php
/**
 * Doctor eKYC Verification Portal (eMudhra IDBroker / Sandbox)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Doctor eKYC Verification";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

$doctorKyc = DoctorEkycService::getDoctorKycStatus($pdo, $userId);

$error = '';
$success = '';

// Handle eKYC Verification Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security CSRF token mismatch. Please try again.";
    } elseif ($_POST['action'] === 'initiate_and_verify') {
        // Initiate KYC
        DoctorEkycService::initiateKyc($pdo, $userId, 'National Medical Commission (NMC) eMudhra Registry');

        // Complete Verification
        $res = DoctorEkycService::verifyDoctorKyc($pdo, $userId, 'eMudhra Medical License Registry Service', 'Verified via National Medical Register Database match');

        if ($res['success']) {
            set_flash('success', "eMudhra Doctor eKYC completed successfully! Reference: {$res['reference']}");
            header("Location: " . BASE_URL . "doctor/ekyc.php");
            exit;
        } else {
            $error = "Verification failed: " . ($res['message'] ?? 'Unknown error');
        }
    }
}

// Refresh status
$doctorKyc = DoctorEkycService::getDoctorKycStatus($pdo, $userId);

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-8">

        <!-- Breadcrumb Navigation -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>doctor/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Doctor eKYC Verification</li>
            </ol>
        </nav>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center shadow-sm mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-3 fs-4 text-danger"></i>
                <div>
                    <strong>Verification Notice:</strong>
                    <div><?= e($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Doctor KYC Card -->
        <div class="card card-custom border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold fs-5 text-dark">
                    <i class="bi bi-shield-check text-primary me-2"></i>Medical Practitioner Identity & eKYC (eMudhra)
                </span>
                <span class="badge bg-<?= $doctorKyc['is_verified'] ? 'success' : 'warning text-dark' ?> px-3 py-2">
                    <i class="bi <?= $doctorKyc['is_verified'] ? 'bi-patch-check-fill' : 'bi-hourglass-split' ?> me-1"></i>
                    Status: <?= e($doctorKyc['status']) ?>
                </span>
            </div>
            <div class="card-body p-4">

                <!-- Notice banner -->
                <div class="alert alert-info border-info d-flex align-items-center shadow-sm mb-4">
                    <i class="bi bi-info-circle-fill fs-3 text-info me-3"></i>
                    <div>
                        <strong>Academic eKYC Sandbox Environment:</strong>
                        <span class="small d-block text-secondary">
                            Demonstrating eMudhra IDBroker medical practitioner identity verification. Validates license numbers against the National Medical Registry without storing sensitive authentication secrets.
                        </span>
                    </div>
                </div>

                <div class="p-3 bg-light rounded-3 border mb-4">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <span class="text-muted small d-block">Practitioner Full Name</span>
                            <strong class="text-dark fs-6"><?= e($doctorKyc['full_name']) ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block">Doctor DEPA Identifier</span>
                            <span class="font-monospace fw-bold text-primary"><?= e($doctorKyc['doctor_uid']) ?></span>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block">Medical Council License Number</span>
                            <span class="font-monospace fw-bold text-dark"><?= e($doctorKyc['license_number']) ?></span>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block">eKYC Audit Reference</span>
                            <span class="font-monospace text-secondary small"><?= e($doctorKyc['kyc_reference'] ?: 'Not Assigned') ?></span>
                        </div>
                        <?php if ($doctorKyc['verified_at']): ?>
                            <div class="col-12 border-top pt-2 mt-2">
                                <span class="text-muted small me-2">Verified At:</span>
                                <span class="badge bg-success-subtle text-success border font-monospace"><?= format_date($doctorKyc['verified_at']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($doctorKyc['is_verified']): ?>
                    <div class="p-3 bg-success-subtle rounded-3 border border-success-subtle text-center mb-4">
                        <i class="bi bi-patch-check-fill text-success fs-1 mb-2 d-block"></i>
                        <h5 class="fw-bold text-success mb-1">eKYC Identity Verified ✓</h5>
                        <p class="text-secondary small mb-0">Your medical identity is authenticated. You are fully authorized to initiate emSigner digital signatures on patient consent requests.</p>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="<?= BASE_URL ?>doctor/dashboard.php" class="btn btn-outline-secondary btn-sm px-3">
                            <i class="bi bi-arrow-left me-1"></i> Dashboard
                        </a>
                        <a href="<?= BASE_URL ?>doctor/my_requests.php" class="btn btn-primary btn-sm px-3 fw-semibold">
                            <i class="bi bi-file-earmark-check me-1"></i> View Consents & Sign with emSigner
                        </a>
                    </div>
                <?php else: ?>
                    <div class="p-3 bg-warning-subtle rounded-3 border border-warning-subtle text-center mb-4">
                        <i class="bi bi-shield-exclamation text-warning-emphasis fs-1 mb-2 d-block"></i>
                        <h5 class="fw-bold text-warning-emphasis mb-1">eKYC Verification Required</h5>
                        <p class="text-secondary small mb-3">Under DEPA regulations, doctors must complete identity verification before electronically signing health data access agreements.</p>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="initiate_and_verify">
                            <button type="submit" class="btn btn-success fw-bold px-4 shadow-sm">
                                <i class="bi bi-check2-circle me-1"></i> Verify Doctor Identity with eMudhra
                            </button>
                        </form>
                    </div>

                    <div class="text-start">
                        <a href="<?= BASE_URL ?>doctor/dashboard.php" class="btn btn-outline-secondary btn-sm px-3">
                            <i class="bi bi-arrow-left me-1"></i> Return to Dashboard
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
