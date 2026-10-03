CREATE TABLE IF NOT EXISTS repos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  stars INT DEFAULT 0,
  last_activity DATE,
  resume_score DECIMAL(5,2),
  status ENUM('candidate','analyzing','clean_deleted','bugs_found',
              'forked','pr_open','expired') DEFAULT 'candidate',
  devcontainer_config JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_full_name (full_name)
) ENGINE=InnoDB;
