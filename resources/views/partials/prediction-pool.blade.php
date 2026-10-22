{{--
    Prediction pool card.

    Rendered only when an operator has a pool published and still open, so the
    lobby shows nothing here the rest of the time. Everything the countdown
    needs is on the element as data, and the server re-checks the deadline on
    submit — the card closing itself is a courtesy, not the guard.
--}}
@php
    $money = fn ($amount) => \App\Support\Money::format($amount, 0);
    $entry = $predictionEntry ?? null;
    $home = $entry->home_score ?? 0;
    $away = $entry->away_score ?? 0;
@endphp

<section class="pp" data-prediction-pool data-closes-at="{{ $predictionPool->closes_at->toIso8601String() }}">
    <div class="pp-head">
        <span class="pp-head-mark" aria-hidden="true">
            <svg viewBox="0 0 512 512" width="17" height="17" fill="currentColor" stroke="currentColor" stroke-width="0">
                <path d="M256 48C141.13 48 48 141.13 48 256s93.13 208 208 208 208-93.13 208-208S370.87 48 256 48Z" fill="none" stroke-width="32"/>
                <path d="M256 144a112 112 0 1 0 112 112 112 112 0 0 0-112-112Z" fill="none" stroke-width="32"/>
                <circle cx="256" cy="256" r="32"/>
            </svg>
        </span>
        <h2>{{ __('Prediction Pool') }}</h2>
    </div>

    <article class="pp-card">
        <span class="pp-competition">{{ $predictionPool->competition }}</span>

        <div class="pp-teams">
            @foreach (['home', 'away'] as $side)
                @php $team = $side === 'home' ? $predictionPool->home_team : $predictionPool->away_team; @endphp
                @if ($side === 'away')<span class="pp-vs">VS</span>@endif
                <div class="pp-team">
                    {{-- Initials are always rendered underneath. If the crest cannot
                         load — a missing storage symlink being the usual reason — the
                         image removes itself and the initials show through, rather
                         than leaving a broken-image icon on the card. --}}
                    <span class="pp-crest">
                        <em>{{ $predictionPool->initials($side) }}</em>
                        @if ($predictionPool->crestUrl($side))
                            <img src="{{ $predictionPool->crestUrl($side) }}" alt="" width="56" height="56"
                                 loading="lazy" decoding="async" data-pp-crest>
                        @endif
                    </span>
                    <strong>{{ $team }}</strong>
                </div>
            @endforeach
        </div>

        <div class="pp-prize">
            <small>{{ __('Prize pool') }}</small>
            <strong>{{ $money($predictionPool->prize_pool) }}</strong>
        </div>

        <div class="pp-meta">
            <span class="pp-chip is-free">{{ __('Free') }}</span>
            <span>{{ $predictionPool->kicks_off_at->format('M j, H:i') }}</span>
        </div>

        <p class="pp-countdown">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            {{ __('Closes in') }} <b data-prediction-countdown>--:--:--</b>
        </p>

        @if ($predictionPool->min_deposit > 0)
            <p class="pp-min">{{ __('Min. balance') }}: {{ \App\Support\Money::format($predictionPool->min_deposit, 2) }}</p>
        @endif

        <div class="pp-market">
            <span>{{ __('Correct Score') }}</span>
            <span class="pp-picks">{{ trans_choice('{0}no picks|{1}:count pick|[2,*]:count picks', $predictionPicks, ['count' => number_format($predictionPicks)]) }}</span>
        </div>

        @auth
            <form class="pp-form" method="POST" action="{{ route('predictions.join', $predictionPool) }}">
                @csrf
                <div class="pp-steppers">
                    @foreach ([['home', $predictionPool->home_team, $home], ['away', $predictionPool->away_team, $away]] as [$side, $team, $value])
                        @if ($side === 'away')<span class="pp-dash">–</span>@endif
                        <div class="pp-stepper">
                            <small>{{ $team }}</small>
                            <div>
                                <button type="button" data-pp-step="-1" data-pp-target="{{ $side }}" aria-label="Fewer goals for {{ $team }}">&minus;</button>
                                <input type="number" name="{{ $side }}_score" id="pp-{{ $side }}" value="{{ $value }}"
                                       min="0" max="20" inputmode="numeric" aria-label="{{ $team }} score">
                                <button type="button" data-pp-step="1" data-pp-target="{{ $side }}" aria-label="More goals for {{ $team }}">+</button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <button class="pp-submit" type="submit">
                    {{ $entry ? __('Update prediction') : __('Join & Predict') }}
                </button>
                @if ($entry)
                    <p class="pp-yours">{{ __('Your prediction') }}: <b>{{ $entry->home_score }} – {{ $entry->away_score }}</b></p>
                @endif
            </form>
        @else
            <a class="pp-submit" href="{{ route('login') }}">{{ __('Sign in to predict') }}</a>
        @endauth
    </article>
</section>
