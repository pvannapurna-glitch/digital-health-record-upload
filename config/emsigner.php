<?php
/**
 * eMudhra & emSigner Integration Configuration
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Central Configuration Interface for:
 * - emSigner Signer Gateway (Demo / Sandbox & Production)
 * - emSigner REST API (InitiateandSign, TransactionStatus, SignedData)
 * - eMudhra eKYC / IDBroker (Sandbox & Production)
 * 
 * Local Credentials Loading:
 * - If `config/emsigner.local.php` exists, it is loaded with top priority.
 * - `config/emsigner.local.php` is strictly ignored by Git (.gitignore).
 * - Direct HTTP access is blocked via .htaccess and PHP execution guard.
 * - Falls back to environment variables or safe academic defaults.
 */

// Prevent direct web browser invocation
if (!defined('ROOT_DIR') && php_sapi_name() !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

if (!defined('ROOT_DIR')) {
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
}

// -----------------------------------------------------------------------------
// Load Local Secrets Configuration (if present)
// -----------------------------------------------------------------------------
$localConfigFile = __DIR__ . DIRECTORY_SEPARATOR . 'emsigner.local.php';
$localConfig = [];
if (file_exists($localConfigFile)) {
    $loaded = include $localConfigFile;
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}

// Helper to resolve configuration with strict hierarchy:
// Local File -> getenv() -> $_ENV -> $_SERVER -> Safe Default
$getVal = function(string $key, string $default = '') use ($localConfig): string {
    if (isset($localConfig[$key]) && (string)$localConfig[$key] !== '') {
        return (string)$localConfig[$key];
    }
    $env = getenv($key);
    if ($env !== false && (string)$env !== '') {
        return (string)$env;
    }
    if (!empty($_ENV[$key])) {
        return (string)$_ENV[$key];
    }
    if (!empty($_SERVER[$key])) {
        return (string)$_SERVER[$key];
    }
    return $default;
};

// -----------------------------------------------------------------------------
// 1. Operating Mode: 'demo' (Sandbox / Simulator) or 'production' (Live eMudhra)
// -----------------------------------------------------------------------------
if (!defined('EMSIGNER_MODE')) {
    define('EMSIGNER_MODE', $getVal('EMSIGNER_MODE', 'demo'));
}

// emSigner Gateway Base URLs
if (!defined('EMSIGNER_DEMO_GATEWAY_URL')) {
    define('EMSIGNER_DEMO_GATEWAY_URL', 'https://demogateway.emsigner.com');
}
if (!defined('EMSIGNER_PROD_GATEWAY_URL')) {
    define('EMSIGNER_PROD_GATEWAY_URL', 'https://gateway.emsigner.com');
}

// emSigner REST API Base URLs
if (!defined('EMSIGNER_DEMO_API_URL')) {
    define('EMSIGNER_DEMO_API_URL', 'https://demoapi.emsigner.com/api/');
}
if (!defined('EMSIGNER_PROD_API_URL')) {
    define('EMSIGNER_PROD_API_URL', 'https://api.emsigner.com/api/');
}

// -----------------------------------------------------------------------------
// 2. emSigner Authentication Token
// -----------------------------------------------------------------------------
if (!defined('EMSIGNER_AUTH_TOKEN')) {
    define('EMSIGNER_AUTH_TOKEN', $getVal('EMSIGNER_AUTH_TOKEN', 'EMSIG-DEMO-TOKEN-DEPA-HEALTH-2026'));
}

// -----------------------------------------------------------------------------
// 3. emSigner App Credentials (Admin -> Apps)
// -----------------------------------------------------------------------------
if (!defined('EMSIGNER_APP_NAME')) {
    define('EMSIGNER_APP_NAME', $getVal('EMSIGNER_APP_NAME', 'DEPA_HEALTH_EXCHANGE'));
}
if (!defined('EMSIGNER_SECRET_KEY')) {
    define('EMSIGNER_SECRET_KEY', $getVal('EMSIGNER_SECRET_KEY', 'depa_emsigner_secret_key_academic_2026'));
}

// -----------------------------------------------------------------------------
// 4. eMudhra eKYC / IDBroker Credentials
// -----------------------------------------------------------------------------
if (!defined('EMUDHRA_KYC_MODE')) {
    define('EMUDHRA_KYC_MODE', $getVal('EMUDHRA_KYC_MODE', 'sandbox'));
}
if (!defined('EMUDHRA_KYC_API_KEY')) {
    define('EMUDHRA_KYC_API_KEY', $getVal('EMUDHRA_KYC_API_KEY', 'emudhra_kyc_api_key_demo'));
}
if (!defined('EMUDHRA_KYC_CLIENT_ID')) {
    define('EMUDHRA_KYC_CLIENT_ID', $getVal('EMUDHRA_KYC_CLIENT_ID', 'emudhra_kyc_client_depa'));
}

// -----------------------------------------------------------------------------
// 5. Default Reviewing Authority / Approver Settings
// -----------------------------------------------------------------------------
if (!defined('DEFAULT_APPROVER_NAME')) {
    define('DEFAULT_APPROVER_NAME', $getVal('DEFAULT_APPROVER_NAME', 'Dr. S. K. Nambiar'));
}
if (!defined('DEFAULT_APPROVER_ROLE')) {
    define('DEFAULT_APPROVER_ROLE', $getVal('DEFAULT_APPROVER_ROLE', 'Medical Superintendent / Reviewing Authority'));
}
if (!defined('DEFAULT_APPROVER_EMAIL')) {
    define('DEFAULT_APPROVER_EMAIL', $getVal('DEFAULT_APPROVER_EMAIL', 'approver@example.com'));
}

// -----------------------------------------------------------------------------
// 6. Signature Coordinate Specifications on Page 1 (A4: 595 x 842 points)
// Format for emSigner PagelevelCoordinates: PageNumber,Left,Top,Width,Height
// -----------------------------------------------------------------------------
if (!defined('EMSIGNER_COORD_DOCTOR')) {
    define('EMSIGNER_COORD_DOCTOR', '1,55,640,220,110');
}
if (!defined('EMSIGNER_COORD_APPROVER')) {
    define('EMSIGNER_COORD_APPROVER', '1,320,640,220,110');
}

// -----------------------------------------------------------------------------
// Helper Inspection Functions (Safe: Never outputs raw secrets)
// -----------------------------------------------------------------------------

if (!function_exists('is_emsigner_live_configured')) {
    /**
     * Determine if custom non-default credentials have been configured
     */
    function is_emsigner_live_configured(): bool {
        $tok = defined('EMSIGNER_AUTH_TOKEN') ? constant('EMSIGNER_AUTH_TOKEN') : '';
        if (empty($tok) || $tok === 'EMSIG-DEMO-TOKEN-DEPA-HEALTH-2026' || strpos($tok, 'YOUR_') === 0) {
            return false;
        }
        return true;
    }
}

if (!function_exists('get_emsigner_config_summary')) {
    /**
     * Return safe summary of emSigner configuration without exposing secrets
     */
    function get_emsigner_config_summary(): array {
        $localPath = __DIR__ . DIRECTORY_SEPARATOR . 'emsigner.local.php';
        $authToken = defined('EMSIGNER_AUTH_TOKEN') ? (string)constant('EMSIGNER_AUTH_TOKEN') : '';
        $secretKey = defined('EMSIGNER_SECRET_KEY') ? (string)constant('EMSIGNER_SECRET_KEY') : '';
        $kycKey    = defined('EMUDHRA_KYC_API_KEY') ? (string)constant('EMUDHRA_KYC_API_KEY') : '';

        $maskString = function(string $str): string {
            $len = strlen($str);
            if ($len === 0) return '[NOT_SET]';
            if ($len <= 8) return substr($str, 0, 2) . str_repeat('*', max(1, $len - 2));
            return substr($str, 0, 4) . str_repeat('*', min(12, $len - 8)) . substr($str, -4);
        };

        return [
            'mode'                 => constant('EMSIGNER_MODE'),
            'has_local_file'       => file_exists($localPath),
            'local_file_readable'  => is_readable($localPath),
            'auth_token_set'       => !empty($authToken) && $authToken !== 'YOUR_EMSIGNER_AUTH_TOKEN',
            'auth_token_preview'   => $maskString($authToken),
            'app_name'             => constant('EMSIGNER_APP_NAME'),
            'secret_key_set'       => !empty($secretKey) && $secretKey !== 'YOUR_EMSIGNER_SECRET_KEY',
            'kyc_mode'             => constant('EMUDHRA_KYC_MODE'),
            'kyc_key_set'          => !empty($kycKey) && $kycKey !== 'YOUR_EMUDHRA_KYC_API_KEY' && $kycKey !== 'emudhra_kyc_api_key_demo',
            'is_live_configured'   => is_emsigner_live_configured(),
            'gateway_url'          => (constant('EMSIGNER_MODE') === 'production') ? constant('EMSIGNER_PROD_GATEWAY_URL') : constant('EMSIGNER_DEMO_GATEWAY_URL'),
            'api_url'              => (constant('EMSIGNER_MODE') === 'production') ? constant('EMSIGNER_PROD_API_URL') : constant('EMSIGNER_DEMO_API_URL')
        ];
    }
}
