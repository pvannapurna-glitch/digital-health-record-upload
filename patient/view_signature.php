<?php
/**
 * Secure Patient Signature Image Viewer Controller
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_login();

$pdo = get_db_connection();
$userId = get_logged_user_id();
$userRole = get_user_role();

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if ($userRole === 'patient') {
    // A patient can only view their own signature
    $stmt = $pdo->prepare("SELECT id, signature_image FROM `patients` WHERE `user_id` = ?");
    $stmt->execute([$userId]);
    $patient = $stmt->fetch();
} else {
    // Admin, Doctor, or Hospital
    if ($patientId <= 0) {
        http_response_code(400);
        die("Patient ID required.");
    }

    if ($userRole === 'admin') {
        $stmt = $pdo->prepare("SELECT id, signature_image FROM `patients` WHERE `id` = ?");
        $stmt->execute([$patientId]);
        $patient = $stmt->fetch();
    } elseif ($userRole === 'doctor' || $userRole === 'hospital') {
        // Can only view if active consent exists
        $stmt = $pdo->prepare("SELECT p.id, p.signature_image 
                               FROM `patients` p 
                               JOIN `consent_requests` cr ON p.id = cr.patient_id 
                               WHERE p.id = ? AND cr.requester_user_id = ? AND cr.status IN ('Active', 'Approved') AND cr.is_esigned = 1");
        $stmt->execute([$patientId, $userId]);
        $patient = $stmt->fetch();
    } else {
        $patient = null;
    }
}

if (!$patient || empty($patient['signature_image'])) {
    http_response_code(404);
    die("Signature image not found.");
}

$safeFilename = basename($patient['signature_image']);
$filePath = SIGNATURE_UPLOAD_DIR . $safeFilename;

if (!file_exists($filePath)) {
    http_response_code(404);
    die("Signature file missing from secure storage.");
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimeMap = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg'
];
$mime = $mimeMap[$ext] ?? 'image/png';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
