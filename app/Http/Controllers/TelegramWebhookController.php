<?php

namespace App\Http\Controllers;

use App\Exceptions\TelegramPhoneRequiredException;
use App\Models\PlatformSetting;
use App\Models\TelegramSubscriber;
use App\Services\TelegramAccountLinker;
use App\Services\TelegramBotService;
use App\Services\TelegramDepositService;
use App\Services\TelegramPlayerService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TelegramWebhookController
{
    public function __invoke(
        Request $request,
        TelegramBotService $telegram,
        TelegramPlayerService $players,
        TelegramDepositService $deposits,
        TelegramAccountLinker $linker,
    ): JsonResponse {
        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! $telegram->validWebhookSecret($provided)) {
            return response()->json(['ok' => false], 403);
        }

        // Telegram redelivers any update this endpoint does not answer promptly, and
        // a redelivery carries the SAME update_id. Without this guard a slow update
        // came back while the first copy was still running, the copies queued on the
        // same subscriber row, each blocked one of the few serve workers for the full
        // lock wait, and their failures produced yet more redeliveries — a storm that
        // fed itself. Claiming the id atomically means a duplicate costs nothing.
        $updateId = $request->input('update_id');
        if (is_int($updateId) || (is_string($updateId) && $updateId !== '')) {
            if (! Cache::add('telegram:update:'.$updateId, 1, now()->addMinutes(10))) {
                return response()->json(['ok' => true, 'duplicate' => true]);
            }
        }

        $message = $request->input('message');
        $from = data_get($message, 'from');
        $chat = data_get($message, 'chat');
        if (! is_array($from) || ! is_array($chat) || data_get($chat, 'type') !== 'private') {
            return response()->json(['ok' => true]);
        }

        $text = trim((string) data_get($message, 'text', ''));
        $command = mb_strtolower(strtok($text, ' ') ?: '');
        $action = mb_strtolower($text);
        $contact = data_get($message, 'contact');
        $subscriber = TelegramSubscriber::updateOrCreate(
            ['telegram_user_id' => (int) data_get($from, 'id')],
            [
                'chat_id' => (int) data_get($chat, 'id'),
                'username' => data_get($from, 'username'),
                'first_name' => data_get($from, 'first_name'),
                'last_name' => data_get($from, 'last_name'),
                'language_code' => data_get($from, 'language_code'),
                'chat_type' => data_get($chat, 'type', 'private'),
                'last_seen_at' => now(),
            ]
        );

        try {
            if (is_array($contact)) {
                $this->handleSharedContact($contact, $from, $subscriber, $telegram, $players, $linker);
            } elseif (str_starts_with($command, '/start') && ($linkCode = $linker->codeFromStart($text)) !== null) {
                // Opened from the website's Connect Telegram button: connect that account.
                $this->startLink($linkCode, $subscriber, $telegram, $players, $linker);
            } elseif (str_starts_with($command, '/stop')) {
                $subscriber->update(['active' => false, 'stopped_at' => now()]);
                $telegram->sendText($subscriber->chat_id, 'You have been unsubscribed. Send /start to join again.', [], $telegram->removeKeyboard());
            } elseif (! $subscriber->hasSharedPhone()) {
                // Phone sharing is mandatory: nothing else is available until Telegram
                // has verified the number, so one person can only ever own one account.
                $this->requestPhone($subscriber, $telegram, $deposits, str_starts_with($command, '/start'));
            } elseif ($this->isAction($action, ['❌ cancel', 'cancel', '/cancel'])) {
                $deposits->cancel($subscriber);
                $telegram->sendText($subscriber->chat_id, 'Deposit cancelled. Ready to play?', [], $telegram->mainMenuKeyboard());
            } elseif ($subscriber->pending_action === 'deposit_amount' && $this->isDepositAmount($text)) {
                $amount = (float) str_replace(',', '', $text);
                $minimum = (float) PlatformSetting::valueFor('min_deposit_amount', 50);
                $maximum = (float) PlatformSetting::valueFor('max_deposit_amount', 100_000);

                if ($amount < $minimum || $amount > $maximum) {
                    $telegram->sendText(
                        $subscriber->chat_id,
                        'Enter an amount from '.number_format($minimum, 2).' to '.number_format($maximum, 2).' ETB, or press Cancel.',
                        [],
                        $telegram->cancelKeyboard()
                    );
                } else {
                    $players->ensurePlayer($subscriber);
                    $subscriber->refresh();
                    try {
                        $bankLabels = $deposits->start($subscriber, $amount);
                    } catch (ValidationException $exception) {
                        $message = collect($exception->errors())->flatten()->first() ?: 'This deposit is outside your configured wallet limits.';
                        $telegram->sendText($subscriber->chat_id, e($message), [], $telegram->mainMenuKeyboard());

                        return response()->json(['ok' => true]);
                    }

                    if ($bankLabels === []) {
                        $telegram->sendText(
                            $subscriber->chat_id,
                            'No agent bank account is available right now. Please try again later.',
                            [],
                            $telegram->mainMenuKeyboard()
                        );
                    } else {
                        $telegram->sendText(
                            $subscriber->chat_id,
                            'Please select the bank you will use to send the payment:',
                            [],
                            $telegram->choiceKeyboard($bankLabels, 'Select a bank')
                        );
                    }
                }
            } elseif ($subscriber->pending_action === 'deposit_amount') {
                $minimum = (float) PlatformSetting::valueFor('min_deposit_amount', 50);
                $telegram->sendText(
                    $subscriber->chat_id,
                    "Enter a valid numeric deposit amount.\n\nMinimum: ".number_format($minimum, 2).' ETB',
                    [],
                    $telegram->cancelKeyboard()
                );
            } elseif ($subscriber->pending_action === 'deposit_bank') {
                try {
                    $order = $deposits->chooseBank($subscriber, $text);
                } catch (\RuntimeException $exception) {
                    $deposits->cancel($subscriber);
                    $telegram->sendText($subscriber->chat_id, e($exception->getMessage()), [], $telegram->mainMenuKeyboard());

                    return response()->json(['ok' => true]);
                }

                if (! $order) {
                    $labels = collect(data_get($subscriber->pending_payload, 'options', []))
                        ->pluck('label')->filter()->values()->all();
                    $telegram->sendText(
                        $subscriber->chat_id,
                        'Choose one of the available banks below, or press Cancel.',
                        [],
                        $telegram->choiceKeyboard($labels, 'Select a bank')
                    );
                } else {
                    $telegram->sendText(
                        $subscriber->chat_id,
                        "<b>Deposit order created!</b>\n\n"
                            .'Amount: '.$this->birr($order->amount)."\n"
                            .'Bank: '.e($order->gateway?->name ?: 'Agent bank')."\n"
                            .'Account Number: '.e($order->account_number)."\n"
                            .'Account Name: '.e($order->account_name)."\n\n"
                            .'Please send the amount to the account above, then enter the bank reference number or confirmation message.',
                        [],
                        $telegram->cancelKeyboard('Enter bank reference')
                    );
                }
            } elseif ($subscriber->pending_action === 'deposit_reference') {
                try {
                    $order = $deposits->submitReference($subscriber, $text);
                } catch (\RuntimeException $exception) {
                    $telegram->sendText(
                        $subscriber->chat_id,
                        e($exception->getMessage()),
                        [],
                        $telegram->cancelKeyboard('Enter bank reference')
                    );

                    return response()->json(['ok' => true]);
                }

                $telegram->sendText(
                    $subscriber->chat_id,
                    "<b>Deposit submitted for approval.</b>\n\n"
                        .'Reference: '.e($order->reference)."\n"
                        .'Amount: '.$this->birr($order->amount)."\n"
                        .'Agent: '.e($order->agent?->nickname ?: 'Assigned agent')."\n\n"
                        .'Your wallet will be credited after the agent verifies and approves the payment.',
                    [],
                    $telegram->mainMenuKeyboard()
                );
            } elseif (str_starts_with($command, '/start')) {
                $subscriber->update([
                    'active' => true,
                    'pending_action' => null,
                    'pending_payload' => null,
                    'started_at' => $subscriber->started_at ?? now(),
                    'stopped_at' => null,
                ]);
                $players->ensurePlayer($subscriber);
                $subscriber->refresh();
                $url = $players->createLoginLink($subscriber, 'lobby');
                try {
                    $telegram->setChatMenuButton($subscriber->chat_id, $url, '🎮 Play Games');
                } catch (\Throwable $e) {}
                $logoPath = public_path('logo.png');
                $startCaption = '<b>Hey '.e($subscriber->first_name ?: 'Player')."! 👋</b>\n\nYour account is verified with "
                    .e(PhoneNumber::format($subscriber->phone)).'. Choose an action below.';
                if (file_exists($logoPath)) {
                    $telegram->sendPhoto($subscriber->chat_id, $logoPath, $startCaption, [], $telegram->mainMenuKeyboard());
                } else {
                    $telegram->sendText($subscriber->chat_id, $startCaption, [], $telegram->mainMenuKeyboard());
                }
            } elseif (str_starts_with($command, '/link')) {
                // Agents link their Telegram for deposit and withdrawal alerts; players connect their
                // website account with the code from Connect Telegram. The code follows the command
                // ($command is only its first word).
                $code = strtoupper(trim(substr($text, 5)));
                $agent = strlen($code) >= 6
                    ? \App\Models\Agent::query()->where('telegram_link_code', $code)->first()
                    : null;

                if (strlen($code) >= 6 && ! $agent && preg_match('/^[A-Z0-9]{6,16}$/', $code)) {
                    $this->startLink($code, $subscriber, $telegram, $players, $linker);
                } elseif (strlen($code) < 6) {
                    $telegram->sendText($subscriber->chat_id, '<b>Missing link code</b>'."\n\n".'Send /link followed by the code shown in your agent portal.', [], $telegram->mainMenuKeyboard());
                } elseif (! $agent) {
                    $telegram->sendText($subscriber->chat_id, '<b>Code not recognized</b>'."\n\n".'That link code does not exist or has already been used. Generate a new one in your agent portal.', [], $telegram->mainMenuKeyboard());
                } else {
                    $agent->forceFill([
                        'telegram_chat_id' => $subscriber->chat_id,
                        'telegram_link_code' => null,
                        'telegram_linked_at' => now(),
                    ])->save();

                    $telegram->sendText(
                        $subscriber->chat_id,
                        '<b>✅ Your agent account is now linked</b>'."\n\n"
                            .'You will receive instant push notifications here when a deposit needs approval or a withdrawal is assigned to you.',
                        [],
                        $telegram->mainMenuKeyboard()
                    );
                }
            } elseif (str_starts_with($command, '/password')) {
                $user = $players->ensurePlayer($subscriber);
                $subscriber->refresh();

                // A password is the key to a wallet. Three an hour is plenty for a player who
                // lost one, and too few for anyone using the bot to lock a player out.
                $limitKey = 'tg-password:'.$subscriber->id;
                if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limitKey, 3)) {
                    $telegram->sendText($subscriber->chat_id, 'You asked for a new password a few times already. Please wait an hour and try again.', [], $telegram->mainMenuKeyboard());

                    return response()->json(['ok' => true]);
                }
                \Illuminate\Support\Facades\RateLimiter::hit($limitKey, 3600);

                $password = $players->reissuePassword($user);
                $this->sendSecret(
                    $telegram,
                    $subscriber->chat_id,
                    "<b>🔑 New website password</b>\n\n"
                        .'📱 Phone: <code>'.e(PhoneNumber::format($user->phone ?: $subscriber->phone)).'</code>'."\n"
                        .'🔑 Password: <code>'.e($password).'</code>'."\n\n"
                        .'Use these to sign in on the website. Your old password no longer works, and any other browser you were signed in on has been signed out.',
                    $telegram->mainMenuKeyboard()
                );
            } elseif (str_starts_with($command, '/status')) {
                $status = $subscriber->active ? 'Your Vamoseone bot is active.' : 'Your bot is paused. Send /start to activate it.';
                $account = $subscriber->user_id
                    ? ' Your player account is connected to '.e(PhoneNumber::format($subscriber->phone)).'.'
                    : ' Press Play Games to open your player account.';
                $telegram->sendText($subscriber->chat_id, $status.$account, [], $telegram->mainMenuKeyboard());
            } elseif ($this->isAction($action, ['🎮 play games', 'play games', '/play'])) {
                $wasNew = ! $subscriber->user_id;
                $user = $players->ensurePlayer($subscriber);
                $subscriber->refresh();
                $url = $players->createLoginLink($subscriber, 'lobby');
                $message = $wasNew
                    ? '<b>Your Vamoseone account is ready! 🎉</b>'."\n\nWelcome balance: ".$this->birr($user->credits).'. Tap below to start playing.'
                    : '<b>Ready to play? 🎮</b>'."\n\nYour balance is ".$this->birr($user->credits).'. Tap below to open the games.';
                $telegram->sendText($subscriber->chat_id, $message, [
                    ['text' => '🎮 Open Game', 'type' => 'web_app', 'value' => $url],
                ]);
            } elseif ($this->isAction($action, ['💰 deposit', 'deposit', '/deposit'])) {
                $minimum = (float) PlatformSetting::valueFor('min_deposit_amount', 50);
                $subscriber->update(['pending_action' => 'deposit_amount', 'pending_payload' => null]);
                $telegram->sendText(
                    $subscriber->chat_id,
                    "Enter the amount you would like to deposit:\n\nMinimum: ".number_format($minimum, 2).' ETB',
                    [],
                    $telegram->cancelKeyboard()
                );
            } elseif ($this->isAction($action, ['💵 check balance', 'check balance', '/balance'])) {
                if (! $subscriber->user_id) {
                    $user = $players->ensurePlayer($subscriber);
                    $subscriber->refresh();
                    $telegram->sendText($subscriber->chat_id, '💰 <b>Balance: '.$this->birr($user->credits).'</b>'.$this->balanceBreakdown($user), [], $telegram->mainMenuKeyboard());
                } else {
                    $user = $subscriber->user()->firstOrFail();
                    $telegram->sendText($subscriber->chat_id, '💰 <b>Balance: '.$this->birr($user->credits).'</b>'.$this->balanceBreakdown($user), [], $telegram->mainMenuKeyboard());
                }
            } else {
                $telegram->sendText($subscriber->chat_id, 'Choose Play Games, Deposit, or Check Balance below.', [], $telegram->mainMenuKeyboard());
            }
        } catch (TelegramPhoneRequiredException $exception) {
            $subscriber->update(['pending_action' => 'await_phone', 'pending_payload' => null]);
            $telegram->sendText($subscriber->chat_id, e($exception->getMessage()), [], $telegram->contactKeyboard());
        } catch (\Throwable $exception) {
            report($exception);
            Log::error('Telegram webhook processing failed; this update is dropped.', [
                'update_id' => $updateId,
                'telegram_user_id' => $subscriber->telegram_user_id,
                'message' => $exception->getMessage(),
            ]);

            // Deliberately 200, not 500. Telegram retries a failed update for hours,
            // and the failures worth retrying here are precisely the ones a retry
            // makes worse: a busy database, a slow provider, a stalled worker pool.
            // The retries were arriving faster than the work drained. A player whose
            // tap is dropped simply taps again; the site staying up is worth more
            // than one recovered bot message.
            return response()->json(['ok' => true, 'handled' => false]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Ask for the contact card and park the conversation until it arrives. Nothing
     * else can happen first, so a member who already registered on the website is
     * always matched by phone instead of being registered again.
     */
    private function requestPhone(
        TelegramSubscriber $subscriber,
        TelegramBotService $telegram,
        TelegramDepositService $deposits,
        bool $isStart,
    ): void {
        if (in_array($subscriber->pending_action, ['deposit_amount', 'deposit_bank', 'deposit_reference'], true)) {
            $deposits->cancel($subscriber);
        }

        // A chat that is part-way through connecting a website account keeps that state:
        // overwriting it would drop the link the moment the player sends another message.
        $connecting = $subscriber->pending_action === 'link_phone';
        $subscriber->update([
            'active' => true,
            'pending_action' => $connecting ? 'link_phone' : 'await_phone',
            'pending_payload' => $connecting ? $subscriber->pending_payload : null,
            'started_at' => $subscriber->started_at ?? now(),
            'stopped_at' => null,
        ]);

        $caption = '<b>Hey '.e($subscriber->first_name ?: 'Player')."! 👋</b>\n\n"
            ."Vamoseone needs your phone number before you can play.\n\n"
            ."Tap <b>📱 Share My Phone Number</b> below. Telegram verifies the number for us, and if you already have a Vamoseone account we reconnect you to it instead of creating a new one.\n\n"
            .'Typing the number will not work — please use the button.';

        $logoPath = public_path('logo.png');
        if ($isStart && file_exists($logoPath)) {
            $telegram->sendPhoto($subscriber->chat_id, $logoPath, $caption, [], $telegram->contactKeyboard());

            return;
        }

        $telegram->sendText($subscriber->chat_id, $caption, [], $telegram->contactKeyboard());
    }

    /**
     * Telegram only populates `message.contact` from the request_contact button, and
     * `contact.user_id` proves the card belongs to the sender rather than a forward.
     */
    private function handleSharedContact(
        array $contact,
        array $from,
        TelegramSubscriber $subscriber,
        TelegramBotService $telegram,
        TelegramPlayerService $players,
        TelegramAccountLinker $linker,
    ): void {
        if ((int) data_get($contact, 'user_id') !== (int) data_get($from, 'id')) {
            $telegram->sendText(
                $subscriber->chat_id,
                "That contact belongs to somebody else. Please tap <b>📱 Share My Phone Number</b> to send <b>your own</b> number.",
                [],
                $telegram->contactKeyboard()
            );

            return;
        }

        $players->recordSharedPhone($subscriber, (string) data_get($contact, 'phone_number'));
        $subscriber->refresh();

        if ($subscriber->pending_action === 'link_phone') {
            $this->finishLink($subscriber, $telegram, $players, $linker);

            return;
        }

        $reconnected = $subscriber->user_id !== null
            || $players->findMemberByPhone((string) $subscriber->phone) !== null;

        $user = $players->ensurePlayer($subscriber);
        $subscriber->refresh();
        $subscriber->update([
            'active' => true,
            'pending_action' => null,
            'pending_payload' => null,
            'started_at' => $subscriber->started_at ?? now(),
            'stopped_at' => null,
        ]);

        $url = $players->createLoginLink($subscriber, 'lobby');
        try {
            $telegram->setChatMenuButton($subscriber->chat_id, $url, '🎮 Play Games');
        } catch (\Throwable $e) {
        }

        $password = $players->issuedPassword();

        $message = ($reconnected ? '<b>Welcome back! ✅</b>' : '<b>🎉 Welcome, '.e($subscriber->first_name ?: 'Player').'!</b>')."\n\n"
            .'📱 Phone: <code>'.e(PhoneNumber::format($subscriber->phone)).'</code>'."\n"
            .'💰 Balance: '.$this->birr($user->credits)."\n";

        if ($password !== null) {
            // Shown once, here only: the account is created by the bot, so this is the
            // player's only chance to learn the password for signing in on a browser.
            $message .= '🔑 Password: <code>'.e($password).'</code>'."\n\n"
                .'⚠️ <b>Save this password.</b> Sign in on the website with your phone number and this password. '
                .'Send /password any time to get a new one.';
        } elseif ($reconnected) {
            $message .= "\n".'We reconnected you to your existing Vamoseone account, so your balance and history are unchanged. '
                .'Send /password if you need a new website password.';
        } else {
            $message .= "\n".'Your player account is ready. Choose an action below.';
        }

        if ($password !== null) {
            // The one message that carries a password is removed from the chat shortly after.
            $this->sendSecret($telegram, $subscriber->chat_id, $message, $telegram->mainMenuKeyboard());

            return;
        }

        $telegram->sendText($subscriber->chat_id, $message, [], $telegram->mainMenuKeyboard());
    }

    /**
     * Opened from the website with a one-time code. Check it, then ask for the
     * contact card (or finish at once if this chat has already shared its number).
     */
    private function startLink(
        string $code,
        TelegramSubscriber $subscriber,
        TelegramBotService $telegram,
        TelegramPlayerService $players,
        TelegramAccountLinker $linker,
    ): void {
        $result = $linker->begin($subscriber, $code);

        if ($result['error'] !== null) {
            $telegram->sendText($subscriber->chat_id, '<b>Could not connect</b>'."\n\n".e($result['error']), [], $telegram->mainMenuKeyboard());

            return;
        }

        if ($result['needs_contact']) {
            $telegram->sendText(
                $subscriber->chat_id,
                '<b>🔗 Connect your account</b>'."\n\n"
                    .'One last step. Tap <b>📱 Share My Phone Number</b> below so Telegram can confirm the number on your account is yours.',
                [],
                $telegram->contactKeyboard()
            );

            return;
        }

        $this->finishLink($subscriber->fresh(), $telegram, $players, $linker);
    }

    /** The contact card is known: confirm it matches the account, link, and welcome the player. */
    private function finishLink(
        TelegramSubscriber $subscriber,
        TelegramBotService $telegram,
        TelegramPlayerService $players,
        TelegramAccountLinker $linker,
    ): void {
        $result = $linker->complete($subscriber);

        if ($result['error'] !== null) {
            $subscriber->refresh();
            // Still waiting on a better contact card? Keep the share button in front of them.
            $keyboard = $subscriber->pending_action === 'link_phone' ? $telegram->contactKeyboard() : $telegram->mainMenuKeyboard();
            $telegram->sendText($subscriber->chat_id, '<b>Could not connect</b>'."\n\n".e($result['error']), [], $keyboard);

            return;
        }

        $subscriber->refresh();
        $user = $result['user'];
        $url = $players->createLoginLink($subscriber, 'lobby');
        try {
            $telegram->setChatMenuButton($subscriber->chat_id, $url, '🎮 Play Games');
        } catch (\Throwable) {
        }

        $telegram->sendText(
            $subscriber->chat_id,
            '<b>✅ Connected!</b>'."\n\n"
                .'Your Telegram is now linked to <b>'.e($user->name).'</b>'."\n"
                .'📱 '.e(PhoneNumber::format($user->phone))."\n"
                .'💰 Balance: '.$this->birr($user->credits)."\n\n"
                .'You can play, deposit and check your balance here, and sign in on the website with your own password.',
            [['text' => '🎮 Open Game', 'type' => 'web_app', 'value' => $url]],
            null
        );
        $telegram->sendText($subscriber->chat_id, 'Choose an action below.', [], $telegram->mainMenuKeyboard());
    }

    /**
     * Send a message that carries a password and schedule its removal from the
     * chat, so a sign-in password does not stay in the conversation history.
     */
    private function sendSecret(TelegramBotService $telegram, int|string $chatId, string $text, ?array $markup = null): void
    {
        $minutes = 10;
        $sent = $telegram->sendText($chatId, $text."\n\n".'🕒 <i>For your safety this message is deleted in '.$minutes.' minutes. Save the password first.</i>', [], $markup);

        $messageId = (int) ($sent['message_id'] ?? 0);
        if ($messageId > 0 && \Illuminate\Support\Facades\Schema::hasTable('telegram_secret_messages')) {
            \Illuminate\Support\Facades\DB::table('telegram_secret_messages')->insert([
                'chat_id' => (int) $chatId,
                'message_id' => $messageId,
                'delete_after' => now()->addMinutes($minutes),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function isAction(string $text, array $actions): bool
    {
        return in_array($text, $actions, true);
    }

    private function isDepositAmount(string $text): bool
    {
        return preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', str_replace(',', '', trim($text))) === 1;
    }

    /**
     * Withdrawable cash and play-only bonus, when there is any bonus. A single total
     * hides that part of it cannot be withdrawn.
     */
    private function balanceBreakdown(\App\Models\User $user): string
    {
        $locked = max(0, round((float) $user->credits - (float) $user->withdrawable_balance, 2));
        if ($locked <= 0) {
            return '';
        }

        return "
✅ Withdrawable: ".$this->birr($user->withdrawable_balance)."
🎁 Bonus (play only): ".$this->birr($locked);
    }

    private function birr(int|float|string $amount): string
    {
        return number_format((float) $amount, 2).' ETB';
    }
}
