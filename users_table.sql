-- Added users table definition for authentication and roles.
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

-- Default admin (username: admin / password: admin123) for first login (idempotent).
INSERT INTO users (username, password_hash, role, full_name, email)
SELECT 'admin', '$2y$10$H4JXv6aWZPFGnxyzATuwZem6YAnDMHzx6gV/1EO3d2vwsFQImWQ5e', 'admin', 'Default Admin', 'admin@example.com'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');
