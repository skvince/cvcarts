<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');
$user = cvcarts_user();
$alerts = cvcarts_admin_alerts(cvcarts_db());
$csrfToken = cvcarts_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Order Claim Scanner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/shared/shared.css')) ?>">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/admin/qr-scanner.css')) ?>">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
</head>
<body>
    <?php cvcarts_admin_header($user, $alerts); ?>
    <div class="app-layout">
        <?php cvcarts_admin_sidebar('qr-scanner'); ?>
        <main class="main-content">
            <div class="page-heading"><div><h1>QR Scanner</h1><p>Verify the order before confirming that it has been physically handed to the student.</p></div><a class="btn btn-secondary" href="<?= cvcarts_h(cvcarts_url('/admin/ready-to-claim/index.php')) ?>">Back to Ready to Claim</a></div>
            <div class="claim-grid">
                <section class="panel scanner-panel">
                    <div id="reader" aria-label="QR code camera scanner"></div>
                    <p class="form-help">Center the QR code in the camera area. The scanner pauses after each result.</p>
                    <div class="scanner-controls">
                        <button id="start-camera" class="btn" type="button">Start Scanner</button>
                        <button id="stop-camera" class="btn btn-small btn-secondary" type="button" disabled>Stop Scanner</button>
                    </div>
                    <div id="status" class="flash-message" role="status" aria-live="polite">Ready to scan a pickup QR code.</div>
                    <form id="manual-verify-form" class="form-field">
                        <label class="form-label" for="manual-qr-token">Manual Order Verification</label>
                        <div class="form-inline"><input class="form-control" id="manual-qr-token" type="text" maxlength="64" pattern="[A-Fa-f0-9]{32}|[A-Fa-f0-9]{64}" placeholder="Enter QR token" autocomplete="off" required><button class="btn btn-small" type="submit">Verify Order</button></div>
                    </form>
                    <button id="scan-another" class="btn" type="button" hidden>Scan Another Order</button>
                    <button id="clear-scan" class="btn btn-small btn-secondary" type="button">Clear Scan</button>
                </section>
                <section id="order-result" class="panel claim-result" hidden aria-live="polite">
                    <h2>Order Verification</h2>
                    <p id="result-message" class="status-note"></p>
                    <div class="summary-grid">
                        <div><span>Order number</span><strong id="order-number"></strong></div>
                        <div><span>Student</span><strong id="student-name"></strong></div>
                        <div><span>Student email</span><strong id="student-email"></strong></div>
                        <div><span>Order status</span><strong id="order-status"></strong></div>
                        <div><span>Payment status</span><strong id="payment-status"></strong></div>
                        <div><span>Payment method</span><strong id="payment-method"></strong></div>
                        <div><span>Total amount</span><strong id="total-amount"></strong></div>
                        <div><span>Order date</span><strong id="order-date"></strong></div>
                        <div><span>Date received</span><strong id="claim-date"></strong></div>
                        <div><span>Processed by</span><strong id="claimed-by"></strong></div>
                    </div>
                    <h3>Products</h3>
                    <div class="table-wrapper"><table><thead><tr><th>Product</th><th>Size</th><th>Quantity</th><th>Unit price</th></tr></thead><tbody id="order-items"></tbody></table></div>
                    <div class="claim-action">
                        <button id="claim-order" class="btn" type="button" hidden>Confirm Claim</button>
                    </div>
                </section>
            </div>
        </main>
    </div>
    <script>
        (function () {
            'use strict';

            const statusDiv = document.getElementById('status');
            const resultPanel = document.getElementById('order-result');
            const resultMessage = document.getElementById('result-message');
            const claimButton = document.getElementById('claim-order');
            const scanAnotherButton = document.getElementById('scan-another');
            const startCameraButton = document.getElementById('start-camera');
            const stopCameraButton = document.getElementById('stop-camera');
            const manualVerifyForm = document.getElementById('manual-verify-form');
            const manualQrToken = document.getElementById('manual-qr-token');
            const manualVerifyButton = manualVerifyForm.querySelector('button[type="submit"]');
            const clearScanButton = document.getElementById('clear-scan');
            const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const apiUrl = <?= json_encode(cvcarts_url('/api/qr/claim.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            let scanning = false;
            let cameraRunning = false;
            let currentToken = '';
            let lastScannedToken = '';
            let currentOrderClaimable = false;
            let claimConfirmationPending = false;
            let requestBusy = false;
            let scanner = null;
            let cameraSupported = typeof window.Html5Qrcode === 'function';

            function setText(id, value) {
                document.getElementById(id).textContent = value || '—';
            }

            function formatDate(value) {
                if (!value) return '—';
                const date = new Date(String(value).replace(' ', 'T'));
                return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
            }

            function formatMoney(value) {
                try {
                    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Number(value) || 0);
                } catch (error) {
                    return '₱' + (Number(value) || 0).toFixed(2);
                }
            }

            function showOrder(order, claimable) {
                currentOrderClaimable = claimable === true;
                setText('order-number', order.reference_number);
                setText('student-name', order.student_name);
                setText('student-email', order.student_email);
                setText('order-status', order.order_status);
                setText('payment-status', order.payment_status);
                setText('payment-method', order.payment_method);
                setText('total-amount', formatMoney(order.total_amount));
                setText('order-date', formatDate(order.order_date));
                setText('claim-date', formatDate(order.claim_date));
                setText('claimed-by', order.claimed_by_admin);

                const itemsBody = document.getElementById('order-items');
                itemsBody.replaceChildren();
                (order.items || []).forEach(function (item) {
                    const row = document.createElement('tr');
                    [item.name, item.size || '—', String(item.quantity), formatMoney(item.unit_price)].forEach(function (value) {
                        const cell = document.createElement('td');
                        cell.textContent = value;
                        row.appendChild(cell);
                    });
                    itemsBody.appendChild(row);
                });
                if (!(order.items || []).length) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 4;
                    cell.textContent = 'No line items recorded for this order.';
                    row.appendChild(cell);
                    itemsBody.appendChild(row);
                }

                resultPanel.hidden = false;
                claimButton.hidden = !claimable;
                claimButton.disabled = !claimable || scanning;
                scanAnotherButton.hidden = false;
            }

            function setRequestBusy(busy) {
                requestBusy = busy;
                manualVerifyButton.disabled = busy;
                clearScanButton.disabled = busy;
                scanAnotherButton.disabled = busy;
                startCameraButton.disabled = busy || cameraRunning || !cameraSupported;
                stopCameraButton.disabled = busy || !cameraRunning;
            }

            function showMessage(message, isError) {
                statusDiv.textContent = message;
                statusDiv.classList.toggle('error', Boolean(isError));
                statusDiv.classList.toggle('warning', !isError);
                resultMessage.textContent = message;
            }

            function sendAction(action, confirmHandover) {
                const payload = { action: action, qr_token: currentToken };
                if (confirmHandover === true) {
                    payload.confirm_handover = true;
                }
                return fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(payload)
                }).then(function (response) {
                    return response.json().catch(function () {
                        return { message: 'Unexpected server response.' };
                    }).then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                });
            }

            function onScanSuccess(decodedText) {
                if (scanning || !decodedText || decodedText === lastScannedToken) return;
                scanning = true;
                currentToken = String(decodedText).trim();
                lastScannedToken = currentToken;
                currentOrderClaimable = false;
                setRequestBusy(true);
                resultPanel.hidden = true;
                claimButton.hidden = true;
                showMessage('Checking QR code and order status...', false);
                window.showLoading('Verifying order', 'Checking the QR code against the latest order status.');

                stopCamera().then(function () {
                    return sendAction('verify');
                })
                    .then(function (result) {
                        window.closeAlert();
                        showMessage(result.data.message || 'Unable to verify this order.', !result.ok);
                        if (!result.ok) {
                            window.showError('Order verification failed', result.data.message || 'The QR code could not be verified.');
                        } else if (result.data.claimable !== true) {
                            window.showWarning('Order is not ready to claim', result.data.message || 'The order status does not allow claiming.');
                        }
                        if (result.data.order) {
                            showOrder(result.data.order, result.data.claimable === true);
                        } else {
                            lastScannedToken = '';
                        }
                    })
                    .catch(function () {
                        window.closeAlert();
                        showMessage('Connection error. Please scan the receipt again.', true);
                        window.showError('Connection error', 'The order could not be verified. Please try scanning again.');
                        lastScannedToken = '';
                    })
                    .finally(function () {
                        scanning = false;
                        setRequestBusy(false);
                        claimButton.disabled = claimButton.hidden || !currentOrderClaimable;
                    });
            }

            claimButton.addEventListener('click', function () {
                if (!currentToken || claimButton.disabled || scanning || claimConfirmationPending) return;
                claimConfirmationPending = true;
                claimButton.disabled = true;
                setRequestBusy(true);
                window.cvcartsConfirm('Confirm that you have physically handed this order to the student? This will record the received date and time.', {
                    title: 'Confirm physical handover',
                    confirmLabel: 'Confirm Claim',
                    cancelLabel: 'Cancel',
                    type: 'warning',
                    opener: claimButton
                }).then(function (confirmed) {
                    claimConfirmationPending = false;
                    if (!confirmed || !currentToken || scanning) {
                        claimButton.disabled = claimButton.hidden || !currentOrderClaimable;
                        setRequestBusy(false);
                        return;
                    }
                    scanning = true;
                    showMessage('Claiming order...', false);
                    window.showLoading('Claiming order', 'Saving the confirmed handover.');
                    sendAction('claim', true)
                        .then(function (result) {
                            window.closeAlert();
                            showMessage(result.data.message || 'Unable to claim this order.', !result.ok);
                            window.cvcartsAlert(
                                result.ok ? 'success' : 'error',
                                result.ok ? 'Order Claimed' : 'Claim Failed',
                                result.data.message || 'The order claim could not be completed.'
                            );
                            if (result.data.order) {
                                showOrder(result.data.order, result.data.claimable === true);
                            }
                            if (!result.ok) {
                                claimButton.disabled = false;
                            }
                        })
                        .catch(function () {
                            window.closeAlert();
                            showMessage('Connection error. Please try claiming the order again.', true);
                            window.showError('Connection error', 'The order could not be claimed. Please try again.');
                            claimButton.disabled = false;
                        })
                        .finally(function () {
                            scanning = false;
                            setRequestBusy(false);
                            claimButton.disabled = claimButton.hidden || !currentOrderClaimable;
                        });
                });
            });

            scanAnotherButton.addEventListener('click', function () {
                if (scanning) return;
                resetScanState();
                startCamera();
            });

            manualVerifyForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (scanning) return;
                const token = manualQrToken.value.trim();
                if (!/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/i.test(token)) {
                    showMessage('INVALID QR CODE', true);
                    return;
                }
                onScanSuccess(token);
            });

            clearScanButton.addEventListener('click', function () {
                if (scanning) return;
                resetScanState();
                showMessage('Scan cleared. Ready to verify an order.', false);
            });

            function resetScanState() {
                currentToken = '';
                lastScannedToken = '';
                currentOrderClaimable = false;
                manualQrToken.value = '';
                resultPanel.hidden = true;
                claimButton.hidden = true;
                claimButton.disabled = true;
                scanAnotherButton.hidden = true;
                setText('order-number', '');
                setText('student-name', '');
                setText('student-email', '');
                setText('order-status', '');
                setText('payment-status', '');
                setText('payment-method', '');
                setText('total-amount', '');
                setText('order-date', '');
                setText('claim-date', '');
                setText('claimed-by', '');
                document.getElementById('order-items').replaceChildren();
            }

            function setCameraControls(running) {
                cameraRunning = running;
                startCameraButton.disabled = requestBusy || running || !cameraSupported;
                stopCameraButton.disabled = requestBusy || !running;
            }

            function startCamera() {
                if (!cameraSupported) {
                    showMessage('Camera scanning is unavailable because the scanner library did not load. Use manual verification below.', true);
                    startCameraButton.disabled = true;
                    return;
                }
                if (cameraRunning) return;

                if (!scanner) {
                    try {
                        scanner = new Html5Qrcode('reader', { verbose: false });
                    } catch (error) {
                        cameraSupported = false;
                        startCameraButton.disabled = true;
                        showMessage('The camera scanner could not start. Use manual verification below.', true);
                        return;
                    }
                }

                startCameraButton.disabled = true;
                scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 250, height: 250 } },
                    onScanSuccess
                ).then(function () {
                    setCameraControls(true);
                    showMessage('Camera ready. Point it at a pickup QR code.', false);
                }).catch(function () {
                    setCameraControls(false);
                    showMessage('Camera permission is required; use localhost or HTTPS.', true);
                });
            }

            function stopCamera() {
                if (!cameraRunning || !scanner) {
                    setCameraControls(false);
                    return Promise.resolve();
                }

                return scanner.stop()
                    .then(function () {
                        if (typeof scanner.clear === 'function') {
                            scanner.clear();
                        }
                        setCameraControls(false);
                    })
                    .catch(function () {
                        setCameraControls(false);
                        showMessage('The camera could not be stopped cleanly. Reload this page before scanning again.', true);
                    });
            }

            startCameraButton.addEventListener('click', startCamera);
            stopCameraButton.addEventListener('click', function () {
                stopCamera().then(function () {
                    showMessage('Camera stopped. You can still verify a QR token manually.', false);
                });
            });
            window.addEventListener('beforeunload', function () {
                if (cameraRunning && scanner && typeof scanner.stop === 'function') {
                    scanner.stop().catch(function () {});
                }
            });

            setCameraControls(false);
            if (!cameraSupported) {
                startCameraButton.disabled = true;
                showMessage('Camera scanning is unavailable. Use manual verification below.', true);
            } else {
                showMessage('Ready to scan a pickup QR code.', false);
            }
        }());
    </script>
</body>
</html>
