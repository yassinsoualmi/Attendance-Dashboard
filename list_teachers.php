<?php
// Admin endpoint to list teachers for management UI.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['admin']);
header('Content-Type: application/json');

try {
    $pdo = get_db();
    $stmt = $pdo->query("
        SELECT id, teacher_id, last_name, first_name, email, module, created_at
        FROM teachers
        ORDER BY last_name ASC, first_name ASC
    ");
    $rows = $stmt->fetchAll();
    echo json_encode(['success' => true, 'teachers' => $rows]);
} catch (Throwable $e) {
    log_app_error('list_teachers error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load teachers.']);
}
