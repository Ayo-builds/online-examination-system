/**
 * The clipboard guard.
 *
 * During an exam nothing moves between the paper and anywhere else: no copy,
 * no cut, no paste, no drag, no right-click, no selecting the question text,
 * no printing or saving the page. Essay boxes are no exception; selecting
 * inside one still works, because editing needs it.
 *
 * Every listener is installed on the capture phase of the target, so it runs
 * before anything the page itself adds and cannot be skipped by a handler
 * that stops propagation.
 *
 * What this cannot stop is anything that never raises a paste: text typed by
 * software one keystroke at a time, the Windows emoji and voice panels, or a
 * console setting textarea.value. Those are signals to log, not events to
 * block, and they are measured elsewhere.
 *
 * Each block is reported as (action, route) through onBlocked, so the caller
 * can count them. Nothing is sent from here.
 */

// A clipboard event counts as keyboard-driven when its shortcut was pressed
// this recently. Long enough for a slow machine to raise the event, short
// enough that a browser-menu paste a minute later is not credited to it.
const ROUTE_WINDOW_MS = 1000;

// beforeinput is the backstop: it catches an insertion whose paste or drop
// event was somehow not cancelled. Ordinary typing, deleting and undo are
// deliberately absent - blocking those would break the essay box.
const BLOCKED_INPUT_TYPES = {
    insertFromPaste:            'paste',
    insertFromPasteAsQuotation: 'paste',
    insertFromYank:             'paste',
    insertFromDrop:             'drop',
    deleteByCut:                'cut',
    insertReplacementText:      'replace',   // spellcheck and autocorrect
};

export function installClipboardGuard(target, options = {}) {
    const now       = options.now || (() => Date.now());
    const onBlocked = options.onBlocked || (() => {});

    // The last shortcut seen, so a clipboard event can say how it was raised.
    let lastShortcut = null;   // { action, at }

    function block(e, action, route) {
        e.preventDefault();
        onBlocked(action, route);
    }

    function routeFor(action) {
        return lastShortcut !== null
            && lastShortcut.action === action
            && now() - lastShortcut.at <= ROUTE_WINDOW_MS
            ? 'keyboard' : 'other';
    }

    // Which clipboard-ish action a key combination asks for, if any. Alt is
    // excluded because Ctrl+Alt is AltGr on many layouts, where it types a
    // character rather than issuing a shortcut.
    function shortcutOf(e) {
        const key  = String(e.key || '').toLowerCase();
        const ctrl = (e.ctrlKey || e.metaKey) && !e.altKey;

        if (ctrl && key === 'c')                   return 'copy';
        if (ctrl && key === 'x')                   return 'cut';
        if (ctrl && key === 'v')                   return 'paste';   // with or without Shift
        if (ctrl && key === 'insert')              return 'copy';
        if (e.shiftKey && key === 'insert')        return 'paste';
        if (e.shiftKey && key === 'delete')        return 'cut';
        if (ctrl && key === 'p')                   return 'print';
        if (ctrl && key === 's')                   return 'save';
        if (key === 'contextmenu')                 return 'context_menu';
        if (e.shiftKey && key === 'f10')           return 'context_menu';
        return null;
    }

    const listeners = {
        keydown(e) {
            const action = shortcutOf(e);
            if (action === null) return;

            lastShortcut = { action, at: now() };

            // Print and save have no event of their own to cancel; the key
            // is the only place to stop them. Copy, cut and paste are left to
            // their clipboard events, which cover the menu routes as well.
            if (action === 'print' || action === 'save') {
                block(e, action, 'keyboard');
            }
        },

        copy(e)  { block(e, 'copy',  routeFor('copy')); },
        cut(e)   { block(e, 'cut',   routeFor('cut')); },
        paste(e) { block(e, 'paste', routeFor('paste')); },

        contextmenu(e) {
            block(e, 'context_menu', routeFor('context_menu') === 'keyboard' ? 'keyboard' : 'mouse');
        },

        // Dragging text out of the page, and dropping text in from anywhere.
        dragstart(e) { block(e, 'drag', 'mouse'); },
        drop(e)      { block(e, 'drop', 'mouse'); },

        beforeinput(e) {
            const action = BLOCKED_INPUT_TYPES[e.inputType];
            if (action !== undefined) block(e, action, 'other');
        },

        // Selecting is blocked outside the fields a candidate types into. A
        // drag over question text starts on a text node, which has no
        // closest() and so is cancelled like any other non-field.
        selectstart(e) {
            const el = e.target;
            if (el && typeof el.closest === 'function' && el.closest('textarea, input')) return;
            e.preventDefault();
        },
    };

    Object.keys(listeners).forEach((type) => {
        target.addEventListener(type, listeners[type], true);
    });
}
