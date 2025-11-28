<?php
// Added teacher view to take or edit attendance for a specific session.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_role(['teacher', 'admin']);
$user = current_user();
$pdo = get_db();

$sessionId = (int) ($_GET['session_id'] ?? $_POST['session_id'] ?? 0);
$error = '';
$success = '';
$session = null;
$students = [];
$records = [];

if ($sessionId <= 0) {
    $error = 'No session selected.';
} else {
    $stmt = $pdo->prepare("SELECT * FROM attendance_sessions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();
    if (!$session) {
        $error = 'Session not found.';
    } elseif ($user['role'] === 'teacher' && (int) $session['opened_by'] !== (int) $user['id']) {
        // Teachers can only access their own sessions.
        $error = 'You cannot edit this session.';
    }
}

if ($session && !$error) {
    $groupFilter = $session['group_id'] ?? null;
    $sectionFilter = $session['section'] ?? null;
    $moduleFilter = $session['module'] ?? ($session['course_id'] ?? null);
    $studentSql = "SELECT * FROM students";
    $params = [];
    $clauses = [];
    if ($groupFilter) {
        $clauses[] = "group_id = :group";
        $params[':group'] = $groupFilter;
    }
    if ($sectionFilter) {
        $clauses[] = "section = :section";
        $params[':section'] = $sectionFilter;
    }
    if ($moduleFilter) {
        $clauses[] = "module = :module";
        $params[':module'] = $moduleFilter;
    }
    if ($clauses) {
        $studentSql .= " WHERE " . implode(' AND ', $clauses);
    }
    $studentSql .= " ORDER BY last_name ASC, first_name ASC";
    $studentStmt = $pdo->prepare($studentSql);
    $studentStmt->execute($params);
    $students = $studentStmt->fetchAll();

    $recStmt = $pdo->prepare("SELECT * FROM attendance_records WHERE session_id = :sid");
    $recStmt->execute([':sid' => $sessionId]);
    foreach ($recStmt->fetchAll() as $rec) {
        $records[$rec['student_id']] = $rec;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error && $session) {
    $studentIds = $_POST['student_ids'] ?? [];
    $pdo->beginTransaction();
    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO attendance_records (session_id, student_id, status, participated)
            VALUES (:session_id, :student_id, :status, :participated)
            ON DUPLICATE KEY UPDATE status = VALUES(status), participated = VALUES(participated)
        ");

        foreach ($studentIds as $sid) {
            $sid = (int) $sid;
            $status = $_POST["status_{$sid}"] ?? 'absent';
            $participated = isset($_POST["participated_{$sid}"]) ? 1 : 0;
            $insertStmt->execute([
                ':session_id' => $sessionId,
                ':student_id' => $sid,
                ':status' => $status === 'present' ? 'present' : 'absent',
                ':participated' => $participated,
            ]);
        }
        $pdo->commit();
        $success = 'Attendance saved successfully.';
    } catch (Throwable $e) {
        $pdo->rollBack();
        log_app_error('take_attendance save error: ' . $e->getMessage());
        $error = 'Could not save attendance.';
    }

    // refresh records after save
    $recStmt = $pdo->prepare("SELECT * FROM attendance_records WHERE session_id = :sid");
    $recStmt->execute([':sid' => $sessionId]);
    $records = [];
    foreach ($recStmt->fetchAll() as $rec) {
        $records[$rec['student_id']] = $rec;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Take Attendance</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #f4f6fb;
      --card: #ffffff;
      --primary: #3c6ff0;
      --primary-dark: #345ed0;
      --accent: #21bca5;
      --text: #222b45;
      --muted: #6b7280;
      --border: #e4e7ef;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif;
      background: linear-gradient(135deg, #eef2ff 0%, #f7f9fc 100%);
      color: var(--text);
      min-height: 100vh;
      padding: 32px 12px 48px;
    }
    .shell { max-width: 960px; margin: 0 auto; }
    .panel {
      background: var(--card);
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 12px 35px rgba(32, 56, 117, 0.08);
      margin-bottom: 24px;
    }
    h1 { margin: 0 0 12px; }
    .muted { color: var(--muted); }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 10px 8px; border-bottom: 1px solid #eef0fb; text-align: center; }
    .btn-primary {
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 10px 16px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 10px 18px rgba(60, 111, 240, 0.25);
    }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-link { color: var(--primary); text-decoration: none; font-weight: 600; }
    .status { min-height: 18px; color: #e45858; margin: 8px 0; }
    .status.success { color: #089e7d; }
    label { font-weight: 600; color: var(--muted); }
  </style>
</head>
<body>
  <div class="shell">
    <div class="panel">
      <a class="btn-link" href="<?php echo $user['role'] === 'teacher' ? 'teacher_dashboard.php' : 'admin_dashboard.php'; ?>">&#8592; Back to dashboard</a>
      <h1>Take Attendance</h1>
      <?php if ($session): ?>
        <p class="muted">
          Session #<?php echo (int) $session['id']; ?>
          <?php if (!empty($session['module'])): ?> · Module <?php echo htmlspecialchars($session['module']); ?><?php endif; ?>
          <?php if (!empty($session['section'])): ?> · Section <?php echo htmlspecialchars($session['section']); ?><?php endif; ?>
          <?php if (!empty($session['group_id'])): ?> · Group <?php echo htmlspecialchars($session['group_id']); ?><?php endif; ?>
          · <?php echo htmlspecialchars($session['date']); ?>
        </p>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="status"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="status success"><?php echo htmlspecialchars($success); ?></div>
      <?php endif; ?>
      <?php if ($session && !$error): ?>
        <form method="POST" action="take_attendance.php">
          <input type="hidden" name="session_id" value="<?php echo (int) $sessionId; ?>">
          <table>
            <thead>
              <tr>
                <th>Last Name</th>
                <th>First Name</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Participated</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $stu): ?>
                <?php
                  $sid = (int) $stu['id'];
                  $rec = $records[$sid] ?? null;
                  $isPresent = !$rec || $rec['status'] === 'present';
                  $participated = $rec ? (int)$rec['participated'] === 1 : 0;
                ?>
                <tr>
                  <td><?php echo htmlspecialchars($stu['last_name']); ?></td>
                  <td><?php echo htmlspecialchars($stu['first_name']); ?></td>
                  <td>
                    <input type="hidden" name="student_ids[]" value="<?php echo $sid; ?>">
                    <input type="radio" name="status_<?php echo $sid; ?>" value="present" <?php echo $isPresent ? 'checked' : ''; ?>>
                  </td>
                  <td>
                    <input type="radio" name="status_<?php echo $sid; ?>" value="absent" <?php echo !$isPresent ? 'checked' : ''; ?>>
                  </td>
                  <td>
                    <label>
                      <input type="checkbox" name="participated_<?php echo $sid; ?>" value="1" <?php echo $participated ? 'checked' : ''; ?>>
                    </label>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="margin-top:16px; display:flex; gap:10px; align-items:center;">
            <button type="submit" class="btn-primary">Save Attendance</button>
            <a class="btn-link" href="close_session.php?session_id=<?php echo (int) $sessionId; ?>">Close session</a>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
