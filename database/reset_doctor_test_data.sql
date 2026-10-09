-- ============================================================================
-- Script: reset_doctor_test_data.sql
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- Purpose: Safely delete all Doctor test data while strictly PRESERVING:
--          - Admin account (users.id = 1, role = 'admin')
--          - Admin eKYC records & SSO sessions & audit logs
--          - All Patient accounts & patient data (records, eKYC, ABHA)
--          - All Hospital accounts & hospital data
--
-- Safety:
--   - Foreign-key-safe child-to-parent deletion order
--   - Atomic transaction wrapper (START TRANSACTION / COMMIT)
--   - Targeted subqueries scoped strictly to role = 'doctor'
-- ============================================================================

USE `digital_health_db`;

START TRANSACTION;

-- ----------------------------------------------------------------------------
-- Step 1: Delete Doctor-related eSign Records
-- Child table: references consent_requests(id) and users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `esign_records` 
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor')
   OR `signer_role` = 'doctor'
   OR `consent_id` IN (
       SELECT `id` FROM `consent_requests` 
       WHERE `requester_user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor')
   );

-- ----------------------------------------------------------------------------
-- Step 2: Delete Doctor Consent Requests
-- Child table: references patients(id), health_records(id), users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `consent_requests` 
WHERE `requester_user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor')
   OR `requester_role` = 'doctor';

-- ----------------------------------------------------------------------------
-- Step 3: Delete Doctor-created Health Records (preserve Patient-uploaded records)
-- Child table: references patients(id) and users(id)
-- ----------------------------------------------------------------------------
DELETE FROM `health_records` 
WHERE `created_by_user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor');

-- ----------------------------------------------------------------------------
-- Step 4: Delete Doctor Central DEPA Registry Entries
-- ----------------------------------------------------------------------------
DELETE FROM `registry` 
WHERE `entity_type` = 'DOCTOR'
   OR `entity_uid` LIKE 'DOC-%'
   OR `reference_id` IN (SELECT `id` FROM `doctors`);

-- ----------------------------------------------------------------------------
-- Step 5: Delete Doctor Profiles from doctors table
-- ----------------------------------------------------------------------------
DELETE FROM `doctors` 
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor');

-- ----------------------------------------------------------------------------
-- Step 6: Delete Doctor Sandbox eKYC Records (preserve Admin & Patient eKYC)
-- ----------------------------------------------------------------------------
DELETE FROM `ekyc_verifications` 
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor');

-- ----------------------------------------------------------------------------
-- Step 7: Delete Doctor Aadhaar Document eKYC Records (preserve Patient Aadhaar)
-- ----------------------------------------------------------------------------
DELETE FROM `aadhaar_ekyc` 
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor');

-- ----------------------------------------------------------------------------
-- Step 8: Delete Doctor SSO Sessions (preserve Admin & Patient SSO sessions)
-- ----------------------------------------------------------------------------
DELETE FROM `sso_sessions` 
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor')
   OR `user_role` = 'doctor';

-- ----------------------------------------------------------------------------
-- Step 9: Delete Doctor Password Reset Tokens
-- ----------------------------------------------------------------------------
DELETE FROM `password_resets` 
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor');

-- ----------------------------------------------------------------------------
-- Step 10: Delete Doctor Audit Logs (preserve Admin and Patient audit logs)
-- ----------------------------------------------------------------------------
DELETE FROM `audit_logs` 
WHERE `user_role` = 'doctor'
   OR `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor');

-- ----------------------------------------------------------------------------
-- Step 11: Delete Doctor User Accounts from users table
-- ----------------------------------------------------------------------------
DELETE FROM `users` 
WHERE `role` = 'doctor';

-- ----------------------------------------------------------------------------
-- Step 12: Audit Log Entry for Doctor Test Data Reset Operation
-- ----------------------------------------------------------------------------
INSERT INTO `audit_logs` (`user_id`, `user_role`, `user_name`, `action`, `details`, `ip_address`, `created_at`)
SELECT `id`, `role`, `username`, 'DOCTOR_TEST_DATA_RESET', 'All Doctor test accounts and doctor records reset. Admin and Patient data preserved.', '127.0.0.1', NOW()
FROM `users` 
WHERE `role` = 'admin' 
LIMIT 1;

COMMIT;

-- ----------------------------------------------------------------------------
-- Verification Queries
-- ----------------------------------------------------------------------------
SELECT 'users (Admin)' AS entity, COUNT(*) AS count FROM `users` WHERE `role` = 'admin'
UNION ALL
SELECT 'users (Patient)', COUNT(*) FROM `users` WHERE `role` = 'patient'
UNION ALL
SELECT 'users (Doctor)', COUNT(*) FROM `users` WHERE `role` = 'doctor'
UNION ALL
SELECT 'doctors', COUNT(*) FROM `doctors`
UNION ALL
SELECT 'registry (DOCTOR)', COUNT(*) FROM `registry` WHERE `entity_type` = 'DOCTOR'
UNION ALL
SELECT 'ekyc_verifications (Doctor)', COUNT(*) FROM `ekyc_verifications` WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` = 'doctor')
UNION ALL
SELECT 'sso_sessions (Doctor)', COUNT(*) FROM `sso_sessions` WHERE `user_role` = 'doctor';
