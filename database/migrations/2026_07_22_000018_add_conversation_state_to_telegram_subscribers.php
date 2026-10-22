<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_subscribers', function (Blueprint $table) {
            $table->string('pending_action', 40)->nullable()->after('active');
            $table->json('pending_payload')->nullable()->after('pending_action');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_subscribers', function (Blueprint $table) {
            $table->dropColumn(['pending_action', 'pending_payload']);
        });
    }
};
