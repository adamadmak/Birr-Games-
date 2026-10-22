/*
 * The confirmation on every destructive form, read from data-confirm.
 *
 * These used to be inline: onsubmit="return confirm('Delete {{ $name }}?')".
 * Blade escapes an apostrophe in that name to &#039;, the browser decodes it
 * back to ' BEFORE the JS parser sees the attribute, and the string literal
 * ends early — a syntax error. A form whose onsubmit fails to parse has no
 * handler at all, so it submits WITHOUT asking: an agent nicknamed O'Brien, or
 * a banner titled "Women's Night", was deleted on the first click of the button
 * that was supposed to ask first.
 *
 * As an attribute the name is just text, and the message never reaches a JS
 * parser. One listener covers every form on the page, including ones rendered
 * after load.
 *
 * Capture phase on purpose: it has to run before any handler that might act on
 * the submit itself.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (! form || form.tagName !== 'FORM') {
            return;
        }

        var message = form.getAttribute('data-confirm');

        if (message && ! window.confirm(message)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
})();
