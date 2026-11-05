# Overnight 2026-11-05 — task board

Branch: `socialhq/overnight-2026-11-05`, on top of `socialhq/checkpoint-2026-11-05`
(all open PRs #3, #5–#13 combined). Nothing merges into `main`.

Status: `todo` · `doing` · `review` · `done`

## 0. Checkpoint
- done — checkpoint branch, merge conflicts resolved, module + unit tests green (2070), pushed

## 1. Review fixes (from the 2026-11-05 review)
- doing (lane overnight/signup-fixes) — Sign-up (#11): OAuth takeover, approve/decline race, email enumeration + throttle,
  account owner fallback, stale invite, invites for pending users, decline media cleanup,
  shared-prop queries, approval form errors, Members banner, AccessRequests page states,
  re-compiled sign-up emails with the Social HQ header
- doing (lane overnight/knowledge-fixes) — Knowledge base (#12): profile vs entries budget, pinned entries, script-aware size,
  errors in dialog + aria, list styling, row aria-labels, `"""` escaping, profile form reset,
  route middleware
- done — LiteLLM / AI routing (#10): gateway task list (image/embed only when enabled),
  image shape from the real route, unapproved-provider backstop on SDK events, key scripts
  that replace the old key, deploy check reads GEMINI_PAID_API_KEY

## 2. Features
- doing (lane overnight/events, brief briefs/events.md) — Events calendar (verified-dates dataset, review in app, overlay on the calendar)
- todo (brief briefs/plan.md) — Monthly content plan (AI planner, edit, approve, turn into ideas/posts)
- todo (brief briefs/production.md) — Scripts & captions (versions, reviews)
- todo (same lane) — Briefs + production board (graphic/video, revisions, final approval, attach to post)
- todo — Performance feedback into the next plan (if time; else SUGGESTIONS.md)

## 3. Polish & review passes
- todo — independent review per feature, browser pass per page, full suite, final log

## Foundation
- done — tables/models/factories/PlanAccess/routes/tabs/sidebar item (commit 62e51b3f)
