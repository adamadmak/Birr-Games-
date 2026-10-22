<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agents can link their Telegram account to receive instant push notifications when a
 * deposit needs approval or a withdrawal is assigned to them. Linking is a one-time code
 * shown in the portal and submitted via `/link CODE` in the bot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('telegram_chat_id', 40)->nullable()->after('phone')->unique();
            $table->string('telegram_link_code', 12)->nullable()->unique();
            $table->timestamp('telegram_linked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn(['telegram_chat_id', 'telegram_link_code', 'telegram_linked_at']);
        });
    }
};
