<?php

namespace App\Services;

use App\Models\QtechSession;
use App\Models\User;
use App\Support\Qtech;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints the fixtures QTech's Common Wallet verifier needs against THIS wallet.
 *
 * The verifier (cw_qtcw_tester.py) drives our endpoints with a player id, a
 * live wallet-session token, an expired one, and a blocked player with a token
 * of its own. Those rows normally come into being only when a real player
 * launches a QTech game — which cannot happen until QTech have certified the
 * wallet with this very tool. So an operator mints them here.
 *
 * The players are fixtures: synthetic balance, a password nobody knows, and a
 * reserved username. Re-issuing resets the balance and retires the earlier
 * tokens, so a run always starts from a known state and only the tokens on
 * screen are live.
 */
final class QtechTestCredentials
{
    public const PLAYER = 'qtech_test';
    public const BLOCKED_PLAYER = 'qtech_blocked';

    /** Enough for the verifier's ~60 one-unit moves with room to spare. */
    public const BALANCE = 1000;

    /**
     * @return array{player_id: string, blocked_player_id: string, session: string,
     *     expired_session: string, blocked_session: string, balance: int, currency: string}
     */
    public function issue(): array
    {
        return DB::transaction(function (): array {
            $player = $this->player(self::PLAYER, 'QTech Verification', active: true);
            $blocked = $this->player(self::BLOCKED_PLAYER, 'QTech Verification (blocked)', active: false);

            // Only the tokens about to be shown may authorise anything.
            QtechSession::query()
                ->whereIn('user_id', [$player->id, $blocked->id])
                ->where('status', 'active')
                ->update(['status' => 'closed', 'updated_at' => now()]);

            // A password shown once, so an operator can sign in as the fixture
            // and play-test QTech games while they are hidden from real players.
            $password = Str::random(14);
            $player->forceFill(['password' => $password])->save(); // the model's hashed cast stores it hashed

            return [
                'player_id' => $player->username,
                'player_password' => $password,
                'player_email' => $player->email,
                'blocked_player_id' => $blocked->username,
                'session' => $this->session($player, now()->addHours(48))->external_session_id,
                'expired_session' => $this->session($player, now()->subHour())->external_session_id,
                'blocked_session' => $this->session($blocked, now()->addHours(48))->external_session_id,
                'balance' => self::BALANCE,
                'currency' => Qtech::currency(),
            ];
        });
    }

    /**
     * Put the fixture players back the way the verifier expects them WITHOUT
     * touching their session tokens.
     *
     * QTech run the config file we sent them, which carries the tokens. If an
     * operator has since toggled the accounts in Members — activating the one
     * labelled "(blocked)" or deactivating the test one — the verifier fails
     * with ACCOUNT_BLOCKED on a success scenario. Re-issuing would fix the
     * flags but rotate the tokens and invalidate QTech's copy; this fixes the
     * flags and the balances and leaves every token alone.
     *
     * @return array{player_id: string, blocked_player_id: string, balance: int, live_sessions: int}
     */
    public function repair(): array
    {
        return DB::transaction(function (): array {
            $player = $this->player(self::PLAYER, 'QTech Verification', active: true, keepPassword: true);
            $blocked = $this->player(self::BLOCKED_PLAYER, 'QTech Verification (blocked)', active: false, keepPassword: true);

            return [
                'player_id' => $player->username,
                'blocked_player_id' => $blocked->username,
                'balance' => self::BALANCE,
                'live_sessions' => QtechSession::query()
                    ->whereIn('user_id', [$player->id, $blocked->id])
                    ->where('status', 'active')
                    ->count(),
            ];
        });
    }

    /**
     * The verifier's own config file, filled in for one of our environments.
     * The pass key is left as a placeholder on purpose: it is write-only in the
     * admin and is never echoed back, and the operator already holds it.
     */
    public function configFile(array $issued, string $siteBase, string $environment): string
    {
        $prefix = $environment === Qtech::STAGING ? 'qtech-staging' : 'qtech';
        $base = rtrim($siteBase, '/')."/api/integrations/{$prefix}/";
        $keyHint = $environment === Qtech::STAGING ? 'REPLACE_WITH_STAGING_PASS_KEY' : 'REPLACE_WITH_PRODUCTION_PASS_KEY';

        return implode("\n", [
            '[wallet]',
            "walleturl = {$base}",
            "withdrawurl = {$base}transactions",
            "depositurl = {$base}transactions",
            "rollbackurl = {$base}transactions/rollback",
            "rewardurl = {$base}bonus/rewards",
            "walletsession = {$issued['session']}",
            "walletsessionExpired = {$issued['expired_session']}",
            "passkey = {$keyHint}",
            "playerid = {$issued['player_id']}",
            "currency = {$issued['currency']}",
            'gameid = TK-froggrog',
            'device = MOBILE',
            'clienttype = HTML5',
            'category = CASINO/SLOT/5REEL',
            'completed = false',
            'amount = 1',
            "blockedplayerid = {$issued['blocked_player_id']}",
            "blockedwalletsession = {$issued['blocked_session']}",
            'amounttoreachinsufficientfund = 99999999',
            'verifybalanceondeposit = 1',
            '',
        ]);
    }

    private function player(string $username, string $name, bool $active, bool $keepPassword = false): User
    {
        // A fixture deleted by hand must come back as the same row, or the
        // reserved username and email collide on their unique indexes.
        $user = User::withTrashed()->where('username', $username)->first() ?? new User();
        if ($user->exists && method_exists($user, 'trashed') && $user->trashed()) {
            $user->restore();
        }

        $attributes = [
            'name' => $name,
            'username' => $username,
            // Older schemas required an email; this one is unique and never mailed.
            'email' => $username.'@qtech.invalid',
            'role' => 'player',
            'credits' => self::BALANCE,
            'withdrawable_balance' => self::BALANCE,
            'active' => $active,
        ];
        // A repair keeps whatever password the operator was given; a fresh
        // issue replaces it (the test player's is then set to a shown value).
        if (! $keepPassword || ! $user->exists) {
            $attributes['password'] = Str::random(40); // hashed by the model cast
        }
        $user->forceFill($attributes)->save();

        return $user;
    }

    private function session(User $user, DateTimeInterface $expiresAt): QtechSession
    {
        return QtechSession::create([
            'user_id' => $user->id,
            'external_session_id' => Str::lower(Str::random(48)),
            'currency' => Qtech::currency(),
            'status' => 'active',
            'expires_at' => $expiresAt,
            'last_activity_at' => now(),
        ]);
    }
}
