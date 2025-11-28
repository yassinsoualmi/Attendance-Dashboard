<?php
// Added feed for sessions to refresh teacher dashboard list.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['teacher', 'admin']);
$user = current_user();
$pdo = get_db();

header('Content-Type: application/json');

try {
    $params = [];
    $where = [];
    if ($user['role'] === 'teacher') {
        $where[] = 'opened_by = :opened_by';
        $params[':opened_by'] = $user['id'];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT id, course_id, module, section, group_id, date, status
        FROM attendance_sessions
        {$whereSql}
        ORDER BY date DESC, id DESC
        LIMIT 20
    ");
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();
    echo json_encode(['success' => true, 'sessions' => $sessions]);
} catch (Throwable $e) {
    log_app_error('list_sessions error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not load sessions.']);
}
