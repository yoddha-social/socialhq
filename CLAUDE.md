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
SOCIALHQ.md, then everything else. The frozen change ledger
(`docs/history/PATCHES-ledger-2026-11-25.md`) says why a change was made then, not
what is true now. In particular these are
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
  upstream merge to protect. **No PATCHES.md rows** (frozen 2026-11-25): write the
  reason in the commit message, and git answers what we changed
  (`git log v2.0.1..main -- <path>`, see PATCHES.md). A branch that still adds rows
  drops them when it merges.
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
