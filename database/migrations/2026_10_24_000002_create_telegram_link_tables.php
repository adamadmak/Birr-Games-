<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A one-time code a signed-in player takes to the bot to connect their
         * Telegram to their website account. Only a hash is stored, the code is
         * short-lived, and it is bound to one account.
         */
        if (! Schema::hasTable('telegram_link_codes')) {
            Schema::create('telegram_link_codes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('code_hash', 64)->unique();
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->dateTime('expires_at');
                $table->dateTime('used_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        /*
         * Bot messages that carry a password. They are deleted from the chat a few
         * minutes after they are sent, so a sign-in password does not sit in the
         * conversation history for ever.
         */
        if (! Schema::hasTable('telegram_secret_messages')) {
            Schema::create('telegram_secret_messages', function (Blueprint $table) {
                $table->id();
                $table->bigInteger('chat_id');
                $table->bigInteger('message_id');
                $table->dateTime('delete_after');
                $table->timestamps();
                $table->index('delete_after');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_secret_messages');
        Schema::dropIfExists('telegram_link_codes');
    }
};
