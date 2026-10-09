<?php
/**
 * eMudhra emSigner Gateway Interface (Sandbox & Integration Portal)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Provides high-fidelity emSigner Signing Portal:
 * - Real-time visual document preview
 * - Selection of emSigner signature types (DSC Token, Aadhaar eSign, eSignature)
 * - Coordinate-exact signature placement overlay
 * - Secure callback generation with signed PDF payload
 */

$pageTitle = "emSigner Gateway - eMudhra Secure Signing";
require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionId = trim($_GET['session'] ?? '');
$sessionData = $_SESSION['emsigner_sessions'][$sessionId] ?? null;

if (!$sessionData) {
    die("Invalid or expired signing session. Please re-initiate signing from your dashboard.");
}

$pdo = get_db_connection();
$consentId = (int)$sessionData['consent_id'];
$signerRole = $sessionData['signer_role'];
$signerName = $sessionData['signer_name'];
$signerIndex = (int)$sessionData['signer_index'];
$refNumber = $sessionData['reference_number'];
$txnNumber = $sessionData['transaction_number'];
$sUrl = $sessionData['s_url'];
$cUrl = $sessionData['c_url'];

// Fetch consent record from DB
$stmtC = $pdo->prepare("SELECT cr.*, p.full_name AS patient_name, p.patient_uid, p.dob, p.gender,
                               d.full_name AS doc_name, d.license_number AS doc_license, d.specialization,
                               hr.title AS record_title, hr.record_uid
                        FROM `consent_requests` cr
                        JOIN `patients` p ON cr.patient_id = p.id
                        JOIN `users` u ON cr.requester_user_id = u.id
                        LEFT JOIN `doctors` d ON u.id = d.user_id
                        LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                        WHERE cr.id = ?");
$stmtC->execute([$consentId]);
$consent = $stmtC->fetch();

if (!$consent) {
    die("Consent record not found.");
}

// Handle Form Submission from Gateway: "Sign Document"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_signing') {
    $sigMethod = $_POST['signature_method'] ?? 'DSC_TOKEN';
    $certPin   = trim($_POST['cert_pin'] ?? '');
    
    // Simulate signing and produce updated signed PDF artifact
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

    $signedTimestamp = date('Y-m-d H:i:s');
    $sigHash = hash('sha256', "EMSIG::{$refNumber}::{$signerRole}::{$signerName}::{$signedTimestamp}");

    // Prepare signature data based on signer index
    $doctorSig = null;
    $approverSig = null;

    if ($signerIndex === 1) { // Doctor is signing
        $doctorSig = [
            'is_signed'   => true,
            'signer_name' => $signerName,
            'license'     => $consent['doc_license'] ?? 'MCI-REG',
            'signed_at'   => $signedTimestamp,
            'txn_id'      => $txnNumber,
            'sig_hash'    => $sigHash
        ];
    } else { // Approver is signing
        // Include doctor signature data from consent request
        $doctorSig = [
            'is_signed'   => true,
            'signer_name' => $consent['doc_name'] ?? 'Attending Physician',
            'license'     => $consent['doc_license'] ?? 'MCI-REG',
            'signed_at'   => $consent['doctor_signed_at'] ?? $signedTimestamp,
            'txn_id'      => $consent['esign_transaction_id'] ?? 'EMSIG-DOC-TXN',
            'sig_hash'    => hash('sha256', "DOCTOR::{$consent['doc_name']}::{$consent['doctor_signed_at']}")
        ];
        $approverSig = [
            'is_signed'   => true,
            'signer_name' => $signerName,
            'role'        => 'Medical Superintendent',
            'signed_at'   => $signedTimestamp,
            'txn_id'      => $txnNumber,
            'sig_hash'    => $sigHash
        ];
    }

    // Generate updated PDF
    $pdfArtifact = ConsentPdfGenerator::generateConsentPdf(
        $consent,
        $patientData,
        $requesterData,
        $recordData,
        null,
        null,
        $doctorSig,
        $approverSig
    );

    // Mark session as completed
    $_SESSION['emsigner_sessions'][$sessionId]['status'] = 'COMPLETED';
    $_SESSION['emsigner_sessions'][$sessionId]['signed_pdf_bytes'] = $pdfArtifact['pdf_content'];
    $_SESSION['emsigner_sessions'][$sessionId]['signed_pdf_hash']  = $pdfArtifact['document_hash'];
    $_SESSION['emsigner_sessions'][$sessionId]['signature_hash']   = $sigHash;

    // Render Auto-POST Form to SUrl matching emSigner Gateway specification
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>emSigner - Redirecting...</title>
        <style>
            body { font-family: -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f8fafc; }
            .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; max-width: 450px; }
            .spinner { border: 4px solid #f3f3f3; border-top: 4px solid #0d6efd; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 20px; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="spinner"></div>
            <h3 style="margin-bottom: 8px; color: #1e293b;">Signature Applied Successfully</h3>
            <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;">Returning to DEPA Health Exchange with verified cryptographic seal...</p>
            <form id="emSignerCallbackForm" method="POST" action="<?= e($sUrl) ?>">
                <input type="hidden" name="Transactionnumber" value="<?= e($txnNumber) ?>">
                <input type="hidden" name="ReferenceNumber" value="<?= e($refNumber) ?>">
                <input type="hidden" name="Status" value="Completed">
                <input type="hidden" name="SignedData" value="<?= e($pdfArtifact['pdf_base64']) ?>">
                <input type="hidden" name="DocumentHash" value="<?= e($pdfArtifact['document_hash']) ?>">
                <input type="hidden" name="SignatureHash" value="<?= e($sigHash) ?>">
                <input type="hidden" name="SignerRole" value="<?= e($signerRole) ?>">
                <input type="hidden" name="SignerName" value="<?= e($signerName) ?>">
                <input type="hidden" name="SignatureMethod" value="<?= e($sigMethod) ?>">
                <noscript><button type="submit" style="padding: 10px 20px; background: #0d6efd; color: white; border: none; border-radius: 6px;">Click to Continue</button></noscript>
            </form>
            <script>document.getElementById('emSignerCallbackForm').submit();</script>
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>emSigner Gateway | eMudhra Digital Signature Suite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .gateway-header { background: #0f172a; border-bottom: 3px solid #3b82f6; }
        .emsigner-brand { font-size: 1.4rem; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; }
        .emsigner-badge { background: #2563eb; color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 4px; }
        .doc-preview-pane { height: 580px; border: 1px solid #cbd5e1; border-radius: 8px; background: #64748b; overflow: hidden; }
        .doc-preview-pane iframe { width: 100%; height: 100%; border: none; }
        .sig-option-card { border: 2px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; padding: 15px; }
        .sig-option-card:hover { border-color: #3b82f6; background-color: #f8fafc; }
        .sig-option-card.selected { border-color: #2563eb; background-color: #eff6ff; }
        .sig-stamp-preview { border: 2px dashed #16a34a; background: #f0fdf4; border-radius: 8px; padding: 12px; }
    </style>
</head>
<body>

    <!-- Header Navigation Bar -->
    <header class="gateway-header py-3 px-4 shadow-sm mb-4">
        <div class="container-fluid d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
                <span class="emsigner-brand"><i class="bi bi-pen-fill text-primary me-2"></i>emSigner Demo</span>
                <span class="emsigner-badge">Local Signer Simulator</span>
                <span class="badge bg-secondary-subtle text-light ms-2">Local Digital Signature + SHA-256 Document Integrity Verification</span>
            </div>
            <div class="text-white-50 small font-monospace">
                Txn: <span class="text-white"><?= e($txnNumber) ?></span> | Ref: <span class="text-warning"><?= e($refNumber) ?></span>
            </div>
        </div>
    </header>

    <div class="container-fluid px-4 mb-5">
        <div class="row g-4">

            <!-- Left Column: Document Visual Inspection Pane -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <div class="fw-bold text-dark">
                            <i class="bi bi-file-earmark-pdf text-danger me-2"></i>Document Preview: <?= e($consent['consent_uid']) ?>
                        </div>
                        <span class="badge bg-light text-secondary border font-monospace">Page 1 of 1</span>
                    </div>
                    <div class="card-body p-2">
                        <div class="doc-preview-pane">
                            <iframe src="<?= BASE_URL ?>patient/view_consent_pdf.php?id=<?= $consentId ?>#toolbar=0&navpanes=0"></iframe>
                        </div>
                    </div>
                    <div class="card-footer bg-light py-2 small text-muted d-flex justify-content-between">
                        <span>Signer Slot: <strong>Signer <?= $signerIndex ?> (<?= ucfirst($signerRole) ?>)</strong></span>
                        <span>Designated Area: <strong>Page 1 (<?= $signerRole === 'doctor' ? 'Left Box' : 'Right Box' ?>)</strong></span>
                    </div>
                </div>
            </div>

            <!-- Right Column: Interactive emSigner Signing Controls -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="fw-bold mb-0 text-dark">
                            <i class="bi bi-fingerprint text-primary me-2"></i>Digital Signing Ceremony
                        </h5>
                    </div>
                    <div class="card-body p-4">

                        <!-- Signer Demographics -->
                        <div class="p-3 bg-light rounded-3 border mb-3">
                            <div class="row g-2 small">
                                <div class="col-6">
                                    <span class="text-muted d-block">Signatory Name</span>
                                    <strong class="text-dark"><?= e($signerName) ?></strong>
                                </div>
                                <div class="col-6">
                                    <span class="text-muted d-block">Signatory Role</span>
                                    <span class="badge bg-primary-subtle text-primary border"><?= ucfirst($signerRole) ?></span>
                                </div>
                                <div class="col-12 mt-2 pt-2 border-top">
                                    <span class="text-muted d-block">Consent Purpose</span>
                                    <span class="text-dark"><?= e($consent['purpose']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Signature Method Selection -->
                        <label class="form-label fw-bold text-dark small mb-2">Select eMudhra Signature Mode:</label>

                        <div class="d-flex flex-column gap-2 mb-4">
                            <div class="sig-option-card selected" onclick="selectMethod('DSC_TOKEN', this)">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-usb-symbol fs-3 text-primary me-3"></i>
                                    <div>
                                        <div class="fw-bold text-dark">Class 3 Digital Signature Certificate (DSC)</div>
                                        <div class="text-muted small">Cryptographic PKI hardware token / FIPS-140 certificate</div>
                                    </div>
                                </div>
                            </div>

                            <div class="sig-option-card" onclick="selectMethod('AADHAAR_ESIGN', this)">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-shield-check fs-3 text-success me-3"></i>
                                    <div>
                                        <div class="fw-bold text-dark">eMudhra eSign (Aadhaar OTP / Bio)</div>
                                        <div class="text-muted small">UIDAI compliant remote electronic signature service</div>
                                    </div>
                                </div>
                            </div>

                            <div class="sig-option-card" onclick="selectMethod('ESIGNATURE_PAD', this)">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-pen fs-3 text-secondary me-3"></i>
                                    <div>
                                        <div class="fw-bold text-dark">Standard Electronic Signature</div>
                                        <div class="text-muted small">Authenticated biometric click-to-sign seal</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Visual Seal Preview Stamp -->
                        <div class="sig-stamp-preview mb-4">
                            <div class="d-flex align-items-center mb-1">
                                <i class="bi bi-patch-check-fill text-success fs-5 me-2"></i>
                                <span class="fw-bold text-success small">emSigner Verified Seal Placement</span>
                            </div>
                            <div class="small text-muted font-monospace" style="font-size: 0.75rem;">
                                Signer: <?= e($signerName) ?><br>
                                Issuer: eMudhra CA 2026 Class 3 Sub-CA<br>
                                Location: Page 1, [<?= $signerRole === 'doctor' ? 'ATTENDING PHYSICIAN' : 'REVIEWING AUTHORITY' ?>]<br>
                                Timestamp: <?= date('d-M-Y H:i:s T') ?>
                            </div>
                        </div>

                        <!-- Signing Form -->
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="complete_signing">
                            <input type="hidden" name="signature_method" id="selectedSigMethod" value="DSC_TOKEN">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-muted">PIN / Security Token Passcode</label>
                                <input type="password" name="cert_pin" class="form-control font-monospace" value="123456" placeholder="Enter Token PIN (Demo: 123456)">
                                <small class="text-muted" style="font-size: 0.72rem;">Hardware token PIN simulated for evaluation.</small>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-label form-check-input" type="checkbox" id="agreeTerms" checked required>
                                <label class="form-check-label small text-secondary" for="agreeTerms">
                                    I confirm that I have reviewed the consent document and authorize emSigner to cryptographically seal this record.
                                </label>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-sm">
                                    <i class="bi bi-check2-circle me-1"></i> Sign Document via emSigner
                                </button>
                                <a href="<?= e($cUrl) ?>" class="btn btn-outline-secondary btn-sm">
                                    Cancel & Return
                                </a>
                            </div>
                        </form>

                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        function selectMethod(method, element) {
            document.getElementById('selectedSigMethod').value = method;
            document.querySelectorAll('.sig-option-card').forEach(c => c.classList.remove('selected'));
            element.classList.add('selected');
        }
    </script>
</body>
</html>
