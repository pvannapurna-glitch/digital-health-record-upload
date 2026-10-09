<?php
/**
 * Initiate DEPA Consent Request (Doctor)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Request Health Record Access";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
require_ekyc_verified($pdo);
$userId = get_logged_user_id();

// Fetch all active patients
$stmtPatients = $pdo->query("SELECT p.*, u.email FROM `patients` p JOIN `users` u ON p.user_id = u.id WHERE u.status = 'active' ORDER BY p.full_name ASC");
$allPatients = $stmtPatients->fetchAll();

$selectedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : (int)($_POST['patient_id'] ?? 0);

// Fetch records for the selected patient if one is chosen
$patientRecords = [];
if ($selectedPatientId > 0) {
    $stmtRecs = $pdo->prepare("SELECT id, record_uid, title, record_type, record_date FROM `health_records` WHERE `patient_id` = ? ORDER BY `record_date` DESC");
    $stmtRecs->execute([$selectedPatientId]);
    $patientRecords = $stmtRecs->fetchAll();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $patientId       = (int)($_POST['patient_id'] ?? 0);
        $recordId        = !empty($_POST['record_id']) ? (int)$_POST['record_id'] : null;
        $purpose         = trim($_POST['purpose'] ?? '');
        $accessStartDate = !empty($_POST['access_start_date']) ? $_POST['access_start_date'] : date('Y-m-d H:i:s');
        $expiryDate      = $_POST['expiry_date'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));

        if ($patientId <= 0 || empty($purpose) || empty($expiryDate)) {
            $error = "Please select a patient, clinical purpose, and validity expiry period.";
        } else {
            try {
                $consentUid = generate_depa_uid('CNST', 'consent_requests', 'consent_uid', $pdo);

                $stmtInsert = $pdo->prepare("INSERT INTO `consent_requests` 
                                             (`consent_uid`, `patient_id`, `requester_user_id`, `requester_role`, `record_id`, `purpose`, `access_start_date`, `requested_date`, `expiry_date`, `status`, `esign_status`, `esign_provider`, `created_at`) 
                                             VALUES (?, ?, ?, 'doctor', ?, ?, ?, NOW(), ?, 'Pending', 'Not Started', 'local_signature', NOW())");
                $stmtInsert->execute([
                    $consentUid,
                    $patientId,
                    $userId,
                    $recordId,
                    $purpose,
                    $accessStartDate,
                    $expiryDate
                ]);
                $consentId = (int)$pdo->lastInsertId();

                // Fetch Patient Name for log
                $stmtP = $pdo->prepare("SELECT full_name, patient_uid FROM `patients` WHERE `id` = ?");
                $stmtP->execute([$patientId]);
                $pat = $stmtP->fetch();

                // Sync Registry
                sync_registry($pdo, 'CONSENT', $consentUid, "Consent {$consentUid} [Doctor -> {$pat['full_name']}]", $consentId, 'ACTIVE');

                // Audit Log
                log_audit($pdo, 'CONSENT_REQUESTED', "Doctor requested DEPA consent {$consentUid} for patient {$pat['full_name']} ({$pat['patient_uid']}). Purpose: {$purpose}", $userId, 'doctor');

                set_flash('success', "Consent request {$consentUid} dispatched to patient {$pat['full_name']}. Patient digital signature is pending.");
                header("Location: " . BASE_URL . "doctor/my_requests.php");
                exit;

            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h3 class="fw-bold mb-1">Request Health Record Permission</h3>
                <p class="text-secondary small mb-0">Submit a purpose-bound access request to a registered patient's health vault.</p>
            </div>
            <a href="<?= BASE_URL ?>doctor/search_patients.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-search me-1"></i> Patient Directory
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

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                    <!-- 1. Select Patient -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">1. Select Target Patient (Data Principal) *</label>
                        <select name="patient_id" id="patient_select" class="form-select select-patient" required onchange="window.location='<?= BASE_URL ?>doctor/request_consent.php?patient_id=' + this.value">
                            <option value="">-- Choose Patient from Registry --</option>
                            <?php foreach ($allPatients as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($selectedPatientId === (int)$p['id']) ? 'selected' : '' ?>>
                                    <?= e($p['full_name']) ?> (<?= e($p['patient_uid']) ?>) - Blood: <?= e($p['blood_group']) ?> - Ph: <?= e($p['phone']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 2. Record Scope -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">2. Health Record Scope *</label>
                        <select name="record_id" class="form-select">
                            <option value="">Full Health Vault (All Current & Future Records)</option>
                            <?php if (!empty($patientRecords)): ?>
                                <optgroup label="Specific Patient Health Artifacts">
                                    <?php foreach ($patientRecords as $rec): ?>
                                        <option value="<?= $rec['id'] ?>">
                                            [<?= e($rec['record_type']) ?>] <?= e($rec['title']) ?> (<?= e($rec['record_uid']) ?> - <?= date('d M Y', strtotime($rec['record_date'])) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php elseif ($selectedPatientId > 0): ?>
                                <option value="" disabled>No individual records uploaded by this patient yet</option>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted">You can request access to a single diagnostic report or comprehensive medical history.</small>
                    </div>

                    <!-- 3. Clinical Purpose -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">3. Purpose of Access *</label>
                        <textarea name="purpose" class="form-control" rows="3" placeholder="State clear medical justification (e.g. Cardiology follow-up consultation, ECG evaluation, pre-op verification, second opinion)..." required><?= e($_POST['purpose'] ?? '') ?></textarea>
                    </div>

                    <div class="row g-3 mb-4">
                        <!-- 4. Access Start Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">4. Access Start Date *</label>
                            <input type="datetime-local" name="access_start_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>" required>
                            <small class="text-muted">Access begins on this date once authorized.</small>
                        </div>

                        <!-- 5. Access Expiry Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">5. Access Expiry Date *</label>
                            <input type="datetime-local" name="expiry_date" class="form-control min-today" value="<?= date('Y-m-d\TH:i', strtotime('+7 days')) ?>" required>
                            <small class="text-muted">Access automatically ceases immediately once expired.</small>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= BASE_URL ?>doctor/dashboard.php" class="btn btn-light px-4">Cancel</a>
                        <button type="submit" class="btn btn-primary px-4 fw-semibold shadow-sm">
                            <i class="bi bi-send-fill me-2"></i> Request Permission
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
