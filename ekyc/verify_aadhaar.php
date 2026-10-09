<?php
/**
 * Unified Aadhaar Verification Gateway (Multi-Role: Patient & Doctor)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Flow:
 * Dashboard -> "Verify Aadhaar" -> Aadhaar Verification page
 * -> "Offline XML" Method -> Upload Aadhaar Card (JPG/JPEG/PNG/PDF/XML/ZIP)
 * -> Verify -> Show Aadhaar Holder's Photo + Name + Date of Birth + "✓ VERIFIED"
 */

$pageTitle = "Aadhaar Verification";
require_once __DIR__ . '/../config/config.php';

require_login();

$pdo = get_db_connection();
$userId = get_logged_user_id();
$userRole = get_user_role();

// Fetch current user and profile
$stmtUser = $pdo->prepare("SELECT * FROM `users` WHERE `id` = ?");
$stmtUser->execute([$userId]);
$currentUser = $stmtUser->fetch();

$profileName = '';
$profileDob = '';
$profileGender = 'Male';
$dashboardUrl = BASE_URL;

if ($userRole === 'patient') {
    $stmtPat = $pdo->prepare("SELECT * FROM `patients` WHERE `user_id` = ?");
    $stmtPat->execute([$userId]);
    $patProfile = $stmtPat->fetch();
    $profileName = $patProfile['full_name'] ?? $currentUser['username'];
    $profileDob = !empty($patProfile['dob']) ? date('d/m/Y', strtotime($patProfile['dob'])) : '01/01/2000';
    $profileGender = $patProfile['gender'] ?? 'Male';
    $dashboardUrl = BASE_URL . 'patient/dashboard.php';
} elseif ($userRole === 'doctor') {
    $stmtDoc = $pdo->prepare("SELECT * FROM `doctors` WHERE `user_id` = ?");
    $stmtDoc->execute([$userId]);
    $docProfile = $stmtDoc->fetch();
    $profileName = $docProfile['full_name'] ?? $currentUser['username'];
    $profileDob = '15/08/1985';
    $dashboardUrl = BASE_URL . 'doctor/dashboard.php';
} else {
    $dashboardUrl = BASE_URL . 'hospital/dashboard.php';
}

// Fetch latest verification record
$latestRecord = AadhaarEkycService::getLatestAadhaarEkyc($pdo, $userId);
$currentStatus = AadhaarEkycService::getAadhaarVerificationStatus($pdo, $userId);
$isVerified = ($currentStatus === 'VERIFIED');
$isFailed = ($currentStatus === 'FAILED');

$error = '';
$success = '';

// Handle POST submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token validation failed. Please refresh the page and try again.";
    } else {
        $action = $_POST['action'] ?? 'verify_aadhaar';

        if ($action === 'reset_verification') {
            AadhaarEkycService::resetVerification($pdo, $userId);
            set_flash('info', "Aadhaar verification status has been reset. You can now re-test the verification flow.");
            header("Location: " . BASE_URL . "ekyc/verify_aadhaar.php");
            exit;
        } elseif ($action === 'verify_aadhaar') {
            $clientOcr = trim($_POST['client_ocr_text'] ?? '');
            $clientPhoto = trim($_POST['client_photo_data'] ?? '');

            // Check if user clicked Quick Test with Demo Sample Card
            if (isset($_POST['use_mock_sample']) && $_POST['use_mock_sample'] === '1') {
                $cleanName = preg_replace('/^(dr\.?|doctor|prof\.?)\s+/i', '', trim($profileName));
                $cleanName = preg_replace('/,\s*(md|mbbs|ms|frcs|phd).*$/i', '', $cleanName) ?: 'John Doe';
                $sampleDob = !empty($profileDob) ? $profileDob : '01/01/2000';

                $sampleDir = ROOT_DIR . 'assets' . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR;
                if (!is_dir($sampleDir)) {
                    @mkdir($sampleDir, 0755, true);
                }
                $userSampleXml = $sampleDir . 'sample_aadhaar_user_' . $userId . '.xml';
                file_put_contents($userSampleXml, MockAadhaarGenerator::generateSampleXml($cleanName, $sampleDob, $profileGender));

                $mockFile = [
                    'name'     => 'sample_offline_aadhaar.xml',
                    'type'     => 'text/xml',
                    'tmp_name' => $userSampleXml,
                    'error'    => UPLOAD_ERR_OK,
                    'size'     => filesize($userSampleXml)
                ];

                $tempCopy = tempnam(sys_get_temp_dir(), 'aadhaar_demo_');
                copy($userSampleXml, $tempCopy);
                $mockFile['tmp_name'] = $tempCopy;

                $res = AadhaarEkycService::processUploadAndVerify($pdo, $userId, $mockFile);

                if (file_exists($tempCopy)) {
                    @unlink($tempCopy);
                }

                if ($res['success']) {
                    set_flash('success', "Aadhaar Verification Completed Successfully.");
                    header("Location: " . BASE_URL . "ekyc/verify_aadhaar.php?verified=1");
                    exit;
                } else {
                    $error = $res['message'] ?? "Unable to verify the uploaded Aadhaar document. Please upload a clear valid document.";
                }

            } elseif (isset($_FILES['aadhaar_file']) && !empty($_FILES['aadhaar_file']['name'])) {
                // Real uploaded document (JPG, JPEG, PNG, PDF, XML, ZIP)
                $res = AadhaarEkycService::processUploadAndVerify($pdo, $userId, $_FILES['aadhaar_file'], $clientOcr, $clientPhoto);

                if ($res['success']) {
                    set_flash('success', "Aadhaar Verification Completed Successfully.");
                    header("Location: " . BASE_URL . "ekyc/verify_aadhaar.php?verified=1");
                    exit;
                } else {
                    $error = $res['message'] ?? "Unable to verify the uploaded Aadhaar document. Please upload a clear valid document.";
                }
            } else {
                $error = "Please select an Aadhaar card document (JPG, JPEG, PNG, PDF, XML, or ZIP) to verify.";
            }
        }
    }
}

// Refresh latest record after POST
$latestRecord = AadhaarEkycService::getLatestAadhaarEkyc($pdo, $userId);
$currentStatus = AadhaarEkycService::getAadhaarVerificationStatus($pdo, $userId);
$isVerified = ($currentStatus === 'VERIFIED');
$isFailed = ($currentStatus === 'FAILED');

require_once ROOT_DIR . 'includes/header.php';
?>

<!-- Breadcrumbs Navigation -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb bg-white p-2 px-3 rounded-3 shadow-sm">
        <li class="breadcrumb-item"><a href="<?= $dashboardUrl ?>"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
        <li class="breadcrumb-item active" aria-current="page">Aadhaar Verification</li>
    </ol>
</nav>

<div class="row justify-content-center py-2">
    <div class="col-lg-7 col-md-9">

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($isVerified && $latestRecord): ?>
            <!-- =================================================================== -->
            <!-- STATE A: AADHAAR VERIFIED EXACT EXPECTED RESULT VIEW               -->
            <!-- =================================================================== -->
            <div class="card card-custom shadow-lg border-0 mb-4 overflow-hidden">
                <div class="card-header bg-dark text-white text-center py-4">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-25 rounded-circle p-2 mb-2" style="width: 50px; height: 50px;">
                        <i class="bi bi-person-badge fs-3 text-white"></i>
                    </div>
                    <h3 class="fw-bold mb-0">Aadhaar Verification</h3>
                </div>

                <div class="card-body p-4 p-md-5 text-center">
                    
                    <!-- [PHOTO] - Aadhaar Holder Extracted Photograph -->
                    <div class="mb-4">
                        <div class="position-relative d-inline-block">
                            <?php 
                            $photoSrc = !empty($latestRecord['photo_data']) 
                                ? $latestRecord['photo_data'] 
                                : MockAadhaarGenerator::getSamplePhotoBase64($latestRecord['verified_gender'] ?? $profileGender, $latestRecord['verified_name'] ?? $profileName);
                            ?>
                            <img src="<?= $photoSrc ?>" class="rounded-circle border border-4 border-success shadow-sm" style="width: 140px; height: 140px; object-fit: cover; background: #ffffff;" alt="Aadhaar Holder Photo">
                            <span class="position-absolute bottom-0 end-0 bg-success text-white rounded-circle p-2 border border-2 border-white shadow-sm" style="line-height: 1;" title="Verified Photo">
                                <i class="bi bi-check-lg fs-6"></i>
                            </span>
                        </div>
                        <div class="small text-muted fw-semibold mt-2 text-uppercase tracking-wider">
                            <i class="bi bi-person-bounding-box me-1"></i> Aadhaar Photo
                        </div>
                    </div>

                    <!-- Verified Details List Matching Exact Expected Result -->
                    <div class="card bg-light border-0 shadow-sm p-4 rounded-4 mb-4 text-start">
                        <div class="mb-3 pb-3 border-bottom">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Name of Aadhaar holder:</div>
                            <div class="h4 fw-bold text-dark mb-0"><?= e($latestRecord['verified_name'] ?: $profileName) ?></div>
                        </div>

                        <div class="mb-3 pb-3 border-bottom">
                            <div class="text-muted small text-uppercase fw-bold mb-1">Date of Birth:</div>
                            <div class="h5 fw-bold text-dark mb-0"><?= e($latestRecord['verified_dob'] ?: '01/01/2000') ?></div>
                        </div>

                        <div>
                            <div class="text-muted small text-uppercase fw-bold mb-1">Verification Status:</div>
                            <div class="h4 fw-bold text-success mb-0 d-flex align-items-center">
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2 rounded-pill fs-5">
                                    ✓ VERIFIED
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Vaulted Document Accordion (Optional Inspection) -->
                    <div class="accordion mb-4 text-start" id="vaultAccordion">
                        <div class="accordion-item border rounded-3 overflow-hidden shadow-sm">
                            <h2 class="accordion-header" id="headingVault">
                                <button class="accordion-button collapsed bg-white text-muted fw-semibold small py-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapseVault" aria-expanded="false" aria-controls="collapseVault">
                                    <i class="bi bi-file-earmark-medical me-2 text-primary"></i> View Vaulted Aadhaar Document
                                </button>
                            </h2>
                            <div id="collapseVault" class="accordion-collapse collapse" aria-labelledby="headingVault" data-bs-parent="#vaultAccordion">
                                <div class="accordion-body bg-light text-center p-3">
                                    <?php 
                                    $docUrl = BASE_URL . 'ekyc/view_aadhaar_doc.php?id=' . (int)$latestRecord['id'];
                                    $docType = strtoupper($latestRecord['document_type'] ?? 'PDF');
                                    ?>
                                    <div class="ratio ratio-16x9 bg-dark rounded-3 overflow-hidden border shadow-inner mb-2">
                                        <?php if ($docType === 'PDF'): ?>
                                            <iframe src="<?= $docUrl ?>#toolbar=0&navpanes=0&scrollbar=0" class="w-100 h-100 border-0" title="Aadhaar Document Preview"></iframe>
                                        <?php elseif (in_array($docType, ['JPG', 'JPEG', 'PNG'])): ?>
                                            <img src="<?= $docUrl ?>" class="img-fluid object-fit-contain w-100 h-100 p-2" alt="Aadhaar Card Document">
                                        <?php else: ?>
                                            <div class="d-flex flex-column align-items-center justify-content-center text-white h-100">
                                                <i class="bi bi-filetype-xml fs-1 text-warning mb-2"></i>
                                                <div class="fw-bold">Offline Paperless XML Vaulted</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="<?= $docUrl ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-arrows-fullscreen me-1"></i> Open Full Document in New Window
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Demo Disclaimer -->
                    <div class="alert alert-secondary bg-light border-0 small text-muted mb-4 text-center">
                        <span class="badge bg-primary me-1">DEMO</span> Identity verified under Paperless Offline XML e-KYC specification for academic health exchange demonstration.
                    </div>

                    <!-- Actions -->
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-3 border-top">
                        <a href="<?= $dashboardUrl ?>" class="btn btn-primary px-4 fw-semibold">
                            <i class="bi bi-arrow-left me-1"></i> Return to Dashboard
                        </a>

                        <form method="POST" action="" onsubmit="return confirm('Do you want to reset verification status to re-test the upload flow?');">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="reset_verification">
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reset / Re-verify
                            </button>
                        </form>
                    </div>

                </div>
            </div>

        <?php else: ?>
            <!-- =================================================================== -->
            <!-- STATE B: AADHAAR VERIFICATION UPLOAD FORM VIEW                     -->
            <!-- =================================================================== -->
            <div class="card card-custom shadow-lg border-0 mb-4">
                <div class="card-header bg-dark text-white text-center py-4 rounded-top-3">
                    <div class="d-inline-flex align-items-center justify-content-center bg-primary bg-opacity-25 text-primary rounded-circle p-2 mb-2" style="width: 50px; height: 50px;">
                        <i class="bi bi-person-vcard fs-3 text-white"></i>
                    </div>
                    <h3 class="fw-bold mb-1">Aadhaar Verification</h3>
                    <p class="small text-white-50 mb-0">Offline XML Document e-KYC Identity Verification</p>
                </div>

                <div class="card-body p-4 p-md-5">

                    <!-- Verification Method Selector (Offline XML) -->
                    <div class="mb-4">
                        <label class="form-label fw-bold text-uppercase small text-muted">Verification Method:</label>
                        <div class="p-3 bg-light rounded-3 border border-primary border-opacity-25 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-primary text-white rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="bi bi-filetype-xml fs-5"></i>
                                </div>
                                <div>
                                    <div class="fw-bold text-dark">Offline XML</div>
                                    <div class="small text-muted">Paperless e-KYC document & demographic verification</div>
                                </div>
                            </div>
                            <span class="badge bg-primary text-white px-3 py-2 rounded-pill fw-semibold">Selected Method</span>
                        </div>
                    </div>

                    <!-- Document Upload Form -->
                    <form method="POST" action="" enctype="multipart/form-data" id="aadhaarUploadForm">
                        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                        <input type="hidden" name="action" value="verify_aadhaar">
                        <input type="hidden" name="client_ocr_text" id="clientOcrText" value="">
                        <input type="hidden" name="client_photo_data" id="clientPhotoData" value="">

                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label fw-bold text-dark mb-0">Upload Aadhaar Card:</label>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                    Supported: JPG / JPEG / PNG / PDF / XML / ZIP
                                </span>
                            </div>
                            <p class="text-muted small mb-2">
                                Select your official Aadhaar card image, PDF, or Offline XML document. <em>Note: You do not need to manually enter your Aadhaar number, name, or date of birth.</em>
                            </p>

                            <div class="input-group input-group-lg shadow-sm">
                                <input type="file" name="aadhaar_file" id="aadhaarFileInput" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.xml,.zip" required>
                                <span class="input-group-text bg-white"><i class="bi bi-cloud-arrow-up-fill text-primary"></i></span>
                            </div>
                            <div class="form-text small text-muted mt-1">
                                <i class="bi bi-shield-lock-fill text-success me-1"></i> Max file size: 5MB. Files are vaulted securely in private storage.
                            </div>
                        </div>

                        <!-- Live File Preview Container (for Images / XML) -->
                        <div id="previewCard" class="card bg-light border p-3 mb-4 rounded-3 d-none">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-bold text-muted"><i class="bi bi-eye me-1"></i> Document Preview</span>
                                <span id="fileBadge" class="badge bg-info text-dark"></span>
                            </div>
                            <div class="text-center">
                                <img id="imagePreview" src="" class="img-fluid rounded border shadow-sm d-none" style="max-height: 250px;" alt="Aadhaar Preview">
                                <div id="pdfNotice" class="d-none py-3 text-muted small">
                                    <i class="bi bi-file-earmark-pdf text-danger fs-1 d-block mb-1"></i>
                                    <span id="pdfFileName" class="fw-semibold"></span>
                                </div>
                                <div id="xmlNotice" class="d-none py-3 text-muted small">
                                    <i class="bi bi-filetype-xml text-primary fs-1 d-block mb-1"></i>
                                    <span id="xmlFileName" class="fw-semibold"></span>
                                </div>
                            </div>
                        </div>

                        <!-- Action Button: Verify Aadhaar -->
                        <button type="submit" id="submitVerifyBtn" class="btn btn-primary w-100 py-3 fw-bold fs-5 shadow-sm rounded-3">
                            <i class="bi bi-shield-check me-2"></i> Verify Aadhaar
                        </button>
                    </form>

                    <!-- Academic Demonstration Sample Quick-Test -->
                    <div class="card border-info border-opacity-50 bg-info bg-opacity-10 rounded-3 p-3 mt-4 text-center">
                        <div class="d-flex align-items-center justify-content-center gap-2 mb-1 text-info-emphasis fw-bold small">
                            <i class="bi bi-info-circle-fill"></i> Project Demo & Evaluation Quick Test
                        </div>
                        <p class="small text-secondary mb-2">
                            To test without uploading an external file, you can instantly run verification using a simulated Offline XML Aadhaar document with photo, name, and DOB.
                        </p>
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="verify_aadhaar">
                            <input type="hidden" name="use_mock_sample" value="1">
                            <button type="submit" class="btn btn-outline-primary btn-sm px-4 fw-semibold">
                                <i class="bi bi-lightning-charge-fill me-1"></i> Quick Test with Sample Aadhaar Document
                            </button>
                        </form>
                    </div>

                    <!-- Privacy & Demo Disclaimer Notice -->
                    <div class="alert alert-secondary bg-light border-0 small text-muted mt-4 mb-0 text-center">
                        <i class="bi bi-shield-shaded me-1 text-primary"></i> <strong>Privacy Guarantee:</strong> This application performs document-level verification for academic exchange demonstration. Full Aadhaar numbers are never stored in plain text or shared externally.
                    </div>

                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Tesseract.js for client-side image OCR assistance if user selects an image -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('aadhaarFileInput');
    const previewCard = document.getElementById('previewCard');
    const imagePreview = document.getElementById('imagePreview');
    const pdfNotice = document.getElementById('pdfNotice');
    const pdfFileName = document.getElementById('pdfFileName');
    const xmlNotice = document.getElementById('xmlNotice');
    const xmlFileName = document.getElementById('xmlFileName');
    const fileBadge = document.getElementById('fileBadge');
    const clientOcrField = document.getElementById('clientOcrText');
    const clientPhotoField = document.getElementById('clientPhotoData');
    const submitBtn = document.getElementById('submitVerifyBtn');

    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;

            previewCard.classList.remove('d-none');
            fileBadge.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';

            const fileNameLower = file.name.toLowerCase();

            if (file.type === 'application/pdf' || fileNameLower.endsWith('.pdf')) {
                imagePreview.classList.add('d-none');
                xmlNotice.classList.add('d-none');
                pdfNotice.classList.remove('d-none');
                pdfFileName.textContent = file.name;
            } else if (fileNameLower.endsWith('.xml') || fileNameLower.endsWith('.zip')) {
                imagePreview.classList.add('d-none');
                pdfNotice.classList.add('d-none');
                xmlNotice.classList.remove('d-none');
                xmlFileName.textContent = file.name;
            } else if (file.type.startsWith('image/')) {
                pdfNotice.classList.add('d-none');
                xmlNotice.classList.add('d-none');
                imagePreview.classList.remove('d-none');

                const reader = new FileReader();
                reader.onload = function(evt) {
                    imagePreview.src = evt.target.result;

                    // Automatically crop Aadhaar resident photo on client canvas
                    const img = new Image();
                    img.onload = function() {
                        try {
                            const canvas = document.createElement('canvas');
                            canvas.width = 200;
                            canvas.height = 240;
                            const ctx = canvas.getContext('2d');
                            ctx.fillStyle = '#f8fafc';
                            ctx.fillRect(0, 0, 200, 240);

                            let cx, cy, cw, ch;
                            if (img.naturalHeight >= img.naturalWidth * 0.75) {
                                // Full e-Aadhaar letter sheet (portrait layout)
                                cx = img.naturalWidth * 0.04;
                                cy = img.naturalHeight * 0.765;
                                cw = img.naturalWidth * 0.155;
                                ch = img.naturalHeight * 0.175;
                            } else {
                                // Standard horizontal card / PVC card (landscape)
                                cx = img.naturalWidth * 0.045;
                                cy = img.naturalHeight * 0.22;
                                cw = img.naturalWidth * 0.29;
                                ch = img.naturalHeight * 0.58;
                            }

                            ctx.drawImage(img, cx, cy, cw, ch, 0, 0, 200, 240);
                            const photoData = canvas.toDataURL('image/jpeg', 0.92);
                            clientPhotoField.value = photoData;
                        } catch (err) {
                            console.log('Client photo crop note:', err);
                        }
                    };
                    img.src = evt.target.result;

                    // Run background Tesseract OCR on image to assist backend
                    if (window.Tesseract) {
                        Tesseract.recognize(evt.target.result, 'eng')
                            .then(function(result) {
                                if (result && result.data && result.data.text) {
                                    clientOcrField.value = result.data.text;
                                }
                            })
                            .catch(function(err) {
                                console.log('Client OCR note:', err);
                            });
                    }
                };
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
