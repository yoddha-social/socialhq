---
paths:
  - 'database/migrations/socialhq/**'
  - 'app/SocialHq/**/*.php'
---

# Social HQ tables

- Our tables are `shq_<plural>`, created from `database/migrations/socialhq/` (loaded
  by `SocialHqServiceProvider`). Models set `protected $table = 'shq_...'`.
- Migrations run on PostgreSQL (tests, production) and, while CI keeps the MySQL leg,
  on MySQL too. No engine-only column types or raw SQL; if one is unavoidable, branch
  on `DB::getDriverName()`.
- Workspace-owned rows carry `workspace_id` with a foreign key, and every query
  scopes by it. `.ai/rules/migrations.md` also applies.
- A column on one of TryPost's tables is fine when it belongs there: put the migration
  in `database/migrations/socialhq/` and list the table in PATCHES.md.
