<?php

namespace App\Console\Commands;

use App\Services\QtechCatalogSynchronizer;
use App\Services\QtechClient;
use Illuminate\Console\Command;
use Throwable;

class SyncQtechCatalog extends Command
{
    protected $signature = 'qtech:sync-catalog {--json : Return machine-readable JSON}';
    protected $description = 'Pull the QTech game list and retire games and providers the account no longer carries';

    public function handle(QtechClient $client, QtechCatalogSynchronizer $synchronizer): int
    {
        if (! $client->configured()) {
            $this->error('QTech is not configured — nothing to sync.');

            return self::FAILURE;
        }

        try {
            $result = $synchronizer->sync();
        } catch (Throwable $e) {
            $this->error('QTech catalog sync failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'QTech catalog synced: %d created, %d updated, %d retired, %d providers disabled, %d re-enabled (%d of %s games over %d page(s)%s).',
            $result['created'], $result['updated'], $result['retired'],
            $result['providers_disabled'], $result['providers_enabled'],
            $result['fetched'], $result['expected'] === null ? '?' : $result['expected'], $result['pages'],
            $result['truncated'] ? ', INCOMPLETE — retirement skipped' : ''
        ));

        return self::SUCCESS;
    }
}
