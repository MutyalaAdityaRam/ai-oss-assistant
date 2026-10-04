-- Addendum: Finding Triage, Full-Cycle Processing, Consolidated Changelog
-- Schema additions for tiering and structured PR changelog

ALTER TABLE fixes ADD COLUMN priority_tier ENUM('primary','secondary') NOT NULL DEFAULT 'primary';

ALTER TABLE fixes ADD COLUMN priority_rank INT DEFAULT 999;

ALTER TABLE pull_requests ADD COLUMN changelog JSON DEFAULT NULL;

