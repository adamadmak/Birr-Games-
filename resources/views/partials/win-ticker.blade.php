{{-- Recent real wins, scrolling. Rendered only when there are some. The list is
     repeated to fill the track so the loop never shows a gap; the repeat is
     hidden from assistive technology so each win is read once. --}}
@if (! empty($recentWins))
    @php
        $repeats = max(2, (int) ceil(14 / count($recentWins)));
    @endphp
    <section class="win-ticker" aria-label="{{ __('Recent wins') }}">
        <span class="win-ticker-label"><i aria-hidden="true"></i>{{ __('Live wins') }}</span>
        <div class="win-ticker-viewport">
            @foreach ([0, 1] as $copy)
                <ul class="win-ticker-track" @if ($copy === 1) aria-hidden="true" @endif>
                    @for ($r = 0; $r < $repeats; $r++)
                        @foreach ($recentWins as $win)
                            <li><b>{{ $win['who'] }}</b> {{ __('won') }} <em>{{ \App\Support\Money::format($win['amount']) }}</em> {{ __('on') }} <span>{{ $win['game'] }}</span></li>
                        @endforeach
                    @endfor
                </ul>
            @endforeach
        </div>
    </section>
@endif
