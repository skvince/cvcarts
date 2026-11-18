<?php

function cvcarts_add_notification(PDO $db, int $userId, string $title, string $message, ?int $orderId = null): void {
    $db->prepare('INSERT INTO notifications (user_id, title, message, related_order_id) VALUES (:user_id, :title, :message, :order_id)')
        ->execute(['user_id' => $userId, 'title' => $title, 'message' => $message, 'order_id' => $orderId]);
}

function cvcarts_record_order_status(PDO $db, int $orderId, ?string $oldStatus, string $newStatus, ?int $actorId, ?string $reason = null): void {
    $db->prepare('INSERT INTO order_status_history (order_id, old_status, new_status, changed_by_user_id, reason) VALUES (:order_id, :old_status, :new_status, :actor_id, :reason)')
        ->execute([
            'order_id' => $orderId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
}

function cvcarts_order_claim_state(array $order): array {
    if (!empty($order['claim_date'])) {
        return ['claimable' => false, 'message' => 'ALREADY CLAIMED'];
    }
    if ($order['order_status'] === 'Claimed') {
        return ['claimable' => false, 'message' => 'ALREADY CLAIMED'];
    }
    if ($order['order_status'] === 'Cancelled') {
        return ['claimable' => false, 'message' => 'ORDER CANCELLED'];
    }
    if ($order['payment_status'] !== 'Paid') {
        return ['claimable' => false, 'message' => 'PAYMENT NOT VERIFIED'];
    }
    if ($order['order_status'] !== 'Ready to Claim') {
        return ['claimable' => false, 'message' => 'ORDER NOT ELIGIBLE FOR CLAIMING'];
    }
    return ['claimable' => true, 'message' => 'Valid QR Code — Ready to Claim. Confirm physical handover before completing the claim.'];
}

function cvcarts_admin_review_payment(PDO $db, int $orderId, int $adminId, string $decision, string $reason = ''): array {
    if (!in_array($decision, ['collect_cash', 'approve', 'reject'], true)) {
        return ['success' => false, 'message' => 'Choose a valid payment action.'];
    }
    if ($decision === 'reject' && trim($reason) === '') {
        return ['success' => false, 'message' => 'Provide a rejection reason.'];
    }

    $db->beginTransaction();
    try {
        $orderQuery = $db->prepare('SELECT id, user_id, reference_number, payment_method, payment_status, order_status FROM orders WHERE id = :id FOR UPDATE');
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Order not found.'];
        }

        $paymentQuery = $db->prepare('SELECT id, status, transaction_reference, proof_path FROM payments WHERE order_id = :order_id ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $paymentQuery->execute(['order_id' => $orderId]);
        $payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);
        if (!$payment) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Payment record not found.'];
        }

        if ($decision === 'collect_cash') {
            if ($order['payment_method'] !== 'Cash' || $payment['status'] !== 'Unpaid' || $order['payment_status'] !== 'Unpaid' || $order['order_status'] !== 'Pending') {
                $db->rollBack();
                return ['success' => false, 'message' => 'This cash payment is not awaiting collection.'];
            }
            $db->prepare("UPDATE payments SET status = 'Paid', reviewed_by_admin_id = :admin_id, reviewed_at = NOW(), paid_at = NOW() WHERE id = :id")
                ->execute(['admin_id' => $adminId, 'id' => $payment['id']]);
            $db->prepare("UPDATE orders SET payment_status = 'Paid', order_status = 'Ready to Claim', verified_at = NOW(), updated_at = NOW() WHERE id = :id")
                ->execute(['id' => $orderId]);
            cvcarts_ensure_order_qr_token($db, $orderId);
            cvcarts_record_order_status($db, $orderId, $order['order_status'], 'Ready to Claim', $adminId, 'Cash payment received and verified.');
            cvcarts_add_notification($db, (int)$order['user_id'], 'Cash payment received', 'Cash payment for order ' . $order['reference_number'] . ' has been confirmed. Your receipt and claiming QR code are ready.', $orderId);
            $activity = 'Cash payment confirmed for ' . $order['reference_number'] . '.';
        } else {
            if ($order['payment_method'] === 'Cash' || $payment['status'] !== 'Pending Verification' || $order['payment_status'] !== 'Pending Verification' || $order['order_status'] !== 'Payment Verification') {
                $db->rollBack();
                return ['success' => false, 'message' => 'This online payment is no longer awaiting review.'];
            }

            if ($decision === 'approve') {
                if (!is_string($payment['transaction_reference']) || trim($payment['transaction_reference']) === ''
                    || !is_string($payment['proof_path']) || trim($payment['proof_path']) === '') {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'Online payment approval requires a transaction reference and uploaded proof.'];
                }
                $db->prepare("UPDATE payments SET status = 'Paid', rejection_reason = NULL, reviewed_by_admin_id = :admin_id, reviewed_at = NOW(), paid_at = NOW() WHERE id = :id")
                    ->execute(['admin_id' => $adminId, 'id' => $payment['id']]);
                $db->prepare("UPDATE orders SET payment_status = 'Paid', order_status = 'Ready to Claim', verified_at = NOW(), updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $orderId]);
                cvcarts_ensure_order_qr_token($db, $orderId);
                cvcarts_record_order_status($db, $orderId, $order['order_status'], 'Ready to Claim', $adminId, 'Online payment approved.');
                cvcarts_add_notification($db, (int)$order['user_id'], 'Payment approved', 'Payment for order ' . $order['reference_number'] . ' was approved. Your receipt and claiming QR code are ready.', $orderId);
                $activity = 'Payment approved for ' . $order['reference_number'] . '.';
            } else {
                $reason = trim($reason);
                if (strlen($reason) > 500) {
                    $db->rollBack();
                    return ['success' => false, 'message' => 'The rejection reason must be 500 characters or fewer.'];
                }
                $db->prepare("UPDATE payments SET status = 'Rejected', rejection_reason = :reason, reviewed_by_admin_id = :admin_id, reviewed_at = NOW(), paid_at = NULL WHERE id = :id")
                    ->execute(['reason' => $reason, 'admin_id' => $adminId, 'id' => $payment['id']]);
                $db->prepare("UPDATE orders SET payment_status = 'Rejected', order_status = 'Payment Rejected', updated_at = NOW() WHERE id = :id")
                    ->execute(['id' => $orderId]);
                cvcarts_record_order_status($db, $orderId, $order['order_status'], 'Payment Rejected', $adminId, $reason);
                cvcarts_add_notification($db, (int)$order['user_id'], 'Payment rejected', 'Payment for order ' . $order['reference_number'] . ' was rejected. Reason: ' . $reason, $orderId);
                $activity = 'Payment rejected for ' . $order['reference_number'] . '.';
            }
        }

        $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
            ->execute([
                'admin_id' => $adminId,
                'action' => $decision === 'reject' ? 'Payment rejected' : 'Payment confirmed',
                'details' => $activity,
            ]);
        cvcarts_emit_realtime_event($db, 'payment.updated', 'admin');
        cvcarts_emit_realtime_event($db, 'order.updated', 'student', (int)$order['user_id']);
        $db->commit();
        $message = $decision === 'reject'
            ? 'Payment rejected and the student was notified.'
            : ($decision === 'collect_cash'
                ? 'Cash payment approved. The QR code was generated and the order is Ready to Claim.'
                : 'Payment verified. The QR code was generated and the order is Ready to Claim.');
        return ['success' => true, 'message' => $message];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cvcarts_restore_cancelled_order_inventory(PDO $db, int $orderId, ?int $adminId): void {
    $itemsQuery = $db->prepare('SELECT product_id, selected_size, quantity FROM order_items WHERE order_id = :order_id ORDER BY product_id, selected_size FOR UPDATE');
    $itemsQuery->execute(['order_id' => $orderId]);
    foreach ($itemsQuery->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $productQuery = $db->prepare('SELECT stock, has_sizes FROM products WHERE id = :id FOR UPDATE');
        $productQuery->execute(['id' => $item['product_id']]);
        $product = $productQuery->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('An ordered product is no longer available for inventory restoration.');
        }

        $size = $item['selected_size'];
        if ((int)$product['has_sizes'] === 1) {
            if (!is_string($size) || $size === '') {
                throw new RuntimeException('The order has no recorded size; it was not cancelled so size inventory remains consistent.');
            }
            $sizeQuery = $db->prepare('SELECT id FROM product_sizes WHERE product_id = :product_id AND size = :size LIMIT 1 FOR UPDATE');
            $sizeQuery->execute(['product_id' => $item['product_id'], 'size' => $size]);
            $sizeId = $sizeQuery->fetchColumn();
            if ($sizeId === false) {
                throw new RuntimeException('The ordered size is missing; the order was not cancelled so size inventory remains consistent.');
            }
            $db->prepare('UPDATE product_sizes SET stock = stock + :quantity WHERE id = :id')
                ->execute(['quantity' => $item['quantity'], 'id' => $sizeId]);
            $db->prepare('UPDATE products SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_sizes WHERE product_id = :size_product_id AND is_active = 1) WHERE id = :product_id')
                ->execute(['size_product_id' => $item['product_id'], 'product_id' => $item['product_id']]);
        } else {
            $db->prepare('UPDATE products SET stock = stock + :quantity WHERE id = :id')
                ->execute(['quantity' => $item['quantity'], 'id' => $item['product_id']]);
            $size = null;
        }
        cvcarts_record_inventory_movement(
            $db,
            (int)$item['product_id'],
            is_string($size) && $size !== '' ? $size : null,
            $orderId,
            $adminId,
            (int)$item['quantity'],
            'order_cancelled'
        );
    }
}

function cvcarts_admin_update_order_status(PDO $db, int $orderId, int $adminId, string $newStatus, string $reason = ''): array {
    if ($newStatus !== 'Cancelled') {
        return ['success' => false, 'message' => 'Order status is managed by payment verification and the claim scanner.'];
    }
    $reason = trim($reason);
    if ($newStatus === 'Cancelled' && $reason === '') {
        return ['success' => false, 'message' => 'Provide a cancellation reason.'];
    }
    if (strlen($reason) > 500) {
        return ['success' => false, 'message' => 'The status reason must be 500 characters or fewer.'];
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id, user_id, reference_number, payment_status, order_status FROM orders WHERE id = :id FOR UPDATE');
        $query->execute(['id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Order not found.'];
        }
        $oldStatus = $order['order_status'];
        if ($newStatus === 'Cancelled' && (in_array($oldStatus, ['Claimed', 'Cancelled'], true) || $order['payment_status'] === 'Paid')) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Paid or already completed orders cannot be cancelled here.'];
        }

        $db->prepare('UPDATE orders SET order_status = :status, cancelled_at = CASE WHEN :cancelled_status = "Cancelled" THEN NOW() ELSE cancelled_at END, cancellation_reason = CASE WHEN :reason_status = "Cancelled" THEN :reason ELSE cancellation_reason END, updated_at = NOW() WHERE id = :id')
            ->execute([
                'status' => $newStatus,
                'cancelled_status' => $newStatus,
                'reason_status' => $newStatus,
                'reason' => $newStatus === 'Cancelled' ? $reason : null,
                'id' => $orderId,
            ]);
        if ($newStatus === 'Cancelled') {
            cvcarts_restore_cancelled_order_inventory($db, $orderId, $adminId);
        }
        cvcarts_record_order_status($db, $orderId, $oldStatus, $newStatus, $adminId, $reason !== '' ? $reason : null);
        cvcarts_add_notification($db, (int)$order['user_id'], 'Order updated', 'Your order ' . $order['reference_number'] . ' is now ' . $newStatus . ($newStatus === 'Cancelled' ? '. Reason: ' . $reason : '.'), $orderId);
        $db->prepare('INSERT INTO admin_activity (admin_id, action, details) VALUES (:admin_id, :action, :details)')
            ->execute([
                'admin_id' => $adminId,
                'action' => 'Order status updated',
                'details' => $order['reference_number'] . ': ' . $oldStatus . ' → ' . $newStatus,
            ]);
        cvcarts_emit_realtime_event($db, 'order.updated', 'admin');
        cvcarts_emit_realtime_event($db, 'order.updated', 'student', (int)$order['user_id']);
        if ($newStatus === 'Cancelled') {
            cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
        }
        $db->commit();
        return ['success' => true, 'message' => $newStatus === 'Cancelled' ? 'Order cancelled successfully.' : 'Order status updated.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function cvcarts_student_cancel_order(PDO $db, int $orderId, int $userId): array {
    if ($orderId < 1 || $userId < 1) {
        return ['success' => false, 'message' => 'This order is not available for cancellation.'];
    }

    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT id, user_id, reference_number, payment_status, order_status FROM orders WHERE id = :id FOR UPDATE');
        $query->execute(['id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        if (!$order || (int)$order['user_id'] !== $userId) {
            $db->rollBack();
            return ['success' => false, 'message' => 'This order is not available for cancellation.'];
        }
        if ($order['payment_status'] !== 'Unpaid' || $order['order_status'] !== 'Pending') {
            $db->rollBack();
            return ['success' => false, 'message' => 'Only pending, unpaid orders can be cancelled.'];
        }

        $reason = 'Cancelled by student.';
        $db->prepare('UPDATE orders SET order_status = "Cancelled", cancelled_at = NOW(), cancellation_reason = :reason, updated_at = NOW() WHERE id = :id')
            ->execute(['reason' => $reason, 'id' => $orderId]);
        cvcarts_restore_cancelled_order_inventory($db, $orderId, null);
        cvcarts_record_order_status($db, $orderId, 'Pending', 'Cancelled', $userId, $reason);
        cvcarts_add_notification($db, $userId, 'Order cancelled', 'Your order ' . $order['reference_number'] . ' was cancelled at your request.', $orderId);
        cvcarts_emit_realtime_event($db, 'order.updated', 'admin');
        cvcarts_emit_realtime_event($db, 'order.updated', 'student', $userId);
        cvcarts_emit_realtime_event($db, 'catalog.updated', 'public');
        $db->commit();
        return ['success' => true, 'message' => 'Your order was cancelled successfully.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
