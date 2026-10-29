<?php

namespace App\Support;

/**
 * Whether each pick on a sportsbook ticket won.
 *
 * WinHouse settles a ticket as a whole and sends no result per selection
 * (docs/agent.md 4.7: "Won/lost is per slip"), but it does send each match's
 * status and score. From those this works out the common full-time markets:
 * match result, double chance, draw no bet, over/under goals and both teams
 * to score. Anything else (halves, handicaps, corners, cards, player props)
 * stays unknown rather than guessed.
 *
 * The ticket's own result fills the gaps it can prove: a won ticket won every
 * pick (a single or an accumulator; not a system bet), a single shares its
 * ticket's result, and a lost accumulator whose other picks all won lost on
 * the one that is left.
 */
final class BetPick
{
    public const WON = 'won';
    public const LOST = 'lost';
    public const VOID = 'void';

    /**
     * @param  array<string, mixed>  $ticket  a normalised ticket (WinhouseTicketsClient)
     * @return list<?string>  one entry per selection: won, lost, void or null (not known yet)
     */
    public static function results(array $ticket): array
    {
        $selections = array_values($ticket['selections'] ?? []);
        $results = array_map(fn (array $s) => self::fromScore($s), $selections);

        $status = strtolower((string) ($ticket['status'] ?? ''));
        $type = strtolower((string) ($ticket['type'] ?? ''));
        $system = str_contains($type, 'system');

        if (count($selections) === 1 && in_array($status, [self::WON, self::LOST], true)) {
            return [$status];
        }
        if ($status === self::WON && ! $system) {
            return array_map(fn (?string $r) => $r === self::VOID ? self::VOID : self::WON, $results);
        }
        if ($status === self::LOST && ! $system) {
            $unknown = array_keys(array_filter($results, fn (?string $r) => $r === null));
            $anyLost = in_array(self::LOST, $results, true);
            if (! $anyLost && count($unknown) === 1) {
                $results[$unknown[0]] = self::LOST;
            }
        }

        return $results;
    }

    /** @param  array<string, mixed>  $s  one normalised selection */
    public static function fromScore(array $s): ?string
    {
        if (! in_array(strtolower((string) ($s['status'] ?? '')), ['ended', 'finished', 'ft', 'closed', 'complete', 'completed'], true)) {
            return null;
        }
        if (! preg_match('/^\s*(\d+)\s*[:\-–]\s*(\d+)/u', (string) ($s['score'] ?? ''), $m)) {
            return null;
        }
        [$home, $away] = [(int) $m[1], (int) $m[2]];

        $market = strtolower(trim((string) ($s['market'] ?? '')));
        // Markets the full-time score cannot settle.
        if ($market === '' || preg_match('/half|1st|2nd|period|quarter|set|corner|card|booking|handicap|asian|player|scorer|minute|interval|exact|correct|odd\/even/', $market)) {
            return null;
        }

        $pick = self::pickCode((string) ($s['pick'] ?? ''), (string) ($s['home'] ?? ''), (string) ($s['away'] ?? ''));
        $outcome = $home > $away ? '1' : ($home < $away ? '2' : 'X');

        if (preg_match('/double chance/', $market)) {
            return in_array($pick, ['1X', 'X2', '12'], true) ? self::verdict(str_contains($pick, $outcome)) : null;
        }
        if (preg_match('/draw no bet|dnb/', $market)) {
            if (! in_array($pick, ['1', '2'], true)) {
                return null;
            }

            return $outcome === 'X' ? self::VOID : self::verdict($pick === $outcome);
        }
        if (preg_match('/both teams|btts|gg\/ng|goal\/no goal/', $market)) {
            $yes = preg_match('/^(yes|gg|y)$/i', trim((string) ($s['pick'] ?? '')));
            $no = preg_match('/^(no|ng|n)$/i', trim((string) ($s['pick'] ?? '')));
            if (! $yes && ! $no) {
                return null;
            }

            return self::verdict(($home > 0 && $away > 0) === (bool) $yes);
        }
        if (preg_match('/total|over|under|goals/', $market) && ! preg_match('/team|home|away/', $market)) {
            $text = strtolower(($s['pick'] ?? '').' '.$market);
            if (! preg_match('/(over|under|\bo\b|\bu\b)\s*\(?\s*(\d+(?:[.,]\d+)?)/', $text, $line)) {
                return null;
            }
            $goals = $home + $away;
            $limit = (float) str_replace(',', '.', $line[2]);
            if ($goals == $limit) {
                return self::VOID;
            }

            return self::verdict(in_array($line[1], ['over', 'o'], true) ? $goals > $limit : $goals < $limit);
        }
        if (preg_match('/1x2|result|winner|full.?time|match|3.?way/', $market) && in_array($pick, ['1', 'X', '2'], true)) {
            return self::verdict($pick === $outcome);
        }

        return null;
    }

    /** "1", "X", "2", "1X", "X2", "12", from codes or the team names WinHouse sometimes sends. */
    private static function pickCode(string $pick, string $home, string $away): string
    {
        $p = trim($pick);
        $lower = mb_strtolower($p);
        $h = mb_strtolower(trim($home));
        $a = mb_strtolower(trim($away));

        return match (true) {
            in_array(strtoupper($p), ['1', 'X', '2', '1X', 'X2', '12'], true) => strtoupper($p),
            $lower === 'draw', $lower === 'tie' => 'X',
            $lower === 'home' || ($h !== '' && $lower === $h) => '1',
            $lower === 'away' || ($a !== '' && $lower === $a) => '2',
            $h !== '' && $lower === $h.' or draw' => '1X',
            $a !== '' && $lower === 'draw or '.$a => 'X2',
            $h !== '' && $a !== '' && $lower === $h.' or '.$a => '12',
            default => strtoupper($p),
        };
    }

    private static function verdict(bool $won): string
    {
        return $won ? self::WON : self::LOST;
    }
}
