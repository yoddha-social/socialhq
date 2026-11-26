---
paths:
  - 'database/migrations/socialhq/**'
  - 'app/SocialHq/**/*.php'
---

# Social HQ tables

- Our tables are `shq_<plural>`, created from `database/migrations/socialhq/` (loaded
  by `SocialHqServiceProvider`). Models set `protected $table = 'shq_...'`.
- Migrations run on PostgreSQL only (tests, CI, production). MySQL is not tested
  (AGENTS.md "Database engines").
- Workspace-owned rows carry `workspace_id` with a foreign key, and every query
  scopes by it. `.ai/rules/migrations.md` also applies.
- A column on one of TryPost's tables is fine when it belongs there: put the migration
  in `database/migrations/socialhq/` (no PATCHES.md row; say why in the commit).
