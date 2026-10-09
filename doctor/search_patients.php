<?php
/**
 * Doctor Patient Search via Central DEPA Registry
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Search Patients";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

$searchQuery = trim($_GET['q'] ?? '');
$bloodFilter  = trim($_GET['blood_group'] ?? '');

$query = "SELECT p.*, u.email,
                 (SELECT COUNT(*) FROM `consent_requests` WHERE `patient_id` = p.id AND `requester_user_id` = ? AND `status` IN ('Approved', 'Active') AND `expiry_date` >= NOW()) AS active_consents_count
          FROM `patients` p 
          JOIN `users` u ON p.user_id = u.id
          WHERE u.status = 'active'";

$params = [$userId];

if (!empty($searchQuery)) {
    $query .= " AND (p.patient_uid LIKE ? OR p.full_name LIKE ? OR p.phone LIKE ? OR u.email LIKE ?)";
    $like = "%{$searchQuery}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if (!empty($bloodFilter)) {
    $query .= " AND p.blood_group = ?";
    $params[] = $bloodFilter;
}

$query .= " ORDER BY p.full_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$patients = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Central Registry - Patient Directory</h3>
        <p class="text-secondary small mb-0">Search registered patients across the national health registry using ABHA Health ID or Demographics.</p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>doctor/request_consent.php" class="btn btn-primary btn-sm fw-semibold">
            <i class="bi bi-shield-plus me-1"></i> Direct Consent Request
        </a>
    </div>
</div>

<!-- Search Form Card -->
<div class="card card-custom shadow-sm border-0 mb-4">
    <div class="card-body p-3 p-md-4">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-6">
                <label class="form-label fw-semibold small text-muted">Search by ABHA ID, Patient Name, Phone or Email</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="e.g. ABHA-PAT-1001 or Rahul Sharma" value="<?= e($searchQuery) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold small text-muted">Blood Group Filter</label>
                <select name="blood_group" class="form-select">
                    <option value="">All Blood Groups</option>
                    <option value="A+" <?= $bloodFilter === 'A+' ? 'selected' : '' ?>>A+</option>
                    <option value="A-" <?= $bloodFilter === 'A-' ? 'selected' : '' ?>>A-</option>
                    <option value="B+" <?= $bloodFilter === 'B+' ? 'selected' : '' ?>>B+</option>
                    <option value="B-" <?= $bloodFilter === 'B-' ? 'selected' : '' ?>>B-</option>
                    <option value="AB+" <?= $bloodFilter === 'AB+' ? 'selected' : '' ?>>AB+</option>
                    <option value="AB-" <?= $bloodFilter === 'AB-' ? 'selected' : '' ?>>AB-</option>
                    <option value="O+" <?= $bloodFilter === 'O+' ? 'selected' : '' ?>>O+</option>
                    <option value="O-" <?= $bloodFilter === 'O-' ? 'selected' : '' ?>>O-</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2 align-items-end mt-md-auto">
                <button type="submit" class="btn btn-primary w-100 fw-semibold">
                    <i class="bi bi-filter me-1"></i> Search
                </button>
                <?php if (!empty($searchQuery) || !empty($bloodFilter)): ?>
                    <a href="<?= BASE_URL ?>doctor/search_patients.php" class="btn btn-outline-secondary">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Results Grid -->
<div class="row g-4">
    <?php if (empty($patients)): ?>
        <div class="col-12 text-center py-5">
            <div class="card card-custom p-5">
                <i class="bi bi-person-x display-4 text-muted mb-3"></i>
                <h5 class="text-secondary">No Registered Patients Found</h5>
                <p class="text-muted small">No active patients match your search criteria in the Central DEPA Registry.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($patients as $p): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card card-custom h-100 shadow-sm border-top border-4 <?= ($p['active_consents_count'] > 0) ? 'border-success' : 'border-primary' ?>">
                    <div class="card-body p-4 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-light text-primary border font-monospace"><?= e($p['patient_uid']) ?></span>
                                <?php if ($p['active_consents_count'] > 0): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="bi bi-shield-check me-1"></i> Consent Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border">
                                        <i class="bi bi-lock me-1"></i> Consent Required
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h5 class="fw-bold text-dark mb-1"><?= e($p['full_name']) ?></h5>
                            <div class="text-muted small mb-3">
                                <div><i class="bi bi-calendar-event me-1"></i> DOB: <?= e(date('d M Y', strtotime($p['dob']))) ?> (<?= e($p['gender']) ?>)</div>
                                <div><i class="bi bi-droplet-half text-danger me-1"></i> Blood Group: <strong><?= e($p['blood_group']) ?></strong></div>
                                <div><i class="bi bi-telephone me-1"></i> Phone: <?= e($p['phone']) ?></div>
                            </div>
                        </div>

                        <div class="pt-3 border-top d-flex gap-2">
                            <a href="<?= BASE_URL ?>doctor/request_consent.php?patient_id=<?= $p['id'] ?>" class="btn btn-primary btn-sm w-100 fw-semibold">
                                <i class="bi bi-shield-plus me-1"></i> Request Consent
                            </a>
                            <?php if ($p['active_consents_count'] > 0): ?>
                                <a href="<?= BASE_URL ?>doctor/authorized_records.php?patient_id=<?= $p['id'] ?>" class="btn btn-outline-success btn-sm" title="View Authorized Records">
                                    <i class="bi bi-folder2-open"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
