<?php
/**
 * User Activation / Deactivation Handler (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security verification failed.');
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? BASE_URL . 'admin/dashboard.php'));
        exit;
    }

    $pdo = get_db_connection();
    $adminUserId = get_logged_user_id();
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $redirect = $_POST['redirect'] ?? 'admin/dashboard.php';

    // Prevent admin self-deactivation
    if ($targetUserId === $adminUserId) {
        set_flash('danger', 'You cannot deactivate your own administrative account.');
        header("Location: " . BASE_URL . $redirect);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ?");
    $stmt->execute([$targetUserId]);
    $targetUser = $stmt->fetch();

    if ($targetUser) {
        $newStatus = ($targetUser['status'] === 'active') ? 'inactive' : 'active';

        $stmtUp = $pdo->prepare("UPDATE `users` SET `status` = ? WHERE `id` = ?");
        $stmtUp->execute([$newStatus, $targetUserId]);

        // Audit Log
        log_audit($pdo, 'USER_STATUS_CHANGED', "Administrator changed status of user {$targetUser['username']} ({$targetUser['role']}) to " . strtoupper($newStatus), $adminUserId, 'admin');

        set_flash('success', "User '{$targetUser['username']}' status changed to " . strtoupper($newStatus) . ".");
    } else {
        set_flash('danger', 'User not found.');
    }
}

header("Location: " . BASE_URL . $redirect);
exit;
