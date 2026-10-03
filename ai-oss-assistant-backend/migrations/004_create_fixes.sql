-- NOTE: Per architecture decisions in project-plan.md & addendum-1,
-- raw diff content is NEVER stored in MySQL to prevent database storage bloat.
-- The fixes table holds only base_sha, head_sha, and explanation. Diffs are
-- fetched live on-demand via GitHub's Compare API in GET /api/fixes/{fixId}/diff.

CREATE TABLE IF NOT EXISTS fixes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  issue_description TEXT,
  branch_id INT,
  base_sha VARCHAR(40),
  head_sha VARCHAR(40),
  explanation TEXT,
  test_status ENUM('pending','passing','failing') DEFAULT 'pending',
  security_status ENUM('pending','clean','findings') DEFAULT 'pending',
  retry_count INT DEFAULT 0,
  merge_status ENUM('fixing','merged_to_fork','flagged_manual_review')
    DEFAULT 'fixing',
  source ENUM('automated','user_requested') DEFAULT 'automated',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
