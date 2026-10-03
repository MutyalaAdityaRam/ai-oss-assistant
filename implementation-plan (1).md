# Detailed Implementation Plan

Companion to `project-plan.md` — that document covers architecture and decisions;
this one covers exact folders, files, endpoints, and build order.

---

## 1. Repository structure

Three separate repos (cleanest split given three different hosting targets):

```
ai-oss-assistant-backend/          → deployed to Hostinger Basic (PHP)
  public/
    index.php                      → single entry point, routes all API requests
    webhooks/
      github.php                   → receives GitHub Actions completion webhooks
      github-pr-status.php         → receives PR review/merge webhooks
  src/
    Router.php
    Controllers/
      RepoController.php
      FixController.php
      ChatController.php
      PullRequestController.php
    Services/
      GitHubService.php            → all GitHub API calls (fork, branch, PR, etc.)
      LLMService.php                → Claude API calls via cURL
      RankingService.php            → repo scoring logic
      NotificationService.php       → builds + queues digest emails
      DevContainerResolver.php      → decides which devcontainer template to use
    Models/
      Repo.php
      Fix.php
      Fork.php
      Branch.php
      PullRequest.php
      NotificationQueue.php
    Database.php                   → PDO MySQL connection wrapper
  cron/
    discover-repos.php              → daily: find + rank candidate repos
    poll-pending-jobs.php           → every 5-15 min: advance job states
    send-digest.php                 → daily: batch notification send
    expire-forks.php                → daily: delete forks past 14-day window
  migrations/
    001_create_users.sql
    002_create_repos.sql
    003_create_scan_results.sql
    004_create_fixes.sql
    005_create_forks.sql
    006_create_branches.sql
    007_create_notification_queue.sql
    008_create_pull_requests.sql
  .env.example
  composer.json

ai-oss-assistant-frontend/         → deployed to Vercel (Next.js)
  app/
    dashboard/page.tsx
    repo/[id]/page.tsx
    chat/[repoId]/page.tsx
  lib/
    api.ts                          → fetch wrapper calling the PHP backend
  .env.example

ai-oss-assistant-actions/          → lives inside each analyzed fork,
                                      or as a reusable workflow called via
                                      workflow_dispatch from the backend
  .github/workflows/
    analyze.yml                     → clone + static analysis + baseline tests
    fix.yml                         → fix-agent + rescan loop
    runtime-scan.yml                → start app + ZAP + Newman
  scripts/
    detect-environment.sh           → devcontainer resolution logic
    merge-scan-results.py           → merges Semgrep/CodeQL/Trivy/Gitleaks JSON
                                       (small Python script, runs INSIDE the
                                       Action, not on the PHP backend)
```

---

## 2. Database migrations (MySQL, run in order)

```sql
-- 001_create_users.sql
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  github_installation_id VARCHAR(64) NOT NULL,
  email VARCHAR(255) NOT NULL,
  spend_cap_usd DECIMAL(10,2) DEFAULT 5.00,
  digest_frequency ENUM('daily','every_3_days','weekly') DEFAULT 'daily',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 002_create_repos.sql
CREATE TABLE repos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(255) NOT NULL,
  stars INT DEFAULT 0,
  last_activity DATE,
  resume_score DECIMAL(5,2),
  status ENUM('candidate','analyzing','clean_deleted','bugs_found',
              'forked','pr_open','expired') DEFAULT 'candidate',
  devcontainer_config JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_full_name (full_name)
) ENGINE=InnoDB;

-- 003_create_scan_results.sql
CREATE TABLE scan_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  tool ENUM('semgrep','codeql','trivy','gitleaks','zap','newman') NOT NULL,
  finding_count INT DEFAULT 0,
  severity_summary JSON,
  artifact_url VARCHAR(500),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 004_create_fixes.sql
CREATE TABLE fixes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  issue_description TEXT,
  branch_id INT,
  diff_artifact_url VARCHAR(500),
  test_status ENUM('pending','passing','failing') DEFAULT 'pending',
  security_status ENUM('pending','clean','findings') DEFAULT 'pending',
  retry_count INT DEFAULT 0,
  merge_status ENUM('fixing','merged_to_fork','flagged_manual_review')
    DEFAULT 'fixing',
  source ENUM('automated','user_requested') DEFAULT 'automated',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 005_create_forks.sql
CREATE TABLE forks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  user_id INT NOT NULL,
  fork_url VARCHAR(500) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 006_create_branches.sql
CREATE TABLE branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fork_id INT NOT NULL,
  fix_id INT,
  branch_name VARCHAR(255) NOT NULL,
  base_branch VARCHAR(100) DEFAULT 'main',
  status ENUM('fixing','merged','abandoned') DEFAULT 'fixing',
  merged_at TIMESTAMP NULL,
  FOREIGN KEY (fork_id) REFERENCES forks(id) ON DELETE CASCADE,
  FOREIGN KEY (fix_id) REFERENCES fixes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 007_create_notification_queue.sql
CREATE TABLE notification_queue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  type ENUM('repo_report','security_flag','manual_review','pr_status') NOT NULL,
  payload JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  batch_id VARCHAR(64),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 008_create_pull_requests.sql
CREATE TABLE pull_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  repo_id INT NOT NULL,
  fork_id INT NOT NULL,
  pr_url VARCHAR(500),
  status ENUM('open','merged','closed','none') DEFAULT 'none',
  fixes_included JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (repo_id) REFERENCES repos(id) ON DELETE CASCADE,
  FOREIGN KEY (fork_id) REFERENCES forks(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

## 3. Backend API endpoints (PHP)

| Method | Route | Purpose |
|---|---|---|
| GET | `/api/repos` | List repos + status for the dashboard |
| GET | `/api/repos/{id}` | Repo detail: summary, findings, fixes |
| GET | `/api/repos/{id}/report` | Full report (bugs fixed, security fixed) |
| POST | `/api/repos/{id}/approve-pr` | User clicks "create PR" — triggers §6 step 7 |
| POST | `/api/repos/{id}/decline-pr` | User declines — hands off to chat agent |
| POST | `/api/chat/{repoId}` | Chat message in → agent tool-calling loop |
| POST | `/api/chat/{repoId}/fix` | User asks agent to fix something (Path A/B router from the "how does a user-requested fix work" logic) |
| POST | `/webhooks/github.php` | GitHub Actions job completion → advance state |
| POST | `/webhooks/github-pr-status.php` | PR review/merge events → update `pull_requests` |
| GET | `/api/notifications/pending` | Dashboard "pending decisions" list (§ fork cleanup) |
| POST | `/api/settings` | Spend cap, digest frequency |

Auth: a single bearer token (GitHub App installation-scoped) issued at setup, checked on every route via middleware in `Router.php`.

---

## 4. GitHub Actions workflow skeletons

**`analyze.yml`** (triggered by backend via `workflow_dispatch`)
```yaml
name: Analyze Repo
on:
  workflow_dispatch:
    inputs:
      repo_full_name: { required: true }
      fork_url: { required: true }
      repo_id: { required: true }
jobs:
  analyze:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with: { repository: ${{ github.event.inputs.fork_url }} }
      - name: Resolve devcontainer
        run: bash scripts/detect-environment.sh
      - uses: devcontainers/ci@v0.3
        with:
          runCmd: |
            npm test || true   # capture baseline, don't fail the job on it
      - name: Semgrep
        run: docker run --rm -v "$PWD:/src" semgrep/semgrep semgrep --config auto --json > semgrep.json
      - name: CodeQL
        uses: github/codeql-action/analyze@v3
      - name: Trivy
        run: docker run --rm -v "$PWD:/src" aquasec/trivy fs --format json /src > trivy.json
      - name: Gitleaks
        run: docker run --rm -v "$PWD:/src" zricethezav/gitleaks detect --report-format json --report-path gitleaks.json
      - name: Merge findings
        run: python3 scripts/merge-scan-results.py
      - name: Report back to backend
        run: |
          curl -X POST https://yourdomain.com/webhooks/github.php \
            -H "Content-Type: application/json" \
            -d @merged-findings.json
```

**`fix.yml`** (triggered once findings exist)
```yaml
name: Fix Issue
on:
  workflow_dispatch:
    inputs:
      fork_url: { required: true }
      branch_name: { required: true }
      fix_id: { required: true }
      issue_description: { required: true }
jobs:
  fix:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          repository: ${{ github.event.inputs.fork_url }}
          ref: ${{ github.event.inputs.branch_name }}
      - uses: devcontainers/ci@v0.3
        with:
          runCmd: |
            pip install openhands-ai --break-system-packages
            openhands --task "${{ github.event.inputs.issue_description }}"
            npm test
      - name: Rescan (Semgrep + Gitleaks only, fast pass)
        run: |
          docker run --rm -v "$PWD:/src" semgrep/semgrep semgrep --config auto --json > semgrep.json
          docker run --rm -v "$PWD:/src" zricethezav/gitleaks detect --report-format json --report-path gitleaks.json
      - name: Report result
        run: curl -X POST https://yourdomain.com/webhooks/github.php -d @result.json
```

The retry loop (up to 5 attempts) is controlled by the **backend**, not the workflow itself — the backend receives each result via webhook and re-triggers `fix.yml` again if not yet clean, incrementing `fixes.retry_count` until the cap in `project-plan.md §11` is hit.

---

## 5. Cron scripts (Hostinger hPanel → Cron Jobs)

| Script | Schedule | Does |
|---|---|---|
| `cron/discover-repos.php` | Daily, e.g. 2:00 AM | GitHub Search API → score → insert `candidate` repos |
| `cron/poll-pending-jobs.php` | Every 10 min | Checks for repos stuck in `analyzing`/`fixing` too long, checks GitHub Actions run status as a backup to webhooks, advances state |
| `cron/send-digest.php` | Daily, per user's `digest_frequency` | Batches `notification_queue` → one email (project-plan.md §12) |
| `cron/expire-forks.php` | Daily | Deletes forks past `expires_at`, notifies user |

---

## 6. Build order (maps to the 5 phases in `project-plan.md §14`, now file-level)

**Phase 1 — MVP**
1. Set up Hostinger Basic: PHP version, MySQL database, run migrations 001-003
2. Build `GitHubService.php`: auth via GitHub App, search repos, fork, create branch
3. Build `cron/discover-repos.php` + `RankingService.php` (basic scoring)
4. Build `analyze.yml`, wire up Semgrep only first (add others once this works end-to-end)
5. Build `/webhooks/github.php` to receive results, update `repos.status`
6. Basic Next.js dashboard: repo list, scan result view, deploy to Vercel
7. **Milestone: one repo goes from discovery → clone → Semgrep scan → result shown in dashboard, fully automated**

**Phase 2 — Fix loop**
1. Migrations 004-006 (`fixes`, `forks`, `branches`)
2. Add CodeQL, Trivy, Gitleaks to `analyze.yml`; build `merge-scan-results.py`
3. Build `fix.yml` with OpenHands integration
4. Build retry-cap logic in `poll-pending-jobs.php` / webhook handler
5. Build `LLMService.php`, wire report generation
6. Build `NotificationService.php` + `cron/send-digest.php`
7. **Milestone: a bug is found, fixed, verified, and the user gets one digest email**

**Phase 3 — Automation + PR**
1. Migrations 007-008
2. Auto-fork logic (no permission gate) wired into the discovery flow
3. Branch-merge-to-fork-default logic (project-plan.md §6 steps 3-5)
4. `PullRequestController.php` + `/api/repos/{id}/approve-pr`
5. `/webhooks/github-pr-status.php` for tracking
6. `cron/expire-forks.php`
7. **Milestone: full daily loop runs with zero manual steps except the PR approval click**

**Phase 4 — Runtime security + chat agent**
1. `runtime-scan.yml` (ZAP + Newman, only if repo has a start script)
2. `ChatController.php` + tool-calling loop (read_file/edit_file/commit/delete_fork/create_pr)
3. Path A/B router for user-requested fixes (from the earlier "how a user-requested fix works" answer)
4. Chat UI in the frontend

**Phase 5 — Hardening**
1. `CONTRIBUTING.md`/AI-policy check before forking
2. Spend cap enforcement in `LLMService.php` (check `users.spend_cap_usd` before each call)
3. Rate limiting on discovery volume
4. Basic uptime/error alerting on the PHP backend (simple email-on-exception is enough at this scale)

---

## 7. Environment variables / secrets checklist

```
# Backend (.env on Hostinger)
GITHUB_APP_ID=
GITHUB_APP_PRIVATE_KEY=
GITHUB_WEBHOOK_SECRET=
ANTHROPIC_API_KEY=
DB_HOST=
DB_NAME=
DB_USER=
DB_PASS=
RESEND_API_KEY=

# GitHub Actions (repo secrets on the backend-controlled workflows repo)
ANTHROPIC_API_KEY=          (same key, scoped for the fix agent)
BACKEND_WEBHOOK_URL=
BACKEND_WEBHOOK_SECRET=

# Frontend (Vercel env vars)
NEXT_PUBLIC_API_BASE_URL=
```

---

## 8. Definition of done for MVP

- [ ] One public repo goes through the full loop unattended: discover → clone → scan → (if bugs) fork → fix → verify → merge to fork default → single digest email → user clicks approve → PR opens upstream
- [ ] No raw scanner JSON stored in MySQL (artifact links only)
- [ ] Retry cap enforced and tested (force a fix to fail 5x, confirm it flags for manual review instead of looping forever)
- [ ] Fork auto-expires after 14 days in a test with a shortened window
- [ ] Digest email caps verified (max 1/day routine, max 3/day including exceptions)
