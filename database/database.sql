-- ============================================================================
-- Database: digital_health_db
-- Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
-- Modules: eKYC Verification, Single Sign-On (SSO), eSign Authorization,
--          DEPA Consent Manager, Central Registry, and Patient/Doctor/Hospital Vault
-- Created for: XAMPP Apache / MySQL / phpMyAdmin
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `digital_health_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `digital_health_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Table: users (Master Authentication & SSO Registry)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'doctor', 'hospital', 'patient') NOT NULL,
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `ekyc_status` ENUM('Pending', 'Verified', 'Failed') NOT NULL DEFAULT 'Pending',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_role` (`role`),
    INDEX `idx_status` (`status`),
    INDEX `idx_ekyc` (`ekyc_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. Table: patients (Patient Profile Demographics)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `patients`;
CREATE TABLE `patients` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `patient_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'DEPA/ABHA Health ID (e.g. ABHA-PAT-1001)',
    `full_name` VARCHAR(100) NOT NULL,
    `dob` DATE NOT NULL,
    `gender` ENUM('Male', 'Female', 'Other') NOT NULL,
    `blood_group` VARCHAR(10) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `address` TEXT NULL,
    `emergency_contact` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_patients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_patient_uid` (`patient_uid`),
    INDEX `idx_blood_group` (`blood_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. Table: doctors (Doctor Profile & Credentials)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `doctors`;
CREATE TABLE `doctors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `doctor_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'DEPA Doctor ID (e.g. DOC-REG-2001)',
    `full_name` VARCHAR(100) NOT NULL,
    `specialization` VARCHAR(100) NOT NULL,
    `license_number` VARCHAR(50) NOT NULL UNIQUE,
    `hospital_affiliation` VARCHAR(150) NULL,
    `phone` VARCHAR(20) NOT NULL,
    `experience_years` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_doctors_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_doctor_uid` (`doctor_uid`),
    INDEX `idx_specialization` (`specialization`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. Table: hospitals (Hospital Profile & Facility Info)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `hospitals`;
CREATE TABLE `hospitals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `hospital_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'DEPA Hospital ID (e.g. HOSP-REG-3001)',
    `hospital_name` VARCHAR(150) NOT NULL,
    `license_number` VARCHAR(50) NOT NULL UNIQUE,
    `address` TEXT NOT NULL,
    `contact_person` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(100) NOT NULL,
    `established_year` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_hospitals_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_hospital_uid` (`hospital_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. Table: ekyc_verifications (Sandbox eKYC Identity Ledger)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `ekyc_verifications`;
CREATE TABLE `ekyc_verifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ekyc_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Sandbox KYC Handle (e.g. KYC-SANDBOX-1001)',
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
    CONSTRAINT `fk_ekyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_ekyc_uid` (`ekyc_uid`),
    INDEX `idx_ekyc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5B. Table: aadhaar_ekyc (Aadhaar Card Document Upload based eKYC)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `aadhaar_ekyc`;
CREATE TABLE `aadhaar_ekyc` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `verification_type` VARCHAR(100) NOT NULL DEFAULT 'Aadhaar Document OCR',
    `verification_status` ENUM('PENDING', 'VERIFIED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    `verified_name` VARCHAR(150) NULL,
    `verified_dob` VARCHAR(50) NULL,
    `verified_gender` VARCHAR(20) NULL,
    `verified_address` TEXT NULL,
    `document_reference` VARCHAR(255) NULL COMMENT 'Secure internal storage reference filename',
    `document_type` VARCHAR(20) NULL COMMENT 'PDF, JPG, JPEG, PNG',
    `ocr_confidence` DECIMAL(5,2) NULL DEFAULT 0.00,
    `fail_reason` TEXT NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_aadhaar_ekyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_aadhaar_user` (`user_id`),
    INDEX `idx_aadhaar_status` (`verification_status`),
    INDEX `idx_aadhaar_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 6. Table: sso_sessions (Single Sign-On Active Session Tracking)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `sso_sessions`;
CREATE TABLE `sso_sessions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `session_token` VARCHAR(64) NOT NULL UNIQUE,
    `user_id` INT NOT NULL,
    `user_role` ENUM('admin', 'doctor', 'hospital', 'patient') NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(255) NULL,
    `login_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_activity` DATETIME NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT `fk_sso_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_sso_token` (`session_token`),
    INDEX `idx_sso_active` (`user_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 7. Table: health_records (Patient Health Records & Artifacts)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `health_records`;
CREATE TABLE `health_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `record_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'DEPA Record Identifier (e.g. REC-UID-5001)',
    `patient_id` INT NOT NULL,
    `created_by_user_id` INT NOT NULL,
    `record_type` ENUM('Diagnostic Report', 'Prescription', 'Discharge Summary', 'Lab Test', 'Immunization', 'Radiology / Scan', 'Consultation Note') NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `diagnosis` TEXT NOT NULL,
    `prescription` TEXT NULL,
    `medical_notes` TEXT NULL,
    `doctor_hospital_name` VARCHAR(150) NULL,
    `record_date` DATE NOT NULL,
    `file_attachment` VARCHAR(255) NULL,
    `file_original_name` VARCHAR(255) NULL,
    `file_size` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_records_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_records_creator` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_patient_records` (`patient_id`),
    INDEX `idx_record_type` (`record_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 8. Table: consent_requests (DEPA Consent Artifacts)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `consent_requests`;
CREATE TABLE `consent_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `consent_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'DEPA Consent Handle (e.g. CNST-9001)',
    `patient_id` INT NOT NULL,
    `requester_user_id` INT NOT NULL,
    `requester_role` ENUM('doctor', 'hospital') NOT NULL,
    `record_id` INT NULL COMMENT 'NULL indicates access to all health records for patient',
    `purpose` VARCHAR(255) NOT NULL,
    `requested_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expiry_date` DATETIME NOT NULL,
    `status` ENUM('Pending', 'Approved', 'Rejected', 'Revoked', 'Expired') NOT NULL DEFAULT 'Pending',
    `is_esigned` TINYINT(1) NOT NULL DEFAULT 0,
    `esign_id` INT NULL,
    `decision_date` DATETIME NULL,
    `revoked_date` DATETIME NULL,
    `patient_notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_consent_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_consent_requester` FOREIGN KEY (`requester_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_consent_record` FOREIGN KEY (`record_id`) REFERENCES `health_records` (`id`) ON DELETE SET NULL,
    INDEX `idx_consent_status` (`status`),
    INDEX `idx_consent_expiry` (`expiry_date`),
    INDEX `idx_patient_consent` (`patient_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 9. Table: esign_records (DEPA Electronic Signature Records)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `esign_records`;
CREATE TABLE `esign_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `esign_uid` VARCHAR(50) NOT NULL UNIQUE COMMENT 'eSign Handle (e.g. ESIGN-DEMO-9001)',
    `consent_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `signer_role` ENUM('patient', 'doctor', 'hospital') NOT NULL,
    `signer_name` VARCHAR(100) NOT NULL,
    `doc_ref` VARCHAR(100) NOT NULL,
    `signature_type` ENUM('OTP Verified eSign', 'Biometric / Cryptographic Token (Demo)', 'Digital Signature Certificate') NOT NULL DEFAULT 'OTP Verified eSign',
    `signature_hash` VARCHAR(64) NOT NULL COMMENT 'SHA-256 digital signature hash',
    `status` ENUM('Signed', 'Pending', 'Failed') NOT NULL DEFAULT 'Signed',
    `signed_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_esign_consent` FOREIGN KEY (`consent_id`) REFERENCES `consent_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_esign_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_esign_uid` (`esign_uid`),
    INDEX `idx_consent_esign` (`consent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 10. Table: registry (DEPA Central Registry / Directory)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `registry`;
CREATE TABLE `registry` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `entity_type` ENUM('PATIENT', 'DOCTOR', 'HOSPITAL', 'RECORD', 'CONSENT', 'ESIGN', 'EKYC') NOT NULL,
    `entity_uid` VARCHAR(50) NOT NULL UNIQUE,
    `entity_name` VARCHAR(150) NOT NULL,
    `reference_id` INT NOT NULL,
    `status` ENUM('ACTIVE', 'INACTIVE', 'REVOKED', 'EXPIRED') NOT NULL DEFAULT 'ACTIVE',
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_entity_type` (`entity_type`),
    INDEX `idx_entity_uid` (`entity_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 11. Table: audit_logs (System Audit Trail)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `user_role` VARCHAR(50) NULL,
    `user_name` VARCHAR(100) NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action` (`action`),
    INDEX `idx_user_role` (`user_role`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA (Pre-loaded Ready-to-use Sample Accounts & Scenarios)
-- Password for Admin:    Admin@123
-- Password for Doctors:  Doctor@123
-- Password for Hospital: Hospital@123
-- Password for Patients: Patient@123
-- ============================================================================

-- 1. Insert Users (All pre-verified with eKYC for quick testing)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `status`, `ekyc_status`, `created_at`) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$zJ1cRvwp0WiPeNhC5x/cmerpgBPzb.xkwEJ30ThyUS633j3s4F2iq', 'admin', 'active', 'Verified', '2026-01-10 10:00:00'),
(2, 'dr.sharma', 'rajesh.sharma@medmail.in', '$2y$10$Bh.EnYnRntUOwjZp0MrCHOL/2NDmBzNgUky9iY9w330MkoHllkvce', 'doctor', 'active', 'Verified', '2026-01-12 11:00:00'),
(3, 'dr.anita', 'anita.desai@cityneuro.in', '$2y$10$Bh.EnYnRntUOwjZp0MrCHOL/2NDmBzNgUky9iY9w330MkoHllkvce', 'doctor', 'active', 'Verified', '2026-01-15 09:30:00'),
(4, 'apollo_hospital', 'contact@apollohealth.org', '$2y$10$0iVai2lJtGW6FM9j5GyJu.8Nn7iIP.SDxI/scH1SBlU3izN5CQmnO', 'hospital', 'active', 'Verified', '2026-01-18 14:00:00'),
(5, 'rahul_sharma', 'rahul.s92@gmail.com', '$2y$10$SEG6qP3kcpYYpdrFuHHyK.J5VNy9N2QTZToKaoCaIyLat.fzgjS0O', 'patient', 'active', 'Verified', '2026-02-01 08:00:00'),
(6, 'priya_patel', 'priya.patel.95@gmail.com', '$2y$10$SEG6qP3kcpYYpdrFuHHyK.J5VNy9N2QTZToKaoCaIyLat.fzgjS0O', 'patient', 'active', 'Pending', '2026-02-05 12:15:00');

-- 2. Insert Patients
INSERT INTO `patients` (`id`, `user_id`, `patient_uid`, `full_name`, `dob`, `gender`, `blood_group`, `phone`, `address`, `emergency_contact`, `created_at`) VALUES
(1, 5, 'ABHA-PAT-1001', 'Rahul Sharma', '1992-04-14', 'Male', 'B+', '+91 98765 43210', 'Flat 402, Sunshine Heights, MG Road, Bengaluru', 'Sunita Sharma (Mother) - 9876543211', '2026-02-01 08:05:00'),
(2, 6, 'ABHA-PAT-1002', 'Priya Patel', '1995-11-20', 'Female', 'O+', '+91 98123 45678', 'Plot 12, Green Avenue, Sector 14, Gurugram', 'Vikram Patel (Spouse) - 9812345679', '2026-02-05 12:20:00');

-- 3. Insert Doctors
INSERT INTO `doctors` (`id`, `user_id`, `doctor_uid`, `full_name`, `specialization`, `license_number`, `hospital_affiliation`, `phone`, `experience_years`, `created_at`) VALUES
(1, 2, 'DOC-REG-2001', 'Dr. Rajesh Sharma, MD', 'Cardiology', 'MCI-KA-2012-48921', 'Apollo Heart Institute', '+91 98450 11223', 14, '2026-01-12 11:05:00'),
(2, 3, 'DOC-REG-2002', 'Dr. Anita Desai, MS', 'Neurology', 'MCI-MH-2016-89104', 'City Neurosciences Centre', '+91 98220 33445', 10, '2026-01-15 09:35:00');

-- 4. Insert Hospitals
INSERT INTO `hospitals` (`id`, `user_id`, `hospital_uid`, `hospital_name`, `license_number`, `address`, `contact_person`, `phone`, `email`, `established_year`, `created_at`) VALUES
(1, 4, 'HOSP-REG-3001', 'Apollo City Hospital & Research Centre', 'NABH-FAC-2018-0912', '154/11 Bannerghatta Road, Bengaluru, Karnataka 560076', 'Dr. S. K. Nambiar (Medical Superintendent)', '+91 80 2630 4000', 'contact@apollohealth.org', 1988, '2026-01-18 14:05:00');

-- 5. Insert eKYC Verifications
INSERT INTO `ekyc_verifications` (`id`, `user_id`, `ekyc_uid`, `doc_type`, `doc_number_masked`, `full_name`, `dob`, `gender`, `otp_verified`, `status`, `verified_at`, `created_at`) VALUES
(1, 1, 'KYC-SANDBOX-1001', 'PAN Card Demo', 'XXXX-XXXX-9001', 'System Administrator', '1985-01-01', 'Other', 1, 'Verified', '2026-01-10 10:05:00', '2026-01-10 10:00:00'),
(2, 2, 'KYC-SANDBOX-2001', 'Medical License Verification', 'XXXX-MCI-2001', 'Dr. Rajesh Sharma', '1980-05-12', 'Male', 1, 'Verified', '2026-01-12 11:10:00', '2026-01-12 11:00:00'),
(3, 3, 'KYC-SANDBOX-2002', 'Medical License Verification', 'XXXX-MCI-2002', 'Dr. Anita Desai', '1984-09-23', 'Female', 1, 'Verified', '2026-01-15 09:40:00', '2026-01-15 09:30:00'),
(4, 4, 'KYC-SANDBOX-3001', 'PAN Card Demo', 'XXXX-NABH-3001', 'Apollo City Hospital', '1995-11-15', 'Other', 1, 'Verified', '2026-01-18 14:10:00', '2026-01-18 14:00:00'),
(5, 5, 'KYC-SANDBOX-5001', 'Aadhaar Sandbox Demo', 'XXXX-XXXX-1001', 'Rahul Sharma', '1992-04-14', 'Male', 1, 'Verified', '2026-02-01 08:10:00', '2026-02-01 08:00:00');

-- 6. Insert Sample Health Records
INSERT INTO `health_records` (`id`, `record_uid`, `patient_id`, `created_by_user_id`, `record_type`, `title`, `diagnosis`, `prescription`, `medical_notes`, `doctor_hospital_name`, `record_date`, `file_attachment`, `file_original_name`, `file_size`, `created_at`) VALUES
(1, 'REC-UID-5001', 1, 5, 'Diagnostic Report', 'Comprehensive Lipid & Cardiac Profile', 'Mild Dyslipidemia with elevated LDL-C (148 mg/dL). Normal resting ECG with normal sinus rhythm.', 'Atorvastatin 10mg OD bedtime for 90 days. Dietary restriction on saturated fats and 30 mins brisk walking daily.', 'Patient presented with intermittent chest heaviness on exertion. Cardiac enzymes normal.', 'Dr. Rajesh Sharma / Apollo Heart Institute', '2026-02-10', 'sample_lipid_ecg.pdf', 'Lipid_Profile_ECG_Report.pdf', 245760, '2026-02-10 16:30:00'),
(2, 'REC-UID-5002', 1, 5, 'Radiology / Scan', 'Digital Chest X-Ray (PA View)', 'Bilateral lung fields clear. No active parenchymal lesion. Cardiac silhouette within normal limits. Costophrenic angles sharp.', 'None. Advised annual surveillance.', 'Routine pre-procedural baseline radiological evaluation.', 'City Scans & Diagnostics', '2026-02-15', 'sample_xray_report.pdf', 'Chest_XRay_PA_Report.pdf', 512000, '2026-02-15 11:10:00'),
(3, 'REC-UID-5003', 1, 5, 'Prescription', 'Post-Op Orthopedic Care & Physio Protocol', 'Grade 2 Right Ankle Sprain with anterior talofibular ligament strain.', 'Tab Aceclofenac 100mg + Paracetamol 325mg BD x 5 days.\nGel Diclofenac topically TID.\nAnkle air-cast support for 2 weeks.', 'RICE protocol explained. Avoid weight bearing for 72 hours. Physiotherapy review after 10 days.', 'Dr. V. Menon (Orthopedics)', '2026-02-20', NULL, NULL, 0, '2026-02-20 18:00:00'),
(4, 'REC-UID-5004', 2, 6, 'Lab Test', 'Complete Thyroid Profile & Fasting Blood Sugar', 'Subclinical Hypothyroidism (TSH: 6.8 uIU/mL, Free T4 normal). Fasting Blood Glucose: 92 mg/dL.', 'Thyroxine 25 mcg OD early morning empty stomach.\nRepeat TSH in 8 weeks.', 'Patient reports mild chronic fatigue and cold intolerance. No goiter.', 'Care Diagnostic Labs', '2026-02-25', 'sample_thyroid_report.pdf', 'Thyroid_FBS_Report.pdf', 184320, '2026-02-25 10:45:00');

-- 7. Insert Sample Consent Requests
INSERT INTO `consent_requests` (`id`, `consent_uid`, `patient_id`, `requester_user_id`, `requester_role`, `record_id`, `purpose`, `requested_date`, `expiry_date`, `status`, `is_esigned`, `esign_id`, `decision_date`, `revoked_date`, `patient_notes`, `created_at`) VALUES
-- Approved Active Consent (eSigned)
(1, 'CNST-9001', 1, 2, 'doctor', 1, 'Cardiology follow-up consultation and lipid management review.', '2026-08-10 10:00:00', '2026-09-10 23:59:59', 'Approved', 1, 1, '2026-08-10 14:20:00', NULL, 'Granted 30-day access for cardiac review.', '2026-08-10 10:00:00'),
-- Pending Consent
(2, 'CNST-9002', 1, 4, 'hospital', 2, 'Pre-admission surgical assessment and radiological verification.', '2026-08-16 09:15:00', '2026-08-25 23:59:59', 'Pending', 0, NULL, NULL, NULL, NULL, '2026-08-16 09:15:00'),
-- Revoked Consent
(3, 'CNST-9003', 1, 3, 'doctor', NULL, 'Neurological headache assessment and previous medical history.', '2026-07-01 11:00:00', '2026-08-01 23:59:59', 'Revoked', 1, NULL, '2026-07-01 15:00:00', '2026-07-15 18:30:00', 'Revoked post-consultation as treatment concluded.', '2026-07-01 11:00:00'),
-- Expired Consent
(4, 'CNST-9004', 2, 4, 'hospital', 4, 'Endocrinology second opinion and institutional health audit.', '2026-06-01 08:30:00', '2026-07-01 23:59:59', 'Expired', 1, NULL, '2026-06-01 10:00:00', NULL, 'Auto-expired per DEPA policy.', '2026-06-01 08:30:00'),
-- Rejected Consent
(5, 'CNST-9005', 1, 3, 'doctor', 3, 'Second opinion on orthopedic physiotherapy prescription.', '2026-08-05 14:00:00', '2026-08-12 23:59:59', 'Rejected', 0, NULL, '2026-08-06 09:10:00', NULL, 'Not relevant to neurology consultation.', '2026-08-05 14:00:00');

-- 8. Insert eSign Records
INSERT INTO `esign_records` (`id`, `esign_uid`, `consent_id`, `user_id`, `signer_role`, `signer_name`, `doc_ref`, `signature_type`, `signature_hash`, `status`, `signed_at`, `created_at`) VALUES
(1, 'ESIGN-DEMO-9001', 1, 5, 'patient', 'Rahul Sharma', 'Consent CNST-9001 (REC-UID-5001)', 'OTP Verified eSign', SHA2('CNST-9001-ABHA-PAT-1001-Cardiac-2026', 256), 'Signed', '2026-08-10 14:20:00', '2026-08-10 14:20:00');

-- 9. Insert Central Registry Entries
INSERT INTO `registry` (`id`, `entity_type`, `entity_uid`, `entity_name`, `reference_id`, `status`, `registered_at`) VALUES
(1, 'PATIENT', 'ABHA-PAT-1001', 'Rahul Sharma', 1, 'ACTIVE', '2026-02-01 08:05:00'),
(2, 'PATIENT', 'ABHA-PAT-1002', 'Priya Patel', 2, 'ACTIVE', '2026-02-05 12:20:00'),
(3, 'DOCTOR', 'DOC-REG-2001', 'Dr. Rajesh Sharma, MD', 1, 'ACTIVE', '2026-01-12 11:05:00'),
(4, 'DOCTOR', 'DOC-REG-2002', 'Dr. Anita Desai, MS', 2, 'ACTIVE', '2026-01-15 09:35:00'),
(5, 'HOSPITAL', 'HOSP-REG-3001', 'Apollo City Hospital & Research Centre', 1, 'ACTIVE', '2026-01-18 14:05:00'),
(6, 'RECORD', 'REC-UID-5001', 'Comprehensive Lipid & Cardiac Profile (Rahul Sharma)', 1, 'ACTIVE', '2026-02-10 16:30:00'),
(7, 'RECORD', 'REC-UID-5002', 'Digital Chest X-Ray (PA View) (Rahul Sharma)', 2, 'ACTIVE', '2026-02-15 11:10:00'),
(8, 'RECORD', 'REC-UID-5003', 'Post-Op Orthopedic Care & Physio Protocol (Rahul Sharma)', 3, 'ACTIVE', '2026-02-20 18:00:00'),
(9, 'RECORD', 'REC-UID-5004', 'Complete Thyroid Profile (Priya Patel)', 4, 'ACTIVE', '2026-02-25 10:45:00'),
(10, 'CONSENT', 'CNST-9001', 'Consent CNST-9001 [Dr. Rajesh Sharma -> Rahul Sharma]', 1, 'ACTIVE', '2026-08-10 10:00:00'),
(11, 'CONSENT', 'CNST-9002', 'Consent CNST-9002 [Apollo Hospital -> Rahul Sharma]', 2, 'ACTIVE', '2026-08-16 09:15:00'),
(12, 'CONSENT', 'CNST-9003', 'Consent CNST-9003 [Dr. Anita Desai -> Rahul Sharma]', 3, 'REVOKED', '2026-07-01 11:00:00'),
(13, 'CONSENT', 'CNST-9004', 'Consent CNST-9004 [Apollo Hospital -> Priya Patel]', 4, 'EXPIRED', '2026-06-01 08:30:00'),
(14, 'CONSENT', 'CNST-9005', 'Consent CNST-9005 [Dr. Anita Desai -> Rahul Sharma]', 5, 'INACTIVE', '2026-08-05 14:00:00');

-- 10. Insert System Audit Logs
INSERT INTO `audit_logs` (`id`, `user_id`, `user_role`, `user_name`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 1, 'admin', 'System Admin', 'SYSTEM_INITIALIZATION', 'DEPA Central Registry, Health Record Exchange, and Audit Engine initialized successfully.', '127.0.0.1', '2026-01-10 10:00:00'),
(2, 5, 'patient', 'Rahul Sharma', 'USER_REGISTER', 'New patient registered with ABHA ID ABHA-PAT-1001.', '127.0.0.1', '2026-02-01 08:05:00'),
(3, 5, 'patient', 'Rahul Sharma', 'EKYC_VERIFIED', 'Completed sandbox eKYC verification (KYC-SANDBOX-5001).', '127.0.0.1', '2026-02-01 08:10:00'),
(4, 5, 'patient', 'Rahul Sharma', 'RECORD_ADDED', 'Added health record: Comprehensive Lipid & Cardiac Profile (REC-UID-5001) with attachment.', '127.0.0.1', '2026-02-10 16:30:00'),
(5, 2, 'doctor', 'Dr. Rajesh Sharma, MD', 'CONSENT_REQUESTED', 'Requested DEPA consent CNST-9001 for patient Rahul Sharma (REC-UID-5001). Purpose: Cardiology follow-up.', '127.0.0.1', '2026-08-10 10:00:00'),
(6, 5, 'patient', 'Rahul Sharma', 'ESIGN_COMPLETED', 'Digitally eSigned consent authorization CNST-9001 (ESIGN-DEMO-9001).', '127.0.0.1', '2026-08-10 14:20:00'),
(7, 5, 'patient', 'Rahul Sharma', 'CONSENT_APPROVED', 'Approved consent CNST-9001 for Dr. Rajesh Sharma with validity till 2026-09-10.', '127.0.0.1', '2026-08-10 14:20:00'),
(8, 2, 'doctor', 'Dr. Rajesh Sharma, MD', 'RECORD_ACCESSED', 'Accessed clinical health record REC-UID-5001 under valid DEPA consent CNST-9001.', '127.0.0.1', '2026-08-11 11:15:20'),
(9, 5, 'patient', 'Rahul Sharma', 'CONSENT_REVOKED', 'Revoked consent CNST-9003 previously granted to Dr. Anita Desai. Access token invalidated.', '127.0.0.1', '2026-07-15 18:30:00');

-- 11. Aadhaar Card Document Upload based eKYC Table
CREATE TABLE IF NOT EXISTS `aadhaar_ekyc` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `verification_type` VARCHAR(100) NOT NULL DEFAULT 'Aadhaar Document OCR',
    `verification_status` ENUM('NOT_VERIFIED', 'PENDING', 'VERIFIED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    `verified_name` VARCHAR(150) NULL,
    `verified_dob` VARCHAR(50) NULL,
    `verified_gender` VARCHAR(20) NULL,
    `verified_address` TEXT NULL,
    `document_reference` VARCHAR(255) NULL COMMENT 'Secure internal storage reference filename',
    `document_type` VARCHAR(20) NULL COMMENT 'PDF, JPG, JPEG, PNG',
    `ocr_confidence` DECIMAL(5,2) NULL DEFAULT 0.00,
    `fail_reason` TEXT NULL,
    `verified_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_aadhaar_ekyc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    INDEX `idx_aadhaar_user` (`user_id`),
    INDEX `idx_aadhaar_status` (`verification_status`),
    INDEX `idx_aadhaar_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
