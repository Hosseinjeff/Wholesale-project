<?php
/** Publication-recovery view integrated into the existing private Woo admin. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_pipeline_request(string $path, string $method, ?array $payload = null) {
    if (!molavi_reference_owner()) { return new WP_Error('owner', 'دسترسی مجاز نیست.'); }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token) < 32 || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') {
        return new WP_Error('config', 'اتصال به سرور تنظیم نشده است.');
    }
    $args = ['method'=>$method, 'timeout'=>12, 'redirection'=>0,
        'headers'=>['X-Molavi-Reference-Token'=>$token, 'X-Molavi-Actor'=>'wp:' . get_current_user_id(),
                    'Content-Type'=>'application/json']];
    $wirePayload=$payload;
    if ($method==='POST' && is_array($payload) && isset($payload['expected_receipt_hash'])) {
        $wirePayload=$payload;
        $wirePayload['receipt_hash']=$payload['expected_receipt_hash'];
        unset($wirePayload['expected_receipt_hash']);
    }
    if ($wirePayload !== null) { $args['body']=wp_json_encode($wirePayload); }
    $response=wp_safe_remote_request(rtrim($base,'/') . $path, $args);
    if (is_wp_error($response)) { return new WP_Error('offline', 'ارتباط با سرور برقرار نشد.'); }
    $body=json_decode(wp_remote_retrieve_body($response), true);
    if (wp_remote_retrieve_response_code($response)!==200 || !is_array($body) || ($body['ok'] ?? false)!==true) {
        return new WP_Error('failed', 'عملیات تأیید نشد. صفحه را تازه کنید و دوباره بررسی کنید.');
    }
    if ($method==='POST') {
        if ($payload===null) { return new WP_Error('receipt', 'تأیید سرور با درخواست مطابقت ندارد.'); }
        foreach (['hold_id','product_id','source_revision','conflict_id','order_id','state','terms_hash',
                  'lot_id','receipt_hash'] as $key) {
            if ($key==='receipt_hash' && array_key_exists('expected_receipt_hash',$payload)) { continue; }
            if (array_key_exists($key,$payload) && ($body[$key] ?? null)!==$payload[$key]) {
                return new WP_Error('receipt', 'تأیید سرور با درخواست مطابقت ندارد.');
            }
        }
        if (array_key_exists('order_id',$payload) && array_key_exists('supplier_terms',$payload)
            && (!in_array($body['state'] ?? '', ['needs_owner_review','procurement_proposed','reserved','released'], true)
                || !preg_match('/^[a-f0-9]{64}$/', (string)($body['terms_hash'] ?? '')))) {
            return new WP_Error('receipt', 'تأیید پیشنهاد سفارش معتبر نیست.');
        }
        if (array_key_exists('order_id',$payload) && array_key_exists('action',$payload)
            && (($body['action'] ?? null)!==$payload['action']
                || !is_array($body['workflow'] ?? null)
                || ($body['workflow']['order_id'] ?? null)!==$payload['order_id']
                || ($body['workflow']['last_event']['action'] ?? null)!==$payload['action'])) {
            return new WP_Error('receipt', 'تأیید عملیات سفارش معتبر نیست.');
        }
        $lotReceiptStates=(array_key_exists('expected_receipt_hash',$payload)
            || array_key_exists('expected_revision',$payload))
            ? ['ready','review','held'] : ['ready','review'];
        if (array_key_exists('lot_id',$payload)
            && (!in_array($body['state'] ?? '', $lotReceiptStates, true)
                || !preg_match('/^[a-f0-9]{64}$/', (string)($body['receipt_hash'] ?? '')))) {
            return new WP_Error('receipt', 'تأیید ثبت موجودی معتبر نیست.');
        }
        if (array_key_exists('lot_id',$payload) && array_key_exists('action',$payload)) {
            $expectedState=['hold'=>'held','release'=>'ready'];
            $eventAction=$payload['action'];
            $expectedRevision=$payload['expected_revision'] ?? null;
            $eventId=(string)($body['event_id'] ?? '');
            $revision=(string)($body['revision'] ?? '');
            if (!is_string($eventAction) || !in_array($eventAction,['adjust','hold','release'],true)
                || !is_string($expectedRevision) || !preg_match('/^[a-f0-9]{64}$/',$expectedRevision)
                || ($body['action'] ?? null)!==$eventAction
                || ($body['previous_revision'] ?? null)!==$expectedRevision
                || !preg_match('/^[a-f0-9]{64}$/',$eventId)
                || !preg_match('/^[a-f0-9]{64}$/',$revision)
                || !is_int($body['units'] ?? null) || $body['units']<0
                || !is_bool($body['idempotent'] ?? null)
                || (isset($expectedState[$eventAction]) && ($body['state'] ?? null)!==$expectedState[$eventAction])) {
                return new WP_Error('receipt', 'تأیید اصلاح موجودی با نسخه بررسی‌شده مطابقت ندارد.');
            }
        }
        if (array_key_exists('normal_price_toman',$payload)
            && (!preg_match('/^[a-f0-9]{64}$/',(string)($body['proposal_id'] ?? ''))
                || !is_array($body['result'] ?? null))) {
            return new WP_Error('receipt', 'تأیید پیشنهاد قیمت معتبر نیست.');
        }
        if (array_key_exists('expected_revision',$payload) && !array_key_exists('lot_id',$payload)
            && ($body['source_revision'] ?? null)!==$payload['expected_revision']) {
            return new WP_Error('receipt', 'تأیید سرور با نسخه قیمت مطابقت ندارد.');
        }
    }
    return $body;
}

function molavi_pipeline_holds_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/publication-holds',$method,$payload);
}

function molavi_supplier_prices_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/supplier-price-evidence',$method,$payload);
}

function molavi_sheet_conflicts_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/sheet-conflicts',$method,$payload);
}

function molavi_paid_orders_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/paid-orders',$method,$payload);
}

function molavi_stock_lots_request(string $method, ?array $payload = null) {
    return molavi_pipeline_request('/pipeline/stock-lots',$method,$payload);
}

function molavi_paid_order_operation_form(string $orderId, string $operation, array $hidden, array $fields, string $label): void {
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('molavi_paid_order_operation:' . $orderId . ':' . $operation);
    echo '<input type="hidden" name="action" value="molavi_paid_order_operation">';
    echo '<input type="hidden" name="order_id" value="' . esc_attr($orderId) . '">';
    echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
    foreach ($hidden as $key=>$value) {
        echo '<input type="hidden" name="details[' . esc_attr((string)$key) . ']" value="' . esc_attr((string)$value) . '">';
    }
    foreach ($fields as $field) {
        if (!is_array($field) || !isset($field['key'],$field['label'],$field['type'])) { continue; }
        $key=(string)$field['key']; $type=(string)$field['type'];
        echo '<label>' . esc_html((string)$field['label']) . ' ';
        if ($type==='select') {
            echo '<select name="details[' . esc_attr($key) . ']' . (!empty($field['multiple'])?'[]':'') . '"' . (!empty($field['required'])?' required':'') . '>';
            foreach (($field['options'] ?? []) as $value=>$text) {
                echo '<option value="' . esc_attr((string)$value) . '">' . esc_html((string)$text) . '</option>';
            }
            echo '</select>';
        } elseif ($type==='textarea') {
            echo '<textarea name="details[' . esc_attr($key) . ']" maxlength="' . esc_attr((string)($field['max'] ?? 500)) . '"' . (!empty($field['required'])?' required':'') . '></textarea>';
        } elseif ($type==='checkbox') {
            echo '<input name="details[' . esc_attr($key) . ']" type="checkbox" value="1">';
        } else {
            echo '<input name="details[' . esc_attr($key) . ']" type="' . esc_attr($type) . '"'
                . (isset($field['min'])?' min="' . esc_attr((string)$field['min']) . '"':'')
                . (isset($field['max'])?' max="' . esc_attr((string)$field['max']) . '"':'')
                . (isset($field['value'])?' value="' . esc_attr((string)$field['value']) . '"':'')
                . (!empty($field['required'])?' required':'') . '>';
        }
        echo '</label> ';
    }
    echo '<label>' . esc_html('یادداشت مالک') . ' <textarea name="note" required maxlength="1000"></textarea></label> ';
    echo '<button class="button">' . esc_html($label) . '</button></form>';
}

add_action('admin_menu', static function (): void {
    if (!molavi_reference_owner()) { return; }
    add_submenu_page('woocommerce', 'بررسی انتشار مولوی‌سل', 'بررسی انتشار مولوی‌سل',
        'manage_woocommerce', 'molavi-publication-holds', 'molavi_publication_holds_screen');
    add_submenu_page('woocommerce', 'تازگی قیمت تأمین‌کنندگان', 'تازگی قیمت تأمین‌کنندگان',
        'manage_woocommerce', 'molavi-supplier-prices', 'molavi_supplier_prices_screen');
    add_submenu_page('woocommerce', 'تعارض تغییرات شیت', 'تعارض تغییرات شیت',
        'manage_woocommerce', 'molavi-sheet-conflicts', 'molavi_sheet_conflicts_screen');
    add_submenu_page('woocommerce', 'آماده‌سازی سفارش‌های پرداخت‌شده', 'آماده‌سازی سفارش‌ها',
        'manage_woocommerce', 'molavi-paid-orders', 'molavi_paid_orders_screen');
});

function molavi_paid_orders_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('آماده‌سازی سفارش‌های پرداخت‌شده') . '</h1>';
    echo '<p>' . esc_html('موجودی ثبت‌شده برای سفارش نگه داشته می‌شود. کسری کالا فقط به شکل پیشنهاد خرید بسته کامل نمایش داده می‌شود؛ این صفحه هیچ خریدی برای تأمین‌کننده ثبت نمی‌کند.') . '</p>';
    echo '<h2>' . esc_html('ثبت رسید موجودی') . '</h2>';
    $stock=molavi_stock_lots_request('GET');
    $stockLots=[];
    if (is_wp_error($stock) || !isset($stock['lots']) || !is_array($stock['lots'])) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($stock) ? $stock->get_error_message() : 'پاسخ موجودی معتبر نیست.') . '</p></div>';
    } else {
        $stockLots=$stock['lots'];
        echo '<table class="widefat striped"><thead><tr><th>شناسه بچ</th><th>SKU</th><th>تأمین‌کننده</th><th>خرید</th><th>دریافت</th><th>تعداد</th><th>در دسترس</th><th>بهای واحد (تومان)</th><th>تولید</th><th>انقضا</th><th>وضعیت</th></tr></thead><tbody>';
        foreach ($stock['lots'] as $lot) {
            if (!is_array($lot)) { continue; }
            echo '<tr><td>' . esc_html((string)($lot['lot_id'] ?? '')) . '</td><td>' . esc_html((string)($lot['sku'] ?? ''))
                . '</td><td>' . esc_html((string)($lot['supplier'] ?? '')) . '</td><td>' . esc_html((string)($lot['purchase_date'] ?? ''))
                . '</td><td>' . esc_html((string)($lot['received_at'] ?? '')) . '</td><td>' . esc_html((string)($lot['units'] ?? ''))
                . '</td><td>' . esc_html((string)($lot['available_units'] ?? '')) . '</td><td>' . esc_html((string)($lot['unit_cost'] ?? ''))
                . '</td><td>' . esc_html((string)($lot['production_date'] ?? '')) . '</td><td>' . esc_html((string)($lot['expiry_date'] ?? ''))
                . '</td><td>' . esc_html((string)($lot['state'] ?? 'review')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('molavi_receive_stock_lot');
        echo '<input type="hidden" name="action" value="molavi_receive_stock_lot">';
        echo '<p><label>' . esc_html('شناسه بچ / رسید') . ' <input name="lot_id" required maxlength="120"></label> ';
        echo '<label>SKU <input name="sku" required maxlength="80"></label> ';
        echo '<label>' . esc_html('تأمین‌کننده') . ' <input name="supplier" required maxlength="120"></label></p>';
        echo '<p><label>' . esc_html('تاریخ خرید (اگر ثبت نشده خالی بماند؛ قیمت‌گذاری انبار با نبود تاریخ نیازمند بررسی است)') . ' <input name="purchase_date" type="date"></label></p>';
        echo '<p><label>' . esc_html('تاریخ دریافت') . ' <input name="received_at" type="date" required value="' . esc_attr(current_time('Y-m-d')) . '"></label> ';
        echo '<label>' . esc_html('تاریخ تولید (در صورت وجود)') . ' <input name="production_date" type="date"></label> ';
        echo '<label>' . esc_html('تاریخ انقضا') . ' <input name="expiry_date" type="date"></label></p>';
        echo '<p><label>' . esc_html('تعداد واحدهای قابل فروش') . ' <input name="units" type="number" min="1" max="1000000" required></label> ';
        echo '<label>' . esc_html('بهای خرید هر واحد (تومان)') . ' <input name="unit_cost" type="number" min="1" max="1000000000000" required></label></p>';
        echo '<p><label>' . esc_html('یادداشت رسید') . ' <textarea name="note" required maxlength="1000"></textarea></label></p>';
        echo '<button class="button">' . esc_html('ثبت رسید موجودی') . '</button></form>';
    }
    $result=molavi_paid_orders_request('GET');
    if (is_wp_error($result) || !isset($result['orders']) || !is_array($result['orders'])) {
        echo '<div class="notice notice-error"><p>' . esc_html(is_wp_error($result) ? $result->get_error_message() : 'پاسخ سرور معتبر نیست.') . '</p></div></div>';
        return;
    }
    if ($result['orders']===[]) { echo '<p>' . esc_html('سفارش پرداخت‌شده‌ای برای آماده‌سازی ثبت نشده است.') . '</p>'; }
    $labels=['reserved'=>'موجودی ثبت‌شده برای سفارش نگه داشته شد',
        'procurement_proposed'=>'پیشنهاد تأمین بسته‌ای آماده بررسی است',
        'needs_owner_review'=>'برای کسری این سفارش، بررسی مالک لازم است',
        'released'=>'رزرو موجودی آزاد شد'];
    foreach ($result['orders'] as $plan) {
        if (!is_array($plan) || !is_string($plan['order_id'] ?? null)) { continue; }
        $orderId=(string)$plan['order_id'];
        echo '<div class="card"><h2>' . esc_html('سفارش ' . $orderId) . '</h2>';
        echo '<p>' . esc_html($labels[$plan['state'] ?? ''] ?? 'نیازمند بررسی') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>SKU</th><th>تعداد مشتری</th><th>موجودی رزروشده</th><th>کسری</th></tr></thead><tbody>';
        foreach (($plan['lines'] ?? []) as $line) {
            if (!is_array($line)) { continue; }
            echo '<tr><td>' . esc_html((string)($line['sku'] ?? '')) . '</td><td>' . esc_html((string)($line['customer_units'] ?? ''))
                . '</td><td>' . esc_html((string)($line['owned_units_reserved'] ?? '')) . '</td><td>'
                . esc_html((string)($line['shortfall_units'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
        $workflow=is_array($plan['workflow'] ?? null) ? $plan['workflow'] : null;
        $workflowProcurement=is_array($workflow['procurement'] ?? null) ? $workflow['procurement'] : [];
        $resolutions=is_array($workflow['resolutions'] ?? null) ? $workflow['resolutions'] : [];
        $shortages=array_values(array_filter(($plan['lines'] ?? []), static function ($line) {
            return is_array($line) && (int)($line['shortfall_units'] ?? 0)>0;
        }));
        if ($workflow!==null) {
            echo '<p>' . esc_html('مسئول: ' . ((string)($workflow['assigned_to'] ?? '') ?: 'تعیین نشده')
                . ' · اقدام بعدی: ' . ((string)($workflow['next_action'] ?? '') ?: 'ثبت نشده')
                . ' · وضعیت پیگیری: ' . (string)($workflow['state'] ?? '')) . '</p>';
            if (!in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                molavi_paid_order_operation_form($orderId,'assign',[],[
                    ['key'=>'assigned_to','label'=>'مسئول','type'=>'text','max'=>120],
                    ['key'=>'next_action','label'=>'اقدام بعدی','type'=>'text','max'=>300],
                    ['key'=>'due_at','label'=>'تاریخ پیگیری','type'=>'date'],
                ],'ثبت مسئول و اقدام بعدی');
            }
            echo '<p>' . esc_html('ثبت انتخاب مشتری پس از تماس/هماهنگی انجام می‌شود؛ ارسال پیام یا بازپرداخت خودکار در این مرحله فعال نیست.') . '</p>';
        }
        foreach (($plan['procurement'] ?? []) as $proposal) {
            if (!is_array($proposal)) { continue; }
            $sku=(string)($proposal['sku'] ?? '');
            $procurementState=(string)($workflowProcurement[$sku]['state'] ?? ($proposal['state'] ?? ''));
            echo '<p>' . esc_html('کسری برای ' . $sku . ': ' . (string)($proposal['shortfall_units'] ?? $proposal['shortfall'] ?? '')) . '</p>';
            if ($procurementState === 'proposed') {
                echo '<p>' . esc_html('تأمین‌کننده: ' . (string)($proposal['supplier'] ?? '') . ' · بسته‌ها: '
                    . (string)($proposal['supplier_packs'] ?? '') . ' · هزینه پیشنهادی: '
                    . (string)($proposal['purchase_cost_toman'] ?? '') . ' تومان · خرید ثبت نشده است.') . '</p>';
                if ($workflow!==null && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                    molavi_paid_order_operation_form($orderId,'record_supplier_order',[
                        'sku'=>$sku,'packs'=>(int)($proposal['supplier_packs'] ?? 0)], [
                            ['key'=>'purchase_date','label'=>'تاریخ واقعی خرید','type'=>'date','required'=>true,'value'=>current_time('Y-m-d')],
                            ['key'=>'supplier_reference','label'=>'شناسه سفارش تأمین‌کننده','type'=>'text','required'=>true,'max'=>120],
                            ['key'=>'expected_at','label'=>'تاریخ مورد انتظار','type'=>'date'],
                        ],'ثبت خرید انجام‌شده');
                    molavi_paid_order_operation_form($orderId,'mark_supplier_unavailable',['sku'=>$sku],[],
                        'ثبت ناموجودی نزد تأمین‌کننده');
                }
            } elseif ($procurementState === 'needs_supplier_terms') {
                $sourceOffer='';
                foreach (($plan['source_lines'] ?? []) as $sourceLine) {
                    if (is_array($sourceLine) && (string)($sourceLine['sku'] ?? '')===$sku) {
                        $sourceOffer=(string)($sourceLine['supplier_offer_id'] ?? ''); break;
                    }
                }
                echo '<p>' . esc_html('وضعیت: برای پیشنهاد بسته کامل، شرایط تأمین‌کننده را از ردیف ثبت‌شده سفارش وارد کنید.') . '</p>';
                if ($sourceOffer !== '') {
                    if (in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                        echo '<p>' . esc_html('پس از لغو مشتری، سفارش تأمین جدید ثبت نمی‌شود.') . '</p>';
                    } else {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    wp_nonce_field('molavi_paid_order_terms:' . $orderId . ':' . $sku);
                    echo '<input type="hidden" name="action" value="molavi_paid_order_terms">';
                    echo '<input type="hidden" name="order_id" value="' . esc_attr($orderId) . '">';
                    echo '<input type="hidden" name="sku" value="' . esc_attr($sku) . '">';
                    echo '<input type="hidden" name="source_offer_id" value="' . esc_attr($sourceOffer) . '">';
                    echo '<p><label>' . esc_html('تأمین‌کننده') . ' <input name="supplier" required maxlength="120"></label> ';
                    echo '<label>' . esc_html('تعداد در بسته') . ' <input name="units_per_pack" type="number" min="1" max="1000000" required></label> ';
                    echo '<label>' . esc_html('قیمت هر بسته (تومان)') . ' <input name="pack_price_toman" type="number" min="1" max="100000000000" required></label></p>';
                    echo '<p><label>' . esc_html('حداقل بسته‌ها') . ' <input name="minimum_packs" type="number" min="1" max="1000000" value="1" required></label> ';
                    echo '<label>' . esc_html('مضرب بسته‌ها') . ' <input name="pack_multiple" type="number" min="1" max="1000000" value="1" required></label></p>';
                    echo '<p><label>' . esc_html('یادداشت بررسی و مبنای شرایط') . ' <textarea name="note" required maxlength="1000"></textarea></label></p>';
                    echo '<button class="button button-primary">' . esc_html('محاسبه پیشنهاد؛ بدون ثبت خرید') . '</button></form>';
                    }
                } else {
                    echo '<p>' . esc_html('ردیف سفارش شناسه پیشنهاد تأمین‌کننده ندارد؛ ابتدا باید منبع کالا در کاتالوگ تطبیق داده شود.') . '</p>';
                }
                if ($workflow!==null && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                    molavi_paid_order_operation_form($orderId,'mark_supplier_unavailable',['sku'=>$sku],[],
                        'ثبت ناموجودی نزد تأمین‌کننده');
                }
            } elseif (in_array($procurementState,['ordered','partially_received'],true)) {
                $receiptOptions=[];
                foreach ($stockLots as $lot) {
                    if (is_array($lot) && (string)($lot['sku'] ?? '')===$sku && (int)($lot['available_units'] ?? 0)>0) {
                        $receiptOptions[(string)$lot['lot_id']]=(string)$lot['lot_id'] . ' · قابل تخصیص: ' . (string)$lot['available_units'];
                    }
                }
                echo '<p>' . esc_html('سفارش ثبت‌شده: ' . (string)($workflowProcurement[$sku]['supplier_reference'] ?? '')
                    . ' · دریافت‌شده: ' . (string)($workflowProcurement[$sku]['received_units'] ?? 0)
                    . ' از ' . (string)($workflowProcurement[$sku]['expected_supplier_units'] ?? 0)) . '</p>';
                if ($receiptOptions!==[] && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                    molavi_paid_order_operation_form($orderId,'mark_supplier_received',[
                        'sku'=>$sku], [
                            ['key'=>'lot_id','label'=>'بچ رسید','type'=>'select','options'=>$receiptOptions,'required'=>true],
                            ['key'=>'accepted_units','label'=>'واحدهای پذیرفته‌شده','type'=>'number','required'=>true,'min'=>1,'max'=>1000000],
                        ],'ثبت دریافت و تخصیص به سفارش');
                } elseif ($receiptOptions===[] && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                    echo '<p>' . esc_html('برای ثبت دریافت، ابتدا همان SKU را در بخش ثبت رسید موجودی وارد کنید.') . '</p>';
                }
                if ($workflow!==null && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
                    molavi_paid_order_operation_form($orderId,'mark_supplier_unavailable',['sku'=>$sku],[],
                        'ثبت ناتوانی تأمین‌کننده در ارسال باقی‌مانده');
                }
            } elseif ($procurementState === 'unavailable') {
                echo '<p>' . esc_html('تأمین‌کننده این کالا را موجود اعلام نکرده است؛ پیشنهاد جایگزین یا تصمیم مشتری را ثبت کنید.') . '</p>';
                $expected=(int)($workflowProcurement[$sku]['expected_supplier_units'] ?? 0);
                $received=(int)($workflowProcurement[$sku]['received_units'] ?? 0);
                if ($expected>$received && (string)($workflowProcurement[$sku]['supplier_reference'] ?? '')!=='') {
                    $receiptOptions=[];
                    foreach ($stockLots as $lot) {
                        if (is_array($lot) && (string)($lot['sku'] ?? '')===$sku && (int)($lot['available_units'] ?? 0)>0) {
                            $receiptOptions[(string)$lot['lot_id']]=(string)$lot['lot_id'] . ' · قابل ثبت: ' . (string)$lot['available_units'];
                        }
                    }
                    if ($receiptOptions!==[]) {
                        molavi_paid_order_operation_form($orderId,'mark_supplier_received',['sku'=>$sku],[
                            ['key'=>'lot_id','label'=>'بچ رسید','type'=>'select','options'=>$receiptOptions,'required'=>true],
                            ['key'=>'accepted_units','label'=>'واحدهای دریافت‌شده','type'=>'number','required'=>true,'min'=>1,'max'=>1000000],
                        ],'ثبت دریافت دیرهنگام برای موجودی');
                    }
                }
            }
        }
        if ($workflow!==null && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled','closed'], true)) {
            foreach ($shortages as $line) {
                $sku=(string)$line['sku'];
                $resolution=is_array($resolutions[$sku] ?? null) ? $resolutions[$sku] : [];
                $proposal=is_array($resolution['proposal'] ?? null) ? $resolution['proposal'] : [];
                $procState=(string)($workflowProcurement[$sku]['state'] ?? '');
                $resolutionState=(string)($resolution['state'] ?? '');
                if ($proposal===[] && in_array($resolutionState,['','withdrawn'],true)
                    && !in_array($procState,['ordered','partially_received','received'],true)) {
                    $candidates=is_array($plan['substitution_candidates'][$sku] ?? null) ? $plan['substitution_candidates'][$sku] : [];
                    $options=[];
                    foreach ($candidates as $candidate) {
                        if (!is_array($candidate) || !isset($candidate['sku'])) { continue; }
                        $options[(string)$candidate['sku']]=(string)($candidate['name'] ?? $candidate['sku']) . ' · SKU '
                            . (string)$candidate['sku'] . ' · موجودی: ' . (string)($candidate['available_units'] ?? 0)
                            . ' · قیمت واحد: ' . (string)($candidate['price_floor_toman'] ?? 0) . ' تا '
                            . (string)($candidate['price_ceiling_toman'] ?? 0) . ' تومان';
                    }
                    if ($options!==[]) {
                        echo '<h3>' . esc_html('جایگزین پیشنهادی برای ' . $sku . ' · تعداد ' . (string)$line['shortfall_units']) . '</h3>';
                        molavi_paid_order_operation_form($orderId,'propose_substitution',[
                            'sku'=>$sku,'replacement_units'=>(int)$line['shortfall_units']], [
                                ['key'=>'replacement_sku','label'=>'کالای جایگزین','type'=>'select','options'=>$options,'required'=>true],
                                ['key'=>'proposed_line_total_toman','label'=>'جمع قیمت پیشنهادی (تومان)','type'=>'number','required'=>true,'min'=>1,'max'=>1000000000000],
                            ],'ثبت پیشنهاد برای انتخاب مشتری');
                    }
                }
                if (($resolution['state'] ?? '')==='awaiting_customer' && $proposal!==[]) {
                    $replacementSku=(string)($proposal['replacement_sku'] ?? '');
                    $amount=(int)($proposal['proposed_line_total_toman'] ?? 0);
                    echo '<p>' . esc_html('پیشنهاد منتظر پاسخ مشتری: ' . $replacementSku . ' · جمع ' . (string)$amount . ' تومان.') . '</p>';
                    molavi_paid_order_operation_form($orderId,'record_customer_choice',[
                        'sku'=>$sku,'choice'=>'replacement','replacement_sku'=>$replacementSku,
                        'approved_line_total_toman'=>$amount], [
                            ['key'=>'customer_difference_approved','label'=>'مشتری افزایش احتمالی مبلغ را پذیرفته است','type'=>'checkbox'],
                        ],'ثبت پذیرش جایگزین توسط مشتری');
                    molavi_paid_order_operation_form($orderId,'withdraw_substitution',['sku'=>$sku],[],
                        'ثبت رد پیشنهاد جایگزین توسط مشتری');
                }
                if (!in_array($resolutionState, ['partial_refund','replacement_accepted','full_cancellation'], true)
                    && !in_array($procState,['ordered','partially_received'],true)) {
                    molavi_paid_order_operation_form($orderId,'record_customer_choice',[
                        'sku'=>$sku,'choice'=>'partial_refund'], [
                            ['key'=>'refund_amount_toman','label'=>'مبلغ بازپرداخت کسری (تومان)','type'=>'number','required'=>true,'min'=>1,
                                'max'=>(int)($plan['original_line_totals_toman'][$sku] ?? 0)],
                    ],'ثبت انتخاب بازپرداخت بخشی');
                }
            }
            $refundAlreadyRequested=false;$refundTotal=0;
            foreach (($workflow['refunds'] ?? []) as $existingRefund) {
                if (!is_array($existingRefund)) { continue; }
                $refundAlreadyRequested=$refundAlreadyRequested || (($existingRefund['status'] ?? '')==='requested');
                $refundTotal+=(int)($existingRefund['amount_toman'] ?? 0);
            }
            $remainingRefund=max(0,(int)($plan['order_total_toman'] ?? 0)-$refundTotal);
            if ($shortages!==[] && !$refundAlreadyRequested && $remainingRefund>0
                && !in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled'], true)) {
                molavi_paid_order_operation_form($orderId,'record_customer_choice',[
                    'sku'=>(string)$shortages[0]['sku'],'choice'=>'full_cancellation',
                    'refund_amount_toman'=>$remainingRefund], [],
                    'ثبت انتخاب لغو کل سفارش و بازپرداخت');
            }
            foreach (($workflow['refunds'] ?? []) as $refund) {
                if (!is_array($refund)) { continue; }
                if (($refund['status'] ?? '')==='requested') {
                    echo '<p>' . esc_html('بازپرداخت باید ابتدا در روش پرداخت/Woo ثبت شود؛ پس از آن رسید واقعی را در اینجا ثبت کنید. مبلغ: '
                        . (string)($refund['amount_toman'] ?? 0) . ' تومان.') . '</p>';
                    molavi_paid_order_operation_form($orderId,'confirm_refund',[
                        'refund_id'=>(string)($refund['refund_id'] ?? ''),
                        'amount_toman'=>(int)($refund['amount_toman'] ?? 0)], [
                            ['key'=>'transaction_reference','label'=>'شناسه واقعی بازپرداخت','type'=>'text','required'=>true,'max'=>120],
                        ],'ثبت انجام واقعی بازپرداخت');
                } elseif (($refund['status'] ?? '')==='confirmed') {
                    echo '<p>' . esc_html('بازپرداخت ثبت شد: ' . (string)($refund['amount_toman'] ?? 0) . ' تومان · '
                        . (string)($refund['transaction_reference'] ?? '')) . '</p>';
                }
            }
            foreach ($resolutions as $sku=>$resolution) {
                if (is_array($resolution) && ($resolution['settlement_status'] ?? '')==='additional_payment_required') {
                    $difference=max(0,(int)($resolution['approved_line_total_toman'] ?? 0)
                        -(int)($plan['original_line_totals_toman'][$sku] ?? 0));
                    echo '<p>' . esc_html('پس از دریافت مبلغ اضافه از مشتری، رسید واقعی آن را ثبت کنید: ' . (string)$difference . ' تومان.') . '</p>';
                    molavi_paid_order_operation_form($orderId,'confirm_additional_payment',[
                        'sku'=>(string)$sku,'amount_toman'=>$difference], [
                            ['key'=>'transaction_reference','label'=>'شناسه واقعی پرداخت','type'=>'text','required'=>true,'max'=>120],
                        ],'ثبت دریافت مبلغ اضافه');
                }
            }
            $packReady=true;
            foreach (($plan['lines'] ?? []) as $line) {
                if (!is_array($line) || (int)($line['shortfall_units'] ?? 0)<=0) { continue; }
                $lineResolution=$resolutions[(string)($line['sku'] ?? '')] ?? [];
                if (!in_array(($lineResolution['state'] ?? ''), ['replacement_accepted','partial_refund'], true)) { $packReady=false; }
            }
            foreach (($workflow['refunds'] ?? []) as $refund) {
                if (is_array($refund) && ($refund['status'] ?? '')==='requested') { $packReady=false; }
            }
            foreach ($resolutions as $resolution) {
                if (is_array($resolution) && ($resolution['settlement_status'] ?? '')==='additional_payment_required') { $packReady=false; }
            }
            if ($packReady && ($workflow['packing']['status'] ?? '')!=='packed' && ($plan['state'] ?? '')!=='released') {
                molavi_paid_order_operation_form($orderId,'mark_packed',[],[],
                    'ثبت تکمیل بسته‌بندی سفارش');
            }
            if (($workflow['packing']['status'] ?? '')==='packed' && ($workflow['dispatch']['status'] ?? '')!=='dispatched') {
                molavi_paid_order_operation_form($orderId,'mark_dispatched',[],[
                    ['key'=>'tracking_reference','label'=>'شناسه پیگیری/تحویل','type'=>'text','required'=>true,'max'=>120],
                    ['key'=>'carrier','label'=>'تحویل‌دهنده','type'=>'text','max'=>120],
                    ['key'=>'handed_over_at','label'=>'زمان تحویل به پیک','type'=>'date'],
                ],'ثبت ارسال/تحویل به پیک');
            }
            if (($workflow['dispatch']['status'] ?? '')==='dispatched' && ($workflow['delivery']['status'] ?? '')!=='delivered') {
                molavi_paid_order_operation_form($orderId,'mark_delivered',[],[
                    ['key'=>'delivered_at','label'=>'تاریخ تحویل به مشتری','type'=>'date'],
                ],'ثبت تحویل به مشتری');
            }
            if (($workflow['aftercare']['status'] ?? '')==='open') {
                echo '<p>' . esc_html('پیگیری باز: ' . (string)($workflow['aftercare']['issue'] ?? '')) . '</p>';
                molavi_paid_order_operation_form($orderId,'resolve_aftercare',[],[
                    ['key'=>'resolution','label'=>'نتیجه پیگیری','type'=>'textarea','required'=>true,'max'=>500],
                ],'ثبت رفع مورد');
            } elseif (($workflow['delivery']['status'] ?? '')==='delivered') {
                molavi_paid_order_operation_form($orderId,'open_aftercare',[],[
                    ['key'=>'issue','label'=>'مورد پیگیری پس از تحویل','type'=>'textarea','required'=>true,'max'=>500],
                    ['key'=>'next_action','label'=>'اقدام بعدی','type'=>'text','max'=>300],
                ],'ثبت مورد پیگیری');
                if (!in_array(($workflow['aftercare']['status'] ?? ''), ['open'], true)) {
                    molavi_paid_order_operation_form($orderId,'close',[],[],
                        'بستن پیگیری سفارش پس از تحویل');
                }
            }
        }
        if ($workflow!==null && in_array(($workflow['state'] ?? ''), ['cancelled_refund_pending','cancelled'], true)) {
            echo '<p>' . esc_html('ההזמנה בוטלה; אין לארוז או לרכוש עבורה. אפשר להשלים תיעוד של משלוח ספק שכבר הוזמן.') . '</p>';
            foreach (($workflow['refunds'] ?? []) as $refund) {
                if (!is_array($refund) || ($refund['status'] ?? '')!=='requested') { continue; }
                molavi_paid_order_operation_form($orderId,'confirm_refund',[
                    'refund_id'=>(string)($refund['refund_id'] ?? ''),
                    'amount_toman'=>(int)($refund['amount_toman'] ?? 0)], [
                        ['key'=>'transaction_reference','label'=>'شناسه واقعی بازپرداخت','type'=>'text','required'=>true,'max'=>120],
                    ],'ثبت انجام واقعی بازپرداخت');
            }
            foreach ($workflowProcurement as $sku=>$purchase) {
                if (!is_array($purchase) || !in_array(($purchase['state'] ?? ''), ['ordered','partially_received','unavailable'], true)
                    || (int)($purchase['expected_supplier_units'] ?? 0)<=(int)($purchase['received_units'] ?? 0)) { continue; }
                $receiptOptions=[];
                foreach ($stockLots as $lot) {
                    if (is_array($lot) && (string)($lot['sku'] ?? '')===(string)$sku && (int)($lot['available_units'] ?? 0)>0) {
                        $receiptOptions[(string)$lot['lot_id']]=(string)$lot['lot_id'] . ' · قابل ثبت: ' . (string)$lot['available_units'];
                    }
                }
                echo '<p>' . esc_html('در حال پیگیری سفارش تأمین قبلی: ' . (string)$sku . ' · '
                    . (string)($purchase['supplier_reference'] ?? '') . ' · دریافت‌شده '
                    . (string)($purchase['received_units'] ?? 0) . ' از ' . (string)($purchase['expected_supplier_units'] ?? 0)) . '</p>';
                if ($receiptOptions!==[]) {
                    molavi_paid_order_operation_form($orderId,'mark_supplier_received',['sku'=>(string)$sku],[
                        ['key'=>'lot_id','label'=>'بچ رسید','type'=>'select','options'=>$receiptOptions,'required'=>true],
                        ['key'=>'accepted_units','label'=>'واحدهای پذیرفته‌شده','type'=>'number','required'=>true,'min'=>1,'max'=>1000000],
                    ],'ثبت دریافت برای موجودی کل فروشگاه');
                }
                if (($purchase['state'] ?? '')!=='unavailable') {
                    molavi_paid_order_operation_form($orderId,'mark_supplier_unavailable',['sku'=>(string)$sku],[],
                        'ثبت عدم ارسال باقیمانده سفارش تأمین');
                }
            }
        }
        echo '</div>';
    }
    echo '</div>';
}

add_action('admin_post_molavi_receive_stock_lot', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    $keys=['lot_id','sku','supplier','purchase_date','received_at','production_date','expiry_date','units','unit_cost','note'];
    foreach ($keys as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    check_admin_referer('molavi_receive_stock_lot');
    $lotId=trim(sanitize_text_field(wp_unslash($_POST['lot_id'])));
    $sku=trim(sanitize_text_field(wp_unslash($_POST['sku'])));
    $supplier=trim(sanitize_text_field(wp_unslash($_POST['supplier'])));
    $purchase=wp_unslash($_POST['purchase_date']);$received=wp_unslash($_POST['received_at']);
    $production=wp_unslash($_POST['production_date']);$expiry=wp_unslash($_POST['expiry_date']);
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    foreach (['units','unit_cost'] as $field) {
        $raw=wp_unslash($_POST[$field]);
        if (!ctype_digit($raw) || (int)$raw<1 || (int)$raw>1000000000000) { wp_die('مقادیر موجودی نامعتبر است.', '', ['response'=>400]); }
        $$field=(int)$raw;
    }
    foreach ([$purchase,$received,$production,$expiry] as $date) {
        if ($date!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) { wp_die('تاریخ رسید نامعتبر است.', '', ['response'=>400]); }
    }
    if ($lotId==='' || strlen($lotId)>120 || $sku==='' || strlen($sku)>80 || $supplier==='' || strlen($supplier)>120
        || $note==='' || mb_strlen($note)>1000) { wp_die('شناسه، SKU، تأمین‌کننده و یادداشت لازم است.', '', ['response'=>400]); }
    $payload=['lot_id'=>$lotId,'sku'=>$sku,'supplier'=>$supplier,'purchase_date'=>$purchase,'received_at'=>$received,
        'production_date'=>$production,'expiry_date'=>$expiry,'units'=>$units,'unit_cost'=>$unit_cost,'note'=>$note];
    $saved=molavi_stock_lots_request('POST',$payload);
    if (is_wp_error($saved)) { wp_die(esc_html($saved->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-paid-orders&stock_saved=1'));
    exit;
});

add_action('admin_post_molavi_paid_order_terms', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['order_id','sku','supplier','source_offer_id','units_per_pack','pack_price_toman','minimum_packs','pack_multiple','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $orderId=trim(sanitize_text_field(wp_unslash($_POST['order_id'])));
    $sku=trim(sanitize_text_field(wp_unslash($_POST['sku'])));
    $supplier=trim(sanitize_text_field(wp_unslash($_POST['supplier'])));
    $offerId=trim(sanitize_text_field(wp_unslash($_POST['source_offer_id'])));
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    check_admin_referer('molavi_paid_order_terms:' . $orderId . ':' . $sku);
    $numbers=[];
    foreach (['units_per_pack','pack_price_toman','minimum_packs','pack_multiple'] as $field) {
        $raw=wp_unslash($_POST[$field]);
        if (!ctype_digit($raw) || (int)$raw<1 || (int)$raw>100000000000) { wp_die('مقادیر بسته نامعتبر است.', '', ['response'=>400]); }
        $numbers[$field]=(int)$raw;
    }
    if ($orderId==='' || strlen($orderId)>40 || $sku==='' || strlen($sku)>80 || $supplier==='' || strlen($supplier)>120
        || $offerId==='' || strlen($offerId)>120 || $note==='' || mb_strlen($note)>1000) {
        wp_die('اطلاعات تأمین‌کننده و یادداشت لازم است.', '', ['response'=>400]);
    }
    $terms=[$sku=>array_merge(['supplier'=>$supplier,'source_offer_id'=>$offerId],$numbers)];
    $saved=molavi_paid_orders_request('POST',['order_id'=>$orderId,'supplier_terms'=>$terms,'note'=>$note]);
    if (is_wp_error($saved)) { wp_die(esc_html($saved->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-paid-orders&saved=1'));
    exit;
});

add_action('admin_post_molavi_paid_order_operation', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['order_id','operation','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $orderId=trim(sanitize_text_field(wp_unslash($_POST['order_id'])));
    $operation=sanitize_key(wp_unslash($_POST['operation']));
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    check_admin_referer('molavi_paid_order_operation:' . $orderId . ':' . $operation);
    $schemas=[
        'assign'=>['assigned_to'=>['text',false,120], 'next_action'=>['text',false,300], 'due_at'=>['date',false,40]],
        'record_supplier_order'=>['sku'=>['text',true,80], 'packs'=>['number',true,16],
            'purchase_date'=>['date',true,40],
            'supplier_reference'=>['text',true,120], 'expected_at'=>['date',false,40]],
        'mark_supplier_unavailable'=>['sku'=>['text',true,80]],
        'mark_supplier_received'=>['sku'=>['text',true,80], 'lot_id'=>['text',true,120],
            'accepted_units'=>['number',true,16]],
        'propose_substitution'=>['sku'=>['text',true,80], 'replacement_sku'=>['text',true,80],
            'replacement_units'=>['number',true,16], 'proposed_line_total_toman'=>['number',true,24]],
        'withdraw_substitution'=>['sku'=>['text',true,80]],
        'record_customer_choice'=>['sku'=>['text',true,80],
            'choice'=>['choice',true,32], 'replacement_sku'=>['text',false,80],
            'approved_line_total_toman'=>['number',false,24], 'refund_amount_toman'=>['number',false,24],
            'customer_difference_approved'=>['boolean',false,1]],
        'confirm_additional_payment'=>['sku'=>['text',true,80], 'amount_toman'=>['number',true,24],
            'transaction_reference'=>['text',true,120]],
        'confirm_refund'=>['refund_id'=>['text',true,64], 'amount_toman'=>['number',true,24],
            'transaction_reference'=>['text',true,120]],
        'mark_packed'=>[],
        'mark_dispatched'=>['tracking_reference'=>['text',true,120], 'carrier'=>['text',false,120],
            'handed_over_at'=>['date',false,40]],
        'mark_delivered'=>['delivered_at'=>['date',false,40]],
        'open_aftercare'=>['issue'=>['text',true,500], 'next_action'=>['text',false,300]],
        'resolve_aftercare'=>['resolution'=>['text',true,500]],
        'close'=>[],
    ];
    if ($orderId==='' || strlen($orderId)>40 || $note==='' || mb_strlen($note)>1000
        || !isset($schemas[$operation]) || (isset($_POST['details']) && !is_array($_POST['details']))) {
        wp_die('اطلاعات عملیات نامعتبر است.', '', ['response'=>400]);
    }
    $raw=isset($_POST['details']) ? wp_unslash($_POST['details']) : [];$schema=$schemas[$operation];
    if (array_diff(array_keys($raw),array_keys($schema))) { wp_die('جزئیات عملیات نامعتبر است.', '', ['response'=>400]); }
    $details=[];
    foreach ($schema as $key=>$rule) {
        [$type,$required,$max]=$rule;
        if (!array_key_exists($key,$raw)) {
            if ($type==='boolean') { $details[$key]=false; continue; }
            if ($required) { wp_die('اطلاعات عملیات ناقص است.', '', ['response'=>400]); }
            continue;
        }
        if (!is_string($raw[$key])) { wp_die('جزئیات عملیات نامعتبر است.', '', ['response'=>400]); }
        $value=trim($raw[$key]);
        if ($type==='number') {
            if (!ctype_digit($value) || (float)$value<1 || strlen($value)>$max) { wp_die('عدد عملیات نامعتبر است.', '', ['response'=>400]); }
            $details[$key]=(int)$value;
        } elseif ($type==='date') {
            if ($value!=='' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$value,$dateParts)
                || !checkdate((int)$dateParts[2],(int)$dateParts[3],(int)$dateParts[1]))) { wp_die('تاریخ عملیات نامعتبر است.', '', ['response'=>400]); }
            $details[$key]=$value;
        } elseif ($type==='boolean') {
            $details[$key]=($value==='1');
        } elseif ($type==='choice') {
            if (!in_array($value,['replacement','partial_refund','full_cancellation'],true)) { wp_die('انتخاب مشتری نامعتبر است.', '', ['response'=>400]); }
            $details[$key]=$value;
        } else {
            $value=sanitize_text_field($value);
            if (($required && $value==='') || mb_strlen($value)>$max) { wp_die('متن عملیات نامعتبر است.', '', ['response'=>400]); }
            $details[$key]=$value;
        }
    }
    $saved=molavi_paid_orders_request('POST',['order_id'=>$orderId,'action'=>$operation,
        'details'=>$details,'note'=>$note]);
    if (is_wp_error($saved)) { wp_die(esc_html($saved->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-paid-orders&operation_saved=1'));
    exit;
});

function molavi_sheet_conflicts_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('تعارض تغییرات شیت و سرور') . '</h1>';
    echo '<p>' . esc_html('هر دو مقدار نگهداری می‌شوند. پس از بررسی، نسخه شیت یا نسخه سرور را برای این فیلد انتخاب کنید.') . '</p>';
    molavi_owner_workflow_mount('در حال دریافت تعارض‌های باز…');
    echo '</div>';
}

add_action('admin_post_molavi_resolve_sheet_conflict', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['conflict_id','choice','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $id=wp_unslash($_POST['conflict_id']); $choice=wp_unslash($_POST['choice']);
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    check_admin_referer('molavi_resolve_sheet_conflict:' . $id . ':' . $choice);
    if (!in_array($choice,['server','sheet'],true) || $note==='' || mb_strlen($note)>1000) { wp_die('یادداشت و انتخاب لازم است.', '', ['response'=>400]); }
    $result=molavi_sheet_conflicts_request('POST',['conflict_id'=>$id,'choice'=>$choice,'note'=>$note]);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-sheet-conflicts'));
    exit;
});

function molavi_supplier_prices_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('تازگی قیمت تأمین‌کنندگان') . '</h1>';
    echo '<p>' . esc_html('قیمت‌های بیش از هفت روز نیاز به بررسی دارند؛ از روز چهاردهم، سفارش تازه متوقف می‌ماند تا منبع قیمت بررسی شود.') . '</p>';
    molavi_owner_workflow_mount('در حال دریافت قیمت‌های نیازمند بررسی…');
    echo '</div>';
}
add_action('admin_post_molavi_verify_supplier_price', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['product_id','source_revision','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $id=wp_unslash($_POST['product_id']); $revision=wp_unslash($_POST['source_revision']);
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    check_admin_referer('molavi_verify_supplier_price:' . $id . ':' . $revision);
    if ($id==='' || $revision==='' || $note==='' || mb_strlen($note)>1000) { wp_die('یادداشت بررسی لازم است.', '', ['response'=>400]); }
    $result=molavi_supplier_prices_request('POST',['product_id'=>$id,'expected_revision'=>$revision,'note'=>$note]);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-supplier-prices'));
    exit;
});

function molavi_publication_holds_screen(): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    nocache_headers();
    echo '<div class="wrap"><h1>' . esc_html('بررسی توقف انتشار') . '</h1>';
    echo '<p>' . esc_html('پیش از رفع توقف، ورودی‌ها و تغییرات این دسته را بررسی و تطبیق دهید. ثبت بررسی، همین دسته و انتشارهای منتظر را دوباره به صف می‌فرستد.') . '</p>';
    molavi_owner_workflow_mount('در حال دریافت توقف‌های انتشار…');
    echo '</div>';
}
add_action('admin_post_molavi_resolve_publication_hold', static function (): void {
    if (!molavi_reference_owner()) { wp_die('دسترسی مجاز نیست.', '', ['response'=>403]); }
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { wp_die('درخواست نامعتبر است.', '', ['response'=>405]); }
    foreach (['hold_id','expected_reason','note'] as $key) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) { wp_die('درخواست نامعتبر است.', '', ['response'=>400]); }
    }
    $id=wp_unslash($_POST['hold_id']);
    $reason=wp_unslash($_POST['expected_reason']);
    $note=trim(sanitize_textarea_field(wp_unslash($_POST['note'])));
    check_admin_referer('molavi_resolve_publication_hold:' . $id);
    if ($note==='' || mb_strlen($note)>1000) { wp_die('شرح بررسی لازم است.', '', ['response'=>400]); }
    $result=molavi_pipeline_holds_request('POST',['hold_id'=>$id,'expected_reason'=>$reason,'note'=>$note,'resume_batch'=>true]);
    if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message()), '', ['response'=>503]); }
    wp_safe_redirect(admin_url('admin.php?page=molavi-publication-holds'));
    exit;
});
