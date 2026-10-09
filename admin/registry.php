<?php
/**
 * Central DEPA Registry Directory & Multi-Entity Ledger (Admin)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Central DEPA Registry";
require_once __DIR__ . '/../config/config.php';

require_role('admin');

$pdo = get_db_connection();

$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$searchQuery = trim($_GET['search'] ?? '');

$query = "SELECT r.*,
                 CASE 
                     WHEN r.entity_type = 'PATIENT' THEN (SELECT u.ekyc_status FROM `patients` p JOIN `users` u ON p.user_id = u.id WHERE p.id = r.reference_id)
                     WHEN r.entity_type = 'DOCTOR' THEN (SELECT d.specialization FROM `doctors` d WHERE d.id = r.reference_id)
                     WHEN r.entity_type = 'HOSPITAL' THEN (SELECT h.license_number FROM `hospitals` h WHERE h.id = r.reference_id)
                     WHEN r.entity_type = 'RECORD' THEN (SELECT hr.record_type FROM `health_records` hr WHERE hr.id = r.reference_id)
                     WHEN r.entity_type = 'CONSENT' THEN (SELECT cr.status FROM `consent_requests` cr WHERE cr.id = r.reference_id)
                     ELSE NULL
                 END AS extra_meta,
                 CASE 
                     WHEN r.entity_type = 'CONSENT' THEN (SELECT cr.is_esigned FROM `consent_requests` cr WHERE cr.id = r.reference_id)
                     ELSE NULL
                 END AS consent_esigned
          FROM `registry` r WHERE 1=1";

$params = [];

if (!empty($typeFilter)) {
    $query .= " AND r.`entity_type` = ?";
    $params[] = $typeFilter;
}

if (!empty($statusFilter)) {
    $query .= " AND r.`status` = ?";
    $params[] = $statusFilter;
}

if (!empty($searchQuery)) {
    $query .= " AND (r.`entity_uid` LIKE ? OR r.`entity_name` LIKE ?)";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
}

$query .= " ORDER BY r.`registered_at` DESC, r.`id` DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$registryItems = $stmt->fetchAll();

// Entity Counts
$patientCount = (int)$pdo->query("SELECT COUNT(*) FROM `registry` WHERE `entity_type` = 'PATIENT'")->fetchColumn();
$doctorCount  = (int)$pdo->query("SELECT COUNT(*) FROM `registry` WHERE `entity_type` = 'DOCTOR'")->fetchColumn();
$hospitalCount= (int)$pdo->query("SELECT COUNT(*) FROM `registry` WHERE `entity_type` = 'HOSPITAL'")->fetchColumn();
$recordCount  = (int)$pdo->query("SELECT COUNT(*) FROM `registry` WHERE `entity_type` = 'RECORD'")->fetchColumn();
$consentCount = (int)$pdo->query("SELECT COUNT(*) FROM `registry` WHERE `entity_type` = 'CONSENT'")->fetchColumn();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Central DEPA Master Registry</h3>
        <p class="text-secondary small mb-0">Unified ecosystem directory indexing Patients (ABHA), Doctors (MCI), Hospitals (NABH), Health Records, and Consent Records.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>admin/patients.php" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-people me-1"></i> Patient Directory
        </a>
        <a href="<?= BASE_URL ?>admin/doctors.php" class="btn btn-outline-purple btn-sm fw-semibold" style="color: #6366f1; border-color: #6366f1;">
            <i class="bi bi-person-badge me-1"></i> Doctor Directory
        </a>
        <a href="<?= BASE_URL ?>admin/hospitals.php" class="btn btn-outline-success btn-sm fw-semibold">
            <i class="bi bi-hospital me-1"></i> Hospital Directory
        </a>
    </div>
</div>

<!-- Registry Summary Filter Cards (5 Master Categories) -->
<div class="row g-3 mb-4">
    <div class="col">
        <a href="<?= BASE_URL ?>admin/registry.php?type=PATIENT" class="text-decoration-none">
            <div class="stat-card primary p-3 text-center <?= ($typeFilter === 'PATIENT') ? 'border border-2 border-primary shadow-sm' : '' ?>">
                <div class="h4 fw-bold text-primary mb-0"><?= $patientCount ?></div>
                <div class="small fw-semibold text-secondary">Patients (ABHA)</div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?= BASE_URL ?>admin/registry.php?type=DOCTOR" class="text-decoration-none">
            <div class="stat-card purple p-3 text-center <?= ($typeFilter === 'DOCTOR') ? 'border border-2 border-primary shadow-sm' : '' ?>">
                <div class="h4 fw-bold mb-0" style="color: #6366f1;"><?= $doctorCount ?></div>
                <div class="small fw-semibold text-secondary">Doctors (MCI)</div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?= BASE_URL ?>admin/registry.php?type=HOSPITAL" class="text-decoration-none">
            <div class="stat-card success p-3 text-center <?= ($typeFilter === 'HOSPITAL') ? 'border border-2 border-success shadow-sm' : '' ?>">
                <div class="h4 fw-bold text-success mb-0"><?= $hospitalCount ?></div>
                <div class="small fw-semibold text-secondary">Hospitals (NABH)</div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?= BASE_URL ?>admin/registry.php?type=RECORD" class="text-decoration-none">
            <div class="stat-card danger p-3 text-center <?= ($typeFilter === 'RECORD') ? 'border border-2 border-danger shadow-sm' : '' ?>">
                <div class="h4 fw-bold text-danger mb-0"><?= $recordCount ?></div>
                <div class="small fw-semibold text-secondary">Health Records</div>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="<?= BASE_URL ?>admin/registry.php?type=CONSENT" class="text-decoration-none">
            <div class="stat-card warning p-3 text-center <?= ($typeFilter === 'CONSENT') ? 'border border-2 border-warning shadow-sm' : '' ?>">
                <div class="h4 fw-bold text-warning-emphasis mb-0"><?= $consentCount ?></div>
                <div class="small fw-semibold text-secondary">Consent Records</div>
            </div>
        </a>
    </div>
</div>

<!-- Search & Filter Controls -->
<div class="card card-custom mb-4 shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <form method="GET" action="<?= BASE_URL ?>admin/registry.php" class="row align-items-center g-2">
            <div class="col-md-3">
                <span class="fw-bold fs-5 text-dark">Directory Index (<?= count($registryItems) ?>)</span>
            </div>
            <div class="col-md-3">
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" <?= empty($typeFilter) ? 'selected' : '' ?>>All Entity Categories</option>
                    <option value="PATIENT" <?= $typeFilter === 'PATIENT' ? 'selected' : '' ?>>Patients Registry</option>
                    <option value="DOCTOR" <?= $typeFilter === 'DOCTOR' ? 'selected' : '' ?>>Doctors Registry</option>
                    <option value="HOSPITAL" <?= $typeFilter === 'HOSPITAL' ? 'selected' : '' ?>>Hospitals Registry</option>
                    <option value="RECORD" <?= $typeFilter === 'RECORD' ? 'selected' : '' ?>>Health Records Registry</option>
                    <option value="CONSENT" <?= $typeFilter === 'CONSENT' ? 'selected' : '' ?>>Consent Records Registry</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="" <?= empty($statusFilter) ? 'selected' : '' ?>>All Statuses</option>
                    <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                    <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                    <option value="REVOKED" <?= $statusFilter === 'REVOKED' ? 'selected' : '' ?>>Revoked</option>
                    <option value="EXPIRED" <?= $statusFilter === 'EXPIRED' ? 'selected' : '' ?>>Expired</option>
                </select>
            </div>
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control" placeholder="Search by UID or Name..." value="<?= e($searchQuery) ?>">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    <?php if (!empty($typeFilter) || !empty($statusFilter) || !empty($searchQuery)): ?>
                        <a href="<?= BASE_URL ?>admin/registry.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (empty($registryItems)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-search display-4 text-secondary d-block mb-2"></i>
                <p class="mb-0">No matching registry entries found for the selected filters.</p>
                <a href="<?= BASE_URL ?>admin/registry.php" class="btn btn-sm btn-outline-primary mt-2">Reset Filters</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0" id="regTable">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Unique Identifier (DEPA UID)</th>
                            <th>Registered Entity Name & Attributes</th>
                            <th>Metadata / Status Info</th>
                            <th>Registry Status</th>
                            <th>Registration Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($registryItems as $item): ?>
                            <tr>
                                <td>
                                    <?php 
                                    switch ($item['entity_type']) {
                                        case 'PATIENT':  echo '<span class="badge badge-patient">PATIENT</span>'; break;
                                        case 'DOCTOR':   echo '<span class="badge badge-doctor">DOCTOR</span>'; break;
                                        case 'HOSPITAL': echo '<span class="badge badge-hospital">HOSPITAL</span>'; break;
                                        case 'RECORD':   echo '<span class="badge bg-danger text-white">RECORD</span>'; break;
                                        case 'CONSENT':  echo '<span class="badge bg-warning text-dark">CONSENT</span>'; break;
                                        case 'EKYC':     echo '<span class="badge bg-success text-white">eKYC</span>'; break;
                                        case 'ESIGN':    echo '<span class="badge bg-info text-dark">eSIGN</span>'; break;
                                        default:         echo '<span class="badge bg-secondary">' . e($item['entity_type']) . '</span>'; break;
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($item['entity_uid']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= e($item['entity_name']) ?></div>
                                    <small class="text-muted font-monospace">Ref ID: #<?= (int)$item['reference_id'] ?></small>
                                </td>
                                <td>
                                    <?php if ($item['entity_type'] === 'PATIENT' && !empty($item['extra_meta'])): ?>
                                        <span class="badge <?= $item['extra_meta'] === 'Verified' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                            eKYC: <?= e($item['extra_meta']) ?>
                                        </span>
                                    <?php elseif ($item['entity_type'] === 'DOCTOR' && !empty($item['extra_meta'])): ?>
                                        <span class="badge bg-light text-dark border">
                                            Spec: <?= e($item['extra_meta']) ?>
                                        </span>
                                    <?php elseif ($item['entity_type'] === 'HOSPITAL' && !empty($item['extra_meta'])): ?>
                                        <span class="badge bg-light text-dark border">
                                            Lic: <?= e($item['extra_meta']) ?>
                                        </span>
                                    <?php elseif ($item['entity_type'] === 'RECORD' && !empty($item['extra_meta'])): ?>
                                        <span class="badge bg-light text-danger border">
                                            <?= e($item['extra_meta']) ?>
                                        </span>
                                    <?php elseif ($item['entity_type'] === 'CONSENT'): ?>
                                        <?php if ((int)$item['consent_esigned'] === 1): ?>
                                            <span class="badge bg-success-subtle text-success border">
                                                <i class="bi bi-pen me-1"></i>eSigned
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border">
                                                Pending eSign
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted small">Standard Registry Entry</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= render_status_badge($item['status']) ?>
                                </td>
                                <td>
                                    <div class="small text-muted"><?= format_date($item['registered_at'], 'd M Y, H:i') ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
