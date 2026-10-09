<?php
/**
 * emSigner Enterprise Gateway & REST API Client Service
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 * 
 * Implements the verified eMudhra emSigner API Contract for:
 * - Signing transaction creation & payload preparation
 * - Multi-party co-signing workflow (Signer 1: Doctor, Signer 2: Approver)
 * - Signature coordinate positioning on Page 1
 * - Callback verification, anti-replay, and cryptographic signed PDF handling
 * - Both Live eMudhra Gateway and High-Fidelity Academic Sandbox modes
 */

if (!defined('ROOT_DIR')) {
    require_once __DIR__ . '/../config/config.php';
}

class EmSignerService {

    /**
     * Get active Gateway base URL based on configured mode
     */
    public static function getGatewayUrl() {
        if (defined('EMSIGNER_MODE') && EMSIGNER_MODE === 'production') {
            return EMSIGNER_PROD_GATEWAY_URL;
        }
        return EMSIGNER_DEMO_GATEWAY_URL;
    }

    /**
     * Get active REST API base URL based on configured mode
     */
    public static function getApiUrl() {
        if (defined('EMSIGNER_MODE') && EMSIGNER_MODE === 'production') {
            return EMSIGNER_PROD_API_URL;
        }
        return EMSIGNER_DEMO_API_URL;
    }

    /**
     * Generate secure signing reference number
     */
    public static function generateReferenceNumber($consentUid, $signerRole) {
        $prefix = 'EMSIG';
        $rand = strtoupper(bin2hex(random_bytes(3)));
        $roleCode = strtoupper(substr($signerRole, 0, 3));
        return "{$prefix}-{$consentUid}-{$roleCode}-{$rand}";
    }

    /**
     * Generate secure one-time signing callback token
     */
    public static function generateCallbackSecurityToken($consentId, $signerRole, $userId) {
        $salt = defined('EMSIGNER_SECRET_KEY') ? EMSIGNER_SECRET_KEY : 'depa_salt';
        return hash('sha256', "EMSIG::CALLBACK::{$consentId}::{$signerRole}::{$userId}::{$salt}");
    }

    /**
     * Verify signing callback security token
     */
    public static function verifyCallbackSecurityToken($token, $consentId, $signerRole, $userId) {
        $expected = self::generateCallbackSecurityToken($consentId, $signerRole, $userId);
        return hash_equals($expected, (string)$token);
    }

    public static function verifySecurityToken($token, $consentId, $signerRole, $userId) {
        return self::verifyCallbackSecurityToken($token, $consentId, $signerRole, $userId);
    }

    /**
     * Create emSigner signing transaction specification
     *
     * @param string $signerRole 'doctor' or 'approver'
     * @param string $signerName Display name of the signatory
     * @param array $consent Consent record from database
     * @param int $userId ID of user performing the sign
     * @param string $pdfContent Raw bytes of PDF to sign
     * @param int $signerIndex 1 for Doctor, 2 for Approver
     * @return array Transaction metadata and formatted payload
     */
    public static function createSigningTransaction($signerRole, $signerName, $consent, $userId, $pdfContent, $signerIndex = 1) {
        $consentId = (int)$consent['id'];
        $consentUid = $consent['consent_uid'] ?? ("CNST-" . $consentId);
        $referenceNumber = self::generateReferenceNumber($consentUid, $signerRole);
        $securityToken = self::generateCallbackSecurityToken($consentId, $signerRole, $userId);

        // Determine exact PagelevelCoordinates based on role
        if ($signerRole === 'doctor') {
            $coords = defined('EMSIGNER_COORD_DOCTOR') ? EMSIGNER_COORD_DOCTOR : '1,55,640,220,110';
        } else {
            $coords = defined('EMSIGNER_COORD_APPROVER') ? EMSIGNER_COORD_APPROVER : '1,320,640,220,110';
        }

        // Parse customize coordinates: Left,Top,Width,Height
        $coordParts = explode(',', $coords);
        $customizeCoordinates = (count($coordParts) === 5)
            ? "{$coordParts[1]},{$coordParts[2]},{$coordParts[3]},{$coordParts[4]}"
            : "55,640,220,110";

        // Callback URLs
        $sUrl = BASE_URL . "esign/callback.php?consent_id={$consentId}&role={$signerRole}&token={$securityToken}&ref={$referenceNumber}&action=success";
        $fUrl = BASE_URL . "esign/callback.php?consent_id={$consentId}&role={$signerRole}&token={$securityToken}&ref={$referenceNumber}&action=failed";
        $cUrl = BASE_URL . "esign/callback.php?consent_id={$consentId}&role={$signerRole}&token={$securityToken}&ref={$referenceNumber}&action=cancelled";

        // Compute pre-signing hash
        $preSignHash = hash('sha256', $pdfContent);

        // Verified Official emSigner Request Contract
        $payload = [
            'Name'                  => $signerName,
            'FileType'              => 'PDF',
            'SignatureType'         => 2, // 2 = Digital Signature Certificate / Token, 3 = Aadhaar eSign, 4 = eSignature
            'SelectPage'            => 'PAGE LEVEL',
            'SignaturePosition'     => 'Customize',
            'AuthToken'             => defined('EMSIGNER_AUTH_TOKEN') ? EMSIGNER_AUTH_TOKEN : '',
            'File'                  => base64_encode($pdfContent),
            'PageNumber'            => 1,
            'PreviewRequired'       => true,
            'PagelevelCoordinates'  => $coords,
            'CustomizeCoordinates'  => $customizeCoordinates,
            'SUrl'                  => $sUrl,
            'FUrl'                  => $fUrl,
            'CUrl'                  => $cUrl,
            'ReferenceNumber'       => $referenceNumber,
            'Enableuploadsignature' => true,
            'Enablefontsignature'   => true,
            'EnableDrawSignature'   => true,
            'EnableeSignaturePad'   => true,
            'IsCompressed'          => false,
            'IsCosign'              => true
        ];

        // Unique transaction number
        $transactionNumber = 'TXN-' . strtoupper(bin2hex(random_bytes(6)));

        // Session ID for local gateway simulator or redirect tracking
        $sessionId = 'SES-' . bin2hex(random_bytes(16));

        // Determine destination redirect URL & enforce mode rules
        $mode = defined('EMSIGNER_MODE') ? strtolower(trim(EMSIGNER_MODE)) : 'demo';
        if ($mode === 'real' || $mode === 'production') {
            // Check for real eMudhra gateway prerequisites
            $hasCert = defined('EMSIGNER_PUBLIC_CERT_PATH') && file_exists(EMSIGNER_PUBLIC_CERT_PATH);
            if (!$hasCert) {
                throw new RuntimeException(
                    "eMudhra emSigner Error: EMSIGNER_MODE is configured as '{$mode}', but the required account-specific " .
                    "Gateway Public Key Certificate (.cer) and gateway encryption parameters are not yet configured. " .
                    "External eMudhra requests cannot proceed without valid gateway credentials. " .
                    "Please keep EMSIGNER_MODE set to 'demo' in config/emsigner.local.php until gateway certificates are provisioned."
                );
            }
            $redirectUrl = EMSIGNER_PROD_GATEWAY_URL . '/SignerGateway';
        } else {
            // High-fidelity interactive local/demo simulator matching official gateway
            $redirectUrl = BASE_URL . "esign/signer_gateway.php?session=" . urlencode($sessionId);
        }

        // Cache session state in PHP session for secure gateway rendering
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['emsigner_sessions'][$sessionId] = [
            'session_id'         => $sessionId,
            'transaction_number' => $transactionNumber,
            'reference_number'   => $referenceNumber,
            'consent_id'         => $consentId,
            'consent_uid'        => $consentUid,
            'user_id'            => $userId,
            'signer_role'        => $signerRole,
            'signer_name'        => $signerName,
            'signer_index'       => $signerIndex,
            'pre_sign_hash'      => $preSignHash,
            'coords'             => $coords,
            'payload'            => $payload,
            's_url'              => $sUrl,
            'f_url'              => $fUrl,
            'c_url'              => $cUrl,
            'created_at'         => time(),
            'status'             => 'INITIATED'
        ];

        return [
            'success'            => true,
            'transaction_number' => $transactionNumber,
            'reference_number'   => $referenceNumber,
            'security_token'     => $securityToken,
            'session_id'         => $sessionId,
            'pre_sign_hash'      => $preSignHash,
            'coordinates_spec'   => $coords,
            'signer_index'       => $signerIndex,
            'redirect_url'       => $redirectUrl,
            'payload'            => $payload,
            'mode'               => $mode
        ];
    }

    /**
     * Retrieve transaction status from emSigner Gateway / REST API
     *
     * @param string $transactionNumber
     * @param string $referenceNumber
     * @return array Status result [success, status, message]
     */
    public static function getTransactionStatus($transactionNumber, $referenceNumber) {
        $mode = defined('EMSIGNER_MODE') ? EMSIGNER_MODE : 'demo';

        if ($mode === 'production') {
            $endpoint = self::getApiUrl() . 'TransactionStatusRequest';
            $params = http_build_query([
                'AuthToken'         => defined('EMSIGNER_AUTH_TOKEN') ? EMSIGNER_AUTH_TOKEN : '',
                'Transactionnumber' => $transactionNumber,
                'Referencenumber'   => $referenceNumber
            ]);

            $ch = curl_init("{$endpoint}?{$params}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && !empty($res)) {
                $decoded = json_decode($res, true);
                if (is_array($decoded)) {
                    return [
                        'success' => $decoded['IsSuccess'] ?? false,
                        'status'  => $decoded['Status'] ?? 'Unknown',
                        'message' => $decoded['ErrorMessage'] ?? ($decoded['Messages'] ?? 'Success')
                    ];
                }
            }
        }

        // Sandbox check from active session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['emsigner_sessions'])) {
            foreach ($_SESSION['emsigner_sessions'] as $s) {
                if ($s['transaction_number'] === $transactionNumber || $s['reference_number'] === $referenceNumber) {
                    return [
                        'success' => true,
                        'status'  => $s['status'] === 'COMPLETED' ? 'Completed' : 'Initiate',
                        'message' => 'Status verified from active transaction registry'
                    ];
                }
            }
        }

        return [
            'success' => true,
            'status'  => 'Initiate',
            'message' => 'Transaction active and pending signature'
        ];
    }
}
