# Suggestions for later (from the overnight build, 2026-11-05/06)

Ideas outside tonight's scope, for you to decide on. Nothing here is built.

## AI and providers
- **Mistral account limit.** Your Mistral key works, but Mistral answers every chat call
  with "Rate limit exceeded" and the headers show 0 requests per minute. In
  console.mistral.ai: check that a plan is active and look at Limits. Also switch
  Admin → Privacy → "Anonymous improvement data" off (our no-training rule). If the free
  plan only works with that switch on, Mistral can't be a free provider for us; on a paid
  plan it should move to the `-paid` groups in `socialhq-deploy/litellm/config.yaml`.
- **Paid Gemini key** (`GEMINI_PAID_API_KEY` in LiteLLM) unlocks image generation and
  embeddings. After setting it, add `image,embed` to `SOCIALHQ_AI_GATEWAY_TASKS`.
- **LiteLLM and Laravel share Redis database 0.** Harmless today; give LiteLLM its own
  database number (or its own Redis) before production so a `FLUSHDB` on one never wipes
  the other's queues or cooldowns.
- **Embeddings for the knowledge base**: pick a fixed model and set
  `LITELLM_EMBEDDING_DIMENSIONS` (≤ 2000 for an indexed pgvector column; Gemini's native
  3072 can't be indexed) before storing any vectors. The compose files use
  `postgres:16-alpine`, which has no pgvector; switch to `pgvector/pgvector:pg16`.

## Events dataset
- The two-day weekend (Saturday + Sunday off since April 2026, a fuel-saving measure) is
  not in the official holiday notice. Decide whether the planner should treat Sundays as
  holidays.
- BS 2084 dates (from mid-April 2027) aren't published yet. Import them when the Ministry
  of Home Affairs publishes the notice (usually Chaitra).
- Provincial holidays and Nepal Rastra Bank's bank-holiday list are not covered.
