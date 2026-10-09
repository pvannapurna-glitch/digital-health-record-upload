<?php
/**
 * Reject Consent Request Action Handler
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security verification failed.');
        header("Location: " . BASE_URL . "patient/consent_requests.php");
        exit;
    }

    $pdo = get_db_connection();
    $userId = get_logged_user_id();
    $patientId = get_patient_profile_id($pdo);
    $consentId = (int)($_POST['consent_id'] ?? 0);
    $patientNotes = trim($_POST['patient_notes'] ?? '');

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
                           WHERE cr.id = ? AND cr.patient_id = ? AND cr.status = 'Pending'");
    $stmt->execute([$consentId, $patientId]);
    $consent = $stmt->fetch();

    if ($consent) {
        $stmtReject = $pdo->prepare("UPDATE `consent_requests` 
                                     SET `status` = 'Rejected', `decision_date` = NOW(), `patient_notes` = ? 
                                     WHERE `id` = ?");
        $stmtReject->execute([$patientNotes, $consentId]);

        // Sync Registry
        sync_registry($pdo, 'CONSENT', $consent['consent_uid'], "Consent " . $consent['consent_uid'], $consentId, 'INACTIVE');

        // Audit Log
        log_audit($pdo, 'CONSENT_REJECTED', "Patient rejected consent {$consent['consent_uid']} requested by {$consent['requester_display_name']}. Reason: {$patientNotes}", $userId, 'patient');

        set_flash('info', "Consent request {$consent['consent_uid']} was declined.");
    } else {
        set_flash('danger', 'Consent request not found or already processed.');
    }
}

header("Location: " . BASE_URL . "patient/consent_requests.php");
exit;
