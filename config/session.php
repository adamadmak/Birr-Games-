<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   session.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

use Illuminate\Support\Str;

return [

    

    'driver' => env('SESSION_DRIVER', env('APP_ENV') === 'production' ? 'database' : 'file'),

    

    'lifetime' => env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', FALSE),

    

    'encrypt' => env('SESSION_ENCRYPT', env('APP_ENV') === 'production'),

    

    'files' => storage_path('framework/sessions'),

    

    'connection' => env('SESSION_CONNECTION'),

    

    'table' => env('SESSION_TABLE', 'sessions'),

    

    'store' => env('SESSION_STORE'),

    

    'lottery' => [2, 100],

    

    'cookie' => env('SESSION_COOKIE', 'lucy_play_session'),

    

    'path' => env('SESSION_PATH', '/'),

    

    'domain' => env('SESSION_DOMAIN'),

    

    'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),

    

    'http_only' => env('SESSION_HTTP_ONLY', true),

    

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    

    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
