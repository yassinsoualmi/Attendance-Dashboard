<?php
// Admin endpoint to delete a teacher.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['admin']);
header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing teacher id.']);
    exit;
}

try {
    $pdo = get_db();
    $stmt = $pdo->prepare("DELETE FROM teachers WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    echo json_encode(['success' => true, 'message' => 'Teacher removed.']);
} catch (Throwable $e) {
    log_app_error('delete_teacher error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not delete teacher.']);
}
