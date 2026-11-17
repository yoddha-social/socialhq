# TryPost's upstream-only agent rules

These sections came with TryPost's `AGENTS.md`. They cover TryPost Cloud: Stripe
billing and offers, plans and the workspace limit, PostHog person properties and
its public docs. Social HQ is a self-hosted internal tool, so they don't apply to
our work and were moved here to keep them out of every agent session
(`CLAUDE.md` imports `AGENTS.md`). The code they describe is still in the fork;
read this file when you touch it (for example during a security intake).

## Stripe Checkout (offers)

Everything that is not the full price is an offer (owner decision October 2026): the discount, the trial and the promotion code field live on the `offers` row (see "Offers"), never in env or in controllers. All of it goes through `App\Support\Billing\ConfigureSubscriptionCheckout` (called from `StartSubscriptionCheckout`).

There is no checkout env (owner decision October 2026; `REQUIRE_CARD_FOR_TRIAL` and `CASHIER_TRIAL_DAYS` were removed, so a forgotten env value can never override an offer). An account has app access with a subscription, or during a generic trial an offer started (see below). Signup alone never grants a trial.

Standing constraints:
- Without an offer the checkout charges the full price at once, with no coupon and no trial, and shows the promotion code field (owner decision October 2026), so a code from support or a partner still works. With an offer, the field shows only when its row has `allow_promotion_codes`.
- An offer applies what its row sets: `stripe_coupon_id` (the discount), `trial_days` (clamped to Stripe's 2-day minimum), `allow_promotion_codes` and `requires_card`. Stripe rejects a coupon and promotion codes on one session but accepts a trial with either, so a coupon wins and the field is left off; a trial goes with both.
- There are no fm1 coupon or promotion code envs any more (`STRIPE_*_FIRST_MONTH_COUPON_ID` and `CASHIER_ALLOW_PROMOTION_CODES` were removed in October 2026). Every offer reaches an account through its link: the 7-day trial (`ft7d`, `trial_days` 8 because Stripe Checkout counts the trial one day short and shows 8 as 7 days, `first_subscription_only`), the 7-day trial without a card (`ft7dnc`, `first_subscription_only`, so a former customer can never take free days again, `trial_days` 7: it never opens Stripe, so the generic trial lasts exactly what the row says), the `$1` first month (`fm1`, `first_subscription_only`) and Black Friday (`bf26`). There is no default offer: a signup without a link pays the full price. Do not reintroduce a checkout env.
- First-subscription offers skip an account with a prior real subscription (`incomplete` / `incomplete_expired` still qualify).
- An owner without app access is always sent to the plan step (`EnsureAccountReady`, the MCP step, the old subscribe route): it sends them back to the first unanswered step, so a new owner still starts at persona while a former customer lands on the plan directly.
- Welcome checkout is the last Welcome step, the plan step (`app.welcome.plan.store`): it shows only **Socials**, monthly, and always opens Stripe Checkout on the **Socials monthly** price. Offers apply there and, coupons only, at **Subscribe now** on billing. Yearly and Workspaces stay on the billing change-plan picker for existing subscribers (no offer).
- An offer with `requires_card = false` and `trial_days` (`Offer::startsWithoutCard()`) never opens Stripe: the Welcome plan step starts Cashier's generic trial (`StartGenericTrial`: `accounts.trial_ends_at`, the Socials `plan_id`, the redemption, `trial.started`) and goes on to the MCP step (owner decision October 2026). The account has app access while it lasts (`Account::hasAppAccess()` = subscribed or `onGenericTrial()`), billing shows the trial and **Subscribe now** (`app.billing.checkout`), a Checkout on the plan's monthly price charged at once, without carrying the days left of the trial (owner decision October 2026), applies a coupon offer claimed meanwhile (never a trial) and returns to billing after paying (`app.billing.processing?from=billing`, read through `CheckoutOrigin`). Changing plan during the trial (the workspace upgrade picker; billing shows only Subscribe now) opens the same Checkout on the picked plan and interval, under the same `swapPlan` rules, which allow a generic trial. Only Socials monthly gets an offer (`StartSubscriptionCheckout::qualifiesForOffer()`). An old subscription ending during the trial leaves its plan alone, and a second submit of the no-card trial starts nothing. The Welcome no-card trial goes through Processing too (`from=welcome`, ready at once), so phones skip the MCP step like a paid checkout. Cashier's `customer.subscription.created` handler clears `trial_ends_at`. When the trial ends without a subscription, access stops and the owner is back at Welcome. Every other offer goes through Stripe Checkout and asks for a card.

## Plans and the workspace limit

TryPost sells two plans. Both are flat: Stripe subscription **quantity is never
used** — `syncWorkspaceQuantity()` was removed with the per-workspace model.

| slug | name | price | workspace_limit |
| --- | --- | --- | --- |
| `socials` | Socials | $19/mo, $190/yr | 1 |
| `workspaces` | Workspaces | $99/mo, $990/yr | `null` (unlimited) |
| `workspace` | Workspace (legacy, archived) | $12/mo per workspace | 1 |

- The cap lives in `plans.workspace_limit`, **not** in code. `null` on that
  column means unlimited. Read it through `Account::workspaceLimit()` /
  `Account::canCreateWorkspace()` — never compare `plan->slug` to decide what
  an account may do. A **missing** `plan_id` is not unlimited: it may create
  only the signup workspace (`count === 0`).
- `WorkspacePolicy::create()` is owner-only. The cap is not a permission and the
  server decides it, never the browser's shared props (owner decision October
  2026), through `User::atWorkspaceLimit()` (`workspaceLimitApplies()`: never
  self-hosted, never the first workspace).
  - Every "new workspace" entry point (sidebar workspace menu, workspaces list)
    asks first with a non-navigating `useHttp` POST to
    `app.workspaces.creation-check.store` (`useWorkspaceLimit().createOrUpgrade`).
    `{ allowed: false }` opens the `WorkspaceUpgradeDialog` in place, on the page
    the user is on; `allowed: true` (or a failed check) visits
    `app.workspaces.create`. A refused check tracks `workspace.limit_hit`.
  - GET `/workspaces/create` is never shown at the cap: it tracks
    `workspace.limit_hit` and redirects to `app.workspaces.index` with
    `workspaces.limit_reached`. Without a subscription it still goes to billing.
  - POST at the cap is refused the same way (to the list, not 403'd, never to create),
    tracked once by `store` itself after its transaction.
- Welcome checks out Socials monthly only, so an offer's price is always about
  Socials monthly. Billing keeps yearly and Workspaces for subscribers changing
  plan.
- The legacy plan is archived: it never appears in the picker, so nobody can move
  back to it. Its `workspace_limit` is 1 because it costs less than Socials.
- Welcome's plan step (`app.welcome.plan`, after the current-tool step) shows
  only Socials (owner decision October 2026): new owners always start on
  Socials. The same `PlanPicker` drives upgrade/downgrade on the billing page
  (`app.billing.change-plan`).
  A change is a `swap()` to another price id. A swap to a yearly price drops the
  subscription's discount (`ChangePlan`, `discounts: ''`; owner decision October
  2026): an offer's coupon would otherwise take its percentage off a whole year.
  A monthly swap keeps it, so an owner who upgrades mid-offer keeps what is left. `accounts.plan_id` is written by
  `changePlan` after a successful `swap()` only when the subscription is
  `active` or `trialing` (so create works before the webhook). Welcome checkout
  never writes it — `StartSubscriptionCheckout` **clears** a leftover `plan_id`
  so Processing cannot treat a stale Workspaces row as paid. The
  `customer.subscription.created` / `updated` webhook writes on `active` /
  `trialing`, **clears** on `unpaid` / `canceled` / `incomplete_expired`, and
  leaves `past_due` / `incomplete` alone. `deleted` always clears.

## Offers (`?offer=` links)

An offer is anything the Welcome checkout gives instead of the full price
(owner decision October 2026): a Stripe coupon, a trial, the promotion code
field, or a mix. The 7-day trial (`ft7d`), the `$1` first month
(`fm1`) and campaigns such as Black Friday (`bf26`) are all offers. It
lives in `offers` (`slug` and `starts_at` required; `stripe_coupon_id`,
`trial_days`, `ends_at` and `max_redemptions` nullable: no coupon, no trial, no
end, no limit; `allow_promotion_codes`, `first_subscription_only` and
`show_banner` false by default; `requires_card` true by default) and reaches an account through the pivot
`account_offer` (`claimed_at`, `redeemed_at`).

- **Capture.** `CaptureOffer` (web middleware) reads `?offer=<slug>` on any page.
  A signed-in owner claims it at once (`ClaimOffer`). A guest's slug waits in the
  session, only when it names an available offer, and the first authenticated
  request claims it, so email signup, Google, GitHub and login need no code of
  their own; moving between register and login keeps it. Slugs match exactly on
  both engines through `Offer::findAvailable()` (MySQL compares without case). Ads and landing pages link to `/register?offer=<slug>`, win-back emails to
  `/login?offer=<slug>`. There is no offer page or route.
- **Who gets it.** Only an account owner whose account has no active
  subscription: new signups and former customers alike, unless the offer is
  `first_subscription_only`, which skips an account that was ever billed or
  already redeemed an offer, a no-card trial included
  (`Account::isFirstTimeSubscriber()`; Cashier clears `trial_ends_at` on any new
  subscription, so the redemption is the lasting record), so trials and
  the `$1` month never stack. `Offer::scopeAvailableTo()` holds both rules
  and drops offers the account redeemed. An unknown, not yet started, ended or
  full offer is ignored silently and nothing is kept for later. A redeemed offer
  is never offered to that account again.
- **The `offers` row is the only source of truth for dates and places.** The
  Stripe coupon has no `redeem_by` and no `max_redemptions`. Only redemptions use
  up a place (`Offer::scopeAvailable`), never a claim; two checkouts racing for the
  last place may both get it (accepted).
- A trial means `trial_days > 0` (`Offer::hasTrial()`); `0` is no trial.
- **Checkout.** `Account::currentOffer()` picks the latest claim the account may
  still use, else none (full price).
  `ConfigureSubscriptionCheckout::apply()` applies it. Validity is checked again
  when checkout starts. The controller resolves the offer once (`Account::currentOffer()`) and
  hands the same one to `StartSubscriptionCheckout` and `apply()`, so the coupon,
  the metadata and the tracked events agree. The offer id and slug go in the
  subscription metadata (`trypost_offer_id`, `trypost_offer_slug`) and the slug in
  the session metadata; the `customer.subscription.created` / `updated` webhook
  writes `redeemed_at` (`RedeemOffer`) only once the subscription is `active` or
  `trialing`, so an incomplete checkout never uses up a place. Offers apply at the
  Welcome checkout (Socials monthly) and, coupons only, at Subscribe now on
  billing (`currentOffer(withTrial: false)`).
- **Revenue is counted once.** `conversion_*` goes on the event of the charge: `checkout.completed` for a Checkout charged at once, `trial.converted` for a trial charged when it ends. A trial without a card that subscribes active sends both events, and its `trial.converted` carries no `conversion_*` (`TrackTrialConverted` `withRevenue: false`).
- **Tracking.** `checkout.started` is sent by `StartSubscriptionCheckout` itself, on the owner, for every Checkout (Welcome, Subscribe now, plan change), never by a controller. The offer goes to PostHog only with the checkout, as the property
  `offer` holding its slug (`bf26`, `ft7d`, null without one) on
  `checkout.started`, `checkout.completed`, `checkout.expired` and the account
  group (set on subscription creation). There is no offer event of its own. The old `is_first_month_offer`,
  `first_month_coupon_id` and `first_month_offer_ends_at` are gone.
- **Copy.** Each offer's copy lives in `lang/<locale>/offers.php` under the
  slug with dashes as underscores (`free-trial-14-days` → `free_trial_14_days`; the short slugs `ft7d`, `fm1`, `ft7dnc` and `bf26` keep theirs): `name` (the
  banner's tag), `headline` (the banner's sentence), `price`, `period` and an
  optional `unit` shown next to the price (`/month` for Black Friday; a trial or
  the `$1` first month has none, so their copy decides it, never the code), in all
  16 locales, plus an optional `terms` for the line under the button (a trial
  without a card says no card is needed; without it the line is
  `welcome.plan_terms`, "Then :price/month"); the price is display text per locale, like
  `billing.subscribe.prices.socials.monthly`. Nothing about the discount is
  stored in the database, and the banner (`name`, `headline`) is optional: an
  offer has both or neither, while `price`, `period` and `checkout_button` are required
  (`OfferCopyTest`). Each trial length is its own offer with its own copy
  (`ft7d`, `free-trial-14-days`), so several can run at once. The page has no fallback language, so `OfferResource`
  checks each key in the current locale only. The Welcome plan step shows the
  banner (a `bg-foreground` pill, the name in a `bg-background` uppercase tag) only when the row has `show_banner` and `name` and `headline` exist, and the offer price only when `price` and `period` exist; it never
  prints a raw key. The button that opens Stripe Checkout is
  the offer's `checkout_button` (required, like `price` and `period`), or
  `welcome.plan_checkout_button` without an offer; without an offer the step
  shows the full price.
  `tests/Feature/Offer/OfferCopyTest.php` fails when an offer misses its copy in
  any locale.
- **Adding an offer.** Create its coupon in Stripe when it has one (no dates, no
  limit), add its
  copy to all 16 locales and deploy, then insert the `offers` row in each
  environment's database by hand (owner decision October 2026: no data
  migration, since the coupon id differs between Stripe test and live). Locally,
  `php artisan db:seed --class=OfferSeeder` adds the test-mode offers; it is not
  part of `DatabaseSeeder`, so tests never start with them.

## PostHog person properties

`App\Jobs\PostHog\SyncUser` writes the user's person properties (two narrower
jobs below add one each), and the distinction between its two buckets is
load-bearing:

- **`$set_once`** — first-touch facts that must never be rewritten: `signed_up_at`
  and the attribution keys (`utm_*`, `gclid`, `fbclid`, …). A later sync must not
  overwrite where a user originally came from.
- **Top level** — current state, overwritten on every sync: `$email`, `$name`, and
  `locale`.

The only other jobs that write person properties are `IdentifyConnectedPlatforms`
(`connected_platforms`) and `TrackLifecycleChange` (`lifecycle_stage`), both
current state at the top level.

`locale` mirrors `users.locale` and is what the PostHog email automations (the
onboarding cadence and friends) read to decide which translation to send, so it
has to reflect the language the user picked *now* — never `$set_once`. Anything
that changes `users.locale` must dispatch `SyncUser`; `ProfileController@updateLanguage`
does, and registration already does via `CreateUser`.

Do not reach for `$browser_language` / `$browser_language_prefix` instead. They
are captured automatically by posthog-js but only as **event** properties on
`$pageview`, so they cannot segment a person or feed an automation — and they
report the browser's language at that pageview, not the language the user chose.

Before adding a person property, check what the project already has with the
PostHog MCP (`read-data-schema` with `{"kind": "entity_properties", "entity":
"person"}`) rather than guessing a name; overwriting an existing property is
silent and retroactive.

## TryPost.it Documentation

- All our documentation to final user it's under https://docs.trypost.it
