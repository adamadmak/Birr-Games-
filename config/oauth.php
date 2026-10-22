<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   oauth.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    

    'facebook' => [
        'client_id'     => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/facebook/callback', 
        'image'         => '/images/auth/facebook.png',
    ],

    'twitter' => [
        'oauth'         => 2,
        'client_id'     => env('TWITTER_CLIENT_ID'),
        'client_secret' => env('TWITTER_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/twitter/callback', 
        'image'         => '/images/auth/x.png',
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/google/callback', 
        'image'         => '/images/auth/google.png',
    ],

    'linkedin' => [
        'client_id'     => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/linkedin/callback', 
    ],

    'github' => [
        'client_id'     => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/github/callback', 
        'image'         => '/images/auth/github.png',
    ],

    'yahoo' => [
        'client_id'     => env('YAHOO_CLIENT_ID'),
        'client_secret' => env('YAHOO_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/yahoo/callback', 
    ],

    'coinbase' => [
        'client_id'     => env('COINBASE_CLIENT_ID'),
        'client_secret' => env('COINBASE_CLIENT_SECRET'),
        'redirect'      => '/api/oauth/coinbase/callback', 
        'image'         => '/images/auth/coinbase.svg',
        'mdi'           => 'circle-multiple'
    ],

    'telegram' => [
        'client_id'     => env('TELEGRAM_CLIENT_ID'), 
        'client_secret' => env('TELEGRAM_CLIENT_SECRET'), 
        'redirect'      => '/api/oauth/telegram/callback',
        'image'         => '/images/auth/telegram.svg'
    ],
];
