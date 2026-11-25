---
name: socialhq-security-intake
description: Security watch for Social HQ, a hard fork of TryPost - dependency advisories (composer audit, npm audit, the PHP base image), then a read of TryPost's commits since the last review in security-relevant areas, with real fixes ported by hand and a dated "reviewed up to" record. TryPost features are never merged.
disable-model-invocation: true
argument-hint: "[TryPost commit or tag reviewed up to, default: the last intake]"
---

# Security intake

Social HQ is a hard fork (2026-11-25): TryPost's code is ours and its features are never
merged. We still run much of the same login, permission and publishing code, and TryPost
doesn't announce security fixes, so we read its changes in those areas ourselves.
Most fixes come from dependencies, so start there.

Last intake: !`awk '/^## Security intake/{f=1} f && /^\| 20[0-9][0-9]-/' PATCHES.md 2>/dev/null | tail -1 | grep . || echo "none recorded"`

## 1. Dependencies

1. `composer audit` and `npm audit --omit=dev` (PHP via `socialhq-dev-env`).
2. For each advisory: is the vulnerable code path reachable in our use? Upgrade the one
   package (`composer update vendor/package --with-dependencies`, or `npm update <pkg>`).
   Ask the user before a major-version jump.
3. PHP itself: `ARG PHP_VERSION` in `docker/Dockerfile`, and `php-version` in
   `.github/workflows/socialhq-ci.yml`, `.github/workflows/socialhq-audit.yml` and
   `.github/actions/setup-laravel/action.yml`. Check php.net for security releases newer
   than the pinned one.
4. After `composer update`, look at the diff of `.claude/skills/` and AGENTS.md if Boost
   ran, and drop changes that undo ours.

## 2. TryPost fixes

```bash
git fetch upstream --tags
git log --oneline <last-reviewed>..upstream/main          # every commit since the last review
git diff --stat <last-reviewed> upstream/main -- app/Http/Controllers/Auth app/Http/Middleware \
  app/Policies app/Models/Traits app/Http/Controllers/Api app/Mcp routes app/Services/Social \
  app/Support composer.lock package-lock.json             # the security-relevant files
```

Read the diffs in those areas commit by commit, not by title: login and sessions,
policies and invites, outbound requests (`SafeHttpFetcher`), the publishers we use
(Facebook, Instagram via Facebook, LinkedIn, YouTube, X) and their token refresh,
webhooks, the REST API, MCP and uploads. Fixes often sit inside feature commits. Also
check TryPost's GitHub security advisories since the last review.

For each candidate:

1. Read the diff. Does the affected code exist in our fork and do we use it?
   `git log --oneline v2.0.1..main -- <file>` shows what we changed there and why. Skip
   features we don't run (billing, Stripe offers, PostHog, the ChatGPT sign-in), with a
   reason.
2. Port the security change by hand onto our version of the file, on a
   `security/<yyyy-mm>` branch, and name the source commit in the message. Our files
   have moved on, so a cherry-pick rarely applies cleanly; keep our behaviour and add
   theirs. A fix to TryPost's `tests.yml` or `lint.yml` goes into `socialhq-ci.yml`.
3. Add or port the test that proves the fix.

## 3. Finish

- Run the affected tests and `pint --test`; CI runs the rest on the PR.
- Add a dated row under "Security intake" at the end of PATCHES.md, even when nothing
  was found: `| date | TryPost reviewed up to <sha> (<its date>) | ported: <shas> or
  nothing found (N commits, M files read) | dependency upgrades |`.
- Open one PR for the intake and tell the user what was skipped and why. Don't merge it.
