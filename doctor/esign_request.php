<?php
/**
 * Doctor emSigner Initiation Handler
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Verifies Doctor authentication & eKYC, generates pre-signing canonical PDF,
 * computes pre-sign SHA-256 hash, creates emSigner transaction, and redirects.
 */

$pageTitle = "Initiating emSigner...";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// 1. Verify Doctor eKYC Status
$doctorKyc = DoctorEkycService::getDoctorKycStatus($pdo, $userId);
if (!$doctorKyc['is_verified']) {
    set_flash('warning', 'Medical Practitioner identity verification is required. Please complete your eMudhra Doctor eKYC before digitally signing.');
    header("Location: " . BASE_URL . "doctor/ekyc.php");
    exit;
}

$consentId = (int)($_GET['id'] ?? 0);

// 2. Fetch Consent Request
$stmt = $pdo->prepare("SELECT cr.*, p.full_name AS patient_name, p.patient_uid, p.dob, p.gender,
                              d.full_name AS doc_name, d.license_number AS doc_license, d.specialization,
                              hr.title AS record_title, hr.record_uid
                       FROM `consent_requests` cr
                       JOIN `patients` p ON cr.patient_id = p.id
                       JOIN `users` u ON cr.requester_user_id = u.id
                       LEFT JOIN `doctors` d ON u.id = d.user_id
                       LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                       WHERE cr.id = ? AND cr.requester_user_id = ?");
$stmt->execute([$consentId, $userId]);
$consent = $stmt->fetch();

if (!$consent) {
    set_flash('danger', 'Consent request not found or does not belong to your account.');
    header("Location: " . BASE_URL . "doctor/my_requests.php");
    exit;
}

// 3. Verify Consent State
if (in_array($consent['status'], ['Revoked', 'Expired', 'Rejected'])) {
    set_flash('danger', "Consent is in '{$consent['status']}' state and cannot be signed.");
    header("Location: " . BASE_URL . "doctor/my_requests.php");
    exit;
}

if (!empty($consent['doctor_signed_at'])) {
    set_flash('info', 'You have already signed this consent agreement with emSigner.');
    header("Location: " . BASE_URL . "doctor/my_requests.php");
    exit;
}

// 4. Generate/Retrieve Canonical Pre-Signing PDF
$patientData = [
    'full_name'   => $consent['patient_name'],
    'patient_uid' => $consent['patient_uid'],
    'dob'         => $consent['dob'],
    'gender'      => $consent['gender']
];
$requesterData = [
    'display_name'   => $consent['doc_name'],
    'license_number' => $consent['doc_license']
];
$recordData = $consent['record_id'] ? [
    'title'      => $consent['record_title'],
    'record_uid' => $consent['record_uid']
] : null;

$canonicalPdfArtifact = ConsentPdfGenerator::generateConsentPdf($consent, $patientData, $requesterData, $recordData);
$preSignHash = $canonicalPdfArtifact['document_hash'];

// 5. Store Pre-Signing Hash & Workflow Step
$stmtUp = $pdo->prepare("UPDATE `consent_requests` 
                         SET `pre_sign_document_hash` = ?, 
                             `esign_provider` = 'emsigner',
                             `signing_initiated_at` = NOW(),
                             `workflow_step` = 'DOCTOR_SIGN'
                         WHERE `id` = ?");
$stmtUp->execute([$preSignHash, $consentId]);

// Audit: Doctor reviewed terms
log_audit($pdo, 'DOCTOR_REVIEWED', "Doctor {$consent['doc_name']} reviewed terms for Consent {$consent['consent_uid']} (Pre-sign Hash: " . substr($preSignHash, 0, 16) . "...).", $userId, 'doctor', $consent['doc_name']);

try {
    // 6. Create emSigner Signing Transaction (Signer 1: Doctor)
    $txn = EmSignerService::createSigningTransaction(
        'doctor',
        $consent['doc_name'],
        $consent,
        $userId,
        $canonicalPdfArtifact['pdf_content'],
        1 // Signer Index 1
    );

    // 7. Audit Log Event
    log_audit($pdo, 'ESIGN_INITIATED', "Doctor {$consent['doc_name']} initiated emSigner transaction ({$txn['transaction_number']}) for Consent {$consent['consent_uid']}. Pre-sign Hash: " . substr($preSignHash, 0, 16) . "...", $userId, 'doctor', $consent['doc_name']);

    // 8. Redirect to emSigner Signing Portal / Gateway
    header("Location: " . $txn['redirect_url']);
    exit;
} catch (Exception $e) {
    log_audit($pdo, 'ESIGN_FAILED', "Failed to initiate emSigner transaction for Consent {$consent['consent_uid']}: " . $e->getMessage(), $userId, 'doctor', $consent['doc_name']);
    set_flash('danger', $e->getMessage());
    header("Location: " . BASE_URL . "doctor/my_requests.php");
    exit;
}
