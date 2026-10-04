# Addendum Prompt — Finding Triage, Full-Cycle Processing, Consolidated Changelog

Append this to `master-build-prompt.md`. This addresses a real quality gap:
the system currently treats every finding as equally PR-worthy and appears
to process only one finding per cycle instead of all of them. Both are
fixed here, along with a maintainer-facing changelog that makes the final
PR look like deliberate engineering work, not an undifferentiated dump.

---

## ADDENDUM STARTS HERE

### 1. Primary/Secondary classification — runs before the Decision Engine, not instead of it

Every finding (scanner output, bug, or optimization candidate) gets
classified into exactly one tier, using the concrete criteria below —
deterministic where possible, not a subjective LLM call:

```
PRIMARY (showcase-worthy — process these first, always):
  - Any security finding, regardless of diff size (Semgrep/CodeQL/
    Gitleaks/Trivy HIGH or CRITICAL severity)
  - Correctness bugs with a concrete reproduction: a crash, an exception,
    incorrect output — verified by an actual failing-test-becomes-passing
    result, not a theoretical concern
  - Bugs in hot-path code: cross-reference against profile-hot-paths.py
    output (already built) or call-graph fan-in from the persisted graph
    (VPS upgrade addendum) — a bug in a function called constantly scores
    higher than one in a rarely-invoked utility
  - Bugs affecting the public API surface — reuse the exact
    public-API-surface check already built for workspace cleanup
    (senior-optimization addendum §5)
  - Performance fixes clearing the existing 5%-minimum real-speedup
    threshold (senior-optimization addendum §3) — below that threshold,
    it was already being rejected outright, so this tier only ever
    contains genuinely meaningful performance wins

SECONDARY (cleanup tier — process only after all Primary findings in
this cycle are resolved, capped):
  - Style/lint-level findings with no behavioral consequence
  - Workspace cleanup (dead code, unused dependencies) — already routed
    through its own tiered auto-fix/suggestion logic, unchanged here
  - Cosmetic refactors, documentation-only changes
  - Any optimization candidate that was evaluated but did not clear the
    5% threshold is NOT secondary — it's rejected outright, per existing
    rule, never bundled in as a minor win
```

Store this classification on the finding itself before it ever reaches
the Decision Engine or fix agent:

```sql
ALTER TABLE fixes ADD COLUMN priority_tier ENUM('primary','secondary')
  NOT NULL DEFAULT 'primary';
ALTER TABLE fixes ADD COLUMN priority_rank INT;
-- within a tier, rank by severity/impact for processing order
```

### 2. Fix the "only one finding processed per cycle" gap

The original design (`master-plan.md §2`) always specified looping over
**every** finding in a cycle, each through its own branch → fix → verify →
merge-to-fork-default flow. If the current implementation only processes
one, this is an implementation bug, not a spec change — investigate and
confirm before assuming new logic is needed:

```
1. Check the actual loop/queue logic in the job processor: does it
   correctly enqueue one job per finding, or does it only read the
   first finding from the merged scanner output and stop?
2. Check whether the retry-cap or confidence-gate logic is incorrectly
   short-circuiting the whole cycle on one finding's outcome, rather
   than continuing to the next finding regardless of the previous one's
   result
3. Fix whatever is actually causing the early stop, then verify: seed a
   test repo with at least 3 distinct findings (mix of primary and
   secondary) and confirm ALL of them are attempted in one cycle, not
   just the first
```

**Processing order within a cycle, once the above is fixed:**

```
1. Process every PRIMARY finding first, ordered by priority_rank within
   the tier (security > correctness-in-hot-path > correctness-general >
   public-API-impact > measured-performance)
2. Each goes through the full pipeline: new ai-fix/* branch, Decision
   Engine (if triggered per its existing rules), fix agent, critic pass,
   rescan, merge to fork default on success — exactly as already
   specified, just now guaranteed to run for every finding, not just one
3. Only after all PRIMARY findings are resolved (merged or flagged for
   manual review after hitting the retry cap) does the system move to
   SECONDARY findings, same pipeline, same per-fix rigor — no shortcuts
   for secondary just because it's lower priority
4. Apply a total-fixes-per-cycle cap to bound cost (configurable;
   reasonable default: no cap on PRIMARY — always attempt all of them,
   since these are the ones that matter — but cap SECONDARY at a
   sensible number, e.g. 5 per cycle, so cleanup work can't balloon
   cost or scope beyond the bugs that actually justify the PR)
```

### 3. Consolidated changelog — document everything, grouped by tier

Extend the existing consolidated report (already specified,
`master-plan.md §2`: "All issues in this cycle resolved → one
consolidated report generated") to produce a structured changelog, not
just a summary paragraph:

```markdown
## Changes in this PR

### Primary fixes
1. **[Security] Fixed SQL injection in user search endpoint**
   - File: `src/search.py`
   - Root cause: unparameterized query construction
   - Verified: new test confirms parameterized query, existing tests pass
   - [View diff](link) · [View complexity/performance data](link)

2. **[Correctness] Fixed CUDA kernel crash on odd batch sizes**
   - File: `kernels/fast_lora.py`
   - Root cause: unaligned memory allocation, no shape validation
   - Verified: reproduction test added, now passing
   - [View diff](link)

### Secondary fixes
3. **[Cleanup] Removed 2 unused dev dependencies**
   - Verified: full build + test suite passed after removal
   - [View diff](link)

### Not included this cycle
- [N findings] flagged for manual review (retry cap reached) — see
  dashboard for details
```

- This is what feeds the "AI Contribution Report" PR comment
  (advanced-enhancements addendum, C10) — extend that comment template
  to use this grouped structure instead of a flat list, so a maintainer
  scanning the PR sees the primary fixes first and can quickly judge
  whether the secondary items are worth their attention too
- Store the structured changelog (not just rendered text) so the
  dashboard and the PR comment can both render it consistently:

```sql
ALTER TABLE pull_requests ADD COLUMN changelog JSON;
-- {primary: [{fix_id, title, summary}], secondary: [...],
--  not_included: [{fix_id, reason}]}
```

## ADDENDUM ENDS HERE
