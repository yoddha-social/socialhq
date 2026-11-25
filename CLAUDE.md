@AGENTS.md
@SOCIALHQ.md

# Social HQ

Social HQ is a **hard fork** of TryPost (owner decision 2026-11-25): TryPost's files,
AGENTS.md (above) included, are ours to change. We never merge TryPost's feature
releases; we only watch TryPost for security fixes and port them by hand (the
`socialhq-security-intake` skill). These rules win where AGENTS.md differs.

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
- Change a file from the TryPost base whenever that is the right place: there is no
  upstream merge to protect. **Transition rule:** until the five open branches have
  landed (p2-feedback, p2-time-zones, p2-events, p2-members, rbac-roles), keep adding a
  PATCHES.md row for each TryPost file you change. After that, PATCHES.md becomes a
  short map of where we diverged, by area, and rows stop.
- Interface copy: `lang/en/socialhq.php` only (the hook copies it to the other
  locales).

## Working here

- The laptop has no PHP. PHP tools run in Docker with TryPost's PHP image (see the
  socialhq-dev-env skill). If they couldn't run, say the PHP checks did not run;
  never report them as passing. Never develop, build or run tests on the production
  server.
- Our skills are the `socialhq-*` ones (canonical copies in `.ai/skills/`). The other
  skills in `.claude/skills/` are Boost's: a manual `boost:update` copies them again.
- Commits: no Co-Authored-By or AI attribution lines.
