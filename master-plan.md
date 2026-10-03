# AI Open Source Contribution Assistant — Master Plan

This is the single consolidated reference: every tool, the full flow, how each
agent behaves, how the APIs and security work, and how GitHub Actions executes
everything. Companion files: `project-plan.md` (decision history) and
`implementation-plan.md` (file-level build plan).

---

## 1. Complete tool list

| Tool | Role | Free? | Runs where |
|---|---|---|---|
| GitHub API (REST + GraphQL) | Discovery, forking, branching, PRs | Free | PHP backend |
| GitHub App | Auth — one-time install grants ongoing access | Free | Backend holds installation token |
| `devcontainers/cli` | Resolves correct environment per repo | Free, open-source | GitHub Actions |
| Semgrep CE | Bug/pattern static analysis | Free | GitHub Actions (Docker image) |
| CodeQL | Deep security static analysis | Free (public repos) | GitHub Actions (native) |
| Trivy | Dependency vulnerability scanning | Free | GitHub Actions (Docker image) |
| Gitleaks | Secret/API-key leak detection | Free | GitHub Actions (Docker image) |
| OWASP ZAP | Runtime web/API security scan | Free | GitHub Actions, sandboxed instance only |
| Postman/Newman | API functional + security testing | Free tier | GitHub Actions |
| OpenHands (or Aider) | Code-fix generation agent | Free, open-source | GitHub Actions (Python, inside devcontainer) |
| Sourcegraph Cody | Repo understanding / code graph | Free tier / self-host | Backend calls it as a service |
| NVIDIA API (OpenAI GPT-OSS) | Summaries, bug explanations, chat agent, decision scorecard | Multi-tier failover (gpt-oss-120b & gpt-oss-20b) | PHP backend, via plain cURL |
| MySQL | All persistent state | Free (Hostinger Basic, already owned) | Hostinger |
| PHP | Backend orchestrator | Free (Hostinger Basic, already owned) | Hostinger |
| Next.js | Frontend dashboard | Free (Vercel Hobby) | Vercel |
| Resend | Digest email delivery | Free tier | Called from PHP backend |

**Deliberately excluded:**
- **Nmap** — port-scan-like traffic risks cloud-provider abuse flags even sandboxed; ZAP + Newman already cover the practical runtime security surface.
- **SonarQube** — needs a persistently-running server, deferred past MVP; Semgrep + CodeQL cover most of the same ground for now.
- **Burp Suite Professional** — paid, no free equivalent needed since ZAP covers the same category free.
- **n8n / Redis / a Node.js backend** — replaced by PHP + MySQL + cron polling once the decision was made to run on already-owned Hostinger Basic hosting.

---

## 2. The full flow (see diagram above)

```
Daily cron (Hostinger) → discover-repos.php
  ↓
Rank candidates → top N marked `candidate` in MySQL
  ↓
For each: trigger analyze.yml (GitHub Actions)
  ↓
  Resolve devcontainer → clone → baseline tests →
  Semgrep + CodeQL + Trivy + Gitleaks → merge findings → webhook back
  ↓
No findings → delete clone, mark `clean_deleted`, next repo
Findings exist → auto-fork (no permission needed)
  ↓
  For each finding: new `ai-fix/*` branch → trigger fix.yml
    OpenHands/Aider fixes it → rescan (Semgrep + Gitleaks) → build + test
    → loop until clean or retry cap (5) hit
    → clean: merge branch into fork's default branch, delete branch
    → cap hit: flag for manual review, leave unmerged, don't block other issues
  ↓
All issues in this cycle resolved → one consolidated report generated (LLM)
  ↓
Queue notification (never sent immediately — batched, see §5)
  ↓
User: "Create PR?" — the one manual gate in the entire system
  Yes → open ONE PR (fork default → upstream), track via webhook
  No  → hand off to chat agent (§3c) for edits/redo/delete
  ↓
Fork with no PR decision after 14 days → auto-expire, notify user
```

---

## 3. How each agent works

### 3a. Discovery & ranking agent (not an LLM agent — deterministic scoring)

Runs in `cron/discover-repos.php`. This is intentionally **not** an LLM call for
the scoring itself — it's a deterministic formula over GitHub API data, so
rankings are reproducible and auditable:

```
score = (stars_weight × log(stars))
      + (activity_weight × days_since_last_commit_inverse)
      + (doc_quality_weight × has_readme_contributing_license)
      + (issue_quality_weight × good_first_issue_count)
      + (tech_relevance_weight × language_in_target_list)
```

The LLM is used *after* scoring, only to generate the human-readable repo
summary for the report — not to decide the ranking itself. This split matters:
non-deterministic ranking would make the daily list unpredictable and hard to
debug.

### 3b. Fix agent (OpenHands/Aider — the only Python component)

Runs entirely inside GitHub Actions, never on the backend. Its **input is
constrained**, not open-ended — it's given:
- The specific finding(s) to address (from the merged scanner output, or the
  user's exact chat request for a user-initiated fix)
- The repo's existing code style (inferred by the agent itself from the
  codebase, not configured manually)
- An explicit instruction to make **minimal changes** — no unrelated
  refactors, no rewriting working code, matching the "never rewrite large
  sections unnecessarily" principle from the original project vision

It does **not** decide which scanners to run (§1 — that's the orchestrator's
job, deterministic and separate) and it does **not** decide whether to open a
PR (that's the user's call). Its scope is strictly: given this specific
finding, produce a fix, run the tests, report pass/fail back.

### 3c. Chat agent (LLM + tool-calling, PHP-orchestrated)

Handles two distinct request types, routed by intent (from the earlier
"how does a user-requested fix work" discussion):

**Path A — behavioral change (bug fix, logic change, dependency update):**
Always routes through the full pipeline — new `ai-fix/*` branch, GitHub Actions
`fix.yml`, rescan, retry cap, merge to fork default. No shortcuts, even if the
user asks for a "quick" fix — the verification gate is not something the
chat agent can skip, since that's what keeps AI-generated changes trustworthy.

**Path B — structural/cosmetic (delete file, rename, delete fork, revert):**
Handled directly by the chat agent's own tool set against the fork, no
pipeline run needed:

```
Tools available to the chat agent (scoped only to the user's own fork —
never the upstream repo):
  read_file(path)
  edit_file(path, changes)
  run_tests()
  commit(message)
  delete_fork()
  create_pr()
  get_scan_results()
```

The routing decision itself: if the request implies changed program behavior
that could break something, it's Path A, no exceptions. If it's purely
structural (no logic change), it's Path B.

---

## 4. API design

Auth: single bearer token (GitHub App installation-scoped), checked in
`Router.php` middleware on every route.

| Method | Route | Request | Response |
|---|---|---|---|
| GET | `/api/repos` | — | `[{id, full_name, status, resume_score}]` |
| GET | `/api/repos/{id}` | — | `{repo, scan_results[], fixes[]}` |
| GET | `/api/repos/{id}/report` | — | `{summary, bugs_fixed, security_fixed}` |
| POST | `/api/repos/{id}/approve-pr` | `{}` | `{pr_url, status: "open"}` |
| POST | `/api/repos/{id}/decline-pr` | `{}` | `{status: "chat_available"}` |
| POST | `/api/chat/{repoId}` | `{message}` | `{reply, actions_taken[]}` |
| POST | `/webhooks/github.php` | GitHub payload + HMAC signature | `200 OK` |
| POST | `/webhooks/github-pr-status.php` | GitHub payload + HMAC signature | `200 OK` |
| GET | `/api/notifications/pending` | — | `[{repo_id, type, summary}]` |
| POST | `/api/settings` | `{spend_cap, digest_frequency}` | `{updated: true}` |

**Every webhook route verifies the HMAC signature GitHub sends** (`X-Hub-Signature-256`)
against `GITHUB_WEBHOOK_SECRET` before processing — this is the one check that
prevents anyone from forging a "job complete, here's a fake clean result"
webhook call.

---

## 5. Security architecture

**GitHub App permission scope (minimum necessary, requested once at install):**
```
contents: write       (push to user's own forks)
pull_requests: write  (open PRs)
issues: read          (read upstream issues for discovery)
actions: write        (trigger workflow_dispatch)
metadata: read        (basic repo info)
```
No `admin` scope, no access to private repos (public-only, per earlier decision),
no access to the upstream repo beyond what opening a PR requires.

**Secrets handling:**
- `ANTHROPIC_API_KEY`, `GITHUB_APP_PRIVATE_KEY`, `GITHUB_WEBHOOK_SECRET` live in
  the PHP backend's `.env`, never committed, never exposed to cloned repos
- Cloned repos' build/test scripts run inside GitHub Actions with **no access**
  to the backend's secrets — only the scoped `GITHUB_TOKEN` the Action itself
  needs, injected per-job, expiring with the job
- Gitleaks re-runs after every fix attempt specifically to catch the fix agent
  accidentally hardcoding a secret into the fix itself

**Sandboxing:**
- All cloning/building/testing happens in ephemeral GitHub Actions runners —
  destroyed after each job, nothing persists, nothing touches the backend
- ZAP/Newman only ever target `localhost`/container-internal addresses —
  never anything external, preventing the "unintentional external scan" risk
- Devcontainers give each repo its correct, isolated toolchain without a
  shared, accumulating environment across runs

**Abuse/spam prevention:**
- Public-repos-only scope
- Branch isolation: fixes never touch the fork's default branch until verified
  clean, and never touch the upstream repo until the user explicitly approves
- One consolidated PR per repo cycle, never parallel PRs for the same repo
- Fork auto-expiry after 14 days of no decision
- Repo policy compliance check (Phase 5) — verifies `CONTRIBUTING.md`/AI-policy
  before ever forking, to respect maintainers who've opted out of AI PRs

**Rate/spend control:**
- `users.spend_cap_usd` checked in `LLMService.php` before every Claude API
  call — hard stop if exceeded, not just a warning
- Retry cap (5 attempts) prevents runaway fix-loop cost on a single issue
- Notification digest caps (§ from `project-plan.md §12`) prevent email abuse
  as a side effect of preventing spam to the user themselves

**No manual security step required beyond initial setup** — every check above
runs automatically as part of the pipeline; the only human action is the
one-time GitHub App install/authorization, which GitHub itself requires by
design and cannot be scripted around.

---

## 6. GitHub Actions & runner mechanics

**Trigger model:** the PHP backend calls `workflow_dispatch` via the GitHub
API with input parameters (repo, fork URL, branch name, issue description) —
Actions never self-initiates; every run traces back to a backend decision.

**Runner choice:** GitHub-hosted runners, by default, at zero cost — since
scope is public-repos-only, Actions minutes are unlimited free (forks of
public repos are also public, so this applies through the whole pipeline).

**Environment resolution (every repo, cached after first run):**
```
Has .devcontainer/devcontainer.json?
  Yes → use as-is, respecting the repo's own maintainers' setup
  No  → detect from manifest files (package.json → Node, requirements.txt →
        Python, go.mod → Go, pom.xml → Java, Cargo.toml → Rust) and apply a
        matching template, or fall back to the universal multi-language image
```

**Self-hosted runner fallback** — only for repos too large for the default
2-core/7GB/6-hour GitHub-hosted runner:
- Route via a job label (`runs-on: self-hosted`) applied only to oversized
  repos, filtered at the discovery/ranking stage
- Oracle Cloud Free Tier (permanently free, 4 ARM cores/24GB RAM) is the
  recommended host for this runner — registered once, used only when needed

**Result delivery:** each job's last step POSTs its JSON result to the PHP
backend's webhook endpoint (HMAC-signed), which updates MySQL and decides the
next action — trigger another Action run, mark clean, or flag for review.
`cron/poll-pending-jobs.php` acts as a backup check in case a webhook is
missed, querying the GitHub Actions API directly for run status.

**Why the split (backend vs. Actions) matters for security:** untrusted
third-party code (the cloned repo's own build/install scripts) only ever
executes inside a disposable Actions runner — never on the PHP backend, never
with access to backend secrets. This is the core safety property of the whole
system's design.

---

## 7. Database schema

See `implementation-plan.md §2` for full MySQL migration SQL. Core tables:
`users`, `repos`, `scan_results`, `fixes`, `forks`, `branches`,
`notification_queue`, `pull_requests`. Raw scanner JSON is never stored in
MySQL — only compact summaries + artifact links, to stay within Hostinger's
storage limits (see `project-plan.md §9a`).

---

## 8. Hosting summary

| Component | Host |
|---|---|
| Backend (PHP) + Database (MySQL) | Hostinger Basic (already owned) |
| Frontend (Next.js) | Vercel (free) |
| Code execution | GitHub Actions (free, public repos) |
| Email | Resend (free tier) |

Full reasoning and alternatives considered are in `project-plan.md §9`.

---

## 9. Build phases (summary — file-level detail in `implementation-plan.md §6`)

1. **MVP** — discovery, one scanner (Semgrep), results in dashboard
2. **Fix loop** — full scanner suite, OpenHands integration, retry cap, digest email
3. **Automation** — auto-fork, branch-merge logic, single consolidated PR, fork expiry
4. **Runtime security + chat agent** — ZAP/Newman, tool-calling chat agent, Path A/B routing
5. **Hardening** — policy compliance check, spend caps, monitoring
