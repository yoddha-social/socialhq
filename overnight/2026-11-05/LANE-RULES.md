# Rules for every overnight lane (builders and fixers)

You work in your own git worktree of `yoddha-social/socialhq` (Laravel 13, Inertia v3,
Vue 3 + TS, Tailwind 4, Pest). The overseer session merges your branch; you never push.

## Before you write code
- Read `CLAUDE.md`, `SOCIALHQ.md`, `PATCHES.md`, `.ai/rules/index.md` and the rule files
  whose globs match the files you touch. Follow them: they win over habit.
- Skills worth loading: `in-app-copy` (every string), `testing-best-practices`,
  `inertia-vue-development`, `laravel-best-practices`, `ai-sdk-development` (AI work).
- Small chunks: split your task into steps, finish and test each before the next, and
  check each step against its acceptance criteria.

## Machine limits (7.6 GB RAM, shared with other lanes) — important
- No PHP on the host. Run PHP through the helper, one command at a time:
  `SHQ=/tmp/claude-1000/-home--sandeep--Org-projects-social-hq/88bc9a7f-e4cc-47a5-8689-d5d2fa85b3b3/scratchpad/shq.sh`
  `DB_DATABASE=<your db> $SHQ "$PWD" php -d memory_limit=-1 vendor/bin/pest --compact <paths>`
  It mounts the main checkout's `vendor/` and `node_modules/` into your worktree. Your
  test database is given in your brief; create it once with
  `docker exec shq-test-pg psql -U postgres -c "create database <name>"`.
- Copy `.env` and `storage/oauth-*.key` from the main checkout
  (`/home/_sandeep_/Org projects/social-hq/socialhq`) into your worktree first.
- Run only the tests you touched or that cover the files you changed. Never run the
  whole suite (the overseer does). No Browser tests.
- At most 2 PHP containers may run on the machine at once (all lanes together). Before
  each PHP command, wait for a free slot:
  `until [ "$(docker ps -q --filter ancestor=ghcr.io/trypostit/trypost:2.0.1 | wc -l)" -lt 3 ]; do sleep 15; done`
  (the long-running `trypost-trial-app` container counts as one).
- Pint: `$SHQ "$PWD" vendor/bin/pint --dirty --format agent`.
- Wayfinder after route changes: `$SHQ "$PWD" php artisan wayfinder:generate --with-form`
  (check `vite.config.ts` for the flags it uses).
- Frontend checks on the files you changed: `npx prettier --write <files>`,
  `npx eslint <files>`. Type check once at the end: `npx vue-tsc --noEmit` (heavy; once).
  Never run `npm run build` or `vite` (the overseer builds).

## Conventions that are easy to miss
- Copy: English only in `lang/en/socialhq.php`, then `bin/socialhq-sync-lang`. Follow the
  `in-app-copy` skill (what is missing, why, the button). No HTML in lang strings.
- Every edit to a file that came with TryPost gets a row in `PATCHES.md`. Use only the row
  numbers given in your brief, so parallel lanes don't collide.
- New tables `shq_*` in `database/migrations/socialhq/`, with migration names using the
  prefix given in your brief. Work on PostgreSQL and MySQL. Never hard-code dates in
  tests (derive from `now()`).
- Vue: arrow functions, `@/` imports only, named handlers in templates, `Button :loading`,
  Cancel before the primary action, Tabler icons, `@/dayjs` and `@/date`, no HTML5
  validation attributes, `data-testid` on what tests would click.
- FormRequests for validation, one controller per resource, policies for abilities.
- Commits: plain messages in the repo's style (imperative, one subject line plus a short
  body). **No Co-Authored-By or AI attribution lines.**

## When you finish
Commit on your branch (several small commits are fine). Reply with: branch name, commit
list, what you changed per finding (one line each), tests run with their result, anything
you could not do and why, and PATCHES rows used. Keep the reply under 500 words.
