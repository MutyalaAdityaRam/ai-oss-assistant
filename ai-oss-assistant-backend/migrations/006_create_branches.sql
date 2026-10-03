CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fork_id INT NOT NULL,
  fix_id INT,
  branch_name VARCHAR(255) NOT NULL,
  base_branch VARCHAR(100) DEFAULT 'main',
  status ENUM('fixing','merged','abandoned') DEFAULT 'fixing',
  merged_at TIMESTAMP NULL,
  FOREIGN KEY (fork_id) REFERENCES forks(id) ON DELETE CASCADE,
  FOREIGN KEY (fix_id) REFERENCES fixes(id) ON DELETE SET NULL
) ENGINE=InnoDB;
