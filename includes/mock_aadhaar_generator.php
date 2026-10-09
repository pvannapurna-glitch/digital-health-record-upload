<?php
/**
 * Mock Aadhaar Card Document Generator (Sandbox / Academic Demo)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Generates sample mock Aadhaar cards (PDF, Offline XML, and Photos) for development and evaluation.
 * STRICT POLICY: NEVER uses real Aadhaar documents or genuine personal identifiers.
 */

if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../config/config.php';
}

class MockAadhaarGenerator {

    /**
     * Generate high-quality photographic portrait SVG for Demo Aadhaar Card Holder
     */
    public static function getSamplePhotoSvg($gender = 'Male', $name = 'John Doe') {
        $isFemale = (strcasecmp($gender, 'Female') === 0 || stripos($name, 'smruthi') !== false || stripos($name, 'priya') !== false || stripos($name, 'anita') !== false);
        $skinTone = '#f5c6a5';
        $hairColor = '#2b1d14';
        $shirtColor = $isFemale ? '#0d6efd' : '#198754';
        
        $initials = strtoupper(substr(trim($name), 0, 1));
        if (strpos(trim($name), ' ') !== false) {
            $parts = explode(' ', trim($name));
            $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
        }

        if ($isFemale) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 240" width="200" height="240">
                <defs>
                    <linearGradient id="bgGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#e3f2fd" />
                        <stop offset="100%" stop-color="#bbdefb" />
                    </linearGradient>
                    <linearGradient id="shirtGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#3b82f6" />
                        <stop offset="100%" stop-color="#1d4ed8" />
                    </linearGradient>
                </defs>
                <rect width="200" height="240" fill="url(#bgGrad)" rx="8" />
                <!-- Watermark / Security Grid -->
                <path d="M0 40 H200 M0 80 H200 M0 120 H200 M0 160 H200 M0 200 H200" stroke="#90caf9" stroke-width="0.5" opacity="0.4"/>
                <path d="M40 0 V240 M80 0 V240 M120 0 V240 M160 0 V240" stroke="#90caf9" stroke-width="0.5" opacity="0.4"/>
                
                <!-- Hair Back -->
                <path d="M50 80 C40 120 40 180 60 210 C140 210 160 180 150 80 C145 40 55 40 50 80 Z" fill="' . $hairColor . '"/>
                <!-- Neck & Shoulders -->
                <path d="M85 130 L85 170 L115 170 L115 130 Z" fill="' . $skinTone . '"/>
                <path d="M30 240 C30 190 70 170 100 170 C130 170 170 190 170 240 Z" fill="url(#shirtGrad)"/>
                <!-- Face -->
                <ellipse cx="100" cy="105" rx="42" ry="50" fill="' . $skinTone . '"/>
                <!-- Hair Front -->
                <path d="M55 85 C65 50 135 50 145 85 C140 70 120 62 100 65 C80 62 60 70 55 85 Z" fill="' . $hairColor . '"/>
                <!-- Eyes & Eyebrows -->
                <ellipse cx="84" cy="102" rx="4.5" ry="3.5" fill="#1e293b"/>
                <ellipse cx="116" cy="102" rx="4.5" ry="3.5" fill="#1e293b"/>
                <circle cx="86" cy="101" r="1.5" fill="#ffffff"/>
                <circle cx="118" cy="101" r="1.5" fill="#ffffff"/>
                <path d="M75 93 Q84 90 93 93" stroke="' . $hairColor . '" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                <path d="M107 93 Q116 90 125 93" stroke="' . $hairColor . '" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                <!-- Nose -->
                <path d="M100 102 L98 116 L104 116" stroke="#d49a7a" stroke-width="2" fill="none" stroke-linecap="round"/>
                <!-- Lips -->
                <path d="M88 128 Q100 135 112 128" stroke="#e11d48" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                <!-- Bindi / Demographics mark -->
                <circle cx="100" cy="92" r="2.5" fill="#dc2626"/>
                <!-- Official Security Emblem Watermark -->
                <circle cx="170" cy="30" r="14" fill="#ffffff" opacity="0.85"/>
                <path d="M165 30 L169 34 L177 26" stroke="#16a34a" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>';
        } else {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 240" width="200" height="240">
                <defs>
                    <linearGradient id="bgGradM" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#f0fdf4" />
                        <stop offset="100%" stop-color="#dcfce7" />
                    </linearGradient>
                    <linearGradient id="shirtGradM" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#059669" />
                        <stop offset="100%" stop-color="#047857" />
                    </linearGradient>
                </defs>
                <rect width="200" height="240" fill="url(#bgGradM)" rx="8" />
                <!-- Watermark / Security Grid -->
                <path d="M0 40 H200 M0 80 H200 M0 120 H200 M0 160 H200 M0 200 H200" stroke="#bbf7d0" stroke-width="0.5" opacity="0.4"/>
                <path d="M40 0 V240 M80 0 V240 M120 0 V240 M160 0 V240" stroke="#bbf7d0" stroke-width="0.5" opacity="0.4"/>
                
                <!-- Shoulders & Collar -->
                <path d="M85 130 L85 170 L115 170 L115 130 Z" fill="' . $skinTone . '"/>
                <path d="M25 240 C25 185 65 165 100 165 C135 165 175 185 175 240 Z" fill="url(#shirtGradM)"/>
                <!-- Shirt Collar -->
                <path d="M100 165 L85 190 L100 205 L115 190 Z" fill="#ffffff"/>
                <!-- Face -->
                <ellipse cx="100" cy="100" rx="42" ry="48" fill="' . $skinTone . '"/>
                <!-- Hair -->
                <path d="M58 85 C55 50 80 40 100 40 C120 40 145 50 142 85 C135 60 115 55 100 55 C85 55 65 60 58 85 Z" fill="' . $hairColor . '"/>
                <!-- Eyes & Eyebrows -->
                <ellipse cx="84" cy="98" rx="4.5" ry="3.5" fill="#1e293b"/>
                <ellipse cx="116" cy="98" rx="4.5" ry="3.5" fill="#1e293b"/>
                <circle cx="86" cy="97" r="1.5" fill="#ffffff"/>
                <circle cx="118" cy="97" r="1.5" fill="#ffffff"/>
                <path d="M74 88 Q84 84 94 88" stroke="' . $hairColor . '" stroke-width="3" fill="none" stroke-linecap="round"/>
                <path d="M106 88 Q116 84 126 88" stroke="' . $hairColor . '" stroke-width="3" fill="none" stroke-linecap="round"/>
                <!-- Nose -->
                <path d="M100 98 L97 114 L105 114" stroke="#d49a7a" stroke-width="2" fill="none" stroke-linecap="round"/>
                <!-- Lips -->
                <path d="M88 126 Q100 132 112 126" stroke="#be123c" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                <!-- Security Emblem Watermark -->
                <circle cx="170" cy="30" r="14" fill="#ffffff" opacity="0.85"/>
                <path d="M165 30 L169 34 L177 26" stroke="#16a34a" stroke-width="2.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>';
        }

        return trim($svg);
    }

    /**
     * Get Base64 encoded photograph data URI
     */
    public static function getSamplePhotoBase64($gender = 'Male', $name = 'John Doe') {
        $svg = self::getSamplePhotoSvg($gender, $name);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Generate valid standard UIDAI Offline Paperless e-KYC XML
     */
    public static function generateSampleXml($name = 'John Doe', $dob = '01/01/2000', $gender = 'Male', $address = 'Flat 402, Lotus Heights, Health City, New Delhi 110001') {
        $photoBase64 = base64_encode(self::getSamplePhotoSvg($gender, $name));
        $cleanName = htmlspecialchars($name, ENT_XML1, 'UTF-8');
        $cleanDob = htmlspecialchars($dob, ENT_XML1, 'UTF-8');
        $cleanGender = htmlspecialchars($gender, ENT_XML1, 'UTF-8');
        $cleanAddress = htmlspecialchars($address, ENT_XML1, 'UTF-8');

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<OfflinePaperlessKyc referenceId=\"DEMO" . time() . "0001\">\n";
        $xml .= "    <UidData>\n";
        $xml .= "        <Poi name=\"{$cleanName}\" dob=\"{$cleanDob}\" gender=\"{$cleanGender}\" phone=\"9876543210\" e=\"user@example.com\"/>\n";
        $xml .= "        <Poa careof=\"S/O Caretaker\" house=\"402\" street=\"Lotus Heights\" landmark=\"Health City\" loc=\"South Ext\" vtc=\"New Delhi\" dist=\"New Delhi\" state=\"Delhi\" pc=\"110001\" formattedAddress=\"{$cleanAddress}\"/>\n";
        $xml .= "        <Pht>{$photoBase64}</Pht>\n";
        $xml .= "    </UidData>\n";
        $xml .= "    <SignatureInfo>\n";
        $xml .= "        <SignerName>UIDAI Demonstration Authority</SignerName>\n";
        $xml .= "        <Timestamp>" . date('Y-m-d\TH:i:sP') . "</Timestamp>\n";
        $xml .= "    </SignatureInfo>\n";
        $xml .= "</OfflinePaperlessKyc>\n";

        return $xml;
    }

    /**
     * Generate valid sample mock Aadhaar PDF
     */
    public static function generateSamplePdf($name = 'John Doe', $dob = '01/01/2000', $gender = 'Male', $address = 'Flat 402, Lotus Heights, Health City, New Delhi 110001') {
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $pdf .= "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $pdf .= "3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R>>\nendobj\n";
        
        $stream = "BT\n";
        $stream .= "/F1 14 Tf\n";
        $stream .= "50 780 Td (Government of India) Tj\n";
        $stream .= "0 -20 Td (Unique Identification Authority of India) Tj\n";
        $stream .= "0 -15 Td (Mera Aadhaar, Meri Pehchan) Tj\n";
        $stream .= "/F1 10 Tf\n";
        $stream .= "0 -30 Td (Enrollment No: 1234/56789/01234) Tj\n";
        $stream .= "0 -25 Td (To,) Tj\n";
        $stream .= "/F1 12 Tf\n";
        $stream .= "0 -18 Td (" . addslashes($name) . ") Tj\n";
        $stream .= "/F1 10 Tf\n";
        $stream .= "0 -16 Td (DOB: " . addslashes($dob) . ") Tj\n";
        $stream .= "0 -16 Td (Gender: " . addslashes($gender) . ") Tj\n";
        $stream .= "0 -16 Td (Address: " . addslashes($address) . ") Tj\n";
        $stream .= "/F1 14 Tf\n";
        $stream .= "0 -35 Td (XXXX XXXX 1234) Tj\n";
        $stream .= "/F1 9 Tf\n";
        $stream .= "0 -25 Td (Helpdesk: 1947 | help@uidai.gov.in | www.uidai.gov.in) Tj\n";
        $stream .= "0 -15 Td (Electronic Aadhaar - Academic Demonstration Sample) Tj\n";
        $stream .= "ET\n";

        $length = strlen($stream);
        $pdf .= "4 0 obj\n<</Length {$length}>>\nstream\n{$stream}\nendstream\nendobj\n";
        $pdf .= "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n";
        $pdf .= "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000056 00000 n \n0000000111 00000 n \n";
        $pdf .= sprintf("%010d 00000 n \n", 220);
        $pdf .= sprintf("%010d 00000 n \n", 270 + $length);
        $pdf .= "trailer\n<</Size 6 /Root 1 0 R>>\nstartxref\n" . (340 + $length) . "\n%%EOF";

        return $pdf;
    }

    /**
     * Generate invalid/unreadable document PDF (for negative testing)
     */
    public static function generateInvalidPdf() {
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n";
        $pdf .= "2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n";
        $pdf .= "3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R>>\nendobj\n";
        
        $stream = "BT\n/F1 12 Tf\n50 780 Td (Random Invoice Document) Tj\n0 -20 Td (Total Amount: Rs. 500) Tj\n0 -20 Td (Date: 2026-09-10) Tj\nET\n";
        $length = strlen($stream);
        $pdf .= "4 0 obj\n<</Length {$length}>>\nstream\n{$stream}\nendstream\nendobj\n";
        $pdf .= "5 0 obj\n<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>\nendobj\n";
        $pdf .= "xref\n0 6\n0000000000 65535 f \n0000000009 00000 n \n0000000056 00000 n \n0000000111 00000 n \n";
        $pdf .= sprintf("%010d 00000 n \n", 220);
        $pdf .= sprintf("%010d 00000 n \n", 270 + $length);
        $pdf .= "trailer\n<</Size 6 /Root 1 0 R>>\nstartxref\n" . (340 + $length) . "\n%%EOF";

        return $pdf;
    }

    /**
     * Save mock files to assets/samples/
     */
    public static function ensureSampleFiles() {
        $sampleDir = ROOT_DIR . 'assets' . DIRECTORY_SEPARATOR . 'samples' . DIRECTORY_SEPARATOR;
        if (!is_dir($sampleDir)) {
            @mkdir($sampleDir, 0755, true);
        }

        $xmlPath = $sampleDir . 'sample_mock_aadhaar.xml';
        if (!file_exists($xmlPath)) {
            file_put_contents($xmlPath, self::generateSampleXml('John Doe', '01/01/2000', 'Male'));
        }

        $pdfPath = $sampleDir . 'sample_mock_aadhaar.pdf';
        if (!file_exists($pdfPath)) {
            file_put_contents($pdfPath, self::generateSamplePdf('John Doe', '01/01/2000', 'Male'));
        }

        $invalidPdfPath = $sampleDir . 'sample_invalid_doc.pdf';
        if (!file_exists($invalidPdfPath)) {
            file_put_contents($invalidPdfPath, self::generateInvalidPdf());
        }

        return [
            'valid_xml' => $xmlPath,
            'valid_pdf' => $pdfPath,
            'invalid_pdf' => $invalidPdfPath
        ];
    }
}
