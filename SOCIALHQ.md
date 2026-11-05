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
