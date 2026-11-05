# Overnight build, 2026-11-05 → 06

What changed, where, and how it was tested. Short on purpose; the details are in the
commits on `socialhq/overnight-2026-11-05`.

## Start here (2 minutes)

**What you can try now** (local stack, http://localhost:8000, log in with the test
admin from `trypost/local/test-account.env`):
1. **Plan › Events:** 193 official and international dates, all "Needs check".
   - Confirm a few. The AI plans only around confirmed dates.
   - The publish calendar now shows them on each day.
2. **Plan › Content plan:**
   - Pick a month and use **Generate with AI**: the AI drafts the month in about 15 to
     40 seconds.
   - Accept or drop suggestions, use **Brand settings** (the sliders icon) for pillars
     and posts per week, then **Send for review** and **Approve plan**.
   - December 2026 already has a live AI draft in both test workspaces.
3. **Open an item:**
   - **Write with AI** writes the copy. Send it for review, then approve it.
   - **Create brief**, then **Draft brief with AI**.
   - Upload a file, send the creative for review, approve it, then **Create post**: it
     opens the composer filled in.
4. **Plan › Production:** the board of briefs.
5. **Settings › Brand knowledge:** shows what reaches the AI, with "Always use" pins.

**Needs you:**
- **Merging:** PR [yoddha-social/socialhq#14](https://github.com/yoddha-social/socialhq/pull/14)
  (draft, into the checkpoint branch) and `socialhq-deploy` PR #1 (LiteLLM). Nothing
  went into `main`.
- **Mistral:** your account allows 0 requests a minute. Check the plan and Limits at
  console.mistral.ai, and switch off Admin › Privacy › "Anonymous improvement data".
  See SUGGESTIONS.md.
- **Deploy:** creative files live on the app's private storage, so keep
  `storage/app/private` on a persistent volume and add it to backups.
- **Translations:** new copy is English in all 16 locales.
- Everything else I'd do next is in `SUGGESTIONS.md`.

**Decisions I made for you** (change any of them):
- Members can plan, write copy and upload. Approving (plans, copy, creatives, brand
  dates) follows "who approves posts".
- Dates shared by every brand are changed only by the account owner, or by someone who
  approves in every brand.
- Starting work on an AI suggestion accepts it, so a new draft never deletes work.
- The AI may plan only around confirmed events; no dates in the past.
- Mistral sits in the free chain after Groq and Cloudflare, priced at zero. If your key
  is on a paid plan, move it to the paid groups.

## Branches
- `socialhq/checkpoint-2026-11-05`: everything that existed (open PRs #3, #5–#13
  combined, merge conflicts resolved). Pushed. Module + unit tests: 2070 passed. Full
  feature suite: 8736 passed, 1 failed: a TryPost billing test that expects pt-BR
  currency formatting the PHP image's ICU data doesn't give (`US$ 18,00` vs `$18.00`).
  Not touched by our changes; probably the ICU data in the image (not checked on main).
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
- **Events** (Plan › Events, `/socialhq/plan/events`):
  - every festival, holiday and day for the month or the next 90 days, with filters
    (Nepal, International, This brand only, Needs check);
  - "Import official dates" loads the 196 researched dates, all marked "Needs check";
    an admin confirms each one ("Confirmed by …"), edits it, deletes it or adds a
    brand-only date;
  - the publish calendar shows event chips on each day (dashed when not confirmed).
  - Tested: 17 tests.
  - Browser: imported 193 rows (3 overlap between the two files), confirmed one, saw the
    calendar chips. Research notes showed as descriptions, so the importer now shows
    readable notes and short source names.
- **Monthly content plan** (Plan › Content plan, `/socialhq/plan/2026-12`):
  - **Month view:** a month grid (or a list) with the brand's events on each day.
  - **Items:** each is a date, channel, format, pillar, owner, event and angle. Add, edit,
    drop or restore them in a side sheet.
  - **Generate with AI:** drafts the month from confirmed events, the brand profile and
    knowledge, pillars and cadence, the last 90 days of posts with their metrics, and the
    team's focus for the month. Suggestions show dashed with Accept/Drop.
  - **Review and approval:** "Send for review" and "Approve plan" (admins only, blocked
    while suggestions are open). Any change sends an approved plan back to review.
  - **Brand settings:** pillars and posts per week per channel.
  - **Safety rules in code:**
    - no dates outside the month or already past;
    - events only if confirmed, and only around their dates;
    - no posts on paused channels, and the format must fit the channel;
    - no duplicate titles;
    - one AI run at a time;
    - a late run never overwrites a newer one or an approval.
  - **Live test through LiteLLM (Groq):** 14 suggestions plus a summary; 1 refused with
    its reason.
  - **Three provider problems found live and fixed:**
    - Groq refuses structured answers whose schema leaves keys optional, so every key is
      now required, with "" meaning none.
    - Cloudflare stops at 256 tokens unless asked for more, so it now gets 8192.
    - 15 s per provider was too short for a month plan, so that group now gets 75 s.
  - **Also fixed:** the plan page had broken the sidebar's channel list.
- **Item page and production board** (`/socialhq/plan/items/{id}`, Plan › Production):
  - **Copy:** caption, hashtags, script and notes, saved as numbered versions (never
    overwritten), with history and a word-by-word compare. "Write with AI" and "Rewrite
    with AI" use the brand knowledge and open change requests.
  - **Review:** send for review → approve or request changes (a note is required) →
    approved copy is locked.
  - **Brief:** format prefilled from the channel's content type; on-screen text,
    direction and references; "Draft brief with AI" fills only empty fields.
  - **Creative rounds:** upload, send for review, approve or request changes; each new
    upload starts a new round.
  - **Create post:** opens TryPost's composer with the approved copy, the final files,
    the date and the channel. Posting still goes through TryPost's approvals.
  - **Production board:** five columns with drag and drop and a keyboard "Move to" menu.
    Only admins move cards into or out of Approved or Changes requested.
  - **Security fix from review:** creative files now go to private storage, never the
    public media bucket, and are served only to members, with the type taken from the
    file's content. **Deploy note:** that storage folder must be on a persistent volume.
  - Browser: wrote copy with AI (it used the brand's price and delivery facts), approved
    it, created and AI-drafted the brief, saw it on the board.
- **Cross-cutting review** (whole night's diff, with fresh eyes):
  - a new AI draft no longer deletes copy, briefs or uploads already started on a
    suggestion, because starting work accepts it;
  - deleting a brand deletes its creative files;
  - an item stays editable after its event is deleted or its owner leaves;
  - dates every brand shares can only be changed by the account owner or someone who
    approves in every brand;
  - brief uploads are rate-limited.
  - Checked and fine: migrations on PostgreSQL and MySQL, route order and middleware,
    shared-prop cost, job timeouts against Horizon, and that the AI guard breaks no
    TryPost feature.
- **Polish and performance feedback:**
  - **Production board:** the five columns fit a desktop screen and run full height.
  - **Calendar:** event chips become a dot and count in narrow day cells.
  - **Phones:** the plan's grid/list switch moves next to the month.
  - **Item page:** clearer "About" select and blocked "Create post".
  - **Member view:**
    - the board hides moves a member can't make and names who can;
    - one-brand approvers see who manages the shared dates;
    - tests check each page's abilities for a member, an admin and the owner.
  - **Copy budget:** 124 button, tab and badge labels are now under the single-line
    copy test.
  - **"What worked lately"** (month page, collapsed by default): the brand's best and
    weakest posts of the last 90 days by engagement (falling back to reach, then views),
    and the best format. It uses the same query the AI planner uses, so people and the
    AI see the same numbers.
  - Browser: checked the board, calendar and panel on the trial data.
