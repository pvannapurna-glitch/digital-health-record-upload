<?php
/**
 * New Google User Onboarding & Role Selection Gateway
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 *
 * For new Google accounts that do not exist in the database.
 * Allows user to complete registration as Patient, Doctor, or Hospital.
 * Strictly prevents automated Admin account creation.
 */

$pageTitle = "Complete Registration with Google";
require_once __DIR__ . '/config/config.php';

redirect_if_logged_in();

$pending = $_SESSION['pending_google_reg'] ?? null;

if (!$pending || empty($pending['sub']) || empty($pending['email']) || time() > ($pending['expires_at'] ?? 0)) {
    unset($_SESSION['pending_google_reg']);
    set_flash('warning', 'Registration session expired. Please sign in with Google again.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

$pdo = get_db_connection();
$error = '';
$activeRole = $_POST['reg_role'] ?? $_GET['role'] ?? 'patient';

// Generate default suggested username from email
$emailPrefix = preg_replace('/[^a-zA-Z0-9_]/', '', strstr($pending['email'], '@', true) ?: 'user');
$suggestedUsername = strtolower($emailPrefix);

// Handle cancellation
if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_google_reg']);
    set_flash('info', 'Google registration was cancelled.');
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// Generate Anti-CSRF token
if (empty($_SESSION['greg_csrf_token'])) {
    $_SESSION['greg_csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['greg_csrf_token'], $submittedToken)) {
        $error = "Security token mismatch. Please try submitting again.";
    } elseif (isset($_POST['action_link_existing'])) {
        // Option to link an existing account with different username/email
        $linkUsername = trim($_POST['link_username'] ?? '');
        $linkPassword = $_POST['link_password'] ?? '';

        if (empty($linkUsername) || empty($linkPassword)) {
            $error = "Please enter both username and password to link your existing account.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM `users` WHERE `username` = ? OR `email` = ? LIMIT 1");
            $stmt->execute([$linkUsername, $linkUsername]);
            $existing = $stmt->fetch();

            if (!$existing || !password_verify($linkPassword, $existing['password_hash'])) {
                $error = "Invalid username or password for account linking.";
                log_audit($pdo, 'GOOGLE_LINK_FAILED', "Failed manual account link attempt for '{$linkUsername}'");
            } elseif ($existing['status'] !== 'active') {
                $error = "That account has been deactivated.";
            } else {
                try {
                    $stmtLink = $pdo->prepare("UPDATE `users` SET `google_sub` = ?, `google_email` = ?, `google_picture` = ?, `google_linked_at` = NOW() WHERE `id` = ?");
                    $stmtLink->execute([$pending['sub'], $pending['email'], $pending['picture'] ?: null, $existing['id']]);

                    $existing['google_sub'] = $pending['sub'];
                    $existing['google_email'] = $pending['email'];
                    $existing['google_picture'] = $pending['picture'];
                    $existing['google_linked_at'] = date('Y-m-d H:i:s');

                    log_audit($pdo, 'GOOGLE_ACCOUNT_LINKED', "Google account ({$pending['email']}) linked to existing account {$existing['username']}", $existing['id'], $existing['role'], $existing['username']);

                    unset($_SESSION['pending_google_reg']);
                    unset($_SESSION['greg_csrf_token']);

                    $redirectUrl = complete_login_session($pdo, $existing, 'google');
                    $profileName = $_SESSION['user_name'] ?? $existing['username'];

                    log_audit($pdo, 'GOOGLE_LOGIN_SUCCESS', "Google SSO login successful for {$profileName} ({$existing['role']})", $existing['id'], $existing['role'], $profileName);
                    set_flash('success', "Google account successfully linked to your account! Welcome back, {$profileName}.");
                    header("Location: " . $redirectUrl);
                    exit;
                } catch (Exception $e) {
                    $error = "Failed to link account: " . $e->getMessage();
                }
            }
        }
    } else {
        // New Registration submission
        $regRole  = trim($_POST['reg_role'] ?? 'patient');
        $username = trim($_POST['username'] ?? $suggestedUsername);
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Strict role whitelist: Never allow Admin creation via Google registration
        if (!in_array($regRole, ['patient', 'doctor', 'hospital'], true)) {
            $error = "Invalid role selected. Google accounts cannot be registered as Administrators.";
        } elseif (empty($username)) {
            $error = "Please enter a valid username.";
        } elseif (!empty($password) && strlen($password) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif (!empty($password) && $password !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            // Check username uniqueness
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = ?");
            $stmtCheck->execute([$username]);
            if ($stmtCheck->fetchColumn() > 0) {
                $error = "Username '{$username}' is already taken. Please choose another username.";
            } else {
                // If no password provided, generate a cryptographically strong random password
                $passwordHash = password_hash(!empty($password) ? $password : bin2hex(random_bytes(20)), PASSWORD_DEFAULT);

                try {
                    $pdo->beginTransaction();

                    // Insert into users
                    $stmtUser = $pdo->prepare("INSERT INTO `users` (`username`, `email`, `password_hash`, `role`, `status`, `ekyc_status`, `google_sub`, `google_email`, `google_picture`, `google_linked_at`, `created_at`) 
                                               VALUES (?, ?, ?, ?, 'active', 'Pending', ?, ?, ?, NOW(), NOW())");
                    $stmtUser->execute([
                        $username,
                        $pending['email'],
                        $passwordHash,
                        $regRole,
                        $pending['sub'],
                        $pending['email'],
                        $pending['picture'] ?: null
                    ]);
                    $userId = (int)$pdo->lastInsertId();

                    if ($regRole === 'patient') {
                        $fullName         = trim($_POST['patient_name'] ?? $pending['name']);
                        $dob              = $_POST['dob'] ?? '';
                        $gender           = $_POST['gender'] ?? 'Other';
                        $bloodGroup       = trim($_POST['blood_group'] ?? 'O+');
                        $phone            = trim($_POST['patient_phone'] ?? '');
                        $address          = trim($_POST['patient_address'] ?? '');
                        $emergencyContact = trim($_POST['emergency_contact'] ?? '');

                        if (empty($fullName) || empty($dob) || empty($phone)) {
                            throw new Exception("Please fill in Full Name, Date of Birth, and Mobile Phone.");
                        }

                        $patientUid = generate_depa_uid('ABHA-PAT', 'patients', 'patient_uid', $pdo);

                        $stmtPat = $pdo->prepare("INSERT INTO `patients` (`user_id`, `patient_uid`, `full_name`, `dob`, `gender`, `blood_group`, `phone`, `address`, `emergency_contact`, `created_at`) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmtPat->execute([$userId, $patientUid, $fullName, $dob, $gender, $bloodGroup, $phone, $address, $emergencyContact]);
                        $profileId = (int)$pdo->lastInsertId();

                        sync_registry($pdo, 'PATIENT', $patientUid, $fullName, $profileId, 'ACTIVE');
                        log_audit($pdo, 'USER_REGISTERED', "New Patient registered via Google SSO: {$fullName} (ABHA: {$patientUid})", $userId, 'patient', $fullName);

                    } elseif ($regRole === 'doctor') {
                        $fullName       = trim($_POST['doctor_name'] ?? $pending['name']);
                        $specialization = trim($_POST['specialization'] ?? '');
                        $licenseNo      = trim($_POST['doctor_license'] ?? '');
                        $hospitalAffil  = trim($_POST['hospital_affiliation'] ?? '');
                        $phone          = trim($_POST['doctor_phone'] ?? '');
                        $experience     = (int)($_POST['experience_years'] ?? 0);

                        if (empty($fullName) || empty($specialization) || empty($licenseNo) || empty($phone)) {
                            throw new Exception("Please fill in Doctor Name, Specialization, Medical License Number, and Phone.");
                        }

                        $doctorUid = generate_depa_uid('DOC-REG', 'doctors', 'doctor_uid', $pdo);

                        $stmtDoc = $pdo->prepare("INSERT INTO `doctors` (`user_id`, `doctor_uid`, `full_name`, `specialization`, `license_number`, `hospital_affiliation`, `phone`, `experience_years`, `created_at`) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmtDoc->execute([$userId, $doctorUid, $fullName, $specialization, $licenseNo, $hospitalAffil, $phone, $experience]);
                        $profileId = (int)$pdo->lastInsertId();

                        sync_registry($pdo, 'DOCTOR', $doctorUid, $fullName, $profileId, 'ACTIVE');
                        log_audit($pdo, 'USER_REGISTERED', "New Doctor registered via Google SSO: {$fullName} ({$doctorUid}) [License: {$licenseNo}]", $userId, 'doctor', $fullName);

                    } elseif ($regRole === 'hospital') {
                        $hospitalName    = trim($_POST['hospital_name'] ?? $pending['name']);
                        $licenseNo       = trim($_POST['hospital_license'] ?? '');
                        $address         = trim($_POST['hospital_address'] ?? '');
                        $contactPerson   = trim($_POST['contact_person'] ?? $pending['name']);
                        $phone           = trim($_POST['hospital_phone'] ?? '');
                        $establishedYear = !empty($_POST['established_year']) ? (int)$_POST['established_year'] : null;

                        if (empty($hospitalName) || empty($licenseNo) || empty($address) || empty($phone)) {
                            throw new Exception("Please fill in Hospital Name, Registry License, Address, and Phone.");
                        }

                        $hospitalUid = generate_depa_uid('HOSP-REG', 'hospitals', 'hospital_uid', $pdo);

                        $stmtHosp = $pdo->prepare("INSERT INTO `hospitals` (`user_id`, `hospital_uid`, `hospital_name`, `license_number`, `address`, `contact_person`, `phone`, `email`, `established_year`, `created_at`) 
                                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                        $stmtHosp->execute([$userId, $hospitalUid, $hospitalName, $licenseNo, $address, $contactPerson, $phone, $pending['email'], $establishedYear]);
                        $profileId = (int)$pdo->lastInsertId();

                        sync_registry($pdo, 'HOSPITAL', $hospitalUid, $hospitalName, $profileId, 'ACTIVE');
                        log_audit($pdo, 'USER_REGISTERED', "New Hospital registered via Google SSO: {$hospitalName} ({$hospitalUid})", $userId, 'hospital', $hospitalName);
                    }

                    log_audit($pdo, 'GOOGLE_ACCOUNT_LINKED', "Google identity ({$pending['email']} [sub: {$pending['sub']}]) linked to new {$regRole} account {$username}", $userId, $regRole, $username);

                    $pdo->commit();

                    // Prepare user record for session initialization
                    $user = [
                        'id'             => $userId,
                        'username'       => $username,
                        'email'          => $pending['email'],
                        'role'           => $regRole,
                        'ekyc_status'    => 'Pending',
                        'google_sub'     => $pending['sub'],
                        'google_email'   => $pending['email'],
                        'google_picture' => $pending['picture']
                    ];

                    unset($_SESSION['pending_google_reg']);
                    unset($_SESSION['greg_csrf_token']);

                    $redirectUrl = complete_login_session($pdo, $user, 'google');
                    $profileName = $_SESSION['user_name'] ?? $username;

                    log_audit($pdo, 'GOOGLE_LOGIN_SUCCESS', "Google SSO login successful for newly registered {$profileName} ({$regRole})", $userId, $regRole, $profileName);

                    set_flash('success', "Registration complete! Welcome to DEPA Health Exchange, {$profileName}.");
                    header("Location: " . $redirectUrl);
                    exit;

                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = "Registration failed: " . $e->getMessage();
                }
            }
        }
    }
}

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center my-4">
    <div class="col-lg-8 col-xl-7">
        <div class="card card-custom shadow border-0 overflow-hidden">
            <div class="card-header bg-primary text-white text-center py-4 position-relative">
                <div class="d-inline-flex align-items-center justify-content-center bg-white bg-opacity-25 rounded-circle p-3 mb-2" style="width: 60px; height: 60px;">
                    <i class="bi bi-person-badge-fill fs-2"></i>
                </div>
                <h4 class="fw-bold mb-1">Complete Your Registration</h4>
                <p class="small text-white-50 mb-0">Select your DEPA ecosystem role to finalize your Google Single Sign-On account</p>
            </div>

            <div class="card-body p-4 p-md-5">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <!-- Google Identity Pill -->
                <div class="p-3 bg-light rounded-3 border mb-4 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <?php if (!empty($pending['picture'])): ?>
                            <img src="<?= e($pending['picture']) ?>" alt="Avatar" class="rounded-circle me-3 border shadow-sm" style="width: 45px; height: 45px; object-fit: cover;">
                        <?php else: ?>
                            <div class="rounded-circle bg-white border d-flex align-items-center justify-content-center me-3 shadow-sm" style="width: 45px; height: 45px;">
                                <i class="bi bi-google fs-4 text-primary"></i>
                            </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-bold"><?= e($pending['name']) ?></div>
                            <div class="small text-muted font-monospace"><?= e($pending['email']) ?></div>
                        </div>
                    </div>
                    <span class="badge bg-success bg-opacity-10 text-success border border-success">
                        <i class="bi bi-shield-check me-1"></i>Google Verified
                    </span>
                </div>

                <!-- Role Selector Tabs -->
                <ul class="nav nav-pills nav-fill mb-4 border rounded-3 p-1 bg-light" id="rolePills" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold <?= $activeRole === 'patient' ? 'active' : '' ?>" id="pill-patient-tab" data-bs-toggle="pill" data-bs-target="#pill-patient" type="button" role="tab" onclick="document.getElementById('inputRegRole').value='patient'">
                            <i class="bi bi-person-fill me-1"></i> Patient
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold <?= $activeRole === 'doctor' ? 'active' : '' ?>" id="pill-doctor-tab" data-bs-toggle="pill" data-bs-target="#pill-doctor" type="button" role="tab" onclick="document.getElementById('inputRegRole').value='doctor'">
                            <i class="bi bi-heart-pulse-fill me-1"></i> Doctor
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold <?= $activeRole === 'hospital' ? 'active' : '' ?>" id="pill-hospital-tab" data-bs-toggle="pill" data-bs-target="#pill-hospital" type="button" role="tab" onclick="document.getElementById('inputRegRole').value='hospital'">
                            <i class="bi bi-building me-1"></i> Hospital
                        </button>
                    </li>
                </ul>

                <form method="POST" action="" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['greg_csrf_token']) ?>">
                    <input type="hidden" name="reg_role" id="inputRegRole" value="<?= e($activeRole) ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="username" class="form-label fw-semibold">Choose Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">@</span>
                                <input type="text" class="form-control" id="username" name="username" value="<?= e($_POST['username'] ?? $suggestedUsername) ?>" required>
                            </div>
                            <div class="form-text small">Unique username for logging in and identity reference.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address (Google)</label>
                            <input type="email" class="form-control bg-light font-monospace" value="<?= e($pending['email']) ?>" readonly disabled>
                            <div class="form-text small">Locked to your verified Google account.</div>
                        </div>
                    </div>

                    <!-- Role-Specific Fields Tab Content -->
                    <div class="tab-content mb-4" id="rolePillsContent">

                        <!-- PATIENT TAB -->
                        <div class="tab-pane fade <?= $activeRole === 'patient' ? 'show active' : '' ?>" id="pill-patient" role="tabpanel">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-1"></i> Patient Demographics</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Full Legal Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="patient_name" value="<?= e($_POST['patient_name'] ?? $pending['name']) ?>" placeholder="e.g. Rahul Sharma">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="dob" value="<?= e($_POST['dob'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select" name="gender">
                                        <option value="Male" <?= ($_POST['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                        <option value="Female" <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                                        <option value="Other" <?= ($_POST['gender'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Blood Group <span class="text-danger">*</span></label>
                                    <select class="form-select" name="blood_group">
                                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                                            <option value="<?= $bg ?>" <?= ($_POST['blood_group'] ?? 'O+') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Mobile Phone <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control" name="patient_phone" value="<?= e($_POST['patient_phone'] ?? '') ?>" placeholder="+91 98765 43210">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Residential Address</label>
                                    <textarea class="form-control" name="patient_address" rows="2" placeholder="Street address, city, state, pincode"><?= e($_POST['patient_address'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Emergency Contact</label>
                                    <input type="text" class="form-control" name="emergency_contact" value="<?= e($_POST['emergency_contact'] ?? '') ?>" placeholder="Name & Phone (e.g. Sunita Sharma - 9876543211)">
                                </div>
                            </div>
                        </div>

                        <!-- DOCTOR TAB -->
                        <div class="tab-pane fade <?= $activeRole === 'doctor' ? 'show active' : '' ?>" id="pill-doctor" role="tabpanel">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-1"></i> Doctor Credentials</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Doctor Full Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="doctor_name" value="<?= e($_POST['doctor_name'] ?? $pending['name']) ?>" placeholder="e.g. Dr. Rajesh Sharma, MD">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Specialization <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="specialization" value="<?= e($_POST['specialization'] ?? '') ?>" placeholder="e.g. Cardiology, Neurology">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Medical License / MCI Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control font-monospace" name="doctor_license" value="<?= e($_POST['doctor_license'] ?? '') ?>" placeholder="e.g. MCI-KA-2022-99881">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Primary Hospital Affiliation</label>
                                    <input type="text" class="form-control" name="hospital_affiliation" value="<?= e($_POST['hospital_affiliation'] ?? '') ?>" placeholder="e.g. Apollo Heart Institute">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Professional Phone <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control" name="doctor_phone" value="<?= e($_POST['doctor_phone'] ?? '') ?>" placeholder="+91 98450 11223">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Years of Experience</label>
                                    <input type="number" class="form-control" name="experience_years" value="<?= e($_POST['experience_years'] ?? '5') ?>" min="0" max="60">
                                </div>
                            </div>
                        </div>

                        <!-- HOSPITAL TAB -->
                        <div class="tab-pane fade <?= $activeRole === 'hospital' ? 'show active' : '' ?>" id="pill-hospital" role="tabpanel">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-info-circle me-1"></i> Healthcare Facility Details</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Hospital / Clinic Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="hospital_name" value="<?= e($_POST['hospital_name'] ?? $pending['name']) ?>" placeholder="e.g. Apollo Super Specialty Hospital">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Facility License / Reg Number <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control font-monospace" name="hospital_license" value="<?= e($_POST['hospital_license'] ?? '') ?>" placeholder="e.g. HOSP-REG-BLR-8821">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Official Contact Person <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="contact_person" value="<?= e($_POST['contact_person'] ?? $pending['name']) ?>" placeholder="e.g. Medical Superintendent">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Hospital Hotline / Phone <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control" name="hospital_phone" value="<?= e($_POST['hospital_phone'] ?? '') ?>" placeholder="+91 80 2299 4400">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Established Year</label>
                                    <input type="number" class="form-control" name="established_year" value="<?= e($_POST['established_year'] ?? '2005') ?>" min="1800" max="<?= date('Y') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Facility Physical Address <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="hospital_address" rows="2" placeholder="Full address of hospital/clinic"><?= e($_POST['hospital_address'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Optional Password Setup -->
                    <div class="p-3 bg-light rounded-3 border mb-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold small"><i class="bi bi-key me-1"></i> Optional Password (Backup Login)</span>
                            <span class="badge bg-secondary">Optional</span>
                        </div>
                        <p class="small text-muted mb-2">You can always sign in with 1-click Google SSO. If you also want standard password login capability, specify a password below:</p>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="password" class="form-control" name="password" placeholder="Optional backup password">
                            </div>
                            <div class="col-md-6">
                                <input type="password" class="form-control" name="confirm_password" placeholder="Confirm backup password">
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm mb-3">
                        <i class="bi bi-check-circle-fill me-2"></i> Complete Account Registration
                    </button>

                    <div class="text-center">
                        <a href="<?= BASE_URL ?>google_register.php?cancel=1" class="text-muted small text-decoration-none">
                            <i class="bi bi-x-circle me-1"></i> Cancel and return to Login
                        </a>
                    </div>
                </form>

                <!-- Alternative Link Section -->
                <div class="mt-4 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted fw-bold text-uppercase"><i class="bi bi-link-45deg me-1"></i>Already have an existing account?</span>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#linkCollapse">
                            Link Existing Account
                        </button>
                    </div>

                    <div class="collapse mt-3" id="linkCollapse">
                        <div class="card card-body bg-light border">
                            <p class="small text-muted mb-3">
                                If you already have an account with a different username or email, enter your credentials below to link your Google identity to it.
                            </p>
                            <form method="POST" action="">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['greg_csrf_token']) ?>">
                                <input type="hidden" name="action_link_existing" value="1">

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Existing Username or Email</label>
                                    <input type="text" class="form-control form-control-sm" name="link_username" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">Account Password</label>
                                    <input type="password" class="form-control form-control-sm" name="link_password" required>
                                </div>
                                <button type="submit" class="btn btn-sm btn-dark w-100">
                                    <i class="bi bi-link me-1"></i> Verify & Link Existing Account
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
