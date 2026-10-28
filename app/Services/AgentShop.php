<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WinhouseTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The counter: walk-in betting through an agent.
 *
 * A customer with no account tells the agent what to bet and pays cash. The
 * agent places the bet in the sportsbook from their counter account — an
 * ordinary player wallet that only this agent can open, funded from the
 * agent's float — prints the ticket and hands it over. A winner brings the
 * ticket back; the agent pays cash once (shop_payouts makes that once) and
 * moves the counter's balance back into the float when they like.
 *
 * Accounting, so it always adds up: float + counter balance + the cash in the
 * drawer = the starting float + wins not yet paid out.
 *
 * The counter account cannot deposit, withdraw or sign in with a password: it
 * is reached only through the agent portal.
 */
final class AgentShop
{
    public const MEMBER_TYPE = 'shop';

    public function __construct(
        private readonly FinancialRuleService $rules,
        private readonly AgentLedgerService $ledger,
    ) {
    }

    public static function username(Agent $agent): string
    {
        return 'shop_agent_'.$agent->id;
    }

    public static function isCounter(?User $user): bool
    {
        return $user !== null && $user->member_type === self::MEMBER_TYPE;
    }

    /** The agent's counter account, created on first use. */
    public function account(Agent $agent): User
    {
        $existing = User::query()->where('username', self::username($agent))->first();
        if ($existing) {
            return $existing;
        }

        return User::query()->create([
            'name' => $agent->nickname.' counter',
            'first_name' => $agent->nickname,
            'last_name' => 'Counter',
            'username' => self::username($agent),
            'phone' => null,
            'email' => null,
            // Never typed by anyone: the counter is opened from the agent portal only.
            'password' => Str::random(48),
            'role' => User::ROLE_PLAYER,
            'member_type' => self::MEMBER_TYPE,
            'credits' => 0,
            'withdrawable_balance' => 0,
            'contact_confirmed' => true,
            'active' => true,
        ]);
    }

    /** Move float into the counter. Refused by the same float floor as every other agent debit. */
    public function topUp(Agent $agent, float $amount): bool
    {
        $amount = round($amount, 2);
        $counter = $this->account($agent);
        $ranLow = false;

        DB::transaction(function () use ($agent, $counter, $amount, &$ranLow): void {
            $lockedAgent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $user = User::query()->lockForUpdate()->findOrFail($counter->id);

            if ($refusal = $this->rules->agentFloatRefusal($lockedAgent, $amount)) {
                throw ValidationException::withMessages(['amount' => $refusal]);
            }
            $ranLow = $this->rules->agentFloatJustRanLow((float) $lockedAgent->balance, (float) $lockedAgent->balance - $amount);

            $key = (string) Str::uuid();
            $lockedAgent->decrement('balance', $amount);
            $this->ledger->record($lockedAgent, 'agent-shop-topup-'.$key, 'agent_shop_topup', -$amount, 'Float moved to the counter account', 'user', $user->id);

            // Both buckets: it is the agent's own money, staked like cash, so a
            // counter win is real money and BonusPlay holds none of it back.
            $user->increment('credits', $amount);
            $user->increment('withdrawable_balance', $amount);
            WalletTransaction::create([
                'ledger_key' => 'shop-topup-'.$key,
                'user_id' => $user->id,
                'agent_id' => $lockedAgent->id,
                'type' => 'shop_transfer',
                'amount' => $amount,
                'status' => 'success',
                'method' => 'agent_shop',
                'reason' => 'Counter topped up from '.$lockedAgent->nickname.'\'s float',
                'currency' => 'ETB',
                'source_type' => 'agent',
                'source_id' => $lockedAgent->id,
            ]);
        });

        return $ranLow;
    }

    /** Move the counter's balance (or part of it) back into the float. */
    public function giveBack(Agent $agent, float $amount): void
    {
        $amount = round($amount, 2);
        $counter = $this->account($agent);

        DB::transaction(function () use ($agent, $counter, $amount): void {
            $lockedAgent = Agent::query()->lockForUpdate()->findOrFail($agent->id);
            $user = User::query()->lockForUpdate()->findOrFail($counter->id);

            if ((float) $user->credits < $amount) {
                throw ValidationException::withMessages(['amount' => 'The counter holds '.number_format((float) $user->credits, 2).' ETB.']);
            }

            $key = (string) Str::uuid();
            $user->forceFill([
                'credits' => round((float) $user->credits - $amount, 2),
                'withdrawable_balance' => max(0, round((float) $user->withdrawable_balance - $amount, 2)),
            ])->save();
            WalletTransaction::create([
                'ledger_key' => 'shop-return-'.$key,
                'user_id' => $user->id,
                'agent_id' => $lockedAgent->id,
                'type' => 'shop_transfer',
                'amount' => -$amount,
                'status' => 'success',
                'method' => 'agent_shop',
                'reason' => 'Counter balance returned to '.$lockedAgent->nickname.'\'s float',
                'currency' => 'ETB',
                'source_type' => 'agent',
                'source_id' => $lockedAgent->id,
            ]);

            $lockedAgent->increment('balance', $amount);
            $this->ledger->record($lockedAgent, 'agent-shop-return-'.$key, 'agent_shop_return', $amount, 'Counter balance returned to the float', 'user', $user->id);
        });
    }

    /**
     * What the wallet paid this counter for a ticket — the amount the agent owes
     * the walk-in customer. Read from our own records, so it holds when WinHouse
     * cannot be reached.
     */
    public function winnings(Agent $agent, string $ticketId): float
    {
        $counter = User::query()->where('username', self::username($agent))->first();
        if (! $counter || ! Schema::hasTable('winhouse_transactions')) {
            return 0.0;
        }

        return round((float) WinhouseTransaction::query()
            ->where('user_id', $counter->id)
            ->where('ticket_id', $ticketId)
            ->where('action', 'win')
            ->sum('amount'), 2);
    }

    /** Record that the customer was paid. Once per ticket, ever. */
    public function payOut(Agent $agent, string $ticketId): float
    {
        $amount = $this->winnings($agent, $ticketId);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['ticket' => 'This ticket has no winnings on the counter account to pay out.']);
        }

        self::ensurePayoutTable();
        DB::transaction(function () use ($agent, $ticketId, $amount): void {
            if (DB::table('shop_payouts')->where('ticket_id', $ticketId)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['ticket' => 'This ticket was already paid out.']);
            }
            DB::table('shop_payouts')->insert([
                'ticket_id' => $ticketId,
                'agent_id' => $agent->id,
                'amount' => $amount,
                'paid_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $amount;
    }

    /** @return array<string, string> ticket id => paid-at, for the tickets given */
    public static function paidOut(array $ticketIds): array
    {
        if ($ticketIds === [] || ! Schema::hasTable('shop_payouts')) {
            return [];
        }

        return DB::table('shop_payouts')->whereIn('ticket_id', $ticketIds)->pluck('paid_at', 'ticket_id')->all();
    }

    /** Created on first use, like deposit_proofs: migrations are applied by hand on this host. */
    public static function ensurePayoutTable(): void
    {
        if (Schema::hasTable('shop_payouts')) {
            return;
        }

        Schema::create('shop_payouts', function ($table) {
            $table->id();
            $table->string('ticket_id', 80)->unique();
            $table->unsignedBigInteger('agent_id')->index();
            $table->decimal('amount', 16, 2);
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }
}
