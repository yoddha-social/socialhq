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

## Planning layer, next steps
- **Email notifications for reviews** (copy or creative: changes requested, approved)
  aren't built; the thread shows them in the app only. Use `SendNotification` with
  `Type::Collaboration` and Maizzle emails in all 16 locales, like the approval emails.
- **Real translations.** All new copy (`socialhq*.php`, the sign-up emails in
  `mail.php`) is English in every locale. Translate before non-English users arrive.
- **The composer can't take a content type** from "Create post"; it works it out from
  the media (a reel planned as a reel opens as a video post). A small change to
  `ComposerInitialDraft` would carry it.
- **Linking the post back to the item.** "Create post" marks the item, but the composer
  doesn't tell us which post was created, so the item can't show the post's status or
  metrics. Pass the item id through the composer and store it on the post (or in a
  `shq_item_posts` table) to close the loop, and let "What worked lately" group by pillar.
- **Videos stream without range requests**, so seeking in long drafts is limited; serve
  brief files with `Storage::response()` range support or through signed URLs on a
  private R2 bucket.
- **The production board loads every brief at once**; add paging per column (like the
  ideas board) when there are more than a few hundred.
- **An AI attempt log** (`shq_ai_runs`: task, provider, model, tokens, outcome) would
  help debug provider failures; today only LiteLLM's spend logs and the app log have it.
- **Deploy:** brief files live on the app's private `local` disk; make sure
  `storage/app/private` is on a persistent volume (or move it to a private R2 bucket).
- **Calendar drag and drop** on the plan grid (the move endpoint exists; the UI uses the
  date field in the side sheet).
