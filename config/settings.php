<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   settings.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [
    'template' => env('TEMPLATE', 'zero'),

    'theme' => [
        'default'   => env('THEME_DEFAULT', 'goldenRubyNight'),
        'is_dark'   => (bool) env('THEME_IS_DARK', TRUE),
        'colors'    => [
            'background'            => env('THEME_COLOR_BACKGROUND', '#000000'),
            'on-background'         => env('THEME_COLOR_ON_BACKGROUND', '#E4E2E4'),
            'app-bar'               => env('THEME_COLOR_APP_BAR', '#252628'),
            'system-bar'            => env('THEME_COLOR_SYSTEM_BAR', '#000000'),
            'surface'               => env('THEME_COLOR_SURFACE', '#252628'),
            'on-surface'            => env('THEME_COLOR_ON_SURFACE', '#E4E2E4'),
            'surface-light'         => env('THEME_COLOR_SURFACE_LIGHT', '#3B3B3D'),
            'surface-variant'       => env('THEME_COLOR_SURFACE_VARIANT', '#3B3B3D'),
            'on-surface-variant'    => env('THEME_COLOR_ON_SURFACE_VARIANT', '#E4E2E4'),
            'on-image'              => env('THEME_COLOR_ON_IMAGE', '#E4E2E4'),
            'footer'                => env('THEME_COLOR_FOOTER', '#252628'),
            'on-footer'             => env('THEME_COLOR_ON_FOOTER', '#E4E2E4'),
            'primary'               => env('THEME_COLOR_PRIMARY', '#0090ff'),
            'on-primary'            => env('THEME_COLOR_ON_PRIMARY', '#ffffff'),
            'primary-container'     => env('THEME_COLOR_PRIMARY_CONTAINER', '#0090ff'),
            'on-primary-container'  => env('THEME_COLOR_ON_PRIMARY_CONTAINER', '#ffffff'),
            'secondary'             => env('THEME_COLOR_SECONDARY', '#FF0090'),
            'on-secondary'          => env('THEME_COLOR_ON_SECONDARY', '#ffffff'),
            'secondary-container'   => env('THEME_COLOR_SECONDARY_CONTAINER', '#FF0090'),
            'on-secondary-container' => env('THEME_COLOR_ON_SECONDARY_CONTAINER', '#ffffff'),
            'link'                  => env('THEME_COLOR_LINK', '#0090ff'),
            'info'                  => env('THEME_COLOR_INFO', '#00E4FF'),
            'warning'               => env('THEME_COLOR_WARNING', '#FFC107'),
            'success'               => env('THEME_COLOR_SUCCESS', '#11FF00'),
            'error'                 => env('THEME_COLOR_ERROR', '#FF1B00'),
            'on-error'              => env('THEME_COLOR_ON_ERROR', '#ffffff'),
        ],
        'background' => env('THEME_BACKGROUND', 'Circles'),
        'fonts' => [
            'body' => [
                'url' => env('THEME_FONT_BODY_URL', 'https://fonts.googleapis.com/css2?family=Goldman:wght@400;700&family=Play&display=swap'),
                'family' => env('THEME_FONT_BODY_FAMILY', 'Play'),
            ],
            'heading' => [
                'url' => env('THEME_FONT_HEADING_URL', ''),
                'family' => env('THEME_FONT_HEADING_FAMILY', 'Goldman'),
            ],
        ],
    ],

    'favicon' => [
        'ico' => env('FAVICON_ICO', '/images/favicon/favicon.ico'),
        'apple_touch' => env('FAVICON_APPLE_TOUCH', '/images/favicon/apple-touch.png'),
        '32x32' => env('FAVICON_32X32', '/images/favicon/32x32.png'),
        '192x192' => env('FAVICON_192X192', '/images/favicon/192x192.png'),
        '512x512' => env('FAVICON_512X512', '/images/favicon/512x512.png'),
        'mask' => env('FAVICON_MASK', '/images/favicon/safari-pinned-tab.svg'),
    ],

    'format' => [
        'number' => [
            'decimal_separator'     => (int) env('FORMAT_NUMBER_DECIMAL_SEPARATOR', ord('.')),
            'thousands_separator'   => (int) env('FORMAT_NUMBER_THOUSANDS_SEPARATOR', ord(',')),
        ]
    ],

    'throttle' => [
        'api' => (int) env('THROTTLE_API', 200),
        'file_upload' => (int) env('THROTTLE_FILE_UPLOAD', 20),
    ],

    'cache' => [
        'leaderboard' => (int) env('CACHE_LEADERBOARD', 60), 
        'admin' => [
            'dashboard' => (int) env('CACHE_ADMIN_DASHBOARD', 60), 
        ],
    ],

    
    'users' => [
        'can_generate_username' => (bool) env('USERS_CAN_GENERATE_USERNAME', TRUE),
        'audit_enabled' => (bool) env('USERS_AUDIT_ENABLED', FALSE),
        'email_auth' => (bool) env('EMAIL_AUTH', TRUE),
        'email_verification' => (bool) env('EMAIL_VERIFICATION', FALSE),
        'create_random_avatar' => (bool) env('USERS_CREATE_RANDOM_AVATAR', TRUE),
        'cookie_consent_required' => (bool) env('USERS_COOKIE_CONSENT_REQUIRED', FALSE),
        'allow_name_change' => (bool) env('USERS_ALLOW_NAME_CHANGE', FALSE),
        'can_view_profiles' => (bool) env('USERS_CAN_VIEW_PROFILES', TRUE),
        'fingerprint_enabled' => (bool) env('USERS_FINGERPRINT_ENABLED', FALSE),
        'fields' => json_decode(env('USERS_FIELDS', json_encode([]))),
        'flags' => json_decode(env('USERS_FLAGS', json_encode([
            ['id' => 'high_risk', 'title' => 'High risk', 'color' => '#FF0000']
        ])))
    ],

    
    'tips' => [
        'enabled' => (bool) env('TIPS_ENABLED', TRUE),
        'max_amount' => (int) env('TIPS_MAX_AMOUNT', 500),
        'interval' => (int) env('TIPS_INTERVAL', 24),
    ],

    
    'affiliate' => [
        'enabled' => (bool) env('AFFILIATE_ENABLED', true),

        'allow_same_ip' => (bool) env('AFFILIATE_ALLOW_SAME_IP', false),

        'auto_approval_frequency' => env('AFFILIATE_AUTO_APPROVAL_FREQUENCY', 'weekly'),

        'currency' => env('AFFILIATE_CURRENCY'),

        
        'commissions' => [
            1  => json_decode(env('AFFILIATE_COMMISSIONS_SIGN_UP', json_encode([100, 50, 25]))),
            6  => json_decode(env('AFFILIATE_COMMISSIONS_EMAIL_VERIFICATION', json_encode([50, 25, 10]))),
            7  => json_decode(env('AFFILIATE_COMMISSIONS_KYC', json_encode([50, 25, 10]))),
            2  => json_decode(env('AFFILIATE_COMMISSIONS_GAME_LOSS', json_encode([10, 5, 1]))),
            3  => json_decode(env('AFFILIATE_COMMISSIONS_GAME_WIN', json_encode([10, 5, 1]))),
            5  => json_decode(env('AFFILIATE_COMMISSIONS_RAFFLE_TICKET', json_encode([20, 10, 5]))),
            4  => json_decode(env('AFFILIATE_COMMISSIONS_DEPOSIT', json_encode([5, 3, 1]))),
        ],
    ],

    'kyc' => [
        'enabled' => (bool) env('KYC_ENABLED', FALSE),
        'provider' => env('KYC_PROVIDER', 'manual'),
        'pages' => json_decode(env('KYC_PAGES', json_encode(['game', 'cashback', 'faucet', 'deposit', 'withdrawal']))),
    ],

    'notifications' => [
        'admin' => [
            'email' => env('NOTIFICATIONS_ADMIN_EMAIL', ''),
            'data_deletion_email' => env('NOTIFICATIONS_ADMIN_DATA_DELETION_EMAIL', !app()->runningInConsole() ? 'info@' . request()->getHost() : ''),
            'registration' => [
                'enabled' => (bool) env('NOTIFICATIONS_ADMIN_REGISTRATION_ENABLED', FALSE),
            ],
            'kyc' => [
                'enabled' => (bool) env('NOTIFICATIONS_ADMIN_KYC_ENABLED', FALSE),
            ],
            'game' => [
                'win' => [
                    'enabled' => (bool) env('NOTIFICATIONS_ADMIN_GAME_WIN_ENABLED', FALSE),
                    'treshold' => (int) env('NOTIFICATIONS_ADMIN_GAME_WIN_TRESHOLD', 1000),
                ],
                'loss' => [
                    'enabled' => (bool) env('NOTIFICATIONS_ADMIN_GAME_LOSS_ENABLED', FALSE),
                    'treshold' => (int) env('NOTIFICATIONS_ADMIN_GAME_LOSS_TRESHOLD', 1000),
                ]
            ],
        ],
    ],

    
    'games' => [
        'playing_cards' => [
            'front_image' => env('GAMES_PLAYING_CARDS_FRONT_IMAGE', '/images/games/playing-cards/front.svg'),
            'back_image' => env('GAMES_PLAYING_CARDS_BACK_IMAGE', '/images/games/playing-cards/back.svg'),
            'deck' => env('GAMES_PLAYING_CARDS_DECK', 'poker'),
        ],
        'multiplayer' => [
            
            'rooms_creation_limit' => (int) env('GAMES_MULTIPLAYER_ROOMS_CREATION_LIMIT', 2)
        ]
    ],

    'bots' => [
        'games' => [
            
            
            'frequency' => env('BOTS_GAMES_FREQUENCY', 'hourly'),
            'min_count' => (int) env('BOTS_GAMES_MIN_COUNT', 1),
            'max_count' => (int) env('BOTS_GAMES_MAX_COUNT', 10),
            'min_bet'   => (int) env('BOTS_GAMES_MIN_BET'),
            'max_bet'   => (int) env('BOTS_GAMES_MAX_BET'),
        ],
        'temp_bets' => [
            'amounts' => json_decode(env('BOTS_TEMP_BETS_AMOUNTS', json_encode([1,2,3,4,5,6,7,8,9,10,15,20,25,30,35,40,45,50,55,60,65,70,75,80,85,90,95,100,125,150,175,200,250,300,350,400,450,500]))),
            'win_probability' => (float) env('BOTS_TEMP_BETS_WIN_PROBABILITY', 30),
            'max_multiplier' => (float) env('BOTS_TEMP_BETS_MAX_MULTIPLIER', 10),
            'interval' => json_decode(env('BOTS_TEMP_BETS_INTERVAL', json_encode([500, 1000]))), 
        ]
    ],

    'bet_stream' => [
        'display_count' => (int) env('BET_STREAM_DISPLAY_COUNT', 10),
        'big_bet_threshold' => (int) env('BET_STREAM_BIG_BET_THRESHOLD', 100),
    ],

    'interface' => [
        'navbar' => [
            'visible' => (bool) env('INTERFACE_NAVBAR_VISIBLE', TRUE),
        ],
        'online' => [
            'enabled' => (bool) env('INTERFACE_ONLINE_ENABLED', FALSE),
        ],
        'system_bar' => [
            'enabled' => (bool) env('INTERFACE_SYSTEM_BAR_ENABLED', FALSE),
        ],
        'chat' => [
            'enabled' => (bool) env('CHAT_ENABLED', FALSE),
            'visible' => (bool) env('CHAT_VISIBLE', FALSE),
            'message_max_length' => (int) env('CHAT_MESSAGE_MAX_LENGTH', 150)
        ],
        'locale' => [
            'detect_from_browser' => (bool) env('INTERFACE_LOCALE_DETECT_FROM_BROWSER', TRUE),
            'can_be_changed' => (bool) env('INTERFACE_LOCALE_CAN_BE_CHANGED', TRUE),
        ],
        'sound' => [
            'enabled_by_default' => (bool) env('INTERFACE_SOUND_ENABLED_BY_DEFAULT', TRUE),
        ],
        'game_feed' => [
            'enabled' => (bool) env('INTERFACE_GAME_FEED_ENABLED', TRUE),
            'enabled_by_default' => (bool) env('INTERFACE_GAME_FEED_ENABLED_BY_DEFAULT', TRUE),
            'delay' => (int) env('INTERFACE_GAME_FEED_DELAY', 0),
            'timeout' => (int) env('INTERFACE_GAME_FEED_TIMEOUT', 10)
        ]
    ],

    'content' => [
        'home' => [
            'game_thumb' => [
                'width' => (int) env('CONTENT_HOME_THUMB_WIDTH', 150),
                'scroll_count' => (int) env('CONTENT_HOME_THUMB_SCROLL_COUNT', 5),
                'aspect_ratio' => (float) env('CONTENT_HOME_THUMB_ASPECT_RATIO', 0.75), 
            ],
            'welcome_card' => [
                'user_image' => env('CONTENT_HOME_WELCOME_CARD_USER_IMAGE', '/images/home/welcome-user.webp'),
                'guest_image' => env('CONTENT_HOME_WELCOME_CARD_GUEST_IMAGE', '/images/home/welcome-guest.webp'),
            ],
            'bonus_cards' => json_decode(env('CONTENT_HOME_BONUS_CARDS', json_encode([
                [
                    'title' => 'Sign up',
                    'description' => 'Sign up and receive free registration bonus.',
                    'icon' => 'mdi-account-plus',
                    'chip' => '1000 CREDITS',
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'Email verification',
                    'description' => 'Verify your email and get additional bonus.',
                    'icon' => 'mdi-email-check',
                    'chip' => '150 credits',
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'KYC',
                    'description' => 'Verify your identity to unlock additional cash rewards.',
                    'icon' => 'mdi-shield-account',
                    'chip' => '100 credits',
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'Reload bonus',
                    'description' => 'Boost your first deposit with an extra reward.',
                    'icon' => 'mdi-cash-plus',
                    'chip' => '50% on first deposit',
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'Cashback',
                    'description' => 'Get a portion of your money back when you experience a loss.',
                    'icon' => 'mdi-cash-refund',
                    'chip' => 'up to 25% of losses',
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'Faucet',
                    'description' => 'Enjoy recurring free bonuses to boost your balance.',
                    'icon' => 'mdi-cash-100',
                    'chip' => 'Up to 100 credits hourly',
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'Chat rain',
                    'description' => 'Be the top chatter and unlock a special reward.',
                    'icon' => 'mdi-weather-pouring',
                    'chip' => null,
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
                [
                    'title' => 'Affiliate program',
                    'description' => 'Refer your friends to the casino and get bonuses when they play games.',
                    'icon' => 'mdi-link',
                    'chip' => null,
                    'bg_image_url' => '/images/home/bonus.webp',
                ],
            ]))),
            'slider' => json_decode(env('CONTENT_HOME_SLIDER', json_encode([
                'height' => 600,
                'height_mobile' => 300,
                'navigation' => false,
                'pagination' => true,
                'overlay' => false,
                'cycle' => true,
                'interval' => 5, 
                'transition' => 'fade',
                'slides' => [
                    [
                        'title' => '1Stake',
                        'subtitle' => 'Fair online casino games',
                        'image' => [
                            'url' => '/images/home/banner.jpg',
                        ],
                        'link' => [
                            'title' => '',
                            'url' => '',
                        ]
                    ]
                ]
            ]))),
            'card_classes' => env('CONTENT_HOME_CARD_CLASSES', 'v-col-6 v-col-sm-4 v-col-md-4 v-col-lg-3 v-col-xl-2'),
            'games' => [
                'display_style' => env('CONTENT_HOME_GAMES_DISPLAY_STYLE', 'card'),
                'filter' => (bool) env('CONTENT_HOME_GAMES_FILTER', FALSE),
                'display_count' => (int) env('CONTENT_HOME_GAMES_DISPLAY_COUNT', 8),
                'load_count' => (int) env('CONTENT_HOME_GAMES_LOAD_COUNT', 8),
            ],
            'provider_games' => [
                'display_style' => env('CONTENT_HOME_PROVIDER_GAMES_DISPLAY_STYLE', 'thumb'),
                'display_count' => (int) env('CONTENT_HOME_PROVIDER_GAMES_DISPLAY_COUNT', 8),
                'load_count' => (int) env('CONTENT_HOME_PROVIDER_GAMES_LOAD_COUNT', 8),
                'featured_categories' => json_decode(env('CONTENT_HOME_PROVIDER_GAMES_FEATURED_CATEGORIES', json_encode([
                    [
                        'title' => 'Live Casino', 'icon' => 'mdi-cards-spade',
                        'categories' => ['Live']
                    ], [
                        'title' => 'Slots', 'icon' => 'mdi-slot-machine', 'categories' => ['Slot', 'Slots']
                    ], [
                        'title' => 'Crash', 'icon' => 'mdi-rocket-launch', 'categories' => [
                            'Crash', 6087, 6080, 'crash_hamster_crash', 'kite_festival_crash_game', 'paper_lanterns_crash_game', '5687', '6001'
                        ]
                    ], [
                        'title' => 'Table games', 'icon' => 'mdi-cards-playing', 'categories' => [
                            'Poker', 'Blackjack', 'Baccarat', 'baccarat_mg', 'blackjack_mg', 'holdem_mg', 'Table'
                        ]
                    ], [
                        'title' => 'Sports', 'icon' => 'mdi-soccer', 'categories' => [
                            '5831', '5643', '6013', '5180', '6019', '1635', '5928', '583', '6116'
                        ]
                    ], ['title' => 'Roulette', 'icon' => 'mdi-cast-audio-variant', 'categories' => ['Roulette', '5523', '5776', '5736']]
                ])), JSON_OBJECT_AS_ARRAY),
                'sort_game_id_reverse' => (bool) env('CONTENT_HOME_PROVIDER_GAMES_SORT_GAME_ID_REVERSE', TRUE),
            ],
            'raffles' => [
                'display_style' => env('CONTENT_HOME_RAFFLES_DISPLAY_STYLE', 'card'),
            ]
        ],
        'auth' => [
            'background_image' => env('CONTENT_AUTH_BACKGROUND_IMAGE', '/images/auth/auth.webp'),
        ],
        'social' => json_decode(env('CONTENT_SOCIAL', json_encode([
            [
                'title' => 'Send us an email',
                'icon' => 'mdi-at',
                'color' => 'rgb(166, 166, 166)',
                'url' => '/'
            ],
            [
                'title' => 'Follow us on Facebook',
                'icon' => 'mdi-facebook',
                'color' => '#4267B2',
                'url' => '/'
            ],
            [
                'title' => 'Follow us on Instagram',
                'icon' => 'mdi-instagram',
                'color' => '#C13584',
                'url' => '/'
            ],
            [
                'title' => 'Follow us on Twitter',
                'icon' => 'mdi-twitter',
                'color' => '#00acee',
                'url' => '/'
            ],
            [
                'title' => 'Follow us on Twitch',
                'icon' => 'mdi-twitch',
                'color' => '#6441a5',
                'url' => '/'
            ]
        ]))),
        'links' => json_decode(env('CONTENT_LINKS', json_encode([]))),
        'pages' => json_decode(env('CONTENT_PAGES', json_encode([
                [
                    'id' => 'provably-fair',
                    'title' => 'Provably fair',
                    'group' => 'Policy',
                    'icon' => 'mdi-certificate'
                ],
                [
                    'id' => 'privacy-policy',
                    'title' => 'Privacy policy',
                    'group' => 'Policy',
                    'icon' => 'mdi-lock'
                ],
                [
                    'id' => 'terms-of-use',
                    'title' => 'Terms of use',
                    'group' => 'Policy',
                    'icon' => 'mdi-file-document'
                ],
        ]))),
        'menu' => [
            'main' => json_decode(env('CONTENT_MENU_MAIN', json_encode([]))), 
            'footer' => json_decode(env('CONTENT_MENU_FOOTER', json_encode(['/pages/provably-fair', '/pages/privacy-policy', '/pages/terms-of-use']))),
        ]
    ],

    'vip' => [
        'enabled' => (bool) env('VIP_ENABLED', false),
    ]
];
