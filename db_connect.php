<?php
// Added reusable PDO connection helper with basic error logging and auto-bootstrap when DB is missing.
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $port = $config['port'] ?? 3306;
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $port,
        $config['dbname'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // If database is missing, attempt to create it and load schema.sql, then retry.
        if (stripos($e->getMessage(), 'Unknown database') !== false) {
            bootstrap_database($config);
            $pdo = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            log_app_error(sprintf("DB connection failed: %s", $e->getMessage()), $config);
            throw $e;
        }
    }

    // Apply lightweight schema upgrades to keep existing installs aligned.
    ensure_schema_upgrades($pdo);

    return $pdo;
}

/**
 * Create the database if missing and run schema.sql.
 */
function bootstrap_database(array $config): void
{
    $hostDsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $config['host'],
        $config['port'] ?? 3306,
        $config['charset']
    );
    $pdo = new PDO($hostDsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $dbName = $config['dbname'];
    $charset = $config['charset'];
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$charset}_general_ci");
    $pdo->exec("USE `{$dbName}`");

    $schemaFile = __DIR__ . '/schema.sql';
    if (is_readable($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        run_sql_batch($pdo, $sql);
    }
}

/**
 * Apply incremental schema upgrades for existing databases.
 */
function ensure_schema_upgrades(PDO $pdo): void
{
    // NOTE: Only students carry section + group. Add missing columns if upgrading an older DB.
    if (!column_exists($pdo, 'students', 'module')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN module VARCHAR(120) DEFAULT NULL");
    }
    if (!column_exists($pdo, 'students', 'section')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN section VARCHAR(50) DEFAULT NULL");
    }
    // Attendance sessions were expanded to capture module/section/teacher ownership in newer versions.
    if (!column_exists($pdo, 'attendance_sessions', 'module')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN module VARCHAR(120) DEFAULT NULL AFTER id");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'section')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN section VARCHAR(50) DEFAULT NULL AFTER module");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'course_id')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN course_id VARCHAR(50) DEFAULT NULL AFTER section");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'group_id')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN group_id VARCHAR(50) DEFAULT NULL AFTER course_id");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'opened_by')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN opened_by INT DEFAULT NULL AFTER date");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'teacher_id')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN teacher_id INT DEFAULT NULL AFTER opened_by");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'status')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN status ENUM('open', 'closed') DEFAULT 'open' AFTER teacher_id");
    }
    if (!column_exists($pdo, 'users', 'student_id')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN student_id VARCHAR(50) DEFAULT NULL AFTER email");
    }
    if (!column_exists($pdo, 'users', 'student_profile_id')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN student_profile_id INT DEFAULT NULL AFTER group_id");
    }
}

/**
 * Check whether a column exists on a table using information_schema for compatibility with older MySQL/MariaDB.
 */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
    ");
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Execute multiple SQL statements separated by semicolons.
 */
function run_sql_batch(PDO $pdo, string $sql): void
{
    // Execute statements while stripping line comments prefixed with "--".
    $lines = preg_split('/\r\n|\r|\n/', $sql);
    $buffer = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || strpos($trim, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr(rtrim($line), -1) === ';') {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
            $buffer = '';
        }
    }
    $tail = trim($buffer);
    if ($tail !== '') {
        $pdo->exec($tail);
    }
}

function log_app_error(string $message, ?array $configOverride = null): void
{
    $config = $configOverride ?? require __DIR__ . '/config.php';
    $line = sprintf("[%s] %s\n", date('c'), $message);
    if (!empty($config['error_log'])) {
        $dir = dirname($config['error_log']);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($config['error_log'], $line, FILE_APPEND);
    }
}
