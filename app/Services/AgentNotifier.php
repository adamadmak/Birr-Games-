<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentOrder;
use App\Support\Money;
use App\Support\SiteUrl;
use Illuminate\Support\Facades\Log;

/**
 * Telegram messages to agents about work waiting for them.
 *
 * The bot told agents who linked their chat that they would hear about every
 * deposit and withdrawal assigned to them; until 28 Oct 2026 nothing sent
 * those messages. They go out right after the order is saved, with a short
 * timeout and no retry, and a failure never affects the order: the portal
 * remains the record. An agent who has not linked Telegram is simply skipped.
 */
final class AgentNotifier
{
    public function __construct(private readonly TelegramBotService $telegram)
    {
    }

    public function depositSubmitted(AgentOrder $order, bool $withProof): void
    {
        $this->send($order->agent, "<b>💰 New deposit to confirm</b>\n\n"
            .$this->orderLines($order)
            .(filled($order->reference) ? 'Reference: <code>'.e((string) $order->reference)."</code>\n" : "No reference: check the screenshot.\n")
            .($withProof ? "📎 The player attached a payment screenshot.\n" : '')
            ."\nCheck the transfer in your account, then approve or reject it in the portal.",
            SiteUrl::route('agent.deposit-orders'));
    }

    public function proofAdded(AgentOrder $order): void
    {
        $this->send($order->agent, "<b>📎 Payment screenshot added</b>\n\n"
            .$this->orderLines($order)
            .(filled($order->reference) ? 'Reference: <code>'.e((string) $order->reference).'</code>' : 'No reference: check the screenshot.'),
            SiteUrl::route('agent.deposit-orders'));
    }

    public function withdrawalRequested(AgentOrder $order): void
    {
        $this->send($order->agent, "<b>🏧 New withdrawal to pay</b>\n\n"
            .$this->orderLines($order)
            .'Pay to: <b>'.e((string) $order->account_name).'</b> · <code>'.e((string) $order->account_number)."</code>\n"
            ."\nPay the player from your account, then mark it paid in the portal.",
            SiteUrl::route('agent.withdrawals'));
    }

    public function lowFloat(Agent $agent, float $minimum): void
    {
        $this->send($agent, "<b>⚠️ Your float is running low</b>\n\n"
            .'Balance: <b>'.Money::exact((float) $agent->balance)."</b>\n"
            .($minimum > 0 ? 'Deposits stop being approvable below '.Money::exact($minimum).".\n" : '')
            ."\nAsk the administrator to top up your float before it runs out.",
            SiteUrl::route('agent.dashboard'));
    }

    private function orderLines(AgentOrder $order): string
    {
        $player = $order->user;

        return 'Amount: <b>'.Money::exact((float) $order->amount)."</b>\n"
            .'Method: '.e($order->gateway?->name ?: '—')."\n"
            .'Player: '.e($player?->name ?: 'Member').($player?->phone ? ' · '.e($player->phone) : '')."\n";
    }

    private function send(?Agent $agent, string $html, string $url): void
    {
        if (! $agent || blank($agent->telegram_chat_id)) {
            return;
        }

        try {
            if (! $this->telegram->configured()) {
                return;
            }
            $this->telegram->notify($agent->telegram_chat_id, $html, [
                ['text' => 'Open agent portal', 'type' => 'url', 'value' => $url],
            ]);
        } catch (\Throwable $exception) {
            Log::warning('An agent Telegram notice could not be sent.', [
                'agent_id' => $agent->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
