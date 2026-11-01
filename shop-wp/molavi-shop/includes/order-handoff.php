<?php
/** Existing WooCommerce orders remain authoritative; this retries the server mirror. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_publish_order_notice(int $orderId, int $attempt = 0): void
{
    $order = wc_get_order($orderId);
    if (!$order || $order->get_meta('_molavi_published') === '1') {
        return;
    }
    $config = molavi_legacy_config();
    $secret = (string) ($config['notify_secret'] ?? '');
    $bot = rtrim((string) ($config['bot_url'] ?? ''), '/');
    if ($secret === '' || $bot === '') {
        molavi_retry_order_handoff($order, $attempt);
        return;
    }
    $payload = molavi_order_handoff_payload($order, true);
    $saved = $order->get_meta('_molavi_handoff_payload');
    if (is_array($saved) && isset($saved['order']['id'])) { $payload = $saved; }
    else { $order->update_meta_data('_molavi_handoff_payload', $payload); $order->save(); }
    if (molavi_post_order_handoff($payload)) {
        $order->update_meta_data('_molavi_published', '1');
        $order->update_meta_data('_molavi_handoff_status', 'written');
        $order->save();
    } else {
        molavi_retry_order_handoff($order, $attempt);
    }
}

function molavi_order_handoff_payload(
    $order,
    bool $notify,
    bool $paymentVerified = false,
    string $verifiedTransactionId = ''
): array
{
    $lines = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $savedSku = (string) $item->get_meta('_molavi_order_sku', true);
        $savedOfferId = (string) $item->get_meta('_molavi_order_supplier_offer_id', true);
        $lines[] = [
            'id' => (string) ($item->get_variation_id() ?: $item->get_product_id()),
            'product_id' => (string) ($item->get_variation_id() ?: $item->get_product_id()),
            'sku' => $savedSku !== '' ? $savedSku : ($product ? (string) $product->get_sku() : ''),
            'supplier_offer_id' => $savedOfferId !== '' ? $savedOfferId
                : ($product ? (string) $product->get_meta('_molavi_supplier_offer_id', true) : ''),
            'name' => $item->get_name(),
            'qty' => $item->get_quantity(),
            'total' => (int) $item->get_total(),
        ];
    }
    $paidAt = $order->get_date_paid();
    $isPaid = $paymentVerified || $order->is_paid();
    $wooStatus = (string) $order->get_status();
    if ($paymentVerified && !$order->is_paid() && !in_array($wooStatus, ['cancelled', 'refunded', 'failed'], true)) {
        $wooStatus = $order->needs_processing() ? 'processing' : 'completed';
    }
    $paymentStatus = $order->has_status('refunded') ? 'refunded' : ($isPaid ? 'verified' : 'pending');
    return [
        'notify' => $notify,
        'order' => [
            'id' => (string) $order->get_id(),
            'name' => $order->get_formatted_billing_full_name(),
            'phone' => $order->get_billing_phone(),
            'email' => $order->get_billing_email(),
            'address' => $order->get_billing_address_1(),
            'note' => $order->get_customer_note(),
            'total' => (int) $order->get_total(),
            'currency' => $order->get_currency(),
            'woo_status' => $wooStatus,
            'payment_status' => $paymentStatus,
            'payment_method' => (string) $order->get_payment_method(),
            'transaction_id' => $verifiedTransactionId !== ''
                ? $verifiedTransactionId : (string) $order->get_transaction_id(),
            'paid_at' => $paidAt ? $paidAt->date('c') : ($paymentVerified ? gmdate('c') : ''),
            'lines' => $lines,
        ],
    ];
}

function molavi_capture_order_line_source($item, string $cartItemKey, array $values, $order): void
{
    $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
    if (!$product instanceof WC_Product) { return; }
    $sku = (string) $product->get_sku();
    $offerId = (string) $product->get_meta('_molavi_supplier_offer_id', true);
    if ($sku !== '') { $item->add_meta_data('_molavi_order_sku', $sku, true); }
    if ($offerId !== '') { $item->add_meta_data('_molavi_order_supplier_offer_id', $offerId, true); }
}

function molavi_post_order_handoff(array $payload): bool
{
    $config = molavi_legacy_config();
    $secret = (string) ($config['notify_secret'] ?? '');
    $bot = rtrim((string) ($config['bot_url'] ?? ''), '/');
    if ($secret === '' || $bot === '') {
        return false;
    }
    $response = wp_remote_post($bot . '/shop/order', [
        'timeout' => 12,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-Shop-Token' => $secret,
        ],
        'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    $receipt = json_decode(wp_remote_retrieve_body($response), true);
    $code = (int) wp_remote_retrieve_response_code($response);
    return $code >= 200 && $code < 300 && is_array($receipt)
        && ($receipt['written'] ?? false) === true
        && (string) ($receipt['order_id'] ?? '') === (string) ($payload['order']['id'] ?? '');
}

function molavi_sync_paid_order(int $orderId, string $transactionId = ''): void
{
    molavi_sync_order_snapshot($orderId, $transactionId, true);
}

function molavi_sync_terminal_order(int $orderId): void
{
    $order = wc_get_order($orderId);
    if (!$order || !in_array($order->get_status(), ['cancelled', 'refunded', 'failed'], true)) {
        return;
    }
    molavi_sync_order_snapshot($orderId, (string) $order->get_transaction_id(), false);
}

function molavi_sync_order_snapshot(int $orderId, string $transactionId, bool $paymentVerified): void
{
    $order = wc_get_order($orderId);
    if (!$order) {
        return;
    }
    if ($transactionId !== '' && $order->get_transaction_id() === '') {
        $order->set_transaction_id($transactionId);
        $order->save();
    }
    // This hook is emitted by Woo after it accepts payment completion. Use the event
    // as the verification signal; a cached order object may still expose old status.
    $payload = molavi_order_handoff_payload($order, false, $paymentVerified, $transactionId);
    $paidOrder = $payload['order'];
    $eventKey = hash('sha256', wp_json_encode([
        $paidOrder['id'], $paidOrder['payment_status'], $paidOrder['transaction_id'],
        $paidOrder['total'], $paidOrder['currency'], $paidOrder['woo_status'],
    ]));
    if ($order->get_meta('_molavi_paid_handoff_complete') === $eventKey) {
        return;
    }
    $savedKey = (string) $order->get_meta('_molavi_paid_handoff_pending_key');
    $savedPayload = $order->get_meta('_molavi_paid_handoff_pending_payload');
    if ($savedKey !== $eventKey || !is_array($savedPayload)) {
        $order->update_meta_data('_molavi_paid_handoff_pending_key', $eventKey);
        $order->update_meta_data('_molavi_paid_handoff_pending_payload', $payload);
        $order->save();
    } else {
        $payload = $savedPayload;
    }
    if (molavi_post_order_handoff($payload)) {
        molavi_mark_paid_handoff_complete($order, $eventKey, $payload);
        return;
    }
    molavi_schedule_paid_handoff_retry($order, 0);
}

function molavi_retry_paid_order_handoff(int $orderId, int $attempt = 0): void
{
    $order = wc_get_order($orderId);
    if (!$order) {
        return;
    }
    $pendingPayload = $order->get_meta('_molavi_paid_handoff_pending_payload');
    $pendingStatus = is_array($pendingPayload) ? (string) ($pendingPayload['order']['woo_status'] ?? '') : '';
    if (!$order->is_paid() && !in_array($pendingStatus, ['cancelled', 'refunded', 'failed'], true)) {
        $order->update_meta_data('_molavi_paid_handoff_status', 'superseded');
        $order->delete_meta_data('_molavi_paid_handoff_pending_key');
        $order->delete_meta_data('_molavi_paid_handoff_pending_payload');
        $order->save();
        return;
    }
    $eventKey = (string) $order->get_meta('_molavi_paid_handoff_pending_key');
    $payload = $order->get_meta('_molavi_paid_handoff_pending_payload');
    if ($eventKey === '' || !is_array($payload)
        || $order->get_meta('_molavi_paid_handoff_complete') === $eventKey) {
        return;
    }
    if (molavi_post_order_handoff($payload)) {
        molavi_mark_paid_handoff_complete($order, $eventKey, $payload);
        return;
    }
    molavi_schedule_paid_handoff_retry($order, $attempt);
}

function molavi_mark_paid_handoff_complete($order, string $eventKey, array $payload): void
{
    $order->update_meta_data('_molavi_paid_handoff_complete', $eventKey);
    $order->update_meta_data('_molavi_paid_handoff_status', 'written');
    $order->update_meta_data('_molavi_handoff_payload', $payload);
    $order->update_meta_data('_molavi_published', '1');
    $order->update_meta_data('_molavi_handoff_status', 'written');
    $order->delete_meta_data('_molavi_paid_handoff_pending_key');
    $order->delete_meta_data('_molavi_paid_handoff_pending_payload');
    $order->save();
}

function molavi_schedule_paid_handoff_retry($order, int $attempt): void
{
    $order->update_meta_data('_molavi_paid_handoff_status', $attempt >= 5 ? 'failed' : 'pending');
    $order->save();
    if ($attempt >= 5) {
        $order->add_order_note('تأیید پرداخت به سرور ارسال نشد؛ نیاز به بررسی دارد.');
        return;
    }
    $args = [(int) $order->get_id(), $attempt + 1];
    if (function_exists('as_schedule_single_action')) {
        if (!as_has_scheduled_action('molavi_retry_paid_order_handoff', $args, 'molavi-orders')) {
            as_schedule_single_action(time() + min(900, 60 * (2 ** $attempt)),
                'molavi_retry_paid_order_handoff', $args, 'molavi-orders');
        }
    } elseif (!wp_next_scheduled('molavi_retry_paid_order_handoff', $args)) {
        wp_schedule_single_event(time() + 60, 'molavi_retry_paid_order_handoff', $args);
    }
}

function molavi_retry_order_handoff($order, int $attempt): void
{
    $order->update_meta_data('_molavi_handoff_status', $attempt >= 5 ? 'failed' : 'pending');
    $order->save();
    if ($attempt >= 5) {
        $order->add_order_note('ارسال سفارش به سرور تأیید نشده است؛ نیاز به بررسی دارد.');
        return;
    }
    $args = [(int) $order->get_id(), $attempt + 1];
    if (function_exists('as_schedule_single_action')) {
        if (!as_has_scheduled_action('molavi_retry_order_notice', $args, 'molavi-orders')) {
            as_schedule_single_action(time() + min(900, 60 * (2 ** $attempt)),
                'molavi_retry_order_notice', $args, 'molavi-orders');
        }
    } elseif (!wp_next_scheduled('molavi_retry_order_notice', $args)) {
        wp_schedule_single_event(time() + 60, 'molavi_retry_order_notice', $args);
    }
}

add_action('woocommerce_checkout_order_processed', 'molavi_publish_order_notice', 20, 1);
add_action('woocommerce_checkout_create_order_line_item', 'molavi_capture_order_line_source', 10, 4);
add_action('woocommerce_store_api_checkout_order_processed', static function ($order): void {
    molavi_publish_order_notice((int) $order->get_id());
}, 20, 1);
add_action('molavi_retry_order_notice', 'molavi_publish_order_notice', 10, 2);
add_action('woocommerce_payment_complete', 'molavi_sync_paid_order', 20, 2);
add_action('woocommerce_order_status_cancelled', 'molavi_sync_terminal_order', 20, 1);
add_action('woocommerce_order_status_refunded', 'molavi_sync_terminal_order', 20, 1);
add_action('woocommerce_order_status_failed', 'molavi_sync_terminal_order', 20, 1);
add_action('molavi_retry_paid_order_handoff', 'molavi_retry_paid_order_handoff', 10, 2);
