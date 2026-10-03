-- 012_add_learning_and_responsiveness_columns.sql

ALTER TABLE fixes ADD COLUMN confidence_score INT;
ALTER TABLE fixes ADD COLUMN critic_notes TEXT;
ALTER TABLE repos ADD COLUMN maintainer_responsiveness_score DECIMAL(5,2);
ALTER TABLE repos ADD COLUMN last_analyzed_sha VARCHAR(40);
ALTER TABLE users ADD COLUMN target_skills JSON;
