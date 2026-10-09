# Google Single Sign-On (SSO) Integration & Setup Guide

This document provides step-by-step instructions for configuring, managing, and testing Google OAuth 2.0 Single Sign-On (SSO) in the **Digital Health Record Exchange (DEPA Consent & Exchange Platform)**.

---

## 1. Google Cloud Project Setup

1. Open the [Google Cloud Console](https://console.cloud.google.com/).
2. Log in with your Google account.
3. In the top navigation bar, click the project dropdown and select **New Project**.
4. Set the project details:
   - **Project Name**: `DEPA Digital Health Record` (or your preferred name)
   - **Organization**: No organization (or your organization)
5. Click **Create** and wait for project initialization to complete.

---

## 2. OAuth Consent Screen Configuration

1. In the left sidebar, navigate to **APIs & Services** → **OAuth consent screen** (or [click here](https://console.cloud.google.com/apis/credentials/consent)).
2. Select **User Type**:
   - Choose **External** (standard for testing with any Google account).
   - Click **Create**.
3. **App Information**:
   - **App name**: `Digital Health Record Exchange`
   - **User support email**: Select your email address.
   - **App logo**: Optional.
4. **App Domain**:
   - **Application home page**: `http://localhost/digital-health-record/`
5. **Developer Contact Information**:
   - Enter your email address.
6. Click **Save and Continue**.
7. **Scopes**:
   - Click **Add or Remove Scopes**.
   - Select the 3 minimal identity scopes:
     - `.../auth/userinfo.email` (`email`)
     - `.../auth/userinfo.profile` (`profile`)
     - `openid`
   - Click **Update**, then click **Save and Continue**.
8. **Test Users (Crucial for Testing in Development)**:
   - When the OAuth status is in "Testing" mode, only designated test users can log in.
   - Click **Add Users**.
   - Add every Gmail/Google Workspace email address you plan to test with (e.g., `pvannapurna4u@gmail.com`, your personal Gmail, etc.).
   - Click **Save and Continue**.
9. Review summary and return to dashboard.

---

## 3. Create OAuth 2.0 Web Client Credentials

1. Navigate to **APIs & Services** → **Credentials** ([click here](https://console.cloud.google.com/apis/credentials)).
2. Click **+ Create Credentials** at the top and select **OAuth client ID**.
3. Fill in the client configuration:
   - **Application type**: `Web application`
   - **Name**: `DEPA Health Exchange Web Client`
   - **Authorized JavaScript origins**:
     ```text
     http://localhost
     ```
   - **Authorized redirect URIs**:
     ```text
     http://localhost/digital-health-record/google_callback.php
     ```
     *(Note: If you access your local XAMPP using a port like `http://localhost:8080`, also add `http://localhost:8080/digital-health-record/google_callback.php`)*
4. Click **Create**.
5. A modal will appear showing:
   - **Your Client ID** (e.g. `xxxxxxxxxxxx-xxxxxxxxxxxxxxxx.apps.googleusercontent.com`)
   - **Your Client Secret** (e.g. `GOCSPX-xxxxxxxxxxxxxxxxxxxxxxxx`)
6. Copy both values securely.

---

## 4. Configuring Credentials in the Project

The application configuration file is located at:
```text
C:\xampp\htdocs\digital-health-record\config\google.php
```

A template file is provided at:
```text
C:\xampp\htdocs\digital-health-record\config\google.example.php
```

### Option A: Direct File Configuration (Simplest for XAMPP)

Open `C:\xampp\htdocs\digital-health-record\config\google.php` and paste your credentials:

```php
// 1. Google OAuth 2.0 Client ID (Web Application Client)
if (!defined('GOOGLE_CLIENT_ID')) {
    define('GOOGLE_CLIENT_ID', 'PASTE_YOUR_GOOGLE_CLIENT_ID_HERE');
}

// 2. Google OAuth 2.0 Client Secret (Server-side ONLY; NEVER exposed to JS/HTML)
if (!defined('GOOGLE_CLIENT_SECRET')) {
    define('GOOGLE_CLIENT_SECRET', 'PASTE_YOUR_GOOGLE_CLIENT_SECRET_HERE');
}

// 3. Authorized Redirect URI
if (!defined('GOOGLE_REDIRECT_URI')) {
    define('GOOGLE_REDIRECT_URI', 'http://localhost/digital-health-record/google_callback.php');
}
```

> **Security Rule**: `config/google.php` is protected from direct browser access. Never commit real secrets to public repositories.

### Option B: System Environment Variables

If you prefer environment variables, set them in your Windows environment or Apache virtual host configuration:
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI`

The code will automatically read them via `getenv()` if present.

---

## 5. Composer Dependencies

The project uses the official Google API Client (`google/apiclient` v2.20.0):
```bash
composer require google/apiclient
```
Dependencies and autoloading are configured in:
- `composer.json`
- `vendor/autoload.php`
- Loaded globally in `config/config.php`

---

## 6. Database Schema Changes

The migration script adds Google SSO fields to the master `users` table:
```sql
ALTER TABLE `users`
    ADD COLUMN `google_sub` VARCHAR(191) NULL DEFAULT NULL AFTER `ekyc_status`,
    ADD COLUMN `google_email` VARCHAR(191) NULL DEFAULT NULL AFTER `google_sub`,
    ADD COLUMN `google_picture` TEXT NULL DEFAULT NULL AFTER `google_email`,
    ADD COLUMN `google_linked_at` DATETIME NULL DEFAULT NULL AFTER `google_picture`,
    ADD UNIQUE KEY `uq_users_google_sub` (`google_sub`),
    ADD INDEX `idx_users_google_email` (`google_email`);
```

- Migration file: `database/migration_v7_google_sso.sql`
- Runner script: `scratch/run_migration_v7.php`

---

## 7. Testing Scenarios

Navigate to: `http://localhost/digital-health-record/login.php`

### Scenario A: Existing Admin Login via Google (e.g. `pvannapurna4u@gmail.com`)
1. Click **Continue with Google**.
2. Select your Google account (`pvannapurna4u@gmail.com`).
3. Because the email exists in the local database but `google_sub` is not yet linked, the system displays the **Link Google Account** confirmation page (`google_link_account.php`).
4. Enter the password for `pvannapurna4u@gmail.com` (default demo password: `appu2720` or your set password).
5. Click **Verify & Link Google Account**.
6. The Google account is permanently linked, the audit event `GOOGLE_ACCOUNT_LINKED` is logged, and you are redirected to the **Admin Dashboard** (`admin/dashboard.php`).
7. Next time you click **Continue with Google**, you are immediately signed in without password entry!

### Scenario B: Existing Patient / Doctor / Hospital Linking
1. If your Google account email matches an existing patient (e.g., `rahul.s92@gmail.com`), doctor, or hospital:
2. Click **Continue with Google**.
3. The confirmation page appears requesting the existing account password (`Patient@123`, `Doctor@123`, or `Hospital@123`).
4. Enter password and verify.
5. Successfully links and redirects to the respective dashboard:
   - Patient → `patient/dashboard.php`
   - Doctor → `doctor/dashboard.php`
   - Hospital → `hospital/dashboard.php`

### Scenario C: Brand New Google User Registration
1. Click **Continue with Google** and choose a Google account not registered in the system.
2. The system redirects to `google_register.php`.
3. Choose your role:
   - **Patient**: Enter Full Name, Date of Birth, Gender, Blood Group, Mobile Phone. (An ABHA ID like `ABHA-PAT-XXXX` is generated automatically).
   - **Doctor**: Enter Doctor Name, Specialization, Medical License Number, Phone. (A Doctor ID like `DOC-REG-XXXX` is generated).
   - **Hospital**: Enter Hospital Name, Facility License, Contact Person, Phone, Address.
4. *Admin role is strictly forbidden from self-registration.*
5. Click **Complete Account Registration**.
6. The new account is created, `google_sub` is linked, registry is synced, and you are signed directly into the role dashboard.

### Scenario D: User Cancels Google Sign-In
1. On the Google OAuth consent prompt, click **Cancel** or close the window.
2. You are safely redirected back to `login.php` with an informative banner: `"Google Sign-In was cancelled."`

---

## 8. Troubleshooting & Common Errors

### Error: `redirect_uri_mismatch`
- **Cause**: The redirect URI registered in Google Cloud Console does not match `http://localhost/digital-health-record/google_callback.php` byte-for-byte.
- **Fix**:
  1. Open Google Cloud Console → **APIs & Services** → **Credentials**.
  2. Edit your Web Client.
  3. Ensure **Authorized redirect URIs** contains exactly:
     ```text
     http://localhost/digital-health-record/google_callback.php
     ```
  4. Watch out for trailing slashes, `127.0.0.1` vs `localhost`, and HTTP vs HTTPS.

### Error: `Access blocked: This app has not been verified` / `Error 403: access_denied`
- **Cause**: While the Google Cloud app is in "Testing" mode, only users listed in "Test Users" can sign in.
- **Fix**:
  1. Go to Google Cloud Console → **APIs & Services** → **OAuth consent screen**.
  2. Under **Test users**, click **Add Users**.
  3. Add the email address of the account you are signing in with.

### Error: `Google Single Sign-On (SSO) is not yet configured`
- **Cause**: `GOOGLE_CLIENT_ID` or `GOOGLE_CLIENT_SECRET` in `config/google.php` contains the default placeholder text `YOUR_CLIENT_ID`.
- **Fix**: Insert your real Client ID and Secret in `config/google.php`.
