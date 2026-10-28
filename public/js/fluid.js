/*
 * Fluid motion for the player site.
 *
 * After the apple-design skill (.claude/skills/apple-design): an interface
 * feels alive when motion starts from where things are on screen, carries the
 * finger's velocity, projects where a flick is going, and can be grabbed again
 * at any instant. Springs do all of that, so the two surfaces a player can
 * drag — the phone drawer and the hero slider — use the spring below instead
 * of fixed-duration CSS transitions while a finger is involved.
 *
 * Exposed as window.VamoseoneFluid for lobby-hero.js. Loaded before it.
 */
(function () {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    // iOS Safari applies :active to a touched element only when some touch
    // listener exists on the page; without it the press feedback never shows.
    document.addEventListener('touchstart', () => {}, { passive: true });

    /** Telegram's haptics, inside the mini app only; a no-op in a browser. Meaningful moments only. */
    function haptic(kind) {
        const feedback = window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.HapticFeedback;
        if (!feedback) return;
        try {
            if (kind === 'success' || kind === 'error' || kind === 'warning') feedback.notificationOccurred(kind);
            else if (kind === 'select') feedback.selectionChanged();
            else feedback.impactOccurred(kind || 'light');
        } catch (e) { /* older clients */ }
    }

    /**
     * A spring on one number, in Apple's two parameters: damping ratio (1 = no
     * overshoot, below 1 = some bounce) and response (seconds, roughly how
     * quickly it gets there; not a duration). Starts from `from` with
     * `velocity` (units per second), so a drag hands its speed straight over.
     * Returns a handle whose value() and velocity() are live, so a new touch
     * can stop it and continue from exactly where it is.
     */
    function spring({ from, to, velocity = 0, damping = 1, response = 0.35, onUpdate, onDone }) {
        let x = from;
        let v = velocity;
        let frame = 0;
        let last = 0;

        if (reduceMotion.matches) {
            onUpdate(to);
            if (onDone) onDone();
            return { stop() {}, value: () => to, velocity: () => 0 };
        }

        const stiffness = Math.pow((2 * Math.PI) / response, 2);
        const friction = (4 * Math.PI * damping) / response;

        const step = (now) => {
            const dt = Math.min(0.032, last ? (now - last) / 1000 : 1 / 60);
            last = now;
            const slices = Math.ceil(dt / 0.004);
            const h = dt / slices;
            for (let i = 0; i < slices; i++) {
                v += (-stiffness * (x - to) - friction * v) * h;
                x += v * h;
            }
            if (Math.abs(x - to) < 0.4 && Math.abs(v) < 8) {
                x = to;
                v = 0;
                onUpdate(x);
                if (onDone) onDone();
                return;
            }
            onUpdate(x);
            frame = requestAnimationFrame(step);
        };
        frame = requestAnimationFrame(step);

        return {
            stop() { cancelAnimationFrame(frame); },
            value: () => x,
            velocity: () => v,
        };
    }

    /** Where a flick would come to rest, the way scrolling decelerates (Apple's projection). */
    function project(velocity, rate = 0.998) {
        return ((velocity / 1000) * rate) / (1 - rate);
    }

    /** Past a boundary the element follows less and less, like something real slowing before it stops. */
    function rubberband(overshoot, dimension, constant = 0.55) {
        return (overshoot * dimension * constant) / (dimension + constant * Math.abs(overshoot));
    }

    /** The last 100 ms of a pointer's positions, for its velocity at release. */
    function tracker() {
        let points = [];
        return {
            add(value, time) {
                points.push([value, time]);
                while (points.length > 2 && time - points[0][1] > 100) points.shift();
            },
            velocity() {
                if (points.length < 2) return 0;
                const [a, b] = [points[0], points[points.length - 1]];
                const dt = b[1] - a[1];
                return dt > 0 ? ((b[0] - a[0]) / dt) * 1000 : 0;
            },
            reset() { points = []; },
        };
    }

    /** The x translation an element is showing right now, mid-transition included. */
    function liveTranslateX(element) {
        const transform = getComputedStyle(element).transform;
        if (!transform || transform === 'none') return 0;
        try { return new DOMMatrixReadOnly(transform).m41; } catch (e) { return 0; }
    }

    window.VamoseoneFluid = { spring, project, rubberband, tracker, haptic, liveTranslateX, reduceMotion };

    /* ------------------------------------------------------------------ */
    /* The phone drawer: drag it closed, 1:1, and fling it.               */
    /* ------------------------------------------------------------------ */

    function bindDrawer() {
        const drawer = document.querySelector('[data-player-sidebar]');
        const overlay = document.querySelector('[data-player-sidebar-overlay]');
        const toggles = document.querySelectorAll('[data-player-menu-toggle]');
        if (!drawer) return;

        const phone = window.matchMedia('(max-width: 980px)');
        let drag = null;
        let motion = null;
        const track = tracker();

        const width = () => drawer.getBoundingClientRect().width || 270;
        const paint = (x) => {
            drawer.style.transform = `translateX(${x}px)`;
            if (overlay) overlay.style.opacity = String(Math.max(0, Math.min(1, 1 + x / width())));
        };
        const release = () => {
            drawer.style.transition = '';
            drawer.style.transform = '';
            if (overlay) overlay.style.opacity = '';
        };
        const close = () => {
            // Land the drawer where CSS keeps it closed, then hand back to the classes
            // without letting the CSS transition replay the last few pixels.
            drawer.style.transition = 'none';
            drawer.classList.remove('open');
            overlay?.classList.remove('open');
            document.body.classList.remove('player-menu-open');
            toggles.forEach((button) => button.setAttribute('aria-expanded', 'false'));
            void drawer.offsetWidth;
            release();
        };

        drawer.addEventListener('pointerdown', (event) => {
            if (!phone.matches || reduceMotion.matches || !drawer.classList.contains('open')) return;
            if (event.pointerType === 'mouse') return;
            // Interruptible: a drawer still settling is grabbed where it is.
            const from = motion ? motion.value() : liveTranslateX(drawer);
            if (motion) { motion.stop(); motion = null; }
            drag = { id: event.pointerId, x0: event.clientX, y0: event.clientY, from, active: false };
            track.reset();
            track.add(event.clientX, event.timeStamp);
        });

        drawer.addEventListener('pointermove', (event) => {
            if (!drag || event.pointerId !== drag.id) return;
            const dx = event.clientX - drag.x0;
            const dy = event.clientY - drag.y0;
            if (!drag.active) {
                // ~10 px of hysteresis, and only a mostly-horizontal move is ours;
                // anything else is the drawer's own vertical scroll.
                if (Math.abs(dx) < 10 && Math.abs(dy) < 10) return;
                if (Math.abs(dy) > Math.abs(dx)) { drag = null; return; }
                drag.active = true;
                // Track from here, so the drawer moves by the finger's change from the
                // grab point instead of jumping the 10 px of hysteresis.
                drag.x0 = event.clientX;
                try { drawer.setPointerCapture(event.pointerId); } catch (e) { /* pointer already gone */ }
                drawer.style.transition = 'none';
            }
            track.add(event.clientX, event.timeStamp);
            const raw = drag.from + (event.clientX - drag.x0);
            // Open is the right-hand boundary: pulling further resists instead of stopping dead.
            paint(raw > 0 ? rubberband(raw, width()) : raw);
        });

        // A drag that ends over a link is not a tap on it.
        let dragged = false;
        drawer.addEventListener('click', (event) => {
            if (dragged) { event.preventDefault(); event.stopPropagation(); dragged = false; }
        }, true);

        const end = (event) => {
            if (!drag || event.pointerId !== drag.id) return;
            const active = drag.active;
            const start = drag.from + (event.clientX - drag.x0);
            drag = null;
            if (!active) return;
            dragged = true;
            setTimeout(() => { dragged = false; }, 0);

            const velocity = track.velocity();
            const current = start > 0 ? rubberband(start, width()) : start;
            const closedAt = -width() * 1.05;
            // Decide from where the flick is going, not where the finger let go.
            const shut = current + project(velocity) < -width() / 2;
            const flung = Math.abs(velocity) > 300;
            if (shut) haptic('light');

            motion = spring({
                from: current,
                to: shut ? closedAt : 0,
                velocity,
                damping: flung ? 0.8 : 1,
                response: 0.3,
                onUpdate: paint,
                onDone: () => { motion = null; if (shut) close(); else release(); },
            });
        };
        drawer.addEventListener('pointerup', end);
        drawer.addEventListener('pointercancel', (event) => {
            if (!drag || event.pointerId !== drag.id) return;
            const wasActive = drag.active;
            drag = null;
            if (wasActive) {
                motion = spring({ from: liveTranslateX(drawer), to: 0, onUpdate: paint, onDone: () => { motion = null; release(); } });
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Haptics for the moments that matter, inside Telegram only.         */
    /* ------------------------------------------------------------------ */

    function bindHaptics() {
        // A result the server just reported: a deposit submitted, a refusal.
        if (document.querySelector('.flash-error, .form-errors')) haptic('error');
        else if (document.querySelector('.flash-success, .form-success')) haptic('success');

        document.addEventListener('click', (event) => {
            const copy = event.target.closest('[data-copy]');
            if (copy) setTimeout(() => { if (copy.classList.contains('is-copied')) haptic('success'); }, 0);
        });
    }

    const boot = () => { bindDrawer(); bindHaptics(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
