-- Added attendance_sessions table definition for teacher session handling.
CREATE TABLE IF NOT EXISTS attendance_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id VARCHAR(50) DEFAULT NULL,
  group_id VARCHAR(50) DEFAULT NULL,
  date DATE NOT NULL,
  opened_by INT DEFAULT NULL,
  status ENUM('open', 'closed') DEFAULT 'open',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL
);
