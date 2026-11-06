# Overnight 2026-11-05 — task board

Branch: `socialhq/overnight-2026-11-05`, on top of `socialhq/checkpoint-2026-11-05`
(all open PRs #3, #5–#13 combined). Nothing merges into `main`.

Status: `todo` · `doing` · `review` · `done`

## 0. Checkpoint
- done — checkpoint branch, merge conflicts resolved, module + unit tests green (2070), pushed

## 1. Review fixes (from the 2026-11-05 review)
- done (+ second review fixes fc338c33) — Sign-up (#11): OAuth takeover, approve/decline race, email enumeration + throttle,
  account owner fallback, stale invite, invites for pending users, decline media cleanup,
  shared-prop queries, approval form errors, Members banner, AccessRequests page states,
  re-compiled sign-up emails with the Social HQ header
- done — Knowledge base (#12): profile vs entries budget, pinned entries, script-aware size,
  errors in dialog + aria, list styling, row aria-labels, `"""` escaping, profile form reset,
  route middleware
- done — LiteLLM / AI routing (#10): gateway task list (image/embed only when enabled),
  image shape from the real route, unapproved-provider backstop on SDK events, key scripts
  that replace the old key, deploy check reads GEMINI_PAID_API_KEY

## 2. Features
- done — Events calendar (verified-dates dataset, review in app, overlay on the calendar)
- done — Monthly content plan (AI planner, edit, approve, turn into ideas/posts)
- done — Scripts & captions (versions, reviews)
- done — Briefs + production board (graphic/video, revisions, final approval, attach to post)
- done — Performance feedback (planner context + "What worked lately" panel)

## 3. Polish & review passes
- done — independent review per feature, cross-cutting review, second pass on fixes, copy review, browser passes, full suite, final log

## Foundation
- done — tables/models/factories/PlanAccess/routes/tabs/sidebar item (commit 62e51b3f)

## Browser-pass polish list (to do after the round-2 fixes merge)
- Production board: 5 columns don't fit at 1440px (horizontal scroll hides Approved);
  make columns share the width (min ~220px) and stretch to the page height; filter
  selects clip their labels ("All kinds", "All months").
- Brief due date uses the browser's native date input; use TryPost's `DatePicker`.
- Publish calendar: event chips truncate to one letter in narrow day cells; show a dot +
  count when there's no room (tooltip keeps the names).
- Plan month page after a failed AI run keeps the red banner until dismissed: fine, but
  check its wording once the planner fix lands.
- AI copy said "Click the shop link now!" with no link in the brand data: CopyWriter must
  not invent links/CTAs that need a URL it wasn't given.
- Plan tabs on mobile: the grid/list toggle sits over the tabs ("Events" cut off); move
  the toggle into the month toolbar row on small screens.
- Item page review thread: the "About" select is too narrow ("The item" clipped).
- Item page "Create post" when blocked: make the disabled state obvious (muted, not
  primary-tinted) next to its reason.
