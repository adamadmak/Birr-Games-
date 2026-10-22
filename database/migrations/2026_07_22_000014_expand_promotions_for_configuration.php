<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('promotion_type', 40)->default('other')->after('accent');
            $table->string('image_path')->nullable()->after('promotion_type');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['promotion_type', 'image_path']);
        });
    }
};
