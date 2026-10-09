-- ============================================================================
-- Database Migration V10: Sequential Consent Multi-Party Approval Workflow
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- ============================================================================

USE `digital_health_db`;

-- 1. Modify consent_requests.status to VARCHAR(50) to support approver_pending, doctor_signed, active, etc.
ALTER TABLE `consent_requests`
MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Pending';

-- 2. Add intermediate document hash and reference columns to consent_requests if missing
ALTER TABLE `consent_requests`
ADD COLUMN IF NOT EXISTS `intermediate_document_hash` VARCHAR(64) NULL AFTER `pre_sign_document_hash`,
ADD COLUMN IF NOT EXISTS `intermediate_document_reference` VARCHAR(255) NULL AFTER `intermediate_document_hash`,
ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL AFTER `patient_notes`;

-- 3. Ensure esign_records has appropriate indexes for performance and multi-party tracking
ALTER TABLE `esign_records`
ADD INDEX IF NOT EXISTS `idx_consent_role` (`consent_id`, `signer_role`);
