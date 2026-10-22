<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   ArtisanService.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/


namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class ArtisanService
{
    public static function migrateAndSeed(): void
    {
        
        Artisan::call('migrate', [
            '--force' => TRUE,
        ]);

        
        Artisan::call('db:seed', [
            '--force' => TRUE,
        ]);
    }

    public static function clearAllCaches(): void
    {
        
        Cache::flush();
        
        Artisan::call('config:clear');
        
        Artisan::call('cache:clear');
        
        Artisan::call('view:clear');
        
        Artisan::call('route:clear');
    }

    public static function restartQueueWorker(): void
    {
        Artisan::call('queue:restart');
    }
}
