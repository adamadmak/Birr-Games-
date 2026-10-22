<?php

namespace App\Http\Controllers\Admin;

use App\Models\GameRound;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class RoundController
{
    /**
     * The round log, searchable by player or game and now by date.
     *
     * Without a date filter this page could only answer "what has this player
     * been doing", never "what happened on the fourteenth" — so an operator
     * investigating a spike on the dashboard had no way to reach the rounds
     * behind it except by paging back through the whole log. The dashboard links
     * here with its own period attached, which is what those two dates are for.
     */
    public function index(Request $request): View
    {
        $search = trim($request->string('search')->toString());
        $isDate = static fn (string $value): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);

        $from = $isDate($request->string('from')->toString()) ? $request->string('from')->toString() : '';
        $to = $isDate($request->string('to')->toString()) ? $request->string('to')->toString() : '';

        $rounds = GameRound::query()
            ->with('user')
            ->when($search, fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('game', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', '%'.ltrim($search, '@').'%')
                        ->orWhere('phone', 'like', "%{$search}%"));
            }))
            // Whole days, inclusive: an operator picking the same date twice means
            // "that day", not an empty midnight-to-midnight instant.
            ->when($from !== '', fn ($query) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($to !== '', fn ($query) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.rounds.index', compact('rounds', 'search', 'from', 'to'));
    }
}
