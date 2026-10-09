<?php
/**
 * Google Account Linking Confirmation Gateway
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 *
 * Enforces explicit confirmation and credential verification before linking
 * a Google identity to an existing account. Never silently merges identities.
 */

$pageTitle = "Link Google Account";
require_once __DIR__ . '/config/config.php';

redirect_if_logged_in();

$pending = $_SESSION['pending_google_link'] ?? null;

if (!$pending || empty($pending['sub']) || empty($pending['matched_user_id']) || time() > ($pending['expires_at'] ?? 0)) {
    unset($_SESSION['pending_google_link']);
    set_flash('warning', 'The account linking session has expired. Please sign in with Google again.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

$pdo = get_db_connection();
$error = '';

// Handle cancellation
if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_google_link']);
    set_flash('info', 'Google account linking was cancelled.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// Generate Anti-CSRF token
if (empty($_SESSION['link_csrf_token'])) {
    $_SESSION['link_csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch matched account details
$stmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ? LIMIT 1");
$stmt->execute([$pending['matched_user_id']]);
$user = $stmt->fetch();

if (!$user) {
    unset($_SESSION['pending_google_link']);
    set_flash('danger', 'The referenced account no longer exists.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['link_csrf_token'], $submittedToken)) {
        $error = "Security token mismatch. Please try again.";
    } else {
        $password = $_POST['password'] ?? '';

        if (empty($password)) {
            $error = "Please enter your current account password to authorize linking.";
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = "Incorrect password. Please verify the credentials for your existing account.";
            log_audit($pdo, 'GOOGLE_LINK_FAILED', "Failed password verification during Google account linking for user {$user['username']}", $user['id'], $user['role'], $user['username']);
        } elseif ($user['status'] !== 'active') {
            $error = "This account is deactivated. Account linking cannot proceed.";
            log_audit($pdo, 'GOOGLE_LINK_FAILED', "Attempted to link Google to inactive account ID {$user['id']}", $user['id'], $user['role'], $user['username']);
        } else {
            // Credentials verified -> Link Google Sub and Profile
            try {
                $pdo->beginTransaction();

                $stmtLink = $pdo->prepare("UPDATE `users` SET 
                    `google_sub` = ?, 
                    `google_email` = ?, 
                    `google_picture` = ?, 
                    `google_linked_at` = NOW() 
                    WHERE `id` = ?");
                $stmtLink->execute([
                    $pending['sub'],
                    $pending['email'],
                    $pending['picture'] ?: null,
                    $user['id']
                ]);

                // Update in-memory user record
                $user['google_sub'] = $pending['sub'];
                $user['google_email'] = $pending['email'];
                $user['google_picture'] = $pending['picture'];
                $user['google_linked_at'] = date('Y-m-d H:i:s');

                log_audit($pdo, 'GOOGLE_ACCOUNT_LINKED', "Google identity ({$pending['email']} [sub: {$pending['sub']}]) successfully linked to user {$user['username']} ({$user['role']})", $user['id'], $user['role'], $user['username']);

                $pdo->commit();

                // Clear pending session and CSRF
                unset($_SESSION['pending_google_link']);
                unset($_SESSION['link_csrf_token']);

                // Authenticate session and redirect to role dashboard
                $redirectUrl = complete_login_session($pdo, $user, 'google');
                $profileName = $_SESSION['user_name'] ?? $user['username'];

                log_audit($pdo, 'GOOGLE_LOGIN_SUCCESS', "Google SSO login successful after initial account link for {$profileName} ({$user['role']})", $user['id'], $user['role'], $profileName);

                set_flash('success', "Google account successfully linked! Welcome back, {$profileName}.");
                header("Location: " . $redirectUrl);
                exit;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Failed to link Google account: " . $e->getMessage();
                log_audit($pdo, 'GOOGLE_LINK_FAILED', "Database error linking Google account: " . $e->getMessage(), $user['id'], $user['role'], $user['username']);
            }
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center my-4">
    <div class="col-md-8 col-lg-6 col-xl-5">
        <div class="card card-custom shadow border-0 overflow-hidden">
            <div class="card-header bg-dark text-white text-center py-4 position-relative" style="background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
                <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-10 rounded-circle p-3 mb-2" style="width: 60px; height: 60px;">
                    <i class="bi bi-link-45deg fs-1 text-primary"></i>
                </div>
                <h4 class="fw-bold mb-1">Link Google Account</h4>
                <p class="small text-white-50 mb-0">Verify account ownership to enable Google Single Sign-On</p>
            </div>

            <div class="card-body p-4 p-md-5">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <div class="alert alert-info border-info d-flex align-items-start shadow-sm mb-4">
                    <i class="bi bi-shield-exclamation me-3 fs-3 text-info"></i>
                    <div>
                        <div class="fw-bold mb-1">Existing Account Detected</div>
                        <div class="small">
                            An existing DEPA account was found matching the email address <strong><?= e($pending['email']) ?></strong>.
                            For security against unauthorized account takeover, please verify your existing account password before linking.
                        </div>
                    </div>
                </div>

                <!-- Google Account Card -->
                <div class="p-3 bg-light rounded-3 border mb-3">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($pending['picture'])): ?>
                            <img src="<?= e($pending['picture']) ?>" alt="Google Avatar" class="rounded-circle me-3 border shadow-sm" style="width: 48px; height: 48px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-white border d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 48px; height: 48px;">
                                <svg width="24" height="24" viewBox="0 0 24 24">
                                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                                </svg>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold"><?= e($pending['name']) ?></div>
                            <div class="small text-muted"><?= e($pending['email']) ?></div>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary mt-1" style="font-size: 0.7rem;">Google Identity</span>
                        </div>
                    </div>
                </div>

                <!-- Existing Account Details -->
                <div class="mb-4 p-3 bg-white rounded-3 border">
                    <div class="small text-muted fw-bold text-uppercase mb-2">Target DEPA Account:</div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-secondary small">Username:</span>
                        <span class="fw-semibold"><?= e($user['username']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="text-secondary small">Role:</span>
                        <span class="badge bg-secondary text-uppercase"><?= e($user['role']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-secondary small">Registered Email:</span>
                        <span class="small font-monospace"><?= e($user['email']) ?></span>
                    </div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['link_csrf_token']) ?>">

                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold">Enter Password for <?= e($user['username']) ?> <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                            <input type="password" class="form-control form-control-lg" id="password" name="password" placeholder="Existing account password" required autofocus>
                        </div>
                        <div class="form-text">Enter your password to securely link this account to your Google sign-in.</div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm mb-2">
                        <i class="bi bi-link me-2"></i> Verify & Link Google Account
                    </button>

                    <a href="<?= BASE_URL ?>google_link_account.php?cancel=1" class="btn btn-outline-secondary w-100">
                        Cancel and return to Login
                    </a>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
