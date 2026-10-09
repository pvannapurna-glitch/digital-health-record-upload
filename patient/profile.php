<?php
/**
 * Patient Profile & ABHA Identity Details
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "My Patient Profile";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();

$stmt = $pdo->prepare("SELECT p.*, u.email, u.username, u.ekyc_status FROM `patients` p JOIN `users` u ON p.user_id = u.id WHERE p.user_id = ?");
$stmt->execute([$userId]);
$patient = $stmt->fetch();

$latestAadhaar = AadhaarEkycService::getLatestAadhaarEkyc($pdo, $userId);
$isAadhaarVerified = ($latestAadhaar && $latestAadhaar['verification_status'] === 'VERIFIED');
$isAadhaarFailed = ($latestAadhaar && $latestAadhaar['verification_status'] === 'FAILED');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $action = $_POST['action'] ?? 'update_profile';

        if ($action === 'upload_signature') {
            // Validate signature upload
            if (!isset($_FILES['signature_file']) || $_FILES['signature_file']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = "Please choose a signature image file to upload.";
            } elseif ($_FILES['signature_file']['error'] !== UPLOAD_ERR_OK) {
                $error = "File upload failed with error code: " . $_FILES['signature_file']['error'];
            } else {
                $file = $_FILES['signature_file'];
                $maxBytes = 2 * 1024 * 1024; // 2MB
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowedExts = ['png', 'jpg', 'jpeg'];
                $allowedMimes = ['image/png', 'image/jpeg'];

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($ext, $allowedExts) || !in_array($mimeType, $allowedMimes)) {
                    $error = "Invalid file type. Only PNG, JPG, and JPEG images are allowed.";
                } elseif ($file['size'] > $maxBytes) {
                    $error = "File exceeds the 2MB size limit. Please upload a smaller image.";
                } else {
                    $imgInfo = @getimagesize($file['tmp_name']);
                    if (!$imgInfo) {
                        $error = "The uploaded file is not a valid image.";
                    } else {
                        $width = $imgInfo[0];
                        $height = $imgInfo[1];

                        if ($width < 50 || $height < 20) {
                            $error = "Image dimensions too small (minimum 50x20 pixels required).";
                        } elseif ($width > 2500 || $height > 1500) {
                            $error = "Image dimensions too large (maximum 2500x1500 pixels allowed).";
                        } else {
                            // Ensure upload directory exists
                            if (!is_dir(SIGNATURE_UPLOAD_DIR)) {
                                mkdir(SIGNATURE_UPLOAD_DIR, 0755, true);
                            }

                            // Generate safe unique filename
                            $newFilename = "sig_pat_" . $patient['id'] . "_" . bin2hex(random_bytes(6)) . "." . $ext;
                            $destination = SIGNATURE_UPLOAD_DIR . $newFilename;

                            if (move_uploaded_file($file['tmp_name'], $destination)) {
                                // Delete old signature if one exists
                                if (!empty($patient['signature_image'])) {
                                    $oldFile = SIGNATURE_UPLOAD_DIR . basename($patient['signature_image']);
                                    if (file_exists($oldFile)) {
                                        @unlink($oldFile);
                                    }
                                }

                                $isUpdate = !empty($patient['signature_image']);
                                $stmtUpSig = $pdo->prepare("UPDATE `patients` SET `signature_image` = ?, `signature_updated_at` = NOW() WHERE `id` = ?");
                                $stmtUpSig->execute([$newFilename, $patient['id']]);

                                $auditAction = $isUpdate ? 'SIGNATURE_UPDATED' : 'SIGNATURE_UPLOADED';
                                log_audit($pdo, $auditAction, "Patient {$patient['full_name']} {$auditAction} successfully (File: {$newFilename}).", $userId, 'patient');

                                set_flash('success', $isUpdate ? 'Signature updated successfully.' : 'Signature uploaded successfully.');
                                header("Location: " . BASE_URL . "patient/profile.php");
                                exit;
                            } else {
                                $error = "Failed to save the uploaded signature file on the server.";
                            }
                        }
                    }
                }
            }
        } else {
            // Update contact information
            $phone             = trim($_POST['phone'] ?? '');
            $address           = trim($_POST['address'] ?? '');
            $emergencyContact  = trim($_POST['emergency_contact'] ?? '');

            if (empty($phone)) {
                $error = "Phone number is mandatory.";
            } else {
                $stmtUp = $pdo->prepare("UPDATE `patients` SET `phone` = ?, `address` = ?, `emergency_contact` = ? WHERE `user_id` = ?");
                $stmtUp->execute([$phone, $address, $emergencyContact, $userId]);

                log_audit($pdo, 'PROFILE_UPDATED', "Patient {$patient['full_name']} updated demographic contact info.", $userId, 'patient');

                set_flash('success', 'Profile updated successfully.');
                header("Location: " . BASE_URL . "patient/profile.php");
                exit;
            }
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <!-- ABHA Card Banner -->
        <div class="abha-card mb-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="text-uppercase small text-white-50 fw-bold">National Digital Health Mission</div>
                    <div class="fw-bold fs-5">Ayushman Bharat Health Account (ABHA)</div>
                </div>
                <div class="bg-white p-2 rounded-3 shadow-sm">
                    <i class="bi bi-qr-code text-dark fs-2"></i>
                </div>
            </div>
            <div class="mb-3">
                <div class="text-white-50 small">ABHA / DEPA Health Number</div>
                <div class="abha-number"><?= e($patient['patient_uid']) ?></div>
            </div>
            <div class="row g-2 text-white-50 small border-top border-white border-opacity-25 pt-2">
                <div class="col-6">
                    <span class="d-block text-white fw-semibold fs-6"><?= e($patient['full_name']) ?></span>
                    <span>DOB: <?= e(date('d-m-Y', strtotime($patient['dob']))) ?> (<?= e($patient['gender']) ?>)</span>
                </div>
                <div class="col-6 text-end">
                    <span class="badge bg-light text-dark fw-bold px-2 py-1">Blood Group: <?= e($patient['blood_group']) ?></span>
                    <span class="d-block mt-1"><?= e($patient['phone']) ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- My Digital Signature Section -->
        <div class="card card-custom shadow-sm border-0 mb-4 overflow-hidden" id="digitalSignatureSection">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark">
                    <i class="bi bi-pen-fill me-2 text-primary"></i>My Digital Signature
                </span>
                <?php if (!empty($patient['signature_image'])): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold">
                        <i class="bi bi-patch-check-fill me-1"></i>SIGNATURE SAVED
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-bold">
                        <i class="bi bi-exclamation-circle me-1"></i>SIGNATURE REQUIRED
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <div class="row align-items-center g-4">
                    <div class="col-md-5 text-center">
                        <div class="p-3 bg-light rounded-3 border d-flex flex-column align-items-center justify-content-center" style="min-height: 150px;">
                            <?php if (!empty($patient['signature_image'])): ?>
                                <img src="<?= BASE_URL ?>patient/view_signature.php?v=<?= !empty($patient['signature_updated_at']) ? strtotime($patient['signature_updated_at']) : time() ?>" 
                                     alt="Signature Preview" 
                                     class="img-fluid border rounded p-2 bg-white shadow-sm mb-2" 
                                     style="max-height: 100px; max-width: 100%; object-fit: contain;">
                                <div class="text-muted small">
                                    <i class="bi bi-calendar-check me-1"></i>Saved: <?= format_date($patient['signature_updated_at'] ?? $patient['created_at']) ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted py-3">
                                    <i class="bi bi-pen display-5 d-block text-secondary opacity-50 mb-2"></i>
                                    <span class="small fw-semibold">No signature uploaded yet</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <h6 class="fw-bold mb-1">
                            <?= !empty($patient['signature_image']) ? 'Change Signature' : 'Upload Signature' ?>
                        </h6>
                        <p class="text-muted small mb-3">
                            Upload a clear image of your handwritten signature. This signature image is used to digitally sign clinical consent requests for this project.
                        </p>

                        <form method="POST" action="" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                            <input type="hidden" name="action" value="upload_signature">

                            <div class="mb-3">
                                <label class="form-label small fw-semibold text-secondary">Select Signature Image (PNG, JPG, max 2MB)</label>
                                <input type="file" name="signature_file" class="form-control form-control-sm" accept=".png,.jpg,.jpeg" required>
                                <div class="form-text small">Recommended: Clean black or dark blue ink on a plain white or transparent background.</div>
                            </div>

                            <button type="submit" class="btn btn-sm <?= !empty($patient['signature_image']) ? 'btn-outline-primary' : 'btn-primary' ?> px-3 fw-semibold">
                                <i class="bi <?= !empty($patient['signature_image']) ? 'bi-arrow-repeat' : 'bi-upload' ?> me-1"></i>
                                <?= !empty($patient['signature_image']) ? 'Change Signature' : 'Upload Signature' ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="mt-3 pt-3 border-top">
                    <div class="text-muted small fst-italic">
                        <i class="bi bi-info-circle me-1 text-primary"></i>
                        Digital Signature Demo: This signature is stored and applied to the consent document for project demonstration purposes.
                    </div>
                </div>
            </div>
        </div>

        <!-- Aadhaar Card Document Verification (eKYC) Section -->
        <div class="card card-custom shadow-sm border-0 mb-4 overflow-hidden">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <span class="fw-bold text-dark"><i class="bi bi-person-vcard me-2 text-primary"></i>Aadhaar Card Document Verification (eKYC)</span>
                <?php if ($isAadhaarVerified): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill fw-bold">
                        <i class="bi bi-patch-check-fill me-1"></i>VERIFIED
                    </span>
                <?php elseif ($isAadhaarFailed): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 rounded-pill fw-bold">
                        <i class="bi bi-x-circle me-1"></i>FAILED
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle px-3 py-1 rounded-pill fw-bold">
                        <i class="bi bi-hourglass-split me-1"></i>PENDING
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-4">
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <?php if ($isAadhaarVerified): ?>
                            <div class="d-flex align-items-center mb-2">
                                <i class="bi bi-shield-check text-success fs-3 me-2"></i>
                                <div>
                                    <strong class="text-success">Aadhaar Document eKYC Verified</strong>
                                    <div class="text-muted small">Your identity document has been verified via OCR against your demographic profile.</div>
                                </div>
                            </div>
                            <div class="bg-light p-3 rounded-3 border small">
                                <div class="row g-2">
                                    <div class="col-sm-4"><strong class="text-secondary">Name:</strong> ********</div>
                                    <div class="col-sm-4"><strong class="text-secondary">DOB:</strong> ********</div>
                                    <div class="col-sm-4"><strong class="text-secondary">Gender:</strong> ********</div>
                                </div>
                            </div>
                        <?php elseif ($isAadhaarFailed): ?>
                            <div class="text-danger small">
                                <i class="bi bi-exclamation-circle-fill me-1"></i> Last document verification attempt was unsuccessful. Please re-upload a clear Aadhaar card document.
                            </div>
                        <?php else: ?>
                            <div class="text-secondary small">
                                <i class="bi bi-info-circle me-1 text-primary"></i> Complete zero-typing Aadhaar Card document verification to activate full DEPA data exchange rights.
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <a href="<?= BASE_URL ?>patient/aadhaar_ekyc.php" class="btn <?= $isAadhaarVerified ? 'btn-outline-primary' : 'btn-primary' ?> px-3 fw-semibold">
                            <i class="bi <?= $isAadhaarVerified ? 'bi-eye' : 'bi-upload' ?> me-1"></i> 
                            <?= $isAadhaarVerified ? 'View Aadhaar eKYC' : 'Verify Aadhaar Card' ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Contact Information -->
        <div class="card card-custom shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3">
                <span class="fw-bold text-primary"><i class="bi bi-person-gear me-2"></i>Edit Contact Information</span>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                    <input type="hidden" name="action" value="update_profile">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted">Legal Name (Registry Locked)</label>
                            <input type="text" class="form-control bg-light" value="<?= e($patient['full_name']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted">DEPA Health ID (ABHA)</label>
                            <input type="text" class="form-control bg-light font-monospace" value="<?= e($patient['patient_uid']) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-muted">Date of Birth</label>
                            <input type="text" class="form-control bg-light" value="<?= e(date('d F Y', strtotime($patient['dob']))) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-muted">Gender</label>
                            <input type="text" class="form-control bg-light" value="<?= e($patient['gender']) ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-muted">Blood Group</label>
                            <input type="text" class="form-control bg-light" value="<?= e($patient['blood_group']) ?>" readonly>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone Number *</label>
                            <input type="tel" name="phone" class="form-control" required value="<?= e($patient['phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Emergency Contact</label>
                            <input type="text" name="emergency_contact" class="form-control" value="<?= e($patient['emergency_contact']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Residential Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= e($patient['address']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-check-lg me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
