<?php

namespace App\GameAgents;

class GameAgentManager
{
    private array $agents;

    public function __construct()
    {
        $this->agents = collect([
            new LuckyDiceAgent(),
            new CoinFlipAgent(),
            new HighLowAgent(),
        ])->keyBy(fn (GameAgent $agent) => $agent->key())->all();
    }

    public function for(?string $key): ?GameAgent
    {
        return $key ? ($this->agents[$key] ?? null) : null;
    }

    public function keys(): array
    {
        return array_keys($this->agents);
    }
}
