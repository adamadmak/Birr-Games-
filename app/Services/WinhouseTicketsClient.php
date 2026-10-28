<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Support\Winhouse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Read-only client for WinHouse's tenant tickets API.
 *
 * WinHouse support (26 Oct 2026): every operator has `GET /tenant/api/tickets`
 * with their key; it returns each ticket with its selections, odds, stake,
 * possible win and status. That is what the admin Bet tickets page lists and
 * what the printable receipt is built from. Nothing here moves money: the
 * wallet callbacks in WinhouseWalletController stay the only money path.
 *
 * Probed the same day. The endpoint identifies the operator by a
 * `customer_key` query parameter, which is the iframe key, and takes the API
 * key in the headers. Their docs/agent.md §4.7 names the headers X-Customer-Key
 * and Authorization: Bearer, the filters `player_id`, `status` (open, pending,
 * won, lost, cashed_out, cancelled, rejected), `from`/`to` (ISO, `to` exclusive),
 * and paging with `limit` (max 200) and `offset`. It answers
 * `{ total, tickets: [...] }` where a ticket is
 *
 *   { ticket_id, player_id: "player_12", username: "<tenant>_player_12",
 *     status: open|won|lost|void, type: single|multiple, placed_at, settled_at,
 *     currency, stake, total_odds, bonus, potential_win, payout,
 *     selections: [ { home, away, league, country, kickoff, bet_type,
 *                      picks: [ { market, pick, odds } ],
 *                      match: { status, score, period, minute } } ] }
 *
 * Every field is still read through a list of likely names, so a renamed key
 * degrades to a blank rather than an error, and the raw ticket is kept.
 */
final class WinhouseTicketsClient
{
    public const DEFAULT_BASE_URL = 'https://iframe.winhouse.bet';

    /** The most tickets one call asks for when a filter has to be applied here. */
    public const SCAN_LIMIT = 200;   // the API caps limit at 200

    /** @var list<string> */
    private const TICKET_LISTS = ['tickets', 'data', 'items', 'results'];

    public function configured(): bool
    {
        return Winhouse::apiKey() !== '' && Winhouse::iframeKey() !== '';
    }

    /**
     * A page of tickets.
     *
     * @param  array<string, scalar|null>  $query  passed through (limit, offset, status, player_id, from, to)
     * @return array{ok: bool, status: int, error: ?string, tickets: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function tickets(array $query = []): array
    {
        if (! $this->configured()) {
            return $this->failure(0, 'The WinHouse API key and iframe key are not set. Add them under Configuration → System.');
        }

        try {
            $response = $this->request()->get('/tenant/api/tickets', $this->params($query));
        } catch (Throwable $e) {
            return $this->failure(0, 'WinHouse did not answer: '.$e->getMessage());
        }

        if (! $response->successful()) {
            return $this->failure($response->status(), $this->errorMessage($response));
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return $this->failure($response->status(), 'WinHouse answered with something other than JSON.');
        }

        $list = $this->ticketList($payload);

        return [
            'ok' => true,
            'status' => $response->status(),
            'error' => null,
            'tickets' => array_values(array_map(fn ($t) => $this->normalize($t), array_filter($list, 'is_array'))),
            'meta' => $this->meta($payload, count($list), $query),
        ];
    }

    /**
     * One ticket by its id: the detail endpoint when there is one, else a scan
     * of the list (the API has no ticket_id filter).
     *
     * @return array<string, mixed>|null  normalised ticket
     */
    public function ticket(string $id): ?array
    {
        if (! $this->configured() || $id === '') {
            return null;
        }

        try {
            $response = $this->request()->get('/tenant/api/tickets/'.rawurlencode($id), $this->params([]));
            if ($response->successful() && is_array($payload = $response->json())) {
                $one = $payload['data'] ?? $payload['ticket'] ?? $payload;
                if (is_array($one) && $this->firstOf($one, ['ticket_id', 'id', 'code', 'number', 'uuid']) !== null) {
                    return $this->normalize($one);
                }
            }
        } catch (Throwable) {
            // fall through to the scan
        }

        for ($offset = 0; $offset < 10 * self::SCAN_LIMIT; $offset += self::SCAN_LIMIT) {
            $page = $this->tickets(['limit' => self::SCAN_LIMIT, 'offset' => $offset]);
            if (! $page['ok']) {
                return null;
            }
            foreach ($page['tickets'] as $ticket) {
                if ((string) $ticket['id'] === $id) {
                    return $ticket;
                }
            }
            if (count($page['tickets']) < self::SCAN_LIMIT) {
                break;
            }
        }

        return null;
    }

    /**
     * A booking code: a slip someone saved in the book, read at current odds
     * (docs/agent.md 4.8, GET /tenant/api/bookings/<code>). An expired code
     * answers 410 with the booked selections still listed.
     *
     * @return array{ok: bool, status: int, error: ?string, booking: ?array<string, mixed>}
     */
    public function booking(string $code): array
    {
        $code = self::bookingCode($code);
        if ($code === null) {
            return ['ok' => false, 'status' => 400, 'error' => 'A booking code is 7 letters and digits, like K7M4QZT.', 'booking' => null];
        }
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'error' => 'The WinHouse keys are not set.', 'booking' => null];
        }

        try {
            $response = $this->request()->get('/tenant/api/bookings/'.$code, $this->params([]));
        } catch (Throwable) {
            return ['ok' => false, 'status' => 0, 'error' => 'WinHouse cannot be reached right now. Try again in a minute.', 'booking' => null];
        }

        $payload = is_array($response->json()) ? $response->json() : [];
        $booking = is_array($payload['booking'] ?? null) ? $this->normalizeBooking($payload['booking']) : null;

        return match (true) {
            $response->successful() && $booking !== null => ['ok' => true, 'status' => 200, 'error' => null, 'booking' => $booking],
            $response->status() === 410 => ['ok' => false, 'status' => 410, 'error' => 'This code has expired: its first match has started or it is older than 72 hours.', 'booking' => $booking],
            in_array($response->status(), [400, 404], true) => ['ok' => false, 'status' => 404, 'error' => 'No booking with that code on this sportsbook.', 'booking' => null],
            $response->status() === 503 => ['ok' => false, 'status' => 503, 'error' => 'WinHouse could not look up the odds just now. Try again in a minute.', 'booking' => null],
            default => ['ok' => false, 'status' => $response->status(), 'error' => $this->errorMessage($response), 'booking' => null],
        };
    }

    /** The code in its canonical form, or null: 7 of A-Z and 2-9 without 0 O 1 I L, any case. */
    public static function bookingCode(mixed $code): ?string
    {
        if (! is_string($code)) {
            return null;
        }
        $code = strtoupper((string) preg_replace('/[\s-]+/', '', $code));

        return preg_match('/^[A-HJKMNP-Z2-9]{7}$/', $code) ? $code : null;
    }

    /** @return array<string, mixed> */
    private function normalizeBooking(array $b): array
    {
        $selections = [];
        foreach ((array) ($b['selections'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $home = (string) ($s['home'] ?? '');
            $away = (string) ($s['away'] ?? '');
            $name = trim((string) ($s['event_name'] ?? ''));
            $selections[] = [
                'match' => $name !== '' ? $name : trim($home.' v '.$away, ' v'),
                'league' => implode(' · ', array_filter([(string) ($s['country'] ?? ''), (string) ($s['league'] ?? '')])),
                'kickoff' => $this->date($s['kickoff'] ?? null),
                'market' => (string) ($s['market'] ?? ''),
                'outcome' => $this->pickLabel((string) ($s['outcome'] ?? ''), $home, $away),
                'odds_at_booking' => is_numeric($s['odds_at_booking'] ?? null) ? (float) $s['odds_at_booking'] : null,
                'current_odds' => is_numeric($s['current_odds'] ?? null) ? (float) $s['current_odds'] : null,
                'available' => (bool) ($s['available'] ?? false),
                'reason' => str_replace('_', ' ', (string) ($s['unavailable_reason'] ?? '')),
            ];
        }

        return [
            'code' => (string) ($b['code'] ?? ''),
            'state' => strtolower((string) ($b['state'] ?? '')),
            'created_at' => $this->date($b['created_at'] ?? null),
            'expires_at' => $this->date($b['expires_at'] ?? null),
            'stake' => is_numeric($b['stake'] ?? null) ? (float) $b['stake'] : 0.0,
            'mode' => strtolower((string) ($b['mode'] ?? '')),
            'system_size' => $b['system_size'] ?? null,
            'available_count' => (int) ($b['available_count'] ?? count(array_filter($selections, fn ($s) => $s['available']))),
            'odds_changed' => (bool) ($b['odds_changed'] ?? false),
            'selections' => $selections,
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Query string for every call: the operator's `customer_key` plus the
     * caller's filters, blanks dropped.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, scalar>
     */
    private function params(array $query): array
    {
        return ['customer_key' => Winhouse::iframeKey()] + array_filter($query, fn ($v) => $v !== null && $v !== '');
    }

    private function request(): PendingRequest
    {
        $key = Winhouse::apiKey();

        // Support said "with their key" and not how it travels, so the API key
        // is sent the two usual ways at once; the server reads the one it knows.
        $request = Http::baseUrl(self::baseUrl())
            ->acceptJson()
            // Documented (docs/agent.md §4.7): X-Customer-Key plus a Bearer API key.
            ->withHeaders(['X-Customer-Key' => Winhouse::iframeKey(), 'X-API-Key' => $key])
            ->withToken($key)
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 400, throw: false);

        // Same escape hatch as the Telegram client: a host whose PHP has no CA
        // store can point at a bundle instead of failing every call.
        $caBundle = trim((string) self::setting('winhouse_ca_bundle', (string) config('services.winhouse.ca_bundle')));

        return $caBundle !== '' ? $request->withOptions(['verify' => $caBundle]) : $request;
    }

    public static function baseUrl(): string
    {
        return rtrim(self::setting('winhouse_tickets_api_base', self::DEFAULT_BASE_URL) ?: self::DEFAULT_BASE_URL, '/');
    }

    private static function setting(string $name, string $default): string
    {
        try {
            return trim((string) PlatformSetting::valueFor($name, $default));
        } catch (Throwable) {
            return $default;
        }
    }

    /** @return array{ok: false, status: int, error: string, tickets: array{}, meta: array{}} */
    private function failure(int $status, string $error): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $error, 'tickets' => [], 'meta' => []];
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        $detail = is_array($json) ? (string) ($json['error'] ?? $json['message'] ?? $json['detail'] ?? '') : '';

        return match ($response->status()) {
            400, 401, 403 => 'WinHouse refused the keys'.($detail !== '' ? ': '.$detail : '.'),
            404 => 'WinHouse has no tickets endpoint at '.self::baseUrl().'/tenant/api/tickets. Set winhouse_tickets_api_base if they gave you another host.',
            default => 'WinHouse answered '.$response->status().($detail !== '' ? ': '.$detail : '.'),
        };
    }

    /** @return list<mixed> */
    private function ticketList(array $payload): array
    {
        if (array_is_list($payload)) {
            return $payload;
        }
        foreach (self::TICKET_LISTS as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                // Laravel-style {data: {data: [...]}} paginators included.
                return array_is_list($payload[$key]) ? $payload[$key] : $this->ticketList($payload[$key]);
            }
        }

        return [];
    }

    /**
     * @param  array<string, scalar|null>  $query  what was asked for, since the answer only carries `total`
     * @return array<string, mixed>
     */
    private function meta(array $payload, int $count, array $query): array
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : $payload;
        $total = (int) ($this->firstOf($meta, ['total', 'count', 'total_count']) ?? $count);
        $perPage = (int) ($this->firstOf($meta, ['per_page', 'limit']) ?? ($query['limit'] ?? max(1, $count)));
        $offset = (int) ($this->firstOf($meta, ['offset']) ?? ($query['offset'] ?? 0));

        return [
            'total' => $total,
            'per_page' => $perPage,
            'offset' => $offset,
            'page' => (int) floor($offset / max(1, $perPage)) + 1,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    /** @return array<string, mixed> */
    private function normalize(array $t): array
    {
        $playerRef = (string) ($this->firstOf($t, ['player_id', 'external_id', 'player', 'user']) ?? '');
        if (is_array($t['player'] ?? null)) {
            $playerRef = (string) ($this->firstOf($t['player'], ['external_id', 'id', 'username']) ?? $playerRef);
        }
        $username = (string) ($this->firstOf($t, ['username']) ?? $playerRef);
        // "player_12", or the tenant-prefixed "<tenant>_player_12" WinHouse shows.
        $playerId = preg_match('/player_(\d+)$/', $playerRef !== '' ? $playerRef : $username, $m) ? (int) $m[1] : null;

        $rawSelections = $this->firstOf($t, ['selections', 'bets', 'events', 'items', 'legs', 'outcomes']);
        $selections = [];
        foreach (is_array($rawSelections) ? array_filter($rawSelections, 'is_array') : [] as $s) {
            foreach ($this->selections($s) as $row) {
                $selections[] = $row;
            }
        }

        return [
            'id' => (string) ($this->firstOf($t, ['ticket_id', 'id', 'code', 'number', 'uuid']) ?? ''),
            'status' => strtolower((string) ($this->firstOf($t, ['status', 'state', 'result']) ?? 'open')),
            'type' => (string) ($this->firstOf($t, ['type', 'bet_type', 'kind']) ?? ''),
            'stake' => (float) ($this->firstOf($t, ['stake', 'amount', 'bet_amount', 'total_stake']) ?? 0),
            'bonus' => (float) ($this->firstOf($t, ['bonus', 'bonus_amount', 'acca_bonus']) ?? 0),
            'possible_win' => (float) ($this->firstOf($t, ['potential_win', 'possible_win', 'max_win', 'potential_payout']) ?? 0),
            'paid' => (float) ($this->firstOf($t, ['payout', 'paid', 'paid_amount', 'won_amount', 'settled_amount']) ?? 0),
            'odds' => (float) ($this->firstOf($t, ['total_odds', 'odds', 'total_odd', 'coefficient', 'price']) ?? 0),
            'currency' => strtoupper((string) ($this->firstOf($t, ['currency']) ?? Winhouse::currency())),
            'placed_at' => $this->date($this->firstOf($t, ['placed_at', 'created_at', 'date', 'time', 'timestamp'])),
            'settled_at' => $this->date($this->firstOf($t, ['settled_at', 'resolved_at'])),
            'player' => $username,
            'player_id' => $playerId,
            'selections' => $selections,
            'raw' => $t,
        ];
    }

    /**
     * One API selection is one event with one or more picks; the receipt wants
     * a line per pick.
     *
     * @return list<array<string, mixed>>
     */
    private function selections(array $s): array
    {
        $home = (string) ($this->firstOf($s, ['home', 'home_team']) ?? '');
        $away = (string) ($this->firstOf($s, ['away', 'away_team']) ?? '');
        $event = $this->firstOf($s, ['event', 'match_name', 'event_name', 'fixture']);
        if (is_array($event)) {
            $event = $this->firstOf($event, ['name', 'title']);
        }
        if (($event === null || $event === '') && $home !== '' && $away !== '') {
            $event = $home.' – '.$away;
        }
        $match = is_array($s['match'] ?? null) ? $s['match'] : [];
        $base = [
            'event' => (string) ($event ?? ''),
            'home' => $home,
            'away' => $away,
            'league' => (string) ($this->firstOf($s, ['league', 'tournament', 'competition', 'category']) ?? ''),
            'country' => (string) ($this->firstOf($s, ['country']) ?? ''),
            'live' => strtolower((string) ($this->firstOf($s, ['bet_type']) ?? '')) === 'live',
            'status' => strtolower((string) ($this->firstOf($match, ['status', 'result', 'state']) ?? $this->firstOf($s, ['status', 'result']) ?? '')),
            'score' => (string) ($this->firstOf($match, ['score', 'final_score']) ?? $this->firstOf($s, ['score']) ?? ''),
            'starts_at' => $this->date($this->firstOf($s, ['kickoff', 'start_time', 'starts_at', 'event_date', 'date'])),
        ];

        $picks = $this->firstOf($s, ['picks', 'outcomes']);
        if (! is_array($picks) || $picks === []) {
            // Flat selection: the pick is on the selection itself.
            $picks = [$s];
        }

        $rows = [];
        foreach (array_filter($picks, 'is_array') as $p) {
            $pick = (string) ($this->firstOf($p, ['pick', 'selection', 'outcome', 'choice', 'outcome_name', 'name']) ?? '');
            $rows[] = $base + [
                'market' => (string) ($this->firstOf($p, ['market', 'market_name']) ?? ''),
                'pick' => $pick,
                'pick_label' => $this->pickLabel($pick, $home, $away),
                'odds' => (float) ($this->firstOf($p, ['odds', 'odd', 'price', 'coefficient', 'rate']) ?? 0),
            ];
        }

        return $rows;
    }

    /** "1" / "X" / "2" and the double chances, spelled out with the team names. */
    private function pickLabel(string $pick, string $home, string $away): string
    {
        if ($home === '' || $away === '') {
            return $pick;
        }

        return match (strtoupper($pick)) {
            '1' => $home,
            '2' => $away,
            'X' => 'Draw',
            '1X' => $home.' or Draw',
            'X2' => 'Draw or '.$away,
            '12' => $home.' or '.$away,
            default => $pick,
        };
    }

    private function firstOf(array $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && $source[$key] !== null && $source[$key] !== '') {
                return $source[$key];
            }
        }

        return null;
    }

    /** Parsed and moved into the app's timezone, so a receipt shows local time. */
    private function date(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $date = is_numeric($value)
                ? Carbon::createFromTimestamp((int) $value > 20000000000 ? (int) $value / 1000 : (int) $value)
                : Carbon::parse((string) $value);

            return $date->setTimezone((string) config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
