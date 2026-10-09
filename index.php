<?php
/**
 * Main Landing Page
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Home - Consent-Driven Health Record Exchange";
require_once __DIR__ . '/config/config.php';
require_once ROOT_DIR . 'includes/header.php';

$pdo = get_db_connection();

// Get counts for live stats counter
$totalPatients = (int)$pdo->query("SELECT COUNT(*) FROM `patients`")->fetchColumn();
$totalDoctors  = (int)$pdo->query("SELECT COUNT(*) FROM `doctors`")->fetchColumn();
$totalHospitals = (int)$pdo->query("SELECT COUNT(*) FROM `hospitals`")->fetchColumn();
$totalRecords  = (int)$pdo->query("SELECT COUNT(*) FROM `health_records`")->fetchColumn();
$totalConsents = (int)$pdo->query("SELECT COUNT(*) FROM `consent_requests`")->fetchColumn();
?>

<!-- Hero Banner Section -->
<div class="card card-custom border-0 bg-primary bg-gradient text-white p-4 p-md-5 mb-5 shadow-lg overflow-hidden position-relative">
    <div class="row align-items-center position-relative" style="z-index: 2;">
        <div class="col-lg-8">
            <span class="badge bg-white text-primary px-3 py-2 rounded-pill fw-semibold mb-3">
                <i class="bi bi-shield-check me-1"></i> DEPA Standard Compliant Simulation
            </span>
            <h1 class="display-5 fw-bold mb-3">Digital Health Record Exchange</h1>
            <p class="lead opacity-90 mb-4" style="max-width: 650px;">
                Empowering patients with granular, time-bound consent control over their medical records. 
                Doctors and hospitals can securely access clinical artifacts <strong>strictly when authorized</strong>.
            </p>
            <div class="d-flex flex-wrap gap-3">
                <a href="<?= BASE_URL ?>login.php" class="btn btn-light text-primary fw-bold px-4 py-2 shadow-sm">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Access Portals
                </a>
                <a href="<?= BASE_URL ?>register.php" class="btn btn-outline-light fw-bold px-4 py-2">
                    <i class="bi bi-person-plus me-2"></i> Register New User
                </a>
                <a href="<?= BASE_URL ?>depa_explainer.php" class="btn btn-info text-dark fw-bold px-4 py-2">
                    <i class="bi bi-info-circle me-2"></i> How DEPA Works
                </a>
            </div>
        </div>
        <div class="col-lg-4 text-center d-none d-lg-block">
            <div class="bg-white bg-opacity-10 p-4 rounded-4 border border-white border-opacity-25 shadow">
                <i class="bi bi-heart-pulse-fill text-warning display-2 mb-2"></i>
                <h5 class="fw-bold">Patient-Centric Health Data</h5>
                <p class="small text-white-50 mb-0">Decoupled data flow with verifiable consent artifacts and complete audit trail.</p>
            </div>
        </div>
    </div>
</div>

<!-- Metrics Counter Bar -->
<div class="row g-3 mb-5">
    <div class="col-6 col-md">
        <div class="stat-card primary p-3 text-center">
            <div class="h2 fw-bold text-primary mb-1"><?= $totalPatients ?></div>
            <div class="text-secondary small fw-semibold">Registered Patients</div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card success p-3 text-center">
            <div class="h2 fw-bold text-success mb-1"><?= $totalDoctors ?></div>
            <div class="text-secondary small fw-semibold">Certified Doctors</div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card warning p-3 text-center">
            <div class="h2 fw-bold text-warning mb-1"><?= $totalHospitals ?></div>
            <div class="text-secondary small fw-semibold">Hospitals / Clinics</div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card danger p-3 text-center">
            <div class="h2 fw-bold text-danger mb-1"><?= $totalRecords ?></div>
            <div class="text-secondary small fw-semibold">Health Artifacts</div>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="stat-card purple p-3 text-center">
            <div class="h2 fw-bold text-purple mb-1" style="color: #7c3aed;"><?= $totalConsents ?></div>
            <div class="text-secondary small fw-semibold">DEPA Consents</div>
        </div>
    </div>
</div>

<!-- 4 Role Portals Grid -->
<div class="mb-5">
    <div class="text-center mb-4">
        <h3 class="fw-bold">Explore the 4 Ecosystem Portals</h3>
        <p class="text-secondary">Simulating the interactions between Data Principals, Data Consumers, and Ecosystem Registries</p>
    </div>
    <div class="row g-4">
        <!-- Patient Portal -->
        <div class="col-md-6 col-lg-3">
            <div class="card card-custom h-100 border-top border-4 border-info">
                <div class="card-body p-4 text-center">
                    <div class="stat-card primary d-inline-block p-3 rounded-circle mb-3">
                        <i class="bi bi-person-heart text-primary fs-2"></i>
                    </div>
                    <h5 class="fw-bold">Patient Module</h5>
                    <p class="text-muted small">
                        Own your medical records, upload clinical reports, grant granular consent to doctors, and revoke permissions at any time.
                    </p>
                    <a href="<?= BASE_URL ?>login.php?role=patient" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                        Patient Login <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Doctor Portal -->
        <div class="col-md-6 col-lg-3">
            <div class="card card-custom h-100 border-top border-4 border-primary">
                <div class="card-body p-4 text-center">
                    <div class="stat-card purple d-inline-block p-3 rounded-circle mb-3">
                        <i class="bi bi-clipboard2-pulse text-purple fs-2" style="color: #6366f1;"></i>
                    </div>
                    <h5 class="fw-bold">Doctor Module</h5>
                    <p class="text-muted small">
                        Lookup patients in Central Registry, dispatch purpose-driven consent requests, and examine authorized diagnosis artifacts.
                    </p>
                    <a href="<?= BASE_URL ?>login.php?role=doctor" class="btn btn-outline-primary btn-sm w-100 fw-semibold">
                        Doctor Login <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Hospital Portal -->
        <div class="col-md-6 col-lg-3">
            <div class="card card-custom h-100 border-top border-4 border-success">
                <div class="card-body p-4 text-center">
                    <div class="stat-card success d-inline-block p-3 rounded-circle mb-3">
                        <i class="bi bi-hospital text-success fs-2"></i>
                    </div>
                    <h5 class="fw-bold">Hospital Module</h5>
                    <p class="text-muted small">
                        Institutional patient admission search, departmental consent requests, and multi-specialty record exchange.
                    </p>
                    <a href="<?= BASE_URL ?>login.php?role=hospital" class="btn btn-outline-success btn-sm w-100 fw-semibold">
                        Hospital Login <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Admin Portal -->
        <div class="col-md-6 col-lg-3">
            <div class="card card-custom h-100 border-top border-4 border-dark">
                <div class="card-body p-4 text-center">
                    <div class="stat-card warning d-inline-block p-3 rounded-circle mb-3">
                        <i class="bi bi-shield-lock text-dark fs-2"></i>
                    </div>
                    <h5 class="fw-bold">Admin Module</h5>
                    <p class="text-muted small">
                        Central Registry management, participant verification, global consent monitoring, and tamper-evident audit log auditing.
                    </p>
                    <a href="<?= BASE_URL ?>admin/login.php" class="btn btn-outline-dark btn-sm w-100 fw-semibold">
                        Admin Portal <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DEPA Workflow Interactive Architecture Stepper -->
<div class="card card-custom p-4 mb-5">
    <div class="card-body">
        <h4 class="fw-bold text-center mb-2">Consent-Driven Exchange Workflow</h4>
        <p class="text-secondary text-center small mb-4">Step-by-step lifecycle of how health records are securely requested and shared</p>

        <div class="row g-4 text-center">
            <div class="col-md-4 col-lg-2">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="badge bg-primary rounded-circle mb-2" style="width: 32px; height: 32px; line-height: 24px;">1</div>
                    <h6 class="fw-bold">Patient Records</h6>
                    <p class="small text-muted mb-0">Patient stores diagnostic reports & prescriptions in personal vault.</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="badge bg-primary rounded-circle mb-2" style="width: 32px; height: 32px; line-height: 24px;">2</div>
                    <h6 class="fw-bold">Doctor Requests</h6>
                    <p class="small text-muted mb-0">Doctor searches Registry & requests access with clinical purpose & validity.</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="badge bg-primary rounded-circle mb-2" style="width: 32px; height: 32px; line-height: 24px;">3</div>
                    <h6 class="fw-bold">Patient Decides</h6>
                    <p class="small text-muted mb-0">Patient inspects incoming request and clicks Approve or Reject.</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="badge bg-primary rounded-circle mb-2" style="width: 32px; height: 32px; line-height: 24px;">4</div>
                    <h6 class="fw-bold">Consent Artifact</h6>
                    <p class="small text-muted mb-0">DEPA engine generates verifiable consent handle (e.g. CNST-9001).</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="badge bg-primary rounded-circle mb-2" style="width: 32px; height: 32px; line-height: 24px;">5</div>
                    <h6 class="fw-bold">Secure Access</h6>
                    <p class="small text-muted mb-0">Doctor views authorized record. Protected document stream verified.</p>
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <div class="p-3 bg-light rounded-3 h-100 border">
                    <div class="badge bg-danger rounded-circle mb-2" style="width: 32px; height: 32px; line-height: 24px;">6</div>
                    <h6 class="fw-bold">Revocation / Expiry</h6>
                    <p class="small text-muted mb-0">Patient revokes consent or expiry triggers, instantly blocking further access.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
