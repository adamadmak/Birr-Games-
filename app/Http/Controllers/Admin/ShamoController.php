<?php

namespace App\Http\Controllers\Admin;

use App\Models\ShamoCampaign;
use App\Models\ShamoParticipation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ShamoController
{
    public function index(): View
    {
        return view('admin.shamo.index', [
            'campaigns' => ShamoCampaign::query()->withCount('participations')->latest()->get(),
            'participations' => ShamoParticipation::query()->with(['campaign', 'user'])->latest('joined_at')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'entry_cost' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reward_pool' => ['required', 'integer', 'min:0', 'max:100000000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'status' => ['required', Rule::in([ShamoCampaign::STATUS_DRAFT, ShamoCampaign::STATUS_ACTIVE])],
        ]);

        ShamoCampaign::create($data);

        return back()->with('status', 'Shamo campaign created.');
    }

    public function status(Request $request, ShamoCampaign $campaign): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['draft', 'active', 'closed'])]]);
        $campaign->update($data);

        return back()->with('status', 'Campaign status updated.');
    }

    public function award(Request $request, ShamoParticipation $participation): RedirectResponse
    {
        abort_if($participation->status === ShamoParticipation::STATUS_CLAIMED, 422, 'A claimed reward cannot be changed.');

        $data = $request->validate([
            'position' => ['required', 'integer', 'min:1', 'max:100000'],
            'prize_amount' => ['required', 'integer', 'min:1', 'max:100000000'],
        ]);

        $participation->update($data + ['status' => ShamoParticipation::STATUS_AWARDED]);

        return back()->with('status', 'Shamo reward awarded and ready for the player to claim.');
    }
}
