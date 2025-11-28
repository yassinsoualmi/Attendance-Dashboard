<?php
// Added admin endpoint to create students via AJAX.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';

require_role(['admin']);
header('Content-Type: application/json');

$studentId = trim($_POST['student_id'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$group = trim($_POST['group_id'] ?? '');
$module = trim($_POST['module'] ?? '');
$section = trim($_POST['section'] ?? '');

if ($studentId === '' || $lastName === '' || $firstName === '') {
    echo json_encode(['success' => false, 'message' => 'Required fields are missing.']);
    exit;
}

try {
    $pdo = get_db();
    $stmt = $pdo->prepare("
        INSERT INTO students (student_id, last_name, first_name, email, module, section, group_id)
        VALUES (:student_id, :last_name, :first_name, :email, :module, :section, :group_id)
    ");
    $stmt->execute([
        ':student_id' => $studentId,
        ':last_name' => $lastName,
        ':first_name' => $firstName,
        ':email' => $email,
        ':module' => $module,
        ':section' => $section,
        ':group_id' => $group,
    ]);

    $newStudentId = (int) $pdo->lastInsertId();

    // Auto-create linked user account for this student: username = student_id, password = lowercase first name.
    ensure_student_user_account($pdo, [
        'id' => $newStudentId,
        'student_id' => $studentId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'group_id' => $group,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Student added.',
        'id' => $newStudentId,
    ]);
} catch (PDOException $e) {
    log_app_error('add_student failed: ' . $e->getMessage());
    $msg = 'Could not add student.';
    if (str_contains($e->getMessage(), 'Duplicate')) {
        $msg = 'Student ID already exists.';
    }
    echo json_encode(['success' => false, 'message' => $msg]);
}
