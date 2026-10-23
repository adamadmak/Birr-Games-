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

`BigBang` and `WinHouse` work around it with literal defaults committed in
`config/services.php`. Do not copy that for anything that authorises money: a
clone of the repo would authenticate as us. Read through a support class that
falls back to `platform_settings` (see `App\Support\Qtech`) and give operators
an admin field to set it.

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

**Every new path that takes a stake or pays out must go through
`App\Services\BonusPlay`.** It remembers how much of a round was bonus, so when
the round pays, the bonus part goes back to play-only and only the winnings
become withdrawable. A path that credits a payout straight to
`withdrawable_balance` lets a 1.01x bet turn any bonus into cash. The four
existing paths (QTech, BigBang, WinHouse, the built-in games) show the shape:
`stake()` after taking the stake, `payout()` before crediting the win, and a
provider rollback calls `unstake()`.

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
