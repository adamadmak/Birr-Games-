<?php

namespace App\Http\Controllers\Admin;

use App\Models\DepositBonusConfig;
use App\Models\MemberBonus;
use App\Models\WageringPolicy;
use App\Services\WageringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WageringController
{
    public function index(Request $request, WageringService $wagering): View
    {
        $wagering->expireOldBonuses();
        $tab = in_array($request->query('tab'), ['policies', 'deposits', 'members', 'summary'], true)
            ? $request->query('tab') : 'policies';
        $members = MemberBonus::query()->with(['user', 'config.policy'])->latest()->get();
        $configs = DepositBonusConfig::query()->with('policy')->withCount('memberBonuses')->latest()->get();

        return view('admin.wagering.index', [
            'tab' => $tab,
            'policies' => WageringPolicy::query()->withCount('depositBonusConfigs')->latest()->get(),
            'configs' => $configs,
            'members' => $members,
            'statusStats' => collect(['pending', 'active', 'redeemed', 'expired', 'cancelled', 'completed'])->mapWithKeys(fn ($status) => [$status => [
                'count' => $members->where('status', $status)->count(),
                'amount' => $members->where('status', $status)->sum('amount_awarded'),
            ]]),
            'summary' => [
                'total_configs' => $configs->count(),
                'active_configs' => $configs->where('active', true)->count(),
                'upcoming' => $configs->where('starts_at', '>', now())->count(),
                'ending_soon' => $configs->filter(fn ($c) => $c->ends_at->between(now(), now()->addDays(7)))->count(),
                'last_30_days' => $configs->where('created_at', '>=', now()->subDays(30))->count(),
                'awarded' => $members->sum('amount_awarded'),
                'contributed' => $members->sum('contributed'),
                'rollover' => $members->sum('rollover_required'),
                'average_bonus' => $members->avg('amount_awarded') ?: 0,
                'average_progress' => $members->avg('progress') ?: 0,
                'completed' => $members->where('status', 'completed')->count(),
            ],
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        WageringPolicy::create($this->policyData($request));
        return back()->with('admin_status', 'Wagering configuration created.');
    }

    public function updatePolicy(Request $request, WageringPolicy $policy): RedirectResponse
    {
        $policy->update($this->policyData($request));
        return back()->with('admin_status', 'Wagering configuration updated.');
    }

    public function destroyPolicy(WageringPolicy $policy): RedirectResponse
    {
        if ($policy->depositBonusConfigs()->exists()) {
            return back()->withErrors(['policy' => 'This policy is used by a deposit bonus and cannot be deleted.']);
        }
        $policy->delete();
        return back()->with('admin_status', 'Wagering configuration deleted.');
    }

    public function createDeposit(): View
    {
        return view('admin.wagering.create-deposit', ['policies' => WageringPolicy::orderBy('name')->get()]);
    }

    public function storeDeposit(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wagering_policy_id' => ['required', 'exists:wagering_policies,id'],
            'validity_period' => ['required', 'string', 'max:80'],
            'eligible_deposit_count' => ['required', 'integer', 'min:1', 'max:100'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'bonus_type' => ['required', Rule::in(['initial_deposit', 'happy_hour'])],
            'reward_type' => ['required', Rule::in(['fixed_amount', 'percentage'])],
            'wallet_type' => ['required', Rule::in(['withdrawable', 'non_withdrawable'])],
            'reward_value' => ['required', 'numeric', 'min:0'], 'rollover_multiplier' => ['required', 'numeric', 'min:0'],
            'maximum_payout' => ['required', 'numeric', 'min:0'],
        ]);
        $data['active'] = $request->boolean('active', true);
        DepositBonusConfig::create($data);
        return redirect()->route('admin.wagering.index', ['tab' => 'deposits'])->with('admin_status', 'Deposit bonus created.');
    }

    public function toggleDeposit(DepositBonusConfig $config): RedirectResponse
    {
        $config->update(['active' => !$config->active]);
        return back()->with('admin_status', 'Deposit bonus status updated.');
    }

    public function destroyDeposit(DepositBonusConfig $config): RedirectResponse
    {
        if ($config->memberBonuses()->exists()) return back()->withErrors(['config' => 'This bonus has member activity and cannot be deleted.']);
        $config->delete();
        return back()->with('admin_status', 'Deposit bonus deleted.');
    }

    private function policyData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'minimum_stake' => ['required', 'numeric', 'min:0'], 'minimum_deposit' => ['required', 'numeric', 'min:0'],
            'maximum_contribution' => ['required', 'numeric', 'min:0'],
            'contribution_type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'contribution_percentage' => ['required', 'numeric', 'min:0', 'max:1000000'],
        ]);
    }
}
