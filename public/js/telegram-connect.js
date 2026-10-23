/*
 * Connect Telegram page: once the player has opened the bot, poll a tiny
 * endpoint until the bot confirms the link, then reload so the page shows the
 * connected state. It only asks "is this account connected?"; the proof itself
 * happens inside Telegram.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-telegram-connect]');
    if (!root) return;

    var url = root.getAttribute('data-status-url');
    var waiting = root.querySelector('[data-telegram-waiting]');
    var open = root.querySelector('[data-telegram-open]');
    var timer = null;
    var stopAt = Date.now() + 16 * 60 * 1000; // a little past the link's own lifetime

    function check() {
        if (document.hidden) return;
        if (Date.now() > stopAt) { stop(); return; }

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.connected) { stop(); window.location.reload(); }
            })
            .catch(function () { /* try again on the next tick */ });
    }

    function start() {
        if (timer) return;
        if (waiting) waiting.hidden = false;
        timer = setInterval(check, 3000);
    }

    function stop() {
        if (timer) { clearInterval(timer); timer = null; }
        if (waiting) waiting.hidden = true;
    }

    if (open) open.addEventListener('click', start);
    // The player may open the link from another device or app; poll anyway, gently.
    setTimeout(start, 8000);
})();
