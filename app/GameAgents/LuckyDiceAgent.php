<?php

namespace App\GameAgents;

use InvalidArgumentException;

class LuckyDiceAgent implements GameAgent
{
    public function key(): string { return 'lucky-dice'; }

    public function choices(): array
    {
        return collect(range(1, 6))->map(fn ($number) => [
            'value' => (string) $number,
            'label' => "Number {$number}",
            'symbol' => ['⚀','⚁','⚂','⚃','⚄','⚅'][$number - 1],
        ])->all();
    }

    public function play(string $choice, int $stake): array
    {
        if (!in_array($choice, array_map('strval', range(1, 6)), true)) {
            throw new InvalidArgumentException('Choose a number from 1 to 6.');
        }

        $roll = random_int(1, 6);
        $won = (int) $choice === $roll;

        return [
            'choice' => $choice,
            'outcome' => (string) $roll,
            'symbol' => ['⚀','⚁','⚂','⚃','⚄','⚅'][$roll - 1],
            'won' => $won,
            'payout' => $won ? $stake * 5 : 0,
            'headline' => $won ? 'Perfect call!' : 'Almost. Roll again.',
            'message' => $won ? 'Your number matched the die.' : "The die landed on {$roll}.",
        ];
    }
}
