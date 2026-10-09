<?php
/**
 * Approve Consent Request Action Handler (Prepares Consent & Redirects to Digital Signing)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security token invalid.');
        header("Location: " . BASE_URL . "patient/consent_requests.php");
        exit;
    }

    $pdo = get_db_connection();
    $userId = get_logged_user_id();
    $patientId = get_patient_profile_id($pdo);
    $consentId = (int)($_POST['consent_id'] ?? 0);
    $expiryDate = $_POST['expiry_date'] ?? date('Y-m-d H:i:s', strtotime('+30 days'));
    $patientNotes = trim($_POST['patient_notes'] ?? 'Reviewed consent terms. Proceeding to digital signature verification.');

    // Verify consent exists and belongs to patient
    $stmt = $pdo->prepare("SELECT cr.*, u.username, 
                                  CASE 
                                      WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                      WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                      ELSE u.username 
                                  END AS requester_display_name,
                                  hr.record_uid
                           FROM `consent_requests` cr
                           JOIN `users` u ON cr.requester_user_id = u.id
                           LEFT JOIN `doctors` d ON u.id = d.user_id
                           LEFT JOIN `hospitals` h ON u.id = h.user_id
                           LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                           WHERE cr.id = ? AND cr.patient_id = ? AND cr.status = 'Pending'");
    $stmt->execute([$consentId, $patientId]);
    $consent = $stmt->fetch();

    if ($consent) {
        // Update expiry date and notes
        $stmtUp = $pdo->prepare("UPDATE `consent_requests` SET `expiry_date` = ?, `patient_notes` = ?, `esign_status` = 'Pending' WHERE `id` = ?");
        $stmtUp->execute([$expiryDate, $patientNotes, $consentId]);

        // Redirect to digital signature consent step
        header("Location: " . BASE_URL . "patient/esign_consent.php?id=" . $consentId);
        exit;
    } else {
        set_flash('danger', 'Consent request not found or already processed.');
    }
}

header("Location: " . BASE_URL . "patient/consent_requests.php");
exit;
