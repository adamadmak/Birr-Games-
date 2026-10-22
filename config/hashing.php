<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   hashing.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    

    'driver' => 'bcrypt',

    

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 10),
    ],

    

    'argon' => [
        'memory' => 1024,
        'threads' => 2,
        'time' => 2,
    ],

    'key' => '',

    'bytes' => [],
];
