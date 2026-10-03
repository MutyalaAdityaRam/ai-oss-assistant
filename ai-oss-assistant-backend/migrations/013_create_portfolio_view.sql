-- 013_create_portfolio_view.sql

CREATE OR REPLACE VIEW portfolio_summary AS
SELECT
  pr.id AS pull_request_id,
  pr.repo_id,
  r.full_name,
  pr.pr_url,
  pr.status,
  pr.created_at,
  f.source AS fix_source
FROM pull_requests pr
JOIN repos r ON pr.repo_id = r.id
LEFT JOIN fixes f ON f.repo_id = r.id
WHERE pr.status = 'merged';
