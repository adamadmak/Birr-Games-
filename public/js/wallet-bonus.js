/*
 * Deposit screen: tells the player what the amount they typed will earn before
 * they pay. The figure is the same arithmetic the server uses when the deposit
 * is approved (percentage, capped by the largest single bonus), and the server
 * stays the authority: this only previews it.
 */
(function () {
    'use strict';

    var offer = document.querySelector('[data-deposit-bonus-offer]');
    var input = document.querySelector('[data-deposit-amount]');
    var preview = document.querySelector('[data-deposit-bonus-preview]');
    if (!offer || !input || !preview) return;

    var percent = parseFloat(offer.getAttribute('data-percent')) || 0;
    var max = parseFloat(offer.getAttribute('data-max')) || 0;
    var min = parseFloat(offer.getAttribute('data-min')) || 0;

    function money(value) {
        return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ETB';
    }

    function update() {
        var amount = parseFloat(input.value);
        if (!isFinite(amount) || amount <= 0) { preview.hidden = true; return; }

        if (amount < min) {
            preview.textContent = 'Deposit ' + money(min) + ' or more to earn the bonus.';
            preview.className = 'deposit-bonus-preview is-short';
            preview.hidden = false;
            return;
        }

        var bonus = Math.round(amount * percent) / 100;
        if (max > 0) bonus = Math.min(bonus, max);
        preview.textContent = 'You will also get +' + money(bonus) + ' play-only bonus once your deposit is approved.';
        preview.className = 'deposit-bonus-preview';
        preview.hidden = false;
    }

    input.addEventListener('input', update);
    update();
})();
