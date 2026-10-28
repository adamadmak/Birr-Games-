<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    private static int $sequence = 0;

    public function definition(): array
    {
        $n = ++self::$sequence;
        // Ethiopian mobile numbers: 09 + 8 digits, unique per row.
        $phone = '09'.str_pad((string) (10000000 + $n), 8, '0', STR_PAD_LEFT);

        return [
            'name' => 'Player '.$n,
            'first_name' => 'Player',
            'last_name' => (string) $n,
            'username' => 'player'.$n,
            'phone' => $phone,
            'email' => null,
            'email_verified_at' => null,
            'password' => 'Secret123!',
            'role' => User::ROLE_PLAYER,
            'credits' => 0,
            'withdrawable_balance' => 0,
            'contact_confirmed' => true,
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function withVerifiedEmail(?string $email = null): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => $email ?? ($attributes['username'].'@example.test'),
            'email_verified_at' => now(),
        ]);
    }

    public function admin(?string $adminRole = User::ADMIN_ROLE_SUPER): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
            'admin_role' => $adminRole,
            'email' => $attributes['username'].'@example.test',
            'email_verified_at' => now(),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
