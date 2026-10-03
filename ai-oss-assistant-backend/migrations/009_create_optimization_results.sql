-- 009_create_optimization_results.sql
-- Stores cyclomatic complexity (Lizard) and wall-clock test suite runtime deltas.
-- NOTE: Cyclomatic complexity measures independent paths through code, NOT algorithmic (Big-O) complexity.

CREATE TABLE IF NOT EXISTS optimization_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fix_id INT NOT NULL,
  complexity_tool VARCHAR(50) DEFAULT 'lizard',
  complexity_before INT,
  complexity_after INT,
  test_suite_duration_ms_before INT,
  test_suite_duration_ms_after INT,
  runtime_delta_pct DECIMAL(6,2),
  summary TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (fix_id) REFERENCES fixes(id) ON DELETE CASCADE
) ENGINE=InnoDB;
