<?php

namespace App\Services\Wallet;

use App\Models\WalletTransaction;

/**
 * What one wallet movement did, for the caller's own provider row and response.
 */
final readonly class Movement
{
    /**
     * @param  float  $amount  the absolute amount moved
     * @param  float  $withdrawableDebit  on a stake or refund: how much came out of (or went back into) the withdrawable balance; the rest was bonus
     * @param  float  $bonusKept  on a payout: how much stayed play-only because it was the bonus stake coming back
     * @param  float  $credits  the whole wallet after the movement
     * @param  float  $withdrawable  the cash part after the movement
     * @param  WalletTransaction|null  $ledger  the ledger row written, when the amount was more than zero
     */
    public function __construct(
        public float $amount,
        public float $withdrawableDebit,
        public float $bonusKept,
        public float $credits,
        public float $withdrawable,
        public ?WalletTransaction $ledger,
    ) {
    }
}
