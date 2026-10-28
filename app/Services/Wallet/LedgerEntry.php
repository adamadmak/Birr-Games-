<?php

namespace App\Services\Wallet;

/**
 * What a wallet movement writes to the operator's ledger (wallet_transactions)
 * besides the amount: who moved it and why, and which provider row it settles.
 */
final readonly class LedgerEntry
{
    /**
     * @param  string  $method  the path the money came through: 'winhouse', 'bigbang', 'qtech', 'game'
     * @param  string  $reason  one human line for the transaction history
     * @param  string|null  $key  idempotent ledger key (unique in the table), e.g. 'winhouse-bet-<txn>'
     * @param  string|null  $sourceType  the provider table the movement settles, e.g. 'winhouse_session'
     * @param  int|null  $sourceId  that row's id; QTech fills it in after its own row exists
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $method,
        public string $reason,
        public ?string $key = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public array $metadata = [],
    ) {
    }
}
