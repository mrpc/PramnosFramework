/**
 * A pop-up that closes by itself: a press outside it, or Escape, calls `onclose` — a Svelte action,
 * `<div use:dismiss={() => (open = false)}>`. A control marked `data-pf-toggle` (or the emoji
 * picker's `data-pf-emoji-toggle`) is the pop-up's own button and opens and closes it itself. It
 * starts listening on the next tick, so the press that opened it does not close it.
 *
 * Tested with `node --test tests/js/conversations-logic.test.js`.
 *
 * @param {Element} node
 * @param {() => void} onclose
 */
export function dismiss(node, onclose) {
    let close = onclose;
    const doc = node.ownerDocument;
    const outside = (event) => {
        const target = event.target;
        if (!node.contains(target) && !target?.closest?.('[data-pf-toggle], [data-pf-emoji-toggle]')) {
            close?.();
        }
    };
    const keys = (event) => {
        if (event.key === 'Escape') {
            close?.();
        }
    };
    const timer = setTimeout(() => {
        doc.addEventListener('pointerdown', outside, true);
        doc.addEventListener('keydown', keys);
    });

    return {
        update(next) {
            close = next;
        },
        destroy() {
            clearTimeout(timer);
            doc.removeEventListener('pointerdown', outside, true);
            doc.removeEventListener('keydown', keys);
        },
    };
}
