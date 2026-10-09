-- ============================================================================
-- Migration V5: Aadhaar Card Document Upload based eKYC Table
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- ============================================================================

USE `digital_health_db`;

CREATE TABLE IF NOT EXISTS `aadhaar_ekyc` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `verification_type` VARCHAR(100) NOT NULL DEFAULT 'Aadhaar Document OCR',
    `verification_status` ENUM('NOT_VERIFIED', 'PENDING', 'VERIFIED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    `verified_name` VARCHAR(150) NULL,
    `verified_dob` VARCHAR(50) NULL,
    `verified_gender` VARCHAR(20) NULL,
    `verified_address` TEXT NULL,
    `photo_data` MEDIUMTEXT NULL COMMENT 'Base64 encoded photograph or SVG avatar data',
    `photo_reference` VARCHAR(255) NULL COMMENT 'Secure photo file reference',
    `document_reference` VARCHAR(255) NULL COMMENT 'Secure internal storage reference filename',
    `document_type` VARCHAR(20) NULL COMMENT 'PDF, JPG, JPEG, PNG, XML',
    `ocr_confidence` DECIMAL(5,2) NULL DEFAULT 0.00,
    `fail_reason` TEXT NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_aadhaar_ekyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_aadhaar_user` (`user_id`),
    INDEX `idx_aadhaar_status` (`verification_status`),
    INDEX `idx_aadhaar_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

