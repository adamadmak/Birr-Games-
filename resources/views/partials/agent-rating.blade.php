{{--
    Rate the agent who settled this order, or show the verdict already given.

    Deliberately a plain form of five radio buttons rather than a script-driven
    star widget: it works before JavaScript loads, on a slow connection, and with a
    keyboard, and the whole control is one tap per star.
--}}
@if($existing)
    <span class="order-rating is-done" title="{{ $existing->comment }}">
        <span class="order-rating-stars" aria-label="{{ __('You rated :score out of 5', ['score' => $existing->score]) }}">
            @for($i = 1; $i <= 5; $i++)<i class="{{ $i <= $existing->score ? 'on' : '' }}">&#9733;</i>@endfor
        </span>
    </span>
@else
    <form class="order-rating" method="POST" action="{{ route('player.orders.rate', $order) }}">
        @csrf
        <fieldset>
            <legend>{{ __('Rate agent') }}</legend>
            @for($i = 1; $i <= 5; $i++)
                <label title="{{ trans_choice('{1}:count star|[2,*]:count stars', $i, ['count' => $i]) }}">
                    <input type="radio" name="score" value="{{ $i }}" required>
                    <i>&#9733;</i>
                    <span class="sr-only">{{ trans_choice('{1}:count star|[2,*]:count stars', $i, ['count' => $i]) }}</span>
                </label>
            @endfor
        </fieldset>
        <button type="submit">{{ __('Send') }}</button>
    </form>
@endif
