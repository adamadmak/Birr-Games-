<?php

namespace App\Http\Controllers\Admin;

use App\Models\AgentOrder;
use App\Models\TelegramLoginToken;
use App\Models\TelegramSubscriber;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\MemberCsvImporter;
use App\Services\FinancialRuleService;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\Username;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberController
{
    private const SORTS = ['first_name', 'last_name', 'username', 'phone', 'credits', 'withdrawable_balance', 'contact_confirmed', 'active', 'created_at'];

    public function index(Request $request): View
    {
        $sort = in_array($request->string('sort')->toString(), self::SORTS, true) ? $request->string('sort')->toString() : 'created_at';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $duplicates = $this->duplicatePhoneAccounts();
        $onlyDuplicates = $request->boolean('duplicates');
        $archived = $request->boolean('archived');

        $members = $this->memberQuery($request)
            ->when($archived, fn (Builder $query) => $query->onlyTrashed())
            ->when($onlyDuplicates, fn (Builder $query) => $query->whereIn('id', $duplicates->flatten()->all()))
            ->withCount('rounds')
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->withQueryString();

        return view('admin.members.index', [
            'members' => $members,
            'search' => trim($request->string('search')->toString()),
            'status' => $request->string('status')->toString(),
            'confirmed' => $request->string('confirmed')->toString(),
            'sort' => $sort,
            'direction' => $direction,
            'duplicateGroups' => $duplicates->count(),
            'duplicateAccounts' => $duplicates->flatten()->count(),
            'onlyDuplicates' => $onlyDuplicates,
            'archived' => $archived,
            'archivedCount' => User::onlyTrashed()->where('role', User::ROLE_PLAYER)->count(),
            'canDelete' => (bool) $request->user()?->hasAdminPermission('members.delete'),
        ]);
    }

    /**
     * Accounts that share one phone number written in different formats (0912…, 912…,
     * 251912…). Cached because it reads every member phone number.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, int>>
     */
    private function duplicatePhoneAccounts()
    {
        return Cache::remember('admin.members.duplicate-phones', now()->addMinutes(5), function () {
            return User::query()
                ->where('role', User::ROLE_PLAYER)
                ->whereNotNull('phone')
                ->orderBy('id')
                ->pluck('phone', 'id')
                ->groupBy(fn (string $phone, int $id) => PhoneNumber::normalize($phone) ?: 'unreadable-'.$id, true)
                ->reject(fn ($group, string $key) => str_starts_with($key, 'unreadable-') || $group->count() < 2)
                ->map(fn ($group) => $group->keys());
        });
    }

    public function show(Request $request, User $user): View
    {
        $this->ensurePlayer($user);
        $tab = in_array($request->string('tab')->toString(), ['details', 'gaming', 'wallet', 'transactions'], true) ? $request->string('tab')->toString() : 'details';
        $walletHistory = $user->walletTransactions()->latest()->paginate(12, ['*'], 'wallet_page')->withQueryString();
        $roundHistory = $user->rounds()->latest()->paginate(12, ['*'], 'round_page')->withQueryString();
        $successfulDeposits = (int) $user->walletTransactions()->where('type', 'deposit')->where('status', 'success')->sum('amount');
        $successfulWithdrawals = abs((int) $user->walletTransactions()->where('type', 'withdrawal')->where('status', 'success')->sum('amount'));

        return view('admin.members.show', [
            'member' => $user->loadCount(['rounds', 'bonusClaims']),
            'tab' => $tab,
            'walletHistory' => $walletHistory,
            'roundHistory' => $roundHistory,
            'successfulDeposits' => $successfulDeposits,
            'successfulWithdrawals' => $successfulWithdrawals,
            'depositCount' => $user->walletTransactions()->where('type', 'deposit')->where('status', 'success')->count(),
            'withdrawalCount' => $user->walletTransactions()->where('type', 'withdrawal')->where('status', 'success')->count(),
            'bonusCredits' => (int) $user->bonusClaims()->sum('credits'),
            'gaming' => $this->gamingProfile($user),
            'canDelete' => (bool) $request->user()?->hasAdminPermission('members.delete'),
            'pendingOrders' => AgentOrder::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['pending', 'processing', 'payout_submitted'])
                ->with(['agent:id,nickname', 'gateway:id,name'])
                ->latest()
                ->limit(6)
                ->get(),
        ]);
    }

    /**
     * Everything the house needs to hold a player accountable: how much they staked,
     * how much came back, and which games moved the money.
     */
    private function gamingProfile(User $user): array
    {
        $totals = $user->rounds()
            ->selectRaw('COUNT(*) AS bet_count')
            ->selectRaw('COALESCE(SUM(stake), 0) AS stake_total')
            ->selectRaw('COALESCE(SUM(payout), 0) AS payout_total')
            ->selectRaw('COALESCE(MAX(payout), 0) AS biggest_win')
            ->selectRaw('COALESCE(MAX(stake), 0) AS biggest_stake')
            ->selectRaw('MIN(created_at) AS first_bet_at')
            ->selectRaw('MAX(created_at) AS last_bet_at')
            ->first();

        $stake = (float) ($totals->stake_total ?? 0);
        $payout = (float) ($totals->payout_total ?? 0);
        $betCount = (int) ($totals->bet_count ?? 0);

        $byGame = $user->rounds()
            ->selectRaw('game, COUNT(*) AS bet_count')
            ->selectRaw('COALESCE(SUM(stake), 0) AS stake_total')
            ->selectRaw('COALESCE(SUM(payout), 0) AS payout_total')
            ->selectRaw('MAX(created_at) AS last_bet_at')
            ->groupBy('game')
            ->orderByRaw('stake_total DESC')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'game' => str($row->game)->replace('-', ' ')->title()->toString(),
                'bet_count' => (int) $row->bet_count,
                'stake_total' => (float) $row->stake_total,
                'payout_total' => (float) $row->payout_total,
                'house_ggr' => (float) $row->stake_total - (float) $row->payout_total,
                'rtp' => (float) $row->stake_total > 0 ? ((float) $row->payout_total / (float) $row->stake_total) * 100 : null,
                'last_bet_at' => $row->last_bet_at,
            ])
            ->all();

        $wins = $user->rounds()->whereColumn('payout', '>', 'stake')->count();

        return [
            'bet_count' => $betCount,
            'stake_total' => $stake,
            'payout_total' => $payout,
            'player_net' => $payout - $stake,
            'house_ggr' => $stake - $payout,
            'rtp' => $stake > 0 ? ($payout / $stake) * 100 : null,
            'average_stake' => $betCount > 0 ? $stake / $betCount : 0.0,
            'biggest_win' => (float) ($totals->biggest_win ?? 0),
            'biggest_stake' => (float) ($totals->biggest_stake ?? 0),
            'wins' => $wins,
            'losses' => max(0, $betCount - $wins),
            'win_rate' => $betCount > 0 ? ($wins / $betCount) * 100 : null,
            'first_bet_at' => $totals->first_bet_at ?? null,
            'last_bet_at' => $totals->last_bet_at ?? null,
            'by_game' => $byGame,
        ];
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlayer($user);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'username' => ['nullable', 'string', 'max:32', Rule::unique('users', 'username')->ignore($user)],
            // A member is identified by their phone number, so it can never be cleared.
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user)],
            'withdrawal_cap' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'transfer_cap' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'deposit_cap' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'contact_confirmed' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);
        $validated['last_name'] = $validated['last_name'] ?? '';
        $validated['name'] = trim($validated['first_name'].' '.$validated['last_name']);
        $validated['phone'] = PhoneNumber::normalize($validated['phone']) ?? $validated['phone'];
        $validated['username'] = filled($validated['username'] ?? null)
            ? Username::unique([$validated['username']], $validated['phone'], $user->id)
            : ($user->username ?: Username::unique([$validated['name']], $validated['phone'], $user->id));
        $validated['contact_confirmed'] = $request->boolean('contact_confirmed');
        $validated['active'] = $request->boolean('active');

        // A different spelling of a number another member already holds is the exact way
        // duplicate accounts were created, so it is rejected here.
        $owner = User::withTrashed()
            ->whereIn('phone', PhoneNumber::candidates($validated['phone']))
            ->whereKeyNot($user->id)
            ->first();

        if ($owner) {
            throw ValidationException::withMessages([
                'phone' => 'Member #'.$owner->id.' ('.$owner->name.') already uses that phone number'
                    .($owner->trashed() ? ' on an archived account.' : '.'),
            ]);
        }

        $user->update($validated);
        Cache::forget('admin.members.duplicate-phones');

        return back()->with('admin_status', "{$user->name}'s account was updated.");
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlayer($user);
        $user->update(['active' => $request->boolean('active')]);
        return back()->with('admin_status', "{$user->name}'s wallet status was updated.");
    }

    public function deposit(Request $request, User $user, ?FinancialRuleService $rules = null): RedirectResponse
    {
        $rules ??= app(FinancialRuleService::class);
        $this->ensurePlayer($user);
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'withdrawable_status' => ['required', Rule::in(['withdrawable', 'locked'])],
            'idempotency_key' => ['required', 'uuid'],
        ]);

        DB::transaction(function () use ($request, $user, $validated, $rules): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $ledgerKey = 'admin-member-deposit-'.$validated['idempotency_key'];
            if (WalletTransaction::query()->where('ledger_key', $ledgerKey)->exists()) {
                return;
            }
            $rules->assertDepositAllowed($locked, (float) $validated['amount']);
            $locked->increment('credits', $validated['amount']);
            if ($validated['withdrawable_status'] === 'withdrawable') {
                $locked->increment('withdrawable_balance', $validated['amount']);
            }
            WalletTransaction::create([
                'ledger_key' => $ledgerKey,
                'user_id' => $locked->id,
                'type' => 'deposit',
                'amount' => $validated['amount'],
                'status' => 'success',
                'method' => 'administrator',
                'reason' => 'Manual member deposit by '.$request->user()->name,
                'currency' => 'ETB',
                'source_type' => 'user',
                'source_id' => $locked->id,
                'metadata' => ['withdrawable_status' => $validated['withdrawable_status']],
            ]);
        });

        return back()->with('admin_status', "Deposit added to {$user->name}'s wallet.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlayer($user);
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $user->update(['password' => $validated['password']]);
        return back()->with('admin_status', "{$user->name}'s password was reset.");
    }

    /**
     * Archive a member: access is revoked and the account disappears from the directory,
     * while every wallet transaction, order and round stays attributable for audits and
     * disputes. Restorable, and permanently removable once it holds no history.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlayer($user);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['member' => 'You cannot archive your own account.']);
        }

        $balance = round((float) $user->credits, 2);
        if ($balance > 0.0) {
            throw ValidationException::withMessages([
                'member' => 'This wallet still holds '.Money::format($balance, 2)
                    .'. Pay it out or zero the balance before archiving the member.',
            ]);
        }

        $openOrders = AgentOrder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'processing', 'payout_submitted'])
            ->count();

        if ($openOrders > 0) {
            throw ValidationException::withMessages([
                'member' => 'This member has '.$openOrders.' open deposit or withdrawal order(s). Settle or reject them first.',
            ]);
        }

        DB::transaction(function () use ($user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);

            // Break every route back into the account: the bot link, live login links and
            // the wallet toggle.
            TelegramSubscriber::query()->where('user_id', $locked->id)->update(['user_id' => null]);
            TelegramLoginToken::query()->where('user_id', $locked->id)->where('expires_at', '>', now())->update(['used_at' => now(), 'expires_at' => now()]);
            $locked->forceFill(['active' => false])->save();
            $locked->delete();
        });

        Cache::forget('admin.members.duplicate-phones');
        Log::warning('A member account was archived by an administrator.', [
            'member_user_id' => $user->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return redirect()->route('admin.members.index')
            ->with('admin_status', "{$user->name} was archived. Their history is retained and the account can be restored.");
    }

    public function restore(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlayer($user);
        abort_unless($user->trashed(), 404);

        $user->restore();
        $user->forceFill(['active' => true])->save();
        Cache::forget('admin.members.duplicate-phones');

        Log::warning('An archived member account was restored by an administrator.', [
            'member_user_id' => $user->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return back()->with('admin_status', "{$user->name} was restored and their wallet re-enabled.");
    }

    /**
     * Permanent removal, allowed only for accounts that never held money or gameplay —
     * test records and mistakes. Anything with history stays archived instead.
     */
    public function purge(Request $request, User $user): RedirectResponse
    {
        $this->ensurePlayer($user);
        abort_unless($user->trashed(), 404);

        $history = [
            'wallet transactions' => WalletTransaction::query()->where('user_id', $user->id)->count(),
            'agent orders' => AgentOrder::query()->where('user_id', $user->id)->count(),
            'game rounds' => $user->rounds()->count(),
        ];
        $blocking = collect($history)->filter()->map(fn (int $count, string $label) => $count.' '.$label);

        if ($blocking->isNotEmpty()) {
            throw ValidationException::withMessages([
                'member' => 'This account cannot be deleted permanently because it holds '
                    .$blocking->implode(', ').'. It stays archived so the records remain auditable.',
            ]);
        }

        if (round((float) $user->credits, 2) !== 0.0) {
            throw ValidationException::withMessages(['member' => 'This account still holds a balance.']);
        }

        $name = $user->name;
        $memberId = $user->id;
        $user->forceDelete();
        Cache::forget('admin.members.duplicate-phones');

        Log::warning('An empty member account was permanently deleted by an administrator.', [
            'member_user_id' => $memberId,
            'admin_user_id' => $request->user()->id,
        ]);

        return back()->with('admin_status', "{$name} was permanently deleted. The phone number and username are free again.");
    }

    /**
     * Every matching member as CSV.
     *
     * Read in batches rather than with a single ->get(). The whole point of a
     * streamed download is that the response does not have to be held in memory,
     * and materialising the entire result set first gave that away: on a member
     * table of any size the export was the heaviest thing the panel could do, and
     * it grew with the business until it exhausted the worker.
     *
     * Ordered by id, which is what chunkById can page on safely. The previous
     * alphabetical order cannot be kept while chunking, and a CSV is opened in a
     * spreadsheet where sorting is one click.
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->memberQuery($request);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['First Name', 'Last Name', 'Username', 'Phone Number', 'Wallet Balance (ETB)', 'Withdrawable Balance (ETB)', 'Contact Confirmed', 'Wallet Status']);

            $query->orderBy('users.id')->chunkById(500, function ($members) use ($stream): void {
                foreach ($members as $member) {
                    fputcsv($stream, [$member->first_name, $member->last_name, $member->username, $member->phone, $member->credits, $member->withdrawable_balance, $member->contact_confirmed ? 'Yes' : 'No', $member->active ? 'Active' : 'Inactive']);
                }
            }, 'users.id', 'id');

            fclose($stream);
        }, 'members-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function import(Request $request, MemberCsvImporter $importer): RedirectResponse
    {
        if ($request->hasFile('members_file') && ! $request->file('members_file')->isValid()) {
            $error = $request->file('members_file')->getError();
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed file size (5MB).',
                UPLOAD_ERR_PARTIAL => 'The CSV upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary folder for upload.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write uploaded file to disk.',
                default => 'The CSV file failed to upload. Please select a valid CSV file under 5MB.',
            };
            return back()->withErrors(['members_file' => $message], 'memberImport')->withInput();
        }

        $validated = $request->validateWithBag('memberImport', [
            'members_file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        try {
            $result = $importer->import($validated['members_file']);
        } catch (RuntimeException $exception) {
            return back()
                ->withErrors(['members_file' => $exception->getMessage()], 'memberImport')
                ->withInput();
        }

        return back()->with(
            'admin_status',
            "Imported {$result['total']} members: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} summary rows skipped."
        );
    }

    private function memberQuery(Request $request): Builder
    {
        $search = trim($request->string('search')->toString());
        $phoneCandidates = PhoneNumber::candidates($search);

        return User::query()->where('role', User::ROLE_PLAYER)
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $nested) => $nested
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', '%'.ltrim($search, '@').'%')
                ->orWhere('phone', 'like', "%{$search}%")
                ->when($phoneCandidates !== [], fn (Builder $phones) => $phones->orWhereIn('phone', $phoneCandidates))))
            ->when($request->string('status')->toString() !== '', fn (Builder $query) => $query->where('active', $request->string('status')->toString() === 'active'))
            ->when($request->string('confirmed')->toString() !== '', fn (Builder $query) => $query->where('contact_confirmed', $request->string('confirmed')->toString() === 'yes'));
    }

    private function ensurePlayer(User $user): void
    {
        abort_unless($user->role === User::ROLE_PLAYER, 404);
    }
}
