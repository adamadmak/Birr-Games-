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
        if (\App\Services\AgentShop::isCounter($request->user())) {
            throw ValidationException::withMessages(['amount' => 'The counter account moves money only through the agent portal.']);
        }
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000000000'],
            'agent_bank_account_id' => ['required', 'integer', Rule::exists('agent_bank_accounts', 'id')],
            // A reference or a screenshot: either lets the agent find the transfer.
            'reference' => ['nullable', 'required_without:proof', 'string', 'min:3', 'max:100'],
            'idempotency_key' => ['nullable', 'uuid'],
            // The payment screenshot the agent checks the transfer against. Optional:
            // the bank reference is still what identifies the payment.
            'proof' => ['nullable', 'required_without:reference', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'reference.required_without' => __('Enter the bank reference or add a screenshot of the transfer.'),
            'proof.required_without' => __('Enter the bank reference or add a screenshot of the transfer.'),
            'proof.mimes' => __('Upload the screenshot as a JPG, PNG or WEBP picture.'),
            'proof.max' => __('That picture is larger than 10 MB. Take a screenshot instead of a photo.'),
        ]);
        $validated['idempotency_key'] ??= (string) Str::uuid();

        $order = DB::transaction(function () use ($request, $validated, $rules): ?AgentOrder {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            if (AgentOrder::query()->where('user_id', $user->id)->where('type', 'deposit')->where('idempotency_key', $validated['idempotency_key'])->exists()) {
                return null;
            }
            $reference = filled($validated['reference'] ?? null) ? trim($validated['reference']) : null;
            if ($reference !== null && AgentOrder::query()->where('reference', $reference)->exists()) {
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
                'reference' => $reference,
                'idempotency_key' => $validated['idempotency_key'],
                'account_name' => $account->account_name,
                'account_number' => $account->account_number,
                'status' => 'pending',
                'notes' => 'Player submitted a bank reference through the web wallet; awaiting agent approval.',
            ]);
            AgentOrderEvent::record($order, 'submitted', $user, null, 'pending', $order->notes);

            return $order;
        });

        // After the commit: a screenshot that cannot be read does not undo a
        // deposit the player has already paid; they are told and can add it again.
        $proofProblem = null;
        $hasProof = false;
        if ($order && $request->hasFile('proof')) {
            try {
                app(\App\Services\DepositProofStore::class)->store($order, $request->file('proof'));
                $hasProof = true;
            } catch (\InvalidArgumentException $exception) {
                $proofProblem = $exception->getMessage();
            } catch (\Throwable $exception) {
                report($exception);
                $proofProblem = __('The deposit was submitted, but the screenshot could not be saved. Add it again from your wallet.');
            }
        }
        if ($order) {
            app(\App\Services\AgentNotifier::class)->depositSubmitted($order->load(['agent', 'user', 'gateway']), $hasProof);
        }

        return redirect()->route('player.wallet', ['tab' => 'deposit'])
            ->with('deposit_submitted', true)
            ->with('error', $proofProblem)
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
