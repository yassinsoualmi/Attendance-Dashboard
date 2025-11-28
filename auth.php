<?php
// Added session/auth helpers for role-based access control.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function current_user(): ?array
{
    if (isset($_SESSION['user'])) {
        return $_SESSION['user'];
    }
    // Fallback: reconstruct from flat session keys if present.
    if (isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['username'])) {
        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name'] ?? $_SESSION['username'],
            'email' => $_SESSION['email'] ?? '',
            'group_id' => $_SESSION['group_id'] ?? null,
            'course_id' => $_SESSION['course_id'] ?? null,
            'student_profile_id' => $_SESSION['student_profile_id'] ?? null,
            'student_id' => $_SESSION['student_id'] ?? null,
            'first_name' => $_SESSION['first_name'] ?? null,
            'last_name' => $_SESSION['last_name'] ?? null,
        ];
    }
    return null;
}

function require_login(): void
{
    if (!isset($_SESSION['user_id'], $_SESSION['role']) || !current_user()) {
        header('Location: login.php');
        exit;
    }
}

function require_role($roles): void
{
    require_login();
    $user = current_user();
    $roles = (array) $roles;
    if (!$user || !in_array($user['role'], $roles, true)) {
        redirect_home_by_role($user ? $user['role'] : null);
    }
}

function redirect_home_by_role(?string $role): void
{
    switch ($role) {
        case 'admin':
            header('Location: admin_dashboard.php');
            break;
        case 'teacher':
            header('Location: teacher_dashboard.php');
            break;
        case 'student':
            header('Location: student_dashboard.php');
            break;
        default:
            header('Location: login.php');
    }
    exit;
}

function require_not_logged_in(): void
{
    if (current_user()) {
        redirect_home_by_role($_SESSION['user']['role'] ?? null);
    }
}
