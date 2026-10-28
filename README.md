# Vamoseone

Sports betting and casino for Ethiopia: a Laravel 12 application with a
player site (web and Telegram mini app), an agent portal for bank-transfer
deposits and payouts, an admin console, and three game providers settled
through wallet callbacks (QTech, WinHouse, and the retired BigBang).

`CLAUDE.md` holds the conventions that matter when changing code. This file
is how to run, test and operate it.

## Run it locally

```bash
composer install
cp .env.example .env          # SQLite, file cache, Secure cookie off
php artisan key:generate
php artisan migrate
php artisan serve --port=8080
```

Create an administrator with `php artisan tinker` (role `admin`, `admin_role`
`super_admin`) and open `/admin`. Provider keys, mail and bonus rules are set
on Configuration, never in `.env` (see "Secrets" below).

## Test it

```bash
php vendor/bin/pest
```

Pest with an in-memory SQLite database (`phpunit.xml`); nothing touches your
local database, cache or mail. CI runs the same suite on every push
(`.github/workflows/tests.yml`). A wallet change ships with a test beside
`tests/Feature/PlayerWalletTest.php`.

## How it is deployed

- Host: AletCloud, app `birr-games-`, MySQL, Cloudflare in front. A push to
  `main` builds and deploys. **Push one brand at a time**: two concurrent
  builds never finish.
- The host builds Laravel's config cache at build time with no runtime
  environment, so `env()` is empty in production. Anything the operator sets
  lives in `platform_settings` and is read through `App\Support\RuntimeEnv`.
- Migrations are not run on deploy. After a deploy that adds one, apply it
  from Configuration → System → Schema updates. `/api/system/health` reports
  `migrations_pending: true` until you do.
- Logs go to stderr (the host's log viewer) and to `storage/logs/app.log`,
  which disappears with the container on the next deploy.

## Things that must be running

| What | Where | If it stops |
|---|---|---|
| Scheduler tick | An outside timer calls `/api/system/tick?token=<sync token>` every minute (crontab line on Configuration → System) | Queue, bonus expiry, session expiry, bot password-message deletion, catalogue refresh and the wallet check all stop silently |
| Health check | Point an uptime monitor at `/api/system/health` | It answers 503 when the database is down, the scheduler has not run for 5 minutes, or migrations are pending |
| Telegram webhook | Set from Configuration → Telegram; carries a secret header | Players cannot deposit or recover a password through the bot |

## Secrets and keys

Every provider key, the mail password and the Turnstile secret are admin
fields (Configuration → System and → Security). To rotate one: set the new
value at the provider, paste it in the field, save. No restart, no deploy.
A blank secret box means "unchanged". The audit log redacts them.

The QTech staging prefix (`/api/integrations/qtech-staging/*`) uses its own
pass key and shares the production database; its handlers refuse every
player except the `qtech_test` fixture.

## Money, in one paragraph

A wallet has two buckets: `users.credits` (everything) and
`users.withdrawable_balance` (cash). Every stake, payout, refund and reward
goes through `App\Services\Wallet\PlayerWallet`, which spends play-only
credit first and keeps the bonus part of a stake play-only when the round
pays. Provider callbacks are idempotent on their own transaction id and
locked on the player's row. Deposits are bank transfers to an agent's
account, confirmed by that agent; withdrawals are paid by an agent and
reviewed on the admin side.

## Adding a provider

Copy the shape of `WinhouseWalletController`: authenticate the callback
(constant-time, fail closed when the key is unset), look the player up by
`player_<id>` exactly, find or refuse the duplicate by (action, transaction
id) with a payload hash, then move money only through `PlayerWallet` inside
one transaction with `lock()`. Keys come from a support class over
`RuntimeEnv` with admin fields. Add a test file beside
`tests/Feature/WinhouseWalletTest.php` before the first real callback.

## Before you push

1. `php vendor/bin/pest` is green.
2. The change is ported to the other brand (`kulubet`) in the same session.
3. If it adds a migration, plan to apply it from the admin right after the deploy.
