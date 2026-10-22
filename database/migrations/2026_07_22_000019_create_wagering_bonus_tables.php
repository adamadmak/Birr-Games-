<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wagering_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->decimal('minimum_stake', 16, 2)->default(0);
            $table->decimal('minimum_deposit', 16, 2)->default(0);
            $table->decimal('maximum_contribution', 16, 2)->default(0);
            $table->string('contribution_type', 30)->default('percentage');
            $table->decimal('contribution_percentage', 8, 2)->default(100);
            $table->timestamps();
        });

        Schema::create('deposit_bonus_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wagering_policy_id')->constrained()->restrictOnDelete();
            $table->string('validity_period', 80);
            $table->unsignedInteger('eligible_deposit_count')->default(1);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('bonus_type', 40)->default('initial_deposit');
            $table->string('reward_type', 30)->default('percentage');
            $table->string('wallet_type', 30)->default('non_withdrawable');
            $table->decimal('reward_value', 16, 2)->default(0);
            $table->decimal('rollover_multiplier', 10, 2)->default(0);
            $table->decimal('maximum_payout', 16, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['active', 'starts_at', 'ends_at']);
        });

        Schema::create('member_bonuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_bonus_config_id')->constrained()->restrictOnDelete();
            $table->foreignId('bonus_claim_id')->nullable()->constrained('bonus_claims')->nullOnDelete();
            $table->string('status', 24)->default('active');
            $table->decimal('amount_awarded', 16, 2)->default(0);
            $table->decimal('rollover_required', 16, 2)->default(0);
            $table->decimal('contributed', 16, 2)->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->unique('bonus_claim_id');
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        $policyId = DB::table('wagering_policies')->insertGetId([
            'name' => 'Wagering Policy',
            'minimum_stake' => 1,
            'minimum_deposit' => 50,
            'maximum_contribution' => 100000,
            'contribution_type' => 'percentage',
            'contribution_percentage' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deposit_bonus_configs')->insert([
            'wagering_policy_id' => $policyId,
            'validity_period' => '30 days',
            'eligible_deposit_count' => 1,
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->addYear()->endOfDay(),
            'bonus_type' => 'initial_deposit',
            'reward_type' => 'percentage',
            'wallet_type' => 'non_withdrawable',
            'reward_value' => 100,
            'rollover_multiplier' => 20,
            'maximum_payout' => 50000,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('member_bonuses');
        Schema::dropIfExists('deposit_bonus_configs');
        Schema::dropIfExists('wagering_policies');
    }
};
