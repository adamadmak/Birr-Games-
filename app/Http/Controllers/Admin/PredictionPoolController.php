<?php

namespace App\Http\Controllers\Admin;

use App\Models\PredictionPool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Prediction pools are deliberately not an always-on feature: the lobby shows
 * one only while an operator has published it, which is what makes it read as
 * an event rather than furniture.
 */
class PredictionPoolController
{
    public function index(): View
    {
        return view('admin.predictions.index', [
            'pools' => PredictionPool::query()
                ->withCount('entries')
                // Live first, then drafts waiting to go out, then history.
                // A CASE rather than MySQL's FIELD() so this is driver-agnostic.
                ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'closed' THEN 2 ELSE 3 END")
                ->orderByDesc('kicks_off_at')
                ->get(),
            'live' => PredictionPool::query()->live()->first(),
            // A pool can be published yet already past its closing time, in
            // which case the lobby shows nothing and the reason is invisible.
            // Surface it rather than letting the operator wonder.
            'expired' => PredictionPool::query()
                ->where('status', PredictionPool::STATUS_ACTIVE)
                ->where('closes_at', '<=', now())
                ->orderByDesc('closes_at')
                ->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $data['home_crest_path'] = $this->storeCrest($request, 'home_crest');
        $data['away_crest_path'] = $this->storeCrest($request, 'away_crest');

        PredictionPool::create($data);

        return back()->with('admin_status', 'Prediction pool created.');
    }

    public function update(Request $request, PredictionPool $pool): RedirectResponse
    {
        $data = $this->validated($request);

        foreach (['home' => 'home_crest', 'away' => 'away_crest'] as $side => $field) {
            if ($path = $this->storeCrest($request, $field)) {
                $old = $pool->{$side.'_crest_path'};
                $data[$side.'_crest_path'] = $path;
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            }
        }

        $pool->update($data);

        return back()->with('admin_status', 'Prediction pool updated.');
    }

    /**
     * Publishing is the switch that puts the card on the lobby. Only one pool
     * runs at a time — a second live pool would mean the lobby silently picked
     * one, so publishing a new pool stands the previous one down.
     */
    public function status(Request $request, PredictionPool $pool): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([
                PredictionPool::STATUS_DRAFT,
                PredictionPool::STATUS_ACTIVE,
                PredictionPool::STATUS_CLOSED,
            ])],
        ]);

        DB::transaction(function () use ($data, $pool) {
            if ($data['status'] === PredictionPool::STATUS_ACTIVE) {
                PredictionPool::query()
                    ->where('status', PredictionPool::STATUS_ACTIVE)
                    ->whereKeyNot($pool->getKey())
                    ->update(['status' => PredictionPool::STATUS_CLOSED]);
            }

            $pool->update($data);
        });

        return back()->with('admin_status', 'Prediction pool is now '.$data['status'].'.');
    }

    /**
     * Records the real score and marks the picks that matched. Prizes are not
     * auto-credited — the same posture as the daily tournament — so this only
     * decides who won, and payment stays a deliberate act.
     */
    public function settle(Request $request, PredictionPool $pool): RedirectResponse
    {
        $data = $request->validate([
            'home_score' => ['required', 'integer', 'min:0', 'max:50'],
            'away_score' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        DB::transaction(function () use ($data, $pool) {
            $pool->entries()->update(['is_winner' => false]);

            $pool->entries()
                ->where('home_score', $data['home_score'])
                ->where('away_score', $data['away_score'])
                ->update(['is_winner' => true]);

            $pool->update($data + [
                'status' => PredictionPool::STATUS_SETTLED,
                'settled_at' => now(),
            ]);
        });

        $winners = $pool->entries()->where('is_winner', true)->count();

        return back()->with('admin_status', $winners === 0
            ? 'Settled — nobody predicted that scoreline.'
            : 'Settled — '.number_format($winners).' correct prediction(s).');
    }

    public function entries(PredictionPool $pool): View
    {
        return view('admin.predictions.entries', [
            'pool' => $pool,
            'entries' => $pool->entries()->with('user')->latest()->paginate(50),
            // The spread of picks is the interesting part for an operator.
            'scorelines' => $pool->entries()
                ->selectRaw('home_score, away_score, COUNT(*) as picks')
                ->groupBy('home_score', 'away_score')
                ->orderByDesc('picks')
                ->limit(12)
                ->get(),
        ]);
    }

    public function destroy(PredictionPool $pool): RedirectResponse
    {
        foreach ([$pool->home_crest_path, $pool->away_crest_path] as $path) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        $pool->delete();

        return back()->with('admin_status', 'Prediction pool deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'competition' => ['required', 'string', 'max:120'],
            'home_team' => ['required', 'string', 'max:80'],
            'away_team' => ['required', 'string', 'max:80'],
            'prize_pool' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'min_deposit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'kicks_off_at' => ['required', 'date'],
            // Picks must shut before kick-off, or the pool could be joined by
            // someone already watching the match.
            'closes_at' => ['required', 'date', 'before_or_equal:kicks_off_at'],
            'status' => ['required', Rule::in([PredictionPool::STATUS_DRAFT, PredictionPool::STATUS_ACTIVE])],
        ]);
    }

    private function storeCrest(Request $request, string $field): ?string
    {
        if (! $request->hasFile($field)) {
            return null;
        }

        $request->validate([
            $field => ['image', 'mimes:png,jpg,jpeg,webp,svg', 'max:512'],
        ]);

        return $request->file($field)->store('prediction-crests', 'public');
    }
}
