<?php
/**
 * Database Connection Wrapper using PDO
 * Project: Digital Health Record Exchange using DEPA Consent, Registry and Modules
 */

function get_db_connection() {
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
    ];

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $pdo;
    } catch (PDOException $e) {
        // If database does not exist yet or connection fails, show graceful error guide
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Database Setup Required - <?= htmlspecialchars(APP_NAME) ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light py-5">
            <div class="container">
                <div class="row justify-content-center">
                    <div class="col-lg-8">
                        <div class="card shadow-lg border-danger">
                            <div class="card-header bg-danger text-white py-3">
                                <h4 class="mb-0 fw-bold">Database Connection Error</h4>
                            </div>
                            <div class="card-body p-4">
                                <div class="alert alert-warning">
                                    <strong>MySQL Message:</strong> <?= htmlspecialchars($e->getMessage()) ?>
                                </div>
                                <h5 class="fw-semibold mt-3">Quick Setup Instructions for XAMPP:</h5>
                                <ol class="list-group list-group-numbered mb-4">
                                    <li class="list-group-item">Ensure <strong>MySQL</strong> is started in the <strong>XAMPP Control Panel</strong>.</li>
                                    <li class="list-group-item">Open <a href="http://localhost/phpmyadmin/" target="_blank" class="fw-bold">phpMyAdmin (http://localhost/phpmyadmin)</a>.</li>
                                    <li class="list-group-item">Click <strong>Import</strong> tab at the top.</li>
                                    <li class="list-group-item">Choose file: <code><?= htmlspecialchars(ROOT_DIR . 'database/database.sql') ?></code> and click <strong>Import</strong>.</li>
                                    <li class="list-group-item">Once imported, reload this page.</li>
                                </ol>
                                <div class="text-end">
                                    <a href="" class="btn btn-primary px-4 fw-semibold">Reload Application</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
