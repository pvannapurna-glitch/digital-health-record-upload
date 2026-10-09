<?php
/**
 * Add New Health Record
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Add Health Record";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();
$patientId = get_patient_profile_id($pdo);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF security verification failed. Please try again.";
    } else {
        $title          = trim($_POST['title'] ?? '');
        $recordType     = trim($_POST['record_type'] ?? '');
        $diagnosis      = trim($_POST['diagnosis'] ?? '');
        $prescription   = trim($_POST['prescription'] ?? '');
        $medicalNotes   = trim($_POST['medical_notes'] ?? '');
        $doctorHospital = trim($_POST['doctor_hospital_name'] ?? '');
        $recordDate     = $_POST['record_date'] ?? date('Y-m-d');

        if (empty($title) || empty($recordType) || empty($diagnosis)) {
            $error = "Please fill in all mandatory fields (Title, Record Type, and Diagnosis).";
        } else {
            $fileNameStored = null;
            $fileOriginalName = null;
            $fileSize = 0;

            // Handle file upload if present
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $fileTmp  = $_FILES['attachment']['tmp_name'];
                $origName = basename($_FILES['attachment']['name']);
                $fSize    = (int)$_FILES['attachment']['size'];
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ALLOWED_EXTENSIONS)) {
                    $error = "Invalid file type. Allowed formats: " . implode(', ', ALLOWED_EXTENSIONS);
                } elseif ($fSize > MAX_FILE_SIZE) {
                    $error = "File size exceeds the 10MB limit.";
                } else {
                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0755, true);
                    }
                    $uniqueName = 'doc_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = UPLOAD_DIR . $uniqueName;

                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $fileNameStored = $uniqueName;
                        $fileOriginalName = $origName;
                        $fileSize = $fSize;
                    } else {
                        $error = "Failed to store uploaded medical document.";
                    }
                }
            }

            if (empty($error)) {
                try {
                    $recordUid = generate_depa_uid('REC-UID', 'health_records', 'record_uid', $pdo);

                    $stmt = $pdo->prepare("INSERT INTO `health_records` (`record_uid`, `patient_id`, `created_by_user_id`, `record_type`, `title`, `diagnosis`, `prescription`, `medical_notes`, `doctor_hospital_name`, `record_date`, `file_attachment`, `file_original_name`, `file_size`, `created_at`) 
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([
                        $recordUid,
                        $patientId,
                        $userId,
                        $recordType,
                        $title,
                        $diagnosis,
                        $prescription,
                        $medicalNotes,
                        $doctorHospital,
                        $recordDate,
                        $fileNameStored,
                        $fileOriginalName,
                        $fileSize
                    ]);
                    $recordId = (int)$pdo->lastInsertId();

                    // Sync to Central DEPA Registry
                    sync_registry($pdo, 'RECORD', $recordUid, $title, $recordId, 'ACTIVE');

                    // Audit Log
                    log_audit($pdo, 'RECORD_ADDED', "Added new health record: {$title} ({$recordUid}) [Type: {$recordType}]", $userId, 'patient');

                    set_flash('success', "Health record {$recordUid} successfully created and registered.");
                    header("Location: " . BASE_URL . "patient/my_records.php");
                    exit;

                } catch (Exception $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h3 class="fw-bold mb-1">Add New Health Record</h3>
                <p class="text-secondary small mb-0">Record clinical diagnosis, prescriptions, and upload medical attachments.</p>
            </div>
            <a href="<?= BASE_URL ?>patient/my_records.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Records
            </a>
        </div>

        <div class="card card-custom shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3">
                <span class="fw-bold text-primary"><i class="bi bi-file-earmark-medical me-2"></i>Medical Record Details</span>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Record Title / Summary *</label>
                            <input type="text" name="title" class="form-control" placeholder="e.g. Comprehensive Cardiac Profile & Lipid Panel" required value="<?= e($_POST['title'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Record Category / Type *</label>
                            <select name="record_type" class="form-select" required>
                                <option value="">-- Select Category --</option>
                                <option value="Diagnostic Report" <?= (($_POST['record_type'] ?? '') === 'Diagnostic Report') ? 'selected' : '' ?>>Diagnostic Report</option>
                                <option value="Prescription" <?= (($_POST['record_type'] ?? '') === 'Prescription') ? 'selected' : '' ?>>Prescription</option>
                                <option value="Discharge Summary" <?= (($_POST['record_type'] ?? '') === 'Discharge Summary') ? 'selected' : '' ?>>Discharge Summary</option>
                                <option value="Lab Test" <?= (($_POST['record_type'] ?? '') === 'Lab Test') ? 'selected' : '' ?>>Lab Test</option>
                                <option value="Immunization" <?= (($_POST['record_type'] ?? '') === 'Immunization') ? 'selected' : '' ?>>Immunization</option>
                                <option value="Radiology / Scan" <?= (($_POST['record_type'] ?? '') === 'Radiology / Scan') ? 'selected' : '' ?>>Radiology / Scan</option>
                                <option value="Consultation Note" <?= (($_POST['record_type'] ?? '') === 'Consultation Note') ? 'selected' : '' ?>>Consultation Note</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Attending Doctor / Hospital / Clinic</label>
                            <input type="text" name="doctor_hospital_name" class="form-control" placeholder="e.g. Dr. Rajesh Sharma / Apollo Hospital" value="<?= e($_POST['doctor_hospital_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Record Date *</label>
                            <input type="date" name="record_date" class="form-control" value="<?= e($_POST['record_date'] ?? date('Y-m-d')) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Clinical Diagnosis & Findings *</label>
                        <textarea name="diagnosis" class="form-control" rows="3" placeholder="Detailed clinical evaluation, observations, symptoms, or lab findings..." required><?= e($_POST['diagnosis'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Prescription & Medication Schedule</label>
                        <textarea name="prescription" class="form-control font-monospace" rows="3" placeholder="e.g. Tab Atorvastatin 10mg OD bedtime x 30 days&#10;Tab Metformin 500mg BD after meals"><?= e($_POST['prescription'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Medical Advice & Doctor's Notes</label>
                        <textarea name="medical_notes" class="form-control" rows="2" placeholder="Lifestyle advice, follow-up dates, physical therapy instructions..."><?= e($_POST['medical_notes'] ?? '') ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Upload Document / Report (PDF, JPG, PNG)</label>
                        <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <small class="text-muted">Maximum file size: 10MB. Files are securely protected and not accessible publicly.</small>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= BASE_URL ?>patient/my_records.php" class="btn btn-light px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-cloud-arrow-up-fill me-2"></i> Save & Register Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
