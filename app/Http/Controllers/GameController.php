<?php

namespace App\Http\Controllers;

use App\GameAgents\GameAgentManager;
use App\Models\Game;
use App\Models\PlatformSetting;
use App\Models\GameRound;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WageringService;
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

    public function play(Request $request, Game $game, GameAgentManager $agents, WageringService $wagering): RedirectResponse
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

        $round = DB::transaction(function () use ($request, $game, $validated, $result, $wagering) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $stake = (int) $validated['stake'];

            if ($user->credits < $stake) {
                return null;
            }

            $round = GameRound::create([
                'user_id' => $user->id,
                'game' => $game->slug,
                'stake' => $stake,
                'payout' => $result['payout'],
                'result' => $result,
            ]);

            $lockedCredits = max(0, $user->credits - $user->withdrawable_balance);
            $withdrawableStake = max(0, $stake - $lockedCredits);
            // The bonus part of the stake returns to the bonus wallet; only the rest of
            // the payout becomes withdrawable (see App\Services\BonusPlay).
            $bonusKept = \App\Services\BonusPlay::immediate((float) $stake, (float) $withdrawableStake, (float) $result['payout']);
            $user->update([
                'credits' => $user->credits - $stake + $result['payout'],
                'withdrawable_balance' => max(0, $user->withdrawable_balance - $withdrawableStake + (int) $result['payout'] - $bonusKept),
            ]);
            WalletTransaction::create([
                'user_id' => $user->id, 'type' => 'bet', 'amount' => -$stake,
                'status' => 'success', 'method' => 'game', 'reason' => $game->title.' stake',
                'currency' => 'ETB', 'source_type' => 'game_round', 'source_id' => $round->id,
            ]);
            if ($result['payout'] > 0) {
                WalletTransaction::create([
                    'user_id' => $user->id, 'type' => 'payout', 'amount' => (int) $result['payout'],
                    'status' => 'success', 'method' => 'game', 'reason' => $game->title.' payout',
                    'currency' => 'ETB', 'source_type' => 'game_round', 'source_id' => $round->id,
                ]);
            }
            $wagering->recordStake($user, $stake);

            return $round;
        });

        if (!$round) {
            return back()->withErrors(['stake' => 'Your Birr balance is too low for that stake.']);
        }

        return back()->with('round', $round->result + ['stake' => $round->stake, 'payout' => $round->payout]);
    }
}
