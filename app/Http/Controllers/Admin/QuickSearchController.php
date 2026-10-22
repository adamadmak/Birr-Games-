<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\AgentOrder;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the Ctrl/Cmd+K command palette: jump to any admin screen, or find a member,
 * agent, transaction or order without knowing which page it lives on.
 */
class QuickSearchController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user() ?: auth()->user();
        if (! $user) {
            return response()->json(['ok' => false, 'groups' => []], 401);
        }

        $term = $request->string('q')->trim()->limit(80)->toString();
        $groups = [];

        $pages = collect($this->pages())
            ->filter(fn (array $page) => $user->hasAdminPermission($page['permission']))
            ->when($term !== '', fn ($pages) => $pages->filter(
                fn (array $page) => str_contains(mb_strtolower($page['label'].' '.$page['hint']), mb_strtolower($term))
            ))
            ->take(8)
            ->values();

        if ($pages->isNotEmpty()) {
            $groups[] = ['label' => $term === '' ? 'Jump to' : 'Pages', 'items' => $pages->all()];
        }

        if ($term === '') {
            return response()->json(['ok' => true, 'groups' => $groups]);
        }

        if ($user->hasAdminPermission('members.manage') || $user->hasAdminPermission('transactions.view')) {
            $phoneCandidates = array_values(array_unique(array_merge([$term], PhoneNumber::candidates($term))));
            $members = User::query()
                ->where('role', User::ROLE_PLAYER)
                ->where(fn ($query) => $query
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('username', 'like', '%'.ltrim($term, '@').'%')
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhereIn('phone', $phoneCandidates))
                ->orderByDesc('credits')
                ->limit(6)
                ->get(['id', 'name', 'username', 'phone', 'credits', 'active']);

            if ($members->isNotEmpty()) {
                $groups[] = [
                    'label' => 'Members',
                    'items' => $members->map(fn (User $member) => [
                        'label' => $member->name.($member->username ? ' (@'.$member->username.')' : ''),
                        'hint' => ($member->phone ?: 'No phone').' · '.\App\Support\Money::format($member->credits, 2).($member->active ? '' : ' · disabled'),
                        'icon' => 'fa-solid fa-user',
                        'url' => route('admin.members.show', $member),
                    ])->all(),
                ];
            }
        }

        if ($user->hasAdminPermission('agents.manage') || $user->hasAdminPermission('agents.finance')) {
            $agents = Agent::query()
                ->where(fn ($query) => $query->where('nickname', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orderBy('nickname')
                ->limit(5)
                ->get(['id', 'nickname', 'phone', 'balance']);

            if ($agents->isNotEmpty()) {
                $groups[] = [
                    'label' => 'Agents',
                    'items' => $agents->map(fn (Agent $agent) => [
                        'label' => $agent->nickname,
                        'hint' => ($agent->phone ?: 'No phone').' · float '.\App\Support\Money::format($agent->balance, 2),
                        'icon' => 'fa-solid fa-user-tie',
                        'url' => route('admin.agents.reports', ['agent_id' => $agent->id, 'preset' => 'month']),
                    ])->all(),
                ];
            }
        }

        if ($user->hasAdminPermission('transactions.view')) {
            $transactions = WalletTransaction::query()
                ->with(['user:id,name', 'agent:id,nickname'])
                ->where(fn ($query) => $query->where('transaction_id', 'like', "%{$term}%")->orWhere('ledger_key', 'like', "%{$term}%"))
                ->latest('id')
                ->limit(5)
                ->get();

            if ($transactions->isNotEmpty()) {
                $groups[] = [
                    'label' => 'Transactions',
                    'items' => $transactions->map(fn (WalletTransaction $transaction) => [
                        'label' => str($transaction->type)->headline().' · '.\App\Support\Money::format($transaction->amount, 2),
                        'hint' => ($transaction->user?->name ?: $transaction->agent?->nickname ?: 'System').' · '.$transaction->transaction_id,
                        'icon' => 'fa-solid fa-receipt',
                        'url' => route('admin.transactions.index', ['transaction_id' => $transaction->transaction_id]),
                    ])->all(),
                ];
            }

            $orders = AgentOrder::query()
                ->with(['user:id,name'])
                ->where(fn ($query) => $query->where('reference', 'like', "%{$term}%")->orWhere('account_number', 'like', "%{$term}%"))
                ->latest('id')
                ->limit(5)
                ->get();

            if ($orders->isNotEmpty()) {
                $groups[] = [
                    'label' => 'Agent orders',
                    'items' => $orders->map(fn (AgentOrder $order) => [
                        'label' => str($order->type)->headline().' #'.$order->id.' · '.\App\Support\Money::format($order->amount, 2),
                        'hint' => ($order->user?->name ?: 'Unknown member').' · '.($order->reference ?: $order->account_number).' · '.$order->status,
                        'icon' => 'fa-solid fa-file-invoice-dollar',
                        'url' => route('admin.agents.orders', ['type' => $order->type, 'search' => $order->reference ?: $order->account_number]),
                    ])->all(),
                ];
            }
        }

        return response()->json(['ok' => true, 'groups' => $groups]);
    }

    /** @return array<int, array{label: string, hint: string, icon: string, url: string, permission: string}> */
    private function pages(): array
    {
        return [
            ['label' => 'Dashboard', 'hint' => 'Overview and KPIs', 'icon' => 'fa-solid fa-chart-pie', 'url' => route('admin.dashboard'), 'permission' => 'dashboard.view'],
            ['label' => 'Player report', 'hint' => 'Deposits, withdrawals, win and loss per member', 'icon' => 'fa-solid fa-scale-balanced', 'url' => route('admin.players.report'), 'permission' => 'transactions.view'],
            ['label' => 'Members', 'hint' => 'Member directory and wallets', 'icon' => 'fa-solid fa-users', 'url' => route('admin.members.index'), 'permission' => 'members.manage'],
            ['label' => 'Transactions', 'hint' => 'Wallet ledger', 'icon' => 'fa-solid fa-chart-simple', 'url' => route('admin.transactions.index'), 'permission' => 'transactions.view'],
            ['label' => 'Operations center', 'hint' => 'Queues and approvals', 'icon' => 'fa-solid fa-clipboard-check', 'url' => route('admin.operations.index'), 'permission' => 'operations.view'],
            ['label' => 'Reconciliation', 'hint' => 'Wallet balance checks', 'icon' => 'fa-solid fa-scale-unbalanced', 'url' => route('admin.operations.reconciliation'), 'permission' => 'reconciliation.view'],
            ['label' => 'Audit log', 'hint' => 'Administrator activity', 'icon' => 'fa-solid fa-clipboard-list', 'url' => route('admin.operations.audit'), 'permission' => 'audit.view'],
            ['label' => 'Agent report transactions', 'hint' => 'Agent settlement and commission', 'icon' => 'fa-solid fa-chart-line', 'url' => route('admin.agents.reports'), 'permission' => 'agents.finance'],
            ['label' => 'Agent list', 'hint' => 'Agent network', 'icon' => 'fa-solid fa-list-ul', 'url' => route('admin.agents.index'), 'permission' => 'agents.manage'],
            ['label' => 'Agent bank info', 'hint' => 'Deposit routing accounts', 'icon' => 'fa-solid fa-building-columns', 'url' => route('admin.agents.bank-info'), 'permission' => 'agents.manage'],
            ['label' => 'Withdrawal list', 'hint' => 'Payouts awaiting agents', 'icon' => 'fa-solid fa-money-bill-transfer', 'url' => route('admin.agents.withdrawals'), 'permission' => 'agents.finance'],
            ['label' => 'Orders list', 'hint' => 'Deposit and withdrawal orders', 'icon' => 'fa-solid fa-receipt', 'url' => route('admin.agents.orders'), 'permission' => 'agents.finance'],
            ['label' => 'Casino report', 'hint' => 'Bets, payouts and GGR by game', 'icon' => 'fa-solid fa-chart-column', 'url' => route('admin.casino.report'), 'permission' => 'casino.manage'],
            ['label' => 'Casino engine', 'hint' => 'Games, providers and lobbies', 'icon' => 'fa-solid fa-dice', 'url' => route('admin.game-engine.index'), 'permission' => 'casino.manage'],
            ['label' => 'Payment gateways', 'hint' => 'Banks and wallets', 'icon' => 'fa-solid fa-credit-card', 'url' => route('admin.payment-gateways.index'), 'permission' => 'payments.manage'],
            ['label' => 'Promotions', 'hint' => 'Bonuses and campaigns', 'icon' => 'fa-solid fa-gift', 'url' => route('admin.promotions.index'), 'permission' => 'promotions.manage'],
            ['label' => 'Broadcast bot', 'hint' => 'Telegram campaigns', 'icon' => 'fa-solid fa-tower-broadcast', 'url' => route('admin.telegram.index'), 'permission' => 'telegram.manage'],
            ['label' => 'Configuration', 'hint' => 'Platform settings', 'icon' => 'fa-solid fa-gear', 'url' => route('admin.settings.index'), 'permission' => 'settings.manage'],
            ['label' => 'Administrators', 'hint' => 'Staff accounts and roles', 'icon' => 'fa-solid fa-user-shield', 'url' => route('admin.administrators.index'), 'permission' => 'administrators.manage'],
        ];
    }
}
