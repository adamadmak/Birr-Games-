<?php

return [
    'providers' => ['All studios', 'Lucy Originals', 'Blue Nile', 'Rift Labs', 'Sheba Arcade'],
    'categories' => [
        [
            'id' => 'trending',
            'title' => 'Trending now',
            'eyebrow' => 'Players love these',
            'games' => [
                ['slug' => 'lucky-dice', 'title' => 'Lucky Dice', 'provider' => 'Lucy Originals', 'tag' => 'PLAYABLE', 'art' => 'dice', 'symbol' => '⚄'],
                ['slug' => 'sky-dash', 'title' => 'Sky Dash', 'provider' => 'Rift Labs', 'tag' => 'HOT', 'art' => 'sky', 'symbol' => '✈'],
                ['slug' => 'sheba-gold', 'title' => 'Sheba Gold', 'provider' => 'Sheba Arcade', 'tag' => 'NEW', 'art' => 'gold', 'symbol' => '♛'],
                ['slug' => 'nile-rush', 'title' => 'Nile Rush', 'provider' => 'Blue Nile', 'tag' => '98% RTP', 'art' => 'nile', 'symbol' => '≈'],
                ['slug' => 'coffee-coins', 'title' => 'Coffee Coins', 'provider' => 'Lucy Originals', 'tag' => 'POPULAR', 'art' => 'coffee', 'symbol' => '◆'],
            ],
        ],
        [
            'id' => 'slots',
            'title' => 'Slots',
            'eyebrow' => 'Bright reels, big thrills',
            'games' => [
                ['slug' => 'axum-treasures', 'title' => 'Axum Treasures', 'provider' => 'Sheba Arcade', 'tag' => '10 LINES', 'art' => 'axum', 'symbol' => '✦'],
                ['slug' => 'rift-wilds', 'title' => 'Rift Wilds', 'provider' => 'Rift Labs', 'tag' => 'MEGAWAYS', 'art' => 'rift', 'symbol' => 'W'],
                ['slug' => 'blue-nile-7s', 'title' => 'Blue Nile 7s', 'provider' => 'Blue Nile', 'tag' => 'CLASSIC', 'art' => 'sevens', 'symbol' => '7'],
                ['slug' => 'lion-pride', 'title' => 'Lion Pride', 'provider' => 'Sheba Arcade', 'tag' => 'JACKPOT', 'art' => 'lion', 'symbol' => '♞'],
                ['slug' => 'orbit-bonus', 'title' => 'Orbit Bonus', 'provider' => 'Lucy Originals', 'tag' => 'BONUS BUY', 'art' => 'orbit', 'symbol' => '◎'],
            ],
        ],
        [
            'id' => 'quick',
            'title' => 'Quick games',
            'eyebrow' => 'Fast rounds, instant results',
            'games' => [
                ['slug' => 'color-drop', 'title' => 'Color Drop', 'provider' => 'Lucy Originals', 'tag' => 'QUICK', 'art' => 'color', 'symbol' => '●'],
                ['slug' => 'rocket-ride', 'title' => 'Rocket Ride', 'provider' => 'Rift Labs', 'tag' => 'CRASH', 'art' => 'rocket', 'symbol' => '▲'],
                ['slug' => 'number-pick', 'title' => 'Number Pick', 'provider' => 'Blue Nile', 'tag' => '1–36', 'art' => 'numbers', 'symbol' => '#'],
                ['slug' => 'high-low', 'title' => 'High / Low', 'provider' => 'Lucy Originals', 'tag' => 'CARDS', 'art' => 'cards', 'symbol' => 'A'],
            ],
        ],
    ],
];
