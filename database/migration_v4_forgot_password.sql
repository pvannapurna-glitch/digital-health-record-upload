-- ============================================================================
-- Migration V4: Email-Only OTP Password Reset Table
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- ============================================================================

USE `digital_health_db`;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `otp_hash` VARCHAR(255) NOT NULL COMMENT 'SHA-256 cryptographic hash of 6-digit OTP',
    `attempts` INT NOT NULL DEFAULT 0 COMMENT 'Count of invalid verification attempts',
    `max_attempts` INT NOT NULL DEFAULT 5 COMMENT 'Max allowed attempts before locking OTP',
    `expires_at` DATETIME NOT NULL COMMENT '5 minutes from generation timestamp',
    `resend_available_at` DATETIME NOT NULL COMMENT 'Cooldown timestamp before resend is allowed',
    `status` ENUM('Pending', 'Verified', 'Used', 'Expired', 'MaxAttemptsExceeded') NOT NULL DEFAULT 'Pending',
    `reset_token` VARCHAR(64) NULL UNIQUE COMMENT 'Single-use token issued upon successful OTP verification',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_reset_email` (`email`),
    INDEX `idx_reset_status` (`status`),
    INDEX `idx_reset_token` (`reset_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
