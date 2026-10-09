<?php
/**
 * Instant Consent Revocation Handler
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security token invalid.');
        header("Location: " . BASE_URL . "patient/sharing_history.php");
        exit;
    }

    $pdo = get_db_connection();
    $userId = get_logged_user_id();
    $patientId = get_patient_profile_id($pdo);
    $consentId = (int)($_POST['consent_id'] ?? 0);

    // Fetch active approved consent
    $stmt = $pdo->prepare("SELECT cr.*, u.username, 
                                  CASE 
                                      WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                      WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                      ELSE u.username 
                                  END AS requester_display_name
                           FROM `consent_requests` cr
                           JOIN `users` u ON cr.requester_user_id = u.id
                           LEFT JOIN `doctors` d ON u.id = d.user_id
                           LEFT JOIN `hospitals` h ON u.id = h.user_id
                           WHERE cr.id = ? AND cr.patient_id = ? AND cr.status IN ('Approved', 'Active')");
    $stmt->execute([$consentId, $patientId]);
    $consent = $stmt->fetch();

    if ($consent) {
        // Instantly mark as Revoked and record revocation timestamp
        $stmtRevoke = $pdo->prepare("UPDATE `consent_requests` 
                                     SET `status` = 'Revoked', `revoked_date` = NOW() 
                                     WHERE `id` = ?");
        $stmtRevoke->execute([$consentId]);

        // Sync Central Registry to REVOKED
        sync_registry($pdo, 'CONSENT', $consent['consent_uid'], "Consent " . $consent['consent_uid'], $consentId, 'REVOKED');

        // Audit Log
        log_audit($pdo, 'CONSENT_REVOKED', "Patient REVOKED consent {$consent['consent_uid']} previously granted to {$consent['requester_display_name']}. Data access terminated immediately.", $userId, 'patient');

        set_flash('warning', "Consent {$consent['consent_uid']} has been REVOKED. {$consent['requester_display_name']} can NO LONGER view this health record.");
    } else {
        set_flash('danger', 'Active consent not found or already revoked/expired.');
    }
}

header("Location: " . BASE_URL . "patient/sharing_history.php");
exit;
