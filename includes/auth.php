<?php
/**
 * Single Sign-On (SSO) Authentication & Role-Based Access Control Middleware
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function get_user_role() {
    return $_SESSION['user_role'] ?? null;
}

function get_logged_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function get_patient_profile_id($pdo, $userId = null) {
    if ($userId === null) {
        $userId = get_logged_user_id();
    }
    if (!$userId) return null;

    $stmt = $pdo->prepare("SELECT id FROM `patients` WHERE `user_id` = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function get_doctor_profile_id($pdo, $userId = null) {
    if ($userId === null) {
        $userId = get_logged_user_id();
    }
    if (!$userId) return null;

    $stmt = $pdo->prepare("SELECT id FROM `doctors` WHERE `user_id` = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function get_hospital_profile_id($pdo, $userId = null) {
    if ($userId === null) {
        $userId = get_logged_user_id();
    }
    if (!$userId) return null;

    $stmt = $pdo->prepare("SELECT id FROM `hospitals` WHERE `user_id` = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchColumn() ?: null;
}

function require_login($redirectUrl = null) {
    if (!is_logged_in()) {
        if ($redirectUrl === null) {
            $redirectUrl = BASE_URL . 'login.php';
        }
        set_flash('warning', 'Please sign in via Single Sign-On (SSO) to access that protected section.');
        header("Location: " . $redirectUrl);
        exit;
    }
}

/**
 * Enforce Role-Based Access Control (RBAC)
 */
function require_role($allowedRoles) {
    require_login();

    if (!is_array($allowedRoles)) {
        $allowedRoles = [$allowedRoles];
    }

    $currentRole = get_user_role();
    if (!in_array($currentRole, $allowedRoles, true)) {
        set_flash('danger', 'Access Denied (403): You do not possess authorization to view that portal.');
        
        // Redirect to their respective authorized dashboard
        switch ($currentRole) {
            case 'patient':
                header("Location: " . BASE_URL . "patient/dashboard.php");
                break;
            case 'doctor':
                header("Location: " . BASE_URL . "doctor/dashboard.php");
                break;
            case 'hospital':
                header("Location: " . BASE_URL . "hospital/dashboard.php");
                break;
            case 'admin':
                header("Location: " . BASE_URL . "admin/dashboard.php");
                break;
            default:
                header("Location: " . BASE_URL . "login.php");
                break;
        }
        exit;
    }
}

/**
 * Enforce eKYC Verification before sensitive actions
 */
function require_ekyc_verified($pdo) {
    require_login();
    $userId = get_logged_user_id();
    if (!is_user_ekyc_verified($pdo, $userId) && get_user_role() !== 'admin') {
        set_flash('warning', 'eKYC Verification Required: You must complete your sandbox identity verification before accessing protected health data exchange features.');
        header("Location: " . BASE_URL . "ekyc/verify.php");
        exit;
    }
}

function redirect_if_logged_in() {
    if (is_logged_in()) {
        $role = get_user_role();
        header("Location: " . get_dashboard_url_for_role($role));
        exit;
    }
}

/**
 * Return authorized dashboard URL based on role
 */
function get_dashboard_url_for_role($role) {
    switch ($role) {
        case 'patient':
            return BASE_URL . "patient/dashboard.php";
        case 'doctor':
            return BASE_URL . "doctor/dashboard.php";
        case 'hospital':
            return BASE_URL . "hospital/dashboard.php";
        case 'admin':
            return BASE_URL . "admin/dashboard.php";
        default:
            return BASE_URL;
    }
}

/**
 * Complete and initialize user session across all authentication types
 * Regenerates session ID, sets standard session variables, creates SSO active token,
 * and fetches role-specific profiles.
 *
 * @param PDO $pdo
 * @param array $user
 * @param string $authMethod 'password' or 'google'
 * @return string Redirect URL to role dashboard
 */
function complete_login_session($pdo, array $user, $authMethod = 'password') {
    // Regenerate session ID to prevent session fixation attacks
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    $userId = (int)$user['id'];
    $userRole = (string)$user['role'];
    $username = (string)$user['username'];
    $userEmail = (string)$user['email'];
    $ekycStatus = (string)($user['ekyc_status'] ?? 'Pending');

    // Base session variables
    $_SESSION['user_id']    = $userId;
    $_SESSION['username']   = $username;
    $_SESSION['user_email'] = $userEmail;
    $_SESSION['user_role']  = $userRole;
    $_SESSION['ekyc_status']= $ekycStatus;
    $_SESSION['auth_method']= $authMethod;

    // Standard session aliases for compatibility
    $_SESSION['email']      = $userEmail;
    $_SESSION['role']       = $userRole;
    $_SESSION['logged_in']  = true;

    if (!empty($user['google_picture'])) {
        $_SESSION['google_picture'] = $user['google_picture'];
    }

    // Role-specific demographic details
    $profileName = $username;
    if ($userRole === 'patient') {
        $stmtP = $pdo->prepare("SELECT full_name, patient_uid FROM `patients` WHERE `user_id` = ?");
        $stmtP->execute([$userId]);
        $pat = $stmtP->fetch();
        if ($pat) {
            $profileName = $pat['full_name'];
            $_SESSION['patient_uid'] = $pat['patient_uid'];
        }
    } elseif ($userRole === 'doctor') {
        $stmtD = $pdo->prepare("SELECT full_name, doctor_uid FROM `doctors` WHERE `user_id` = ?");
        $stmtD->execute([$userId]);
        $doc = $stmtD->fetch();
        if ($doc) {
            $profileName = $doc['full_name'];
            $_SESSION['doctor_uid'] = $doc['doctor_uid'];
        }
    } elseif ($userRole === 'hospital') {
        $stmtH = $pdo->prepare("SELECT hospital_name, hospital_uid FROM `hospitals` WHERE `user_id` = ?");
        $stmtH->execute([$userId]);
        $hosp = $stmtH->fetch();
        if ($hosp) {
            $profileName = $hosp['hospital_name'];
            $_SESSION['hospital_uid'] = $hosp['hospital_uid'];
        }
    } elseif ($userRole === 'admin') {
        $profileName = "System Administrator";
    }

    $_SESSION['user_name'] = $profileName;
    $_SESSION['name']      = $profileName;

    // Create SSO session token in sso_sessions table
    create_sso_session($pdo, $userId, $userRole);

    return get_dashboard_url_for_role($userRole);
}
