<?php
/**
 * Google OAuth 2.0 Login Gateway
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/config/config.php';

// Redirect if already logged in
redirect_if_logged_in();

$pdo = get_db_connection();

try {
    $googleAuth = new GoogleAuthService();

    if (!$googleAuth->isConfigured()) {
        set_flash('warning', 'Google Single Sign-On (SSO) is not yet configured. Please set your GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in config/google.php.');
        header("Location: " . BASE_URL . "login.php");
        exit;
    }

    // Generate cryptographically secure CSRF anti-tamper state
    $state = bin2hex(random_bytes(32));
    $_SESSION['oauth2_state'] = $state;

    // Create authorization URL
    $authUrl = $googleAuth->createAuthUrl($state);

    // Redirect user to Google's OAuth consent
    header("Location: " . $authUrl);
    exit;

} catch (Exception $e) {
    log_audit($pdo, 'GOOGLE_LOGIN_FAILED', 'Failed to initialize Google OAuth: ' . $e->getMessage());
    set_flash('danger', 'Unable to connect to Google Sign-In right now. Please try standard login.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}
