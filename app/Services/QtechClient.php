<?php

namespace App\Services;

use App\Models\Game;
use App\Models\User;
use App\Support\Qtech;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The half of the QTech integration where we call them.
 *
 * QtechWalletController answers QTech's calls (bets, wins, refunds). This
 * client makes ours: retrieve an access token, read the game list, and ask for
 * a launch URL when a player opens a game. It is the QTech counterpart of
 * BigBangCasinoClient and follows the same rules about timeouts — every call
 * here runs inside a web request on a small worker pool, so nothing may wait
 * on QTech for long.
 */
class QtechClient
{
    /**
     * Their token idles out after six hours and is refreshed on every use, so
     * five hours of caching keeps a busy site on one token and a quiet site
     * from ever presenting a dead one. A 401 refreshes it anyway.
     */
    private const TOKEN_TTL_SECONDS = 5 * 3600;

    public function configured(): bool
    {
        return Qtech::apiConfigured();
    }

    /**
     * Retrieve, or reuse, the OAuth-style access token (spec §4.1).
     *
     * The token is what every other call authenticates with; QTech ask that it
     * be reused rather than minted per call. Keyed by host and user so switching
     * from the integration environment to production cannot present a token
     * minted against the other.
     */
    public function accessToken(bool $forceRefresh = false): string
    {
        $key = $this->tokenCacheKey();

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $response = $this->attempt(fn () => $this->request()
            ->withQueryParameters([
                'grant_type' => 'password',
                'response_type' => 'token',
                'username' => Qtech::apiUsername(),
                'password' => Qtech::apiPassword(),
            ])
            ->post('/v1/auth/token'), 'POST /v1/auth/token');

        $payload = $this->json($response, 'POST /v1/auth/token');
        $token = trim((string) ($payload['access_token'] ?? ''));

        if ($token === '') {
            throw new RuntimeException('QTech issued no access token.');
        }

        Cache::put($key, $token, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    /** Drop the cached token — after a credential change, or when QTech reject it. */
    public function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey());
    }

    /**
     * One page of the game list (spec §8.1), filtered to our currency.
     *
     * The list is cursor-paginated: each page carries a `links` entry whose
     * `rel` is `next` with a RELATIVE href, and the last page carries none. The
     * caller passes that href back in to continue, and null to start.
     *
     * @return array{items: list<array>, total: int|null, next: string|null}
     */
    public function gameListPage(?string $nextHref = null): array
    {
        $response = $this->authorized(function (string $token) use ($nextHref) {
            $request = $this->request(retrySafe: true)
                ->withToken($token)
                ->withHeaders(['Time-Zone' => 'UTC', 'Accept-Language' => 'en-US']);

            // The cursor href carries its own query string. It must be sent as
            // the single argument: passing any query array — even an empty one
            // — makes Guzzle REPLACE the URL's query, which strips the cursor
            // and turns every "next" request back into the first page.
            return $this->attempt(fn () => $nextHref !== null
                ? $request->get($nextHref)
                : $request->get('/v2/games', [
                    'size' => 1000,
                    'currencies' => Qtech::currency(),
                    'includeFields' => 'id,name,provider,description,category,supportedDevices,clientTypes,demoSupport,images',
                ]), 'GET /v2/games');
        });

        $payload = $this->json($response, 'GET /v2/games');

        $items = $payload['items'] ?? [];
        $next = null;
        foreach ((array) ($payload['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? null) === 'next' && filled($link['href'] ?? null)) {
                $next = (string) $link['href'];
                break;
            }
        }

        return [
            'items' => is_array($items) && array_is_list($items) ? array_values(array_filter($items, 'is_array')) : [],
            'total' => isset($payload['totalCount']) ? (int) $payload['totalCount'] : null,
            'next' => $next,
        ];
    }

    /**
     * Ask QTech for a launch URL (spec §5). The wallet session id we hand over
     * here is the token QTech will present on every wallet call for this game
     * session — it has to exist in qtech_sessions before this is called.
     */
    public function launchUrl(Game $game, User $user, string $walletSessionId, string $device, string $returnUrl): string
    {
        $gameId = trim((string) ($game->external_launch_code ?: $game->provider_game_id));
        if ($gameId === '') {
            throw new RuntimeException('This game has no QTech identifier.');
        }

        $response = $this->authorized(fn (string $token) => $this->attempt(fn () => $this->request()
            ->withToken($token)
            ->post('/v1/games/'.rawurlencode($gameId).'/launch-url', [
                'playerId' => $this->playerId($user),
                'displayName' => Str::limit(trim((string) ($user->username ?: $user->name)), 50, ''),
                'currency' => Qtech::currency(),
                'country' => Qtech::country(),
                'lang' => Qtech::lang(),
                'mode' => 'real',
                'device' => $device === 'desktop' ? 'desktop' : 'mobile',
                'returnUrl' => $returnUrl,
                'walletSessionId' => $walletSessionId,
            ]), 'POST /v1/games/{id}/launch-url'));

        $payload = $this->json($response, 'POST /v1/games/{id}/launch-url');
        $url = trim((string) ($payload['url'] ?? ''));

        if ($url === '') {
            throw new RuntimeException('QTech returned no launch URL.');
        }

        return $url;
    }

    /**
     * The identifier QTech know a player by. The same `player_<id>` shape as
     * BigBang, which QtechWalletController::player() already resolves — and
     * inside QTech's 34-character limit for any id we will ever mint.
     */
    public function playerId(User $user): string
    {
        return 'player_'.$user->id;
    }

    /**
     * Prove the credentials work — from THIS server, which is the only place
     * it can be proved: QTech whitelist the caller's address, so a developer
     * machine is refused regardless of whether the password is right.
     *
     * Also reports the address this server is seen from, because that is the
     * value QTech's handover form asked for and it could only be guessed at.
     *
     * @return array{ok: bool, message: string, egress_ip: string|null}
     */
    public function probe(): array
    {
        $egress = null;
        try {
            $seen = Http::connectTimeout(4)->timeout(6)->get('https://api.ipify.org', ['format' => 'json']);
            $egress = $seen->successful() ? trim((string) ($seen->json('ip') ?? '')) ?: null : null;
        } catch (\Throwable) {
            // Cosmetic. The credential check below is the real answer.
        }

        try {
            $this->accessToken(forceRefresh: true);
        } catch (\Throwable $exception) {
            return ['ok' => false, 'message' => self::redact($exception->getMessage()), 'egress_ip' => $egress];
        }

        return ['ok' => true, 'message' => 'QTech issued an access token for '.Qtech::apiUsername().' at '.Qtech::apiBaseUrl().'.', 'egress_ip' => $egress];
    }

    /**
     * Strip credentials out of anything that might be shown or logged.
     *
     * QTech's auth endpoint takes the username and password as QUERY
     * parameters, so a transport failure quotes the entire credential back in
     * its message — and that message reaches an admin's screen and the log
     * file. Nothing that leaves this class may carry it.
     */
    public static function redact(string $message): string
    {
        return preg_replace(
            '/([?&](?:password|username)=)[^&\s"\']*/i',
            '$1[redacted]',
            $message,
        ) ?? $message;
    }

    /**
     * Perform one HTTP attempt, converting any transport failure into a
     * RuntimeException whose message is safe to display and to log.
     *
     * Deliberately does NOT chain the original exception: report() writes the
     * previous exception's message and trace too, which would put the password
     * straight back into the log this exists to keep it out of.
     */
    private function attempt(callable $call, string $operation): Response
    {
        try {
            return $call();
        } catch (\Throwable $exception) {
            throw new RuntimeException(sprintf(
                'QTech %s could not be reached: %s',
                $operation,
                self::redact($exception->getMessage()),
            ));
        }
    }

    /**
     * Run a call with the current token; on INVALID_TOKEN, fetch a fresh one
     * and run it exactly once more. That is the retry QTech's spec asks for
     * (§4.1): reuse the token until a 401 says it has expired.
     */
    private function authorized(callable $call): Response
    {
        $response = $call($this->accessToken());

        if ($response->status() === 401) {
            $this->forgetToken();
            $response = $call($this->accessToken(forceRefresh: true));
        }

        return $response;
    }

    private function request(bool $retrySafe = false): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('QTech is not configured. Add the API username and password under Settings → System.');
        }

        $request = Http::baseUrl(Qtech::apiBaseUrl())
            ->acceptJson()
            ->asJson()
            // A connect bound as well as a read bound: a blackholed edge would
            // otherwise hold the request for the full read timeout per attempt.
            ->connectTimeout(5)
            ->timeout(15);

        // Reads may retry; a launch-url POST creates a game session on their
        // side and must not, or a slow success becomes two sessions.
        return $retrySafe
            ? $request->timeout(8)->retry(3, 500, throw: false)
            : $request;
    }

    private function json(Response $response, string $operation): array
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $payload = str_contains($contentType, 'json') ? $response->json() : null;

        if (! $response->successful()) {
            $code = is_array($payload) ? (string) ($payload['code'] ?? '') : '';
            $message = is_array($payload) ? (string) ($payload['message'] ?? '') : '';

            throw new RuntimeException(sprintf(
                'QTech %s failed with HTTP %d%s%s.',
                $operation,
                $response->status(),
                $code !== '' ? ' '.$code : '',
                $message !== '' ? ': '.$message : '',
            ));
        }

        if (! is_array($payload)) {
            throw new RuntimeException(sprintf('QTech %s returned a non-JSON response.', $operation));
        }

        return $payload;
    }

    private function tokenCacheKey(): string
    {
        return 'qtech-access-token:'.md5(Qtech::apiBaseUrl().'|'.Qtech::apiUsername());
    }
}
