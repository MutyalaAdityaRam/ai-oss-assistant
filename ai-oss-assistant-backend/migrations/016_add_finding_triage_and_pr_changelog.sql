-- Addendum: Finding Triage, Full-Cycle Processing, Consolidated Changelog
-- Schema additions for tiering and structured PR changelog

ALTER TABLE fixes ADD COLUMN IF NOT EXISTS priority_tier ENUM('primary','secondary')
  NOT NULL DEFAULT 'primary';

ALTER TABLE fixes ADD COLUMN IF NOT EXISTS priority_rank INT DEFAULT 999;

ALTER TABLE pull_requests ADD COLUMN IF NOT EXISTS changelog JSON DEFAULT NULL;
