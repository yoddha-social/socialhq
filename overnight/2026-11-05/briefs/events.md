# Lane brief — Events

Read first: `overnight/2026-11-05/LANE-RULES.md`, `overnight/2026-11-05/DESIGN.md`
(sections "Who can do what", `shq_events`, "Copy files and routes"), SOCIALHQ.md.
Test DB: `shq_lane_events`. PATCHES rows: 60–64. Migration prefix (only if you need a
new one): `2026_11_06_0004xx_`. Branch: `overnight/events`.

Already built for you (don't recreate): migration `2026_11_06_000301_create_shq_events_table`,
model `App\SocialHq\Events\CalendarEvent` (scopes `visibleTo`, `overlapping`, `verified`;
the date/name change clears verification), enums `EventCategory`, `EventRegion`, factory,
route file `routes/socialhq/events.php` (inside the `socialhq.plan.` group, prefix
`socialhq/plan`), copy file `lang/en/socialhq_events.php`, and the researched dataset in
`database/data/socialhq/events/` (`nepal-2083.csv`, `international.csv`, `README.md` —
read the README: sources, conflicts, weak rows).

## Build

1. **Importer** `App\SocialHq\Events\ImportOfficialEvents` (action) + artisan command
   `socialhq:events:import {--account=} {--file=}` registered in `SocialHqServiceProvider`
   (append one line to its `$this->commands([...])` array):
   - Reads both CSVs (or `--file`), upserts by `(account_id, workspace_id null, key,
     starts_on)`. `key` = slug of `name_en`. Nepal rows: `region nepal`, category from the
     CSV (`festival`, `public_holiday`, `national_day`, `observance`), `public_holiday` from
     `yes|partial` (partial → true, and put the community/region note in `description`),
     `bs_date`, `name_ne`, `source_url`, `source_name`. International rows: `region
     international`, category map `international_day`→InternationalDay,
     `commercial`→Commercial, `global_holiday`→Observance; `rule` and `notes` into
     `description` (short).
   - Every imported row is **unverified**. Re-importing never clears a verification of an
     unchanged row, and never overwrites a row a person edited (rows with `created_by`
     set, or updated after import: keep it simple — skip rows whose `updated_at` is later
     than `created_at`).
   - Returns counts {created, updated, skipped}.
2. **Events page** `GET socialhq.plan.events.index` → `socialhq/plan/Events.vue`:
   - Month navigation (default: the current month in the user's zone; `?month=YYYY-MM`),
     plus "Next 90 days" overview toggle if cheap. List grouped by day: date (AD, and
     the BS date text when present), name (+ Nepali name), category badge, "Public
     holiday" badge, region, source link (external, `rel=noopener`), verified state:
     "Confirmed by {name}" or a "Needs check" badge (dashed outline).
   - Filters: All / Nepal / International / This brand only / Needs check.
   - Actions for people with `approvePosts`: "Confirm date" (verify) and "Undo
     confirmation"; edit (dialog: name, Nepali name, category, dates, BS date, holiday,
     description, source link, "only this brand" switch); delete (plain confirmation,
     CLAUDE.md delete rules); "Add a date" (dialog, same fields; brand-only by default);
     "Import official dates" (runs the importer for the account; toast with counts).
   - Members without `approvePosts` see everything read-only, with one line naming who
     can confirm dates (in-app-copy rules for people who cannot act).
   - First-use empty state (no events at all for the account): illustration + "Import
     official dates" for approvers; for members, who can import.
   - Explain once, in the page description: dates come from official lists and a person
     confirms each one; the AI plans only around confirmed dates.
3. **JSON for other features**: `App\SocialHq\Events\EventsForMonth::forWorkspace(Workspace,
   CarbonImmutable $from, CarbonImmutable $to): Collection` returning the visible events
   (verified and not) ordered by date — the planner and the plan grid will call it. Unit
   test it.
4. **Calendar overlay** (TryPost's publish calendar, small patch): show a tiny marker
   with event names on days that have events, in month view (`CalendarMonthGrid.vue` next
   to the day number) and week/day view headers (`CalendarTimeGrid.vue`). Data: add one
   optional prop through `BuildCalendarPageProps` (`shqEvents`: `Record<'YYYY-MM-DD',
   {name, verified, category}[]>`, computed for the shown range) — or, if a cleaner seam
   exists, an Inertia shared prop is NOT acceptable (per-request cost); prefer the
   `Inertia::optional`/always-sent prop on that page only. Render through a new
   `components/socialhq/plan/CalendarEventMarkers.vue` (tooltip with the full list, dashed
   style for unconfirmed). Keep each TryPost file edit to a few lines; PATCHES rows.
   If this turns out risky (drag-and-drop, layout shift), do the smallest safe version
   (month view only) and report.
5. **Tests** (`tests/Feature/SocialHq/Plan/EventsTest.php`): importer (counts, idempotent,
   keeps verification, skips edited rows, maps categories), page renders for member,
   verify/unverify/edit/delete/add authorized for approvers and refused (403) for members,
   cross-account isolation (another account's event id → 404), brand-only events not
   visible to another workspace, calendar prop contains the range's events.

UI quality bar: this page is what the team opens to trust the planner. Make it calm and
scannable: day groups, tabular alignment, badges with consistent colours per category
(define the palette once in `components/socialhq/plan/eventStyles.ts`), keyboard
reachable actions, `data-testid`s, mobile layout that stacks. Use TryPost's components
(`AppLayout`, `ui/badge`, `ui/dialog`, `EmptyState`, `SettingsListRow` where it fits).
Add the `PlanTabs.vue` component (`components/socialhq/plan/PlanTabs.vue`: Content plan /
Production / Events, using `socialhq_plan.tabs.*` keys — it exists in copy already; link
targets: `socialhq.plan.events.index` now; the other two routes don't exist yet, so render
those tabs from props/conditionally via `route().has` equivalent — simplest: accept an
`items` prop list, and only Events is passed for now). The overseer wires the other tabs.
