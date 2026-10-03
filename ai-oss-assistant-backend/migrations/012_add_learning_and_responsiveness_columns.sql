-- 012_add_learning_and_responsiveness_columns.sql

ALTER TABLE fixes ADD COLUMN IF NOT EXISTS confidence_score INT;
ALTER TABLE fixes ADD COLUMN IF NOT EXISTS critic_notes TEXT;
ALTER TABLE repos ADD COLUMN IF NOT EXISTS maintainer_responsiveness_score DECIMAL(5,2);
ALTER TABLE repos ADD COLUMN IF NOT EXISTS last_analyzed_sha VARCHAR(40);
ALTER TABLE users ADD COLUMN IF NOT EXISTS target_skills JSON;
