<?php
// Added login page with role-based redirection and password hashing check.
error_reporting(E_ALL);
ini_set('display_errors', '1'); // Enable full error reporting for troubleshooting.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/helpers.php';

// Extra debug toggle: append ?debug=1 to see raw login errors.
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

require_not_logged_in();

$error = '';

try {
    $pdo = get_db();

    // Bootstrap a default admin account if the table is empty.
    $count = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $defaultHash = password_hash('admin123', PASSWORD_DEFAULT);
        $bootstrapStmt = $pdo->prepare("
            INSERT INTO users (username, password_hash, role, full_name, email)
            VALUES (:u, :p, 'admin', 'Default Admin', 'admin@example.com')
        ");
        $bootstrapStmt->execute([':u' => 'admin', ':p' => $defaultHash]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $identifier = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        // Authenticate strictly on username to match users.username and avoid ambiguous lookups.
        $stmt = $pdo->prepare("
            SELECT * FROM users
            WHERE username = :username
            LIMIT 1
        ");
        $stmt->execute([':username' => $identifier]);
        $user = $stmt->fetch();

        // If this looks like a student_id and no user exists, auto-create the student user account from students table.
        if (!$user) {
            $studentLookup = $pdo->prepare("SELECT * FROM students WHERE student_id = :sid LIMIT 1");
            $studentLookup->execute([':sid' => $identifier]);
            $studentRow = $studentLookup->fetch();
            if ($studentRow) {
                // Automatically create linked user account for this student so they can log in.
                ensure_student_user_account($pdo, $studentRow, true);
                $stmt->execute([':username' => $identifier]);
                $user = $stmt->fetch();
            }
        }

        // If still not found, try to auto-create a teacher account from teachers table (default password: teacher123).
        if (!$user) {
            $teacherLookup = $pdo->prepare("
                SELECT * FROM teachers
                WHERE teacher_id = :tid OR email = :tid
                ORDER BY id ASC
                LIMIT 1
            ");
            $teacherLookup->execute([':tid' => $identifier]);
            $teacherRow = $teacherLookup->fetch();
            if ($teacherRow) {
                ensure_teacher_user_account($pdo, $teacherRow, true, 'teacher123', true);
                $stmt->execute([':username' => $identifier]);
                $user = $stmt->fetch();
            }
        }

        if ($user && password_verify($password, $user['password_hash'])) {
            // Build base session payload.
            $sessionUser = [
                'id' => (int) $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'full_name' => $user['full_name'] ?? $user['username'],
                'email' => $user['email'] ?? '',
                'group_id' => $user['group_id'] ?? null,
                'course_id' => $user['course_id'] ?? null,
                'student_profile_id' => $user['student_profile_id'] ?? null,
                'student_id' => $user['student_id'] ?? null,
                'first_name' => null,
                'last_name' => null,
            ];

            // Link the user to their student profile when role is student.
            if ($user['role'] === 'student') {
                $studentLookupId = $user['student_id'] ?: $user['username'];
                $studentStmt = $pdo->prepare("
                    SELECT * FROM students
                    WHERE student_id = :sid
                    LIMIT 1
                ");
                $studentStmt->execute([':sid' => $studentLookupId]);
                $studentRow = $studentStmt->fetch();

                if (!$studentRow) {
                    $error = 'Student profile not found. Please contact the administrator.';
                } else {
                    $sessionUser['student_profile_id'] = (int) $studentRow['id'];
                    $sessionUser['student_id'] = $studentRow['student_id'];
                    $sessionUser['first_name'] = $studentRow['first_name'];
                    $sessionUser['last_name'] = $studentRow['last_name'];
                    $sessionUser['group_id'] = $studentRow['group_id'] ?? $sessionUser['group_id'];
                    $sessionUser['student_profile_id'] = (int) $studentRow['id'];
                    $sessionUser['full_name'] = trim(($studentRow['first_name'] ?? '') . ' ' . ($studentRow['last_name'] ?? '')) ?: $sessionUser['full_name'];
                    if (empty($sessionUser['email']) && !empty($studentRow['email'])) {
                        $sessionUser['email'] = $studentRow['email'];
                    }
                }
            }

            if (!$error) {
                // Set flattened session values for easy access.
                $_SESSION['user_id'] = $sessionUser['id'];
                $_SESSION['role'] = $sessionUser['role'];
                $_SESSION['username'] = $sessionUser['username'];
                $_SESSION['full_name'] = $sessionUser['full_name'];
                $_SESSION['email'] = $sessionUser['email'];
                $_SESSION['group_id'] = $sessionUser['group_id'];
                $_SESSION['course_id'] = $sessionUser['course_id'];
                $_SESSION['student_profile_id'] = $sessionUser['student_profile_id'];
                $_SESSION['student_id'] = $sessionUser['student_id'];
                $_SESSION['first_name'] = $sessionUser['first_name'];
                $_SESSION['last_name'] = $sessionUser['last_name'];

                // Keep the structured payload too.
                $_SESSION['user'] = $sessionUser;

                redirect_home_by_role($user['role']);
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
    // If debug flag is on, surface the raw login error to the browser to unblock troubleshooting.
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        echo "LOGIN ERROR: " . htmlspecialchars($message);
        exit;
    }
    // Friendly fallback for production.
    $error = 'Database error. Please contact the administrator.';
    log_app_error('Login error: ' . $message);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Attendance Dashboard - Login</title>
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
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 12px;
    }
    .login-card {
      width: 100%;
      max-width: 420px;
      background: var(--card);
      border-radius: 18px;
      padding: 28px;
      box-shadow: 0 18px 48px rgba(32, 56, 117, 0.12);
    }
    .login-card h1 {
      margin: 0 0 8px;
      font-size: 1.45rem;
      text-align: center;
    }
    .login-card p {
      margin: 0 0 20px;
      color: var(--muted);
      text-align: center;
    }
    label {
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--muted);
      display: block;
      margin-bottom: 6px;
    }
    input[type="text"], input[type="password"] {
      width: 100%;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--border);
      font-size: 1rem;
      margin-bottom: 14px;
      transition: border 0.2s, box-shadow 0.2s;
    }
    input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(60, 111, 240, 0.15);
    }
    .btn-primary {
      width: 100%;
      background: var(--primary);
      color: #fff;
      border: none;
      border-radius: 12px;
      padding: 12px 16px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 10px 18px rgba(60, 111, 240, 0.25);
      transition: background 0.2s ease, transform 0.1s ease;
    }
    .btn-primary:hover { background: var(--primary-dark); }
    .btn-primary:active { transform: translateY(1px); }
    .error {
      color: #e45858;
      font-size: 0.9rem;
      min-height: 20px;
      margin-bottom: 8px;
      text-align: center;
    }
    .hint {
      font-size: 0.9rem;
      color: var(--muted);
      text-align: center;
      margin-top: 12px;
    }
  </style>
</head>
<body>
  <div class="login-card">
    <h1>Attendance Dashboard</h1>
    <p>Sign in to manage attendance.</p>
    <?php if ($error): ?>
      <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php else: ?>
      <div class="error"></div>
    <?php endif; ?>
    <form method="POST" action="login.php" autocomplete="off">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required>

      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>

      <button type="submit" class="btn-primary">Login</button>
    </form>
    <p class="hint">Default admin: admin / admin123</p>
  </div>
</body>
</html>
