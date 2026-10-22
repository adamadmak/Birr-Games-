<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('withdrawable_balance')->default(0)->after('credits');
            $table->boolean('contact_confirmed')->default(false)->after('phone');
            $table->boolean('active')->default(true)->after('role');
        });

        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['bank', 'provider'])->default('bank');
            $table->string('account_label')->nullable();
            $table->boolean('supports_deposit')->default(true);
            $table->boolean('supports_withdrawal')->default(false);
            $table->boolean('active')->default(true);
            $table->json('configuration')->nullable();
            $table->timestamps();
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('nickname')->unique();
            $table->string('phone')->unique();
            $table->string('password');
            $table->unsignedBigInteger('balance')->default(0);
            $table->boolean('commission_enabled')->default(false);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('agent_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_gateway_id')->constrained()->cascadeOnDelete();
            $table->string('account_name');
            $table->string('account_number');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['agent_id', 'payment_gateway_id', 'account_number'], 'agent_bank_acct_unique');
        });

        Schema::create('agent_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['deposit', 'withdrawal', 'manual_withdrawal']);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('commission')->default(0);
            $table->string('reference')->nullable()->unique();
            $table->enum('status', ['pending', 'success', 'rejected', 'cancelled'])->default('pending');
            $table->boolean('auto_verified')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('transaction_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_gateway_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->bigInteger('amount');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('status')->default('success');
            $table->string('method')->default('system');
            $table->string('reason');
            $table->string('currency', 3)->default('ETB');
            $table->string('bank_code')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
            $table->index(['type', 'status', 'created_at']);
        });

        foreach ([
            ['name' => 'Telebirr', 'code' => 'telebirr', 'type' => 'provider', 'account_label' => 'Mobile number', 'supports_deposit' => true, 'supports_withdrawal' => true],
            ['name' => 'Ebirr', 'code' => 'ebirr', 'type' => 'provider', 'account_label' => 'Mobile number', 'supports_deposit' => true, 'supports_withdrawal' => true],
            ['name' => 'Commercial Bank of Ethiopia', 'code' => 'cbe', 'type' => 'bank', 'account_label' => 'Account number', 'supports_deposit' => true, 'supports_withdrawal' => false],
            ['name' => 'Bank of Abyssinia', 'code' => 'abyssinia', 'type' => 'bank', 'account_label' => 'Account number', 'supports_deposit' => true, 'supports_withdrawal' => false],
            ['name' => 'Dashen Bank', 'code' => 'dashen', 'type' => 'bank', 'account_label' => 'Account number', 'supports_deposit' => true, 'supports_withdrawal' => false],
        ] as $gateway) {
            DB::table('payment_gateways')->insert($gateway + ['active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('users')->orderBy('id')->each(function ($user): void {
            DB::table('wallet_transactions')->insert([
                'transaction_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => 'opening_balance',
                'amount' => $user->credits,
                'fee' => 0,
                'status' => 'success',
                'method' => 'migration',
                'reason' => 'Opening balance imported when the ETB ledger was activated',
                'currency' => 'ETB',
                'source_type' => 'user',
                'source_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('agent_orders');
        Schema::dropIfExists('agent_bank_accounts');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('payment_gateways');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['withdrawable_balance', 'contact_confirmed', 'active']);
        });
    }
};
