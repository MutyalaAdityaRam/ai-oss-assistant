-- 014_add_indexes_and_optimizations.sql
-- Optimizes MySQL query performance with composite B-Tree indexes across all core tables.

CREATE INDEX idx_repos_status ON repos(status);
CREATE INDEX idx_repos_score ON repos(resume_score);
CREATE INDEX idx_repos_responsiveness ON repos(maintainer_responsiveness_score);

CREATE INDEX idx_scan_results_repo_tool ON scan_results(repo_id, tool);

CREATE INDEX idx_fixes_repo_status ON fixes(repo_id, merge_status);
CREATE INDEX idx_fixes_source ON fixes(source);

CREATE INDEX idx_forks_repo_expires ON forks(repo_id, expires_at);

CREATE INDEX idx_branches_fork_status ON branches(fork_id, status);

CREATE INDEX idx_notification_queue_user_sent ON notification_queue(user_id, sent_at);

CREATE INDEX idx_pull_requests_repo_status ON pull_requests(repo_id, status);

CREATE INDEX idx_suggestions_repo_status ON suggestions(repo_id, status);

CREATE INDEX idx_pr_outcomes_repo_outcome ON pr_outcomes(repo_id, outcome);
