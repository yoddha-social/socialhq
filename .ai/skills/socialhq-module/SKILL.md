---
name: socialhq-module
description: Adds or changes a Social HQ feature - where the code goes, what TryPost already provides to build on, and how to change a file that came with TryPost. Use when building anything under app/SocialHq, routes/socialhq.php, config/socialhq.php, database/migrations/socialhq, resources/js/pages/socialhq or resources/js/components/socialhq, or when changing a file that came with TryPost.
---

# Building a Social HQ feature

New features live in the module. Social HQ is a hard fork (2026-11-25): files that came
with TryPost are ours and may be changed whenever that is the right place. Such changes
get no PATCHES.md row (the ledger was frozen on 2026-11-25); the reason goes in the commit
message.

## Where things go

| What | Path | Notes |
|---|---|---|
| PHP | `app/SocialHq/<Feature>/` | Namespace `App\SocialHq\<Feature>`. Actions, models, jobs, AI agents together per feature |
| Controllers, requests | `app/SocialHq/Http/` | |
| Wiring | `app/SocialHq/SocialHqServiceProvider.php` | Routes, migrations, schedule, rate limiters, listeners, `Inertia::share` |
| Routes | `routes/socialhq.php` | Under `/socialhq/*`, named `socialhq.*` |
| Migrations | `database/migrations/socialhq/` | Tables `shq_*` |
| Config | `config/socialhq.php` | Every limit env-overridable, with a comment saying why the default is what it is |
| Vue | `resources/js/pages/socialhq/`, `resources/js/components/socialhq/` | Reuse the shared UI components in `resources/js/components/ui/` |
| Copy | `lang/en/socialhq.php` | `socialhq.*` keys, English only; the hook syncs other locales |
| AI prompts | `resources/views/prompts/` (a `socialhq/` folder) | Blade, never heredocs in PHP |
| Tests | `tests/Feature/SocialHq/`, `tests/Browser/SocialHq/` (and `Unit/SocialHq/` when needed) | Pest |

## Build on what TryPost provides

Before adding a model or screen, check whether TryPost already has it: workspaces and
members (`is_admin`, `requires_approval`; there are no roles), post approvals
(`App\Support\PostApproval` is the only approval rule), ideas and idea stages (`Idea`,
`IdeaStage`), labels, media, the calendar and composer, channels, analytics snapshots,
repurposing. Extend these instead of building a second version. AGENTS.md has a section
per area: read it before touching one.

## Changing a TryPost base file

Do it when the change belongs there: a fix, a behaviour we want different, a place to
render our component. Keep the change focused and say why in the commit message; when it
is a rule people must know, add it to SOCIALHQ.md. `git log v2.0.1..main -- <path>` shows
what we already changed in a file. A small extension point (an event, a slot, a config
value) is often cleaner than scattering edits, but it isn't required. "PATCHES.md row N"
in old comments means the frozen ledger, `docs/history/PATCHES-ledger-2026-11-25.md`.

## Checklist

- [ ] Workspace scoping on every query (`workspace_id`), and a policy check on every action
- [ ] Each change to a TryPost base file says why in its commit message (no PATCHES.md row)
- [ ] Copy in `lang/en/socialhq.php`; `bin/socialhq-sync-lang` ran (the hook does it)
- [ ] Pest tests in the SocialHq folders, run where PHP is available (`socialhq-dev-env`)
- [ ] SOCIALHQ.md updated when the feature is user-visible
