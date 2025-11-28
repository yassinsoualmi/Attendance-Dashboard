<?php
// Added admin endpoint to remove a student.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['admin']);
header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing student id.']);
    exit;
}

try {
    $pdo = get_db();
    $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    echo json_encode(['success' => true, 'message' => 'Student removed.']);
} catch (Throwable $e) {
    log_app_error('delete_student error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not delete student.']);
}
