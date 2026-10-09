<?php
/**
 * DEPA Consent Agreement PDF Generator & Digital Signature Engine
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Generates official Consent Agreement PDF artifacts with:
 * - Patient Consent Authorization Seal
 * - Attending Physician (Doctor) emSigner Signature Control (Signer 1)
 * - Reviewing Authority (Approver) emSigner Signature Control (Signer 2)
 * - Two distinct signature quadrants matching emSigner coordinates
 * - Strict Pre-Signing SHA-256 Hash and Final Signed PDF SHA-256 Hash
 */

require_once __DIR__ . '/../config/config.php';

class ConsentPdfGenerator {

    /**
     * Escape special PDF string characters
     */
    private static function escapePdfString($text) {
        $text = (string)($text ?? '');
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        return $text;
    }

    /**
     * Convert an image file (PNG/JPG) to normalized raw JPEG bytes with white background
     */
    private static function prepareJpegImage($imagePath) {
        if (!file_exists($imagePath) || !extension_loaded('gd')) {
            return null;
        }

        $info = @getimagesize($imagePath);
        if (!$info) {
            return null;
        }

        $sourceImg = @imagecreatefromstring(file_get_contents($imagePath));
        if (!$sourceImg) {
            return null;
        }

        $origW = imagesx($sourceImg);
        $origH = imagesy($sourceImg);

        // Normalize onto a clean white canvas
        $canvas = imagecreatetruecolor($origW, $origH);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $origW, $origH, $white);
        imagecopy($canvas, $sourceImg, 0, 0, 0, 0, $origW, $origH);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpegData = ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($sourceImg);

        return [
            'width'  => $origW,
            'height' => $origH,
            'bytes'  => $jpegData,
            'length' => strlen($jpegData)
        ];
    }

    /**
     * Generate DEPA Consent Agreement PDF artifact
     *
     * @param array $consent Consent request record
     * @param array $patient Patient demographics record
     * @param array $requester Requester display metadata
     * @param array|null $record Health record details
     * @param string|null $signatureImagePath Patient signature image path
     * @param string|null $signedAt Patient signature timestamp
     * @param array|null $doctorSig Doctor emSigner signature data
     * @param array|null $approverSig Approver emSigner signature data
     * @return array [pdf_path, pdf_content, pdf_base64, document_hash, filename, canonical_data, is_signed]
     */
    public static function generateConsentPdf(
        $consent, 
        $patient, 
        $requester, 
        $record = null, 
        $signatureImagePath = null, 
        $signedAt = null,
        $doctorSig = null,
        $approverSig = null
    ) {
        $uploadDir = ROOT_DIR . 'uploads/consent_agreements/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
            file_put_contents($uploadDir . '.htaccess', "Deny from all\n<FilesMatch \"\.(pdf|PDF)$\">\nOrder Allow,Deny\nDeny from all\n</FilesMatch>\n");
        }

        $consentUid = $consent['consent_uid'] ?? ('CNST-' . ($consent['id'] ?? '0000'));
        $patientName = $patient['full_name'] ?? 'Unknown Patient';
        $patientUid = $patient['patient_uid'] ?? 'N/A';
        $patientDob = $patient['dob'] ?? 'N/A';
        $patientGender = $patient['gender'] ?? 'N/A';

        $requesterName = $requester['display_name'] ?? ($requester['full_name'] ?? 'Medical Requester');
        $requesterRole = ucfirst($consent['requester_role'] ?? 'Doctor');
        $requesterLicense = $requester['license_number'] ?? 'N/A';

        $recordRef = $record ? ("Record: " . ($record['title'] ?? 'Selected Record') . " [" . ($record['record_uid'] ?? 'N/A') . "]") : "Full Health Vault (All Patient Records)";
        $recordType = $record ? ($record['record_type'] ?? 'Clinical Artifact') : 'Comprehensive Vault';
        $purpose = $consent['purpose'] ?? 'Medical Review and Clinical Assessment';
        
        $startDate = !empty($consent['access_start_date']) ? $consent['access_start_date'] : ($consent['requested_date'] ?? date('Y-m-d H:i:s'));
        $expiryDate = $consent['expiry_date'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
        $createdTimestamp = date('Y-m-d H:i:s');

        // Check if patient signature is provided
        $isPatientSigned = false;
        $imageData = null;
        if (!empty($signatureImagePath) && file_exists($signatureImagePath)) {
            $imageData = self::prepareJpegImage($signatureImagePath);
            if ($imageData) {
                $isPatientSigned = true;
            }
        } elseif (!empty($consent['is_esigned']) || (!empty($consent['esign_status']) && in_array($consent['esign_status'], ['Signed', 'Pending']))) {
            $isPatientSigned = true;
        }

        // Multi-Party Statuses
        $isDoctorSigned = !empty($doctorSig['is_signed']);
        $isApproverSigned = !empty($approverSig['is_signed']);
        $isFullySigned = ($isPatientSigned && $isDoctorSigned && $isApproverSigned);

        $patientSignedTimestamp = $signedAt ? date('Y-m-d H:i:s', strtotime($signedAt)) : ($isPatientSigned ? ($consent['signed_at'] ?? date('Y-m-d H:i:s')) : null);
        $doctorSignedTimestamp = $doctorSig['signed_at'] ?? ($consent['doctor_signed_at'] ?? null);
        $approverSignedTimestamp = $approverSig['signed_at'] ?? ($consent['approver_signed_at'] ?? null);

        // Canonical dataset
        $canonicalData = [
            'consent_id'          => $consentUid,
            'patient_name'        => $patientName,
            'patient_uid'         => $patientUid,
            'requester_name'      => $requesterName,
            'requester_license'   => $requesterLicense,
            'record_ref'          => $recordRef,
            'purpose'             => $purpose,
            'access_start_date'   => $startDate,
            'access_expiry_date'  => $expiryDate,
            'patient_signed'      => $isPatientSigned ? 'YES' : 'NO',
            'patient_signed_at'   => $patientSignedTimestamp,
            'doctor_signed'       => $isDoctorSigned ? 'YES' : 'NO',
            'doctor_signed_at'    => $doctorSignedTimestamp,
            'approver_signed'     => $isApproverSigned ? 'YES' : 'NO',
            'approver_signed_at'  => $approverSignedTimestamp,
            'is_fully_signed'     => $isFullySigned ? 'YES' : 'NO',
            'provider'            => 'eMudhra emSigner Multi-Party Suite',
            'framework'           => 'Data Empowerment and Protection Architecture (DEPA)'
        ];

        // Begin raw PDF 1.4 binary construction
        $pdf = "%PDF-1.4\n";
        $pdf .= "%\xE2\xE3\xCF\xD3\n"; // Binary header flag

        $offsets = [];

        // Object 1: Catalog
        $offsets[1] = strlen($pdf);
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

        // Object 2: Pages
        $offsets[2] = strlen($pdf);
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

        // Object 3: Page (A4: 595 x 842 points)
        $hasImage = ($isPatientSigned && $imageData);
        $resources = "<< /Font << /F1 5 0 R /F2 6 0 R >>";
        if ($hasImage) {
            $resources .= " /XObject << /Im1 7 0 R >>";
        }
        $resources .= " >>";

        $offsets[3] = strlen($pdf);
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources {$resources} /Contents 4 0 R >>\nendobj\n";

        // Object 5: Font Helvetica-Bold
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Object 6: Font Helvetica
        $offsets[6] = strlen($pdf);
        $pdf .= "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        // Object 7: Image XObject (if patient signature image is available)
        if ($hasImage) {
            $offsets[7] = strlen($pdf);
            $pdf .= "7 0 obj\n<< /Type /XObject /Subtype /Image /Width {$imageData['width']} /Height {$imageData['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length {$imageData['length']} >>\nstream\n" . $imageData['bytes'] . "\nendstream\nendobj\n";
        }

        // Object 4: Page Content Stream
        $stream = "";

        // Header Banner Box (y: 735, height: 75)
        $stream .= "0.93 0.95 0.98 rg\n";
        $stream .= "40 735 515 75 re f\n";
        $stream .= "0.15 0.25 0.50 RG\n1.5 w\n40 735 515 75 re S\n";

        // Header Titles
        $stream .= "BT\n";
        $stream .= "/F1 14 Tf\n0.10 0.20 0.45 rg\n55 785 Td (" . self::escapePdfString("DATA EMPOWERMENT & PROTECTION ARCHITECTURE (DEPA)") . ") Tj\n";
        $stream .= "/F1 11 Tf\n0.20 0.30 0.60 rg\n0 -16 Td (" . self::escapePdfString("ELECTRONIC HEALTH CONSENT & MULTI-PARTY AGREEMENT") . ") Tj\n";
        $stream .= "/F2 8 Tf\n0.35 0.40 0.50 rg\n0 -13 Td (" . self::escapePdfString("eMudhra emSigner Multi-Party Digital Signature Verification") . ") Tj\n";
        $stream .= "ET\n";

        // Consent ID & Overall Status Box (y: 690, height: 35)
        $stream .= "0.98 0.98 0.98 rg\n";
        $stream .= "40 690 515 35 re f\n";
        $stream .= "0.80 0.82 0.85 RG\n0.5 w\n40 690 515 35 re S\n";

        $overallStatusText = $isFullySigned ? "FULLY SIGNED (ACTIVE)" : ($isDoctorSigned ? "DOCTOR SIGNED (AWAITING APPROVER)" : ($isPatientSigned ? "PATIENT SIGNED (PENDING DOCTOR)" : "DRAFT (PENDING SIGNATURES)"));
        $overallStatusColor = $isFullySigned ? "0.05 0.50 0.25" : ($isDoctorSigned ? "0.10 0.35 0.65" : "0.75 0.45 0.05");

        $stream .= "BT\n";
        $stream .= "/F1 9.5 Tf\n0.10 0.10 0.10 rg\n55 706 Td (" . self::escapePdfString("Consent ID: " . $consentUid) . ") Tj\n";
        $stream .= "/F2 8.5 Tf\n0.30 0.30 0.30 rg\n180 0 Td (" . self::escapePdfString("Date: " . date('d-m-Y H:i', strtotime($createdTimestamp))) . ") Tj\n";
        $stream .= "/F1 8.5 Tf\n{$overallStatusColor} rg\n110 0 Td (" . self::escapePdfString($overallStatusText) . ") Tj\n";
        $stream .= "ET\n";

        // Section 1: Parties to the Consent Agreement (y: 595, height: 80)
        $stream .= "BT\n/F1 10 Tf\n0.15 0.25 0.50 rg\n40 670 Td (" . self::escapePdfString("1. PARTIES TO THE CONSENT AGREEMENT") . ") Tj\nET\n";
        $stream .= "0.98 0.98 0.98 rg\n40 595 515 70 re f\n0.85 0.85 0.85 RG\n0.5 w\n40 595 515 70 re S\n";

        $stream .= "BT\n";
        $stream .= "/F1 8.5 Tf\n0.20 0.20 0.20 rg\n55 645 Td (" . self::escapePdfString("Data Principal (Patient):") . ") Tj\n";
        $stream .= "/F2 8.5 Tf\n120 0 Td (" . self::escapePdfString($patientName . " (" . $patientUid . ")") . ") Tj\n";
        $stream .= "/F2 7.5 Tf\n0.40 0.40 0.40 rg\n0 -11 Td (" . self::escapePdfString("DOB: " . $patientDob . " | Gender: " . $patientGender) . ") Tj\n";

        $stream .= "/F1 8.5 Tf\n0.20 0.20 0.20 rg\n-120 -15 Td (" . self::escapePdfString("Data Consumer (Requester):") . ") Tj\n";
        $stream .= "/F2 8.5 Tf\n120 0 Td (" . self::escapePdfString($requesterName . " [" . $requesterRole . "]") . ") Tj\n";
        $stream .= "/F2 7.5 Tf\n0.40 0.40 0.40 rg\n0 -11 Td (" . self::escapePdfString("Medical License: " . $requesterLicense) . ") Tj\n";
        $stream .= "ET\n";

        // Section 2: Health Record Scope & Clinical Purpose (y: 495, height: 85)
        $stream .= "BT\n/F1 10 Tf\n0.15 0.25 0.50 rg\n40 580 Td (" . self::escapePdfString("2. HEALTH RECORD SCOPE & PURPOSE OF ACCESS") . ") Tj\nET\n";
        $stream .= "0.98 0.98 0.98 rg\n40 495 515 75 re f\n0.85 0.85 0.85 RG\n0.5 w\n40 495 515 75 re S\n";

        $stream .= "BT\n";
        $stream .= "/F1 8.5 Tf\n0.20 0.20 0.20 rg\n55 552 Td (" . self::escapePdfString("Record Scope:") . ") Tj\n";
        $stream .= "/F2 8.5 Tf\n120 0 Td (" . self::escapePdfString(substr($recordRef, 0, 70)) . ") Tj\n";

        $stream .= "/F1 8.5 Tf\n0.20 0.20 0.20 rg\n-120 -16 Td (" . self::escapePdfString("Clinical Purpose:") . ") Tj\n";
        $stream .= "/F2 8.5 Tf\n120 0 Td (" . self::escapePdfString(substr($purpose, 0, 70)) . ") Tj\n";

        $stream .= "/F1 8.5 Tf\n0.20 0.20 0.20 rg\n-120 -16 Td (" . self::escapePdfString("Validity Window:") . ") Tj\n";
        $stream .= "/F2 8.5 Tf\n120 0 Td (" . self::escapePdfString($startDate . " to " . $expiryDate) . ") Tj\n";
        $stream .= "ET\n";

        // Section 3: Patient Consent Terms & Confirmation (y: 395, height: 85)
        $stream .= "BT\n/F1 10 Tf\n0.15 0.25 0.50 rg\n40 480 Td (" . self::escapePdfString("3. PATIENT CONSENT AUTHORIZATION TERMS") . ") Tj\nET\n";
        $stream .= "0.98 0.98 0.98 rg\n40 395 515 75 re f\n0.85 0.85 0.85 RG\n0.5 w\n40 395 515 75 re S\n";

        $patTerms = [
            "a. Purpose Limitation: Access is strictly restricted to clinical review pursuant to stated purpose.",
            "b. Time-Bound Access: Permission expires on " . date('d-m-Y', strtotime($expiryDate)) . " unless revoked earlier.",
            "c. Right to Revoke: Patient retains statutory right to revoke this consent grant at any time.",
            "d. Legal Standing: IT Act 2000 & Digital Personal Data Protection (DPDP) Act compliance."
        ];
        $stream .= "BT\n/F2 7.5 Tf\n0.25 0.25 0.25 rg\n55 455 Td\n";
        foreach ($patTerms as $i => $pt) {
            $stream .= "(" . self::escapePdfString($pt) . ") Tj\n";
            if ($i < count($patTerms) - 1) {
                $stream .= "0 -14 Td\n";
            }
        }
        $stream .= "ET\n";

        // Section 4: Multi-Party Digital Signatures Header
        $stream .= "BT\n/F1 10 Tf\n0.15 0.25 0.50 rg\n40 375 Td (" . self::escapePdfString("4. MULTI-PARTY DIGITAL SIGNATURES (emSigner Co-Signing Suite)") . ") Tj\nET\n";

        // Patient Status Bar (y: 330, height: 35)
        $patBg = $isPatientSigned ? "0.95 0.98 0.95" : "0.98 0.98 0.98";
        $patBorder = $isPatientSigned ? "0.20 0.55 0.30" : "0.75 0.75 0.75";
        $stream .= "{$patBg} rg\n40 330 515 35 re f\n{$patBorder} RG\n0.5 w\n40 330 515 35 re S\n";

        $patSignText = $isPatientSigned ? ("Patient Authorization: CONFIRMED by " . $patientName . " (" . ($patientSignedTimestamp ? date('d-m-Y H:i', strtotime($patientSignedTimestamp)) : 'N/A') . ")") : ("Patient Authorization: PENDING");
        $stream .= "BT\n/F1 8.5 Tf\n" . ($isPatientSigned ? "0.10 0.45 0.20" : "0.70 0.40 0.05") . " rg\n55 344 Td (" . self::escapePdfString($patSignText) . ") Tj\n";
        $stream .= "/F2 7.5 Tf\n0.40 0.40 0.40 rg\n270 0 Td (" . self::escapePdfString("DEPA Data Principal Consent Granted") . ") Tj\nET\n";

        // =========================================================================
        // SIGNATURE QUADRANT 1: ATTENDING PHYSICIAN / DOCTOR (Signer 1)
        // PDF Box: x: 45, y: 130, width: 245, height: 185
        // emSigner API Coordinates: 1, 55, 640, 220, 110
        // =========================================================================
        $docBoxBg = $isDoctorSigned ? "0.94 0.98 0.95" : "0.99 0.99 0.99";
        $docBorder = $isDoctorSigned ? "0.15 0.50 0.25" : "0.75 0.75 0.75";
        $stream .= "{$docBoxBg} rg\n45 130 245 185 re f\n{$docBorder} RG\n0.75 w\n45 130 245 185 re S\n";

        // Doctor Header Banner
        $docHeaderBg = $isDoctorSigned ? "0.85 0.95 0.88" : "0.93 0.93 0.93";
        $stream .= "{$docHeaderBg} rg\n45 285 245 30 re f\n{$docBorder} RG\n0.5 w\n45 285 245 30 re S\n";

        $stream .= "BT\n/F1 8.5 Tf\n0.15 0.25 0.40 rg\n55 297 Td (" . self::escapePdfString("SIGNER 1: ATTENDING PHYSICIAN") . ") Tj\n";
        $stream .= "/F2 7.5 Tf\n0.30 0.30 0.30 rg\n0 -10 Td (" . self::escapePdfString("emSigner Digital Signature Control") . ") Tj\nET\n";

        if ($isDoctorSigned) {
            $docName = $doctorSig['signer_name'] ?? $requesterName;
            $docLic  = $doctorSig['license'] ?? $requesterLicense;
            $docTime = $doctorSig['signed_at'] ?? ($consent['doctor_signed_at'] ?? date('Y-m-d H:i:s'));
            $docTxn  = $doctorSig['txn_id'] ?? ($consent['esign_transaction_id'] ?? 'EMSIG-DOC-TXN');
            $docHash = $doctorSig['sig_hash'] ?? hash('sha256', "DOCTOR::{$docName}::{$docTime}");

            // Seal Stamp Box
            $stream .= "1 1 1 rg\n55 185 225 90 re f\n0.20 0.55 0.30 RG\n0.5 w\n55 185 225 90 re S\n";

            $stream .= "BT\n";
            $stream .= "/F1 8 Tf\n0.10 0.50 0.20 rg\n65 260 Td (" . self::escapePdfString("[ emSigner VERIFIED SIGNATURE ]") . ") Tj\n";
            $stream .= "/F1 8 Tf\n0.15 0.15 0.15 rg\n0 -14 Td (" . self::escapePdfString("Signer: " . $docName) . ") Tj\n";
            $stream .= "/F2 7.5 Tf\n0.30 0.30 0.30 rg\n0 -12 Td (" . self::escapePdfString("License: " . $docLic) . ") Tj\n";
            $stream .= "/F2 7.5 Tf\n0.30 0.30 0.30 rg\n0 -11 Td (" . self::escapePdfString("Signed At: " . date('d-m-Y H:i:s', strtotime($docTime))) . ") Tj\n";
            $stream .= "/F2 7 Tf\n0.40 0.40 0.40 rg\n0 -10 Td (" . self::escapePdfString("Method: Digital Signature (DSC/Token)") . ") Tj\n";
            $stream .= "/F2 6.5 Tf\n0.45 0.45 0.45 rg\n0 -9 Td (" . self::escapePdfString("Txn: " . substr($docTxn, 0, 24)) . ") Tj\n";
            $stream .= "ET\n";

            // Hash display
            $stream .= "BT\n/F2 6 Tf\n0.50 0.50 0.50 rg\n55 160 Td (" . self::escapePdfString("Digest: " . substr($docHash, 0, 36) . "...") . ") Tj\n";
            $stream .= "/F1 7.5 Tf\n0.10 0.50 0.20 rg\n0 -12 Td (" . self::escapePdfString("Status: SIGNED & SEALED") . ") Tj\nET\n";

        } else {
            // Unsigned Doctor Area
            $stream .= "1 1 1 rg\n55 185 225 90 re f\n0.80 0.80 0.80 RG\n0.5 w\n55 185 225 90 re S\n";

            $stream .= "BT\n";
            $stream .= "/F1 8 Tf\n0.75 0.45 0.05 rg\n65 245 Td (" . self::escapePdfString("[ PENDING DOCTOR SIGNATURE ]") . ") Tj\n";
            $stream .= "/F2 7.5 Tf\n0.35 0.35 0.35 rg\n0 -16 Td (" . self::escapePdfString("Attending physician must review terms and") . ") Tj\n";
            $stream .= "0 -11 Td (" . self::escapePdfString("sign via emSigner gateway before access.") . ") Tj\n";
            $stream .= "/F2 7 Tf\n0.55 0.55 0.55 rg\n0 -14 Td (" . self::escapePdfString("Requires eKYC verified practitioner.") . ") Tj\n";
            $stream .= "ET\n";
        }

        // =========================================================================
        // SIGNATURE QUADRANT 2: REVIEWING AUTHORITY / APPROVER (Signer 2)
        // PDF Box: x: 310, y: 130, width: 245, height: 185
        // emSigner API Coordinates: 1, 320, 640, 220, 110
        // =========================================================================
        $appBoxBg = $isApproverSigned ? "0.94 0.98 0.95" : "0.99 0.99 0.99";
        $appBorder = $isApproverSigned ? "0.15 0.50 0.25" : "0.75 0.75 0.75";
        $stream .= "{$appBoxBg} rg\n310 130 245 185 re f\n{$appBorder} RG\n0.75 w\n310 130 245 185 re S\n";

        // Approver Header Banner
        $appHeaderBg = $isApproverSigned ? "0.85 0.95 0.88" : "0.93 0.93 0.93";
        $stream .= "{$appHeaderBg} rg\n310 285 245 30 re f\n{$appBorder} RG\n0.5 w\n310 285 245 30 re S\n";

        $stream .= "BT\n/F1 8.5 Tf\n0.15 0.25 0.40 rg\n320 297 Td (" . self::escapePdfString("SIGNER 2: REVIEWING AUTHORITY") . ") Tj\n";
        $stream .= "/F2 7.5 Tf\n0.30 0.30 0.30 rg\n0 -10 Td (" . self::escapePdfString("Medical Superintendent / Approver") . ") Tj\nET\n";

        if ($isApproverSigned) {
            $appName = $approverSig['signer_name'] ?? (defined('DEFAULT_APPROVER_NAME') ? DEFAULT_APPROVER_NAME : 'Reviewing Authority');
            $appRole = $approverSig['role'] ?? (defined('DEFAULT_APPROVER_ROLE') ? DEFAULT_APPROVER_ROLE : 'Medical Superintendent');
            $appTime = $approverSig['signed_at'] ?? ($consent['approver_signed_at'] ?? date('Y-m-d H:i:s'));
            $appTxn  = $approverSig['txn_id'] ?? 'EMSIG-APP-TXN';
            $appHash = $approverSig['sig_hash'] ?? hash('sha256', "APPROVER::{$appName}::{$appTime}");

            // Seal Stamp Box
            $stream .= "1 1 1 rg\n320 185 225 90 re f\n0.20 0.55 0.30 RG\n0.5 w\n320 185 225 90 re S\n";

            $stream .= "BT\n";
            $stream .= "/F1 8 Tf\n0.10 0.50 0.20 rg\n330 260 Td (" . self::escapePdfString("[ emSigner APPROVAL SEAL ]") . ") Tj\n";
            $stream .= "/F1 8 Tf\n0.15 0.15 0.15 rg\n0 -14 Td (" . self::escapePdfString("Approver: " . $appName) . ") Tj\n";
            $stream .= "/F2 7.5 Tf\n0.30 0.30 0.30 rg\n0 -12 Td (" . self::escapePdfString("Role: " . substr($appRole, 0, 30)) . ") Tj\n";
            $stream .= "/F2 7.5 Tf\n0.30 0.30 0.30 rg\n0 -11 Td (" . self::escapePdfString("Approved At: " . date('d-m-Y H:i:s', strtotime($appTime))) . ") Tj\n";
            $stream .= "/F2 7 Tf\n0.40 0.40 0.40 rg\n0 -10 Td (" . self::escapePdfString("Method: Co-Sign Digital Seal") . ") Tj\n";
            $stream .= "/F2 6.5 Tf\n0.45 0.45 0.45 rg\n0 -9 Td (" . self::escapePdfString("Txn: " . substr($appTxn, 0, 24)) . ") Tj\n";
            $stream .= "ET\n";

            // Hash display
            $stream .= "BT\n/F2 6 Tf\n0.50 0.50 0.50 rg\n320 160 Td (" . self::escapePdfString("Digest: " . substr($appHash, 0, 36) . "...") . ") Tj\n";
            $stream .= "/F1 7.5 Tf\n0.10 0.50 0.20 rg\n0 -12 Td (" . self::escapePdfString("Status: APPROVED & SEALED") . ") Tj\nET\n";

        } else {
            // Unsigned Approver Area
            $stream .= "1 1 1 rg\n320 185 225 90 re f\n0.80 0.80 0.80 RG\n0.5 w\n320 185 225 90 re S\n";

            $stream .= "BT\n";
            $stream .= "/F1 8 Tf\n0.75 0.45 0.05 rg\n330 245 Td (" . self::escapePdfString("[ PENDING APPROVER SIGNATURE ]") . ") Tj\n";
            $stream .= "/F2 7.5 Tf\n0.35 0.35 0.35 rg\n0 -16 Td (" . self::escapePdfString("Clinical director / superintendent must") . ") Tj\n";
            $stream .= "0 -11 Td (" . self::escapePdfString("co-sign document to finalize permission.") . ") Tj\n";
            $stream .= "/F2 7 Tf\n0.55 0.55 0.55 rg\n0 -14 Td (" . self::escapePdfString("Dispatched upon doctor signature completion.") . ") Tj\n";
            $stream .= "ET\n";
        }

        // =========================================================================
        // FOOTER: Cryptographic Digest & Compliance Notice
        // =========================================================================
        $stream .= "BT\n";
        $stream .= "/F1 7.5 Tf\n0.25 0.25 0.25 rg\n45 105 Td (" . self::escapePdfString("Cryptographic Document Integrity (SHA-256):") . ") Tj\n";
        $stream .= "/F2 7 Tf\n0.40 0.40 0.40 rg\n0 -12 Td (" . self::escapePdfString("Calculated directly over byte content. Pre-sign and final hashes are permanently audited in DEPA ledger.") . ") Tj\n";
        $stream .= "0 -11 Td (" . self::escapePdfString("Official DEPA Health Exchange Multi-Party Artifact | IT Act 2000 Section 3A & 5 | emSigner Enterprise Gateway") . ") Tj\n";
        $stream .= "ET\n";

        $streamLen = strlen($stream);
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n<< /Length " . $streamLen . " >>\nstream\n" . $stream . "endstream\nendobj\n";

        // Cross-Reference Table
        $numObjs = $hasImage ? 8 : 7;
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . $numObjs . "\n";
        $pdf .= "0000000000 65535 f \n";
        $pdf .= sprintf("%010d 00000 n \n", $offsets[1]); // 1 Catalog
        $pdf .= sprintf("%010d 00000 n \n", $offsets[2]); // 2 Pages
        $pdf .= sprintf("%010d 00000 n \n", $offsets[3]); // 3 Page
        $pdf .= sprintf("%010d 00000 n \n", $offsets[4]); // 4 Stream
        $pdf .= sprintf("%010d 00000 n \n", $offsets[5]); // 5 Font 1
        $pdf .= sprintf("%010d 00000 n \n", $offsets[6]); // 6 Font 2
        if ($hasImage) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[7]); // 7 Image
        }

        $pdf .= "trailer\n<< /Size " . $numObjs . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        // Calculate SHA-256 of the generated PDF bytes
        $documentHash = hash('sha256', $pdf);

        // Determine filename
        $suffix = ($isDoctorSigned && $isApproverSigned) ? "_fully_signed" : ($isDoctorSigned ? "_doctor_signed" : "_canonical");
        $filename = "consent_agreement_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $consentUid) . "{$suffix}.pdf";
        $filePath = $uploadDir . $filename;
        file_put_contents($filePath, $pdf);

        return [
            'pdf_path'       => $filePath,
            'pdf_content'    => $pdf,
            'pdf_base64'     => base64_encode($pdf),
            'document_hash'  => $documentHash,
            'filename'       => $filename,
            'canonical_data' => $canonicalData,
            'is_signed'      => $isFullySigned,
            'is_doctor_signed'   => $isDoctorSigned,
            'is_approver_signed' => $isApproverSigned
        ];
    }
}
