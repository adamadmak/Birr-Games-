{{--
    Inline sparkline. Expects:
      $series — array of numeric values (2+ points to draw a line)
    The viewBox is stretched with preserveAspectRatio="none" so the line always
    fills the card width; vector-effect keeps the stroke an even weight despite
    the non-uniform scale.
--}}
@php
    $series = array_values(array_map(static fn ($v) => (float) $v, $series ?? []));
    $count = count($series);
    $min = $count ? min($series) : 0.0;
    $max = $count ? max($series) : 0.0;
    $span = ($max - $min) ?: 1.0;

    $points = [];
    foreach ($series as $i => $value) {
        $x = $count > 1 ? ($i / ($count - 1)) * 100 : 50;
        // Inset vertically so the stroke is never clipped at the extremes.
        $y = 27 - (($value - $min) / $span) * 24;
        $points[] = round($x, 2).','.round($y, 2);
    }
@endphp
@if ($count > 1)
    <svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false">
        <polygon class="spark-fill" points="0,30 {{ implode(' ', $points) }} 100,30"></polygon>
        <polyline class="spark-line" vector-effect="non-scaling-stroke" points="{{ implode(' ', $points) }}"></polyline>
    </svg>
@endif
