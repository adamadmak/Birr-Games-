<?php

namespace App\Services;

use App\Models\BonusClaim;
use App\Models\ShamoParticipation;
use App\Models\TelegramSubscriber;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Folds a duplicate player record into the account that owns the phone number.
 *
 * Before phone verification was mandatory, opening the Telegram bot created a brand new
 * player even when the person had already registered on the website. Those people end up
 * with two wallets: the bot shows one balance, the admin shows the other. Merging moves
 * every balance and every row of history onto one surviving account so both views agree.
 */
final class AccountMergeService
{
    /** Tables that carry a plain player reference and can be repointed wholesale. */
    private const OWNED_TABLES = [
        'game_rounds',
        'wallet_transactions',
        'agent_orders',
        'bigbang_sessions',
        'bigbang_transactions',
        'member_bonuses',
        'telegram_login_tokens',
    ];

    /** Rows repointed per statement while no user lock is held. */
    private const DRAIN_BATCH = 2000;

    /**
     * @return array<string, mixed> a summary of what moved
     */
    public function merge(User $duplicate, User $survivor): array
    {
        if ($duplicate->id === $survivor->id) {
            throw new InvalidArgumentException('An account cannot be merged into itself.');
        }
        if ($duplicate->role !== User::ROLE_PLAYER || $survivor->role !== User::ROLE_PLAYER) {
            throw new InvalidArgumentException('Only player accounts can be merged.');
        }

        // Phase one: repoint the history WITHOUT holding either user's row lock.
        // An active player's history is hundreds of thousands of rows; rewriting
        // it in one statement inside the locked transaction below held both user
        // rows for the whole rewrite, and every wallet callback for that player
        // parked a worker behind the lock — a site-wide freeze. Repointing a
        // history row touches nothing a balance reads, so it is safe to do in
        // bounded batches that each commit on their own; whatever lands between
        // this drain and the lock is swept up inside the transaction, where the
        // residue is at most a few seconds of play.
        $moved = [];
        foreach (self::OWNED_TABLES as $table) {
            $moved[$table] = $this->drainInBatches($table, $duplicate->id, $survivor->id);
        }

        return DB::transaction(function () use ($duplicate, $survivor, $moved): array {
            // Deterministic lock order avoids deadlocks when two merges run at once.
            $ids = [$duplicate->id, $survivor->id];
            sort($ids);
            User::query()->whereIn('id', $ids)->lockForUpdate()->get();

            $duplicate = User::query()->findOrFail($duplicate->id);
            $survivor = User::query()->findOrFail($survivor->id);

            $transferred = round((float) $duplicate->credits, 2);
            $transferredWithdrawable = min(round((float) $duplicate->withdrawable_balance, 2), $transferred);

            // The last few rows written while the batches above were draining.
            foreach (self::OWNED_TABLES as $table) {
                $moved[$table] += DB::table($table)->where('user_id', $duplicate->id)->update(['user_id' => $survivor->id]);
            }

            $moved['bonus_claims'] = $this->moveWithoutClashing(
                BonusClaim::query()->where('user_id', $duplicate->id)->get(),
                fn (BonusClaim $claim) => BonusClaim::query()
                    ->where('user_id', $survivor->id)
                    ->where('bonus_type', $claim->bonus_type)
                    ->exists(),
                $survivor->id,
            );

            $moved['shamo_participations'] = $this->moveWithoutClashing(
                ShamoParticipation::query()->where('user_id', $duplicate->id)->get(),
                fn (ShamoParticipation $participation) => ShamoParticipation::query()
                    ->where('user_id', $survivor->id)
                    ->where('shamo_campaign_id', $participation->shamo_campaign_id)
                    ->exists(),
                $survivor->id,
            );

            // The duplicate's ledger rows have already moved to the survivor, so the balance
            // transfer is fully represented. A zero-value entry records the event itself
            // without disturbing any sum: the ledger still equals the wallet on both sides.
            WalletTransaction::query()->firstOrCreate(
                ['ledger_key' => 'account-merge-'.$duplicate->id.'-into-'.$survivor->id],
                [
                    'user_id' => $survivor->id,
                    'type' => 'account_merge',
                    'amount' => 0,
                    'status' => 'success',
                    'method' => 'system',
                    'reason' => 'Duplicate account #'.$duplicate->id.' merged in ('
                        .number_format($transferred, 2).' ETB, '.number_format($transferredWithdrawable, 2).' withdrawable)',
                    'currency' => 'ETB',
                    'source_type' => 'user',
                    'source_id' => $duplicate->id,
                    'metadata' => [
                        'merged_from_user_id' => $duplicate->id,
                        'credits_transferred' => $transferred,
                        'withdrawable_transferred' => $transferredWithdrawable,
                    ],
                ]
            );

            // The duplicate is released first: closed, emptied and stripped of the phone
            // number so it can never be signed into or matched again. The name is left
            // alone — it is the person's real name — and the merge is recorded in the
            // ledger and the log instead.
            $duplicateNumber = $duplicate->phone;
            $duplicate->forceFill([
                'credits' => 0,
                'withdrawable_balance' => 0,
                'active' => false,
                'phone' => null,
            ])->save();

            $survivor->forceFill([
                'credits' => round((float) $survivor->credits + $transferred, 2),
                'withdrawable_balance' => round((float) $survivor->withdrawable_balance + $transferredWithdrawable, 2),
                'contact_confirmed' => $survivor->contact_confirmed || $duplicate->contact_confirmed,
                'phone' => $this->canonicalNumber($survivor, $survivor->phone ?: $duplicateNumber),
            ])->save();

            $subscribers = $this->moveTelegramLink($duplicate, $survivor);

            Cache::forget('admin.members.duplicate-phones');

            Log::warning('Duplicate player account merged.', [
                'duplicate_user_id' => $duplicate->id,
                'survivor_user_id' => $survivor->id,
                'credits_transferred' => $transferred,
                'withdrawable_transferred' => $transferredWithdrawable,
                'rows_moved' => $moved,
                'telegram_subscribers_moved' => $subscribers,
            ]);

            return [
                'duplicate_user_id' => $duplicate->id,
                'survivor_user_id' => $survivor->id,
                'credits_transferred' => $transferred,
                'withdrawable_transferred' => $transferredWithdrawable,
                'rows_moved' => $moved,
                'telegram_subscribers_moved' => $subscribers,
            ];
        });
    }

    private function drainInBatches(string $table, int $duplicateId, int $survivorId): int
    {
        $moved = 0;

        do {
            $changed = DB::table($table)
                ->where('user_id', $duplicateId)
                ->limit(self::DRAIN_BATCH)
                ->update(['user_id' => $survivorId]);
            $moved += $changed;
        } while ($changed === self::DRAIN_BATCH);

        return $moved;
    }

    /**
     * Leave the survivor holding one canonical spelling of the number so a further format
     * cannot restart the problem — unless a third account is still holding that exact
     * string, in which case the next merge in the chain will canonicalise it.
     */
    private function canonicalNumber(User $survivor, ?string $number): ?string
    {
        $canonical = PhoneNumber::normalize($number);

        if ($canonical === null || $canonical === $survivor->phone) {
            return $survivor->phone ?: $number;
        }

        $held = User::query()->where('phone', $canonical)->whereKeyNot($survivor->id)->exists();

        return $held ? ($survivor->phone ?: $number) : $canonical;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     */
    private function moveWithoutClashing($rows, callable $clashes, int $survivorId): int
    {
        $moved = 0;

        foreach ($rows as $row) {
            if ($clashes($row)) {
                // The survivor already has this one-per-member record; leaving it on the
                // duplicate keeps the history without breaking the unique constraint.
                continue;
            }

            $row->forceFill(['user_id' => $survivorId])->save();
            $moved++;
        }

        return $moved;
    }

    /** telegram_subscribers.user_id is unique, so the link is transferred, never duplicated. */
    private function moveTelegramLink(User $duplicate, User $survivor): int
    {
        $incoming = TelegramSubscriber::query()->where('user_id', $duplicate->id)->get();
        if ($incoming->isEmpty()) {
            return 0;
        }

        TelegramSubscriber::query()
            ->where('user_id', $survivor->id)
            ->each(function (TelegramSubscriber $existing) use ($survivor): void {
                Log::warning('A Telegram account was unlinked so a freshly verified one could own the player record.', [
                    'released_subscriber_id' => $existing->id,
                    'user_id' => $survivor->id,
                ]);
                $existing->forceFill(['user_id' => null])->save();
            });

        $primary = $incoming->first();
        $primary->forceFill(['user_id' => $survivor->id])->save();

        $incoming->skip(1)->each(fn (TelegramSubscriber $extra) => $extra->forceFill(['user_id' => null])->save());

        return 1;
    }
}
