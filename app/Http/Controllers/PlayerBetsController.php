<?php

namespace App\Http\Controllers;

use App\Services\WinhouseTicketsClient;
use App\Support\BetPick;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * My Bets: the signed-in player's sportsbook tickets, one tap from the menu,
 * each pick marked won or lost where the final score settles it (BetPick).
 * Read-only: WinHouse's tickets API, filtered to this player.
 */
class PlayerBetsController
{
    private const PER_PAGE = 15;

    /** Our tabs, and the WinHouse status each one asks for. */
    public const TABS = ['all' => null, 'open' => 'open', 'won' => 'won', 'lost' => 'lost'];

    public function index(Request $request, WinhouseTicketsClient $client): View
    {
        $player = $request->user();
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'all';
        $page = max(1, (int) $request->query('page', 1));

        $result = $client->tickets([
            'player_id' => 'player_'.$player->id,
            'status' => self::TABS[$tab],
            'limit' => self::PER_PAGE,
            'offset' => ($page - 1) * self::PER_PAGE,
        ]);

        // Kept to this player even if WinHouse ever ignored the filter.
        $tickets = collect($result['tickets'])
            ->filter(fn (array $t) => $t['player_id'] === (int) $player->id)
            ->map(fn (array $t) => $t + ['pick_results' => BetPick::results($t)])
            ->values();

        $total = (int) ($result['meta']['total'] ?? $tickets->count());

        return view('player.bets', [
            'tickets' => $tickets,
            'tab' => $tab,
            'page' => $page,
            'hasMore' => $page * self::PER_PAGE < $total,
            'unavailable' => ! $result['ok'],
        ]);
    }
}
