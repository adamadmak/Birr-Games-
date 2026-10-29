<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\AgentOrderEvent;
use App\Models\PaymentGateway;
use App\Models\TelegramSubscriber;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\AgentLedgerService;
use App\Services\TelegramBotService;
use App\Services\FinancialRuleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentPortalController
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('agent')->check()) {
            return redirect()->route('agent.dashboard');
        }

        return view('agent.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);
        $credentials['active'] = true;

        if (!Auth::guard('agent')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['phone' => 'The phone number or password is incorrect, or this agent is disabled.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('agent.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('agent')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('agent.login');
    }

    public function generateTelegramLinkCode(Request $request): RedirectResponse
    {
        $agent = $this->agent($request);
        $code = strtoupper(Str::random(8));
        $agent->forceFill(['telegram_link_code' => $code])->save();

        return back()->with('status', 'Your Telegram link code was generated.');
    }

    public function unlinkTelegram(Request $request): RedirectResponse
    {
        $agent = $this->agent($request);
        $agent->forceFill(['telegram_chat_id' => null, 'telegram_link_code' => null, 'telegram_linked_at' => null])->save();

        return back()->with('status', 'Your Telegram account was unlinked.');
    }

    /** Periods the agent dashboard can be read over. 'all' keeps the old behaviour. */
    private const DASHBOARD_PERIODS = ['today', '7days', '30days', 'all'];

    /**
     * The agent's own numbers, over a period they choose.
     *
     * Every figure here used to be cumulative since the account was opened, which
     * stops being information after the first month: a lifetime commission total
     * only ever goes up, so it cannot answer "how did I do today" or "is this week
     * worse than last". The same figures are now readable over a window, with
     * movement against the preceding window of equal length — the comparison the
     * admin dashboard already makes.
     *
     * Pending counts stay absolute. A queue is what is waiting now, not what
     * arrived inside some window, and filtering it by date would hide work.
     */
    public function dashboard(Request $request): View
    {
        $agent = $this->agent($request);

        $period = in_array($request->string('period')->toString(), self::DASHBOARD_PERIODS, true)
            ? $request->string('period')->toString()
            : 'today';

        [$from, $to] = $this->dashboardWindow($period);

        $orders = AgentOrder::query()->where('agent_id', $agent->id);
        $inWindow = fn () => $from
            ? (clone $orders)->whereBetween('created_at', [$from, $to])
            : (clone $orders);

        $deposits = fn ($query) => $query->where('type', 'deposit')->where('status', 'success');
        $withdrawals = fn ($query) => $query->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'success');

        $current = [
            'completedDeposits' => $deposits($inWindow())->count(),
            'totalDeposited' => (int) $deposits($inWindow())->sum('amount'),
            'totalWithdrawn' => (int) $withdrawals($inWindow())->sum('amount'),
            'depositCommission' => (int) $deposits($inWindow())->sum('commission'),
            'withdrawalCommission' => (int) $withdrawals($inWindow())->sum('commission'),
        ];

        return view('agent.dashboard', array_merge($current, [
            'agent' => $agent,
            // Their own service record. Shown to the agent as well as the operator:
            // a score used only for oversight teaches nobody anything.
            'ratingAverage' => $agent->ratings()->avg('score'),
            'ratingCount' => $agent->ratings()->count(),
            'period' => $period,
            'periodLabel' => $this->dashboardPeriodLabel($period),
            // Absolute on purpose: see the note above.
            'pendingDeposits' => (clone $orders)->where('type', 'deposit')->where('status', 'pending')->count(),
            'pendingWithdrawals' => $this->availableWithdrawals($agent)->where('status', 'pending')->count(),
            'deltas' => $this->dashboardDeltas($agent, $from, $to, $current),
            'floatMinimum' => app(FinancialRuleService::class)->agentMinimumFloat(),
            'floatWarning' => app(FinancialRuleService::class)->agentLowFloatWarning(),
        ]));
    }

    /** @return array{0: ?Carbon, 1: Carbon} Null start means "everything". */
    private function dashboardWindow(string $period): array
    {
        $to = now()->endOfDay();

        return match ($period) {
            '7days' => [now()->subDays(6)->startOfDay(), $to],
            '30days' => [now()->subDays(29)->startOfDay(), $to],
            'all' => [null, $to],
            default => [now()->startOfDay(), $to],
        };
    }

    private function dashboardPeriodLabel(string $period): string
    {
        return match ($period) {
            '7days' => 'Last 7 days',
            '30days' => 'Last 30 days',
            'all' => 'All time',
            default => 'Today',
        };
    }

    /**
     * Movement against the preceding window of the same length.
     *
     * Null for a metric whose baseline was zero — "up from nothing" is not a
     * percentage, and rendering one would invent precision. Null for "all time"
     * too, which has nothing before it to compare against.
     *
     * @param  array<string, int>  $current
     * @return array<string, ?float>
     */
    private function dashboardDeltas(Agent $agent, ?Carbon $from, Carbon $to, array $current): array
    {
        if (! $from) {
            return [];
        }

        $length = $from->diffInSeconds($to);
        $previousEnd = $from->copy()->subSecond();
        $previousStart = $previousEnd->copy()->subSeconds($length);

        $previousOrders = AgentOrder::query()
            ->where('agent_id', $agent->id)
            ->whereBetween('created_at', [$previousStart, $previousEnd]);

        $deposits = fn () => (clone $previousOrders)->where('type', 'deposit')->where('status', 'success');
        $withdrawals = fn () => (clone $previousOrders)->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'success');

        $previous = [
            'completedDeposits' => $deposits()->count(),
            'totalDeposited' => (int) $deposits()->sum('amount'),
            'totalWithdrawn' => (int) $withdrawals()->sum('amount'),
            'depositCommission' => (int) $deposits()->sum('commission'),
            'withdrawalCommission' => (int) $withdrawals()->sum('commission'),
        ];

        $deltas = [];
        foreach ($current as $key => $now) {
            $before = $previous[$key] ?? 0;
            $deltas[$key] = $before > 0 ? round((($now - $before) / $before) * 100, 1) : null;
        }

        return $deltas;
    }

    public function withdrawals(Request $request): View
    {
        $agent = $this->agent($request);
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();

        $orders = $this->availableWithdrawals($agent)
            ->with(['user', 'gateway'])
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->where('reference', 'like', "%{$search}%")
                ->orWhere('account_name', 'like', "%{$search}%")
                ->orWhere('account_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn (Builder $userQuery) => $userQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%"))))
            ->when(in_array($status, ['pending', 'processing', 'payout_submitted', 'success', 'rejected', 'cancelled'], true), fn (Builder $query) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('agent.withdrawals', compact('agent', 'orders', 'search', 'status'));
    }

    public function processWithdrawal(
        Request $request,
        AgentOrder $order,
        AgentLedgerService $agentLedger,
        ?FinancialRuleService $rules = null,
    ): RedirectResponse
    {
        $rules ??= app(FinancialRuleService::class);
        $agent = $this->agent($request);
        if ($request->hasFile('payout_proof') && ! $request->file('payout_proof')->isValid()) {
            $error = $request->file('payout_proof')->getError();
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded proof file exceeds the maximum allowed file size (5MB).',
                UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary folder for upload.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write uploaded file to disk.',
                default => 'The file failed to upload. Please select a valid file under 5MB.',
            };
            throw ValidationException::withMessages(['payout_proof' => $message]);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['success', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:1000'],
            'payout_reference' => ['required_if:status,success', 'nullable', 'string', 'max:120'],
            'payout_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $proofPath = ($validated['status'] === 'success' && $request->hasFile('payout_proof'))
            ? $request->file('payout_proof')->store(
                'withdrawal-proofs',
                (string) config('filesystems.payout_proof_disk', 'secure')
            )
            : null;

        try {
            DB::transaction(function () use ($agent, $order, $validated, $proofPath, $agentLedger, $rules): void {
                $lockedOrder = AgentOrder::query()->lockForUpdate()->findOrFail($order->id);
                if (! in_array($lockedOrder->type, ['withdrawal', 'manual_withdrawal'], true)) {
                    abort(404);
                }
                if ($lockedOrder->agent_id !== $agent->id) {
                    abort(403, 'This withdrawal must be assigned to you by an administrator.');
                }
                if (! in_array($lockedOrder->status, ['pending', 'processing', 'payout_submitted'], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'This withdrawal has already been completed or cancelled.',
                    ]);
                }
                if ($lockedOrder->status === 'payout_submitted' && $validated['status'] === 'rejected') {
                    throw ValidationException::withMessages([
                        'status' => 'This legacy payout was already reported as sent and cannot be automatically refunded.',
                    ]);
                }
                $fromStatus = $lockedOrder->status;

                $transaction = WalletTransaction::query()
                    ->where('source_type', 'agent_order')
                    ->where('source_id', $lockedOrder->id)
                    ->where('type', 'withdrawal')
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($validated['status'] === 'rejected') {
                    $user = User::query()->lockForUpdate()->findOrFail($lockedOrder->user_id);
                    $user->increment('credits', $lockedOrder->amount);
                    $user->increment('withdrawable_balance', $lockedOrder->amount);
                    $lockedOrder->update([
                        'status' => 'rejected',
                        'processed_at' => now(),
                        'notes' => ($validated['notes'] ?? null) ?: 'Rejected by agent '.$agent->nickname.'; reserved funds restored.',
                    ]);
                    $transaction->update([
                        'agent_id' => $agent->id,
                        'status' => 'rejected',
                        'reason' => 'Withdrawal rejected by assigned agent; reserved funds restored',
                        'metadata' => array_merge($transaction->metadata ?? [], [
                            'processed_at' => now()->toIso8601String(),
                            'processed_by_agent_id' => $agent->id,
                            'funds_restored' => true,
                            'agent_notes' => $validated['notes'] ?? null,
                        ]),
                    ]);
                    AgentOrderEvent::record($lockedOrder, 'rejected', $agent, $fromStatus, 'rejected', $lockedOrder->notes);

                    return;
                }

                $lockedAgent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
                $commission = $rules->agentCommissionFor($lockedAgent, (float) $lockedOrder->amount, 'withdrawal');
                if (WalletTransaction::query()->where('ledger_key', 'agent-withdrawal-settlement-'.$lockedOrder->id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'This withdrawal has already been settled.']);
                }

                $lockedAgent->increment('balance', $lockedOrder->amount);
                $agentLedger->record(
                    $lockedAgent,
                    'agent-withdrawal-settlement-'.$lockedOrder->id,
                    'agent_withdrawal_settlement',
                    $lockedOrder->amount,
                    'Agent reimbursement for paid withdrawal',
                    'agent_order',
                    $lockedOrder->id,
                );
                if ($commission > 0) {
                    $lockedAgent->increment('balance', $commission);
                    $agentLedger->record(
                        $lockedAgent,
                        'agent-withdrawal-commission-'.$lockedOrder->id,
                        'agent_commission',
                        $commission,
                        'Commission for a qualifying withdrawal',
                        'agent_order',
                        $lockedOrder->id,
                    );
                }

                $transaction->update([
                    'agent_id' => $lockedAgent->id,
                    'status' => 'success',
                    'reason' => 'Withdrawal paid and approved by assigned agent',
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'processed_at' => now()->toIso8601String(),
                        'processed_by_agent_id' => $lockedAgent->id,
                        'assigned_agent_id' => $lockedAgent->id,
                        'payout_reference' => trim($validated['payout_reference']),
                        'funds_restored' => false,
                        'agent_notes' => $validated['notes'] ?? null,
                    ]),
                ]);
                $lockedOrder->update([
                    'commission' => $commission,
                    'status' => 'success',
                    'processed_at' => now(),
                    'payout_submitted_by_agent_id' => $lockedAgent->id,
                    'payout_submitted_at' => now(),
                    'payout_reference' => trim($validated['payout_reference']),
                    'payout_proof_path' => $proofPath,
                    'notes' => ($validated['notes'] ?? null) ?: 'Withdrawal paid and approved by agent '.$lockedAgent->nickname.'.',
                ]);
                AgentOrderEvent::record(
                    $lockedOrder,
                    'paid_and_approved',
                    $lockedAgent,
                    $fromStatus,
                    'success',
                    $lockedOrder->notes,
                    ['payout_reference' => $lockedOrder->payout_reference],
                );
            });
        } catch (\Throwable $exception) {
            if ($proofPath) {
                Storage::disk((string) config('filesystems.payout_proof_disk', 'secure'))->delete($proofPath);
            }
            throw $exception;
        }

        return back()->with('agent_status', $validated['status'] === 'success'
            ? 'Withdrawal paid and completed. The player and agent ledgers are synchronized.'
            : 'Withdrawal rejected and the player funds were restored.');
    }

    public function manualWithdrawal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
        ]);
        $agent = $this->agent($request);
        $order = $this->availableWithdrawals($agent)
            ->where('reference', trim($validated['reference']))
            ->whereHas('user', fn (Builder $query) => $query->where('phone', trim($validated['phone'])))
            ->where('status', 'pending')
            ->first();

        if (!$order) {
            throw ValidationException::withMessages(['reference' => 'No pending withdrawal matches that code and phone number.']);
        }

        return redirect()->route('agent.withdrawals', ['search' => $order->reference])
            ->with('agent_status', 'Withdrawal found. Verify the payout details before marking it paid.');
    }

    public function bankInfo(Request $request): View
    {
        $agent = $this->agent($request);
        return view('agent.bank-info', [
            'agent' => $agent,
            'accounts' => $agent->bankAccounts()->with('gateway')->latest()->get(),
            'gateways' => PaymentGateway::query()
                ->where('active', true)
                ->where(fn (Builder $query) => $query
                    ->where('supports_deposit', true)
                    ->orWhere('supports_withdrawal', true))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validate([
            'payment_gateway_id' => ['required', Rule::exists('payment_gateways', 'id')->where('active', true)],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $validated['account_number'] = preg_replace('/\s+/', '', trim($validated['account_number']));

        // One row per agent, method and number (agent_bank_acct_unique): adding
        // it again would be a database error, so say so, or switch it back on.
        $existing = AgentBankAccount::query()
            ->where('agent_id', $agent->id)
            ->where('payment_gateway_id', $validated['payment_gateway_id'])
            ->where('account_number', $validated['account_number'])
            ->first();
        if ($existing) {
            if ($existing->active) {
                throw ValidationException::withMessages(['account_number' => 'You already have this account number for this payment method.']);
            }
            $existing->update(['active' => true, 'account_name' => $validated['account_name'], 'notes' => $validated['notes'] ?? $existing->notes]);

            return back()->with('agent_status', 'That account was switched off; it is active again.');
        }

        AgentBankAccount::create([...$validated, 'agent_id' => $agent->id, 'active' => true]);

        return back()->with('agent_status', 'Bank account added.');
    }

    public function toggleBankAccount(Request $request, AgentBankAccount $account): RedirectResponse
    {
        $agent = $this->agent($request);
        abort_unless($account->agent_id === $agent->id, 404);
        $account->update(['active' => !$account->active]);

        return back()->with('agent_status', 'Bank account status updated.');
    }

    public function depositOrders(Request $request): View
    {
        $agent = $this->agent($request);
        $status = $request->string('status')->toString();
        $reference = trim($request->string('reference')->toString());
        $orders = AgentOrder::query()
            ->where('agent_id', $agent->id)
            ->where('type', 'deposit')
            ->with(['user', 'gateway'])
            ->when(in_array($status, ['pending', 'success', 'rejected', 'cancelled'], true), fn (Builder $query) => $query->where('status', $status))
            ->latest()
            ->paginate(15, ['*'], 'orders_page')
            ->withQueryString();
        $searchedOrder = $reference === '' ? null : AgentOrder::query()
            ->where('agent_id', $agent->id)
            ->where('reference', $reference)
            ->with(['user', 'gateway'])
            ->first();
        $receivedTransfers = WalletTransaction::query()
            ->where('agent_id', $agent->id)
            ->where('type', 'agent_transfer')
            ->where('amount', '>', 0)
            ->latest()
            ->paginate(15, ['*'], 'received_page')
            ->withQueryString();
        $counterpartIds = $receivedTransfers->getCollection()->pluck('source_id')->filter()->unique();
        $counterpartAgents = Agent::query()->whereIn('id', $counterpartIds)->get()->keyBy('id');

        $proofOrderIds = \App\Models\DepositProof::idsFor(collect($orders->items())->push($searchedOrder)->filter());

        return view('agent.deposit-orders', compact(
            'agent', 'orders', 'status', 'reference', 'searchedOrder', 'receivedTransfers', 'counterpartAgents', 'proofOrderIds'
        ));
    }

    public function processDeposit(
        Request $request,
        AgentOrder $order,
        TelegramBotService $telegram,
        AgentLedgerService $agentLedger,
        ?FinancialRuleService $rules = null,
    ): RedirectResponse {
        $rules ??= app(FinancialRuleService::class);
        $agent = $this->agent($request);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['success', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($agent, $order, $validated, $agentLedger, $rules): array {
            $lockedOrder = AgentOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->type !== 'deposit') {
                abort(404);
            }
            if ($lockedOrder->agent_id !== $agent->id) {
                abort(403, 'This deposit is assigned to another agent.');
            }
            if ($lockedOrder->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This deposit has already been processed.']);
            }
            if ($validated['status'] === 'success' && ! $lockedOrder->hasPaymentEvidence()) {
                throw ValidationException::withMessages(['status' => 'The player has not sent a bank reference or a payment screenshot yet.']);
            }

            $lockedAgent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $user = User::query()->lockForUpdate()->findOrFail($lockedOrder->user_id);
            $amount = (float) $lockedOrder->amount;
            $commission = $validated['status'] === 'success'
                ? $rules->agentCommissionFor($lockedAgent, $amount)
                : 0.0;

            $floatBefore = (float) $lockedAgent->balance;
            if ($validated['status'] === 'success') {
                if ($refusal = $rules->agentFloatRefusal($lockedAgent, $amount)) {
                    throw ValidationException::withMessages(['status' => $refusal]);
                }
                if (WalletTransaction::query()->where('ledger_key', 'player-deposit-order-'.$lockedOrder->id)->exists()) {
                    throw ValidationException::withMessages(['status' => 'This deposit already has a ledger settlement.']);
                }

                $lockedAgent->decrement('balance', $amount);
                $agentLedger->record($lockedAgent, 'agent-deposit-settlement-'.$lockedOrder->id, 'agent_deposit_settlement', -$amount, 'Agent funded player deposit', 'agent_order', $lockedOrder->id);
                if ($commission > 0) {
                    $lockedAgent->increment('balance', $commission);
                    $agentLedger->record($lockedAgent, 'agent-deposit-commission-'.$lockedOrder->id, 'agent_commission', $commission, 'Commission for a qualifying deposit', 'agent_order', $lockedOrder->id);
                }
                $user->increment('credits', $amount);
                $user->increment('withdrawable_balance', $amount);

                WalletTransaction::create([
                    'ledger_key' => 'player-deposit-order-'.$lockedOrder->id,
                    'user_id' => $user->id,
                    'agent_id' => $lockedAgent->id,
                    'payment_gateway_id' => $lockedOrder->payment_gateway_id,
                    'type' => 'deposit',
                    'amount' => $amount,
                    'fee' => 0,
                    'status' => 'success',
                    // The player funded this at a bank; the agent only verified the reference.
                    'method' => 'agent_approved',
                    'reason' => 'Player deposit approved by agent '.$lockedAgent->nickname,
                    'currency' => 'ETB',
                    'bank_code' => $lockedOrder->gateway?->code,
                    'source_type' => 'agent_order',
                    'source_id' => $lockedOrder->id,
                    'metadata' => [
                        'reference' => $lockedOrder->reference,
                        'account_name' => $lockedOrder->account_name,
                        'account_number' => $lockedOrder->account_number,
                        'commission' => number_format($commission, 2, '.', ''),
                        'approved_by_agent_id' => $lockedAgent->id,
                    ],
                ]);

                // Play-only bonus on top of the cash; never withdrawable, never throws.
                app(\App\Services\DepositBonusService::class)->grantForDeposit($user, $lockedOrder, $amount);
            }

            $lockedOrder->update([
                'commission' => $commission,
                'status' => $validated['status'],
                'processed_at' => now(),
                'notes' => ($validated['notes'] ?? null) ?: ($validated['status'] === 'success'
                    ? 'Deposit verified and approved by agent '.$lockedAgent->nickname.'.'
                    : 'Deposit rejected by agent '.$lockedAgent->nickname.'.'),
            ]);
            AgentOrderEvent::record(
                $lockedOrder,
                $validated['status'] === 'success' ? 'approved' : 'rejected',
                $lockedAgent,
                'pending',
                $validated['status'],
                $lockedOrder->notes,
            );

            return [
                'order' => $lockedOrder->fresh(),
                'user' => $user->fresh(),
                'ranLow' => $rules->agentFloatJustRanLow($floatBefore, (float) $lockedAgent->fresh()->balance),
            ];
        });

        if ($result['ranLow']) {
            app(\App\Services\AgentNotifier::class)->lowFloat($agent->fresh(), $rules->agentMinimumFloat());
        }

        try {
            $subscriber = TelegramSubscriber::query()
                ->where('user_id', $result['user']->id)
                ->where('active', true)
                ->latest('id')
                ->first();

            if ($subscriber) {
                $message = $validated['status'] === 'success'
                    ? '<b>Your deposit was approved.</b>'."\n\nAmount: ".number_format((float) $result['order']->amount, 2)." ETB\nNew balance: ".number_format((float) $result['user']->credits, 2).' ETB'
                    : '<b>Your deposit was rejected.</b>'."\n\nReference: ".e($result['order']->reference)."\nPlease contact the assigned agent if you need help.";
                $telegram->sendText($subscriber->chat_id, $message, [], $telegram->mainMenuKeyboard());
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram deposit status notification failed.', [
                'order_id' => $result['order']->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return back()->with('agent_status', $validated['status'] === 'success'
            ? 'Deposit approved and the player wallet was credited.'
            : 'Deposit rejected. No wallet funds were added.');
    }

    /**
     * The people this agent actually serves.
     *
     * The portal had no such view at all: an agent could process a deposit, but
     * could not answer "who are my customers", "has this person dealt with me
     * before", or "is this withdrawal unusual for them". Every one of those
     * questions was a support call.
     *
     * There is no assignment column linking a player to an agent — the
     * relationship is transactional, so it is derived from the orders this agent
     * has handled. Totals are correlated subqueries rather than a group-by join,
     * which keeps the list paginated: a group-by would have to build every
     * customer before it could return the first page.
     */
    public function customers(Request $request): View
    {
        $agent = $this->agent($request);
        $search = trim($request->string('search')->toString());

        $mine = fn (Builder|\Illuminate\Database\Query\Builder $query) => $query
            ->from('agent_orders')
            ->whereColumn('agent_orders.user_id', 'users.id')
            ->where('agent_orders.agent_id', $agent->id);

        $sumOf = fn (array $types) => AgentOrder::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('agent_orders.user_id', 'users.id')
            ->where('agent_orders.agent_id', $agent->id)
            ->whereIn('type', $types)
            ->where('status', 'success');

        $customers = User::query()
            ->where('role', User::ROLE_PLAYER)
            ->whereExists(fn ($query) => $mine($query)->select(DB::raw(1)))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->where('name', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('username', 'like', '%'.ltrim($search, '@').'%')
                ->orWhere('phone', 'like', "%{$search}%")))
            ->select('users.*')
            ->selectSub($sumOf(['deposit']), 'agent_deposited')
            ->selectSub($sumOf(['withdrawal', 'manual_withdrawal']), 'agent_withdrawn')
            ->selectSub(
                AgentOrder::query()->selectRaw('COUNT(*)')
                    ->whereColumn('agent_orders.user_id', 'users.id')
                    ->where('agent_orders.agent_id', $agent->id),
                'agent_order_count'
            )
            ->selectSub(
                AgentOrder::query()->selectRaw('MAX(created_at)')
                    ->whereColumn('agent_orders.user_id', 'users.id')
                    ->where('agent_orders.agent_id', $agent->id),
                'agent_last_order_at'
            )
            ->selectSub(
                AgentOrder::query()->selectRaw('COUNT(*)')
                    ->whereColumn('agent_orders.user_id', 'users.id')
                    ->where('agent_orders.agent_id', $agent->id)
                    ->where('status', 'pending'),
                'agent_pending_count'
            )
            ->orderByDesc('agent_last_order_at')
            ->paginate(20)
            ->withQueryString();

        return view('agent.customers', [
            'agent' => $agent,
            'customers' => $customers,
            'search' => $search,
        ]);
    }

    public function reports(Request $request): View
    {
        $agent = $this->agent($request);
        [$from, $to] = $this->reportRange($request);
        $orders = AgentOrder::query()->where('agent_id', $agent->id)->whereBetween('created_at', [$from, $to]);

        return view('agent.reports', [
            'agent' => $agent,
            'from' => $from,
            'to' => $to,
            'depositCount' => (clone $orders)->where('type', 'deposit')->where('status', 'success')->count(),
            'depositTotal' => (int) (clone $orders)->where('type', 'deposit')->where('status', 'success')->sum('amount'),
            'withdrawalCount' => (clone $orders)->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'success')->count(),
            'withdrawalTotal' => (int) (clone $orders)->whereIn('type', ['withdrawal', 'manual_withdrawal'])->where('status', 'success')->sum('amount'),
        ]);
    }

    public function exportReport(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['deposit', 'withdrawal'], true), 404);
        $agent = $this->agent($request);
        [$from, $to] = $this->reportRange($request);
        $orders = AgentOrder::query()
            ->where('agent_id', $agent->id)
            ->whereBetween('created_at', [$from, $to])
            ->where('status', 'success')
            ->when($type === 'deposit', fn (Builder $query) => $query->where('type', 'deposit'))
            ->when($type === 'withdrawal', fn (Builder $query) => $query->whereIn('type', ['withdrawal', 'manual_withdrawal']))
            ->with(['user', 'gateway']);

        // Batched, not ->get(): the same reason the member export is. A streamed
        // response that first materialises every row has given up the only thing
        // streaming was for, and this one grows with the agent's history.
        return response()->streamDownload(function () use ($orders): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Reference', 'Player', 'Phone', 'Amount (ETB)', 'Status', 'Method', 'Date']);
            $orders->orderBy('agent_orders.id')->chunkById(500, function ($rows) use ($output): void {
                foreach ($rows as $order) {
                    fputcsv($output, [
                        $order->reference,
                        $order->user?->name,
                        $order->user?->phone,
                        number_format($order->amount, 2, '.', ''),
                        $order->status,
                        $order->gateway?->name ?: 'Agent',
                        $order->created_at?->toDateTimeString(),
                    ]);
                }
            }, 'agent_orders.id', 'id');
            fclose($output);
        }, $type.'-report-'.$from->format('Y-m-d').'-'.$to->format('Y-m-d').'.csv');
    }

    public function directDeposit(Request $request): View
    {
        return view('agent.direct-deposit', ['agent' => $this->agent($request)]);
    }

    public function storeDirectDeposit(
        Request $request,
        ?FinancialRuleService $rules = null,
        ?AgentLedgerService $agentLedger = null,
    ): RedirectResponse
    {
        $rules ??= app(FinancialRuleService::class);
        $agentLedger ??= app(AgentLedgerService::class);
        $agent = $this->agent($request);
        $validated = $request->validate([
            'phone' => ['required', 'string', Rule::exists('users', 'phone')],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reference' => ['required', 'string', 'min:3', 'max:120'],
            'withdrawable_status' => ['required', Rule::in(['withdrawable', 'locked'])],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $validated['reference'] = trim($validated['reference']);

        $ranLow = false;
        DB::transaction(function () use ($agent, $validated, $rules, $agentLedger, &$ranLow): void {
            $lockedAgent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            if (AgentOrder::query()
                ->where('agent_id', $lockedAgent->id)
                ->where('type', 'deposit')
                ->where('idempotency_key', $validated['idempotency_key'])
                ->exists()) {
                return;
            }
            // A bank reference identifies one incoming payment, so it can only be credited
            // once. Without this an agent could bank one transfer and credit it repeatedly.
            $duplicate = AgentOrder::query()
                ->where('agent_id', $lockedAgent->id)
                ->whereIn('status', ['pending', 'success'])
                ->whereRaw('UPPER(reference) = ?', [strtoupper($validated['reference'])])
                ->first();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'reference' => 'Reference '.$validated['reference'].' was already used on order '
                        .($duplicate->reference ?: '#'.$duplicate->id).' for '
                        .number_format((float) $duplicate->amount, 2).' ETB. Use the reference of the payment you actually received.',
                ]);
            }

            if ($refusal = $rules->agentFloatRefusal($lockedAgent, (float) $validated['amount'])) {
                throw ValidationException::withMessages(['amount' => $refusal]);
            }
            $ranLow = $rules->agentFloatJustRanLow((float) $lockedAgent->balance, (float) $lockedAgent->balance - (float) $validated['amount']);
            $user = User::query()->where('phone', $validated['phone'])->where('role', User::ROLE_PLAYER)->lockForUpdate()->firstOrFail();
            $rules->assertAgentDirectDepositAllowed($user, (float) $validated['amount']);
            $lockedAgent->decrement('balance', $validated['amount']);
            $user->increment('credits', $validated['amount']);
            if ($validated['withdrawable_status'] === 'withdrawable') {
                $user->increment('withdrawable_balance', $validated['amount']);
            }

            $order = AgentOrder::create([
                'user_id' => $user->id,
                'agent_id' => $lockedAgent->id,
                'type' => 'deposit',
                'amount' => $validated['amount'],
                'reference' => $validated['reference'],
                'idempotency_key' => $validated['idempotency_key'],
                'status' => 'success',
                'processed_at' => now(),
                'notes' => 'Direct deposit by agent '.$lockedAgent->nickname.' against reference '.$validated['reference'].'.',
            ]);
            $ledgerKey = 'agent-direct-deposit-'.$order->id;
            $agentLedger->record(
                $lockedAgent,
                $ledgerKey.'-settlement',
                'agent_direct_deposit',
                -$validated['amount'],
                'Direct player deposit settlement',
                'agent_order',
                $order->id,
                ['user_id' => $user->id],
            );
            WalletTransaction::create([
                'ledger_key' => $ledgerKey.'-player',
                'user_id' => $user->id,
                'agent_id' => $lockedAgent->id,
                'type' => 'deposit',
                'amount' => $validated['amount'],
                'status' => 'success',
                // The agent funded this one out of their own float.
                'method' => 'agent_direct',
                'reason' => 'Direct deposit by agent '.$lockedAgent->nickname.' (ref '.$validated['reference'].')',
                'currency' => 'ETB',
                'source_type' => 'agent_order',
                'source_id' => $order->id,
                'metadata' => [
                    'withdrawable_status' => $validated['withdrawable_status'],
                    'reference' => $validated['reference'],
                ],
            ]);
        });

        if ($ranLow) {
            app(\App\Services\AgentNotifier::class)->lowFloat($this->agent($request)->fresh(), $rules->agentMinimumFloat());
        }

        return back()->with('agent_status', 'Deposit completed against reference '.$validated['reference'].' and recorded in the Birr ledger.');
    }

    public function creditTransfer(Request $request): View
    {
        $agent = $this->agent($request);
        $transfers = WalletTransaction::query()
            ->where('agent_id', $agent->id)
            ->where('type', 'agent_transfer')
            ->latest()
            ->paginate(15)
            ->withQueryString();
        $counterpartAgents = Agent::query()->whereIn('id', $transfers->getCollection()->pluck('source_id')->filter()->unique())->get()->keyBy('id');

        return view('agent.credit-transfer', compact('agent', 'transfers', 'counterpartAgents'));
    }

    public function storeCreditTransfer(Request $request, ?FinancialRuleService $rules = null): RedirectResponse
    {
        $rules ??= app(FinancialRuleService::class);
        $agent = $this->agent($request);
        $validated = $request->validate([
            'phone' => ['required', 'string', Rule::exists('agents', 'phone')],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        $ranLow = false;
        DB::transaction(function () use ($agent, $validated, $rules, &$ranLow): void {
            $receiverId = Agent::query()
                ->where('phone', $validated['phone'])
                ->where('active', true)
                ->value('id');
            if (!$receiverId) {
                throw ValidationException::withMessages(['phone' => 'Choose an active agent.']);
            }

            $lockedAgents = Agent::query()
                ->whereIn('id', [$agent->id, $receiverId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $sender = $lockedAgents->get($agent->id);
            $receiver = $lockedAgents->get($receiverId);
            if (!$sender || !$receiver) {
                throw ValidationException::withMessages(['phone' => 'The selected agent is no longer available.']);
            }
            if ($receiver->id === $sender->id) {
                throw ValidationException::withMessages(['phone' => 'Choose another active agent.']);
            }
            $ledgerPrefix = 'agent-transfer-'.$validated['idempotency_key'];
            if (WalletTransaction::query()->where('ledger_key', $ledgerPrefix.'-debit')->exists()) {
                return;
            }
            if ($refusal = $rules->agentFloatRefusal($sender, (float) $validated['amount'])) {
                throw ValidationException::withMessages(['amount' => $refusal]);
            }
            $ranLow = $rules->agentFloatJustRanLow((float) $sender->balance, (float) $sender->balance - (float) $validated['amount']);
            $rules->assertAgentTransferAllowed($sender, (float) $validated['amount']);
            $sender->decrement('balance', $validated['amount']);
            $receiver->increment('balance', $validated['amount']);
            $transferId = $validated['idempotency_key'];

            WalletTransaction::create([
                'ledger_key' => $ledgerPrefix.'-debit',
                'agent_id' => $sender->id,
                'type' => 'agent_transfer',
                'amount' => -$validated['amount'],
                'status' => 'success',
                'method' => 'agent_transfer',
                'reason' => 'Agent credit transfer to '.$receiver->nickname,
                'currency' => 'ETB',
                'source_type' => 'agent',
                'source_id' => $receiver->id,
                'metadata' => ['transfer_id' => $transferId, 'receiver_agent_id' => $receiver->id, 'note' => $validated['note'] ?? null],
            ]);
            WalletTransaction::create([
                'ledger_key' => $ledgerPrefix.'-credit',
                'agent_id' => $receiver->id,
                'type' => 'agent_transfer',
                'amount' => $validated['amount'],
                'status' => 'success',
                'method' => 'agent_transfer',
                'reason' => 'Agent credit transfer from '.$sender->nickname,
                'currency' => 'ETB',
                'source_type' => 'agent',
                'source_id' => $sender->id,
                'metadata' => ['transfer_id' => $transferId, 'sender_agent_id' => $sender->id, 'note' => $validated['note'] ?? null],
            ]);
        });

        if ($ranLow) {
            app(\App\Services\AgentNotifier::class)->lowFloat($this->agent($request)->fresh(), $rules->agentMinimumFloat());
        }

        return back()->with('agent_status', 'Agent credit transferred successfully.');
    }

    public function profile(Request $request): View
    {
        $agent = $this->agent($request);
        $orders = AgentOrder::query()->where('agent_id', $agent->id)->where('type', 'deposit');

        return view('agent.profile', [
            'agent' => $agent,
            'accounts' => $agent->bankAccounts()->with('gateway')->latest()->get(),
            'pendingOrders' => (clone $orders)->where('status', 'pending')->count(),
            'completedOrders' => (clone $orders)->where('status', 'success')->count(),
            'telegramUsername' => app(\App\Services\TelegramBotService::class)->botUsername(),
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'max:30', Rule::unique('agents', 'phone')->ignore($agent)],
            'password' => ['nullable', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }
        $agent->update($validated);

        return back()->with('agent_status', 'Profile updated.');
    }

    private function agent(Request $request): Agent
    {
        return Auth::guard('agent')->user();
    }

    private function availableWithdrawals(Agent $agent): Builder
    {
        return AgentOrder::query()
            ->whereIn('type', ['withdrawal', 'manual_withdrawal'])
            ->where(fn (Builder $query) => $query->where('agent_id', $agent->id)->orWhereNull('agent_id'));
    }

    private function reportRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->startOfDay();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
