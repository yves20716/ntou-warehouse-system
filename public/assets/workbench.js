(() => {
    function setup() {
        if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
        if (document.querySelector('dialog[data-workbench]')) return;
        const sections = [...document.querySelectorAll('section[data-drawer][id]')];
        if (!sections.length) return;
        const byId = new Map(sections.map((section) => [section.id, section]));
        const dialog = document.createElement('dialog');
        dialog.className = 'drawer';
        dialog.dataset.workbench = 'ready';
        dialog.setAttribute('aria-labelledby', 'workbench-title');
        const header = document.createElement('header');
        header.className = 'drawer-header';
        const title = document.createElement('h2');
        title.id = 'workbench-title';
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'drawer-close';
        close.textContent = '關閉';
        close.setAttribute('aria-label', '關閉面板');
        header.append(title, close);

        const confirmation = document.createElement('div');
        confirmation.className = 'drawer-confirm';
        confirmation.hidden = true;
        confirmation.setAttribute('role', 'alert');
        const message = document.createElement('p');
        message.textContent = '變更尚未儲存，要捨棄這次修改嗎？';
        const confirmActions = document.createElement('div');
        confirmActions.className = 'drawer-confirm-actions';
        const keep = document.createElement('button');
        keep.type = 'button';
        keep.className = 'button primary';
        keep.textContent = '保留編輯';
        const discard = document.createElement('button');
        discard.type = 'button';
        discard.className = 'button danger';
        discard.textContent = '捨棄變更';
        confirmActions.append(keep, discard);
        confirmation.append(message, confirmActions);
        const body = document.createElement('div');
        body.className = 'drawer-body';
        dialog.append(header, confirmation, body);
        document.body.append(dialog);
        sections.forEach((section) => {
            section.hidden = true;
            body.append(section);
        });

        let active = null;
        let baseline = '';
        let trigger = null;
        let pending = null;
        let editingFocus = null;
        let scrollPosition = [0, 0];
        let displayedError = null;
        let errorPlaceholder = null;

        function hashId() {
            try { return decodeURIComponent(location.hash.slice(1)); } catch { return ''; }
        }

        function snapshot(section) {
            return JSON.stringify([...section.querySelectorAll('input, select, textarea')].map((field) => {
                if (field.type === 'checkbox' || field.type === 'radio') return [field.name, field.checked];
                if (field.tagName === 'SELECT' && field.multiple) return [field.name, [...field.selectedOptions].map((option) => option.value)];
                return [field.name, field.value];
            }));
        }

        function dirty() { return active !== null && snapshot(active) !== baseline; }

        function hideConfirmation() {
            confirmation.hidden = true;
            pending = null;
        }

        function restoreDraftFocus() {
            hideConfirmation();
            if (editingFocus?.isConnected && dialog.contains(editingFocus)) editingFocus.focus({ preventScroll: true });
            else close.focus({ preventScroll: true });
        }

        function request(action) {
            if (!dirty()) { action(); return; }
            if (confirmation.hidden) editingFocus = document.activeElement;
            pending = action;
            confirmation.hidden = false;
            keep.focus({ preventScroll: true });
        }

        function closeDrawer() { dialog.close(); }

        function restoreError() {
            if (!displayedError) return;
            if (errorPlaceholder?.isConnected) errorPlaceholder.replaceWith(displayedError);
            else dialog.before(displayedError);
            dialog.removeAttribute('aria-describedby');
            displayedError = null;
            errorPlaceholder = null;
        }

        function activate(section, source = null, showInitialError = false) {
            restoreError();
            if (!dialog.open) {
                scrollPosition = [window.scrollX, window.scrollY];
                trigger = source || document.activeElement;
            }
            active = section;
            sections.forEach((item) => { item.hidden = item !== section; });
            title.textContent = section.querySelector('h2')?.textContent.trim() || '詳細內容';
            hideConfirmation();
            if (showInitialError) {
                const error = document.querySelector('#main .notice.error');
                if (error) {
                    displayedError = error;
                    errorPlaceholder = document.createComment('form error position');
                    error.before(errorPlaceholder);
                    if (!error.id) error.id = 'workbench-error';
                    dialog.insertBefore(error, confirmation);
                    dialog.setAttribute('aria-describedby', error.id);
                }
            }
            const stock = section.querySelector('#out-stock');
            const stockId = source?.dataset.stockId;
            if (stock && stockId && [...stock.options].some((option) => option.value === stockId && !option.disabled)) {
                stock.value = stockId;
                stock.dispatchEvent(new Event('change', { bubbles: true }));
            }
            baseline = snapshot(section);
            const url = new URL(location.href);
            url.hash = section.id;
            history.replaceState(history.state, '', url);
            document.body.classList.add('drawer-open');
            if (!dialog.open) dialog.showModal();
            const field = section.querySelector('[data-drawer-focus]') || section.querySelector('input:not([type="hidden"]):not(:disabled):not([readonly]), select:not(:disabled), textarea:not(:disabled), button, a[href]');
            (field || close).focus({ preventScroll: true });
            body.scrollTop = 0;
        }

        close.addEventListener('click', () => request(closeDrawer));
        dialog.addEventListener('cancel', (event) => {
            event.preventDefault();
            if (!confirmation.hidden) restoreDraftFocus();
            else request(closeDrawer);
        });
        dialog.addEventListener('click', (event) => {
            if (event.target !== dialog) return;
            const box = dialog.getBoundingClientRect();
            if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) request(closeDrawer);
        });
        keep.addEventListener('click', restoreDraftFocus);
        discard.addEventListener('click', () => {
            const action = pending;
            if (active) {
                active.querySelectorAll('form').forEach((form) => form.reset());
                active.querySelectorAll('input, select, textarea').forEach((field) => {
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                });
                baseline = snapshot(active);
            }
            hideConfirmation();
            action?.();
        });
        dialog.addEventListener('close', () => {
            restoreError();
            document.body.classList.remove('drawer-open');
            if (byId.has(hashId())) {
                const url = new URL(location.href);
                url.hash = '';
                history.replaceState(history.state, '', url);
            }
            hideConfirmation();
            active = null;
            if (trigger?.isConnected && !dialog.contains(trigger)) trigger.focus({ preventScroll: true });
            window.scrollTo(...scrollPosition);
        });

        document.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const link = event.target.closest('a[href]');
            if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
            const destination = new URL(link.href, location.href);
            const samePage = destination.origin === location.origin && destination.pathname === location.pathname && destination.search === location.search;
            let id;
            try { id = decodeURIComponent(destination.hash.slice(1)); } catch { return; }
            const section = samePage ? byId.get(id) : null;
            if (section) {
                event.preventDefault();
                if (active === section && !link.dataset.stockId) return;
                request(() => activate(section, link));
            } else if (dialog.open && dialog.contains(link) && dirty()) {
                event.preventDefault();
                request(() => location.assign(destination.href));
            }
        });
        window.addEventListener('hashchange', () => {
            const section = byId.get(hashId());
            if (section && section !== active) request(() => activate(section));
        });
        const initial = sections.find((section) => section.hasAttribute('data-open')) || byId.get(hashId());
        if (initial) activate(initial, null, initial.hasAttribute('data-open'));
    }

    // Body-level deferred scripts attach stock previews before the panel is opened.
    if (document.readyState === 'complete') setup();
    else document.addEventListener('DOMContentLoaded', setup, { once: true });
})();
