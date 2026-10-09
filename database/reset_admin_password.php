<?php
/**
 * Local-Only Administrator Password Reset Utility
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 *
 * Security Constraints:
 * - Local-Only: Only accessible via CLI or 127.0.0.1 / ::1
 * - Target Lock: Hardcoded strictly to Admin User ID = 1 with role = 'admin'
 * - Algorithm: Native PHP password_hash(..., PASSWORD_DEFAULT) [Bcrypt, Cost 10]
 * - Lockout: Automatically self-locks with a .lock file after a single successful reset
 * - Audit: Logs ADMIN_PASSWORD_RESET action into the immutable audit trail
 */

$lockFile = __DIR__ . DIRECTORY_SEPARATOR . 'reset_admin_password.lock';

// 1. Check if utility has already been used / locked
if (file_exists($lockFile)) {
    http_response_code(403);
    die("Security Error: This password reset utility has already been used and is permanently locked. To re-enable, manually delete 'database/reset_admin_password.lock'.\n");
}

// 2. Enforce local-only invocation
$isCli = (php_sapi_name() === 'cli');
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$isLocalhost = in_array($remoteAddr, ['127.0.0.1', '::1', 'localhost'], true);

if (!$isCli && !$isLocalhost) {
    http_response_code(403);
    die("Access Denied (403): This administrative utility is only executable from localhost or the command line.\n");
}

require_once __DIR__ . '/../config/config.php';
$pdo = get_db_connection();

$adminId = 1;
$error = '';
$success = false;

// 3. Verify Admin Account exists and is active
$stmtAdmin = $pdo->prepare("SELECT id, username, email, role, status FROM `users` WHERE `id` = ? AND `role` = 'admin' LIMIT 1");
$stmtAdmin->execute([$adminId]);
$adminUser = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

if (!$adminUser) {
    die("Fatal Error: Administrator account with User ID {$adminId} and role 'admin' not found in database.\n");
}

// ----------------------------------------------------------------------------
// CLI Execution Mode
// Usage: php database/reset_admin_password.php [new_password]
// ----------------------------------------------------------------------------
if ($isCli) {
    echo "============================================================" . PHP_EOL;
    echo "  DEPA PLATFORM: LOCAL ADMIN PASSWORD RESET UTILITY          " . PHP_EOL;
    echo "============================================================" . PHP_EOL;
    echo "Target Account: User ID {$adminUser['id']} | Username: {$adminUser['username']} | Role: {$adminUser['role']}" . PHP_EOL;

    $newPassword = $argv[1] ?? null;

    if (empty($newPassword)) {
        echo PHP_EOL . "Enter new password for Admin (min 8 characters): ";
        $handle = fopen("php://stdin", "r");
        $newPassword = trim(fgets($handle));
        fclose($handle);
    }

    if (empty($newPassword) || strlen($newPassword) < 8) {
        die(PHP_EOL . "Error: Password must be at least 8 characters long. Reset aborted.\n");
    }

    try {
        $pdo->beginTransaction();

        // Hash using the application's exact default algorithm (Bcrypt)
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update ONLY password_hash and updated_at on the specific Admin user
        $stmtUpdate = $pdo->prepare("UPDATE `users` SET `password_hash` = ?, `updated_at` = NOW() WHERE `id` = ? AND `role` = 'admin'");
        $stmtUpdate->execute([$passwordHash, $adminId]);

        // Invalidate previous SSO sessions for safety
        $pdo->prepare("UPDATE `sso_sessions` SET `is_active` = 0 WHERE `user_id` = ?")->execute([$adminId]);

        // Log audit trail
        log_audit(
            $pdo, 
            'ADMIN_PASSWORD_RESET', 
            "Administrator password successfully reset via local CLI utility. Previous SSO sessions invalidated.", 
            $adminId, 
            'admin', 
            $adminUser['username']
        );

        $pdo->commit();

        // Create permanent lockfile
        file_put_contents($lockFile, "RESET_COMPLETED_AT=" . date('Y-m-d H:i:s') . "\nUSER_ID=" . $adminId . "\n");

        echo PHP_EOL . "SUCCESS: Administrator password has been updated successfully!" . PHP_EOL;
        echo "Lockfile created at: database/reset_admin_password.lock" . PHP_EOL;
        echo "You can now log in at http://localhost/digital-health-record/login.php with your new password." . PHP_EOL;
        exit(0);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die(PHP_EOL . "Execution Error: " . $e->getMessage() . PHP_EOL);
    }
}

// ----------------------------------------------------------------------------
// Web Interface Mode (Localhost Only: http://localhost/digital-health-record/database/reset_admin_password.php)
// ----------------------------------------------------------------------------
if (empty($_SESSION['admin_reset_csrf'])) {
    $_SESSION['admin_reset_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_reset_csrf'], $token)) {
        $error = "CSRF security token mismatch. Please try again.";
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword) || empty($confirmPassword)) {
            $error = "Please enter both new password and confirmation.";
        } elseif (strlen($newPassword) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            try {
                $pdo->beginTransaction();

                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmtUpdate = $pdo->prepare("UPDATE `users` SET `password_hash` = ?, `updated_at` = NOW() WHERE `id` = ? AND `role` = 'admin'");
                $stmtUpdate->execute([$passwordHash, $adminId]);

                $pdo->prepare("UPDATE `sso_sessions` SET `is_active` = 0 WHERE `user_id` = ?")->execute([$adminId]);

                log_audit(
                    $pdo, 
                    'ADMIN_PASSWORD_RESET', 
                    "Administrator password successfully reset via local Web utility. Previous SSO sessions invalidated.", 
                    $adminId, 
                    'admin', 
                    $adminUser['username']
                );

                $pdo->commit();

                // Lock utility immediately
                file_put_contents($lockFile, "RESET_COMPLETED_AT=" . date('Y-m-d H:i:s') . "\nUSER_ID=" . $adminId . "\n");
                unset($_SESSION['admin_reset_csrf']);

                $success = true;

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Failed to update password: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local Admin Password Reset - DEPA HealthExchange</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card card-custom shadow border-0 overflow-hidden">
                <div class="card-header bg-dark text-white text-center py-4" style="background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);">
                    <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-10 rounded-circle p-3 mb-2" style="width: 60px; height: 60px;">
                        <i class="bi bi-shield-lock-fill fs-2 text-warning"></i>
                    </div>
                    <h4 class="fw-bold mb-1">Local Admin Password Reset</h4>
                    <p class="small text-white-50 mb-0">Emergency Localhost Administrative Recovery Utility</p>
                </div>

                <div class="card-body p-4 p-md-5">

                    <?php if ($success): ?>
                        <div class="alert alert-success shadow-sm mb-4">
                            <h5 class="fw-bold mb-2"><i class="bi bi-check-circle-fill me-2"></i> Password Successfully Reset!</h5>
                            <p class="small mb-2">The password for Administrator <strong><?= e($adminUser['username']) ?></strong> (User ID: 1) has been updated and securely hashed with Bcrypt.</p>
                            <p class="small text-muted mb-0">This utility has now been <strong>automatically locked</strong> against further access.</p>
                        </div>
                        <a href="<?= BASE_URL ?>login.php" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                            <i class="bi bi-box-arrow-in-right me-2"></i> Go to Admin Login
                        </a>

                    <?php else: ?>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger d-flex align-items-center shadow-sm mb-3">
                                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                                <div><?= e($error) ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="p-3 bg-light rounded-3 border mb-4">
                            <div class="small text-muted fw-bold text-uppercase mb-2"><i class="bi bi-person-badge me-1"></i>Target Account:</div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-secondary small">User ID:</span>
                                <span class="fw-semibold">1 (Administrator)</span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-secondary small">Username / Email:</span>
                                <span class="fw-semibold font-monospace small"><?= e($adminUser['username']) ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-secondary small">Hashing Algorithm:</span>
                                <span class="badge bg-secondary">Bcrypt (PASSWORD_DEFAULT)</span>
                            </div>
                        </div>

                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['admin_reset_csrf']) ?>">

                            <div class="mb-3">
                                <label for="new_password" class="form-label fw-semibold">New Admin Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-key"></i></span>
                                    <input type="password" class="form-control form-control-lg" id="new_password" name="new_password" placeholder="Minimum 8 characters" required autofocus minlength="8">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="confirm_password" class="form-label fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light"><i class="bi bi-check2-circle"></i></span>
                                    <input type="password" class="form-control form-control-lg" id="confirm_password" name="confirm_password" placeholder="Re-type new password" required minlength="8">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-warning btn-lg w-100 fw-bold shadow-sm mb-2 text-dark">
                                <i class="bi bi-arrow-repeat me-2"></i> Update Admin Password
                            </button>

                            <a href="<?= BASE_URL ?>login.php" class="btn btn-outline-secondary w-100">
                                Cancel
                            </a>
                        </form>

                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
