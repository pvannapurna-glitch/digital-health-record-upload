<?php
/**
 * Forgot Password - Secure Email OTP Verification & Password Reset
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Reset Password (Email OTP)";
require_once __DIR__ . '/config/config.php';

// Redirect if already logged in
redirect_if_logged_in();

$pdo = get_db_connection();

$error = '';
$success = '';
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Session keys for wizard progression
$sessionEmail = $_SESSION['reset_email'] ?? '';
$sessionToken = $_SESSION['reset_token'] ?? '';

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    // -------------------------------------------------------------
    // ACTION 1: REQUEST / SEND OTP
    // -------------------------------------------------------------
    if ($action === 'request_otp') {
        $identity = trim($_POST['identity'] ?? $_POST['email'] ?? '');
        $res = request_password_reset_otp($pdo, $identity);

        if ($res['success']) {
            $_SESSION['reset_email'] = $res['email'];
            set_flash('success', "A secure 6-digit OTP has been sent to the registered email address. Valid for 10 minutes.");
            header("Location: " . BASE_URL . "forgot_password.php?step=2");
            exit;
        } else {
            $error = $res['error'];
        }
    }

    // -------------------------------------------------------------
    // ACTION 2: RESEND OTP (WITH COOLDOWN)
    // -------------------------------------------------------------
    elseif ($action === 'resend_otp') {
        $email = $sessionEmail ?: trim($_POST['identity'] ?? $_POST['email'] ?? '');
        $res = request_password_reset_otp($pdo, $email);

        if ($res['success']) {
            $_SESSION['reset_email'] = $res['email'];
            set_flash('info', "A fresh 6-digit OTP has been sent to your registered email address.");
            header("Location: " . BASE_URL . "forgot_password.php?step=2");
            exit;
        } else {
            $error = $res['error'];
        }
    }

    // -------------------------------------------------------------
    // ACTION 3: VERIFY OTP
    // -------------------------------------------------------------
    elseif ($action === 'verify_otp') {
        $email = $sessionEmail ?: trim($_POST['email'] ?? '');
        $enteredOtp = trim($_POST['otp'] ?? '');

        $res = verify_password_reset_otp($pdo, $email, $enteredOtp);

        if ($res['success']) {
            $_SESSION['reset_token'] = $res['reset_token'];
            set_flash('success', "OTP verified successfully! Please choose your new password.");
            header("Location: " . BASE_URL . "forgot_password.php?step=3");
            exit;
        } else {
            $error = $res['error'];
        }
    }

    // -------------------------------------------------------------
    // ACTION 4: COMPLETE PASSWORD RESET
    // -------------------------------------------------------------
    elseif ($action === 'reset_password') {
        $token = $sessionToken ?: trim($_POST['reset_token'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $res = complete_password_reset($pdo, $token, $newPassword, $confirmPassword);

        if ($res['success']) {
            // Clean up reset session
            unset($_SESSION['reset_email']);
            unset($_SESSION['reset_token']);

            set_flash('success', "<strong>Password Reset Successful!</strong> You can now sign in with your new password.");
            header("Location: " . BASE_URL . "login.php");
            exit;
        } else {
            $error = $res['error'];
        }
    }
}

// Retrieve active state for Step 2
$otpState = null;
if ($step === 2 && !empty($sessionEmail)) {
    $otpState = get_active_otp_state($pdo, $sessionEmail);
    if (!$otpState) {
        set_flash('warning', 'Your OTP session has expired or is invalid. Please request a new OTP.');
        header("Location: " . BASE_URL . "forgot_password.php?step=1");
        exit;
    }
}

// Security guard for Step 3
if ($step === 3 && empty($sessionToken)) {
    set_flash('warning', 'Please verify your OTP before attempting to set a new password.');
    header("Location: " . BASE_URL . "forgot_password.php?step=1");
    exit;
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center my-4">
    <div class="col-md-8 col-lg-6 col-xl-5">

        <!-- Multi-Step Progress Tracker -->
        <div class="d-flex justify-content-between align-items-center mb-4 px-3">
            <div class="text-center flex-fill position-relative">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold <?= $step >= 1 ? 'bg-primary text-white shadow' : 'bg-light text-muted border' ?>" style="width: 38px; height: 38px; z-index: 2; position: relative;">
                    1
                </div>
                <div class="small fw-semibold mt-1 <?= $step >= 1 ? 'text-primary' : 'text-muted' ?>">Account</div>
            </div>
            <div class="flex-fill border-top border-2 <?= $step >= 2 ? 'border-primary' : 'border-light' ?>" style="margin-top: -18px;"></div>
            <div class="text-center flex-fill position-relative">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold <?= $step >= 2 ? 'bg-primary text-white shadow' : 'bg-light text-muted border' ?>" style="width: 38px; height: 38px; z-index: 2; position: relative;">
                    2
                </div>
                <div class="small fw-semibold mt-1 <?= $step >= 2 ? 'text-primary' : 'text-muted' ?>">Verify OTP</div>
            </div>
            <div class="flex-fill border-top border-2 <?= $step >= 3 ? 'border-primary' : 'border-light' ?>" style="margin-top: -18px;"></div>
            <div class="text-center flex-fill position-relative">
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold <?= $step >= 3 ? 'bg-primary text-white shadow' : 'bg-light text-muted border' ?>" style="width: 38px; height: 38px; z-index: 2; position: relative;">
                    3
                </div>
                <div class="small fw-semibold mt-1 <?= $step >= 3 ? 'text-primary' : 'text-muted' ?>">New Password</div>
            </div>
        </div>

        <div class="card card-custom shadow-lg border-0 overflow-hidden">
            
            <!-- Card Header -->
            <div class="card-header bg-gradient bg-primary text-white text-center py-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-25 rounded-circle p-3 mb-2" style="width: 58px; height: 58px;">
                    <?php if ($step === 1): ?>
                        <i class="bi bi-envelope-at-fill fs-2"></i>
                    <?php elseif ($step === 2): ?>
                        <i class="bi bi-shield-lock-fill fs-2"></i>
                    <?php else: ?>
                        <i class="bi bi-key-fill fs-2"></i>
                    <?php endif; ?>
                </div>
                <h4 class="fw-bold mb-1">
                    <?php if ($step === 1): ?>
                        Forgot Password
                    <?php elseif ($step === 2): ?>
                        Email OTP Verification
                    <?php else: ?>
                        Create New Password
                    <?php endif; ?>
                </h4>
                <p class="small text-white-50 mb-0">
                    <?php if ($step === 1): ?>
                        Enter your registered email address or username to receive a secure 6-digit OTP
                    <?php elseif ($step === 2): ?>
                        Enter the 6-digit OTP code sent to your registered email address
                    <?php else: ?>
                        Choose a strong, memorable password for your account
                    <?php endif; ?>
                </p>
            </div>

            <div class="card-body p-4 p-md-5">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5 flex-shrink-0"></i>
                        <div><?= $error ?></div>
                    </div>
                <?php endif; ?>

                <!-- ======================================================= -->
                <!-- STEP 1: ENTER REGISTERED EMAIL OR USERNAME               -->
                <!-- ======================================================= -->
                <?php if ($step === 1): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="request_otp">

                        <div class="mb-4">
                            <label for="identity" class="form-label fw-semibold">Registered Email or Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-person-badge"></i></span>
                                <input type="text" class="form-control form-control-lg" id="identity" name="identity" placeholder="e.g. rahul.s92@gmail.com, admin@example.com, or username" value="<?= e($sessionEmail) ?>" required autofocus autocomplete="username">
                            </div>
                            <div class="form-text text-muted small mt-1">
                                <i class="bi bi-shield-lock me-1"></i> A secure 6-digit verification OTP will be sent to the registered email address.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm mb-3">
                            <i class="bi bi-send-fill me-2"></i> Send 6-Digit OTP
                        </button>

                        <div class="text-center">
                            <a href="<?= BASE_URL ?>login.php" class="text-decoration-none small text-secondary">
                                <i class="bi bi-arrow-left me-1"></i> Back to Single Sign-On (SSO) Login
                            </a>
                        </div>
                    </form>

                <!-- ======================================================= -->
                <!-- STEP 2: VERIFY 6-DIGIT OTP                               -->
                <!-- ======================================================= -->
                <?php elseif ($step === 2): ?>
                    <div class="text-center mb-4">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 fs-6">
                            <i class="bi bi-envelope-check me-1"></i> OTP sent to: <strong><?= e($sessionEmail) ?></strong>
                        </span>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="verify_otp">
                        <input type="hidden" name="email" value="<?= e($sessionEmail) ?>">

                        <div class="mb-4">
                            <label for="otp" class="form-label fw-semibold d-flex justify-content-between align-items-center">
                                <span>Enter 6-Digit Email OTP <span class="text-danger">*</span></span>
                                <span class="badge bg-danger-subtle text-danger font-monospace" id="countdownTimer">
                                    <i class="bi bi-clock-history me-1"></i> <span id="timeRemaining">10:00</span>
                                </span>
                            </label>
                            
                            <input type="text" class="form-control form-control-lg text-center font-monospace fw-bold fs-2 letter-spacing-lg" 
                                   id="otp" name="otp" maxlength="6" pattern="\d{6}" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;" 
                                   style="letter-spacing: 0.5rem;" required autofocus autocomplete="one-time-code">
                            
                            <div class="d-flex justify-content-between align-items-center mt-2 small">
                                <span class="text-muted">
                                    <i class="bi bi-shield-check me-1"></i> 
                                    Attempts: <strong class="<?= ($otpState['remaining_attempts'] ?? 5) <= 2 ? 'text-danger' : 'text-dark' ?>"><?= e($otpState['remaining_attempts'] ?? 5) ?> of <?= e($otpState['max_attempts'] ?? 5) ?> remaining</strong>
                                </span>
                                <span class="text-muted">Expires in 10 minutes</span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm mb-3">
                            <i class="bi bi-check2-circle me-2"></i> Verify OTP Code
                        </button>
                    </form>

                    <!-- Resend OTP Form with Cooldown -->
                    <form method="POST" action="" class="text-center mt-3 pt-3 border-top">
                        <input type="hidden" name="action" value="resend_otp">
                        <input type="hidden" name="email" value="<?= e($sessionEmail) ?>">

                        <div class="d-flex justify-content-between align-items-center">
                            <a href="<?= BASE_URL ?>forgot_password.php?step=1" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-pencil-square me-1"></i> Change Account
                            </a>

                            <button type="submit" id="resendBtn" class="btn btn-outline-primary btn-sm fw-semibold" <?= ($otpState['cooldown_in'] ?? 0) > 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-arrow-repeat me-1"></i> 
                                <span id="resendBtnText">
                                    <?= ($otpState['cooldown_in'] ?? 0) > 0 ? "Resend in {$otpState['cooldown_in']}s" : "Resend OTP" ?>
                                </span>
                            </button>
                        </div>
                    </form>

                <!-- ======================================================= -->
                <!-- STEP 3: CREATE NEW PASSWORD                              -->
                <!-- ======================================================= -->
                <?php elseif ($step === 3): ?>
                    <form method="POST" action="" id="resetPasswordForm">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="reset_token" value="<?= e($sessionToken) ?>">

                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control form-control-lg" id="new_password" name="new_password" minlength="8" placeholder="At least 8 characters" required autofocus>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="progress mt-2" style="height: 4px;">
                                <div id="passwordStrengthBar" class="progress-bar bg-danger" role="progressbar" style="width: 0%;"></div>
                            </div>
                            <small id="passwordStrengthText" class="text-muted d-block mt-1" style="font-size: 0.75rem;">Password strength</small>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-shield-check"></i></span>
                                <input type="password" class="form-control form-control-lg" id="confirm_password" name="confirm_password" minlength="8" placeholder="Re-enter new password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <small id="passwordMatchText" class="text-danger d-none mt-1" style="font-size: 0.75rem;"><i class="bi bi-x-circle me-1"></i> Passwords do not match</small>
                        </div>

                        <button type="submit" id="savePasswordBtn" class="btn btn-success btn-lg w-100 fw-bold shadow-sm mb-3">
                            <i class="bi bi-check-lg me-2"></i> Update Password & Sign In
                        </button>
                    </form>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<!-- Dynamic Client-Side Countdown & Interactive Helpers -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 1. Countdown Timer & Resend Cooldown (Step 2)
    <?php if ($step === 2 && $otpState): ?>
    let expirySeconds = <?= (int)($otpState['expires_in'] ?? 600) ?>;
    let cooldownSeconds = <?= (int)($otpState['cooldown_in'] ?? 0) ?>;
    const timeRemainingEl = document.getElementById('timeRemaining');
    const resendBtn = document.getElementById('resendBtn');
    const resendBtnText = document.getElementById('resendBtnText');

    function formatTime(sec) {
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
    }

    if (timeRemainingEl) {
        timeRemainingEl.textContent = formatTime(expirySeconds);
    }

    const timerInterval = setInterval(function() {
        if (expirySeconds > 0) {
            expirySeconds--;
            if (timeRemainingEl) {
                timeRemainingEl.textContent = formatTime(expirySeconds);
            }
        } else {
            if (timeRemainingEl) {
                timeRemainingEl.textContent = 'EXPIRED';
                timeRemainingEl.parentElement.classList.replace('bg-danger-subtle', 'bg-dark');
                timeRemainingEl.parentElement.classList.replace('text-danger', 'text-white');
            }
            clearInterval(timerInterval);
        }

        if (cooldownSeconds > 0) {
            cooldownSeconds--;
            if (resendBtnText) {
                resendBtnText.textContent = `Resend in ${cooldownSeconds}s`;
            }
        } else {
            if (resendBtn) {
                resendBtn.removeAttribute('disabled');
                if (resendBtnText) {
                    resendBtnText.textContent = 'Resend OTP';
                }
            }
        }
    }, 1000);
    <?php endif; ?>

    // 2. Password Visibility Toggle (Step 3)
    const toggleButtons = document.querySelectorAll('.toggle-password');
    toggleButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetInput = document.getElementById(targetId);
            const icon = this.querySelector('i');
            if (targetInput && icon) {
                if (targetInput.type === 'password') {
                    targetInput.type = 'text';
                    icon.classList.replace('bi-eye', 'bi-eye-slash');
                } else {
                    targetInput.type = 'password';
                    icon.classList.replace('bi-eye-slash', 'bi-eye');
                }
            }
        });
    });

    // 3. Password Strength & Match Validation (Step 3)
    const newPass = document.getElementById('new_password');
    const confPass = document.getElementById('confirm_password');
    const strengthBar = document.getElementById('passwordStrengthBar');
    const strengthText = document.getElementById('passwordStrengthText');
    const matchText = document.getElementById('passwordMatchText');

    if (newPass) {
        newPass.addEventListener('input', function() {
            const val = this.value;
            let score = 0;
            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            if (score <= 1) {
                if (strengthBar) {
                    strengthBar.style.width = '25%';
                    strengthBar.className = 'progress-bar bg-danger';
                }
                if (strengthText) strengthText.textContent = 'Weak password (Use letters, numbers & symbols)';
            } else if (score === 2 || score === 3) {
                if (strengthBar) {
                    strengthBar.style.width = '70%';
                    strengthBar.className = 'progress-bar bg-warning';
                }
                if (strengthText) strengthText.textContent = 'Moderate password strength';
            } else {
                if (strengthBar) {
                    strengthBar.style.width = '100%';
                    strengthBar.className = 'progress-bar bg-success';
                }
                if (strengthText) strengthText.textContent = 'Strong password!';
            }
        });
    }

    if (confPass && newPass) {
        confPass.addEventListener('input', function() {
            if (this.value && this.value !== newPass.value) {
                if (matchText) matchText.classList.remove('d-none');
            } else {
                if (matchText) matchText.classList.add('d-none');
            }
        });
    }
});
</script>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
