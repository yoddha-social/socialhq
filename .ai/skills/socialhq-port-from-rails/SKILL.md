---
name: socialhq-port-from-rails
description: Ports one feature from the retired Rails + React prototype (socialhq-rails-reference) into the Laravel fork, keeping its behaviour and invariants but not its structure or its out-of-date decisions.
disable-model-invocation: true
argument-hint: "<feature, e.g. verified events>"
---

# Port a feature from the Rails reference: $ARGUMENTS

The reference is at `../socialhq-rails-reference/` (`api/` Rails, `web/` React). It is
a source of behaviour and tests, not of architecture or decisions.

## Steps

1. **Read the reference**: the models, services, controllers, React pages and tests for
   "$ARGUMENTS". List its behaviours and invariants (validations, state changes, checks
   that block an action, what is logged) in a few lines each.
2. **Check what is newer.** For each invariant, check it against: the fork's code,
   CLAUDE.md "What is true", the dated decisions in memory, and SOCIALHQ.md. Mark
   anything the fork or a later decision replaced (for example the
   public/confidential AI split, the REST hand-off to TryPost, role names).
3. **Check what TryPost already does.** Grep `app/Models`, `app/Actions`,
   `resources/js/pages` and AGENTS.md. Map each behaviour to: reuse what TryPost has, extend it,
   or build it in the module (`socialhq-module`).
4. **Show the user a short plan** (behaviours kept, dropped and why, tables, screens,
   TryPost files changed, if any) and wait for a yes.
5. **Build**, then port the reference tests as Pest tests, including the failure paths.
6. **Update** SOCIALHQ.md and the matching `socialhq-*` skill's "Status" line.

## Translation table

| Rails reference | Fork |
|---|---|
| `org` | `workspace` (or a shared table; decide per feature) |
| Roles admin / lead / planner / writer / designer | Workspace `is_admin`, `requires_approval`, policies; no role enum (AGENTS.md) |
| Rails model | Eloquent model in `app/SocialHq/<Feature>/`, table `shq_*` |
| Service object | Action class (TryPost style: `app/Actions`), in the module |
| Solid Queue job | Laravel queued job on Horizon |
| JSON controller + React page | Controller + Inertia Vue page; shared UI components |
| `LitellmClient` with `-confidential` groups | Laravel AI SDK agent; task groups in `socialhq-ai-features` |
| TryPost REST client, publish attempts, poller | Not needed: call TryPost's actions directly |
| Minitest | Pest |
