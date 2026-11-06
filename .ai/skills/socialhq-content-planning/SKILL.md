---
name: socialhq-content-planning
description: Builds the content planning layer of Social HQ on top of TryPost: brand profiles, the AI monthly calendar draft, ideas to scripts to creative briefs, and the review and approval flow. Use when working on monthly plans, calendar items, content pillars, brand voice, scripts, captions, briefs, production tasks, or how planned content becomes TryPost posts.
---

# Content planning

The team: a lead, a calendar manager, script writers, graphics and video people;
three or four of them on social media directly. The flow to support:

monthly calendar draft → ideas → scripts and captions → graphic and video briefs →
production → TryPost post → approval → publishing → performance back into the next plan.

**Status:** AI graphics are built. The calendar planner, scripts and briefs are not:
port the planner from `../socialhq-rails-reference/api/app/services/calendar_planner.rb`
with `socialhq-port-from-rails`.

## Reuse TryPost, don't rebuild it

| Need | TryPost already has | Social HQ adds |
|---|---|---|
| Brand | A workspace per brand (confirm with the user) | An `shq_` brand profile: audience, voice, pillars, posts per week per platform |
| Backlog | `Idea`, `IdeaStage` | AI suggestions into the same ideas |
| Approval | `App\Support\PostApproval`, `requires_approval` members | Nothing parallel. Review of scripts and briefs, if needed, is a separate `shq_` flow |
| Publishing | Posts, channels, calendar | Planned items that become posts |
| Performance | Analytics snapshots | Feeding them into the planner's context |

## The planner's contract (from the reference)

- Input: the month, the brands with their platforms and pillars, **verified** events
  overlapping the month, items already planned, and history (the same month last year
  first, then the last three months).
- Output: structured records `date, brand, platform, format, pillar, title, objective,
  brief, event_key`, plus a short summary of the month.
- Every record is checked in code: date inside the month, the brand posts on that
  platform, the format suits the platform, the event exists and is verified. Rejected
  records are dropped with a reason.
- Accepted records are saved as **suggestions** a person accepts or drops. Nothing the
  AI drafts is approved by itself.
- An edited approved month goes back to review.

AI rules (routing, logging, fail closed): `socialhq-ai-features`.

## Out of scope

Employee management (the company's NepalHRM covers it), a social inbox (Vista Social
has one), analytics dashboards beyond TryPost's, a media editor.
