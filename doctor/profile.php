<?php
/**
 * Doctor Profile Management
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Doctor Profile";
require_once __DIR__ . '/../config/config.php';

require_role('doctor');

$pdo = get_db_connection();
$userId = get_logged_user_id();

$stmt = $pdo->prepare("SELECT d.*, u.email, u.username FROM `doctors` d JOIN `users` u ON d.user_id = u.id WHERE d.user_id = ?");
$stmt->execute([$userId]);
$doctor = $stmt->fetch();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "CSRF verification failed.";
    } else {
        $phone          = trim($_POST['phone'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $affiliation    = trim($_POST['hospital_affiliation'] ?? '');
        $experience     = (int)($_POST['experience_years'] ?? 0);

        if (empty($phone) || empty($specialization)) {
            $error = "Phone and Specialization are required.";
        } else {
            $stmtUp = $pdo->prepare("UPDATE `doctors` SET `phone` = ?, `specialization` = ?, `hospital_affiliation` = ?, `experience_years` = ? WHERE `user_id` = ?");
            $stmtUp->execute([$phone, $specialization, $affiliation, $experience, $userId]);

            log_audit($pdo, 'PROFILE_UPDATED', "Doctor {$doctor['full_name']} updated profile info.", $userId, 'doctor');

            set_flash('success', 'Profile updated successfully.');
            header("Location: " . BASE_URL . "doctor/profile.php");
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
                <span class="fw-bold text-primary"><i class="bi bi-person-badge me-2"></i>Doctor Professional Profile</span>
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
                            <small class="text-muted d-block">Doctor DEPA Identifier</small>
                            <span class="font-monospace fw-bold text-primary"><?= e($doctor['doctor_uid']) ?></span>
                        </div>
                        <div class="col-sm-6">
                            <small class="text-muted d-block">Medical Council Registration / License No.</small>
                            <span class="font-monospace fw-bold text-dark"><?= e($doctor['license_number']) ?></span>
                            <span class="badge bg-success-subtle text-success border ms-1"><i class="bi bi-patch-check-fill me-1"></i>Verified</span>
                        </div>
                    </div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted">Doctor Full Name (Registry Locked)</label>
                            <input type="text" class="form-control bg-light" value="<?= e($doctor['full_name']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted">Official Email (Username: <?= e($doctor['username']) ?>)</label>
                            <input type="email" class="form-control bg-light" value="<?= e($doctor['email']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Specialization *</label>
                            <input type="text" name="specialization" class="form-control" required value="<?= e($doctor['specialization']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Clinical Experience (Years)</label>
                            <input type="number" name="experience_years" class="form-control" min="0" value="<?= (int)$doctor['experience_years'] ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Hospital / Clinic Affiliation</label>
                            <input type="text" name="hospital_affiliation" class="form-control" value="<?= e($doctor['hospital_affiliation']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Official Phone Number *</label>
                            <input type="tel" name="phone" class="form-control" required value="<?= e($doctor['phone']) ?>">
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
