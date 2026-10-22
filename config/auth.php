<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   auth.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'api' => [
            'driver' => 'token',
            'provider' => 'users',
            'hash' => false,
        ],

        'agent' => [
            'driver' => 'session',
            'provider' => 'agents',
        ],
    ],

    

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        'agents' => [
            'driver' => 'eloquent',
            'model' => App\Models\Agent::class,
        ],

        
        
        
        
    ],

    

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    

    'password_timeout' => 10800,

    'web3' => [
        'evm' => [
            'enabled' => env('AUTH_WEB3_EVM_ENABLED', FALSE),
        ],
        'solana' => [
            'enabled' => env('AUTH_WEB3_SOLANA_ENABLED', FALSE),
        ],
        'tron' => [
            'enabled' => env('AUTH_WEB3_TRON_ENABLED', FALSE),
        ],
    ]
];
