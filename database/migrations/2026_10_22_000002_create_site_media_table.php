<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Where operator-uploaded site images (the lobby hero) are kept.
 *
 * Guarded, and also created on demand by App\Models\SiteMedia::ensureTable():
 * deploys on this platform do not reliably run `migrate`, so either path may
 * have created the table first.
 */
return new class extends Migration
{
    public function up(): void
    {
        \App\Models\SiteMedia::ensureTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_media');
    }
};
