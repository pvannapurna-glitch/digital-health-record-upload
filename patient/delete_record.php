<?php
/**
 * Delete Health Record Handler
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

require_once __DIR__ . '/../config/config.php';

require_role('patient');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('danger', 'Security verification failed.');
        header("Location: " . BASE_URL . "patient/my_records.php");
        exit;
    }

    $pdo = get_db_connection();
    $userId = get_logged_user_id();
    $patientId = get_patient_profile_id($pdo);
    $recordId = (int)($_POST['record_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM `health_records` WHERE `id` = ? AND `patient_id` = ?");
    $stmt->execute([$recordId, $patientId]);
    $record = $stmt->fetch();

    if ($record) {
        // Delete attachment file if present
        if (!empty($record['file_attachment']) && file_exists(UPLOAD_DIR . $record['file_attachment'])) {
            @unlink(UPLOAD_DIR . $record['file_attachment']);
        }

        // Delete from health_records
        $stmtDel = $pdo->prepare("DELETE FROM `health_records` WHERE `id` = ?");
        $stmtDel->execute([$recordId]);

        // Remove from Registry
        $stmtReg = $pdo->prepare("DELETE FROM `registry` WHERE `entity_type` = 'RECORD' AND `entity_uid` = ?");
        $stmtReg->execute([$record['record_uid']]);

        // Audit Log
        log_audit($pdo, 'RECORD_DELETED', "Deleted health record {$record['record_uid']} ({$record['title']})", $userId, 'patient');

        set_flash('success', "Health record {$record['record_uid']} permanently deleted.");
    } else {
        set_flash('danger', 'Record not found or unauthorized.');
    }
}

header("Location: " . BASE_URL . "patient/my_records.php");
exit;
