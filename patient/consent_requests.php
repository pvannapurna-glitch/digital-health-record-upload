<?php
/**
 * Incoming Consent Requests Management for Patient (Accept & Reject Workflow)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

$pageTitle = "Pending Consent Requests";
require_once __DIR__ . '/../config/config.php';

require_role('patient');

$pdo = get_db_connection();
$userId = get_logged_user_id();
$patientId = get_patient_profile_id($pdo);

// Auto expire passed consents
auto_expire_consents($pdo);

// Fetch all pending requests for this patient
$stmt = $pdo->prepare("SELECT cr.*, u.username AS requester_username,
                              CASE 
                                  WHEN cr.requester_role = 'doctor' THEN d.full_name 
                                  WHEN cr.requester_role = 'hospital' THEN h.hospital_name 
                                  ELSE u.username 
                              END AS requester_name,
                              d.specialization, d.license_number AS doc_license, d.hospital_affiliation,
                              h.license_number AS hosp_license, h.contact_person,
                              hr.title AS record_title, hr.record_uid, hr.record_type, hr.record_date
                       FROM `consent_requests` cr
                       JOIN `users` u ON cr.requester_user_id = u.id
                       LEFT JOIN `doctors` d ON u.id = d.user_id
                       LEFT JOIN `hospitals` h ON u.id = h.user_id
                       LEFT JOIN `health_records` hr ON cr.record_id = hr.id
                       WHERE cr.patient_id = ? AND cr.status = 'Pending'
                       ORDER BY cr.id DESC");
$stmt->execute([$patientId]);
$pendingRequests = $stmt->fetchAll();

require_once ROOT_DIR . 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1">Incoming Consent Requests</h3>
        <p class="text-secondary small mb-0">Under DEPA, doctors and hospitals cannot access your health records without your explicit approval and electronic signature.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>patient/sharing_history.php" class="btn btn-outline-secondary btn-sm fw-semibold">
            <i class="bi bi-clock-history me-1"></i> View Past Grants & Revoke Access
        </a>
        <a href="<?= BASE_URL ?>esign/status.php" class="btn btn-outline-primary btn-sm fw-semibold">
            <i class="bi bi-patch-check me-1"></i> Central eSign Status
        </a>
    </div>
</div>

<div class="card card-custom shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3">
        <span class="fw-bold fs-5 text-warning-emphasis">
            <i class="bi bi-hourglass-split me-2 text-warning"></i>Requests Awaiting Your Decision (<?= count($pendingRequests) ?>)
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($pendingRequests)): ?>
            <div class="text-center py-5">
                <i class="bi bi-shield-check display-3 text-success mb-3 d-block"></i>
                <h5 class="text-secondary">No Pending Requests</h5>
                <p class="text-muted small">You have responded to all incoming data access requests.</p>
                <a href="<?= BASE_URL ?>patient/dashboard.php" class="btn btn-outline-primary btn-sm px-3">Return to Dashboard</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom mb-0">
                    <thead>
                        <tr>
                            <th>Consent Handle</th>
                            <th>Data Requester</th>
                            <th>Requested Scope</th>
                            <th>Purpose of Access</th>
                            <th>Consent Status</th>
                            <th>eSign Status</th>
                            <th>Target Expiry</th>
                            <th class="text-end">Decision Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingRequests as $req): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-light text-primary border font-monospace"><?= e($req['consent_uid']) ?></span>
                                    <div class="small text-muted mt-1"><?= format_date($req['requested_date']) ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?= e($req['requester_name']) ?></div>
                                    <span class="badge badge-<?= e($req['requester_role']) ?> text-uppercase me-1"><?= e($req['requester_role']) ?></span>
                                    <?php if ($req['requester_role'] === 'doctor'): ?>
                                        <small class="text-muted d-block"><?= e($req['specialization']) ?> (Lic: <?= e($req['doc_license']) ?>)</small>
                                    <?php elseif ($req['requester_role'] === 'hospital'): ?>
                                        <small class="text-muted d-block"><?= e($req['contact_person']) ?> (NABH: <?= e($req['hosp_license']) ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($req['record_id']): ?>
                                        <div class="fw-semibold text-dark"><?= e($req['record_title']) ?></div>
                                        <small class="text-muted font-monospace"><?= e($req['record_uid']) ?> | <?= e($req['record_type']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-primary text-white p-2">
                                            <i class="bi bi-collection-fill me-1"></i> Full Health History (All Records)
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="p-2 bg-light rounded-2 border small" style="max-width: 220px;">
                                        <?= e($req['purpose']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= render_status_badge($req['status']) ?>
                                </td>
                                <td>
                                    <?= render_esign_status_badge($req['esign_status']) ?>
                                </td>
                                <td>
                                    <div class="small fw-semibold text-danger"><?= format_date($req['expiry_date']) ?></div>
                                </td>
                                <td class="text-end">
                                    <!-- Primary eSign Gateway Action -->
                                    <a href="<?= BASE_URL ?>patient/esign_consent.php?id=<?= $req['id'] ?>" class="btn btn-success btn-sm px-3 fw-bold me-1 shadow-sm">
                                        <i class="bi bi-patch-check-fill me-1"></i> Review & Accept
                                    </a>

                                    <!-- Reject Button (Triggers Modal) -->
                                    <button type="button" class="btn btn-outline-danger btn-sm px-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalReject<?= $req['id'] ?>" title="Reject Request">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- Reject Modal -->
                            <div class="modal fade" id="modalReject<?= $req['id'] ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content border-0 shadow">
                                        <form method="POST" action="<?= BASE_URL ?>patient/reject_consent.php">
                                            <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                                            <input type="hidden" name="consent_id" value="<?= $req['id'] ?>">
                                            
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title fw-bold">
                                                    <i class="bi bi-x-circle me-2"></i>Reject Consent Request (<?= e($req['consent_uid']) ?>)
                                                </h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body p-4">
                                                <p class="text-dark">Are you sure you want to decline access to <strong><?= e($req['requester_name']) ?></strong>?</p>
                                                <p class="small text-muted">The requester will be prevented from accessing this medical data and the rejection will be logged in the audit trail.</p>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold">Reason for Declining (Optional)</label>
                                                    <textarea name="patient_notes" class="form-control" rows="2" placeholder="e.g. Record is not relevant to current consultation."></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer bg-light">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger fw-bold px-4 shadow-sm">
                                                    <i class="bi bi-x-lg me-1"></i> Confirm Rejection
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once ROOT_DIR . 'includes/footer.php'; ?>
