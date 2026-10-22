<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\WalletTransaction;

final class AgentLedgerService
{
    public function record(
        Agent $agent,
        string $key,
        string $type,
        float|string $amount,
        string $reason,
        ?string $sourceType = null,
        ?int $sourceId = null,
        array $metadata = [],
    ): WalletTransaction {
        return WalletTransaction::query()->firstOrCreate(
            ['ledger_key' => $key],
            [
                'agent_id' => $agent->id,
                'type' => $type,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'fee' => 0,
                'status' => 'success',
                // Every row this service writes is the agent float side of an approval.
                'method' => 'agent_float',
                'reason' => $reason,
                'currency' => 'ETB',
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => $metadata,
            ]
        );
    }
}
