/*
 * Drives the real public/js/confirm-action.js against a minimal DOM.
 *
 * This listener is the only thing standing between a click and an irreversible
 * delete on every destructive form in the admin. If it silently fails to fire,
 * nothing on screen says so — the form just submits. So it is exercised as
 * written rather than read.
 *
 * Run: node tests/confirm_action.node.js   (called by the PHP smoke test too)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let pass = 0;
let fail = 0;
function check(label, ok, detail) {
    if (ok) { pass++; console.log('  ok   ' + label); }
    else { fail++; console.log('  FAIL ' + label + (detail ? ' — ' + detail : '')); }
}

/** A document that records submit listeners and can fire one. */
const listeners = [];
const document = {
    addEventListener(type, fn, capture) { listeners.push({ type: type, fn: fn, capture: capture }); },
};

let answer = true;
let asked = [];
const windowStub = { confirm: function (message) { asked.push(message); return answer; } };

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'confirm-action.js'), 'utf8');
vm.runInNewContext(source, { document: document, window: windowStub });

console.log('— it listens at all —');
check('one submit listener is registered', listeners.length === 1 && listeners[0].type === 'submit');
check('in the capture phase', listeners[0].capture === true, String(listeners[0].capture));
const fire = listeners[0].fn;

/** A form element plus the event that would carry it. */
function submitEvent(attributes, tagName) {
    const form = {
        tagName: tagName || 'FORM',
        attributes: attributes,
        getAttribute: function (name) {
            return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
        },
    };
    return {
        target: form,
        defaultPrevented: false,
        propagationStopped: false,
        preventDefault: function () { this.defaultPrevented = true; },
        stopImmediatePropagation: function () { this.propagationStopped = true; },
    };
}

console.log('\n— saying yes lets the form go —');
answer = true; asked = [];
let event = submitEvent({ 'data-confirm': 'Delete this?' });
fire(event);
check('the operator was asked', asked.length === 1 && asked[0] === 'Delete this?', JSON.stringify(asked));
check('and the submit proceeds', event.defaultPrevented === false);

console.log('\n— saying no stops it —');
answer = false; asked = [];
event = submitEvent({ 'data-confirm': 'Delete this?' });
fire(event);
check('the submit is cancelled', event.defaultPrevented === true);
check('and nothing downstream runs', event.propagationStopped === true);

console.log('\n— a name with an apostrophe is passed through as text —');
// The exact case that broke the inline version.
answer = true; asked = [];
const tricky = "Delete agent O'Brien? It's permanent — \"really\".";
fire(submitEvent({ 'data-confirm': tricky }));
check('the message arrives intact', asked[0] === tricky, JSON.stringify(asked[0]));

console.log('\n— forms without the attribute are left alone —');
answer = false; asked = [];
event = submitEvent({});
fire(event);
check('nothing is asked', asked.length === 0);
check('and the submit is untouched', event.defaultPrevented === false);
event = submitEvent({ 'data-confirm': '' });
fire(event);
check('an empty message asks nothing', asked.length === 0 && event.defaultPrevented === false);

console.log('\n— and something that is not a form is ignored —');
answer = false; asked = [];
event = submitEvent({ 'data-confirm': 'Delete this?' }, 'DIV');
fire(event);
check('no prompt, no cancel', asked.length === 0 && event.defaultPrevented === false);

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
