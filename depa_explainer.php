<?php
/**
 * DEPA Architecture & Technical Guide
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "DEPA Architecture & Framework";
require_once __DIR__ . '/config/config.php';
require_once ROOT_DIR . 'includes/header.php';
?>

<div class="row justify-content-center mb-5">
    <div class="col-lg-10">
        <!-- Hero Header -->
        <div class="text-center mb-5">
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 rounded-pill fw-semibold mb-2">
                <i class="bi bi-diagram-3-fill me-1"></i> Technical Architecture
            </span>
            <h2 class="fw-bold">Data Empowerment & Protection Architecture (DEPA)</h2>
            <p class="lead text-secondary mx-auto" style="max-width: 750px;">
                Understanding the consent-based digital health data sharing paradigm, participant roles, electronic consent artifacts, and registry integration.
            </p>
        </div>

        <!-- 1. What is DEPA? -->
        <div class="card card-custom p-4 mb-4">
            <div class="card-body">
                <h4 class="fw-bold text-primary mb-3"><i class="bi bi-shield-shaded me-2"></i>1. Overview & Core Philosophy</h4>
                <p>
                    The <strong>Data Empowerment and Protection Architecture (DEPA)</strong> is a modern techno-legal framework designed to democratize data access while putting individuals in total control of their personal information. In healthcare, it addresses the traditional problem of data silos where patient medical history is fragmented across hospitals, diagnostic centers, and clinics without patient autonomy.
                </p>
                <div class="row g-3 mt-2">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <h6 class="fw-bold text-dark"><i class="bi bi-person-check text-success me-2"></i>Data Principal Control</h6>
                            <p class="small text-muted mb-0">Patients retain full ownership over who accesses their health data, for what medical purpose, and for how long.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <h6 class="fw-bold text-dark"><i class="bi bi-file-earmark-lock text-primary me-2"></i>Decoupled Flow</h6>
                            <p class="small text-muted mb-0">Consent verification is decoupled from raw data storage, preventing unauthorized backend indexing or leaks.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border h-100">
                            <h6 class="fw-bold text-dark"><i class="bi bi-slash-circle text-danger me-2"></i>Instant Revocability</h6>
                            <p class="small text-muted mb-0">Consent is not a blanket authorization; it can be revoked at any moment, immediately halting data access.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. The 5 Core Entities -->
        <div class="card card-custom p-4 mb-4">
            <div class="card-body">
                <h4 class="fw-bold text-primary mb-3"><i class="bi bi-people me-2"></i>2. The 5 Key Entities in this Simulation</h4>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 22%;">DEPA Entity</th>
                                <th style="width: 20%;">Simulation Role</th>
                                <th>Function in Health Record Exchange</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong class="text-info"><i class="bi bi-person-circle me-1"></i> Data Principal</strong></td>
                                <td><span class="badge badge-patient">Patient</span></td>
                                <td>The citizen who generates health data. Holds ultimate authority to approve, reject, or revoke consent.</td>
                            </tr>
                            <tr>
                                <td><strong class="text-primary"><i class="bi bi-clipboard2-pulse me-1"></i> Data Consumer</strong></td>
                                <td><span class="badge badge-doctor">Doctor</span> / <span class="badge badge-hospital">Hospital</span></td>
                                <td>Medical practitioners or hospitals requesting view access to patient diagnosis, lab results, and scans for clinical care.</td>
                            </tr>
                            <tr>
                                <td><strong class="text-success"><i class="bi bi-database-lock me-1"></i> Data Provider</strong></td>
                                <td><span class="badge bg-success text-white">Patient Record Vault</span></td>
                                <td>The repository hosting encrypted medical attachments, diagnostic PDFs, and doctor prescriptions.</td>
                            </tr>
                            <tr>
                                <td><strong class="text-warning"><i class="bi bi-shield-lock-fill me-1"></i> Consent Manager</strong></td>
                                <td><span class="badge bg-warning text-dark">DEPA Policy Engine</span></td>
                                <td>Intermediary service that formats, dispatches, validates, and manages electronic consent artifacts (`CNST-XXXX`).</td>
                            </tr>
                            <tr>
                                <td><strong class="text-purple" style="color: #7c3aed;"><i class="bi bi-card-checklist me-1"></i> Central Registry</strong></td>
                                <td><span class="badge badge-admin">Registry & Admin</span></td>
                                <td>Directory maintaining verified identities (ABHA Patient IDs, Doctor Medical Council Licenses, Hospital Facility IDs).</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 3. The Consent Artifact Specification -->
        <div class="card card-custom p-4 mb-4">
            <div class="card-body">
                <h4 class="fw-bold text-primary mb-3"><i class="bi bi-code-square me-2"></i>3. Structure of a DEPA Electronic Consent Artifact</h4>
                <p class="text-muted">A DEPA consent artifact is a machine-readable, verifiable authorization object stored in the registry:</p>
                <div class="bg-dark text-light p-3 rounded-3 font-monospace small">
<pre class="mb-0 text-light">{
  <span class="text-info">"consent_id"</span>: <span class="text-warning">"CNST-9001"</span>,
  <span class="text-info">"patient_identifier"</span>: <span class="text-warning">"ABHA-PAT-1001"</span>,
  <span class="text-info">"requester_id"</span>: <span class="text-warning">"DOC-REG-2001"</span>,
  <span class="text-info">"record_scope"</span>: <span class="text-warning">"REC-UID-5001"</span>, <span class="text-secondary">// or NULL for all records</span>
  <span class="text-info">"purpose"</span>: <span class="text-warning">"Cardiology Consultation & Lipid Monitoring"</span>,
  <span class="text-info">"status"</span>: <span class="text-success">"Approved"</span>, <span class="text-secondary">// Pending | Approved | Rejected | Revoked | Expired</span>
  <span class="text-info">"requested_timestamp"</span>: <span class="text-warning">"2026-08-10 10:00:00"</span>,
  <span class="text-info">"expiry_timestamp"</span>: <span class="text-warning">"2026-09-10 23:59:59"</span>,
  <span class="text-info">"decision_timestamp"</span>: <span class="text-warning">"2026-08-10 14:20:00"</span>,
  <span class="text-info">"revocation_timestamp"</span>: <span class="text-warning">null</span>
}</pre>
                </div>
            </div>
        </div>

        <!-- 4. Security & Expiry Guarantees -->
        <div class="card card-custom p-4 mb-4">
            <div class="card-body">
                <h4 class="fw-bold text-primary mb-3"><i class="bi bi-lock me-2"></i>4. Security & Compliance Guarantees</h4>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item px-0 py-3">
                        <h6 class="fw-bold mb-1"><i class="bi bi-check-circle-fill text-success me-2"></i>No Direct Document Exposure</h6>
                        <p class="small text-muted mb-0">Files are never accessed via public URLs like <code>/uploads/test.pdf</code>. All requests route through <code>includes/view_document.php</code>, which interrogates the database for an active, approved, non-expired consent token.</p>
                    </li>
                    <li class="list-group-item px-0 py-3">
                        <h6 class="fw-bold mb-1"><i class="bi bi-clock-history text-warning me-2"></i>Time-Bound Authorization & Auto-Expiry</h6>
                        <p class="small text-muted mb-0">Every consent grant has an explicit expiry timestamp. If a doctor tries to access data even 1 second after expiration, the system flags the consent as <code>Expired</code> and halts access immediately.</p>
                    </li>
                    <li class="list-group-item px-0 py-3">
                        <h6 class="fw-bold mb-1"><i class="bi bi-journal-check text-primary me-2"></i>Tamper-Evident Audit Logging</h6>
                        <p class="small text-muted mb-0">Every login, consent request, approval, rejection, revocation, and record download is permanently logged with IP address and timestamp in the <code>audit_logs</code> table.</p>
                    </li>
                </ul>
            </div>
        </div>

        <div class="text-center">
            <a href="<?= BASE_URL ?>login.php" class="btn btn-primary btn-lg px-5 shadow">
                <i class="bi bi-box-arrow-in-right me-2"></i> Proceed to Login & Test
            </a>
        </div>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
