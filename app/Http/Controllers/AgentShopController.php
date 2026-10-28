<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AgentNotifier;
use App\Services\AgentShop;
use App\Services\FinancialRuleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** The agent's counter for walk-in customers (App\Services\AgentShop). */
class AgentShopController
{
    public function __construct(private readonly AgentShop $shop)
    {
    }

    public function show(): View
    {
        $agent = $this->agent();

        return view('agent.counter', [
            'agent' => $agent,
            'counter' => $this->shop->account($agent),
            'floatMinimum' => app(FinancialRuleService::class)->agentMinimumFloat(),
        ]);
    }

    public function topUp(Request $request, AgentNotifier $notifier): RedirectResponse
    {
        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:1', 'max:10000000']])['amount'];
        $agent = $this->agent();

        if ($this->shop->topUp($agent, $amount)) {
            $notifier->lowFloat($agent->fresh(), app(FinancialRuleService::class)->agentMinimumFloat());
        }

        return back()->with('agent_status', 'Moved '.number_format($amount, 2).' ETB from your float to the counter.');
    }

    public function giveBack(Request $request): RedirectResponse
    {
        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:10000000']])['amount'];
        $this->shop->giveBack($this->agent(), $amount);

        return back()->with('agent_status', 'Returned '.number_format($amount, 2).' ETB from the counter to your float.');
    }

    /**
     * Open the sportsbook signed in as this agent's counter, in this browser.
     * Only ever the agent's own counter; the counter has no password of its own.
     */
    public function open(Request $request): RedirectResponse
    {
        $counter = $this->shop->account($this->agent());
        Auth::guard('web')->login($counter);

        return redirect('/');
    }

    public function payOut(string $ticket): RedirectResponse
    {
        $amount = $this->shop->payOut($this->agent(), $ticket);

        return back()->with('agent_status', 'Ticket '.$ticket.' marked paid: give the customer '.number_format($amount, 2).' ETB.');
    }

    private function agent(): Agent
    {
        return Auth::guard('agent')->user();
    }
}
