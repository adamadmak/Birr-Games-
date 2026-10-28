<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\TelegramLinkCode;
use App\Models\TelegramSubscriber;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;

/**
 * Connects a website account to a Telegram chat, with proof of both ends.
 *
 * The player signs up on the website and is shown a one-time code. Opening the
 * bot with it proves they control the website account; sharing their contact
 * card from the same Telegram proves the phone number on the account is theirs,
 * because Telegram only fills that card in from the sender's own number. Only
 * when both agree is the chat linked and the account marked as confirmed.
 *
 * What it refuses, and why:
 *   - an expired, used, unknown or guessed code (short life, single use, a few
 *     tries per code and per Telegram user, only a hash stored);
 *   - a Telegram chat already linked to a different account, so one person
 *     cannot end up with two wallets or take over another player's chat;
 *   - a contact card whose number is not the account's number, so the code on
 *     its own can never confirm a number the player does not hold.
 */
class TelegramAccountLinker
{
    public const START_PREFIX = 'link_';
    public const CODE_MINUTES = 15;
    private const CODES_PER_HOUR = 6;
    private const CODE_LENGTH = 8;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly TelegramBotService $bot)
    {
    }

    public static function ready(): bool
    {
        try {
            return Schema::hasTable('telegram_link_codes');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether there is a bot to connect to at all. */
    public function available(): bool
    {
        return self::ready() && $this->bot->configured() && $this->bot->botUsername() !== '';
    }

    /* ------------------------------------------------------------------ */
    /* Website side                                                        */
    /* ------------------------------------------------------------------ */

    public function subscriberFor(User $user): ?TelegramSubscriber
    {
        return TelegramSubscriber::query()->where('user_id', $user->id)->latest('id')->first();
    }

    public function isLinked(User $user): bool
    {
        return $this->subscriberFor($user) !== null;
    }

    /**
     * Mint a code for a signed-in player. Earlier unused codes stop working, so
     * only the newest link on screen is valid. Returns null when the player has
     * asked for too many in the last hour.
     *
     * @return array{code: string, url: string, expires_at: \Illuminate\Support\Carbon}|null
     */
    public function issue(User $user): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $key = 'tg-link-issue:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::CODES_PER_HOUR)) {
            return null;
        }
        RateLimiter::hit($key, 3600);

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return DB::transaction(function () use ($user, $code): array {
            TelegramLinkCode::query()->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
            $row = TelegramLinkCode::create([
                'user_id' => $user->id,
                'code_hash' => $this->hash($code),
                'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            ]);

            return [
                'code' => $code,
                'url' => 'https://t.me/'.$this->bot->botUsername().'?start='.self::START_PREFIX.$code,
                'expires_at' => $row->expires_at,
            ];
        });
    }

    /** Detach the player's Telegram. The wallet and history are untouched. */
    public function unlink(User $user): bool
    {
        $subscribers = TelegramSubscriber::query()->where('user_id', $user->id)->get();
        foreach ($subscribers as $subscriber) {
            $subscriber->forceFill(['user_id' => null, 'pending_action' => null, 'pending_payload' => null])->save();
            \App\Models\TelegramLoginToken::query()->where('telegram_subscriber_id', $subscriber->id)->where('expires_at', '>', now())->update(['used_at' => now(), 'expires_at' => now()]);
        }

        if ($subscribers->isNotEmpty()) {
            SecurityEvent::record('telegram', SecurityEvent::OUTCOME_ALLOWED, 'unlinked', request()?->ip(), request()?->userAgent(), (string) $user->id, $user->id);
        }

        return $subscribers->isNotEmpty();
    }

    /* ------------------------------------------------------------------ */
    /* Bot side                                                            */
    /* ------------------------------------------------------------------ */

    /** The code in `/start link_ABCD1234`, or null when the message is not a link request. */
    public function codeFromStart(string $text): ?string
    {
        if (! preg_match('/^\/start(?:@\w+)?\s+'.self::START_PREFIX.'([A-Za-z0-9]{6,16})\s*$/', trim($text), $m)) {
            return null;
        }

        return strtoupper($m[1]);
    }

    /**
     * Step one, in the bot: check the code and park the chat until the contact
     * card arrives. Returns the account being connected, or a reason to show.
     *
     * @return array{user: ?User, error: ?string, needs_contact: bool}
     */
    public function begin(TelegramSubscriber $subscriber, string $code): array
    {
        $fail = fn (string $reason, string $message): array => $this->refuse($subscriber, $reason, $message);

        $chatKey = 'tg-link-try:'.$subscriber->telegram_user_id;
        if (RateLimiter::tooManyAttempts($chatKey, 8)) {
            return $fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again from the website.');
        }
        RateLimiter::hit($chatKey, 600);

        $record = TelegramLinkCode::query()->where('code_hash', $this->hash($code))->first();
        if (! $record || ! $record->isUsable()) {
            return $fail($record ? 'code_expired' : 'code_unknown', 'That link has expired or was already used. Open the website, go to Connect Telegram, and tap the new link.');
        }

        $user = User::query()->find($record->user_id);
        if (! $user || ! $user->active || $user->role !== User::ROLE_PLAYER) {
            return $fail('account_unavailable', 'That account cannot be connected. Please contact support.');
        }

        if ($subscriber->user_id && $subscriber->user_id !== $user->id) {
            return $fail('chat_linked_elsewhere', 'This Telegram account is already connected to a different player account. Send /start to continue with it, or contact support.');
        }

        $subscriber->forceFill([
            'active' => true,
            'pending_action' => 'link_phone',
            'pending_payload' => ['link_code_id' => $record->id],
        ])->save();

        // A chat that has already shared its number can be checked straight away.
        return ['user' => $user, 'error' => null, 'needs_contact' => ! $subscriber->hasSharedPhone()];
    }

    /**
     * Step two: the contact card is in. Confirm the numbers agree, then link.
     *
     * @return array{user: ?User, error: ?string}
     */
    public function complete(TelegramSubscriber $subscriber): array
    {
        $codeId = (int) data_get($subscriber->pending_payload, 'link_code_id');
        $record = $codeId ? TelegramLinkCode::query()->find($codeId) : null;

        if (! $record || ! $record->isUsable()) {
            return $this->clear($subscriber, 'code_expired', 'That link has expired. Open the website, go to Connect Telegram, and tap the new link.');
        }

        // Counted per code: a few wrong contact cards and the code is dead.
        if ($record->attempts >= 3) {
            $record->forceFill(['used_at' => now()])->save();

            return $this->clear($subscriber, 'too_many_attempts', 'Too many attempts. Please create a new link on the website.');
        }

        $user = User::query()->find($record->user_id);
        if (! $user || ! $user->active || $user->role !== User::ROLE_PLAYER) {
            return $this->clear($subscriber, 'account_unavailable', 'That account cannot be connected. Please contact support.');
        }

        $shared = PhoneNumber::normalize($subscriber->phone);
        if ($shared === null) {
            return ['user' => null, 'error' => 'That phone number could not be read. Tap the button to share your contact again.'];
        }

        // The number on the account must be the number Telegram verified.
        if (filled($user->phone) && ! in_array($shared, array_map([PhoneNumber::class, 'normalize'], PhoneNumber::candidates($user->phone)), true)) {
            $record->increment('attempts');
            SecurityEvent::record('telegram', SecurityEvent::OUTCOME_BLOCKED, 'phone_mismatch', null, null, (string) $subscriber->telegram_user_id, $user->id);

            return ['user' => null, 'error' => 'The phone number on your Telegram does not match the number on your account ('
                .PhoneNumber::format($user->phone).'). Share the contact for that number, or update the number on your account.'];
        }

        // An account with no number yet takes the verified one, unless it belongs to someone else.
        if (blank($user->phone)) {
            $taken = User::withTrashed()->whereIn('phone', PhoneNumber::candidates($shared))->whereKeyNot($user->id)->exists();
            if ($taken) {
                return $this->clear($subscriber, 'phone_in_use', 'That phone number already belongs to another account. Please contact support.');
            }
        }

        if ($subscriber->user_id && $subscriber->user_id !== $user->id) {
            return $this->clear($subscriber, 'chat_linked_elsewhere', 'This Telegram account is already connected to a different player account.');
        }

        DB::transaction(function () use ($subscriber, $user, $record, $shared): void {
            // One Telegram per account: a previous chat is released, not duplicated.
            TelegramSubscriber::query()->where('user_id', $user->id)->whereKeyNot($subscriber->id)
                ->update(['user_id' => null, 'pending_action' => null, 'pending_payload' => null]);

            $subscriber->forceFill([
                'user_id' => $user->id,
                'active' => true,
                'pending_action' => null,
                'pending_payload' => null,
                'started_at' => $subscriber->started_at ?? now(),
                'stopped_at' => null,
            ])->save();

            $updates = ['contact_confirmed' => true];
            if (blank($user->phone)) {
                $updates['phone'] = $shared;
            }
            $user->forceFill($updates)->save();

            $record->forceFill(['used_at' => now()])->save();
        });

        SecurityEvent::record('telegram', SecurityEvent::OUTCOME_ALLOWED, 'linked', null, null, (string) $subscriber->telegram_user_id, $user->id);
        Log::info('A website account was connected to Telegram.', ['user_id' => $user->id, 'telegram_subscriber_id' => $subscriber->id]);

        return ['user' => $user, 'error' => null];
    }

    /* ------------------------------------------------------------------ */

    private function refuse(TelegramSubscriber $subscriber, string $reason, string $message): array
    {
        SecurityEvent::record('telegram', SecurityEvent::OUTCOME_BLOCKED, $reason, null, null, (string) $subscriber->telegram_user_id);

        return ['user' => null, 'error' => $message, 'needs_contact' => false];
    }

    private function clear(TelegramSubscriber $subscriber, string $reason, string $message): array
    {
        $subscriber->forceFill(['pending_action' => null, 'pending_payload' => null])->save();
        SecurityEvent::record('telegram', SecurityEvent::OUTCOME_BLOCKED, $reason, null, null, (string) $subscriber->telegram_user_id);

        return ['user' => null, 'error' => $message];
    }

    private function hash(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }
}
