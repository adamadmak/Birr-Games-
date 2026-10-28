<?php

namespace App\Services\Wallet;

use DomainException;

/** A stake larger than the whole wallet. Nothing has moved when this is thrown. */
final class InsufficientFunds extends DomainException
{
    public function __construct(public readonly float $requested, public readonly float $available)
    {
        parent::__construct('Insufficient balance.');
    }
}
