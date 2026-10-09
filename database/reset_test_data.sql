-- ============================================================================
-- Script: reset_test_data.sql
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- Purpose: Safely reset database to clean TESTING state by removing all test
--          patients, doctors, hospitals, health records, consents, eKYC,
--          eSign, and audit entries while strictly PRESERVING Admin account(s).
--
-- Execution:
--   Can be executed via MySQL CLI, PowerShell, or phpMyAdmin SQL tab.
-- ============================================================================

USE `digital_health_db`;

-- Start an atomic transaction
START TRANSACTION;

-- ----------------------------------------------------------------------------
-- Step 1: Delete Dependent eSign Records
-- Child table: references consent_requests(id) and users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `esign_records` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 2: Delete Dependent Consent Requests
-- Child table: references health_records(id), patients(id), users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `consent_requests` 
WHERE `requester_user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 3: Delete Health Records
-- Child table: references patients(id) and users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `health_records` 
WHERE `created_by_user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 4: Delete Patient Profiles
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `patients` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 5: Delete Doctor Profiles
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `doctors` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 6: Delete Hospital Facility Profiles
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `hospitals` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 7: Delete Aadhaar Document eKYC Records
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `aadhaar_ekyc` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 8: Delete Sandbox eKYC Records (Preserving Admin verified identity)
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `ekyc_verifications` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 9: Delete Temporary Password Reset Tokens
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `password_resets`;

-- ----------------------------------------------------------------------------
-- Step 10: Delete Non-Admin Single Sign-On (SSO) Sessions
-- Child table: references users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `sso_sessions` 
WHERE `user_id` NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 11: Clear Central DEPA Registry of Test Artifacts
-- ----------------------------------------------------------------------------
DELETE FROM `registry`;

-- ----------------------------------------------------------------------------
-- Step 12: Delete Non-Admin Audit Logs (Preserving Admin System Trail)
-- ----------------------------------------------------------------------------
DELETE FROM `audit_logs` 
WHERE COALESCE(`user_role`, '') != 'admin' 
  AND COALESCE(`user_id`, 0) NOT IN (SELECT `id` FROM `users` WHERE `role` = 'admin');

-- ----------------------------------------------------------------------------
-- Step 13: Delete All Test Users (Patients, Doctors, Hospitals)
-- Parent table: Admin user(s) strictly preserved
-- ----------------------------------------------------------------------------
DELETE FROM `users` 
WHERE `role` != 'admin';

-- Reset Admin Google SSO links if needed so fresh Google linking can be tested
UPDATE `users` 
SET `google_sub` = NULL, 
    `google_email` = NULL, 
    `google_picture` = NULL, 
    `google_linked_at` = NULL 
WHERE `role` = 'admin';

-- Log audit record of the database reset operation
INSERT INTO `audit_logs` (`user_id`, `user_role`, `user_name`, `action`, `details`, `ip_address`, `created_at`) 
SELECT `id`, `role`, `username`, 'SYSTEM_RESET_TEST_DATA', 'Database test data safely reset to clean testing state. Admin preserved.', '127.0.0.1', NOW()
FROM `users` 
WHERE `role` = 'admin' 
LIMIT 1;

-- Commit the transaction atomically
COMMIT;

-- ----------------------------------------------------------------------------
-- Verification Query: Inspect remaining counts after reset
-- ----------------------------------------------------------------------------
SELECT 'users (Admin)' AS entity, COUNT(*) AS count FROM `users`
UNION ALL
SELECT 'patients', COUNT(*) FROM `patients`
UNION ALL
SELECT 'doctors', COUNT(*) FROM `doctors`
UNION ALL
SELECT 'hospitals', COUNT(*) FROM `hospitals`
UNION ALL
SELECT 'health_records', COUNT(*) FROM `health_records`
UNION ALL
SELECT 'consent_requests', COUNT(*) FROM `consent_requests`
UNION ALL
SELECT 'esign_records', COUNT(*) FROM `esign_records`
UNION ALL
SELECT 'ekyc_verifications (Admin)', COUNT(*) FROM `ekyc_verifications`
UNION ALL
SELECT 'aadhaar_ekyc', COUNT(*) FROM `aadhaar_ekyc`
UNION ALL
SELECT 'registry', COUNT(*) FROM `registry`
UNION ALL
SELECT 'sso_sessions (Admin)', COUNT(*) FROM `sso_sessions`
UNION ALL
SELECT 'audit_logs (Admin)', COUNT(*) FROM `audit_logs`;
