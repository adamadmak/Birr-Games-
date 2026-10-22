<?php

namespace App\GameAgents;

interface GameAgent
{
    public function key(): string;

    public function choices(): array;

    public function play(string $choice, int $stake): array;
}
