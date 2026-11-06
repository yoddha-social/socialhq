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
| 4 | `app/Ai/Agents/{IdeaGenerator,MediaAltTextGenerator,PostContentShortener,PostWritingAssistant}.php` | `#[Temperature(x)]` replaced by `use UsesModelSafeTemperature` plus `preferredTemperature()` returning the same value | Claude 5 models reject `temperature`, which broke every AI feature. Candidate for an upstream PR |
| 5 | `app/Support/Timezone.php` | `normalize()` asks ICU for an alias's canonical name without first requiring it in PHP's legacy tz list | Without the legacy tz database (Ubuntu's tzdata-legacy), every legacy alias became UTC; this also kept CI red. Proposed upstream (trypostit/trypost#478) |
| 6 | `resources/js/types/command-palette.ts` | Adds the `workspaces` group (1 line) | Command palette entries to switch workspace |
| 7 | `resources/js/components/command-palette/CommandPaletteContent.vue` | Adds `socialhq/workspaceCommandEntries.ts` entries to the palette, shown while searching (5 lines) | Same |
| 8 | `app/Models/Traits/HasWorkspace.php` | `accessIn()`, `canPublishDirectlyIn()` and `publishesDirectlyThrough()` ask `App\SocialHq\Approval\ApprovalRules` (3 lines + import) | Every member who is not an admin needs approval; API and MCP callers need approval and cannot approve. Every post write and the approve gate read these |
| 9 | `app/Http/Requests/App/Invite/Concerns/ValidatesMemberAccess.php` | Stores `requires_approval` for non-admins when members are always reviewed (1 line + import) | Invites and member edits store what the rule enforces |
| 10 | `app/Actions/Post/Approval/NotifyApprovalRequested.php` | Emails the requester too when an agent made the request (1 line + import) | An admin's own agent posts reach the admin's inbox |
| 11 | `resources/js/components/members/MemberAccessFields.vue` | Shows `socialhq/MembersReviewedNote.vue` instead of the publishing choice when members are always reviewed (about 8 lines) | The choice no longer applies |
| 12 | `phpunit.xml` | Turns our approval rules off for the test run (2 lines) | Upstream's tests expect TryPost's rules; ours switch them on |
| 13 | `resources/js/components/posts/composer/MediaSourceMenu.vue` | Adds `socialhq/AiGraphicMenuItem.vue` under "Upload from computer" and `socialhq/AiGraphicDialog.vue` after the menu (9 lines) | "Generate with AI" graphics in the media menu of ideas and posts |
| 14 | `composer.json` | Removes `boost:update` from `post-update-cmd` (1 line) | It rewrote AGENTS.md and the Boost skills on every `composer update`, undoing our edits. Run it by hand and review the diff |
| 15 | `app/Ai/Agents/IdeaGenerator.php`, `MediaAltTextGenerator.php`, `PostContentShortener.php`, `PostWritingAssistant.php` | `use UsesAiTask;` and an `aiTask()` method naming the agent's AI task (about 10 lines each) | `App\SocialHq\Ai\AiRouter` picks the providers per task: the LiteLLM model group, or the direct route, approved providers only |
| 16 | `app/Http/Middleware/App/HandleInertiaRequests.php` | `aiEnabled` asks `AiRouter::isAvailable(AiTask::Write)` instead of checking the default provider's key (3 lines) | With LiteLLM there is no provider key in the app |
| 17 | `tests/Unit/Ai/Agents/AgentProviderResolutionTest.php` | Asserts every agent routes through an AI task (it asserted that none overrides `provider()`) | The rule changed with row 15 |
| 18 | `phpunit.xml` | `SOCIALHQ_AI_GATEWAY=direct` and `SOCIALHQ_AI_APPROVED_PROVIDERS` for the test providers (2 lines) | Tests call the SDK's fakes and `Http::fake()`, never LiteLLM |
| 19 | `.env.example` | "Social HQ AI routing" block under the AI settings | Documents the gateway, the approved list and the routes |
| 20 | `app/Actions/User/CreateUser.php` | A sign-up allowed by `SignupRules` joins the team's account with no workspace and opens an access request, instead of getting an account of its own (about 12 lines) | Self sign-up with company emails, let in by an admin (rows 13 to 19 are in the AI branches) |
| 21 | `app/Http/Requests/App/Auth/RegisterRequest.php` | Without an invite (posted or in the session), refuses emails `SignupRules` doesn't allow (5 lines) | Same |
| 22 | `app/Http/Middleware/App/EnsureRegistrationEnabled.php` | Lets registration through on a self-hosted install when sign-up is open (1 line) | Same |
| 23 | `app/Http/Controllers/Auth/Concerns/PreservesInvite.php` | Google and GitHub sign-ups follow the same allow list (6 lines) | Same |
| 24 | `resources/js/pages/auth/Login.vue` | Shows the sign-up link when sign-up is open (4 lines) | Same |
| 25 | `resources/views/prompts/post_content/assistant.blade.php` | `@include('prompts.socialhq.brand_knowledge')` before the closing rules (2 lines) | The caption assistant writes from the workspace's brand profile and knowledge |
| 26 | `resources/views/prompts/ideas/generator.blade.php` | Same include before the rules (2 lines) | The idea generator does too |
| 27 | `resources/js/composables/useSettingsNavigation.ts` | "Brand knowledge" in the Content group of the settings sidebar (13 lines) | Where people look for workspace content settings |
| 28 | `lang/*/*.php` (not `socialhq.php`) | "TryPost" and "TryPost.it" replaced by "Social HQ" in every locale, except the G2 review quotes and the founder's signature, which are no longer shown; `channels.details.help_docs` is "Setup guides" | Rebrand: the app is Social HQ. The setup guides still open TryPost's docs, so they don't say "Social HQ documentation". After a security intake, run the same replacement on TryPost's new copy |
| 29 | `resources/js/components/AppLogo.vue`, `resources/js/components/AppSidebar.vue` (sidebar logo 50px, collapsed mark 26px), `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png`, `public/images/emails/logo-header.png`, `public/images/socialhq/*` (replaces `public/images/trypost/`) | The Social HQ mark (an S and a rising arrow in a hexagon, navy to teal) and wordmark; the shape lives in `components/socialhq/logo.ts` | TryPost's logo is TryPost's brand |
| 30 | `resources/js/lib/shareCard/frame.ts`, `index.ts` | The share card paints the Social HQ mark from `components/socialhq/logo.ts`; downloads are named `socialhq-…` | Same |
| 31 | `resources/js/layouts/auth/AuthSplitLayout.vue` | The panel beside login and register lists what Social HQ does (`socialhq.auth_panel.*`) instead of TryPost's G2 reviews | Those reviews are about TryPost, not this app |
| 32 | `resources/js/components/channels/ConnectChannelDetails.vue` | Drops the founder's photo and signature under the channel tip | Same |
| 33 | `resources/js/components/WorkspaceMenuContent.vue` | Help menu: feature requests and GitHub open this repository; the Discord link is gone | TryPost's community isn't ours |
| 34 | `resources/js/pages/auth/Login.vue`, `Register.vue` | `LegalLinks` shows only when `LEGAL_TERMS_URL` and `LEGAL_PRIVACY_URL` are set | Their defaults (`config/trypost.php`, row 35) pointed at TryPost's terms |
| 35 | `config/app.php`, `config/mail.php`, `config/session.php`, `config/trypost.php`, `.env.example`, `docker/.env.docker.example` | Defaults: app name "Social HQ", mail from "Social HQ" at `hello@example.com`, cookie `socialhq-session`, user agent `SocialHQ/1.0`, no legal URLs, changelog off | No TryPost name or domain unless set; the changelog is TryPost's release notes |
| 36 | `resources/js/app.ts`, `resources/views/app.blade.php`, `resources/views/vendor/mail/html/header.blade.php`, `resources/js/components/analytics/workspace/TopPosts.vue`, `resources/js/components/welcome/mcp/McpChatExample.vue` | "Social HQ" where the name is written in code | Same |
| 37 | `maizzle/components/header.html`, `footer.html` and the compiled `resources/views/mail/*.blade.php`; `public/images/emails/social/` removed | Emails link to `APP_URL`, sign with `APP_NAME` and drop TryPost's social links and copyright line | Same |
| 38 | `app/Mcp/Servers/TryPostServer.php`, `app/Mcp/Tools/**` descriptions, `app/Http/Middleware/Mcp/ChallengeChatGptToolCalls.php`, `app/Services/Http/SafeHttpFetcher.php`, `app/Http/Controllers/App/InsightsController.php` | The MCP server is named "Social HQ" with our icon; tool descriptions, the sign-in message, the fetch user agent and the insights file name say Social HQ | Same. Class names, config keys (`trypost.*`) and the `/mcp/trypost` URL stay, so upstream fixes still apply |
| 39 | `README.md` | Upstream's logo loads from TryPost's repository | The file moved out of `public/images/trypost/` |
| 40 | `tests/Unit/TryPostServerTest.php`, `tests/Feature/Analytics/InsightsExportTest.php`, `tests/Feature/Mail/MailRenderingTest.php`, `MailLocalizationTest.php`, `tests/Feature/Mcp/ChatGptSignInTest.php`, `tests/Unit/Settings/DeleteAccountCopyTest.php`, `tests/fixtures/single-line-copy.php` (four budgets +2, the length difference of the name), `tests/Browser/AuthTitlesTest.php`, `tests/Browser/AuthReviewsTest.php` (now `AuthPanelTest.php`) | Expect the new icon, file name, titles and panel | Rows 29 to 38 |
| 41 | `app/Http/Controllers/Auth/GoogleController.php`, `GitHubController.php` | Signing in to an existing account goes through `App\SocialHq\Signup\OAuthSignIn::claimUnverified()` instead of `markEmailAsVerified()`; Google refuses an address whose `email_verified` claim is false (about 10 lines + import each); the provider's email is lowercased before the lookup and on create (2 lines each) | Someone who registered another person's address with a password and never confirmed it kept that password after the real owner signed in through Google or GitHub. The provider's proof now wins: new password, new remember token, other sessions ended, `Verified` fired |
| 42 | `app/Http/Requests/App/Auth/RegisterRequest.php` | The allow list is a `bail` rule before `unique` (`SignupRules::allowListRule()`), replacing the `after()` check of row 21 | `unique` ran first, so anyone could learn which emails have an account |
| 43 | `routes/auth.php` | `throttle:socialhq-register` on `register.store` (1 line; the limiter is defined in `SocialHqServiceProvider`) | Sign-up is open to the company domain: 5 tries a minute and 20 an hour per IP |
| 44 | `app/Actions/Invite/SettleAfterInvite.php` | Doesn't settle (delete) a user who still waits for access (1 line + import) | Someone waiting for access may now open invites; declining one deleted them as a stranded member |
| 45 | `resources/js/pages/settings/workspace/Members.vue` | Renders `socialhq/AccessRequestsBanner.vue` above the members list (2 lines) | Admins find waiting access requests where they manage people, not only by email |
| 46 | `lang/*/mail.php` | `socialhq_access_requested` and `socialhq_access_approved` blocks, English in every locale | The sign-up emails follow the Maizzle + i18n rule (copy in `mail.php`, not `socialhq.php`) |
| 53 | `resources/js/components/AppSidebar.vue` | A "Plan" item after Publish for people who can create posts (10 lines + 2 imports) | The planning layer (content plan, production, events) under `/socialhq/plan` |
| 60 | `app/Actions/Post/BuildCalendarPageProps.php` | A `shqEvents` prop (closure, so partial reloads skip it unless asked): the shown days' events from `EventsForMonth::markersByDay()` (2 lines + 1 import) | Festival and holiday markers on the publish calendar |
| 61 | `resources/js/pages/posts/Calendar.vue` | `shqEvents` added to `RELOAD_PROPS`, so moving between periods refreshes the markers (1 line) | Same |
| 62 | `resources/js/components/publish/CalendarMonthGrid.vue` | `CalendarEventMarkers` next to each day number (the number and markers share a wrapper; 9 lines + 1 import) | Same |
| 63 | `resources/js/components/publish/CalendarTimeGrid.vue` | `CalendarEventMarkers` beside the day number in each column header, week and days views; the number's span wraps both so the header keeps its height (4 lines + 1 import) | Same |
| 75 | `tests/fixtures/single-line-copy.php` | Budgets for the planning layer's single-line labels (buttons, tabs, badges, menu items in `socialhq_plan`, `socialhq_events`, `socialhq_production`), at their English length (124 lines) | `SingleLineCopyTest` keeps them on one line once they are translated |
| 80 | `composer.json`, `composer.lock` | Adds `openspout/openspout` (CSV and XLSX reading) and `smalot/pdfparser` (PDF text), both pure PHP | Brand knowledge: importing old calendars from spreadsheets and reading uploaded documents |
| 81 | `tests/fixtures/single-line-copy.php` | Budgets for the old calendars' buttons (`socialhq_calendars.*`), at their English length (7 lines) | `SingleLineCopyTest` keeps them on one line once they are translated |

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
