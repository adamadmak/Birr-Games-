<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_subscribers', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->after('last_name')->index();
            $table->timestamp('phone_shared_at')->nullable()->after('phone');
        });

        // Backfill the phone number of subscribers already linked to a player so
        // existing bot users are not asked to re-share a contact they confirmed.
        DB::table('telegram_subscribers')
            ->join('users', 'users.id', '=', 'telegram_subscribers.user_id')
            ->whereNotNull('users.phone')
            ->select('telegram_subscribers.id', 'users.phone')
            ->orderBy('telegram_subscribers.id')
            ->each(function ($row): void {
                DB::table('telegram_subscribers')->where('id', $row->id)->update([
                    'phone' => $row->phone,
                    'phone_shared_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('telegram_subscribers', function (Blueprint $table): void {
            $table->dropIndex(['phone']);
            $table->dropColumn(['phone', 'phone_shared_at']);
        });
    }
};
