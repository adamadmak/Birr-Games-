/**
 * Admin command palette. Ctrl/Cmd+K from anywhere in the back office to jump to a
 * page or find a member, agent, transaction or agent order by name, phone,
 * reference or transaction id.
 *
 * Also handles the small quality-of-life behaviours that belong to every admin
 * page: auto-dismissing flash messages and the "/" shortcut for page search.
 */
(function () {
    'use strict';

    var endpoint = (document.currentScript && document.currentScript.dataset.endpoint) || '/admin/quick-search';

    document.addEventListener('DOMContentLoaded', function () {
        setupFlashes();
        setupSearchShortcut();

        var palette = document.querySelector('[data-palette]');
        if (!palette) return;

        var input = palette.querySelector('[data-palette-input]');
        var results = palette.querySelector('[data-palette-results]');
        var items = [];
        var cursor = -1;
        var requestId = 0;
        var debounce = null;
        var cache = Object.create(null);

        function open() {
            if (!palette.hasAttribute('hidden')) return;
            palette.removeAttribute('hidden');
            document.body.classList.add('has-palette-open');
            input.value = '';
            window.requestAnimationFrame(function () { input.focus(); });
            query('');
        }

        function close() {
            palette.setAttribute('hidden', '');
            document.body.classList.remove('has-palette-open');
            cursor = -1;
        }

        function highlight(next) {
            if (!items.length) return;
            cursor = (next + items.length) % items.length;
            items.forEach(function (item, index) {
                item.classList.toggle('is-active', index === cursor);
                if (index === cursor && item.scrollIntoView) {
                    item.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function render(groups, term) {
            results.innerHTML = '';
            items = [];
            cursor = -1;

            if (!groups || !groups.length) {
                var empty = document.createElement('p');
                empty.className = 'admin-palette-empty';
                empty.textContent = term ? 'Nothing matches "' + term + '".' : 'Start typing to search.';
                results.appendChild(empty);
                return;
            }

            groups.forEach(function (group) {
                var heading = document.createElement('p');
                heading.className = 'admin-palette-group';
                heading.textContent = group.label;
                results.appendChild(heading);

                (group.items || []).forEach(function (entry) {
                    var link = document.createElement('a');
                    link.className = 'admin-palette-item';
                    link.href = entry.url;
                    link.setAttribute('role', 'option');
                    link.innerHTML = '<i class="' + (entry.icon || 'fa-solid fa-arrow-right') + '"></i>' +
                        '<span><strong></strong><small></small></span>' +
                        '<b class="admin-palette-enter"><i class="fa-solid fa-arrow-turn-down"></i></b>';
                    link.querySelector('strong').textContent = entry.label || '';
                    link.querySelector('small').textContent = entry.hint || '';
                    link.addEventListener('mouseenter', function () { highlight(items.indexOf(link)); });
                    results.appendChild(link);
                    items.push(link);
                });
            });

            highlight(0);
        }

        function query(term) {
            var key = term || '';
            if (cache[key]) {
                render(cache[key], term);
                return;
            }

            var current = ++requestId;
            results.setAttribute('aria-busy', 'true');

            fetch(endpoint + '?q=' + encodeURIComponent(term), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (response) { return response.ok ? response.json() : { groups: [] }; })
                .then(function (payload) {
                    if (current !== requestId) return;
                    cache[key] = payload.groups || [];
                    render(cache[key], term);
                })
                .catch(function () {
                    if (current !== requestId) return;
                    render([], term);
                })
                .finally(function () { results.removeAttribute('aria-busy'); });
        }

        document.querySelectorAll('[data-palette-open]').forEach(function (trigger) {
            trigger.addEventListener('click', open);
        });
        palette.querySelectorAll('[data-palette-close]').forEach(function (trigger) {
            trigger.addEventListener('click', close);
        });

        input.addEventListener('input', function () {
            var term = input.value.trim();
            window.clearTimeout(debounce);
            debounce = window.setTimeout(function () { query(term); }, term ? 180 : 0);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); highlight(cursor + 1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); highlight(cursor - 1); }
            else if (event.key === 'Enter' && items[cursor]) { event.preventDefault(); window.location.href = items[cursor].href; }
        });

        document.addEventListener('keydown', function (event) {
            var isShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k';
            if (isShortcut) { event.preventDefault(); palette.hasAttribute('hidden') ? open() : close(); }
            else if (event.key === 'Escape' && !palette.hasAttribute('hidden')) { close(); }
        });
    });

    /** Flash messages fade themselves out so the page does not stay cluttered. */
    function setupFlashes() {
        document.querySelectorAll('.admin-flash').forEach(function (flash) {
            var dismiss = function () {
                flash.classList.add('is-leaving');
                window.setTimeout(function () { flash.remove(); }, 320);
            };

            var closer = flash.querySelector('[data-flash-close]');
            if (closer) closer.addEventListener('click', dismiss);
            if (flash.hasAttribute('data-autodismiss')) window.setTimeout(dismiss, 6000);
        });
    }

    /** "/" focuses the first search box on the page, like every modern console. */
    function setupSearchShortcut() {
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;

            var active = document.activeElement;
            var tag = active && active.tagName ? active.tagName.toLowerCase() : '';
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || (active && active.isContentEditable)) return;

            var search = document.querySelector('main input[type="search"], main input[name="search"]');
            if (!search) return;

            event.preventDefault();
            search.focus();
            if (search.select) search.select();
        });
    }
})();
