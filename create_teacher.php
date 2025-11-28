<?php
// Admin endpoint to create teachers (and optionally a linked user account).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['admin']);
header('Content-Type: application/json');

$teacherId = trim($_POST['teacher_id'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$module = trim($_POST['module'] ?? '');
$createUser = isset($_POST['create_user']) ? (bool) $_POST['create_user'] : false;
$tempPassword = trim($_POST['temp_password'] ?? 'teacher123');

if ($teacherId === '' || $lastName === '' || $firstName === '') {
    echo json_encode(['success' => false, 'message' => 'Teacher ID, last name, and first name are required.']);
    exit;
}

try {
    $pdo = get_db();
    $pdo->beginTransaction();

    $insertTeacher = $pdo->prepare("
        INSERT INTO teachers (teacher_id, last_name, first_name, email, module)
        VALUES (:teacher_id, :last_name, :first_name, :email, :module)
    ");
    $insertTeacher->execute([
        ':teacher_id' => $teacherId,
        ':last_name' => $lastName,
        ':first_name' => $firstName,
        ':email' => $email,
        ':module' => $module,
    ]);

    $teacherDbId = (int) $pdo->lastInsertId();

    if ($createUser) {
        // If username is empty, fall back to teacher_id.
        $username = $email !== '' ? $email : $teacherId;
        $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $insertUser = $pdo->prepare("
            INSERT INTO users (username, password_hash, role, full_name, email, course_id)
            VALUES (:username, :password_hash, 'teacher', :full_name, :email, :course_id)
        ");
        $insertUser->execute([
            ':username' => $username,
            ':password_hash' => $passwordHash,
            ':full_name' => trim($firstName . ' ' . $lastName),
            ':email' => $email,
            ':course_id' => $module,
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $teacherDbId]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_app_error('create_teacher error: ' . $e->getMessage());
    $msg = 'Could not add teacher.';
    if (str_contains($e->getMessage(), 'Duplicate')) {
        $msg = 'Teacher ID or username already exists.';
    }
    echo json_encode(['success' => false, 'message' => $msg]);
}
