<?php
// Settings page: allow logged-in users (admin/teacher/student) to change their own password.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

require_login();
$user = current_user();
$pdo = get_db();

$error = '';
$success = '';
$homeUrl = 'admin_dashboard.php';

if ($user['role'] === 'teacher') {
    $homeUrl = 'teacher_dashboard.php';
} elseif ($user['role'] === 'student') {
    $homeUrl = 'student_dashboard.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'All fields are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New passwords do not match.';
    } elseif (strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters.';
    } else {
        try {
            $userId = (int) $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch();

            if (!$row) {
                $error = 'User not found.';
            } elseif (!password_verify($currentPassword, $row['password_hash'])) {
                $error = 'Current password is incorrect.';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
                $update->execute([':hash' => $newHash, ':id' => $userId]);
                // Keep the user logged in after changing the password to avoid disrupting their workflow.
                $success = 'Password updated successfully.';
            }
        } catch (Throwable $e) {
            $error = 'An unexpected error occurred while updating your password. Please try again.';
            log_app_error('settings error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Change Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #f4f6fb;
      --card: #ffffff;
      --primary: #3c6ff0;
      --primary-dark: #345ed0;
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
    .app-shell { max-width: 520px; margin: 0 auto; }
    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 14px;
    }
    .user-chip {
      display: inline-flex; align-items: center; gap: 10px;
      background: #f7f8fc; border: 1px solid #eef0fb;
      padding: 8px 12px; border-radius: 12px;
      font-weight: 600; color: var(--text);
    }
    .user-chip small { color: var(--muted); text-transform: capitalize; }
    .nav-links { display: flex; gap: 10px; flex-wrap: wrap; }
    .nav-links a, .logout-link {
      color: var(--primary);
      text-decoration: none;
      font-weight: 600;
      padding: 8px 12px;
      border-radius: 10px;
      background: #eef2ff;
      border: 1px solid #dfe6ff;
    }
    .nav-links a:hover, .logout-link:hover { background: #dbe5ff; }
    .card {
      background: var(--card);
      border-radius: 18px;
      padding: 26px;
      box-shadow: 0 18px 48px rgba(32, 56, 117, 0.12);
      margin-top: 10px;
    }
    h1 { margin: 0 0 10px; }
    p.muted { color: var(--muted); margin: 0 0 18px; }
    label {
      font-size: 0.9rem;
      font-weight: 600;
      color: var(--muted);
      display: block;
      margin-bottom: 6px;
    }
    input[type="password"] {
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
    .alert {
      border-radius: 12px;
      padding: 12px 14px;
      margin-bottom: 12px;
      font-weight: 600;
    }
    .alert.error { background: #ffecec; color: #c24141; border: 1px solid #f5c2c2; }
    .alert.success { background: #e9fcf6; color: #0b8f70; border: 1px solid #c4f1e2; }
  </style>
</head>
<body>
  <div class="app-shell">
    <div class="topbar">
      <div class="user-chip">
        <span><?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
        <small><?php echo htmlspecialchars($user['role']); ?></small>
      </div>
      <div class="nav-links">
        <a href="<?php echo htmlspecialchars($homeUrl); ?>">Dashboard</a>
        <a href="settings.php" aria-current="page">Settings</a>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="admin_room.php">Management</a>
        <?php endif; ?>
        <a href="logout.php" class="logout-link">Logout</a>
      </div>
    </div>

    <div class="card">
      <h1>Change password</h1>
      <p class="muted">Update your account password. Enter your current password first for security.</p>
      <?php if ($error): ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
      <?php endif; ?>
      <form method="POST" action="settings.php" autocomplete="off">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" required>

        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" required>

        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" required>

        <button type="submit" class="btn-primary">Update password</button>
      </form>
    </div>
  </div>
</body>
</html>
