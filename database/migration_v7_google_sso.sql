-- ============================================================================
-- Migration V7: Google SSO / Google Sign-In Integration
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- Target Table: users
-- ============================================================================

USE `digital_health_db`;

-- Add Google SSO identity fields to users table
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `google_sub` VARCHAR(191) NULL DEFAULT NULL AFTER `ekyc_status`,
    ADD COLUMN IF NOT EXISTS `google_email` VARCHAR(191) NULL DEFAULT NULL AFTER `google_sub`,
    ADD COLUMN IF NOT EXISTS `google_picture` TEXT NULL DEFAULT NULL AFTER `google_email`,
    ADD COLUMN IF NOT EXISTS `google_linked_at` DATETIME NULL DEFAULT NULL AFTER `google_picture`;

-- Create indexes for performance and enforce unique stable Google sub identifier
ALTER TABLE `users`
    ADD UNIQUE KEY IF NOT EXISTS `uq_users_google_sub` (`google_sub`),
    ADD INDEX IF NOT EXISTS `idx_users_google_email` (`google_email`);
