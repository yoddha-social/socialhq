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
