# Attendance-Dashboard

Attendance dashboard with role-based access (admin, teacher, student), MySQL storage, and jQuery-powered UI.

## Quick start
1) Create the database and tables: import `schema.sql` (or individual `*_table.sql` files) into your MySQL/MariaDB instance.
2) Configure credentials in `config.php`.
3) Place the project in your WAMP `www` folder (e.g., `C:\wamp64\www\Attendance-Dashboard\`).
4) Browse to `http://localhost/Attendance-Dashboard/login.php`.

The first login after installation seeds a default admin account:
- Username: `admin`
- Password: `admin123`

Student logins are auto-created from student records:
- Username = `student_id`
- Temporary password = student's first name in lowercase (no spaces; change it after first login).

## Roles
- **Admin**: manage students (CRUD), view global dashboard.
- **Teacher**: create sessions, take/edit attendance, view dashboard filtered to their group.
- **Student**: view personal attendance report and submit justification placeholders.

## Key endpoints
- `index.php` / `welcome.php`: splash screen that redirects to login after 5 seconds.
- `admin_dashboard.php`: protected dashboard for admin (keeps original UI layout).
- `student_dashboard.php`: student view.
- `take_attendance.php?session_id=ID`: per-session attendance editor.
- APIs: `add_student.php`, `update_student.php`, `delete_student.php`, `list_students.php`, `create_session.php`, `close_session.php`, `list_sessions.php`.

## Notes
- `.htaccess` sets `DirectoryIndex index.php` so the welcome screen loads first.
- All database queries use prepared statements; adjust `config.php` for your local credentials.
