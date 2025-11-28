<?php
// Added admin endpoint to update student details.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';

require_role(['admin']);
header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
$studentId = trim($_POST['student_id'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$group = trim($_POST['group_id'] ?? '');
$module = trim($_POST['module'] ?? '');
$section = trim($_POST['section'] ?? '');

if ($id <= 0 || $studentId === '' || $lastName === '' || $firstName === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid data.']);
    exit;
}

try {
    $pdo = get_db();
    $stmt = $pdo->prepare("
        UPDATE students
        SET student_id = :student_id,
            last_name = :last_name,
            first_name = :first_name,
            email = :email,
            module = :module,
            section = :section,
            group_id = :group_id
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':student_id' => $studentId,
        ':last_name' => $lastName,
        ':first_name' => $firstName,
        ':email' => $email,
        ':module' => $module,
        ':section' => $section,
        ':group_id' => $group,
        ':id' => $id,
    ]);

    // Keep linked user account in sync with the student row.
    ensure_student_user_account($pdo, [
        'id' => $id,
        'student_id' => $studentId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'group_id' => $group,
    ]);

    echo json_encode(['success' => true, 'message' => 'Student updated.']);
} catch (PDOException $e) {
    log_app_error('update_student failed: ' . $e->getMessage());
    $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Student ID already exists.' : 'Could not update student.';
    echo json_encode(['success' => false, 'message' => $msg]);
}
