# Working notes for this codebase

Conventions that are not obvious from the code, and that have each caused a
production bug here at least once. Follow them in every module.

## Admin forms

**Helper text goes in `<small>`; it renders as a hint below the field.**
Do not write explanatory sentences any other way, and do not add inline
styles to position them.

```blade
<label>API base URL
    <input type="url" name="qtech_api_base_url">
    <small>The integration host for now. QTech issue a production host to switch to.</small>
</label>
```

**A unit badge needs `class="unit"`.** That is the short marker sitting inside
the control's right edge — `ETB`, `%`, `Top N`. Only use it for a word or two.

```blade
<label>Max Payout<input type="number" name="max_payout"><small class="unit">ETB</small></label>
```

This used to be the other way round: every `<small>` was positioned absolutely
inside the input, so any real sentence rendered on top of the control and was
unreadable. The default is now the safe one. If you forget `.unit`, a badge
renders as a hint — plainer, but never broken.

**Group related fields with `.configuration-form-section`** (add `.divided` for
a rule above it) rather than reusing `.configuration-panel-title`, which is the
panel header and carries its own border and spacing.

**A secondary action with its own explanation uses `.configuration-inline-action`**
— a button plus a `<p>`. The panel's primary save stays in
`.configuration-form-actions`. Section-level errors go in
`.configuration-form-feedback`.

**No inline `style` attributes.** If a layout needs something new, add a class
to the brand's admin stylesheet so both brands can share it.

## A submit button inherits its form's method spoof

The settings form posts with `@method('PUT')`. A button inside it sends PUT no
matter what its own `formmethod` says, because Laravel reads the hidden
`_method` field. Pointing such a button at a POST-only route returns 405.

Give the action its own form, declared outside the spoofed one, and reach it by
id — HTML associates a control with a form anywhere in the document:

```blade
<form id="thing-test-form" method="POST" action="{{ route('...') }}">@csrf</form>
...
<button type="submit" form="thing-test-form">Test</button>
```

## Secrets live in `platform_settings`, not environment variables

This host builds Laravel's config cache at BUILD time, without the runtime
environment. A cached config makes Laravel skip loading `.env` entirely, so
`config()`, `env()`, `$_ENV` and `getenv()` are all empty at runtime — a value
set in the platform dashboard never reaches the application.

No provider key is a literal in the repository any more (they were, until
28 Oct 2026, and a clone of the repo authenticated as us). `App\Support\Qtech`,
`BigBang` and `Winhouse` each read through `RuntimeEnv` and the admin fields on
Configuration → System; keep it that way for anything that authorises money.
Rotating a key is: set the new value at the provider, paste it in the admin
field, save. No restart. The QTech staging prefix shares the production
database, so its handlers refuse every player except the `qtech_test` fixture.

For anything new, resolve the value with `App\Support\RuntimeEnv::get()` —
config → `platform_settings` → process env → the `.env` file on disk — as
`App\Support\Turnstile` and `App\Support\Mailer` do. Write-only secrets saved
from the admin (Turnstile secret, SMTP password) go in `SettingController::SECRETS`
so a blank box keeps the stored value instead of erasing it.

## Registration is guarded in one place

`App\Services\RegistrationGuard` runs before validation on `POST /register`:
honeypot, session-bound render token with a minimum fill time, Cloudflare
Turnstile when keys are set, and a per-network daily quota. The auth routes use
the named limiters in `App\Support\AuthRateLimits`, which answer with the form
and a message rather than a bare 429. Every refusal lands in `security_events`
(Configuration → Security). Keep new checks inside the guard so the Security
tab keeps seeing them, and keep the per-network quota loose — Ethiopian mobile
carriers put whole towns behind one address.

The smoke-test scripts that exercised these were removed from the repository
(see commit a32bf27 and earlier to recover them); re-check sign-up, sign-in and
the hero by hand after touching auth or the hero.

## Both brands move together

`casinogame` (Lucy Chewata) and `kulubet` are the same product under two brands.
A change to one almost always belongs in the other. Port it in the same session
and run the same checks against both before pushing.

## Deploy one brand at a time

AletCloud's plan allows two concurrent builds, and whenever both brands build
at once neither finishes — they sit in BUILDING for an hour and need a manual
cancel in the dashboard (there is no cancel API). A build started during that
overlap does not recover after the other is cancelled. Builds started alone
land in two to three minutes.

A push auto-triggers a build, so pushing both repositories back to back IS
starting two builds. Push one, confirm it is live (probe a new route, or
`lastDeployedAt` from the API — the CSS cache-buster is unreliable), then push
the other.

## A wallet has two buckets, and bonus must never cross over

`users.credits` is the whole wallet; `users.withdrawable_balance` is the part a
player can cash out. The difference is play-only credit: deposit bonus, the
welcome balance, anything an administrator locked. A stake is paid from
play-only credit first.

**Every path that takes a stake or pays out goes through
`App\Services\Wallet\PlayerWallet`.** Inside the caller's transaction:
`lock()` the player, then `stake()`, `payout()`, `refund()` (a provider
rollback), `reward()` (a prize outside any round) or `playRound()` (a built-in
game, staked and settled in one step). The service spends play-only credit
first, keeps the bonus part of a stake play-only when the round pays (through
`App\Services\BonusPlay`, which remembers how much of a round was bonus),
rounds once, writes the ledger row from the `LedgerEntry` you describe, and
counts a real stake toward wagering. A path that moves `credits` or
`withdrawable_balance` by hand lets a 1.01x bet turn any bonus into cash and
will drift from the others, which is how the four paths got out of step before
28 Oct 2026. The controllers keep only their own protocol: authentication,
the idempotency row, the round mirror and the response. Add a test beside
`tests/Feature/PlayerWalletTest.php` for any new rule.

The deposit bonus itself is `App\Services\DepositBonusService`: it adds to
`credits` only, never to `withdrawable_balance`, and is paid once per approved
deposit order (a unique key on the order). Its settings live in
`platform_settings` under `deposit_bonus_*` and are edited on Configuration →
Deposit Bonus.

**Code on a money path must survive a missing table.** This host does not run
migrations on deploy (an administrator applies them from Configuration →
System), so the bonus and sticky-bonus code check that their tables exist and
fall back to the old behaviour, and a failure inside them is reported but never
fails the deposit, bet or payout.

## Telegram: connecting a website account

`App\Services\TelegramAccountLinker` connects a signed-in website account to a
chat. The site shows a one-time code (a hash is stored, it lasts 15 minutes);
the bot proves the person controls both the account (the code) and the phone
(Telegram's own contact card must match the number on the account). Keep both
checks: the code alone must never mark a phone number as confirmed.

Bot messages that carry a password go through `sendSecret()` in the webhook, which
schedules their deletion from the chat. `/password` is limited to three an hour and
ends the player's other browser sessions. Run the scratch checks by hand after
touching either (the earlier smoke scripts were removed; see git history).

## Password recovery has two doors, and both prove something

`App\Http\Controllers\PasswordRecoveryController` (`/password/forgot`). Telegram
for everyone: the page opens the bot with `?start=password`, which the webhook
treats as `/password`, and the bot only answers a chat whose contact card
carries the account's phone. Email only for an account whose address is
confirmed, through Laravel's password broker. The request form answers the
same way whether the account exists or not; keep it that way. Sign-in and
recovery find the account through `User::findByLogin()`, so a new spelling of
a phone number goes there, not in a controller. A changed password rotates
the remember token and calls `App\Support\PlayerSessions::endAll()`.

## Run the tests before pushing a money change

```bash
php vendor/bin/pest
```

Pest with an in-memory SQLite database (phpunit.xml); nothing touches the local
database, cache or mail. The suite covers the bonus split rule and ledger, the
deposit bonus grant, the WinHouse wallet callbacks (token, signature,
idempotency, bonus-first stake), sign-in, password recovery, admin default-deny
and the scheduler tick. A new wallet path gets a test next to
`tests/Feature/WinhouseWalletTest.php` before it ships; `player()` in
`tests/Pest.php` makes a player with a given wallet, and `$this->setting()` on
the test case saves a platform setting the way the admin would.

## Player interface follows the apple-design skill

`.claude/skills/apple-design/` (Emil Kowalski's distillation of Apple's WWDC
design talks, MIT, copied unchanged; see `SOURCE.md` there) is the design
reference for the player site. What it means here:

- Press feedback comes from `scale` on `:active` (the press-feedback list at
  the end of `public/css/lucy-play.css`), never `transform`, so a control that
  is positioned with translate() does not jump. Add a new tappable control to
  that list.
- Anything a finger drags uses the spring in `public/js/fluid.js`
  (`window.VamoseoneFluid`): motion starts from the live on-screen value,
  inherits the release velocity, decides by projected momentum, and resists
  past a boundary. The phone drawer and the hero slider use it; a new gesture
  should too, not a fixed-duration CSS transition.
- Nothing loops forever unless it reports something live. Everything that
  moves has a `prefers-reduced-motion` equivalent (a cross-fade), blur has a
  `prefers-reduced-transparency` solid fallback, and `prefers-contrast: more`
  firms up text and edges.
- No text below 10px. Display headings track no tighter than about -0.035em,
  and Amharic (`:lang(am)`) gets normal tracking and at least 1.25 leading.
- Haptics only inside Telegram, only for results (success, error, a snap).
- The two phone bars stay solid blue: that is the owner's choice and
  overrides the skill's translucent-chrome advice.
- Casino tiles are portrait (3:4, the providers' art is 2:3) with the title
  on the art, and every lobby section is one swipeable row, phones included.
  The last block of `lucy-play.css` ("CASINO TILES") settles the older tile
  rules above it; change tiles there, and keep the empty layers out of the markup.

## Agents: float floor, alerts, screenshots

- Every path that takes money out of an agent's float (deposit approval by the
  agent or an admin, direct deposit, credit sent to another agent) asks
  `FinancialRuleService::agentFloatRefusal()` first. The floor and the warning
  level are `agent_min_float` and `agent_low_float_warning` (Configuration →
  Agent). A new debit path must use the same check.
- Agents hear about work on Telegram through `App\Services\AgentNotifier`,
  sent after the commit with `TelegramBotService::notify()` (one attempt, five
  seconds). Never send Telegram inside a money transaction.
- Commission comes only from `FinancialRuleService::agentCommissionFor($agent, $amount, $kind)`,
  with `$kind` deposit or withdrawal. `agent_commission_mode` is per_order or
  per_block (the flat amount on orders that reach the threshold) or percent
  (`agent_commission_deposit_percent` / `agent_commission_withdrawal_percent`
  of every order). The agent must also have commission enabled.
- A deposit needs a bank reference or a payment screenshot, either one
  (`AgentOrder::hasPaymentEvidence()`); approve on that, never on the reference alone.
- Payment screenshots live in `deposit_proofs` (database, not disk: the
  container is replaced on deploy), re-encoded by `DepositProofStore`, and are
  served only to the player, the assigned agent and an admin.
- Any link that leaves the site (bot buttons, agent alerts, reset emails) is
  built with `App\Support\SiteUrl`, which uses the public address saved on
  Configuration → System.

## The counter: walk-in bets through an agent

`App\Services\AgentShop` gives each agent one counter account (a player with
`member_type` `shop`, username `shop_agent_<id>`, no phone). Float moves into it
and back with ledger rows on both sides, and a top-up obeys the float floor.
The agent opens the sportsbook as the counter from the portal; nothing else
signs into it, and deposits and withdrawals refuse it. A winning counter ticket
is paid in cash from Bet Tickets once (`shop_payouts`, unique ticket id), for
the amount our WinHouse ledger credited, not what the provider API reports.
Agent tools that find players by phone cannot reach a counter; keep it so.

Booking codes (WinHouse docs/agent.md 4.8) are read with
`WinhouseTicketsClient::booking()`; `bookingCode()` is the only gate for a code
and must run before a code reaches a URL. The Counter page looks a code up and
opens it in the book as the counter (`/?booking=CODE`), where WinHouse lets the
agent change the stake and picks before placing. Our site never edits a slip.

## My Bets: per-pick results are worked out, not reported

`/player/bets` (`PlayerBetsController`) lists the player's WinHouse tickets.
WinHouse settles a ticket as a whole and sends no result per pick, so
`App\Support\BetPick` marks each pick from the final score (match result,
double chance, draw no bet, over/under goals, both teams to score) and from
what the ticket result proves. Anything else stays unknown, never guessed.
The printed receipt uses the same marks.
