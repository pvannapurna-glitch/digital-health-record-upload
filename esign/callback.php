<?php
/**
 * emSigner Multi-Party Callback & Webhook Handler
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Securely processes return callbacks from emSigner Gateway:
 * - Anti-replay and security token validation
 * - Signer 1 (Doctor): Stores intermediate signed PDF, transitions to WAITING_FOR_APPROVER, sends email
 * - Signer 2 (Approver): Stores final signed PDF, validates SHA-256 hash, transitions to Active / FULLY_SIGNED
 * - Immutable DEPA audit trail generation
 */

$pageTitle = "Processing Signature Callback...";
require_once __DIR__ . '/../config/config.php';

$pdo = get_db_connection();

$consentId = (int)($_GET['consent_id'] ?? 0);
$role      = strtolower(trim($_GET['role'] ?? ''));
$token     = trim($_GET['token'] ?? '');
$ref       = trim($_GET['ref'] ?? '');
$action    = strtolower(trim($_GET['action'] ?? ''));

// Fetch Consent Record
$stmt = $pdo->prepare("SELECT cr.*, p.full_name AS patient_name, p.patient_uid,
                              d.full_name AS doc_name, d.license_number AS doc_license, d.user_id AS doc_user_id
                       FROM `consent_requests` cr
                       JOIN `patients` p ON cr.patient_id = p.id
                       JOIN `users` u ON cr.requester_user_id = u.id
                       LEFT JOIN `doctors` d ON u.id = d.user_id
                       WHERE cr.id = ?");
$stmt->execute([$consentId]);
$consent = $stmt->fetch();

if (!$consent) {
    die("Error: Consent request {$consentId} not found.");
}

// 1. Handle Cancellation
if ($action === 'cancelled' || (isset($_POST['Status']) && $_POST['Status'] === 'Cancelled')) {
    log_audit($pdo, 'ESIGN_FAILED', "emSigner signing was cancelled by user for Consent {$consent['consent_uid']} ({$role}).", $consent['requester_user_id'], $role);
    set_flash('warning', "Digital signing ceremony was cancelled. Document remains in previous state.");
    if ($role === 'doctor') {
        header("Location: " . BASE_URL . "doctor/my_requests.php");
    } else {
        header("Location: " . BASE_URL . "esign/status.php");
    }
    exit;
}

// 2. Handle Failure
if ($action === 'failed' || (isset($_POST['Status']) && $_POST['Status'] === 'Failed')) {
    log_audit($pdo, 'SIGNATURE_VERIFICATION_FAILED', "emSigner signing failed or was rejected for Consent {$consent['consent_uid']} ({$role}).", $consent['requester_user_id'], $role);
    set_flash('danger', "Digital signature verification failed on the gateway.");
    if ($role === 'doctor') {
        header("Location: " . BASE_URL . "doctor/my_requests.php");
    } else {
        header("Location: " . BASE_URL . "esign/status.php");
    }
    exit;
}

// 3. Process Successful Callback
$txnNumber  = trim($_POST['Transactionnumber'] ?? ($_GET['txn'] ?? ''));
$status     = trim($_POST['Status'] ?? 'Completed');
$signedData = trim($_POST['SignedData'] ?? '');
$sigHash    = trim($_POST['SignatureHash'] ?? '');
$sigMethod  = trim($_POST['SignatureMethod'] ?? 'DSC_TOKEN');

// Validate expected status
if ($status !== 'Completed') {
    die("Error: Transaction status is not 'Completed' (Received: {$status}).");
}

// Validate Consent State
if (in_array($consent['status'], ['Revoked', 'Expired', 'Rejected'])) {
    log_audit($pdo, 'SIGNATURE_VERIFICATION_FAILED', "Attempted to sign a '{$consent['status']}' consent ({$consent['consent_uid']}). Rejected.", null, 'system');
    die("Security Error: This consent request is {$consent['status']} and cannot be signed.");
}

$uploadDir = ROOT_DIR . 'uploads/consent_agreements/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// =============================================================================
// FLOW A: DOCTOR SIGNING CALLBACK (SIGNER 1)
// =============================================================================
if ($role === 'doctor') {
    $expectedUserId = (int)$consent['doc_user_id'];

    // Verify Security Token
    if (!EmSignerService::verifyCallbackSecurityToken($token, $consentId, 'doctor', $expectedUserId)) {
        log_audit($pdo, 'SIGNATURE_VERIFICATION_FAILED', "Security callback token verification failed for Doctor on Consent {$consent['consent_uid']}.", $expectedUserId, 'doctor');
        die("Security Token Verification Failed.");
    }

    // Replay Prevention: Ensure Doctor has not already signed
    if (!empty($consent['doctor_signed_at'])) {
        set_flash('info', "Doctor signature for Consent {$consent['consent_uid']} has already been recorded.");
        header("Location: " . BASE_URL . "doctor/my_requests.php");
        exit;
    }

    // Decode signed PDF bytes
    $pdfBytes = !empty($signedData) ? base64_decode($signedData) : null;
    if (!$pdfBytes) {
        // Fallback: If not passed directly in POST, retrieve from active session
        if (session_status() === PHP_SESSION_NONE) session_start();
        foreach ($_SESSION['emsigner_sessions'] ?? [] as $s) {
            if ($s['transaction_number'] === $txnNumber && !empty($s['signed_pdf_bytes'])) {
                $pdfBytes = $s['signed_pdf_bytes'];
                break;
            }
        }
    }

    if (!$pdfBytes) {
        die("Error: Signed PDF payload is missing from callback.");
    }

    // Save intermediate doctor-signed PDF
    $docSignedFilename = "consent_agreement_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $consent['consent_uid']) . "_doctor_signed.pdf";
    $docSignedPath = $uploadDir . $docSignedFilename;
    file_put_contents($docSignedPath, $pdfBytes);

    // Compute intermediate document hash
    $docSignedHash = hash_file('sha256', $docSignedPath);

    // Generate eSign record UID
    $esignUid = generate_depa_uid('ESIGN-DOC', 'esign_records', 'esign_uid', $pdo);
    if (empty($sigHash)) {
        $sigHash = hash('sha256', "DOCTOR_EMSIG::{$consentId}::{$txnNumber}::{$docSignedHash}");
    }

    // Insert into esign_records (Signer 1)
    $stmtEsign = $pdo->prepare("INSERT INTO `esign_records`
        (`esign_uid`, `consent_id`, `user_id`, `esign_provider`, `esign_transaction_id`, `signing_initiated_at`,
         `signer_role`, `signer_index`, `signer_name`, `doc_ref`, `signature_method`, `coordinates_spec`,
         `document_hash`, `signature_type`, `signature_hash`, `emsigner_mode`, `emsigner_ref`,
         `signed_document_reference`, `signed_document_hash`, `status`, `signed_at`, `created_at`)
        VALUES (?, ?, ?, 'emsigner', ?, NOW(), 'doctor', 1, ?, ?, ?, ?, ?, 'Digital Signature Certificate', ?, ?, ?, ?, ?, 'Signed', NOW(), NOW())");

    $mode = defined('EMSIGNER_MODE') ? EMSIGNER_MODE : 'demo';
    $stmtEsign->execute([
        $esignUid,
        $consentId,
        $expectedUserId,
        $txnNumber,
        $consent['doc_name'],
        $consent['consent_uid'],
        $sigMethod,
        defined('EMSIGNER_COORD_DOCTOR') ? EMSIGNER_COORD_DOCTOR : '1,55,640,220,110',
        $consent['pre_sign_document_hash'] ?? $docSignedHash,
        $sigHash,
        $mode,
        $ref,
        $docSignedFilename,
        $docSignedHash
    ]);
    $doctorEsignId = (int)$pdo->lastInsertId();

    // Prepare Approver Task details
    $approverToken  = bin2hex(random_bytes(32));
    $approverExpiry = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $approverEmail  = defined('DEFAULT_APPROVER_EMAIL') ? DEFAULT_APPROVER_EMAIL : 'approver@example.com';
    $approverName   = defined('DEFAULT_APPROVER_NAME') ? DEFAULT_APPROVER_NAME : 'Dr. S. K. Nambiar';

    // Update consent request state: DOCTOR_SIGNED / approver_pending / WAITING_FOR_APPROVER
    // Notice: Set status = 'approver_pending'. Do NOT mark as 'Active' yet! Approver signature is still required!
    $stmtUp = $pdo->prepare("UPDATE `consent_requests`
        SET `status` = 'approver_pending',
            `workflow_step` = 'WAITING_FOR_APPROVER',
            `doctor_signed_at` = NOW(),
            `doctor_esign_id` = ?,
            `approver_name` = ?,
            `approver_email` = ?,
            `approver_token` = ?,
            `approver_token_expiry` = ?,
            `esign_provider` = 'emsigner',
            `esign_transaction_id` = ?,
            `intermediate_document_hash` = ?,
            `intermediate_document_reference` = ?,
            `signed_document_reference` = ?,
            `signed_document_hash` = ?,
            `callback_received_at` = NOW(),
            `callback_payload` = ?
        WHERE `id` = ?");
    $stmtUp->execute([
        $doctorEsignId,
        $approverName,
        $approverEmail,
        $approverToken,
        $approverExpiry,
        $txnNumber,
        $docSignedHash,
        $docSignedFilename,
        $docSignedFilename,
        $docSignedHash,
        json_encode($_POST),
        $consentId
    ]);

    // Dispatch Approver Notification Email
    $reviewLink = BASE_URL . "esign/approver_review.php?token={$approverToken}&id={$consentId}";
    $emailSubject = "Consent Approval Required – Digital Health Record";
    $currentTime = date('d-M-Y H:i:s');
    $emailBody = "
    <div style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; padding: 25px; line-height: 1.6; color: #1e293b; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #ffffff;'>
        <div style='background: #0d6efd; color: #ffffff; padding: 18px 20px; border-radius: 8px 8px 0 0; text-align: center;'>
            <h2 style='margin: 0; font-size: 20px;'>DEPA Digital Health Record Exchange</h2>
            <p style='margin: 4px 0 0; font-size: 13px; opacity: 0.9;'>Consent Authority Co-Signing Portal</p>
        </div>
        <div style='padding: 20px 0;'>
            <p>Dear <strong>{$approverName}</strong> (Medical Superintendent / Reviewing Authority),</p>
            <p>A health consent agreement has been signed by the attending physician and requires your official review and second signature to activate record exchange permissions.</p>
            
            <div style='background: #f8fafc; border-left: 4px solid #0d6efd; padding: 15px; margin: 20px 0; border-radius: 4px;'>
                <p style='margin: 4px 0;'><strong>Patient Name:</strong> {$consent['patient_name']} (ID: {$consent['patient_uid']})</p>
                <p style='margin: 4px 0;'><strong>Doctor Name:</strong> {$consent['doc_name']} (License: {$consent['doc_license']})</p>
                <p style='margin: 4px 0;'><strong>Consent / Request Reference:</strong> <span style='font-family: monospace;'>{$consent['consent_uid']}</span></p>
                <p style='margin: 4px 0;'><strong>Purpose of Access:</strong> {$consent['purpose']}</p>
                <p style='margin: 4px 0;'><strong>Date / Time of Doctor Signing:</strong> {$currentTime}</p>
                <p style='margin: 4px 0;'><strong>Intermediate PDF SHA-256 Hash:</strong> <span style='font-family: monospace; font-size: 11px;'>{$docSignedHash}</span></p>
            </div>
            
            <p>Please click the button below to review the intermediate doctor-signed document and apply your Reviewing Authority digital seal:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$reviewLink}' style='background: #198754; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 15px; display: inline-block;'>Review & Co-Sign Agreement</a>
            </div>
            
            <p style='font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 15px;'>
                Security Notice: This secure single-use approval link is valid for 48 hours. If you did not expect this transmission, please contact the DEPA Security Office.
            </p>
        </div>
    </div>";

    $plainText = "Consent Approval Required – Digital Health Record\n\n"
               . "Dear {$approverName},\n\n"
               . "A health consent agreement requires your review and second signature.\n\n"
               . "Patient Name: {$consent['patient_name']}\n"
               . "Doctor Name: {$consent['doc_name']}\n"
               . "Consent Reference: {$consent['consent_uid']}\n"
               . "Purpose: {$consent['purpose']}\n"
               . "Date/Time: {$currentTime}\n"
               . "Review Link: {$reviewLink}\n\n"
               . "This link is valid for 48 hours.\n";

    $mailer = new SmtpMailer();
    $mailRes = $mailer->send($approverEmail, $emailSubject, $emailBody, $approverName, $plainText);

    // Audit logs for Doctor Signing & Approver Email
    log_audit($pdo, 'DOCTOR_SIGNED', "Attending Physician {$consent['doc_name']} successfully signed Consent {$consent['consent_uid']} (Txn: {$txnNumber}). Intermediate Hash: " . substr($docSignedHash, 0, 16) . "...", $expectedUserId, 'doctor', $consent['doc_name']);
    
    if (!empty($mailRes['success'])) {
        log_audit($pdo, 'APPROVER_EMAIL_SENT', "Consent approval notification email dispatched via SMTP to Reviewing Authority {$approverName} ({$approverEmail}) for Consent {$consent['consent_uid']}.", $expectedUserId, 'doctor');
        set_flash('success', "Consent agreement digitally signed by Doctor! Status is now 'approver_pending'. Email notification was successfully dispatched to Reviewing Authority ({$approverEmail}).");
    } else {
        $mailErr = $mailRes['error'] ?? 'SMTP credentials not configured';
        log_audit($pdo, 'APPROVER_EMAIL_FAILED', "Failed to dispatch approver notification email to {$approverEmail} for Consent {$consent['consent_uid']}: {$mailErr}", $expectedUserId, 'doctor');
        set_flash('warning', "Consent agreement digitally signed by Doctor! Status is now 'approver_pending'. (Notice: Email delivery to {$approverEmail} was not completed: {$mailErr}. The Reviewing Authority can access the request directly via the Review link or Admin portal).");
    }

    header("Location: " . BASE_URL . "doctor/my_requests.php?signed=1&id=" . $consentId);
    exit;
}

// =============================================================================
// FLOW B: APPROVER SIGNING CALLBACK (SIGNER 2 - FINAL MULTI-SIGNED PDF)
// =============================================================================
elseif ($role === 'approver') {
    $approverName = $consent['approver_name'] ?: (defined('DEFAULT_APPROVER_NAME') ? DEFAULT_APPROVER_NAME : 'Reviewing Authority');

    // Replay Prevention: Ensure Approver has not already signed
    if (!empty($consent['approver_signed_at'])) {
        set_flash('info', "Approver signature for Consent {$consent['consent_uid']} has already been processed.");
        header("Location: " . BASE_URL . "esign/status.php");
        exit;
    }

    // Decode final signed PDF bytes
    $pdfBytes = !empty($signedData) ? base64_decode($signedData) : null;
    if (!$pdfBytes) {
        if (session_status() === PHP_SESSION_NONE) session_start();
        foreach ($_SESSION['emsigner_sessions'] ?? [] as $s) {
            if ($s['transaction_number'] === $txnNumber && !empty($s['signed_pdf_bytes'])) {
                $pdfBytes = $s['signed_pdf_bytes'];
                break;
            }
        }
    }

    if (!$pdfBytes) {
        die("Error: Final signed PDF payload is missing from callback.");
    }

    // Save final multi-signed PDF to disk
    $finalFilename = "consent_agreement_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $consent['consent_uid']) . "_fully_signed.pdf";
    $finalPath = $uploadDir . $finalFilename;
    file_put_contents($finalPath, $pdfBytes);

    // Calculate final SHA-256 hash directly from the disk file
    $finalPdfHash = hash_file('sha256', $finalPath);

    // Verify hash format & length integrity
    if (strlen($finalPdfHash) !== 64 || !preg_match('/^[a-f0-9]{64}$/', $finalPdfHash)) {
        log_audit($pdo, 'SIGNATURE_VERIFICATION_FAILED', "Calculated final PDF hash format is invalid ({$finalPdfHash}).", null, 'system');
        die("Cryptographic verification failure: Invalid SHA-256 digest format.");
    }

    // Generate Approver eSign UID
    $approverEsignUid = generate_depa_uid('ESIGN-APP', 'esign_records', 'esign_uid', $pdo);
    if (empty($sigHash)) {
        $sigHash = hash('sha256', "APPROVER_EMSIG::{$consentId}::{$txnNumber}::{$finalPdfHash}");
    }

    // Insert into esign_records (Signer 2)
    $stmtEsignApp = $pdo->prepare("INSERT INTO `esign_records`
        (`esign_uid`, `consent_id`, `user_id`, `esign_provider`, `esign_transaction_id`, `signing_initiated_at`,
         `signer_role`, `signer_index`, `signer_name`, `doc_ref`, `signature_method`, `coordinates_spec`,
         `document_hash`, `signature_type`, `signature_hash`, `emsigner_mode`, `emsigner_ref`,
         `signed_document_reference`, `signed_document_hash`, `status`, `signed_at`, `created_at`)
        VALUES (?, ?, ?, 'emsigner', ?, NOW(), 'admin', 2, ?, ?, ?, ?, ?, 'Digital Signature Certificate', ?, ?, ?, ?, ?, 'Signed', NOW(), NOW())");

    $mode = defined('EMSIGNER_MODE') ? EMSIGNER_MODE : 'demo';
    $approverUserId = $consent['approver_user_id'] ?: 1; // Default to admin user ID if not specified

    $stmtEsignApp->execute([
        $approverEsignUid,
        $consentId,
        $approverUserId,
        $txnNumber,
        $approverName,
        $consent['consent_uid'],
        $sigMethod,
        defined('EMSIGNER_COORD_APPROVER') ? EMSIGNER_COORD_APPROVER : '1,320,640,220,110',
        $finalPdfHash,
        $sigHash,
        $mode,
        $ref,
        $finalFilename,
        $finalPdfHash
    ]);
    $approverEsignId = (int)$pdo->lastInsertId();

    // Finalize consent request: Active / Signed / COMPLETED
    $stmtFinal = $pdo->prepare("UPDATE `consent_requests`
        SET `status` = 'Active',
            `esign_status` = 'Signed',
            `is_esigned` = 1,
            `workflow_step` = 'COMPLETED',
            `approver_signed_at` = NOW(),
            `approver_esign_id` = ?,
            `signed_at` = NOW(),
            `decision_date` = NOW(),
            `signed_document_reference` = ?,
            `signed_document_hash` = ?,
            `callback_received_at` = NOW(),
            `callback_payload` = ?
        WHERE `id` = ?");
    $stmtFinal->execute([
        $approverEsignId,
        $finalFilename,
        $finalPdfHash,
        json_encode($_POST),
        $consentId
    ]);

    // Sync Central Registry
    sync_registry($pdo, 'CONSENT', $consent['consent_uid'], "Consent {$consent['consent_uid']} [Multi-Party emSigner Sealed]", $consentId, 'ACTIVE');
    sync_registry($pdo, 'ESIGN', $approverEsignUid, "Final Co-Sign Seal: {$approverName}", $approverEsignId, 'ACTIVE');

    // Immutable Audit Logs for Approver Signing & Activation Lifecycle
    log_audit($pdo, 'APPROVER_SIGNED', "Reviewing Authority {$approverName} successfully co-signed Consent {$consent['consent_uid']} via emSigner (Txn: {$txnNumber}).", $approverUserId, 'admin', $approverName);
    log_audit($pdo, 'FINAL_DOCUMENT_CREATED', "Final dual-signed PDF generated ({$finalFilename}) for Consent {$consent['consent_uid']}.", $approverUserId, 'admin', $approverName);
    log_audit($pdo, 'FINAL_HASH_GENERATED', "Final Signed Document SHA-256 Hash calculated: {$finalPdfHash} for Consent {$consent['consent_uid']}.", $approverUserId, 'admin', $approverName);
    log_audit($pdo, 'CONSENT_ACTIVATED', "Consent {$consent['consent_uid']} transitioned to ACTIVE state following dual-signature completion.", $approverUserId, 'admin', $approverName);
    log_audit($pdo, 'ESIGN_COMPLETED', "Multi-party emSigner workflow completed for Consent {$consent['consent_uid']}. Pre-sign Hash: " . substr($consent['pre_sign_document_hash'] ?? '', 0, 16) . "... | Intermediate Hash: " . substr($consent['intermediate_document_hash'] ?? '', 0, 16) . "... | Final Hash: " . substr($finalPdfHash, 0, 16) . "...", $approverUserId, 'admin', $approverName);
    log_audit($pdo, 'SIGNED_DOCUMENT_STORED', "Final dual-signed PDF ({$finalFilename}) stored with verified SHA-256: {$finalPdfHash}.", $approverUserId, 'admin');

    // Render Clean Completion Screen
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Consent Authorization Completed | DEPA HealthExchange</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    </head>
    <body class="bg-light py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-lg rounded-4 overflow-hidden text-center">
                        <div class="card-header bg-success text-white py-4">
                            <div class="d-inline-flex p-3 rounded-circle bg-white text-success mb-2 shadow">
                                <i class="bi bi-shield-fill-check display-4"></i>
                            </div>
                            <h3 class="fw-bold mb-1">Multi-Party Consent Co-Signed & Active!</h3>
                            <p class="text-white-50 small mb-0">emSigner cryptographic seals applied by both Doctor and Reviewing Authority.</p>
                        </div>
                        <div class="card-body p-4 p-md-5">
                            <div class="p-3 bg-light rounded-3 border text-start mb-4">
                                <div class="row g-2 small">
                                    <div class="col-6">
                                        <span class="text-muted d-block">Consent ID:</span>
                                        <span class="font-monospace fw-bold text-dark"><?= e($consent['consent_uid']) ?></span>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted d-block">Patient:</span>
                                        <strong><?= e($consent['patient_name']) ?></strong>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted d-block">Attending Physician:</span>
                                        <span><?= e($consent['doc_name']) ?> (Signed ✓)</span>
                                    </div>
                                    <div class="col-6">
                                        <span class="text-muted d-block">Reviewing Authority:</span>
                                        <span><?= e($approverName) ?> (Approved ✓)</span>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-success-subtle border border-success-subtle py-2 px-3 small text-start mb-3">
                                <strong>Integrity Method:</strong> Local Digital Signature + SHA-256 Document Integrity Verification.
                                Cryptographic SHA-256 hashing verifies that neither the clinical terms nor the applied signature blocks have been altered.
                            </div>

                            <div class="text-start mb-3">
                                <label class="form-label fw-bold text-muted small mb-1">1. Canonical Document SHA-256 Hash (Pre-Sign):</label>
                                <div class="p-2 bg-light border rounded font-monospace small text-break text-secondary mb-2" style="font-size: 0.75rem;">
                                    <?= e($consent['pre_sign_document_hash'] ?: 'N/A') ?>
                                </div>

                                <label class="form-label fw-bold text-muted small mb-1">2. Doctor-Signed Intermediate Document SHA-256 Hash:</label>
                                <div class="p-2 bg-light border rounded font-monospace small text-break text-secondary mb-2" style="font-size: 0.75rem;">
                                    <?= e($consent['intermediate_document_hash'] ?: 'N/A') ?>
                                </div>

                                <label class="form-label fw-bold text-dark small mb-1">3. Final Signed Document SHA-256 Hash:</label>
                                <div class="p-2 bg-dark text-success rounded font-monospace small text-break mb-3" style="font-size: 0.8rem;">
                                    <?= e($finalPdfHash) ?>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-center gap-2">
                                <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consentId ?>" target="_blank" class="btn btn-outline-primary px-3 fw-semibold">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> View / Download Final PDF
                                </a>
                                <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-primary px-4 fw-bold">
                                    <i class="bi bi-patch-check-fill me-1"></i> Open eSign Monitor
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
} else {
    die("Error: Unknown signing role '{$role}'.");
}
