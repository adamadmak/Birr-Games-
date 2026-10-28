<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Empties the books before a platform goes live.
 *
 * Everything on these two brands was built and rehearsed against the same
 * database the public will use: test deposits, rehearsed withdrawals, staff
 * accounts playing games, contests created to see what they looked like. On the
 * day real money arrives none of that should still be in the reports, and there
 * is no shell on this host to clear it by hand.
 *
 * WHAT THIS WILL NOT DO
 *
 * It never touches configuration. Platform settings, payment gateways, the
 * game catalogue and its lobbies, promotions, bonus and wagering rules,
 * administrators and agents all survive — going live means opening the books
 * again, not rebuilding the platform. The tables below are the only ones it can
 * reach, and each one holds a record of something that HAPPENED rather than a
 * decision about how the platform works.
 *
 * ORDER MATTERS
 *
 * Children are listed before parents. `agent_orders.user_id` is nullOnDelete,
 * so deleting players first would leave their orders behind with nobody
 * attached — the reports would still carry the test money, just anonymously.
 * Deleting in this order removes the record rather than orphaning it.
 */
final class GoLiveReset
{
    /** The word an operator has to type. Deliberately not a click. */
    public const CONFIRMATION = 'ERASE';

    /**
     * Set the first time the reset runs. From then on it refuses, because a
     * site that has cleared its test data is live, and a stolen super-admin
     * session must not be able to erase the ledger. Unlocking means deleting
     * this row from platform_settings by hand, on purpose, at the database.
     */
    public const LOCK_KEY = 'go_live_reset_locked_at';

    public static function locked(): bool
    {
        try {
            return filled(\App\Models\PlatformSetting::valueFor(self::LOCK_KEY));
        } catch (\Throwable) {
            return true;   // a settings table that cannot be read is no reason to allow a wipe
        }
    }

    /**
     * @var array<string, array{label: string, note: string, tables: list<string>}>
     */
    private const GROUPS = [
        'orders' => [
            'label' => 'Deposit and withdrawal orders',
            'note' => 'Every agent order, its timeline, and the ratings players left.',
            'tables' => ['agent_order_events', 'agent_ratings', 'agent_orders'],
        ],
        'wallet' => [
            'label' => 'Wallet ledger',
            'note' => 'Every credit and debit recorded against a wallet.',
            'tables' => ['wallet_transactions'],
        ],
        'play' => [
            'label' => 'Game rounds and provider sessions',
            'note' => 'Rounds played, and the session and transaction rows the game providers wrote.',
            'tables' => [
                'game_rounds',
                'qtech_transactions', 'qtech_sessions',
                'bigbang_transactions', 'bigbang_sessions',
                'winhouse_transactions', 'winhouse_sessions',
            ],
        ],
        'bonuses' => [
            'label' => 'Bonuses awarded',
            'note' => 'Claims and member bonuses. The bonus RULES are configuration and stay.',
            'tables' => ['bonus_claims', 'member_bonuses'],
        ],
        'contests' => [
            'label' => 'Contest entries',
            'note' => 'Prediction entries and pools, and Shamo participations. The campaigns stay.',
            'tables' => ['prediction_entries', 'prediction_pools', 'shamo_participations'],
        ],
        'messaging' => [
            'label' => 'Telegram history',
            'note' => 'Broadcasts sent and their deliveries. Templates and subscribers stay.',
            'tables' => ['telegram_broadcast_deliveries', 'telegram_broadcasts', 'telegram_updates', 'telegram_login_tokens'],
        ],
        'players' => [
            'label' => 'Player accounts',
            'note' => 'Every player, with their wallet balance. Administrators and agents are not touched.',
            'tables' => [],
        ],
        'balances' => [
            'label' => 'Agent float',
            'note' => 'Sets every agent balance back to zero. The agents themselves stay.',
            'tables' => [],
        ],
    ];

    /**
     * What is there to clear, group by group.
     *
     * @return array<string, array{label: string, note: string, count: int, missing: bool}>
     */
    public function preview(): array
    {
        $out = [];

        foreach (self::GROUPS as $key => $group) {
            $count = 0;
            $missing = false;

            foreach ($group['tables'] as $table) {
                if (! Schema::hasTable($table)) {
                    $missing = true;
                    continue;
                }
                $count += DB::table($table)->count();
            }

            if ($key === 'players') {
                $count = $this->players()->count();
            }
            if ($key === 'balances') {
                $count = Schema::hasTable('agents')
                    ? DB::table('agents')->where('balance', '!=', 0)->count()
                    : 0;
            }

            $out[$key] = $group + ['count' => $count, 'missing' => $missing];
        }

        return $out;
    }

    /** Total rows across every group, for the panel's headline. */
    public function total(): int
    {
        return array_sum(array_column($this->preview(), 'count'));
    }

    /**
     * Clear the chosen groups.
     *
     * One transaction: a reset that stopped half way would leave orders whose
     * players are gone, which reads as corruption rather than a fresh start.
     *
     * @param  list<string>  $groups
     * @return array<string, int> rows removed, per group
     */
    public function apply(array $groups, string $confirmation): array
    {
        if (self::locked()) {
            throw new RuntimeException('The reset is locked: it ran once already and the site is live. Nothing was cleared.');
        }

        if (trim($confirmation) !== self::CONFIRMATION) {
            throw new RuntimeException('Type '.self::CONFIRMATION.' to confirm. Nothing was cleared.');
        }

        $groups = array_values(array_intersect($groups, array_keys(self::GROUPS)));

        if ($groups === []) {
            throw new RuntimeException('Choose at least one thing to clear. Nothing was cleared.');
        }

        $removed = [];

        DB::transaction(function () use ($groups, &$removed): void {
            foreach ($groups as $key) {
                $count = 0;

                foreach (self::GROUPS[$key]['tables'] as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    $count += DB::table($table)->delete();
                }

                if ($key === 'players') {
                    // forceDelete: users are soft-deleted, and a row still in the
                    // table is still a player as far as every report is concerned.
                    $count = $this->players()->forceDelete();
                }

                if ($key === 'balances' && Schema::hasTable('agents')) {
                    $count = DB::table('agents')->where('balance', '!=', 0)->update(['balance' => 0]);
                }

                $removed[$key] = $count;
            }

            \App\Models\PlatformSetting::query()->updateOrCreate(['key' => self::LOCK_KEY], ['value' => now()->toIso8601String()]);
            \App\Models\PlatformSetting::flushValueCache();
        });

        return $removed;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<User> */
    private function players()
    {
        return User::withTrashed()->where('role', User::ROLE_PLAYER);
    }

    /** @return array<string, array{label: string, note: string, tables: list<string>}> */
    public static function groups(): array
    {
        return self::GROUPS;
    }
}
