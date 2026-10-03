-- 011_create_pr_outcomes.sql
CREATE TABLE IF NOT EXISTS pr_outcomes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pull_request_id INT NOT NULL,
  repo_id INT NOT NULL,
  outcome ENUM('merged','rejected','changes_requested','still_open') NOT NULL,
  days_to_resolution INT,
  fix_types JSON,
  maintainer_feedback_summary TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pull_request_id) REFERENCES pull_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
