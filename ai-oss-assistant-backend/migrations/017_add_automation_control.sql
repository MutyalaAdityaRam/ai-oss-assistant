-- Migration 017: Add Automation Pipeline Controls (Active, Paused, Run Once)
-- Allows user to pause/resume automated repo searching, scanning, and fixing,
-- or run a single cycle for today and stop, while keeping PRs, chat, and deletion active.

ALTER TABLE users ADD COLUMN automation_status VARCHAR(32) NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN last_run_at DATETIME NULL;
ALTER TABLE users ADD COLUMN paused_at DATETIME NULL;
ALTER TABLE users ADD COLUMN run_once_at DATETIME NULL;
