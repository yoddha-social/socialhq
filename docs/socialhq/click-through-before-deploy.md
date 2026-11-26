# Click-through before each deploy (10 minutes)

Automated checks cover the server well, but only a few Social HQ screens are
clicked through by a browser test (audit 2026-11-24, CQ-02). Until more are,
run this list by hand on the release before it is deployed: the local stack on
the release commit, signed in as a workspace admin of a test workspace. Use a
test channel or drafts only; nothing here should publish.

Tick each line. If a step fails, stop the release, write down what you clicked
and what you saw, and add a check for it before the fix (AGENTS.md, "Browser
Tests").

## Plan (4 minutes)

1. **Plan › this month.** The month opens with its items and the event markers.
   Switch to the list view and back: same items, no error.
2. **Add an item** (any channel, a coming day), save, then open it in the side
   sheet and **move it to another day**. It shows on the new day without a reload.
3. **Drop the item**, then restore it. With a post attached, the dialog asks
   "Move it to drafts" or "Keep it scheduled".
4. **Open an item's page.** Write a copy version, send it for review, approve
   it. The status follows each step; "Create post" opens the composer with the
   copy, the date and the channel. Close the composer without saving: the item
   is unchanged.

## Events and production (2 minutes)

5. **Plan › Events.** The list loads; filter by a country; confirm one date
   that "Needs check". It turns confirmed.
6. **Plan › Production.** Drag a card to the next column. It stays there after a
   reload. Its "Move to" menu works too.

## Brand and settings (2 minutes)

7. **Settings › Content › Brand knowledge.** The overview's status says what is
   set up ("4 of 6 set up" or "Looking good") and its link opens the next step.
   Add a knowledge entry and delete it.
8. **Settings › AI.** The keys list loads; "Test" on a key answers. Settings › AI
   › Usage shows today's requests.
9. **A channel's page** (Settings › Channels › a channel). The tiles load. "Copy
   link" adds a row under "Links still open", and "Cancel link" removes it.

## Publish and the rest (2 minutes)

10. **Publish.** Open the composer, write a draft for the test channel with a
    date next week, "Save draft". It shows in Drafts.
11. **Calendar.** Month and week views load with the event markers. Drag the
    draft from step 10 to another day: it stays there after a reload. Delete it.
12. **The bell and "Waiting for you".** Both open. The user menu's Help shows
    "Source code (AGPL-3.0)". Sign out and back in.

Last reviewed: 2026-11-26. Change this list when a screen it names changes.
