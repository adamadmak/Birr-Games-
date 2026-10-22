<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Players are identified by username + phone number. Email addresses were only ever
 * invented to satisfy a NOT NULL column (telegram.123.abc@lucyplay.local), so they are
 * removed from player records; only administrators keep an email, which is what they
 * sign in with.
 */
return new class extends Migration
{
    /** Synthetic address prefixes this platform generated for players. */
    private const SYNTHETIC = ['telegram.%', 'user_%', 'imported.%'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 60)->nullable()->after('name');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        // A phone number is now the player identity, so recover the ones Telegram verified.
        if (Schema::hasColumn('telegram_subscribers', 'phone')) {
            DB::table('telegram_subscribers')
                ->join('users', 'users.id', '=', 'telegram_subscribers.user_id')
                ->whereNotNull('telegram_subscribers.phone')
                ->whereNull('users.phone')
                ->select('users.id', 'telegram_subscribers.phone')
                ->orderBy('users.id')
                ->each(function ($row): void {
                    $clash = DB::table('users')->where('phone', $row->phone)->where('id', '!=', $row->id)->exists();
                    if (! $clash) {
                        DB::table('users')->where('id', $row->id)->update(['phone' => $row->phone]);
                    }
                });
        }

        $taken = [];
        DB::table('users')
            ->leftJoin('telegram_subscribers', 'telegram_subscribers.user_id', '=', 'users.id')
            ->select([
                'users.id', 'users.name', 'users.first_name', 'users.last_name',
                'users.phone', 'users.email', 'users.role',
                'telegram_subscribers.username as telegram_username',
            ])
            ->orderBy('users.id')
            ->each(function ($row) use (&$taken): void {
                $handle = $this->handleFor($row, $taken);
                $taken[$handle] = true;
                DB::table('users')->where('id', $row->id)->update(['username' => $handle]);
            });

        // Drop the invented addresses. Real administrator logins are untouched.
        DB::table('users')
            ->where('role', 'player')
            ->where(function ($query): void {
                foreach (self::SYNTHETIC as $pattern) {
                    $query->orWhere('email', 'like', $pattern);
                }
            })
            ->update(['email' => null, 'email_verified_at' => null]);

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });

        DB::table('users')->whereNull('email')->orderBy('id')->each(function ($row): void {
            DB::table('users')->where('id', $row->id)->update([
                'email' => 'restored.'.$row->id.'.'.Str::lower(Str::random(6)).'@lucyplay.local',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
        });
    }

    /** @param  array<string, bool>  $taken */
    private function handleFor(object $row, array $taken): string
    {
        $candidates = [
            $row->telegram_username ?? null,
            $row->role !== 'player' ? Str::before((string) $row->email, '@') : null,
            trim((string) ($row->first_name ?? '').' '.(string) ($row->last_name ?? '')),
            $row->name ?? null,
        ];

        $base = '';
        foreach ($candidates as $candidate) {
            $base = $this->sanitize($candidate);
            if (strlen($base) >= 3) {
                break;
            }
        }

        if (strlen($base) < 3) {
            $tail = substr(preg_replace('/\D+/', '', (string) $row->phone) ?: (string) $row->id, -4);
            $base = $this->sanitize('player'.$tail);
        }

        $handle = $base;
        $suffix = 1;
        while (isset($taken[$handle]) || DB::table('users')->where('username', $handle)->where('id', '!=', $row->id)->exists()) {
            $suffix++;
            $handle = substr($base, 0, 32 - strlen((string) $suffix) - 1).'_'.$suffix;
        }

        return $handle;
    }

    private function sanitize(?string $value): string
    {
        $handle = strtolower(trim((string) $value));
        $handle = str_replace(['@', ' ', '-', '.', '+'], '_', $handle);
        $handle = preg_replace('/[^a-z0-9_]/', '', $handle) ?? '';
        $handle = preg_replace('/_+/', '_', $handle) ?? '';

        return substr(trim($handle, '_'), 0, 32);
    }
};
