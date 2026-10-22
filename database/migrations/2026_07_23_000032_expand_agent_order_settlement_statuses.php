<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->enum('status', [
                'pending',
                'processing',
                'payout_submitted',
                'success',
                'rejected',
                'cancelled',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->enum('status', [
                'pending',
                'success',
                'rejected',
                'cancelled',
            ])->default('pending')->change();
        });
    }
};
