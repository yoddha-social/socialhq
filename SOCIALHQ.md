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
  LiteLLM tries Groq and Cloudflare Workers AI first and falls back
  to paid Gemini; the key's budget ($25 every 30 days) caps paid spending.
  The groups live in `socialhq-deploy/litellm/config.yaml`.
- **Direct** (the default; tests and development): the SDK calls providers
  from `config/ai.php` with their own keys. `SOCIALHQ_AI_ROUTE_<TASK>` sets a
  route ("provider:model,provider", tried in order on rate limits and
  overload); without one, a task uses the SDK's default provider (images:
  Gemini).
- **Approved providers only** (`SOCIALHQ_AI_APPROVED_PROVIDERS`, default the
  two LiteLLM entries). Any other target is dropped, so a key for a provider
  that trains on prompts (the Gemini or Mistral free tier) is never called.
- `php artisan socialhq:ai-routes` shows each task's targets and why one is
  unused; `--live` sends a small request through each (`--images` adds one
  image).
- Per-call records are LiteLLM's spend logs (its admin UI on port 4000), not
  a table in the app: AGENTS.md keeps TryPost free of AI usage recording.

## Brand knowledge

Each workspace (one brand) has a brand profile (what the brand is, audience,
voice and tone, words to use and to avoid) and knowledge entries (products,
projects, past campaigns, facts), under Settings › Content › Brand knowledge.
Members who can post read it; workspace admins change it. Code:
`app/SocialHq/Knowledge/`.

The caption assistant and the idea generator get it in their prompts
(`prompts/socialhq/brand_knowledge.blade.php`, filled by a view composer for
the user's current workspace): the profile, then the newest entries marked
"Use in AI writing" until `SOCIALHQ_KNOWLEDGE_PROMPT_CHARACTERS` (6000) is
spent. The prompt tells the model it is reference data, not instructions.
Up to `SOCIALHQ_KNOWLEDGE_MAX_ENTRIES` (300) entries per workspace.

Not yet: picking the entries that match the request (embeddings through the
`embed` task), and feeding it to calendar drafts and scripts.

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

## Interface copy

Our screens use the `socialhq.*` keys in `lang/en/socialhq.php`, in English
only. Upstream's parity test wants every locale to have the same keys, so
after changing that file run `bin/socialhq-sync-lang`, which copies it to the
other 15 locales.

## Branding

Set by configuration, not code: `APP_NAME="Social HQ"` at runtime, and
`VITE_APP_NAME` at image build time (set by our image workflow).

## License

AGPL-3.0, as upstream. The app links to this repository in the sidebar
(`SOCIALHQ_SOURCE_URL`, `VITE_SOCIALHQ_SOURCE_URL`). Keep TryPost's license
and copyright notices intact. Don't present the app as "TryPost": the AGPL
doesn't license the name.

## Deployment

[yoddha-social/socialhq-deploy](https://github.com/yoddha-social/socialhq-deploy) (private).
