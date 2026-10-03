# AI Open Source Contribution Assistant — Project Plan

## 1. What the system does (final spec)

**Scope: public repositories only.** No private repo access is requested or needed —
this simplifies the GitHub App permission scope and avoids any handling of
proprietary/private code. It also aligns with the goal: a fix on a private repo
has no resume value since no one else can see it.

Runs daily, unattended. For each cycle:

1. Discovers and ranks GitHub repos worth having on a resume
2. Clones each candidate into a sandbox and checks for bugs/security issues
3. No issues → deletes the clone, updates the list, moves on
4. Issues found → auto-forks to the user's GitHub account (no permission needed)
5. Fixes the bugs, re-scans for security issues and leaked secrets, re-tests until clean
6. Commits the fix to the fork, sends the user a report (repo summary + bugs found + fixes made)
7. Asks: **"Create PR?"** — the only manual gate in the whole system
   - Yes → opens PR from fork to upstream repo
   - No → user can talk to a chat agent to edit/delete/redo things on the fork
8. Forks with no PR decision after 14 days auto-expire (deleted, user notified)

---

## 2. Manual steps required (final list)

| Step | When | Why |
|---|---|---|
| Install GitHub App + authorize | Once, at setup | GitHub requires explicit human consent for any app to act on an account |
| Add LLM + scanner API keys | Once, at setup | Third-party services require a human-created credential |
| Set spend/rate caps | Once, as config | Prevents runaway cost/API abuse — your choice, not GitHub's requirement |
| "Create PR?" | Per repo, when bugs are fixed | The one point where output leaves your system and touches someone else's project |

Everything else — discovery, cloning, forking, fixing, testing, security scanning, committing — is fully automated with zero human involvement.

---

## 3. Architecture

```
┌─────────────────────────────────────────────┐
│  Backend (PHP) on Hostinger Basic — the brain│
│  - Cron-triggered scheduler                  │
│  - State machine / job orchestrator          │
│    (MySQL status columns + cron polling,     │
│     no persistent worker process needed)     │
│  - Discovery & ranking logic                 │
│  - Approval gate + notifications             │
│  - Chat agent (LLM via cURL + tool calling)  │
│  - MySQL database                            │
└───────────────┬───────────────────────────────┘
                │ triggers via cURL / workflow_dispatch
┌───────────────▼───────────────────────────────┐
│  GitHub Actions — the hands (ephemeral runners)│
│  - Clone repo                                  │
│  - Static analysis (Semgrep, CodeQL, Trivy,    │
│    Gitleaks)                                   │
│  - Fix agent (OpenHands / Aider — Python,      │
│    runs entirely inside the Action, never on   │
│    the PHP backend)                            │
│  - Build + run tests                           │
│  - Start app + runtime scan (ZAP, Newman)      │
│  - Commit, push                                │
└───────────────┬───────────────────────────────┘
                │ results (JSON) via webhook → PHP endpoint
┌───────────────▼───────────────────────────────┐
│  GitHub (repos, forks, PRs, webhooks)          │
└─────────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│  Frontend — Vercel (Next.js dashboard)       │
│  talks to the PHP backend's REST endpoints   │
└─────────────────────────────────────────────┘
```

**Why this split:** the backend never runs untrusted third-party code directly — all cloning, building, and testing happens inside disposable GitHub Actions runners. If a malicious repo's build script tries anything harmful, it dies with the runner and never touches your infrastructure or credentials. This also means Hostinger Basic's lack of Python/root-access is a non-issue — the one Python-dependent piece (the fix agent) was always meant to live in GitHub Actions, not on the backend.

---

## 4. Tech stack

| Layer | Choice | Why |
|---|---|---|
| Backend | **PHP** on Hostinger Basic (shared hosting) | Fully supported on shared hosting, including outbound cURL to GitHub/LLM APIs and cron jobs. No SSH/root needed since the backend only orchestrates — it never runs untrusted code itself. |
| Database | **MySQL** on Hostinger Basic | Comes standard with shared hosting + phpMyAdmin. Schema in §5 is written for MySQL's `JSON` column type (Postgres `jsonb` equivalent). |
| Job queue | **MySQL status columns + Hostinger cron polling** (no Redis) | Shared hosting has no persistent worker process to consume a live queue. A cron job every few minutes checks for repos/fixes in a pending state and advances them — fine given the system's daily cadence, nothing here needs sub-minute responsiveness. |
| Execution engine | GitHub Actions (`workflow_dispatch`), running each repo inside a **dev container** (`devcontainers/cli`) | Free — unlimited minutes on public repos. Dev containers give each repo its correct language/toolchain automatically. This is also where the one Python-dependent component (the fix agent) actually runs — never on the PHP backend. |
| Repo understanding | Bounded 1-hop AST/Import Parser (`resolve-issue-context.py` + `classify-repo-files.py`) | Sourcegraph Cody deferred past MVP per original open decision; 1-hop context scoping delivers precise, token-efficient finding context without running a heavy self-hosted code-graph server. |
| Static analysis | Semgrep, CodeQL, Trivy, Gitleaks | All free, CLI-based, run as Action steps. SonarQube CE deferred past MVP — it requires a persistently-running self-hosted server + DB, unlike the others which are stateless CLI calls; Semgrep + CodeQL already cover most of the same ground for Phase 1. |
| Fix generation | OpenHands (primary) or Aider | Both open-source, sandboxed, git-aware, Python-based — runs entirely inside GitHub Actions, never needs a Python host on the backend side. **Neither bundles Semgrep/CodeQL/SonarQube/ZAP/Trivy/Gitleaks/Postman/Nmap built-in** — the orchestrator runs each scanner as a deterministic CI step and hands the merged findings to the agent as its fix target. |
| Runtime security | OWASP ZAP baseline scan, Postman/Newman | Only ever pointed at the sandboxed instance, never external |
| LLM | Claude API, called via plain PHP cURL (no SDK/framework needed) | Repo summaries, bug explanations, chat agent, plan generation — all just POST-JSON/parse-JSON, no Python required |
| Notifications | Email (Resend/SendGrid) via PHP cURL, or Hostinger's own mail sending | User needs to see reports and approve PRs async |
| Frontend | Next.js dashboard on **Vercel** | Repo list, reports, PR approval, chat interface — calls the PHP backend's REST endpoints |

---

## 5. Database schema (core tables — MySQL, InnoDB engine)

```
users
  id, github_installation_id, email, spend_cap, created_at

repos
  id, full_name, stars, last_activity, resume_score, status
  (status: candidate | analyzing | clean_deleted | bugs_found | forked | pr_open | expired)

scan_results
  id, repo_id, tool (semgrep/codeql/trivy/gitleaks/zap/newman),
  finding_count, severity_summary (JSON, small), artifact_url, created_at
  -- NOTE: do NOT store raw scanner JSON here (see §9a below) — raw output
  -- goes to GitHub Actions artifacts or object storage; this table holds
  -- only a compact summary + a link, to stay within Hostinger Basic's
  -- MySQL storage limits

fixes
  id, repo_id, issue_description, branch_id, diff, test_status,
  security_status, retry_count, merge_status
  (merge_status: fixing | merged_to_fork | flagged_manual_review)

forks
  id, repo_id, user_id, fork_url, created_at, expires_at

branches
  id, fork_id, fix_id, branch_name (ai-fix/*), base_branch,
  status (fixing | merged | abandoned), merged_at

notification_queue
  id, user_id, type (repo_report/security_flag/manual_review/pr_status),
  payload (JSON), created_at, sent_at, batch_id

pull_requests
  id, repo_id, fork_id, pr_url, status (open/merged/closed/none),
  fixes_included (JSON array of fix_id — every accumulated fix this PR covers),
  created_at

chat_sessions
  id, repo_id, user_id, messages (jsonb)

reports
  id, repo_id, summary, bugs_fixed, security_fixed, created_at
```

---

## 6. Pipeline logic (step by step)

```
1. CRON (daily)
   → GitHub Search/GraphQL API: pull candidate repos
   → Score: stars, activity, doc quality, issue quality, tech relevance
   → Insert top N into `repos` table as `candidate`

2. FOR EACH candidate repo:
   → Trigger GitHub Actions workflow_dispatch: "analyze"
     - clone repo into runner
     - run Semgrep, CodeQL, Trivy, Gitleaks
     - run existing test suite
     - upload results as JSON artifact
   → Backend receives results via webhook
   → No findings → status = clean_deleted, delete any temp data, next repo
   → Findings exist → status = bugs_found

3. Auto-fork (no permission needed)
   → GitHub API: create fork under user's account
   → Push analyzed clone state into fork's default branch (mirror only, no edits yet)
   → Create a new branch off default: `ai-fix/<issue-id>-<short-id>`
     - ALL fix work for THIS issue happens on this branch, never directly on default
   → LLM: generate repo summary + bug report from scan results
   → Store in `reports`, queue notification (see §9, no immediate send)

4. Trigger GitHub Actions workflow_dispatch: "fix"
   → checkout the `ai-fix/*` branch (never default)
   → OpenHands/Aider works against that branch, guided by scan findings
   → Re-run static analysis + Gitleaks after each fix attempt
   → If app has a start script: start it, run ZAP + Newman
   → Loop fix → rescan until clean OR retry_count hits cap (e.g. 5)
   → Cap hit without resolution → flag issue for manual review, queue notification,
     leave this branch unmerged (does not block other issues in the same repo)

5. Clean result → MERGE `ai-fix/*` branch into the fork's default branch
   → this merge is safe: it only touches the user's own fork, nothing upstream
   → delete the now-merged `ai-fix/*` branch (cleanup)
   → mark this issue's fix as `merged_to_fork` in the `fixes` table
   → repeat steps 3-5 for every other issue found in this repo (each gets its own
     branch, its own verification loop, then merges into the same fork default
     branch) — the fork's default branch accumulates all verified fixes

6. Once all issues found in this cycle are resolved (merged or flagged for review):
   → generate ONE consolidated report: total bugs fixed, security issues fixed,
     anything still flagged, across the whole repo — not per-issue
   → queue notification: "Repo X: N bugs fixed and merged. Ready for a single PR?"

7. WAIT for user response (async, no timeout — sits in dashboard/chat)
   → "Yes" → GitHub API: open ONE PR from fork's default branch to upstream
             default branch, containing every accumulated fix
             → status = pr_open, track via webhook (review/comment/merge events)
   → "No" / custom instruction → route to chat agent
             → chat agent has tool access: edit file, re-run tests, delete fork, etc.
   → if the repo is revisited on a later daily run and new issues are found after
     the PR was already opened, those go through steps 3-5 again on fresh
     `ai-fix/*` branches, merge to the fork's default branch, and queue toward
     the NEXT PR (or an update to the same open PR if it hasn't been merged/closed
     yet) — never a second parallel PR for the same repo while one is still open

8. Fork expiry job (daily)
   → any fork with unresolved fixes and no PR decision for 14 days →
     delete fork, notify user, status = expired
```

---

## 7. Full pipeline — exact tool per stage, and environment handling

Every cloned repo can be a different language/framework/toolchain. Running each one
correctly (right runtime version, right package manager, right test command) is
solved with **dev containers**, not a single fixed Docker image. This is the same
mechanism VS Code's "Reopen in Container" uses, and it's fully CLI-scriptable via
the official `devcontainers/cli` — which means it runs identically in GitHub Actions.

### Environment detection logic (runs first, every repo)

```
1. Does the repo already have .devcontainer/devcontainer.json?
   → YES: use it as-is. The repo's own maintainers already defined the
     correct environment — respect it, don't override it.
   → NO: auto-detect from manifest files and pick a fallback template:
        package.json         → Node devcontainer template
        requirements.txt /
        pyproject.toml       → Python devcontainer template
        go.mod                → Go devcontainer template
        pom.xml / build.gradle → Java devcontainer template
        Cargo.toml            → Rust devcontainer template
        multiple matches       → "universal" devcontainer image
                                  (mcr.microsoft.com/devcontainers/universal —
                                  ships Node, Python, Go, Java, Ruby preinstalled)
2. Store the resolved devcontainer config against the repo record, reuse it
   on future daily runs of the same repo (skip re-detection).
```

### Stage-by-stage table

| Stage | Tool | Runs where | Notes |
|---|---|---|---|
| Discover + rank repos | GitHub Search/GraphQL API | Backend (no container) | Plain API calls, no execution |
| Environment detection | Manifest file check + `devcontainers/cli` | GitHub Actions job | Resolves which devcontainer to use, cached for reuse |
| Clone + spin up environment | `devcontainers/ci` GitHub Action | GitHub Actions job, inside the resolved devcontainer | Official action, wraps `devcontainer up` |
| Baseline test run | Repo's own test command (npm test / pytest / go test / etc.) | Inside devcontainer | Establishes what's already failing vs what the AI's fix affects |
| Static analysis — bugs | **Semgrep** | Official `semgrep/semgrep` Docker image, as a sibling step | `semgrep --config auto --json` |
| Static analysis — security (deep) | **CodeQL** | `github/codeql-action` (GitHub-native, no extra container needed) | Free for public repos |
| Dependency vulnerabilities | **Trivy** | `aquasec/trivy` Docker image | `trivy fs --format json` |
| Secret leaks | **Gitleaks** | `zricethezav/gitleaks` Docker image | Run again after every fix attempt, not just once |
| Merge + prioritize findings | Backend logic (LLM-assisted ranking) | Backend | Combines all 4 JSON outputs into one issue list |
| Fix generation | **OpenHands or Aider** | Inside the same devcontainer (needs the repo's real toolchain to run/test) | Orchestrator tells it exactly which findings to fix — not "figure out security" |
| Re-scan after fix | Semgrep + Gitleaks (fast ones) | Same containers as above | Full CodeQL/Trivy re-run only periodically — they're slower |
| Build + test again | Repo's own build/test command | Inside devcontainer | Must pass before proceeding |
| Runtime security (only if app has a start script) | **OWASP ZAP** baseline scan + **Postman/Newman** | ZAP official Docker image (`zaproxy/zap-stable`) + Newman container, both on the same Docker network as the app container | Only ever targets the app's `localhost`/container-internal address, never anything external |
| Merge fix branch → fork default | Git operations | GitHub Actions job | Only touches the user's own fork |
| Generate repo summary + report | LLM (Claude API) | Backend | Not run in a container — plain API call |
| Notify (digest) | Backend + email provider | Backend | Batched, not per-event |
| Open PR (on approval) | GitHub API | Backend | One consolidated PR per repo cycle |

**Why devcontainers specifically, not one big Docker image:** a single fixed image
would break on any repo using a language/version it doesn't have. Devcontainers let
each repo run in the *correct* environment automatically, while still giving you a
consistent, scriptable, reproducible container underneath — same mechanism whether
it runs on your laptop, in CI, or in a self-hosted runner.

**SonarQube note:** deferred past MVP as decided earlier — if added later, it needs
a persistently-running server (not a per-job container), so it would run as a
separate long-lived service, not inside the per-repo pipeline above.

---

## 8. Where the pipeline actually executes — GitHub Actions vs alternatives

**Short answer: GitHub-hosted runners, and because you've scoped this to public
repos only, this is effectively free at any realistic scale.**

- Public repositories get **unlimited free GitHub Actions minutes** on
  GitHub-hosted runners. Since every repo you touch is public, and forks of
  public repos are public by default, this applies to every job in the pipeline
  above — discovery, scanning, fixing, testing, security scanning all run at
  zero Actions cost.
- Default GitHub-hosted runners: 2-core, 7GB RAM, 14GB disk, 6-hour max job time.
  This is enough for the large majority of repos you'll target (small-to-medium
  OSS projects). It will not be enough for very large monorepos with slow builds
  or huge test suites — those should be filtered out at the discovery/ranking
  stage rather than force-run.

### If you ever hit the GitHub-hosted runner's limits

You don't need a different CI platform — you can add **self-hosted runners**
that GitHub Actions dispatches to exactly the same way, just running on your
own compute:

| Option | Cost | Notes |
|---|---|---|
| **Oracle Cloud Free Tier (Ampere ARM)** | Free, permanently (not a trial) | 4 OCPUs / 24GB RAM available on the always-free tier — register it as a self-hosted GitHub Actions runner for the rare large-repo job |
| **Fly.io free allowance** | Free, small tier | Good for a small always-on self-hosted runner, less generous than Oracle |
| **Your own Render/Railway worker** | Cheap, not free | Simpler to set up since it's the same platform as your backend, but doesn't have a free tier as generous as Oracle for this purpose |

You would only route specific jobs to a self-hosted runner (via a label like
`runs-on: self-hosted` on just the large-repo jobs) — everything else stays on
GitHub's free hosted runners.

### Other CI platforms — not needed, but worth knowing they exist
GitLab CI, CircleCI, and Buildkite all have free tiers and could technically run
this same pipeline. There's no real benefit to using them here: your repos and
forks already live on GitHub, so triggering jobs natively via GitHub Actions
avoids mirroring code to a second platform for no gain. I would not add one
unless you hit a specific GitHub Actions limitation that self-hosted runners
don't solve.

---

## 9. Hosting — Hostinger Basic + Vercel (decided, revised)

| Component | Platform | Notes |
|---|---|---|
| Backend (orchestrator API) | **PHP on Hostinger Basic** (already owned) | Handles GitHub API calls, LLM calls (plain cURL), webhook receiving, notifications, all discovery/ranking/state-machine logic |
| Database | **MySQL on Hostinger Basic** (already owned) | Matches the schema in §5 (MySQL `JSON` columns instead of Postgres `jsonb`) |
| Job queue | **MySQL status columns + Hostinger cron polling** | No Redis — shared hosting has no persistent worker. A cron job every few minutes advances pending jobs. Fine given the system's daily cadence. |
| Frontend dashboard | Vercel (Hobby) | Native Next.js deploys, calls the PHP backend's REST endpoints over HTTPS |
| Email digest | Resend, or Hostinger's own outgoing mail | Either works via PHP; Resend gives cleaner deliverability/tracking |
| Code execution (Python fix-agent, scanners) | GitHub Actions | Unlimited free minutes — public repos only. This is also where the only Python-dependent piece of the whole system runs, so Hostinger Basic's lack of Python support is a non-issue. |
| Daily scheduler | Hostinger cron (hPanel → Cron Jobs) | Calls a PHP script on schedule; PHP cron support is confirmed on Hostinger's web/cloud hosting plans |

**Why this works despite Basic being shared hosting:** the backend was always designed to be a thin coordinator, not a heavy compute host (see §3) — it only makes outbound API calls, receives webhooks, and reads/writes MySQL. None of that needs SSH, root access, Docker, or Python. The one genuinely heavy, Python-dependent piece — cloning, scanning, running the fix agent — was already routed to GitHub Actions from early in this plan, specifically so the backend never has to run untrusted or resource-heavy code itself.

**What changed from the earlier free-stack plan (Render/Neon/Upstash):** that stack is still a valid alternative if you ever move off Hostinger, but since you already have Hostinger Basic paid for, PHP/MySQL there is now the primary plan — it removes three separate platforms (Render, Neon, Upstash) down to one you already own, at the cost of losing a live/instant job queue in favor of cron-based polling (acceptable given the daily cadence).

### 9a. Capacity check — does Hostinger Basic actually cover this project?

At solo/MVP scale (~20 repos scanned/day):

- **PHP execution:** each backend operation (webhook handling, API calls, state updates) is a single quick request-response cycle, well within shared hosting's per-request time limits — no long-running process is ever needed here since the heavy work is in GitHub Actions.
- **MySQL storage — the actual pinch point, same issue as before, different database:** raw scanner JSON from Semgrep/CodeQL/Trivy/Gitleaks can run 50–500KB per tool per repo. Storing it naively could fill a shared-hosting storage quota within weeks at meaningful volume.
  **Mitigation (required, not optional): never store raw scan JSON in MySQL.** Keep raw findings as GitHub Actions artifacts (or free object storage like Cloudflare R2, 10GB free), and store only a compact summary + artifact link in `scan_results` (see §5).
- **Cron frequency:** confirm your specific Hostinger Basic plan's cron job frequency limits in hPanel (entry-tier shared plans sometimes cap how often cron can fire) — if it's limited to, say, once every 5–15 minutes, that's still fine for this system's daily-driven cadence.
- **Concurrent connections / shared CPU:** shared hosting throttles under heavy simultaneous load. At solo/MVP scale (one user, sequential repo processing) this isn't a concern; if you ever run many repos genuinely in parallel, that's the point you'd outgrow Basic and move to Hostinger's VPS tier or the earlier free-platform stack.

**First likely outgrow point:** MySQL storage quota (mitigated above) or cron frequency limits — both are configuration/mitigation issues, not architecture blockers.

---

## 10. Chat agent (tool-calling)

A simple LLM agent with a fixed toolset, scoped only to the user's own fork:

```
Tools:
  - read_file(path)
  - edit_file(path, changes)
  - run_tests()
  - commit(message)
  - delete_fork()
  - create_pr()
  - get_scan_results()
```

Every tool call operates only on the user's fork — never the upstream repo — so there's no destructive-action risk beyond the user's own account.

---

## 11. Retry cap logic (fix ↔ rescan loop)

```
retry_count = 0
MAX_RETRIES = 5

while not (tests_pass and security_clean):
    run fix agent with latest findings
    rescan (static + runtime)
    retry_count += 1
    if retry_count >= MAX_RETRIES:
        status = "needs_manual_review"
        notify user: "Could not fully resolve after 5 attempts.
                       Here's what's still failing: [details]"
        break
```

This prevents infinite loops and infinite LLM spend on a single repo.

---

## 12. Notifications — digest, not per-event (anti-spam design)

The pipeline runs across many repos every day. If every repo report, security flag,
and status update fired its own email, the user gets a flooded inbox within a week
and starts ignoring it — which defeats the purpose. Design rule: **nothing emails
immediately. Everything writes to `notification_queue` first, and a single batched
job decides what actually gets sent.**

```
Every event (report ready, security flag, manual review needed, PR status change)
  → INSERT into notification_queue (not sent yet)

ONE digest job, runs once daily (e.g. 6pm user's local time)
  → SELECT all unsent rows for this user
  → group by type
  → build ONE email:
      "Today: 3 repos ready for PR review, 1 needs manual attention,
       2 clean (no action), 1 PR merged upstream"
      [expandable per-repo detail in the dashboard, not the email body]
  → mark all as sent, tag with batch_id
```

**Exceptions — send immediately, but rate-limited per user:**
- A PR gets merged or explicitly rejected by a maintainer (rare, high-value, user
  will want to know same-day) — but cap at 1 email per hour even for these; queue
  and batch anything beyond that.
- Fork auto-expiring in the next 24 hours with a pending decision — one reminder,
  not a repeat per day.

**Hard rules to prevent runaway sending:**
- Max **1 email per user per day** for the routine digest, regardless of how many
  repos were processed (0 repos with news that day → no email at all, not an empty one)
- Max **3 emails per user per day** total, including the rate-limited exceptions above
- User can set digest frequency (daily / every 3 days / weekly) in settings
- All non-urgent detail lives in the dashboard — email is a pointer to check the
  dashboard, not the full report body

This turns "10 repos processed today" into one email, not ten.

---

## 13. Security requirements (non-negotiable, not user-facing)

- GitHub Actions runners are ephemeral and network-isolated during build/test steps except for package registry access
- Secrets (GitHub token, LLM API key, scanner keys) are never exposed to the cloned repo's build/test scripts — inject only what's needed at the specific step, never as global env vars
- Gitleaks re-run after every fix, specifically to catch the fix agent accidentally hardcoding a secret
- ZAP/Newman only ever point at `localhost` inside the sandbox — never an external target
- No Nmap — deliberately excluded from the automated daily pipeline. Even scoped to
  the sandbox, port-scan-like traffic is a common trigger for cloud-provider abuse
  detection (AWS/GCP flag outbound scanning regardless of intent). ZAP + Newman
  already cover the practical runtime/API security surface without that risk.
- Rate/spend caps enforced per user, checked before each pipeline stage starts

---

## 14. Build phases

**Phase 1 — MVP (2-3 weeks)**
- GitHub App setup + auth
- Discovery + ranking (basic scoring)
- One GitHub Actions workflow: clone + Semgrep + tests
- Manual trigger only (no cron yet)
- Basic dashboard: list repos, view scan results

**Phase 2 — Fix loop (2-3 weeks)**
- Integrate OpenHands or Aider for fix generation
- Add CodeQL, Trivy, Gitleaks
- Retry/rescan loop with cap
- Report generation (LLM summaries)
- Email notifications

**Phase 3 — Automation (1-2 weeks)**
- Daily cron trigger
- Auto-fork logic
- PR creation + webhook tracking
- Fork expiry job

**Phase 4 — Runtime security + chat agent (2 weeks)**
- ZAP + Newman integration
- Chat agent with tool-calling
- Dashboard polish (approve/reject UI, chat interface)

**Phase 5 — Hardening**
- Repo policy compliance check (CONTRIBUTING.md / AI-contribution rules)
- Spend caps, abuse-rate limiting
- Monitoring/alerting on pipeline failures

---

## 15. Open decisions you'll need to make before Phase 1

- Which PHP framework (or none — plain PHP with a small router) for the backend; something lightweight like Slim is enough given the backend is just REST endpoints + cron scripts, no need for a full framework like Laravel unless you want its tooling
- Confirm your specific Hostinger Basic plan's cron job frequency limit and PHP execution time limit in hPanel, since these vary slightly by exact plan tier
- Self-host Sourcegraph Cody vs skip it initially and rely on LLM + grep-based understanding for MVP (cheaper to start without it)
- Which LLM provider for the chat agent + summaries (Claude API is a solid default given cost/quality balance)
