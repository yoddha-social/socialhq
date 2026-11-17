# Next MVP (2026-11-06)

Owner's list, with the decisions taken on 2026-11-06. Three sessions build it on
top of `socialhq/companies`; the overseer session merges them.

## Decisions
- **Google Docs**: link-shared files first ("anyone with the link"): Docs, Sheets and
  Slides read through Google's export URLs, no Google sign-in. Private files later
  (Google sign-in, Drive read-only).
- **Website crawl**: same site only, sitemap first, up to ~25 pages, robots.txt
  respected, main text only (no menus, footers, scripts). The AI drafts brand profile
  fields and knowledge entries from it; an admin accepts each before it is saved.
  Token-optimal: cleaned text is deduplicated and cut before any AI call; pages
  already read and unchanged are not read again.
- **Other countries' events**: official public holidays per country from Nager.Date
  (https://date.nager.at, free), imported as "Needs check" like the Nepal list. The AI
  never computes dates.
- **Audience time zones**: the brand profile lists audience locations (country, city
  optional, time zone, share %). Plan items get a suggested time in the main (largest)
  audience's zone, shown in the viewer's zone too. No per-location duplicate posts.

## Session 1: Knowledge sources and brand theme
- Brand theme on Settings › Brand knowledge: logo (upload, private disk), brand
  colours (named hex values), fonts, visual style notes ("overall theme").
  Reaches the brief writer and AI graphics prompts (and a short line for copy).
- Google link import: paste a link, the file's text becomes a knowledge document
  (reuse `app/SocialHq/Knowledge/Documents`: a document whose source is a URL), with
  "Refresh" to read it again.
- Website crawl with review (decision above).

## Session 2: Audience-aware planning
- Brand profile: audience locations (decision above).
- "Generate with AI" options: date range (not only the month), include old
  calendars yes/no, include past posts yes/no, events only for the audience's
  countries yes/no, plus the existing focus box. Remembered per brand.
- Planner gets the audience (locations, main zone) and only relevant events; items
  get a suggested local posting time (`suggested_time`, in the main audience zone).
- Plan list view: optimise (dense, sortable, grouped by week, sticky header, quick
  edit), mobile friendly.

## Session 3: Events by country
- `shq_events.country_code` (ISO 3166-1 alpha-2, nullable; Nepal rows `NP`,
  international days null). Contract used by session 2: events "for the audience"
  = country_code in the audience's countries, or null (international), when that
  option is on.
- Import public holidays from Nager.Date for chosen countries and years (Events page
  "Add a country"), marked "Needs check", source linked, re-import safe like today's
  importer. Country filter on the Events page.
