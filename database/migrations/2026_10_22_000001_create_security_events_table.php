<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registration and sign-in attempts, kept for abuse monitoring.
 *
 * Guarded like the other recent migrations: deploys do not reliably run
 * `migrate` on every brand, so a table may already have been created by hand
 * and re-creating it would abort the whole run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_events')) {
            Schema::create('security_events', function (Blueprint $table) {
                $table->id();
                $table->string('type', 30);
                $table->string('outcome', 20);
                $table->string('reason', 60)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                // What the visitor typed as their phone, handle or email — the
                // thing an administrator searches for when a player complains.
                $table->string('identifier', 190)->nullable();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['type', 'created_at'], 'security_events_type_created_idx');
                $table->index(['ip', 'type', 'created_at'], 'security_events_ip_type_created_idx');
                $table->index(['outcome', 'created_at'], 'security_events_outcome_created_idx');
            });
        }

        if (! Schema::hasColumn('users', 'registration_ip')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('registration_ip', 45)->nullable()->after('remember_token')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'registration_ip')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['registration_ip']);
                $table->dropColumn('registration_ip');
            });
        }

        Schema::dropIfExists('security_events');
    }
};
