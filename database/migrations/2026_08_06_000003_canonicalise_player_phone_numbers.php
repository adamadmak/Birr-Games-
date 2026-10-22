<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The unique index on users.phone compares literal strings, so the same person could be
 * stored three times as 0983900800, 983900800 and 251983900800 — one per entry point
 * (web form, CSV import, Telegram). Every number is rewritten to one canonical
 * international form so a duplicate can no longer hide behind its formatting.
 *
 * Numbers that would collide with an existing account are left untouched: those are real
 * duplicate accounts and merging wallets is an operator decision, made with
 * `php artisan players:merge-duplicates --apply`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $skipped = [];

        DB::table('users')
            ->whereNotNull('phone')
            ->select(['id', 'phone', 'role'])
            ->orderBy('id')
            ->each(function ($row) use (&$skipped): void {
                $canonical = PhoneNumber::normalize($row->phone);

                if ($canonical === null || $canonical === $row->phone) {
                    return;
                }

                $clash = DB::table('users')
                    ->where('phone', $canonical)
                    ->where('id', '!=', $row->id)
                    ->value('id');

                if ($clash !== null) {
                    $skipped[] = ['user_id' => $row->id, 'phone' => $row->phone, 'conflicts_with' => $clash];

                    return;
                }

                DB::table('users')->where('id', $row->id)->update(['phone' => $canonical]);
            });

        if ($skipped !== []) {
            Log::warning('Phone numbers left unchanged because another account already uses the canonical form. Run players:merge-duplicates to consolidate them.', [
                'accounts' => $skipped,
            ]);
        }
    }

    public function down(): void
    {
        // Canonical numbers are a strict improvement; the original spellings are not restorable.
    }
};
