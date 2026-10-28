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

        var fluid = window.VamoseoneFluid;
        if (fluid) {
            bindDrag();
        } else {
            // Without the motion helper: the plain swipe, judged at release.
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
        }

        /*
         * Dragging a slide (the apple-design skill, .claude/skills/apple-design).
         * The slide stays under the finger from the first frame of a horizontal
         * move, resisting progressively because a fading carousel has no
         * neighbour to pull in. At release the decision is made from where the
         * flick is heading (momentum projection), not where the finger stopped;
         * the outgoing slide leaves in the direction it was thrown and the next
         * one arrives from the side the finger came from, carrying the same
         * speed. A slide still settling can be caught again mid-motion.
         * A mostly-vertical move is a page scroll and is left to the browser.
         */
        function bindDrag() {
            var drag = null;
            var settle = null;
            var swiped = false;
            var track = fluid.tracker();

            function slideAt(n) { return n === 0 ? media : promos[n - 1]; }
            function width() { return root.clientWidth || 360; }
            function place(el, x, fade) {
                el.style.transform = x ? 'translateX(' + x + 'px)' : '';
                el.style.opacity = fade && x ? String(Math.max(0.3, 1 - Math.abs(x) / (width() * 0.8))) : '';
            }
            function clear(el) { el.style.transition = ''; place(el, 0, false); }

            root.addEventListener('pointerdown', function (e) {
                if (e.pointerType === 'mouse' && e.button !== 0) return;
                paused = true; stop();
                var el = slideAt(index);
                var from = 0;
                if (settle && settle.el === el) { from = settle.motion.value(); }
                if (settle) { settle.motion.stop(); if (settle.el !== el) clear(settle.el); settle = null; }
                drag = { id: e.pointerId, x0: e.clientX, y0: e.clientY, from: from, el: el, active: false, offset: from };
                track.reset();
                track.add(e.clientX, e.timeStamp);
            });

            root.addEventListener('pointermove', function (e) {
                if (!drag || e.pointerId !== drag.id) return;
                var dx = e.clientX - drag.x0;
                var dy = e.clientY - drag.y0;
                if (!drag.active) {
                    if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;
                    if (Math.abs(dy) > Math.abs(dx)) { drag = null; paused = false; start(); return; }
                    drag.active = true;
                    drag.x0 = e.clientX;
                    try { root.setPointerCapture(e.pointerId); } catch (err) { /* pointer already gone */ }
                    drag.el.style.transition = 'none';
                }
                track.add(e.clientX, e.timeStamp);
                drag.offset = drag.from + (e.clientX - drag.x0);
                place(drag.el, fluid.rubberband(drag.offset, width()), true);
            });

            function finish(e, cancelled) {
                if (!drag || e.pointerId !== drag.id) return;
                var d = drag;
                drag = null;
                paused = false;
                if (!d.active) { start(); return; }
                swiped = true;
                setTimeout(function () { swiped = false; }, 0);

                var velocity = cancelled ? 0 : track.velocity();
                var shown = fluid.rubberband(d.offset, width());
                var heading = d.offset + fluid.project(velocity);
                var commit = !cancelled && count > 1 && Math.abs(heading) > width() * 0.25;

                if (!commit) {
                    settle = { el: d.el, motion: fluid.spring({
                        from: shown, to: 0, velocity: velocity,
                        onUpdate: function (x) { place(d.el, x, true); },
                        onDone: function () { clear(d.el); settle = null; },
                    }) };
                    start();
                    return;
                }

                var direction = heading < 0 ? 1 : -1;   // pulled left: the next slide
                fluid.haptic('light');
                // Out the way it was thrown, fading as it goes.
                fluid.spring({
                    from: shown, to: -direction * width() * 0.45, velocity: velocity, response: 0.3,
                    onUpdate: function (x) { place(d.el, x, true); },
                    onDone: function () { clear(d.el); },
                });
                go(index + direction, true);
                // In from where the finger came from, at the finger's speed.
                var next = slideAt(index);
                next.style.transition = 'opacity .25s ease';
                settle = { el: next, motion: fluid.spring({
                    from: direction * width() * 0.2, to: 0, velocity: velocity, response: 0.35,
                    onUpdate: function (x) { next.style.transform = x ? 'translateX(' + x + 'px)' : ''; },
                    onDone: function () { clear(next); settle = null; },
                }) };
            }
            root.addEventListener('pointerup', function (e) { finish(e, false); });
            root.addEventListener('pointercancel', function (e) { finish(e, true); });

            // A drag that ends over the slide's button is not a tap on it.
            root.addEventListener('click', function (e) {
                if (swiped) { e.preventDefault(); e.stopPropagation(); swiped = false; }
            }, true);
        }

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
