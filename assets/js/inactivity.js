(function () {
    'use strict';

    if (window.__cvcartsInactivityBound) return;
    window.__cvcartsInactivityBound = true;

    var config = window.CVCARTS_SESSION_CONFIG;
    if (!config) return;

    var timeoutSeconds = Number(config.timeoutSeconds) || 180;
    var warningSeconds = Number(config.warningSeconds) || 150;
    var warningShown = false;
    var warningTimer = null;
    var countdown = 30;
    var idleTimer = null;
    var lastPingAt = 0;

    var originalFetch = window.fetch;
    if (typeof originalFetch === 'function') {
        window.fetch = function () {
            return originalFetch.apply(this, arguments).then(function (response) {
                if (response.status === 401) redirectExpired();
                return response;
            });
        };
    }

    var modal = document.createElement('dialog');
    modal.className = 'app-modal inactivity-modal';
    modal.setAttribute('aria-labelledby', 'inactivity-title');
    modal.setAttribute('aria-describedby', 'inactivity-message');
    modal.innerHTML =
        '<div class="modal-shell">' +
            '<header class="modal-header"><div>' +
                '<h2 id="inactivity-title">Session Expiring</h2>' +
                '<p id="inactivity-message">You have been inactive. Your session will end in <strong id="inactivity-countdown">30</strong> seconds.</p>' +
            '</div></header>' +
            '<footer class="modal-footer">' +
                '<button type="button" class="btn" id="inactivity-stay">Stay Logged In</button>' +
            '</footer>' +
        '</div>';
    document.body.appendChild(modal);

    var countdownText = modal.querySelector('#inactivity-countdown');
    var stayButton = modal.querySelector('#inactivity-stay');

    function closeModal() {
        if (modal.open) modal.close();
    }

    function redirectExpired() {
        closeModal();
        window.location.replace(config.keepAliveUrl.replace('/api/session/keepalive.php', '/auth/login.php?expired=1'));
    }

    function pingBackend(useBeacon) {
        var body = new URLSearchParams();
        body.set('csrf_token', config.csrfToken);
        if (useBeacon && navigator.sendBeacon) {
            var ok = navigator.sendBeacon(config.keepAliveUrl, body);
            if (!ok) return pingBackend(false);
            return Promise.resolve();
        }
        return fetch(config.keepAliveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            cache: 'no-store',
            body: body.toString()
        }).then(function (response) {
            if (response.status === 401 || response.status === 419) {
                redirectExpired();
                throw new Error('Session expired');
            }
            if (!response.ok) throw new Error('Session refresh failed');
            return response.json();
        });
    }

    function stopCountdown() {
        if (warningTimer) {
            window.clearInterval(warningTimer);
            warningTimer = null;
        }
    }

    function expireAtZero() {
        stopCountdown();
        redirectExpired();
    }

    function showWarning() {
        if (warningShown) return;
        warningShown = true;
        countdown = timeoutSeconds - warningSeconds;
        countdownText.textContent = String(countdown);
        if (typeof modal.showModal === 'function') modal.showModal();
        else modal.setAttribute('open', '');

        warningTimer = window.setInterval(function () {
            countdown -= 1;
            if (countdownText) countdownText.textContent = String(Math.max(0, countdown));
            if (countdown <= 0) expireAtZero();
        }, 1000);
    }

    function resetLocalTimer() {
        if (idleTimer) window.clearTimeout(idleTimer);
        if (warningShown) return;
        idleTimer = window.setTimeout(showWarning, warningSeconds * 1000);
    }

    function resetAll() {
        stopCountdown();
        closeModal();
        warningShown = false;
        countdown = timeoutSeconds - warningSeconds;
        resetLocalTimer();
    }

    function genuineActivity() {
        resetLocalTimer();
        if (warningShown) return;
        var now = Date.now();
        if (now - lastPingAt >= 10000) {
            lastPingAt = now;
            pingBackend(true).catch(function () {});
        }
    }

    ['pointerdown', 'keydown', 'scroll', 'touchstart', 'submit'].forEach(function (eventName) {
        document.addEventListener(eventName, genuineActivity, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) genuineActivity();
    });

    stayButton.addEventListener('click', function () {
        stayButton.disabled = true;
        pingBackend(false).then(function () {
            stayButton.disabled = false;
            resetAll();
        }).catch(function () {
            stayButton.disabled = false;
        });
    });

    window.addEventListener('pageshow', resetLocalTimer);
    resetLocalTimer();
}());
