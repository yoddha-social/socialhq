@AGENTS.md
@SOCIALHQ.md

# Social HQ

Social HQ is built on a fork of TryPost. We own all of it now: AGENTS.md (above) came
with TryPost and is ours to edit. These rules win where the two differ.

## What is true

Specs and handoff notes from before the fork are often out of date. When sources
disagree, trust them in this order: the code on the current branch, then
SOCIALHQ.md and PATCHES.md, then everything else. In particular these are
**historical**: Social HQ as a separate Rails + React app, "talk to TryPost only
through its REST API", "run TryPost unmodified", the Oracle server, and
public/confidential LiteLLM model groups. If a doc you read contradicts the code,
say so instead of following the doc.

## Where code goes

- New features go in the module paths in SOCIALHQ.md (`app/SocialHq/`,
  `database/migrations/socialhq/` with `shq_` tables, `routes/socialhq.php`,
  `config/socialhq.php`, `resources/js/{pages,components}/socialhq/`,
  `tests/*/SocialHq/`), wired from `SocialHqServiceProvider`.
- Change a file from the TryPost base when that is the right place, and add a line to
  PATCHES.md. The list tells the monthly security intake (`socialhq-security-intake`)
  where TryPost's fixes may conflict with ours.
- Interface copy: `lang/en/socialhq.php` only (the hook copies it to the other
  locales).

## Working here

- The laptop has no PHP. PHP tools run on the dev box or in the `app` container of
  compose.yaml (see the socialhq-dev-env skill). If neither is available, say the
  PHP checks did not run; never report them as passing.
- Our skills are the `socialhq-*` ones (canonical copies in `.ai/skills/`). The other
  skills in `.claude/skills/` are Boost's: a manual `boost:update` copies them again.
- Commits: no Co-Authored-By or AI attribution lines.
