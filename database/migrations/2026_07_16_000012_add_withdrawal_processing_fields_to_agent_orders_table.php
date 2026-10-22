<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_orders', function (Blueprint $table) {
            $table->string('account_name')->nullable()->after('reference');
            $table->string('account_number')->nullable()->after('account_name');
            $table->foreignId('processed_by_user_id')->nullable()->after('auto_verified')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by_user_id');
            $table->index(['user_id', 'type', 'status'], 'agent_orders_user_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('agent_orders', function (Blueprint $table) {
            $table->dropIndex('agent_orders_user_type_status_index');
            $table->dropConstrainedForeignId('processed_by_user_id');
            $table->dropColumn(['account_name', 'account_number', 'processed_at']);
        });
    }
};
