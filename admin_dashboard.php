<?php
// Added role-protected dashboard view wired to MySQL data.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_role(['admin']);
$user = current_user();

$pdo = get_db();
$groupFilter = null;
$students = [];
$sessions = [];
$groups = [];

try {
    $students = fetch_student_summaries($pdo, $groupFilter);
    $groups = fetch_groups($pdo);

    if ($user['role'] === 'teacher') {
        $params = [':opened_by' => $user['id']];
        $where = ['(opened_by = :opened_by'];
        if ($groupFilter) {
            $where[] = 'group_id = :group_id';
            $params[':group_id'] = $groupFilter;
        }
        $whereSql = implode(' OR ', $where) . ')';
        $sessionsStmt = $pdo->prepare("
            SELECT id, course_id, group_id, date, status
            FROM attendance_sessions
            WHERE {$whereSql}
            ORDER BY date DESC, id DESC
            LIMIT 12
        ");
        $sessionsStmt->execute($params);
        $sessions = $sessionsStmt->fetchAll();
    }
} catch (Throwable $e) {
    log_app_error('Dashboard load error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Attendance Dashboard</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
    /* Custom scrollbar (dark rail with lighter thumb, narrow like the provided example) */
    html {
      scrollbar-width: thin;
      scrollbar-color: #9aa0ac #2e3035;
    }
    ::-webkit-scrollbar {
      width: 12px;
    }
    ::-webkit-scrollbar-track {
      background: #2e3035;
    }
    ::-webkit-scrollbar-thumb {
      background: #a4a8b1;
      border-radius: 8px;
      border: 2px solid #2e3035;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #b3b7c1;
    }
    ::-webkit-scrollbar-button:single-button:vertical:decrement {
      height: 12px;
      background: linear-gradient(to bottom, #7f8693, #7f8693);
      border: none;
    }
    ::-webkit-scrollbar-button:single-button:vertical:increment {
      height: 12px;
      background: linear-gradient(to bottom, #7f8693, #7f8693);
      border: none;
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
    .app-shell {
      max-width: 1100px;
      margin: 0 auto;
    }
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
    .user-chip small {
      color: var(--muted);
      font-weight: 500;
      text-transform: capitalize;
    }
    .logout-link {
      color: var(--primary);
      text-decoration: none;
      font-weight: 600;
    }
    .logout-link:hover { text-decoration: underline; }
    .page-header {
      text-align: center;
      margin-bottom: 24px;
    }
    .header-icon {
      width: 70px;
      height: 70px;
      margin: 0 auto 16px;
      border-radius: 20px;
      background: linear-gradient(145deg, #3c6ff0, #5c8ff9);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      box-shadow: 0 12px 28px rgba(60, 111, 240, 0.35);
    }
    .header-icon svg {
      width: 36px;
      height: 36px;
    }
    .page-header h1 {
      margin: 0;
      font-size: 2.1rem;
    }
    .page-header p {
      color: var(--muted);
      margin-top: 8px;
      font-size: 1rem;
    }
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
    input[type="text"], input[type="number"], input[type="date"] {
      width: 100%;
      padding: 12px 16px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 1rem;
      transition: border 0.2s, box-shadow 0.2s;
    }
    input[type="text"]:focus, input[type="number"]:focus, input[type="date"]:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(60, 111, 240, 0.15);
    }
    .button-stack {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .quick-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 8px;
    }
    .edit-cell {
      text-align: center;
      display: flex;
      gap: 6px;
      justify-content: center;
    }
    .edit-absence-btn, .delete-student-btn {
      border-radius: 10px;
      border: 1px solid #dfe3ee;
      background: #f7f8fb;
      color: #4b5563;
      padding: 9px 14px;
      font-weight: 600;
      font-size: 0.9rem;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: background 0.2s, color 0.2s, transform 0.1s, box-shadow 0.2s;
    }
    .edit-absence-btn:hover, .delete-student-btn:hover {
      background: #eef2ff;
      color: var(--primary-dark);
      box-shadow: 0 4px 10px rgba(60, 111, 240, 0.12);
    }
    .delete-student-btn {
      color: #c24141;
    }
    .delete-student-btn:hover { color: #9b1c1c; }
    .edit-absence-btn:active, .delete-student-btn:active {
      transform: scale(0.95);
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
    .btn-ghost {
      background: #eef2ff;
      color: var(--primary);
    }
    .btn-secondary {
      background: #e9fcf6;
      color: #089e7d;
    }
    .btn-muted {
      background: #f3f4f6;
      color: var(--muted);
    }
    .hint-text {
      color: var(--muted);
      font-size: 0.9rem;
      margin: 4px 0 0;
    }
    .table-wrapper { overflow-x: auto; }
    table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      min-width: 720px;
    }
    th, td {
      padding: 14px 12px;
      text-align: center;
    }
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
    tbody tr:hover {
      background: #f8fbff;
      border-left-color: var(--primary);
    }
    .green { background-color: #e8fff5; }
    .yellow { background-color: #fff6e5; }
    .red { background-color: #ffeaea; }
    .highlight { box-shadow: inset 0 0 0 2px rgba(60, 111, 240, 0.2); }
    .report-panel {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 18px;
    }
    .stat-card {
      border-radius: 14px;
      padding: 18px;
      background: #f9fbff;
      border: 1px solid #eef0fb;
    }
    .stat-card .label {
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 6px;
    }
    .stat-card .value {
      font-size: 1.7rem;
      font-weight: 600;
      color: var(--text);
    }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px 24px;
    }
    .form-field { display: flex; flex-direction: column; }
    .error {
      color: #e45858;
      font-size: 0.82rem;
      min-height: 18px;
      margin-top: 4px;
    }
    .form-footer {
      margin-top: 18px;
      display: flex;
      justify-content: flex-end;
    }
    .full-width { width: 100%; }
    .modal-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s ease;
      z-index: 1000;
    }
    .modal-overlay.visible {
      opacity: 1;
      pointer-events: auto;
    }
    .modal-dialog {
      width: 100%;
      max-width: 420px;
      background: var(--card);
      border-radius: 20px;
      padding: 28px;
      box-shadow: 0 35px 65px rgba(15, 23, 42, 0.25);
      max-height: 85vh;
      overflow-y: auto;
    }
    .modal-dialog h3 {
      margin: 0 0 18px;
      font-size: 1.2rem;
      color: var(--text);
    }
    .modal-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--muted);
      margin-bottom: 6px;
      display: block;
    }
    .modal-input {
      width: 100%;
      padding: 12px 16px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 1rem;
      margin-bottom: 22px;
      transition: border 0.2s ease, box-shadow 0.2s ease;
    }
    .modal-input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(60, 111, 240, 0.15);
    }
    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }
    .btn-outline {
      background: transparent;
      color: var(--muted);
      border: 1px solid var(--border);
    }
    .btn-outline:hover {
      color: var(--primary-dark);
      border-color: var(--primary);
    }
    .info-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.25s ease;
      z-index: 999;
    }
    .info-overlay.visible {
      opacity: 1;
      pointer-events: auto;
    }
    .info-dialog {
      width: 100%;
      max-width: 360px;
      background: #111827;
      border-radius: 18px;
      padding: 24px 28px;
      box-shadow: 0 35px 65px rgba(15, 23, 42, 0.35);
      color: #f9fafb;
    }
    .info-name {
      margin: 0 0 8px;
      font-size: 1.05rem;
      font-weight: 600;
    }
    .info-details {
      margin: 0 0 24px;
      color: #d1d5db;
      font-size: 0.95rem;
    }
    .info-actions {
      display: flex;
      justify-content: flex-end;
    }
    @media (max-width: 640px) {
      body { padding: 24px 12px 40px; }
      .quick-actions { flex-direction: column; }
      .button-stack { flex-direction: column; }
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <div class="topbar">
      <div class="user-chip">
        <span><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
        <small><?php echo htmlspecialchars($user['role']); ?></small>
      </div>
      <?php if ($user['role'] === 'admin'): ?>
        <a href="admin_room.php" class="logout-link" style="margin-right:8px;">Management</a>
      <?php endif; ?>
      <a href="settings.php" class="logout-link" style="margin-right:8px;">Settings</a>
      <a href="logout.php" class="logout-link">Logout</a>
    </div>
    <header class="page-header">
      <div class="header-icon" aria-hidden="true">
        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M20 32.5 12 24.4l3.2-3.2 4.8 4.8 12.8-12.8 3.2 3.2L20 32.5Z" fill="currentColor"/>
        </svg>
      </div>
      <h1>Attendance Dashboard</h1>
      <p>Monitor session participation, spot trends, and quickly act on attendance insights.</p>
    </header>

    <section class="panel">
      <div class="controls-grid">
        <div>
          <label for="searchName">Search a student</label>
          <input type="text" id="searchName" placeholder="Type a last or first name">
        </div>
        <div>
          <label>Sorting shortcuts</label>
          <div class="button-stack">
            <button id="sortAbs" class="btn-ghost">Sort by Absences</button>
            <button id="sortPar" class="btn-ghost">Sort by Participation</button>
          </div>
        </div>
      </div>
      <div class="quick-actions">
        <button id="showReport" class="btn-primary">Show Report</button>
        <button id="highlightExcellent" class="btn-secondary">Highlight Excellent</button>
        <button id="resetColors" class="btn-muted">Reset Colors</button>
      </div>
      <p id="sortMessage" class="hint-text">Tables update instantly as you search or sort.</p>
    </section>

    <?php if ($user['role'] === 'teacher'): ?>
    <section class="panel">
      <h2>Attendance Sessions</h2>
      <div class="controls-grid">
        <div>
          <label for="courseId">Course ID</label>
          <input type="text" id="courseId" placeholder="Ex: WEB101" value="<?php echo htmlspecialchars($user['course_id'] ?? ''); ?>">
        </div>
        <div>
          <label for="groupId">Group</label>
          <input type="text" id="groupId" placeholder="Ex: G1" value="<?php echo htmlspecialchars($groupFilter ?? ''); ?>">
        </div>
        <div>
          <label for="sessionDate">Date</label>
          <input type="date" id="sessionDate" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div>
          <button type="button" id="createSession" class="btn-primary">Create Session</button>
        </div>
      </div>
      <p id="sessionMessage" class="error" aria-live="polite"></p>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Course</th><th>Group</th><th>Date</th><th>Status</th><th>Action</th>
            </tr>
          </thead>
          <tbody id="sessionTableBody">
            <?php foreach ($sessions as $s): ?>
              <tr data-session-id="<?php echo (int) $s['id']; ?>">
                <td><?php echo (int) $s['id']; ?></td>
                <td><?php echo htmlspecialchars($s['course_id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($s['group_id'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($s['date']); ?></td>
                <td><?php echo htmlspecialchars($s['status']); ?></td>
                <td><a class="btn-ghost" href="take_attendance.php?session_id=<?php echo (int) $s['id']; ?>">Take / Edit</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <section class="panel table-panel">
      <div class="table-wrapper">
        <table id="attendance">
          <thead>
            <tr>
              <th>Last Name</th><th>First Name</th>
              <th>S1</th><th>S2</th><th>S3</th><th>S4</th><th>S5</th><th>S6</th>
              <th>Absences</th><th>Edit</th><th>Participation</th><th>Message</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $student): ?>
              <tr
                data-student-id="<?php echo (int) $student['id']; ?>"
                data-total-abs="<?php echo (int) $student['absences']; ?>"
                data-total-par="<?php echo (int) $student['participations']; ?>"
                data-student-id-code="<?php echo htmlspecialchars($student['student_id']); ?>"
                data-student-email="<?php echo htmlspecialchars($student['email']); ?>"
                data-group="<?php echo htmlspecialchars($student['group_id']); ?>"
                data-module="<?php echo htmlspecialchars($student['module'] ?? ''); ?>"
                data-section="<?php echo htmlspecialchars($student['section'] ?? ''); ?>"
              >
                <td><?php echo htmlspecialchars($student['last_name']); ?></td>
                <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                <?php foreach ($student['marks'] as $mark): ?>
                  <td><?php echo htmlspecialchars($mark); ?></td>
                <?php endforeach; ?>
                <td><?php echo (int) $student['absences']; ?> Abs</td>
                <td class="edit-cell">
                  <button type="button" class="edit-absence-btn" aria-label="Edit absences for <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?>">Edit</button>
                  <?php if ($user['role'] === 'admin'): ?>
                    <button type="button" class="delete-student-btn" aria-label="Delete student <?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name']); ?>">Delete</button>
                  <?php endif; ?>
                </td>
                <td><?php echo (int) $student['participations']; ?> Par</td>
                <td><?php echo htmlspecialchars($student['status']['message']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p id="editAbsError" class="error" aria-live="polite"></p>
    </section>

    <section id="report" class="panel report-panel">
      <h2>Weekly snapshot</h2>
      <p class="hint-text">Run the report to surface how your class is doing this week.</p>
      <div class="stat-grid">
        <div class="stat-card">
          <p class="label">Total Students</p>
          <p class="value">0</p>
        </div>
        <div class="stat-card">
          <p class="label">Marked Present</p>
          <p class="value">0</p>
        </div>
        <div class="stat-card">
          <p class="label">Total Participations</p>
          <p class="value">0</p>
        </div>
      </div>
    </section>

  </div>

  <!-- Modal for editing absences -->
  <div id="absenceModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="absenceModalTitle">
      <form id="absenceModalForm">
        <h3 id="absenceModalTitle">
          Update number of absences for <span id="absenceModalStudent">this student</span>:
        </h3>
        <label for="absenceModalInput" class="modal-label">Absences</label>
        <input type="number" id="absenceModalInput" class="modal-input" min="0" step="1" required>
        <div class="modal-actions">
          <button type="button" id="absenceModalCancel" class="btn-outline">Cancel</button>
          <button type="submit" id="absenceModalOk" class="btn-primary">OK</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal for editing student information (admin) -->
  <div id="studentModal" class="modal-overlay" aria-hidden="true">
    <div class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="studentModalTitle">
      <form id="studentModalForm">
        <h3 id="studentModalTitle">Edit student</h3>
        <label class="modal-label" for="modalStudentId">Student ID</label>
        <input type="text" id="modalStudentId" class="modal-input" required>
        <label class="modal-label" for="modalLastName">Last Name</label>
        <input type="text" id="modalLastName" class="modal-input" required>
        <label class="modal-label" for="modalFirstName">First Name</label>
        <input type="text" id="modalFirstName" class="modal-input" required>
        <label class="modal-label" for="modalEmail">Email</label>
        <input type="text" id="modalEmail" class="modal-input">
        <label class="modal-label" for="modalModule">Module</label>
        <input type="text" id="modalModule" class="modal-input">
        <label class="modal-label" for="modalSection">Section</label>
        <input type="text" id="modalSection" class="modal-input">
        <label class="modal-label" for="modalGroup">Group</label>
        <input type="text" id="modalGroup" class="modal-input">
        <div class="modal-actions">
          <button type="button" id="studentModalCancel" class="btn-outline">Cancel</button>
          <button type="submit" id="studentModalSave" class="btn-primary">Save</button>
        </div>
        <p id="studentModalError" class="error" aria-live="polite"></p>
      </form>
    </div>
  </div>

  <!-- Popup notification for viewing absences -->
  <div id="infoPopup" class="info-overlay" aria-hidden="true">
    <div class="info-dialog" role="dialog" aria-modal="true" aria-labelledby="infoPopupName">
      <p id="infoPopupName" class="info-name">Student Name</p>
      <p id="infoPopupDetails" class="info-details">- 0 Abs</p>
      <div class="info-actions">
        <button type="button" id="infoPopupOk" class="btn-primary">OK</button>
      </div>
    </div>
  </div>

  <script>
    window.APP_ROLE = "<?php echo htmlspecialchars($user['role']); ?>";
    window.APP_GROUP = "<?php echo htmlspecialchars($groupFilter ?? ''); ?>";
  </script>
  <script src="script.js"></script>
</body>
</html>
