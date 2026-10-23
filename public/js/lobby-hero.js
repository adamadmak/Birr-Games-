/*
 * Lobby hero slider and studio pills.
 *
 * The first slide is the operator's own artwork (the <picture class="hero-media">
 * that was always there); the rest are `[data-hero-slide]` promo cards. The
 * slider only adds behaviour to markup that already works without it: with no
 * script the artwork shows and the promo cards stay hidden.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var INTERVAL = 6500;

    function initSlider(root) {
        var media = root.querySelector('.hero-media');
        var promos = Array.prototype.slice.call(root.querySelectorAll('[data-hero-slide]'));
        var controls = root.querySelector('[data-hero-controls]');
        var dotsBox = root.querySelector('[data-hero-dots]');
        if (!media || !promos.length || !controls || !dotsBox) return;

        var count = promos.length + 1;
        var index = 0;
        var timer = null;
        var paused = false;
        var dots = [];

        for (var i = 0; i < count; i++) {
            (function (n) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'hero-dot';
                dot.setAttribute('aria-label', 'Slide ' + (n + 1));
                dot.addEventListener('click', function () { go(n, true); });
                dotsBox.appendChild(dot);
                dots.push(dot);
            })(i);
        }
        controls.hidden = false;
        root.classList.add('is-slider');

        function render() {
            root.setAttribute('data-slide', String(index));
            media.setAttribute('aria-hidden', index === 0 ? 'false' : 'true');
            promos.forEach(function (slide, n) {
                var active = index === n + 1;
                slide.classList.toggle('is-active', active);
                slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                Array.prototype.forEach.call(slide.querySelectorAll('a'), function (a) {
                    a.tabIndex = active ? 0 : -1;
                });
            });
            dots.forEach(function (dot, n) {
                dot.classList.toggle('is-active', n === index);
                if (n === index) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current');
            });
        }

        function go(n, user) {
            index = (n + count) % count;
            render();
            if (user) restart();
        }

        function stop() { if (timer) { clearInterval(timer); timer = null; } }
        function start() {
            if (reduceMotion || paused || document.hidden) return;
            stop();
            timer = setInterval(function () { go(index + 1, false); }, INTERVAL);
        }
        function restart() { stop(); start(); }

        root.querySelector('[data-hero-prev]').addEventListener('click', function () { go(index - 1, true); });
        root.querySelector('[data-hero-next]').addEventListener('click', function () { go(index + 1, true); });

        root.addEventListener('mouseenter', function () { paused = true; stop(); });
        root.addEventListener('mouseleave', function () { paused = false; start(); });
        root.addEventListener('focusin', function () { paused = true; stop(); });
        root.addEventListener('focusout', function () { paused = false; start(); });
        document.addEventListener('visibilitychange', function () { if (document.hidden) stop(); else start(); });

        // Swipe on touch screens; a mostly-vertical drag is a page scroll and is ignored.
        var x0 = null, y0 = null;
        root.addEventListener('touchstart', function (e) {
            x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; paused = true; stop();
        }, { passive: true });
        root.addEventListener('touchend', function (e) {
            if (x0 !== null) {
                var dx = e.changedTouches[0].clientX - x0;
                var dy = e.changedTouches[0].clientY - y0;
                if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.4) go(index + (dx < 0 ? 1 : -1), false);
            }
            x0 = y0 = null; paused = false; start();
        }, { passive: true });

        root.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowLeft') go(index - 1, true);
            if (e.key === 'ArrowRight') go(index + 1, true);
        });

        render();
        start();
    }

    // Keep the chosen studio pill in view on a long, scrolling row.
    function revealActiveChip() {
        var row = document.querySelector('.lobby-chips');
        var active = row && row.querySelector('.lobby-chip.active');
        if (!active || active.classList.contains('lobby-chip-all')) return;
        row.scrollLeft = Math.max(0, active.offsetLeft - (row.clientWidth - active.offsetWidth) / 2);
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-hero-slider]'), initSlider);
        revealActiveChip();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
