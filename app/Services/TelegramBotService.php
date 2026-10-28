<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\TelegramBroadcast;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class TelegramBotService
{
    private const STORED_TOKEN_KEY = 'telegram_bot_token_encrypted';
    private const STORED_USERNAME_KEY = 'telegram_bot_username';

    /*
     * What each value must look like before it is used, wherever it came from.
     *
     * On 28 Oct 2026 the host's environment for this app held the bot TOKEN
     * under TELEGRAM_BOT_USERNAME, "3000" under TELEGRAM_BOT_TOKEN, and a
     * twelve-letter word under TELEGRAM_WEBHOOK_SECRET. None of them should
     * reach the app on that host, but the code read all three as a fallback
     * and trusted whatever it found: a token would have been printed in public
     * t.me links, and a short guessable word would have been accepted as the
     * webhook's secret, which lets anyone post updates as the bot's users.
     * A value that does not have the right shape is now ignored.
     */
    /** Telegram usernames: 5 to 32 letters, digits or underscores, starting with a letter. */
    public const USERNAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{4,31}$/';

    /** A BotFather token: the bot id, a colon, and the secret part. */
    public const TOKEN_PATTERN = '/^\d{6,}:[A-Za-z0-9_-]{20,}$/';

    /** What Telegram accepts as a webhook secret_token, and so all it can ever send back. */
    public const WEBHOOK_SECRET_PATTERN = '/^[A-Za-z0-9_-]{16,256}$/';

    public function configured(): bool
    {
        return $this->botToken() !== '';
    }

    public function configurationSources(): array
    {
        return [
            'config' => self::isToken((string) config('services.telegram.bot_token')),
            'server' => self::isToken((string) ($_SERVER['TELEGRAM_BOT_TOKEN'] ?? '')),
            'environment' => self::isToken((string) ($_ENV['TELEGRAM_BOT_TOKEN'] ?? '')),
            'process' => self::isToken((string) (getenv('TELEGRAM_BOT_TOKEN') ?: '')),
            'database' => trim((string) PlatformSetting::valueFor(self::STORED_TOKEN_KEY, '')) !== '',
        ];
    }

    /**
     * The bot's public @username, or '' when none is known. Callers show a
     * t.me link only when this is not empty. The one saved when the admin
     * entered the token (Telegram's own answer to getMe) comes first; there is
     * no default, because the old one was another brand's bot.
     */
    public function botUsername(): string
    {
        foreach ([
            PlatformSetting::valueFor(self::STORED_USERNAME_KEY, ''),
            config('services.telegram.bot_username'),
            $this->runtimeEnvironment('TELEGRAM_BOT_USERNAME'),
        ] as $candidate) {
            $candidate = ltrim(trim((string) $candidate), '@');
            if (preg_match(self::USERNAME_PATTERN, $candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public static function isToken(string $value): bool
    {
        return (bool) preg_match(self::TOKEN_PATTERN, trim($value));
    }

    public function configureToken(string $token): array
    {
        $token = trim($token);
        if (!preg_match('/^\d{6,}:[A-Za-z0-9_-]{20,}$/', $token)) {
            throw new RuntimeException('Enter a valid Telegram bot token from BotFather.');
        }

        try {
            // This runs inside the admin's save click, not a background job — they
            // are watching a spinner. It used to share the 20s config timeout with
            // no retry limit downstream in setWebhook(), so a slow Telegram could
            // hold the request past the platform gateway's own timeout, which then
            // answered with a bare 503 instead of this class's clear error message.
            // Ten seconds is generous for an API that normally answers in tens of
            // milliseconds, and failing fast means the admin can just try again.
            $response = $this->http()
                ->asJson()
                ->timeout(10)
                ->post('https://api.telegram.org/bot'.$token.'/getMe');
        } catch (ConnectionException) {
            throw new RuntimeException('Telegram could not be reached to verify the bot token. Try again shortly.');
        }
        $bot = $this->validated($response)->json('result', []);

        PlatformSetting::query()->updateOrCreate(
            ['key' => self::STORED_TOKEN_KEY],
            ['value' => Crypt::encryptString($token)]
        );
        if (filled($bot['username'] ?? null)) {
            PlatformSetting::query()->updateOrCreate(
                ['key' => self::STORED_USERNAME_KEY],
                ['value' => ltrim((string) $bot['username'], '@')]
            );
        }
        PlatformSetting::flushValueCache();

        return $bot;
    }

    public function getMe(): array
    {
        return $this->post('getMe')->json('result', []);
    }

    public function getWebhookInfo(): array
    {
        return $this->post('getWebhookInfo')->json('result', []);
    }

    public function getUpdates(int $offset = 0, int $timeout = 0): array
    {
        return $this->post('getUpdates', array_filter([
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message'],
        ], fn ($value) => $value !== 0))->json('result', []);
    }

    public function setWebhook(string $url): array
    {
        $secret = $this->webhookSecret();

        if (!preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            throw new RuntimeException('Configure TELEGRAM_WEBHOOK_SECRET with 16-256 letters, numbers, underscores, or hyphens.');
        }

        // No retry: this is the second of two sequential Telegram calls inside
        // the same admin click that verifies the token, and setWebhook is safe to
        // resubmit — Telegram just overwrites the same registration. Retrying here
        // was what pushed the worst case past the platform gateway's own timeout.
        return $this->post('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
        ], retry: false)->json();
    }

    public function webhookSecret(): string
    {
        return $this->configuredWebhookSecret() ?: $this->derivedWebhookSecret();
    }

    /**
     * An operator-chosen secret, if one is set AND has the shape Telegram
     * accepts. A shorter one could never have been registered with Telegram,
     * so honouring it would only widen what the endpoint lets in.
     */
    private function configuredWebhookSecret(): string
    {
        foreach ([config('services.telegram.webhook_secret'), $this->runtimeEnvironment('TELEGRAM_WEBHOOK_SECRET')] as $candidate) {
            $candidate = trim((string) $candidate);
            if (preg_match(self::WEBHOOK_SECRET_PATTERN, $candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    public function validWebhookSecret(string $provided): bool
    {
        if ($provided === '') {
            return false;
        }

        if (! preg_match(self::WEBHOOK_SECRET_PATTERN, $provided)) {
            return false;
        }

        $configured = $this->configuredWebhookSecret();
        $token = $this->botToken();
        $candidates = array_filter([
            $configured,
            $token !== '' ? hash('sha256', 'lucy-telegram-webhook|'.$token) : null,
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (hash_equals($candidate, $provided)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A notice sent inside somebody else's request (a player submitting a
     * deposit): one attempt, five seconds at most, so a slow Telegram can
     * never hold the page. Callers catch and log; the portal is the record.
     */
    public function notify(int|string $chatId, string $text, array $buttons = []): void
    {
        $this->post('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
            'reply_markup' => $this->keyboard($buttons),
        ], fn ($value) => $value !== null), retry: false, timeout: 5);
    }

    public function sendText(int|string $chatId, string $text, array $buttons = [], ?array $replyMarkup = null): array
    {
        return $this->post('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
            'reply_markup' => $replyMarkup ?? $this->keyboard($buttons),
        ], fn ($value) => $value !== null))->json('result', []);
    }

    public function sendPhoto(int|string $chatId, string $photoPath, string $caption = '', array $buttons = [], ?array $replyMarkup = null): array
    {
        if (! file_exists($photoPath) || ! is_readable($photoPath)) {
            return $this->sendText($chatId, $caption, $buttons, $replyMarkup);
        }

        $stream = fopen($photoPath, 'rb');
        if ($stream === false) {
            return $this->sendText($chatId, $caption, $buttons, $replyMarkup);
        }

        try {
            $payload = array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => $replyMarkup ? json_encode($replyMarkup) : ($buttons !== [] ? json_encode($this->keyboard($buttons)) : null),
            ], fn ($value) => $value !== null);

            $response = $this->http()
                ->timeout((int) config('services.telegram.timeout', 20))
                ->attach('photo', $stream, basename($photoPath))
                ->post($this->endpoint('sendPhoto'), $payload);

            return $this->validated($response)->json('result', []);
        } catch (\Throwable) {
            return $this->sendText($chatId, $caption, $buttons, $replyMarkup);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function setChatMenuButton(int|string|null $chatId, string $webAppUrl, string $text = '🎮 Play Games'): array
    {
        $payload = [
            'menu_button' => [
                'type' => 'web_app',
                'text' => $text,
                'web_app' => ['url' => $webAppUrl],
            ],
        ];
        if ($chatId !== null) {
            $payload['chat_id'] = $chatId;
        }

        return $this->post('setChatMenuButton', $payload)->json();
    }

    public function mainMenuKeyboard(): array
    {
        return [
            'keyboard' => [
                [['text' => '🎮 Play Games'], ['text' => '💰 Deposit']],
                [['text' => '💵 Check Balance']],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'input_field_placeholder' => 'Choose an action',
        ];
    }

    public function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }

    /**
     * Mandatory registration step: Telegram only fills `message.contact` when the
     * player taps this button, so the phone number is verified by Telegram itself.
     */
    public function contactKeyboard(string $label = '📱 Share My Phone Number'): array
    {
        return [
            'keyboard' => [[['text' => $label, 'request_contact' => true]]],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' => 'Tap the button to share your phone number',
        ];
    }

    public function cancelKeyboard(string $placeholder = 'Enter deposit amount'): array
    {
        return [
            'keyboard' => [[['text' => '❌ Cancel']]],
            'resize_keyboard' => true,
            'is_persistent' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' => $placeholder,
        ];
    }

    /** @param array<int, string> $choices */
    public function choiceKeyboard(array $choices, string $placeholder = 'Choose an option'): array
    {
        $rows = array_map(fn (string $choice) => [['text' => $choice]], $choices);
        $rows[] = [['text' => '❌ Cancel']];

        return [
            'keyboard' => $rows,
            'resize_keyboard' => true,
            'is_persistent' => true,
            'one_time_keyboard' => false,
            'input_field_placeholder' => $placeholder,
        ];
    }

    public function sendBroadcast(TelegramBroadcast $broadcast, int|string $chatId): array
    {
        if (!$broadcast->image_path || !Storage::disk('public')->exists($broadcast->image_path)) {
            return $this->sendText($chatId, $broadcast->caption, $broadcast->buttons ?? []);
        }

        $path = Storage::disk('public')->path($broadcast->image_path);
        $isAnimation = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'gif';
        $method = $isAnimation ? 'sendAnimation' : 'sendPhoto';
        $field = $isAnimation ? 'animation' : 'photo';
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The broadcast image could not be opened.');
        }

        try {
            $payload = array_filter([
                'chat_id' => $chatId,
                'caption' => $broadcast->caption,
                'parse_mode' => 'HTML',
                'reply_markup' => ($keyboard = $this->keyboard($broadcast->buttons ?? [])) ? json_encode($keyboard) : null,
            ], fn ($value) => $value !== null);

            $response = $this->http()->timeout((int) config('services.telegram.timeout', 20))
                ->attach($field, $stream, basename($path))
                ->post($this->endpoint($method), $payload);

            return $this->validated($response)->json('result', []);
        } finally {
            fclose($stream);
        }
    }

    public function editBroadcastMessage(TelegramBroadcast $broadcast, int|string $chatId, int $messageId): array
    {
        $keyboard = $this->keyboard($broadcast->buttons ?? []);
        $hasImage = $broadcast->image_path && Storage::disk('public')->exists($broadcast->image_path);

        $payload = array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard,
        ], fn ($value) => $value !== null);

        if ($hasImage) {
            $payload['caption'] = $broadcast->caption;
            try {
                return $this->post('editMessageCaption', $payload)->json('result', []);
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                if (str_contains($msg, 'message is not modified')) {
                    return [];
                }
                unset($payload['caption']);
                $payload['text'] = $broadcast->caption;
                return $this->post('editMessageText', $payload)->json('result', []);
            }
        }

        $payload['text'] = $broadcast->caption;
        try {
            return $this->post('editMessageText', $payload)->json('result', []);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'message is not modified')) {
                return [];
            }
            unset($payload['text']);
            $payload['caption'] = $broadcast->caption;
            return $this->post('editMessageCaption', $payload)->json('result', []);
        }
    }

    public function deleteMessage(int|string $chatId, int $messageId): bool
    {
        try {
            $response = $this->post('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
            return (bool) $response->json('ok', false);
        } catch (\Throwable $e) {
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'message to delete not found') || str_contains($msg, 'message can\'t be deleted') || str_contains($msg, 'chat not found')) {
                return true;
            }
            throw $e;
        }
    }

    private function post(string $method, array $payload = [], bool $retry = true, ?int $timeout = null): Response
    {
        try {
            $request = $this->http()->asJson()->timeout($timeout ?? (int) config('services.telegram.timeout', 20));
            if ($retry) {
                $request = $request->retry(2, 300, throw: false);
            }
            $response = $request->post($this->endpoint($method), $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Telegram API could not be reached. Try again shortly.');
        }

        return $this->validated($response);
    }

    private function validated(Response $response): Response
    {
        if (!$response->successful() || !$response->json('ok', false)) {
            $description = (string) $response->json('description', 'Telegram request failed.');
            throw new RuntimeException(str($description)->limit(300)->toString());
        }

        return $response;
    }

    private function endpoint(string $method): string
    {
        $token = $this->botToken();

        if ($token === '') {
            throw new RuntimeException('The Telegram bot token is not configured.');
        }

        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }

    private function derivedWebhookSecret(): string
    {
        $token = $this->botToken();

        if ($token === '') {
            throw new RuntimeException('The Telegram bot token is not configured.');
        }

        return hash('sha256', 'lucy-telegram-webhook|'.$token);
    }

    /** The token the web-app sign-in verifies Telegram's signature with. Resolved like every other call's. */
    public function signingToken(): string
    {
        return $this->botToken();
    }

    /**
     * Config, then the token saved from the admin, then the environment: the
     * same order as App\Support\RuntimeEnv, so the admin field wins over a
     * stray environment variable. Anything that is not shaped like a token is
     * skipped rather than sent to Telegram (or used to verify a sign-in).
     */
    private function botToken(): string
    {
        foreach ([
            fn () => (string) config('services.telegram.bot_token'),
            fn () => $this->storedToken(),
            fn () => $this->runtimeEnvironment('TELEGRAM_BOT_TOKEN'),
        ] as $source) {
            $candidate = trim($source());
            if (self::isToken($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private function storedToken(): string
    {
        $encrypted = trim((string) PlatformSetting::valueFor(self::STORED_TOKEN_KEY, ''));
        if ($encrypted === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($encrypted));
        } catch (\Throwable) {
            return '';
        }
    }

    private function runtimeEnvironment(string $key): string
    {
        foreach ([
            $_SERVER[$key] ?? null,
            $_ENV[$key] ?? null,
            getenv($key),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        $caBundle = trim((string) config('services.telegram.ca_bundle'));

        return Http::withOptions(['verify' => $caBundle !== '' ? $caBundle : true])
            ->connectTimeout(5);
    }

    private function keyboard(array $buttons): ?array
    {
        $rows = collect($buttons)
            ->filter(fn ($button) => filled($button['text'] ?? null) && filled($button['value'] ?? null))
            ->map(function (array $button): array {
                $item = ['text' => $button['text']];
                $item[$button['type'] === 'web_app' ? 'web_app' : 'url'] = $button['type'] === 'web_app'
                    ? ['url' => $button['value']]
                    : $button['value'];

                return [$item];
            })->values()->all();

        return $rows === [] ? null : ['inline_keyboard' => $rows];
    }
}
