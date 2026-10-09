<?php
/**
 * View & Download DEPA Consent Agreement PDF
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/consent_pdf_generator.php';

$pdo = get_db_connection();
$userId = get_logged_user_id();
$userRole = get_logged_user_role();

if (!$userId) {
    die("Access Denied: Please log in to examine consent artifacts.");
}

$consentId = (int)($_GET['id'] ?? 0);

if ($consentId <= 0) {
    die("Invalid Consent ID requested.");
}

// Fetch consent details
$stmt = $pdo->prepare("SELECT cr.*, u.username AS requester_username,
                              CASE 
                                  WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                  WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                  ELSE u.username 
                              END AS requester_display_name,
                              d.license_number AS doc_license,
                              h.license_number AS hosp_license,
                              p.full_name AS patient_name, p.patient_uid, p.dob AS patient_dob, p.gender AS patient_gender, 
                              p.user_id AS patient_user_id, p.signature_image,
                              hr.title AS record_title, hr.record_uid, hr.record_type
                       FROM `consent_requests` cr
                       JOIN `users` u ON cr.requester_user_id = u.id
                       JOIN `patients` p ON cr.patient_id = p.id
                       LEFT JOIN `doctors` d ON u.id = d.user_id
                       LEFT JOIN `hospitals` h ON u.id = h.user_id
                       LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                       WHERE cr.id = ?");
$stmt->execute([$consentId]);
$consent = $stmt->fetch();

if (!$consent) {
    die("Consent record not found in system.");
}

// Access authorization check: Only Patient, Requester, or Admin can view
$isAuthorized = false;
if ($userRole === 'admin') {
    $isAuthorized = true;
} elseif ($userRole === 'patient' && (int)$consent['patient_user_id'] === (int)$userId) {
    $isAuthorized = true;
} elseif (($userRole === 'doctor' || $userRole === 'hospital') && (int)$consent['requester_user_id'] === (int)$userId) {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    log_audit($pdo, 'UNAUTHORIZED_CONSENT_PDF_VIEW', "User ID {$userId} attempted to view Consent PDF {$consent['consent_uid']} without authorization.", $userId, $userRole);
    die("Access Denied (403): You are not authorized to view this Consent Agreement PDF.");
}

$uploadDir = ROOT_DIR . 'uploads/consent_agreements/';
$pdfContent = null;
$pdfFilename = "consent_agreement_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $consent['consent_uid']) . ".pdf";

// If signed and file already exists on server, serve the exact final signed artifact
if (!empty($consent['signed_document_reference'])) {
    $existingPath = $uploadDir . basename($consent['signed_document_reference']);
    if (file_exists($existingPath)) {
        $pdfContent = file_get_contents($existingPath);
        $pdfFilename = basename($consent['signed_document_reference']);
    }
}

if ($pdfContent === null) {
    // Prepare patient data
    $patientData = [
        'full_name'   => $consent['patient_name'],
        'patient_uid' => $consent['patient_uid'],
        'dob'         => $consent['patient_dob'],
        'gender'      => $consent['patient_gender']
    ];

    // Prepare requester data
    $requesterData = [
        'display_name'   => $consent['requester_display_name'],
        'full_name'      => $consent['requester_display_name'],
        'license_number' => $consent['doc_license'] ?? ($consent['hosp_license'] ?? 'N/A')
    ];

    // Prepare record data
    $recordData = $consent['record_id'] ? [
        'title'       => $consent['record_title'],
        'record_uid'  => $consent['record_uid'],
        'record_type' => $consent['record_type']
    ] : null;

    $signaturePath = null;
    $signedAt = null;
    if ($consent['is_esigned'] == 1 && !empty($consent['signature_image'])) {
        $candidateSigPath = SIGNATURE_UPLOAD_DIR . basename($consent['signature_image']);
        if (file_exists($candidateSigPath)) {
            $signaturePath = $candidateSigPath;
            $signedAt = $consent['signed_at'] ?? $consent['decision_date'];
        }
    }

    // Prepare multi-party signature records for doctor and approver
    $doctorSig = null;
    if (!empty($consent['doctor_signed_at'])) {
        $docEsignStmt = $pdo->prepare("SELECT * FROM esign_records WHERE consent_id = ? AND signer_role = 'doctor' ORDER BY id DESC LIMIT 1");
        $docEsignStmt->execute([$consentId]);
        $docRec = $docEsignStmt->fetch();
        $doctorSig = [
            'is_signed'   => true,
            'signer_name' => $docRec['signer_name'] ?? $consent['requester_display_name'],
            'license'     => $consent['doc_license'] ?? 'N/A',
            'signed_at'   => $consent['doctor_signed_at'],
            'txn_id'      => $docRec['esign_transaction_id'] ?? ($consent['esign_transaction_id'] ?? 'EMSIG-DOC-TXN'),
            'sig_hash'    => $docRec['signature_hash'] ?? null
        ];
    }

    $approverSig = null;
    if (!empty($consent['approver_signed_at'])) {
        $appEsignStmt = $pdo->prepare("SELECT * FROM esign_records WHERE consent_id = ? AND signer_role IN ('approver', 'admin') ORDER BY id DESC LIMIT 1");
        $appEsignStmt->execute([$consentId]);
        $appRec = $appEsignStmt->fetch();
        $approverSig = [
            'is_signed'   => true,
            'signer_name' => $consent['approver_name'] ?? ($appRec['signer_name'] ?? (defined('DEFAULT_APPROVER_NAME') ? DEFAULT_APPROVER_NAME : 'Reviewing Authority')),
            'role'        => defined('DEFAULT_APPROVER_ROLE') ? DEFAULT_APPROVER_ROLE : 'Medical Superintendent',
            'signed_at'   => $consent['approver_signed_at'],
            'txn_id'      => $appRec['esign_transaction_id'] ?? 'EMSIG-APP-TXN',
            'sig_hash'    => $appRec['signature_hash'] ?? null
        ];
    }

    $pdfArtifact = ConsentPdfGenerator::generateConsentPdf(
        $consent, 
        $patientData, 
        $requesterData, 
        $recordData, 
        $signaturePath, 
        $signedAt,
        $doctorSig,
        $approverSig
    );
    $pdfContent = $pdfArtifact['pdf_content'];
    $pdfFilename = $pdfArtifact['filename'];
}

$isDownload = isset($_GET['download']) && $_GET['download'] === '1';
$disposition = $isDownload ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $pdfFilename . '"');
header('Content-Length: ' . strlen($pdfContent));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdfContent;
exit;
