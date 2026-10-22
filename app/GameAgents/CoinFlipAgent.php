<?php

namespace App\GameAgents;

use InvalidArgumentException;

class CoinFlipAgent implements GameAgent
{
    public function key(): string { return 'coin-flip'; }

    public function choices(): array
    {
        return [
            ['value' => 'heads', 'label' => 'Heads', 'symbol' => 'H'],
            ['value' => 'tails', 'label' => 'Tails', 'symbol' => 'T'],
        ];
    }

    public function play(string $choice, int $stake): array
    {
        if (!in_array($choice, ['heads', 'tails'], true)) {
            throw new InvalidArgumentException('Choose heads or tails.');
        }

        $outcome = random_int(0, 1) === 1 ? 'heads' : 'tails';
        $won = $choice === $outcome;

        return [
            'choice' => $choice,
            'outcome' => $outcome,
            'symbol' => $outcome === 'heads' ? 'H' : 'T',
            'won' => $won,
            'payout' => $won ? $stake * 2 : 0,
            'headline' => $won ? 'You called it!' : 'The coin had other plans.',
            'message' => 'The coin landed on '.ucfirst($outcome).'.',
        ];
    }
}
