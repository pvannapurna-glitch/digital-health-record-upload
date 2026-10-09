<?php
/**
 * Google OAuth 2.0 Callback Endpoint
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 *
 * Receives authorization code from Google, securely exchanges for verified ID token,
 * enforces account matching and linking rules, and sets up authenticated SSO session.
 */

require_once __DIR__ . '/config/config.php';

$pdo = get_db_connection();

// 1. Handle user cancellation or OAuth errors from Google
if (isset($_GET['error'])) {
    $errorReason = $_GET['error_description'] ?? $_GET['error'];
    log_audit($pdo, 'GOOGLE_LOGIN_FAILED', 'User cancelled Google Sign-In or access denied: ' . $errorReason);
    set_flash('info', 'Google Sign-In was cancelled.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// 2. Validate Anti-CSRF OAuth State Parameter
$state = $_GET['state'] ?? '';
$savedState = $_SESSION['oauth2_state'] ?? '';
unset($_SESSION['oauth2_state']);

if (empty($state) || empty($savedState) || !hash_equals($savedState, $state)) {
    log_audit($pdo, 'GOOGLE_LOGIN_FAILED', 'OAuth state verification failure / CSRF mismatch.');
    set_flash('danger', 'Authentication session expired or security verification failed. Please try again.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// 3. Validate Authorization Code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    log_audit($pdo, 'GOOGLE_LOGIN_FAILED', 'Missing authorization code in Google callback.');
    set_flash('danger', 'No authorization code received from Google. Please try again.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// 4. Exchange code for verified OpenID Connect ID token
try {
    $googleAuth = new GoogleAuthService();
    $googleUser = $googleAuth->handleOAuthCallback($code);
} catch (Exception $e) {
    // Never display sensitive raw tokens or secrets
    log_audit($pdo, 'GOOGLE_LOGIN_FAILED', 'Google authentication exchange error: ' . $e->getMessage());
    set_flash('danger', 'Google authentication could not be completed: ' . e($e->getMessage()));
    header("Location: " . BASE_URL . "login.php");
    exit;
}

$googleSub     = $googleUser['sub'];
$googleEmail   = $googleUser['email'];
$googleName    = $googleUser['name'];
$googlePicture = $googleUser['picture'];

// 5. Check if user is already linked by stable Google `sub`
$stmtSub = $pdo->prepare("SELECT * FROM `users` WHERE `google_sub` = ? LIMIT 1");
$stmtSub->execute([$googleSub]);
$linkedUser = $stmtSub->fetch();

if ($linkedUser) {
    // Account is already linked with this Google sub
    if ($linkedUser['status'] !== 'active') {
        log_audit($pdo, 'GOOGLE_LOGIN_FAILED', "Attempted Google login to inactive account ID {$linkedUser['id']}", $linkedUser['id'], $linkedUser['role'], $linkedUser['username']);
        set_flash('danger', 'Your account has been deactivated by the system administrator.');
        header("Location: " . BASE_URL . "login.php");
        exit;
    }

    // Refresh profile picture or email in background if updated on Google
    try {
        $stmtUp = $pdo->prepare("UPDATE `users` SET `google_email` = ?, `google_picture` = ? WHERE `id` = ?");
        $stmtUp->execute([$googleEmail, $googlePicture, $linkedUser['id']]);
        $linkedUser['google_email']   = $googleEmail;
        $linkedUser['google_picture'] = $googlePicture;
    } catch (Exception $e) {
        // Non-critical, continue login
    }

    // Authenticate user session with session regeneration
    $redirectUrl = complete_login_session($pdo, $linkedUser, 'google');
    $profileName = $_SESSION['user_name'] ?? $linkedUser['username'];

    log_audit($pdo, 'GOOGLE_LOGIN_SUCCESS', "Google SSO login successful for user {$profileName} ({$linkedUser['role']}) [Google Sub: {$googleSub}]", $linkedUser['id'], $linkedUser['role'], $profileName);
    set_flash('success', "Signed in via Google successfully. Welcome back, {$profileName}!");
    header("Location: " . $redirectUrl);
    exit;
}

// 6. Check if email matches an existing local user account
$stmtEmail = $pdo->prepare("SELECT * FROM `users` WHERE `email` = ? LIMIT 1");
$stmtEmail->execute([$googleEmail]);
$existingUser = $stmtEmail->fetch();

if ($existingUser) {
    // Existing account found with matching email but NOT yet linked to this Google sub.
    // RULE: DO NOT silently merge accounts!
    // Require explicit confirmation & password verification before linking.
    $_SESSION['pending_google_link'] = [
        'sub'              => $googleSub,
        'email'            => $googleEmail,
        'name'             => $googleName,
        'picture'          => $googlePicture,
        'matched_user_id'  => (int)$existingUser['id'],
        'matched_username' => $existingUser['username'],
        'matched_email'    => $existingUser['email'],
        'matched_role'     => $existingUser['role'],
        'expires_at'       => time() + 900 // 15 minutes validity
    ];

    header("Location: " . BASE_URL . "google_link_account.php");
    exit;
}

// 7. Brand new Google user: neither sub nor email exists.
// RULE: Do not automatically create an Admin.
// Require user to choose Role (Patient, Doctor, Hospital) and supply profile information.
$_SESSION['pending_google_reg'] = [
    'sub'        => $googleSub,
    'email'      => $googleEmail,
    'name'       => $googleName,
    'picture'    => $googlePicture,
    'expires_at' => time() + 1800 // 30 minutes validity
];

header("Location: " . BASE_URL . "google_register.php");
exit;
