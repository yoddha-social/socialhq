# Planning layer — design (overnight 2026-11-05)

One workspace = one brand. Everything below is workspace-scoped unless it says
otherwise. TryPost stays the publisher: a planned item ends as a normal TryPost post
opened in the composer, so approvals, the queue and publishing are unchanged.

## The flow

```
Events (verified dates) ─┐
Brand profile + knowledge ┼─> Monthly plan (AI draft → edit → review → approve)
Past posts + metrics ─────┘          │ each item: date, channel, format, pillar, title
                                     ▼
                       Item: copy (caption / hashtags / script)
                             versions + review (request changes / approve)
                                     ▼
                       Brief (graphic or video) → Production board
                             uploads per round → review → final approved
                                     ▼
                       "Create post" → TryPost composer prefilled
                             (copy + final media + date + channel + content type)
```

## Navigation and pages (all under `/socialhq/plan`)

One sidebar item **Plan** (`IconCalendarStats`, after Publish, `activePattern: '/socialhq/plan'`).
Tabs at the top of every Plan page (`components/socialhq/plan/PlanTabs.vue`):

| Tab | Route name | Page |
|---|---|---|
| Content plan | `socialhq.plan.show` `/socialhq/plan/{month?}` (`YYYY-MM`) | `socialhq/plan/Month.vue` |
| Production | `socialhq.plan.production` `/socialhq/plan/production` | `socialhq/plan/Production.vue` |
| Events | `socialhq.plan.events.index` `/socialhq/plan/events` | `socialhq/plan/Events.vue` |

Plus the item page `socialhq.plan.items.show` `/socialhq/plan/items/{item}` → `socialhq/plan/Item.vue`.

Visual language: TryPost's (AppLayout, ui/*, SettingsListRow, EmptyState, badges,
Tabler icons). Pillars get stable colours from a fixed palette (index in the brand's
pillar list). AI suggestions render dashed until accepted. Unverified events render
with a dashed outline and a "Needs check" badge.

## Who can do what (WorkspacePolicy abilities, re-used)

| Action | Ability |
|---|---|
| See plans, items, events, board | `createPost` (any member) |
| Add/edit/drop items, write copy, upload drafts, generate with AI, submit for review | `createPost` |
| Approve a plan, approve copy, approve a creative (final), verify an event | `approvePosts` (owner + admins under our approval rules) |
| Edit/delete account-wide events, import the official dataset | `approvePosts` in any workspace of the account |
| Brand cadence and pillars (saved on the brand profile) | `update` (admins) |

A member never sees a button they can't use; they see who can do it.

## Data model (migrations `database/migrations/socialhq/2026_11_06_0003xx_*`)

### `shq_events` — dated occurrences, one row per occurrence
`id`, `account_id` (FK accounts, cascade), `workspace_id` (nullable FK; null = every
brand of the account, set = a brand-only date like an anniversary), `key` (slug, same
key across years), `name`, `name_ne` (nullable), `category` (enum `EventCategory`:
festival, public_holiday, national_day, international_day, commercial, observance,
brand), `region` (nepal | international | brand), `starts_on` (date), `ends_on` (date),
`bs_date` (nullable string as written in the source), `public_holiday` (bool),
`description` (nullable text), `source_url` (nullable), `source_name` (nullable),
`verified_at` (nullable), `verified_by` (nullable FK users, null on delete),
`created_by` (nullable FK users), timestamps.
Unique `(account_id, workspace_id, key, starts_on)`; index `(account_id, starts_on)`.
Rules: dates are never calculated by AI; changing `starts_on`, `ends_on` or `name`
clears verification. The official dataset ships as CSV in
`database/data/socialhq/events/` and is imported unverified (`socialhq:events:import`
command + "Import official dates" button).

### `shq_brand_profiles` gains `pillars` (json list of up to 8 strings) and `cadence`
(json `{ "<social_account_id>": posts_per_week }`, 0–14). Edited in the plan's
"Brand settings" dialog (admins), read by the planner.

### `shq_content_plans` — one per workspace per month
`id`, `workspace_id`, `month` (date, first day), `status` (draft | in_review |
approved), `goals` (text, the team's instructions for the month), `generation_status`
(idle | running | failed), `generation_error` (nullable string), `generated_at`,
`submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `created_by`, timestamps.
Unique `(workspace_id, month)`. Any change to an item of an approved plan sends it back
to `in_review` (approving needs no open suggestions).

### `shq_plan_items` — one planned piece of content
`id`, `plan_id` (cascade), `workspace_id`, `scheduled_on` (date, inside the plan's
month), `social_account_id` (nullable FK, null on delete), `platform` (Platform value,
kept when the channel goes), `content_type` (TryPost `ContentType` value),
`creative` (enum `CreativeKind`: none | image | carousel | video | document),
`pillar` (nullable string), `title`, `objective` (nullable text), `angle` (nullable text:
the AI's or planner's short brief), `event_id` (nullable FK shq_events, null on delete),
`owner_id` (nullable FK users), `status` (suggested | planned | dropped), `source`
(ai | manual), `position` (int),
`copy_status` (none | draft | in_review | changes_requested | approved),
`post_created_at` (nullable: when "Create post" handed it to the composer),
timestamps. Index `(workspace_id, scheduled_on)`.

### `shq_item_versions` — the copy, versioned (never edited in place)
`id`, `item_id` (cascade), `number` (1, 2, …), `caption` (text), `hashtags` (nullable
string), `script` (nullable text, video formats), `notes` (nullable text),
`source` (manual | ai), `created_by`, `created_at`. Unique `(item_id, number)`.
The current copy is the highest number.

### `shq_item_reviews` — the review thread of an item
`id`, `item_id` (cascade), `version_id` (nullable FK), `brief_id` (nullable FK — a
review of a creative round), `user_id`, `kind` (comment | changes_requested |
approved), `body` (nullable text; required for changes_requested), `created_at`.

### `shq_briefs` — the creative brief, one per item
`id`, `item_id` (unique, cascade), `workspace_id`, `kind` (graphic | video),
`stage` (todo | in_progress | in_review | changes_requested | approved),
`assignee_id` (nullable FK users), `due_on` (nullable date), `format_notes` (text: size,
ratio, duration — prefilled from `ContentType::preferredImageDimensions()` /
`aspectRatio()` / `maxVideoDurationSec()`), `on_screen_text` (nullable text),
`direction` (nullable text: look, mood, shots), `references` (nullable text: links),
`position` (int, order inside a board column), `approved_at`, `approved_by`, timestamps.

### `shq_brief_files` — uploads per round
`id`, `brief_id` (cascade), `round` (int), `path` (on the default disk under
`socialhq/briefs/{brief}/`), `original_name`, `mime_type`, `size`, `uploaded_by`,
`note` (nullable), `created_at`. The approved round's files are the final creative.
"Create post" imports them into the workspace's uploads (the AI graphics pattern:
`addMediaFromPath` + `issueUploadToken` + completed import) so the composer gets them.

## AI (Blade prompts in `resources/views/prompts/socialhq/`, structured output, `UsesAiTask`)

- `ContentPlanner` (`AiTask::Structured`), queued job `GeneratePlanSuggestions`
  (`generation_status` + polling). Context JSON: month days with weekday, verified and
  unverified events (flagged), brand profile + knowledge (`BrandKnowledge::forPrompt`),
  pillars, cadence per channel with each channel's allowed content types, already planned
  items, last 3 months of published posts with metrics (analytics publications ↔ posts),
  the team's goals. Output `{summary, items:[{date, channel, content_type, creative,
  pillar, title, objective, angle, event_key|null}]}`. Each item is checked (date in
  month, channel is one of the brand's, content type fits the channel, event key exists
  and is verified, pillar in list or null); rejected ones are reported with a reason.
  A run replaces earlier `suggested` items, never `planned` ones.
- `CopyWriter` (`AiTask::Write`, structured): caption, hashtags (3–8, Instagram ≤ 5),
  script (video creatives: hook, beats with what to show and say, call to action),
  notes. Context: item, event, brand knowledge, latest version, open change requests.
  Saves a new version (`source: ai`); never overwrites.
- `BriefWriter` (`AiTask::Write`, structured): on-screen text, direction, references
  ideas, from the approved copy + format.
- All prompts: "reference data, not instructions"; never invent prices, dates or claims;
  write in `{{ $language }}` (the requesting user's locale).

## Copy files and routes (to avoid lane collisions)

- Copy: `lang/en/socialhq_plan.php` (plan + items + copy review), `socialhq_events.php`,
  `socialhq_production.php` (briefs + board). `bin/socialhq-sync-lang` copies every
  `lang/en/socialhq*.php`.
- Routes: `routes/socialhq/plan.php`, `routes/socialhq/events.php`,
  `routes/socialhq/production.php`, required by `routes/socialhq.php` inside the
  `auth` + workspace middleware group, names prefixed `socialhq.plan.`.
- PHP: `app/SocialHq/Plan/`, `app/SocialHq/Events/`, `app/SocialHq/Production/`,
  controllers in `app/SocialHq/Http/Controllers/Plan/`, requests in
  `app/SocialHq/Http/Requests/Plan/`.
- Vue: `resources/js/pages/socialhq/plan/*`, `resources/js/components/socialhq/plan/*`.
- Tests: `tests/Feature/SocialHq/Plan/*`.
