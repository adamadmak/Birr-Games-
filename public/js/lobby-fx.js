/*
 * Reveal-on-scroll for the lobby's shelves.
 *
 * Purely cosmetic and strictly additive: the hidden starting state is applied
 * by this script, never by the stylesheet, so with JavaScript off, with
 * reduced motion, or if this file fails to load, every shelf is simply there.
 * Shelves the lobby swaps in while searching are never hidden at all — only
 * the ones present at first paint are animated.
 */
(function () {
    'use strict';

    if (!('IntersectionObserver' in window)) return;
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    function init() {
        var sections = document.querySelectorAll('[data-game-section]');
        if (!sections.length) return;

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('fx-in');
                observer.unobserve(entry.target);
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });

        sections.forEach(function (section) {
            var box = section.getBoundingClientRect();
            // Anything already on screen needs no entrance.
            if (box.top < window.innerHeight * 0.92) return;
            section.classList.add('fx-reveal');
            observer.observe(section);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
