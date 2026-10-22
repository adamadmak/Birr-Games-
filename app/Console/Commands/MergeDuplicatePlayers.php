<?php

namespace App\Console\Commands;

use App\Models\TelegramSubscriber;
use App\Models\User;
use App\Services\AccountMergeService;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Finds people who ended up with two player accounts — one from the website and one the
 * Telegram bot created before phone verification was mandatory — and folds them into one.
 *
 * Reports by default; pass --apply to actually merge.
 */
class MergeDuplicatePlayers extends Command
{
    protected $signature = 'players:merge-duplicates
        {--apply : Perform the merge instead of only reporting}
        {--user= : Restrict to a single surviving member id}';

    protected $description = 'Report or merge duplicate player accounts that belong to the same phone number';

    public function handle(AccountMergeService $merges): int
    {
        $candidates = $this->candidates();

        if ($candidates->isEmpty()) {
            $this->info('No duplicate player accounts found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Phone', 'Keep', 'Keep balance', 'Merge', 'Merge balance', 'Reason'],
            $candidates->map(fn (array $row) => [
                PhoneNumber::format($row['phone']),
                '#'.$row['survivor']->id.' '.$row['survivor']->name,
                number_format((float) $row['survivor']->credits, 2),
                '#'.$row['duplicate']->id.' '.$row['duplicate']->name,
                number_format((float) $row['duplicate']->credits, 2),
                $row['reason'],
            ])->all()
        );

        if (! $this->option('apply')) {
            $this->warn($candidates->count().' duplicate pair(s) found. Nothing was changed — re-run with --apply to merge.');

            return self::FAILURE;
        }

        $merged = 0;
        foreach ($candidates as $row) {
            try {
                $result = $merges->merge($row['duplicate'], $row['survivor']);
                $merged++;
                $this->line(sprintf(
                    'Merged #%d into #%d (%s ETB moved).',
                    $result['duplicate_user_id'],
                    $result['survivor_user_id'],
                    number_format($result['credits_transferred'], 2)
                ));
            } catch (Throwable $exception) {
                $this->error(sprintf(
                    'Could not merge #%d into #%d: %s',
                    $row['duplicate']->id,
                    $row['survivor']->id,
                    $exception->getMessage()
                ));
            }
        }

        $this->info($merged.' account(s) merged.');

        return $merged === $candidates->count() ? self::SUCCESS : self::FAILURE;
    }

    /** @return Collection<int, array{phone: string, survivor: User, duplicate: User, reason: string}> */
    private function candidates(): Collection
    {
        $restrict = $this->option('user') ? (int) $this->option('user') : null;
        $pairs = collect();
        $seen = [];

        // 1. A Telegram subscriber whose verified number belongs to a different member than
        //    the account the bot linked it to.
        TelegramSubscriber::query()
            ->whereNotNull('user_id')
            ->whereNotNull('phone')
            ->with('user')
            ->each(function (TelegramSubscriber $subscriber) use (&$pairs, &$seen, $restrict): void {
                $candidates = PhoneNumber::candidates($subscriber->phone);
                if ($candidates === [] || ! $subscriber->user) {
                    return;
                }

                $owner = User::query()
                    ->where('role', User::ROLE_PLAYER)
                    ->whereIn('phone', $candidates)
                    ->whereKeyNot($subscriber->user_id)
                    ->orderBy('id')
                    ->first();

                if (! $owner || ($restrict && $owner->id !== $restrict)) {
                    return;
                }

                $key = $subscriber->user_id.':'.$owner->id;
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;

                $pairs->push([
                    'phone' => (string) $subscriber->phone,
                    'survivor' => $owner,
                    'duplicate' => $subscriber->user,
                    'reason' => 'Telegram account linked to a second wallet',
                ]);
            });

        // 2. Two member records that normalise to the same phone number.
        User::query()
            ->where('role', User::ROLE_PLAYER)
            ->whereNotNull('phone')
            ->orderBy('id')
            ->get(['id', 'name', 'phone', 'credits', 'withdrawable_balance', 'role', 'active', 'contact_confirmed', 'created_at'])
            ->groupBy(fn (User $user) => PhoneNumber::normalize($user->phone) ?: 'unreadable-'.$user->id)
            ->filter(fn (Collection $group, string $key) => ! str_starts_with($key, 'unreadable-') && $group->count() > 1)
            ->each(function (Collection $group) use (&$pairs, &$seen, $restrict): void {
                // Keep the oldest account: it holds the original registration and password.
                $survivor = $group->sortBy('id')->first();
                if ($restrict && $survivor->id !== $restrict) {
                    return;
                }

                $group->skip(1)->each(function (User $duplicate) use ($survivor, &$pairs, &$seen): void {
                    $key = $duplicate->id.':'.$survivor->id;
                    if (isset($seen[$key])) {
                        return;
                    }
                    $seen[$key] = true;

                    $pairs->push([
                        'phone' => (string) $survivor->phone,
                        'survivor' => $survivor,
                        'duplicate' => $duplicate,
                        'reason' => 'Two members share one phone number',
                    ]);
                });
            });

        return $pairs->values();
    }
}
