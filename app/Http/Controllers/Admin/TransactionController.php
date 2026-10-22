<?php

namespace App\Http\Controllers\Admin;

use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class TransactionController
{
    private const SORTS = [
        'date' => 'wallet_transactions.created_at',
        'amount' => 'wallet_transactions.amount',
        'status' => 'wallet_transactions.status',
        'method' => 'wallet_transactions.method',
        'fee' => 'wallet_transactions.fee',
        'transaction_id' => 'wallet_transactions.transaction_id',
        'type' => 'wallet_transactions.type',
        'reason' => 'wallet_transactions.reason',
        'currency' => 'wallet_transactions.currency',
        'bank_code' => 'wallet_transactions.bank_code',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $sort = array_key_exists($request->string('sort')->toString(), self::SORTS)
            ? $request->string('sort')->toString()
            : 'date';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($request->integer('per_page'), [10, 25, 50], true)
            ? $request->integer('per_page')
            : 10;

        $query = $this->query($filters);

        $transactions = $query
            ->orderBy(self::SORTS[$sort], $direction)
            ->paginate($perPage)
            ->withQueryString();

        $filterOptions = Cache::remember('admin.transactions.filter-options', now()->addMinutes(5), fn () => [
            // Three DISTINCT scans of the whole ledger to populate filter dropdowns. Their
            // contents change rarely, so they are cached instead of scanned per request.
            'types' => Cache::remember('admin:tx-filter-types', now()->addMinutes(15),
                fn () => WalletTransaction::query()->whereNotNull('type')->distinct()->orderBy('type')->pluck('type')),
            'statuses' => Cache::remember('admin:tx-filter-statuses', now()->addMinutes(15),
                fn () => WalletTransaction::query()->whereNotNull('status')->distinct()->orderBy('status')->pluck('status')),
            'reasons' => Cache::remember('admin:tx-filter-reasons', now()->addMinutes(15),
                fn () => WalletTransaction::query()->whereNotNull('reason')->where('reason', '!=', '')->distinct()->orderBy('reason')->limit(100)->pluck('reason')),
        ]);

        return view('admin.transactions.index', [
            'transactions' => $transactions,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            ...$filterOptions,
            'hasFilters' => collect($filters)->contains(fn ($value) => $value !== ''),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'wallet-transactions-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($filters): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Date', 'Transaction ID', 'Member', 'Phone', 'Agent', 'Type', 'Amount ETB', 'Fee ETB', 'Status', 'Method', 'Reason', 'Bank code', 'Provider reference']);

            $this->query($filters)->orderBy('wallet_transactions.id')->chunkById(500, function ($transactions) use ($output): void {
                foreach ($transactions as $transaction) {
                    fputcsv($output, [
                        $transaction->created_at->toIso8601String(), $transaction->transaction_id,
                        $transaction->user?->name, $transaction->user?->phone ?: $transaction->agent?->phone,
                        $transaction->agent?->nickname, $transaction->type, $transaction->amount, $transaction->fee,
                        $transaction->status, $transaction->method, $transaction->reason, $transaction->bank_code,
                        data_get($transaction->metadata, 'provider_reference', data_get($transaction->metadata, 'reference')),
                    ]);
                }
            }, 'wallet_transactions.id', 'id');
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(array $filters): Builder
    {
        return WalletTransaction::query()
            ->with(['user:id,name,phone', 'agent:id,nickname,phone', 'gateway:id,name,code'])
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                $query->where('transaction_id', 'like', "%{$search}%")
                    ->orWhere('method', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('bank_code', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('agent', fn (Builder $agent) => $agent->where('nickname', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
            }))
            ->when($filters['min_amount'] !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($filters) {
                $amount = (float) $filters['min_amount'];
                $query->where('amount', '>=', $amount)->orWhere('amount', '<=', -$amount);
            }))
            ->when($filters['max_amount'] !== '', fn (Builder $query) => $query->whereBetween('amount', [-(float) $filters['max_amount'], (float) $filters['max_amount']]))
            ->when($filters['min_fee'] !== '', fn (Builder $query) => $query->where('fee', '>=', (float) $filters['min_fee']))
            ->when($filters['max_fee'] !== '', fn (Builder $query) => $query->where('fee', '<=', (float) $filters['max_fee']))
            ->when($filters['created_after'], fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['created_before'], fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['reason'], fn (Builder $query, string $reason) => $query->where('reason', $reason))
            ->when($filters['transaction_id'], fn (Builder $query, string $id) => $query->where('transaction_id', 'like', "%{$id}%"))
            ->when($filters['provider_reference'], fn (Builder $query, string $reference) => $query->where(function (Builder $query) use ($reference) {
                $query->where('metadata->provider_reference', 'like', "%{$reference}%")
                    ->orWhere('metadata->reference', 'like', "%{$reference}%");
            }))
            ->when($filters['payment_provider'], fn (Builder $query, string $provider) => $query->where(function (Builder $query) use ($provider) {
                $query->where('method', 'like', "%{$provider}%")
                    ->orWhere('bank_code', 'like', "%{$provider}%")
                    ->orWhereHas('gateway', fn (Builder $gateway) => $gateway->where('name', 'like', "%{$provider}%")->orWhere('code', 'like', "%{$provider}%"));
            }))
            ->when($filters['member_phone'], fn (Builder $query, string $phone) => $query->where(function (Builder $query) use ($phone) {
                $query->whereHas('user', fn (Builder $user) => $user->where('phone', 'like', "%{$phone}%"))
                    ->orWhereHas('agent', fn (Builder $agent) => $agent->where('phone', 'like', "%{$phone}%"));
            }));
    }

    private function filters(Request $request): array
    {
        $date = static fn (string $value): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';

        return [
            'search' => $request->string('search')->trim()->limit(120)->toString(),
            'min_amount' => $request->string('min_amount')->trim()->toString(),
            'max_amount' => $request->string('max_amount')->trim()->toString(),
            'min_fee' => $request->string('min_fee')->trim()->toString(),
            'max_fee' => $request->string('max_fee')->trim()->toString(),
            'created_after' => $date($request->string('created_after')->toString()),
            'created_before' => $date($request->string('created_before')->toString()),
            'type' => $request->string('type')->trim()->toString(),
            'status' => $request->string('status')->trim()->toString(),
            'reason' => $request->string('reason')->trim()->toString(),
            'transaction_id' => $request->string('transaction_id')->trim()->limit(120)->toString(),
            'provider_reference' => $request->string('provider_reference')->trim()->limit(120)->toString(),
            'payment_provider' => $request->string('payment_provider')->trim()->limit(120)->toString(),
            'member_phone' => $request->string('member_phone')->trim()->limit(40)->toString(),
        ];
    }
}
