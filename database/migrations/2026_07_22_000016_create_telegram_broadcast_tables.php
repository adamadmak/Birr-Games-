<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('caption');
            $table->string('image_path')->nullable();
            $table->json('buttons')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_subscribers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->bigInteger('chat_id')->unique();
            $table->string('username')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('language_code', 12)->nullable();
            $table->string('chat_type', 24)->default('private');
            $table->boolean('active')->default(true)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('telegram_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_template_id')->nullable()->constrained('telegram_templates')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('caption');
            $table->string('image_path')->nullable();
            $table->json('buttons')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_broadcast_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_broadcast_id')->constrained('telegram_broadcasts')->cascadeOnDelete();
            $table->foreignId('telegram_subscriber_id')->nullable()->constrained('telegram_subscribers')->nullOnDelete();
            $table->bigInteger('chat_id');
            $table->string('status', 24)->default('pending');
            $table->bigInteger('telegram_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['telegram_broadcast_id', 'chat_id'], 'telegram_broadcast_chat_unique');
            $table->index(['telegram_broadcast_id', 'status'], 'telegram_delivery_status_index');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('telegram_broadcast_deliveries');
        Schema::dropIfExists('telegram_broadcasts');
        Schema::dropIfExists('telegram_subscribers');
        Schema::dropIfExists('telegram_templates');
    }
};
