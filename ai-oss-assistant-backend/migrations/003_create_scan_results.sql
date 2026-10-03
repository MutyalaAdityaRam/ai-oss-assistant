CREATE TABLE IF NOT EXISTS scan_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  tool ENUM('semgrep','codeql','trivy','gitleaks','zap','newman') NOT NULL,
  finding_count INT DEFAULT 0,
  severity_summary JSON,
  artifact_url VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
