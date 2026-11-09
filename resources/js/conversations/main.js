/**
 * `pf-conversations.js`: the conversations' dock drawn into `[data-pf-conversations-dock]`, which
 * carries what `Client::props()` gives it as `data-props`. Built by `npm run build:conversations`
 * into `scaffolding/assets/js/`, so an application serves it without a build of its own.
 */
import { mount } from 'svelte';
import DockApp from './DockApp.svelte';
import CanvasEditor from './CanvasEditor.svelte';
import { createApi } from './api.js';
import { setStrings } from './t.js';
import { configure } from './extras.js';

/** Draw the dock into an element, once, from what its `data-props` carries. */
function dock(element) {
    if (element.dataset.pfConversationsReady) {
        return;
    }
    element.dataset.pfConversationsReady = '1';
    let props = {};
    try {
        props = JSON.parse(element.dataset.props || '{}');
    } catch {
        return;
    }
    setStrings(props.strings);
    configure(props);
    element.textContent = '';
    mount(DockApp, { target: element, props: { api: createApi(props.base), props } });
}

function all() {
    document.querySelectorAll('[data-pf-conversations-dock]').forEach(dock);
}

/**
 * A canvas drawn into an element: `{base, canvasId, conversationId, me, strings}` — for an
 * application's own chat page, beside the dock. The page loads `pf-rich-editor.js` for editing.
 */
function canvas(element, props) {
    setStrings(props.strings);
    element.textContent = '';

    return mount(CanvasEditor, { target: element, props: { api: createApi(props.base), canvasId: props.canvasId, conversationId: props.conversationId, me: props.me, onclose: props.onclose || null } });
}

window.PfConversations = { dock, canvas };
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', all);
} else {
    all();
}
