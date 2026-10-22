<?php

namespace App\Http\Controllers;

use App\Models\PredictionEntry;
use App\Models\PredictionPool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PredictionController
{
    /**
     * Serve a club crest straight out of the public disk.
     *
     * Crests used to be linked as /storage/..., which only resolves through the
     * public/storage symlink. That symlink is not in the repository and nothing in
     * the deploy creates it, so on the live host every crest 404'd and the card fell
     * back to team initials — the bug this route removes. Reading the file through
     * PHP needs no filesystem setup at all, which also means a fresh deploy cannot
     * silently lose the crests again.
     */
    public function crest(PredictionPool $pool, string $side): StreamedResponse
    {
        $path = $side === 'home' ? $pool->home_crest_path : $pool->away_crest_path;

        abort_unless($path && Storage::disk('public')->exists($path), 404);

        // The URL carries an updated_at cache-buster, so a stored crest can be
        // treated as immutable until the operator replaces it.
        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function join(Request $request, PredictionPool $pool): RedirectResponse
    {
        $data = $request->validate([
            'home_score' => ['required', 'integer', 'min:0', 'max:20'],
            'away_score' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        // Re-checked server-side: the card hides itself once the countdown ends,
        // but a form left open in a background tab would still post.
        if (! $pool->isOpen()) {
            throw ValidationException::withMessages([
                'home_score' => 'Predictions for this match are closed.',
            ]);
        }

        $user = $request->user();

        if ($pool->min_deposit > 0 && $user->credits < $pool->min_deposit) {
            throw ValidationException::withMessages([
                'home_score' => 'A balance of at least '.\App\Support\Money::format($pool->min_deposit).' is needed to enter.',
            ]);
        }

        // The unique index is the real guard; this only turns a duplicate into
        // an update rather than a constraint violation the player would see.
        PredictionEntry::updateOrCreate(
            ['prediction_pool_id' => $pool->getKey(), 'user_id' => $user->getKey()],
            $data,
        );

        return back()->with('status', 'Your prediction is in. Good luck.');
    }
}
