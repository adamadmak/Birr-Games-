/*
 * Count-up for the figures on the admin dashboard.
 *
 * Every element marked [data-countup] (or one of the dashboard's headline
 * figures) eases from zero to the number it already contains, keeping its
 * prefix, suffix, thousands separators and decimals. The server-rendered text
 * is the source of truth and is restored exactly when the animation ends, so a
 * script failure, a print, or reduced-motion simply shows the right number.
 */
(function () {
    'use strict';

    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var SELECTOR = '[data-countup], .ops-position-value, .ops-count b, .ops-split-keys b';
    var NUMBER = /^([^\d-]*)(-?\d[\d,]*(?:\.\d+)?)(.*)$/;
    var DURATION = 1100;

    function ease(t) { return 1 - Math.pow(1 - t, 4); }

    function animate(el) {
        // Browsers pause animation frames in a hidden tab; leave the real number.
        if (document.hidden) return;

        var original = el.textContent;
        var match = original.trim().match(NUMBER);
        if (!match) return;

        var target = parseFloat(match[2].replace(/,/g, ''));
        if (!isFinite(target) || target === 0) return;

        var decimals = (match[2].split('.')[1] || '').length;
        var useGrouping = match[2].indexOf(',') !== -1;
        var start = null;

        function frame(now) {
            if (start === null) start = now;
            var progress = Math.min((now - start) / DURATION, 1);
            var value = target * ease(progress);

            if (progress < 1) {
                el.textContent = match[1] + value.toLocaleString('en-US', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals,
                    useGrouping: useGrouping
                }) + match[3];
                requestAnimationFrame(frame);
            } else {
                el.textContent = original;
            }
        }

        requestAnimationFrame(frame);
    }

    function run() {
        var els = document.querySelectorAll(SELECTOR);
        if (!('IntersectionObserver' in window)) { els.forEach(animate); return; }

        var seen = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    seen.unobserve(entry.target);
                    animate(entry.target);
                }
            });
        }, { threshold: 0.4 });

        els.forEach(function (el) { seen.observe(el); });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
    else run();
})();
