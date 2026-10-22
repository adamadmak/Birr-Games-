<?php

namespace App\Console\Commands;

use App\Services\BigBangCasinoClient;
use App\Services\BigBangCatalogSynchronizer;
use Illuminate\Console\Command;
use Throwable;

class SyncBigBangCatalog extends Command
{
    protected $signature = 'bigbang:sync-catalog {--json : Return machine-readable JSON}';
    protected $description = 'Pull the BigBang catalog and retire games and providers the account no longer carries';

    public function handle(BigBangCasinoClient $client, BigBangCatalogSynchronizer $synchronizer): int
    {
        if (\App\Support\BigBang::retired()) {
            $this->warn('BigBang has been retired — nothing to sync.');

            return self::SUCCESS;
        }

        if (!$client->configured()) {
            $this->error('BigBang is not configured — nothing to sync.');

            return self::FAILURE;
        }

        try {
            $result = $synchronizer->sync();
        } catch (Throwable $e) {
            $this->error('Catalog sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Catalog synced: %d created, %d updated, %d retired, %d providers disabled, %d re-enabled (%d of %s games over %d pages%s).',
            $result['created'],
            $result['updated'],
            $result['retired'],
            $result['providers_disabled'],
            $result['providers_enabled'],
            $result['fetched'],
            $result['expected'] === null ? '?' : $result['expected'],
            $result['pages'],
            $result['truncated'] ? ', INCOMPLETE — retirement skipped' : ''
        ));

        if ($result['truncated']) {
            $this->warn(sprintf(
                'Only %d of %s games came back, so nothing was retired this run — withdrawn providers stay on the lobby until a full read succeeds.',
                $result['fetched'],
                $result['expected'] === null ? 'an unreported number' : $result['expected']
            ));
        }

        return self::SUCCESS;
    }
}
