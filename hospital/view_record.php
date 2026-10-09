<?php
/**
 * Consent-Guarded Clinical Record Viewer (Hospital)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Hospital Record Inspection";
require_once __DIR__ . '/../config/config.php';

require_role('hospital');

$pdo = get_db_connection();
$userId = get_logged_user_id();
$recordId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($recordId <= 0) {
    set_flash('danger', 'Invalid health record requested.');
    header("Location: " . BASE_URL . "hospital/authorized_records.php");
    exit;
}

// Fetch record & patient details
$stmt = $pdo->prepare("SELECT hr.*, p.id AS patient_profile_id, p.full_name AS patient_name, p.patient_uid, 
                              p.dob, p.gender, p.blood_group, p.phone, p.emergency_contact
                       FROM `health_records` hr
                       JOIN `patients` p ON hr.patient_id = p.id
                       WHERE hr.id = ?");
$stmt->execute([$recordId]);
$record = $stmt->fetch();

if (!$record) {
    set_flash('danger', 'Health record not found in system.');
    header("Location: " . BASE_URL . "hospital/authorized_records.php");
    exit;
}

// DEPA CONSENT ENFORCEMENT CHECK
$depaAuth = validate_depa_consent($pdo, $userId, $record['patient_profile_id'], $record['id']);

if (!$depaAuth['allowed']) {
    log_audit($pdo, 'UNAUTHORIZED_ACCESS_ATTEMPT', "Hospital attempted to view Record ID {$recordId} ({$record['record_uid']}) without valid consent. {$depaAuth['reason']}", $userId, 'hospital');

    require_once ROOT_DIR . 'includes/header.php';
    ?>
    <div class="row justify-content-center py-5">
        <div class="col-md-8 col-lg-6">
            <div class="card card-custom shadow-lg border-danger text-center p-4">
                <div class="card-body">
                    <div class="d-inline-flex p-3 rounded-circle bg-danger bg-opacity-10 text-danger mb-3">
                        <i class="bi bi-shield-x display-3"></i>
                    </div>
                    <h3 class="text-danger fw-bold mb-2">DEPA Access Prohibited (403)</h3>
                    <p class="text-secondary mb-3">
                        Your facility does not possess valid electronic consent to examine this patient's health data.
                    </p>
                    <div class="alert alert-danger text-start p-3 mb-4">
                        <strong>DEPA Validation Feedback:</strong>
                        <div class="mt-1"><?= e($depaAuth['reason']) ?></div>
                    </div>
                    <div class="d-flex justify-content-center gap-2">
                        <a href="<?= BASE_URL ?>hospital/request_consent.php?patient_id=<?= $record['patient_profile_id'] ?>" class="btn btn-success fw-semibold px-4">
                            <i class="bi bi-shield-plus me-1"></i> Request Patient Consent
                        </a>
                        <a href="<?= BASE_URL ?>hospital/dashboard.php" class="btn btn-outline-secondary px-4">
                            Return to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once ROOT_DIR . 'includes/footer.php';
    exit;
}

// Successful Authorization
$consent = $depaAuth['consent'];

// Fetch eSign Verification Record
$esignRecord = get_esign_for_consent($pdo, $consent['id']);

// Log access in immutable audit trail
log_audit($pdo, 'RECORD_ACCESSED', "Hospital staff examined record {$record['record_uid']} ({$record['title']}) for patient {$record['patient_name']} under active consent {$consent['consent_uid']}.", $userId, 'hospital');

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-10">
        <!-- Active Consent & eSign Verification Banner -->
        <div class="alert alert-success border-success shadow-sm d-flex flex-wrap align-items-center justify-content-between p-3 mb-4">
            <div class="d-flex align-items-center mb-2 mb-md-0">
                <i class="bi bi-patch-check-fill text-success fs-2 me-3"></i>
                <div>
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="fw-bold mb-0 text-success">Authorized via Active DEPA Consent: <?= e($consent['consent_uid']) ?></h6>
                        <span class="badge bg-success text-white font-monospace"><i class="bi bi-pen me-1"></i>eSign: <?= e($esignRecord['esign_uid'] ?? 'Signed') ?></span>
                    </div>
                    <small class="text-secondary d-block mt-1">
                        <strong>Institutional Purpose:</strong> <?= e($consent['purpose']) ?> | 
                        <strong>Valid Until:</strong> <?= format_date($consent['expiry_date']) ?> |
                        <strong>Signed By:</strong> <?= e($record['patient_name']) ?> (<?= format_date($esignRecord['signed_at'] ?? $consent['decision_date']) ?>)
                    </small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <span class="badge bg-success px-3 py-2 fw-semibold align-self-center">
                    <i class="bi bi-unlock-fill me-1"></i> Access Active
                </span>
            </div>
        </div>

        <!-- Patient Demographics Bar -->
        <div class="card card-custom p-4 mb-4 shadow-sm border-0">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <div class="d-flex align-items-center mb-1">
                        <h4 class="fw-bold mb-0 me-3 text-dark"><?= e($record['patient_name']) ?></h4>
                        <span class="badge bg-light text-primary border font-monospace"><?= e($record['patient_uid']) ?></span>
                    </div>
                    <div class="text-secondary small">
                        <span><i class="bi bi-calendar3 me-1"></i> DOB: <?= e(date('d M Y', strtotime($record['dob']))) ?> (<?= e($record['gender']) ?>)</span> | 
                        <span><i class="bi bi-droplet-fill text-danger me-1"></i> Blood: <strong><?= e($record['blood_group']) ?></strong></span> | 
                        <span><i class="bi bi-telephone me-1"></i> Phone: <?= e($record['phone']) ?></span>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="<?= BASE_URL ?>hospital/authorized_records.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i> Back to Authorized List
                    </a>
                </div>
            </div>
        </div>

        <!-- Clinical Record Main Card -->
        <div class="card card-custom shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-secondary-subtle text-secondary border me-2"><?= e($record['record_type']) ?></span>
                    <span class="fw-bold fs-5 text-dark"><?= e($record['title']) ?></span>
                </div>
                <span class="text-muted small">
                    <i class="bi bi-calendar-check me-1"></i> Date: <?= e(date('d F Y', strtotime($record['record_date']))) ?>
                </span>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 mb-4 border-bottom pb-3">
                    <div class="col-sm-6">
                        <small class="text-muted d-block">DEPA Record Identifier</small>
                        <span class="font-monospace fw-bold text-primary"><?= e($record['record_uid']) ?></span>
                    </div>
                    <div class="col-sm-6">
                        <small class="text-muted d-block">Originating Hospital / Attending Practitioner</small>
                        <span class="fw-semibold text-dark"><?= e($record['doctor_hospital_name'] ?: 'Not Specified') ?></span>
                    </div>
                </div>

                <!-- Diagnosis -->
                <div class="mb-4">
                    <h6 class="fw-bold text-dark"><i class="bi bi-heart-pulse-fill text-danger me-2"></i>Clinical Diagnosis & Findings</h6>
                    <div class="p-3 bg-light rounded-3 border">
                        <?= nl2br(e($record['diagnosis'])) ?>
                    </div>
                </div>

                <!-- Prescription -->
                <?php if (!empty($record['prescription'])): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark"><i class="bi bi-capsule text-primary me-2"></i>Prescriptions & Medication Regimen</h6>
                        <div class="p-3 bg-light rounded-3 border font-monospace">
                            <?= nl2br(e($record['prescription'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Medical Notes -->
                <?php if (!empty($record['medical_notes'])): ?>
                    <div class="mb-4">
                        <h6 class="fw-bold text-dark"><i class="bi bi-journal-medical text-secondary me-2"></i>Clinical Advice & Notes</h6>
                        <div class="p-3 bg-light rounded-3 border text-secondary">
                            <?= nl2br(e($record['medical_notes'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Document Attachment (Protected DEPA stream) -->
                <?php if (!empty($record['file_attachment'])): ?>
                    <div class="p-4 bg-success bg-opacity-10 rounded-3 border border-success border-opacity-25 d-flex flex-wrap justify-content-between align-items-center">
                        <div class="d-flex align-items-center mb-2 mb-sm-0">
                            <i class="bi bi-file-earmark-pdf-fill text-success display-6 me-3"></i>
                            <div>
                                <h6 class="fw-bold mb-0 text-dark"><?= e($record['file_original_name'] ?: 'Clinical Document Attachment') ?></h6>
                                <small class="text-muted">Size: <?= round($record['file_size'] / 1024, 1) ?> KB | Consent-Protected Binary Stream</small>
                            </div>
                        </div>
                        <a href="<?= BASE_URL ?>includes/view_document.php?id=<?= $record['id'] ?>" target="_blank" class="btn btn-success fw-semibold px-4 shadow-sm">
                            <i class="bi bi-file-earmark-arrow-down me-1"></i> Examine Medical Document
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-light py-3 text-muted small text-center">
                <i class="bi bi-shield-lock me-1"></i> Institutional access logged under DEPA audit protocol.
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
