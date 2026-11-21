<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pages/admin_chrome.php';
cvcarts_require_login('admin');
$user = cvcarts_user();
$alerts = cvcarts_admin_alerts(cvcarts_db());
$csrfToken = cvcarts_csrf_token();
?>
<!DOCTYPE html>
<html lang="en" class="admin-responsive">
<head>
    <?= cvcarts_favicon_tags() ?>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVCarts | Order Claim Scanner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/shared/shared.css?v=' . filemtime(__DIR__ . '/../../assets/css/shared/shared.css'))) ?>">
    <link rel="stylesheet" href="<?= cvcarts_h(cvcarts_url('/assets/css/admin/qr-scanner.css')) ?>">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
</head>
<body class="admin-scanner-page">
    <?php cvcarts_admin_header($user, $alerts); ?>
    <div class="app-layout">
        <?php cvcarts_admin_sidebar('qr-scanner'); ?>
        <main class="main-content">
            <div class="page-heading"><div><h1>QR Scanner</h1><p>Verify the order before confirming that it has been physically handed to the student.</p></div><a class="btn btn-secondary" href="<?= cvcarts_h(cvcarts_url('/admin/ready-to-claim/index.php')) ?>">Back to Ready to Claim</a></div>
            <div class="claim-grid">
                <section class="panel scanner-panel">
                    <div id="reader" aria-label="QR code camera scanner"></div>
                    <p id="camera-help" class="form-help">On phones, the rear camera is preferred; computers can use a webcam. Camera access requires HTTPS and browser permission. If unavailable, scan a QR image or verify the order number manually.</p>
                    <div class="scanner-controls">
                        <button id="start-camera" class="btn" type="button">Start Scanner</button>
                        <button id="stop-camera" class="btn btn-small btn-secondary" type="button" disabled>Stop Scanner</button>
                        <button id="switch-camera" class="btn btn-small btn-secondary" type="button" hidden disabled>Switch Camera</button>
                        <button id="scan-image" class="btn btn-small btn-secondary" type="button">Scan QR from Photo</button>
                        <input id="scan-image-file" type="file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" hidden aria-describedby="camera-help">
                    </div>
                    <div id="status" class="flash-message" role="status" aria-live="polite">Ready to scan a pickup QR code.</div>
                    <form id="manual-verify-form" class="form-field">
                        <label class="form-label" for="manual-order-identifier">Manual Order Verification</label>
                        <div class="form-inline"><input class="form-control" id="manual-order-identifier" type="text" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" placeholder="Enter Order Number (e.g., ORD-1024)" autocomplete="off" aria-describedby="manual-order-help" required><button class="btn btn-small" type="submit">Verify Order</button></div>
                        <small id="manual-order-help" class="form-help">Only orders marked as 'Ready to Claim' can be verified manually. The camera light turns off when you stop scanning or leave this page.</small>
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
                        <button id="claim-order" class="btn" type="button" hidden>Confirm &amp; Mark as Claimed</button>
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
            const manualOrderInput = document.getElementById('manual-order-identifier');
            const manualVerifyButton = manualVerifyForm.querySelector('button[type="submit"]');
            const clearScanButton = document.getElementById('clear-scan');
            const switchCameraButton = document.getElementById('switch-camera');
            const scanImageButton = document.getElementById('scan-image');
            const scanImageInput = document.getElementById('scan-image-file');
            const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const apiUrl = <?= json_encode(cvcarts_url('/api/qr/claim.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const barcodeApiUrl = <?= json_encode(cvcarts_url('/api/admin/barcode_lookup.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            let scanning = false;
            let cameraRunning = false;
            let cameraStartPromise = null;
            let cameraStopRequested = false;
            let imageScanBusy = false;
            let currentToken = '';
            let currentOrderIdentifier = '';
            let lastScannedToken = '';
            let currentOrderClaimable = false;
            let claimConfirmationPending = false;
            let requestBusy = false;
            let scanner = null;
            let cameraSupported = typeof window.Html5Qrcode === 'function';
            let cameraDevices = [];
            let activeCameraId = '';

            const looksMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '')
                || (navigator.maxTouchPoints > 0 && (matchMedia('(pointer: coarse)').matches));
            const hasRearCameraCapability = looksMobile && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
            if (hasRearCameraCapability) {
                scanImageInput.setAttribute('capture', 'environment');
            } else {
                scanImageInput.removeAttribute('capture');
            }

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
                manualVerifyButton.disabled = busy || imageScanBusy;
                clearScanButton.disabled = busy || imageScanBusy;
                scanAnotherButton.disabled = busy || imageScanBusy;
                startCameraButton.disabled = busy || imageScanBusy || cameraRunning || !cameraSupported;
                stopCameraButton.disabled = busy || imageScanBusy || !cameraRunning;
                switchCameraButton.disabled = busy || imageScanBusy || !cameraRunning || cameraDevices.length < 2;
                scanImageButton.disabled = busy || imageScanBusy || !cameraSupported;
            }

            function showMessage(message, severity) {
                const state = severity === true ? 'error' : (severity === false ? 'success' : (severity || 'success'));
                statusDiv.textContent = message;
                statusDiv.classList.toggle('error', state === 'error');
                statusDiv.classList.toggle('warning', state === 'warning');
                statusDiv.classList.toggle('success', state === 'success');
                resultMessage.textContent = message;
            }

            function sendAction(action, confirmHandover) {
                const manual = currentOrderIdentifier !== '';
                const payload = {
                    action: manual ? (action === 'claim' ? 'claim_manual' : 'verify_manual') : action
                };
                if (manual) {
                    payload.order_identifier = currentOrderIdentifier;
                } else {
                    payload.qr_token = currentToken;
                }
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
                        return { ok: response.ok, status: response.status, data: data };
                    });
                });
            }

            function onScanSuccess(decodedText) {
                if (scanning || !decodedText || decodedText === lastScannedToken) return;
                scanning = true;
                currentToken = String(decodedText).trim();
                currentOrderIdentifier = '';
                lastScannedToken = currentToken;
                manualOrderInput.value = '';
                currentOrderClaimable = false;
                const isLikelyBarcode = /^[0-9]{8,14}$/.test(currentToken) || /^[A-Za-z0-9_-]{5,64}$/.test(currentToken);
                fetch(barcodeApiUrl + '?barcode=' + encodeURIComponent(currentToken), {credentials: 'same-origin'})
                    .then(function (response) { return response.json().then(function (data) { return {ok: response.ok, data: data}; }); })
                    .then(function (barcodeResult) {
                        if (barcodeResult.ok && barcodeResult.data.success && barcodeResult.data.product) {
                            const product = barcodeResult.data.product;
                            setText('order-number', product.barcode || currentToken);
                            setText('student-name', product.name);
                            setText('student-email', product.category || '—');
                            setText('order-status', product.stock > 0 ? 'In Stock' : 'Out of Stock');
                            setText('payment-status', String(product.stock) + ' remaining');
                            setText('payment-method', 'Inventory');
                            setText('total-amount', '₱' + Number(product.price).toFixed(2));
                            setText('order-date', '—');
                            setText('claim-date', '—');
                            setText('claimed-by', '—');
                            const itemRow = document.createElement('tr');
                            [product.name, '—', String(product.stock), '₱' + Number(product.price).toFixed(2)].forEach(function (value) {
                                const cell = document.createElement('td');
                                cell.textContent = value;
                                itemRow.appendChild(cell);
                            });
                            document.getElementById('order-items').replaceChildren(itemRow);
                            claimButton.hidden = true;
                            resultPanel.hidden = false;
                            resultMessage.textContent = 'Matching inventory product found.';
                            showMessage('Product Found: ' + product.name, 'success');
                            window.closeAlert();
                            scanning = false;
                            return true;
                        }
                        return false;
                    })
                    .then(function (matched) {
                        if (matched) return;
                        if (isLikelyBarcode && !/^[A-Za-z0-9_-]{24,64}$/.test(currentToken)) {
                            resultPanel.hidden = false;
                            document.getElementById('order-items').replaceChildren();
                            claimButton.hidden = true;
                            setText('order-number', currentToken);
                            setText('student-name', '—');
                            setText('student-email', '—');
                            setText('order-status', 'Product Not Found');
                            setText('payment-status', 'Not in inventory');
                            setText('payment-method', '—');
                            setText('total-amount', '—');
                            setText('order-date', '—');
                            setText('claim-date', '—');
                            setText('claimed-by', '—');
                            resultMessage.textContent = 'Product Not Found';
                            showMessage('Product Not Found', 'error');
                            scanning = false;
                            setRequestBusy(false);
                            return;
                        }
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
                        const message = result.data.message || 'Unable to verify this order.';
                        showMessage(message, !result.ok ? 'error' : (result.data.claimable === true ? 'success' : 'warning'));
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
                        showMessage('Connection error. Please scan the receipt again.', 'error');
                        window.showError('Connection error', 'The order could not be verified. Please try scanning again.');
                        lastScannedToken = '';
                    })
                    .finally(function () {
                        scanning = false;
                        setRequestBusy(false);
                        claimButton.disabled = claimButton.hidden || !currentOrderClaimable;
                    });
                    });
            }

            claimButton.addEventListener('click', function () {
                if ((!currentToken && !currentOrderIdentifier) || claimButton.disabled || scanning || claimConfirmationPending) return;
                claimConfirmationPending = true;
                claimButton.disabled = true;
                setRequestBusy(true);
                window.cvcartsConfirm('Confirm that you have physically handed this order to the student? This will record the received date and time.', {
                    title: 'Confirm physical handover',
                    confirmLabel: 'Confirm & Mark as Claimed',
                    cancelLabel: 'Cancel',
                    type: 'warning',
                    opener: claimButton
                }).then(function (confirmed) {
                    claimConfirmationPending = false;
                    if (!confirmed || (!currentToken && !currentOrderIdentifier) || scanning) {
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
                            showMessage('Connection error. Please try claiming the order again.', 'error');
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

            manualOrderInput.addEventListener('input', function () {
                const normalized = manualOrderInput.value.trim().toUpperCase();
                if (manualOrderInput.value !== normalized) {
                    manualOrderInput.value = normalized;
                }
                if (normalized !== currentOrderIdentifier) {
                    currentOrderIdentifier = '';
                    currentToken = '';
                    lastScannedToken = '';
                    currentOrderClaimable = false;
                    resultPanel.hidden = true;
                    claimButton.hidden = true;
                    claimButton.disabled = true;
                }
            });

            manualVerifyForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (scanning) return;
                const identifier = manualOrderInput.value.trim().toUpperCase();
                manualOrderInput.value = identifier;
                if (!/^[A-Z0-9][A-Z0-9_-]{0,63}$/.test(identifier)) {
                    showMessage('Enter a valid Order Number or Order ID.', 'error');
                    return;
                }

                currentOrderIdentifier = identifier;
                currentToken = '';
                currentOrderClaimable = false;
                scanning = true;
                setRequestBusy(true);
                resultPanel.hidden = true;
                claimButton.hidden = true;
                showMessage('Checking Order Number and status...', 'success');
                window.showLoading('Verifying order', 'Checking the Order Number against the latest order status.');

                stopCamera().then(function () {
                    return sendAction('verify');
                }).then(function (result) {
                    window.closeAlert();
                    const message = result.data.message || 'Unable to verify this order.';
                    if (!result.ok) {
                        const statusType = result.status === 409 ? 'warning' : 'error';
                        showMessage(message, statusType);
                        resultPanel.hidden = true;
                        claimButton.hidden = true;
                        currentOrderClaimable = false;
                        return;
                    }

                    showMessage(message, 'success');
                    showOrder(result.data.order, result.data.claimable === true);
                }).catch(function () {
                    window.closeAlert();
                    showMessage('Connection error. Please check the Order Number and try again.', 'error');
                }).finally(function () {
                    scanning = false;
                    setRequestBusy(false);
                    claimButton.disabled = claimButton.hidden || !currentOrderClaimable;
                });
            });

            function refreshDisplayedOrder() {
                if (scanning || requestBusy || imageScanBusy || resultPanel.hidden || (!currentToken && !currentOrderIdentifier)) {
                    return;
                }

                scanning = true;
                setRequestBusy(true);
                showMessage('Refreshing order status from the latest update...', 'success');
                sendAction('verify').then(function (result) {
                    const message = result.data.message || 'Unable to refresh this order.';
                    if (result.data.order) {
                        showOrder(result.data.order, result.data.claimable === true);
                    }
                    showMessage(
                        message,
                        result.ok
                            ? (result.data.claimable === true ? 'success' : 'warning')
                            : (result.status === 409 ? 'warning' : 'error')
                    );
                }).catch(function () {
                    showMessage('Could not refresh this order. The displayed status may be out of date.', 'warning');
                }).finally(function () {
                    scanning = false;
                    setRequestBusy(false);
                    claimButton.disabled = claimButton.hidden || !currentOrderClaimable;
                });
            }

            window.addEventListener('cvcarts:realtime', function (event) {
                if (event.detail && event.detail.type === 'order.updated') {
                    refreshDisplayedOrder();
                }
            });

            clearScanButton.addEventListener('click', function () {
                if (scanning) return;
                resetScanState();
                showMessage('Ready to scan a pickup QR code.', 'success');
            });

            function resetScanState() {
                currentToken = '';
                currentOrderIdentifier = '';
                lastScannedToken = '';
                currentOrderClaimable = false;
                manualOrderInput.value = '';
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
                startCameraButton.disabled = requestBusy || imageScanBusy || running || !cameraSupported;
                stopCameraButton.disabled = requestBusy || imageScanBusy || !running;
                switchCameraButton.hidden = cameraDevices.length < 2;
                switchCameraButton.disabled = requestBusy || imageScanBusy || !running || cameraDevices.length < 2;
                scanImageButton.disabled = requestBusy || imageScanBusy || !cameraSupported;
            }

            function createScanner() {
                if (!cameraSupported) {
                    throw new Error('Scanner library unavailable');
                }
                if (!scanner) {
                    scanner = new Html5Qrcode('reader', { verbose: false });
                }
                return scanner;
            }

            function stopVideoTracks() {
                const video = document.querySelector('#reader video');
                const stream = video && video.srcObject;
                if (stream && typeof stream.getTracks === 'function') {
                    stream.getTracks().forEach(function (track) {
                        track.stop();
                    });
                }
            }

            function getActiveCameraId() {
                const video = document.querySelector('#reader video');
                const stream = video && video.srcObject;
                if (!stream || typeof stream.getVideoTracks !== 'function') return '';
                const track = stream.getVideoTracks()[0];
                if (!track || typeof track.getSettings !== 'function') return '';
                try {
                    return track.getSettings().deviceId || '';
                } catch (error) {
                    return '';
                }
            }

            function readCameraDevices() {
                if (!cameraSupported || typeof Html5Qrcode.getCameras !== 'function') {
                    cameraDevices = [];
                    setCameraControls(cameraRunning);
                    return Promise.resolve(cameraDevices);
                }

                return Html5Qrcode.getCameras().then(function (devices) {
                    cameraDevices = Array.isArray(devices) ? devices.filter(function (device) {
                        return device && typeof device.id === 'string';
                    }) : [];
                    activeCameraId = getActiveCameraId() || activeCameraId;
                    if (!activeCameraId && cameraDevices.length) {
                        const rearCamera = cameraDevices.find(function (device) {
                            return /back|rear|environment/i.test(device.label || '');
                        });
                        activeCameraId = (rearCamera || cameraDevices[0]).id;
                    }
                    setCameraControls(cameraRunning);
                    return cameraDevices;
                }).catch(function () {
                    cameraDevices = [];
                    setCameraControls(cameraRunning);
                    if (cameraRunning) {
                        showMessage('Camera scanning is working, but the browser could not list other cameras to switch to.', 'warning');
                    }
                    return cameraDevices;
                });
            }

            function cameraErrorMessage(error) {
                const detail = error && error.name ? String(error.name) : String(error || '');
                const normalized = detail.toLowerCase();
                if (normalized.indexOf('notallowed') !== -1 || normalized.indexOf('permission') !== -1 || normalized.indexOf('security') !== -1) {
                    return 'Camera access was denied. Allow camera permission in your browser settings, or scan a QR image or enter the order number manually.';
                }
                if (normalized.indexOf('notfound') !== -1 || normalized.indexOf('devicesnotfound') !== -1 || normalized.indexOf('no camera') !== -1) {
                    return 'No camera was detected. Scan a QR image or enter the order number manually.';
                }
                if (normalized.indexOf('notreadable') !== -1 || normalized.indexOf('trackstart') !== -1 || normalized.indexOf('busy') !== -1) {
                    return 'The camera is busy in another app. Close that app and try again, or use a QR image or manual order number.';
                }
                return 'The camera could not start. Check browser permission and try another camera, scan a QR image, or enter the order number manually.';
            }

            function scannerConfig() {
                return {
                    fps: 10,
                    qrbox: function (viewfinderWidth, viewfinderHeight) {
                        const size = Math.floor(Math.min(viewfinderWidth, viewfinderHeight) * 0.72);
                        return { width: size, height: size };
                    }
                };
            }

            function startCamera(cameraId, triedFallback) {
                if (!cameraSupported) {
                    showMessage('The QR scanner library did not load. Scan a QR image or enter the order number manually.', 'error');
                    return Promise.resolve();
                }
                const hostname = window.location.hostname;
                const secureOrigin = window.isSecureContext === true
                    || window.location.protocol === 'https:'
                    || hostname === 'localhost'
                    || hostname === '127.0.0.1'
                    || hostname === '[::1]';
                if (!secureOrigin) {
                    showMessage('Camera scanning requires HTTPS (or localhost). Open the secure site, or scan a QR image or enter the order number manually.', 'error');
                    return Promise.resolve();
                }
                if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
                    showMessage('This browser does not provide camera access. Scan a QR image or enter the order number manually.', 'error');
                    return Promise.resolve();
                }
                if (cameraRunning || cameraStartPromise) return Promise.resolve();

                try {
                    createScanner();
                } catch (error) {
                    showMessage('The scanner could not initialize. Scan a QR image or enter the order number manually.', 'error');
                    return Promise.resolve();
                }

                cameraStopRequested = false;
                startCameraButton.disabled = true;
                const source = cameraId || { facingMode: { ideal: 'environment' } };
                let startPromise;
                try {
                    startPromise = scanner.start(source, scannerConfig(), onScanSuccess);
                } catch (error) {
                    setCameraControls(false);
                    showMessage(cameraErrorMessage(error), 'error');
                    return Promise.resolve();
                }
                cameraStartPromise = startPromise;

                const attempt = startPromise.then(function () {
                    cameraRunning = true;
                    activeCameraId = getActiveCameraId() || cameraId || activeCameraId;
                    setCameraControls(true);
                    if (cameraStopRequested) {
                        return stopCameraNow();
                    }
                    showMessage('Camera ready. Point it at a pickup QR code.', 'success');
                    return readCameraDevices();
                }).catch(function (error) {
                    cameraRunning = false;
                    setCameraControls(false);
                    if (cameraStopRequested) {
                        return stopCameraNow();
                    }
                    const errorName = error && error.name ? String(error.name).toLowerCase() : String(error || '').toLowerCase();
                    const permissionDenied = errorName.indexOf('notallowed') !== -1
                        || errorName.indexOf('permission') !== -1
                        || errorName.indexOf('security') !== -1;
                    if (!permissionDenied && !cameraId && !triedFallback && typeof Html5Qrcode.getCameras === 'function') {
                        return Html5Qrcode.getCameras().then(function (devices) {
                            if (cameraStopRequested) {
                                return stopCameraNow();
                            }
                            const available = Array.isArray(devices) ? devices.filter(function (device) {
                                return device && typeof device.id === 'string';
                            }) : [];
                            if (available.length) {
                                const rearCamera = available.find(function (device) {
                                    return /back|rear|environment/i.test(device.label || '');
                                });
                                const fallbackCamera = rearCamera || available[0];
                                cameraDevices = available;
                                activeCameraId = fallbackCamera.id;
                                cameraStartPromise = null;
                                return startCamera(fallbackCamera.id, true);
                            }
                            showMessage('No camera was detected. Scan a QR image or enter the order number manually.', 'error');
                        }).catch(function () {
                            showMessage(cameraErrorMessage(error), 'error');
                        });
                    }
                    showMessage(cameraErrorMessage(error), 'error');
                }).finally(function () {
                    if (cameraStartPromise === attempt) {
                        cameraStartPromise = null;
                    }
                    setCameraControls(cameraRunning);
                });

                cameraStartPromise = attempt;
                return attempt;
            }

            function stopCameraNow() {
                cameraStopRequested = false;
                if (!scanner || !cameraRunning) {
                    stopVideoTracks();
                    setCameraControls(false);
                    return Promise.resolve();
                }

                return scanner.stop().then(function () {
                    cameraRunning = false;
                    stopVideoTracks();
                    if (typeof scanner.clear === 'function') scanner.clear();
                    setCameraControls(false);
                }).catch(function () {
                    stopVideoTracks();
                    cameraRunning = false;
                    setCameraControls(false);
                    showMessage('The camera could not stop cleanly. Its video track was released; reload before scanning again.', 'error');
                });
            }

            function stopCamera() {
                cameraStopRequested = true;
                if (cameraStartPromise) {
                    return cameraStartPromise.catch(function () {}).then(stopCameraNow);
                }
                return stopCameraNow();
            }

            function scanImageFile(file) {
                if (!file) return;
                if (typeof file.type === 'string' && !file.type.startsWith('image/')) {
                    showMessage('Choose an image file containing the order QR code.', 'error');
                    scanImageInput.value = '';
                    return;
                }
                if (imageScanBusy || scanning) return;

                imageScanBusy = true;
                setRequestBusy(true);
                showMessage('Reading the barcode or QR code from the selected image...', 'success');
                stopCamera().then(async function () {
                    if ('BarcodeDetector' in window) {
                        try {
                            const detector = new window.BarcodeDetector({
                                formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'qr_code']
                            });
                            const bitmap = await createImageBitmap(file);
                            const codes = await detector.detect(bitmap);
                            if (bitmap.close) bitmap.close();
                            if (codes && codes.length && codes[0].rawValue) {
                                if (typeof scanner === 'object' && scanner && typeof scanner.clear === 'function') scanner.clear();
                                imageScanBusy = false;
                                setRequestBusy(false);
                                onScanSuccess(codes[0].rawValue);
                                return;
                            }
                        } catch (error) {
                            // Fall back to the loaded QR/image scanning library.
                        }
                    }
                    if (!cameraSupported) {
                        throw new Error('Image scanning is unavailable because the scanner library did not load.');
                    }
                    try {
                        createScanner();
                    } catch (error) {
                        throw new Error('The scanner could not initialize.');
                    }
                    if (typeof scanner.scanFile !== 'function') {
                        throw new Error('Image scanning is not supported by this browser.');
                    }
                    return scanner.scanFile(file, true);
                }).then(function (decodedText) {
                    if (typeof scanner.clear === 'function') scanner.clear();
                    imageScanBusy = false;
                    setRequestBusy(false);
                    if (decodedText) onScanSuccess(decodedText);
                }).catch(function () {
                    let cleanupFailed = false;
                    if (scanner && typeof scanner.clear === 'function') {
                        try {
                            scanner.clear();
                        } catch (error) {
                            cleanupFailed = true;
                        }
                    }
                    imageScanBusy = false;
                    setRequestBusy(false);
                    showMessage(cleanupFailed
                        ? 'The image scanner could not reset. Reload the page before scanning another image; manual order entry is still available.'
                        : 'No readable QR code was found in that image. Try a clearer photo or enter the order number manually.', 'error');
                }).finally(function () {
                    scanImageInput.value = '';
                });
            }

            function switchCamera() {
                if (!cameraRunning || cameraDevices.length < 2 || scanning) return;
                const currentId = getActiveCameraId() || activeCameraId;
                const currentIndex = cameraDevices.findIndex(function (device) {
                    return device.id === currentId;
                });
                const nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % cameraDevices.length;
                const nextCamera = cameraDevices[nextIndex];
                showMessage('Switching camera...', 'success');
                stopCamera().then(function () {
                    return startCamera(nextCamera.id, true);
                });
            }

            startCameraButton.addEventListener('click', function () {
                startCamera('', false);
            });
            switchCameraButton.addEventListener('click', switchCamera);
            scanImageButton.addEventListener('click', function () {
                scanImageInput.click();
            });
            scanImageInput.addEventListener('change', function () {
                scanImageFile(scanImageInput.files && scanImageInput.files[0]);
            });
            stopCameraButton.addEventListener('click', function () {
                stopCamera().then(function () {
                    showMessage('Camera stopped. You can still verify an order number manually.', 'success');
                });
            });

            function releaseCameraOnNavigation() {
                cameraStopRequested = true;
                stopVideoTracks();
                if (cameraRunning && scanner && typeof scanner.stop === 'function') {
                    scanner.stop().catch(function () {});
                }
            }

            document.addEventListener('visibilitychange', function () {
                if (document.hidden && (cameraRunning || cameraStartPromise)) {
                    stopCamera();
                }
            });
            window.addEventListener('pagehide', releaseCameraOnNavigation);
            window.addEventListener('beforeunload', releaseCameraOnNavigation);

            setCameraControls(false);
            if (!cameraSupported) {
                startCameraButton.disabled = true;
                showMessage('Camera scanning is unavailable. Use manual verification below.', 'error');
            } else {
                showMessage('Ready to scan a pickup QR code.', 'success');
            }
        }());
    </script>
</body>
</html>
