<?php

namespace App\Http\Controllers;

use App\Services\TelegramAccountLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The website half of connecting a player's account to the Telegram bot.
 *
 * It shows a one-time link, waits (by polling a tiny status endpoint) for the
 * bot to confirm, and lets the player disconnect again. The proof happens in
 * the bot: see App\Services\TelegramAccountLinker.
 */
class TelegramConnectController
{
    public function show(Request $request, TelegramAccountLinker $linker): View|RedirectResponse
    {
        $user = $request->user();
        // Administrators have no player wallet to connect; say so instead of showing a bare 404.
        if ($user->isAdmin()) {
            return redirect()->route('home')->with('status', 'Administrator accounts do not connect Telegram. Sign in with a player account to try it.');
        }

        $subscriber = $linker->subscriberFor($user);
        $link = null;
        $limited = false;

        // A new code is only minted when there is something to connect: an
        // already-connected player just sees their status.
        if (! $subscriber && $linker->available()) {
            $link = $linker->issue($user);
            $limited = $link === null;
        }

        return view('player.telegram-connect', [
            'available' => $linker->available(),
            'subscriber' => $subscriber,
            'link' => $link,
            'limited' => $limited,
            'welcome' => $request->boolean('welcome'),
        ]);
    }

    /** Polled by the page while it waits; says only whether the account is connected. */
    public function status(Request $request, TelegramAccountLinker $linker): JsonResponse
    {
        return response()->json(['connected' => $linker->isLinked($request->user())])
            ->header('Cache-Control', 'no-store');
    }

    /** Disconnect. The password is asked for again, so a borrowed session cannot cut a player off. */
    public function unlink(Request $request, TelegramAccountLinker $linker): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:1024']]);

        if (! Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'That password is not correct.']);
        }

        $linker->unlink($request->user());

        return redirect()->route('player.telegram.show')->with('status', 'Telegram was disconnected. Your wallet and history are unchanged.');
    }
}
