<?php
/**
 * Dedicated Administrator Login
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Administrator Sign In";
require_once __DIR__ . '/../config/config.php';

if (is_logged_in() && get_user_role() === 'admin') {
    header("Location: " . BASE_URL . "admin/dashboard.php");
    exit;
}

$error = '';
$pdo = get_db_connection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter admin username/email and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `users` WHERE (`username` = ? OR `email` = ?) AND `role` = 'admin'");
        $stmt->execute([$username, $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            if ($admin['status'] !== 'active') {
                $error = "Administrator account is currently deactivated.";
            } else {
                $_SESSION['user_id']    = (int)$admin['id'];
                $_SESSION['username']   = $admin['username'];
                $_SESSION['user_email'] = $admin['email'];
                $_SESSION['user_role']  = 'admin';
                $_SESSION['user_name']  = 'System Administrator';

                // Create SSO active session tracking
                $ssoToken = create_sso_session($pdo, $admin['id'], 'admin');
                $_SESSION['sso_token'] = $ssoToken;
                $_SESSION['ekyc_status'] = $admin['ekyc_status'] ?? 'Verified';

                log_audit($pdo, 'ADMIN_LOGIN', "Administrator logged into control console ({$admin['email']}).", $admin['id'], 'admin', 'System Administrator');

                set_flash('success', "Welcome to the DEPA Administration Console.");
                header("Location: " . BASE_URL . "admin/dashboard.php");
                exit;
            }
        } else {
            $error = "Invalid administrator credentials.";
            log_audit($pdo, 'ADMIN_LOGIN_FAILED', "Failed admin login attempt with identifier: {$username}");
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center py-4">
    <div class="col-md-7 col-lg-5">
        <div class="card card-custom shadow-lg border-0">
            <div class="card-header bg-dark text-white text-center py-4 rounded-top-3 border-bottom border-secondary">
                <div class="d-inline-flex p-3 rounded-circle bg-purple bg-opacity-25 text-white mb-2" style="background-color: rgba(124, 58, 237, 0.25);">
                    <i class="bi bi-shield-lock-fill fs-2 text-warning"></i>
                </div>
                <h4 class="fw-bold mb-1">DEPA Master Admin Console</h4>
                <p class="small text-white-50 mb-0">Registry Administration & Ecosystem Governance</p>
            </div>
            
            <div class="card-body p-4 p-md-5">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" autocomplete="on">
                    <div class="mb-3">
                        <label for="username" class="form-label fw-semibold">Admin Username or Email</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-shield-person"></i></span>
                            <input type="text" name="username" id="username" class="form-control" placeholder="Enter admin username or email" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold">Master Password</label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-key-fill"></i></span>
                            <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" required autocomplete="current-password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-dark w-100 py-2 fw-semibold shadow-sm mb-3">
                        <i class="bi bi-box-arrow-in-right me-2"></i> Access Admin Console
                    </button>
                </form>

                <!-- Quick Demo Login Helper for Evaluators (Password hidden from UI text) -->
                <div class="mt-4 pt-3 border-top text-center">
                    <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-bold btn-quick-login" data-username="pvannapurna4u@gmail.com" data-password="appu2720">
                        <i class="bi bi-lightning-charge-fill me-1 text-warning"></i> Auto-fill Admin: pvannapurna4u@gmail.com
                    </button>
                </div>

                <div class="mt-4 text-center">
                    <a href="<?= BASE_URL ?>login.php" class="text-secondary small text-decoration-none">
                        &larr; Return to Single Sign-On (SSO) Portal
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const quickBtn = document.querySelector('.btn-quick-login');
    if (quickBtn) {
        quickBtn.addEventListener('click', function() {
            document.getElementById('username').value = this.getAttribute('data-username');
            document.getElementById('password').value = this.getAttribute('data-password');
        });
    }
});
</script>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
