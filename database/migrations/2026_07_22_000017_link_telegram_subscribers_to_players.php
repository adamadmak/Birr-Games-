<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_subscribers', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->unique()->constrained()->nullOnDelete();
        });

        Schema::create('telegram_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_subscriber_id')->constrained('telegram_subscribers')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('destination', 24)->default('lobby');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_login_tokens');

        Schema::table('telegram_subscribers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
