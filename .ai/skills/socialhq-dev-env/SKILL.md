---
name: socialhq-dev-env
description: Runs Social HQ's PHP tools, tests, linters and the app where PHP is available (TryPost's PHP image in Docker, or the compose app container; the laptop has no PHP). Use before running pint, Pest, artisan, browser tests, vue-tsc or the dev server, or when a hook reports "no PHP runner".
---

# Running things here

PHP runner right now: !`command -v php >/dev/null && echo "php on PATH" || (docker compose ps --status running --services 2>/dev/null | grep -qx app && echo "compose app container" || echo "none") || true`

## Where PHP runs

| Place | How | Notes |
|---|---|---|
| Laptop, one-off container | `docker run --rm` of `ghcr.io/trypostit/trypost:2.0.1` with the checkout (or worktree) at `/app`, the main checkout's `vendor` and `node_modules` mounted, against a throwaway Postgres (`shq-test-pg`, a database per branch) | The usual way. 8 GB RAM: one heavy run at a time, check `free -m` first. Pest locally needs `--no-tia` |
| Laptop, compose | `docker compose up -d`, then `docker compose exec -T app <command>` | Stop other stacks first (`trypost/local`, HRMS). Building the image and Vite dev mode may not fit |
| Rented instance, while it is a dev box | Commands as written | Only before it becomes production (plan D3). Once it is production: no builds, tests or agent sessions there |
| No PHP at all | Node tools only | Say plainly that pint and Pest did not run |

Node tools (prettier, eslint, vue-tsc, `npm run build`) run on the laptop directly.

## Commands

```bash
./vendor/bin/pest tests/Feature/SocialHq --compact                         # our tests
./vendor/bin/pest --compact --parallel --tia                              # tests affected by the change
./vendor/bin/pint --dirty                                                 # format changed PHP
npx --no-install vue-tsc --noEmit                                         # types
composer test:lint && npx --no-install eslint .                           # Pint and ESLint as CI's quality job runs them
                                                                          # (it also runs vue-tsc, above, and Prettier on changed files)
```

### The whole suite on a fresh checkout

A new checkout or worktree has no Passport keys, and Pest's test impact analysis
(`->tia()->locally()` in `tests/Pest.php`) asks git which branch it is on. Both make a
full run fail in confusing ways, so:

```bash
php artisan passport:keys                  # once per checkout or worktree (CI makes them in .github/actions/setup-laravel)
./vendor/bin/pest --compact --no-tia       # the whole suite, without test impact analysis
```

- Without the keys, about 1,700 API and MCP tests fail on the missing key files.
- Without `--no-tia`, a full run stops when git can't answer for the folder (a worktree
  mounted alone into a container, or git's "dubious ownership" refusal).
- A targeted run (a path or `--filter`) is unaffected: Pest skips test impact analysis for it.
- Not with `--parallel`: Pest drops `--no-tia` before it starts the workers. With no
  recorded graph yet, each worker still asks git and fails the same way (read in Pest's
  `Plugins/Tia.php` and `Plugins/Parallel.php`, not reproduced). Run the whole suite
  serially, or make git work where the tests run.

Browser tests (Pest + Playwright) need built assets: `npm run build`, then
`./vendor/bin/pest tests/Browser/SocialHq --parallel --processes=3`. Never
`php artisan test --parallel` for these (it doesn't start Playwright). Read AGENTS.md
"Browser Tests" before writing one: shared `waitFor*` helpers, `data-testid`, no `sleep()`.

Tests use PostgreSQL (`.env.testing`), as CI and production do. Wayfinder output under
`resources/js/{actions,routes,wayfinder}` is generated: never edit it.

## Hooks in this repo

- Before each edit: generated files and the non-English `lang/*/socialhq*.php` copies
  are refused; a TryPost file without a PATCHES.md row gets a note.
- After each edit: pint (PHP), prettier and eslint (`resources/js`), locale sync for
  `lang/en/socialhq*.php`, `shq_` check for module migrations.
- After Claude stops, in the background: PATCHES.md completeness (TryPost files changed
  against `main` with no row, `unlisted_patches` in `.claude/hooks/lib/common.sh`),
  vue-tsc, `pint --test` and `pest --tia`. Failures wake Claude with a report.
