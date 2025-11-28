<?php
// Added endpoint to create attendance sessions for teachers/admins.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['teacher', 'admin']);
header('Content-Type: application/json');

$user = current_user();
$courseId = trim($_POST['course_id'] ?? ''); // used as module
$groupId = trim($_POST['group_id'] ?? '');
$section = trim($_POST['section'] ?? '');
$date = trim($_POST['date'] ?? '');

$groupId = ($user['role'] === 'teacher' && !empty($user['group_id'])) ? $user['group_id'] : $groupId;
if ($user['role'] === 'teacher' && $groupId === '') {
    $groupId = $user['group_id'] ?? '';
}
$section = ($user['role'] === 'teacher' && !empty($user['section'])) ? $user['section'] : $section;

if ($date === '') {
    $date = date('Y-m-d');
}

try {
    $pdo = get_db();

    // Attempt to resolve teacher_id from teachers table using username/email/teacher_id.
    $teacherIdFk = null;
    if ($user['role'] === 'teacher') {
        $lookup = $pdo->prepare("
            SELECT id FROM teachers
            WHERE teacher_id = :tid OR email = :email
            ORDER BY id ASC LIMIT 1
        ");
        $lookup->execute([
            ':tid' => $user['username'],
            ':email' => $user['email'] ?? '',
        ]);
        $teacherIdFk = $lookup->fetchColumn() ?: null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO attendance_sessions (course_id, module, section, group_id, date, opened_by, teacher_id, status)
        VALUES (:course_id, :module, :section, :group_id, :date, :opened_by, :teacher_id, 'open')
    ");
    $stmt->execute([
        ':course_id' => $courseId,
        ':module' => $courseId,
        ':section' => $section,
        ':group_id' => $groupId,
        ':date' => $date,
        ':opened_by' => $user['id'],
        ':teacher_id' => $teacherIdFk,
    ]);

    echo json_encode(['success' => true, 'session_id' => (int) $pdo->lastInsertId()]);
} catch (Throwable $e) {
    log_app_error('create_session error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not create session.']);
}
