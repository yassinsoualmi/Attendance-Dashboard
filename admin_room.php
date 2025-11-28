<?php
// Admin room: manage students and teachers.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';

require_role(['admin']);
$pdo = get_db();

$flashSuccess = '';
$flashError = '';

// Safe escape helper to avoid passing null into htmlspecialchars (PHP 8+ deprecation).
function e($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// Handle form submissions for students and teachers.
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_student' || $action === 'update_student') {
            $sid = trim($_POST['student_id'] ?? '');
            $ln = trim($_POST['last_name'] ?? '');
            $fn = trim($_POST['first_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $module = trim($_POST['module'] ?? '');
            $section = trim($_POST['section'] ?? '');
            $group = trim($_POST['group_id'] ?? '');
            $rowId = (int) ($_POST['id'] ?? 0);

            if ($sid === '' || $ln === '' || $fn === '') {
                throw new RuntimeException('Student ID, last name, and first name are required.');
            }

            if ($action === 'create_student') {
                $stmt = $pdo->prepare("
                    INSERT INTO students (student_id, last_name, first_name, email, module, section, group_id)
                    VALUES (:student_id, :last_name, :first_name, :email, :module, :section, :group_id)
                ");
                $stmt->execute([
                    ':student_id' => $sid,
                    ':last_name' => $ln,
                    ':first_name' => $fn,
                    ':email' => $email,
                    ':module' => $module,
                    ':section' => $section,
                    ':group_id' => $group,
                ]);
                // Auto-create linked user account for this student: username = student_id, password = lowercase first name.
                $newId = (int) $pdo->lastInsertId();
                ensure_student_user_account($pdo, [
                    'id' => $newId,
                    'student_id' => $sid,
                    'first_name' => $fn,
                    'last_name' => $ln,
                    'email' => $email,
                    'group_id' => $group,
                ]);
                $flashSuccess = 'Student added.';
            } else {
                if ($rowId <= 0) {
                    throw new RuntimeException('Missing student id for update.');
                }
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET student_id = :student_id,
                        last_name = :last_name,
                        first_name = :first_name,
                        email = :email,
                        module = :module,
                        section = :section,
                        group_id = :group_id
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':student_id' => $sid,
                    ':last_name' => $ln,
                    ':first_name' => $fn,
                    ':email' => $email,
                    ':module' => $module,
                    ':section' => $section,
                    ':group_id' => $group,
                    ':id' => $rowId,
                ]);
                // Keep linked user account in sync with the student row.
                ensure_student_user_account($pdo, [
                    'id' => $rowId,
                    'student_id' => $sid,
                    'first_name' => $fn,
                    'last_name' => $ln,
                    'email' => $email,
                    'group_id' => $group,
                ]);
                $flashSuccess = 'Student updated.';
            }
        } elseif ($action === 'delete_student') {
            $rowId = (int) ($_POST['id'] ?? 0);
            if ($rowId > 0) {
                $stmt = $pdo->prepare("DELETE FROM students WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $rowId]);
                $flashSuccess = 'Student deleted.';
            }
        } elseif ($action === 'create_teacher' || $action === 'update_teacher') {
            $tid = trim($_POST['teacher_id'] ?? '');
            $ln = trim($_POST['last_name'] ?? '');
            $fn = trim($_POST['first_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $module = trim($_POST['module'] ?? '');
            $rowId = (int) ($_POST['id'] ?? 0);
            $createUser = true; // Always ensure a teacher user account exists.
            $tempPassword = trim($_POST['temp_password'] ?? 'teacher123');

            if ($tid === '' || $ln === '' || $fn === '') {
                throw new RuntimeException('Teacher ID, last name, and first name are required.');
            }

            if ($action === 'create_teacher') {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("
                    INSERT INTO teachers (teacher_id, last_name, first_name, email, module)
                    VALUES (:teacher_id, :last_name, :first_name, :email, :module)
                ");
                $stmt->execute([
                    ':teacher_id' => $tid,
                    ':last_name' => $ln,
                    ':first_name' => $fn,
                    ':email' => $email,
                    ':module' => $module,
                ]);

                // Automatically create or sync a teacher user so they can log in.
                ensure_teacher_user_account($pdo, [
                    'teacher_id' => $tid,
                    'first_name' => $fn,
                    'last_name' => $ln,
                    'email' => $email,
                    'module' => $module,
                ], $createUser, $tempPassword, true);
                $pdo->commit();
                $flashSuccess = 'Teacher added.';
            } else {
                if ($rowId <= 0) {
                    throw new RuntimeException('Missing teacher id for update.');
                }
                $stmt = $pdo->prepare("
                    UPDATE teachers
                    SET teacher_id = :teacher_id,
                        last_name = :last_name,
                        first_name = :first_name,
                        email = :email,
                        module = :module
                    WHERE id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    ':teacher_id' => $tid,
                    ':last_name' => $ln,
                    ':first_name' => $fn,
                    ':email' => $email,
                    ':module' => $module,
                    ':id' => $rowId,
                ]);
                // Sync teacher user profile; create if missing (keeps existing password).
                ensure_teacher_user_account($pdo, [
                    'teacher_id' => $tid,
                    'first_name' => $fn,
                    'last_name' => $ln,
                    'email' => $email,
                    'module' => $module,
                ], true, null, false);
                $flashSuccess = 'Teacher updated.';
            }
        } elseif ($action === 'delete_teacher') {
            $rowId = (int) ($_POST['id'] ?? 0);
            if ($rowId > 0) {
                $stmt = $pdo->prepare("DELETE FROM teachers WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $rowId]);
                $flashSuccess = 'Teacher deleted.';
            }
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $flashError = $e->getMessage();
    log_app_error('admin_room error: ' . $e->getMessage());
}

// Load lists for display.
$students = [];
$teachers = [];
$editStudent = null;
$editTeacher = null;

$studentStmt = $pdo->query("
    SELECT id, student_id, last_name, first_name, email, module, section, group_id, created_at
    FROM students
    ORDER BY last_name ASC, first_name ASC
");
$students = $studentStmt->fetchAll();

$teacherStmt = $pdo->query("
    SELECT id, teacher_id, last_name, first_name, email, module, created_at
    FROM teachers
    ORDER BY last_name ASC, first_name ASC
");
$teachers = $teacherStmt->fetchAll();

if (isset($_GET['edit_student'])) {
    $id = (int) $_GET['edit_student'];
    foreach ($students as $s) {
        if ((int) $s['id'] === $id) {
            $editStudent = $s;
            break;
        }
    }
}
if (isset($_GET['edit_teacher'])) {
    $id = (int) $_GET['edit_teacher'];
    foreach ($teachers as $t) {
        if ((int) $t['id'] === $id) {
            $editTeacher = $t;
            break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Room</title>
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
      padding: 32px 16px 60px;
    }
    .app-shell { max-width: 1150px; margin: 0 auto; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
    .user-chip {
      display: inline-flex; align-items: center; gap: 10px;
      background: #f7f8fc; border: 1px solid #eef0fb;
      padding: 8px 12px; border-radius: 12px;
      font-weight: 600; color: var(--text);
    }
    .user-chip small { color: var(--muted); text-transform: capitalize; }
    .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
    .nav-links a {
      color: var(--primary);
      text-decoration: none;
      font-weight: 600;
      padding: 8px 12px;
      border-radius: 10px;
      background: #eef2ff;
      border: 1px solid #dfe6ff;
    }
    .nav-links a:hover { background: #dbe5ff; }
    .panel {
      background: var(--card);
      border-radius: 16px;
      padding: 22px;
      box-shadow: 0 12px 35px rgba(32, 56, 117, 0.08);
      margin-bottom: 22px;
    }
    h1, h2 { margin: 0 0 10px; }
    p.muted { color: var(--muted); margin-top: 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px 18px; }
    label { font-size: 0.9rem; font-weight: 600; color: var(--muted); display: block; margin-bottom: 6px; }
    input[type="text"], input[type="email"], input[type="password"] {
      width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid var(--border); font-size: 1rem;
    }
    input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(60, 111, 240, 0.15); }
    button {
      border: none; border-radius: 12px; padding: 12px 16px;
      font-size: 0.95rem; font-weight: 600; cursor: pointer;
      transition: transform 0.1s ease, box-shadow 0.1s ease, background 0.2s ease;
    }
    button:active { transform: translateY(1px); }
    .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 10px 18px rgba(60, 111, 240, 0.25); }
    .btn-muted { background: #f3f4f6; color: var(--muted); }
    .table-wrapper { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 760px; }
    th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #eef0fb; }
    thead th { text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.04em; color: var(--muted); }
    .actions { display: flex; gap: 6px; flex-wrap: wrap; }
    .flash { padding: 12px 14px; border-radius: 12px; margin-bottom: 12px; font-weight: 600; }
    .flash.success { background: #e9fcf6; color: #0b8f70; border: 1px solid #c4f1e2; }
    .flash.error { background: #ffecec; color: #c24141; border: 1px solid #f5c2c2; }
  </style>
</head>
<body>
  <div class="app-shell">
    <div class="topbar">
      <div class="user-chip">
        <span><?php echo htmlspecialchars(current_user()['full_name'] ?? 'Admin'); ?></span>
        <small>admin</small>
      </div>
      <div class="nav-links">
        <a href="admin_dashboard.php">Dashboard</a>
        <a href="admin_room.php">Management</a>
        <a href="settings.php">Settings</a>
        <a href="logout.php">Logout</a>
      </div>
    </div>

    <div class="panel">
      <h1>Admin Management Room</h1>
      <p class="muted">Manage students and teachers, including module, section, and group assignments.</p>
      <?php if ($flashSuccess): ?>
        <div class="flash success"><?php echo htmlspecialchars($flashSuccess); ?></div>
      <?php endif; ?>
      <?php if ($flashError): ?>
        <div class="flash error"><?php echo htmlspecialchars($flashError); ?></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2><?php echo $editStudent ? 'Edit Student' : 'Add Student'; ?></h2>
      <form method="POST">
        <input type="hidden" name="action" value="<?php echo $editStudent ? 'update_student' : 'create_student'; ?>">
        <input type="hidden" name="id" value="<?php echo $editStudent ? (int) $editStudent['id'] : 0; ?>">
        <div class="grid">
          <div>
            <label for="student_id">Student ID</label>
            <input type="text" id="student_id" name="student_id" required value="<?php echo e($editStudent['student_id'] ?? ''); ?>">
          </div>
          <div>
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" required value="<?php echo e($editStudent['last_name'] ?? ''); ?>">
          </div>
          <div>
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" required value="<?php echo e($editStudent['first_name'] ?? ''); ?>">
          </div>
          <div>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?php echo e($editStudent['email'] ?? ''); ?>">
          </div>
          <div>
            <label for="module">Module</label>
            <input type="text" id="module" name="module" value="<?php echo e($editStudent['module'] ?? ''); ?>">
          </div>
          <div>
            <label for="section">Section</label>
            <input type="text" id="section" name="section" value="<?php echo e($editStudent['section'] ?? ''); ?>">
          </div>
          <div>
            <label for="group_id">Group</label>
            <input type="text" id="group_id" name="group_id" value="<?php echo e($editStudent['group_id'] ?? ''); ?>">
          </div>
        </div>
        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit" class="btn-primary"><?php echo $editStudent ? 'Update Student' : 'Add Student'; ?></button>
          <?php if ($editStudent): ?>
            <a class="btn-muted" style="text-decoration:none; padding:12px 16px;" href="admin_room.php">Cancel edit</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="panel table-panel">
      <h2>Students</h2>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Student ID</th><th>Name</th><th>Email</th><th>Module</th><th>Section</th><th>Group</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s): ?>
              <tr>
                <td><?php echo (int) $s['id']; ?></td>
                <td><?php echo e($s['student_id']); ?></td>
                <td><?php echo e($s['last_name'] . ' ' . $s['first_name']); ?></td>
                <td><?php echo e($s['email']); ?></td>
                <td><?php echo e($s['module']); ?></td>
                <td><?php echo e($s['section']); ?></td>
                <td><?php echo e($s['group_id']); ?></td>
                <td>
                  <div class="actions">
                    <a class="btn-muted" style="text-decoration:none; padding:8px 12px;" href="admin_room.php?edit_student=<?php echo (int) $s['id']; ?>">Edit</a>
                    <form method="POST" onsubmit="return confirm('Delete this student?');">
                      <input type="hidden" name="action" value="delete_student">
                      <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                      <button type="submit" class="btn-muted">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$students): ?>
              <tr><td colspan="8">No students found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel">
      <h2><?php echo $editTeacher ? 'Edit Teacher' : 'Add Teacher'; ?></h2>
      <form method="POST">
        <input type="hidden" name="action" value="<?php echo $editTeacher ? 'update_teacher' : 'create_teacher'; ?>">
        <input type="hidden" name="id" value="<?php echo $editTeacher ? (int) $editTeacher['id'] : 0; ?>">
        <div class="grid">
          <div>
            <label for="teacher_id">Teacher ID</label>
            <input type="text" id="teacher_id" name="teacher_id" required value="<?php echo e($editTeacher['teacher_id'] ?? ''); ?>">
          </div>
          <div>
            <label for="t_last_name">Last Name</label>
            <input type="text" id="t_last_name" name="last_name" required value="<?php echo e($editTeacher['last_name'] ?? ''); ?>">
          </div>
          <div>
            <label for="t_first_name">First Name</label>
            <input type="text" id="t_first_name" name="first_name" required value="<?php echo e($editTeacher['first_name'] ?? ''); ?>">
          </div>
          <div>
            <label for="t_email">Email</label>
            <input type="email" id="t_email" name="email" value="<?php echo e($editTeacher['email'] ?? ''); ?>">
          </div>
          <div>
            <label for="t_module">Module</label>
            <input type="text" id="t_module" name="module" value="<?php echo e($editTeacher['module'] ?? ''); ?>">
          </div>
          <?php if (!$editTeacher): ?>
          <div>
            <label for="temp_password">Temporary password (for user creation)</label>
            <input type="password" id="temp_password" name="temp_password" value="teacher123">
            <div style="margin-top:8px;">
              <label><input type="checkbox" name="create_user" checked> Create linked teacher user</label>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
          <button type="submit" class="btn-primary"><?php echo $editTeacher ? 'Update Teacher' : 'Add Teacher'; ?></button>
          <?php if ($editTeacher): ?>
            <a class="btn-muted" style="text-decoration:none; padding:12px 16px;" href="admin_room.php">Cancel edit</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="panel table-panel">
      <h2>Teachers</h2>
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>ID</th><th>Teacher ID</th><th>Name</th><th>Email</th><th>Module</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($teachers as $t): ?>
              <tr>
                <td><?php echo (int) $t['id']; ?></td>
                <td><?php echo e($t['teacher_id']); ?></td>
                <td><?php echo e($t['last_name'] . ' ' . $t['first_name']); ?></td>
                <td><?php echo e($t['email']); ?></td>
                <td><?php echo e($t['module']); ?></td>
                <td>
                  <div class="actions">
                    <a class="btn-muted" style="text-decoration:none; padding:8px 12px;" href="admin_room.php?edit_teacher=<?php echo (int) $t['id']; ?>">Edit</a>
                    <form method="POST" onsubmit="return confirm('Delete this teacher?');">
                      <input type="hidden" name="action" value="delete_teacher">
                      <input type="hidden" name="id" value="<?php echo (int) $t['id']; ?>">
                      <button type="submit" class="btn-muted">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$teachers): ?>
              <tr><td colspan="8">No teachers found.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
