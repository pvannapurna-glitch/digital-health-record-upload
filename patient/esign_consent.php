<?php
/**
 * DEPA Consent Authorization & Local Digital Signature Signing
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Consent Authorization";
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/consent_pdf_generator.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Fetch patient profile
$stmtP = $pdo->prepare("SELECT id, user_id, full_name, patient_uid, dob, gender, blood_group, phone, signature_image, signature_updated_at FROM `patients` WHERE `user_id` = ?");
$stmtP->execute([$userId]);
$patient = $stmtP->fetch();

if (!$patient) {
    set_flash('danger', 'Patient profile not found.');
    header("Location: " . BASE_URL . "patient/dashboard.php");
    exit;
}

$hasSavedSignature = !empty($patient['signature_image']) && file_exists(SIGNATURE_UPLOAD_DIR . basename($patient['signature_image']));

$consentId = (int)($_GET['id'] ?? 0);

// Fetch consent request details
$stmtC = $pdo->prepare("SELECT cr.*, u.username AS requester_username,
                               CASE 
                                   WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                   WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                   ELSE u.username 
                               END AS requester_display_name,
                               d.specialization, d.license_number AS doc_license, d.hospital_affiliation,
                               h.license_number AS hosp_license, h.contact_person,
                               hr.title AS record_title, hr.record_uid, hr.record_type, hr.diagnosis, hr.record_date,
                               es.esign_uid, es.signature_hash, es.signature_method, es.signed_at AS esign_signed_at
                        FROM `consent_requests` cr
                        JOIN `users` u ON cr.requester_user_id = u.id
                        LEFT JOIN `doctors` d ON u.id = d.user_id
                        LEFT JOIN `hospitals` h ON u.id = h.user_id
                        LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                        LEFT JOIN `esign_records` es ON cr.id = es.consent_id
                        WHERE cr.id = ? AND cr.patient_id = ?");
$stmtC->execute([$consentId, $patient['id']]);
$consent = $stmtC->fetch();

if (!$consent) {
    set_flash('danger', 'Consent request not found or does not belong to your health vault.');
    header("Location: " . BASE_URL . "patient/consent_requests.php");
    exit;
}

// Check state
$isSigned = ($consent['esign_status'] === 'Signed' && in_array($consent['status'], ['Approved', 'Active']));
$isBlocked = in_array($consent['status'], ['Revoked', 'Expired', 'Rejected']);

// Format requester data
$requesterData = [
    'display_name'   => $consent['requester_display_name'],
    'full_name'      => $consent['requester_display_name'],
    'license_number' => $consent['doc_license'] ?? ($consent['hosp_license'] ?? 'N/A')
];

// Record data
$recordData = $consent['record_id'] ? [
    'title'       => $consent['record_title'],
    'record_uid'  => $consent['record_uid'],
    'record_type' => $consent['record_type']
] : null;

// Generate preview PDF & canonical digest
$previewPdfArtifact = ConsentPdfGenerator::generateConsentPdf($consent, $patient, $requesterData, $recordData);

$error = '';

// Handle Digital Signing Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_digitally') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security CSRF token verification failed. Please refresh and try again.";
    } elseif ($isBlocked) {
        $error = "This consent request is in '{$consent['status']}' state and cannot be signed.";
    } elseif ($isSigned) {
        $error = "This consent request is already signed.";
    } elseif (!$hasSavedSignature) {
        $error = "Please upload your signature in your Patient Profile before signing this consent.";
    } elseif (empty($_POST['confirm_agreement'])) {
        $error = "You must confirm that you reviewed the consent terms before signing.";
    } else {
        // Audit: Signing initiated
        log_audit($pdo, 'CONSENT_SIGNING_INITIATED', "Patient {$patient['full_name']} initiated digital signing for Consent {$consent['consent_uid']}.", $userId, 'patient');

        try {
            $sigPath = SIGNATURE_UPLOAD_DIR . basename($patient['signature_image']);
            $signedTimestamp = date('Y-m-d H:i:s');

            // Generate final signed PDF with visible patient signature image embedded
            $signedArtifact = ConsentPdfGenerator::generateConsentPdf(
                $consent,
                $patient,
                $requesterData,
                $recordData,
                $sigPath,
                $signedTimestamp
            );

            if (!$signedArtifact || empty($signedArtifact['is_signed'])) {
                throw new Exception("PDF generation failed: could not embed digital signature image.");
            }

            $finalPdfFilename = $signedArtifact['filename'];
            $finalPdfHash     = $signedArtifact['document_hash']; // SHA-256 of final signed PDF

            // Audit: Signed PDF generated
            log_audit($pdo, 'SIGNED_PDF_GENERATED', "Final signed PDF created ({$finalPdfFilename}) with SHA-256: {$finalPdfHash} for Consent {$consent['consent_uid']}.", $userId, 'patient');

            // Generate unique eSign ID
            $esignUid = generate_depa_uid('ESIGN', 'esign_records', 'esign_uid', $pdo);
            $sigHash  = hash('sha256', "LOCAL_SIGNATURE::{$patient['id']}::{$consentId}::{$finalPdfHash}::" . microtime(true));

            // Create signature record
            $stmtEsign = $pdo->prepare("INSERT INTO `esign_records` 
                (`esign_uid`, `consent_id`, `user_id`, `esign_provider`, `esign_transaction_id`, `signing_initiated_at`, 
                 `signer_role`, `signer_name`, `doc_ref`, `signature_method`, `signature_image_reference`, 
                 `document_summary`, `document_hash`, `signature_type`, `signature_hash`, 
                 `signed_document_reference`, `signed_document_hash`, `status`, `signed_at`, `created_at`)
                VALUES (?, ?, ?, 'local_signature', ?, NOW(), 'patient', ?, ?, 'LOCAL_SIGNATURE_IMAGE', ?, ?, ?, 'Digital Signature Certificate', ?, ?, ?, 'Signed', NOW(), NOW())");
            
            $stmtEsign->execute([
                $esignUid,
                $consentId,
                $userId,
                $esignUid,
                $patient['full_name'],
                $consent['consent_uid'],
                $patient['signature_image'],
                json_encode($signedArtifact['canonical_data'], JSON_UNESCAPED_SLASHES),
                $finalPdfHash,
                $sigHash,
                $finalPdfFilename,
                $finalPdfHash
            ]);

            $esignId = (int)$pdo->lastInsertId();

            // Transition consent status to Active
            $stmtUpConsent = $pdo->prepare("UPDATE `consent_requests` 
                SET `status` = 'Active',
                    `esign_status` = 'Signed',
                    `is_esigned` = 1,
                    `esign_id` = ?,
                    `esign_provider` = 'local_signature',
                    `signed_at` = ?,
                    `decision_date` = ?,
                    `signed_document_reference` = ?,
                    `signed_document_hash` = ?
                WHERE `id` = ?");
            $stmtUpConsent->execute([
                $esignId,
                $signedTimestamp,
                $signedTimestamp,
                $finalPdfFilename,
                $finalPdfHash,
                $consentId
            ]);

            // Sync Registry
            sync_registry($pdo, 'CONSENT', $consent['consent_uid'], "Consent {$consent['consent_uid']} [Signed/Active]", $consentId, 'ACTIVE');
            sync_registry($pdo, 'ESIGN', $esignUid, "Local Digital Signature: {$patient['full_name']}", $esignId, 'ACTIVE');

            // Audit Logs
            log_audit($pdo, 'CONSENT_SIGNED_SUCCESS', "Patient {$patient['full_name']} successfully digitally signed Consent {$consent['consent_uid']} using saved signature image. Doc Hash: " . substr($finalPdfHash, 0, 16) . "...", $userId, 'patient');
            log_audit($pdo, 'CONSENT_ACTIVATED', "Consent {$consent['consent_uid']} activated. Requester {$consent['requester_display_name']} granted access until " . format_date($consent['expiry_date']) . ".", $userId, 'patient');

            set_flash('success', 'Consent Digitally Signed successfully! Permissions are now active.');
            header("Location: " . BASE_URL . "patient/esign_consent.php?id=" . $consentId . "&signed=1");
            exit;

        } catch (Exception $e) {
            log_audit($pdo, 'SIGNING_FAILED', "Digital signing failed for Consent {$consent['consent_uid']}. Error: " . $e->getMessage(), $userId, 'patient');
            $error = "Digital signing could not be completed: " . $e->getMessage();
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-9">

        <!-- Breadcrumb Navigation -->
        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>patient/dashboard.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>patient/consent_requests.php">Consent Requests</a></li>
                <li class="breadcrumb-item active" aria-current="page">Consent Authorization</li>
            </ol>
        </nav>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center shadow-sm mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-3 fs-4 text-danger"></i>
                <div>
                    <strong>Signing Notice:</strong>
                    <div><?= e($error) ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isSigned): ?>
            <!-- Signed State Display: "✓ Consent Digitally Signed" -->
            <div class="card card-custom border-0 shadow-lg mb-4 text-center overflow-hidden">
                <div class="card-header bg-success text-white py-4">
                    <div class="d-inline-flex p-3 rounded-circle bg-white text-success mb-2 shadow">
                        <i class="bi bi-check-lg display-4 fw-bold"></i>
                    </div>
                    <h3 class="fw-bold mb-1">✓ Consent Digitally Signed</h3>
                    <p class="mb-0 opacity-90">
                        DEPA Electronic Health Consent Agreement has been sealed with your saved digital signature.
                    </p>
                </div>
                <div class="card-body p-4 p-md-5">
                    <div class="bg-light p-4 rounded-4 border text-start mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold text-success mb-0">
                                <i class="bi bi-patch-check-fill me-2"></i>Signed Consent Verification Summary
                            </h6>
                            <span class="badge bg-success text-white px-3 py-2 rounded-pill font-monospace">
                                STATUS: ACTIVE
                            </span>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <small class="text-muted d-block">Consent ID Handle</small>
                                <span class="fw-bold text-dark font-monospace fs-6"><?= e($consent['consent_uid']) ?></span>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block">Signature Method</small>
                                <span class="badge bg-primary-subtle text-primary border font-monospace">LOCAL_SIGNATURE_IMAGE</span>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block">Data Principal (Signer)</small>
                                <span class="fw-bold text-dark"><?= e($patient['full_name']) ?></span> (<?= e($patient['patient_uid']) ?>)
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block">Authorized Requester</small>
                                <span class="fw-bold text-dark"><?= e($consent['requester_display_name']) ?></span> (<?= ucfirst($consent['requester_role']) ?>)
                            </div>

                            <div class="col-12 pt-2 border-top">
                                <small class="text-muted d-block mb-1">Patient Signature Applied</small>
                                <div class="p-2 bg-white rounded border d-inline-block">
                                    <img src="<?= BASE_URL ?>patient/view_signature.php" alt="Patient Signature" style="max-height: 70px; max-width: 240px; object-fit: contain;">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <small class="text-muted d-block">Signed Date & Time</small>
                                <span class="fw-semibold text-dark">
                                    <?= format_date($consent['signed_at'] ?: ($consent['esign_signed_at'] ?: $consent['decision_date'])) ?>
                                </span>
                            </div>
                            <div class="col-md-6">
                                <small class="text-muted d-block">Access Validity Expiry</small>
                                <span class="fw-bold text-danger"><?= format_date($consent['expiry_date']) ?></span>
                            </div>

                            <div class="col-12 pt-2 border-top">
                                <small class="text-muted d-block">Document Integrity Hash (SHA-256 of Final Signed PDF)</small>
                                <div class="p-2 bg-white rounded border font-monospace text-break small text-success">
                                    <?= e($consent['signed_document_hash'] ?: $consent['signature_hash']) ?>
                                </div>
                                <small class="text-muted mt-1 d-block">This cryptographic hash is calculated directly from the final PDF containing your embedded signature image.</small>
                            </div>
                        </div>

                        <div class="mt-3 pt-3 border-top">
                            <div class="text-muted small fst-italic">
                                <i class="bi bi-info-circle me-1 text-primary"></i>
                                Digital Signature Demo: This signature is stored and applied to the consent document for project demonstration purposes.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-center gap-3">
                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consent['id'] ?>&download=1" class="btn btn-outline-primary px-4 fw-semibold">
                            <i class="bi bi-file-earmark-pdf-fill me-1 text-danger"></i> Download Signed Agreement PDF
                        </a>
                        <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consent['id'] ?>" target="_blank" class="btn btn-outline-secondary px-4 fw-semibold">
                            <i class="bi bi-eye me-1"></i> View Signed PDF
                        </a>
                        <a href="<?= BASE_URL ?>patient/sharing_history.php" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <i class="bi bi-clock-history me-1"></i> Data Sharing Ledger
                        </a>
                        <a href="<?= BASE_URL ?>patient/dashboard.php" class="btn btn-outline-dark px-4">
                            Return to Dashboard
                        </a>
                    </div>
                </div>
            </div>

        <?php elseif ($isBlocked): ?>
            <!-- Inactive / Revoked State -->
            <div class="card card-custom border-danger shadow-sm mb-4 text-center p-4">
                <div class="card-body">
                    <i class="bi bi-x-octagon-fill display-4 text-danger mb-3 d-block"></i>
                    <h4 class="fw-bold text-danger">Consent is <?= e($consent['status']) ?></h4>
                    <p class="text-secondary">This consent request cannot be signed as its state is <?= e($consent['status']) ?>.</p>
                    <a href="<?= BASE_URL ?>patient/consent_requests.php" class="btn btn-outline-secondary">Back to Consent Requests</a>
                </div>
            </div>

        <?php else: ?>

            <!-- Consent Review & Sign Digitally UI -->
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-shield-lock-fill text-warning fs-5 me-2"></i>
                        <h4 class="fw-bold mb-0 text-white">Consent Authorization</h4>
                    </div>
                    <span class="badge bg-warning text-dark font-monospace"><?= e($consent['consent_uid']) ?></span>
                </div>
                
                <div class="card-body p-4 p-md-5">

                    <!-- Consent Overview Summary Box -->
                    <div class="border rounded-4 p-4 mb-4" style="background-color: #fafbfc;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Data Principal (Patient)</label>
                                <div class="fw-bold text-dark fs-6"><?= e($patient['full_name']) ?></div>
                                <span class="badge bg-light text-primary border font-monospace"><?= e($patient['patient_uid']) ?></span>
                            </div>

                            <div class="col-md-6">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Data Consumer (Requester)</label>
                                <div class="fw-bold text-dark fs-6"><?= e($consent['requester_display_name']) ?></div>
                                <span class="badge badge-<?= e($consent['requester_role']) ?> text-uppercase"><?= e($consent['requester_role']) ?></span>
                                <?php if ($consent['requester_role'] === 'doctor'): ?>
                                    <small class="text-muted d-block mt-1"><?= e($consent['specialization']) ?> (Lic: <?= e($consent['doc_license']) ?>)</small>
                                <?php elseif ($consent['requester_role'] === 'hospital'): ?>
                                    <small class="text-muted d-block mt-1"><?= e($consent['contact_person']) ?> (NABH: <?= e($consent['hosp_license']) ?>)</small>
                                <?php endif; ?>
                            </div>

                            <div class="col-12 pt-2 border-top">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Health Record Reference / Scope</label>
                                <?php if ($consent['record_id']): ?>
                                    <div class="p-3 bg-white rounded-3 border border-primary-subtle mt-1">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div class="fw-bold text-primary"><?= e($consent['record_title']) ?></div>
                                            <span class="badge bg-primary-subtle text-primary border"><?= e($consent['record_type']) ?></span>
                                        </div>
                                        <small class="text-muted font-monospace d-block">Record UID: <?= e($consent['record_uid']) ?> | Date: <?= e(date('d M Y', strtotime($consent['record_date']))) ?></small>
                                    </div>
                                <?php else: ?>
                                    <div class="p-3 bg-white rounded-3 border border-primary mt-1">
                                        <span class="badge bg-primary text-white mb-1"><i class="bi bi-collection-fill me-1"></i> Full Health Vault Access</span>
                                        <div class="small text-muted">Grants access to all medical records and consultation history in your vault.</div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-12">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Clinical Purpose</label>
                                <div class="p-3 bg-white rounded-3 border small text-dark mt-1 fst-italic">
                                    "<?= e($consent['purpose']) ?>"
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Access Start Date</label>
                                <span class="small fw-semibold"><?= format_date(!empty($consent['access_start_date']) ? $consent['access_start_date'] : $consent['requested_date']) ?></span>
                            </div>

                            <div class="col-md-6">
                                <label class="small text-muted text-uppercase fw-semibold d-block">Access Expiry Date</label>
                                <span class="small fw-bold text-danger"><?= format_date($consent['expiry_date']) ?></span>
                            </div>

                            <div class="col-12 pt-3 border-top">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <label class="small text-muted text-uppercase fw-semibold mb-0" for="canonicalDocHash">
                                        <i class="bi bi-fingerprint text-primary me-1"></i> CANONICAL DOCUMENT SHA-256 HASH
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-secondary px-2.5 py-1 d-inline-flex align-items-center gap-1 shadow-sm rounded-2" id="copyHashBtn" onclick="copyCanonicalHash(this)" title="Copy SHA-256 hash to clipboard">
                                        <i class="bi bi-clipboard" id="copyHashIcon"></i>
                                        <span id="copyHashLabel" class="small fw-semibold">Copy Hash</span>
                                    </button>
                                </div>

                                <div class="p-2.5 px-3 bg-white border rounded-3 shadow-sm">
                                    <div id="canonicalDocHash" class="font-monospace text-dark text-break user-select-all fw-semibold" style="letter-spacing: 0.04em; line-height: 1.6; word-break: break-all; font-size: 0.85rem;"><?= e($previewPdfArtifact['document_hash']) ?></div>
                                </div>

                                <div class="mt-2 text-muted small">
                                    SHA-256 fingerprint of the consent document before the digital signature is embedded.
                                </div>

                                <div class="mt-2 p-2.5 bg-light border border-secondary-subtle rounded-3 text-secondary small d-flex align-items-start gap-2">
                                    <i class="bi bi-info-circle-fill text-primary mt-0.5 flex-shrink-0"></i>
                                    <div>After signing, the final PDF is hashed separately. The final PDF SHA-256 hash represents the signed document and may differ from this pre-signing hash.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Digital Signature Check Box -->
                    <?php if (!$hasSavedSignature): ?>
                        <div class="alert alert-warning border-warning p-4 rounded-4 mb-4 shadow-sm">
                            <div class="d-flex align-items-start">
                                <i class="bi bi-exclamation-triangle-fill fs-3 text-warning-emphasis me-3 mt-1"></i>
                                <div>
                                    <h5 class="fw-bold text-warning-emphasis mb-1">Signature Image Required</h5>
                                    <p class="mb-3 text-dark">
                                        Please upload your signature in your Patient Profile before signing this consent.
                                    </p>
                                    <a href="<?= BASE_URL ?>patient/profile.php#digitalSignatureSection" class="btn btn-warning fw-bold px-4">
                                        <i class="bi bi-pen me-1"></i> Go to Patient Profile to Upload Signature
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card border-primary border-opacity-25 bg-light p-3 rounded-4 mb-4">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-white p-2 border rounded shadow-sm">
                                        <img src="<?= BASE_URL ?>patient/view_signature.php" alt="Your Saved Signature" style="max-height: 48px; max-width: 140px; object-fit: contain;">
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark small">Your Saved Digital Signature Ready</div>
                                        <div class="text-muted small">Will be sealed into the final consent agreement PDF upon confirmation.</div>
                                    </div>
                                </div>
                                <a href="<?= BASE_URL ?>patient/profile.php#digitalSignatureSection" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-pencil me-1"></i> Change Signature
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="card border-primary border-opacity-25 bg-light p-4 rounded-4 mb-4">
                        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                            <!-- View Consent PDF Preview -->
                            <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consent['id'] ?>" target="_blank" class="btn btn-outline-primary btn-lg fw-bold px-4 w-100 w-sm-auto shadow-sm">
                                <i class="bi bi-file-earmark-pdf-fill me-2 text-danger"></i> View Consent PDF
                            </a>

                            <!-- Sign Digitally Button (Triggers Confirmation Modal) -->
                            <?php if ($hasSavedSignature): ?>
                                <button type="button" class="btn btn-success btn-lg fw-bold px-4 w-100 w-sm-auto shadow-sm" data-bs-toggle="modal" data-bs-target="#modalSignConfirmation" style="background-color: #0d8050; border-color: #0d8050;">
                                    <i class="bi bi-patch-check-fill me-2"></i> Sign Digitally
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-secondary btn-lg fw-bold px-4 w-100 w-sm-auto shadow-sm" disabled title="Upload signature image in profile first">
                                    <i class="bi bi-lock-fill me-2"></i> Sign Digitally (Upload Signature First)
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="text-center">
                        <a href="<?= BASE_URL ?>patient/consent_requests.php" class="btn btn-link text-secondary btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Return to Consent Requests
                        </a>
                    </div>

                </div>
            </div>

            <!-- Signing Confirmation Modal -->
            <?php if ($hasSavedSignature): ?>
                <div class="modal fade" id="modalSignConfirmation" tabindex="-1" aria-labelledby="modalSignConfirmationLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-dark text-white py-3">
                                <h5 class="modal-title fw-bold" id="modalSignConfirmationLabel">
                                    <i class="bi bi-shield-check text-success me-2"></i>Confirm Digital Signature
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                <input type="hidden" name="action" value="sign_digitally">

                                <div class="modal-body p-4">
                                    <div class="alert alert-info py-2 px-3 small mb-3">
                                        <i class="bi bi-info-circle me-1"></i> Please review the consent details before applying your digital signature.
                                    </div>

                                    <div class="bg-light p-3 rounded-3 border mb-3">
                                        <div class="row g-2 small">
                                            <div class="col-sm-6">
                                                <strong class="text-muted d-block">Patient Name:</strong>
                                                <span class="fw-bold text-dark fs-6"><?= e($patient['full_name']) ?></span>
                                            </div>
                                            <div class="col-sm-6">
                                                <strong class="text-muted d-block">Data Requester:</strong>
                                                <span class="fw-bold text-dark"><?= e($consent['requester_display_name']) ?></span> (<?= ucfirst($consent['requester_role']) ?>)
                                            </div>
                                            <div class="col-12 pt-2 border-top">
                                                <strong class="text-muted d-block">Consent Purpose:</strong>
                                                <span class="text-dark fst-italic">"<?= e($consent['purpose']) ?>"</span>
                                            </div>
                                            <div class="col-sm-6 pt-2 border-top">
                                                <strong class="text-muted d-block">Access Start Date:</strong>
                                                <span class="fw-semibold text-dark"><?= format_date(!empty($consent['access_start_date']) ? $consent['access_start_date'] : $consent['requested_date']) ?></span>
                                            </div>
                                            <div class="col-sm-6 pt-2 border-top">
                                                <strong class="text-muted d-block">Access Expiry Date:</strong>
                                                <span class="fw-bold text-danger"><?= format_date($consent['expiry_date']) ?></span>
                                            </div>
                                            <div class="col-12 pt-2 border-top">
                                                <strong class="text-muted d-block">Document Pre-Signing SHA-256 Hash:</strong>
                                                <span class="font-monospace text-break small text-secondary"><?= e($previewPdfArtifact['document_hash']) ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="p-3 bg-white border rounded-3 mb-3 text-center">
                                        <small class="text-muted d-block mb-1">Your signature to be stamped on the consent document:</small>
                                        <img src="<?= BASE_URL ?>patient/view_signature.php" alt="Signature Preview" style="max-height: 50px; max-width: 180px; object-fit: contain;">
                                    </div>

                                    <div class="form-check p-3 bg-warning-subtle border border-warning rounded-3">
                                        <input class="form-check-input ms-0 me-2" type="checkbox" name="confirm_agreement" value="1" id="confirmAgreementCheck" required>
                                        <label class="form-check-label small fw-bold text-dark" for="confirmAgreementCheck">
                                            I confirm that I have reviewed this consent and agree to sign it digitally for this project.
                                        </label>
                                    </div>

                                    <div class="small text-muted mt-2 fst-italic">
                                        Digital Signature Demo: This signature is stored and applied to the consent document for project demonstration purposes.
                                    </div>
                                </div>

                                <div class="modal-footer bg-light py-2 px-4">
                                    <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-success fw-bold px-4" style="background-color: #0d8050; border-color: #0d8050;">
                                        <i class="bi bi-pen-fill me-1"></i> Confirm & Sign
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<script>
function copyCanonicalHash(btn) {
    var hashEl = document.getElementById('canonicalDocHash');
    if (!hashEl) return;
    var hashText = hashEl.innerText.trim();
    if (!hashText) return;

    function showCopiedFeedback() {
        var labelEl = document.getElementById('copyHashLabel');
        var iconEl = document.getElementById('copyHashIcon');
        if (labelEl) labelEl.textContent = 'Hash copied';
        if (iconEl) iconEl.className = 'bi bi-check-lg text-success';
        if (btn) {
            btn.classList.remove('btn-outline-secondary');
            btn.classList.add('btn-success', 'text-white');
        }

        setTimeout(function() {
            if (labelEl) labelEl.textContent = 'Copy Hash';
            if (iconEl) iconEl.className = 'bi bi-clipboard';
            if (btn) {
                btn.classList.remove('btn-success', 'text-white');
                btn.classList.add('btn-outline-secondary');
            }
        }, 2200);
    }

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(hashText).then(function() {
            showCopiedFeedback();
        }).catch(function() {
            fallbackCopyText(hashText, showCopiedFeedback);
        });
    } else {
        fallbackCopyText(hashText, showCopiedFeedback);
    }
}

function fallbackCopyText(text, callback) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    textArea.style.top = '0';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        var successful = document.execCommand('copy');
        if (successful && callback) {
            callback();
        }
    } catch (err) {
        console.error('Clipboard copy failed:', err);
    }
    document.body.removeChild(textArea);
}
</script>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>

