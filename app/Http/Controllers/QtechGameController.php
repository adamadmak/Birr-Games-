<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\QtechSession;
use App\Services\QtechClient;
use App\Support\Qtech;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Opens a QTech game for a player.
 *
 * The order here is the whole design. The wallet session row is written FIRST,
 * then handed to QTech as the walletSessionId, and only then is the launch URL
 * requested — because the moment the game loads, QTech call our wallet with
 * that token, and QtechWalletController refuses any token it cannot find.
 * A player let into a game before the row existed would place bets that fail
 * to settle. So if anything after the row is written fails, the row is closed
 * again and the player is told, the same visible way BigBang launches fail.
 */
class QtechGameController
{
    public function launch(Request $request, Game $game, QtechClient $client): View|RedirectResponse
    {
        $user = $request->user();

        // The one guard that touches money. On the integration host a launch
        // would debit a real player's real balance against QTech's test system,
        // so only the fixture player may proceed; everyone else is told why
        // rather than shown a 404 they would report as a broken game.
        if ($game->isIntegrationOnly() && ! Qtech::isFixturePlayer($user)) {
            return redirect()->route('games.show', $game)
                ->withErrors(['provider' => 'QTech games are not open to players yet.']);
        }

        abort_unless(
            $game->isAvailableForTesting() && $game->playable && $game->content_server_enabled
            && $game->sync_source === 'qtech' && ($game->external_launch_code || $game->provider_game_id),
            404
        );

        if ($user->isAdmin()) {
            return redirect()->route('games.show', $game)
                ->withErrors(['provider' => 'Administrator accounts are restricted from playing casino games.']);
        }

        if (! $client->configured()) {
            return redirect()->route('games.show', $game)
                ->withErrors(['provider' => 'QTech games are not available yet. Please try again later.']);
        }

        // The wallet session. Its id is opaque to QTech and to us; the row is
        // what turns it into a player. Lifetime matches the wallet's own rule
        // so verify-session and this agree on when a token is dead.
        $session = QtechSession::create([
            'user_id' => $user->id,
            'external_session_id' => Str::lower(Str::random(64)),
            'currency' => Qtech::currency(),
            'status' => 'active',
            'expires_at' => now()->addHours(Qtech::sessionLifetimeHours()),
            'last_activity_at' => now(),
        ]);

        try {
            $launchUrl = $this->validatedLaunchUrl($client->launchUrl(
                $game,
                $user,
                $session->external_session_id,
                $this->device($request),
                route('home'),
            ));
        } catch (Throwable $exception) {
            report($exception);
            $session->forceFill(['status' => 'closed'])->save();

            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'QTech could not be reached. Please try again in a moment.';

            return redirect()->route('games.show', $game)->withErrors(['provider' => $message]);
        }

        // A player is in one game at a time. Retiring the other open sessions
        // means a stale token can never authorise a later debit. The session
        // for THIS launch already exists, so a failure here is cosmetic.
        try {
            QtechSession::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->whereKeyNot($session->id)
                ->update(['status' => 'closed', 'updated_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return view('games.provider', [
            'game' => $game->load('provider'),
            'launchUrl' => $launchUrl,
            'currency' => Qtech::currency(),
        ]);
    }

    /** QTech want to know which build to serve; the Telegram web view is a phone. */
    private function device(Request $request): string
    {
        $agent = Str::lower((string) $request->userAgent());

        return preg_match('/mobile|android|iphone|ipad|ipod/', $agent) ? 'mobile' : 'desktop';
    }

    /**
     * The URL goes straight into an iframe, so it must be one we would send a
     * player to: https, and on a host QTech own — the host itself or any
     * subdomain, because they serve games from a family of launcher hosts.
     */
    private function validatedLaunchUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        $allowed = false;
        foreach (Qtech::allowedLaunchHosts() as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $allowed = true;
                break;
            }
        }

        if (($parts['scheme'] ?? null) !== 'https' || $host === '' || ! $allowed) {
            throw new RuntimeException('QTech returned an unsafe or unexpected launch URL.');
        }

        return $url;
    }
}
