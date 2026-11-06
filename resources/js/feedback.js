(() => {
    const script = document.currentScript;
    const MARKER = 'data-aom-feedback';
    const MAX_SELECTOR_LENGTH = 500;
    const SCREENSHOT_QUALITY = 0.7;
    const OUTLINE = '3px solid #dc2626';
    const GAP = '16px';
    const SMALL_GAP = '8px';
    const LAYER = 2147483646;
    const DARK = '#1f2937';
    const UNAVAILABLE = 'Versturen lukt nu niet. Probeer het later opnieuw.';
    const ERRORS = {
        403: 'Feedback staat uit voor deze omgeving.',
        422: 'Controleer je bericht en probeer het opnieuw.',
        429: 'Je hebt te veel feedback verstuurd. Probeer het later opnieuw.',
    };

    const create = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        node.setAttribute(MARKER, '');
        node.className = className;
        node.textContent = text;

        return node;
    };

    const style = create('style');
    style.textContent = `
        [${MARKER}] { box-sizing: border-box; font: 14px/1.4 system-ui, sans-serif; }
        .aom-fb-launcher { position: fixed; right: ${GAP}; bottom: ${GAP}; z-index: ${LAYER}; padding: ${SMALL_GAP} ${GAP}; border: 0; border-radius: 999px; background: ${DARK}; color: #fff; cursor: pointer; box-shadow: 0 2px 8px rgba(0, 0, 0, .2); }
        .aom-fb-hint { position: fixed; top: ${GAP}; left: 50%; transform: translateX(-50%); z-index: ${LAYER}; padding: ${SMALL_GAP} ${GAP}; border-radius: ${SMALL_GAP}; background: ${DARK}; color: #fff; }
        .aom-fb-highlight { position: fixed; z-index: ${LAYER - 1}; pointer-events: none; outline: ${OUTLINE}; outline-offset: 2px; }
        .aom-fb-dialog { width: min(480px, calc(100vw - 2 * ${GAP})); padding: ${GAP}; border: 0; border-radius: 12px; box-shadow: 0 8px 32px rgba(0, 0, 0, .25); }
        .aom-fb-dialog img { display: block; max-width: 100%; max-height: 200px; margin-bottom: ${SMALL_GAP}; border: 1px solid #e5e7eb; }
        .aom-fb-dialog textarea { display: block; width: 100%; min-height: 100px; padding: ${SMALL_GAP}; border: 1px solid #d1d5db; border-radius: 6px; }
        .aom-fb-error { margin-top: ${SMALL_GAP}; color: #dc2626; }
        .aom-fb-actions { display: flex; justify-content: flex-end; gap: ${SMALL_GAP}; margin-top: ${SMALL_GAP}; }
        .aom-fb-actions button { padding: 6px 12px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; cursor: pointer; }
        .aom-fb-actions .aom-fb-primary { border-color: ${DARK}; background: ${DARK}; color: #fff; }
    `;

    const launcher = create('button', 'aom-fb-launcher', 'Feedback');
    launcher.type = 'button';
    const hint = create('div', 'aom-fb-hint', 'Klik op het onderdeel waar je feedback over hebt. Esc om te stoppen.');
    const highlight = create('div', 'aom-fb-highlight');
    const dialog = create('dialog', 'aom-fb-dialog');
    const preview = create('img');
    preview.alt = 'Schermafbeelding';
    const message = create('textarea');
    message.placeholder = 'Wat valt je op?';
    message.maxLength = 5000;
    const thanks = create('p', '', 'Bedankt, je feedback is verstuurd.');
    const error = create('div', 'aom-fb-error');
    const actions = create('div', 'aom-fb-actions');
    const cancel = create('button', '', 'Annuleren');
    cancel.type = 'button';
    const send = create('button', 'aom-fb-primary', 'Versturen');
    send.type = 'button';

    actions.append(cancel, send);
    dialog.append(thanks, preview, message, error, actions);

    let selector = '';
    let screenshot = '';
    let sent = false;

    const show = (node, visible) => {
        node.style.display = visible ? '' : 'none';
    };

    const showSent = (visible) => {
        sent = visible;
        show(thanks, visible);
        show(preview, !visible && screenshot !== '');
        show(message, !visible);
        show(cancel, !visible);
        send.textContent = visible ? 'Sluiten' : 'Versturen';
    };

    const pickable = (target) => (target instanceof Element && target.closest(`[${MARKER}]`) === null ? target : null);

    const describe = (target) => {
        const parts = [];

        for (let node = target; node instanceof Element && node !== document.body && node !== document.documentElement; node = node.parentElement) {
            if (node.id) {
                parts.unshift(`#${CSS.escape(node.id)}`);
                break;
            }

            const siblings = Array.from(node.parentElement?.children ?? []).filter((sibling) => sibling.tagName === node.tagName);
            parts.unshift(`${node.tagName.toLowerCase()}:nth-of-type(${siblings.indexOf(node) + 1})`);
        }

        return (parts.join(' > ') || 'body').slice(0, MAX_SELECTOR_LENGTH);
    };

    const capture = async (target) => {
        const outline = target.style?.outline ?? '';
        const offset = target.style?.outlineOffset ?? '';

        target.style?.setProperty('outline', OUTLINE);
        target.style?.setProperty('outline-offset', '2px');

        try {
            return await window.htmlToImage.toJpeg(document.body, {
                backgroundColor: '#fff',
                filter: (node) => !(node instanceof Element && node.hasAttribute(MARKER)),
                pixelRatio: 1,
                quality: SCREENSHOT_QUALITY,
            });
        } catch {
            return '';
        } finally {
            target.style?.setProperty('outline', outline);
            target.style?.setProperty('outline-offset', offset);
        }
    };

    const asBlob = (dataUrl) => {
        const [header, data] = dataUrl.split(',');
        const bytes = Uint8Array.from(atob(data), (character) => character.charCodeAt(0));

        return new Blob([bytes], { type: header.slice(header.indexOf(':') + 1, header.indexOf(';')) });
    };

    const onPointerMove = (event) => {
        const target = pickable(event.target);
        show(highlight, target !== null);

        if (target !== null) {
            const rect = target.getBoundingClientRect();
            Object.assign(highlight.style, { left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px` });
        }
    };

    const onClick = (event) => {
        const target = pickable(event.target);

        if (target === null) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        choose(target);
    };

    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            stopPicking();
        }
    };

    const listen = (on) => {
        const method = on ? 'addEventListener' : 'removeEventListener';
        document[method]('pointermove', onPointerMove, true);
        document[method]('click', onClick, true);
        document[method]('keydown', onKeydown, true);
    };

    const startPicking = () => {
        show(hint, true);
        listen(true);
    };

    const stopPicking = () => {
        listen(false);
        show(hint, false);
        show(highlight, false);
    };

    const choose = async (target) => {
        stopPicking();
        selector = describe(target);
        screenshot = await capture(target);
        preview.src = screenshot;
        message.value = '';
        error.textContent = '';
        showSent(false);
        dialog.showModal();
        message.focus();
    };

    const finish = () => {
        dialog.close();
        selector = '';
        screenshot = '';
    };

    const submit = async () => {
        if (sent) {
            finish();

            return;
        }

        if (message.value.trim() === '' || send.disabled) {
            return;
        }

        const body = new FormData();
        body.append('message', message.value.trim());
        body.append('url', window.location.href);
        body.append('selector', selector);
        body.append('viewport', `${window.innerWidth}x${window.innerHeight}`);

        if (screenshot !== '') {
            body.append('screenshot', asBlob(screenshot), 'screenshot.jpg');
        }

        send.disabled = true;
        error.textContent = '';

        try {
            const response = await fetch(script.dataset.endpoint, {
                body,
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': script.dataset.csrf },
                method: 'POST',
            });

            if (!response.ok) {
                throw response.status;
            }

            showSent(true);
        } catch (status) {
            error.textContent = ERRORS[status] ?? UNAVAILABLE;
        } finally {
            send.disabled = false;
        }
    };

    launcher.addEventListener('click', startPicking);
    cancel.addEventListener('click', finish);
    send.addEventListener('click', submit);

    show(hint, false);
    show(highlight, false);
    document.head.append(style);
    document.body.append(launcher, hint, highlight, dialog);
})();
