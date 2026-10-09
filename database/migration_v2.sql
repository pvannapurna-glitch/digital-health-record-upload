-- Database Migration: Adding eKYC, SSO, and eSign Modules
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules

USE `digital_health_db`;

-- 1. Add ekyc_status to users table if not exists
ALTER TABLE `users` 
ADD COLUMN IF NOT EXISTS `ekyc_status` ENUM('Pending', 'Verified', 'Failed') NOT NULL DEFAULT 'Pending' AFTER `status`;

-- 2. Create ekyc_verifications table
CREATE TABLE IF NOT EXISTS `ekyc_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ekyc_uid` VARCHAR(50) NOT NULL UNIQUE,
    `doc_type` ENUM('Aadhaar Sandbox Demo', 'PAN Card Demo', 'Passport Demo', 'Voter ID Demo', 'Medical License Verification') NOT NULL,
    `doc_number_masked` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `dob` DATE NOT NULL,
    `gender` VARCHAR(20) NOT NULL,
    `otp_verified` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('Pending', 'Verified', 'Failed') NOT NULL DEFAULT 'Pending',
    `verification_notes` TEXT NULL,
    `verified_by_user_id` INT NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ekyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Create sso_sessions table
CREATE TABLE IF NOT EXISTS `sso_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_token` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `user_role` ENUM('admin', 'doctor', 'hospital', 'patient') NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT `fk_sso_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Create esign_records table
CREATE TABLE IF NOT EXISTS `esign_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `esign_uid` VARCHAR(50) NOT NULL UNIQUE,
    `consent_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `signer_role` ENUM('patient', 'doctor', 'hospital') NOT NULL,
    `signer_name` VARCHAR(100) NOT NULL,
    `doc_ref` VARCHAR(100) NOT NULL,
    `signature_type` ENUM('OTP Verified eSign', 'Biometric / Cryptographic Token (Demo)', 'Digital Signature Certificate') NOT NULL DEFAULT 'OTP Verified eSign',
    `signature_hash` VARCHAR(64) NOT NULL,
    `status` ENUM('Signed', 'Pending', 'Failed') NOT NULL DEFAULT 'Signed',
    `signed_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_esign_consent` FOREIGN KEY (`consent_id`) REFERENCES `consent_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_esign_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Add esign fields to consent_requests if not exists
ALTER TABLE `consent_requests`
ADD COLUMN IF NOT EXISTS `is_esigned` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`,
ADD COLUMN IF NOT EXISTS `esign_id` INT NULL AFTER `is_esigned`;

-- 6. Seed initial eKYC verification records for demo users
UPDATE `users` SET `ekyc_status` = 'Verified' WHERE `username` IN ('admin', 'dr.sharma', 'dr.anita', 'apollo_hospital', 'rahul_sharma');

INSERT IGNORE INTO `ekyc_verifications` (`user_id`, `ekyc_uid`, `doc_type`, `doc_number_masked`, `full_name`, `dob`, `gender`, `otp_verified`, `status`, `verified_at`) VALUES
(1, 'KYC-SANDBOX-1001', 'PAN Card Demo', 'XXXX-XXXX-9001', 'System Administrator', '1985-01-01', 'Other', 1, 'Verified', NOW()),
(2, 'KYC-SANDBOX-2001', 'Medical License Verification', 'XXXX-MCI-2001', 'Dr. Rajesh Sharma', '1980-05-12', 'Male', 1, 'Verified', NOW()),
(3, 'KYC-SANDBOX-2002', 'Medical License Verification', 'XXXX-MCI-2002', 'Dr. Anita Desai', '1984-09-23', 'Female', 1, 'Verified', NOW()),
(4, 'KYC-SANDBOX-3001', 'PAN Card Demo', 'XXXX-NABH-3001', 'Apollo City Hospital', '1995-11-15', 'Other', 1, 'Verified', NOW()),
(5, 'KYC-SANDBOX-5001', 'Aadhaar Sandbox Demo', 'XXXX-XXXX-1001', 'Rahul Sharma', '1992-06-15', 'Male', 1, 'Verified', NOW());

-- Seed a signed eSign record for active demo consent 1 (Dr Sharma & Rahul)
INSERT IGNORE INTO `esign_records` (`esign_uid`, `consent_id`, `user_id`, `signer_role`, `signer_name`, `doc_ref`, `signature_type`, `signature_hash`, `status`, `signed_at`) VALUES
('ESIGN-DEMO-9001', 1, 5, 'patient', 'Rahul Sharma', 'Consent CNST-9001 (REC-UID-5001)', 'OTP Verified eSign', SHA2('CNST-9001-ABHA-PAT-1001-Cardiac-2026', 256), 'Signed', NOW());

UPDATE `consent_requests` SET `is_esigned` = 1, `esign_id` = 1 WHERE `id` = 1;
