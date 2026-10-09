<?php
/**
 * Patient Aadhaar eKYC Gateway
 * Forwards seamlessly to unified Aadhaar verification controller
 */
require_once __DIR__ . '/../config/config.php';
require_login();
require_once ROOT_DIR . 'ekyc/verify_aadhaar.php';
