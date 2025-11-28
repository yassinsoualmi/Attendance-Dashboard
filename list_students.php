<?php
// Added JSON feed of students with attendance summary for dashboard usage.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_login();
$user = current_user();
$pdo = get_db();

header('Content-Type: application/json');

$groupFilter = null;
$studentFilter = null;

if ($user['role'] === 'teacher') {
    $groupFilter = $user['group_id'] ?? null;
} elseif ($user['role'] === 'student') {
    $student = resolve_student_row($pdo, $user);
    if ($student) {
        $studentFilter = (int) $student['id'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Student not found.']);
        exit;
    }
} else {
    $groupFilter = $_GET['group_id'] ?? null;
}

try {
    $data = fetch_student_summaries($pdo, $groupFilter, $studentFilter);
    echo json_encode(['success' => true, 'students' => $data]);
} catch (Throwable $e) {
    log_app_error('list_students error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load students.']);
}
