# Companies and per-company AI (2026-11-06)

Owner decision (2026-11-06): Social HQ hosts several companies. A company is a
TryPost account a platform admin approved; it holds workspaces (brands). Each
company brings its own AI provider keys; ours keeps the platform keys.

## Step 1: companies (lane `companies`)
- `shq_companies` (one per account, status pending/approved/declined/suspended,
  `uses_platform_ai`) and `shq_platform_admins` exist (foundation commit).
  Existing accounts that own a workspace were migrated as approved companies using
  the platform AI.
- Platform admins are granted only with `php artisan socialhq:platform-admin`.
- Sign-up: an email on the company allow-list joins that company (today's flow).
  Anyone else may **ask to start a company** (company name), once email-verified;
  platform admins are emailed; the person waits on a page until approved.
- Platform admin pages (`/socialhq/platform/companies`): requests and companies,
  approve / decline (note) / suspend / restore, allow platform AI.
- Only an approved company's owner creates workspaces; suspended companies can't
  work (a page says so). Invited people's empty accounts are not companies.

## Step 2: company AI (lane `company-ai`)
- Each company is a LiteLLM team with its own virtual key and budget. Provider keys
  live only in LiteLLM (credentials, encrypted with its salt key); the app keeps
  provider, last 4 characters, status, who and when, never the key.
- The company's virtual key is stored in the app, encrypted with a dedicated key
  (not APP_KEY).
- Settings › AI (account owner): add a provider key (approved, no-training
  providers only, with setup steps), Test, Replace, Remove, order, monthly budget,
  spend. Every change is logged.
- AI calls use the company's key; a company without its own keys uses the
  platform's only when `uses_platform_ai` is on; otherwise AI features say how to
  set it up.

As built (2026-11-06), checked live against LiteLLM v1.106.7:
- Credentials, teams, team keys and `/health/test_connection` need no licence;
  `/model/new` needs `store_model_in_db: true`. **Team-scoped models
  (`model_info.team_id`) need LiteLLM Enterprise**, so each company's
  deployments are global groups `shq-<task>@shq-co-<account>` and its team's
  `models` list lets only its own key call them (verified: the team key gets 403
  on `shq-write`, the platform key 403 on a company group).
- Provider order is the deployments' `order` in each group (verified: a failing
  first deployment falls over to the second).
- The app holds a management key (`LITELLM_ADMIN_KEY`: a `proxy_admin` user's
  key limited by `allowed_routes`), not the master key. Limit found: a key
  allowed `/key/generate` can mint an unrestricted key for its own admin user,
  so a stolen one is admin-equivalent; it is still revocable on its own.

## Step 3 (later): bring your own social apps
Per-company Meta (and YouTube, X) app credentials so a company publishes through
its own app until our app passes App Review. See the answer in the session notes.

## Step 4 (later): public sign-up
Once our Meta and LinkedIn apps are approved for other companies.
