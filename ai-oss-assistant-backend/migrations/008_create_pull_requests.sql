CREATE TABLE IF NOT EXISTS pull_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  fork_id INT NOT NULL,
  pr_url VARCHAR(500),
  status ENUM('open','merged','closed','none') DEFAULT 'none',
  fixes_included JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE,
  FOREIGN KEY (fork_id) REFERENCES forks(id) ON DELETE CASCADE
) ENGINE=InnoDB;
