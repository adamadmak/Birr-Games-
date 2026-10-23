<?php

namespace App\Http\Controllers;

use App\Models\AgentBankAccount;
use App\Models\PaymentGateway;
use App\Models\PlatformSetting;
use App\Services\FinancialRuleService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlayerController
{
    /**
     * The signed-in player's current balance, for the topbar to poll.
     *
     * Sportsbook stakes and settlements arrive server-to-server from WinHouse,
     * so an open page never learns that the wallet moved. The pill would keep
     * showing the pre-bet figure until a full reload, which a player reads as
     * "my stake was not taken" — and then places it again.
     *
     * Deliberately the raw stored value plus a preformatted string: the pill and
     * the sidebar strip render the same number two different ways, and formatting
     * it twice in JS is how they drift apart.
     */
    public function balance(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'credits' => (float) $user->credits,
            // Both through Money, and never rounded up: a balance of 23,000.75
            // shown as 23,001 is money the player cannot spend or withdraw, and
            // nothing on screen tells them which of the two it is.
            'pill' => \App\Support\Money::amount($user->credits),
            'formatted' => \App\Support\Money::exact($user->credits),
        ])->header('Cache-Control', 'no-store');
    }

    public function wallet(Request $request): View
    {
        $tab = $request->string('tab')->toString() === 'withdraw' ? 'withdraw' : 'deposit';
        $withdrawalOrders = $request->user()->withdrawalOrders()->with(['gateway', 'agent', 'rating'])->latest()->paginate(10)->withQueryString();
        $depositOrders = $request->user()->depositOrders()->with(['agent', 'gateway', 'rating'])->latest()->paginate(10, ['*'], 'deposit_page')->withQueryString();
        $depositAccounts = AgentBankAccount::query()
            ->where('active', true)
            ->whereHas('agent', fn ($query) => $query->where('active', true))
            ->whereHas('gateway', fn ($query) => $query->where('active', true)->where('supports_deposit', true))
            // Service record travels with the agent: a player choosing where to send
            // money should be able to see how that agent has handled it before.
            ->with(['agent' => fn ($query) => $query->withAvg('ratings', 'score')->withCount('ratings'), 'gateway'])
            ->orderBy('payment_gateway_id')
            ->orderBy('agent_id')
            ->get();

        /*
         * Who is actually at their desk. A deposit sits unapproved until an agent
         * looks at it, so an offline agent is a slower deposit — which the player
         * had no way of knowing at the moment they were choosing one. Online agents
         * are listed first within each payment method, and the rest keep their
         * existing order so the list does not reshuffle unpredictably.
         */
        $onlineAgentIds = \App\Http\Middleware\TrackAgentPresence::onlineAgentIds();
        $depositAccounts = $depositAccounts
            // One padded composite key rather than sortBy's multi-key form: given an
            // array, that form treats a closure as a comparator receiving both items,
            // not as a value to sort on, so the intended ordering silently did nothing.
            ->sortBy(fn ($account) => sprintf(
                '%010d%d%010d',
                $account->payment_gateway_id,
                isset($onlineAgentIds[$account->agent_id]) ? 0 : 1,
                $account->agent_id
            ))
            ->values();

        $bonuses = app(\App\Services\DepositBonusService::class);

        return view('player.wallet', [
            'tab' => $tab,
            // The deposit bonus: what a deposit would earn, what the player still holds,
            // and whether a withdrawal will cancel it. Each is empty when the bonus is off.
            'bonusInfo' => [
                'offer' => $bonuses->offerFor($request->user()),
                'outstanding' => $bonuses->outstanding($request->user()),
                'expires' => $bonuses->nextExpiry($request->user()),
                'forfeits' => $bonuses->settings()['forfeit_on_withdrawal'],
            ],
            'onlineAgentIds' => $onlineAgentIds,
            'gateways' => PaymentGateway::query()->where('active', true)->where('supports_withdrawal', true)->orderBy('name')->get(),
            'depositAccounts' => $depositAccounts,
            'depositGateways' => $depositAccounts->pluck('gateway')->unique('id')->values(),
            'depositOrders' => $depositOrders,
            'pendingDeposits' => $request->user()->depositOrders()->where('status', 'pending')->count(),
            'minimumDeposit' => (float) PlatformSetting::valueFor('min_deposit_amount', 50),
            'maximumDeposit' => (float) PlatformSetting::valueFor('max_deposit_amount', 100_000),
            // One band, worked out by the service that also validates the
            // request, so the form cannot offer what the server refuses.
            'withdrawalRange' => app(FinancialRuleService::class)->withdrawalRange($request->user()),
            'depositIdempotencyKey' => (string) Str::uuid(),
            'withdrawalIdempotencyKey' => (string) Str::uuid(),
            'withdrawalOrders' => $withdrawalOrders,
            'pendingWithdrawals' => $request->user()->withdrawalOrders()->where('status', 'pending')->count(),
            'ledgerTransactions' => $request->user()->walletTransactions()->with('gateway')->latest()->limit(12)->get(),
        ]);
    }

    public function profile(Request $request): View
    {
        return view('player.profile', [
            'roundCount' => $request->user()->rounds()->count(),
            'winCount' => $request->user()->rounds()->whereColumn('payout', '>', 'stake')->count(),
            'favoriteGame' => $request->user()->rounds()
                ->selectRaw('game, COUNT(*) as rounds_count')
                ->groupBy('game')
                ->orderByDesc('rounds_count')
                ->value('game'),
        ]);
    }
}
