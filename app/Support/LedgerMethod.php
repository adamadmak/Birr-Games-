<?php

namespace App\Support;

/**
 * How a ledger row came to exist, in the words an operator uses.
 *
 * The Method column used to lump every agent action under "Agent", so a deposit the agent
 * funded themselves looked identical to one a player paid into a bank and the agent merely
 * verified. Those are different responsibilities and different money, so they read
 * differently now.
 */
final class LedgerMethod
{
    /** @var array<string, array{0: string, 1: string}> label and tooltip per stored value */
    private const MEANINGS = [
        'agent_direct' => [
            'Agent deposit',
            'The agent credited this player straight from their own float.',
        ],
        'agent_approved' => [
            'Agent approved',
            'The player paid into a bank and submitted the reference; the assigned agent verified it and released the credit.',
        ],
        'administrator' => [
            'Admin approved',
            'An administrator approved or adjusted this entry.',
        ],
        'agent_float' => [
            'Agent float',
            'The agent float side of a settlement, written by the platform alongside the approval. It never moves a player balance on its own.',
        ],
        'agent_transfer' => [
            'Agent transfer',
            'Credit moved between two agents.',
        ],
        'agent' => [
            'Agent',
            'Recorded by an agent.',
        ],
        'system' => [
            'System',
            'Written by the platform itself, with no operator involved.',
        ],
        'migration' => [
            'Opening balance',
            'Imported when the Birr ledger was activated.',
        ],
        'telegram' => [
            'Telegram',
            'Raised through the Telegram bot.',
        ],
        'promotion' => ['Promotion', 'Part of a promotion or bonus.'],
        'shamo' => ['Shamo', 'A Shamo campaign entry or prize.'],
        'game' => ['Game', 'An in-house game stake or payout.'],
        'bigbang' => ['BigBang', 'A provider game stake or payout.'],
        'winhouse' => ['Sportsbook', 'A sportsbook stake or payout.'],
    ];

    public static function label(?string $method): string
    {
        $key = trim((string) $method);

        if ($key === '') {
            return '—';
        }

        return self::MEANINGS[$key][0] ?? str($key)->replace('_', ' ')->headline()->toString();
    }

    /** Tooltip explaining the label, or null when the label speaks for itself. */
    public static function hint(?string $method): ?string
    {
        return self::MEANINGS[trim((string) $method)][1] ?? null;
    }

    /** True for a bookkeeping row that mirrors an approval rather than initiating money. */
    public static function isBookkeeping(?string $method): bool
    {
        return trim((string) $method) === 'agent_float';
    }
}
