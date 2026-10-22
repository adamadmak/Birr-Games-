/*
 * Drives the real public/js/password-reveal.js against a minimal DOM.
 *
 * The behaviour that matters cannot be read off the file: that the button is
 * built as type="button" and so never submits the form it sits in, that a click
 * actually swaps the input between password and text, and that a revealed
 * password is hidden again when the form goes. A browser would be the natural
 * place to check it; there is none on this machine, so the script is loaded as
 * written and run against just enough DOM to answer those questions.
 *
 * Run: node tests/password_reveal.node.js   (called by the PHP smoke test too)
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

/** Appending a node MOVES it, as it does in a real document. */
function detach(node) {
    if (!node.parentNode) { return; }
    const at = node.parentNode.children.indexOf(node);
    if (at !== -1) { node.parentNode.children.splice(at, 1); }
    node.parentNode = null;
}

/** Just enough of an element for this script. */
function makeNode(tag) {
    return {
        tagName: tag.toUpperCase(),
        children: [],
        parentNode: null,
        dataset: {},
        attributes: {},
        listeners: {},
        className: '',
        innerHTML: '',
        title: '',
        tabIndex: 0,
        value: '',
        form: null,
        setAttribute(name, value) { this.attributes[name] = String(value); },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; },
        appendChild(child) { detach(child); child.parentNode = this; this.children.push(child); return child; },
        insertBefore(node, ref) {
            detach(node);
            node.parentNode = this;
            const at = this.children.indexOf(ref);
            this.children.splice(at === -1 ? this.children.length : at, 0, node);
            return node;
        },
        addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
        dispatch(type) { (this.listeners[type] || []).forEach((fn) => fn({ type })); },
        /* A button whose type is not "button" submits the form it is inside — the
           whole reason the script sets it — so the shim does that too, or the
           check below would pass on a button that submits. */
        click() {
            this.dispatch('click');
            if (this.tagName === 'BUTTON' && this.type !== 'button') {
                let node = this.parentNode;
                while (node && node.tagName !== 'FORM') { node = node.parentNode; }
                if (node) { node.dispatch('submit'); }
            }
        },
        focus() { this.focused = true; },
        setSelectionRange() {},
    };
}

const form = makeNode('form');
const label = makeNode('label');
const input = makeNode('input');
input.type = 'password';
input.value = 'Hunter2-secret';
input.form = form;
form.appendChild(label);
label.appendChild(input);

const document = {
    readyState: 'complete',
    createElement: makeNode,
    querySelectorAll(selector) {
        return selector === 'input[type="password"]'
            ? [input].filter((el) => el.type === 'password')
            : [];
    },
    addEventListener() {},
};

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'password-reveal.js'), 'utf8');
vm.runInNewContext(source, { document, window: {}, console });

console.log('— the control is built —');
const wrapper = input.parentNode;
check('the input is wrapped', wrapper && wrapper.className === 'pw-field', wrapper && wrapper.className);
check('the wrapper sits where the input was', label.children.length === 1 && label.children[0] === wrapper);
const button = wrapper.children.filter((c) => c.className === 'pw-reveal')[0];
check('a reveal button is added', !!button);
check('it is a button, never a submit', button && button.type === 'button', button && button.type);
check('it starts as "Show password"', button.getAttribute('aria-label') === 'Show password');
check('and is not pressed', button.getAttribute('aria-pressed') === 'false');
check('the field is still a password field', input.type === 'password');

console.log('\n— clicking shows what was typed —');
let submitted = 0;
form.addEventListener('submit', () => { submitted++; });
button.click();
check('the characters become readable', input.type === 'text', input.type);
check('the value is untouched', input.value === 'Hunter2-secret');
check('the label flips to Hide', button.getAttribute('aria-label') === 'Hide password');
check('and it reads as pressed', button.getAttribute('aria-pressed') === 'true');
check('clicking did NOT submit the form', submitted === 0, submitted + ' submit(s)');
check('the caret is put back in the box', input.focused === true);

console.log('\n— and clicking again hides it —');
button.click();
check('back to a password field', input.type === 'password');
check('back to Show', button.getAttribute('aria-label') === 'Show password');
check('still no submit', submitted === 0);

console.log('\n— a revealed password is not left on screen —');
button.click();
check('revealed again', input.type === 'text');
form.dispatch('submit');
check('submitting hides it', input.type === 'password');

console.log('\n— running twice does not double up —');
vm.runInNewContext(source, { document, window: {}, console });
check('only one button exists', wrapper.children.filter((c) => c.className === 'pw-reveal').length === 1);

console.log('\n' + pass + ' passed, ' + fail + ' failed');
process.exit(fail ? 1 : 0);
