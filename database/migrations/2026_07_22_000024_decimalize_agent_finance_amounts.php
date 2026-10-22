<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->decimal('balance', 18, 2)->default(0)->change();
        });
        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->decimal('amount', 18, 2)->change();
            $table->decimal('commission', 18, 2)->default(0)->change();
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->decimal('amount', 18, 2)->change();
            $table->decimal('fee', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->unsignedBigInteger('balance')->default(0)->change();
        });
        Schema::table('agent_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('amount')->change();
            $table->unsignedBigInteger('commission')->default(0)->change();
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->bigInteger('amount')->change();
            $table->unsignedBigInteger('fee')->default(0)->change();
        });
    }
};
