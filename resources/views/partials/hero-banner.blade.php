{{-- The hero banner: the operator's artwork plus the promo slides. Used by the
     casino lobby, and by the front page on phones (desktop has its own band). --}}
{{-- The artwork is whatever an operator uploaded under Configuration →
     Frontend → Hero banner (App\Support\HeroBanner), with the bundled
     illustration as the fallback. Phones get their own, taller image
     when one is set; otherwise the wide one is used. --}}
@php([$heroW, $heroH] = \App\Support\HeroBanner::size('desktop'))
@php([$heroMobileW, $heroMobileH] = \App\Support\HeroBanner::size('mobile'))
{{-- Half the picture's natural height: the ratio is doubled, and the image
     is cropped evenly top and bottom to fit. --}}
<section class="hero-banner hero-banner-image" data-hero-slider aria-roledescription="carousel" aria-label="Featured" style="--hero-src: url('{{ \App\Support\HeroBanner::desktopUrl() }}'); --hero-ratio: {{ round(2 * $heroW / max($heroH, 1), 4) }}; --hero-ratio-m: {{ round(4 * $heroMobileW / max($heroMobileH, 1), 4) }}">
    {{-- width/height are the uploaded picture's own size: on desktop the
         hero takes that picture's height, so nothing is cropped. --}}
    <picture class="hero-media">
        <source media="(max-width: 700px)" srcset="{{ \App\Support\HeroBanner::mobileUrl() }}" width="{{ $heroMobileW }}" height="{{ $heroMobileH }}">
        <img src="{{ \App\Support\HeroBanner::desktopUrl() }}" alt="{{ \App\Support\HeroBanner::alt() }}" width="{{ $heroW }}" height="{{ $heroH }}" fetchpriority="high" decoding="async">
    </picture>
    {{-- The picture is the whole banner. The heading stays for screen readers
         and search engines but is not drawn. --}}
    <h1 class="hero-sr-title">{{ __('Explore it. Play it. Enjoy it.') }}</h1>

    {{-- More slides after the operator's artwork. They are live text over
         bundled art, so they follow the language switch and the brand colours. --}}
    <article class="hero-slide hero-slide-promo promo-welcome" data-hero-slide aria-hidden="true">
        <div class="promo-copy">
            <span class="promo-eyebrow">{{ __('Welcome bonus') }}</span>
            <h2>{{ __('Join today and') }} <em>{{ __('start winning') }}</em></h2>
            <p>{{ __('Create your free account in under a minute, then claim your VIP rewards and a bonus on your first deposit.') }}</p>
            <div class="promo-actions">
                <a class="button button-primary" href="{{ route('register') }}" tabindex="-1">{{ __('Create account') }}</a>
                <a class="button button-soft" href="{{ route('promotions') }}" tabindex="-1">{{ __('See promotions') }}</a>
            </div>
        </div>
        <div class="promo-art" role="img" aria-label=""></div>
    </article>
    <article class="hero-slide hero-slide-promo promo-games" data-hero-slide aria-hidden="true">
        <div class="promo-copy">
            <span class="promo-eyebrow">{{ __('Daily tournament') }}</span>
            <h2>{{ __('Climb the board,') }} <em>{{ __('take the prize') }}</em></h2>
            <p>{{ __('Hundreds of slots and table games from top studios. Play every day and compete for the daily prize pool.') }}</p>
            <div class="promo-actions">
                <a class="button button-primary" href="{{ route('register') }}" tabindex="-1">{{ __('Play now') }}</a>
                <a class="button button-soft" href="#trending" tabindex="-1">{{ __('Browse games') }}</a>
            </div>
        </div>
        <div class="promo-art" role="img" aria-label=""></div>
    </article>

    <div class="hero-controls" data-hero-controls hidden>
        <button type="button" class="hero-arrow" data-hero-prev aria-label="{{ __('Previous slide') }}">‹</button>
        <div class="hero-dots" data-hero-dots></div>
        <button type="button" class="hero-arrow" data-hero-next aria-label="{{ __('Next slide') }}">›</button>
    </div>
</section>
