<?php
/**
 * Edit Existing Health Record
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Edit Health Record";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();
$patientId = get_patient_profile_id($pdo);
$recordId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fetch record verifying patient ownership
$stmt = $pdo->prepare("SELECT * FROM `health_records` WHERE `id` = ? AND `patient_id` = ?");
$stmt->execute([$recordId, $patientId]);
$record = $stmt->fetch();

if (!$record) {
    set_flash('danger', 'Health record not found or unauthorized.');
    header("Location: " . BASE_URL . "patient/my_records.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $title          = trim($_POST['title'] ?? '');
        $recordType     = trim($_POST['record_type'] ?? '');
        $diagnosis      = trim($_POST['diagnosis'] ?? '');
        $prescription   = trim($_POST['prescription'] ?? '');
        $medicalNotes   = trim($_POST['medical_notes'] ?? '');
        $doctorHospital = trim($_POST['doctor_hospital_name'] ?? '');
        $recordDate     = $_POST['record_date'] ?? $record['record_date'];

        if (empty($title) || empty($recordType) || empty($diagnosis)) {
            $error = "Title, Record Type, and Diagnosis are mandatory.";
        } else {
            $fileNameStored   = $record['file_attachment'];
            $fileOriginalName = $record['file_original_name'];
            $fileSize         = $record['file_size'];

            // Replace file if new one uploaded
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $fileTmp  = $_FILES['attachment']['tmp_name'];
                $origName = basename($_FILES['attachment']['name']);
                $fSize    = (int)$_FILES['attachment']['size'];
                $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ALLOWED_EXTENSIONS)) {
                    $error = "Invalid file type. Allowed: " . implode(', ', ALLOWED_EXTENSIONS);
                } elseif ($fSize > MAX_FILE_SIZE) {
                    $error = "File exceeds 10MB limit.";
                } else {
                    $uniqueName = 'doc_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $targetPath = UPLOAD_DIR . $uniqueName;

                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        // Delete old file if present
                        if (!empty($record['file_attachment']) && file_exists(UPLOAD_DIR . $record['file_attachment'])) {
                            @unlink(UPLOAD_DIR . $record['file_attachment']);
                        }
                        $fileNameStored = $uniqueName;
                        $fileOriginalName = $origName;
                        $fileSize = $fSize;
                    } else {
                        $error = "Failed to save uploaded file.";
                    }
                }
            }

            if (empty($error)) {
                try {
                    $stmtUpdate = $pdo->prepare("UPDATE `health_records` 
                                                 SET `record_type` = ?, `title` = ?, `diagnosis` = ?, `prescription` = ?, 
                                                     `medical_notes` = ?, `doctor_hospital_name` = ?, `record_date` = ?, 
                                                     `file_attachment` = ?, `file_original_name` = ?, `file_size` = ? 
                                                 WHERE `id` = ? AND `patient_id` = ?");
                    $stmtUpdate->execute([
                        $recordType,
                        $title,
                        $diagnosis,
                        $prescription,
                        $medicalNotes,
                        $doctorHospital,
                        $recordDate,
                        $fileNameStored,
                        $fileOriginalName,
                        $fileSize,
                        $recordId,
                        $patientId
                    ]);

                    // Sync Registry Name
                    sync_registry($pdo, 'RECORD', $record['record_uid'], $title, $recordId, 'ACTIVE');

                    // Audit Log
                    log_audit($pdo, 'RECORD_UPDATED', "Updated health record: {$title} ({$record['record_uid']})", $userId, 'patient');

                    set_flash('success', "Health record {$record['record_uid']} updated successfully.");
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
                <h3 class="fw-bold mb-1">Edit Health Record</h3>
                <span class="badge bg-light text-primary border font-monospace"><?= e($record['record_uid']) ?></span>
            </div>
            <a href="<?= BASE_URL ?>patient/my_records.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Records
            </a>
        </div>

        <div class="card card-custom shadow-sm border-0 mb-4">
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
                            <label class="form-label fw-semibold">Record Title *</label>
                            <input type="text" name="title" class="form-control" required value="<?= e($_POST['title'] ?? $record['title']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Record Category *</label>
                            <select name="record_type" class="form-select" required>
                                <?php 
                                $types = ['Diagnostic Report', 'Prescription', 'Discharge Summary', 'Lab Test', 'Immunization', 'Radiology / Scan', 'Consultation Note'];
                                $currType = $_POST['record_type'] ?? $record['record_type'];
                                foreach ($types as $t): 
                                ?>
                                    <option value="<?= $t ?>" <?= ($currType === $t) ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Attending Doctor / Hospital</label>
                            <input type="text" name="doctor_hospital_name" class="form-control" value="<?= e($_POST['doctor_hospital_name'] ?? $record['doctor_hospital_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Record Date *</label>
                            <input type="date" name="record_date" class="form-control" value="<?= e($_POST['record_date'] ?? $record['record_date']) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Clinical Diagnosis & Findings *</label>
                        <textarea name="diagnosis" class="form-control" rows="3" required><?= e($_POST['diagnosis'] ?? $record['diagnosis']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Prescription</label>
                        <textarea name="prescription" class="form-control font-monospace" rows="3"><?= e($_POST['prescription'] ?? $record['prescription']) ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Medical Advice & Notes</label>
                        <textarea name="medical_notes" class="form-control" rows="2"><?= e($_POST['medical_notes'] ?? $record['medical_notes']) ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Replace Attached Document</label>
                        <?php if (!empty($record['file_attachment'])): ?>
                            <div class="small text-muted mb-2">
                                Current file: <strong><?= e($record['file_original_name']) ?></strong> (<?= round($record['file_size'] / 1024, 1) ?> KB)
                            </div>
                        <?php endif; ?>
                        <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <small class="text-muted">Leave empty to keep existing file.</small>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= BASE_URL ?>patient/my_records.php" class="btn btn-light px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-save me-2"></i> Update Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
