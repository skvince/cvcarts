(function () {
    'use strict';

    if (window.__cvcartsAlertInitialized) return;
    window.__cvcartsAlertInitialized = true;

    function initialize() {
        const dialogs = document.querySelectorAll('dialog.app-modal');
        const transitionTime = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 1 : 180;
        let pendingForm = null;
        let pendingSubmitter = null;
        let pendingConfirmationResolver = null;
        let confirmDialog = null;
        let alertDialog = null;
        let alertResolver = null;
        let activeAlert = null;
        let alertOpen = false;
        const alertQueue = [];
        const openers = new WeakMap();
        const boundDialogs = new WeakSet();

    function initializeNavigation() {
        const header = document.querySelector('.top-header');
        const sidebar = document.querySelector('.app-layout .sidebar');
        if (!header || !sidebar) return;

        let toggle = header.querySelector('.mobile-menu-toggle');
        if (!toggle) {
            toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'mobile-menu-toggle';
            toggle.innerHTML = '<i class="bi bi-list hamburger-icon hamburger-icon-menu" aria-hidden="true"></i><i class="bi bi-x-lg hamburger-icon hamburger-icon-close" aria-hidden="true"></i>';
            toggle.setAttribute('aria-label', 'Open navigation menu');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-controls', 'app-sidebar');
            header.insertBefore(toggle, header.firstChild);
        }
        sidebar.id = sidebar.id || 'app-sidebar';

        let backdrop = document.querySelector('.sidebar-backdrop');
        if (!backdrop) {
            backdrop = document.createElement('button');
            backdrop.type = 'button';
            backdrop.className = 'sidebar-backdrop';
            backdrop.setAttribute('aria-label', 'Close navigation menu');
            document.body.appendChild(backdrop);
        }

        function closeDrawer() {
            document.body.classList.remove('nav-drawer-open');
            toggle.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Open navigation menu');
        }

        function setDrawerOpen(open) {
            document.body.classList.toggle('nav-drawer-open', open);
            toggle.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
        }

        toggle.addEventListener('click', function () {
            setDrawerOpen(!document.body.classList.contains('nav-drawer-open'));
        });
        backdrop.addEventListener('click', closeDrawer);
        sidebar.addEventListener('click', function (event) {
            if (event.target.closest('a')) closeDrawer();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeDrawer();
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth > 1050) closeDrawer();
        });
    }

    initializeNavigation();

    let filterRequestPending = false;

    function loadFilterPage(url, addHistory) {
        if (filterRequestPending) return;
        filterRequestPending = true;
        const currentMain = document.querySelector('.main-content');
        if (currentMain) currentMain.setAttribute('aria-busy', 'true');

        window.fetch(url.href, {
            credentials: 'same-origin',
            headers: { 'X-Cvcarts-Partial': '1' }
        }).then(function (response) {
            if (!response.ok) throw new Error('Filter request failed.');
            return response.text();
        }).then(function (html) {
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            const nextMain = nextDocument.querySelector('.main-content');
            const main = document.querySelector('.main-content');
            if (!nextMain || !main) throw new Error('The filtered page content was not available.');
            if (addHistory) window.history.pushState({}, '', url.href);
            document.title = nextDocument.title;
            main.removeAttribute('aria-busy');
            main.replaceChildren.apply(main, Array.from(nextMain.childNodes).map(function (node) {
                return document.importNode(node, true);
            }));
            filterRequestPending = false;
            document.dispatchEvent(new CustomEvent('cvcarts:content-updated'));
        }).catch(function (error) {
            console.error('CVCarts could not update the filtered results without reloading.', error);
            window.location.assign(url.href);
        });
    }

    document.addEventListener('popstate', function () {
        loadFilterPage(new URL(window.location.href), false);
    });

    document.addEventListener('click', function (event) {
        const resetLink = event.target.closest('[data-filter-reset]');
        if (!resetLink || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const url = new URL(resetLink.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        event.preventDefault();
        if (filterRequestPending) return;
        resetLink.setAttribute('aria-disabled', 'true');
        loadFilterPage(url, true);
    });

    function initializeRealtime() {
        const header = document.querySelector('.top-header');
        const sidebar = document.querySelector('.app-layout .sidebar');
        const appScript = Array.from(document.scripts).find(function (script) {
            return /\/assets\/js\/modal\.js(?:\?|$)/.test(script.src);
        });
        if (!header || !sidebar || !appScript || !window.EventSource || !window.fetch) return;

        const appBase = appScript.src.replace(/\/assets\/js\/modal\.js(?:\?.*)?$/, '');
        const stream = new EventSource(appBase + '/api/realtime/stream.php');

        const adminPage = sidebar.querySelector('a[href*="/admin/"]') !== null;
        const notificationLink = adminPage
            ? header.querySelector('.notification-menu > summary')
            : header.querySelector('a[href*="/student/notifications/"]');
        const sidebarNotificationLink = adminPage ? null : sidebar.querySelector('a[href*="/student/notifications/"]');
        let notificationBadge = notificationLink && notificationLink.querySelector('.notification-badge');
        let sidebarNotificationBadge = sidebarNotificationLink && sidebarNotificationLink.querySelector('.nav-sync-badge');
        const cartLink = sidebar.querySelector('a[href*="/student/cart/"]');
        let lastEventId = 0;
        let hasUnsubmittedChanges = false;
        let realtimeRefreshQueued = false;
        let refreshTimer = null;
        const liveRefreshPaths = [
            '/admin/dashboard.php',
            '/admin/inventory/index.php',
            '/admin/users/index.php',
            '/admin/order-status/index.php',
            '/admin/order-history/index.php',
            '/admin/ready-to-claim/index.php',
            '/admin/receipt.php',
            '/student/dashboard.php',
            '/student/shop/index.php',
            '/student/cart/index.php',
            '/student/checkout/index.php',
            '/student/order-status/index.php',
            '/student/order-history/index.php',
            '/student/notifications/index.php',
            '/student/receipt.php',
            '/admin/sales/index.php'
        ];

        function updateBadge(count) {
            if (!notificationLink) return;
            const safeCount = Math.max(0, Number(count) || 0);
            if (safeCount === 0) {
                if (notificationBadge) notificationBadge.remove();
                notificationBadge = null;
            } else {
                if (!notificationBadge) {
                    notificationBadge = document.createElement('span');
                    notificationBadge.className = 'notification-badge';
                    notificationLink.appendChild(notificationBadge);
                }
                notificationBadge.textContent = safeCount > 99 ? '99+' : String(safeCount);
            }
            notificationLink.setAttribute('aria-label', 'Notifications, ' + safeCount + ' unread');
            if (sidebarNotificationLink) {
                const icon = sidebarNotificationLink.querySelector('.nav-icon');
                Array.from(sidebarNotificationLink.childNodes).forEach(function (node) {
                    if (node.nodeType === Node.TEXT_NODE) node.textContent = ' Notifications ';
                });
                if (safeCount === 0) {
                    if (sidebarNotificationBadge) sidebarNotificationBadge.remove();
                    sidebarNotificationBadge = null;
                } else {
                    if (!sidebarNotificationBadge) {
                        sidebarNotificationBadge = document.createElement('span');
                        sidebarNotificationBadge.className = 'nav-sync-badge';
                        sidebarNotificationLink.appendChild(sidebarNotificationBadge);
                    }
                    sidebarNotificationBadge.textContent = safeCount > 99 ? '99+' : String(safeCount);
                }
                if (!icon) sidebarNotificationLink.setAttribute('aria-label', 'Notifications, ' + safeCount + ' unread');
            }
        }

        function updateAdminAlertCounts(state) {
            const dropdown = header.querySelector('.notification-dropdown');
            if (!dropdown) return;
            const pending = Math.max(0, Number(state.pending_payments) || 0);
            const ready = Math.max(0, Number(state.ready_orders) || 0);
            const lowStock = Array.isArray(state.low_stock) ? state.low_stock : [];
            dropdown.replaceChildren();

            const title = document.createElement('div');
            title.className = 'dropdown-title';
            const heading = document.createElement('strong');
            heading.textContent = 'Notifications';
            const summary = document.createElement('span');
            summary.textContent = String(Number(state.notification_count) || 0) + ' pending';
            title.append(heading, summary);
            dropdown.appendChild(title);

            function addItem(url, icon, label, detail) {
                const link = document.createElement('a');
                link.className = 'dropdown-item';
                link.href = appBase + url;
                const glyph = document.createElement('span');
                glyph.className = 'dropdown-item-icon';
                glyph.setAttribute('aria-hidden', 'true');
                const glyphIcon = document.createElement('i');
                glyphIcon.className = 'bi ' + icon;
                glyph.appendChild(glyphIcon);
                const copy = document.createElement('span');
                const strong = document.createElement('strong');
                strong.textContent = label;
                const small = document.createElement('small');
                small.textContent = detail;
                copy.append(strong, small);
                link.append(glyph, copy);
                dropdown.appendChild(link);
            }

            if (pending > 0) {
                addItem('/admin/order-status/index.php', 'bi-credit-card', pending + ' pending payment' + (pending === 1 ? '' : 's'),
                    (Number(state.payment_verification) || 0) + ' awaiting verification');
            }
            if (ready > 0) {
                addItem('/admin/ready-to-claim/index.php', 'bi-bag-check', ready + ' ready to claim',
                    'Scan the claiming QR code to release orders');
            }
            lowStock.slice(0, 5).forEach(function (item) {
                if (!item || typeof item.label !== 'string') return;
                addItem('/admin/inventory/index.php', 'bi-exclamation-triangle', 'Low stock: ' + item.label,
                    'Only ' + (Number(item.stock) || 0) + ' left in stock');
            });

            if (pending === 0 && ready === 0 && lowStock.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'dropdown-empty';
                empty.textContent = "You're all caught up. No low-stock or pending-payment alerts.";
                dropdown.appendChild(empty);
            } else {
                const target = pending > 0 ? '/admin/order-status/index.php'
                    : (ready > 0 ? '/admin/ready-to-claim/index.php' : '/admin/inventory/index.php');
                const label = pending > 0 ? 'Review payments'
                    : (ready > 0 ? 'Ready to Claim' : 'View inventory');
                const footer = document.createElement('a');
                footer.className = 'dropdown-footer';
                footer.href = appBase + target;
                footer.textContent = label;
                dropdown.appendChild(footer);
            }
        }

        function refreshCounters() {
            window.fetch(appBase + '/api/realtime/state.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Realtime state request failed.');
                return response.json();
            }).then(function (state) {
                if (!state || typeof state.notification_count !== 'number') return;
                updateBadge(state.notification_count);
                if (state.role === 'admin') updateAdminAlertCounts(state);
                if (cartLink && typeof state.cart_count === 'number') {
                    const icon = cartLink.querySelector('.nav-icon');
                    const label = cartLink.lastChild;
                    if (label && label.nodeType === Node.TEXT_NODE) {
                        label.textContent = ' My Cart (' + state.cart_count + ')';
                    } else if (icon) {
                        cartLink.appendChild(document.createTextNode(' My Cart (' + state.cart_count + ')'));
                    }
                }
            }).catch(function () {
                console.error('CVCarts could not refresh the synchronized header counters.');
            });
        }

        function hasFocusedControl(main) {
            const activeElement = document.activeElement;
            return !!(activeElement && main.contains(activeElement)
                && activeElement.matches('input:not([type="hidden"]), select, textarea, button, [contenteditable="true"]'));
        }

        function refreshMainContent() {
            const path = window.location.pathname.replace(appBase.replace(window.location.origin, ''), '') || '/';
            const main = document.querySelector('.main-content');
            if (!main || liveRefreshPaths.indexOf(path) === -1) return;
            if (filterRequestPending) {
                realtimeRefreshQueued = true;
                return;
            }
            if (hasFocusedControl(main)) {
                realtimeRefreshQueued = true;
                return;
            }
            if (hasUnsubmittedChanges || document.querySelector('dialog[open], .modal.show')
                || main.querySelector('[data-app-alert="true"]:not([hidden]), .flash-message:not([hidden])')) return;

            window.fetch(window.location.href, {
                credentials: 'same-origin',
                headers: { 'X-Cvcarts-Partial': '1' }
            }).then(function (response) {
                if (!response.ok) throw new Error('Page update request failed.');
                return response.text();
            }).then(function (html) {
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                const nextMain = nextDocument.querySelector('.main-content');
                const currentMain = document.querySelector('.main-content');
                if (!nextMain || !currentMain || hasUnsubmittedChanges || document.querySelector('dialog[open], .modal.show')) return;
                if (hasFocusedControl(currentMain)) {
                    realtimeRefreshQueued = true;
                    return;
                }
                const tableScrollPositions = Array.from(currentMain.querySelectorAll('.table-wrapper, .recent-orders-scroll'))
                    .map(function (container) {
                        return { left: container.scrollLeft, top: container.scrollTop };
                    });
                const pageScrollX = window.scrollX;
                const pageScrollY = window.scrollY;
                currentMain.replaceChildren.apply(currentMain, Array.from(nextMain.childNodes).map(function (node) {
                    return document.importNode(node, true);
                }));
                window.requestAnimationFrame(function () {
                    window.scrollTo(pageScrollX, pageScrollY);
                    Array.from(currentMain.querySelectorAll('.table-wrapper, .recent-orders-scroll'))
                        .forEach(function (container, index) {
                            const position = tableScrollPositions[index];
                            if (!position) return;
                            container.scrollLeft = position.left;
                            container.scrollTop = position.top;
                        });
                });
                document.dispatchEvent(new CustomEvent('cvcarts:content-updated'));
            }).catch(function () {
                console.error('CVCarts could not refresh the current page content.');
            });
        }

        document.addEventListener('input', function (event) {
            if (event.target.closest('form')) hasUnsubmittedChanges = true;
        }, true);
        document.addEventListener('change', function (event) {
            if (event.target.closest('form')) hasUnsubmittedChanges = true;
        }, true);
        document.addEventListener('submit', function () {
            hasUnsubmittedChanges = false;
        }, true);
        document.addEventListener('focusout', function () {
            window.setTimeout(function () {
                const main = document.querySelector('.main-content');
                if (!realtimeRefreshQueued || hasUnsubmittedChanges || !main || hasFocusedControl(main)) return;
                realtimeRefreshQueued = false;
                refreshMainContent();
            }, 0);
        }, true);
        document.addEventListener('cvcarts:content-updated', function () {
            hasUnsubmittedChanges = false;
            if (realtimeRefreshQueued) {
                realtimeRefreshQueued = false;
                window.setTimeout(refreshMainContent, 0);
            }
        });

        stream.addEventListener('auth.expired', function () {
            stream.close();
        });
        stream.addEventListener('cvcarts', function (event) {
            const eventId = Number(event.lastEventId || 0);
            if (eventId && eventId <= lastEventId) return;
            lastEventId = Math.max(lastEventId, eventId);
            let payload;
            try {
                payload = JSON.parse(event.data);
            } catch (error) {
                return;
            }
            if (!payload || typeof payload.type !== 'string') return;
            window.dispatchEvent(new CustomEvent('cvcarts:realtime', { detail: payload }));
            refreshCounters();
            window.clearTimeout(refreshTimer);
            refreshTimer = window.setTimeout(refreshMainContent, 300);
        });
        refreshCounters();
    }

    initializeRealtime();

    function bindDialog(dialog) {
        if (boundDialogs.has(dialog)) return;
        boundDialogs.add(dialog);
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog(dialog);
        });
        dialog.addEventListener('close', function () {
            dialog.classList.remove('is-opening', 'is-closing');
            const opener = openers.get(dialog);
            if (opener && opener.isConnected) opener.focus();
        });
    }

    function openDialog(dialog, opener) {
        if (dialog.open) return;
        if (opener) openers.set(dialog, opener);
        dialog.classList.remove('is-closing');
        dialog.classList.add('is-opening');
        dialog.showModal();
        const focusTarget = dialog.querySelector('[autofocus], input:not([type="hidden"]):not([hidden]), select:not([hidden]), textarea:not([hidden]), button:not([data-modal-close]):not([hidden])')
            || dialog.querySelector('[data-modal-close]');
        if (focusTarget) focusTarget.focus();
        else {
            dialog.setAttribute('tabindex', '-1');
            dialog.focus();
        }
        window.setTimeout(function () {
            dialog.classList.remove('is-opening');
        }, 20);
    }

    function animateClose(dialog, onClosed) {
        if (!dialog.open) {
            if (onClosed) onClosed();
            return;
        }
        if (dialog.classList.contains('is-closing')) return;
        dialog.classList.remove('is-opening');
        dialog.classList.add('is-closing');
        window.setTimeout(function () {
            if (dialog.open && dialog.classList.contains('is-closing')) dialog.close();
            if (onClosed) onClosed();
        }, transitionTime);
    }

    function formSnapshot(form) {
        return Array.from(form.elements).map(function (field) {
            if (!field.name) return '';
            if (field.type === 'file') {
                return field.name + ':' + Array.from(field.files || []).map(function (file) { return file.name; }).join(',');
            }
            if (field.type === 'checkbox' || field.type === 'radio') {
                return field.name + ':' + field.value + ':' + String(field.checked);
            }
            return field.name + ':' + field.value;
        }).join('|');
    }

    function isDirty(dialog) {
        const form = dialog.querySelector('form[data-modal-form]');
        if (form && form.dataset.initialSnapshot !== formSnapshot(form)) return true;
        return dialog.dataset.initialSnapshot !== undefined && dialog.dataset.initialSnapshot !== dialog.dataset.currentSnapshot;
    }

    function closeDialog(dialog) {
        if (dialog === alertDialog && activeAlert && activeAlert.type === 'loading') return;
        if (dialog !== confirmDialog && isDirty(dialog) && dialog.dataset.discardConfirmed !== 'true') {
            window.cvcartsConfirm('Discard your unsaved changes?', {
                title: 'Discard changes?',
                confirmLabel: 'Discard',
                cancelLabel: 'Keep Editing',
                type: 'warning'
            }).then(function (confirmed) {
                if (!confirmed) return;
                dialog.dataset.discardConfirmed = 'true';
                closeDialog(dialog);
            });
            return;
        }
        delete dialog.dataset.discardConfirmed;
        if (dialog === confirmDialog) {
            pendingForm = null;
            pendingSubmitter = null;
            if (pendingConfirmationResolver) {
                const resolve = pendingConfirmationResolver;
                pendingConfirmationResolver = null;
                resolve(false);
            }
        }
        animateClose(dialog);
    }

    dialogs.forEach(bindDialog);
    document.addEventListener('cvcarts:content-updated', function () {
        document.querySelectorAll('dialog.app-modal').forEach(bindDialog);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || event.defaultPrevented) return;
        const openDialogs = document.querySelectorAll('dialog.app-modal[open]');
        const topDialog = openDialogs[openDialogs.length - 1];
        if (!topDialog) return;
        event.preventDefault();
        closeDialog(topDialog);
    }, true);

    document.addEventListener('click', function (event) {
        const logoutLink = event.target.closest('a[href]');
        if (logoutLink && new URL(logoutLink.href, window.location.href).pathname.endsWith('/auth/logout.php')) {
            event.preventDefault();
            if (logoutLink.dataset.confirmPending === 'true') return;
            logoutLink.dataset.confirmPending = 'true';
            window.cvcartsConfirm('Are you sure you want to log out?', {
                title: 'Log out?',
                confirmLabel: 'Log Out',
                cancelLabel: 'Stay Signed In',
                type: 'warning',
                opener: logoutLink
            }).then(function (confirmed) {
                delete logoutLink.dataset.confirmPending;
                if (confirmed && logoutLink.isConnected) window.location.assign(logoutLink.href);
            });
            return;
        }

        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            const dialog = document.getElementById(opener.dataset.modalOpen);
            if (dialog && typeof dialog.showModal === 'function') {
                const form = dialog.querySelector('form[data-modal-form]');
                if (form) form.dataset.initialSnapshot = formSnapshot(form);
                openDialog(dialog, opener);
            }
            return;
        }

        const closer = event.target.closest('[data-modal-close]');
        if (closer) {
            const dialog = closer.closest('dialog.app-modal');
            if (dialog) closeDialog(dialog);
            return;
        }

        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('app-modal')) {
            const bounds = event.target.getBoundingClientRect();
            if (event.target.open && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) {
                closeDialog(event.target);
            }
        }
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const submitter = event.submitter || null;
        if (form.matches('[data-filter-form]') && form.method.toLowerCase() === 'get') {
            event.preventDefault();
            if (form.dataset.filterSubmitting === 'true' || filterRequestPending) return;

            const url = new URL(form.action || window.location.href, window.location.href);
            if (url.origin !== window.location.origin) {
                form.submit();
                return;
            }
            url.search = new URLSearchParams(new FormData(form)).toString();
            url.searchParams.delete('page');
            form.dataset.filterSubmitting = 'true';
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
                button.disabled = true;
                button.classList.add('is-loading');
            });
            if (submitter && submitter instanceof HTMLButtonElement) {
                submitter.textContent = submitter.dataset.loadingLabel || 'Applying…';
            }
            loadFilterPage(url, true);
            return;
        }
        if (form.matches('[data-ajax-submit]')) return;
        const confirmationText = (submitter && submitter.dataset.confirm) || form.dataset.confirm;

        if (confirmationText && form.dataset.confirmed !== 'true') {
            event.preventDefault();
            pendingForm = form;
            pendingSubmitter = submitter;
            const message = document.getElementById('app-confirm-message');
            if (message) message.textContent = confirmationText;
            const title = document.getElementById('app-confirm-title');
            if (title) title.textContent = (submitter && submitter.dataset.confirmTitle) || 'Confirm action';
            const confirmButton = document.getElementById('app-confirm-submit');
            const cancelButton = document.getElementById('app-confirm-cancel');
            if (confirmButton) confirmButton.textContent = (submitter && submitter.dataset.confirmLabel) || 'Confirm';
            if (cancelButton) cancelButton.textContent = (submitter && submitter.dataset.cancelLabel) || 'Cancel';
            const icon = document.querySelector('#app-confirm-dialog .app-alert-icon');
            if (icon) {
                const type = (submitter && submitter.dataset.confirmType) || 'warning';
                icon.className = 'app-alert-icon app-alert-icon--' + (['success', 'error', 'warning', 'info'].indexOf(type) === -1 ? 'warning' : type);
                icon.textContent = type === 'success' ? '✓' : (type === 'error' ? '×' : (type === 'info' ? 'i' : '!'));
            }
            const confirmation = document.getElementById('app-confirm-dialog');
            if (confirmation && typeof confirmation.showModal === 'function') openDialog(confirmation, submitter);
            return;
        }

        if (form.dataset.confirmed === 'true') delete form.dataset.confirmed;
        if (form.dataset.submitting === 'true') {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('input[data-submitter-marker]').forEach(function (marker) {
            marker.remove();
        });
        if (submitter && submitter.name && (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement)) {
            const marker = document.createElement('input');
            marker.type = 'hidden';
            marker.name = submitter.name;
            marker.value = submitter.value;
            marker.dataset.submitterMarker = 'true';
            form.appendChild(marker);
        }
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
            button.disabled = true;
            button.classList.add('is-loading');
        });
        if (submitter && submitter instanceof HTMLButtonElement) {
            submitter.textContent = submitter.dataset.loadingLabel || 'Please wait…';
            submitter.setAttribute('aria-label', submitter.dataset.loadingLabel || 'Please wait');
        } else if (submitter instanceof HTMLInputElement) {
            submitter.value = submitter.dataset.loadingLabel || 'Please wait…';
        }
    }, true);

    confirmDialog = document.getElementById('app-confirm-dialog');
    if (!confirmDialog) {
        confirmDialog = document.createElement('dialog');
        confirmDialog.id = 'app-confirm-dialog';
        confirmDialog.className = 'app-modal confirm-dialog';
        confirmDialog.setAttribute('aria-labelledby', 'app-confirm-title');
        confirmDialog.setAttribute('aria-describedby', 'app-confirm-message');
        confirmDialog.innerHTML = '<div class="modal-shell"><div class="app-alert-icon app-alert-icon--warning" aria-hidden="true">!</div><header class="modal-header"><h2 id="app-confirm-title">Confirm action</h2></header><div class="modal-body"><p id="app-confirm-message"></p></div><footer class="modal-footer"><button id="app-confirm-cancel" class="btn btn-small btn-secondary" type="button" autofocus>Cancel</button><button id="app-confirm-submit" class="btn btn-small" type="button">Confirm</button></footer></div>';
        document.body.appendChild(confirmDialog);
    }
    bindDialog(confirmDialog);

    const confirmButton = document.getElementById('app-confirm-submit');
    const cancelButton = document.getElementById('app-confirm-cancel');
    if (confirmButton && cancelButton && confirmDialog) {
        confirmButton.addEventListener('click', function () {
            if (!pendingForm) {
                if (!pendingConfirmationResolver) return;
                const resolve = pendingConfirmationResolver;
                pendingConfirmationResolver = null;
                animateClose(confirmDialog, function () { resolve(true); });
                return;
            }
            const form = pendingForm;
            const submitter = pendingSubmitter;
            pendingForm = null;
            pendingSubmitter = null;
            if (pendingConfirmationResolver) {
                const resolve = pendingConfirmationResolver;
                pendingConfirmationResolver = null;
                resolve(true);
            }
            form.dataset.confirmed = 'true';
            animateClose(confirmDialog, function () {
                if (submitter && submitter.form === form) form.requestSubmit(submitter);
                else form.requestSubmit();
            });
        });
        cancelButton.addEventListener('click', function () {
            pendingForm = null;
            pendingSubmitter = null;
            if (pendingConfirmationResolver) {
                const resolve = pendingConfirmationResolver;
                pendingConfirmationResolver = null;
                resolve(false);
            }
            animateClose(confirmDialog);
        });
        confirmDialog.addEventListener('click', function (event) {
            if (event.target === confirmDialog) {
                pendingForm = null;
                pendingSubmitter = null;
                if (pendingConfirmationResolver) {
                    const resolve = pendingConfirmationResolver;
                    pendingConfirmationResolver = null;
                    resolve(false);
                }
                animateClose(confirmDialog);
            }
        });
    }

    window.cvcartsConfirm = function (message, options) {
        const settings = options || {};
        const title = document.getElementById('app-confirm-title');
        const body = document.getElementById('app-confirm-message');
        const icon = confirmDialog.querySelector('.app-alert-icon');
        const confirm = document.getElementById('app-confirm-submit');
        const cancel = document.getElementById('app-confirm-cancel');
        if (title) title.textContent = settings.title || 'Confirm action';
        if (body) body.textContent = String(message || 'Continue with this action?');
        if (confirm) confirm.textContent = settings.confirmLabel || 'Confirm';
        if (cancel) cancel.textContent = settings.cancelLabel || 'Cancel';
        if (icon) {
            const type = ['success', 'error', 'warning', 'info'].indexOf(settings.type) === -1 ? 'warning' : settings.type;
            icon.className = 'app-alert-icon app-alert-icon--' + type;
            icon.textContent = type === 'success' ? '✓' : (type === 'error' ? '×' : (type === 'info' ? 'i' : '!'));
        }
        return new Promise(function (resolve) {
            pendingConfirmationResolver = resolve;
            openDialog(confirmDialog, settings.opener || null);
        });
    };

    window.cvcartsAlert = function (type, titleText, message, buttonLabel) {
        const alertType = ['success', 'error', 'warning', 'info', 'loading'].indexOf(type) === -1 ? 'info' : type;
        return new Promise(function (resolve) {
            alertQueue.push({
                type: alertType,
                title: titleText,
                message: message,
                buttonLabel: buttonLabel,
                resolve: resolve
            });
            showNextAlert();
        });
    };

    window.showSuccess = function (title, message) {
        return window.cvcartsAlert('success', title, message);
    };
    window.showError = function (title, message) {
        return window.cvcartsAlert('error', title, message);
    };
    window.showWarning = function (title, message) {
        return window.cvcartsAlert('warning', title, message);
    };
    window.showInfo = function (title, message) {
        return window.cvcartsAlert('info', title, message);
    };
    window.showConfirm = function (title, message, onConfirm) {
        return window.cvcartsConfirm(message, { title: title }).then(function (confirmed) {
            if (confirmed && typeof onConfirm === 'function') onConfirm();
            return confirmed;
        });
    };
    window.showLoading = function (title, message) {
        return window.cvcartsAlert('loading', title || 'Please wait', message || 'Your request is being processed.');
    };
    window.closeAlert = function () {
        if (alertDialog && alertDialog.open) animateClose(alertDialog);
    };

    function showNextAlert() {
        if (alertOpen || !alertQueue.length) return;
        if (!alertDialog) {
            alertDialog = document.createElement('dialog');
            alertDialog.id = 'app-alert-dialog';
            alertDialog.className = 'app-modal app-alert-dialog';
            alertDialog.setAttribute('aria-labelledby', 'app-alert-title');
            alertDialog.setAttribute('aria-describedby', 'app-alert-message');
            alertDialog.setAttribute('role', 'alertdialog');
            alertDialog.innerHTML = '<div class="modal-shell"><div class="app-alert-icon" aria-hidden="true"></div><header class="modal-header"><h2 id="app-alert-title"></h2></header><div class="modal-body"><p id="app-alert-message" role="status" aria-live="polite"></p></div><footer class="modal-footer"><button id="app-alert-close" class="btn" type="button"></button></footer></div>';
            document.body.appendChild(alertDialog);
            bindDialog(alertDialog);
            alertDialog.addEventListener('close', function () {
                alertOpen = false;
                activeAlert = null;
                const resolve = alertResolver;
                alertResolver = null;
                if (resolve) resolve();
                showNextAlert();
            });
            alertDialog.querySelector('#app-alert-close').addEventListener('click', function () {
                animateClose(alertDialog);
            });
        }
        const alert = alertQueue.shift();
        activeAlert = alert;
        const icon = alertDialog.querySelector('.app-alert-icon');
        icon.className = 'app-alert-icon app-alert-icon--' + alert.type;
        if (alert.type === 'loading') {
            icon.innerHTML = '<span class="app-alert-spinner" aria-hidden="true"></span>';
        } else {
            icon.textContent = alert.type === 'success' ? '✓' : (alert.type === 'error' ? '×' : (alert.type === 'warning' ? '!' : 'i'));
        }
        alertDialog.querySelector('#app-alert-title').textContent = alert.title || (alert.type === 'error' ? 'Something Went Wrong' : (alert.type === 'success' ? 'Success' : (alert.type === 'warning' ? 'Warning' : (alert.type === 'loading' ? 'Please wait' : 'Information'))));
        alertDialog.querySelector('#app-alert-message').textContent = String(alert.message || '');
        const closeButton = alertDialog.querySelector('#app-alert-close');
        closeButton.textContent = alert.buttonLabel || 'OK';
        closeButton.hidden = alert.type === 'loading';
        alertDialog.setAttribute('aria-busy', alert.type === 'loading' ? 'true' : 'false');
        alertResolver = alert.resolve;
        alertOpen = true;
        openDialog(alertDialog, document.activeElement);
    }

        document.querySelectorAll('[data-app-alert="true"]').forEach(function (element) {
            element.hidden = true;
            window.cvcartsAlert(
                element.dataset.alertType,
                element.dataset.alertTitle,
                element.dataset.alertMessage || element.textContent.trim(),
                element.dataset.alertButton
            );
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
}());
