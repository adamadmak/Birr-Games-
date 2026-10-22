<?php
/**
 *   1Stake iGaming Platform
 *   -----------------------
 *   view.php
 * 
 *   @copyright  Copyright (c) 1stake, All rights reserved
 *   @author     1stake <sales@1stake.app>
 *   @see        https://1stake.app
*/

return [

    

    'paths' => [
        resource_path('views'),
    ],

    

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        realpath(storage_path('framework/views'))
    ),

];
