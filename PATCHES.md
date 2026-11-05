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
| 8 | `app/Models/Traits/HasWorkspace.php` | `accessIn()`, `canPublishDirectlyIn()` and `publishesDirectlyThrough()` ask `App\SocialHq\Approval\ApprovalRules` (3 lines + import) | Every member who is not an admin needs approval; API and MCP callers need approval and cannot approve. Every post write and the approve gate read these |
| 9 | `app/Http/Requests/App/Invite/Concerns/ValidatesMemberAccess.php` | Stores `requires_approval` for non-admins when members are always reviewed (1 line + import) | Invites and member edits store what the rule enforces |
| 10 | `app/Actions/Post/Approval/NotifyApprovalRequested.php` | Emails the requester too when an agent made the request (1 line + import) | An admin's own agent posts reach the admin's inbox |
| 11 | `resources/js/components/members/MemberAccessFields.vue` | Shows `socialhq/MembersReviewedNote.vue` instead of the publishing choice when members are always reviewed (about 8 lines) | The choice no longer applies |
| 12 | `phpunit.xml` | Turns our approval rules off for the test run (2 lines) | Upstream's tests expect TryPost's rules; ours switch them on |
| 20 | `app/Actions/User/CreateUser.php` | A sign-up allowed by `SignupRules` joins the team's account with no workspace and opens an access request, instead of getting an account of its own (about 12 lines) | Self sign-up with company emails, let in by an admin (rows 13 to 19 are in the AI branches) |
| 21 | `app/Http/Requests/App/Auth/RegisterRequest.php` | Without an invite (posted or in the session), refuses emails `SignupRules` doesn't allow (5 lines) | Same |
| 22 | `app/Http/Middleware/App/EnsureRegistrationEnabled.php` | Lets registration through on a self-hosted install when sign-up is open (1 line) | Same |
| 23 | `app/Http/Controllers/Auth/Concerns/PreservesInvite.php` | Google and GitHub sign-ups follow the same allow list (6 lines) | Same |
| 24 | `resources/js/pages/auth/Login.vue` | Shows the sign-up link when sign-up is open (4 lines) | Same |

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
