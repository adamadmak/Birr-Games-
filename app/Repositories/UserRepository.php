<?php

namespace App\Repositories;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Username;
use InvalidArgumentException;

class UserRepository
{
    /**
     * Every player is created through here, so this is where the two identity rules are
     * enforced: a verified phone number is mandatory, and the account gets a readable
     * handle instead of an invented email address.
     */
    public static function create(array $attributes): User
    {
        $attributes['role'] ??= User::ROLE_PLAYER;
        $attributes['credits'] ??= (int) PlatformSetting::valueFor('welcome_credits', 5);

        if ($attributes['role'] === User::ROLE_PLAYER) {
            $phone = PhoneNumber::normalize($attributes['phone'] ?? null);

            if ($phone === null) {
                throw new InvalidArgumentException('A player account cannot be created without a phone number.');
            }

            $attributes['phone'] = $phone;
            $attributes['username'] = Username::unique(
                [
                    $attributes['username'] ?? null,
                    $attributes['name'] ?? null,
                    trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? '')),
                ],
                $phone,
            );
            // Players do not have email addresses; only administrators sign in with one.
            $attributes['email'] = $attributes['email'] ?? null;
        }

        return User::create($attributes);
    }
}
