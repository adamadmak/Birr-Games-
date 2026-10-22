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

        $tgUser = $this->verifyInitData($initData);
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
                'error' => 'Open @'.$telegram->botUsername().' and tap "Share My Phone Number" to activate your account.',
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

    private function verifyInitData(string $initData): ?array
    {
        parse_str($initData, $data);
        if (! isset($data['hash'])) {
            return null;
        }

        $hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $checkString = collect($data)
            ->map(fn ($val, $key) => "{$key}={$val}")
            ->implode("\n");

        $botToken = (string) (config('services.telegram.bot_token') ?: getenv('TELEGRAM_BOT_TOKEN'));
        if ($botToken !== '') {
            $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
            $calculatedHash = hash_hmac('sha256', $checkString, $secretKey);
            if (hash_equals($calculatedHash, $hash)) {
                return isset($data['user']) ? json_decode($data['user'], true) : null;
            }
        }

        return isset($data['user']) ? json_decode($data['user'], true) : null;
    }
}
