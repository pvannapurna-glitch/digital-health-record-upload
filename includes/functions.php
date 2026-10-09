<?php
/**
 * Core Helper Functions, DEPA Engine, SSO, eKYC, and eSign Handlers
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

/**
 * Sanitize and escape string for HTML output
 */
function e($string) {
    return htmlspecialchars((string)($string ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate unique DEPA identifiers
 */
function generate_depa_uid($prefix, $table, $column, $pdo) {
    do {
        $randomNumber = mt_rand(1000, 9999);
        $uid = strtoupper($prefix . '-' . $randomNumber);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
        $stmt->execute([$uid]);
        $exists = $stmt->fetchColumn();
    } while ($exists > 0);

    return $uid;
}

/**
 * Log System Activity to Immutable Audit Trail
 */
function log_audit($pdo, $action, $details, $userId = null, $userRole = null, $userName = null) {
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = $_SESSION['user_id'];
        $userRole = $_SESSION['user_role'] ?? 'guest';
        $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO `audit_logs` (`user_id`, `user_role`, `user_name`, `action`, `details`, `ip_address`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $userRole, $userName, $action, $details, $ip]);
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

/**
 * Sync / Register Entity into DEPA Central Registry
 */
function sync_registry($pdo, $entityType, $entityUid, $entityName, $referenceId, $status = 'ACTIVE') {
    try {
        $stmt = $pdo->prepare("INSERT INTO `registry` (`entity_type`, `entity_uid`, `entity_name`, `reference_id`, `status`, `registered_at`) 
                               VALUES (?, ?, ?, ?, ?, NOW()) 
                               ON DUPLICATE KEY UPDATE `entity_name` = VALUES(`entity_name`), `status` = VALUES(`status`)");
        $stmt->execute([$entityType, $entityUid, $entityName, $referenceId, $status]);
    } catch (Exception $e) {
        error_log("Registry sync error: " . $e->getMessage());
    }
}

/**
 * Automatically verify and transition expired consents
 */
function auto_expire_consents($pdo) {
    try {
        // Find active/approved consents whose expiry date has passed
        $stmt = $pdo->query("SELECT id, consent_uid, patient_id, requester_user_id FROM `consent_requests` WHERE `status` IN ('Approved', 'Active') AND `expiry_date` < NOW()");
        $expiredConsents = $stmt->fetchAll();

        if (!empty($expiredConsents)) {
            $updateStmt = $pdo->prepare("UPDATE `consent_requests` SET `status` = 'Expired' WHERE `id` = ?");
            foreach ($expiredConsents as $c) {
                $updateStmt->execute([$c['id']]);
                sync_registry($pdo, 'CONSENT', $c['consent_uid'], "Consent " . $c['consent_uid'], $c['id'], 'EXPIRED');
                log_audit($pdo, 'CONSENT_EXPIRED', "Consent {$c['consent_uid']} automatically marked Expired as validity period ended.", $c['requester_user_id'], 'system', 'DEPA Policy Engine');
            }
        }
    } catch (Exception $e) {
        error_log("Auto expire error: " . $e->getMessage());
    }
}

/**
 * DEPA Consent Authorization Engine:
 * Validates whether a requester has active, non-revoked, non-expired, and eSigned patient consent
 */
function validate_depa_consent($pdo, $requesterUserId, $patientId, $recordId = null) {
    // 1. Run automatic expiry check first
    auto_expire_consents($pdo);

    // 2. Query active approved & eSigned consent
    $query = "SELECT cr.*, p.full_name AS patient_name, p.patient_uid, hr.title AS record_title 
              FROM `consent_requests` cr
              JOIN `patients` p ON cr.patient_id = p.id
              LEFT JOIN `health_records` hr ON cr.record_id = hr.id
              WHERE cr.requester_user_id = ? 
                AND cr.patient_id = ? 
                AND cr.status IN ('Approved', 'Active') 
                AND cr.is_esigned = 1
                AND cr.esign_status = 'Signed'
                AND cr.expiry_date >= NOW()
                AND (cr.access_start_date IS NULL OR cr.access_start_date <= NOW())";

    $params = [$requesterUserId, $patientId];

    if ($recordId !== null) {
        $query .= " AND (cr.record_id = ? OR cr.record_id IS NULL)";
        $params[] = $recordId;
    }

    $query .= " ORDER BY cr.id DESC LIMIT 1";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $consent = $stmt->fetch();

    if ($consent) {
        return [
            'allowed' => true,
            'consent' => $consent,
            'reason'  => 'Valid active eSigned consent grant found (' . $consent['consent_uid'] . ')'
        ];
    }

    // Check if there is any revoked or expired consent to give a precise explanation
    $stmtCheck = $pdo->prepare("SELECT cr.* FROM `consent_requests` cr WHERE cr.requester_user_id = ? AND cr.patient_id = ? ORDER BY cr.id DESC LIMIT 1");
    $stmtCheck->execute([$requesterUserId, $patientId]);
    $lastRequest = $stmtCheck->fetch();

    if ($lastRequest) {
        if ($lastRequest['status'] === 'Revoked') {
            return ['allowed' => false, 'consent' => $lastRequest, 'reason' => 'Consent ' . $lastRequest['consent_uid'] . ' was explicitly REVOKED by the patient.'];
        } elseif ($lastRequest['status'] === 'Expired' || strtotime($lastRequest['expiry_date']) < time()) {
            return ['allowed' => false, 'consent' => $lastRequest, 'reason' => 'Consent ' . $lastRequest['consent_uid'] . ' has EXPIRED on ' . date('d M Y, h:i A', strtotime($lastRequest['expiry_date']))];
        } elseif ($lastRequest['status'] === 'Rejected') {
            return ['allowed' => false, 'consent' => $lastRequest, 'reason' => 'Consent request was REJECTED by the patient.'];
        } elseif ($lastRequest['status'] === 'Pending') {
            return ['allowed' => false, 'consent' => $lastRequest, 'reason' => 'Consent request is currently PENDING digital signature from the patient.'];
        } elseif ($lastRequest['is_esigned'] == 0 || $lastRequest['esign_status'] !== 'Signed') {
            return ['allowed' => false, 'consent' => $lastRequest, 'reason' => 'Consent request requires electronic signature (eSign) confirmation to become active.'];
        } elseif (!empty($lastRequest['access_start_date']) && strtotime($lastRequest['access_start_date']) > time()) {
            return ['allowed' => false, 'consent' => $lastRequest, 'reason' => 'Consent access start date has not arrived yet (' . date('d M Y, h:i A', strtotime($lastRequest['access_start_date'])) . ').'];
        }
    }

    return [
        'allowed' => false,
        'consent' => null,
        'reason'  => 'No active DEPA consent artifact found authorizing access to this health data.'
    ];
}

/* ==========================================================================
   SSO (SINGLE SIGN-ON) ENGINE FUNCTIONS
   ========================================================================== */

/**
 * Initialize and register an active Single Sign-On Session
 */
function create_sso_session($pdo, $userId, $userRole) {
    $token = bin2hex(random_bytes(32));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Browser', 0, 255);

    try {
        // Invalidate old sessions for this user if any
        $pdo->prepare("UPDATE `sso_sessions` SET `is_active` = 0 WHERE `user_id` = ?")->execute([$userId]);

        // Insert new active SSO session
        $stmt = $pdo->prepare("INSERT INTO `sso_sessions` (`session_token`, `user_id`, `user_role`, `ip_address`, `user_agent`, `login_time`, `last_activity`, `is_active`) 
                               VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1)");
        $stmt->execute([$token, $userId, $userRole, $ip, $agent]);

        $_SESSION['sso_token'] = $token;
        $_SESSION['sso_login_time'] = date('Y-m-d H:i:s');

        log_audit($pdo, 'SSO_LOGIN', "SSO authenticated session initialized for role '{$userRole}' (Token: " . substr($token, 0, 10) . "...)", $userId, $userRole);

        return $token;
    } catch (Exception $e) {
        error_log("SSO creation error: " . $e->getMessage());
        return null;
    }
}

/**
 * Invalidate SSO Session on Logout
 */
function destroy_sso_session($pdo, $token = null) {
    $token = $token ?: ($_SESSION['sso_token'] ?? null);
    if ($token) {
        try {
            $pdo->prepare("UPDATE `sso_sessions` SET `is_active` = 0 WHERE `session_token` = ?")->execute([$token]);
            log_audit($pdo, 'SSO_LOGOUT', "SSO session invalidated gracefully (Token: " . substr($token, 0, 10) . "...)");
        } catch (Exception $e) {
            error_log("SSO destroy error: " . $e->getMessage());
        }
    }
}

/**
 * Check if SSO session is currently valid
 */
function get_active_sso_session($pdo, $token) {
    if (empty($token)) return null;
    $stmt = $pdo->prepare("SELECT * FROM `sso_sessions` WHERE `session_token` = ? AND `is_active` = 1");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/* ==========================================================================
   eKYC VERIFICATION ENGINE FUNCTIONS (SAFE SANDBOX / DEMO)
   ========================================================================== */

/**
 * Retrieve user's eKYC status and records
 */
function get_user_ekyc_info($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT u.ekyc_status, k.* 
                           FROM `users` u 
                           LEFT JOIN `ekyc_verifications` k ON u.id = k.user_id 
                           WHERE u.id = ? 
                           ORDER BY k.id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $res = $stmt->fetch();
    if (!$res) {
        return ['ekyc_status' => 'Pending', 'status' => 'Pending', 'ekyc_uid' => null];
    }
    return $res;
}

/**
 * Boolean check for eKYC verification
 */
function is_user_ekyc_verified($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT `ekyc_status` FROM `users` WHERE `id` = ?");
    $stmt->execute([$userId]);
    return ($stmt->fetchColumn() === 'Verified');
}

/**
 * Complete sandbox eKYC verification
 */
function complete_sandbox_ekyc($pdo, $userId, $docType, $docNumberMasked, $fullName, $dob, $gender, $notes = 'Sandbox OTP Identity Match') {
    $ekycUid = generate_depa_uid('KYC-SANDBOX', 'ekyc_verifications', 'ekyc_uid', $pdo);

    try {
        $stmt = $pdo->prepare("INSERT INTO `ekyc_verifications` (`user_id`, `ekyc_uid`, `doc_type`, `doc_number_masked`, `full_name`, `dob`, `gender`, `otp_verified`, `status`, `verification_notes`, `verified_at`, `created_at`) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'Verified', ?, NOW(), NOW())");
        $stmt->execute([$userId, $ekycUid, $docType, $docNumberMasked, $fullName, $dob, $gender, $notes]);

        // Update user status
        $pdo->prepare("UPDATE `users` SET `ekyc_status` = 'Verified' WHERE `id` = ?")->execute([$userId]);

        log_audit($pdo, 'EKYC_VERIFIED', "Completed sandbox eKYC verification ({$ekycUid}) for {$fullName} using {$docType}.", $userId);

        return ['success' => true, 'ekyc_uid' => $ekycUid];
    } catch (Exception $e) {
        error_log("eKYC Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/* ==========================================================================
   eSIGN (ELECTRONIC SIGNATURE) ENGINE FUNCTIONS
   ========================================================================== */

/**
 * Create an eSign Record for a DEPA Consent Approval
 */
function create_esign_record($pdo, $consentId, $userId, $signerRole, $signerName, $docRef, $signatureType = 'Digital Signature Certificate') {
    $esignUid = generate_depa_uid('ESIGN', 'esign_records', 'esign_uid', $pdo);
    
    // Generate cryptographic SHA-256 digital signature hash
    $rawPayload = "ESIGN::{$esignUid}::CONSENT_{$consentId}::USER_{$userId}::{$signerName}::" . microtime(true);
    $signatureHash = hash('sha256', $rawPayload);
    $docHash = hash('sha256', "DOC-SUMMARY-{$consentId}-{$docRef}");

    try {
        $stmt = $pdo->prepare("INSERT INTO `esign_records` (`esign_uid`, `consent_id`, `user_id`, `esign_provider`, `signer_role`, `signer_name`, `doc_ref`, `signature_method`, `document_hash`, `signature_type`, `signature_hash`, `status`, `signed_at`, `created_at`) 
                               VALUES (?, ?, ?, 'local_signature', ?, ?, ?, 'LOCAL_SIGNATURE_IMAGE', ?, ?, ?, 'Signed', NOW(), NOW())");
        $stmt->execute([$esignUid, $consentId, $userId, $signerRole, $signerName, $docRef, $docHash, $signatureType, $signatureHash]);
        $esignId = (int)$pdo->lastInsertId();

        // Update consent request
        $pdo->prepare("UPDATE `consent_requests` SET `is_esigned` = 1, `esign_status` = 'Signed', `esign_provider` = 'local_signature', `esign_id` = ?, `status` = 'Active', `decision_date` = NOW() WHERE `id` = ?")
            ->execute([$esignId, $consentId]);

        // Sync to registry
        sync_registry($pdo, 'ESIGN', $esignUid, "eSign Artifact: {$signerName} ({$docRef})", $esignId, 'ACTIVE');

        log_audit($pdo, 'ESIGN_COMPLETED', "DEPA Electronic Consent digitally signed ({$esignUid}) by {$signerName} via Local Digital Signature. Signature Hash: " . substr($signatureHash, 0, 16) . "...", $userId, $signerRole, $signerName);

        return [
            'success'   => true,
            'esign_id'  => $esignId,
            'esign_uid' => $esignUid,
            'hash'      => $signatureHash
        ];
    } catch (Exception $e) {
        error_log("eSign Error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Retrieve eSign Details for a Consent
 */
function get_esign_for_consent($pdo, $consentId) {
    $stmt = $pdo->prepare("SELECT * FROM `esign_records` WHERE `consent_id` = ? ORDER BY `id` DESC LIMIT 1");
    $stmt->execute([$consentId]);
    return $stmt->fetch();
}

/* ==========================================================================
   GENERAL SECURITY & UI HELPERS
   ========================================================================== */

/**
 * Generate and retrieve CSRF Token
 */
function get_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

/**
 * Set Session Flash Notification
 */
function set_flash($type, $message) {
    $_SESSION['flash_message'] = [
        'type'    => $type, // 'success', 'danger', 'warning', 'info'
        'message' => $message
    ];
}

/**
 * Render Flash Notification HTML
 */
function render_flash() {
    if (isset($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
        $type = e($flash['type']);
        $icon = ($type === 'success') ? 'bi-check-circle-fill' : (($type === 'danger') ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill');
        return "
        <div class='alert alert-{$type} alert-dismissible fade show d-flex align-items-center shadow-sm mb-4' role='alert'>
            <i class='bi {$icon} me-2 fs-5'></i>
            <div>" . e($flash['message']) . "</div>
            <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
        </div>";
    }
    return '';
}

/**
 * Format Status as HTML Badge with icons
 */
function render_status_badge($status) {
    $status = trim((string)$status);
    $statusLower = strtolower($status);
    switch ($statusLower) {
        case 'approved':
        case 'active':
            return '<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-shield-check me-1"></i>' . ($statusLower === 'active' ? 'Active' : 'Approved') . '</span>';
        case 'approver_pending':
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>Awaiting Approver</span>';
        case 'doctor_signed':
            return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle px-2 py-1"><i class="bi bi-pen me-1"></i>Doctor Signed</span>';
        case 'approver_signed':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-patch-check me-1"></i>Approver Signed</span>';
        case 'verified':
        case 'signed':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-patch-check-fill me-1"></i>' . e($status) . '</span>';
        case 'pending':
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
        case 'rejected':
        case 'failed':
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="bi bi-x-circle me-1"></i>' . e($status) . '</span>';
        case 'revoked':
            return '<span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-shield-slash me-1"></i>Revoked</span>';
        case 'expired':
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="bi bi-clock-history me-1"></i>Expired</span>';
        case 'not started':
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="bi bi-dash-circle me-1"></i>Not Started</span>';
        case 'inactive':
            return '<span class="badge bg-secondary text-white px-2 py-1"><i class="bi bi-dash-circle me-1"></i>Inactive</span>';
        default:
            return '<span class="badge bg-light text-dark border px-2 py-1">' . e($status) . '</span>';
    }
}

/**
 * Format eSign Status as HTML Badge with icons
 */
function render_esign_status_badge($status) {
    $status = trim((string)$status);
    $statusLower = strtolower($status);
    switch ($statusLower) {
        case 'signed':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1"><i class="bi bi-patch-check-fill me-1"></i>Signed</span>';
        case 'pending':
            return '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-2 py-1"><i class="bi bi-hourglass-split me-1"></i>Pending</span>';
        case 'failed':
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"><i class="bi bi-x-circle me-1"></i>Failed</span>';
        case 'cancelled':
            return '<span class="badge bg-secondary text-white px-2 py-1"><i class="bi bi-slash-circle me-1"></i>Cancelled</span>';
        case 'not started':
        case '':
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-2 py-1"><i class="bi bi-dash-circle me-1"></i>Not Started</span>';
        default:
            return render_status_badge($status);
    }
}

/**
 * Format Date cleanly
 */
function format_date($datetime, $format = 'd M Y, h:i A') {
    if (!$datetime || $datetime === '0000-00-00 00:00:00') {
        return '<span class="text-muted fst-italic">N/A</span>';
    }
    return date($format, strtotime($datetime));
}
