<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('pending')->index();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
