<?php
/** Customer journey on native WooCommerce forms and the existing price engine. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_is_customer_request(): bool {
    return !is_admin() && !wp_doing_ajax() && (
        molavi_is_storefront_request()
        || (function_exists('is_product') && is_product())
        || (function_exists('is_product_taxonomy') && is_product_taxonomy())
        || (function_exists('is_cart') && is_cart())
        || (function_exists('is_checkout') && is_checkout())
        || (function_exists('is_account_page') && is_account_page()));
}

add_filter('template_include', static function (string $template): string {
    return molavi_is_customer_request() && !molavi_is_storefront_request()
        ? dirname(__DIR__) . '/customer-template.php' : $template;
}, 100);

add_action('wp_enqueue_scripts', static function (): void {
    if (!molavi_is_customer_request()) { return; }
    wp_enqueue_style('molavi-customer', plugins_url('../customer.css', __FILE__), ['molavi-storefront'],
        (string) filemtime(dirname(__DIR__) . '/customer.css'));
});

/** Read the configured native Tehran method; use approved launch values as an estimate before address entry. */
function molavi_delivery_preview(int $goods): array {
    $policy = ['fee'=>250000,'threshold'=>5000000,'configured'=>false,'scope'=>'تهران'];
    if (class_exists('WC_Shipping_Zones')) {
        $choices = [];
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            foreach (($zone['shipping_methods'] ?? []) as $method) {
                if (($method->id ?? '') !== 'molavi_tehran_delivery' || ($method->enabled ?? '') !== 'yes') { continue; }
                $fee = (string) $method->get_option('fee_toman', '250000');
                $threshold = (string) $method->get_option('threshold_toman', '5000000');
                if (ctype_digit($fee) && ctype_digit($threshold) && (int) $threshold > 0) {
                    $choices[$fee . ':' . $threshold] = ['fee'=>(int)$fee,'threshold'=>(int)$threshold,'configured'=>true,'scope'=>'تهران'];
                }
            }
        }
        if (count($choices) === 1) { $policy = reset($choices); }
    }
    $remaining = max(0, $policy['threshold'] - $goods);
    return $policy + ['remaining'=>$remaining,'qualifies'=>$remaining===0,
        'estimate'=>$remaining===0?0:$policy['fee'],
        'basis'=>'جمع کالاها پس از تخفیف؛ مبلغ قطعی ارسال با نشانی در تسویه‌حساب مشخص می‌شود.'];
}

function molavi_render_delivery_preview(array $quote): void {
    $delivery = $quote['delivery'] ?? molavi_delivery_preview((int)($quote['subtotal'] ?? 0));
    echo '<section class="molavi-delivery-preview" aria-label="ارسال تهران">';
    echo '<div><strong>ارسال تهران</strong><span data-molavi-delivery-estimate>'
        . esc_html($delivery['qualifies'] ? 'به حد ارسال رایگان رسیدید' : 'برآورد ارسال: ' . molavi_toman((int)$delivery['estimate'])) . '</span></div>';
    echo '<p data-molavi-delivery-remaining>' . esc_html($delivery['qualifies'] ? 'ارسال رایگان برای نشانی‌های مشمول تهران'
        : molavi_toman((int)$delivery['remaining']) . ' تا ارسال رایگان') . '</p>';
    echo '<small>' . esc_html((string)$delivery['basis']) . '</small></section>';
}

// Guest access and optional email are the agreed buying policy. Gateway-specific required fields remain explicit.
add_filter('pre_option_woocommerce_enable_guest_checkout', static function () { return 'yes'; });
add_filter('woocommerce_checkout_registration_required', '__return_false');
add_filter('woocommerce_checkout_fields', static function (array $fields): array {
    if (isset($fields['billing']['billing_email'])) { $fields['billing']['billing_email']['required'] = false; }
    if (isset($fields['billing']['billing_phone'])) {
        $fields['billing']['billing_phone']['required'] = true;
        $fields['billing']['billing_phone']['label'] = 'شماره همراه';
        $fields['billing']['billing_phone']['autocomplete'] = 'tel';
    }
    foreach (['billing_company','billing_address_2'] as $optional) {
        if (isset($fields['billing'][$optional])) { $fields['billing'][$optional]['required'] = false; }
    }
    return $fields;
}, 30);

add_action('woocommerce_before_checkout_form', static function (): void {
    echo '<div class="molavi-checkout-intro"><h1>تکمیل سفارش</h1>';
    echo '<p>نام، شماره همراه و نشانی تحویل را وارد کنید. ساخت حساب و ایمیل اختیاری است.</p>';
    echo '<p>سفارش پس از تأیید پرداخت برای آماده‌سازی و تأمین پیگیری می‌شود.</p></div>';
}, 5);

add_action('woocommerce_single_product_summary', static function (): void {
    global $product;
    if (!$product instanceof WC_Product) { return; }
    molavi_render_buyer_profile_selector('product');
    $step = molavi_product_min($product);
    $quote = molavi_price_for_product($product);
    echo '<div class="molavi-purchase-explanation" data-molavi-product-detail data-product="' . esc_attr((string)$product->get_id()) . '">';
    echo '<p data-molavi-detail-quantity>حداقل خرید و گام تغییر تعداد: ' . esc_html(molavi_digits((string)$step)) . ' عدد</p>';
    echo '<p><span data-molavi-detail-total-label>مبلغ ' . esc_html(molavi_digits((string)$step)) . ' عدد با قیمت فعلی: </span><strong data-molavi-detail-total>'
        . esc_html(molavi_toman((int)$quote['price'] * $step)) . '</strong></p>';
    echo '<small>قیمت فعلی شامل تخفیف است؛ با تغییر سبد، قیمت کالاهای مشمول دوباره محاسبه می‌شود.</small></div>';
}, 25);
add_filter('woocommerce_product_single_add_to_cart_text', static function (string $text, $product): string {
    return $product instanceof WC_Product && $product->is_type('simple')
        ? 'افزودن به سبد' : $text;
}, 20, 2);

add_action('woocommerce_before_cart', static function (): void {
    echo '<h1 class="molavi-customer-title">سبد خرید</h1>';
    echo '<p class="molavi-customer-note">قیمت‌ها شامل تخفیف فعلی هستند. هزینه ارسال با نشانی شما در تسویه‌حساب محاسبه می‌شود.</p>';
}, 1);
add_action('woocommerce_cart_totals_before_shipping', static function (): void {
    echo '<tr><td colspan="2">'; molavi_render_delivery_preview(molavi_quote()); echo '</td></tr>';
});
add_action('woocommerce_after_cart_table', static function (): void {
    echo '<a class="molavi-continue-shopping" href="' . esc_url(wc_get_page_permalink('shop')) . '">ادامهٔ خرید</a>';
});
add_action('woocommerce_thankyou', static function ($orderId): void {
    // WooCommerce's order-received template has already validated the order key and owner.
    $order = wc_get_order((int)$orderId);
    if (!$order) { return; }
    echo '<section class="molavi-order-next"><h2>پیگیری سفارش</h2><p>'
        . esc_html($order->is_paid() ? 'پرداخت ثبت شده است. سفارش برای آماده‌سازی و تأمین پیگیری می‌شود.'
            : 'پرداخت این سفارش هنوز تأیید نشده است. وضعیت پرداخت را از همین صفحه پیگیری کنید.') . '</p>';
    echo '<p>این پیوند را برای مشاهدهٔ وضعیت سفارش نگه دارید. جزئیات شخصی سفارش را با دیگران به اشتراک نگذارید.</p></section>';
}, 25);

add_filter('gettext_woocommerce', static function (string $translation, string $text): string {
    $labels = [
        'Your cart is currently empty.'=>'سبد خرید شما خالی است.', 'Return to shop'=>'بازگشت به فروشگاه',
        'Add to cart'=>'افزودن به سبد','Sale!'=>'تخفیف','Description'=>'توضیحات','Additional information'=>'مشخصات',
        'Related products'=>'کالاهای مرتبط','Reviews (%d)'=>'دیدگاه‌ها (%d)','Reviews'=>'دیدگاه‌ها',
        'There are no reviews yet.'=>'هنوز دیدگاهی ثبت نشده است.', 'Be the first to review &ldquo;%s&rdquo;'=>'نخستین دیدگاه را دربارهٔ «%s» بنویسید.',
        'Login'=>'ورود','Dashboard'=>'حساب من','Orders'=>'سفارش‌های من','Addresses'=>'نشانی‌ها',
        'Account details'=>'اطلاعات حساب','Logout'=>'خروج','Order again'=>'خرید دوباره',
        'Pending payment'=>'در انتظار پرداخت','Processing'=>'در حال آماده‌سازی','On hold'=>'در انتظار بررسی',
        'Completed'=>'تکمیل‌شده','Cancelled'=>'لغوشده','Refunded'=>'بازپرداخت‌شده','Failed'=>'ناموفق',
        'Order'=>'سفارش','Date'=>'تاریخ','Status'=>'وضعیت','Actions'=>'پیگیری',
        'Billing address'=>'نشانی سفارش','Shipping address'=>'نشانی تحویل',
        'View'=>'مشاهده','Pay'=>'پرداخت','Cancel'=>'لغو','Save changes'=>'ذخیرهٔ تغییرات',
        'Order received'=>'سفارش ثبت شد','Order details'=>'جزئیات سفارش','Payment method:'=>'روش پرداخت:',
        'Email address'=>'ایمیل (اختیاری)','Phone'=>'شماره همراه','Shipping'=>'ارسال','Coupon code'=>'کد تخفیف',
        'Apply coupon'=>'اعمال کد','First name'=>'نام','Last name'=>'نام خانوادگی',
        'Street address'=>'نشانی','Town / City'=>'شهر','Postcode / ZIP'=>'کد پستی','Country / Region'=>'کشور',
        'State / County'=>'استان','Shipping details'=>'نشانی تحویل','Order notes'=>'توضیحات سفارش',
        'Your order'=>'سفارش شما','Your rating'=>'امتیاز شما','Your review'=>'دیدگاه شما','Name'=>'نام','Submit'=>'ثبت',
        'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.'=>'درگاه پرداختی برای این سفارش در دسترس نیست. پرداخت انجام نشده است؛ برای پیگیری از راه تماس فروشگاه استفاده کنید.',
    ];
    return $labels[$text] ?? $translation;
}, 30, 2);

function molavi_restore_shopping_list(): void {
    check_ajax_referer('molavi-qty','nonce');
    $cart = WC()->cart;
    if (!$cart instanceof WC_Cart) { wp_send_json_error(['message'=>'سبد در دسترس نیست.'],503); }
    $raw = isset($_POST['items']) && is_string($_POST['items']) ? wp_unslash($_POST['items']) : '';
    $items = strlen($raw)<=12000 ? json_decode($raw,true) : null;
    if (!is_array($items) || !$items || count($items)>40) { wp_send_json_error(['message'=>'فهرست خرید معتبر نیست؛ حداکثر ۴۰ کالا.'],400); }
    $requestId=isset($_POST['request_id'])&&is_string($_POST['request_id'])?$_POST['request_id']:'';
    if(!preg_match('/^[a-f0-9]{32}$/',$requestId)){wp_send_json_error(['message'=>'شناسهٔ درخواست فهرست معتبر نیست.'],400);}
    $fingerprint=hash('sha256',json_encode($items));
    $receipts=WC()->session->get('molavi_restored_lists',[]);
    if(!is_array($receipts))$receipts=[];
    if(isset($receipts[$requestId])){
        $receipt=$receipts[$requestId];
        if(($receipt['fingerprint']??'')!==$fingerprint){wp_send_json_error(['message'=>'این درخواست با فهرست دیگری ثبت شده است.'],409);}
        $quote=molavi_quote();
        wp_send_json_success(['quote'=>$quote,'catalog'=>\Molavi\Elementor\molavi_catalog_public_rows(array_keys($quote['qtys']))]+$receipt);
    }
    $skipped=[]; $adjusted=[]; $added=0;
    foreach ($items as $id=>$requested) {
        if (!ctype_digit((string)$id) || !is_int($requested) || $requested<1 || $requested>999) {
            wp_send_json_error(['message'=>'تعدادهای فهرست خرید معتبر نیست.'],400);
        }
    }
    foreach ($items as $id=>$requested) {
        $product = wc_get_product((int)$id);
        if (!$product instanceof WC_Product || !$product->is_type('simple')
            || !$product->is_purchasable() || !$product->is_in_stock()) {
            $skipped[]=(int)$id; continue;
        }
        $existing=molavi_cart_qty((int)$id);
        $target=molavi_normalize_order_quantity($existing+$requested,molavi_product_min($product));
        if ($target<=$existing) { $skipped[]=(int)$id; continue; }
        if (molavi_set_cart_qty((int)$id,$target)) {
            $added++;
            if ($target!==$existing+$requested) { $adjusted[]=(int)$id; }
        } else { $skipped[]=(int)$id; }
    }
    $cart->calculate_totals();
    $receipts[$requestId]=['fingerprint'=>$fingerprint,'added'=>$added,'skipped'=>$skipped,'adjusted'=>$adjusted];
    WC()->session->set('molavi_restored_lists',array_slice($receipts,-10,null,true));
    $quote=molavi_quote();
    wp_send_json_success(['quote'=>$quote,'catalog'=>\Molavi\Elementor\molavi_catalog_public_rows(array_keys($quote['qtys'])),'added'=>$added,'skipped'=>$skipped,'adjusted'=>$adjusted]);
}
add_action('wc_ajax_molavi_restore_list','molavi_restore_shopping_list');
add_action('wc_ajax_nopriv_molavi_restore_list','molavi_restore_shopping_list');

function molavi_render_shopping_help(): void {
    $delivery=molavi_delivery_preview(0);
    echo '<dialog id="molavi-shopping-help" class="molavi-info-dialog"><h2>روش خرید</h2>';
    echo '<p>برای خرید خانه «خانوار» و برای خرید مغازه «مغازه‌دار» را انتخاب کنید. قیمت و حداقل تعداد، متناسب با این انتخاب نمایش داده می‌شود.</p>';
    echo '<p>قیمت فعلی شامل تخفیف است. سقف تخفیف هر کالا جداست و با افزایش ارزش کالاهای مشمول در سبد فعال می‌شود. کم‌کردن سبد می‌تواند قیمت فعلی کالاهای مشمول را تغییر دهد.</p>';
    echo '<h3>ارسال تهران</h3><p>بر مبنای شرایط فعلی، ارسال برای جمع کالای پس از تخفیف از '
        . esc_html(molavi_toman((int)$delivery['threshold'])) . ' رایگان است؛ کمتر از آن '
        . esc_html(molavi_toman((int)$delivery['fee'])) . ' برآورد می‌شود. نشانی مشمول و هزینهٔ قطعی در تسویه‌حساب مشخص می‌شود.</p>';
    echo '<p>خرید به‌صورت مهمان امکان‌پذیر است. نام، شماره همراه و نشانی تحویل لازم است؛ حساب و ایمیل اختیاری‌اند. پس از تأیید پرداخت، آماده‌سازی و تأمین سفارش پیگیری می‌شود.</p>';
    echo '<form method="dialog"><button class="button" type="submit">متوجه شدم</button></form></dialog>';
}
add_action('wp_footer',static function (): void { if(molavi_is_customer_request()) molavi_render_shopping_help(); },15);
