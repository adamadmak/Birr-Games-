<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   app.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    'version' => '3.2.14',

    

    'name' => env('APP_NAME', 'Lucy Play'),

    /*
    |--------------------------------------------------------------------------
    | Deploy cache token
    |--------------------------------------------------------------------------
    |
    | Required by GET /clear-deploy-cache. With no token configured the route
    | answers 404 to everyone, which is the safe default for an endpoint that
    | resets opcache on a live site.
    |
    */

    'deploy_cache_token' => env('DEPLOY_CACHE_TOKEN'),

    

    'logo' => env('APP_LOGO', '/images/logo/logo.png'),

    'og_image' => env('APP_OG_IMAGE', '/images/home/welcome-guest.webp'),

    

    'env' => env('APP_ENV', 'production'),

    

    'debug' => (bool) env('APP_DEBUG', false),

    

    'url' => env('APP_URL', ''),

    'asset_url' => env('ASSET_URL'),

    'force_ssl' => env('FORCE_SSL', FALSE),

    

    'timezone' => env('APP_TIMEZONE', 'Africa/Addis_Ababa'),

    

    'locale' => env('LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    
    
    'default_locale' => env('LOCALE', 'en'),

    'locales' => [
        'am' => [
            'flag'  => 'et',
            'title' => 'አማርኛ'
        ],
        'en' => [
            'flag'  => 'us',
            'title' => 'English'
        ],
        'ru' => [
            'title' => 'Русский'
        ],
        'de' => [
            'title' => 'Deutsch',
        ],
        'es' => [
            'title' => 'Español',
        ],
        'fr' => [
            'title' => 'Français',
        ],
        'pt' => [
            'title' => 'Português',
        ],
        'nl' => [
            'title' => 'Nederlands',
        ],
        'cs' => [
            'flag'  => 'cz',
            'title' => 'Česky',
        ],
        'it' => [
            'title' => 'Italiano',
        ],
        'fi' => [
            'title' => 'Suomi',
        ],
        'sv' => [
            'flag'  => 'se',
            'title' => 'Svenska',
        ],
        'hu' => [
            'title' => 'Magyar',
        ],
        'el' => [
            'flag'  => 'gr',
            'title' => 'Ελληνικά',
        ],
        'da' => [
            'flag'  => 'dk',
            'title' => 'Dansk',
        ],
        'lv' => [
            'title' => 'Latviešu',
        ],
        'lt' => [
            'title' => 'Lietuvių',
        ],
        'et' => [
            'flag'  => 'ee',
            'title' => 'Eesti',
        ],
        'sk' => [
            'title' => 'Slovenčina',
        ],
        'sl' => [
            'flag'  => 'si',
            'title' => 'Slovenščina',
        ],
        'th' => [
            'title' => 'ภาษาไทย',
        ],
        'ko' => [
            'flag'  => 'kr',
            'title' => '한국어',
        ],
        
        'zh-cn' => [
            'flag'  => 'cn',
            'title' => '简体',
        ],
        
        'zh-tw' => [
            'flag'  => 'tw',
            'title' => '繁体',
        ],
        'ja' => [
            'flag'  => 'jp',
            'title' => '日本語',
        ],
    ],

    'translation_files_folder' => env('TRANSLATION_FILES_FOLDER', 'lang'), 

    

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'hash' => 'da34b0aa975fddd5a90a1eb2bb8cb9d2',

    'hashb' => '0dcd235baec3bed9c8374828935afa0d',

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    

    'csp_allowed_urls' => json_decode(env('CSP_ALLOWED_URLS', '[]')),
];
