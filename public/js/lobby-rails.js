/*
 * Scroll arrows for the lobby's game rails.
 *
 * Delegated from the document rather than bound to each button: the lobby
 * replaces its results in place while a player searches, and anything bound to
 * the old buttons would silently stop working. The rail is found from the
 * button each time, so nothing here holds a reference that can go stale.
 */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-rail-prev], [data-rail-next]');
        if (!button) return;

        var section = button.closest('[data-game-section]');
        var rail = section && section.querySelector('[data-game-grid]');
        if (!rail || rail.classList.contains('collapsed')) return;

        var direction = button.hasAttribute('data-rail-prev') ? -1 : 1;
        // Move by most of a screenful so the card at the edge stays in view.
        var distance = Math.max(rail.clientWidth * 0.85, 160) * direction;

        rail.scrollBy({ left: distance, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
})();
