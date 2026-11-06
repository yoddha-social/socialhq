---
name: socialhq-feature-brief
description: Writes a one-page brief for a Social HQ feature before any code - who it is for on the team, the job it does, the smallest useful slice, what TryPost already covers, and the risks - and slices it into PRs.
disable-model-invocation: true
argument-hint: "<feature idea>"
---

# Feature brief: $ARGUMENTS

Social HQ is an internal tool for three or four people. A brief is short: aim for a
page. Ask the user only what you can't find in the code or SOCIALHQ.md.

## The brief

1. **Who and when.** Which person on the team (lead, calendar manager, script writer,
   designer, video editor), at what moment in their month.
2. **The job.** What they do today without it, and what changes.
3. **Smallest useful slice.** The first version someone would use next week.
4. **What TryPost already does** here (check the code and AGENTS.md), and what we add.
5. **Shape.** Tables (`shq_*`), screens, AI calls (`socialhq-ai-features`), TryPost
   files to change (each one a PATCHES.md row).
6. **Not doing.** At least two things, said plainly.
7. **Risks.** Wrong dates, data sent to the wrong provider, posts published without
   approval, cost. One line each, with the guard.
8. **Done when.** What the user can do, and the tests that prove it.

## Slicing

One PR per slice, each shippable alone: schema and model first, then the action with
tests, then the screen, then AI.

Save the brief only if the user asks; otherwise give it in the reply.
