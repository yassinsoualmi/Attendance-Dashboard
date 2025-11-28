<?php
// Student dashboard: read-only personal view.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_role(['student']);
$user = current_user();
$pdo = get_db();

// Find the student profile linked to this session (by student_id stored in session).
$studentRow = null;
$sessionStudentId = $_SESSION['student_id'] ?? null;
if ($sessionStudentId) {
    $studentStmt = $pdo->prepare("SELECT * FROM students WHERE student_id = :sid LIMIT 1");
    $studentStmt->execute([':sid' => $sessionStudentId]);
    $studentRow = $studentStmt->fetch();
} elseif (!empty($_SESSION['student_profile_id'])) {
    $studentStmt = $pdo->prepare("SELECT * FROM students WHERE id = :id LIMIT 1");
    $studentStmt->execute([':id' => (int) $_SESSION['student_profile_id']]);
    $studentRow = $studentStmt->fetch();
}

$summary = null;
$records = [];
$error = '';
$totalSessions = 0;
$totalPresent = 0;
$totalAbsent = 0;
$totalParticipation = 0;
$finalMessage = '';
$studentName = $user['full_name'] ?? $user['username'];

if ($studentRow) {
    // Keep session values in sync with the resolved student profile.
    $_SESSION['student_id'] = $studentRow['student_id'];
    $_SESSION['student_profile_id'] = (int) $studentRow['id'];
    $_SESSION['group_id'] = $studentRow['group_id'] ?? $_SESSION['group_id'] ?? null;
    $_SESSION['first_name'] = $studentRow['first_name'] ?? $_SESSION['first_name'] ?? null;
    $_SESSION['last_name'] = $studentRow['last_name'] ?? $_SESSION['last_name'] ?? null;

    $summaryList = fetch_student_summaries($pdo, null, (int) $studentRow['id']);
    $summary = $summaryList[0] ?? null;
    $studentName = trim(($studentRow['first_name'] ?? '') . ' ' . ($studentRow['last_name'] ?? '')) ?: $studentName;

    $recStmt = $pdo->prepare("
        SELECT sess.id AS session_id, sess.course_id, sess.group_id, sess.date, ar.status, ar.participated
        FROM attendance_records ar
        INNER JOIN attendance_sessions sess ON sess.id = ar.session_id
        WHERE ar.student_id = :sid
        ORDER BY sess.date DESC, sess.id DESC
    ");
    $recStmt->execute([':sid' => $studentRow['id']]);
    $records = $recStmt->fetchAll();

    foreach ($records as $rec) {
        $totalSessions++;
        $status = strtolower($rec['status'] ?? '');
        if ($status === 'present') {
            $totalPresent++;
        } else {
            $totalAbsent++;
        }
        if ((int) ($rec['participated'] ?? 0) === 1) {
            $totalParticipation++;
        }
    }

    if ($summary) {
        $finalMessage = $summary['status']['message'] ?? '';
    }
} else {
    $error = 'Student profile not found. Please contact the administrator.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Student Dashboard</title>
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
      padding: 40px 16px 60px;
    }
    .app-shell { max-width: 960px; margin: 0 auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
    .user-chip {
      display: inline-flex; align-items: center; gap: 10px;
      background: #f7f8fc; border: 1px solid #eef0fb;
      padding: 8px 12px; border-radius: 12px;
      font-weight: 600; color: var(--text);
    }
    .user-chip small { color: var(--muted); }
    .logout-link { color: var(--primary); font-weight: 600; text-decoration: none; }
    .logout-link:hover { text-decoration: underline; }
    .page-header { text-align: center; margin-bottom: 20px; }
    .header-icon {
      width: 70px; height: 70px; margin: 0 auto 12px;
      border-radius: 20px;
      background: linear-gradient(145deg, #3c6ff0, #5c8ff9);
      display: flex; align-items: center; justify-content: center;
      color: #fff; box-shadow: 0 12px 28px rgba(60, 111, 240, 0.35);
    }
    .header-icon svg { width: 34px; height: 34px; }
    .page-header h1 { margin: 0; font-size: 1.9rem; }
    .page-header p { margin: 6px 0 0; color: var(--muted); }
    .panel {
      background: var(--card);
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 12px 35px rgba(32, 56, 117, 0.08);
      margin-bottom: 24px;
    }
    .panel.soft {
      background: linear-gradient(135deg, #f9fbff 0%, #f3f6ff 100%);
      border: 1px solid #e6ecfb;
    }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 18px;
    }
    .stat-card {
      border-radius: 14px;
      padding: 18px;
      background: #f9fbff;
      border: 1px solid #eef0fb;
      min-height: 94px;
    }
    .stat-card .label { font-size: 0.85rem; color: var(--muted); margin-bottom: 6px; }
    .stat-card .value { font-size: 1.6rem; font-weight: 600; color: var(--text); }
    .stat-card .value.small { font-size: 1rem; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px 10px; text-align: center; border-bottom: 1px solid #eef0fb; }
    thead th {
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      background: #f7f8fc;
      color: var(--muted);
    }
    .error { color: #e45858; font-weight: 600; }
    .muted { color: var(--muted); }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px 18px; }
    .justification-grid { grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    input[type="text"], input[type="file"], textarea {
      width: 100%;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 1rem;
    }
    input[type="text"]:focus, textarea:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(60, 111, 240, 0.18);
    }
    textarea { min-height: 100px; }
    button {
      border: none;
      border-radius: 12px;
      padding: 12px 18px;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      background: var(--primary);
      color: #fff;
      box-shadow: 0 10px 18px rgba(60, 111, 240, 0.25);
    }
    .upload-shell {
      position: relative;
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 14px;
      border: 1px dashed #d3dbf5;
      border-radius: 12px;
      background: #f5f7ff;
      color: var(--muted);
      min-height: 52px;
    }
    .upload-shell input[type="file"] {
      position: absolute;
      inset: 0;
      opacity: 0;
      cursor: pointer;
    }
    .upload-shell .upload-label {
      font-weight: 600;
      color: var(--primary);
      background: #e8edff;
      padding: 8px 12px;
      border-radius: 10px;
      border: 1px solid #d6e0ff;
    }
    .justification-actions {
      margin-top: 14px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border-radius: 999px;
      background: #e8f6ff;
      color: #0d6efd;
      font-weight: 600;
      font-size: 0.85rem;
    }
    .justification-header {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 6px;
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <div class="topbar">
      <div class="user-chip">
        <span><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
        <small>student</small>
      </div>
      <a class="logout-link" href="settings.php" style="margin-right:8px;">Settings</a>
      <a class="logout-link" href="logout.php">Logout</a>
    </div>
    <header class="page-header">
      <div class="header-icon" aria-hidden="true">
        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M20 32.5 12 24.4l3.2-3.2 4.8 4.8 12.8-12.8 3.2 3.2L20 32.5Z" fill="currentColor"/>
        </svg>
      </div>
      <h1>Hello, <?php echo htmlspecialchars($studentName); ?></h1>
      <p>Here is your personal attendance summary.</p>
    </header>

    <section class="panel">
      <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
      <?php endif; ?>
      <?php if ($studentRow): ?>
        <p class="muted">
          Student ID: <?php echo htmlspecialchars($studentRow['student_id']); ?>
          <?php if (!empty($studentRow['group_id'])): ?> · Group: <?php echo htmlspecialchars($studentRow['group_id']); ?><?php endif; ?>
          <?php if (!empty($studentRow['section'])): ?> · Section: <?php echo htmlspecialchars($studentRow['section']); ?><?php endif; ?>
          <?php if (!empty($studentRow['module'])): ?> · Module: <?php echo htmlspecialchars($studentRow['module']); ?><?php endif; ?>
          <?php if (!empty($studentRow['email'])): ?> · Email: <?php echo htmlspecialchars($studentRow['email']); ?><?php endif; ?>
        </p>
      <?php endif; ?>
      <div class="stat-grid">
        <div class="stat-card">
          <p class="label">Total Sessions</p>
          <p class="value"><?php echo (int) $totalSessions; ?></p>
        </div>
        <div class="stat-card">
          <p class="label">Present</p>
          <p class="value"><?php echo (int) $totalPresent; ?></p>
        </div>
        <div class="stat-card">
          <p class="label">Absent</p>
          <p class="value"><?php echo (int) $totalAbsent; ?></p>
        </div>
        <div class="stat-card">
          <p class="label">Participations</p>
          <p class="value"><?php echo (int) $totalParticipation; ?></p>
        </div>
        <div class="stat-card">
          <p class="label">Message</p>
          <p class="value small"><?php echo htmlspecialchars($finalMessage ?: 'No records yet.'); ?></p>
        </div>
      </div>
    </section>

    <section class="panel">
      <h2>Your Sessions</h2>
      <p class="muted">All fields are read-only. Contact your teacher if something looks wrong.</p>
      <table>
        <thead>
          <tr>
            <th>Date</th><th>Course</th><th>Group</th><th>Present</th><th>Participated</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $rec): ?>
            <tr>
              <td><?php echo htmlspecialchars($rec['date']); ?></td>
              <td><?php echo htmlspecialchars($rec['course_id']); ?></td>
              <td><?php echo htmlspecialchars($rec['group_id']); ?></td>
              <td><?php echo strtolower($rec['status']) === 'present' ? 'Present' : 'Absent'; ?></td>
              <td><?php echo ((int) $rec['participated'] === 1) ? 'Yes' : 'No'; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$records): ?>
            <tr><td colspan="5">No sessions recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="panel soft">
      <div class="justification-header">
        <h2 style="margin:0;">Absence Justification</h2>
        <span class="pill">Secure upload</span>
      </div>
      <p class="muted">Submit a justification if you missed a session. Your teacher or admin will review it.</p>
      <form action="submit_justification.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="student_id" value="<?php echo $studentRow ? (int) $studentRow['id'] : 0; ?>">
        <div class="form-grid justification-grid">
          <div>
            <label for="sessionRef">Session ID</label>
            <input type="text" id="sessionRef" name="session_id" placeholder="Ex: 12">
          </div>
          <div>
            <label for="reason">Reason</label>
            <textarea id="reason" name="reason" placeholder="Describe the reason"></textarea>
          </div>
          <div>
            <label for="justFile">Upload file (optional)</label>
            <div class="upload-shell">
              <span class="upload-label">Choose file</span>
              <span class="muted" aria-live="polite">Attach PDF/JPG/PNG</span>
              <input type="file" id="justFile" name="justification_file" aria-label="Upload justification file">
            </div>
          </div>
        </div>
        <div class="justification-actions">
          <button type="submit">Submit justification</button>
        </div>
      </form>
    </section>
  </div>
</body>
</html>
