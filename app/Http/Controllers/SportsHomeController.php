<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\WinhouseSession;
use App\Support\CatalogCache;
use App\Support\Winhouse;
use App\Support\WinsTicker;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The front page: the WinHouse sportsbook first, with a shelf of casino games
 * under it. The casino lobby itself lives at /casino (LobbyController).
 */
class SportsHomeController
{
    /** How many casino tiles sit under the book. A teaser, not the catalogue. */
    private const CASINO_PICKS = 12;

    public function index(Request $request): View
    {
        return view('home', [
            'bookSrc' => $this->bookSrc($request),
            // Identical for every visitor and changed only by catalog syncs, so it
            // is cached like the lobby's featured game (revision-keyed, see CatalogCache).
            'casinoPicks' => CatalogCache::rememberAux(
                'home_casino_picks',
                300,
                fn () => Game::query()->available()->where('playable', true)->with('provider')
                    ->orderByDesc('top_game')->orderBy('sort_order')->orderBy('id')
                    ->take(self::CASINO_PICKS)->get()
            ),
            'recentWins' => WinsTicker::recent(),
        ]);
    }

    /**
     * WinHouse sportsbook (iframe.winhouse.bet).
     *
     * For a signed-in player we mint a short-lived launch token and open a
     * winhouse_sessions row. The book exchanges the token for its own session,
     * and every stake and settlement then comes back to this app's wallet API
     * as a signed server-to-server call — the player's money never leaves here.
     *
     * A guest gets the book without a token: they can browse odds and will be
     * asked to sign in on THIS site before betting.
     */
    private function bookSrc(Request $request): string
    {
        $key = Winhouse::iframeKey();
        $src = 'https://iframe.winhouse.bet/book?key='.$key.'&embed=1';
        // A booking code on our address opens that slip in the book (docs/agent.md 4.8).
        if ($booking = \App\Services\WinhouseTicketsClient::bookingCode($request->query('booking'))) {
            $src .= '&booking='.$booking;
        }
        $secret = Winhouse::apiKey();

        $player = $request->user();
        if ($player === null || $key === '' || $secret === '') {
            return $src;
        }

        $session = 'wh_'.$player->id.'_'.bin2hex(random_bytes(6));
        $expiry = (int) (microtime(true) * 1000) + 120000;   // 2 minutes is ample to load a page

        WinhouseSession::create([
            'user_id' => $player->id,
            'external_session_id' => $session,
            'mode' => Winhouse::mode(),
            'currency' => Winhouse::currency(),
            'status' => 'active',
            'expires_at' => now()->addHours(12),
            'last_activity_at' => now(),
        ]);

        $extId = 'player_'.$player->id;
        $sig = hash_hmac('sha256', $extId.'|'.$expiry.'|'.$session, $secret);

        return $src.'&launch='.urlencode($extId.'.'.$expiry.'.'.$session.'.'.$sig);
    }
}
