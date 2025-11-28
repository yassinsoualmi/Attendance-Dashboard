<?php
// Added endpoint to close a session.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['teacher', 'admin']);

$sessionId = (int) ($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
$isGet = $_SERVER['REQUEST_METHOD'] === 'GET';
$user = current_user();
if ($sessionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing session id.']);
    exit;
}

$redirectTarget = ($user['role'] ?? '') === 'teacher' ? 'teacher_dashboard.php' : 'admin_dashboard.php';

try {
    $pdo = get_db();
    $sessionStmt = $pdo->prepare("SELECT opened_by FROM attendance_sessions WHERE id = :id");
    $sessionStmt->execute([':id' => $sessionId]);
    $row = $sessionStmt->fetch();
    if (!$row) {
        throw new RuntimeException('Session not found');
    }
    $user = current_user();
    if ($user['role'] === 'teacher' && (int)$row['opened_by'] !== (int)$user['id']) {
        throw new RuntimeException('Unauthorized');
    }
    $stmt = $pdo->prepare("UPDATE attendance_sessions SET status = 'closed' WHERE id = :id");
    $stmt->execute([':id' => $sessionId]);
    if ($isGet) {
        header("Location: {$redirectTarget}");
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Session closed.']);
} catch (Throwable $e) {
    log_app_error('close_session error: ' . $e->getMessage());
    if ($isGet) {
        header("Location: {$redirectTarget}");
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Could not close session.']);
}
