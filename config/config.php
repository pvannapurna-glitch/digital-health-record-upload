<?php
/**
 * Global Configuration File
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

// Enable error reporting during development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set default timezone
date_default_timezone_set('Asia/Kolkata');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

// Database Credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'digital_health_db');

// Application Constants
define('APP_NAME', 'Digital Health Record Exchange');
define('APP_SHORT_NAME', 'DEPA HealthExchange');
define('APP_TAGLINE', 'Consent-Driven Secure Health Data Exchange Simulation');
define('APP_VERSION', '1.0.0');

// Base Paths and URLs
define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('UPLOAD_DIR', ROOT_DIR . 'uploads' . DIRECTORY_SEPARATOR . 'health_records' . DIRECTORY_SEPARATOR);
define('SIGNATURE_UPLOAD_DIR', ROOT_DIR . 'uploads' . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR);

// Dynamically determine BASE_URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Determine base URL path relative to DocumentRoot
$docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
$rootDir = str_replace('\\', '/', realpath(ROOT_DIR));

if ($docRoot && strpos($rootDir, $docRoot) === 0) {
    $basePath = substr($rootDir, strlen($docRoot));
} else {
    // Fallback: strip known project subdirectories if present
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = preg_replace('/(\/(admin|doctor|hospital|patient|ekyc|sso|includes|config|database|assets|uploads).*)$/i', '', $scriptDir);
    if (empty($basePath) || strpos($basePath, ':') !== false || $basePath === '.' || $basePath === '/') {
        $basePath = '/digital-health-record';
    }
}

$basePath = str_replace('\\', '/', (string)$basePath);
$basePath = trim($basePath, '/. ');
if ($basePath === '') {
    $basePath = 'digital-health-record';
}
$basePath = '/' . $basePath;

define('BASE_URL', rtrim($protocol . $host . $basePath, '/') . '/');

// Max file upload size (10 MB)
define('MAX_FILE_SIZE', 10 * 1024 * 1024);
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);

// Require Composer Autoloader if available
if (file_exists(ROOT_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
    require_once ROOT_DIR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
}

// Require Database & Helper libraries
require_once ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
if (file_exists(ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'smtp_config.php')) {
    require_once ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'smtp_config.php';
} elseif (file_exists(ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'smtp_config.example.php')) {
    require_once ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'smtp_config.example.php';
}
require_once ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'emsigner.php';
if (file_exists(ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'google.php')) {
    require_once ROOT_DIR . 'config' . DIRECTORY_SEPARATOR . 'google.php';
}
require_once ROOT_DIR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once ROOT_DIR . 'includes' . DIRECTORY_SEPARATOR . 'smtp_mailer.php';
require_once ROOT_DIR . 'includes' . DIRECTORY_SEPARATOR . 'password_reset_service.php';
require_once ROOT_DIR . 'includes' . DIRECTORY_SEPARATOR . 'mock_aadhaar_generator.php';
require_once ROOT_DIR . 'includes' . DIRECTORY_SEPARATOR . 'aadhaar_ekyc_service.php';
require_once ROOT_DIR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';
if (file_exists(ROOT_DIR . 'services' . DIRECTORY_SEPARATOR . 'GoogleAuthService.php')) {
    require_once ROOT_DIR . 'services' . DIRECTORY_SEPARATOR . 'GoogleAuthService.php';
}
if (file_exists(ROOT_DIR . 'services' . DIRECTORY_SEPARATOR . 'EmSignerService.php')) {
    require_once ROOT_DIR . 'services' . DIRECTORY_SEPARATOR . 'EmSignerService.php';
}
if (file_exists(ROOT_DIR . 'services' . DIRECTORY_SEPARATOR . 'DoctorEkycService.php')) {
    require_once ROOT_DIR . 'services' . DIRECTORY_SEPARATOR . 'DoctorEkycService.php';
}


