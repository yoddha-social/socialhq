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
- AI through a LiteLLM gateway, failing closed for confidential data.

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

Edits to upstream files are kept to a minimum and listed in
[PATCHES.md](PATCHES.md), which also explains how to merge an upstream release.
Follow the upstream conventions in [AGENTS.md](AGENTS.md) (Laravel Boost,
Pest, Pint, Inertia v3, Wayfinder).

## AI graphics

"Generate with AI" in the media menu of ideas and posts makes a still graphic
(no video) from a prompt and up to 3 reference images, with an image model
(`SOCIALHQ_AI_GRAPHICS_PROVIDER`, default `gemini`; needs `GEMINI_API_KEY`).
The finished graphic arrives through TryPost's media import, like an
Unsplash pick. Code: `app/SocialHq/AiGraphics/`.

The vision gate, which fails closed:

1. Reference images must be real JPEG, PNG or WebP bitmaps, checked by
   content (SVG is refused), 64 to 8192 px, up to 10 MB, at most 3.
2. A vision model (the default text provider, or
   `SOCIALHQ_AI_GRAPHICS_VISION_PROVIDER`) reviews the prompt and the
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
