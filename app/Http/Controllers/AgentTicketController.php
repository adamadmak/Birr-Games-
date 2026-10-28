<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\User;
use App\Models\WinhouseTransaction;
use App\Services\WinhouseTicketsClient;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/**
 * Sportsbook tickets in the agent portal, with the 80 mm receipt the back
 * office prints (resources/views/sports/ticket-print.blade.php).
 *
 * Agents are the ones at the counter, so they print the slips. Who may see what:
 *
 *  - The list holds this agent's customers' tickets only. A customer is a
 *    player with at least one agent_orders row for this agent, the same rule
 *    as My Customers (AgentPortalController::customers()).
 *  - A ticket number typed in full is looked up on its own and shown whoever
 *    placed it: a player walks in with the slip, and holding the number is the
 *    authorisation. The receipt follows the same rule, so it prints for any
 *    ticket that exists.
 *  - A phone narrows the list to one customer. Another agent's players cannot
 *    be found by phone, and their phone number is never shown in full.
 *
 * When WinHouse cannot be reached the page says so and lists the wallet's own
 * winhouse_transactions rows instead, under the same rule.
 */
class AgentTicketController
{
    /** The print route's constraint (routes/web.php); a ticket outside it gets no print link. */
    public const PRINTABLE_ID = '/^[A-Za-z0-9_.:\-]{1,80}$/';

    /** @var array<string, string> what WinHouse filters on (docs/agent.md §4.7) */
    public const STATUSES = [
        'open' => 'Open', 'pending' => 'Pending', 'won' => 'Won', 'lost' => 'Lost',
        'cashed_out' => 'Cashed out', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected',
    ];

    private const PER_PAGE = 25;

    public function __construct(private readonly WinhouseTicketsClient $client)
    {
    }

    public function index(Request $request): View
    {
        $agent = $this->agent();
        $ticketSearch = mb_substr(trim($request->string('ticket')->toString()), 0, 80);
        $phone = mb_substr(trim($request->string('phone')->toString()), 0, 40);
        $status = strtolower(trim($request->string('status')->toString()));
        $status = array_key_exists($status, self::STATUSES) ? $status : '';
        $date = $this->date($request->string('date')->toString());
        $page = max(1, (int) $request->input('page', 1));

        // A phone resolves to one of this agent's customers or to nobody: the
        // phone box never reaches another agent's players.
        $customer = null;
        if ($phone !== '' && ($candidates = PhoneNumber::candidates($phone)) !== []) {
            $customer = $this->customers($agent)->whereIn('phone', $candidates)->first(['users.id', 'users.name', 'users.phone']);
        }
        $phoneMiss = $phone !== '' && $customer === null;

        $filters = [
            'status' => $status !== '' ? $status : null,
            'player_id' => $customer ? 'player_'.$customer->id : null,
            'from' => $date?->toIso8601String(),
            'to' => $date?->copy()->addDay()->toIso8601String(),
        ];

        // WinHouse pages by player itself, so one customer's tickets come a page
        // at a time. Anything else is the latest SCAN_LIMIT tickets sifted here.
        $paged = $customer !== null && $ticketSearch === '';
        $exact = null;
        $sifted = false;

        if ($phoneMiss && $ticketSearch === '') {
            $result = ['ok' => true, 'status' => 0, 'error' => null, 'tickets' => [], 'meta' => []];
        } else {
            $result = $this->client->tickets($filters + ($paged
                ? ['limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE]
                : ['limit' => WinhouseTicketsClient::SCAN_LIMIT, 'offset' => 0]));
        }

        if ($result['ok']) {
            $tickets = collect($result['tickets']);

            if ($ticketSearch !== '') {
                $exact = $tickets->first(fn ($t) => (string) $t['id'] === $ticketSearch)
                    ?? $this->client->ticket($ticketSearch)
                    ?? $this->fromLocal($this->localTickets([$ticketSearch])->get($ticketSearch));
            }

            if ($paged) {
                // Kept to that player even if WinHouse ever ignored the filter.
                $tickets = $tickets->filter(fn ($t) => $t['player_id'] === (int) $customer->id)->values();
                $total = (int) ($result['meta']['total'] ?? $tickets->count());
            } else {
                $sifted = ! $phoneMiss;
                $tickets = $phoneMiss ? collect() : $this->onlyCustomers($agent, $tickets);
                $total = null;
            }
        } else {
            // WinHouse is unreachable: the wallet's rows are the list, still only
            // this agent's customers (or the one customer the phone named).
            $players = $customer ? User::query()->whereKey($customer->id) : $this->customers($agent);
            $tickets = $phoneMiss ? collect() : $this->localTickets(null, $players)
                ->when($status !== '', fn (Collection $c) => $c->filter(fn ($t) => $t->status === $status))
                ->when($date, fn (Collection $c) => $c->filter(fn ($t) => $t->placed_at?->isSameDay($date)))
                ->map(fn ($t) => $this->fromLocal($t))
                ->values();
            $total = null;

            if ($ticketSearch !== '') {
                $exact = $this->fromLocal($this->localTickets([$ticketSearch])->get($ticketSearch));
            }
        }

        if ($ticketSearch !== '') {
            // The full number first, whoever placed it; then the agent's own
            // customers' tickets whose number contains what was typed.
            $needle = strtolower($ticketSearch);
            $tickets = $tickets
                ->filter(fn ($t) => str_contains(strtolower((string) $t['id']), $needle) && (string) $t['id'] !== $ticketSearch)
                ->when($exact, fn (Collection $c) => $c->prepend($exact))
                ->values();
        }

        if ($total === null) {
            $total = $tickets->count();
            $tickets = $tickets->forPage($page, self::PER_PAGE)->values();
        }

        $playerIds = $tickets->pluck('player_id')->filter()->unique()->values()->all();
        $users = User::query()->whereKey($playerIds)->get(['id', 'name', 'phone'])->keyBy('id');
        $mine = $this->customerIdsAmong($agent, $playerIds);

        return view('agent.tickets', [
            'agent' => $agent,
            'result' => $result,
            'tickets' => $tickets,
            'local' => $result['ok'] ? $this->localTickets($tickets->pluck('id')->all()) : collect(),
            'users' => $users,
            'mine' => $mine,
            'exactId' => $exact['id'] ?? null,
            'ticketSearch' => $ticketSearch,
            'phone' => $phone,
            'phoneMiss' => $phoneMiss,
            'customer' => $customer,
            'status' => $status,
            'statuses' => self::STATUSES,
            'date' => $date,
            'page' => $page,
            'total' => $total,
            'lastPage' => max(1, (int) ceil($total / self::PER_PAGE)),
            'sifted' => $sifted,
        ]);
    }

    /**
     * The receipt. Any ticket that exists prints: the agent can only reach one
     * that is not their customer's by typing its full number. ?auto=1 opens
     * the print dialog.
     */
    public function print(Request $request, string $ticket): View
    {
        $agent = $this->agent();
        $data = $this->client->ticket($ticket);
        $local = $this->localTickets([$ticket])->get($ticket);
        abort_if($data === null && $local === null, 404, 'Ticket not found.');

        $playerId = $data['player_id'] ?? $local?->user?->id;
        $player = $playerId ? User::query()->find($playerId, ['id', 'name', 'phone']) : null;

        return view('sports.ticket-print', [
            'ticketId' => $ticket,
            'data' => $data,
            'local' => $local,
            'player' => $player,
            'auto' => $request->boolean('auto'),
            'backUrl' => route('agent.tickets'),
            'printedBy' => trim($agent->first_name.' '.$agent->last_name),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function agent(): Agent
    {
        return Auth::guard('agent')->user();
    }

    /** This agent's customers: players with at least one of the agent's orders. */
    private function customers(Agent $agent): Builder
    {
        return User::query()
            ->where('users.role', User::ROLE_PLAYER)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('agent_orders')
                ->whereColumn('agent_orders.user_id', 'users.id')
                ->where('agent_orders.agent_id', $agent->id));
    }

    /**
     * Which of these players are this agent's customers.
     *
     * @param  list<int|string|null>  $userIds
     * @return Collection<int, int>
     */
    private function customerIdsAmong(Agent $agent, array $userIds): Collection
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        return $userIds === []
            ? collect()
            : $this->customers($agent)->whereIn('users.id', $userIds)->pluck('users.id')->map(fn ($id) => (int) $id)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $tickets
     * @return Collection<int, array<string, mixed>>
     */
    private function onlyCustomers(Agent $agent, Collection $tickets): Collection
    {
        $mine = $this->customerIdsAmong($agent, $tickets->pluck('player_id')->all())->flip();

        return $tickets->filter(fn ($t) => $t['player_id'] !== null && $mine->has($t['player_id']))->values();
    }

    private function date(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The wallet's view of tickets: stake, win and void rows grouped per ticket
     * (the same grouping as Admin\SportsTicketController).
     *
     * @param  list<string>|null  $onlyIds  exact ticket ids, or null for the latest ones
     * @param  Builder|null  $players  a users query the rows must belong to
     * @return Collection<string, object>
     */
    private function localTickets(?array $onlyIds, ?Builder $players = null, int $limit = 200): Collection
    {
        if (! Schema::hasTable('winhouse_transactions') || ($onlyIds !== null && $onlyIds === [])) {
            return collect();
        }

        $query = WinhouseTransaction::query()->with('user:id,name,phone')->whereNotNull('ticket_id')->latest('id');
        if ($players !== null) {
            $query->whereIn('user_id', (clone $players)->select('users.id'));
        }
        if ($onlyIds !== null) {
            $query->whereIn('ticket_id', $onlyIds);
        } else {
            $query->limit($limit * 4);
        }

        return $query->get()->groupBy('ticket_id')->map(function (Collection $rows, string $id) {
            $bet = $rows->firstWhere('action', 'bet') ?? $rows->first();

            return (object) [
                'id' => $id,
                'user' => $bet->user,
                'currency' => $bet->currency,
                'stake' => (float) $rows->where('action', 'bet')->sum('amount'),
                'paid' => (float) $rows->where('action', 'win')->sum('amount'),
                'void' => (float) $rows->where('action', 'void')->sum('amount'),
                'placed_at' => $rows->min('created_at'),
                'status' => $rows->contains('action', 'void') ? 'void' : ($rows->contains('action', 'win') ? 'won' : 'open'),
            ];
        });
    }

    /**
     * A wallet ticket in the shape WinhouseTicketsClient normalises to.
     *
     * @return array<string, mixed>|null
     */
    private function fromLocal(?object $t): ?array
    {
        if ($t === null) {
            return null;
        }

        return [
            'id' => $t->id, 'status' => $t->status, 'type' => '', 'stake' => $t->stake, 'bonus' => 0.0,
            'possible_win' => 0.0, 'paid' => $t->paid, 'odds' => 0.0, 'currency' => $t->currency,
            'placed_at' => $t->placed_at, 'settled_at' => null, 'player' => $t->user ? 'player_'.$t->user->id : '',
            'player_id' => $t->user?->id, 'selections' => [], 'raw' => [],
        ];
    }
}
