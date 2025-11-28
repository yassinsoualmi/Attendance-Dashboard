<?php
// Teacher dashboard: limited view to mark presence and participation only.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_role(['teacher']);
$user = current_user();
$pdo = get_db();

$groupFilter = null;
$sessions = [];
$selectedSessionId = isset($_POST['session_id']) ? (int) $_POST['session_id'] : (int) ($_GET['session_id'] ?? 0);
$selectedSession = null;
$sessionRecords = [];
$students = [];
$sessionMessage = '';
$saveMessage = '';
$error = '';
$action = $_POST['action'] ?? '';
$teacherRow = null;
$summaryRows = [];

try {
    // Ensure expected columns exist to avoid runtime SQL errors.
    if (!column_exists($pdo, 'students', 'module')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN module VARCHAR(120) DEFAULT NULL");
    }
    if (!column_exists($pdo, 'students', 'section')) {
        $pdo->exec("ALTER TABLE students ADD COLUMN section VARCHAR(50) DEFAULT NULL");
    }
    // Ensure attendance_sessions has the fields referenced below.
    if (!column_exists($pdo, 'attendance_sessions', 'teacher_id')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN teacher_id INT DEFAULT NULL AFTER opened_by");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'module')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN module VARCHAR(120) DEFAULT NULL AFTER course_id");
    }
    if (!column_exists($pdo, 'attendance_sessions', 'section')) {
        $pdo->exec("ALTER TABLE attendance_sessions ADD COLUMN section VARCHAR(50) DEFAULT NULL AFTER module");
    }
    // Locate teacher profile by username/ email against teacher_id or email.
    $lookupValue = $user['username'] ?? ($user['email'] ?? '');
    $teacherStmt = $pdo->prepare("
        SELECT * FROM teachers
        WHERE teacher_id = :v OR email = :v
        ORDER BY id ASC
        LIMIT 1
    ");
    $teacherStmt->execute([':v' => $lookupValue]);
    $teacherRow = $teacherStmt->fetch();

    if (!$teacherRow) {
        $error = 'Teacher profile not found. Ask an administrator to link your account.';
        throw new RuntimeException($error);
    }

    $defaultModule = $teacherRow['module'] ?? ($user['course_id'] ?? '');

    // Create a session for this teacher.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_session') {
        $courseId = trim($_POST['course_id'] ?? $defaultModule);
        $sectionVal = trim($_POST['section'] ?? '');
        $groupId = trim($_POST['group_id'] ?? '');
        $date = trim($_POST['date'] ?? date('Y-m-d'));
        if ($date === '') {
            $date = date('Y-m-d');
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO attendance_sessions (course_id, module, section, group_id, date, opened_by, teacher_id, status)
            VALUES (:course_id, :module, :section, :group_id, :date, :opened_by, :teacher_id, 'open')
        ");
        $insertStmt->execute([
            ':course_id' => $courseId,
            ':module' => $courseId,
            ':section' => $sectionVal,
            ':group_id' => $groupId,
            ':date' => $date,
            ':opened_by' => $user['id'],
            ':teacher_id' => (int) $teacherRow['id'],
        ]);
        $selectedSessionId = (int) $pdo->lastInsertId();
        $sessionMessage = 'Session created successfully.';
    }

    // Load sessions for this teacher.
    $sessionStmt = $pdo->prepare("
        SELECT id, course_id, module, section, group_id, date, status
        FROM attendance_sessions
        WHERE (opened_by = :uid OR teacher_id = :tid OR opened_by = 0)
        ORDER BY date DESC, id DESC
    ");
    $sessionStmt->execute([':uid' => $user['id'], ':tid' => (int) $teacherRow['id']]);
    $sessions = $sessionStmt->fetchAll();

    if ($selectedSessionId === 0 && $sessions) {
        $selectedSessionId = (int) $sessions[0]['id'];
    }
    foreach ($sessions as $sess) {
        if ((int) $sess['id'] === (int) $selectedSessionId) {
            $selectedSession = $sess;
            break;
        }
    }

    // Save attendance for the selected session.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_attendance') {
        if (!$selectedSession) {
            $error = 'Please choose a session before saving attendance.';
        } else {
            $studentSql = "SELECT id FROM students";
            $params = [];
            $clauses = [];
            $sessionGroup = $selectedSession['group_id'] ?? $groupFilter;
            $sessionSection = $selectedSession['section'] ?? null;
            $sessionModule = $selectedSession['module'] ?? $selectedSession['course_id'] ?? null;
            if ($sessionGroup) {
                $clauses[] = "group_id = :gid";
                $params[':gid'] = $sessionGroup;
            }
            if ($sessionSection) {
                $clauses[] = "section = :section";
                $params[':section'] = $sessionSection;
            }
            if ($sessionModule) {
                $clauses[] = "module = :module";
                $params[':module'] = $sessionModule;
            }
            if ($clauses) {
                $studentSql .= " WHERE " . implode(' AND ', $clauses);
            }
            $studentStmt = $pdo->prepare($studentSql);
            $studentStmt->execute($params);
            $allowedIds = array_map('intval', $studentStmt->fetchAll(PDO::FETCH_COLUMN));

            $studentIds = array_map('intval', $_POST['student_ids'] ?? []);
            $saveCount = 0;

            $pdo->beginTransaction();
            try {
                $insertStmt = $pdo->prepare("
                    INSERT INTO attendance_records (session_id, student_id, status, participated)
                    VALUES (:session_id, :student_id, :status, :participated)
                    ON DUPLICATE KEY UPDATE status = VALUES(status), participated = VALUES(participated)
                ");
                foreach ($studentIds as $sid) {
                    if (!in_array($sid, $allowedIds, true)) {
                        continue;
                    }
                    $statusInput = $_POST['status'][$sid] ?? 'absent';
                    $statusValue = $statusInput === 'present' ? 'present' : 'absent';
                    $participated = isset($_POST['participated'][$sid]) ? 1 : 0;
                    $insertStmt->execute([
                        ':session_id' => $selectedSessionId,
                        ':student_id' => $sid,
                        ':status' => $statusValue,
                        ':participated' => $participated,
                    ]);
                    $saveCount++;
                }
                $pdo->commit();
                $saveMessage = $saveCount > 0 ? 'Attendance saved.' : 'No changes to save.';
            } catch (Throwable $inner) {
                $pdo->rollBack();
                throw $inner;
            }
        }
    }

    // Load attendance marks for the selected session.
    if ($selectedSession) {
        $recStmt = $pdo->prepare("
            SELECT student_id, status, participated
            FROM attendance_records
            WHERE session_id = :sid
        ");
        $recStmt->execute([':sid' => $selectedSessionId]);
        foreach ($recStmt->fetchAll() as $rec) {
            $sessionRecords[(int) $rec['student_id']] = $rec;
        }
    }

    // Pull students only when a session is selected; filter by module/section/group.
    if ($selectedSession) {
        $sessionGroup = $selectedSession['group_id'] ?? null;
        $sessionSection = $selectedSession['section'] ?? null;
        $sessionModule = $selectedSession['module'] ?? $selectedSession['course_id'] ?? null;

        $students = fetch_student_summaries($pdo, $sessionGroup);
        $students = array_values(array_filter($students, function ($stu) use ($sessionModule, $sessionSection, $sessionGroup) {
            if ($sessionModule && !empty($stu['module']) && strcasecmp($stu['module'], $sessionModule) !== 0) {
                return false;
            }
            if ($sessionSection && !empty($stu['section']) && strcasecmp($stu['section'], $sessionSection) !== 0) {
                return false;
            }
            if ($sessionGroup && !empty($stu['group_id']) && strcasecmp($stu['group_id'], $sessionGroup) !== 0) {
                return false;
            }
            return true;
        }));
    } else {
        $students = [];
    }

    // Session summary table: per-student totals of absences, presences, and participations for this group.
    if ($selectedSession && $students) {
        $sessionGroup = $selectedSession['group_id'] ?? '';
        $sessionSection = $selectedSession['section'] ?? '';
        $sessionModule = $selectedSession['module'] ?? $selectedSession['course_id'] ?? '';

        $studentIds = array_column($students, 'id');
        if ($studentIds) {
            $params = [
                ':tid' => (int) $teacherRow['id'],
                ':uid' => (int) $user['id'],
                ':mod' => $sessionModule,
                ':sec' => $sessionSection,
                ':grp' => $sessionGroup,
            ];
            $inPlaceholders = [];
            foreach ($studentIds as $idx => $sid) {
                $ph = ':sid' . $idx;
                $inPlaceholders[] = $ph;
                $params[$ph] = (int) $sid;
            }
            $placeholders = implode(',', $inPlaceholders);
            $sql = "
                SELECT ar.student_id,
                       SUM(CASE WHEN ar.status = 'present' THEN 1 ELSE 0 END) AS total_present,
                       SUM(CASE WHEN ar.status = 'absent' THEN 1 ELSE 0 END) AS total_absent,
                       SUM(CASE WHEN ar.participated = 1 THEN 1 ELSE 0 END) AS total_participated
                FROM attendance_records ar
                INNER JOIN attendance_sessions sess ON sess.id = ar.session_id
                WHERE ar.student_id IN ($placeholders)
                  AND (sess.teacher_id = :tid OR sess.opened_by = :uid)
                  AND (sess.module = :mod OR sess.course_id = :mod OR (:mod = '' AND (sess.module IS NULL OR sess.course_id IS NULL)))
                  AND (sess.section = :sec OR (:sec = '' AND (sess.section IS NULL OR sess.section = '')))
                  AND (sess.group_id = :grp OR (:grp = '' AND (sess.group_id IS NULL OR sess.group_id = '')))
                GROUP BY ar.student_id
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rawTotals = [];
            foreach ($stmt->fetchAll() as $row) {
                $rawTotals[(int) $row['student_id']] = [
                    'present' => (int) $row['total_present'],
                    'absent' => (int) $row['total_absent'],
                    'participated' => (int) $row['total_participated'],
                ];
            }

            foreach ($students as $stu) {
                $sid = (int) $stu['id'];
                $totals = $rawTotals[$sid] ?? ['present' => 0, 'absent' => 0, 'participated' => 0];
                $summaryRows[] = [
                    'student_id' => $stu['student_id'],
                    'first_name' => $stu['first_name'],
                    'last_name' => $stu['last_name'],
                    'present' => $totals['present'],
                    'absent' => $totals['absent'],
                    'participated' => $totals['participated'],
                ];
            }
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $error ?: 'Could not load the teacher dashboard.';
    log_app_error('teacher_dashboard error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Teacher Dashboard</title>
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
    .app-shell { max-width: 1100px; margin: 0 auto; }
    .topbar {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      margin-bottom: 10px;
    }
    .user-chip {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      background: #f7f8fc;
      border: 1px solid #eef0fb;
      padding: 8px 12px;
      border-radius: 12px;
      font-weight: 600;
      color: var(--text);
    }
    .user-chip small { color: var(--muted); font-weight: 500; text-transform: capitalize; }
    .logout-link { color: var(--primary); text-decoration: none; font-weight: 600; }
    .logout-link:hover { text-decoration: underline; }
    .page-header { text-align: center; margin-bottom: 24px; }
    .header-icon {
      width: 70px; height: 70px; margin: 0 auto 16px;
      border-radius: 20px;
      background: linear-gradient(145deg, #3c6ff0, #5c8ff9);
      display: flex; align-items: center; justify-content: center;
      color: #fff; box-shadow: 0 12px 28px rgba(60, 111, 240, 0.35);
    }
    .header-icon svg { width: 36px; height: 36px; }
    .page-header h1 { margin: 0; font-size: 2.1rem; }
    .page-header p { color: var(--muted); margin-top: 8px; font-size: 1rem; }
    .panel {
      background: var(--card);
      border-radius: 16px;
      padding: 24px;
      box-shadow: 0 12px 35px rgba(32, 56, 117, 0.08);
      margin-bottom: 24px;
    }
    .controls-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 16px;
      align-items: end;
    }
    label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--muted);
      display: block;
      margin-bottom: 6px;
    }
    input[type="text"], input[type="number"], input[type="date"], select {
      width: 100%;
      padding: 12px 16px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 1rem;
      transition: border 0.2s, box-shadow 0.2s;
    }
    input:focus, select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(60, 111, 240, 0.15);
    }
    button {
      border: none;
      border-radius: 12px;
      padding: 12px 18px;
      font-size: 0.95rem;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.1s ease, box-shadow 0.1s ease, background 0.2s ease;
    }
    button:active { transform: translateY(1px); }
    .btn-primary {
      background: var(--primary);
      color: #fff;
      box-shadow: 0 10px 18px rgba(60, 111, 240, 0.25);
    }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-muted { background: #f3f4f6; color: var(--muted); }
    .btn-ghost { background: #eef2ff; color: var(--primary); }
    .hint-text { color: var(--muted); font-size: 0.9rem; margin: 4px 0 0; }
    .table-wrapper { overflow-x: auto; }
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      min-width: 720px;
    }
    th, td { padding: 14px 12px; text-align: center; }
    thead th {
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      background: #f7f8fc;
      color: var(--muted);
    }
    tbody tr {
      border: 1px solid #f0f1f7;
      border-left: 4px solid transparent;
      transition: background 0.2s, border-left 0.2s, transform 0.2s;
    }
    tbody tr + tr { border-top: none; }
    tbody tr:hover { background: #f8fbff; border-left-color: var(--primary); }
    .green { background-color: #e8fff5; }
    .yellow { background-color: #fff6e5; }
    .red { background-color: #ffeaea; }
    .highlight { box-shadow: inset 0 0 0 2px rgba(60, 111, 240, 0.2); }
    .status-line { min-height: 20px; margin-top: 6px; font-weight: 600; }
    .status-line.error { color: #e45858; }
    .status-line.success { color: #089e7d; }
    .badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 10px; border-radius: 10px;
      background: #eef2ff; color: var(--primary);
      font-weight: 600; font-size: 0.85rem;
    }
    @media (max-width: 640px) {
      body { padding: 24px 12px 40px; }
      .controls-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <div class="topbar">
      <div class="user-chip">
        <span><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
        <small>teacher</small>
      </div>
      <a href="settings.php" class="logout-link">Settings</a>
      <a href="logout.php" class="logout-link">Logout</a>
    </div>
    <header class="page-header">
      <div class="header-icon" aria-hidden="true">
        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M20 32.5 12 24.4l3.2-3.2 4.8 4.8 12.8-12.8 3.2 3.2L20 32.5Z" fill="currentColor"/>
        </svg>
      </div>
      <h1>Teacher Dashboard</h1>
      <p>Mark presence and participation for your sessions.</p>
    </header>

    <section class="panel">
      <div class="controls-grid">
        <div>
          <label for="searchName">Search a student</label>
          <input type="text" id="searchName" placeholder="Type a last or first name">
        </div>
        <div>
          <label>Sorting shortcuts</label>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" id="sortAbs" class="btn-ghost">Sort by Absences</button>
            <button type="button" id="sortPar" class="btn-ghost">Sort by Participation</button>
          </div>
        </div>
        <div>
          <label for="sessionSelect">Select session</label>
          <form method="GET" id="sessionSelectForm">
            <select name="session_id" id="sessionSelect" onchange="document.getElementById('sessionSelectForm').submit();">
              <?php foreach ($sessions as $sess): ?>
                <option value="<?php echo (int) $sess['id']; ?>" <?php echo (int) $sess['id'] === (int) $selectedSessionId ? 'selected' : ''; ?>>
                  #<?php echo (int) $sess['id']; ?> · <?php echo htmlspecialchars($sess['date']); ?>
                  <?php if (!empty($sess['module'])): ?> · <?php echo htmlspecialchars($sess['module']); ?><?php endif; ?>
                  <?php if (!empty($sess['section'])): ?> · Section <?php echo htmlspecialchars($sess['section']); ?><?php endif; ?>
                  <?php if (!empty($sess['group_id'])): ?> · Group <?php echo htmlspecialchars($sess['group_id']); ?><?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <p class="hint-text"><?php echo $selectedSession ? 'Editing session #' . (int) $selectedSession['id'] : 'Create a session to start marking attendance.'; ?></p>
        </div>
      </div>
      <div class="controls-grid">
        <form method="POST" class="controls-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
          <input type="hidden" name="action" value="create_session">
          <div>
            <label for="courseId">Module</label>
            <input type="text" id="courseId" name="course_id" placeholder="Ex: WEB101" value="<?php echo htmlspecialchars($user['course_id'] ?? ''); ?>">
          </div>
          <div>
            <label for="sectionId">Section</label>
            <input type="text" id="sectionId" name="section" placeholder="Ex: A" value="">
          </div>
          <div>
            <label for="groupId">Group</label>
            <input type="text" id="groupId" name="group_id" placeholder="Ex: G1" value="<?php echo htmlspecialchars($groupFilter ?? ''); ?>">
          </div>
          <div>
            <label for="sessionDate">Date</label>
            <input type="date" id="sessionDate" name="date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>">
          </div>
          <div style="display:flex; align-items:flex-end;">
            <button type="submit" class="btn-primary" style="width:100%;">Create Session</button>
          </div>
        </form>
      </div>
      <div class="status-line <?php echo $sessionMessage ? 'success' : ''; ?> <?php echo $error ? 'error' : ''; ?>">
        <?php echo htmlspecialchars($sessionMessage ?: $error); ?>
      </div>
    </section>

    <section class="panel table-panel">
      <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
        <div class="badge">
          <?php if ($selectedSession): ?>
            Session #<?php echo (int) $selectedSession['id']; ?> · <?php echo htmlspecialchars($selectedSession['date']); ?>
            <?php if (!empty($selectedSession['module'])): ?> · <?php echo htmlspecialchars($selectedSession['module']); ?><?php endif; ?>
            <?php if (!empty($selectedSession['section'])): ?> · Section <?php echo htmlspecialchars($selectedSession['section']); ?><?php endif; ?>
            <?php if (!empty($selectedSession['group_id'])): ?> · Group <?php echo htmlspecialchars($selectedSession['group_id']); ?><?php endif; ?>
          <?php else: ?>
            No session selected
          <?php endif; ?>
        </div>
        <div class="status-line <?php echo $saveMessage ? 'success' : ''; ?> <?php echo $error ? 'error' : ''; ?>">
          <?php echo htmlspecialchars($saveMessage ?: $error); ?>
        </div>
      </div>
      <?php if (!$selectedSession): ?>
        <p class="hint-text">No session selected. Create or select a session to start marking attendance.</p>
      <?php elseif (!$students): ?>
        <p class="hint-text">No students available. Ask an administrator to add students to your group.</p>
      <?php else: ?>
        <form method="POST" action="teacher_dashboard.php">
          <input type="hidden" name="action" value="save_attendance">
          <input type="hidden" name="session_id" value="<?php echo (int) $selectedSessionId; ?>">
          <div class="table-wrapper">
            <table id="attendance">
              <thead>
                <tr>
                  <th>Last Name</th><th>First Name</th>
                  <th>S1</th><th>S2</th><th>S3</th><th>S4</th><th>S5</th><th>S6</th>
                  <th>Absences</th><th>Participation</th><th>Message</th>
                  <th>Present?</th><th>Participated?</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($students as $student): ?>
                  <?php
                    $sid = (int) $student['id'];
                    $rowStatus = $student['status']['rowClass'] ?? '';
                    $record = $sessionRecords[$sid] ?? null;
                    $isPresent = $record ? ($record['status'] === 'present') : true;
                    $didParticipate = $record ? ((int) $record['participated'] === 1) : false;
                    $marks = $student['marks'];
                    $absences = (int) $student['absences'];
                    $participations = (int) $student['participations'];
                  ?>
                  <tr
                    class="<?php echo htmlspecialchars($rowStatus); ?>"
                    data-total-abs="<?php echo $absences; ?>"
                    data-total-par="<?php echo $participations; ?>"
                  >
                    <td><?php echo htmlspecialchars($student['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                    <?php foreach ($marks as $mark): ?>
                      <td><?php echo htmlspecialchars($mark); ?></td>
                    <?php endforeach; ?>
                    <td><?php echo $absences; ?> Abs</td>
                    <td><?php echo $participations; ?> Par</td>
                    <td><?php echo htmlspecialchars($student['status']['message']); ?></td>
                    <td>
                      <input type="hidden" name="student_ids[]" value="<?php echo $sid; ?>">
                      <label><input type="radio" name="status[<?php echo $sid; ?>]" value="present" <?php echo $isPresent ? 'checked' : ''; ?>> Present</label><br>
                      <label><input type="radio" name="status[<?php echo $sid; ?>]" value="absent" <?php echo !$isPresent ? 'checked' : ''; ?>> Absent</label>
                    </td>
                    <td>
                      <label>
                        <input type="checkbox" name="participated[<?php echo $sid; ?>]" value="1" <?php echo $didParticipate ? 'checked' : ''; ?>>
                        Yes
                      </label>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div style="margin-top:14px; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;">
            <button type="submit" class="btn-primary">Save Attendance</button>
            <a class="btn-muted" style="text-decoration:none; padding:12px 18px;" href="close_session.php?session_id=<?php echo (int) $selectedSessionId; ?>">Close Session</a>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Session summary: absences &amp; participation</h2>
      <?php if (!$selectedSession): ?>
        <p class="hint-text">No session selected. Select or create a session to see summary statistics.</p>
      <?php elseif (!$students): ?>
        <p class="hint-text">No students found for this group.</p>
      <?php else: ?>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Total Absences</th>
                <th>Total Presences</th>
                <th>Total Participations</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($summaryRows as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?></td>
                  <td><?php echo (int) ($row['absent'] ?? 0); ?></td>
                  <td><?php echo (int) ($row['present'] ?? 0); ?></td>
                  <td><?php echo (int) ($row['participated'] ?? 0); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$summaryRows): ?>
                <tr><td colspan="5">No records yet for this group.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <script>
    // Simple client-side helpers for search and sorting without affecting saved values.
    (function() {
      const table = document.getElementById('attendance');
      const search = document.getElementById('searchName');
      const sortAbs = document.getElementById('sortAbs');
      const sortPar = document.getElementById('sortPar');

      if (search && table) {
        search.addEventListener('input', function() {
          const value = this.value.toLowerCase();
          table.querySelectorAll('tbody tr').forEach(function(row) {
            const text = row.textContent.toLowerCase();
            row.style.display = text.indexOf(value) > -1 ? '' : 'none';
          });
        });
      }

      function sortRows(comparer) {
        if (!table) return;
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        rows.sort(comparer);
        const tbody = table.querySelector('tbody');
        rows.forEach((r) => tbody.appendChild(r));
      }

      if (sortAbs) {
        sortAbs.addEventListener('click', function() {
          sortRows((a, b) => {
            const absA = parseInt(a.getAttribute('data-total-abs') || '0', 10);
            const absB = parseInt(b.getAttribute('data-total-abs') || '0', 10);
            return absA - absB;
          });
        });
      }

      if (sortPar) {
        sortPar.addEventListener('click', function() {
          sortRows((a, b) => {
            const parA = parseInt(a.getAttribute('data-total-par') || '0', 10);
            const parB = parseInt(b.getAttribute('data-total-par') || '0', 10);
            return parB - parA;
          });
        });
      }
    })();
  </script>
</body>
</html>
