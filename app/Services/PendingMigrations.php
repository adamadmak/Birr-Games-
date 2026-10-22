<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applying schema changes on a host with no shell.
 *
 * WHY THIS EXISTS
 *
 * There is no terminal on this platform. Whether a migration ever runs depends
 * entirely on one string in the hosting dashboard — the app's build command —
 * and the two brands were not set the same way:
 *
 *   kulubet     composer install ... --php artisan migrate   → migrations run
 *   casinogame  composer install ...                         → they never do
 *
 * Nothing in the repository makes up the difference: there is no deploy script,
 * no Dockerfile and no entrypoint, and the one service that would have called
 * `migrate --force` was never wired to anything. So on this brand a migration
 * has been a no-op since the app was created — it lands in the repository, the
 * deploy succeeds, and the change silently does not happen. That was found the
 * hard way: the same migration applied on one brand and did nothing on the
 * other, with a green build both times.
 *
 * Fixing the build command is still the right answer, and it only has to be
 * done once. This is what makes the schema recoverable without it, and what
 * makes the state visible either way — pending() is worth showing even on a
 * brand where deploys do migrate, because "waiting" and "applied" have looked
 * identical from the outside.
 *
 * WHAT IT DOES NOT DO
 *
 * Only `migrate --force`. Never a seeder: a seeder on a live casino would
 * rewrite operator-owned rows, and the dead service this replaces called both.
 */
final class PendingMigrations
{
    /**
     * @return array{supported: bool, pending: list<string>, applied: int, onDisk: int}
     */
    public function status(): array
    {
        $onDisk = $this->onDisk();

        if (! Schema::hasTable('migrations')) {
            return ['supported' => false, 'pending' => $onDisk, 'applied' => 0, 'onDisk' => count($onDisk)];
        }

        $applied = $this->applied();

        return [
            'supported' => true,
            'pending' => array_values(array_diff($onDisk, $applied)),
            'applied' => count($applied),
            'onDisk' => count($onDisk),
        ];
    }

    /**
     * Run whatever is waiting.
     *
     * The list of what actually ran is taken by comparing the `migrations`
     * table before and after, rather than by parsing Artisan's output: the
     * table is the record the framework itself trusts, and it stays right if
     * the output format ever changes.
     *
     * @return array{ran: list<string>, output: string}
     */
    public function apply(): array
    {
        $before = $this->applied();

        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        return [
            'ran' => array_values(array_diff($this->applied(), $before)),
            'output' => $output,
        ];
    }

    /** @return list<string> */
    private function applied(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    /** @return list<string> every migration file present, without the .php */
    private function onDisk(): array
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        $names = array_map(static fn (string $path): string => basename($path, '.php'), $files);
        sort($names);

        return array_values($names);
    }
}
