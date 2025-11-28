<?php
// Admin endpoint to update teacher records.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['admin']);
header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
$teacherId = trim($_POST['teacher_id'] ?? '');
$lastName = trim($_POST['last_name'] ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$module = trim($_POST['module'] ?? '');

if ($id <= 0 || $teacherId === '' || $lastName === '' || $firstName === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid teacher data.']);
    exit;
}

try {
    $pdo = get_db();
    $stmt = $pdo->prepare("
        UPDATE teachers
        SET teacher_id = :teacher_id,
            last_name = :last_name,
            first_name = :first_name,
            email = :email,
            module = :module
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':teacher_id' => $teacherId,
        ':last_name' => $lastName,
        ':first_name' => $firstName,
        ':email' => $email,
        ':module' => $module,
        ':id' => $id,
    ]);

    echo json_encode(['success' => true, 'message' => 'Teacher updated.']);
} catch (Throwable $e) {
    log_app_error('update_teacher error: ' . $e->getMessage());
    $msg = str_contains($e->getMessage(), 'Duplicate') ? 'Teacher ID already exists.' : 'Could not update teacher.';
    echo json_encode(['success' => false, 'message' => $msg]);
}
