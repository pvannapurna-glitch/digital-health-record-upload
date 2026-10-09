<?php
/**
 * Single Sign-On (SSO) Logout Handler
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/config/config.php';

if (is_logged_in()) {
    $pdo = get_db_connection();
    $userId   = get_logged_user_id();
    $userRole = get_user_role();
    $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';

    // Invalidate SSO active session token in database
    destroy_sso_session($pdo);

    log_audit($pdo, 'SSO_LOGOUT', "User {$userName} ({$userRole}) successfully logged out of SSO session.", $userId, $userRole, $userName);

    // Unset all session variables
    $_SESSION = [];

    // Delete session cookie if present
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
}

// Start temporary session for flash message
session_start();
set_flash('info', 'You have been successfully signed out of Single Sign-On (SSO).');
header("Location: " . BASE_URL . "login.php");
exit;
