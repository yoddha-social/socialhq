---
name: socialhq-release
description: Releases a Social HQ version - tags shq-vX.Y.Z so the image workflow builds it, then prepares the socialhq-deploy change that pins the new image.
disable-model-invocation: true
argument-hint: "<version, e.g. 0.2.0>"
---

# Release Social HQ $ARGUMENTS

The `/release` and `/changelog` commands in `.claude/commands/` are TryPost's ritual (v* tags,
customer emails). Don't use them here. Our tags are `shq-v*`.

Last Social HQ release: !`git describe --tags --match 'shq-v*' --abbrev=0 2>/dev/null || echo none`

## Steps

1. On `main`, clean and up to date with origin; CI green on the commit.
2. Summarise what changed since the last `shq-v*` tag (`git log --oneline <last>..HEAD`),
   grouped: features, fixes, security intake. Note new env variables and migrations.
3. Show the user the version and summary; wait for a yes.
4. `git tag shq-v$ARGUMENTS && git push origin shq-v$ARGUMENTS`. The
   `socialhq-image.yml` workflow builds `ghcr.io/yoddha-social/socialhq:$ARGUMENTS`
   (exact version, never `latest`). Watch the run and report its result.
5. In `../socialhq-deploy`, prepare a branch that pins the image (see its README for the
   variable) and adds any new env variables to the examples. Back-up-before-deploy is
   the deploy script's job; don't skip it. Open a PR; don't deploy.
