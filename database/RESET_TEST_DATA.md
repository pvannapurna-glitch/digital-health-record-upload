# Database Reset Guide: Returning to Clean Testing State

This document explains how to reset the **Digital Health Record Exchange (`digital_health_db`)** database to a clean testing state while strictly preserving the **System Administrator** account, credentials, database structure, and application configurations.

---

## 1. Summary of Changes

### What Gets Preserved
1. **Database Schema & Structure**: All 13 tables, keys, indexes, triggers, and foreign keys remain completely intact. No `DROP TABLE` or `DROP DATABASE` is executed.
2. **Admin Account**: 
   - **ID**: `1`
   - **Username**: `pvannapurna4u@gmail.com`
   - **Email**: `pvannapurna4u@gmail.com`
   - **Role**: `admin`
   - **Password**: `appu2720` (or your existing updated admin password)
   - **eKYC Status**: `Verified` (`KYC-SANDBOX-1001`)
3. **Admin Audit Logs**: All administrative activity records in `audit_logs` are kept.
4. **Configuration Files**: 
   - `config/config.php`
   - `config/database.php`
   - `config/google.php` (OAuth Client ID & Secret)
   - `config/smtp_config.php`
5. **Composer & Code Architecture**: Google API Client (`vendor/`) and all application code are unchanged.

---

### What Gets Deleted
1. **Test User Accounts**: 65 test accounts (`patients`, `doctors`, `hospitals`).
2. **Patient Demographic Profiles**: 47 patient records in `patients`.
3. **Doctor Profiles & Credentials**: 17 doctor records in `doctors`.
4. **Hospital Facility Profiles**: 1 hospital facility record in `hospitals`.
5. **Health Records**: 17 patient health record artifacts in `health_records`.
6. **Consent Artifacts**: 18 consent requests in `consent_requests`.
7. **Electronic Signatures**: 12 eSign records in `esign_records`.
8. **Test eKYC Verifications**: 46 OCR records in `aadhaar_ekyc` and 22 test sandbox verification records in `ekyc_verifications`.
9. **Central Registry Entries**: 160 test entity handles in `registry`.
10. **Test SSO Sessions**: 92 test tokens in `sso_sessions`.
11. **Temporary Password Resets**: 4 reset tokens in `password_resets`.
12. **Test Audit Trails**: 472 non-admin log entries in `audit_logs`.

---

## 2. Table-by-Table Impact Analysis

| Table | Total Rows Before | Rows to Delete | Rows Preserved | Details |
| :--- | :---: | :---: | :---: | :--- |
| `users` | **66** | **65** | **1** | Only Admin (`id = 1`) preserved |
| `patients` | **47** | **47** | **0** | All test patient profiles removed |
| `doctors` | **17** | **17** | **0** | All test doctor profiles removed |
| `hospitals` | **1** | **1** | **0** | All test hospital facilities removed |
| `health_records` | **17** | **17** | **0** | All patient diagnostic/medical records removed |
| `consent_requests` | **18** | **18** | **0** | All DEPA consent requests removed |
| `esign_records` | **12** | **12** | **0** | All test eSign digital signature records removed |
| `aadhaar_ekyc` | **46** | **46** | **0** | All uploaded Aadhaar OCR test records removed |
| `ekyc_verifications` | **23** | **22** | **1** | Admin verified eKYC record (`KYC-SANDBOX-1001`) preserved |
| `password_resets` | **4** | **4** | **0** | Temporary expired test tokens cleared |
| `sso_sessions` | **104** | **92** | **12** | Non-admin SSO sessions cleared; Admin sessions preserved |
| `registry` | **160** | **160** | **0** | Test registry handles cleared |
| `audit_logs` | **513** | **472** | **41** | 41 Admin audit trail entries preserved |
| **Total** | **1,028** | **973** | **55** | Clean state ready for testing |

---

## 3. How to Take a Database Backup Before Reset

Before executing any deletion, create a complete backup so you can restore at any time.

### Option A: Via Command Line (Recommended)
Open PowerShell or Command Prompt:
```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root -p digital_health_db > C:\xampp\htdocs\digital-health-record\database\backup_before_reset.sql
```
*(Press Enter if you do not have a MySQL root password)*

### Option B: Via phpMyAdmin
1. Open your browser and navigate to: `http://localhost/phpmyadmin/`
2. In the left sidebar, click on **`digital_health_db`**.
3. In the top navigation tabs, click **Export**.
4. Choose **Quick** export method and Format **SQL**.
5. Click **Export** to download the `.sql` backup file.

---

## 4. How to Execute the Reset Safely

### Execution Order & Foreign Key Safety
The reset script strictly executes in the required topological dependency order:
```text
esign_records (child)
  → consent_requests (child)
    → health_records (child)
      → patients, doctors, hospitals (profiles)
        → aadhaar_ekyc, ekyc_verifications, password_resets, sso_sessions (user modules)
          → registry, audit_logs
            → users (parent master table)
```
This guarantees that **MySQL foreign key constraints are never violated** and eliminates the need for unsafe `FOREIGN_KEY_CHECKS = 0`. The entire process runs inside an atomic SQL `START TRANSACTION` ... `COMMIT` block.

### Running in phpMyAdmin
1. Open phpMyAdmin: `http://localhost/phpmyadmin/`
2. Select **`digital_health_db`** from the left list.
3. Click the **Import** tab:
   - Click **Choose File** and select `C:\xampp\htdocs\digital-health-record\database\reset_test_data.sql`.
   - Scroll down and click **Import**.
4. Alternatively, click the **SQL** tab:
   - Open `database/reset_test_data.sql` in VS Code / Notepad.
   - Copy the entire SQL content and paste it into the phpMyAdmin query window.
   - Click **Go**.

### Running via Command Line (PowerShell)
```powershell
Get-Content "C:\xampp\htdocs\digital-health-record\database\reset_test_data.sql" | & "C:\xampp\mysql\bin\mysql.exe" -u root digital_health_db
```

---

## 5. Verification Checklist After Reset

Run this query to verify that only the Admin remains:

```sql
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
SELECT 'registry', COUNT(*) FROM `registry`;
```

Expected Output:
- `users (Admin)`: **1**
- `ekyc_verifications (Admin)`: **1**
- All other test data tables: **0**

Log in at [http://localhost/digital-health-record/login.php](http://localhost/digital-health-record/login.php) with:
- **Username**: `pvannapurna4u@gmail.com`
- **Password**: `appu2720` (or Admin@123)
- **Role**: `System Administrator`

The system is now in a pristine state ready for fresh registrations and end-to-end testing of Patients, Doctors, Hospitals, and Google SSO.
