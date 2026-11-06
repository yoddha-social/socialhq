---
name: socialhq-security-intake
description: Monthly security pass for Social HQ - dependency advisories (composer audit, npm audit, the PHP base image) and security fixes from TryPost, cherry-picked one by one. TryPost features are not merged.
disable-model-invocation: true
argument-hint: "[TryPost commit or tag reviewed up to, default: the last intake]"
---

# Security intake

We take security fixes from TryPost and from our dependencies, not TryPost's features.
Most fixes come from dependencies, so start there.

Last intake: !`awk '/^## Security intake/{f=1} f && /^\| 20[0-9][0-9]-/' PATCHES.md 2>/dev/null | tail -1 | grep . || echo "none recorded"`

## 1. Dependencies

1. `composer audit` and `npm audit --omit=dev` (PHP via `socialhq-dev-env`).
2. For each advisory: is the vulnerable code path reachable in our use? Upgrade the one
   package (`composer update vendor/package --with-dependencies`, or `npm update <pkg>`).
   Ask the user before a major-version jump.
3. PHP itself: `ARG PHP_VERSION` in `docker/Dockerfile`, and the CI `php-version`.
   Check php.net for security releases newer than the pinned one.
4. After `composer update`, look at the diff of `.claude/skills/` and AGENTS.md if Boost
   ran, and drop changes that undo ours.

## 2. TryPost fixes

```bash
git fetch upstream --tags
git log --oneline <last-reviewed>..upstream/main -i -E \
  --grep='secur|vulnerab|CVE|XSS|CSRF|SSRF|inject|auth|escape|sanitiz|bypass|leak'
```

Also read TryPost's release notes and GitHub security advisories since the last intake.
The keyword search misses fixes inside large feature PRs.

For each candidate:

1. Read the diff. Does the affected code exist in our fork and do we use it? Skip
   features we don't run (billing, Stripe offers, PostHog, the ChatGPT sign-in), with a
   reason.
2. Clean fix commit: `git cherry-pick -x <sha>` on a `security/<yyyy-mm>` branch.
3. Fix buried in a feature commit: port only the security hunk by hand, and name the
   source commit in the message.
4. A conflict in a file PATCHES.md lists: keep our change, apply theirs around it. A
   conflict anywhere else means an unlisted edit: list it.
5. Add or port the test that proves the fix.

## 3. Finish

- Run the affected tests and `pint --test`; CI runs the rest on the PR.
- Add a row under "Security intake" at the end of PATCHES.md:
  `| date | TryPost reviewed up to <sha> | picked: <shas or none> | dependency upgrades |`.
- Open one PR for the intake and tell the user what was skipped and why. Don't merge it.
