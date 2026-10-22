<?php

namespace App\Http\Controllers\Admin;

use App\Models\Agent;
use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\AgentOrderEvent;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AgentLedgerService;
use App\Services\FinancialRuleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentController
{
    public function index(): View
    {
        return view('admin.agents.index', [
            'agents' => Agent::query()
                ->with('bankAccounts.gateway')
                ->withCount('orders')
                // Service record and presence, so the list answers "who is working
                // and how well" rather than only "who exists".
                ->withAvg('ratings', 'score')
                ->withCount('ratings')
                ->latest()
                ->paginate(15),
            'onlineAgentIds' => \App\Http\Middleware\TrackAgentPresence::onlineAgentIds(),
            'commissionRule' => app(FinancialRuleService::class)->agentCommissionSummary(),
        ]);
    }

    public function create(): View
    {
        return view('admin.agents.create', [
            'gateways' => PaymentGateway::query()->where('active', true)->where('type', 'bank')->orderBy('name')->get(),
            'commissionRule' => app(FinancialRuleService::class)->agentCommissionSummary(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'],
            'nickname' => ['required', 'alpha_dash', 'max:60', 'unique:agents,nickname'],
            'phone' => ['required', 'string', 'max:30', 'unique:agents,phone'],
            'password' => ['required', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        // Commission is a platform-wide flat rule, not a per-agent percentage.
        $validated['commission_rate'] = 0;
        $validated['commission_enabled'] = $request->boolean('commission_enabled');
        $validated['active'] = true;
        Agent::create($validated);
        return redirect()->route('admin.agents.index')->with('admin_status', 'Agent created.');
    }

    public function toggle(Agent $agent): RedirectResponse
    {
        $agent->update(['active' => !$agent->active]);
        return back()->with('admin_status', 'Agent status updated.');
    }

    /**
     * Delete an agent who never handled money.
     *
     * `agent_orders.agent_id` and `wallet_transactions.agent_id` are declared
     * nullOnDelete, so deleting an agent WITH history does not fail — it blanks
     * them out of every order and transaction they ever touched, quietly, with
     * nothing left to say who took the cash. Their ratings cascade away in the
     * same breath. An accounting record has to keep naming the person who
     * handled the money, so that case is refused and pointed at Disable, which
     * is what standing an agent down actually means.
     *
     * What is left is the case this exists for: an agent added by mistake, who
     * has done nothing yet, deleted along with the payment accounts that only
     * ever described how to reach them.
     */
    public function destroy(Agent $agent): RedirectResponse
    {
        $orders = $agent->orders()->count();
        $transactions = WalletTransaction::query()->where('agent_id', $agent->id)->count();
        $balance = (float) $agent->balance;

        if ($orders > 0 || $transactions > 0 || abs($balance) > 0.001) {
            $holds = [];
            if ($orders > 0) {
                $holds[] = number_format($orders).' '.($orders === 1 ? 'order' : 'orders');
            }
            if ($transactions > 0) {
                $holds[] = number_format($transactions).' wallet '.($transactions === 1 ? 'transaction' : 'transactions');
            }
            if (abs($balance) > 0.001) {
                $holds[] = 'a balance of '.\App\Support\Money::format($balance, 2);
            }

            return back()->withErrors(['agent' => $agent->nickname.' has '.implode(', ', $holds)
                .'. Deleting the agent would blank them out of that history rather than remove it, so it is refused.'
                .' Disable the agent instead — that stops new orders and keeps the record intact.']);
        }

        $accounts = $agent->bankAccounts()->count();
        $nickname = $agent->nickname;
        $agent->delete();

        return back()->with('admin_status', 'Deleted agent '.$nickname
            .($accounts > 0 ? ', along with '.$accounts.' payment '.($accounts === 1 ? 'account' : 'accounts').'.' : '.'));
    }

    public function updateBalance(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate([
            'operation' => ['required', Rule::in(['add', 'remove'])],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        DB::transaction(function () use ($request, $agent, $validated): void {
            $locked = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $ledgerKey = 'admin-agent-credit-'.$validated['idempotency_key'];
            if (WalletTransaction::query()->where('ledger_key', $ledgerKey)->exists()) {
                return;
            }
            $signedAmount = $validated['operation'] === 'add' ? $validated['amount'] : -$validated['amount'];
            if ($signedAmount < 0 && $locked->balance < abs($signedAmount)) {
                throw ValidationException::withMessages(['amount' => 'The agent does not have enough credit for this deduction.']);
            }
            if ($signedAmount > 0) {
                $locked->increment('balance', $signedAmount);
            } else {
                $locked->decrement('balance', abs($signedAmount));
            }
            WalletTransaction::create([
                'ledger_key' => $ledgerKey,
                'agent_id' => $locked->id,
                'type' => 'agent_credit',
                'amount' => $signedAmount,
                'status' => 'success',
                'method' => 'administrator',
                'reason' => ($validated['notes'] ?? null) ?: ucfirst($validated['operation']).' agent credit by '.$request->user()->name,
                'currency' => 'ETB',
                'source_type' => 'agent',
                'source_id' => $locked->id,
                'metadata' => ['admin_user_id' => $request->user()->id, 'operation' => $validated['operation']],
            ]);
        });

        return back()->with('admin_status', 'Agent credit balance updated.');
    }

    public function orders(Request $request): View
    {
        return $this->orderBoard($request, [
            'deposit' => 'Deposit orders',
            'withdrawal' => 'Withdrawal orders',
            'manual_withdrawal' => 'Manual withdrawals',
        ], 'admin.agents.orders', 'Orders List', 'Review auditable deposit, withdrawal, and manual withdrawal orders.');
    }

    public function withdrawals(Request $request): View
    {
        return $this->orderBoard($request, [
            'withdrawal' => 'Player withdrawals',
            'manual_withdrawal' => 'Manual withdrawals',
        ], 'admin.agents.withdrawals', 'Withdrawal List', 'Assign, track, and audit every payout handled by the agent network.');
    }

    /**
     * Agent bank accounts drive the deposit instructions shown to players, so they get
     * their own screen instead of being buried in the agent list.
     */
    public function bankInfo(Request $request): View
    {
        $search = $request->string('search')->trim()->limit(80)->toString();
        $agentId = $request->integer('agent_id') ?: null;
        $status = in_array($request->string('status')->toString(), ['active', 'inactive'], true)
            ? $request->string('status')->toString()
            : '';

        return view('admin.agents.bank-info', [
            'search' => $search,
            'agentId' => $agentId,
            'status' => $status,
            'agents' => Agent::query()->orderBy('nickname')->get(['id', 'nickname', 'active', 'balance']),
            'gateways' => PaymentGateway::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'code', 'account_label']),
            'accounts' => AgentBankAccount::query()
                ->with(['agent:id,nickname,phone,active', 'gateway:id,name,code'])
                ->when($agentId, fn ($query, $id) => $query->where('agent_id', $id))
                ->when($status !== '', fn ($query) => $query->where('active', $status === 'active'))
                ->when($search, fn ($query, string $term) => $query->where(fn ($nested) => $nested
                    ->where('account_name', 'like', "%{$term}%")
                    ->orWhere('account_number', 'like', "%{$term}%")
                    ->orWhereHas('agent', fn ($agents) => $agents->where('nickname', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                    ->orWhereHas('gateway', fn ($gateways) => $gateways->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))))
                ->orderBy('agent_id')
                ->orderBy('payment_gateway_id')
                ->paginate($this->perPage($request))
                ->withQueryString(),
        ]);
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'agent_id' => ['required', Rule::exists('agents', 'id')],
            'payment_gateway_id' => ['required', Rule::exists('payment_gateways', 'id')->where('active', true)],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $duplicate = AgentBankAccount::query()
            ->where('agent_id', $validated['agent_id'])
            ->where('payment_gateway_id', $validated['payment_gateway_id'])
            ->where('account_number', $validated['account_number'])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['account_number' => 'This agent already has that account registered for the selected gateway.']);
        }

        AgentBankAccount::create([...$validated, 'active' => true]);

        return back()->with('admin_status', 'Agent bank account added.');
    }

    public function toggleBankAccount(AgentBankAccount $account): RedirectResponse
    {
        $account->update(['active' => ! $account->active]);

        return back()->with('admin_status', 'Agent bank account '.($account->active ? 'enabled' : 'disabled').'.');
    }

    /**
     * Delete an agent payment account outright.
     *
     * Nothing points back at this row. A deposit COPIES the account name and
     * number onto the order as it is created (PlayerDepositController) and
     * refers to the agent and the gateway, never to the account itself — so
     * every order taken through this account keeps the details it was taken
     * with after the row is gone. That is what makes an account added by
     * mistake something an operator can simply undo, rather than leave sitting
     * disabled forever because deleting it might cost something.
     */
    public function destroyBankAccount(AgentBankAccount $account): RedirectResponse
    {
        $label = ($account->gateway?->name ?: 'Payment account').' '.$account->account_number
            .' ('.($account->agent?->nickname ?: 'unassigned').')';

        $account->delete();

        return back()->with('admin_status', 'Deleted payment account '.$label.'.');
    }

    /**
     * Report Transactions: the settled money view of the agent network. Every figure is
     * derived from agent_orders and the agent wallet ledger for the selected period.
     */
    public function reports(Request $request): View
    {
        $filters = $this->reportFilters($request);
        $agents = Agent::query()->orderBy('nickname')->get(['id', 'nickname', 'phone', 'balance', 'commission_enabled', 'commission_rate', 'active']);

        return view('admin.agents.reports', [
            'filters' => $filters,
            'agents' => $agents,
            'summary' => $this->reportSummary($filters),
            'breakdown' => $this->reportBreakdown($filters, $agents),
            // A DISTINCT over the whole ledger just to fill a dropdown. The set of types
            // barely changes, so it is cached rather than scanned on every page view.
            'ledgerTypes' => Cache::remember(
                'admin:agent-ledger-types',
                now()->addMinutes(15),
                fn () => WalletTransaction::query()->whereNotNull('agent_id')->distinct()->orderBy('type')->pluck('type')
            ),
            'transactions' => $this->withCounterparties(
                $this->reportLedgerQuery($filters)
                    ->with(['agent:id,nickname,phone', 'user:id,name,phone', 'gateway:id,name,code'])
                    ->latest('created_at')
                    ->latest('id')
                    ->paginate($this->perPage($request))
                    ->withQueryString()
            ),
        ]);
    }

    public function exportReports(Request $request): StreamedResponse
    {
        $filters = $this->reportFilters($request);
        $filename = 'agent-transaction-report-'.$filters['from']->format('Ymd').'-'.$filters['to']->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Date', 'Transaction ID', 'Agent', 'Agent phone', 'Member', 'Member phone', 'Type', 'Amount ETB', 'Status', 'Method', 'Gateway', 'Reason', 'Source', 'Source ID']);

            $this->reportLedgerQuery($filters)
                ->with(['agent:id,nickname,phone', 'user:id,name,phone', 'gateway:id,name,code'])
                ->orderBy('wallet_transactions.id')
                ->chunkById(500, function ($transactions) use ($output): void {
                    foreach ($this->withCounterparties($transactions) as $transaction) {
                        fputcsv($output, [
                            $transaction->created_at->toIso8601String(),
                            $transaction->transaction_id,
                            $transaction->agent?->nickname,
                            $transaction->agent?->phone,
                            $transaction->user?->name ?: $transaction->counterparty_name,
                            $transaction->user?->phone ?: $transaction->counterparty_phone,
                            $transaction->type,
                            $transaction->amount,
                            $transaction->status,
                            $transaction->method,
                            $transaction->gateway?->name ?: $transaction->bank_code,
                            $transaction->reason,
                            $transaction->source_type,
                            $transaction->source_id,
                        ]);
                    }
                }, 'wallet_transactions.id', 'id');

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function assignOrder(Request $request, AgentOrder $order): RedirectResponse
    {
        abort_unless(in_array($order->type, ['withdrawal', 'manual_withdrawal'], true), 404);
        $validated = $request->validate(['agent_id' => ['required', Rule::exists('agents', 'id')->where('active', true)]]);

        DB::transaction(function () use ($request, $order, $validated): void {
            $locked = AgentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['agent_id' => 'Only pending orders can be assigned.']);
            }
            $previous = $locked->agent_id;
            $locked->update(['agent_id' => $validated['agent_id']]);
            AgentOrderEvent::record($locked, $previous ? 'reassigned' : 'assigned', $request->user(), 'pending', 'pending', null, [
                'previous_agent_id' => $previous,
                'agent_id' => (int) $validated['agent_id'],
            ]);
        });

        return back()->with('admin_status', 'Withdrawal assigned to the selected agent.');
    }

    public function updateOrderStatus(
        Request $request,
        AgentOrder $order,
        AgentLedgerService $agentLedger,
    ): RedirectResponse {
        abort_unless(in_array($order->type, ['deposit', 'withdrawal', 'manual_withdrawal'], true), 404);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['processing', 'success', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($order, $request, $validated, $agentLedger): void {
            $lockedOrder = AgentOrder::query()->lockForUpdate()->findOrFail($order->id);
            $user = User::query()->lockForUpdate()->findOrFail($lockedOrder->user_id);
            $agent = $lockedOrder->agent_id
                ? Agent::query()->lockForUpdate()->find($lockedOrder->agent_id)
                : null;

            if ($lockedOrder->type === 'deposit') {
                $this->settleDeposit($lockedOrder, $user, $agent, $request, $validated, $agentLedger);

                return;
            }

            throw ValidationException::withMessages([
                'status' => 'Withdrawals are completed or rejected directly by the assigned agent.',
            ]);
        });

        return back()->with('admin_status', 'Order status updated with settlement controls.');
    }

    public function payoutProof(AgentOrder $order): StreamedResponse
    {
        $disk = Storage::disk((string) config('filesystems.payout_proof_disk', 'secure'));
        abort_if(blank($order->payout_proof_path) || ! $disk->exists($order->payout_proof_path), 404);

        return $disk->download(
            $order->payout_proof_path,
            'withdrawal-'.$order->id.'-proof.'.pathinfo($order->payout_proof_path, PATHINFO_EXTENSION),
        );
    }

    /**
     * Settlement legs are recorded against the agent float with no member attached, because
     * agent balance reconciliation sums exactly the rows that carry no user. The member the
     * entry settles for is kept in the entry metadata, so it is resolved here for display.
     */
    private function withCounterparties(mixed $transactions): mixed
    {
        $rows = $transactions instanceof Collection ? $transactions : collect($transactions->items());

        $memberIds = $rows
            ->filter(fn (WalletTransaction $transaction) => $transaction->user_id === null)
            ->map(fn (WalletTransaction $transaction) => (int) data_get($transaction->metadata, 'user_id'))
            ->filter()
            ->unique();

        if ($memberIds->isEmpty()) {
            return $transactions;
        }

        $members = User::withTrashed()->whereKey($memberIds->all())->get(['id', 'name', 'phone'])->keyBy('id');

        $rows->each(function (WalletTransaction $transaction) use ($members): void {
            $member = $members->get((int) data_get($transaction->metadata, 'user_id'));

            if ($transaction->user_id === null && $member) {
                $transaction->setAttribute('counterparty_name', $member->name);
                $transaction->setAttribute('counterparty_phone', $member->phone);
            }
        });

        return $transactions;
    }

    /** @param array<string, string> $tabs */
    private function orderBoard(Request $request, array $tabs, string $route, string $heading, string $intro): View
    {
        $types = array_keys($tabs);
        $type = in_array($request->string('type')->toString(), $types, true)
            ? $request->string('type')->toString()
            : $types[0];
        $status = in_array($request->string('status')->toString(), ['pending', 'processing', 'payout_submitted', 'success', 'rejected', 'cancelled'], true)
            ? $request->string('status')->toString()
            : null;
        $search = $request->string('search')->trim()->toString();
        $stale = $request->boolean('stale');

        return view('admin.agents.orders', [
            'type' => $type,
            'tabs' => $tabs,
            'route' => $route,
            'heading' => $heading,
            'intro' => $intro,
            'status' => $status,
            'search' => $search,
            'stale' => $stale,
            'agents' => Agent::query()->where('active', true)->orderBy('nickname')->get(['id', 'nickname', 'balance']),
            'orders' => AgentOrder::query()
                ->where('type', $type)
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($stale, fn ($query) => $query->where('status', 'pending')->where('created_at', '<=', now()->subMinutes((int) config('operations.stale_order_minutes', 30))))
                ->when($search, fn ($query) => $query->where(fn ($nested) => $nested
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhere('account_number', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($users) => $users->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))))
                ->with(['user', 'agent', 'gateway', 'processedBy', 'reviewedBy', 'payoutSubmittedBy', 'events'])
                ->latest()
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    private function perPage(Request $request): int
    {
        return in_array($request->integer('per_page'), [10, 25, 50, 100], true) ? $request->integer('per_page') : 25;
    }

    /** @return array{from: CarbonImmutable, to: CarbonImmutable, agent_id: ?int, type: string, status: string, search: string, preset: string} */
    private function reportFilters(Request $request): array
    {
        $preset = in_array($request->string('preset')->toString(), ['today', 'week', 'month', 'quarter', 'custom'], true)
            ? $request->string('preset')->toString()
            : 'month';

        $today = CarbonImmutable::now()->startOfDay();
        $isDate = static fn (string $value): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        $fromInput = $request->string('from')->toString();
        $toInput = $request->string('to')->toString();

        if ($preset === 'custom' && $isDate($fromInput) && $isDate($toInput)) {
            $from = CarbonImmutable::parse($fromInput)->startOfDay();
            $to = CarbonImmutable::parse($toInput)->endOfDay();
        } else {
            $preset = $preset === 'custom' ? 'month' : $preset;
            [$from, $to] = match ($preset) {
                'today' => [$today, $today->endOfDay()],
                'week' => [$today->subDays(6), $today->endOfDay()],
                'quarter' => [$today->subDays(89), $today->endOfDay()],
                default => [$today->subDays(29), $today->endOfDay()],
            };
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        return [
            'preset' => $preset,
            'from' => $from,
            'to' => $to,
            'agent_id' => $request->integer('agent_id') ?: null,
            'type' => $request->string('type')->trim()->limit(40)->toString(),
            'status' => in_array($request->string('status')->toString(), ['success', 'pending', 'failed', 'rejected'], true)
                ? $request->string('status')->toString()
                : '',
            'search' => $request->string('search')->trim()->limit(120)->toString(),
        ];
    }

    /** @return Builder<WalletTransaction> */
    private function reportLedgerQuery(array $filters): Builder
    {
        return WalletTransaction::query()
            ->whereNotNull('agent_id')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['agent_id'], fn (Builder $query, int $id) => $query->where('agent_id', $id))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['search'], fn (Builder $query, string $term) => $query->where(fn (Builder $nested) => $nested
                ->where('transaction_id', 'like', "%{$term}%")
                ->orWhere('reason', 'like', "%{$term}%")
                ->orWhere('method', 'like', "%{$term}%")
                ->orWhereHas('agent', fn (Builder $agents) => $agents->where('nickname', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))
                ->orWhereHas('user', fn (Builder $users) => $users->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))));
    }

    /**
     * Settled order volume plus the agent ledger movements for the selected window.
     *
     * Aggregated in two grouped passes rather than one query per figure. Working it out a
     * total at a time meant twelve scans of the same two date ranges, which is what made
     * this page crawl as the ledger grew.
     */
    private function reportSummary(array $filters): array
    {
        $orderRows = AgentOrder::query()
            ->selectRaw('type, status, COUNT(*) as orders, COALESCE(SUM(amount), 0) as amount')
            ->whereNotNull('agent_id')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['agent_id'], fn ($query, int $id) => $query->where('agent_id', $id))
            ->groupBy('type', 'status')
            ->get();

        $ordersWhere = function (array $types, ?string $status) use ($orderRows): array {
            $matching = $orderRows->filter(fn ($row) => in_array($row->type, $types, true)
                && ($status === null || $row->status === $status));

            return [
                'count' => (int) $matching->sum('orders'),
                'total' => (float) $matching->sum('amount'),
            ];
        };

        $deposits = $ordersWhere(['deposit'], 'success');
        $withdrawals = $ordersWhere(['withdrawal', 'manual_withdrawal'], 'success');
        $pendingRows = $orderRows->where('status', 'pending');

        $ledgerRows = WalletTransaction::query()
            ->selectRaw('type, COUNT(*) as entries, COALESCE(SUM(amount), 0) as total, '
                .'COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as credits, '
                .'COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) as debits')
            ->whereNotNull('agent_id')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['agent_id'], fn ($query, int $id) => $query->where('agent_id', $id))
            ->groupBy('type')
            ->get();

        $credit = $ledgerRows->firstWhere('type', 'agent_credit');

        return [
            'deposit_count' => $deposits['count'],
            'deposit_total' => $deposits['total'],
            'withdrawal_count' => $withdrawals['count'],
            'withdrawal_total' => $withdrawals['total'],
            'pending_count' => (int) $pendingRows->sum('orders'),
            'pending_total' => (float) $pendingRows->sum('amount'),
            'commission_total' => (float) ($ledgerRows->firstWhere('type', 'agent_commission')->total ?? 0),
            'top_up_total' => (float) ($credit->credits ?? 0),
            'deduction_total' => abs((float) ($credit->debits ?? 0)),
            'net_settled' => $deposits['total'] - $withdrawals['total'],
            'ledger_entries' => (int) $ledgerRows->sum('entries'),
            'float_balance' => (float) Agent::query()
                ->when($filters['agent_id'], fn ($query, int $id) => $query->whereKey($id))
                ->sum('balance'),
        ];
    }

    /**
     * Per-agent settlement grid. Aggregated in two grouped queries and stitched together
     * so the page stays fast no matter how many agents are on the network.
     *
     * @param  \Illuminate\Support\Collection<int, Agent>  $agents
     */
    private function reportBreakdown(array $filters, $agents): array
    {
        $orderRows = AgentOrder::query()
            ->selectRaw('agent_id, type, status, COUNT(*) as orders, COALESCE(SUM(amount), 0) as amount')
            ->whereNotNull('agent_id')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['agent_id'], fn ($query, int $id) => $query->where('agent_id', $id))
            ->groupBy('agent_id', 'type', 'status')
            ->get();

        $ledgerRows = WalletTransaction::query()
            ->selectRaw('agent_id, type, COUNT(*) as entries, COALESCE(SUM(amount), 0) as amount')
            ->whereNotNull('agent_id')
            ->whereBetween('created_at', [$filters['from'], $filters['to']])
            ->when($filters['agent_id'], fn ($query, int $id) => $query->where('agent_id', $id))
            ->groupBy('agent_id', 'type')
            ->get();

        $rows = [];

        foreach ($agents as $agent) {
            if ($filters['agent_id'] && (int) $agent->id !== (int) $filters['agent_id']) {
                continue;
            }

            $agentOrders = $orderRows->where('agent_id', $agent->id);
            $agentLedger = $ledgerRows->where('agent_id', $agent->id);

            $settledDeposits = $agentOrders->where('type', 'deposit')->where('status', 'success');
            $settledWithdrawals = $agentOrders->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'success');
            $pendingOrders = $agentOrders->where('status', 'pending');

            $row = [
                'agent' => $agent,
                'deposit_count' => (int) $settledDeposits->sum('orders'),
                'deposit_total' => (float) $settledDeposits->sum('amount'),
                'withdrawal_count' => (int) $settledWithdrawals->sum('orders'),
                'withdrawal_total' => (float) $settledWithdrawals->sum('amount'),
                'pending_count' => (int) $pendingOrders->sum('orders'),
                'pending_total' => (float) $pendingOrders->sum('amount'),
                'commission_total' => (float) $agentLedger->where('type', 'agent_commission')->sum('amount'),
                'credit_total' => (float) $agentLedger->where('type', 'agent_credit')->sum('amount'),
                'ledger_entries' => (int) $agentLedger->sum('entries'),
            ];

            $row['net_settled'] = $row['deposit_total'] - $row['withdrawal_total'];
            $row['has_activity'] = $row['ledger_entries'] > 0
                || $row['deposit_count'] > 0
                || $row['withdrawal_count'] > 0
                || $row['pending_count'] > 0;

            $rows[] = $row;
        }

        usort($rows, fn (array $left, array $right) => [$right['has_activity'], $right['deposit_total'] + $right['withdrawal_total']]
            <=> [$left['has_activity'], $left['deposit_total'] + $left['withdrawal_total']]);

        return $rows;
    }

    private function settleDeposit(
        AgentOrder $order,
        User $user,
        ?Agent $agent,
        Request $request,
        array $validated,
        AgentLedgerService $agentLedger,
    ): void {
        if ($order->status !== 'pending' || ! in_array($validated['status'], ['success', 'rejected'], true)) {
            throw ValidationException::withMessages(['status' => 'This deposit request has already been processed.']);
        }
        if ($validated['status'] === 'success' && (blank($order->reference) || ! $agent)) {
            throw ValidationException::withMessages(['status' => 'A bank reference and assigned agent are required.']);
        }
        if ($validated['status'] === 'success') {
            if (WalletTransaction::query()->where('ledger_key', 'player-deposit-order-'.$order->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'This deposit already has a ledger settlement.']);
            }
            $amount = (float) $order->amount;
            if ((float) $agent->balance < $amount) {
                throw ValidationException::withMessages(['status' => 'The assigned agent does not have enough credit.']);
            }
            $commission = app(FinancialRuleService::class)->agentCommissionFor($agent, $amount);
            $agent->decrement('balance', $amount);
            $agentLedger->record($agent, 'agent-deposit-settlement-'.$order->id, 'agent_deposit_settlement', -$amount, 'Agent funded player deposit', 'agent_order', $order->id);
            if ($commission > 0) {
                $agent->increment('balance', $commission);
                $agentLedger->record($agent, 'agent-deposit-commission-'.$order->id, 'agent_commission', $commission, 'Commission for a qualifying deposit', 'agent_order', $order->id);
            }
            $user->increment('credits', $amount);
            $user->increment('withdrawable_balance', $amount);
            $order->commission = $commission;
            WalletTransaction::create([
                'ledger_key' => 'player-deposit-order-'.$order->id,
                'user_id' => $user->id,
                'agent_id' => $agent->id,
                'payment_gateway_id' => $order->payment_gateway_id,
                'type' => 'deposit',
                'amount' => $amount,
                'fee' => 0,
                'status' => 'success',
                'method' => 'administrator',
                'reason' => 'Deposit approved by administrator',
                'currency' => 'ETB',
                'bank_code' => $order->gateway?->code,
                'source_type' => 'agent_order',
                'source_id' => $order->id,
                'metadata' => ['reference' => $order->reference, 'approved_by_user_id' => $request->user()->id],
            ]);
        }

        $order->update([
            'status' => $validated['status'],
            'processed_by_user_id' => $request->user()->id,
            'processed_at' => now(),
            'notes' => ($validated['notes'] ?? null) ?: ($validated['status'] === 'success'
                ? 'Approved by administrator.'
                : 'Rejected by administrator.'),
        ]);
        AgentOrderEvent::record(
            $order,
            $validated['status'] === 'success' ? 'approved' : 'rejected',
            $request->user(),
            'pending',
            $validated['status'],
            $order->notes,
        );
    }
}
