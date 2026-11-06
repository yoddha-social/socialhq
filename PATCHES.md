# Edits to upstream TryPost files

Social HQ code lives in its own module (see [SOCIALHQ.md](SOCIALHQ.md)). This
file lists **every** change to a file that came from upstream TryPost, so
merging an upstream release stays a short, predictable job. Add a row
whenever you touch an upstream file; keep each edit as small as possible.

| # | File | Change | Why |
|---|---|---|---|
| 1 | `bootstrap/providers.php` | Registers `App\SocialHq\SocialHqServiceProvider` (2 lines) | Loads the Social HQ module |
| 2 | `resources/js/components/AppSidebar.vue` | Imports and renders `socialhq/SourceLink.vue` in the sidebar footer and `socialhq/WorkspaceSwitcher.vue` at the top of the sidebar content (4 lines) | AGPL-3.0 §13: users of the modified version can get its source. Upstream hides the workspace switcher in a submenu of the user menu |
| 3 | `README.md` | Short Social HQ note at the top | Says what this fork is and where our docs are |
| 6 | `resources/js/types/command-palette.ts` | Adds the `workspaces` group (1 line) | Command palette entries to switch workspace |
| 7 | `resources/js/components/command-palette/CommandPaletteContent.vue` | Adds `socialhq/workspaceCommandEntries.ts` entries to the palette, shown while searching (5 lines) | Same |
| 90 | `app/Models/Traits/HasWorkspace.php` | `switchWorkspace()` also sets the `currentWorkspace` relation (1 line) | The relation stayed on the old workspace for the rest of the request; browser tests reuse the signed-in user across requests, so the sidebar kept the old name |
| 91 | `.github/workflows/tests.yml` | Runs on every PR and on pushes to `main` only; read-only token; job timeouts; failed browser test files get one retry, reported as a flaky warning | Stacked PRs got no CI; random browser failures kept every run red |
| 92 | `.github/workflows/lint.yml` | Checks instead of fixing: Pint, ESLint and `vue-tsc` on the whole repo (after generating Wayfinder routes), Prettier on the files a change touches unless they weren't clean before; every PR; read-only token | The old job ran the fixers, so it could never fail; much of the TryPost base isn't Prettier-clean |
| 93 | `.github/workflows/release-docker.yml` | Deleted | TryPost's own image release; ours is `socialhq-image.yml` |
| 94 | `tests/Browser/CalendarDragAndDropTest.php` | "The hour under way" test uses the app's five-minute rule and accepts either outcome in the minute when the page's clock may differ (8 lines) | It used six minutes, so it failed whenever it ran in minute :54 of an hour |
| 4 | `app/Ai/Agents/{IdeaGenerator,MediaAltTextGenerator,PostContentShortener,PostWritingAssistant}.php` | `#[Temperature(x)]` replaced by `use UsesModelSafeTemperature` plus `preferredTemperature()` returning the same value | Claude 5 models reject `temperature`, which broke every AI feature. Candidate for an upstream PR |
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
