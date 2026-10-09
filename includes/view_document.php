<?php
/**
 * Protected Document Retrieval Proxy (DEPA Consent Enforced)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_login();

$pdo = get_db_connection();
$currentUserId = get_logged_user_id();
$currentRole   = get_user_role();
$recordId      = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($recordId <= 0) {
    http_response_code(400);
    die("Invalid Health Record ID.");
}

// Fetch record and patient owner info
$stmt = $pdo->prepare("SELECT hr.*, p.user_id AS patient_user_id, p.full_name AS patient_name, p.patient_uid 
                       FROM `health_records` hr 
                       JOIN `patients` p ON hr.patient_id = p.id 
                       WHERE hr.id = ?");
$stmt->execute([$recordId]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    die("Health record artifact not found.");
}

if (empty($record['file_attachment'])) {
    http_response_code(404);
    die("No medical document attachment associated with this record.");
}

$filePath = UPLOAD_DIR . $record['file_attachment'];

// Authorization Check based on DEPA Consent Rules
$authorized = false;
$accessReason = "";

if ($currentRole === 'patient') {
    // Patient accessing their own record
    if ((int)$record['patient_user_id'] === (int)$currentUserId) {
        $authorized = true;
        $accessReason = "Data Principal (Patient) accessed own medical document.";
    }
} elseif ($currentRole === 'doctor' || $currentRole === 'hospital') {
    // Requester must possess active approved consent
    $depaCheck = validate_depa_consent($pdo, $currentUserId, $record['patient_id'], $record['id']);
    if ($depaCheck['allowed']) {
        $authorized = true;
        $accessReason = "Data Consumer ({$currentRole}) accessed document under valid DEPA consent " . $depaCheck['consent']['consent_uid'];
    } else {
        $accessReason = "Access Denied: " . $depaCheck['reason'];
    }
} elseif ($currentRole === 'admin') {
    // Admin system verification
    $authorized = true;
    $accessReason = "System Administrator verified document integrity.";
}

if (!$authorized) {
    log_audit($pdo, 'UNAUTHORIZED_ACCESS_ATTEMPT', "Denied attempt to download document for Record ID {$recordId} ({$record['record_uid']}). {$accessReason}", $currentUserId, $currentRole);
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden - DEPA Protection</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    </head>
    <body class="bg-light py-5">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-7">
                    <div class="card shadow border-danger text-center p-4">
                        <div class="card-body">
                            <i class="bi bi-shield-x text-danger display-1 mb-3"></i>
                            <h3 class="text-danger fw-bold">DEPA Consent Violation (403 Forbidden)</h3>
                            <p class="lead text-secondary mt-3">Access to this patient health record is strictly protected by the Data Empowerment and Protection Architecture.</p>
                            <div class="alert alert-danger text-start">
                                <strong>Reason:</strong> <?= e($accessReason) ?>
                            </div>
                            <p class="text-muted small">An audit log entry has been recorded with timestamp <?= date('d M Y, h:i:s A') ?>.</p>
                            <a href="javascript:history.back()" class="btn btn-secondary px-4 me-2">Go Back</a>
                            <a href="<?= BASE_URL ?>" class="btn btn-primary px-4">Dashboard</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Check if file exists on disk
if (!file_exists($filePath)) {
    // If it is one of our demo sample PDF files, generate a clean placeholder PDF or image on-the-fly
    if (pathinfo($record['file_attachment'], PATHINFO_EXTENSION) === 'pdf') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($record['file_original_name'] ?: 'medical_report.pdf') . '"');
        
        // Output a minimal valid PDF format document
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/MediaBox[0 0 612 792]/Parent 2 0 R/Resources<<>>/Contents 4 0 R>>endobj\n4 0 obj<</Length 120>>stream\nBT /F1 18 Tf 50 720 Td (DEPA Medical Record: " . addslashes($record['title']) . ") Tj /F1 12 Tf 0 -30 Td (Patient: " . addslashes($record['patient_name']) . " | UID: " . addslashes($record['patient_uid']) . ") Tj 0 -20 Td (Diagnosis: " . addslashes(substr($record['diagnosis'], 0, 80)) . ") Tj ET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000214 00000 n \ntrailer<</Size 5/Root 1 0 R>>\nstartxref\n386\n%%EOF";
        echo $pdfContent;
        log_audit($pdo, 'DOCUMENT_VIEWED', "Viewed document for record {$record['record_uid']} ({$record['title']}). {$accessReason}", $currentUserId, $currentRole);
        exit;
    } else {
        http_response_code(404);
        die("Document file was not found in storage repository.");
    }
}

// Determine MIME type
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// Log successful document access in audit trail
log_audit($pdo, 'DOCUMENT_DOWNLOADED', "Accessed document '{$record['file_original_name']}' for Record {$record['record_uid']} ({$record['title']}). {$accessReason}", $currentUserId, $currentRole);

// Send safe binary download / inline view headers
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . basename($record['file_original_name'] ?: $record['file_attachment']) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($filePath);
exit;
