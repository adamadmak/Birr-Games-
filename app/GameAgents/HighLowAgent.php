<?php

namespace App\GameAgents;

use InvalidArgumentException;

class HighLowAgent implements GameAgent
{
    public function key(): string { return 'high-low'; }

    public function choices(): array
    {
        return [
            ['value' => 'low', 'label' => 'Low — 1 to 6', 'symbol' => '↓'],
            ['value' => 'high', 'label' => 'High — 8 to 13', 'symbol' => '↑'],
        ];
    }

    public function play(string $choice, int $stake): array
    {
        if (!in_array($choice, ['low', 'high'], true)) {
            throw new InvalidArgumentException('Choose high or low.');
        }

        $card = random_int(1, 13);
        $won = ($choice === 'low' && $card <= 6) || ($choice === 'high' && $card >= 8);
        $label = match ($card) { 1 => 'A', 11 => 'J', 12 => 'Q', 13 => 'K', default => (string) $card };

        return [
            'choice' => $choice,
            'outcome' => (string) $card,
            'symbol' => $label,
            'won' => $won,
            'payout' => $won ? $stake * 2 : 0,
            'headline' => $won ? 'Right side of seven!' : ($card === 7 ? 'Seven belongs to the house.' : 'Wrong side this time.'),
            'message' => "The card was {$label}.",
        ];
    }
}
