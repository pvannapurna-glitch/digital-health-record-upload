-- ============================================================================
-- Database Migration V9: Doctor eKYC & emSigner Multi-Party Signing Workflow
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- ============================================================================

USE `digital_health_db`;

-- 1. Add Doctor KYC & Signature fields to doctors table
ALTER TABLE `doctors`
ADD COLUMN IF NOT EXISTS `kyc_status` ENUM('NOT_STARTED', 'PENDING', 'VERIFIED', 'REJECTED', 'EXPIRED') NOT NULL DEFAULT 'NOT_STARTED' AFTER `experience_years`,
ADD COLUMN IF NOT EXISTS `kyc_reference` VARCHAR(100) NULL AFTER `kyc_status`,
ADD COLUMN IF NOT EXISTS `kyc_verified_at` DATETIME NULL AFTER `kyc_reference`,
ADD COLUMN IF NOT EXISTS `signature_image` VARCHAR(255) NULL AFTER `kyc_verified_at`,
ADD COLUMN IF NOT EXISTS `signature_updated_at` DATETIME NULL AFTER `signature_image`;

-- 2. Add Multi-party Signing fields to consent_requests table
ALTER TABLE `consent_requests`
ADD COLUMN IF NOT EXISTS `pre_sign_document_hash` VARCHAR(64) NULL AFTER `signed_document_hash`,
ADD COLUMN IF NOT EXISTS `doctor_signed_at` DATETIME NULL AFTER `pre_sign_document_hash`,
ADD COLUMN IF NOT EXISTS `doctor_esign_id` INT NULL AFTER `doctor_signed_at`,
ADD COLUMN IF NOT EXISTS `approver_user_id` INT NULL AFTER `doctor_esign_id`,
ADD COLUMN IF NOT EXISTS `approver_name` VARCHAR(150) NULL AFTER `approver_user_id`,
ADD COLUMN IF NOT EXISTS `approver_email` VARCHAR(150) NULL AFTER `approver_name`,
ADD COLUMN IF NOT EXISTS `approver_token` VARCHAR(64) NULL AFTER `approver_email`,
ADD COLUMN IF NOT EXISTS `approver_token_expiry` DATETIME NULL AFTER `approver_token`,
ADD COLUMN IF NOT EXISTS `approver_signed_at` DATETIME NULL AFTER `approver_token_expiry`,
ADD COLUMN IF NOT EXISTS `approver_esign_id` INT NULL AFTER `approver_signed_at`,
ADD COLUMN IF NOT EXISTS `workflow_step` VARCHAR(50) NOT NULL DEFAULT 'PATIENT_APPROVAL' AFTER `approver_esign_id`;

-- 3. Add signer order and coordinates spec to esign_records, expand signer_role
ALTER TABLE `esign_records`
MODIFY COLUMN `signer_role` ENUM('patient', 'doctor', 'hospital', 'approver', 'admin') NOT NULL DEFAULT 'doctor',
ADD COLUMN IF NOT EXISTS `signer_index` TINYINT NOT NULL DEFAULT 1 AFTER `signer_role`,
ADD COLUMN IF NOT EXISTS `coordinates_spec` VARCHAR(100) NULL AFTER `signature_image_reference`;

-- 4. Sync existing verified doctors based on users.ekyc_status
UPDATE `doctors` d
JOIN `users` u ON d.user_id = u.id
SET d.kyc_status = 'VERIFIED', d.kyc_verified_at = NOW(), d.kyc_reference = CONCAT('DOC-KYC-', d.id)
WHERE u.ekyc_status = 'Verified' AND d.kyc_status = 'NOT_STARTED';
