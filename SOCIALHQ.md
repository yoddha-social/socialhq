# Social HQ

Social HQ is the internal social media platform of our team: a fork of
[TryPost](https://github.com/trypostit/trypost) (AGPL-3.0) with our own
features in a separate module. TryPost gives us workspaces, members,
approvals, the calendar, the post composer, media and publishing; Social HQ
adds the planning and AI layer:

- a verified events dataset (Nepali festivals from the official holiday list,
  international days), never calculated by AI;
- AI monthly calendar drafts from brand profiles, verified events and past
  posts, checked before anything is saved;
- ideas, scripts and creative briefs, with review and approval;
- AI through a LiteLLM gateway: free providers first, paid Gemini last, only
  providers that don't train on prompts.

## Where our code lives

| What | Path |
|---|---|
| PHP (models, actions, jobs, controllers, policies, AI) | `app/SocialHq/` (namespace `App\SocialHq`) |
| Wiring (routes, migrations, schedule) | `app/SocialHq/SocialHqServiceProvider.php` |
| Database migrations (tables prefixed `shq_`) | `database/migrations/socialhq/` |
| Routes | `routes/socialhq.php`, which loads `routes/socialhq/*.php` (all under `/socialhq/*`) |
| Configuration | `config/socialhq.php` |
| Inertia pages and components | `resources/js/pages/socialhq/`, `resources/js/components/socialhq/` |
| Tests (Pest) | `tests/Feature/SocialHq/`, `tests/Unit/SocialHq/`, `tests/Browser/SocialHq/` |
| Interface copy | `lang/en/socialhq.php` (see below) |
| Image build | `.github/workflows/socialhq-image.yml` (tags `shq-v*`) |
| Dependency audit | `.github/workflows/socialhq-audit.yml` (PRs and weekly) |
| CI | `.github/workflows/socialhq-ci.yml` (PRs and pushes to `main`), PostgreSQL only: quality (Pint, ESLint, `vue-tsc`, Prettier on changed files), backend in 2 shards, browser in 4 shards, and production rules: TryPost's post, API, MCP, parity and repurpose suites with the approval and company rules on (`phpunit.xml` turns them off), skipping the tests listed with the reason in `tests/fixtures/socialhq/production-rules-exclusions.txt`. The job `ci` passes only when all of them pass; it is the check `main`'s ruleset is to require (until the owner switches it, the ruleset requires `quality`, `backend` and `e2e`, which the workflow still reports) |
| Claude Code setup | `CLAUDE.md`, `.claude/settings.json`, `.claude/hooks/`, `.claude/rules/`; skills in `.ai/skills/socialhq-*` (linked into `.claude/skills/` and `.agents/skills/`), checked by `bin/socialhq-lint-skills` |

Changes to files that came with TryPost are listed in [PATCHES.md](PATCHES.md),
which also logs the monthly security intake (we take TryPost's security fixes,
not its feature releases). Follow the conventions in [AGENTS.md](AGENTS.md)
(Laravel Boost, Pest, Pint, Inertia v3, Wayfinder).

## AI routing

Every AI call names a task (`App\SocialHq\Ai\AiTask`): `write` (captions,
rewrites, shortening), `structured` (ideas), `vision` (alt text, the graphics
safety check), `image` and `embed`. `AiRouter` turns the task into the
providers the SDK tries in order. Agents get it from the `UsesAiTask` trait;
TryPost's four agents carry it too (PATCHES.md row 15). Code:
`app/SocialHq/Ai/`, settings in `config/socialhq.php` ("ai").

- **Gateway** (`SOCIALHQ_AI_GATEWAY=litellm`, production): each task goes to
  its LiteLLM model group (`shq-write`, `shq-structured`, `shq-vision`,
  `shq-image`, `shq-embed`) with the app's virtual key (`LITELLM_API_KEY`).
  LiteLLM tries Groq, Cloudflare Workers AI and Mistral first (a free group
  per task) and falls back to the task's paid group (Gemini); the key's
  budget ($25 every 30 days) caps paid spending and never blocks the free
  groups.
  The groups live in `socialhq-deploy/litellm/config.yaml`.
- **Direct** (the default; tests and development): the SDK calls providers
  from `config/ai.php` with their own keys. `SOCIALHQ_AI_ROUTE_<TASK>` sets a
  route ("provider:model,provider", tried in order on rate limits and
  overload); without one, a task uses the SDK's default provider (images:
  Gemini).
- **Approved providers only** (`SOCIALHQ_AI_APPROVED_PROVIDERS`, default the
  two LiteLLM entries). Any other target is dropped, and
  `ApprovedProvidersGuard` refuses any SDK request to another provider before
  it is sent, even from code that skips `AiRouter`. A key for a provider that
  trains on prompts (the Gemini free tier) is never called with the
  platform's keys (a company may choose one for its own, see Settings › AI).
  Mistral is in the
  LiteLLM chain only with "Anonymous improvement data" off in its console.
- **Gateway tasks** (`SOCIALHQ_AI_GATEWAY_TASKS`, default
  `write,structured,vision`): the gateway serves only these. `image` and
  `embed` have only paid Gemini in LiteLLM, so they stay off (and "Generate
  with AI" says images aren't set up) until its key is set and they are
  listed.
- **Per company** (gateway only; `app/SocialHq/Ai/Company/`). Each call
  runs for a company (`AiTenant`): on the web the user's current workspace's
  account, in a job the workspace it carries (`AiTenant::run($workspace,
  ...)`; a job without one fails closed). `CompanyRouting` decides once per
  request or job: a company with its own provider keys calls
  `shq-<task>@shq-co-<account>` with its own LiteLLM team key (SDK entry
  `litellm-co-<account>`, so no key carries over between companies or
  Horizon jobs); one without keys uses the platform groups above only when
  a platform admin allowed it (`uses_platform_ai`); otherwise AI buttons
  hide and a call says "add an AI provider in Settings › AI". Written
  routes (`SOCIALHQ_AI_ROUTE_<TASK>`) apply to the platform only.
- **Settings › AI** (owner of an approved company): add keys from the
  catalogue (`socialhq.company_ai.providers`: Groq, Cloudflare Workers AI,
  Mistral with its training opt-out ticked, Gemini, OpenRouter, OpenAI,
  Anthropic), up to 20, several for one provider too (more free quota, or
  a key per team). Each key has a name (unique in the company), a plan
  (free or paid, as the provider offers: Gemini paid needs billing ticked;
  the Gemini free tier and OpenRouter's free models need "may train on what
  we send" ticked, the company's own choice; OpenRouter paid needs its
  training providers turned off), the tasks it serves (a subset of the
  plan's) and, per task, a model id instead of the catalogue's. A free
  key's deployments carry zero prices, so they never use up the budget. A
  key is tested through LiteLLM before anything is saved, and again with
  the stored key when an edit changes the model a test calls; Edit (name,
  plan, tasks, models), Test, Replace, Remove, order (LiteLLM's deployment
  `order`: keys are tried top to bottom, the next one on an error or rate
  limit), monthly budget (the team's `max_budget`) and spend. Each key is
  its own LiteLLM credential (`<team>-<provider>-<random>`; keys added
  before names keep `<team>-<provider>`) with deployments
  `<credential>-<task>`. Each key's row records a fingerprint of every
  deployment it added (`deployments`), so a change removes and adds only
  the deployments that differ; a key whose row doesn't know (older keys, or
  after a failed add) has all of its deployments removed and added once.
  A plan's task in the catalogue may carry `params` for its deployments:
  OpenRouter's free models run with reasoning off
  (`extra_body.reasoning.enabled: false`), because the free reasoning
  models spent a call's whole output limit thinking and answered nothing
  (QA 2026-11-23). When a release changes the catalogue,
  `php artisan socialhq:ai-sync` (run by the entrypoint on every start)
  re-adds only the deployments whose settings differ; with nothing to
  change it sends nothing.
  Provider keys live
  only in LiteLLM (credentials, encrypted with its salt key); the app keeps
  the last 4 characters. The team key is encrypted with
  `SOCIALHQ_SECRETS_KEY` (`SecretBox`, never APP_KEY). Every change is in
  `shq_company_ai_events`. The app calls LiteLLM's admin API with
  `LITELLM_ADMIN_KEY` (`Gateway\LiteLlmAdmin`), which needs
  `store_model_in_db`. Team-scoped model names are LiteLLM Enterprise, so
  each company's groups are global names its team key alone may call.
- `php artisan socialhq:ai-routes` shows each task's targets and why one is
  unused; `--live` sends a small request through each (`--images` adds one
  image); `--account=<id>` routes as that company.
- **Spend attribution in LiteLLM**: every text call through the gateway also
  carries the account id as OpenAI's `user` field and
  `metadata.spend_logs_metadata` (account, workspace, task), so platform-AI
  spend, which shares `LITELLM_API_KEY`, can be told apart in LiteLLM's own
  spend logs (`AiRouter::gatewayAttribution`). Image and embedding calls
  can't: the SDK has no hook for them. The app's own record is the usage log
  below.
- **Timeouts** (`AiTimeout`, through `UsesAiTask::timeout()`): 90 s for a
  call someone waits for (Cloudflare ends a request at about 100 s), 300 s
  for agents that only run in queued jobs (the planner, the website reader).
  LiteLLM's per-deployment timeouts must leave room for a fallback inside
  90 s.
- **Temperature through the gateway**: the model the app sees is a group
  name, so `models_without_temperature` never matches and the temperature
  is always sent; LiteLLM's `drop_params: true` removes it for models that
  reject it. Keep that setting in the LiteLLM config.
- **"AI isn't set up"** (the company has no AI, or none that serves the
  task) is a configuration state, not an error: endpoints answer 409 with
  copy that says how to set it up (`AiResponses`), jobs don't report it, and
  the shared `aiTasks` prop (`write`, `structured`, `vision`, and `any`) hides
  the assistant and idea buttons. A company with some AI still sees "Generate
  with AI" and alt text's "Generate" when its AI can't make or read images:
  dimmed, with what is missing and who adds it on hover, focus and click
  (`AiMissingTooltip.vue`); a company with no AI at all sees none of them.
  A company key that no secrets key opens (missing or rotated
  `SOCIALHQ_SECRETS_KEY`) turns that company's AI off and is logged; after a
  rotation run `php artisan socialhq:secrets-reencrypt` (`--dry-run` first),
  then drop the previous key.
- **Settings › AI › Usage** (same owner; `/socialhq/settings/ai/usage`) lists
  each AI request and how it was routed; platform admins see every company's
  at `/socialhq/platform/ai-usage` (a link on the companies page, 404 for
  others). `Ai/Usage/AiUsageRecorder` writes `shq_ai_usage_logs`, one row per
  request the SDK sends to LiteLLM plus calls refused before sending
  (`UsesAiTask`, "No AI provider"): company, brand, person (a job gets the
  user of the request that queued it through hidden Context), feature (the
  agent's class), task, keys used (own or platform), status (answered,
  failed, cut off, rate-limited, budget reached, no AI provider; "cut off"
  is a 200 whose answer stopped at its length limit, from the SDK's finish
  reason, so an empty answer is never counted as answered), and from LiteLLM's
  response headers the model that answered, the group that answered, the
  fallbacks, the cost and the duration; tokens come from the SDK. Only
  requests to the gateway's model endpoints carry our `x-litellm-call-id` and
  `x-litellm-tags` (feature, company), so LiteLLM's own spend log can be
  matched by call id; no other host gets them. **Metadata only**: never a
  prompt, an answer or a provider's error text (only LiteLLM's `error.type`).
  Logging never breaks AI (each step is `rescue()`d). Rows are kept 90 days
  (`AiUsageLog::RETENTION_DAYS`, `model:prune` daily). Images and streamed
  replies get no group or fallback headers from LiteLLM; its admin UI (port
  4000) has those by call id.
  The page shows four stat cards (requests per hour or day, spent against
  the company's monthly budget over the last 30 days, the share answered
  free, failed or blocked), requests by feature and by provider, and the
  log as one card per day in the viewer's time zone, counted in PHP
  (`AiUsagePage`). Days page 7 at a time (`DAYS_PER_PAGE`, owner decision
  2026-11-18, an exception to the config page size); a day's rows load when
  it is opened (`socialhq.ai-usage.day` / `socialhq.platform.ai-usage.day`,
  up to 500). Today and days with failed or blocked requests start open.
- That log is a **scoped exception** to AGENTS.md "AI usage is not recorded
  or metered" (user decision 2026-11-16): it is an admin audit log only.
  Nothing reads it to allow, limit or bill AI (LiteLLM's team budget does
  that) and `AccountPolicy::useAi` is unchanged. Don't add credits, limits
  or billing on top of it.

## Brand knowledge

Each workspace (one brand) has a brand profile (what the brand is, audience,
voice and tone, words to use and to avoid) and knowledge entries (products,
projects, past campaigns, facts), under Settings › Content › Brand knowledge.
Members who can post read it; workspace admins change it. Code:
`app/SocialHq/Knowledge/`.

`BrandKnowledge::forPrompt($workspace)` builds the block every AI feature
gets; `assemble()` also says which entries made it in and the space used.
Budget: `SOCIALHQ_KNOWLEDGE_PROMPT_CHARACTERS` (6000), counted by
`BrandKnowledge::size()`, where characters outside Latin-1 (Devanagari,
emoji) count double.

1. The profile takes at most half. When longer, each field is shortened
   fairly (short fields stay whole) and ends with "…".
2. Entries marked "Use in AI writing" fill the rest: pinned ones ("Always
   use in AI writing") first, then newest by creation date, so editing
   an entry never pushes others out. One that doesn't fit is skipped.
3. Runs of `"""` in the text are broken up, so nothing can close the quoted
   block. The prompt tells the model it is reference data, not instructions.

The page shows the space used and, per entry, whether it is in AI prompts,
doesn't fit (pin it or shorten others) or is too long on its own. Up to
`SOCIALHQ_KNOWLEDGE_MAX_ENTRIES` (300) entries per workspace (counted before
creating, so two admins adding at once may pass it by one; accepted).

The caption assistant and the idea generator get it through
`prompts/socialhq/brand_knowledge.blade.php`, filled by a view composer for
the user's current workspace; their controllers authorize that workspace. New
features (calendar drafts, scripts, briefs) call `forPrompt()` with the
workspace they work for and pass the text to their own prompt view.

**Old calendars** (same page): admins import past content calendars as CSV
or XLSX (up to 5 MB and 5,000 rows, 50 per brand; `knowledge.calendars` in
`config/socialhq.php`; an XLSX is checked by `ZipGuard` before it is read,
and rows keep 200 columns at most). The file is read once: its sheets wait
on the private `local` disk (JSON) while the admin matches columns (guessed
from English and Nepali headers; only the date is required) and the date
order; importing stores the rows in `shq_past_posts` and deletes the file.
The same file (by hash) is imported once per brand. Uploading and
importing share a limit of 60 an hour per person. Rows without a readable AD date, in
the future or with a Bikram Sambat year (2070–2099) are skipped with a
reason; dates are never converted. Uploads never imported are pruned after
a day (`socialhq:calendars:prune`, hourly). The planner gets up to 60 rows
(`old_calendar`): the plan's month in earlier years first, then the best by
engagement, reach or views, then the newest. Code:
`app/SocialHq/Knowledge/Calendars/`.

The planner, the copy writer and the brief writer read it too (see Planning).

**Documents.** Admins upload PDF, Word (.docx), PowerPoint (.pptx), text and
Markdown files (type checked by content, up to 10 MB, 30 per workspace;
`socialhq.knowledge.documents`). They live on the private `local` disk under
`socialhq/knowledge/{workspace}/`, are downloaded only by members, and go with
the document or the workspace. `ExtractKnowledgeDocument` (queued) reads the
text (`DocumentText`: zip sizes checked before reading, at most 300,000
characters) into excerpts of about 900 characters (`Chunker`). A scan with no
text, a password or a damaged file fails with a reason the page shows. To
change the text, delete and upload again.

**Google links** (same section, "Add Google link"). A Docs, Sheets or Slides
file shared as "Anyone with the link can view" is read without a Google
sign-in, through its export URL (Docs and Slides as text, Sheets as XLSX:
every visible sheet, "cell | cell" rows). Only `https://docs.google.com`
links are taken (`socialhq.knowledge.google.host`), and only the file id is
kept: the export URL is rebuilt from it. Redirects may go to Google's own
hosts only (`*.googleusercontent.com`); a sign-in redirect or page, a 401 or
a 403 means the file isn't shared ("Share it as 'Anyone with the link can
view', or upload the file"). The text is stored as a .txt document
(`source` `google_doc` / `google_sheet` / `google_slides`) and goes through
the same extraction, limits and count as an upload. "Read again" re-reads
it; unchanged text (same hash) keeps its excerpts, and a read that fails
keeps the document ready with the excerpts read before ("We couldn't read
it again"; only a first read fails the document). The title follows the
file's name until an admin renames it. Code: `app/SocialHq/Knowledge/Sources/`.

**Brand look** (same page). The logo (PNG, JPEG, WebP or SVG, checked by
content, up to 2 MB; an SVG is stored only after `SvgSanitizer` keeps its
drawing and drops scripts, handlers, foreign content, images and links out,
and is served with a `default-src 'none'; sandbox` policy) on the private
`local` disk under `socialhq/brand/{workspace}/`, served to members and
deleted with the workspace; up to 8 named hex colours; heading and body
fonts; "Overall look" notes (`shq_brand_looks`, `Knowledge/Look/`). The
brief writer gets it all as reference data (`BrandLook::promptText()`), the
copy writer only the style notes (`copyLine()`). AI graphics offer "Follow
our brand look" (the text goes in the image prompt and is kept on the
`shq_ai_graphics` row) and "Add our logo" (a bitmap logo becomes the first
of the 3 reference images; the vision check is told it is the company's
own). An SVG logo can't be a reference: upload a PNG for that.

**SEO keywords** (Strategy tab; admins edit, members read). Up to 5 main
and 25 supporting keywords on the brand profile (`keywords`, `[{text,
main}]`; `socialhq.knowledge.keywords`), cleaned (one line, lowercased, a
leading `#` dropped) and without duplicates (case and accents ignored).
Every change saves the whole list (`PUT socialhq/brand/keywords`). A CSV or
text file can be dropped on the card or chosen: it is read in the browser
(up to 100 KB; a `keyword` column or the first one, an optional `group`
column), each row checked through `brand/keywords/check`, shown in a
preview, and saved through the same `PUT`. The keyword check
(`Knowledge\Keywords\KeywordCheck`, code, no AI, cached per brand until
its knowledge changes) gives each keyword a verdict, first match wins:
**Conflict** (a word or phrase of "words to avoid" is in it), **Weak** (no
single profile field, entry used in AI or document excerpt holds all its
words, claim words aside), **Check** (one word, a claim word from
`claim_words`, a country or time zone city that isn't an audience place,
or the same words as another keyword), else **Good fit**, with where it was
found and how many posts of the last 90 days say it (`KeywordMatcher`:
words in order, case and accents ignored, linking words between allowed,
a hashtag of the words run together counts). Verdicts are warnings only
(owner decision 2026-11-23): every keyword goes to the AI. It checks fit
with the brand, never search demand; there is no score. A prompt gets the
main keywords, then the supporting ones that share most words with its
focus, 8 at most, outside the knowledge budget
(`BrandKeywords::forPrompt()`): the copy writer through its own view, the
caption assistant and the idea generator through the `brand_knowledge`
view composer (a rewrite of the person's caption is only told to keep the
keywords it has). The rules (`prompts/socialhq/seo_keywords.blade.php`):
one or two where they read naturally, never as a claim, never translated.
A place inside a keyword is a source for "Check these". When the brand has
none, the plan item's AI copy and the composer assistant's new captions
answer `keywords_missing` and show a light note (× hides it for 7 days in
that browser).

**Read our website** (admins). The brand's address starts `ReadWebsite`
(queued, 5 an hour per person, one run at a time per brand):
`WebsiteCrawler` reads robots.txt (rules for `SocialHQBot` or `*`, its
Crawl-delay, its sitemaps), then the sitemap (an index followed one level),
then the home page's links; the same site only (with or without `www.`),
HTML only, up to 25 pages (`socialhq.knowledge.website`), a pause between
requests (1 s or the Crawl-delay), 150 s for the run. Every request goes
through `SourceFetcher` (`SafeHttpFetcher`): http(s), DNS resolved and
private, loopback and link-local addresses refused on every redirect hop,
size and time caps. `PageText` keeps the main text (`<main>`, else the
largest `<article>`, else the body; no nav, header, footer, aside, forms,
scripts, hidden parts or cookie banners) and notes the markup's colours and
fonts. Pages unchanged since the last run (304 to their ETag or
Last-Modified, or the same HTML hash) reuse their stored text
(`shq_website_pages`). Lines seen on an earlier page are dropped, each page
is cut to 4,000 characters and the whole to 20,000, and only that goes to
the AI: one `WebsiteReader` call (`AiTask::Structured`, every key required),
none at all when the text is the same as the last run's (its suggestions
carry over). `WebsiteSuggestions` checks the answer (unchanged fields,
entries whose title exists, colours not in the markup are dropped). The
page lists each suggestion (profile field, entry, colour, font, overall
look, and keeping the pages' text as the "Website" document for search,
which doesn't count toward the documents limit) with Accept, Edit and Skip;
nothing is saved until accepted (`WebsiteReview`). Each run is logged in
`shq_website_crawls` (who, status, reason, provider, model). Code:
`app/SocialHq/Knowledge/Website/`.

**Focus and search.** Every caller passes what it writes about as `$focus`:
the planner the month's goals, pillars, confirmed events and networks; the
copy writer the item (title, objective, angle, pillar, event, format) and open
change requests; the brief writer the item and its caption; TryPost's caption
assistant and idea generator the request typed in their form
(`TryPostPromptFocus`). With a focus the order is: profile, pinned entries,
the entries and up to 6 excerpts that match (best first, under
"From documents:"), then the newest entries, in 8000 characters
(`SOCIALHQ_KNOWLEDGE_PROMPT_CHARACTERS_FOCUSED`). Without one, nothing
changes and documents stay out. Search is `KnowledgeSearch`, today BM25 over
words in PHP (`LexicalKnowledgeSearch`, Unicode words so Nepali works, entry
titles count triple), scoring word counts stored with each entry and excerpt
(`terms`, written with the text; older rows are filled the first time
search reads them). "Check what the AI reads" on the page shows what a topic
gets. Embeddings (the `embed` task, pgvector) would add meaning ("cost" finding
"price", English finding Nepali): a vector per entry and excerpt, blended with
the word scores, bound in place of the lexical class.

## Planning (Plan in the sidebar)

The month's content, from idea to post. One workspace is one brand. Code:
`app/SocialHq/{Events,Plan,Production}/`, pages in
`resources/js/pages/socialhq/plan/`, routes in `routes/socialhq/*.php`
(`socialhq.plan.*`), copy in `lang/en/socialhq_{plan,events,production}.php`.
Who may do what is `App\SocialHq\Plan\PlanAccess`: anyone who may create posts
works on plans, copy and briefs; approving (a plan, copy, a creative, an event
date) follows TryPost's `approvePosts`, so it matches who approves posts.

- **Events** (`/socialhq/plan/events`). Dated festivals, holidays and days,
  shared by the account's brands or for one brand only. "Import official dates"
  loads `database/data/socialhq/events/` (the BS 2083 holiday notice and
  international days, a source per row; `php artisan socialhq:events:import`
  does the same). Every imported date starts as "Needs check" until an approver
  confirms it ("Confirm all shown" confirms, in one query, the unconfirmed dates
  of the list's period, country and filter that the approver may change, except
  dates no longer listed); a
  date added by hand (only approvers add dates) starts confirmed. Changing a
  date or name clears the confirmation. Re-importing
  never brings back a date the team edited, moved or deleted. A date its source
  no longer lists (corrected or dropped) loses its confirmation, shows "No
  longer listed" and stays out of planning until the source lists it again or
  someone edits it. The AI never
  calculates a date and plans only around confirmed ones. The publish calendar
  shows a marker on days with events (PATCHES rows 60–63).
  Each date has a country (`country_code`, ISO 3166-1 alpha-2): `NP` for
  Nepal's, a country's code for its public holidays, null for international
  days. "Add a country" (who may change shared dates) imports countries'
  public holidays for this year and the next from Nager.Date
  (`ImportPublicHolidays`, `--country=US` on the command; host in
  `socialhq.events.nager`, answers cached per country and year), as "Needs
  check" with Nager.Date as the source; re-importing follows the same rules,
  per country. Nepal never comes from Nager.Date. The country filter and
  `EventsForMonth`'s `$countries` keep the chosen countries' dates plus every
  date without a country.
- **Content plan** (`/socialhq/plan/{YYYY-MM}`). Items with a date, channel,
  format (TryPost's content type), what to make, pillar, owner, event, objective
  and angle. "Generate with AI" (`ContentPlanner`, a queued job) drafts the
  month from confirmed events, `BrandKnowledge`, pillars and posts per week
  (brand settings), the last 90 days of posts with metrics and the team's focus.
  Its suggestions are checked in code (date in the month and not past, channel,
  format, confirmed event around its dates, paused channels, duplicates) before
  they show, dashed, to accept or drop. "Approve plan" waits until no
  suggestion is open; any change sends an approved plan back to review.
  The dialog's options (`PlannerOptions`, saved on the plan as
  `generation_options`; the switches remembered per brand in the profile's
  `planner_options`): the days to plan, any range inside the plan's month (a
  plan stays one month, so items never leave it; suggestions outside the range
  are kept), old calendars and recent posts on or off (left out of the prompt
  when off), and events only for the audience's countries (`country_code` in
  them, or null for international days).
- **Audience** (Brand knowledge, "Where your audience is"; admins edit,
  members read). Up to 10 places on the brand profile (`audience_locations`:
  country, city or none, IANA zone prefilled from the country's main zone,
  share %, at most 100 in all). The main zone is the largest share's, else the
  zone of whoever plans. The planner gets the places and the main zone, and
  each item a `suggested_time` ("HH:MM", checked in code; an unreadable one is
  dropped, not the item) with `suggested_timezone` set by the code. Cards, the
  list and the side sheet show it in that zone and the viewer's ("9:00 in
  Sydney · 4:45 in Kathmandu"); "Create post" hands the composer that instant
  as a wall clock in the user's zone (`SuggestedTime`), else 09:00 of the day.
- **List view**: grouped by week (the user's start of week) with sticky week
  headers, sortable by day, channel, pillar, owner and status (kept per
  browser), inline accept, drop and restore, time and event columns; stacked
  cards below `md`.
- **Item** (`/socialhq/plan/items/{id}`). Copy (caption, hashtags, script for
  video, notes) saved as numbered versions, never edited in place; AI writes or
  rewrites a version (`CopyWriter`). Review thread: send for review, request
  changes (a note is required), approve; approved copy is locked until someone
  starts a new version. The brief (graphic or video) is prefilled from the
  format; `BriefWriter` drafts empty fields. Creative files are uploaded in
  rounds, reviewed like copy, and kept on the private `local` disk, served only
  to members. "Create post" opens TryPost's composer with the approved copy,
  the final files, the date and the channel; posting follows TryPost's
  approvals. The composer carries the item (`planItemId`), and saving links
  the posts to it (`Plan\ItemPosts`, table `shq_plan_item_posts`, PATCHES.md
  rows 260–263); a composer closed without saving changes nothing. The item
  lists its posts with where each stands now (draft, pending approval,
  scheduled, published, failed) and a link to each; the month's cards show
  the item's post status (a failed post first, else the one furthest along);
  a post card in Publish says "From plan: <title>" and links back. A deleted
  post drops off the item. `post_created_at` is when the first post was
  saved. The plan keeps the posts in step (`ItemPosts`, through TryPost's
  own actions, so rights and approvals still apply): dropping or deleting
  an item whose post will still go out (scheduled, or waiting for
  approval) asks first, "Move it to drafts" (Publish's "Move to drafts")
  or "Keep it scheduled" (user decision 2026-11-23); the server refuses
  the change without a choice (`posts`). An item moved to another day
  offers "Move post too" (a toast after the sheet saves, and a button on
  the item page by a post marked "Not on the item's day"): dated drafts
  and scheduled posts go to the item's day at the same time of day
  (`ReschedulePost`), read in the item's posting zone, else the
  channel's; one that can't move (about to go out, or the person may not
  change it) keeps its time and is named. Until the first post is saved, the card lists everything still in the way at once
  (`CreatePostFromItem::blockers()`: copy not approved, `[gaps]` in it, the
  creative not approved). An approver can "Post text-only": the post stops
  waiting for the creative (`creative_waived_at`/`_by` on the item; the brief
  stays, and an approved creative is still added if it's ready first). Only
  on a format TryPost can post without media (`ContentType::requiresMedia()`
  false: a LinkedIn, X or Facebook post, not an Instagram feed post or a
  reel); on the others the card says the format needs an image or video,
  and a waiver given before this rule no longer counts.
- **Copy checks** (QA 2026-11-18). A caption or hashtags with text in
  square brackets (`Placeholders`: "[city]", a markdown link too, since
  networks post it as typed; not a video script's "[close-up]") can't be
  approved (the button is disabled, the server refuses) or become a post.
  AI copy (the writer and the MCP save tool) and AI-planned items (objective
  and angle) carry `unsupported_details`: names, numbers and quotes their
  sources don't contain (`Plan\Ai\UnsupportedDetails`, a word-level
  heuristic: numbering such as "Slide 3" doesn't count, and a name starting a
  sentence is missed), shown as "Check these". A person's edit keeps the
  flags its text still says (`stillIn()`), on a copy version unless the
  version before was approved, and on an item's objective and angle. A
  rewrite counts the latest version as a source only when a person wrote it.
- **Waiting for you** (`/socialhq/inbox`, sidebar with a count from
  `socialhq.inbox`; `Inbox\WaitingForYou`). Approvers: monthly plans, copy and
  creatives in review, and posts awaiting approval. Anyone working on the plan:
  copy sent back with changes on an item they own or whose latest version they
  wrote, and creatives sent back that are assigned to them (with nobody
  assigned, to the item's owner). The plan's month picker marks the months
  with any of these (`waitingMonths`, also on the year arrows). The count
  refreshes on full visits and after approving or rejecting a post.
- **Publish now asks first** (`components/socialhq/publishNow.ts`, the dialog
  in the app layout, rendered only while asking so it stacks above the
  composer; the browser's own confirm on a page without it;
  `socialhq.publish_now.confirm`, off only in phpunit.xml). The Queue card's
  "Publish Now" button moved into its ⋯ menu, and the approve button reads
  "Approve and schedule". A text-only waiver ends when the item's creative,
  channel or format changes.
- **Production** (`/socialhq/plan/production`). Every brief by stage, with drag
  and drop or a keyboard "Move to" menu. Only approvers move a card into or out
  of Changes requested and Approved.
- **AI output** must list every key as required (strict providers such as Groq
  refuse a schema with optional keys, and models drop them); `""` means none.

## AI graphics

"Generate with AI" in the media menu of ideas and posts makes a still graphic
(no video) from a prompt and up to 3 reference images, through the `image`
task. The finished graphic arrives through TryPost's media import, like an
Unsplash pick. On a plan item's Creative, the same button sits next to
"Upload files" and adds the graphic to the current round as an upload, with
the reviewer's note or, without one, "Made with AI from: …" and the prompt.
Code: `app/SocialHq/AiGraphics/`.

The button shows whenever the company has some AI and the person may create
posts (`AiGraphics::isOffered()`). When that AI can't make images, or can't
read them for the check below, it stays dimmed and says which and who adds it
(`AiGraphics::missing()`, the `socialhq.aiGraphics` prop's `missing`).

The vision gate, which fails closed:

1. Reference images must be real JPEG, PNG or WebP bitmaps, checked by
   content (SVG is refused), 64 to 8192 px, up to 10 MB, at most 3.
2. A vision model (the `vision` task) reviews the prompt and the
   reference images before anything is generated, and the generated
   graphic before it is stored. It blocks misleading use of real people,
   other brands' logos, fake documents, sexual or violent content and the
   like, and says why. If the check can't run, nothing is generated.
3. Limits: 20 per person per hour, 100 per workspace per day (env).
4. Only people who can create posts in the workspace can use it, and posts
   with AI graphics still go through approvals.
5. Every attempt is logged in `shq_ai_graphics`: who, prompt, provider,
   status (generated, blocked, failed) and reason.

## Notifications (the bell)

Every notification TryPost emails goes through the `SendNotification` job,
which hands it to `App\SocialHq\Notices\Notices::fromNotification()` first
(PATCHES.md row 228). The same event becomes a notice in the bell, emailed or
not: published and failed posts, a channel that needs reconnecting (once a
day per channel), posts at risk, notes, approval requests and decisions.
Invites, access requests and company decisions stay email only. Code:
`app/SocialHq/Notices/`, components in `resources/js/components/socialhq/notices/`.

- **Storage:** `shq_notices`, one row per person and event, with the post or
  channel it is about and the text's parts (`data`). The bell lists the last 7
  days of the current workspace (50 at most); rows go after 30 days
  (`model:prune`, daily). Titles and buttons are built when read
  (`NoticePresenter`), in the reader's language; a deleted post or channel
  simply has no button.
- **Live:** `NoticeCreated` is broadcast on the person's private channel
  (`shq.user.{id}`) with the notice's id only. `NoticeLive.vue`, mounted in the
  app layout, raises the count and shows the notice in the corner of a tab the
  person is looking at, which marks it read.
- **Preferences** (Settings › Notifications): TryPost's `notification_preferences`
  keeps the email switches; `shq_notice_preferences` keeps the "In the app"
  switch of each row (only what was turned off) and the live notices switch.
- Plan reviews (copy and creatives sent back or approved) aren't in the bell
  yet: "Waiting for you" lists them.

## The channel page

`/channels/{account}/settings` (admins) is one page per channel, with the workspace's
channels listed beside it (`pages/socialhq/channels/Show.vue`, components in
`components/socialhq/channels/`). TryPost's settings page is no longer rendered
(PATCHES.md row 230); its posting schedule endpoints and weekday grid are reused.

- **Where it stands:** four tiles (Scheduled in the next 30 days, Needs approval,
  Drafts, Published in the last 30 days), each a link to that tab of the channel's
  publish page, and the week's goal as a bar. Counted by
  `App\SocialHq\Channels\ChannelPage::props()`.
- **Settings as rows, one button each:** time zone (TryPost's confirmation first),
  posting goal, posting times, name, channel ID.
- **Posting times:** when every active day shares the same times, "Edit" opens a
  simple list (add a time, remove a time, up to TryPost's four a day) and saving
  writes that list to every active day. "Different times per day" opens TryPost's
  weekday grid on the page; a channel whose days already differ opens it directly.
- **Name in Social HQ:** `social_accounts.shq_name` (nullable, 60 characters), set by
  `PUT socialhq/channels/{account}/name` (admins). `SocialAccount::accountDisplayName()`
  and so `display_label` return it first; the network's own name stays in
  `display_name`. Print a channel's name through `display_label` (or `channelName()`
  on the client), never `display_name || username`. A post keeps the name it had when
  it was created (`channelSnapshot()`).
- **Connection:** refresh, link a Facebook Page (Instagram Login accounts) and
  disconnect through TryPost's dialog. A disconnect started here goes to the next
  channel's page, or to Settings › Channels when none is left.
- **What a disconnect deletes** (PATCHES.md row 231, on the channel list too): when
  the dialog opens it reads `socialhq.channels.disconnect-impact.show`
  (`App\SocialHq\Channels\DisconnectImpact`, admins only) and says what goes from
  Social HQ: posts not sent yet and the record of sent ones (imported too), media
  files, the channel's Insights history with the number of posts it has numbers
  for, and that nothing is deleted on the network. "See what you'll lose" adds the
  counts by status, the next 3 scheduled posts, what else stops (the queue, the goal,
  planned items that lose their channel, repurposes that read from it), what stays
  and a link to the channel's posts. If the counts can't be read, the dialog is as
  before.
- **Insights history goes with the channel** (owner decision 2026-11-23,
  PATCHES.md row 280). TryPost keeps a disconnected channel's analytics
  (publications with their daily numbers, follower snapshots, sync states) so a
  reconnect picks them up again; Insights, its export and the planner's recent
  posts then kept counting a channel the workspace no longer has, and a Meta data
  deletion request left the Page's posts and numbers behind. `DisconnectSocialAccount`
  now deletes them in the same transaction through `App\SocialHq\Channels\ChannelInsights`:
  every row of the channel's identity (workspace, network, account id on the
  network) and any row still linked to it, unless another channel of the workspace
  is the same identity. A reconnect reads the posts and their numbers from the
  network again; the old daily follower counts don't come back.
  `php artisan socialhq:insights:prune` (`--dry-run` first) deletes the history of
  identities no channel is connected to, left by disconnects before this change.

### Ask the account owner to connect

For a network account whose owner has no Social HQ login. An admin presses "Copy link"
on the channel page and sends the link; the owner opens it, signs in on the network,
picks the account, and the channel lands in the workspace.

- **The link** (`App\SocialHq\Channels\ConnectInvite`, table `shq_connect_invites`):
  one workspace, one network, one hour, one use. The token is 48 random characters;
  only its SHA-256 is stored, and it is returned once, when the link is made. A link
  made for a channel that lost its connection reconnects that channel only. Networks
  that sign in by redirect only (not Bluesky, Mastodon or Telegram, which ask for a
  password or a bot inside the app). Rows are the record of who asked and what was
  connected, deleted 90 days after the link stopped working.
- **The guest's pages** (`ConnectInviteController`, `pages/socialhq/ConnectInvite.vue`):
  the link shows who asks and for what, and starts nothing until "Continue" (a POST).
  After the network, the guest picks accounts on our own page and ends on "Connected".
  The page sends `Referrer-Policy: no-referrer`, since the token is in its address.
- **How the sign-in runs** (`ActAsConnectInviter`, in the `web` group, sorted before
  `auth`): TryPost's start route, OAuth callback and "Finish connection" need a
  signed-in admin. For a guest whose session holds a link that still works, those
  three requests, for the link's network only, run with the admin who sent the link
  as the request's user. Nothing is written to the session, so the guest is never
  signed in. TryPost checks that admin's rights on each step, so a link stops working
  when its sender stops being an admin, and a paused company's links do too.
- **What the guest can never get:** a page rendered for that admin. Whatever TryPost
  answers on those three requests is replaced by a redirect to the guest's page, and
  TryPost's confirmation page is never reached; what TryPost flashed for the admin's
  next page is dropped. The guest's page shows the offered accounts' names and
  pictures, never their tokens, and names connected channels as the network does,
  never by their name in Social HQ.
- **What the guest can never widen:** the start route sees only the link's own
  options (a reconnect link stays a reconnect; `return_to`, `switch` and `?offer=`
  are dropped). "Finish connection" runs only on the connection that start stored,
  once the network offered its accounts, and one request at a time per link (a cache
  lock), so a link connects once. The channels it records are the accounts the guest
  picked, matched by network and account id. A guest's waiting offer is put aside
  while TryPost runs as the admin, so it is never claimed for the admin's account.
- **Someone signed in** who opens a link is told it is not for them; a flow left in
  their session is dropped.
- **Afterwards:** the link is marked used with the channels it connected and the
  guest's IP, and the sender gets a "…is connected" notice in the bell.
- Limits: 30 requests a minute per IP on the guest pages, 30 steps run as the admin a
  minute per IP, 10 links a minute per admin. Telescope, when on, ignores
  `socialhq/connect-invite*`, since the token is in the address.
- Not done: a link cannot be cancelled before its hour ends, and it is not tied to
  the first browser that opens it (two people holding it can each start; only one
  finishes).
- Every rule above has a test in `tests/Feature/SocialHq/Channels/ConnectInviteTest.php`.
  Changing the middleware, the routes it covers or the link's lifetime needs a
  security review.

## The composer's footer

Everything about when a post goes out sits in one bar (PATCHES.md rows 232–233):
labels and "Repeat" on the left; the "when" button (a set time shows its weekday
and time zone), "Save draft" and the primary button on the right. The primary
button counts the posts it saves ("Schedule 4 posts", "Request approval for 4
posts").

- **Repeat** (`components/socialhq/composer/ComposerRepeat.vue`) is the post card's
  "Make Recurring" rule, picked before saving. Only people who publish directly see
  it, and only a set time can repeat: in the queue or "Now" the button is off and
  says why, and the rule comes back when a time is set again.
- The web store and update requests take `recurrence`; `App\SocialHq\Composer\ComposerRepeat`
  applies it after the save. Saving a post of a series with its rule unchanged keeps
  its place in the series; "Don't repeat" (`null`) stops it; a save without the key
  changes nothing.

## Approvals

Stricter than TryPost (`app/SocialHq/Approval/ApprovalRules.php`):

| Who | Schedules or publishes | Approves |
|---|---|---|
| Owner and admins, in the app | Directly | Yes |
| Members who are not admins | Always sent for approval (`SOCIALHQ_MEMBERS_ALWAYS_REVIEWED`, default on) | No |
| Anyone through an API key or MCP grant, admins included | Always sent for approval (`SOCIALHQ_AGENT_POSTS_REVIEWED`, default on) | No |

Every request, approval, rejection and withdrawal is recorded in
`shq_approval_events` (who, when, from the app, an agent or the system).
TryPost keeps only the latest approver and nothing about rejections.

## Agents (API keys and MCP)

An API key or MCP grant is an agent (`ApprovalRules::actsAsAgent`), an
admin's too. Besides the approval rules above:

- **Repurposes are read only** (`WorkspacePolicy::viewRepurposes`, PATCHES
  rows 196–197): a repurpose's posts are made later by a queued job acting
  as its creator, so they skip the review every agent post gets. The refusal
  says why.
- **API keys and webhooks are refused** (`App\SocialHq\Agents\AdminSettings`)
  and the MCP server doesn't list those tools (`App\SocialHq\Mcp\HiddenTools`):
  an agent reading untrusted text could be steered into minting a key or
  sending post events to any URL. `SOCIALHQ_AGENTS_MANAGE_ADMIN_SETTINGS=true`
  allows them again; `manageTeam` itself is unchanged.
- **Safe retries**: `create-post-tool` and `create-posts-tool` take an
  `idempotency_key`; the same key and arguments within 24 hours return the
  posts already made (`App\SocialHq\Mcp\IdempotentPostCreation`, cache).
- **Planning tools** (`app/SocialHq/Mcp/Tools/Plan/`): read a month's plan,
  an item (copy, change requests, thread, brief), the month's events and
  the brand knowledge AI writers get; save copy as an item's next version
  (source `ai`; refused while the copy is in review or approved) and send it
  for review. Approving stays in the app, and no tool calls our AI (AI is
  web only).

## Sign-up

Self-hosted TryPost only registers people with an invite. Social HQ also lets
people sign up with a company email (`SOCIALHQ_SIGNUP_DOMAINS`, comma
separated, e.g. `acme.com`) or a listed address (`SOCIALHQ_SIGNUP_EMAILS`).
Public mail domains (gmail.com and the like) are ignored, and with both lists
empty sign-up stays invites only. Code: `app/SocialHq/Signup/`.

1. The login page shows "Sign up". Email, Google and GitHub sign-ups follow
   the same list. An email that may not sign up only ever hears "can't sign
   up here", never whether it has an account. The register form allows 5
   tries a minute and 20 an hour per IP.
2. The person joins the team's account (the account that
   `SOCIALHQ_SIGNUP_ACCOUNT_OWNER` owns, matched without regard to case, or
   else the oldest account that owns a workspace) with no workspace and
   waits on one page until let in. While company requests are on, the
   owner must be set (several companies may own workspaces); where
   companies are required, only an approved company takes sign-ups. If the
   lists are set but no account is found, sign-up stays closed and the log
   says why. Only an invite that still exists counts as an invite.
3. Once their email is confirmed, the account owner and every workspace admin
   get an email (at most once an hour per address, so someone declined who
   signs up again doesn't flood them), "Access requests" with a count
   appears in the workspace menu, and a banner on Settings › Members links
   to it.
4. An admin picks the workspaces they manage and whether the person is an
   admin; a member who is not an admin always sends posts for approval. The
   person gets an email. Only the account owner declines (an admin of one
   workspace sees the list without the button): declining deletes their
   user (and their avatar files) and keeps the request in
   `shq_access_requests`. When two admins
   decide at once, the first wins and the second is told so.
5. While waiting, the person can open and accept an invite: once they have a
   workspace their request closes by itself (`note` `joined_by_invite`).
   Declining an invite leaves them waiting.

**Companies** (`app/SocialHq/Companies/`). A company is an account a
platform admin approved; only its owner creates workspaces
(`SOCIALHQ_COMPANIES_REQUIRED`, default on). Platform admins are granted
with `php artisan socialhq:platform-admin <email>` only; on a new server the
first person asks for a company, is granted, and approves it.

1. Anyone off the lists may ask to start a company
   (`SOCIALHQ_COMPANY_REQUESTS`, default on; off brings back "can't sign up
   here"). The register form adds "Company name"; the server decides on
   submit (`CompanySignup`). That path never logs in and always ends on
   "Check your email": a new email gets its account, a pending company and
   the confirmation link; an email that has an account gets "You already have
   an account" (`AccountExists`, with login and reset links, at most one a
   minute), so the form never says which. Google and GitHub prove the email, so
   they create the pending company at once, named after the person; the
   owner renames it while it waits.
2. Platform admins are emailed once the owner's email is confirmed (once an
   hour per address). The owner waits on one page (resend, rename, log
   out). Declined: the page shows the note, and "Ask again" puts it back to
   pending after 24 hours.
3. `/socialhq/platform/companies` ("Platform: companies" in the user menu,
   404 for others): Requests (approve once the email is confirmed, decline
   with a note) and Companies (pause with a note, restore, "May use Social
   HQ's AI keys"). Decisions lock the row: the first admin wins, the second
   is told. Every request and decision is in `shq_company_events`; the owner
   is emailed when approved or declined.
4. `EnsureCompanyApproved` (web group): a waiting company's owner sees the
   waiting page; everyone in a paused company sees the paused page (who to
   contact: `SOCIALHQ_PLATFORM_CONTACT_EMAIL`, else the admin who paused it,
   and for members the owner), and its API keys and MCP grants get a 403.
   Someone with no workspace who can't create one (an invited person's
   empty account) gets a page that says to ask for an invite or, if their
   account is empty, to ask for a company. An account that owns
   workspaces but has no company row (sign-up never makes one: the
   register and Google/GitHub controllers tell `CreateUser` it is a self
   sign-up, which either joins a team, asks for a company or gets no
   workspace) is not approved: its people get the same page, its owner
   asks for approval there, and its API keys get a 403. An invite link
   kept in the session counts as the invite only for its own email.
   Platform admins follow their own
   company's rules but always reach the platform pages.
5. A paused company publishes nothing: its due posts and limit retries wait
   (`posts:process-scheduled`), and its repurposes neither read their
   source nor make posts. Restoring sends its posts that came due while it
   was paused (still scheduled, time passed) back to drafts, content and
   channels kept and schedule cleared, so a dated post never goes out late
   and nothing publishes at once; the flash tells the platform admin how
   many. Posts scheduled after the restore publish as planned.

Google or GitHub sign-in to an account whose email isn't confirmed yet
(someone registered the address with a password and never confirmed it)
takes the account over for the address's owner: the password is replaced,
remember-me and other sessions end, and the email counts as confirmed
(`OAuthSignIn`). An address Google doesn't say it verified (`email_verified`
false or missing) signs in to nothing and creates nothing.

## Queue workers

TryPost's seven Horizon supervisors keep a worker per queue even when idle.
`App\SocialHq\Queue\CompactHorizon` (`SOCIALHQ_HORIZON_COMPACT`, default on,
off in phpunit.xml) folds them into three single pools, `core`,
`social-publishing` (name kept for `horizon:pause-supervisor`) and `media`:
one worker each when idle, one more per waiting job up to 4, 8 and 4
(`socialhq.horizon.max_processes`). `config/horizon.php` is untouched.
Measured on 2026-11-18 with a burst of 81 jobs, including 35 publishes:
idle memory went from 1,408 MB to 578 MB and peak memory from 2,227 MB to
1,395 MB. Publishing waits and the time to clear the burst stayed the same.
Horizon refuses `minProcesses` 0. One worker per pool also keeps moving
delayed and retried jobs onto its queues.

## Interface copy

Our screens use the `socialhq.*` keys in `lang/en/socialhq.php`, in English
only. Email copy follows AGENTS.md instead: one `mail.<slug>` block per
template in every `lang/*/mail.php` (the sign-up emails are
`socialhq_access_requested` and `socialhq_access_approved`, and the company
emails `socialhq_company_requested`, `_approved` and `_declined`, English in
every locale for now). Upstream's parity test wants every locale to have the same keys, so
after changing that file run `bin/socialhq-sync-lang`, which copies it to the
other 15 locales.

## Branding

The app, its emails and its MCP server are called Social HQ, with our own mark:
an S and a rising arrow in a hexagon, navy to teal. Its shape and colours live
in `resources/js/components/socialhq/logo.ts` (used by `AppLogo.vue` and the
share card); `public/images/socialhq/logo.svg` and `public/favicon.svg` are the
same mark as files, and the PNGs (favicon, touch icon, email header, MCP icon)
are rendered from it. `APP_NAME`
(runtime) and `VITE_APP_NAME` (image build) default to "Social HQ". TryPost's
name stays where it is credit or code: the license, the source link, class names,
`trypost.*` config keys, the `/mcp/trypost` URL and the "Setup guides" links,
which open TryPost's documentation. PATCHES.md rows 28 to 40 list the files.

Login and register show the terms and privacy links only when
`LEGAL_TERMS_URL` and `LEGAL_PRIVACY_URL` are set. TryPost's "What's new"
changelog is off (`CHANGELOG_ENABLED=false`): it announces TryPost's releases,
which we don't take.

## License

AGPL-3.0, as upstream. The app links to this repository in the sidebar
(`SOCIALHQ_SOURCE_URL`, `VITE_SOCIALHQ_SOURCE_URL`). Keep TryPost's license
and copyright notices intact. Don't present the app as "TryPost": the AGPL
doesn't license the name.

## Deployment

[yoddha-social/socialhq-deploy](https://github.com/yoddha-social/socialhq-deploy) (private).
