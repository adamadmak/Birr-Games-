<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\WinhouseTransaction;
use App\Services\WinhouseTicketsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Sportsbook tickets for the back office, with a printable receipt.
 *
 * The list comes from WinHouse's tickets API (selections, odds, possible win,
 * status). The wallet's own winhouse_transactions rows are read alongside:
 * they name the player and carry what was actually debited and paid here, and
 * they are the whole list when WinHouse cannot be reached.
 */
class SportsTicketController
{
    public function __construct(private readonly WinhouseTicketsClient $client)
    {
    }

    public function index(Request $request): View
    {
        $ticketSearch = trim($request->string('ticket')->toString());
        $phone = trim($request->string('phone')->toString());
        $status = trim(strtolower($request->string('status')->toString()));
        $date = $request->filled('date') ? Carbon::parse($request->string('date')->toString())->startOfDay() : null;
        $perPage = in_array((int) $request->input('per_page', 25), [10, 25, 50], true) ? (int) $request->input('per_page', 25) : 25;
        $page = max(1, (int) $request->input('page', 1));

        $player = $phone !== ''
            ? User::query()->where('phone', 'like', '%'.$phone.'%')->orWhere('name', 'like', '%'.$phone.'%')->first()
            : null;

        // WinHouse filters by player, status and placement date and pages with
        // limit/offset. A ticket number is not a filter it knows, so for that the
        // recent tickets are fetched in pages of the maximum and sifted here.
        $sift = $ticketSearch !== '';
        $query = [
            'status' => $status !== '' ? $status : null,
            'player_id' => $player ? 'player_'.$player->id : null,
            'from' => $date?->toIso8601String(),
            'to' => $date?->copy()->addDay()->toIso8601String(),
        ];
        if ($sift) {
            $query['limit'] = WinhouseTicketsClient::SCAN_LIMIT;
            $query['offset'] = 0;
        } else {
            $query['limit'] = $perPage;
            $query['offset'] = ($page - 1) * $perPage;
        }

        $result = $this->client->tickets($query);

        if ($result['ok']) {
            $tickets = collect($result['tickets'])
                ->when($ticketSearch !== '', fn (Collection $c) => $c->filter(fn ($t) => str_contains(strtolower($t['id']), strtolower($ticketSearch))))
                ->values();
            $total = $sift ? $tickets->count() : (int) ($result['meta']['total'] ?? $tickets->count());
            if ($sift) {
                $tickets = $tickets->forPage($page, $perPage)->values();
            }
            $local = $this->localTickets($tickets->pluck('id')->all(), $perPage);
        } else {
            // WinHouse is unreachable: the wallet rows are the list.
            $local = $this->localTickets(null, 200)
                ->when($ticketSearch !== '', fn (Collection $c) => $c->filter(fn ($t) => str_contains(strtolower($t->id), strtolower($ticketSearch))))
                ->when($player, fn (Collection $c) => $c->filter(fn ($t) => $t->user?->id === $player->id))
                ->when($status !== '', fn (Collection $c) => $c->filter(fn ($t) => $t->status === $status))
                ->when($date, fn (Collection $c) => $c->filter(fn ($t) => $t->placed_at?->isSameDay($date)));
            $total = $local->count();
            $tickets = $local->values()->map(fn ($t) => [
                'id' => $t->id, 'status' => $t->status, 'type' => '', 'stake' => $t->stake, 'bonus' => 0.0, 'possible_win' => 0.0,
                'paid' => $t->paid, 'odds' => 0.0, 'currency' => $t->currency, 'placed_at' => $t->placed_at,
                'settled_at' => null, 'player' => $t->user ? 'player_'.$t->user->id : '', 'player_id' => $t->user?->id,
                'selections' => [], 'raw' => [],
            ])->forPage($page, $perPage)->values();
        }

        $users = User::query()
            ->whereIn('id', $tickets->pluck('player_id')->filter()->unique()->all())
            ->get(['id', 'name', 'phone'])
            ->keyBy('id');

        return view('admin.sports.tickets', [
            'result' => $result,
            'tickets' => $tickets,
            'local' => $local,
            'users' => $users,
            'ticketSearch' => $ticketSearch,
            'phone' => $phone,
            'status' => $status,
            'date' => $date,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => max(1, (int) ceil($total / $perPage)),
            'sifted' => $result['ok'] && $sift,
            'apiBase' => WinhouseTicketsClient::baseUrl(),
        ]);
    }

    /** The receipt. A standalone page sized for an 80 mm roll; ?auto=1 opens the print dialog. */
    public function print(Request $request, string $ticket): View
    {
        $data = $this->client->ticket($ticket);
        $local = $this->localTickets([$ticket], 1)->get($ticket);
        abort_if($data === null && $local === null, 404, 'Ticket not found.');

        $playerId = $data['player_id'] ?? $local?->user?->id;
        $player = $playerId ? User::query()->find($playerId, ['id', 'name', 'phone']) : null;

        return view('admin.sports.ticket-print', [
            'ticketId' => $ticket,
            'data' => $data,
            'local' => $local,
            'player' => $player,
            'auto' => $request->boolean('auto'),
        ]);
    }

    /**
     * The wallet's view of tickets: stake, win and void rows grouped per ticket.
     *
     * @param  list<string>|null  $onlyIds  null means "the latest ones"
     * @return Collection<string, object>
     */
    private function localTickets(?array $onlyIds, int $limit): Collection
    {
        if (! Schema::hasTable('winhouse_transactions') || ($onlyIds !== null && $onlyIds === [])) {
            return collect();
        }

        $query = WinhouseTransaction::query()->with('user:id,name,phone')->whereNotNull('ticket_id')->latest('id');
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
}
