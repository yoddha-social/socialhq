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
| 4 | `app/Ai/Agents/{IdeaGenerator,MediaAltTextGenerator,PostContentShortener,PostWritingAssistant}.php` | `#[Temperature(x)]` replaced by `use UsesModelSafeTemperature` plus `preferredTemperature()` returning the same value | Claude 5 models reject `temperature`, which broke every AI feature. Candidate for an upstream PR |

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
