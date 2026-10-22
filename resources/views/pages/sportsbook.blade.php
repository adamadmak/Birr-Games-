@extends('layouts.app')
@section('title', 'Sportsbook — Lucy Play')
@section('content')
@php
    /*
     * WinHouse sportsbook (tenant "Lucy chewata", iframe.winhouse.bet).
     *
     * For a signed-in player we mint a short-lived launch token and open a
     * winhouse_sessions row. The book exchanges the token for its own session,
     * and every stake and settlement then comes back to this app's wallet API
     * as a signed server-to-server call — the player's money never leaves here.
     *
     * A guest gets the book without a token: they can browse odds and will be
     * asked to sign in on THIS site before betting.
     */
    $whKey    = \App\Support\Winhouse::iframeKey();
    $whSrc    = 'https://iframe.winhouse.bet/book?key=' . $whKey . '&embed=1';
    $whSecret = \App\Support\Winhouse::apiKey();

    if (auth()->check() && $whKey !== '' && $whSecret !== '') {
        $player  = auth()->user();
        $session = 'wh_' . $player->id . '_' . bin2hex(random_bytes(6));
        $expiry  = (int) (microtime(true) * 1000) + 120000;   // 2 minutes is ample to load a page

        \App\Models\WinhouseSession::create([
            'user_id'             => $player->id,
            'external_session_id' => $session,
            'mode'                => \App\Support\Winhouse::mode(),
            'currency'            => \App\Support\Winhouse::currency(),
            'status'              => 'active',
            'expires_at'          => now()->addHours(12),
            'last_activity_at'    => now(),
        ]);

        $extId  = 'player_' . $player->id;
        $sig    = hash_hmac('sha256', $extId . '|' . $expiry . '|' . $session, $whSecret);
        $whSrc .= '&launch=' . urlencode($extId . '.' . $expiry . '.' . $session . '.' . $sig);
    }
@endphp
<div class="content-page sportsbook-page">
    <iframe
        src="{{ $whSrc }}"
        title="Sportsbook"
        allow="fullscreen"
        referrerpolicy="strict-origin-when-cross-origin"></iframe>
</div>

<style>
/* Let the book own the viewport rather than sitting in a fixed-height box — a
   sportsbook that scrolls inside a scroll is unusable. */
/* Specificity matters here: the theme has
   `.player-authenticated/.player-shell .content-page{width:min(1180px,calc(100% - 44px));padding-top:52px}`
   at (0,2,0), which outranks a lone `.sportsbook-page` — so a signed-in player
   got the narrow 1180px column back while a guest saw full width. These
   selectors match/beat it. */
.content-page.sportsbook-page,
.player-authenticated .content-page.sportsbook-page,
.player-shell .content-page.sportsbook-page {
    padding: 0;
    margin-top: 0;
    /* The theme sets .content-page{width:min(1180px,calc(100% - 36px));margin:auto},
       so it is a fixed centred column — max-width alone cannot widen it, the
       width itself has to go. 100% of <main>, not 100vw: 100vw counts the
       scrollbar and would leave a ~15px horizontal scroll on desktop. */
    max-width: none;
    width: 100%;
    margin-left: 0;
    margin-right: 0;
}
.sportsbook-page iframe {
    display: block;
    width: 100%;
    height: calc(100dvh - 78px);   /* measured header height, was 70 and overshot by 8px */
    min-height: 560px;
    border: 0;
    /* Transparent so the tenant's own Page colour decides. A literal here
       painted over it: a Page set to transparent showed this navy instead of
       the site behind, which read as "transparency does nothing". */
    background: transparent;
}
@media (max-width: 760px) {
    /* .content-page insets its children 18px a side; break out of it so the
       board is not squeezed on a phone. */
    .content-page.sportsbook-page,
    .player-authenticated .content-page.sportsbook-page,
    .player-shell .content-page.sportsbook-page {
        margin-top: 0;
        width: 100%;
        margin-left: 0;
        margin-right: 0;
    }

    /* The book renders its own fixed tab bar (56px) along the bottom of the
       iframe. The site's cashback pill is fixed at bottom:78px — clear of the
       site's 66px nav but landing exactly on that tab bar, hiding Search /
       My Bets / Favorites. Lift it above the tab bar on this page only. */
    body .player-cashback-pill { bottom: calc(78px + 56px); }
    /* Site header (~66px) plus the fixed .player-mobile-nav (66px): without
       this the book's own tab bar renders under the site's nav. */
    .sportsbook-page iframe {
        height: calc(100dvh - 132px);
        min-height: 420px;
    }
}
</style>
@endsection
