<?php
/**
 * SMTP Mailer Configuration Template (Sample)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * INSTRUCTIONS:
 * 1. Duplicate this file as `config/smtp_config.php` or set environment variables.
 * 2. Configure your SMTP host, port, credentials, and from-email.
 * 3. Never commit `config/smtp_config.php` or `config/*.local.php` with real credentials.
 */

// SMTP Server Host (e.g. smtp.gmail.com, smtp.mailtrap.io, or localhost)
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
}

// SMTP Port (587 for STARTTLS, 465 for SSL, 25 or 1025 for local testing)
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
}

// SMTP Encryption ('tls', 'ssl', or 'none')
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'tls');
}

// SMTP Authentication Required (true/false)
if (!defined('SMTP_AUTH')) {
    define('SMTP_AUTH', true);
}

// SMTP Username / Email (e.g. your_email@gmail.com)
if (!defined('SMTP_USER')) {
    define('SMTP_USER', getenv('SMTP_USER') ?: 'your_email@gmail.com');
}

// SMTP Password or App Password (DO NOT HARDCODE REAL SECRETS IN PRODUCTION)
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', getenv('SMTP_PASS') ?: 'YOUR_SMTP_APP_PASSWORD');
}

// Sender Email Address
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'noreply@yourdomain.com');
}

// Sender Display Name
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'DEPA Health Record Exchange');
}

// SMTP Timeout in seconds
if (!defined('SMTP_TIMEOUT')) {
    define('SMTP_TIMEOUT', 15);
}

// OTP Expiry duration in seconds (10 minutes = 600 seconds)
if (!defined('OTP_EXPIRY_SECONDS')) {
    define('OTP_EXPIRY_SECONDS', 600);
}

// Resend OTP Cooldown duration in seconds (60 seconds)
if (!defined('OTP_RESEND_COOLDOWN_SECONDS')) {
    define('OTP_RESEND_COOLDOWN_SECONDS', 60);
}

// Max failed attempts before locking the OTP
if (!defined('OTP_MAX_ATTEMPTS')) {
    define('OTP_MAX_ATTEMPTS', 5);
}

// Local Development Log File for Email OTPs
if (!defined('EMAIL_OTP_LOG_FILE')) {
    define('EMAIL_OTP_LOG_FILE', ROOT_DIR . 'logs' . DIRECTORY_SEPARATOR . 'email_otp_log.txt');
}
