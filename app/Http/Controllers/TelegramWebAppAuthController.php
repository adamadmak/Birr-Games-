<?php

namespace App\Http\Controllers;

use App\Exceptions\TelegramPhoneRequiredException;
use App\Models\TelegramSubscriber;
use App\Services\TelegramBotService;
use App\Services\TelegramPlayerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramWebAppAuthController
{
    public function __invoke(
        Request $request,
        TelegramBotService $telegram,
        TelegramPlayerService $players,
    ): JsonResponse {
        $initData = (string) $request->input('init_data');
        if ($initData === '') {
            return response()->json(['ok' => false, 'error' => 'No init_data provided']);
        }

        $tgUser = $this->verifyInitData($initData, $telegram->signingToken());
        if (! $tgUser || ! isset($tgUser['id'])) {
            return response()->json(['ok' => false, 'error' => 'Invalid Telegram user payload']);
        }

        $subscriber = TelegramSubscriber::query()
            ->where('telegram_user_id', (int) $tgUser['id'])
            ->first();

        // Accounts are only ever opened from the bot chat, where Telegram verifies the
        // phone number. Without that verified contact there is no way to tell whether
        // this person already has a member account, so we never register them here.
        if (! $subscriber || ! $subscriber->hasSharedPhone()) {
            return response()->json([
                'ok' => false,
                'error' => ($telegram->botUsername() !== '' ? 'Open @'.$telegram->botUsername() : 'Open our bot').' and tap "Share My Phone Number" to activate your account.',
                'phone_required' => true,
            ]);
        }

        $subscriber->forceFill([
            'username' => $tgUser['username'] ?? $subscriber->username,
            'first_name' => $tgUser['first_name'] ?? $subscriber->first_name,
            'last_name' => $tgUser['last_name'] ?? $subscriber->last_name,
            'language_code' => $tgUser['language_code'] ?? $subscriber->language_code,
            'last_seen_at' => now(),
        ])->save();

        try {
            $user = $players->ensurePlayer($subscriber);
        } catch (TelegramPhoneRequiredException $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->getMessage(),
                'phone_required' => true,
            ]);
        }

        if (! Auth::check() || Auth::id() !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();

            return response()->json(['ok' => true, 'redirect' => true]);
        }

        return response()->json(['ok' => true, 'redirect' => false]);
    }

    /**
     * Telegram's own check for a mini-app payload, and nothing less.
     *
     * The user object inside init_data is only trustworthy when the hash over
     * the rest of the payload matches an HMAC keyed with the bot token, and
     * the payload is recent. Until 28 Oct 2026 this method handed the user
     * back even when the hash did not match (and skipped the check entirely
     * when no token resolved), which let anyone sign in as any linked player
     * by posting a made-up hash and a victim's Telegram id. Every failure
     * path now answers null, and no token means no sign-in.
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    private const MAX_AGE_SECONDS = 86400;

    private function verifyInitData(string $initData, string $botToken): ?array
    {
        if ($botToken === '') {
            report(new \RuntimeException('Telegram web-app sign-in refused: no bot token is configured to verify the payload.'));

            return null;
        }

        parse_str($initData, $data);
        if (! isset($data['hash']) || ! is_string($data['hash']) || $data['hash'] === '') {
            return null;
        }

        $hash = strtolower($data['hash']);
        unset($data['hash']);

        $authDate = (int) ($data['auth_date'] ?? 0);
        if ($authDate <= 0 || abs(time() - $authDate) > self::MAX_AGE_SECONDS) {
            return null;
        }

        ksort($data);
        $checkString = collect($data)
            ->map(fn ($val, $key) => "{$key}={$val}")
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $checkString, $secretKey);
        if (! hash_equals($calculatedHash, $hash)) {
            return null;
        }

        $user = isset($data['user']) ? json_decode((string) $data['user'], true) : null;

        return is_array($user) ? $user : null;
    }
}
