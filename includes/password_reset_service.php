<?php
/**
 * Password Reset Service Layer (Email OTP only)
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

/**
 * Request a 6-digit OTP for password reset
 */
function request_password_reset_otp($pdo, $identifier) {
    $cleanInput = trim((string)$identifier);

    if (empty($cleanInput)) {
        return [
            'success' => false,
            'error'   => 'Please enter your registered email address or username.'
        ];
    }

    // Lookup active user by email OR username (case-insensitive)
    $stmt = $pdo->prepare("SELECT * FROM `users` WHERE (LOWER(`email`) = LOWER(?) OR LOWER(`username`) = LOWER(?)) AND `status` = 'active' LIMIT 1");
    $stmt->execute([$cleanInput, $cleanInput]);
    $user = $stmt->fetch();

    if (!$user) {
        // Anti-enumeration protection: Return generic success without revealing non-existence
        return [
            'success'            => true,
            'email'              => filter_var($cleanInput, FILTER_VALIDATE_EMAIL) ? strtolower($cleanInput) : $cleanInput,
            'display_name'       => 'User',
            'expires_at'         => date('Y-m-d H:i:s', time() + 600),
            'expiry_seconds'     => 600,
            'cooldown_seconds'   => 60,
            'nonexistent'        => true,
            'message'            => 'If an active account is registered with that email or username, a 6-digit OTP has been dispatched.'
        ];
    }

    $cleanEmail = strtolower(trim((string)$user['email']));

    // Determine recipient display name
    $displayName = $user['username'];
    if ($user['role'] === 'patient') {
        $stmtP = $pdo->prepare("SELECT full_name FROM `patients` WHERE `user_id` = ?");
        $stmtP->execute([$user['id']]);
        $displayName = $stmtP->fetchColumn() ?: $user['username'];
    } elseif ($user['role'] === 'doctor') {
        $stmtD = $pdo->prepare("SELECT full_name FROM `doctors` WHERE `user_id` = ?");
        $stmtD->execute([$user['id']]);
        $displayName = $stmtD->fetchColumn() ?: $user['username'];
    } elseif ($user['role'] === 'hospital') {
        $stmtH = $pdo->prepare("SELECT hospital_name FROM `hospitals` WHERE `user_id` = ?");
        $stmtH->execute([$user['id']]);
        $displayName = $stmtH->fetchColumn() ?: $user['username'];
    } elseif ($user['role'] === 'admin') {
        $displayName = "System Administrator";
    }

    // Check for existing active pending request and cooldown
    $stmtCheck = $pdo->prepare("SELECT * FROM `password_resets` 
                                WHERE `user_id` = ? AND `status` = 'Pending' 
                                ORDER BY `id` DESC LIMIT 1");
    $stmtCheck->execute([$user['id']]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $cooldownRemaining = strtotime($existing['resend_available_at']) - time();
        if ($cooldownRemaining > 0) {
            return [
                'success'            => false,
                'error'              => "Please wait {$cooldownRemaining} seconds before requesting a new OTP.",
                'cooldown_remaining' => $cooldownRemaining
            ];
        }

        // Expire previous pending OTPs for this user
        $pdo->prepare("UPDATE `password_resets` SET `status` = 'Expired' WHERE `user_id` = ? AND `status` = 'Pending'")
            ->execute([$user['id']]);
    }

    // Generate cryptographically secure 6-digit numerical OTP
    $otpCode = sprintf('%06d', random_int(100000, 999999));
    $otpHash = hash('sha256', $otpCode);

    $expirySeconds = defined('OTP_EXPIRY_SECONDS') ? (int)OTP_EXPIRY_SECONDS : 600;
    $cooldownSeconds = defined('OTP_RESEND_COOLDOWN_SECONDS') ? (int)OTP_RESEND_COOLDOWN_SECONDS : 60;
    $maxAttempts = defined('OTP_MAX_ATTEMPTS') ? (int)OTP_MAX_ATTEMPTS : 5;

    $expiresAt = date('Y-m-d H:i:s', time() + $expirySeconds);
    $resendAvailableAt = date('Y-m-d H:i:s', time() + $cooldownSeconds);

    // Insert reset record
    $stmtInsert = $pdo->prepare("INSERT INTO `password_resets` (
        `user_id`, `email`, `otp_hash`, `attempts`, `max_attempts`, `expires_at`, `resend_available_at`, `status`
    ) VALUES (?, ?, ?, 0, ?, ?, ?, 'Pending')");
    $stmtInsert->execute([$user['id'], $cleanEmail, $otpHash, $maxAttempts, $expiresAt, $resendAvailableAt]);
    $resetId = (int)$pdo->lastInsertId();

    // Dispatch real email via Gmail SMTP
    $emailResult = send_otp_email($cleanEmail, $displayName, $otpCode, round($expirySeconds / 60));

    if (!$emailResult['success']) {
        // Rollback / Mark reset as Expired if email fails
        $pdo->prepare("UPDATE `password_resets` SET `status` = 'Expired' WHERE `id` = ?")->execute([$resetId]);
        log_audit(
            $pdo,
            'PASSWORD_RESET_FAILED',
            "Failed to dispatch OTP email to {$cleanEmail}: " . ($emailResult['error'] ?? 'SMTP Error'),
            $user['id'],
            $user['role'],
            $displayName
        );
        return [
            'success' => false,
            'error'   => 'Unable to send OTP email: ' . htmlspecialchars($emailResult['error'] ?? 'SMTP dispatch failed.')
        ];
    }

    // Audit log entry (NEVER log the OTP code!)
    log_audit(
        $pdo, 
        'PASSWORD_RESET_REQUESTED', 
        "Password reset OTP generated and dispatched via SMTP to {$cleanEmail} (Reset ID #{$resetId}).", 
        $user['id'], 
        $user['role'], 
        $displayName
    );

    return [
        'success'            => true,
        'reset_id'           => $resetId,
        'email'              => $cleanEmail,
        'display_name'       => $displayName,
        'expires_at'         => $expiresAt,
        'expiry_seconds'     => $expirySeconds,
        'cooldown_seconds'   => $cooldownSeconds
    ];
}

/**
 * Verify entered 6-digit OTP
 */
function verify_password_reset_otp($pdo, $email, $enteredOtp) {
    $cleanEmail = trim(strtolower((string)$email));
    $cleanOtp   = preg_replace('/\D/', '', (string)$enteredOtp);

    if (strlen($cleanOtp) !== 6) {
        return [
            'success' => false,
            'error'   => 'Please enter a valid 6-digit numerical OTP code.'
        ];
    }

    // Lookup latest Pending reset record
    $stmt = $pdo->prepare("SELECT * FROM `password_resets` 
                           WHERE `email` = ? AND `status` = 'Pending' 
                           ORDER BY `id` DESC LIMIT 1");
    $stmt->execute([$cleanEmail]);
    $reset = $stmt->fetch();

    if (!$reset) {
        return [
            'success' => false,
            'error'   => 'No active password reset request found for this email. Please request a new OTP.'
        ];
    }

    // Check expiry
    if (strtotime($reset['expires_at']) < time()) {
        $pdo->prepare("UPDATE `password_resets` SET `status` = 'Expired' WHERE `id` = ?")
            ->execute([$reset['id']]);

        log_audit(
            $pdo, 
            'PASSWORD_RESET_EXPIRED', 
            "OTP verification failed for {$cleanEmail}: OTP expired (Reset ID #{$reset['id']}).", 
            $reset['user_id']
        );

        return [
            'success' => false,
            'error'   => 'This OTP has expired (10-minute limit exceeded). Please request a fresh OTP.'
        ];
    }

    // Check maximum attempts
    if ($reset['attempts'] >= $reset['max_attempts']) {
        $pdo->prepare("UPDATE `password_resets` SET `status` = 'MaxAttemptsExceeded' WHERE `id` = ?")
            ->execute([$reset['id']]);

        log_audit(
            $pdo, 
            'PASSWORD_RESET_LOCKED', 
            "OTP locked for {$cleanEmail}: Maximum incorrect attempts ({$reset['max_attempts']}) exceeded (Reset ID #{$reset['id']}).", 
            $reset['user_id']
        );

        return [
            'success' => false,
            'error'   => "Maximum incorrect attempts ({$reset['max_attempts']}/{$reset['max_attempts']}) exceeded. This OTP is now locked. Please request a fresh OTP."
        ];
    }

    // Check OTP hash match
    $enteredHash = hash('sha256', $cleanOtp);
    if ($enteredHash !== $reset['otp_hash']) {
        $newAttempts = (int)$reset['attempts'] + 1;
        $remainingAttempts = max(0, (int)$reset['max_attempts'] - $newAttempts);

        if ($newAttempts >= $reset['max_attempts']) {
            $pdo->prepare("UPDATE `password_resets` SET `attempts` = ?, `status` = 'MaxAttemptsExceeded' WHERE `id` = ?")
                ->execute([$newAttempts, $reset['id']]);

            log_audit(
                $pdo, 
                'PASSWORD_RESET_LOCKED', 
                "OTP locked for {$cleanEmail} on attempt {$newAttempts}/{$reset['max_attempts']}.", 
                $reset['user_id']
            );

            return [
                'success' => false,
                'error'   => "Incorrect OTP entered. Maximum attempts ({$reset['max_attempts']}/{$reset['max_attempts']}) exceeded. This OTP is now locked. Please request a fresh OTP."
            ];
        } else {
            $pdo->prepare("UPDATE `password_resets` SET `attempts` = ? WHERE `id` = ?")
                ->execute([$newAttempts, $reset['id']]);

            log_audit(
                $pdo, 
                'PASSWORD_RESET_OTP_FAILED', 
                "Incorrect OTP entered for {$cleanEmail} (Attempt {$newAttempts}/{$reset['max_attempts']}).", 
                $reset['user_id']
            );

            return [
                'success'            => false,
                'error'              => "Invalid OTP code. {$remainingAttempts} attempt(s) remaining before this OTP is locked.",
                'attempts_used'      => $newAttempts,
                'remaining_attempts' => $remainingAttempts
            ];
        }
    }

    // OTP Verification Successful -> Issue single-use reset token
    $resetToken = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE `password_resets` SET `status` = 'Verified', `reset_token` = ? WHERE `id` = ?")
        ->execute([$resetToken, $reset['id']]);

    log_audit(
        $pdo, 
        'PASSWORD_RESET_OTP_VERIFIED', 
        "OTP verified successfully for {$cleanEmail} (Reset ID #{$reset['id']}). One-time token issued.", 
        $reset['user_id']
    );

    return [
        'success'     => true,
        'reset_token' => $resetToken,
        'email'       => $cleanEmail,
        'message'     => 'OTP verified successfully. You may now create your new password.'
    ];
}

/**
 * Complete password reset with new password
 */
function complete_password_reset($pdo, $resetToken, $newPassword, $confirmPassword) {
    $cleanToken = trim((string)$resetToken);

    if (empty($cleanToken)) {
        return [
            'success' => false,
            'error'   => 'Missing or invalid password reset authorization token.'
        ];
    }

    if (empty($newPassword) || empty($confirmPassword)) {
        return [
            'success' => false,
            'error'   => 'Please provide both new password and password confirmation.'
        ];
    }

    if ($newPassword !== $confirmPassword) {
        return [
            'success' => false,
            'error'   => 'Passwords do not match. Please re-enter your new password.'
        ];
    }

    if (strlen($newPassword) < 8) {
        return [
            'success' => false,
            'error'   => 'Password must be at least 8 characters long.'
        ];
    }

    // Lookup active Verified reset record
    $stmt = $pdo->prepare("SELECT * FROM `password_resets` 
                           WHERE `reset_token` = ? AND `status` = 'Verified' 
                           ORDER BY `id` DESC LIMIT 1");
    $stmt->execute([$cleanToken]);
    $reset = $stmt->fetch();

    if (!$reset) {
        return [
            'success' => false,
            'error'   => 'Invalid or expired password reset session. Please request a new OTP.'
        ];
    }

    // Check expiry
    if (strtotime($reset['expires_at']) < time()) {
        $pdo->prepare("UPDATE `password_resets` SET `status` = 'Expired', `reset_token` = NULL WHERE `id` = ?")
            ->execute([$reset['id']]);

        return [
            'success' => false,
            'error'   => 'Password reset session has expired (5-minute window). Please request a new OTP.'
        ];
    }

    // Hash new password using standard bcrypt password_hash()
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    // Update user password in users table
    $stmtUpdate = $pdo->prepare("UPDATE `users` SET `password_hash` = ?, `updated_at` = NOW() WHERE `id` = ?");
    $stmtUpdate->execute([$passwordHash, $reset['user_id']]);

    // Mark current reset record as Used
    $pdo->prepare("UPDATE `password_resets` SET `status` = 'Used', `reset_token` = NULL WHERE `id` = ?")
        ->execute([$reset['id']]);

    // Expire any other lingering reset tokens for this user
    $pdo->prepare("UPDATE `password_resets` SET `status` = 'Expired', `reset_token` = NULL WHERE `user_id` = ? AND `status` IN ('Pending', 'Verified')")
        ->execute([$reset['user_id']]);

    // Invalidate existing active SSO sessions for security
    $pdo->prepare("UPDATE `sso_sessions` SET `is_active` = 0 WHERE `user_id` = ?")
        ->execute([$reset['user_id']]);

    // Audit log entry
    log_audit(
        $pdo, 
        'PASSWORD_RESET_SUCCESS', 
        "Password successfully updated via Email OTP for account {$reset['email']} (Reset ID #{$reset['id']}).", 
        $reset['user_id']
    );

    return [
        'success' => true,
        'message' => 'Your password has been successfully reset! You can now sign in with your new credentials.'
    ];
}

/**
 * Get active pending OTP state for a given email (to resume step 2 if needed)
 */
function get_active_otp_state($pdo, $email) {
    $cleanEmail = trim(strtolower((string)$email));
    $stmt = $pdo->prepare("SELECT * FROM `password_resets` 
                           WHERE `email` = ? AND `status` = 'Pending' 
                           ORDER BY `id` DESC LIMIT 1");
    $stmt->execute([$cleanEmail]);
    $reset = $stmt->fetch();

    if (!$reset) return null;

    $expiresIn = strtotime($reset['expires_at']) - time();
    $cooldownIn = strtotime($reset['resend_available_at']) - time();

    if ($expiresIn <= 0) {
        $pdo->prepare("UPDATE `password_resets` SET `status` = 'Expired' WHERE `id` = ?")
            ->execute([$reset['id']]);
        return null;
    }

    return [
        'id'                 => (int)$reset['id'],
        'email'              => $reset['email'],
        'attempts'           => (int)$reset['attempts'],
        'max_attempts'       => (int)$reset['max_attempts'],
        'remaining_attempts' => max(0, (int)$reset['max_attempts'] - (int)$reset['attempts']),
        'expires_in'         => max(0, $expiresIn),
        'cooldown_in'        => max(0, $cooldownIn)
    ];
}
