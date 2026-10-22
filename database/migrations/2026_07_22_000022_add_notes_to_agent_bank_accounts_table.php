<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_bank_accounts', function (Blueprint $table): void {
            $table->string('notes', 500)->nullable()->after('account_number');
        });
    }

    public function down(): void
    {
        Schema::table('agent_bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
