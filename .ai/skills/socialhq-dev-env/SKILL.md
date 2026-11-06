---
name: socialhq-dev-env
description: Runs Social HQ's PHP tools, tests, linters and the app where PHP is available (the dev box or the compose app container; the laptop has no PHP). Use before running pint, Pest, artisan, browser tests, vue-tsc or the dev server, or when a hook reports "no PHP runner".
---

# Running things here

PHP runner right now: !`command -v php >/dev/null && echo "php on PATH" || (docker compose ps --status running --services 2>/dev/null | grep -qx app && echo "compose app container" || echo "none") || true`

## Where PHP runs

| Place | How | Notes |
|---|---|---|
| Dev box (Hetzner CX33) | Commands as written, `php` on PATH | The intended place for PHP work |
| Laptop, compose | `docker compose up -d`, then `docker compose exec -T app <command>` | 8 GB RAM: stop other stacks first (`trypost/local`, HRMS). Heavy: building the image and Vite dev mode may not fit |
| Laptop, no container | Node tools only | Say plainly that pint and Pest did not run |

Node tools (prettier, eslint, vue-tsc, `npm run build`) run on the laptop directly.

## Commands

```bash
./vendor/bin/pest tests/Feature/SocialHq --compact                         # our tests
./vendor/bin/pest --compact --parallel --tia                              # tests affected by the change
./vendor/bin/pint --dirty                                                 # format changed PHP
npx --no-install vue-tsc --noEmit                                         # types
npm run lint && npm run format:check                                      # what CI's linter checks
```

Browser tests (Pest + Playwright) need built assets: `npm run build`, then
`./vendor/bin/pest tests/Browser/SocialHq --parallel --processes=3`. Never
`php artisan test --parallel` for these (it doesn't start Playwright). Read AGENTS.md
"Browser Tests" before writing one: shared `waitFor*` helpers, `data-testid`, no `sleep()`.

Tests use PostgreSQL (`.env.testing`); CI also runs MySQL. Wayfinder output under
`resources/js/{actions,routes,wayfinder}` is generated: never edit it.

## Hooks in this repo

- After each edit: pint (PHP), prettier and eslint (`resources/js`), locale sync for
  `lang/en/socialhq.php`, `shq_` check for module migrations.
- After Claude stops, in the background: PATCHES.md completeness, vue-tsc, `pint --test`
  and `pest --tia`. Failures wake Claude with a report.
