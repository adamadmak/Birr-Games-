<?php

namespace App\Http\Controllers;

use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\AgentOrderEvent;
use App\Models\User;
use App\Services\FinancialRuleService;
use App\Services\TelegramBotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlayerDepositController
{
    public function store(Request $request, ?FinancialRuleService $rules = null, ?TelegramBotService $telegram = null): RedirectResponse
    {
        $rules ??= app(FinancialRuleService::class);
        $telegram ??= app(TelegramBotService::class);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000000'],
            'agent_bank_account_id' => ['required', 'integer', Rule::exists('agent_bank_accounts', 'id')],
            'reference' => ['required', 'string', 'min:3', 'max:100'],
            'idempotency_key' => ['nullable', 'uuid'],
        ]);
        $validated['idempotency_key'] ??= (string) Str::uuid();

        DB::transaction(function () use ($request, $validated, $rules): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (AgentOrder::query()->where('user_id', $user->id)->where('type', 'deposit')->where('idempotency_key', $validated['idempotency_key'])->exists()) {
                return;
            }
            if (AgentOrder::query()->where('reference', trim($validated['reference']))->exists()) {
                throw ValidationException::withMessages(['reference' => 'That bank reference has already been submitted.']);
            }
            $account = AgentBankAccount::query()
                ->whereKey($validated['agent_bank_account_id'])
                ->where('active', true)
                ->whereHas('agent', fn ($query) => $query->where('active', true))
                ->whereHas('gateway', fn ($query) => $query->where('active', true)->where('supports_deposit', true))
                ->with(['agent', 'gateway'])
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw ValidationException::withMessages(['agent_bank_account_id' => 'The selected agent payment account is no longer available.']);
            }
            if (! $user->active) {
                throw ValidationException::withMessages(['amount' => 'Your wallet is inactive. Contact support before depositing.']);
            }
            $rules->assertDepositAllowed($user, (float) $validated['amount']);

            $order = AgentOrder::create([
                'user_id' => $user->id,
                'agent_id' => $account->agent_id,
                'payment_gateway_id' => $account->payment_gateway_id,
                'type' => 'deposit',
                'amount' => number_format((float) $validated['amount'], 2, '.', ''),
                'commission' => 0,
                'reference' => trim($validated['reference']),
                'idempotency_key' => $validated['idempotency_key'],
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
                'status' => 'pending',
                'notes' => 'Player submitted a bank reference through the web wallet; awaiting agent approval.',
            ]);
            AgentOrderEvent::record($order, 'submitted', $user, null, 'pending', $order->notes);
        });

        return redirect()->route('player.wallet', ['tab' => 'deposit'])
            ->with('deposit_submitted', true)
            ->with('status', __('Deposit submitted. Your agent will confirm the transfer, usually within :minutes minutes, and your wallet is credited then.', ['minutes' => $rules->depositReviewMinutes()]));
    }

    public function cancel(Request $request, AgentOrder $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id && $order->type === 'deposit', 404);

        DB::transaction(function () use ($order, $request): void {
            $lockedOrder = AgentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status !== 'pending') {
                throw ValidationException::withMessages(['deposit' => 'Only pending deposits can be cancelled.']);
            }

            $lockedOrder->update([
                'status' => 'cancelled',
                'processed_at' => now(),
                'notes' => 'Cancelled by the player before agent approval.',
            ]);
            AgentOrderEvent::record($lockedOrder, 'cancelled', $request->user(), 'pending', 'cancelled', $lockedOrder->notes);
        });

        return redirect()->route('player.wallet', ['tab' => 'deposit'])->with('status', 'Pending deposit cancelled.');
    }
}
