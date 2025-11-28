-- Added unified schema for Attendance Dashboard (users, students, sessions, records, justifications).

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin', 'teacher', 'student') NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(120) DEFAULT NULL,
  student_id VARCHAR(50) DEFAULT NULL,
  group_id VARCHAR(50) DEFAULT NULL,
  course_id VARCHAR(50) DEFAULT NULL,
  student_profile_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(50) NOT NULL UNIQUE,
  last_name VARCHAR(80) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  email VARCHAR(120) DEFAULT NULL,
  module VARCHAR(120) DEFAULT NULL,
  section VARCHAR(50) DEFAULT NULL,
  group_id VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  teacher_id VARCHAR(50) NOT NULL UNIQUE,
  last_name VARCHAR(80) NOT NULL,
  first_name VARCHAR(80) NOT NULL,
  email VARCHAR(120) DEFAULT NULL,
  module VARCHAR(120) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS attendance_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  module VARCHAR(120) DEFAULT NULL,
  section VARCHAR(50) DEFAULT NULL,
  course_id VARCHAR(50) DEFAULT NULL,
  group_id VARCHAR(50) DEFAULT NULL,
  date DATE NOT NULL,
  opened_by INT DEFAULT NULL,
  teacher_id INT DEFAULT NULL,
  status ENUM('open', 'closed') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS attendance_records (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  student_id INT NOT NULL,
  status ENUM('present', 'absent') NOT NULL,
  participated TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_session_student (session_id, student_id),
  FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS justifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  session_id INT DEFAULT NULL,
  reason TEXT,
  file_path VARCHAR(255) DEFAULT NULL,
  status ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE SET NULL
);

-- Optional starter admin (username: admin, password: admin123) - ensure inserted only once.
INSERT INTO users (username, password_hash, role, full_name, email)
SELECT 'admin', '$2y$10$H4JXv6aWZPFGnxyzATuwZem6YAnDMHzx6gV/1EO3d2vwsFQImWQ5e', 'admin', 'Default Admin', 'admin@example.com'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');
