<?php
/**
 * Reviewing Authority / Approver Co-Signing Interface
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Secure review portal for Medical Superintendent / Reviewing Authority:
 * - Validates secure one-time signed token or admin session
 * - Enforces workflow state: status must be approver_pending (Doctor signed first)
 * - Displays Doctor's signature status, license, and intermediate doctor-signed PDF
 * - Allows Approver to approve/co-sign (Signer 2) or reject with reason
 * - Applies Second Signature preserving Doctor signature and generating final active PDF
 */

$pageTitle = "Reviewing Authority - Consent Co-Signing";
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/consent_pdf_generator.php';
require_once __DIR__ . '/../services/EmSignerService.php';

$pdo = get_db_connection();

$consentId = (int)($_GET['id'] ?? ($_POST['consent_id'] ?? 0));
$token     = trim($_GET['token'] ?? ($_POST['token'] ?? ''));

if ($consentId <= 0) {
    http_response_code(400);
    die("Error: Invalid consent request ID.");
}

// Fetch Consent Record
$stmt = $pdo->prepare("SELECT cr.*, p.full_name AS patient_name, p.patient_uid, p.dob, p.gender,
                              d.full_name AS doc_name, d.license_number AS doc_license, d.specialization,
                              hr.title AS record_title, hr.record_uid, hr.record_type, hr.diagnosis
                       FROM `consent_requests` cr
                       JOIN `patients` p ON cr.patient_id = p.id
                       JOIN `users` u ON cr.requester_user_id = u.id
                       LEFT JOIN `doctors` d ON u.id = d.user_id
                       LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                       WHERE cr.id = ?");
$stmt->execute([$consentId]);
$consent = $stmt->fetch();

if (!$consent) {
    http_response_code(404);
    die("Error: Consent request record not found.");
}

// Check Access Permission: Either valid one-time token or logged in Admin/Hospital
$isAuthorized = false;
$currentUserId = get_logged_user_id();
$currentUserRole = get_user_role();

if (!empty($token) && !empty($consent['approver_token']) && hash_equals($consent['approver_token'], $token)) {
    if (strtotime($consent['approver_token_expiry']) >= time()) {
        $isAuthorized = true;
    } else {
        log_audit($pdo, 'UNAUTHORIZED_APPROVER_ACCESS', "Expired approver token used for Consent ID {$consentId}.", $currentUserId, $currentUserRole ?: 'guest');
        http_response_code(403);
        die("Security Notice: This secure co-signing link has expired. Please contact the Medical Records Department.");
    }
} elseif ($currentUserId && in_array($currentUserRole, ['admin', 'hospital'])) {
    $isAuthorized = true;
}

if (!$isAuthorized) {
    log_audit($pdo, 'UNAUTHORIZED_APPROVER_ACCESS', "Unauthorized access attempt for Consent ID {$consentId} without valid token or credentials.", $currentUserId, $currentUserRole ?: 'guest');
    http_response_code(403);
    die("Access Denied (403): You must use the secure review link from your email or log in with Reviewing Authority credentials.");
}

// Workflow state checks
$isDoctorSigned = !empty($consent['doctor_signed_at']);
$isAlreadyApproverSigned = !empty($consent['approver_signed_at']) || $consent['status'] === 'Active';
$isRejected = ($consent['status'] === 'Rejected');
$isRevoked = ($consent['status'] === 'Revoked');
$isExpired = ($consent['status'] === 'Expired');
$isAwaitingApprover = ($isDoctorSigned && !$isAlreadyApproverSigned && in_array($consent['status'], ['approver_pending', 'Approved']) && !$isRejected && !$isRevoked && !$isExpired);

$approverName = $consent['approver_name'] ?: (defined('DEFAULT_APPROVER_NAME') ? DEFAULT_APPROVER_NAME : 'Dr. S. K. Nambiar');
$approverRole = defined('DEFAULT_APPROVER_ROLE') ? DEFAULT_APPROVER_ROLE : 'Medical Superintendent';
$approverUserId = $currentUserId ?: 1;

$error = '';
$success = '';

// Audit: Reviewing Authority opened the review page
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $isAwaitingApprover) {
    log_audit($pdo, 'APPROVER_REVIEWED', "Reviewing Authority {$approverName} viewed intermediate document review portal for Consent {$consent['consent_uid']}.", $approverUserId, 'admin', $approverName);
}

// -----------------------------------------------------------------------------
// POST ACTION 1: REJECT CONSENT
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_consent') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security CSRF token mismatch. Please reload the page.";
    } elseif (!$isDoctorSigned) {
        $error = "Doctor has not signed yet. Rejection can only occur in approver review.";
    } elseif ($isAlreadyApproverSigned) {
        $error = "This consent has already been co-signed and activated; it cannot be rejected.";
    } elseif ($isRejected) {
        $error = "This consent is already rejected.";
    } else {
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        if (empty($rejectionReason)) {
            $error = "Please provide an explicit reason for rejecting this consent request.";
        } else {
            $stmtRej = $pdo->prepare("UPDATE `consent_requests` 
                                      SET `status` = 'Rejected', 
                                          `workflow_step` = 'REJECTED', 
                                          `rejection_reason` = ?, 
                                          `patient_notes` = ?, 
                                          `decision_date` = NOW() 
                                      WHERE `id` = ?");
            $stmtRej->execute([$rejectionReason, $rejectionReason, $consentId]);

            // Sync Registry
            sync_registry($pdo, 'CONSENT', $consent['consent_uid'], "Consent {$consent['consent_uid']} [Rejected by Approver]", $consentId, 'REJECTED');

            // Audit
            log_audit($pdo, 'APPROVER_REJECTED', "Reviewing Authority {$approverName} rejected Consent {$consent['consent_uid']}. Reason: {$rejectionReason}", $approverUserId, 'admin', $approverName);

            set_flash('danger', "Consent request {$consent['consent_uid']} has been REJECTED. Record access is denied.");
            header("Location: " . BASE_URL . "esign/status.php");
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// POST ACTION 2: APPROVE & CO-SIGN (Signer 2)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sign_as_approver') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security CSRF token mismatch. Please reload the page.";
    } elseif (!$isDoctorSigned) {
        $error = "Attending Physician has not yet signed this agreement. Co-signing by the Approver is blocked.";
    } elseif ($isAlreadyApproverSigned) {
        $error = "This consent has already been co-signed by the Reviewing Authority.";
    } elseif ($isRejected || $isRevoked || $isExpired) {
        $error = "Consent is in state '{$consent['status']}' and cannot be signed.";
    } else {
        // Retrieve intermediate doctor-signed PDF
        $uploadDir = ROOT_DIR . 'uploads/consent_agreements/';
        $docSignedPath = '';

        if (!empty($consent['intermediate_document_reference'])) {
            $docSignedPath = $uploadDir . basename($consent['intermediate_document_reference']);
        } elseif (!empty($consent['signed_document_reference'])) {
            $docSignedPath = $uploadDir . basename($consent['signed_document_reference']);
        }

        if (!empty($docSignedPath) && file_exists($docSignedPath)) {
            $pdfBytes = file_get_contents($docSignedPath);
        } else {
            // Re-generate intermediate Doctor-signed PDF if file reference is missing
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

            $doctorSig = [
                'is_signed'   => true,
                'signer_name' => $consent['doc_name'],
                'license'     => $consent['doc_license'],
                'signed_at'   => $consent['doctor_signed_at'],
                'txn_id'      => $consent['esign_transaction_id'],
                'sig_hash'    => hash('sha256', "DOCTOR::{$consent['doc_name']}::{$consent['doctor_signed_at']}")
            ];

            $intermediateArtifact = ConsentPdfGenerator::generateConsentPdf(
                $consent,
                $patientData,
                $requesterData,
                $recordData,
                null,
                null,
                $doctorSig,
                null
            );
            $pdfBytes = $intermediateArtifact['pdf_content'];
        }

        try {
            // Create emSigner Signing Transaction for Signer 2 (Approver)
            $txn = EmSignerService::createSigningTransaction(
                'approver',
                $approverName,
                $consent,
                $approverUserId,
                $pdfBytes,
                2 // Signer Index 2 (Reviewing Authority)
            );

            // Update workflow step to APPROVER_SIGN
            $pdo->prepare("UPDATE `consent_requests` SET `workflow_step` = 'APPROVER_SIGN' WHERE `id` = ?")->execute([$consentId]);

            log_audit($pdo, 'ESIGN_INITIATED', "Reviewing Authority {$approverName} initiated emSigner transaction ({$txn['transaction_number']}) for Consent {$consent['consent_uid']}.", $approverUserId, 'admin', $approverName);

            header("Location: " . $txn['redirect_url']);
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
            log_audit($pdo, 'ESIGN_FAILED', "Approver emSigner initiation failed for Consent {$consent['consent_uid']}: " . $e->getMessage(), $approverUserId, 'admin', $approverName);
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-10">

        <!-- Top Banner -->
        <div class="card card-custom border-0 bg-dark text-white shadow-sm p-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge bg-warning text-dark fw-bold px-3 py-1 rounded-pill">
                            <i class="bi bi-shield-lock-fill me-1"></i> Reviewing Authority Portal
                        </span>
                        <span class="badge bg-primary text-white px-2 py-1">DEPA Sequential Consent Co-Signing</span>
                    </div>
                    <h2 class="fw-bold mb-1">Health Consent Agreement Co-Signing</h2>
                    <p class="text-white-50 mb-0">
                        Reviewing Consent <span class="font-monospace text-warning"><?= e($consent['consent_uid']) ?></span> for Patient <strong><?= e($consent['patient_name']) ?></strong>.
                    </p>
                </div>
                <div class="mt-3 mt-md-0 text-md-end">
                    <?php if ($isAlreadyApproverSigned): ?>
                        <span class="badge bg-success px-3 py-2 fs-6">
                            <i class="bi bi-patch-check-fill me-1"></i> Approver Signature Completed
                        </span>
                    <?php elseif ($isRejected): ?>
                        <span class="badge bg-danger px-3 py-2 fs-6">
                            <i class="bi bi-x-circle-fill me-1"></i> Consent Rejected
                        </span>
                    <?php elseif ($isAwaitingApprover): ?>
                        <span class="badge bg-warning text-dark px-3 py-2 fs-6">
                            <i class="bi bi-hourglass-split me-1"></i> Awaiting Approver Signature
                        </span>
                    <?php else: ?>
                        <span class="badge bg-secondary px-3 py-2 fs-6">
                            <?= e($consent['status']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success d-flex align-items-center mb-4 shadow-sm">
                <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                <div><?= e($success) ?></div>
            </div>
        <?php endif; ?>

        <!-- Two Column Layout: Document Verification vs Signing/Rejection Actions -->
        <div class="row g-4">
            
            <!-- Left Pane: Agreement Verification & Intermediate Document Details -->
            <div class="col-lg-7">
                <div class="card card-custom border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-card-text text-primary me-2"></i>Agreement Verification & Audit Details
                        </h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="row g-3 small">
                                <div class="col-sm-6">
                                    <span class="text-muted d-block">Data Principal (Patient)</span>
                                    <strong class="text-dark fs-6"><?= e($consent['patient_name']) ?></strong>
                                    <span class="text-muted font-monospace d-block"><?= e($consent['patient_uid']) ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted d-block">Target Scope / Health Record</span>
                                    <strong><?= e($consent['record_title'] ?: 'Comprehensive Health Vault') ?></strong>
                                    <span class="text-muted d-block"><?= e($consent['record_type'] ?: 'All Patient Records') ?></span>
                                </div>
                                <div class="col-12 border-top pt-2">
                                    <span class="text-muted d-block">Clinical Purpose of Access</span>
                                    <span class="text-dark"><?= e($consent['purpose']) ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted d-block">Validity Period</span>
                                    <span><?= format_date($consent['access_start_date']) ?> to <?= format_date($consent['expiry_date']) ?></span>
                                </div>
                                <div class="col-sm-6">
                                    <span class="text-muted d-block">Current Workflow Status</span>
                                    <span class="badge bg-info text-dark font-monospace"><?= e($consent['status']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Doctor Intermediate Signature Verification Box -->
                        <div class="p-3 bg-success-subtle rounded-3 border border-success-subtle mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="fw-bold text-success">
                                    <i class="bi bi-patch-check-fill me-1"></i>Signer 1: Attending Physician Verification
                                </span>
                                <?php if ($isDoctorSigned): ?>
                                    <span class="badge bg-success text-white">Doctor Signature: Completed ✓</span>
                                <?php else: ?>
                                    <span class="badge bg-danger text-white">Pending Doctor Signature</span>
                                <?php endif; ?>
                            </div>
                            <div class="row g-2 small text-secondary">
                                <div class="col-sm-6">
                                    <strong>Doctor:</strong> <?= e($consent['doc_name']) ?>
                                </div>
                                <div class="col-sm-6">
                                    <strong>License:</strong> <?= e($consent['doc_license']) ?>
                                </div>
                                <div class="col-sm-6">
                                    <strong>Signed Timestamp:</strong> <?= $consent['doctor_signed_at'] ? format_date($consent['doctor_signed_at']) : 'Pending' ?>
                                </div>
                                <div class="col-sm-6">
                                    <strong>Transaction ID:</strong> <span class="font-monospace"><?= e($consent['esign_transaction_id'] ?: 'N/A') ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Cryptographic Integrity Hashes -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <h6 class="fw-bold small text-dark mb-2">
                                <i class="bi bi-hash text-secondary me-1"></i>Cryptographic Document Integrity
                            </h6>
                            <p class="small text-muted mb-2">
                                Local Digital Signature + SHA-256 Document Integrity Verification guarantees non-repudiation between sequential workflow stages.
                            </p>
                            <div class="small">
                                <span class="text-muted d-block fw-semibold">Canonical Document SHA-256 Hash (Pre-Sign):</span>
                                <div class="p-2 bg-white border rounded font-monospace small text-break text-secondary mb-2" style="font-size: 0.72rem;">
                                    <?= e($consent['pre_sign_document_hash'] ?: 'N/A') ?>
                                </div>

                                <span class="text-muted d-block fw-semibold">Doctor-Signed Intermediate Document SHA-256 Hash:</span>
                                <div class="p-2 bg-white border rounded font-monospace small text-break text-secondary mb-0" style="font-size: 0.72rem;">
                                    <?= e($consent['intermediate_document_hash'] ?: ($consent['signed_document_hash'] ?: 'Pending')) ?>
                                </div>
                            </div>
                        </div>

                        <!-- PDF Viewer Button -->
                        <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded-3 border">
                            <div class="small text-muted">
                                <i class="bi bi-file-earmark-pdf text-danger fs-5 me-2 align-middle"></i>
                                View intermediate doctor-signed PDF
                            </div>
                            <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consentId ?>" target="_blank" class="btn btn-outline-primary btn-sm px-3 fw-semibold">
                                <i class="bi bi-box-arrow-up-right me-1"></i> Open Intermediate PDF
                            </a>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Right Pane: Approver Co-Signing / Rejection Actions -->
            <div class="col-lg-5">
                <div class="card card-custom border-0 shadow-sm">
                    <div class="card-header bg-white py-3">
                        <h6 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-pen text-primary me-2"></i>Signer 2: Reviewing Authority Action
                        </h6>
                    </div>
                    <div class="card-body p-4 text-center">

                        <?php if ($isAlreadyApproverSigned): ?>
                            <!-- Completed State -->
                            <div class="p-4 bg-success-subtle rounded-3 border border-success-subtle mb-4">
                                <i class="bi bi-check-circle-fill text-success display-4 mb-2 d-block"></i>
                                <h5 class="fw-bold text-success mb-1">Approver Signature Completed</h5>
                                <p class="text-secondary small mb-0">This document has been fully signed by both Doctor and Reviewing Authority. Consent status is ACTIVE.</p>
                                <div class="mt-3 font-monospace small text-muted">
                                    Approved: <?= format_date($consent['approver_signed_at']) ?>
                                </div>
                            </div>

                            <a href="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consentId ?>" target="_blank" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm">
                                <i class="bi bi-file-earmark-pdf me-1"></i> Download Final Dual-Signed PDF
                            </a>

                        <?php elseif ($isRejected): ?>
                            <!-- Rejected State -->
                            <div class="p-4 bg-danger-subtle rounded-3 border border-danger-subtle mb-4 text-start">
                                <i class="bi bi-x-octagon-fill text-danger display-5 mb-2 d-block text-center"></i>
                                <h5 class="fw-bold text-danger mb-1 text-center">Consent Request Rejected</h5>
                                <p class="small text-secondary mb-2">This consent request was reviewed and rejected by the Reviewing Authority.</p>
                                <div class="p-2 bg-white border rounded small">
                                    <strong>Rejection Reason:</strong> <?= e($consent['rejection_reason'] ?: ($consent['patient_notes'] ?: 'No explicit reason given.')) ?>
                                </div>
                            </div>

                        <?php elseif (!$isDoctorSigned): ?>
                            <!-- Pending Doctor State -->
                            <div class="alert alert-warning text-start small mb-4">
                                <i class="bi bi-exclamation-circle-fill me-1"></i>
                                <strong>Pending Doctor Signature:</strong> The attending physician has not yet applied their intermediate signature. The Reviewing Authority can only co-sign after the doctor signs.
                            </div>

                        <?php else: ?>
                            <!-- Awaiting Approver Action -->
                            <div class="p-3 bg-light rounded-3 border mb-4 text-start small">
                                <span class="text-muted d-block">Designated Approver:</span>
                                <strong class="fs-6 text-dark d-block"><?= e($approverName) ?></strong>
                                <span class="text-muted d-block"><?= e($approverRole) ?></span>
                                <span class="badge bg-secondary-subtle text-secondary border mt-1">Placement: Page 1, Right Quadrant</span>
                            </div>

                            <p class="small text-muted mb-4 text-start">
                                Review the intermediate PDF and clinical request terms. You may apply your digital approval seal or reject the request with cause.
                            </p>

                            <!-- Co-Sign Form -->
                            <form method="POST" action="" class="mb-3">
                                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                <input type="hidden" name="action" value="sign_as_approver">
                                <input type="hidden" name="consent_id" value="<?= $consentId ?>">
                                <input type="hidden" name="token" value="<?= e($token) ?>">
                                <button type="submit" class="btn btn-success btn-lg w-100 fw-bold shadow-sm py-3">
                                    <i class="bi bi-pen-fill me-2"></i> Approve & Co-Sign Agreement
                                </button>
                            </form>

                            <!-- Reject Modal Trigger -->
                            <button type="button" class="btn btn-outline-danger btn-sm w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                <i class="bi bi-x-circle me-1"></i> Reject Consent Request
                            </button>

                            <!-- Reject Reason Modal -->
                            <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title fw-bold" id="rejectModalLabel">
                                                <i class="bi bi-exclamation-triangle-fill me-2"></i>Reject Consent Request
                                            </h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="">
                                            <div class="modal-body p-4 text-start">
                                                <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                                <input type="hidden" name="action" value="reject_consent">
                                                <input type="hidden" name="consent_id" value="<?= $consentId ?>">
                                                <input type="hidden" name="token" value="<?= e($token) ?>">
                                                
                                                <p class="small text-muted mb-3">
                                                    Rejecting this consent request will immediately terminate the authorization workflow. The doctor will not be granted access to the patient's records.
                                                </p>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold small text-dark">Reason for Rejection <span class="text-danger">*</span></label>
                                                    <textarea name="rejection_reason" class="form-control" rows="3" placeholder="Provide clinical or institutional justification for rejecting access..." required></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer bg-light">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger btn-sm fw-bold">Confirm Rejection</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 border-top pt-3 text-start">
                            <a href="<?= BASE_URL ?>esign/status.php" class="small text-decoration-none text-secondary">
                                &larr; Return to Central eSign Monitor
                            </a>
                        </div>

                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
