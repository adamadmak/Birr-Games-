/*
 * Show what is being typed into a password box.
 *
 * A STORED password cannot be shown, here or anywhere. Every password on this
 * platform is a bcrypt hash — `'password' => 'hashed'` on User and Agent — and
 * a hash cannot be turned back into the text it came from. Nobody can read an
 * existing password: not an administrator, not somebody holding the database.
 * That is the point of storing them this way, and it is why "view password" can
 * only ever mean one of two things:
 *
 *   1. see the characters you are typing, before you save them  — this file
 *   2. set a new one and tell the person what it is            — Reset Password
 *
 * Applied to every password box on the site rather than form by form, so a new
 * form gets it without anybody remembering to.
 */
(function () {
    'use strict';

    var SHOWN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/></svg>';
    var HIDDEN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="2.6"/><path d="m3.5 3.5 17 17"/></svg>';

    function decorate(input) {
        if (input.dataset.pwRevealReady === '1') {
            return;
        }
        input.dataset.pwRevealReady = '1';

        var wrap = document.createElement('span');
        wrap.className = 'pw-field';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var button = document.createElement('button');
        // Never a submit: inside a form a bare <button> posts it.
        button.type = 'button';
        button.className = 'pw-reveal';
        button.tabIndex = -1 === input.tabIndex ? -1 : 0;
        button.innerHTML = HIDDEN;
        button.setAttribute('aria-label', 'Show password');
        button.setAttribute('aria-pressed', 'false');
        button.title = 'Show password';

        function set(visible) {
            input.type = visible ? 'text' : 'password';
            button.innerHTML = visible ? SHOWN : HIDDEN;
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
            button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            button.title = visible ? 'Hide password' : 'Show password';
        }

        button.addEventListener('click', function () {
            var visible = input.type === 'password';
            set(visible);
            // Typing carries on where it left off rather than at the end.
            var at = input.value.length;
            try {
                input.focus();
                input.setSelectionRange(at, at);
            } catch (e) {
                input.focus();
            }
        });

        wrap.appendChild(button);

        // A revealed password must not be left on screen once the form is gone.
        if (input.form) {
            input.form.addEventListener('submit', function () { set(false); });
        }
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('input[type="password"]'), decorate);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
