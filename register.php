<?php
/**
 * Multi-Role Registration Page
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Create Account";
require_once __DIR__ . '/config/config.php';

redirect_if_logged_in();

$error = '';
$success = '';
$pdo = get_db_connection();

$activeTab = $_POST['reg_role'] ?? $_GET['role'] ?? 'patient';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $regRole  = trim($_POST['reg_role'] ?? 'patient');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $error = "Please fill in all core credentials.";
    } elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        // Check uniqueness of username and email
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = ? OR `email` = ?");
        $stmtCheck->execute([$username, $email]);
        if ($stmtCheck->fetchColumn() > 0) {
            $error = "Username or Email is already registered. Please sign in or use another.";
        } else {
            try {
                $pdo->beginTransaction();

                // 1. Hash password & Insert into users table
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $stmtUser = $pdo->prepare("INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `status`, `created_at`) VALUES (?, ?, ?, ?, 'active', NOW())");
                $stmtUser->execute([$username, $email, $passwordHash, $regRole]);
                $userId = (int)$pdo->lastInsertId();

                if ($regRole === 'patient') {
                    $fullName         = trim($_POST['patient_name'] ?? '');
                    $dob              = $_POST['dob'] ?? '';
                    $gender           = $_POST['gender'] ?? 'Other';
                    $bloodGroup       = trim($_POST['blood_group'] ?? 'O+');
                    $phone            = trim($_POST['patient_phone'] ?? '');
                    $address          = trim($_POST['patient_address'] ?? '');
                    $emergencyContact = trim($_POST['emergency_contact'] ?? '');

                    $patientUid = generate_depa_uid('ABHA-PAT', 'patients', 'patient_uid', $pdo);

                    $stmtPat = $pdo->prepare("INSERT INTO `patients` (`user_id`, `patient_uid`, `full_name`, `dob`, `gender`, `blood_group`, `phone`, `address`, `emergency_contact`, `created_at`) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmtPat->execute([$userId, $patientUid, $fullName, $dob, $gender, $bloodGroup, $phone, $address, $emergencyContact]);
                    $profileId = (int)$pdo->lastInsertId();

                    // Sync with Central DEPA Registry
                    sync_registry($pdo, 'PATIENT', $patientUid, $fullName, $profileId, 'ACTIVE');
                    log_audit($pdo, 'USER_REGISTERED', "New Patient registered: {$fullName} (ABHA: {$patientUid})", $userId, 'patient', $fullName);

                } elseif ($regRole === 'doctor') {
                    $fullName       = trim($_POST['doctor_name'] ?? '');
                    $specialization = trim($_POST['specialization'] ?? '');
                    $licenseNo      = trim($_POST['doctor_license'] ?? '');
                    $hospitalAffil  = trim($_POST['hospital_affiliation'] ?? '');
                    $phone          = trim($_POST['doctor_phone'] ?? '');
                    $experience     = (int)($_POST['experience_years'] ?? 0);

                    $doctorUid = generate_depa_uid('DOC-REG', 'doctors', 'doctor_uid', $pdo);

                    $stmtDoc = $pdo->prepare("INSERT INTO `doctors` (`user_id`, `doctor_uid`, `full_name`, `specialization`, `license_number`, `hospital_affiliation`, `phone`, `experience_years`, `created_at`) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmtDoc->execute([$userId, $doctorUid, $fullName, $specialization, $licenseNo, $hospitalAffil, $phone, $experience]);
                    $profileId = (int)$pdo->lastInsertId();

                    // Sync with Central DEPA Registry
                    sync_registry($pdo, 'DOCTOR', $doctorUid, $fullName, $profileId, 'ACTIVE');
                    log_audit($pdo, 'USER_REGISTERED', "New Doctor registered: {$fullName} ({$doctorUid}) [License: {$licenseNo}]", $userId, 'doctor', $fullName);

                } elseif ($regRole === 'hospital') {
                    $hospitalName    = trim($_POST['hospital_name'] ?? '');
                    $licenseNo       = trim($_POST['hospital_license'] ?? '');
                    $address         = trim($_POST['hospital_address'] ?? '');
                    $contactPerson   = trim($_POST['contact_person'] ?? '');
                    $phone           = trim($_POST['hospital_phone'] ?? '');
                    $establishedYear = !empty($_POST['established_year']) ? (int)$_POST['established_year'] : null;

                    $hospitalUid = generate_depa_uid('HOSP-REG', 'hospitals', 'hospital_uid', $pdo);

                    $stmtHosp = $pdo->prepare("INSERT INTO `hospitals` (`user_id`, `hospital_uid`, `hospital_name`, `license_number`, `address`, `contact_person`, `phone`, `email`, `established_year`, `created_at`) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    $stmtHosp->execute([$userId, $hospitalUid, $hospitalName, $licenseNo, $address, $contactPerson, $phone, $email, $establishedYear]);
                    $profileId = (int)$pdo->lastInsertId();

                    // Sync with Central DEPA Registry
                    sync_registry($pdo, 'HOSPITAL', $hospitalUid, $hospitalName, $profileId, 'ACTIVE');
                    log_audit($pdo, 'USER_REGISTERED', "New Hospital registered: {$hospitalName} ({$hospitalUid})", $userId, 'hospital', $hospitalName);
                }

                $pdo->commit();
                set_flash('success', "Registration successful! You can now log in to your account.");
                header("Location: " . BASE_URL . "login.php?role=" . urlencode($regRole));
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Registration failed: " . $e->getMessage();
            }
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center py-3">
    <div class="col-lg-8">
        <div class="card card-custom shadow-lg border-0">
            <div class="card-header bg-dark text-white text-center py-4 rounded-top-3">
                <h4 class="fw-bold mb-1">Join the DEPA Health Record Network</h4>
                <p class="small text-white-50 mb-0">Select your entity type and register for consent-governed health exchange</p>
            </div>

            <div class="card-body p-4 p-md-5">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Role Selection Tabs -->
                <ul class="nav nav-pills nav-fill mb-4 p-1 bg-light rounded-3" id="regTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $activeTab === 'patient' ? 'active' : '' ?> fw-semibold" id="patient-tab" data-bs-toggle="tab" data-bs-target="#patient-pane" type="button" role="tab">
                            <i class="bi bi-person-heart me-1"></i> Patient (Principal)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $activeTab === 'doctor' ? 'active' : '' ?> fw-semibold" id="doctor-tab" data-bs-toggle="tab" data-bs-target="#doctor-pane" type="button" role="tab">
                            <i class="bi bi-clipboard2-pulse me-1"></i> Doctor
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= $activeTab === 'hospital' ? 'active' : '' ?> fw-semibold" id="hospital-tab" data-bs-toggle="tab" data-bs-target="#hospital-pane" type="button" role="tab">
                            <i class="bi bi-hospital me-1"></i> Hospital / Clinic
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="regTabsContent">
                    
                    <!-- ==================== 1. PATIENT REGISTRATION ==================== -->
                    <div class="tab-pane fade <?= $activeTab === 'patient' ? 'show active' : '' ?>" id="patient-pane" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="reg_role" value="patient">

                            <h6 class="text-primary fw-bold text-uppercase mb-3" style="font-size: 0.8rem;">
                                <i class="bi bi-person-vcard me-1"></i> 1. Patient Demographics & ABHA Identity
                            </h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Full Legal Name *</label>
                                    <input type="text" name="patient_name" id="regPatientName" class="form-control" placeholder="e.g. Rahul Sharma" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Date of Birth *</label>
                                    <input type="date" name="dob" class="form-control" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Gender *</label>
                                    <select name="gender" class="form-select" required>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Blood Group *</label>
                                    <select name="blood_group" class="form-select" required>
                                        <option value="A+">A+</option>
                                        <option value="A-">A-</option>
                                        <option value="B+">B+</option>
                                        <option value="B-">B-</option>
                                        <option value="AB+">AB+</option>
                                        <option value="AB-">AB-</option>
                                        <option value="O+">O+</option>
                                        <option value="O-">O-</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Phone Number *</label>
                                    <input type="tel" name="patient_phone" class="form-control" placeholder="+91 98765 43210" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Emergency Contact</label>
                                    <input type="text" name="emergency_contact" class="form-control" placeholder="Name & Phone">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Residential Address</label>
                                    <textarea name="patient_address" class="form-control" rows="2" placeholder="Full address"></textarea>
                                </div>
                            </div>

                            <h6 class="text-primary fw-bold text-uppercase mb-3 mt-4" style="font-size: 0.8rem;">
                                <i class="bi bi-shield-lock me-1"></i> 2. Portal Sign-in Credentials
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Username *</label>
                                    <input type="text" name="username" class="form-control" placeholder="Unique username" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Email Address *</label>
                                    <input type="email" name="email" class="form-control" placeholder="name@example.com" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Password (min. 6 chars) *</label>
                                    <input type="password" name="password" class="form-control" required minlength="6">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Confirm Password *</label>
                                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                                <i class="bi bi-person-check-fill me-2"></i> Register as Patient
                            </button>
                        </form>
                    </div>

                    <!-- ==================== 2. DOCTOR REGISTRATION ==================== -->
                    <div class="tab-pane fade <?= $activeTab === 'doctor' ? 'show active' : '' ?>" id="doctor-pane" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="reg_role" value="doctor">

                            <h6 class="text-primary fw-bold text-uppercase mb-3" style="font-size: 0.8rem;">
                                <i class="bi bi-file-medical me-1"></i> 1. Medical Professional Credentials
                            </h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Doctor Full Name *</label>
                                    <input type="text" name="doctor_name" class="form-control" placeholder="e.g. Dr. Rajesh Sharma, MD" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Medical Council License No. *</label>
                                    <input type="text" name="doctor_license" class="form-control" placeholder="e.g. MCI-KA-2015-12345" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Specialization *</label>
                                    <input type="text" name="specialization" class="form-control" placeholder="e.g. Cardiology, Neurology" required>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-semibold">Hospital / Clinic Affiliation</label>
                                    <input type="text" name="hospital_affiliation" class="form-control" placeholder="Hospital or Clinic Name">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Experience (Years)</label>
                                    <input type="number" name="experience_years" class="form-control" min="0" value="5">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Official Phone Number *</label>
                                    <input type="tel" name="doctor_phone" class="form-control" placeholder="+91 98450 11223" required>
                                </div>
                            </div>

                            <h6 class="text-primary fw-bold text-uppercase mb-3 mt-4" style="font-size: 0.8rem;">
                                <i class="bi bi-shield-lock me-1"></i> 2. Doctor Portal Credentials
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Username *</label>
                                    <input type="text" name="username" class="form-control" placeholder="e.g. dr.rajesh" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Professional Email *</label>
                                    <input type="email" name="email" class="form-control" placeholder="doctor@medmail.in" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Password *</label>
                                    <input type="password" name="password" class="form-control" required minlength="6">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Confirm Password *</label>
                                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold shadow-sm">
                                <i class="bi bi-clipboard2-check-fill me-2"></i> Register as Doctor
                            </button>
                        </form>
                    </div>

                    <!-- ==================== 3. HOSPITAL REGISTRATION ==================== -->
                    <div class="tab-pane fade <?= $activeTab === 'hospital' ? 'show active' : '' ?>" id="hospital-pane" role="tabpanel">
                        <form method="POST" action="">
                            <input type="hidden" name="reg_role" value="hospital">

                            <h6 class="text-primary fw-bold text-uppercase mb-3" style="font-size: 0.8rem;">
                                <i class="bi bi-building me-1"></i> 1. Health Facility / Hospital Profile
                            </h6>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Hospital / Healthcare Facility Name *</label>
                                    <input type="text" name="hospital_name" class="form-control" placeholder="e.g. Apollo City Hospital" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Hospital Registration / License No. *</label>
                                    <input type="text" name="hospital_license" class="form-control" placeholder="e.g. NABH-FAC-2018-0912" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Medical Superintendent / Contact Person *</label>
                                    <input type="text" name="contact_person" class="form-control" placeholder="Dr. Administrator" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Official Phone *</label>
                                    <input type="tel" name="hospital_phone" class="form-control" placeholder="+91 80 2630 4000" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">Established Year</label>
                                    <input type="number" name="established_year" class="form-control" placeholder="1995">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Hospital Address *</label>
                                    <textarea name="hospital_address" class="form-control" rows="2" placeholder="Full facility location and pincode" required></textarea>
                                </div>
                            </div>

                            <h6 class="text-primary fw-bold text-uppercase mb-3 mt-4" style="font-size: 0.8rem;">
                                <i class="bi bi-shield-lock me-1"></i> 2. Hospital Portal Credentials
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Username *</label>
                                    <input type="text" name="username" class="form-control" placeholder="e.g. apollo_city" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Facility Email *</label>
                                    <input type="email" name="email" class="form-control" placeholder="contact@hospital.org" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Password *</label>
                                    <input type="password" name="password" class="form-control" required minlength="6">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Confirm Password *</label>
                                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success w-100 py-2 fw-semibold shadow-sm">
                                <i class="bi bi-building-check me-2"></i> Register Healthcare Facility
                            </button>
                        </form>
                    </div>

                </div>

                <div class="text-center mt-4 pt-3 border-top">
                    <span class="text-muted small">Already registered?</span> 
                    <a href="<?= BASE_URL ?>login.php" class="small fw-bold">Sign In to your portal</a>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
