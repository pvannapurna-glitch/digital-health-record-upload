<?php
/**
 * Hospital Authorized Health Records
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Hospital Authorized Records";
require_once __DIR__ . '/../config/config.php';

require_role('hospital');

$pdo = get_db_connection();
$userId = get_logged_user_id();

// Auto expire passed consents
auto_expire_consents($pdo);

$patientFilterId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

// Query all health records for which this hospital has an active Approved/Active eSigned consent
$query = "SELECT DISTINCT hr.*, p.full_name AS patient_name, p.patient_uid, p.blood_group, p.gender, p.dob,
                          cr.consent_uid, cr.expiry_date, cr.purpose AS consent_purpose, cr.esign_status,
                          es.esign_uid, es.signature_hash, es.signed_at AS esigned_at
          FROM `health_records` hr
          JOIN `patients` p ON hr.patient_id = p.id
          JOIN `consent_requests` cr ON (
              cr.patient_id = p.id 
              AND cr.requester_user_id = ? 
              AND cr.status IN ('Approved', 'Active') 
              AND cr.is_esigned = 1
              AND cr.expiry_date >= NOW() 
              AND (cr.record_id = hr.id OR cr.record_id IS NULL)
          )
          LEFT JOIN `esign_records` es ON cr.id = es.consent_id";

$params = [$userId];

if ($patientFilterId > 0) {
    $query .= " WHERE hr.patient_id = ?";
    $params[] = $patientFilterId;
}

$query .= " ORDER BY hr.record_date DESC, hr.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$authorizedRecords = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Hospital Authorized Patient Records</h3>
        <p class="text-secondary small mb-0">Clinical documents accessible under active institutional DEPA electronic consent grants.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>hospital/search_patients.php" class="btn btn-outline-success btn-sm fw-semibold">
            <i class="bi bi-search me-1"></i> Patient Registry
        </a>
    </div>
</div>

<?php if (empty($authorizedRecords)): ?>
    <div class="card card-custom p-5 text-center shadow-sm border-0 mb-4">
        <i class="bi bi-hospital display-3 text-muted mb-3"></i>
        <h4 class="text-secondary">No Active Authorizations</h4>
        <p class="text-muted small" style="max-width: 500px; margin: 0 auto 1.5rem;">
            The hospital does not currently possess active DEPA consent for any patient health records.
        </p>
        <div>
            <a href="<?= BASE_URL ?>hospital/search_patients.php" class="btn btn-success px-4 fw-semibold shadow-sm">
                <i class="bi bi-search me-1"></i> Search Patient Registry
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="card card-custom mb-4 shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <div class="row align-items-center g-3">
                <div class="col-md-6">
                    <span class="fw-bold fs-5 text-success">
                        <i class="bi bi-shield-check me-2"></i>Active Authorized Records (<?= count($authorizedRecords) ?>)
                    </span>
                </div>
                <div class="col-md-6">
                    <input type="text" class="form-control form-control-sm table-search-input ms-md-auto" data-table-target="hospAuthTable" placeholder="Search records..." style="max-width: 260px;">
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="hospAuthTable">
                    <thead>
                        <tr>
                            <th>Patient / ABHA ID</th>
                            <th>Health Record</th>
                            <th>Record Type</th>
                            <th>Record Date</th>
                            <th>DEPA Grant Handle</th>
                            <th>Valid Until</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($authorizedRecords as $rec): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($rec['patient_name']) ?></div>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($rec['patient_uid']) ?></span>
                                    <small class="text-muted d-block">Blood: <?= e($rec['blood_group']) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($rec['title']) ?></div>
                                    <small class="text-muted font-monospace"><?= e($rec['record_uid']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border"><?= e($rec['record_type']) ?></span>
                                </td>
                                <td>
                                    <div class="small fw-semibold"><?= e(date('d M Y', strtotime($rec['record_date']))) ?></div>
                                </td>
                                <td>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle font-monospace">
                                        <?= e($rec['consent_uid']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-success small fw-semibold">
                                        <i class="bi bi-clock me-1"></i><?= format_date($rec['expiry_date']) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="<?= BASE_URL ?>hospital/view_record.php?id=<?= $rec['id'] ?>" class="btn btn-sm btn-success fw-semibold px-3 shadow-sm">
                                        <i class="bi bi-file-earmark-medical me-1"></i> Examine Clinical Data
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
