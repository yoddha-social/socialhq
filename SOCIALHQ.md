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
| Tests (Pest) | `tests/Feature/SocialHq/` |
| Image build | `.github/workflows/socialhq-image.yml` (tags `shq-v*`) |

Edits to upstream files are kept to a minimum and listed in
[PATCHES.md](PATCHES.md), which also explains how to merge an upstream release.
Follow the upstream conventions in [AGENTS.md](AGENTS.md) (Laravel Boost,
Pest, Pint, Inertia v3, Wayfinder).

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
