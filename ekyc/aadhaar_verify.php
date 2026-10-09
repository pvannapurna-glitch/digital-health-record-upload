<?php
/**
 * Aadhaar Card Document Verification Router
 */
require_once __DIR__ . '/../config/config.php';
require_login();
header("Location: " . BASE_URL . "ekyc/verify_aadhaar.php");
exit;
