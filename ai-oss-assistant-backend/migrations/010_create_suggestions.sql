-- 010_create_suggestions.sql
-- Stores cited research-based domain improvement suggestions and user custom ideas.

CREATE TABLE IF NOT EXISTS suggestions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  rationale TEXT,
  source_links JSON,
  effort_estimate ENUM('small','medium','large') DEFAULT 'medium',
  status ENUM('proposed','selected','implemented','skipped','rejected') DEFAULT 'proposed',
  fix_id INT,
  source ENUM('ai_research','user_custom') DEFAULT 'ai_research',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE,
  FOREIGN KEY (fix_id) REFERENCES fixes(id) ON DELETE SET NULL
) ENGINE=InnoDB;
