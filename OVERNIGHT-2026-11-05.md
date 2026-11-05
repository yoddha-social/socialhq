# Overnight build, 2026-11-05 → 06

What changed, where, and how it was tested. Short on purpose; the details are in the
commits on `socialhq/overnight-2026-11-05`.

## Branches
- `socialhq/checkpoint-2026-11-05`: everything that existed (open PRs #3, #5–#13
  combined, merge conflicts resolved). Pushed. Module + unit tests: 2070 passed. Full
  feature suite: 8736 passed, 1 failed: a TryPost billing test that expects pt-BR
  currency formatting the PHP image's ICU data doesn't give (`US$ 18,00` vs `$18.00`).
  Not ours; it fails the same way on the base.
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
- **Sign-up fixes** (review of #11), all 11 findings:
  - a Google/GitHub sign-in takes an unconfirmed registration away from whoever set its
    password (password replaced, their sessions ended);
  - approve/decline can't race;
  - the register form no longer reveals which emails have accounts, and it is
    rate-limited;
  - the team account is found without the env setting;
  - waiting people can accept invites;
  - declined people's files are deleted;
  - Members shows a banner for waiting requests;
  - the emails are rebuilt with the Social HQ header.
  - **A second review found three more gaps, now closed:** a password reset ends the
    other sessions and confirms the email; Google/GitHub emails are matched whatever
    their capitals; already-accepted invites don't skip approval.
  - Tested: sign-up 33 tests + TryPost auth tests, 172 passed.
- **Knowledge base fixes** (review of #12), all 12 findings:
  - the profile can use at most half of the AI prompt space, so entries always get in;
  - each entry shows whether it reaches AI prompts, and a meter shows the space used;
  - "Always include" pins an entry;
  - Nepali text counts double, because it uses more of the AI's budget;
  - errors show next to their fields, with accessible labels, and the list style is
    fixed;
  - members see a read-only page that says who can edit it.
  - Tested: 13 tests (7 new).
