# Changes to TryPost files

Social HQ code lives in its own module (see [SOCIALHQ.md](SOCIALHQ.md)). We
don't merge TryPost's feature releases; we take its security fixes one by one
(see "Security intake" below). This file lists **every** change to a file that
came with TryPost, so we know where one of its fixes may conflict with ours.
Change a TryPost file when that is the right place, and add a row.

| # | File | Change | Why |
|---|---|---|---|
| 1 | `bootstrap/providers.php` | Registers `App\SocialHq\SocialHqServiceProvider` (2 lines) | Loads the Social HQ module |
| 2 | `resources/js/components/AppSidebar.vue` | Imports and renders `socialhq/SourceLink.vue` in the sidebar footer and `socialhq/WorkspaceSwitcher.vue` at the top of the sidebar content (4 lines) | AGPL-3.0 §13: users of the modified version can get its source. Upstream hides the workspace switcher in a submenu of the user menu |
| 3 | `README.md` | Short Social HQ note at the top | Says what this fork is and where our docs are |
| 6 | `resources/js/types/command-palette.ts` | Adds the `workspaces` group (1 line) | Command palette entries to switch workspace |
| 7 | `resources/js/components/command-palette/CommandPaletteContent.vue` | Adds `socialhq/workspaceCommandEntries.ts` entries to the palette, shown while searching (5 lines) | Same |
| 13 | `resources/js/components/posts/composer/MediaSourceMenu.vue` | Adds `socialhq/AiGraphicMenuItem.vue` under "Upload from computer" and `socialhq/AiGraphicDialog.vue` after the menu (9 lines) | "Generate with AI" graphics in the media menu of ideas and posts (rows 8–12 are in the approval-gate branch) |
| 14 | `composer.json` | Removes `boost:update` from `post-update-cmd` (1 line) | It rewrote AGENTS.md and the Boost skills on every `composer update`, undoing our edits. Run it by hand and review the diff |
| 15 | `app/Ai/Agents/IdeaGenerator.php`, `MediaAltTextGenerator.php`, `PostContentShortener.php`, `PostWritingAssistant.php` | `use UsesAiTask;` and an `aiTask()` method naming the agent's AI task (about 10 lines each) | `App\SocialHq\Ai\AiRouter` picks the providers per task: the LiteLLM model group, or the direct route, approved providers only |
| 16 | `app/Http/Middleware/App/HandleInertiaRequests.php` | `aiEnabled` asks `AiRouter::isAvailable(AiTask::Write)` instead of checking the default provider's key (3 lines) | With LiteLLM there is no provider key in the app |
| 17 | `tests/Unit/Ai/Agents/AgentProviderResolutionTest.php` | Asserts every agent routes through an AI task (it asserted that none overrides `provider()`) | The rule changed with row 15 |
| 18 | `phpunit.xml` | `SOCIALHQ_AI_GATEWAY=direct` and `SOCIALHQ_AI_APPROVED_PROVIDERS` for the test providers (2 lines) | Tests call the SDK's fakes and `Http::fake()`, never LiteLLM |
| 19 | `.env.example` | "Social HQ AI routing" block under the AI settings | Documents the gateway, the approved list and the routes |

Files we **added** aren't listed here; they're under the module paths in
SOCIALHQ.md, plus `config/socialhq.php`, `routes/socialhq.php`, `CLAUDE.md`,
`.claude/hooks/`, `.claude/rules/`, `.claude/settings.json`,
`.ai/skills/socialhq-*`, `bin/socialhq-*` and `.github/workflows/socialhq-*.yml`.

## Security intake

Monthly, with the `socialhq-security-intake` skill: dependency advisories
(`composer audit`, `npm audit`, the PHP base image), then TryPost's security
commits since the last intake, cherry-picked with `git cherry-pick -x` (or the
security part ported by hand from a feature commit). One row per intake.

| Date | TryPost reviewed up to | Picked | Dependency upgrades |
|---|---|---|---|
