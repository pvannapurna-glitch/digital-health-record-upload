-- Database Migration V8: Local Patient Signature & In-PDF Consent Signing
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules

USE `digital_health_db`;

-- 1. Add signature columns to patients table
ALTER TABLE `patients`
ADD COLUMN IF NOT EXISTS `signature_image` VARCHAR(255) NULL AFTER `emergency_contact`,
ADD COLUMN IF NOT EXISTS `signature_updated_at` DATETIME NULL AFTER `signature_image`;

-- 2. Add signature method and image reference to esign_records
ALTER TABLE `esign_records`
ADD COLUMN IF NOT EXISTS `signature_method` VARCHAR(50) NOT NULL DEFAULT 'LOCAL_SIGNATURE_IMAGE' AFTER `doc_ref`,
ADD COLUMN IF NOT EXISTS `signature_image_reference` VARCHAR(255) NULL AFTER `signature_method`;

-- 3. Ensure consent_requests has default local provider support
ALTER TABLE `consent_requests`
MODIFY COLUMN `esign_provider` VARCHAR(50) NOT NULL DEFAULT 'local_signature';
