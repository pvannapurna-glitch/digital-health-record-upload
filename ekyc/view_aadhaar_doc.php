<?php
/**
 * Secure Aadhaar Document Retrieval Controller (Multi-Role)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Strict Access Control:
 * - Only the owning user (Patient or Doctor) or an authorized Administrator can access.
 * - Prevents path traversal and direct URL access.
 */

require_once __DIR__ . '/../config/config.php';

require_login();

$pdo = get_db_connection();
$currentUserId = get_logged_user_id();
$currentRole   = get_user_role();
$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$ref   = isset($_GET['ref']) ? trim($_GET['ref']) : '';

if ($docId <= 0 && empty($ref)) {
    http_response_code(400);
    die("Invalid Aadhaar verification identifier.");
}

if ($docId > 0) {
    $stmt = $pdo->prepare("SELECT a.*, u.username FROM `aadhaar_ekyc` a JOIN `users` u ON a.user_id = u.id WHERE a.id = ?");
    $stmt->execute([$docId]);
} else {
    $stmt = $pdo->prepare("SELECT a.*, u.username FROM `aadhaar_ekyc` a JOIN `users` u ON a.user_id = u.id WHERE a.document_reference = ?");
    $stmt->execute([$ref]);
}

$record = $stmt->fetch();

if (!$record) {
    http_response_code(404);
    die("Aadhaar document record not found.");
}

// Role and Ownership Authorization Check
$isOwner = ((int)$record['user_id'] === (int)$currentUserId);
$isAdmin = ($currentRole === 'admin');

if (!$isOwner && !$isAdmin) {
    log_audit($pdo, 'UNAUTHORIZED_AADHAAR_ACCESS', "Denied unauthorized access attempt to Aadhaar document ID {$record['id']} (Owner User ID: {$record['user_id']}).", $currentUserId, $currentRole);
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>403 Forbidden - Aadhaar Privacy Protection</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light py-5">
        <div class="container text-center">
            <div class="card shadow-sm border-danger p-5 mx-auto" style="max-width: 500px;">
                <h3 class="text-danger fw-bold">403 Access Denied</h3>
                <p class="text-secondary mt-2">Aadhaar verification documents are strictly confidential and protected by privacy protocols.</p>
                <a href="<?= BASE_URL ?>" class="btn btn-primary mt-3">Return to Portal</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$filename = basename($record['document_reference']);
$filePath = AADHAAR_DOC_DIR . $filename;

if (!file_exists($filePath)) {
    http_response_code(404);
    die("Aadhaar document file not found in secure vault.");
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'xml'  => 'text/xml',
    'zip'  => 'application/zip'
];
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

log_audit($pdo, 'AADHAAR_DOCUMENT_VIEWED', "Aadhaar eKYC document viewed securely for user {$record['username']} (Doc ID: {$record['id']}).", $currentUserId, $currentRole);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="aadhaar_document_' . $record['id'] . '.' . $ext . '"');
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
readfile($filePath);
exit;
