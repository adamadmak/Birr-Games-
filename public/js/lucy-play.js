document.addEventListener('DOMContentLoaded', () => {

    /*
     * Splash screen.
     *
     * This used to hold for a fixed 1200ms plus a 400ms fade on EVERY page
     * load, so a page that was ready in 200ms still sat behind the logo for
     * over a second and a half — the single largest contributor to the app
     * feeling slow. It now shows once per session (the point of a splash is
     * the first impression, not every navigation) and holds only long enough
     * to avoid flashing past.
     */
    const splash = document.getElementById('splashScreen');
    if (splash) {
        const SEEN_KEY = 'lucy-splash-seen';
        let alreadySeen = false;
        try { alreadySeen = sessionStorage.getItem(SEEN_KEY) === '1'; } catch (e) { /* private mode */ }

        if (alreadySeen) {
            splash.remove();
        } else {
            try { sessionStorage.setItem(SEEN_KEY, '1'); } catch (e) { /* ignore */ }
            const minDisplay = 250; // just enough not to flash
            const start = performance.now();
            const dismiss = () => {
                const remaining = Math.max(0, minDisplay - (performance.now() - start));
                window.setTimeout(() => {
                    splash.classList.add('fade-out');
                    splash.addEventListener('transitionend', () => splash.remove(), { once: true });
                    // Belt and braces: if the transition never fires, still clear it.
                    window.setTimeout(() => splash.remove(), 500);
                }, remaining);
            };
            if (document.readyState === 'complete') dismiss();
            else window.addEventListener('load', dismiss, { once: true });
        }
    }

    const menuToggle = document.querySelector('[data-menu-toggle]');
    const menu = document.querySelector('[data-menu]');

    menuToggle?.addEventListener('click', () => {
        menu?.classList.toggle('open');
    });

    const playerMenuToggles = document.querySelectorAll('[data-player-menu-toggle]');
    const playerSidebar = document.querySelector('[data-player-sidebar]');
    const playerSidebarOverlay = document.querySelector('[data-player-sidebar-overlay]');
    /*
     * Opening the drawer locks the page behind it. Without this the lobby keeps
     * scrolling under the open drawer, which on a touch screen reads as the
     * drawer itself refusing to scroll.
     */
    const setPlayerSidebar = (open) => {
        playerSidebar?.classList.toggle('open', open);
        playerSidebarOverlay?.classList.toggle('open', open);
        document.body.classList.toggle('player-menu-open', open);
        playerMenuToggles.forEach((button) => button.setAttribute('aria-expanded', String(open)));
        if (open) playerSidebar?.scrollTo({ top: 0 });
    };
    const closePlayerSidebar = () => setPlayerSidebar(false);

    playerMenuToggles.forEach((playerMenuToggle) => playerMenuToggle.addEventListener('click', () => {
        setPlayerSidebar(!playerSidebar?.classList.contains('open'));
    }));
    playerSidebarOverlay?.addEventListener('click', closePlayerSidebar);
    playerSidebar?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closePlayerSidebar));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && playerSidebar?.classList.contains('open')) closePlayerSidebar();
    });
    // A drawer left open while the viewport grows into the desktop layout would
    // keep the body locked with no visible way to close it.
    window.matchMedia('(min-width: 701px)').addEventListener('change', (event) => {
        if (event.matches) closePlayerSidebar();
    });

    const search = document.querySelector('[data-game-search]');
    const provider = document.querySelector('[data-provider-filter]');
    let cards = [...document.querySelectorAll('[data-game-card]')];
    const empty = document.querySelector('[data-empty-games]');
    const searching = document.querySelector('[data-searching-games]');

    // Squash separators so a typed "aztec gems" matches a feed title of "AztecGemsDeluxe",
    // the same reduction the server applies.
    const squash = (value) => (value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');

    /*
     * The squashed forms never change, so they are computed once here rather
     * than re-running two regexes per card on every keystroke. Each card also
     * remembers its section, which removes the second pass that used to run a
     * querySelectorAll inside a loop over every section.
     */
    const buildCardIndex = () => [...document.querySelectorAll('[data-game-card]')].map((card) => ({
        el: card,
        section: card.closest('[data-game-section]'),
        title: squash(card.dataset.title || ''),
        provider: card.dataset.provider || '',
        providerKey: squash(card.dataset.provider || ''),
        hidden: false,
    }));
    const buildSections = () => [...document.querySelectorAll('[data-game-section]')]
        .map((el) => ({ el, visible: true }));

    // Rebuilt whenever the server swaps results in. They memoise the cards that
    // were on the page when they were built, so holding on to the originals is
    // what put "No games found" above a result the server had just returned.
    let cardIndex = buildCardIndex();
    let sections = buildSections();
    const sectionVisible = new Map();

    // Anything that binds itself to individual cards has to be told when they
    // are replaced, or only the cards present at first paint keep working.
    const cardsReplacedHandlers = [];
    const onCardsReplaced = (handler) => cardsReplacedHandlers.push(handler);

    const filterGames = (pending = false) => {
        const query = squash((search?.value || '').trim().toLowerCase());
        const selectedProvider = provider?.value || '';
        let visibleCount = 0;

        sectionVisible.clear();

        for (let i = 0; i < cardIndex.length; i += 1) {
            const card = cardIndex[i];
            const visible = (!query || card.title.includes(query) || card.providerKey.includes(query))
                && (!selectedProvider || card.provider === selectedProvider);

            // Only touch the DOM when the state actually flips; assigning `hidden`
            // unconditionally invalidated style for every card on every keystroke.
            const shouldHide = !visible;
            if (card.hidden !== shouldHide) {
                card.el.hidden = shouldHide;
                card.hidden = shouldHide;
            }
            if (visible) {
                visibleCount += 1;
                if (card.section) sectionVisible.set(card.section, true);
            }
        }

        for (let i = 0; i < sections.length; i += 1) {
            const section = sections[i];
            const visible = sectionVisible.has(section.el);
            if (section.visible !== visible) {
                section.el.hidden = !visible;
                section.visible = visible;
            }
        }

        // This filter only sees the games already rendered, so an empty result here does not
        // mean the catalogue has none. While a server search is on its way, say so instead of
        // claiming there is nothing — that message was appearing for games that do exist.
        if (empty) {
            empty.hidden = visibleCount !== 0 || pending;
        }
        if (searching) {
            searching.hidden = !(pending && visibleCount === 0);
        }
    };

    // The instant filter can only see the cards already on the page — ten per provider —
    // so on its own it answered "aztec" with the 2 that happened to be in that sample and
    // looked broken, while the catalogue holds 33. Keep it for immediate feedback, and
    // ask the server for the rest once typing settles.
    //
    // That second step used to be searchForm.submit(), a full navigation. On a phone the
    // gap between two letters is routinely longer than the debounce, so the page reloaded
    // mid-word: the input lost focus and the keyboard dropped, which is why searching felt
    // like it refreshed on every letter — worst inside Telegram, where the webview
    // re-runs its whole startup on each load. The results are fetched and swapped in
    // place now; nothing navigates, so focus and the keyboard are never disturbed.
    const searchForm = search?.closest('form');
    let searchSubmitTimer;
    let inFlightSearch;

    const swapResults = (html) => {
        const fresh = new DOMParser().parseFromString(html, 'text/html')
            .querySelector('[data-game-results]');
        const current = document.querySelector('[data-game-results]');
        if (!fresh || !current) return false;

        current.replaceChildren(...fresh.childNodes);

        /*
         * Every card element is new, and two things memoise the old ones.
         *
         * The instant filter walks `cardIndex`, not the DOM. Left stale, it
         * measured the previous page's cards — all now detached and none of
         * them matching the new query — counted zero visible, and revealed the
         * "No games found" panel directly above the results the server had
         * just returned. Searching "Jetx" showed that message on top of Jet X.
         *
         * The game dialog binds a click listener per card, so without the
         * second line a search result was the one tile on the page that did
         * not open the dialog.
         */
        cards = [...document.querySelectorAll('[data-game-card]')];
        cardIndex = buildCardIndex();
        sections = buildSections();
        cardsReplacedHandlers.forEach((handler) => handler(cards));

        return true;
    };

    const runServerSearch = () => {
        if (!searchForm) return;

        const query = (search.value || '').trim();
        const chosenProvider = provider?.value || '';
        const settled = (searchForm.dataset.serverQuery || '').trim();
        const settledProvider = searchForm.dataset.serverProvider || '';
        if (query === settled && chosenProvider === settledProvider) return;

        const url = new URL(searchForm.getAttribute('action') || window.location.pathname, window.location.href);
        query ? url.searchParams.set('q', query) : url.searchParams.delete('q');
        chosenProvider ? url.searchParams.set('provider', chosenProvider) : url.searchParams.delete('provider');

        // A slow reply for "aztec g" must never land on top of the one for "aztec gem".
        inFlightSearch?.abort();
        inFlightSearch = new AbortController();

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: inFlightSearch.signal,
        })
            .then((response) => (response.ok ? response.text() : Promise.reject(response)))
            .then((html) => {
                if (!swapResults(html)) return;
                searchForm.dataset.serverQuery = query;
                searchForm.dataset.serverProvider = chosenProvider;
                filterGames(false);
                // Keeps the address bar shareable and reload-able without navigating.
                window.history.replaceState({}, '', url);
            })
            .catch(() => {
                /* Aborted by the next keystroke, or offline. The instant filter still
                   stands, so the player is never left with a blank grid. */
            });
    };

    search?.addEventListener('input', () => {
        const pending = (search.value || '').trim() !== (searchForm?.dataset.serverQuery || '').trim();
        filterGames(pending);
        clearTimeout(searchSubmitTimer);
        searchSubmitTimer = setTimeout(runServerSearch, 400);
    });

    // Enter (and the phone keyboard's Go) must not navigate either.
    searchForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(searchSubmitTimer);
        runServerSearch();
    });

    provider?.addEventListener('change', () => {
        filterGames(true);
        clearTimeout(searchSubmitTimer);
        runServerSearch();
    });

    // Searching reloads the page, so put the cursor back where it was to keep typing continuous.
    if (search?.value) {
        search.focus({ preventScroll: true });
        try {
            search.setSelectionRange(search.value.length, search.value.length);
        } catch (error) {
            /* setSelectionRange is not supported on every input type */
        }
    }

    document.querySelectorAll('[data-game-favorite]').forEach((button) => {
        const key = `lucy-favorite-${button.dataset.gameFavorite}`;
        const renderFavorite = () => {
            const active = localStorage.getItem(key) === '1';
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            const label = button.querySelector('span');
            const icon = button.querySelector('i');
            if (label) label.textContent = active ? 'Added to favorite' : 'Add to favorite';
            if (icon) icon.textContent = active ? '♥' : '♡';
        };
        renderFavorite();
        button.addEventListener('click', () => {
            localStorage.setItem(key, button.getAttribute('aria-pressed') === 'true' ? '0' : '1');
            renderFavorite();
        });
    });

    const gameDialog = document.querySelector('[data-lobby-game-dialog]');
    if (gameDialog) {
        const gameImage = gameDialog.querySelector('[data-lobby-game-image]');
        const gameTitle = gameDialog.querySelector('[data-lobby-game-title]');
        const playLink = gameDialog.querySelector('[data-lobby-game-play]');
        const favorite = gameDialog.querySelector('[data-lobby-game-favorite]');
        const submitProtectedLaunch = (event) => {
            if (event.currentTarget.dataset.method !== 'post') return;
            event.preventDefault();
            const form = document.createElement('form');
            form.method = 'post';
            form.action = event.currentTarget.href;
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = document.querySelector('meta[name="csrf-token"]')?.content || '';
            form.appendChild(csrf);
            document.body.appendChild(form);
            form.submit();
        };
        playLink.addEventListener('click', submitProtectedLaunch);
        const renderDialogFavorite = () => {
            const key = `lucy-favorite-${favorite.dataset.slug || ''}`;
            const active = localStorage.getItem(key) === '1';
            favorite.setAttribute('aria-pressed', active ? 'true' : 'false');
            favorite.querySelector('span').textContent = active ? 'Added to favorite' : 'Add to favorite';
            favorite.querySelector('i').textContent = active ? '♥' : '♡';
        };
        const bindCardDialog = (card) => card.addEventListener('click', (event) => {
            event.preventDefault();
            gameTitle.textContent = card.dataset.modalTitle;
            gameImage.src = card.dataset.modalImage || '';
            gameImage.alt = card.dataset.modalTitle;
            gameImage.hidden = !card.dataset.modalImage;
            playLink.href = card.dataset.modalLaunch || card.href;
            playLink.dataset.method = card.dataset.modalLaunchMethod || 'get';
            favorite.dataset.slug = card.dataset.modalSlug;
            renderDialogFavorite();
            gameDialog.showModal();
        });
        cards.forEach(bindCardDialog);
        onCardsReplaced((fresh) => fresh.forEach(bindCardDialog));
        favorite.addEventListener('click', () => {
            const key = `lucy-favorite-${favorite.dataset.slug || ''}`;
            localStorage.setItem(key, favorite.getAttribute('aria-pressed') === 'true' ? '0' : '1');
            renderDialogFavorite();
        });
        gameDialog.querySelector('[data-lobby-game-close]').addEventListener('click', () => gameDialog.close());
        gameDialog.addEventListener('click', (event) => {
            if (event.target === gameDialog) gameDialog.close();
        });
    }

    document.querySelectorAll('[data-collapse]').forEach((button) => {
        button.addEventListener('click', () => {
            const section = button.closest('[data-game-section]');
            const grid = section?.querySelector('[data-game-grid]');
            const collapsed = grid?.classList.toggle('collapsed');
            button.setAttribute('aria-expanded', String(!collapsed));
            button.firstChild.textContent = collapsed ? 'Expand ' : 'Collapse ';
            button.querySelector('span').textContent = collapsed ? '+' : '−';
        });
    });

    /*
     * Copy buttons. The account number and the amount are the two things a
     * player has to carry into their bank app; a button beats retyping them.
     */
    const copyFallback = (text, done) => {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { if (document.execCommand('copy')) done(); } catch (e) { /* nothing to do */ }
        area.remove();
    };
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-copy]');
        if (!button) return;
        const text = button.dataset.copy || '';
        const label = button.textContent;
        const done = () => {
            button.classList.add('is-copied');
            button.textContent = button.dataset.copiedLabel || 'Copied';
            setTimeout(() => { button.classList.remove('is-copied'); button.textContent = label; }, 1500);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, () => copyFallback(text, done));
        } else {
            copyFallback(text, done);
        }
    });

    /*
     * The deposit dialog.
     *
     * The player picks an amount and an agent account, leaves to pay, and
     * comes back to enter the bank reference. Until 28 Oct 2026 everything
     * lived in the dialog, so a reload, an accidental close or a Telegram
     * restart dropped the account they were paying into. The flow is now a
     * draft in localStorage: closing the dialog keeps it, the page offers to
     * resume it, a submitted deposit clears it, and a refused one reopens at
     * the last step with the server's message.
     */
    const depositDialog = document.querySelector('[data-deposit-dialog]');
    if (depositDialog) {
        const DRAFT_KEY = 'vamoseone-deposit-draft';
        const DRAFT_LIFETIME = 24 * 60 * 60 * 1000;
        const form = depositDialog.querySelector('[data-deposit-form]');
        const steps = [...depositDialog.querySelectorAll('[data-deposit-step]')];
        const amountInput = depositDialog.querySelector('[data-deposit-amount]');
        const accountInput = depositDialog.querySelector('[data-deposit-account-id]');
        const methodName = depositDialog.querySelector('[data-deposit-method-name]');
        const methodLabel = depositDialog.querySelector('[data-deposit-method-label]');
        const amountLabel = depositDialog.querySelector('[data-deposit-amount-label]');
        const nextReference = depositDialog.querySelector('[data-deposit-next-reference]');
        const formError = depositDialog.querySelector('[data-deposit-form-error]');
        const resume = document.querySelector('[data-deposit-resume]');
        let gatewayId = '';
        let gatewayName = '';
        let selectedAgent = null;

        const money = (value) => `ETB ${Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
        const readDraft = () => {
            try {
                const draft = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
                return draft && draft.at && Date.now() - draft.at < DRAFT_LIFETIME ? draft : null;
            } catch (e) { return null; }
        };
        const saveDraft = (step) => {
            try {
                localStorage.setItem(DRAFT_KEY, JSON.stringify({ gatewayId, gatewayName, accountId: accountInput.value, amount: amountInput.value, step, at: Date.now() }));
            } catch (e) { /* private mode */ }
        };
        const clearDraft = () => { try { localStorage.removeItem(DRAFT_KEY); } catch (e) { /* ignore */ } };

        const showStep = (name) => {
            steps.forEach((step) => step.classList.toggle('active', step.dataset.depositStep === name));
            if (name !== 'amount') saveDraft(name);
        };
        const showError = (message) => {
            if (!formError) return;
            formError.textContent = message || '';
            formError.hidden = !message;
        };
        const pickMethod = (id, name) => {
            gatewayId = id;
            gatewayName = name;
            methodName.textContent = name;
            methodLabel.textContent = name;
            depositDialog.querySelectorAll('[data-deposit-agent]').forEach((card) => {
                card.hidden = card.dataset.gatewayId !== id;
            });
        };
        const pickAgent = (card) => {
            selectedAgent = card;
            accountInput.value = card.dataset.accountId;
            depositDialog.querySelectorAll('[data-deposit-agent]').forEach((candidate) => candidate.classList.toggle('selected', candidate === card));
            nextReference.disabled = false;
        };
        const fillConfirmation = () => {
            if (!selectedAgent) return;
            depositDialog.querySelector('[data-confirm-amount]').textContent = money(amountInput.value);
            depositDialog.querySelector('[data-confirm-bank]').textContent = selectedAgent.dataset.bankName;
            depositDialog.querySelector('[data-confirm-number]').textContent = selectedAgent.dataset.accountNumber;
            depositDialog.querySelector('[data-confirm-name]').textContent = selectedAgent.dataset.accountName;
            depositDialog.querySelector('[data-confirm-agent]').textContent = selectedAgent.dataset.agentName;
            const copyAmount = depositDialog.querySelector('[data-copy-amount]');
            if (copyAmount) copyAmount.dataset.copy = Number(amountInput.value || 0).toFixed(2);
            const copyNumber = depositDialog.querySelector('[data-copy-number]');
            if (copyNumber) copyNumber.dataset.copy = selectedAgent.dataset.accountNumber;
        };
        // Clears the form, never the draft: an accidental close is the case the draft exists for.
        const resetFlow = () => {
            form.reset();
            accountInput.value = '';
            selectedAgent = null;
            nextReference.disabled = true;
            depositDialog.querySelectorAll('[data-deposit-agent]').forEach((card) => card.classList.remove('selected'));
            showError('');
            steps.forEach((step) => step.classList.toggle('active', step.dataset.depositStep === 'amount'));
        };
        const openDraft = (draft, error, reference) => {
            const card = draft.accountId ? depositDialog.querySelector(`[data-deposit-agent][data-account-id="${draft.accountId}"]`) : null;
            const id = draft.gatewayId || (card ? card.dataset.gatewayId : '');
            const method = document.querySelector(`[data-deposit-method][data-gateway-id="${id}"]`);
            if (!method) { clearDraft(); return false; }
            resetFlow();
            pickMethod(id, method.dataset.gatewayName);
            amountInput.value = draft.amount || '';
            amountLabel.textContent = money(amountInput.value);
            if (card) { pickAgent(card); fillConfirmation(); }
            const step = card && draft.step === 'reference' ? 'reference' : 'agent';
            if (step === 'reference') {
                const input = form.querySelector('input[name="reference"]');
                if (input && reference) input.value = reference;
                showError(error);
            }
            showStep(step);
            depositDialog.showModal();
            return true;
        };

        document.querySelectorAll('[data-deposit-method]').forEach((button) => button.addEventListener('click', () => {
            resetFlow();
            pickMethod(button.dataset.gatewayId, button.dataset.gatewayName);
            depositDialog.showModal();
            setTimeout(() => amountInput.focus(), 50);
        }));

        depositDialog.querySelector('[data-deposit-next-agent]')?.addEventListener('click', () => {
            if (!amountInput.reportValidity()) return;
            amountLabel.textContent = money(amountInput.value);
            showStep('agent');
        });

        depositDialog.querySelectorAll('[data-deposit-agent]').forEach((card) => card.addEventListener('click', () => pickAgent(card)));

        nextReference?.addEventListener('click', () => {
            if (!selectedAgent || !accountInput.value) return;
            fillConfirmation();
            showStep('reference');
        });

        depositDialog.querySelector('[data-deposit-back-amount]')?.addEventListener('click', () => showStep('amount'));
        depositDialog.querySelector('[data-deposit-back-agent]')?.addEventListener('click', () => showStep('agent'));
        depositDialog.querySelectorAll('[data-deposit-close]').forEach((button) => button.addEventListener('click', () => depositDialog.close()));
        depositDialog.addEventListener('click', (event) => {
            if (event.target === depositDialog) depositDialog.close();
        });
        depositDialog.addEventListener('close', resetFlow);

        // What the page knows from the last request wins over the draft.
        if (depositDialog.dataset.depositSubmitted) {
            clearDraft();
        } else if (depositDialog.dataset.depositError) {
            openDraft({
                accountId: depositDialog.dataset.oldAccount,
                amount: depositDialog.dataset.oldAmount,
                step: 'reference',
            }, depositDialog.dataset.depositError, depositDialog.dataset.oldReference);
        } else if (resume) {
            const draft = readDraft();
            const card = draft && draft.accountId ? depositDialog.querySelector(`[data-deposit-agent][data-account-id="${draft.accountId}"]`) : null;
            const method = draft ? document.querySelector(`[data-deposit-method][data-gateway-id="${draft.gatewayId || (card ? card.dataset.gatewayId : '')}"]`) : null;
            if (draft && method && draft.amount) {
                resume.querySelector('[data-resume-amount]').textContent = money(draft.amount);
                resume.querySelector('[data-resume-bank]').textContent = method.dataset.gatewayName;
                resume.querySelector('[data-resume-agent]').textContent = card ? card.dataset.agentName : '…';
                resume.hidden = false;
                resume.querySelector('[data-deposit-resume-continue]')?.addEventListener('click', () => { resume.hidden = true; openDraft(draft); });
                resume.querySelector('[data-deposit-resume-discard]')?.addEventListener('click', () => { clearDraft(); resume.hidden = true; });
            } else if (draft) {
                clearDraft();
            }
        }
    }

    const stakeInput = document.querySelector('input[name="stake"]');
    document.querySelectorAll('[data-stake]').forEach((button) => {
        button.addEventListener('click', () => {
            if (stakeInput) stakeInput.value = button.dataset.stake;
        });
    });
});

/**
 * Game artwork loading state.
 *
 * The card shows a shimmer until its cover has actually decoded, so a slow
 * connection sees the grid's structure rather than empty boxes. Runs outside
 * DOMContentLoaded so images that were already cached and complete before this
 * script executes are marked immediately instead of shimmering forever.
 */
(function () {
    'use strict';

    function settle(art) {
        if (art) art.classList.add('is-loaded');
    }

    function bind(root) {
        (root || document).querySelectorAll('.game-art.has-image > .game-cover').forEach(function (img) {
            var art = img.parentElement;
            if (img.complete && img.naturalWidth > 0) {
                settle(art);
                return;
            }
            img.addEventListener('load', function () { settle(art); }, { once: true });
            // A broken cover must not leave the card shimmering for ever.
            img.addEventListener('error', function () { settle(art); }, { once: true });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { bind(document); });
    } else {
        bind(document);
    }

    // The lobby swaps in search results without a page load.
    window.addEventListener('lucy:games-rendered', function (event) {
        bind(event.detail && event.detail.root);
    });
})();

/**
 * Prediction pool card.
 *
 * Only present when an operator has published a pool, so everything here is a
 * no-op on an ordinary lobby. Runs the countdown, the score steppers, and
 * disables entry once the deadline passes — the server re-checks the deadline
 * on submit, so this is a courtesy rather than the guard.
 */
(function () {
    'use strict';

    function init() {
        const pool = document.querySelector('[data-prediction-pool]');
        if (!pool) return;

        const card = pool.querySelector('.pp-card');
        const output = pool.querySelector('[data-prediction-countdown]');
        const submit = pool.querySelector('.pp-submit');
        const closesAt = Date.parse(pool.dataset.closesAt || '');

        // A crest that 404s (missing storage symlink, deleted file) should fall
        // back to the initials underneath rather than show a broken image.
        pool.querySelectorAll('[data-pp-crest]').forEach(function (img) {
            if (img.complete && img.naturalWidth === 0) { img.remove(); return; }
            img.addEventListener('error', function () { img.remove(); }, { once: true });
        });

        // Steppers. Clamped to the same range the server validates.
        pool.querySelectorAll('[data-pp-step]').forEach(function (button) {
            button.addEventListener('click', function () {
                const input = document.getElementById('pp-' + button.dataset.ppTarget);
                if (!input) return;
                const next = (parseInt(input.value, 10) || 0) + parseInt(button.dataset.ppStep, 10);
                input.value = Math.min(20, Math.max(0, next));
            });
        });

        // Typing straight into the box must respect the same bounds.
        pool.querySelectorAll('.pp-stepper input').forEach(function (input) {
            input.addEventListener('change', function () {
                input.value = Math.min(20, Math.max(0, parseInt(input.value, 10) || 0));
            });
        });

        if (!output || Number.isNaN(closesAt)) return;

        const pad = function (n) { return String(n).padStart(2, '0'); };
        let timer;

        const close = function () {
            output.textContent = 'closed';
            card?.classList.add('is-closed');
            if (submit && submit.tagName === 'BUTTON') {
                submit.disabled = true;
                submit.textContent = 'Predictions closed';
            }
            window.clearInterval(timer);
        };

        const tick = function () {
            const left = Math.floor((closesAt - Date.now()) / 1000);
            if (left <= 0) { close(); return; }
            const d = Math.floor(left / 86400);
            const h = Math.floor((left % 86400) / 3600);
            output.textContent = (d > 0 ? d + 'd ' : '') + pad(h) + ':' + pad(Math.floor((left % 3600) / 60)) + ':' + pad(left % 60);
        };

        tick();
        timer = window.setInterval(tick, 1000);

        // A background tab does not need a per-second timer running.
        document.addEventListener('visibilitychange', function () {
            window.clearInterval(timer);
            if (!document.hidden) { tick(); timer = window.setInterval(tick, 1000); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/*
 * Wallet helpers (28 Oct 2026).
 *  - Withdrawal: only the agents who pay out with the chosen method are offered;
 *    the others are disabled so they neither show nor submit.
 *  - Deposit dialog: a preview of the payment screenshot before it is sent.
 *  - Pending deposit card: a screenshot uploads as soon as it is picked.
 */
(function () {
    'use strict';

    function bindWithdrawAgents() {
        const box = document.querySelector('[data-withdraw-agents]');
        if (!box) return;
        const options = [...box.querySelectorAll('.wd-agent-option')];
        const empty = box.querySelector('[data-withdraw-agents-empty]');
        const methods = [...document.querySelectorAll('input[name="payment_gateway_id"]')];
        const sync = () => {
            const chosen = methods.find((input) => input.checked);
            const gatewayId = chosen ? chosen.value : '';
            let visible = 0;
            options.forEach((option) => {
                const match = option.dataset.gatewayId === gatewayId;
                const input = option.querySelector('input');
                option.hidden = !match;
                input.disabled = !match;
                if (!match) input.checked = false;
                if (match) visible++;
            });
            if (empty) empty.hidden = visible > 0;
        };
        methods.forEach((input) => input.addEventListener('change', sync));
        sync();
    }

    function bindProofPreview() {
        const input = document.querySelector('[data-deposit-proof]');
        const preview = document.querySelector('[data-deposit-proof-preview]');
        if (!input || !preview) return;
        let url = '';
        const clear = () => {
            if (url) URL.revokeObjectURL(url);
            url = '';
            preview.hidden = true;
            preview.removeAttribute('src');
        };
        input.addEventListener('change', () => {
            clear();
            const file = input.files && input.files[0];
            if (!file || !file.type.startsWith('image/')) return;
            url = URL.createObjectURL(file);
            preview.src = url;
            preview.hidden = false;
        });
        input.form?.addEventListener('reset', clear);
    }

    function bindProofAutosubmit() {
        document.querySelectorAll('[data-proof-autosubmit]').forEach((input) => {
            const form = input.form;
            const send = form?.querySelector('.deposit-proof-send');
            if (send) send.hidden = true;   // with script, picking the picture is the upload
            input.addEventListener('change', () => {
                if (!input.files || !input.files.length) return;
                form.classList.add('is-uploading');
                form.submit();
            });
        });
    }

    const boot = () => { bindWithdrawAgents(); bindProofPreview(); bindProofAutosubmit(); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
