-- Added justifications table placeholder for absence justifications workflow.
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
