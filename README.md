# Social HQ

Social HQ is where our social media team does its work: plan the month, write
captions and creative briefs with AI, review and approve, publish to every channel,
and see what worked. It is an internal tool, built and run by
[yoddha-social](https://github.com/yoddha-social).

## What it does

**Brand knowledge.** The AI reads the company's website and suggests the brand
profile (what we sell, audience, voice) and knowledge entries (products, key facts);
nothing is saved until someone accepts it. Add documents and Google Docs, old
calendars, the brand look (logo, colours, fonts), content pillars, posts per week,
where the audience is and SEO keywords. This is what the AI reads before it plans
or writes anything.

**Month plan.** One click drafts a month of posts from the brand profile, the
pillars, past posts and a verified events calendar (Nepali festivals from the official
holiday list and international days; dates are never calculated by AI). The lead
accepts or drops each suggestion; every item has a date, time, channel, format,
pillar and objective.

**Writing and production.** For each plan item the AI writes the caption in the
brand voice and drafts the design brief (format, on-screen text, art direction).
Captions keep their versions; designers upload creatives in rounds.

**Review and approval.** Copy and creative are reviewed separately. Reviewers
comment, request changes or approve, and everything waiting on someone shows up in
their *Waiting for you* inbox. Approved work is locked. Members who aren't admins,
and every post made through an API key or MCP, always go to review.

**Publishing.** An approved item becomes a post in the composer, with its caption,
creative and time filled in, and is scheduled for its channel. Plan and posts stay
linked: moving or dropping an item offers to move or hold its post too. Channels:
Facebook Pages, Instagram (through a Facebook Page), LinkedIn, YouTube and X.

**Insights.** Followers, reactions, comments and engagement per channel and per
post, compared with the previous period.

**Companies and workspaces.** A company signs up and waits for a platform admin to
approve it. Each brand gets a workspace with its own channels, members and settings.

## AI

Every AI call goes through a [LiteLLM](https://github.com/BerriAI/litellm) gateway,
named by task (write, structured, vision, image, embed):

- Free providers first (Groq, Cloudflare Workers AI, Mistral), paid Gemini only as
  the fallback, under a monthly budget.
- The platform's keys only reach approved providers that don't train on prompts;
  any other request is refused before it is sent.
- Every call is logged without its content (Settings › AI › Usage).
- A company can bring its own provider keys (Settings › AI).

Details: [SOCIALHQ.md](SOCIALHQ.md#ai-routing).

## Built with

Laravel 13 (PHP 8.4+), Inertia v3 with Vue 3, Tailwind CSS 4, PostgreSQL, Redis with
Horizon, Reverb for live updates, the Laravel AI SDK and LiteLLM.

## Working on it

| Read | For |
|---|---|
| [SOCIALHQ.md](SOCIALHQ.md) | Where our code lives, AI routing, design decisions |
| [AGENTS.md](AGENTS.md) and [CLAUDE.md](CLAUDE.md) | Conventions (Laravel Boost, Pest, Pint, Inertia, Wayfinder) |
| [PATCHES.md](PATCHES.md) | The security watch, and how to see what we changed in TryPost's files |
| [.ai/skills/socialhq-dev-env/SKILL.md](.ai/skills/socialhq-dev-env/SKILL.md) | Running PHP, tests and linters |

```bash
./vendor/bin/pest tests/Feature/SocialHq --compact   # our tests
./vendor/bin/pint --dirty                            # format changed PHP
npx --no-install vue-tsc --noEmit                    # types
```

CI (`.github/workflows/socialhq-ci.yml`) runs quality checks, the backend and
browser suites on PostgreSQL, and TryPost's suites under our production rules. Pull
requests merge into `main` once the `ci` check passes.

Deployment lives in a separate private repository,
[yoddha-social/socialhq-deploy](https://github.com/yoddha-social/socialhq-deploy).

## Origin and license

Social HQ started as a hard fork of [TryPost](https://github.com/trypostit/trypost)
v2.0.1, the open-source social media scheduler by Paulo Castellano. TryPost gave us
the workspaces, members, composer, calendar, media handling and publishing. We don't
merge TryPost releases; we review its security fixes and port them by hand
([PATCHES.md](PATCHES.md#security-intake)).

Social HQ is licensed under the [GNU Affero General Public License v3.0](LICENSE.md),
like TryPost. If you run a modified version as a network service, you must offer
its users the source (AGPL §13); the app's sidebar links to this repository.
