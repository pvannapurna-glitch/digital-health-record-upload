<?php
/**
 * Single Sign-On (SSO) Unified Login Gateway
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Single Sign-On (SSO) Portal";
require_once __DIR__ . '/config/config.php';

// Redirect if already authenticated
redirect_if_logged_in();

$error = '';
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = trim($_POST['role'] ?? '');

    if (empty($username) || empty($password)) {
        $error = "Please enter both username/email and password.";
    } else {
        // Query user by username or email
        $query = "SELECT * FROM `users` WHERE (`username` = ? OR `email` = ?)";
        $params = [$username, $username];

        if (!empty($role)) {
            $query .= " AND `role` = ?";
            $params[] = $role;
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if ($user['status'] !== 'active') {
                $error = "Your account has been deactivated by the system administrator.";
                log_audit($pdo, 'LOGIN_FAILED', "Attempted login to deactivated account: {$username}", $user['id'], $user['role'], $user['username']);
            } else {
                // Successful Login -> Initialize SSO Session
                $redirectUrl = complete_login_session($pdo, $user, 'password');
                $profileName = $_SESSION['user_name'] ?? $user['username'];

                set_flash('success', "SSO Authentication Successful. Welcome back, {$profileName}!");
                header("Location: " . $redirectUrl);
                exit;
            }
        } else {
            $error = "Invalid username or password. Please try again.";
            log_audit($pdo, 'LOGIN_FAILED', "Failed login attempt for username '{$username}'.");
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center my-4">
    <div class="col-md-8 col-lg-6 col-xl-5">
        <div class="card card-custom shadow border-0 overflow-hidden">
            <div class="card-header bg-primary text-white text-center py-4">
                <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-25 rounded-circle p-3 mb-2" style="width: 60px; height: 60px;">
                    <i class="bi bi-shield-lock-fill fs-2"></i>
                </div>
                <h4 class="fw-bold mb-1">DEPA Single Sign-On (SSO) Portal</h4>
                <p class="small text-white-50 mb-0">Unified Authentication Gateway for all Ecosystem Participants</p>
            </div>
            <div class="card-body p-4 p-md-5">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="needs-validation" novalidate autocomplete="on">
                    <div class="mb-3">
                        <label for="role" class="form-label fw-semibold">Select Role Portal <span class="text-danger">*</span></label>
                        <select class="form-select form-select-lg" id="role" name="role" required>
                            <option value="">-- Detect Automatically from Account --</option>
                            <option value="patient">Patient (Data Principal)</option>
                            <option value="doctor">Doctor (Medical Practitioner)</option>
                            <option value="hospital">Hospital / Healthcare Facility</option>
                            <option value="admin">System Administrator</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold">Username or Email Address <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-person"></i></span>
                            <input type="text" class="form-control form-control-lg" id="username" name="username" placeholder="e.g. rahul_sharma or dr.sharma" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="password" class="form-label fw-semibold mb-0">Password <span class="text-danger">*</span></label>
                            <a href="<?= BASE_URL ?>forgot_password.php" class="small text-primary text-decoration-none fw-semibold">
                                <i class="bi bi-question-circle me-1"></i>Forgot Password?
                            </a>
                        </div>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control form-control-lg" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Single Sign-On (SSO)
                    </button>
                </form>

                <!-- OR Google Sign-In Gateway -->
                <div class="my-4 d-flex align-items-center">
                    <hr class="flex-grow-1 border-secondary opacity-25 my-0">
                    <span class="px-3 text-muted small fw-semibold text-uppercase">OR</span>
                    <hr class="flex-grow-1 border-secondary opacity-25 my-0">
                </div>

                <a href="<?= BASE_URL ?>google_login.php" class="btn btn-outline-dark btn-lg w-100 fw-semibold d-flex align-items-center justify-content-center py-2 shadow-sm text-decoration-none" style="border-color: #dadce0; background-color: #ffffff; color: #3c4043; transition: all 0.2s ease-in-out;">
                    <svg class="me-2" width="20" height="20" viewBox="0 0 24 24" style="vertical-align: middle;">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                    </svg>
                    <span>Continue with Google</span>
                </a>

                <!-- Quick Demo Login Helpers -->
                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted fw-bold text-uppercase"><i class="bi bi-magic me-1"></i>One-Click Demo Credentials:</span>
                        <span class="badge bg-light text-secondary border font-monospace" style="font-size: 0.65rem;">Sandbox</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm demo-login-btn" data-username="rahul_sharma" data-password="Patient@123" data-role="patient">
                            <i class="bi bi-person-fill"></i> Patient
                        </button>
                        <button type="button" class="btn btn-outline-info btn-sm demo-login-btn text-dark" data-username="priya_patel" data-password="Patient@123" data-role="patient">
                            <i class="bi bi-person-fill"></i> Patient (Unverified eKYC)
                        </button>
                        <button type="button" class="btn btn-outline-purple btn-sm demo-login-btn" style="color: #6366f1; border-color: #6366f1;" data-username="dr.sharma" data-password="Doctor@123" data-role="doctor">
                            <i class="bi bi-heart-pulse-fill"></i> Doctor
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm demo-login-btn" data-username="apollo_hospital" data-password="Hospital@123" data-role="hospital">
                            <i class="bi bi-building"></i> Hospital
                        </button>
                        <button type="button" class="btn btn-outline-dark btn-sm demo-login-btn" data-username="pvannapurna4u@gmail.com" data-password="appu2720" data-role="admin">
                            <i class="bi bi-shield-lock-fill"></i> Admin (pvannapurna4u@gmail.com)
                        </button>
                    </div>
                </div>

                <div class="mt-4 text-center">
                    <p class="small text-muted mb-0">
                        Don't have an account? <a href="<?= BASE_URL ?>register.php" class="fw-semibold text-decoration-none">Register New Account</a>
                    </p>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
