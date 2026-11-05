# Overnight build, 2026-11-05 → 06

What changed, where, and how it was tested. Short on purpose; the details are in the
commits on `socialhq/overnight-2026-11-05`.

## Branches
- `socialhq/checkpoint-2026-11-05`: everything that existed (open PRs #3, #5–#13
  combined, merge conflicts resolved). Pushed. Module + unit tests: 2070 passed.
- `socialhq/overnight-2026-11-05`: tonight's work, on top of the checkpoint.

## Log
- **Checkpoint.** Merged sign-up (#11) into the AI/rebrand stack (#13 → #6), then the
  time zone (#5) and temperature (#3) fixes. Notable merge decisions:
  - one `Inertia::share('socialhq')` now holds approvals, AI graphics and sign-up (two
    shares would have overwritten each other);
  - the temperature fix now follows each agent's AI route, not the default provider.
- **AI routing fixes** (review of #10):
  - every SDK request to a provider outside the approved list is refused before it is
    sent, whoever makes it (`ApprovedProvidersGuard`);
  - the LiteLLM gateway serves only the tasks listed in `SOCIALHQ_AI_GATEWAY_TASKS`
    (default write, structured, vision), so image generation stays hidden until the
    paid Gemini key exists;
  - the image shape follows the providers the route really uses.
  - Tested: router tests plus all 206 of TryPost's AI test files (4932 passed).
- **LiteLLM config** (`socialhq-deploy`, pushed to its PR #1):
  - free and paid groups per task, so a spent $25 budget no longer blocks the free
    providers;
  - 15 s timeouts on free providers;
  - Mistral added to the free chain;
  - the key script replaces the old key, and the deploy check looks for the right
    Gemini variable.
  - Tested live on the local stack: free groups cost 0 and the paid groups are priced;
    Groq and Cloudflare answer; Mistral rate-limited (your account, see SUGGESTIONS.md).
- **Planning layer foundation:** tables, models, factories, access rules, the sidebar
  "Plan" item and tabs; the official BS 2083 holiday list (91 dates) and 105
  international days as CSV with a source per row.
