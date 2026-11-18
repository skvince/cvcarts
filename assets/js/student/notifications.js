(function () {
    'use strict';

    const dialog = document.getElementById('notification-details-modal');
    if (!dialog) return;

    const pendingReads = new Set();
    const readNotifications = new Set();

    document.addEventListener('click', function (event) {
        const target = event.target instanceof Element ? event.target : null;
        const trigger = target ? target.closest('.notification-card') : null;
        if (!trigger) return;

        const notificationId = trigger.dataset.notificationId;
        dialog.dataset.notificationId = notificationId;
        dialog.dataset.notificationUnread = trigger.dataset.notificationUnread;
        document.getElementById('notification-modal-title').textContent = trigger.dataset.notificationTitle || '';
        document.getElementById('notification-modal-message').textContent = trigger.dataset.notificationMessage || '';
        document.getElementById('notification-modal-date').textContent = trigger.dataset.notificationDate || '';

        const reference = document.getElementById('notification-modal-reference');
        const referenceNumber = trigger.dataset.notificationReference || '';
        reference.textContent = referenceNumber ? 'Order: ' + referenceNumber : '';
        reference.hidden = referenceNumber === '';
    }, true);

    function markNotificationRead() {
        const notificationId = dialog.dataset.notificationId;
        if (!notificationId || dialog.dataset.notificationUnread !== 'true'
            || pendingReads.has(notificationId) || readNotifications.has(notificationId)) {
            return;
        }

        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (!csrfMeta || !csrfMeta.content) {
            reportReadFailure(new Error('The security token is unavailable. Reload the page and try again.'));
            return;
        }

        const formData = new FormData();
        formData.append('csrf_token', csrfMeta.content);
        formData.append('mark_read', '1');
        formData.append('notification_id', notificationId);
        pendingReads.add(notificationId);

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.json().then(function (result) {
                if (!response.ok || !result || result.success !== true) {
                    throw new Error(result && result.message ? result.message : 'The notification could not be marked as read.');
                }
                return result;
            });
        }).then(function () {
            readNotifications.add(notificationId);
            const trigger = document.querySelector('.notification-card[data-notification-id="' + notificationId + '"]');
            if (trigger) {
                trigger.dataset.notificationUnread = 'false';
                trigger.classList.remove('is-unread');
                const newBadge = trigger.querySelector('.notification-new');
                if (newBadge) newBadge.remove();

                const unreadCount = document.getElementById('notification-unread-count');
                const unreadTabCount = document.getElementById('notification-unread-tab-count');
                const currentCount = Number(unreadCount ? unreadCount.dataset.count : 0);
                const nextCount = Math.max(0, currentCount - 1);
                if (unreadCount) {
                    unreadCount.dataset.count = String(nextCount);
                    unreadCount.textContent = nextCount + ' unread';
                }
                if (unreadTabCount) {
                    unreadTabCount.dataset.count = String(nextCount);
                    unreadTabCount.textContent = String(nextCount);
                }

                const markAllButton = document.querySelector('.notification-mark-all button');
                if (markAllButton) markAllButton.disabled = nextCount === 0;

                const filter = new URLSearchParams(window.location.search).get('filter');
                if (filter === 'unread') trigger.remove();
                if (!document.querySelector('.notification-card')) {
                    const emptyState = document.getElementById('notification-empty-state');
                    if (emptyState) emptyState.hidden = false;
                }
            }
        }).catch(reportReadFailure).finally(function () {
            pendingReads.delete(notificationId);
        });
    }

    dialog.addEventListener('close', markNotificationRead);
    new MutationObserver(function (records) {
        if (records.some(function (record) { return record.attributeName === 'open'; }) && !dialog.open) {
            markNotificationRead();
        }
    }).observe(dialog, { attributes: true, attributeFilter: ['open'] });

    function reportReadFailure(error) {
        console.error('Unable to mark the notification as read.', error);
        if (typeof window.cvcartsAlert === 'function') {
            window.cvcartsAlert('error', 'Notification update failed', error.message || 'The notification could not be marked as read.');
        }
    }
}());
