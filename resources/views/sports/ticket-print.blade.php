@php
    /*
     * A sportsbook receipt, laid out for an 80 mm roll but readable on A4.
     * Standalone on purpose: the admin shell, its fonts and its dark theme
     * have no place on a slip of thermal paper. ?auto=1 opens the print dialog.
     *
     * Shared by the back office (Admin\SportsTicketController) and the agent
     * portal (AgentTicketController), so the page never names a route or a
     * guard itself: each controller passes $backUrl and $printedBy.
     */
    $backUrl = $backUrl ?? null;
    $printedBy = $printedBy ?? null;
    $status = $data['status'] ?? $local->status ?? 'open';
    if ($status === 'open' && $local) {
        $status = $local->status;
    }
    $stake = ($data['stake'] ?? 0) > 0 ? $data['stake'] : ($local->stake ?? 0);
    $paid = ($data['paid'] ?? 0) > 0 ? $data['paid'] : ($local->paid ?? 0);
    $currency = $data['currency'] ?? $local->currency ?? \App\Support\Winhouse::currency();
    $placedAt = $data['placed_at'] ?? $local?->placed_at;
    $selections = $data['selections'] ?? [];
    $money = fn ($v) => number_format((float) $v, 2).' '.$currency;
    $phone = $player?->phone ? \App\Support\PhoneNumber::format($player->phone) : null;
    $maskedPhone = $phone ? preg_replace('/\d(?=(?:\D*\d){4})/', '•', $phone) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Ticket {{ $ticketId }} — Vamoseone</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #e9edf4; color: #111; font: 13px/1.4 "Segoe UI", Roboto, Arial, sans-serif; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 10px; justify-content: center; padding: 12px; background: #0f3fa6; }
        .toolbar a, .toolbar button { min-height: 40px; padding: 0 18px; border: 0; border-radius: 6px; background: #fff; color: #0b2a7a; font: inherit; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
        .toolbar .primary { background: #2f7bff; color: #fff; }
        .slip { width: 80mm; margin: 18px auto 40px; padding: 6mm 5mm 8mm; background: #fff; box-shadow: 0 12px 40px rgba(4, 12, 40, .18); }
        .brand { text-align: center; }
        .brand img { width: 46mm; height: auto; }
        .brand small { display: block; margin-top: 2px; font-size: 10px; letter-spacing: .18em; text-transform: uppercase; color: #333; }
        .rule { border: 0; border-top: 1px dashed #777; margin: 8px 0; }
        .ticket-no { text-align: center; margin: 6px 0 2px; }
        .ticket-no small { display: block; font-size: 10px; letter-spacing: .14em; text-transform: uppercase; color: #555; }
        .ticket-no strong { display: block; font: 700 20px/1.2 "Consolas", "Courier New", monospace; letter-spacing: .06em; word-break: break-all; }
        .barcode { margin: 6px auto 0; height: 34px; width: 62mm; background: repeating-linear-gradient(90deg, #000 0 1px, transparent 1px 2px, #000 2px 4px, transparent 4px 5px, #000 5px 6px, transparent 6px 8px); }
        .status { display: inline-block; margin-top: 6px; padding: 2px 10px; border: 1.5px solid #000; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: 3px 10px; margin: 0; font-size: 12px; }
        dt { color: #555; }
        dd { margin: 0; text-align: right; font-weight: 600; }
        .picks { list-style: none; margin: 0; padding: 0; }
        .picks li { padding: 6px 0; border-bottom: 1px dotted #bbb; }
        .picks li:last-child { border-bottom: 0; }
        .picks .league { display: block; font-size: 10px; color: #666; text-transform: uppercase; letter-spacing: .06em; }
        .picks .event { display: block; font-weight: 700; }
        .picks .live { display: inline-block; margin-left: 4px; padding: 0 5px; border: 1px solid #000; border-radius: 3px; font-size: 9px; font-weight: 800; letter-spacing: .1em; vertical-align: 1px; }
        .picks .line { display: flex; justify-content: space-between; gap: 8px; }
        .picks .line em { font-style: normal; font-weight: 800; font-family: "Consolas", "Courier New", monospace; white-space: nowrap; }
        .picks .meta { display: flex; justify-content: space-between; font-size: 10.5px; color: #555; }
        .totals { font-size: 13px; }
        .totals dd { font-family: "Consolas", "Courier New", monospace; font-size: 14px; }
        .totals dt.big, .totals dd.big { font-size: 16px; font-weight: 800; color: #000; }
        .foot { margin-top: 10px; text-align: center; font-size: 10.5px; color: #444; }
        .foot p { margin: 3px 0; }
        .muted-note { margin: 6px 0 0; padding: 6px; border: 1px dashed #999; font-size: 10.5px; color: #444; text-align: center; }
        @media print {
            @page { size: 80mm auto; margin: 3mm; }
            html, body { background: #fff; }
            .toolbar { display: none; }
            .slip { width: auto; margin: 0; padding: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button class="primary" type="button" onclick="window.print()">🖨 Print</button>
        @if($backUrl)<a href="{{ $backUrl }}">Back to tickets</a>@endif
    </div>

    <article class="slip">
        <header class="brand">
            <img src="{{ asset('logo.png') }}" alt="Vamoseone">
            <small>Betting &amp; Casino</small>
        </header>
        <hr class="rule">

        <div class="ticket-no">
            <small>Ticket</small>
            <strong>{{ $ticketId }}</strong>
            <div class="barcode" aria-hidden="true"></div>
            <span class="status">{{ str_replace('_', ' ', $status) }}</span>
        </div>
        <hr class="rule">

        <dl>
            <dt>Placed</dt><dd>{{ $placedAt?->format('d/m/Y H:i') ?? '—' }}</dd>
            @if($data && $data['type'] !== '')<dt>Type</dt><dd>{{ ucfirst($data['type']) }}{{ count($selections) > 1 ? ' · '.count($selections).' picks' : '' }}</dd>@endif
            <dt>Player</dt><dd>{{ $player?->name ?? ($data['player'] ?? '—') }}</dd>
            @if($maskedPhone)<dt>Phone</dt><dd>{{ $maskedPhone }}</dd>@endif
            @if(($data['settled_at'] ?? null) && $status !== 'open')<dt>Settled</dt><dd>{{ $data['settled_at']->format('d/m/Y H:i') }}</dd>@endif
        </dl>
        <hr class="rule">

        @if(count($selections))
            <ul class="picks">
                @foreach($selections as $pick)
                    <li>
                        @if($pick['league'] !== '')<span class="league">{{ $pick['league'] }}</span>@endif
                        <span class="event">{{ $pick['event'] ?: 'Selection '.$loop->iteration }}@if($pick['live'])<span class="live">LIVE</span>@endif</span>
                        <div class="line">
                            <span>{{ $pick['market'] }}{{ $pick['market'] !== '' && $pick['pick_label'] !== '' ? ': ' : '' }}{{ $pick['pick_label'] }}</span>
                            @if($pick['odds'] > 0)<em>{{ number_format($pick['odds'], 2) }}</em>@endif
                        </div>
                        @if($pick['starts_at'] || $pick['status'] !== '' || $pick['score'] !== '')
                            <div class="meta">
                                <span>{{ $pick['starts_at']?->format('d/m H:i') }}</span>
                                <span>{{ $pick['score'] !== '' ? $pick['score'].' · ' : '' }}{{ ucfirst($pick['status']) }}</span>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            <hr class="rule">
        @else
            <p class="muted-note">Selections are not available for this ticket{{ $data ? '' : ' while WinHouse is unreachable' }}. Amounts below are from the Vamoseone wallet.</p>
            <hr class="rule">
        @endif

        <dl class="totals">
            @if(($data['odds'] ?? 0) > 0)<dt>Total odds</dt><dd>{{ number_format($data['odds'], 2) }}</dd>@endif
            <dt>Stake</dt><dd>{{ $money($stake) }}</dd>
            @if(($data['bonus'] ?? 0) > 0)<dt>Bonus</dt><dd>{{ $money($data['bonus']) }}</dd>@endif
            @if(($data['possible_win'] ?? 0) > 0)<dt class="big">Possible win</dt><dd class="big">{{ $money($data['possible_win']) }}</dd>@endif
            @if($paid > 0)<dt class="big">Paid out</dt><dd class="big">{{ $money($paid) }}</dd>@endif
            @if(($local->void ?? 0) > 0)<dt>Refunded</dt><dd>{{ $money($local->void) }}</dd>@endif
        </dl>
        <hr class="rule">

        <footer class="foot">
            <p>Winnings are paid to the player's Vamoseone wallet.</p>
            <p>Keep this ticket. Printed {{ now()->format('d/m/Y H:i') }}@if(filled($printedBy)) by {{ $printedBy }}@endif.</p>
            <p>{{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'vamoseone' }} · 18+ · Play responsibly</p>
        </footer>
    </article>

    @if($auto)
        <script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 150); });</script>
    @endif
</body>
</html>
