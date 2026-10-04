-- Migration 018: Add findings JSON column to scan_results
-- Stores structured finding details (file, line, rule_id, severity, msg) for UI rendering

ALTER TABLE scan_results ADD COLUMN findings JSON NULL;
