<?php
/**
 * Google OAuth 2.0 Configuration Template (Sample)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 *
 * INSTRUCTIONS:
 * 1. Duplicate this file as `config/google.php` if it doesn't already exist.
 * 2. Replace YOUR_CLIENT_ID and YOUR_CLIENT_SECRET with your real Google Cloud Console OAuth 2.0 Client credentials.
 * 3. Never commit `config/google.php` with real production secrets to version control.
 */

// Prevent direct web access
if (!defined('ROOT_DIR') && php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

// Google OAuth 2.0 Web Application Client Credentials
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: 'YOUR_CLIENT_ID');
}

if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', getenv('GOOGLE_CLIENT_SECRET') ?: 'YOUR_CLIENT_SECRET');
}

// Authorized Redirect URI (Must exactly match the Authorized redirect URI in Google Cloud Console)
if (!defined('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/digital-health-record/google_callback.php');
}

// Minimal Identity Scopes (OpenID Connect Standard)
if (!defined('GOOGLE_OAUTH_SCOPES')) {
    define('GOOGLE_OAUTH_SCOPES', ['openid', 'email', 'profile']);
}

return [
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'scopes'        => GOOGLE_OAUTH_SCOPES,
];
