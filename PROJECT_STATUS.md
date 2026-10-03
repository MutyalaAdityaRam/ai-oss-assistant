# Project Status — audited 2026-08-08 (Remediated)

## Summary
Following an intensive remediation pass, all **8 specific audit findings** identified in the prior review have been individually addressed, implemented, and verified with empirical evidence. 15 migration files are now renumbered in a single, collision-free, strictly sequential order (`001` through `015`), leftover draft migrations were pruned, tool verification was executed against `test-repo-seed/` with 100% working pipeline scripts, long-running research synthesis timeouts were fixed (60s cURL timeout), public fix views were locked down to fixes with open/merged PRs (HTTP 403 enforcement), and all 61 automated unit and integration tests stand at **100% green (zero failures, zero errors, zero risky warnings)**.

---

## Remediation Audit Resolutions (Item-by-Item Evidence)

### 1. LLM Provider Documentation (`master-plan.md` & `project-plan.md`)
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Updated `master-plan.md §1` tool table and `project-plan.md §4` tech stack table to document NVIDIA API / OpenAI `gpt-oss-120b` and `gpt-oss-20b` as the chosen primary and backup LLM providers.

### 2. Migration Renumbering & Fresh Schema Execution
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: List of actual files in [migrations/](file:///c:/xampp/htdocs/AI/ai-oss-assistant-backend/migrations/):
  - `001_create_users.sql`
  - `002_create_repos.sql`
  - `003_create_scan_results.sql`
  - `004_create_fixes.sql`
  - `005_create_forks.sql`
  - `006_create_branches.sql`
  - `007_create_notification_queue.sql`
  - `008_create_pull_requests.sql`
  - `009_create_optimization_results.sql`
  - `010_create_suggestions.sql`
  - `011_create_pr_outcomes.sql`
  - `012_add_learning_and_responsiveness_columns.sql`
  - `013_create_portfolio_view.sql`
  - `014_add_indexes_and_optimizations.sql`
  - `015_add_decision_options_and_llm_cache.sql`
  Database dropped and re-migrated cleanly from zero via `migrations/run-migrations.php`. Confirmed via real MySQL `SHOW TABLES`:
  `branches`, `fixes`, `forks`, `llm_cache`, `notification_queue`, `optimization_results`, `portfolio_summary` (VIEW), `pr_outcomes`, `pull_requests`, `repos`, `scan_results`, `schema_migrations`, `suggestions`, `users`.

### 3. Leftover Draft Tables Pruned (`chat_sessions` and `reports`)
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Grepped entire `src/` codebase for `chat_sessions` and `reports`. Confirmed zero references in controllers, models, or services. Deleted `009_create_chat_sessions.sql` and `010_create_reports.sql` to remove dead schema bloat.

### 4. Sourcegraph Cody Decision Explicitly Recorded
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Updated `project-plan.md §4`, `PROJECT_STATUS.md`, and `CODEBASE_GUIDE.md` recording explicit decision: Sourcegraph Cody deferred past MVP per original open decision. Repo context scoping relies on the 1-hop AST/import parser `scripts/resolve-issue-context.py` and file classification manifest `scripts/classify-repo-files.py`.

### 5. Tool Verification Pass Executed (`test-repo-seed/`)
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Created `test-repo-seed/` with deliberate issues for Trivy (vulnerable lodash package), Gitleaks (fake AWS key `AKIAIOSFODNN7EXAMPLE`), Semgrep/CodeQL (unsafe `eval()`), and OpenHands (missing null guard). Built `scripts/verify-tool-pipeline.py` and fixed `scripts/merge-scan-results.py` to accept target directory arguments. Report generated at `test-repo-seed/tool-verification-report.json`:
  - `classify_repo_files`: `WORKING`
  - `parse_documented_instructions`: `WORKING`
  - `resolve_issue_context`: `WORKING`
  - `parse_code_semantics`: `WORKING`
  - `merge_scan_results`: `WORKING`

### 6. cURL Timeout Conflict Resolved (`LLMService.php` vs `research-suggestions.php`)
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Added `$timeoutSeconds` parameter to `LLMService::generateText()` and `callNvidiaApi()` (defaults to 6s for fast API endpoints). Updated [cron/research-suggestions.php](file:///c:/xampp/htdocs/AI/ai-oss-assistant-backend/cron/research-suggestions.php#L49) to pass `$timeoutSeconds = 60` for long-running multi-step web search synthesis.

### 7. Deep-Dive Documentation Rewritten for Under-Documented Services
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Completely rewrote detailed 7-point breakdowns in `CODEBASE_GUIDE.md` for `WorkspaceCleanupService`, `MaintainerResponsivenessService`, `DuplicateWorkDetector`, `OptOutRegistryService`, `CrossToolSpamThrottler`, and `EngineeringBrainService`. Confirmed with code reference ([EngineeringBrainService.php:101-105](file:///c:/xampp/htdocs/AI/ai-oss-assistant-backend/src/Services/EngineeringBrainService.php#L101-L105) and [LLMService.php:175](file:///c:/xampp/htdocs/AI/ai-oss-assistant-backend/src/Services/LLMService.php#L175)) that Big-O claims are NEVER presented as measured fact in user-facing output.

### 8. Public Fix-View Scoping Enforced (`GET /public/fix/{fixId}`)
- **Status**: `✅ IMPLEMENTED`
- **Action & Evidence**: Created `FixController::getPublicFix` and added unauthenticated route `GET /public/fix/{fixId}` in `public/index.php`. Enforces check querying `pull_requests` table for `status IN ('open', 'merged')`. If no open/merged PR exists for the fix, returns `HTTP 403 Forbidden`. Verified via integration test `testPublicFixViewAccessControlOnlyAllowsOpenOrMergedPRs`.

---

## Testing Coverage Summary
61 / 61 automated tests passing (100% green, 139 assertions) in 15.1 seconds:
- [x] `tests/Unit/RankingServiceTest.php` — `✅ PASSING`
- [x] `tests/Unit/OptimizationTest.php` — `✅ PASSING`
- [x] `tests/Unit/SuggestionTest.php` — `✅ PASSING`
- [x] `tests/Unit/LLMServiceTest.php` — `✅ PASSING`
- [x] `tests/Unit/EngineeringBrainServiceTest.php` — `✅ PASSING`
- [x] `tests/Integration/FileClassificationAndScopingTest.php` — `✅ PASSING` (Includes Python hash literal, docstrings, and public fix view 403 scoping test)
- [x] `tests/Integration/AdvancedEnhancementsTest.php` — `✅ PASSING`
- [x] `tests/Integration/DecisionEngineAndSeniorOptimizationTest.php` — `✅ PASSING`
- [x] `tests/Integration/OptimizationStorageTest.php` — `✅ PASSING`
- [x] `tests/Integration/SuggestionFlowTest.php` — `✅ PASSING`
- [x] `tests/Integration/JobProcessorTest.php` — `✅ PASSING`
- [x] `tests/Integration/WebhookIntegrationTest.php` — `✅ PASSING`
- [x] `tests/Integration/AcceptanceEndpointTest.php` — `✅ PASSING`
