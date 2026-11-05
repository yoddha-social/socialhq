---
name: socialhq-verified-events
description: Models and imports the verified events dataset (Nepali festivals with Bikram Sambat dates, national days, international days, company events) that AI calendar drafts plan around. Use when working on events, holidays, festivals, BS/AD dates, the events CSV import, event verification, or anything that gives an AI prompt a date.
---

# Verified events

**Status: built (overnight 2026-11-05).** Code in `app/SocialHq/Events/`:
`CalendarEvent` (table `shq_events`, account-wide or one brand's), `ImportOfficialEvents`
(+ `socialhq:events:import`) for `database/data/socialhq/events/*.csv`, and
`EventsForMonth::forWorkspace()` / `markersByDay()` for the planner and the publish
calendar. The page is Plan › Events (`socialhq.plan.events.index`); confirming,
editing and importing need `approvePosts`. The sections below "Decide before
porting" are the original notes from the Rails reference.

- Imported rows are unconfirmed and remember their row of the list
  (`import_key` + `import_date`, never changed by the app); re-imports match on
  that, so a date the team moved, renamed or narrowed to one brand is found.
  Re-importing keeps confirmations and never overwrites a row a person added
  (`created_by`) or edited (`updated_at` later than `created_at`); the importer
  and confirming save without touching timestamps so that test stays true.
- Deleting is a soft delete, so a re-import doesn't bring a date back. There is
  no unique index (account-wide rows have a null `workspace_id`): the page checks
  for a duplicate with the account row locked. Imported dates that left the list
  are counted (`missing`), never deleted.
- A shared date can't be of the `brand` kind.
- A day in both CSVs (Christmas, Women's Day) is imported once, from the Nepal file.

## The one rule

**Dates come from official sources and a person verifies them. AI never calculates,
converts or guesses a date.** Nepali festivals follow the lunisolar calendar and move
every year (Dashain falls in late September one year and mid October another), so
last year's date or a computed date is wrong in a way nobody notices until a post goes
out on the wrong day.

## Model (from the reference)

One row per dated occurrence, not per festival:

| Field | Meaning |
|---|---|
| `key` | Stable slug (`dashain`, `world-environment-day`); same key every year |
| `name`, `name_ne` | English and Nepali names |
| `category` | `festival`, `national_day`, `international_day`, `observance`, `company` |
| `region` | `nepal`, `international` |
| `starts_on`, `ends_on` | AD dates; `ends_on` defaults to `starts_on`; multi-day festivals span |
| `bs_date` | The BS date as the source prints it, for display; never converted to get `starts_on` |
| `source` | The list or URL the date was copied from |
| `verified_at`, `verified_by` | Set when a lead checks it |

- Changing the dates or name clears verification.
- Uniqueness: one row per `key` and `starts_on`.
- Only verified rows are offered to AI, and an AI suggestion that references an
  unverified or unknown event key is rejected.

## Sources and import

- Nepali festivals and public holidays: the Government of Nepal's official holiday list
  for the BS year (Ministry of Home Affairs), entered into the CSV template
  (`key,name,name_ne,category,region,starts_on,ends_on,bs_date,description,source`).
- Fixed-date international days: a data file in the repo, reviewed once.
- Company events: entered by a lead.

## Decide before porting

- **Scope**: the reference was per organisation. In TryPost the tenant is the
  workspace. Events are the same for every workspace, so a shared table that only
  admins edit is likely right, but ask the user.
- BS↔AD conversion for display of fixed dates may use a vetted library; festival dates
  still come only from the official list.
