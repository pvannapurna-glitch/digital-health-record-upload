<?php
/**
 * SMTP Mailer & OTP Email Dispatcher
 * Powered by PHPMailer
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class SmtpMailer {
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;
    private $fromEmail;
    private $fromName;
    private $timeout;
    private $lastError = '';

    public function __construct() {
        $this->host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
        $this->port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
        $this->encryption = defined('SMTP_ENCRYPTION') ? strtolower(SMTP_ENCRYPTION) : 'tls';
        $this->username   = defined('SMTP_USER') ? SMTP_USER : '';
        $this->password   = defined('SMTP_PASS') ? SMTP_PASS : '';
        $this->fromEmail  = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'noreply@example.com';
        $this->fromName   = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'DEPA Health Record Exchange';
        $this->timeout    = defined('SMTP_TIMEOUT') ? (int)SMTP_TIMEOUT : 15;
    }

    public function getLastError() {
        return $this->lastError;
    }

    /**
     * Send email via PHPMailer SMTP
     */
    public function send($toEmail, $subject, $htmlBody, $toName = '', $plainTextBody = '') {
        if (empty($this->username) || empty($this->password)) {
            $this->lastError = 'SMTP credentials are not configured.';
            return [
                'success' => false,
                'mode'    => 'error',
                'error'   => 'SMTP credentials are not configured.'
            ];
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->username;
            $mail->Password   = $this->password;

            if ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($this->encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->Port       = $this->port;
            $mail->Timeout    = $this->timeout;
            $mail->CharSet    = 'UTF-8';

            // Sender & Recipient
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($toEmail, $toName ?: '');

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            if (!empty($plainTextBody)) {
                $mail->AltBody = $plainTextBody;
            } else {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
            }

            $mail->send();

            return [
                'success' => true,
                'mode'    => 'smtp',
                'message' => "OTP email dispatched successfully via SMTP."
            ];
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            return [
                'success' => false,
                'mode'    => 'smtp_error',
                'error'   => 'Failed to deliver email: ' . $e->getMessage()
            ];
        }
    }
}

/**
 * Generate responsive HTML email template for 6-Digit Password Reset OTP
 */
function build_otp_email_template($recipientName, $otpCode, $expiryMinutes = 10) {
    $appName = defined('APP_NAME') ? APP_NAME : 'Digital Health Record Exchange';
    $tagline = defined('APP_TAGLINE') ? APP_TAGLINE : 'DEPA Consent-Driven Health Data Network';
    $year = date('Y');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DEPA HealthExchange - Password Reset OTP</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333333; }
        .email-container { max-width: 580px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
        .email-header { background: linear-gradient(135deg, #0d6efd 0%, #0b5ed7 100%); padding: 30px 25px; text-align: center; color: #ffffff; }
        .email-header h1 { margin: 0 0 6px 0; font-size: 22px; font-weight: 700; }
        .email-header p { margin: 0; font-size: 13px; opacity: 0.9; }
        .email-body { padding: 35px 30px; line-height: 1.6; }
        .greeting { font-size: 16px; font-weight: 600; margin-bottom: 12px; color: #1e293b; }
        .otp-box { background: #f0f7ff; border: 2px dashed #0d6efd; border-radius: 10px; padding: 25px; text-align: center; margin: 25px 0; }
        .otp-badge { font-size: 38px; font-weight: 800; letter-spacing: 8px; color: #0d6efd; font-family: 'Courier New', Courier, monospace; margin: 10px 0; }
        .otp-expiry { font-size: 13px; color: #dc3545; font-weight: 600; margin-top: 8px; }
        .security-notice { background: #fff8e6; border-left: 4px solid #ffc107; padding: 12px 15px; border-radius: 4px; font-size: 13px; color: #856404; margin-top: 20px; }
        .email-footer { background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <h1>DEPA HealthExchange</h1>
            <p>{$tagline}</p>
        </div>
        <div class="email-body">
            <div class="greeting">Hello {$recipientName},</div>
            <p>We received a password reset request for your account in <strong>DEPA HealthExchange</strong>.</p>
            <p>Please enter the following 6-digit One-Time Password (OTP) to verify your request and set a new password:</p>
            
            <div class="otp-box">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 600;">Your Verification OTP</div>
                <div class="otp-badge">{$otpCode}</div>
                <div class="otp-expiry">&#9200; Valid for {$expiryMinutes} minutes only (Maximum 5 attempts)</div>
            </div>

            <div class="security-notice">
                <strong>Security Alert:</strong> Do not share this OTP with anyone. Our team will never ask for your OTP. If you did not request a password reset, you can safely ignore this email; your account remains secure.
            </div>
        </div>
        <div class="email-footer">
            &copy; {$year} DEPA HealthExchange &bull; Consent & Security Architecture<br>
            This is an automated transmission. Please do not reply to this email.
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Helper function to dispatch password reset OTP email
 */
function send_otp_email($toEmail, $recipientName, $otpCode, $expiryMinutes = 10) {
    $mailer = new SmtpMailer();
    $subject = "DEPA HealthExchange - Password Reset OTP";
    $htmlBody = build_otp_email_template($recipientName, $otpCode, $expiryMinutes);
    $plainText = "DEPA HealthExchange - Password Reset OTP\n\n"
               . "Hello {$recipientName},\n\n"
               . "We received a password reset request for your account in DEPA HealthExchange.\n"
               . "Your 6-digit verification OTP is: {$otpCode}\n\n"
               . "This OTP is valid for {$expiryMinutes} minutes only (Maximum 5 attempts).\n\n"
               . "Security Alert: Do not share this OTP with anyone. If you did not request this, you can safely ignore this email; your account remains secure.\n";
    return $mailer->send($toEmail, $subject, $htmlBody, $recipientName, $plainText);
}
