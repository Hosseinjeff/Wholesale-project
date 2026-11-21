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
// Keep shipping rates and address-based delivery decisions at checkout.
add_filter('woocommerce_cart_ready_to_calc_shipping', static function ($ready): bool {
    return is_cart() ? false : (bool) $ready;
});
add_action('wp', static function (): void {
    if (is_cart()) { remove_action('woocommerce_cart_is_empty','wc_empty_cart_message',10); }
});
add_action('woocommerce_cart_is_empty', static function (): void {
    echo '<section class="molavi-cart-empty"><span class="molavi-empty-cart-mark" aria-hidden="true">';
    echo '<svg viewBox="0 0 24 24"><path d="M4 5h3l2 11h10l2-9H8"></path><circle cx="10" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle></svg></span>';
    echo '<h1>سبد خرید شما خالی است</h1><p>از فروشگاه شروع کنید یا سراغ انتخاب‌های ذخیره‌شدهٔ خود بروید.</p><div>';
    echo '<a class="button" href="' . esc_url(wc_get_page_permalink('shop')) . '">دیدن فروشگاه</a>';
    echo '<button type="button" data-molavi-open-library aria-haspopup="dialog" aria-controls="molavi-favorites-dialog">علاقه‌مندی‌ها و سبدهای ذخیره‌شده</button></div></section>';
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
    $mode=isset($_POST['mode'])&&is_string($_POST['mode'])?$_POST['mode']:'merge';
    if(!in_array($mode,['merge','replace'],true)){wp_send_json_error(['message'=>'روش افزودن فهرست معتبر نیست.'],400);}
    $fingerprint=hash('sha256',json_encode([$mode,$items]));
    $receipts=WC()->session->get('molavi_restored_lists',[]);
    if(!is_array($receipts))$receipts=[];
    if(isset($receipts[$requestId])){
        $receipt=$receipts[$requestId];
        if(($receipt['fingerprint']??'')!==$fingerprint){wp_send_json_error(['message'=>'این درخواست با فهرست دیگری ثبت شده است.'],409);}
        $quote=molavi_quote();
        wp_send_json_success(['quote'=>$quote,'catalog'=>\Molavi\Elementor\molavi_catalog_public_rows(array_keys($quote['qtys']))]+$receipt);
    }
    $skipped=[]; $adjusted=[]; $added=0;
    $planned=[];
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
        $existing=$mode==='replace'?0:molavi_cart_qty((int)$id);
        $target=molavi_normalize_order_quantity($existing+$requested,molavi_product_min($product));
        if ($target<=$existing) { $skipped[]=(int)$id; continue; }
        $planned[(int)$id]=$target;
        if ($target!==$existing+$requested) { $adjusted[]=(int)$id; }
    }
    if($mode==='replace'&&!$planned){wp_send_json_error(['message'=>'هیچ‌کدام از علاقه‌مندی‌ها اکنون قابل خرید نیست؛ سبد قبلی حفظ شد.'],400);}
    $original=$cart->get_cart();
    $failed=false;
    try {
        foreach($planned as $id=>$target){
            if(molavi_set_cart_qty($id,$target)){$added++;}
            elseif($mode==='replace'){$failed=true;break;}
            else{$skipped[]=$id;}
        }
        if($mode==='replace'&&!$failed){
            foreach($cart->get_cart() as $key=>$line){
                if(!isset($planned[(int)$line['product_id']])||!empty($line['variation_id'])){$cart->remove_cart_item($key);}
            }
        }
    } catch (\Throwable $error) { $failed=true; }
    if($failed){
        $cart->set_cart_contents($original);
        $cart->calculate_totals();
        wp_send_json_error(['message'=>'سبد جدید ساخته نشد؛ سبد قبلی حفظ شد. دوباره امتحان کنید.'],409);
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
    echo '<p>قیمت فعلی شامل تخفیف است. خرید یک یا چند بستهٔ کامل، سقف تخفیف همان کالا را فعال می‌کند. ارزش این کالاها جدا از پیشرفت تخفیف سایر کالاهای سبد محاسبه می‌شود.</p>';
    echo '<h3>ارسال تهران</h3><p>بر مبنای شرایط فعلی، ارسال برای جمع کالای پس از تخفیف از '
        . esc_html(molavi_toman((int)$delivery['threshold'])) . ' رایگان است؛ کمتر از آن '
        . esc_html(molavi_toman((int)$delivery['fee'])) . ' برآورد می‌شود. نشانی مشمول و هزینهٔ قطعی در تسویه‌حساب مشخص می‌شود.</p>';
    echo '<p>خرید به‌صورت مهمان امکان‌پذیر است. نام، شماره همراه و نشانی تحویل لازم است؛ حساب و ایمیل اختیاری‌اند. پس از تأیید پرداخت، آماده‌سازی و تأمین سفارش پیگیری می‌شود.</p>';
    echo '<form method="dialog"><button class="button" type="submit">متوجه شدم</button></form></dialog>';
}
add_action('wp_footer',static function (): void {
    if(!molavi_is_customer_request())return;
    if(!molavi_is_storefront_request()&&function_exists('Molavi\\Elementor\\molavi_render_floating_cart'))\Molavi\Elementor\molavi_render_floating_cart(molavi_quote(),true);
    molavi_render_shopping_help();
    ?>
    <dialog id="molavi-full-pack-help" class="molavi-library-prompt" aria-labelledby="molavi-full-pack-title">
        <h2 id="molavi-full-pack-title">چرا این کالا حداکثر تخفیف دارد؟</h2>
        <p data-molavi-full-pack-copy></p>
        <p>تخفیف این کالا در صرفه‌جویی کل سبد لحاظ می‌شود. ارزش آن در نوار پیشرفت تخفیف سایر کالاها حساب نمی‌شود.</p>
        <form method="dialog" class="molavi-prompt-actions"><button type="submit" autofocus>متوجه شدم</button></form>
    </dialog>
    <?php
    \Molavi\Elementor\molavi_render_favorites_library();
},15);

/** Account-owned favorites and named lists; active WooCommerce carts remain separate. */
function molavi_library_empty(): array {
    return ['revision'=>0, 'favorites'=>[], 'carts'=>[], 'receipts'=>[]];
}
function molavi_library_public(array $state): array {
    return ['revision'=>(int)$state['revision'], 'favorites'=>array_values($state['favorites']), 'carts'=>array_values($state['carts'])];
}
function molavi_library_ids($ids) {
    if (!is_array($ids) || count($ids)>1000) return new WP_Error('invalid', 'فهرست علاقه‌مندی‌ها معتبر نیست.');
    $out=[];
    foreach ($ids as $id) {
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,17}$/', (string)$id)) return new WP_Error('invalid', 'شناسهٔ کالا معتبر نیست.');
        $out[(string)$id]=(string)$id;
    }
    return array_values($out);
}
function molavi_library_cart($cart) {
    if (!is_array($cart) || !is_string($cart['id']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$cart['id'])
        || !is_string($cart['name']??null) || !is_array($cart['items']??null)) return new WP_Error('invalid','سبد ذخیره‌شده معتبر نیست.');
    $name=trim(sanitize_text_field($cart['name']));
    if ($name==='' || mb_strlen($name)>60 || !$cart['items'] || count($cart['items'])>40) return new WP_Error('invalid','نام یا تعداد کالاهای سبد معتبر نیست؛ هر سبد حداکثر ۴۰ کالا دارد.');
    $items=[];
    foreach ($cart['items'] as $id=>$qty) {
        if (!preg_match('/^[1-9][0-9]{0,17}$/',(string)$id) || !is_int($qty) || $qty<1 || $qty>999) return new WP_Error('invalid','تعداد کالاهای سبد معتبر نیست.');
        $items[(string)$id]=$qty;
    }
    $origin=molavi_library_ids($cart['favorite_ids']??[]);if(is_wp_error($origin))return $origin;
    return ['id'=>$cart['id'],'name'=>$name,'items'=>$items,'favorite_ids'=>$origin];
}
function molavi_library_apply(array $state, array $ops) {
    foreach ($ops as $op) {
        if (!is_array($op)) return new WP_Error('invalid','درخواست معتبر نیست.');
        $type=$op['type']??'';
        if ($type==='favorites_add' || $type==='favorites_remove') {
            $ids=molavi_library_ids($op['ids']??null);if(is_wp_error($ids))return $ids;
            $state['favorites']=$type==='favorites_add'
                ? array_values(array_unique(array_merge($state['favorites'],$ids)))
                : array_values(array_diff($state['favorites'],$ids));
            if(count($state['favorites'])>1000)return new WP_Error('limit','حداکثر ۱۰۰۰ علاقه‌مندی می‌توانید ذخیره کنید.');
        } elseif ($type==='cart_save') {
            $cart=molavi_library_cart($op['cart']??null);if(is_wp_error($cart))return $cart;
            $found=false;
            foreach($state['carts'] as $existing){if($existing['id']===$cart['id']){$found=true;break;}}
            if(!$found){
                if(count($state['carts'])>=10)return new WP_Error('limit','۱۰ سبد ذخیره دارید؛ پیش از ذخیرهٔ سبد جدید، یکی را حذف کنید. هیچ سبدی خودکار حذف نمی‌شود.');
                array_unshift($state['carts'],$cart);
            }
        } elseif ($type==='cart_edit' || $type==='cart_merge_favorites') {
            $id=$op['id']??null;
            if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$id))return new WP_Error('invalid','شناسهٔ سبد معتبر نیست.');
            $index=null;
            foreach($state['carts'] as $i=>$cart){if($cart['id']===$id){$index=$i;break;}}
            if($index===null)return new WP_Error('conflict','این سبد دیگر در حساب نیست. فهرست را تازه کنید.');
            $cart=$state['carts'][$index];
            if($type==='cart_merge_favorites'){
                $additions=$op['items']??null;
                $validated=molavi_library_cart(['id'=>$id,'name'=>$cart['name'],'items'=>$additions]);
                if(is_wp_error($validated))return $validated;
                foreach($validated['items'] as $product=>$qty){if(!isset($cart['items'][$product]))$cart['items'][$product]=$qty;}
                if(count($cart['items'])>40)return new WP_Error('limit','سبد مقصد نمی‌تواند بیش از ۴۰ کالا داشته باشد؛ علاقه‌مندی‌ها حفظ شدند.');
                $cart['favorite_ids']=array_values(array_unique(array_merge($cart['favorite_ids']??[],array_map('strval',array_keys($validated['items'])))));
            }else{
                $changes=$op['changes']??null;$expected=$op['expected']??null;
                if(!is_array($changes)||!$changes||count($changes)>40||!is_array($expected))return new WP_Error('invalid','تغییر تعدادها معتبر نیست.');
                foreach($changes as $product=>$qty){
                    if(!preg_match('/^[1-9][0-9]{0,17}$/',(string)$product)||!is_int($qty)||$qty<0||$qty>999
                        ||!isset($expected[$product])||!is_int($expected[$product])||$expected[$product]<0||$expected[$product]>999)return new WP_Error('invalid','تعداد باید یک عدد صحیح بین صفر و ۹۹۹ باشد.');
                    if((int)($cart['items'][$product]??0)!==$expected[$product])return new WP_Error('conflict','تعداد این کالا در دستگاه دیگری تغییر کرده است. مقدار تازه را بررسی و دوباره ویرایش کنید.');
                    if($qty===0)unset($cart['items'][$product]);else $cart['items'][$product]=$qty;
                }
            }
            $validated=molavi_library_cart($cart);if(is_wp_error($validated))return $validated;
            $state['carts'][$index]=$validated;
        } elseif ($type==='cart_delete' || $type==='cart_rename') {
            $id=$op['id']??null;
            if(!is_string($id)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/',$id))return new WP_Error('invalid','شناسهٔ سبد معتبر نیست.');
            if($type==='cart_delete')$state['carts']=array_values(array_filter($state['carts'],static fn($cart)=>$cart['id']!==$id));
            else{
                $name=is_string($op['name']??null)?trim(sanitize_text_field($op['name'])):'';
                if($name===''||mb_strlen($name)>60)return new WP_Error('invalid','نام سبد باید بین ۱ تا ۶۰ نویسه باشد.');
                foreach($state['carts'] as &$cart){if($cart['id']===$id)$cart['name']=$name;}unset($cart);
            }
        } else return new WP_Error('invalid','عملیات فهرست خرید معتبر نیست.');
    }
    return $state;
}
function molavi_account_library(): void {
    nocache_headers();
    $userId=get_current_user_id();
    if(!$userId)wp_send_json_error(['message'=>'برای همگام‌سازی وارد حساب خود شوید.'],401);
    check_ajax_referer('molavi-qty','nonce');
    $key='_molavi_library_v1';
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $state=get_user_meta($userId,$key,true);
        wp_send_json_success(molavi_library_public(is_array($state)?$state:molavi_library_empty()));
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')wp_send_json_error(['message'=>'روش درخواست معتبر نیست.'],405);
    $raw=isset($_POST['operations'])&&is_string($_POST['operations'])?wp_unslash($_POST['operations']):'';
    $ops=strlen($raw)<=80000?json_decode($raw,true):null;
    $requestId=isset($_POST['request_id'])&&is_string($_POST['request_id'])?$_POST['request_id']:'';
    if(!is_array($ops)||!$ops||count($ops)>30||!preg_match('/^[a-f0-9]{32}$/',$requestId))wp_send_json_error(['message'=>'درخواست همگام‌سازی معتبر نیست.'],400);
    $fingerprint=hash('sha256',$raw);
    // Compare-and-swap retries apply each operation to the latest account state.
    for($attempt=0;$attempt<5;$attempt++){
        $previous=get_user_meta($userId,$key,true);
        $state=is_array($previous)?$previous:molavi_library_empty();
        if(isset($state['receipts'][$requestId])){
            if($state['receipts'][$requestId]!==$fingerprint)wp_send_json_error(['message'=>'شناسهٔ درخواست با تغییر دیگری استفاده شده است.'],409);
            wp_send_json_success(molavi_library_public($state));
        }
        $next=molavi_library_apply($state,$ops);
        if(is_wp_error($next))wp_send_json_error(['message'=>$next->get_error_message(),'library'=>molavi_library_public($state)],in_array($next->get_error_code(),['limit','conflict'],true)?409:400);
        $next['revision']=(int)$state['revision']+1;
        $next['receipts'][$requestId]=$fingerprint;
        $next['receipts']=array_slice($next['receipts'],-20,null,true);
        if(is_array($previous))$saved=update_user_meta($userId,$key,$next,$previous);
        else{
            // WordPress usermeta has no unique key: serialize first creation with an option's unique name.
            $guard='molavi_library_init_'.$userId;
            if(!add_option($guard,time(),'',false)){
                if((int)get_option($guard,0)<time()-30)delete_option($guard);
                wp_cache_delete($userId,'user_meta');continue;
            }
            try{
                wp_cache_delete($userId,'user_meta');
                $saved=is_array(get_user_meta($userId,$key,true))?false:add_user_meta($userId,$key,$next,true);
            }finally{delete_option($guard);}
        }
        if($saved)wp_send_json_success(molavi_library_public($next));
        wp_cache_delete($userId,'user_meta');
    }
    wp_send_json_error(['message'=>'حساب هم‌زمان در حال تغییر است. دوباره تلاش کنید.'],409);
}
add_action('wc_ajax_molavi_library','molavi_account_library');
add_action('wc_ajax_nopriv_molavi_library','molavi_account_library');

/** Same-session read only refresh for the active customer page. */
function molavi_cart_ui_quote(): void {
    nocache_headers();
    if($_SERVER['REQUEST_METHOD']!=='GET')wp_send_json_error(['message'=>'روش درخواست معتبر نیست.'],405);
    check_ajax_referer('molavi-qty','nonce');
    $quote=molavi_quote();
    wp_send_json_success(['quote'=>$quote,'catalog'=>\Molavi\Elementor\molavi_catalog_public_rows(array_keys($quote['qtys']))]);
}
add_action('wc_ajax_molavi_ui_quote','molavi_cart_ui_quote');
add_action('wc_ajax_nopriv_molavi_ui_quote','molavi_cart_ui_quote');
