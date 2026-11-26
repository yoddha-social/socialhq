# What we rely on TryPost for

Places where Social HQ code depends on how TryPost's code behaves, without the
TryPost file saying so (audit 2026-11-24, ARC-05). TryPost's files are ours
since the hard fork, so nothing changes under us by itself. The risk is a
change on one side by someone who doesn't know about the other. If you change
anything named here, check the Social HQ side too, and update this page.

What we changed inside TryPost's files is a different question; git answers it:
`git log --oneline v2.0.1..main -- <path>` (see PATCHES.md).

## Guest connect links

`App\SocialHq\Http\Middleware\ActAsConnectInviter` lets a guest with a connect
link walk TryPost's own connect flow as the admin who sent the link (SOCIALHQ.md,
"Ask the account owner to connect"). It relies on:

- **Route names.** It recognises the steps by name: `app.social.<network>.connect`
  (GET, the start), `app.social.<network>.callback`, `app.social.connect.show` and
  `app.social.connect.finish` (both with `{platform}`). A renamed route makes the
  guard do nothing, so the guest meets the login wall (it fails closed) and the
  tests below fail.
- **TryPost checking the admin on every step.** The three steps run with the
  admin as the request's user, and TryPost's `manageAccounts` check on each step
  is what limits a link to that admin's rights. When the roles branch replaces the
  admin flag with permissions, run the tests below again.
- **How a connection is stored.** The start route keeps a
  `App\Support\Social\PendingConnection` in the session and redirects to the
  network; "Finish connection" forgets it only once it stored the channels, so
  "a redirect, and no pending connection left" is read as success. Used:
  `current()`, `forget()`, `platform()`, `workspaceId()`, `reconnectId()`,
  `isReady()`, `selected()`, `failure()`.
- **What the start route reads from the address.** `reconnect`, `return_to`,
  `switch`, and `?offer=` (kept by `CaptureOffer` under its session key). The
  guard keeps only the link's own `reconnect` and puts the guest's waiting offer
  aside while TryPost runs as the admin.
- **What TryPost flashes.** Inertia's flash (`SessionKey::FLASH_DATA`) set for the
  admin's next page is dropped, so the guest never sees it.
- **Middleware order.** Appended to the `web` group and sorted before
  `AuthenticatesRequests` (`SocialHqServiceProvider`), so `auth` and the checks
  after it judge the admin.

Tests: `tests/Feature/SocialHq/Channels/ConnectInviteTest.php` (the whole flow on X,
and every guard) and `ConnectInviteFacebookTest.php` (the flow on Facebook, with
Pages from the Graph API).

## Model events

Registered in `SocialHqServiceProvider`:

- `Post::created` / `Post::updated` write the approval history
  (`RecordApprovalEvents`): requests, approvals, rejections and withdrawals,
  read from the status change (`PostApproval::isRequest()` / `isApproval()`) and
  the signed-in caller. A status written by a query rather than a model save is
  not recorded.
- `User::updated` on `current_workspace_id` closes a waiting access request
  (`AccessRequests::closeOnceJoined`).
- `Account::deleting` removes the company's AI from LiteLLM (`TearDownCompanyAi`).
- `Post::resolveRelationUsing('planItems')`: the plan item a post came from.

## Sign-up events

TryPost's auth controllers fire `Registered`, `Verified` and `PasswordReset`. We
listen for them to tell admins about access requests and platform admins about
company requests, and, after a password reset, to end the person's other sessions
and count their email as confirmed (`PasswordResetProof`). A path that doesn't
fire them skips those.

## AI prompts

TryPost's caption assistant (`prompts/post_content/assistant`) and idea generator
(`prompts/ideas/generator`) include `prompts.socialhq.brand_knowledge` without
passing a workspace. A view composer fills it for the signed-in user's current
workspace, which is safe only because those controllers authorize that same
workspace. `TryPostPromptFocus` reads their view data (`business`, `notes`,
`current_content`, `writes_new_text`) and the request's `prompt`. Renaming those
keys quietly drops the focus.

## Every request

Appended to the `web` group: `RedirectWhileAwaitingAccess`, `ShareSocialHqProps`
and `EnsureCompanyApproved`. The two gates let through a list of TryPost routes by
name (`logout`, `verification.notice`, `verification.verify`, `verification.send`,
`app.invites.show`, `app.invites.accept`, `app.invites.decline`) and send
`app.workspaces.create` to the company page. A renamed route there locks people
out of it.

## Notifications

`SendNotification` hands every mailable to `Notices::fromNotification()`, which
maps TryPost's mailables (`PostPublished`, `PostPublishFailed`,
`PostApprovalRequested`, `PostApproved`, `PostRejected`, `PostNoteAdded`,
`AccountDisconnected`, `WorkspaceConnectionsDisconnected`, `PostAtRisk`) to bell
notices. A new mailable reaches the bell only once it is mapped there.
