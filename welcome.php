<?php
// Welcome splash screen: shows logo for 5 seconds, then redirects to login.php.
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Welcome – Attendance Dashboard</title>
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
      --shadow: 0 18px 48px rgba(32, 56, 117, 0.14);
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
      padding: 32px 16px;
      transition: opacity 0.4s ease;
    }
    body.fade-out { opacity: 0; }
    .card {
      width: 100%;
      max-width: 480px;
      background: var(--card);
      border-radius: 18px;
      padding: 32px;
      box-shadow: var(--shadow);
      text-align: center;
      animation: fadeIn 0.6s ease, floaty 2.8s ease-in-out infinite;
    }
    .logo {
      width: 86px;
      height: 86px;
      margin: 0 auto 14px;
      border-radius: 22px;
      background: linear-gradient(145deg, var(--primary), #5c8ff9);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 14px 35px rgba(60, 111, 240, 0.35);
      font-size: 1.8rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }
    h1 { margin: 0 0 8px; font-size: 1.8rem; }
    p { margin: 0; color: var(--muted); }
    .spinner {
      margin: 18px auto 0;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: 4px solid #e5e9f5;
      border-top-color: var(--primary);
      animation: spin 1s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes floaty { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-4px); } }
  </style>
</head>
<body>
  <div class="card">
    <div class="logo" aria-hidden="true">AD</div>
    <h1>Attendance Dashboard</h1>
    <p>Loading Attendance Dashboard…</p>
    <div class="spinner" aria-hidden="true"></div>
  </div>
  <script>
    setTimeout(() => {
      document.body.classList.add('fade-out');
      setTimeout(() => { window.location.href = 'login.php'; }, 500);
    }, 5000);
  </script>
</body>
</html>
