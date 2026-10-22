<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->decimal('credits', 18, 2)->default(10)->change();
            $table->decimal('withdrawable_balance', 18, 2)->default(0)->change();
            $table->unsignedBigInteger('import_source_id')->nullable()->after('id')->index();
            $table->string('member_type', 50)->nullable()->after('role');
            $table->string('affiliate_id', 100)->nullable()->after('member_type');
            $table->string('affiliate_provider', 100)->nullable()->after('affiliate_id');
            $table->string('hashed_username', 191)->nullable()->after('affiliate_provider')->index();
            $table->string('deposit_setting', 100)->nullable()->after('hashed_username');
            $table->string('withdraw_setting', 100)->nullable()->after('deposit_setting');
            $table->timestamp('imported_at')->nullable()->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['import_source_id']);
            $table->dropIndex(['hashed_username']);
            $table->dropColumn([
                'import_source_id', 'member_type', 'affiliate_id', 'affiliate_provider',
                'hashed_username', 'deposit_setting', 'withdraw_setting', 'imported_at',
            ]);
            $table->unsignedBigInteger('credits')->default(10)->change();
            $table->unsignedBigInteger('withdrawable_balance')->default(0)->change();
        });
    }
};
