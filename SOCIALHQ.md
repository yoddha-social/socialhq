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
| Routes | `routes/socialhq.php` (under `/socialhq/*`) |
| Configuration | `config/socialhq.php` |
| Inertia pages and components | `resources/js/pages/socialhq/`, `resources/js/components/socialhq/` |
| Tests (Pest) | `tests/Feature/SocialHq/`, `tests/Unit/SocialHq/`, `tests/Browser/SocialHq/` |
| Interface copy | `lang/en/socialhq.php` (see below) |
| Image build | `.github/workflows/socialhq-image.yml` (tags `shq-v*`) |
| Dependency audit | `.github/workflows/socialhq-audit.yml` (PRs and weekly) |
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
  trains on prompts (the Gemini free tier) is never called. Mistral is in the
  LiteLLM chain only with "Anonymous improvement data" off in its console.
- **Gateway tasks** (`SOCIALHQ_AI_GATEWAY_TASKS`, default
  `write,structured,vision`): the gateway serves only these. `image` and
  `embed` have only paid Gemini in LiteLLM, so they stay off (and "Generate
  with AI" stays hidden) until its key is set and they are listed.
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
- **Settings › AI** (owner of an approved company): add a key from the
  catalogue (`socialhq.company_ai.providers`: Groq, Cloudflare Workers AI,
  Mistral with its training opt-out ticked, paid Gemini with billing
  ticked, OpenAI, Anthropic), tested through LiteLLM before anything is
  saved; Test, Replace, Remove, order (LiteLLM's deployment `order`),
  monthly budget (the team's `max_budget`) and spend. Provider keys live
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
- Per-call records are LiteLLM's spend logs (its admin UI on port 4000), not
  a table in the app: AGENTS.md keeps TryPost free of AI usage recording.

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
`config/socialhq.php`). The upload waits on the private `local` disk while
the admin matches columns (guessed from English and Nepali headers; only
the date is required) and the date order; importing stores the rows in
`shq_past_posts` and deletes the file. Rows without a readable AD date, in
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
it; unchanged text (same hash) keeps its excerpts. The title follows the
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
titles count triple). "Check what the AI reads" on the page shows what a topic
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
  confirms it; changing a date or name clears the confirmation. Re-importing
  never brings back a date the team edited, moved or deleted. The AI never
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
  approvals.
- **Production** (`/socialhq/plan/production`). Every brief by stage, with drag
  and drop or a keyboard "Move to" menu. Only approvers move a card into or out
  of Changes requested and Approved.
- **AI output** must list every key as required (strict providers such as Groq
  refuse a schema with optional keys, and models drop them); `""` means none.

## AI graphics

"Generate with AI" in the media menu of ideas and posts makes a still graphic
(no video) from a prompt and up to 3 reference images, through the `image`
task. The finished graphic arrives through TryPost's media import, like an
Unsplash pick. Code: `app/SocialHq/AiGraphics/`.

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
2. The person joins the team's account (`SOCIALHQ_SIGNUP_ACCOUNT_OWNER`,
   matched without regard to case, or else the oldest account that owns a
   workspace) with no workspace and waits on one page until let in. If the
   lists are set but no account is found, sign-up stays closed and the log
   says why. Only an invite that still exists counts as an invite.
3. Once their email is confirmed, the account owner and every workspace admin
   get an email (at most once an hour per address, so someone declined who
   signs up again doesn't flood them), "Access requests" with a count
   appears in the workspace menu, and a banner on Settings › Members links
   to it.
4. An admin picks the workspaces they manage and whether the person is an
   admin; a member who is not an admin always sends posts for approval. The
   person gets an email. Declining deletes their user (and their avatar
   files) and keeps the request in `shq_access_requests`. When two admins
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
   the confirmation link; an email that has an account gets a password reset
   link, so the form never says which. Google and GitHub prove the email, so
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
   account is empty, to ask for a company. Platform admins follow their own
   company's rules but always reach the platform pages. Scheduled posts of
   a paused company still publish.

Google or GitHub sign-in to an account whose email isn't confirmed yet
(someone registered the address with a password and never confirmed it)
takes the account over for the address's owner: the password is replaced,
remember-me and other sessions end, and the email counts as confirmed
(`OAuthSignIn`). An address Google says it hasn't verified signs in to
nothing and creates nothing.

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
