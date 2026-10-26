<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   services.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'lucygames_bot'),
        'app_url' => env('TELEGRAM_APP_URL'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'timeout' => (int) env('TELEGRAM_TIMEOUT', 20),
        'ca_bundle' => env('TELEGRAM_CA_BUNDLE'),
        'queue_connection' => env('TELEGRAM_QUEUE_CONNECTION', 'database'),
    ],

    'bigbang' => [
        'base_url' => env('BIGBANG_API_BASE_URL', 'https://api.bigbangcasino.bet/api/v1'),
        'api_key' => env('BIGBANG_API_KEY', 'ek_96a92ecfbdd6d7f2877554c83841e0af8f33f36acadb3425'),
        'mode' => env('BIGBANG_MODE', 'live'),
        'currency' => env('BIGBANG_CURRENCY', 'ETB'),
        'provider' => env('BIGBANG_PROVIDER'),
        'callback_token' => env('BIGBANG_CALLBACK_TOKEN'),
        'webhook_secret' => env('BIGBANG_WEBHOOK_SECRET'),
        'timeout' => (int) env('BIGBANG_TIMEOUT', 20),
        'ca_bundle' => env('BIGBANG_CA_BUNDLE'),
        'sync_page_size' => (int) env('BIGBANG_SYNC_PAGE_SIZE', 200),
        // Guards the HTTP catalog-refresh endpoint an external timer calls, because
        // Laravel's scheduler does not run on this host. Carries a literal default for
        // the same reason `callback_token` needs one: the build caches config without
        // the runtime env, so an env-only secret never arrives. Rotate here, not in env.
        'sync_token' => env('BIGBANG_SYNC_TOKEN', 'a4c40206893828818071ba567c3e2d06bafdd552bd79800e52b88cee8cb1acf3'),
        // Runaway guard, not a size limit: the sync bounds itself by the row count
        // the feed reports. At 30 it capped the read at 3,600 of 5,106 games, and a
        // read that never completes is a read that can never retire anything.
        'max_sync_pages' => (int) env('BIGBANG_MAX_SYNC_PAGES', 200),
        'allowed_launch_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BIGBANG_ALLOWED_LAUNCH_HOSTS', 'api.bigbangcasino.bet,bigbangcasino.bet'))
        ))),
    ],

    // WinHouse sportsbook (iframe.winhouse.bet), seamless wallet.
    //
    // Every key here is env-only, with NO default on purpose. App\Support\Winhouse
    // resolves these and only falls through a level when it finds an empty value,
    // so a default written here would mask the later levels — a literal 'sandbox'
    // default did exactly that and left the wallet refusing every real bet with
    // "Live wallet sessions are not enabled". The fail-closed behaviour lives in
    // that resolver instead: unknown host or nothing configured ⇒ sandbox.
    'winhouse' => [
        'mode' => env('WINHOUSE_MODE'),
        'currency' => env('WINHOUSE_CURRENCY'),
        'iframe_key' => env('WINHOUSE_IFRAME_KEY'),
        'api_key' => env('WINHOUSE_API_KEY'),
        'callback_token' => env('WINHOUSE_CALLBACK_TOKEN'),
        // Optional CA bundle for the tickets API client, like the Telegram one.
        'ca_bundle' => env('WINHOUSE_CA_BUNDLE'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    
    'gtm' => [
        'container_id' => env('GTM_CONTAINER_ID'),
    ],

    
    'recaptcha' => [
        'project_id' => env('RECAPTCHA_PROJECT_ID'),
        'key_id' => env('RECAPTCHA_KEY_ID'),
        'key_file' => env('RECAPTCHA_KEY_FILE'), 
        'key_has_challenge' => (bool) env('RECAPTCHA_KEY_HAS_CHALLENGE', false),
        'min_score' => env('RECAPTCHA_MIN_SCORE', 0.5),
    ],

    'sendgrid' => [
        'api_key' => env('SENDGRID_API_KEY', env('MAIL_PASSWORD')),
        'list_id' => env('SENDGRID_LIST_ID'),
    ],

    'blueassure' => [
        'user' => env('BLUEASSURE_USER'),
        'password' => env('BLUEASSURE_PASSWORD'),
    ],

    'postaffiliatepro' => [
        'script' => [
            'id' => env('POSTAFFILIATEPRO_SCRIPT_ID'),
            'url' => env('POSTAFFILIATEPRO_SCRIPT_URL'),
        ],
        'api' => [
            'url' => env('POSTAFFILIATEPRO_API_URL'),
        ],
        'signup_action_code' => env('POSTAFFILIATEPRO_SIGNUP_ACTION_CODE'),
        'cron' => [
            'time' => env('POSTAFFILIATEPRO_CRON_TIME', '02:00'),
        ]
    ],

    'amcharts' => [
        'license' => env('AMCHARTS_LICENSE', 'AM5C123456789'),
    ],

    // QTech (QT) Common Wallet. QTech run the games and call back here for
    // every bet, win and refund.
    //
    // Env-only with NO defaults, on purpose: App\Support\Qtech falls through
    // to platform_settings and the process env only when it finds an empty
    // value, so a default written here would mask those. And unlike the other
    // providers there is no committed literal to fall back on — the pass-key
    // guards a debit endpoint, so "not configured" must mean "authenticate
    // nobody".
    'qtech' => [
        'pass_key' => env('QTECH_PASS_KEY'),
        'pass_key_staging' => env('QTECH_PASS_KEY_STAGING'),
        // The API half: our credentials for calling QTech.
        'api_base_url' => env('QTECH_API_BASE_URL'),
        'api_username' => env('QTECH_API_USERNAME'),
        'api_password' => env('QTECH_API_PASSWORD'),
        'sync_token' => env('QTECH_SYNC_TOKEN'),
        'country' => env('QTECH_COUNTRY'),
        'lang' => env('QTECH_LANG'),
        'allowed_launch_hosts' => env('QTECH_ALLOWED_LAUNCH_HOSTS'),
        'currency' => env('QTECH_CURRENCY'),
        'session_hours' => env('QTECH_SESSION_HOURS'),
    ],

    'support_chat' => [
        'provider' => env('SUPPORT_CHAT_PROVIDER'),
        'is_hidden' => env('SUPPORT_CHAT_IS_HIDDEN', true),
        'providers' => [
            'crisp' => [
                'website_id' => env('SUPPORT_CHAT_CRISP_WEBSITE_ID'),
                'identifier' => env('SUPPORT_CHAT_CRISP_IDENTIFIER'),
                'key' => env('SUPPORT_CHAT_CRISP_KEY'),
            ],
            'tawk' => [
                'property_id' => env('SUPPORT_CHAT_TAWK_PROPERTY_ID'),
                'widget_id' => env('SUPPORT_CHAT_TAWK_WIDGET_ID'),
            ],
        ],
    ],

    'api' => [
        'crypto' => [
            'provider' => env('API_CRYPTO_PROVIDER'),
            'providers' => [
                'random' => [
                    'min_value' => (float) env('API_CRYPTO_PROVIDERS_RANDOM_MIN_VALUE', 100),
                    'max_value' => (float) env('API_CRYPTO_PROVIDERS_RANDOM_MAX_VALUE', 200),
                    'series_length' => (int) env('API_CRYPTO_PROVIDERS_RANDOM_SERIES_LENGTH', 300),
                    'decimals' => (int) env('API_CRYPTO_PROVIDERS_RANDOM_DECIMALS', 2),
                ],
                'binance' => [
                    'base_url' => env('API_CRYPTO_PROVIDERS_BINANCE_BASE_URL', 'https://data-api.binance.vision/api/v3/'),
                    'symbols' => json_decode(env('API_CRYPTO_PROVIDERS_BINANCE_SYMBOLS', json_encode(['BTC','ETH','XRP','BNB','SOL','TRX','DOGE','ADA','HYPE','XLM','SUI','BCH','LINK','HEAR','LTC']))),
                    'currencies' => json_decode(env('API_CRYPTO_PROVIDERS_BINANCE_CURRENCIES', json_encode(['USDT', 'EUR']))),
                    'ws_url' => env('API_CRYPTO_PROVIDERS_BINANCE_WS_URL', 'wss://data-stream.binance.vision/ws/%s@ticker'),
                ],
                'coincap' => [
                    'base_url' => env('API_CRYPTO_PROVIDERS_COINCAP_BASE_URL', 'https://rest.coincap.io/v3/'),
                    'api_key' => env('API_CRYPTO_PROVIDERS_COINCAP_API_KEY'),
                ],
                'cryptocompare' => [
                    'base_url' => env('API_CRYPTO_PROVIDERS_CRYPTOCOMPARE_BASE_URL', 'https://min-api.cryptocompare.com/data/'),
                    'api_key' => env('API_CRYPTO_PROVIDERS_CRYPTOCOMPARE_API_KEY'),
                ]
            ]
        ]
    ],
];
