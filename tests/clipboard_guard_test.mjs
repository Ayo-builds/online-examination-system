/**
 * The clipboard guard: what it blocks, what it leaves alone, and how it names
 * the route each block came by.
 *
 *   node --test tests/clipboard_guard_test.mjs
 *
 * Node's own test runner, no packages. The events here are plain objects
 * shaped like the browser's, dispatched through a stand-in for document. That
 * proves the decisions; whether a real browser actually raises these events
 * for a given key or menu is the manual browser checklist's job, because no
 * test can press Win+V.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { installClipboardGuard } from '../public/assets/js/clipboard-guard.js';

// ---- A document stand-in ---------------------------------------------------

function fakeTarget() {
    const listeners = [];
    return {
        listeners,
        addEventListener(type, fn, capture) { listeners.push({ type, fn, capture }); },
        dispatch(e) {
            listeners.filter((l) => l.type === e.type).forEach((l) => l.fn(e));
            return e;
        },
    };
}

function ev(type, props = {}) {
    return {
        type,
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
        ...props,
    };
}

function key(k, mods = {}) {
    return ev('keydown', { key: k, ctrlKey: false, metaKey: false, shiftKey: false, altKey: false, ...mods });
}

/** A guard on a fresh target with a clock the test moves, recording blocks. */
function setup() {
    let now = 0;
    const blocked = [];
    const target = fakeTarget();
    installClipboardGuard(target, {
        now: () => now,
        onBlocked: (action, route) => blocked.push([action, route]),
    });
    return {
        target,
        blocked,
        advance(ms) { now += ms; },
        send(e) { return target.dispatch(e); },
    };
}

/** An element whose closest() matches only if it is one of the editable kinds. */
function element(tag) {
    return {
        nodeType: 1,
        tagName: tag.toUpperCase(),
        closest(sel) {
            return ['textarea', 'input'].includes(tag) && sel.includes(tag) ? this : null;
        },
    };
}

// ---- Installation ----------------------------------------------------------

test('every listener is on the capture phase', () => {
    const { target } = setup();
    assert.ok(target.listeners.length > 0);
    target.listeners.forEach((l) => assert.equal(l.capture, true, l.type + ' is not capturing'));
});

test('onBlocked is optional', () => {
    const target = fakeTarget();
    installClipboardGuard(target);
    assert.equal(target.dispatch(ev('paste')).defaultPrevented, true);
});

// ---- Clipboard events ------------------------------------------------------

test('copy, cut and paste are all cancelled', () => {
    const { send } = setup();
    ['copy', 'cut', 'paste'].forEach((type) => {
        assert.equal(send(ev(type)).defaultPrevented, true, type + ' got through');
    });
});

test('a paste straight after Ctrl+V is credited to the keyboard', () => {
    const { send, blocked, advance } = setup();
    send(key('v', { ctrlKey: true }));
    advance(50);
    send(ev('paste'));
    assert.deepEqual(blocked, [['paste', 'keyboard']]);
});

test('every keyboard paste, copy and cut shortcut is recognised', () => {
    const cases = [
        [key('v', { ctrlKey: true, shiftKey: true }), 'paste'],   // paste as plain text
        [key('Insert', { shiftKey: true }), 'paste'],
        [key('Insert', { ctrlKey: true }), 'copy'],
        [key('c', { ctrlKey: true }), 'copy'],
        [key('X', { ctrlKey: true }), 'cut'],                     // Caps Lock on
        [key('Delete', { shiftKey: true }), 'cut'],
    ];
    cases.forEach(([down, action]) => {
        const { send, blocked } = setup();
        send(down);
        send(ev(action));
        assert.deepEqual(blocked, [[action, 'keyboard']], down.key + ' for ' + action);
    });
});

test('a paste with no shortcut before it came some other way', () => {
    const { send, blocked } = setup();
    send(ev('paste'));
    assert.deepEqual(blocked, [['paste', 'other']]);
});

test('a shortcut too long ago does not claim a later paste', () => {
    const { send, blocked, advance } = setup();
    send(key('v', { ctrlKey: true }));
    advance(1001);
    send(ev('paste'));
    assert.deepEqual(blocked, [['paste', 'other']]);
});

test('a copy shortcut does not claim a paste', () => {
    const { send, blocked } = setup();
    send(key('c', { ctrlKey: true }));
    send(ev('paste'));
    assert.deepEqual(blocked, [['paste', 'other']]);
});

test('AltGr (Ctrl+Alt) is typing, not a shortcut', () => {
    const { send, blocked } = setup();
    send(key('v', { ctrlKey: true, altKey: true }));
    send(ev('paste'));
    assert.deepEqual(blocked, [['paste', 'other']]);
});

test('the clipboard keys themselves are not cancelled, so their events still fire', () => {
    const { send } = setup();
    ['c', 'x', 'v'].forEach((k) => {
        assert.equal(send(key(k, { ctrlKey: true })).defaultPrevented, false, 'Ctrl+' + k);
    });
});

// ---- Keys with no event of their own ---------------------------------------

test('Ctrl+P and Ctrl+S are cancelled at the key', () => {
    const { send, blocked } = setup();
    assert.equal(send(key('p', { ctrlKey: true })).defaultPrevented, true);
    assert.equal(send(key('s', { ctrlKey: true })).defaultPrevented, true);
    assert.deepEqual(blocked, [['print', 'keyboard'], ['save', 'keyboard']]);
});

test('ordinary typing is neither cancelled nor reported', () => {
    const { send, blocked } = setup();
    ['a', 'v', 'p', 's', 'Enter', 'Backspace', 'Delete', 'Insert', 'F10'].forEach((k) => {
        assert.equal(send(key(k)).defaultPrevented, false, k);
    });
    assert.equal(send(key('A', { shiftKey: true })).defaultPrevented, false);
    assert.equal(send(key('z', { ctrlKey: true })).defaultPrevented, false);   // undo
    assert.deepEqual(blocked, []);
});

// ---- Context menu ----------------------------------------------------------

test('the context menu is cancelled, and a right-click is the mouse', () => {
    const { send, blocked } = setup();
    assert.equal(send(ev('contextmenu')).defaultPrevented, true);
    assert.deepEqual(blocked, [['context_menu', 'mouse']]);
});

test('the Menu key and Shift+F10 open it from the keyboard', () => {
    [key('ContextMenu'), key('F10', { shiftKey: true })].forEach((down) => {
        const { send, blocked } = setup();
        send(down);
        assert.equal(send(ev('contextmenu')).defaultPrevented, true);
        assert.deepEqual(blocked, [['context_menu', 'keyboard']], down.key);
    });
});

// ---- Drag and drop ---------------------------------------------------------

test('dragging out and dropping in are both cancelled', () => {
    const { send, blocked } = setup();
    assert.equal(send(ev('dragstart')).defaultPrevented, true);
    assert.equal(send(ev('drop')).defaultPrevented, true);
    assert.deepEqual(blocked, [['drag', 'mouse'], ['drop', 'mouse']]);
});

// ---- The beforeinput backstop ----------------------------------------------

test('every insertion that is not typing is cancelled before it lands', () => {
    const expected = {
        insertFromPaste: 'paste',
        insertFromPasteAsQuotation: 'paste',
        insertFromYank: 'paste',
        insertFromDrop: 'drop',
        deleteByCut: 'cut',
        insertReplacementText: 'replace',
    };
    Object.entries(expected).forEach(([inputType, action]) => {
        const { send, blocked } = setup();
        assert.equal(send(ev('beforeinput', { inputType })).defaultPrevented, true, inputType);
        assert.deepEqual(blocked, [[action, 'other']], inputType);
    });
});

test('typing, deleting, undo and IME composition are left alone', () => {
    const { send, blocked } = setup();
    [
        'insertText', 'insertLineBreak', 'insertParagraph', 'insertCompositionText',
        'deleteContentBackward', 'deleteContentForward', 'deleteWordBackward',
        'historyUndo', 'historyRedo',
    ].forEach((inputType) => {
        assert.equal(send(ev('beforeinput', { inputType })).defaultPrevented, false, inputType);
    });
    assert.deepEqual(blocked, []);
});

// ---- Selection -------------------------------------------------------------

test('selecting question text is cancelled, on the element or its text node', () => {
    const { send } = setup();
    const p = element('p');
    assert.equal(send(ev('selectstart', { target: p })).defaultPrevented, true);
    assert.equal(send(ev('selectstart', { target: { nodeType: 3 } })).defaultPrevented, true);
});

test('selecting inside an essay box or an input still works', () => {
    const { send, blocked } = setup();
    assert.equal(send(ev('selectstart', { target: element('textarea') })).defaultPrevented, false);
    assert.equal(send(ev('selectstart', { target: element('input') })).defaultPrevented, false);
    assert.deepEqual(blocked, []);   // selection is blocked quietly, never reported
});

test('a selectstart with no usable target is cancelled rather than thrown on', () => {
    const { send } = setup();
    assert.equal(send(ev('selectstart', { target: null })).defaultPrevented, true);
    assert.equal(send(ev('selectstart', { target: { nodeType: 9 } })).defaultPrevented, true);
});
