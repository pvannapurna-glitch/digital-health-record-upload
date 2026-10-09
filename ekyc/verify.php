<?php
/**
 * eKYC Identity Verification Gateway (Sandbox / Demo)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "eKYC Identity Verification";
require_once __DIR__ . '/../config/config.php';

require_login();

$pdo = get_db_connection();
$userId = get_logged_user_id();
$userRole = get_user_role();

// Fetch current user demographics
$stmtU = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ?");
$stmtU->execute([$userId]);
$user = $stmtU->fetch();

$fullName = '';
$dob = '1995-01-01';
$gender = 'Male';
$currentUid = '';

if ($userRole === 'patient') {
    $stmtP = $pdo->prepare("SELECT * FROM `patients` WHERE `user_id` = ?");
    $stmtP->execute([$userId]);
    $p = $stmtP->fetch();
    if ($p) {
        $fullName = $p['full_name'];
        $dob = $p['dob'];
        $gender = $p['gender'];
        $currentUid = $p['patient_uid'];
    }
} elseif ($userRole === 'doctor') {
    $stmtD = $pdo->prepare("SELECT * FROM `doctors` WHERE `user_id` = ?");
    $stmtD->execute([$userId]);
    $d = $stmtD->fetch();
    if ($d) {
        $fullName = $d['full_name'];
        $currentUid = $d['doctor_uid'];
    }
} elseif ($userRole === 'hospital') {
    $stmtH = $pdo->prepare("SELECT * FROM `hospitals` WHERE `user_id` = ?");
    $stmtH->execute([$userId]);
    $h = $stmtH->fetch();
    if ($h) {
        $fullName = $h['hospital_name'];
        $currentUid = $h['hospital_uid'];
    }
}

$ekycStatus = $user['ekyc_status'] ?? 'Pending';
$ekycInfo = get_user_ekyc_info($pdo, $userId);

$error = '';
$success = '';

// Handle eKYC Verification Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token verification failed. Please refresh and try again.";
    } else {
        $action = $_POST['action'] ?? 'verify';

        if ($action === 'reset_pending') {
            // Reset to Pending for testing
            $pdo->prepare("UPDATE `users` SET `ekyc_status` = 'Pending' WHERE `id` = ?")->execute([$userId]);
            $_SESSION['ekyc_status'] = 'Pending';
            log_audit($pdo, 'EKYC_RESET', "User reset eKYC status to Pending for re-verification test.", $userId, $userRole);
            set_flash('info', "eKYC status reset to Pending. You can now re-test the verification flow.");
            header("Location: " . BASE_URL . "ekyc/verify.php");
            exit;
        }

        $docType = trim($_POST['doc_type'] ?? '');
        $docNumberRaw = trim($_POST['doc_number'] ?? '');
        $otp = trim($_POST['otp'] ?? '');

        if ($action === 'simulate_fail' || (!empty($otp) && $otp !== '123456' && $otp !== '999999')) {
            // Record Failed eKYC verification attempt in ledger
            $failUid = generate_depa_uid('KYC-FAIL', 'ekyc_verifications', 'ekyc_uid', $pdo);
            $cleaned = preg_replace('/\s+/', '', $docNumberRaw ?: '000000000000');
            $last4 = substr($cleaned, -4);
            $maskedNumber = 'XXXX-XXXX-' . ($last4 ?: '0000');

            $stmtFail = $pdo->prepare("INSERT INTO `ekyc_verifications` (`ekyc_uid`, `user_id`, `doc_type`, `doc_number_masked`, `full_name`, `dob`, `gender`, `otp_verified`, `status`, `verification_notes`, `created_at`, `verified_at`) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'Failed', 'OTP verification mismatch or sandbox rejection', NOW(), NULL)");
            $stmtFail->execute([$failUid, $userId, $docType ?: 'Aadhaar Sandbox Demo', $maskedNumber, $fullName ?: $user['username'], $dob, $gender]);

            $pdo->prepare("UPDATE `users` SET `ekyc_status` = 'Failed' WHERE `id` = ?")->execute([$userId]);
            $_SESSION['ekyc_status'] = 'Failed';

            log_audit($pdo, 'EKYC_FAILED', "Failed eKYC verification attempt for user {$user['username']} ({$failUid}).", $userId, $userRole);
            $error = "eKYC Verification FAILED: The provided OTP or identity documents could not be validated. Status marked as 'Failed'.";
            $ekycStatus = 'Failed';
        } else {
            // Mask document number (store only last 4 digits)
            $cleaned = preg_replace('/\s+/', '', $docNumberRaw ?: '123412341234');
            $last4 = substr($cleaned, -4);
            $maskedNumber = 'XXXX-XXXX-' . ($last4 ?: '1234');

            $res = complete_sandbox_ekyc($pdo, $userId, $docType ?: 'Aadhaar Sandbox Demo', $maskedNumber, $fullName ?: $user['username'], $dob, $gender, "Verified via Sandbox OTP (Code: {$otp})");

            if ($res['success']) {
                $_SESSION['ekyc_status'] = 'Verified';
                set_flash('success', "Identity VERIFIED successfully via Sandbox eKYC ({$res['ekyc_uid']})!");
                header("Location: " . BASE_URL . "ekyc/verify.php");
                exit;
            } else {
                $error = "eKYC verification could not be completed: " . ($res['error'] ?? 'Internal Error');
            }
        }
    }
}

// Fetch user eKYC history
$stmtHist = $pdo->prepare("SELECT * FROM `ekyc_verifications` WHERE `user_id` = ? ORDER BY `id` DESC LIMIT 5");
$stmtHist->execute([$userId]);
$ekycHistory = $stmtHist->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-8 col-xl-7">

        <!-- Sandbox Disclaimer Banner -->
        <div class="alert alert-warning border-warning d-flex align-items-center shadow-sm mb-4" role="alert">
            <i class="bi bi-info-circle-fill fs-3 text-warning-emphasis me-3"></i>
            <div>
                <strong>DEPA Sandbox eKYC Engine (Academic Simulation)</strong><br>
                <small class="text-secondary">
                    This is a safe demonstration eKYC workflow designed for academic and evaluation purposes. 
                    No real Aadhaar or government biometric data is queried or stored. All identification numbers are safely masked.
                </small>
            </div>
        </div>

        <!-- Aadhaar Card Document Upload Option Banner -->
        <div class="card border-primary border-start border-4 shadow-sm mb-4 bg-primary bg-opacity-10">
            <div class="card-body p-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <i class="bi bi-person-vcard fs-2 text-primary me-3"></i>
                    <div>
                        <strong class="text-dark">Aadhaar Card Document Upload (Recommended)</strong>
                        <div class="text-secondary small">Upload your Aadhaar Card as PDF, JPG, or PNG for automatic OCR document verification without typing an Aadhaar number.</div>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>patient/aadhaar_ekyc.php" class="btn btn-primary btn-sm px-3 fw-bold text-nowrap ms-3">
                    <i class="bi bi-upload me-1"></i> Upload Document
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center shadow-sm mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($ekycStatus === 'Verified'): ?>
            <!-- Verified Identity Certificate Card -->
            <div class="card card-custom border-0 shadow-sm overflow-hidden mb-4">
                <div class="card-header bg-success text-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-patch-check-fill me-2"></i>eKYC Verification Certificate</span>
                    <span class="badge bg-white text-success fw-bold px-3 py-1 rounded-pill">VERIFIED</span>
                </div>
                <div class="card-body p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-circle p-4 mb-2">
                            <i class="bi bi-shield-check" style="font-size: 3rem;"></i>
                        </div>
                        <h4 class="fw-bold text-success mb-1">Identity Verified Successfully</h4>
                        <p class="text-muted small">Your account is accredited for DEPA consent-based medical data exchange.</p>
                    </div>

                    <div class="bg-light p-4 rounded-4 border mb-4">
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.72rem;">eKYC Unique Handle</small>
                                <span class="fw-bold text-primary font-monospace fs-6"><?= e($ekycInfo['ekyc_uid'] ?? 'KYC-SANDBOX-VERIFIED') ?></span>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.72rem;">Verified Full Name</small>
                                <span class="fw-bold text-dark"><?= e($ekycInfo['full_name'] ?? $fullName) ?></span>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.72rem;">Identity Proof (Masked)</small>
                                <span class="badge bg-white text-dark border font-monospace"><?= e($ekycInfo['doc_type'] ?? 'Aadhaar Sandbox Demo') ?> (<?= e($ekycInfo['doc_number_masked'] ?? 'XXXX-XXXX-1001') ?>)</span>
                            </div>
                            <div class="col-sm-6">
                                <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.72rem;">Verification Timestamp</small>
                                <span class="small fw-semibold text-secondary"><?= format_date($ekycInfo['verified_at'] ?? $user['created_at']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a href="<?= BASE_URL ?><?= $userRole ?>/dashboard.php" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-speedometer2 me-1"></i> Return to Dashboard
                        </a>
                        <form method="POST" action="<?= BASE_URL ?>ekyc/verify.php" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="reset_pending">
                            <button type="submit" class="btn btn-outline-secondary btn-sm px-3" onclick="return confirm('Reset status to Pending to test verification flow again?')">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Test Re-verification (Reset to Pending)
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- eKYC Verification Form (Pending or Failed) -->
            <div class="card card-custom border-0 shadow-sm overflow-hidden mb-4">
                <div class="card-header <?= $ekycStatus === 'Failed' ? 'bg-danger' : 'bg-primary' ?> text-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">
                        <i class="bi bi-person-check me-2"></i><?= $ekycStatus === 'Failed' ? 'eKYC Verification Failed (Re-try)' : 'Complete Your eKYC Identity Verification' ?>
                    </h5>
                    <span class="badge bg-white <?= $ekycStatus === 'Failed' ? 'text-danger' : 'text-primary' ?> fw-bold"><?= strtoupper($ekycStatus) ?></span>
                </div>
                <div class="card-body p-4 p-md-5">

                    <?php if ($ekycStatus === 'Failed'): ?>
                        <div class="alert alert-danger mb-4">
                            <strong><i class="bi bi-x-octagon-fill me-1"></i> Verification Failed:</strong> Your last verification attempt was unsuccessful. Please check your details and re-try with the sandbox OTP <code>123456</code>.
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= BASE_URL ?>ekyc/verify.php">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="verify">

                        <div class="mb-3">
                            <label class="form-label fw-bold">Full Name (from Profile)</label>
                            <input type="text" class="form-control" value="<?= e($fullName ?: $user['username']) ?>" readonly style="background: #f8fafc;">
                        </div>

                        <div class="mb-3">
                            <label for="doc_type" class="form-label fw-bold">Select Identity Proof Type <span class="text-danger">*</span></label>
                            <select class="form-select" id="doc_type" name="doc_type" required>
                                <option value="Aadhaar Sandbox Demo">Aadhaar Card (Sandbox Demo)</option>
                                <option value="PAN Card Demo">PAN Card (Sandbox Demo)</option>
                                <option value="Medical License Verification">Medical Council / Institutional License</option>
                                <option value="Passport Demo">Passport (Demo)</option>
                                <option value="Voter ID Demo">Voter ID (Demo)</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="doc_number" class="form-label fw-bold">Identity Document Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-card-text"></i></span>
                                <input type="text" class="form-control font-monospace" id="doc_number" name="doc_number" placeholder="e.g. 5678 1234 9876 or ABCDE1234F" value="5678 1234 9876" required>
                            </div>
                            <small class="text-muted">Enter any dummy document number. Only the last 4 digits will be masked and saved.</small>
                        </div>

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label for="otp" class="form-label fw-bold mb-0">Simulated 6-Digit OTP <span class="text-danger">*</span></label>
                                <span class="badge bg-info-subtle text-info-emphasis border">Use Demo OTP: <strong>123456</strong></span>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-phone"></i></span>
                                <input type="text" class="form-control font-monospace text-center fs-5" id="otp" name="otp" value="123456" maxlength="6" required>
                            </div>
                            <small class="text-muted">OTP is pre-filled for convenience in evaluation.</small>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg fw-bold shadow-sm">
                                <i class="bi bi-patch-check me-2"></i> Verify Identity & Activate eKYC (OTP: 123456)
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- eKYC Verification History Ledger -->
        <?php if (!empty($ekycHistory)): ?>
            <div class="card card-custom border-0 shadow-sm overflow-hidden mb-4">
                <div class="card-header bg-white py-3">
                    <span class="fw-bold fs-6 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Your eKYC Verification History</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom mb-0">
                            <thead>
                                <tr>
                                    <th>Handle</th>
                                    <th>Proof</th>
                                    <th>Status</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ekycHistory as $h): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-primary border font-monospace"><?= e($h['ekyc_uid']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small fw-semibold"><?= e($h['doc_type']) ?></div>
                                            <small class="text-muted font-monospace"><?= e($h['doc_number_masked']) ?></small>
                                        </td>
                                        <td>
                                            <?php if ($h['status'] === 'Verified'): ?>
                                                <span class="badge bg-success text-white">Verified</span>
                                            <?php elseif ($h['status'] === 'Failed'): ?>
                                                <span class="badge bg-danger text-white">Failed</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted"><?= format_date($h['created_at']) ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
