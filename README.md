# Digital Health Record Exchange using DEPA Consent, Registry and Modules

An enterprise-grade, consent-based digital health data exchange simulation based on the **Data Empowerment and Protection Architecture (DEPA)** framework.

Developed with: **PHP 8+, MySQL (MariaDB), HTML5, CSS3, JavaScript, and Bootstrap 5.3**.  
Designed to run locally on **XAMPP (Apache + MySQL)**.

---

## 🏗️ Architecture & Modules Overview

```
Registration ➔ eKYC Sandbox ➔ SSO Login ➔ Health Records Vault ➔ Consent Manager ➔ eSign Authorization ➔ Clinical Viewer ➔ Audit Logs
```

1. **Centralized Single Sign-On (SSO)** (`login.php`, `sso_sessions`): Unified login gateway with automatic role identification and session tracking.
2. **Aadhaar Card Document Upload eKYC** (`patient/aadhaar_ekyc.php`, `includes/aadhaar_ekyc_service.php`, `aadhaar_ekyc`): Zero-typing, privacy-preserving document upload eKYC for JPG, JPEG, PNG, and PDF. Uses OCR and document processing to extract Name, DOB/YOB, Gender, and Address, performs format and consistency checks, securely stores documents in a protected non-public repository, and masks personal identifiers.
3. **eKYC Verification Sandbox** (`ekyc/verify.php`, `admin/ekyc.php`): Safe identity verification simulation supporting masked document proofs and OTP authentication.
4. **Patient Health Record Vault** (`patient/`): Secure storage for diagnostic reports, prescriptions, radiology scans, and lab tests.
5. **DEPA-Inspired Consent Manager** (`doctor/`, `hospital/`, `patient/`): Purpose-bound, time-limited, revocable electronic consent management.
6. **eSign Digital Signature Module** (`patient/esign_consent.php`, `esign_records`): Cryptographic SHA-256 digital signature authorization.
7. **Central Master Registry** (`admin/registry.php`): Master directory indexing Patients (ABHA), Doctors (MCI), Hospitals (NABH), Records, and Consents.
8. **Immutable Audit Trail** (`admin/audit_logs.php`): Regulatory compliance ledger capturing 15+ distinct system interaction events with IP addresses and timestamps.

---

## 🪪 Aadhaar Card Document Upload based eKYC

### Key Privacy & Compliance Guidelines:
- **Zero-Manual Aadhaar Number Entry**: The patient is never prompted to type a 12-digit Aadhaar number. All demographics are extracted directly from the uploaded document (PDF, JPG, JPEG, PNG).
- **Minimum Required Data Storage**: The system stores only `verified_name`, `verified_dob`, `verified_gender`, `document_reference`, and status. Full Aadhaar numbers are never logged or stored in plaintext.
- **Protected Non-Public Storage**: Uploaded Aadhaar documents are saved in a protected directory (`uploads/aadhaar_documents/`) with `.htaccess` (`Require all denied`) and accessed only through authenticated role-checked controllers (`patient/view_aadhaar_doc.php`).
- **Data Masking in UI**: After verification, user attributes are displayed with strict masking:
  ```
  ✓ Aadhaar Card Document Verification Successful
  Name: ********
  DOB: ********
  Gender: ********
  (Full Aadhaar number is never displayed)
  ```
- **UIDAI & Legal Disclaimer**: This module performs document-level verification and OCR extraction for academic and demonstration purposes. OCR/document checks are not the same as official UIDAI authentication. Production deployment requires an authorized UIDAI/ABDM-supported gateway (AUA/KUA).
- **Test Artifacts**: Sample mock Aadhaar cards (`sample_mock_aadhaar.pdf`) are provided for testing. Real Aadhaar documents are strictly prohibited in test data.

---

## 🚀 Setup Instructions (XAMPP)

1. **Place Project Files**:  
   Ensure the project folder is placed in:
   ```
   C:/xampp/htdocs/digital-health-record/
   ```
2. **Start Apache & MySQL**:  
   Open the **XAMPP Control Panel** and start **Apache** and **MySQL**.
3. **Import Database**:
   - Open **phpMyAdmin**: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/)
   - Create database named: `digital_health_db` (or run `database/database.sql` which creates it automatically).
   - Import file: `database/database.sql`.
4. **Launch Application**:  
   Open your browser and navigate to:
   ```
   http://localhost/digital-health-record/
   ```

---

## 🔑 Demo User Accounts & Credentials

| Role | Username | Password | Full Name / Description | DEPA UID / Handle | eKYC Status |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Admin** | `pvannapurna4u@gmail.com` | `Admin@123` | System Administrator | N/A | `Verified` |
| **Doctor** | `dr.sharma` | `Doctor@123` | Dr. Rajesh Sharma, MD (Cardiology) | `DOC-REG-2001` | `Verified` |
| **Doctor** | `dr.anita` | `Doctor@123` | Dr. Anita Desai, MS (Neurology) | `DOC-REG-2002` | `Verified` |
| **Hospital** | `apollo_hospital` | `Hospital@123` | Apollo City Hospital & Research Centre | `HOSP-REG-3001` | `Verified` |
| **Patient** | `rahul_sharma` | `Patient@123` | Rahul Sharma | `ABHA-PAT-1001` | `Verified` |
| **Patient (Pending)** | `priya_patel` | `Patient@123` | Priya Patel (Use for testing eKYC) | `ABHA-PAT-1002` | `Pending` |

---

## 🌐 Complete URL Directory

- **Home / Landing**: `http://localhost/digital-health-record/`
- **SSO Login Portal**: `http://localhost/digital-health-record/login.php`
- **Registration**: `http://localhost/digital-health-record/register.php`
- **eKYC Verification**: `http://localhost/digital-health-record/ekyc/verify.php`
- **Patient Portal**: `http://localhost/digital-health-record/patient/dashboard.php`
  - Aadhaar Card eKYC: `http://localhost/digital-health-record/patient/aadhaar_ekyc.php`
  - Profile & ABHA: `http://localhost/digital-health-record/patient/profile.php`
  - Records: `http://localhost/digital-health-record/patient/my_records.php`
  - Upload Record: `http://localhost/digital-health-record/patient/add_record.php`
  - Consent Requests: `http://localhost/digital-health-record/patient/consent_requests.php`
  - Sharing History & Revoke: `http://localhost/digital-health-record/patient/sharing_history.php`
- **Doctor Portal**: `http://localhost/digital-health-record/doctor/dashboard.php`
  - Search Patients: `http://localhost/digital-health-record/doctor/search_patients.php`
  - Request Consent: `http://localhost/digital-health-record/doctor/request_consent.php`
  - Authorized Records: `http://localhost/digital-health-record/doctor/authorized_records.php`
- **Hospital Portal**: `http://localhost/digital-health-record/hospital/dashboard.php`
- **Admin Console**: `http://localhost/digital-health-record/admin/login.php`
  - Central Registry: `http://localhost/digital-health-record/admin/registry.php`
  - Patient Directory: `http://localhost/digital-health-record/admin/patients.php`
  - Doctor Directory: `http://localhost/digital-health-record/admin/doctors.php`
  - Hospital Directory: `http://localhost/digital-health-record/admin/hospitals.php`
  - Consent & eSign Monitor: `http://localhost/digital-health-record/admin/consents.php`
  - Audit Trail: `http://localhost/digital-health-record/admin/audit_logs.php`
