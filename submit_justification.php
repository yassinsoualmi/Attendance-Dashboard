<?php
// Added placeholder handler for absence justifications.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';

require_role(['student']);
$user = current_user();

$studentId = (int) ($_POST['student_id'] ?? 0);
$sessionId = (int) ($_POST['session_id'] ?? 0);
$reason = trim($_POST['reason'] ?? '');
$filePath = null;
$message = '';

try {
    $pdo = get_db();

    // Ensure the student_id matches the logged-in student profile.
    $stmt = $pdo->prepare("SELECT id FROM students WHERE (student_id = :sid OR email = :email) LIMIT 1");
    $stmt->execute([':sid' => $user['username'], ':email' => $user['email']]);
    $studentRow = $stmt->fetch();
    if (!$studentRow) {
        throw new RuntimeException('Student record not found.');
    }
    $realStudentId = (int) $studentRow['id'];
    if ($studentId !== $realStudentId) {
        $studentId = $realStudentId;
    }

    if (!empty($_FILES['justification_file']['name'])) {
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0775, true);
        }
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['justification_file']['name']));
        $target = $uploadsDir . '/' . time() . '_' . $safeName;
        if (move_uploaded_file($_FILES['justification_file']['tmp_name'], $target)) {
            $filePath = 'uploads/' . basename($target);
        }
    }

    $insert = $pdo->prepare("
        INSERT INTO justifications (student_id, session_id, reason, file_path, status)
        VALUES (:student_id, :session_id, :reason, :file_path, 'pending')
    ");
    $insert->execute([
        ':student_id' => $studentId,
        ':session_id' => $sessionId ?: null,
        ':reason' => $reason,
        ':file_path' => $filePath,
    ]);

    $message = 'Justification submitted.';
} catch (Throwable $e) {
    log_app_error('justification error: ' . $e->getMessage());
    $message = 'Could not submit justification.';
}

header('Location: student_dashboard.php');
exit;
