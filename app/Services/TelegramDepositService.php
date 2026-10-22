<?php

namespace App\Services;

use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\TelegramSubscriber;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

final class TelegramDepositService
{
    public function __construct(private readonly FinancialRuleService $rules)
    {
    }

    /** @return array<int, string> */
    public function start(TelegramSubscriber $subscriber, float $amount): array
    {
        if (! $subscriber->user_id) {
            throw new RuntimeException('A player account is required before creating a deposit.');
        }
        $user = User::query()->find($subscriber->user_id);
        if (! $user || ! $user->active) {
            throw new RuntimeException('Your player wallet is not active.');
        }
        $this->rules->assertDepositAllowed($user, $amount);

        $accounts = $this->availableAccounts();
        if ($accounts->isEmpty()) {
            $subscriber->update(['pending_action' => null, 'pending_payload' => null]);

            return [];
        }

        $gatewayCounts = $accounts->countBy(fn (AgentBankAccount $account) => $account->gateway->name);
        $usedLabels = [];
        $options = [];

        foreach ($accounts as $account) {
            $base = $account->gateway->name;
            $label = $gatewayCounts[$base] > 1
                ? $base.' - '.substr($account->account_number, -4)
                : $base;

            if (isset($usedLabels[mb_strtolower($label)])) {
                $label .= ' #'.$account->id;
            }

            $usedLabels[mb_strtolower($label)] = true;
            $options[] = ['label' => $label, 'bank_account_id' => $account->id];
        }

        $subscriber->update([
            'pending_action' => 'deposit_bank',
            'pending_payload' => [
                'amount' => number_format($amount, 2, '.', ''),
                'options' => $options,
            ],
        ]);

        return array_column($options, 'label');
    }

    public function chooseBank(TelegramSubscriber $subscriber, string $selection): ?AgentOrder
    {
        $payload = $subscriber->pending_payload ?? [];
        $option = collect($payload['options'] ?? [])->first(
            fn (array $candidate) => mb_strtolower(trim((string) ($candidate['label'] ?? ''))) === mb_strtolower(trim($selection))
        );

        if (! $option) {
            return null;
        }

        $account = $this->availableAccounts()->firstWhere('id', (int) $option['bank_account_id']);
        if (! $account) {
            throw new RuntimeException('That bank account is no longer available. Please restart the deposit.');
        }

        $amount = number_format((float) ($payload['amount'] ?? 0), 2, '.', '');
        $order = AgentOrder::create([
            'user_id' => $subscriber->user_id,
            'agent_id' => $account->agent_id,
            'payment_gateway_id' => $account->payment_gateway_id,
            'type' => 'deposit',
            'amount' => $amount,
            'commission' => 0,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'status' => 'pending',
            'notes' => 'Telegram deposit instructions sent; awaiting the player bank reference.',
        ]);

        $subscriber->update([
            'pending_action' => 'deposit_reference',
            'pending_payload' => ['order_id' => $order->id],
        ]);

        return $order->load(['agent', 'gateway']);
    }

    public function submitReference(TelegramSubscriber $subscriber, string $reference): AgentOrder
    {
        $reference = trim($reference);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 100) {
            throw new RuntimeException('Enter a bank reference between 3 and 100 characters.');
        }
        if (AgentOrder::query()->where('reference', $reference)->exists()) {
            throw new RuntimeException('That bank reference has already been submitted. Check it and try again.');
        }

        $order = AgentOrder::query()
            ->whereKey((int) data_get($subscriber->pending_payload, 'order_id'))
            ->where('user_id', $subscriber->user_id)
            ->where('type', 'deposit')
            ->where('status', 'pending')
            ->first();

        if (! $order) {
            $subscriber->update(['pending_action' => null, 'pending_payload' => null]);
            throw new RuntimeException('This deposit order is no longer pending. Start a new deposit.');
        }

        $order->update([
            'reference' => $reference,
            'notes' => 'Bank reference submitted through Telegram; awaiting agent approval.',
        ]);
        $subscriber->update(['pending_action' => null, 'pending_payload' => null]);

        return $order->fresh(['agent', 'gateway']);
    }

    public function cancel(TelegramSubscriber $subscriber): void
    {
        if ($subscriber->pending_action === 'deposit_reference') {
            AgentOrder::query()
                ->whereKey((int) data_get($subscriber->pending_payload, 'order_id'))
                ->where('user_id', $subscriber->user_id)
                ->where('type', 'deposit')
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'processed_at' => now(),
                    'notes' => 'Cancelled by the player from Telegram before submitting a bank reference.',
                ]);
        }

        $subscriber->update(['pending_action' => null, 'pending_payload' => null]);
    }

    /** @return Collection<int, AgentBankAccount> */
    private function availableAccounts(): Collection
    {
        return AgentBankAccount::query()
            ->where('active', true)
            ->whereHas('agent', fn ($query) => $query->where('active', true))
            ->whereHas('gateway', fn ($query) => $query->where('active', true)->where('supports_deposit', true))
            ->with(['agent', 'gateway'])
            ->orderBy('payment_gateway_id')
            ->orderBy('agent_id')
            ->get();
    }
}
