<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M-PESA as a deposit and withdrawal provider (ported from kulubet, 28 Oct 2026).
 *
 * Seeded here rather than left to the admin panel so both brands come up with
 * the same method list, the way Telebirr and Ebirr already do. Safaricom's
 * wallet behaves like those two — a mobile number, money both ways — so it is a
 * `provider` rather than a `bank`.
 *
 * The artwork ships as MPSA.png; `PaymentGateway::LOGO_FILES` maps the code to
 * it, because the file is named for the brand and the gateway for the service.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Operators can create gateways by hand, so this may already be here.
        // Matching on the code rather than the name: the name is what an
        // operator edits, and re-adding a duplicate would split the agent
        // accounts routed through it across two rows.
        if (DB::table('payment_gateways')->where('code', 'mpesa')->exists()) {
            return;
        }

        DB::table('payment_gateways')->insert([
            'name' => 'M-PESA',
            'code' => 'mpesa',
            'type' => 'provider',
            'account_label' => 'Mobile number',
            'supports_deposit' => true,
            'supports_withdrawal' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $id = DB::table('payment_gateways')->where('code', 'mpesa')->value('id');

        if (! $id) {
            return;
        }

        // A rollback must not take live routing with it. Once an agent has an
        // account on this gateway, or a single order has moved through it, the
        // row is somebody's record and the migration leaves it alone — the same
        // rule the admin panel's delete already enforces.
        $inUse = DB::table('agent_bank_accounts')->where('payment_gateway_id', $id)->exists()
            || DB::table('agent_orders')->where('payment_gateway_id', $id)->exists();

        if ($inUse) {
            return;
        }

        DB::table('payment_gateways')->where('id', $id)->delete();
    }
};
