<?php
/**
 * Hospital Facility Profile Management
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Hospital Profile";
require_once __DIR__ . '/../config/config.php';

require_role('hospital');

$pdo = get_db_connection();
$userId = get_logged_user_id();

$stmt = $pdo->prepare("SELECT h.*, u.email AS user_email, u.username FROM `hospitals` h JOIN `users` u ON h.user_id = u.id WHERE h.user_id = ?");
$stmt->execute([$userId]);
$hospital = $stmt->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $address       = trim($_POST['address'] ?? '');

        if (empty($contactPerson) || empty($phone) || empty($address)) {
            $error = "Contact person, phone number, and address are mandatory.";
        } else {
            $stmtUp = $pdo->prepare("UPDATE `hospitals` SET `contact_person` = ?, `phone` = ?, `address` = ? WHERE `user_id` = ?");
            $stmtUp->execute([$contactPerson, $phone, $address, $userId]);

            log_audit($pdo, 'PROFILE_UPDATED', "Hospital {$hospital['hospital_name']} updated facility contact info.", $userId, 'hospital');

            set_flash('success', 'Hospital profile updated successfully.');
            header("Location: " . BASE_URL . "hospital/profile.php");
            exit;
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card card-custom shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3">
                <span class="fw-bold text-success"><i class="bi bi-building me-2"></i>Hospital / Health Facility Profile</span>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <div class="p-3 bg-light rounded-3 border mb-4">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <small class="text-muted d-block">DEPA Facility UID</small>
                            <span class="font-monospace fw-bold text-success"><?= e($hospital['hospital_uid']) ?></span>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">NABH / Regulatory Accreditation No.</small>
                            <span class="font-monospace fw-bold text-dark"><?= e($hospital['license_number']) ?></span>
                            <span class="badge bg-success-subtle text-success border ms-1"><i class="bi bi-patch-check-fill me-1"></i>Accredited</span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted">Facility Name (Registry Locked)</label>
                            <input type="text" class="form-control bg-light" value="<?= e($hospital['hospital_name']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted">Official Email (Username: <?= e($hospital['username']) ?>)</label>
                            <input type="email" class="form-control bg-light" value="<?= e($hospital['email']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Medical Superintendent / Contact Person *</label>
                            <input type="text" name="contact_person" class="form-control" required value="<?= e($hospital['contact_person']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Official Contact Phone *</label>
                            <input type="tel" name="phone" class="form-control" required value="<?= e($hospital['phone']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Facility Address *</label>
                            <textarea name="address" class="form-control" rows="2" required><?= e($hospital['address']) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-success px-4 fw-semibold shadow-sm">
                            <i class="bi bi-check-lg me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
