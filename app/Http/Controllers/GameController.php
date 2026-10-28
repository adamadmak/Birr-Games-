<?php

namespace App\Http\Controllers;

use App\GameAgents\GameAgentManager;
use App\Models\Game;
use App\Models\PlatformSetting;
use App\Models\GameRound;
use App\Models\User;
use App\Services\Wallet\LedgerEntry;
use App\Services\Wallet\PlayerWallet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;

class GameController
{
    public function show(Game $game, GameAgentManager $agents): View
    {
        // Not just $game->active: a game reached by its direct URL has to answer to
        // the same provider and category switches the lobby honours, or switching a
        // provider off only hides it while it stays playable to anyone with the link.
        // An integration-only QTech game answers 404 to everyone except the
        // fixture player minted for verification, who needs the page to reach
        // Play Now. See Game::isIntegrationOnly().
        abort_unless($game->isVisibleTo(auth()->user()), 404);
        $agent = $agents->for($game->agent);

        return view('games.show', [
            'game' => $game->load('provider'),
            'agent' => $agent,
            'choices' => $agent?->choices() ?? [],
            'recentRounds' => auth()->check()
                ? auth()->user()->rounds()->where('game', $game->slug)->latest()->limit(8)->get()
                : collect(),
            'welcomeBalance' => (int) PlatformSetting::valueFor('welcome_credits', 5),
        ]);
    }

    public function play(Request $request, Game $game, GameAgentManager $agents, PlayerWallet $wallet): RedirectResponse
    {
        $agent = $agents->for($game->agent);
        abort_unless($game->isAvailable() && $game->playable && $agent, 404);

        if ($request->user()->isAdmin()) {
            return back()->withInput()->withErrors(['stake' => 'Administrator accounts are restricted from playing casino games.']);
        }

        $validated = $request->validate([
            'stake' => ['required', 'integer', 'min:10', 'max:1000'],
            'choice' => ['required', 'string', 'max:30'],
        ]);

        try {
            $result = $agent->play($validated['choice'], (int) $validated['stake']);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['choice' => $exception->getMessage()]);
        }

        $round = DB::transaction(function () use ($request, $game, $validated, $result, $wallet) {
            $user = $wallet->lock($request->user());
            $stake = (float) $validated['stake'];

            if (! $wallet->covers($user, $stake)) {
                return null;
            }

            $round = GameRound::create([
                'user_id' => $user->id,
                'game' => $game->slug,
                'stake' => $stake,
                'payout' => $result['payout'],
                'result' => $result,
            ]);

            // Stake and payout in one step: bonus credit first, the bonus part
            // of the stake back as bonus, two ledger rows, the wagering credit.
            $wallet->playRound(
                $user,
                $stake,
                (float) $result['payout'],
                new LedgerEntry(method: 'game', reason: $game->title.' stake', sourceType: 'game_round', sourceId: $round->id),
                new LedgerEntry(method: 'game', reason: $game->title.' payout', sourceType: 'game_round', sourceId: $round->id),
            );

            return $round;
        });

        if (!$round) {
            return back()->withErrors(['stake' => 'Your Birr balance is too low for that stake.']);
        }

        return back()->with('round', $round->result + ['stake' => $round->stake, 'payout' => $round->payout]);
    }
}
