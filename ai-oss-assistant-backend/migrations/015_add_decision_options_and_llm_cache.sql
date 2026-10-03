-- 015_add_decision_options_and_llm_cache.sql

ALTER TABLE fixes ADD COLUMN decision_options JSON;

CREATE TABLE IF NOT EXISTS llm_cache (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cache_key VARCHAR(64) NOT NULL,
  cache_type ENUM('repo_summary','ast_parse','finding_explanation','embedding') NOT NULL,
  result_ref VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_cache_key_type (cache_key, cache_type)
) ENGINE=InnoDB;
