<?php
// Added shared helpers for attendance calculations and data shaping.
require_once __DIR__ . '/db_connect.php';

function compute_status(int $absences, int $participation): array
{
    if ($absences >= 5) {
        return [
            'message' => 'Excluded - too many absences. You need to participate more',
            'rowClass' => 'red',
        ];
    }

    $attendancePart = $absences <= 2 ? 'Good attendance' : 'Average attendance';
    if ($participation >= 5) {
        $participationPart = 'Excellent participation';
    } elseif ($participation >= 3) {
        $participationPart = 'Good participation';
    } else {
        $participationPart = 'Needs to participate more';
    }

    return [
        'message' => "{$attendancePart} - {$participationPart}",
        'rowClass' => $absences <= 2 ? 'green' : 'yellow',
    ];
}

/**
 * Normalize the temporary password seed for students (lowercase, trimmed, spaces removed, accents stripped when possible).
 */
function normalize_student_temp_password(string $firstName): string
{
    $base = trim($firstName);
    if ($base === '') {
        return 'student';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
    if ($ascii !== false && $ascii !== null) {
        $base = $ascii;
    }
    $base = strtolower($base);
    $base = preg_replace('/\s+/', '', $base);
    return $base === '' ? 'student' : $base;
}

/**
 * Ensure users.student_id exists for legacy databases.
 */
function ensure_user_student_id_column(PDO $pdo): void
{
    if (!column_exists($pdo, 'users', 'student_id')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN student_id VARCHAR(50) DEFAULT NULL AFTER email");
    }
}

/**
 * Auto-create or update a linked student user account using student_id as username.
 * Auto-create linked user account for this student: username = student_id, password = lowercase first name.
 */
function ensure_student_user_account(PDO $pdo, array $student, bool $resetPassword = false): array
{
    ensure_user_student_id_column($pdo);

    $username = $student['student_id'] ?? '';
    if ($username === '') {
        return ['created' => false, 'updated' => false];
    }

    $fullName = trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? ''));
    if ($fullName === '') {
        $fullName = $username;
    }
    $passwordSeed = normalize_student_temp_password((string) ($student['first_name'] ?? ''));
    $passwordHash = password_hash($passwordSeed, PASSWORD_DEFAULT);
    $studentProfileId = $student['id'] ?? null;
    $groupId = $student['group_id'] ?? null;
    $email = $student['email'] ?? null;

    $existingStmt = $pdo->prepare("
        SELECT id, password_hash
        FROM users
        WHERE student_id = :sid OR username = :sid
        LIMIT 1
    ");
    $existingStmt->execute([':sid' => $username]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $sql = "
            UPDATE users
            SET username = :username,
                role = 'student',
                full_name = :full_name,
                email = :email,
                student_id = :student_id,
                group_id = :group_id,
                student_profile_id = :student_profile_id
        ";
        $params = [
            ':username' => $username,
            ':full_name' => $fullName,
            ':email' => $email,
            ':student_id' => $username,
            ':group_id' => $groupId,
            ':student_profile_id' => $studentProfileId,
            ':id' => $existing['id'],
        ];
        if ($resetPassword) {
            $sql .= ", password_hash = :password_hash";
            $params[':password_hash'] = $passwordHash;
        }
        $sql .= " WHERE id = :id";
        $update = $pdo->prepare($sql);
        $update->execute($params);
        return ['created' => false, 'updated' => true];
    }

    $insert = $pdo->prepare("
        INSERT INTO users (username, password_hash, role, full_name, email, student_id, group_id, student_profile_id)
        VALUES (:username, :password_hash, 'student', :full_name, :email, :student_id, :group_id, :student_profile_id)
    ");
    $insert->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':full_name' => $fullName,
        ':email' => $email,
        ':student_id' => $username,
        ':group_id' => $groupId,
        ':student_profile_id' => $studentProfileId,
    ]);

    return ['created' => true, 'updated' => false];
}

/**
 * Ensure a teacher user account exists and is synced.
 * Username defaults to email if provided, otherwise teacher_id.
 */
function ensure_teacher_user_account(PDO $pdo, array $teacher, bool $allowCreate = true, ?string $tempPassword = null, bool $resetPassword = false): array
{
    $username = $teacher['email'] ?? '';
    if ($username === '') {
        $username = $teacher['teacher_id'] ?? '';
    }
    if ($username === '') {
        return ['created' => false, 'updated' => false];
    }

    $fullName = trim(($teacher['first_name'] ?? '') . ' ' . ($teacher['last_name'] ?? ''));
    if ($fullName === '') {
        $fullName = $username;
    }
    $course = $teacher['module'] ?? null;
    $email = $teacher['email'] ?? null;

    $existingStmt = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
    $existingStmt->execute([':username' => $username]);
    $existing = $existingStmt->fetch();

    if ($existing) {
        $sql = "
            UPDATE users
            SET role = 'teacher',
                full_name = :full_name,
                email = :email,
                course_id = :course_id
        ";
        $params = [
            ':full_name' => $fullName,
            ':email' => $email,
            ':course_id' => $course,
            ':id' => $existing['id'],
        ];
        if ($resetPassword) {
            $passwordHash = password_hash($tempPassword ?: 'teacher123', PASSWORD_DEFAULT);
            $sql .= ", password_hash = :password_hash";
            $params[':password_hash'] = $passwordHash;
        }
        $sql .= " WHERE id = :id";
        $update = $pdo->prepare($sql);
        $update->execute($params);
        return ['created' => false, 'updated' => true];
    }

    if (!$allowCreate) {
        return ['created' => false, 'updated' => false];
    }

    $passwordHash = password_hash($tempPassword ?: 'teacher123', PASSWORD_DEFAULT);
    $insert = $pdo->prepare("
        INSERT INTO users (username, password_hash, role, full_name, email, course_id)
        VALUES (:username, :password_hash, 'teacher', :full_name, :email, :course_id)
    ");
    $insert->execute([
        ':username' => $username,
        ':password_hash' => $passwordHash,
        ':full_name' => $fullName,
        ':email' => $email,
        ':course_id' => $course,
    ]);
    return ['created' => true, 'updated' => false];
}

function fetch_student_summaries(PDO $pdo, ?string $groupFilter = null, ?int $studentIdFilter = null): array
{
    $params = [];
    $where = [];
    $hasModule = column_exists($pdo, 'students', 'module');
    $hasSection = column_exists($pdo, 'students', 'section');

    if ($groupFilter !== null && $groupFilter !== '') {
        $where[] = 's.group_id = :group_id';
        $params[':group_id'] = $groupFilter;
    }
    if ($studentIdFilter !== null) {
        $where[] = 's.id = :student_id';
        $params[':student_id'] = $studentIdFilter;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $selectCols = [
        's.id',
        's.student_id',
        's.last_name',
        's.first_name',
        's.email',
        's.group_id',
    ];
    if ($hasModule) {
        $selectCols[] = 's.module';
    }
    if ($hasSection) {
        $selectCols[] = 's.section';
    }
    $selectSql = implode(', ', $selectCols);

    $stmt = $pdo->prepare("
        SELECT {$selectSql}
        FROM students s
        {$whereSql}
        ORDER BY s.last_name ASC, s.first_name ASC
    ");
    $stmt->execute($params);
    $students = $stmt->fetchAll();

    if (!$students) {
        return [];
    }

    $studentIds = array_column($students, 'id');
    $inPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));
    $recordsStmt = $pdo->prepare("
        SELECT ar.student_id, ar.status, ar.participated, sess.date
        FROM attendance_records ar
        INNER JOIN attendance_sessions sess ON sess.id = ar.session_id
        WHERE ar.student_id IN ($inPlaceholders)
        ORDER BY sess.date ASC, ar.id ASC
    ");
    $recordsStmt->execute($studentIds);
    $records = $recordsStmt->fetchAll();

    $byStudent = [];
    foreach ($records as $rec) {
        $byStudent[$rec['student_id']][] = $rec;
    }

    foreach ($students as &$student) {
        $sid = (int) $student['id'];
        $absences = 0;
        $participation = 0;
        $marks = [];
        $recordsForStudent = $byStudent[$sid] ?? [];

        foreach ($recordsForStudent as $rec) {
            $status = strtolower($rec['status'] ?? '');
            $marks[] = $status === 'present' ? 'P' : 'A';
            if ($status === 'absent') {
                $absences++;
            } else {
                // present counts as not absent
            }
            if ((int) ($rec['participated'] ?? 0) === 1) {
                $participation++;
            }
        }

        $marks = array_slice($marks, -6); // keep last 6 entries
        while (count($marks) < 6) {
            $marks[] = '';
        }

        $student['absences'] = $absences;
        $student['participations'] = $participation;
        $student['marks'] = $marks;
        $student['status'] = compute_status($absences, $participation);
    }
    unset($student);

    return $students;
}

function fetch_groups(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT DISTINCT group_id FROM students WHERE group_id IS NOT NULL AND group_id <> '' ORDER BY group_id ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function resolve_student_row(PDO $pdo, array $user): ?array
{
    $studentProfileId = $user['student_profile_id'] ?? ($_SESSION['student_profile_id'] ?? null);
    if ($studentProfileId) {
        $directStmt = $pdo->prepare("SELECT * FROM students WHERE id = :id LIMIT 1");
        $directStmt->execute([':id' => $studentProfileId]);
        $row = $directStmt->fetch();
        if ($row) {
            $_SESSION['student_profile_id'] = (int) $row['id'];
            return $row;
        }
    }

    $username = $user['username'] ?? '';
    $email = $user['email'] ?? '';
    if ($username === '' && $email === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM students
        WHERE student_id = :sid OR email = :email
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([':sid' => $username, ':email' => $email]);
    $row = $stmt->fetch();
    if ($row) {
        $_SESSION['student_profile_id'] = (int) $row['id'];
        return $row;
    }

    return null;
}
