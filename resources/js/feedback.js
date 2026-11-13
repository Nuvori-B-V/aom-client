(() => {
    const script = document.currentScript;
    const MARKER = 'data-aom-feedback';
    const MAX_SELECTOR_LENGTH = 500;
    const SCREENSHOT_QUALITY = 0.7;
    const OUTLINE = '3px solid #c8432b';
    const XML_NAME = /^[A-Za-z_][\w.-]*$/;
    const UNAVAILABLE = 'Versturen lukt nu niet. Probeer het later opnieuw.';
    const ERRORS = {
        403: 'Feedback staat uit voor deze omgeving.',
        422: 'Controleer je bericht en probeer het opnieuw.',
        429: 'Je hebt te veel feedback verstuurd. Probeer het later opnieuw.',
    };
    const STROKE = 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"';
    const CHAT_ICON = `<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" ${STROKE}><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>`;
    const CURSOR_ICON = `<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" ${STROKE}><path d="M4 4l7 17 2.5-7.5L21 11z"/></svg>`;
    const CHECK_ICON = `<svg aria-hidden="true" width="24" height="24" viewBox="0 0 24 24" ${STROKE} stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>`;
    const AOM_LOGO = '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 30 30"><circle cx="15" cy="15" r="12" fill="none" stroke="currentColor" stroke-width="4"/><circle cx="15" cy="15" r="4.6" fill="#c8432b"/></svg>';

    const create = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        node.setAttribute(MARKER, '');
        node.className = className;
        node.textContent = text;

        return node;
    };

    const icon = (className, svg) => {
        const node = create('span', className);
        node.innerHTML = svg;

        return node;
    };

    const launcher = create('button', 'aom-fb-launcher', 'Feedback');
    launcher.type = 'button';
    launcher.prepend(icon('aom-fb-icon', CHAT_ICON));
    const hint = create('div', 'aom-fb-hint', 'Klik op het onderdeel waar je feedback over hebt. Esc om te stoppen.');
    hint.prepend(icon('aom-fb-icon', CURSOR_ICON));
    const highlight = create('div', 'aom-fb-highlight');
    const dialog = create('dialog', 'aom-fb-dialog');
    const content = create('div', 'aom-fb-body');
    const header = create('div');
    header.append(create('h2', 'aom-fb-title', 'Feedback geven'), create('p', 'aom-fb-subtitle', 'Over het onderdeel dat je aanklikte'));
    const shot = create('div', 'aom-fb-shot');
    const preview = create('img');
    preview.alt = 'Schermafbeelding';
    shot.append(preview, create('span', 'aom-fb-badge', 'Schermafbeelding'));
    const field = create('div', 'aom-fb-field');
    const label = create('label', 'aom-fb-label', 'Wat valt je op?');
    label.htmlFor = 'aom-fb-message';
    const message = create('textarea');
    message.id = 'aom-fb-message';
    message.placeholder = 'Bijvoorbeeld: deze knop doet niets als ik erop klik';
    message.maxLength = 5000;
    field.append(label, message);
    const thanks = create('div', 'aom-fb-thanks');
    thanks.append(icon('aom-fb-check', CHECK_ICON), 'Bedankt, je feedback is verstuurd.');
    const error = create('div', 'aom-fb-error');
    const actions = create('div', 'aom-fb-actions');
    const cancel = create('button', '', 'Annuleren');
    cancel.type = 'button';
    const send = create('button', 'aom-fb-primary', 'Versturen');
    send.type = 'button';

    const footer = create('div', 'aom-fb-footer', 'Powered by');
    const brand = create('span', 'aom-fb-brand', 'aom');
    brand.insertAdjacentHTML('afterbegin', AOM_LOGO);
    footer.append(brand);

    actions.append(cancel, send);
    content.append(header, thanks, shot, field, error, actions);
    dialog.append(content, footer);

    let selector = '';
    let screenshot = '';
    let sent = false;

    const show = (node, visible) => {
        node.style.display = visible ? '' : 'none';
    };

    const showSent = (visible) => {
        sent = visible;
        show(thanks, visible);
        show(header, !visible);
        show(shot, !visible && screenshot !== '');
        show(field, !visible);
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

    const stripNonXmlAttributes = (root) => {
        for (const element of [root, ...root.querySelectorAll('*')]) {
            for (const attribute of Array.from(element.attributes)) {
                if (attribute.namespaceURI === null && !XML_NAME.test(attribute.name)) {
                    element.removeAttributeNode(attribute);
                }
            }
        }
    };

    const capture = async (target) => {
        const outline = target.style?.outline ?? '';
        const offset = target.style?.outlineOffset ?? '';
        const serialize = XMLSerializer.prototype.serializeToString;

        target.style?.setProperty('outline', OUTLINE);
        target.style?.setProperty('outline-offset', '2px');
        XMLSerializer.prototype.serializeToString = function (node) {
            if (node instanceof Element && !node.isConnected) {
                stripNonXmlAttributes(node);
            }

            return serialize.call(this, node);
        };

        try {
            return await window.htmlToImage.toJpeg(document.body, {
                backgroundColor: '#fff',
                filter: (node) => !(node instanceof Element && node.hasAttribute(MARKER)),
                onImageErrorHandler: () => {},
                pixelRatio: 1,
                quality: SCREENSHOT_QUALITY,
            });
        } catch {
            return '';
        } finally {
            XMLSerializer.prototype.serializeToString = serialize;
            target.style?.setProperty('outline', outline);
            target.style?.setProperty('outline-offset', offset);
        }
    };

    const csrfHeaders = () => {
        const cookie = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

        return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) } : { 'X-CSRF-TOKEN': script.dataset.csrf };
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
                headers: { Accept: 'application/json', ...csrfHeaders() },
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
    document.body.append(launcher, hint, highlight, dialog);
})();
