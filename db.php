<?php
declare(strict_types=1);

/**
 * Database connection module (COMP016 Lab Activity 5).
 *
 * Include it with require_once 'db.php'; it exposes the $pdo handle.
 *
 * The defaults match Laragon (localhost, root, no password). Docker Compose
 * overrides them with environment variables, so no code change is needed.
 */

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Manila');

$host     = getenv('DB_HOST') ?: 'localhost';
$port     = getenv('DB_PORT') ?: '3306';
$dbname   = getenv('DB_NAME') ?: 'task_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            // Throw exceptions instead of failing silently.
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Use real server-side prepared statements, not emulated ones.
            PDO::ATTR_EMULATE_PREPARES   => false,
            // rowCount() reports rows matched, not only rows changed.
            PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]
    );

    // Make CURRENT_TIMESTAMP and the dates shown by PHP use the same zone.
    $pdo->exec("SET time_zone = '" . date('P') . "'");
} catch (PDOException $e) {
    // Log the detail for the developer; show visitors a generic message.
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection error. Check that MySQL is running and that the settings in db.php are correct.');
}
