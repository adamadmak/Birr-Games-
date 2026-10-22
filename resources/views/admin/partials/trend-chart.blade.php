{{--
    Dashboard trend chart — plain inline SVG, no charting library.

    Expects:
      $labels  — array of x-axis labels (one per point)
      $series  — array of ['key','name','color','type' => 'area'|'line','data' => []]
      $format  — optional callable used for tooltip values

    The viewBox keeps a fixed aspect (uniform scale) so axis text is never
    distorted; the SVG then scales fluidly to its container width.
--}}
@php
    $labels = array_values($labels ?? []);
    $series = array_values($series ?? []);
    $format = $format ?? (static fn ($v) => number_format((float) $v));

    $W = 760; $H = 260;
    $padL = 58; $padR = 14; $padT = 16; $padB = 30;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $count = count($labels);
    $all = [];
    foreach ($series as $s) { foreach ($s['data'] as $v) { $all[] = (float) $v; } }

    $max = $all ? max($all) : 0.0;
    $min = $all ? min($all) : 0.0;
    $min = min(0.0, $min);                 // always anchor the baseline at zero or below
    if ($max === $min) { $max = $min + 1; }

    // Round the axis step up to a clean number so gridline labels read well.
    $rawStep = ($max - $min) / 4;
    $mag = $rawStep > 0 ? pow(10, floor(log10($rawStep))) : 1;
    $step = ceil($rawStep / $mag) * $mag;
    $axisMin = floor($min / $step) * $step;
    $axisMax = $axisMin + $step * 4;

    $x = static fn ($i) => $count > 1
        ? $padL + ($i / ($count - 1)) * $plotW
        : $padL + $plotW / 2;
    $y = static fn ($v) => $padT + $plotH - ((((float) $v) - $axisMin) / ($axisMax - $axisMin)) * $plotH;

    $short = static function ($v) {
        $v = (float) $v; $abs = abs($v);
        if ($abs >= 1000000) return round($v / 1000000, 1).'M';
        if ($abs >= 1000) return round($v / 1000, 1).'k';
        return (string) round($v);
    };

    // Thin the x labels on wide series so they never collide.
    $labelEvery = $count > 10 ? (int) ceil($count / 7) : 1;
@endphp

@if ($count < 2)
    <div class="chart-empty">Not enough data yet to draw a trend.</div>
@else
<svg class="lp-chart" viewBox="0 0 {{ $W }} {{ $H }}" role="img"
     aria-label="Trend over the last {{ $count }} days">
    <defs>
        @foreach ($series as $s)
            @if (($s['type'] ?? 'line') === 'area')
                <linearGradient id="grad-{{ $s['key'] }}" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="{{ $s['color'] }}" stop-opacity=".34"></stop>
                    <stop offset="100%" stop-color="{{ $s['color'] }}" stop-opacity="0"></stop>
                </linearGradient>
            @endif
        @endforeach
    </defs>

    {{-- horizontal gridlines + value axis --}}
    @for ($i = 0; $i <= 4; $i++)
        @php $v = $axisMin + $step * $i; $gy = round($y($v), 2); @endphp
        <line class="grid-line" x1="{{ $padL }}" y1="{{ $gy }}" x2="{{ $W - $padR }}" y2="{{ $gy }}"></line>
        <text class="axis-label" x="{{ $padL - 9 }}" y="{{ $gy + 3.5 }}" text-anchor="end">{{ $short($v) }}</text>
    @endfor

    {{-- series --}}
    @foreach ($series as $s)
        @php
            $pts = [];
            foreach ($s['data'] as $i => $v) { $pts[] = round($x($i), 2).','.round($y($v), 2); }
            $line = implode(' ', $pts);
        @endphp
        @if (($s['type'] ?? 'line') === 'area')
            <polygon class="series-area" fill="url(#grad-{{ $s['key'] }})"
                     points="{{ round($x(0), 2) }},{{ round($y($axisMin), 2) }} {{ $line }} {{ round($x($count - 1), 2) }},{{ round($y($axisMin), 2) }}"></polygon>
        @endif
        <polyline class="series-line" stroke="{{ $s['color'] }}" points="{{ $line }}"></polyline>
    @endforeach

    {{-- x-axis labels --}}
    @foreach ($labels as $i => $label)
        @if ($i % $labelEvery === 0 || $i === $count - 1)
            <text class="axis-label" x="{{ round($x($i), 2) }}" y="{{ $H - 10 }}" text-anchor="middle">{{ $label }}</text>
        @endif
    @endforeach

    {{-- invisible hover bands carry a native tooltip for every day --}}
    @php $bandW = $plotW / max($count - 1, 1); @endphp
    @foreach ($labels as $i => $label)
        <rect class="hover-band" x="{{ round($x($i) - $bandW / 2, 2) }}" y="{{ $padT }}"
              width="{{ round($bandW, 2) }}" height="{{ $plotH }}">
            <title>{{ $label }}@foreach ($series as $s) · {{ $s['name'] }}: {{ $format($s['data'][$i] ?? 0) }}@endforeach</title>
        </rect>
    @endforeach
</svg>
@endif
