/*
 * Navigation feedback.
 *
 * Every action in this panel is a full page navigation, and nothing acknowledged
 * one. On the connections this audience uses, an operator taps, sees nothing for
 * a second or two, and taps again — which is also how a request gets sent twice.
 * The money forms already guard that with idempotency keys and submit-disabling;
 * this is about the panel not appearing dead in the meantime.
 *
 * Deliberately tiny: a progress bar the browser drives to completion by leaving
 * the page, plus a busy state on whatever control was pressed. No library, no
 * router, nothing to keep in sync with the server.
 */
document.addEventListener('DOMContentLoaded', () => {
    const bar = document.createElement('div');
    bar.className = 'nav-progress';
    bar.setAttribute('aria-hidden', 'true');
    document.body.appendChild(bar);

    let running = false;

    const start = () => {
        if (running) return;
        running = true;
        bar.classList.add('is-running');
        document.documentElement.setAttribute('aria-busy', 'true');
    };

    const stop = () => {
        running = false;
        bar.classList.remove('is-running');
        document.documentElement.removeAttribute('aria-busy');
    };

    /* Ordinary in-page links only: a new tab, a download or an anchor jump never
       replaces this document, so showing progress for one would leave a bar that
       never finishes. */
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;

        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
        if (new URL(link.href, window.location.href).origin !== window.location.origin) return;

        start();
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;

        start();

        /* The pressed control says so, rather than the whole page going grey. Left
           enabled: disabling a submit button before the browser has serialised the
           form drops its name and value from the request. */
        const control = form.querySelector('[type="submit"]:focus, button:focus') || form.querySelector('[type="submit"], button:not([type="button"])');
        control?.classList.add('is-busy');
    });

    /* Back/forward restores this document from the cache with the bar still mid-flight. */
    window.addEventListener('pageshow', stop);
    window.addEventListener('pagehide', stop);
});
