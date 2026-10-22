<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   filesystems.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    'payout_proof_disk' => env('PAYOUT_PROOF_DISK', 'secure'),

    

    'default' => env('FILESYSTEM_DISK', 'local'),

    

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        'assets' => [
            'driver' => 'local',
            'root' => public_path(),
        ],

        'resources' => [
            'driver' => 'local',
            'root' => resource_path(),
        ],

        'routes' => [
            'driver' => 'local',
            'root' => base_path('routes'),
        ],

        'logs' => [
            'driver' => 'local',
            'root' => storage_path('logs'),
        ],

        'secure' => [
            'driver' => 'local',
            'root' => storage_path('app/secure'),
            'throw' => true,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    

    'links' => [
        public_path('storage') => storage_path('app/public'),
        public_path('lang') => resource_path('lang'),
    ],

];
