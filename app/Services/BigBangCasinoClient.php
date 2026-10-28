<?php

namespace App\Services;

use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BigBangCasinoClient
{
    public function configured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function sandbox(): bool
    {
        return config('services.bigbang.mode', 'sandbox') === 'sandbox';
    }

    public function catalogPage(int $page = 1, int $limit = 200): array
    {
        $limit = min(500, max(1, $limit));
        $provider = trim((string) config('services.bigbang.provider'));

        return $this->json(
            $this->request(retrySafe: true)->get('/games', [
                'type' => $this->sandbox() ? 'standard' : null,
                'provider' => $provider !== '' ? $provider : null,
                'offset' => (max(1, $page) - 1) * $limit,
                'limit' => $limit,
            ]),
            'GET /games'
        );
    }

    public function launch(Game $game, User $user, string $walletUrl, string $returnUrl): array
    {
        $gameId = $game->provider_game_id ?: $game->external_launch_code;
        if (!$gameId || !ctype_digit((string) $gameId)) {
            throw new RuntimeException('This game has no current BigBang numeric game ID.');
        }

        if (!$this->sandbox()) {
            $this->provisionPlayerIfNeeded($user);
        }

        $payload = $this->json($this->request()->post('/games/launch', [
            'game_id' => (int) $gameId,
            'user_token' => $this->sandbox() ? 'demo' : $this->userToken($user),
            'demo' => $this->sandbox(),
            'language' => 'en',
            'return_url' => $returnUrl,
            'home_url' => $returnUrl,
        ]), 'POST /games/launch');

        $launchUrl = trim((string) ($payload['game_url'] ?? $payload['launch_url'] ?? ''));
        if ($launchUrl === '') {
            throw new RuntimeException('BigBang did not return a launch URL.');
        }

        return $payload + [
            'launch_url' => $launchUrl,
            'mode' => $this->sandbox() ? 'fun' : 'real',
            'currency' => strtoupper((string) config('services.bigbang.currency', 'EUR')),
        ];
    }

    public function provisionPlayerIfNeeded(User $user): void
    {
        if ($user->bigbang_provisioned_at && $user->bigbang_provisioned_at->isAfter(now()->subHours(24))) {
            return;
        }

        $this->provisionPlayer($user);
        $user->update(['bigbang_provisioned_at' => now()]);
    }

    public function provisionPlayer(User $user): array
    {
        $this->json($this->request()->post('/users/create', [
            'user_token' => $this->userToken($user),
            'username' => (string) ($user->name ?: $this->userToken($user)),
            'country' => 'ET',
        ]), 'POST /users/create');

        $payload = $this->json(
            $this->request(retrySafe: true)->get('/users/'.rawurlencode($this->userToken($user))),
            'GET /users/:user_token'
        );
        $providerCurrency = strtoupper((string) data_get($payload, 'data.currency', ''));
        $configuredCurrency = strtoupper((string) config('services.bigbang.currency', ''));
        if ($providerCurrency === '') {
            throw new RuntimeException('BigBang did not report the provisioned player currency.');
        }
        if ($configuredCurrency !== '' && $providerCurrency !== $configuredCurrency) {
            throw new RuntimeException(sprintf(
                'BigBang provisioned the player in %s, but this application requires %s. Ask BigBang to enable the required account currency.',
                $providerCurrency,
                $configuredCurrency
            ));
        }

        return $payload;
    }

    public function balanceChangeSignatureIsValid(array $payload): bool
    {
        $provided = strtolower(trim((string) ($payload['signature'] ?? '')));
        if (strlen($provided) !== 64 || !$this->configured()) {
            return false;
        }

        $base = (string) ($payload['username'] ?? '')
            .(string) ($payload['amount'] ?? '')
            .(string) ($payload['game'] ?? '')
            .(string) ($payload['game_category'] ?? '')
            .(string) ($payload['transaction_id'] ?? '');

        return hash_equals(hash_hmac('sha256', $base, $this->apiKey()), $provided);
    }

    public function userToken(User $user): string
    {
        return 'player_'.$user->id;
    }

    /**
     * Can this server reach BigBang with the key it holds?
     *
     * One catalogue page, because that is the cheapest authenticated call. It
     * also reports the address BigBang see this server from: on 27 Oct 2026
     * every call from the vamosonebet.com host timed out while connecting,
     * while the same key answered at once from elsewhere, which is what an
     * address missing from BigBang's allow-list looks like. The address is the
     * one thing the operator needs to send them.
     *
     * @return array{ok: bool, message: string, egress_ip: string|null}
     */
    public function probe(): array
    {
        $egress = null;
        try {
            $caBundle = trim((string) config('services.bigbang.ca_bundle'));
            $seen = Http::withOptions(['verify' => $caBundle !== '' ? $caBundle : true])->connectTimeout(4)->timeout(6)->get('https://api.ipify.org', ['format' => 'json']);
            $egress = $seen->successful() ? (trim((string) ($seen->json('ip') ?? '')) ?: null) : null;
        } catch (\Throwable) {
            // Cosmetic. The call below is the real answer.
        }

        if (! $this->configured()) {
            return ['ok' => false, 'message' => 'No BigBang API key is set on this server.', 'egress_ip' => $egress];
        }

        try {
            $page = $this->catalogPage(1, 1);
        } catch (\Illuminate\Http\Client\ConnectionException $exception) {
            return [
                'ok' => false,
                'message' => 'BigBang did not answer from this server ('.self::shortCurl($exception->getMessage()).'). The key was never checked: the connection itself was dropped, which is what BigBang\'s IP allow-list does to an address it does not know.',
                'egress_ip' => $egress,
            ];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage(), 'egress_ip' => $egress];
        }

        $total = (int) (data_get($page, 'pagination.total') ?? count($page['data'] ?? []));

        return ['ok' => true, 'message' => 'BigBang accepted the key at '.rtrim((string) config('services.bigbang.base_url'), '/').' ('.number_format($total).' game(s) in the catalogue, '.($this->sandbox() ? 'sandbox' : 'live').' mode).', 'egress_ip' => $egress];
    }

    /** "cURL error 28: Connection timed out after 5002 milliseconds (see https://…) for https://…" → "connection timed out". */
    public static function shortCurl(string $message): string
    {
        if (preg_match('/cURL error (\d+): ([^(]+?)(?: \(see |$| for http)/i', $message, $m)) {
            return strtolower(trim($m[2])).' (cURL '.$m[1].')';
        }

        return mb_substr($message, 0, 160);
    }

    private function request(bool $retrySafe = false): PendingRequest
    {
        if (!$this->configured()) {
            throw new RuntimeException('BigBang is not configured. Add a current API key to the server environment.');
        }

        $caBundle = trim((string) config('services.bigbang.ca_bundle'));
        $request = Http::baseUrl(rtrim((string) config('services.bigbang.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-API-Key' => $this->apiKey()])
            ->withOptions(['verify' => $caBundle !== '' ? $caBundle : true])
            // The read timeout below only starts counting once a connection
            // exists. Without a connect bound, a blackholed provider edge held
            // the request for the full read timeout per attempt.
            ->connectTimeout(5)
            ->timeout((int) config('services.bigbang.timeout', 20));

        // A full catalog read is ~26 requests and the edge occasionally answers a
        // single page with a 403. Two retries at 250ms was not enough to ride that
        // out, and a page lost mid-run aborts the whole sync. But the timeout
        // above applies PER ATTEMPT: four attempts at twenty seconds held one of
        // the few serve workers for ~82 seconds per page, which is how the hourly
        // sync meeting a provider brownout froze the whole site. Eight seconds an
        // attempt keeps the retries and bounds the worst page near half a minute,
        // inside the sync's own time budget.
        if ($retrySafe) {
            return $request->timeout(8)->retry(4, 500, throw: false);
        }

        // A write (player create, game launch) must not be replayed after BigBang
        // may have acted on it, but a connection that never opened reached no
        // one. From the vamosonebet.com host those drops come and go within a
        // minute (27 Oct 2026: the probe passed on its retries while every
        // launch, which had none, failed), so the connect phase alone is retried.
        return $request->retry(3, 400, fn (\Throwable $e) => $e instanceof \Illuminate\Http\Client\ConnectionException
            && self::connectPhaseFailure($e->getMessage()));
    }

    /** cURL's wording for a connection that never opened, as opposed to one that went quiet mid-request. */
    public static function connectPhaseFailure(string $message): bool
    {
        return (bool) preg_match('/Connection timed out|Failed to connect|Could not resolve host|Connection refused|Connection reset by peer|SSL connect error|Empty reply from server/i', $message);
    }

    /** See App\Support\BigBang: settings first on this host, never a literal. */
    private function apiKey(): string
    {
        return \App\Support\BigBang::apiKey();
    }

    private function json(Response $response, string $operation): array
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $payload = str_contains($contentType, 'json') ? $response->json() : null;

        if (!$response->successful()) {
            $providerMessage = is_array($payload)
                ? (data_get($payload, 'error.message') ?? data_get($payload, 'message'))
                : null;
            throw new RuntimeException(sprintf(
                'BigBang %s failed with HTTP %d%s.',
                $operation,
                $response->status(),
                filled($providerMessage) ? ': '.$providerMessage : ''
            ));
        }

        if (!str_contains($contentType, 'json')) {
            throw new RuntimeException(sprintf(
                'BigBang %s returned a non-JSON response. Confirm the /api/v1 base URL in Portal > Integration.',
                $operation
            ));
        }
        if (!is_array($payload)) {
            throw new RuntimeException("BigBang {$operation} returned an invalid JSON payload.");
        }
        if (($payload['success'] ?? true) === false) {
            throw new RuntimeException(sprintf(
                'BigBang %s failed: %s',
                $operation,
                (string) (data_get($payload, 'error.message') ?? 'Unknown provider error')
            ));
        }

        return $payload;
    }
}
