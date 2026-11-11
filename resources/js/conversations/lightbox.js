/**
 * A message's picture opened large over the page, as in Slack: a blurred backdrop, the picture in
 * the middle, its name, Download and New tab, closed with ×, Escape or a click outside it; a
 * Ctrl/⌘/middle click on the thumbnail still opens a tab of its own. It zooms:
 *
 *   - the wheel, around the pointer; a double click or double tap, between fitted and 1:1 (200%
 *     for a picture smaller than the window); two fingers pinching; dragging pans a zoomed one;
 *   - the bar's −, its percentage (a click fits it again) and +; the keys + − 0;
 *   - from fitted to 800%.
 *
 * Plain DOM, no framework, so the dock and an application's own chat page both use it:
 *
 * ```js
 * import { installLightbox, openImage } from '…/resources/js/conversations/lightbox.js';
 * installLightbox(document, { download: 'Λήψη', tab: 'Νέα καρτέλα', close: 'Κλείσιμο' });
 * openImage(href, name, { labels, src: blobUrl });   // a desktop app's picture already in hand
 * ```
 *
 * The zoom's arithmetic is in pure functions (`fitScale`, `zoomAt`, `clampPan`), tested with
 * `node --test tests/js/lightbox.test.js`.
 */

/** The most it zooms: 800%. */
export const MAX_SCALE = 8;

/**
 * The scale that fits a picture in a view, never above its own size.
 *
 * @param {{w: number, h: number}} natural
 * @param {{w: number, h: number}} view
 */
export function fitScale(natural, view) {
    if (!natural.w || !natural.h) {
        return 1;
    }

    return Math.min(1, view.w / natural.w, view.h / natural.h);
}

/**
 * Pan a picture so it never leaves a gap: one smaller than the view along an axis is centred on
 * it, a larger one keeps its edges at or beyond the view's.
 *
 * @param {{scale: number, x: number, y: number}} state The picture's scale, and where its top left is
 * @param {{w: number, h: number}} natural
 * @param {{w: number, h: number}} view
 */
export function clampPan(state, natural, view) {
    const w = natural.w * state.scale;
    const h = natural.h * state.scale;
    const axis = (at, size, room) => (size <= room ? (room - size) / 2 : Math.min(0, Math.max(room - size, at)));

    return { scale: state.scale, x: axis(state.x, w, view.w), y: axis(state.y, h, view.h) };
}

/**
 * Zoom to a scale — between fitted and {@link MAX_SCALE} — keeping the point of the picture under
 * `point` where it is, then panned so no gap shows.
 *
 * @param {{scale: number, x: number, y: number}} state
 * @param {number} scale The scale wanted
 * @param {{x: number, y: number}} point In the view
 * @param {{w: number, h: number}} natural
 * @param {{w: number, h: number}} view
 */
export function zoomAt(state, scale, point, natural, view) {
    const fit = fitScale(natural, view);
    const next = Math.min(MAX_SCALE, Math.max(fit, scale));
    // The picture's own coordinates under the pointer stay under it.
    const px = (point.x - state.x) / state.scale;
    const py = (point.y - state.y) / state.scale;

    return clampPan({ scale: next, x: point.x - px * next, y: point.y - py * next }, natural, view);
}

/**
 * What a double click toggles to: fitted, from anything else; else 1:1 — or 200% for a picture
 * that already fits at its own size.
 */
export function toggleScale(state, natural, view) {
    const fit = fitScale(natural, view);
    if (Math.abs(state.scale - fit) > 0.001) {
        return fit;
    }

    return fit >= 1 ? 2 : 1;
}

let open = null;

/** Close the picture open, if any. */
export function closeImage() {
    if (open) {
        open.cleanup();
        open.box.remove();
        open = null;
    }
}

/**
 * Open a picture over the page.
 *
 * @param {string} href What Download and New tab point at
 * @param {string} name Its name
 * @param {{labels?: Record<string, string>, src?: string}} options `src`: a picture already in hand
 *        (a desktop application's blob URL), shown in place of `href`
 */
export function openImage(href, name, options = {}) {
    closeImage();
    const labels = options.labels || {};
    const doc = document;
    // Attributes, not properties: what any DOM reflects, and what a test's reads back.
    const el = (tag, attrs = {}, text = '') => {
        const node = doc.createElement(tag);
        for (const [name, value] of Object.entries(attrs)) {
            if (name === 'className') {
                node.className = value;
            } else if (value !== false) {
                node.setAttribute(name, String(value));
            }
        }
        if (text) {
            node.textContent = text;
        }

        return node;
    };
    const box = el('div', { className: 'pf-conv-lightbox' });
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', name || '');
    const bar = el('div', { className: 'pf-conv-lightbox-bar' });
    const title = el('span', { className: 'pf-conv-lightbox-name' }, name || '');
    const out = el('button', { type: 'button', className: 'pf-conv-lightbox-zoom' }, '−');
    const percent = el('button', { type: 'button', className: 'pf-conv-lightbox-percent' }, '100%');
    const into = el('button', { type: 'button', className: 'pf-conv-lightbox-zoom' }, '+');
    out.setAttribute('aria-label', labels.zoomOut || 'Zoom out');
    into.setAttribute('aria-label', labels.zoomIn || 'Zoom in');
    percent.setAttribute('aria-label', labels.fit || 'Fit to the window');
    // An icon button: the label is its title, its aria-label and a visually hidden text.
    const icon = (attrs, label, path) => {
        const link = el('a', { ...attrs, className: 'pf-conv-lightbox-action', title: label, 'aria-label': label });
        const svg = doc.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('aria-hidden', 'true');
        const line = doc.createElementNS('http://www.w3.org/2000/svg', 'path');
        line.setAttribute('d', path);
        svg.appendChild(line);
        link.append(svg, el('span', { className: 'pf-conv-lightbox-label' }, label));

        return link;
    };
    const download = icon({ href, download: name || '' }, labels.download || 'Download', 'M12 4v11m-5-5 5 5 5-5M5 20h14');
    const tab = icon({ href, target: '_blank', rel: 'noopener' }, labels.tab || 'Open in a new tab', 'M14 4h6v6m0-6-9 9M18 14v6H4V6h6');
    const shut = el('button', { type: 'button', className: 'pf-conv-lightbox-close' }, '×');
    shut.setAttribute('aria-label', labels.close || 'Close');
    shut.setAttribute('title', labels.close || 'Close');
    const group = el('span', { className: 'pf-conv-lightbox-zoomgroup' });
    group.append(out, percent, into);
    const actions = el('span', { className: 'pf-conv-lightbox-actions' });
    actions.append(group, download, tab, shut);
    bar.append(title, actions);
    const stage = el('div', { className: 'pf-conv-lightbox-stage' });
    const img = el('img', { src: options.src || href, alt: name || '', draggable: 'false' });
    stage.append(img);
    box.append(bar, stage);

    let state = { scale: 1, x: 0, y: 0 };
    const natural = () => ({ w: img.naturalWidth || img.width || 1, h: img.naturalHeight || img.height || 1 });
    const view = () => ({ w: stage.clientWidth || 1, h: stage.clientHeight || 1 });
    const draw = () => {
        img.style.transform = `translate(${state.x}px, ${state.y}px) scale(${state.scale})`;
        percent.textContent = Math.round(state.scale * 100) + '%';
        stage.classList.toggle('pf-conv-lightbox-zoomed', state.scale > fitScale(natural(), view()) + 0.001);
    };
    const fit = () => {
        state = clampPan({ scale: fitScale(natural(), view()), x: 0, y: 0 }, natural(), view());
        draw();
    };
    const zoom = (scale, point = { x: view().w / 2, y: view().h / 2 }) => {
        state = zoomAt(state, scale, point, natural(), view());
        draw();
    };
    const local = (event) => {
        const at = stage.getBoundingClientRect();

        return { x: event.clientX - at.left, y: event.clientY - at.top };
    };

    img.addEventListener('load', fit);
    out.addEventListener('click', () => zoom(state.scale / 1.25));
    into.addEventListener('click', () => zoom(state.scale * 1.25));
    percent.addEventListener('click', fit);
    shut.addEventListener('click', closeImage);
    box.addEventListener('click', (event) => {
        if (event.target === box || event.target === stage) {
            closeImage();
        }
    });
    stage.addEventListener('wheel', (event) => {
        event.preventDefault();
        zoom(state.scale * Math.exp(-event.deltaY * 0.002), local(event));
    }, { passive: false });
    stage.addEventListener('dblclick', (event) => zoom(toggleScale(state, natural(), view()), local(event)));

    // Pointers: one drags a zoomed picture, two pinch; a quick second tap toggles as a double click.
    const pointers = new Map();
    let pinch = null;
    let lastTap = 0;
    stage.addEventListener('pointerdown', (event) => {
        pointers.set(event.pointerId, local(event));
        stage.setPointerCapture?.(event.pointerId);
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y), scale: state.scale };
        }
        if (event.pointerType === 'touch' && pointers.size === 1) {
            const now = Date.now();
            if (now - lastTap < 300) {
                zoom(toggleScale(state, natural(), view()), local(event));
            }
            lastTap = now;
        }
    });
    stage.addEventListener('pointermove', (event) => {
        const before = pointers.get(event.pointerId);
        if (!before) {
            return;
        }
        const now = local(event);
        pointers.set(event.pointerId, now);
        if (pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            zoom(pinch.scale * Math.hypot(a.x - b.x, a.y - b.y) / Math.max(1, pinch.distance), { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 });
        } else if (pointers.size === 1) {
            state = clampPan({ scale: state.scale, x: state.x + now.x - before.x, y: state.y + now.y - before.y }, natural(), view());
            draw();
        }
    });
    const lift = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) {
            pinch = null;
        }
    };
    stage.addEventListener('pointerup', lift);
    stage.addEventListener('pointercancel', lift);

    const keys = (event) => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            closeImage();
        } else if (event.key === '+' || event.key === '=') {
            zoom(state.scale * 1.25);
        } else if (event.key === '-') {
            zoom(state.scale / 1.25);
        } else if (event.key === '0') {
            fit();
        }
    };
    const resized = () => fit();
    doc.addEventListener('keydown', keys);
    globalThis.addEventListener?.('resize', resized);
    open = { box, cleanup: () => { doc.removeEventListener('keydown', keys); globalThis.removeEventListener?.('resize', resized); } };
    doc.body.appendChild(box);
    if (img.complete && img.naturalWidth) {
        fit();
    }
    shut.focus();

    return box;
}

/**
 * Every message picture under `root` opens here — a link `a.pf-conv-thumb` around an `img` — but
 * a click with Ctrl, ⌘, Shift or another button opens it as the browser would.
 *
 * @param {Document|Element} root
 * @param {Record<string, string>} labels
 * @returns {() => void} Stop listening
 */
export function installLightbox(root, labels = {}) {
    const onClick = (event) => {
        const link = event.target.closest?.('a.pf-conv-thumb');
        if (!link || !root.contains(link) || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey) {
            return;
        }
        event.preventDefault();
        openImage(link.href || link.getAttribute('href'), link.getAttribute('data-name') || link.querySelector('img')?.getAttribute('alt') || '', { labels });
    };
    root.addEventListener('click', onClick);

    return () => root.removeEventListener('click', onClick);
}
