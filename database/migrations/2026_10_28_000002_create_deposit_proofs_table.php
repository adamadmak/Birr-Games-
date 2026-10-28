<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Payment screenshots attached to deposits (App\Models\DepositProof).
 *
 * The model also creates this table on first use, because migrations are
 * applied by hand on this host; whichever runs first wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        \App\Models\DepositProof::ensureTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_proofs');
    }
};
