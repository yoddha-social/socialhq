---
name: socialhq-ai-features
description: Builds or changes an AI feature in Social HQ (calendar drafts, idea and caption suggestions, graphics, vision checks, embeddings) with the Laravel AI SDK, routed through the LiteLLM gateway, failing closed, logged and rate-limited. Use when touching app/SocialHq/**/ AI code, Blade prompts, config/ai.php or config/socialhq.php AI settings, or choosing a provider or model.
---

# AI features

Read Boost's `ai-sdk-development` skill for the SDK API, and AGENTS.md "AI agents"
and "AI language". This skill adds the Social HQ rules. `app/SocialHq/AiGraphics/` is
the worked example.

## Rules

1. **Agents implement `Laravel\Ai\Contracts\Agent`** (plus `HasStructuredOutput` when
   the result is data). One agent per job, inside the feature's folder.
2. **Prompts live in Blade** in a `socialhq/` folder under `resources/views/prompts/`; `instructions()`
   returns `view(...)->render()`. No `<<<PROMPT` heredocs or long strings in PHP.
3. **Language**: new text is written in `Locale::promptLanguage()` of the user the call
   runs for; rewrites keep the text's own language.
4. **Web only.** No REST endpoint or MCP tool calls an AI agent (AGENTS.md).
5. **Fail closed.** If a required check (safety review, validation) cannot run, the
   action does not happen and the user sees why. Never fall back to a provider outside
   the approved list.
6. **Validate before saving.** Ask for structured output, then check every record in
   code (dates in range, allowed platforms and formats, referenced events exist and are
   verified). Save only what passes, and keep the rejection reasons.
7. **AI never decides facts we can look up**, above all dates (see
   `socialhq-verified-events`).
8. **Log every attempt** in an `shq_` table: workspace, user, provider, model, status
   (`generated` / `blocked` / `failed`), reason, and tokens or cost when the provider
   reports them.
9. **Limits and access**: per-user and per-workspace limits in `config/socialhq.php`
   (env-overridable), a named rate limiter registered in the provider, and only people
   who can create posts in the workspace. AI output still goes through post approvals.
10. **Tests** fake the provider (SDK fakes), and cover the blocked, failed and
    check-unavailable paths, not only success.

## Routing (decided 2026-11-05)

Text, vision, image and embedding calls go through the LiteLLM gateway as an
OpenAI-compatible provider, by task model group: `shq-write`, `shq-structured`,
`shq-vision`, `shq-image`, `shq-embed`. LiteLLM tries free providers first (Groq
with zero data retention, then Cloudflare Workers AI, then Mistral with "Anonymous
improvement data" off; OpenRouter `:free` only with privacy filters; not Cerebras, whose
free tier became a card-gated trial) and falls back to paid Gemini, capped at $25 a month.
Free and paid deployments live in separate groups (`shq-write` falls back to
`shq-write-paid`): LiteLLM prices a group at its dearest deployment, so one paid
deployment in a free group would let a spent budget block the free providers too.

- **Never** a provider that trains on or human-reviews prompts: no Gemini free tier,
  Mistral only with its training opt-out on. `ApprovedProvidersGuard` refuses any SDK
  request to a provider outside `SOCIALHQ_AI_APPROVED_PROVIDERS`. Every provider in the chain is approved for company data, so there is
  no public/confidential split (the Rails reference had one; it is historical).
- Free image generation calls Cloudflare Workers AI directly (LiteLLM cannot route it).

**As built:** `app/SocialHq/Ai/`. An agent uses the `UsesAiTask` trait and returns its
`AiTask` from `aiTask()`; never pass `provider:` or `model:` to `prompt()`. Image and
embedding calls pass `AiRouter::route(AiTask::Image)` (or `Embed`) to `generate()`. A
new kind of job is a new `AiTask` case plus a LiteLLM group `shq-<task>`. Free images
through Cloudflare are not built yet: `shq-image` is paid Gemini only.
