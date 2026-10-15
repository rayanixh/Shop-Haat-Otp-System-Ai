<?php
/**
 * Customer-facing order-progress mapping.
 *
 * Orders and payments intentionally keep separate persisted statuses. This
 * module reads both rather than treating a paid order as delivered or a
 * delivered COD order as prepaid. It is shared by confirmation, order-detail
 * and public tracking surfaces so they cannot drift apart.
 */
declare(strict_types=1);

/** Normalize status spelling/case in the application layer without rewriting stored history. */
function sh_order_timeline_status_key(?string $status): string
{
    $key = strtolower(trim((string)$status));
    $key = preg_replace('/[\s-]+/', '_', $key) ?? '';
    return $key;
}

/** Current and legacy equivalents accepted by the order timeline only. */
function sh_order_timeline_order_status(?string $status): string
{
    $key = sh_order_timeline_status_key($status);
    return [
        'order_placed'       => 'pending',
        'placed'             => 'pending',
        'complete'           => 'completed',
        'delivery_complete'  => 'delivered',
        'delivery_completed' => 'delivered',
        'payment_approved'   => 'payment_verified',
    ][$key] ?? $key;
}

/** Current and legacy equivalents accepted by the payment timeline only. */
function sh_order_timeline_payment_status(?string $status): string
{
    $key = sh_order_timeline_status_key($status);
    return [
        'approved'         => 'verified',
        'payment_approved' => 'verified',
        'payment_failed'   => 'failed',
    ][$key] ?? $key;
}

/** @return ?string Exact admin status-change time when audit history exists. */
function sh_order_timeline_status_at(int $orderId, string $wantedStatus): ?string
{
    static $times = [];
    if ($orderId <= 0 || !function_exists('sh_table_exists') || !sh_table_exists('admin_audit_logs')) {
        return null;
    }
    if (!array_key_exists($orderId, $times)) {
        $times[$orderId] = [];
        try {
            $rows = sh_all(
                "SELECT new_value, created_at FROM admin_audit_logs
                 WHERE module = ? AND target_id = ? AND action = ?
                 ORDER BY id DESC LIMIT 60",
                ['order', $orderId, 'order_status_changed']
            );
            foreach ($rows as $row) {
                $stored = trim((string)($row['new_value'] ?? ''), " \t\n\r\0\x0B\"");
                $status = sh_order_timeline_order_status($stored);
                // Rows are newest-first, so retain the newest exact status event.
                if ($status !== '' && !isset($times[$orderId][$status]) && !empty($row['created_at'])) {
                    $times[$orderId][$status] = (string)$row['created_at'];
                }
            }
        } catch (Throwable $e) {
            sh_log_exception($e, 'order-timeline-status-time');
        }
    }
    return $times[$orderId][$wantedStatus] ?? null;
}

/** @return ?string A persisted timestamp only; invalid or absent values are never invented. */
function sh_order_timeline_timestamp($value): ?string
{
    $value = trim((string)$value);
    return $value !== '' && strtotime($value) !== false ? $value : null;
}

/** @return array{key:string,label:string,state:string,at:?string,note:string} */
function sh_order_timeline_step(string $key, string $label, string $state, ?string $at = null, string $note = ''): array
{
    return ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $at, 'note' => $note];
}

/**
 * Produce a truthful progress timeline from persisted order/payment records.
 *
 * @param array<string,mixed> $order
 * @param ?array<string,mixed> $payment Latest saved payment snapshot, if already loaded.
 * @param ?list<array<string,mixed>> $items Order items when available; used to
 *        distinguish a digital-only order from a mixed physical/digital order.
 * @return array{is_cod:bool,is_digital_only:bool,order_status:string,payment_status:string,steps:list<array{key:string,label:string,state:string,at:?string,note:string}>,problem:?array{label:string,detail:string,severity:string,at:?string}}
 */
function sh_order_timeline(array $order, ?array $payment = null, ?array $items = null): array
{
    $orderId = (int)($order['id'] ?? 0);
    $payment = $payment ?? ($orderId > 0 ? sh_order_latest_payment($orderId) : null);
    $orderStatus = sh_order_timeline_order_status($order['status'] ?? '');
    $paymentStatus = sh_order_timeline_payment_status($order['payment_status'] ?? '');
    $paymentRecordStatus = sh_order_timeline_payment_status($payment['status'] ?? '');
    $isCod = sh_order_is_cod($order, $payment);

    if ($items === null && $orderId > 0) {
        try {
            $items = sh_all('SELECT product_type FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
        } catch (Throwable $e) {
            sh_log_exception($e, 'order-timeline-items');
            $items = [];
        }
    }
    $hasDigital = (int)($order['has_digital'] ?? 0) === 1;
    $hasPhysical = false;
    foreach ($items ?? [] as $item) {
        if ((string)($item['product_type'] ?? '') === 'physical') { $hasPhysical = true; break; }
    }
    // On a legacy order without item snapshots, preserve the established
    // has_digital behaviour rather than presenting a made-up physical delivery.
    $isDigitalOnly = $hasDigital && (!$hasPhysical || empty($items));

    $placedAt = sh_order_timeline_timestamp($order['created_at'] ?? null);
    $submittedAt = sh_order_timeline_timestamp($payment['submitted_at'] ?? null);
    $verifiedAt = sh_order_timeline_timestamp($payment['verified_at'] ?? null);

    $hasReference = $payment !== null && (
        trim((string)($payment['transaction_id'] ?? '')) !== ''
        || trim((string)($payment['gateway_reference'] ?? '')) !== ''
    );
    $paymentSubmitted = !$isCod && (
        in_array($paymentStatus, ['submitted', 'verified', 'refunded'], true)
        || in_array($paymentRecordStatus, ['submitted', 'verified', 'refunded'], true)
        || $hasReference
        || in_array($orderStatus, ['payment_submitted', 'payment_verified', 'payment_rejected'], true)
    );
    $paymentVerified = !$isCod && (
        in_array($paymentStatus, ['verified', 'refunded'], true)
        || in_array($paymentRecordStatus, ['verified', 'refunded'], true)
        || $orderStatus === 'payment_verified'
    );
    $paymentFailed = !$isCod && (
        $orderStatus === 'payment_rejected'
        || $paymentStatus === 'rejected'
        || (!$paymentVerified && in_array($paymentRecordStatus, ['rejected', 'failed', 'cancelled'], true))
    );
    if ($paymentFailed) { $paymentSubmitted = true; }
    $failedAt = $paymentFailed ? $verifiedAt : null;

    $processingReached = in_array($orderStatus, ['processing', 'shipped', 'delivered', 'completed'], true);
    $deliveryReached = in_array($orderStatus, ['delivered', 'completed'], true);
    $codesDelivered = $isDigitalOnly && (int)($order['codes_delivered'] ?? 0) === 1;
    $cancelled = $orderStatus === 'cancelled';
    $returned = $orderStatus === 'returned';
    $refunded = $paymentStatus === 'refunded';

    $problem = null;
    if ($cancelled) {
        $problem = [
            'label' => 'Cancelled',
            'detail' => 'This order was cancelled and will not be delivered.',
            'severity' => 'error',
            'at' => sh_order_timeline_status_at($orderId, 'cancelled'),
        ];
    } elseif ($returned) {
        $problem = [
            'label' => 'Returned',
            'detail' => 'This order was returned and is no longer progressing to delivery.',
            'severity' => 'error',
            'at' => sh_order_timeline_status_at($orderId, 'returned'),
        ];
    } elseif ($paymentFailed) {
        $problem = [
            'label' => 'Payment rejected',
            'detail' => 'The saved payment record was rejected or failed. It has not been verified.',
            'severity' => 'error',
            'at' => $failedAt,
        ];
    } elseif ($refunded) {
        $problem = [
            'label' => 'Refund recorded',
            'detail' => 'A refund is recorded for this order. Delivery is shown only when the order status itself confirms it.',
            'severity' => 'warning',
            'at' => null,
        ];
    } elseif (!$isCod && $deliveryReached && !$paymentVerified) {
        // Preserve both facts rather than falsely filling prepaid milestones.
        $problem = [
            'label' => 'Payment status needs review',
            'detail' => 'The order is marked as delivered, but its saved payment status is not verified.',
            'severity' => 'warning',
            'at' => null,
        ];
    }

    $terminalProblem = $cancelled || $returned || $paymentFailed;
    $active = null;
    if (!$terminalProblem) {
        if ($isDigitalOnly) {
            if ($orderStatus === 'completed') {
                $active = null;
            } elseif ($codesDelivered) {
                $active = 'code_delivered';
            } elseif ($paymentVerified) {
                $active = 'payment_verified';
            } elseif ($paymentSubmitted) {
                $active = 'payment_verified';
            } elseif (!$isCod) {
                $active = 'payment_submitted';
            } else {
                $active = 'order_placed';
            }
        } elseif ($deliveryReached) {
            $active = null;
        } elseif ($processingReached) {
            $active = 'processing';
        } elseif ($paymentVerified) {
            $active = 'payment_verified';
        } elseif ($paymentSubmitted) {
            $active = 'payment_verified';
        } elseif (!$isCod) {
            $active = 'payment_submitted';
        } else {
            $active = 'order_placed';
        }
    }

    $state = static function (string $key, bool $reached) use ($active): string {
        if ($reached) { return $active === $key ? 'current' : 'done'; }
        return $active === $key ? 'current' : 'pending';
    };

    $steps = [sh_order_timeline_step('order_placed', 'Order Placed', $state('order_placed', true), $placedAt)];
    if ($isCod) {
        $steps[] = sh_order_timeline_step('payment_not_required', 'Payment on Delivery', 'not-required', null, 'Cash on Delivery');
    } else {
        $verifiedLabel = $paymentFailed ? 'Payment Rejected' : 'Payment Verified';
        $verifiedState = $paymentFailed ? 'bad' : $state('payment_verified', $paymentVerified);
        $steps[] = sh_order_timeline_step('payment_submitted', 'Payment Submitted', $state('payment_submitted', $paymentSubmitted), $submittedAt);
        $steps[] = sh_order_timeline_step('payment_verified', $verifiedLabel, $verifiedState, $paymentVerified ? $verifiedAt : ($paymentFailed ? $failedAt : null));
    }

    if ($isDigitalOnly) {
        $steps[] = sh_order_timeline_step('code_delivered', 'Code Delivered', $state('code_delivered', $codesDelivered), null);
        $steps[] = sh_order_timeline_step('delivery', 'Completed', $state('delivery', $orderStatus === 'completed'), sh_order_timeline_status_at($orderId, 'completed'));
    } else {
        $steps[] = sh_order_timeline_step('processing', 'Processing', $state('processing', $processingReached), sh_order_timeline_status_at($orderId, 'processing'));
        $steps[] = sh_order_timeline_step('delivery', 'Delivery', $state('delivery', $deliveryReached),
            $deliveryReached ? sh_order_timeline_status_at($orderId, $orderStatus) : null);
    }

    if ($cancelled || $returned) {
        $steps[] = sh_order_timeline_step($cancelled ? 'cancelled' : 'returned', $cancelled ? 'Cancelled' : 'Returned', 'bad', $problem['at']);
    } elseif ($refunded) {
        $steps[] = sh_order_timeline_step('refunded', 'Refund Recorded', 'bad');
    }

    return [
        'is_cod' => $isCod,
        'is_digital_only' => $isDigitalOnly,
        'order_status' => $orderStatus,
        'payment_status' => $paymentStatus,
        'steps' => $steps,
        'problem' => $problem,
    ];
}

/** Render a responsive tracking sequence using the existing ShopHaat track styles. */
function sh_order_timeline_render(array $timeline): string
{
    $icons = [
        'done' => 'check-circle',
        'current' => 'clock',
        'bad' => 'x-circle',
        'not-required' => 'minus',
    ];
    $html = '<div class="sh-track" role="list" aria-label="Order progress">';
    foreach (($timeline['steps'] ?? []) as $step) {
        $state = (string)($step['state'] ?? 'pending');
        $class = match ($state) {
            'done' => ' sh-track__step--done',
            'current' => ' sh-track__step--on',
            'bad' => ' sh-track__step--bad',
            'not-required' => ' sh-track__step--not-required',
            default => '',
        };
        $icon = isset($icons[$state]) ? sh_icon($icons[$state], 11) : '';
        $html .= '<div class="sh-track__step' . $class . '" role="listitem">'
            . '<span class="sh-track__dot">' . $icon . '</span>'
            . '<span class="sh-track__label">' . e((string)($step['label'] ?? ''));
        if (!empty($step['note'])) {
            $html .= '<small class="sh-track__meta">' . e((string)$step['note']) . '</small>';
        }
        if (!empty($step['at'])) {
            $html .= '<small class="sh-track__meta">' . e(date('d M Y, h:i A', strtotime((string)$step['at']))) . '</small>';
        }
        $html .= '</span></div>';
    }
    return $html . '</div>';
}

/** Render a truthful terminal/inconsistency message without exposing internal SQL details. */
function sh_order_timeline_problem_render(array $timeline): string
{
    $problem = $timeline['problem'] ?? null;
    if (!is_array($problem)) { return ''; }
    $class = ($problem['severity'] ?? '') === 'warning' ? 'sh-alert--warning' : 'sh-alert--error';
    $icon = ($problem['severity'] ?? '') === 'warning' ? 'alert' : 'x-circle';
    $html = '<div class="sh-alert ' . $class . '" style="margin-top:12px">' . sh_icon($icon, 16)
        . '<div><strong>' . e((string)($problem['label'] ?? 'Order update')) . '</strong>';
    if (!empty($problem['detail'])) { $html .= '<p style="margin-top:3px">' . e((string)$problem['detail']) . '</p>'; }
    if (!empty($problem['at'])) { $html .= '<p style="margin-top:3px">' . e(date('d M Y, h:i A', strtotime((string)$problem['at']))) . '</p>'; }
    return $html . '</div></div>';
}
