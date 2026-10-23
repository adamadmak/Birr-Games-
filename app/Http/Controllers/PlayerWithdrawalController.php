<?php

namespace App\Http\Controllers;

use App\Models\AgentOrder;
use App\Models\AgentOrderEvent;
use App\Models\Agent;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FinancialRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerWithdrawalController
{
    public function store(Request $request, ?FinancialRuleService $rules = null): RedirectResponse
    {
        if ($request->user()->isAdmin()) {
            throw ValidationException::withMessages(['amount' => 'Administrator accounts cannot request withdrawals from this portal.']);
        }

        // Money leaving the platform is the one feature held behind email
        // confirmation while the operator requires it (see RegistrationGuard).
        if ($request->user()->needsEmailVerification()) {
            throw ValidationException::withMessages(['amount' => filled($request->user()->email)
                ? 'Please confirm your email address before requesting a withdrawal. Use the "Resend link" button at the top of the page.'
                : 'Please add and confirm an email address before requesting a withdrawal. Contact support to add one.']);
        }

        $rules ??= app(FinancialRuleService::class);
        $minWithdraw = (float) \App\Models\PlatformSetting::valueFor('min_withdraw_amount', 30);
        $maxWithdraw = (float) \App\Models\PlatformSetting::valueFor('max_withdraw_amount', 30000);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', "min:{$minWithdraw}", "max:{$maxWithdraw}"],
            'payment_gateway_id' => ['required', 'integer', Rule::exists('payment_gateways', 'id')],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:80'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $validated['idempotency_key'] ??= (string) Str::uuid();

        DB::transaction(function () use ($request, $validated, $rules): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (AgentOrder::query()->where('user_id', $user->id)->where('type', 'withdrawal')->where('idempotency_key', $validated['idempotency_key'])->exists()) {
                return;
            }
            $gateway = PaymentGateway::query()->whereKey($validated['payment_gateway_id'])->where('active', true)->where('supports_withdrawal', true)->first();

            if (!$user->active) {
                throw ValidationException::withMessages(['amount' => 'Your wallet is inactive. Contact an administrator before requesting a withdrawal.']);
            }
            if (!$gateway) {
                throw ValidationException::withMessages(['payment_gateway_id' => 'The selected withdrawal method is not available.']);
            }
            $rules->assertWithdrawalAllowed($user, (float) $validated['amount']);

            // A withdrawal cancels any deposit bonus the player has not used. Without
            // this a player could take their own deposit out and keep playing on the
            // house's bonus. They are told first and must confirm.
            $bonuses = app(\App\Services\DepositBonusService::class);
            if ($bonuses->settings()['forfeit_on_withdrawal'] && ($unusedBonus = $bonuses->outstanding($user)) > 0) {
                if (! $request->boolean('acknowledge_bonus_forfeit')) {
                    throw ValidationException::withMessages(['amount' => 'Withdrawing cancels your remaining bonus of '
                        .number_format($unusedBonus, 2).' ETB. Tick the box to confirm, or keep playing to use it first.']);
                }
                $bonuses->forfeit($user, $unusedBonus, 'withdrawal');
            }

            $agent = Agent::query()
                ->where('active', true)
                ->whereHas('bankAccounts', fn ($query) => $query
                    ->where('payment_gateway_id', $gateway->id)
                    ->where('active', true))
                ->withCount(['orders as pending_orders_count' => fn ($query) => $query->where('status', 'pending')])
                ->orderBy('pending_orders_count')
                ->orderBy('id')
                ->first() ?? Agent::query()->where('active', true)->orderBy('id')->first();

            $order = AgentOrder::create([
                'user_id' => $user->id,
                'agent_id' => $agent?->id,
                'payment_gateway_id' => $gateway->id,
                'type' => 'withdrawal',
                'amount' => $validated['amount'],
                'commission' => 0,
                'reference' => 'WDR-'.now()->format('ymdHis').'-'.strtoupper(Str::random(5)),
                'idempotency_key' => $validated['idempotency_key'],
                'account_name' => trim($validated['account_name']),
                'account_number' => trim($validated['account_number']),
                'status' => 'pending',
                'notes' => $agent
                    ? 'Player withdrawal request assigned to '.$agent->nickname.' for payout.'
                    : 'Player withdrawal request awaiting agent assignment.',
            ]);
            AgentOrderEvent::record($order, $agent ? 'submitted_and_assigned' : 'submitted', $user, null, 'pending', $order->notes, [
                'agent_id' => $agent?->id,
            ]);

            $user->decrement('credits', $validated['amount']);
            $user->decrement('withdrawable_balance', $validated['amount']);

            WalletTransaction::create([
                'user_id' => $user->id,
                'payment_gateway_id' => $gateway->id,
                'type' => 'withdrawal',
                'amount' => -$validated['amount'],
                'status' => 'pending',
                'method' => $gateway->code,
                'reason' => 'Withdrawal funds reserved pending agent payout',
                'currency' => 'ETB',
                'bank_code' => $gateway->code,
                'source_type' => 'agent_order',
                'source_id' => $order->id,
                'metadata' => [
                    'reference' => $order->reference,
                    'account_name' => $order->account_name,
                    'account_number' => $order->account_number,
                    'assigned_agent_id' => $agent?->id,
                ],
            ]);
        });

        return redirect()->route('player.wallet', ['tab' => 'withdraw'])->with('status', 'Your withdrawal request was submitted for agent payout review.');
    }

    public function cancel(Request $request, AgentOrder $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id && $order->type === 'withdrawal', 404);

        DB::transaction(function () use ($order, $request): void {
            $lockedOrder = AgentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status !== 'pending') {
                throw ValidationException::withMessages(['withdrawal' => 'Only pending withdrawal requests can be cancelled.']);
            }

            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $user->increment('credits', $lockedOrder->amount);
            $user->increment('withdrawable_balance', $lockedOrder->amount);
            $lockedOrder->update(['status' => 'cancelled', 'processed_at' => now(), 'notes' => 'Cancelled by the player. Reserved funds were restored.']);
            AgentOrderEvent::record($lockedOrder, 'cancelled', $request->user(), 'pending', 'cancelled', $lockedOrder->notes, ['funds_restored' => true]);

            $transaction = WalletTransaction::query()->where('source_type', 'agent_order')->where('source_id', $lockedOrder->id)->where('type', 'withdrawal')->first();
            if ($transaction) {
                $transaction->update([
                    'status' => 'cancelled',
                    'reason' => 'Withdrawal cancelled by player; reserved funds restored',
                    'metadata' => array_merge($transaction->metadata ?? [], ['cancelled_at' => now()->toIso8601String(), 'funds_restored' => true]),
                ]);
            }
        });

        return redirect()->route('player.wallet', ['tab' => 'withdraw'])->with('status', 'Withdrawal cancelled and reserved funds restored.');
    }
}
