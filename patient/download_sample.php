<?php
/**
 * Safe Sample Mock Aadhaar Card Download Endpoint
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/mock_aadhaar_generator.php';

$type = $_GET['type'] ?? 'valid';

MockAadhaarGenerator::ensureSampleFiles();
$sampleDir = ROOT_DIR . 'assets' . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR;

if ($type === 'invalid') {
    $filePath = $sampleDir . 'sample_invalid_doc.pdf';
    $downloadName = 'sample_invalid_doc.pdf';
} else {
    $filePath = $sampleDir . 'sample_mock_aadhaar.pdf';
    $downloadName = 'sample_mock_aadhaar.pdf';
}

if (!file_exists($filePath)) {
    die("Sample file not available.");
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Cache-Control: private, max-age=0');
readfile($filePath);
exit;
