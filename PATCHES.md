# Edits to upstream TryPost files

Social HQ code lives in its own module (see [SOCIALHQ.md](SOCIALHQ.md)). This
file lists **every** change to a file that came from upstream TryPost, so
merging an upstream release stays a short, predictable job. Add a row
whenever you touch an upstream file; keep each edit as small as possible.

| # | File | Change | Why |
|---|---|---|---|
| 1 | `bootstrap/providers.php` | Registers `App\SocialHq\SocialHqServiceProvider` (2 lines) | Loads the Social HQ module |
| 2 | `resources/js/components/AppSidebar.vue` | Imports and renders `socialhq/SourceLink.vue` in the sidebar footer (2 lines) | AGPL-3.0 §13: users of the modified version can get its source |
| 3 | `README.md` | Short Social HQ note at the top | Says what this fork is and where our docs are |
| 91 | `.github/workflows/tests.yml` | Runs on every PR and on pushes to `main` only; read-only token; job timeouts; failed browser test files get one retry, reported as a flaky warning | Stacked PRs got no CI; random browser failures kept every run red |
| 92 | `.github/workflows/lint.yml` | Checks instead of fixing: Pint, ESLint and `vue-tsc` on the whole repo (after generating Wayfinder routes), Prettier on the files a change touches unless they weren't clean before; every PR; read-only token | The old job ran the fixers, so it could never fail; much of the TryPost base isn't Prettier-clean |
| 93 | `.github/workflows/release-docker.yml` | Deleted | TryPost's own image release; ours is `socialhq-image.yml` |
| 94 | `tests/Browser/CalendarDragAndDropTest.php` | "The hour under way" test uses the app's five-minute rule and accepts either outcome in the minute when the page's clock may differ (8 lines) | It used six minutes, so it failed whenever it ran in minute :54 of an hour |
| 5 | `app/Support/Timezone.php` | `normalize()` asks ICU for an alias's canonical name without first requiring it in PHP's legacy tz list | Without the legacy tz database (Ubuntu's tzdata-legacy), every legacy alias became UTC; this also kept CI red. Proposed upstream (trypostit/trypost#478) |

Files we **added** (no merge risk) aren't listed here; they're under the
module paths in SOCIALHQ.md, plus `config/socialhq.php`,
`routes/socialhq.php` and `.github/workflows/socialhq-image.yml`.

## Merging an upstream release

```bash
git fetch upstream --tags
git switch -c upstream/v2.1.0 main
git merge v2.1.0               # resolve conflicts; only the files above can conflict on our side
# push the branch, open a PR into our main, let CI (upstream's tests plus ours) pass, merge
```

Merge **release tags**, not upstream `main`. Read the release notes first:
TryPost has shipped breaking changes in patch releases.
