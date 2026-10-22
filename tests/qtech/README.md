# QTech integration checks

Standalone scripts, like `tests/prediction_pool_smoke.php`: each boots the app against the
local `.env` database, runs inside a transaction that is rolled back, fakes QTech's HTTP where
needed, and exits non-zero on any failure.

    php artisan migrate --force
    for t in tests/qtech/*.php; do php "$t" || exit 1; done

- `live_switch.php` — QTech games are held from players on the integration host, and on the
  production host until the "live to players" switch is on; the fixture player bypasses both.
- `fixtures.php` — "Issue Test Credentials" mints the player, blocked player and three session
  tokens QTech's verifier needs, and the wallet answers each exactly as the verifier expects.
- `repair.php` — "Repair Test Players" restores the fixtures' flags and balances without
  rotating the tokens QTech already hold.
- `go_live_surface.php` — the switch's decision actually reaches the player: our own CSP
  allows the launcher's iframe, the GET launch route answers 404 rather than advertising
  hidden games, and ticking the box in the admin form clears the shared lobby snapshot at
  once instead of a TTL later.
- `rounds.php` — a QTech settlement reaches `game_rounds`, the table Admin Rounds, the Casino
  Report, the dashboard and tournaments all read. The wallet ledger was always right; this is
  the play history, which QTech did not write until it became the only aggregator.
