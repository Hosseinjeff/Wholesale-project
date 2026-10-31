<?php
/** Run with WP-CLI eval-file against disposable WP+WooCommerce only. */
$owner = get_user_by('login', 'reference_test_owner');
define('MOLAVI_REFERENCE_OWNER_IDS', [(int) $owner->ID]);
define('MOLAVI_REFERENCE_SERVER_URL', 'https://reference-test.invalid');
define('MOLAVI_REFERENCE_BRIDGE_TOKEN', str_repeat('synthetic-token-', 4));
wp_set_current_user($owner->ID);
require '/plugin/molavi-shop.php';
function ref_assert($condition, $label) {
    if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
    echo 'PASS: ' . $label . "\n";
}
function ref_prepare_owner_workflow_page($page) {
    $_GET['page'] = $page;
    do_action('admin_enqueue_scripts', '');
}
$event = ['event_id' => 'failure:<script>alert(1)</script>', 'snapshot_id' => 'A',
    'reason' => 'refresh_failed', 'created_at' => '2026-10-25T00:00:00+00:00'];
$price_offer = ['product_id'=>'price-1','name'=>'<script>price</script>','channel'=>'@supplier',
    'sale_price'=>40000,'source_revision'=>'revision-1','state'=>'warning'];
$sheet_conflict = ['conflict_id'=>'sheet:synthetic-conflict','product_id'=>'product-1',
    'product_name'=>'Synthetic','channel'=>'@supplier','field'=>'Consumer Price',
    'source_revision'=>'offer-2','base'=>50000,'server'=>52000,'sheet'=>53000,'state'=>'open'];
$paid_plan = ['order_id'=>'paid-runtime-1','state'=>'needs_owner_review',
    'lines'=>[['sku'=>'runtime-tuna','customer_units'=>30,'owned_units_reserved'=>12,'shortfall_units'=>18]],
    'source_lines'=>[['sku'=>'runtime-tuna','supplier_offer_id'=>'runtime-offer-1']],
    'procurement'=>[['sku'=>'runtime-tuna','state'=>'needs_supplier_terms','shortfall_units'=>18]],
    'workflow'=>['order_id'=>'paid-runtime-1','state'=>'supplier_followup','assigned_to'=>'owner',
        'next_action'=>'Check the source offer','procurement'=>[],'resolutions'=>[],'refunds'=>[],
        'packing'=>['status'=>'pending'],'dispatch'=>['status'=>'pending'],'delivery'=>['status'=>'pending'],
        'aftercare'=>['status'=>'none']],
    'substitution_candidates'=>['runtime-tuna'=>[['sku'=>'runtime-tuna-alt','name'=>'Tuna Premium',
        'available_units'=>18,'required_units'=>18,'price_floor_toman'=>50000,'price_ceiling_toman'=>65000]]],
    'order_total_toman'=>900000,'original_line_totals_toman'=>['runtime-tuna'=>900000],
    'purchase_authorized'=>false];
$cancelled_plan=['order_id'=>'paid-runtime-cancelled','state'=>'released','lines'=>[],'procurement'=>[],
    'workflow'=>['order_id'=>'paid-runtime-cancelled','state'=>'cancelled_refund_pending',
        'procurement'=>['runtime-tuna'=>['state'=>'ordered','supplier_reference'=>'PO-cancelled',
            'expected_supplier_units'=>24,'received_units'=>0]],'resolutions'=>[],
        'refunds'=>[['refund_id'=>'runtime-refund-1','amount_toman'=>900000,'status'=>'requested']],
        'packing'=>['status'=>'pending'],'dispatch'=>['status'=>'pending'],'delivery'=>['status'=>'pending'],
        'aftercare'=>['status'=>'none']]];
$stock_lot = ['lot_id'=>'receipt-runtime-1','sku'=>'runtime-tuna','supplier'=>'Supplier A',
    'purchase_date'=>'2026-10-01','received_at'=>'2026-10-28','production_date'=>'2026-09-01','expiry_date'=>'2027-09-01',
    'units'=>12,'available_units'=>8,'committed_units'=>4,'unit_cost'=>700000,'state'=>'ready',
    'receipt_hash'=>str_repeat('b',64),'revision'=>str_repeat('c',64)];
$clearance_result=['state'=>'alert_threshold_required','suggested_price_toman'=>780000,
    'near_cost_alert'=>false,'purchase_cost_toman'=>700000];
$review_case=['case_id'=>'item:runtime-case','offer_id'=>'runtime-offer:1','snapshot_id'=>'runtime-snapshot',
    'product_name'=>'چای سبز','channel'=>'@supplier','supplier_price'=>'450000',
    'match_state'=>'multiple_candidates','price_role_hint'=>'needs_review','reason'=>'reference: multiple_candidates',
    'decision'=>null,'candidates'=>[['reference_id'=>'R-1','name'=>'چای سبز','unit_price'=>40000,
        'package_price'=>480000,'score'=>0.99]]];
$ai_case=['case_id'=>'ai:' . str_repeat('d',64),'offer_id'=>'runtime-ai-offer:1',
    'signature'=>str_repeat('e',64),'product_name'=>'لوبیا','channel'=>'@supplier',
    'reason'=>'supplier price role is unclear','sale_price'=>'120000','actual_price'=>'150000',
    'package_price'=>'120000','package_count'=>'12','min_qty'=>'12','price_type'=>'needs_review',
    'currency'=>'IRT','stock_status'=>'Available','original_message'=>'لوبیا ۱۲ عدد ۱۲۰۰۰۰ تومان',
    'previous_decision_id'=>null,'decision'=>null];
$reference_snapshot=['snapshot_id'=>str_repeat('a',64),'extracted_at'=>'2026-10-28T10:00:00+00:00',
    'row_count'=>1,'pdf_sha256'=>str_repeat('b',64),'model'=>'gemini-3.1-flash-lite',
    'source_url'=>'https://rezvanpakhsh.com/pdf/price-list.pdf','reviewed_by'=>null,
    'review_note'=>null,'active'=>false,'preview'=>[['product_name'=>'نمونه زنده',
        'product_code'=>'REF-1','package_price'=>120000,'unit_price'=>10000,
        'consumer_unit_price'=>'','package_count'=>12,'unit_label'=>'عدد','pdf_row_number'=>'1']]];
$operations_snapshot=['actionable_count'=>2,'attention_count'=>1,
    'by_state'=>['queued'=>1,'needs_review'=>1],'publication_holds'=>1,'sheet_conflicts'=>2,
    'items'=>[['id'=>'batch:runtime-1','kind'=>'batch','state'=>'needs_review','attempts'=>2,
        'error'=>'<script>job</script>','lease_expired'=>false]]];
$http_mode = 'queue'; $captured = [];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$http_mode, &$captured, $event, $price_offer, $sheet_conflict, $paid_plan, $cancelled_plan, $stock_lot, $review_case, $ai_case, $reference_snapshot, $operations_snapshot, $clearance_result) {
    $captured = [$args, $url];
    if (str_ends_with($url, '/pipeline/operations-dashboard')) {
        return ['headers'=>[], 'body'=>wp_json_encode(['ok'=>true,'operations'=>$operations_snapshot]),
            'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/supplier-price-evidence')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        $body = $args['method'] === 'POST' ? ['ok'=>true,'product_id'=>$payload['product_id'],'source_revision'=>$payload['expected_revision']] :
            ['ok'=>true,'offers'=>[$price_offer]];
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/sheet-conflicts')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        $body = $args['method'] === 'POST' ? ['ok'=>true,'conflict_id'=>$payload['conflict_id'],'choice'=>$payload['choice']] :
            ['ok'=>true,'conflicts'=>[$sheet_conflict]];
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/paid-orders')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        if ($args['method'] === 'POST') {
            if (isset($payload['action'])) {
                $action=$payload['action'];
                if ($http_mode==='paid_bad_action') { $action='mark_delivered'; }
                $body=['ok'=>true,'order_id'=>$payload['order_id'],'state'=>'needs_owner_review',
                    'action'=>$action,'workflow'=>['order_id'=>$payload['order_id'],
                        'last_event'=>['action'=>$action]]];
            } else {
                $body=['ok'=>true,'order_id'=>$payload['order_id'],'state'=>'procurement_proposed',
                    'terms_hash'=>str_repeat('a',64)];
                if ($http_mode==='paid_bad_receipt') { unset($body['terms_hash']); }
            }
        } else { $body=['ok'=>true,'orders'=>[$paid_plan,$cancelled_plan]]; }
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/stock-lots')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        if ($args['method'] === 'POST') {
            $body=['ok'=>true,'lot_id'=>$payload['lot_id'],'state'=>'ready','receipt_hash'=>str_repeat('b',64)];
            if ($http_mode==='stock_bad_receipt') { unset($body['receipt_hash']); }
        } else { $body=['ok'=>true,'lots'=>[$stock_lot]]; }
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/clearance-proposals')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        if ($args['method']==='POST') {
            $body=['ok'=>true,'proposal_id'=>str_repeat('c',64),'lot_id'=>$payload['lot_id'],
                'receipt_hash'=>$payload['receipt_hash'],'state'=>'ready','result'=>$clearance_result];
        } else {
            $display_lot=$stock_lot;
            if ($http_mode==='clearance_missing_purchase') {
                $display_lot['purchase_date']=null;$display_lot['state']='held';$display_lot['available_units']=0;
            }
            $body=['ok'=>true,'today'=>'2026-10-28','near_cost_alert_threshold_toman'=>null,
                'lots'=>[$display_lot],'proposals'=>[['proposal_id'=>str_repeat('c',64),'lot_id'=>$stock_lot['lot_id'],
                    'sku'=>$stock_lot['sku'],'created_at'=>'2026-10-28T10:00:00+03:30','note'=>'Synthetic owner note',
                    'result'=>$clearance_result]]];
        }
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/stock-lots/purchase-date')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        $body=['ok'=>true,'lot_id'=>$payload['lot_id'],
            'state'=>$http_mode==='clearance_missing_purchase'?'held':'ready','receipt_hash'=>str_repeat('c',64)];
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/stock-lot-actions')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        $body=$args['method']==='POST'
            ? ['ok'=>true,'lot_id'=>$payload['lot_id'],'state'=>$payload['action']==='hold'?'held':'ready',
                'action'=>$payload['action'],'previous_revision'=>$http_mode==='stock_bad_event_receipt'?str_repeat('a',64):$payload['expected_revision'],
                'receipt_hash'=>str_repeat('b',64),'event_id'=>str_repeat('d',64),
                'revision'=>str_repeat('c',64),'units'=>8,'idempotent'=>false]
            : ['ok'=>true,'events'=>[['event_id'=>str_repeat('d',64),'lot_id'=>$stock_lot['lot_id'],
                'action'=>'adjust','delta_units'=>-2,'reason'=>'damage','note'=>'<script>stock</script>',
                'created_at'=>'2026-10-28T12:00:00+03:30']]];
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/ai-confirmations')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        if ($args['method']==='POST') {
            $body=['ok'=>true,'case_id'=>$payload['case_id'],'offer_id'=>$payload['offer_id'],
                'signature'=>$payload['signature'],'choice'=>$payload['choice'],
                'decision_id'=>str_repeat('f',64),'projection_state'=>$payload['choice']==='not_product'?'not_product':'imported',
                'publish_job'=>'publish:ai-confirmation'];
            if ($http_mode==='ai_bad_receipt') { $body['case_id']='another-case'; }
        } else { $body=['ok'=>true,'cases'=>[$ai_case],'recent_decisions'=>[],'publication_held'=>false]; }
        if ($http_mode==='offline') { return new WP_Error('timeout', 'synthetic timeout'); }
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/reference/snapshots')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        if ($args['method']==='POST') {
            if (($payload['action'] ?? '')==='refresh') {
                $body=['ok'=>true,'action'=>'refresh','state'=>'failed',
                    'last_attempt_at'=>$payload['expected_last_attempt_at'] ?? '2026-10-28T10:00:00+00:00',
                    'error_code'=>'gemini_http_404'];
                if ($http_mode==='snapshot_bad_receipt') { $body['state']='unknown'; }
            } else {
                $body=['ok'=>true,'action'=>$payload['action'],'snapshot_id'=>$payload['snapshot_id']];
                if ($payload['action']==='review') { $body['reviewed_by']='wp:' . $GLOBALS['current_user']->ID; }
                else { $body += ['active_snapshot_id'=>$payload['snapshot_id'],'projection_state'=>'written','row_count'=>1]; }
                if ($http_mode==='snapshot_bad_receipt') { $body['snapshot_id']=str_repeat('c',64); }
            }
        } else {
            $body=['ok'=>true,'active_snapshot_id'=>null,'snapshots'=>[$reference_snapshot],
                'refresh_state'=>['state'=>'staged','last_attempt_at'=>'2026-10-28T10:00:00+00:00']];
            if ($http_mode==='snapshot_refresh_failed') {
                $body['refresh_state']=['state'=>'failed','last_attempt_at'=>'2026-10-28T10:00:00+00:00',
                    'error_code'=>'gemini_http_404'];
            }
        }
        if ($http_mode==='offline') { return new WP_Error('timeout','synthetic timeout'); }
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/reference/item-reviews')) {
        $payload=json_decode($args['body'] ?? '{}',true);
        if ($args['method']==='POST') {
            $body=['ok'=>true,'case_id'=>$payload['case_id'],'offer_id'=>$payload['offer_id'],
                'snapshot_id'=>$payload['snapshot_id'],'choice'=>$payload['choice'],
                'decision_id'=>str_repeat('c',64),'price_role'=>$payload['price_role'],
                'projection_state'=>$payload['choice']==='match'?'imported':'held_for_review','publish_job'=>'publish:runtime'];
            if ($http_mode==='item_bad_receipt') { $body['offer_id']='different-offer'; }
        } else {
            $body=['ok'=>true,'reference_state'=>['allow_automatic'=>true], 'cases'=>[$review_case],
                'recent_decisions'=>[]];
        }
        if ($http_mode==='offline') { return new WP_Error('timeout', 'synthetic timeout'); }
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/pipeline/publication-holds')) {
        if ($http_mode === 'offline') { return new WP_Error('timeout', 'synthetic timeout'); }
        $body = $args['method'] === 'POST' ? ['ok'=>true,'hold_id'=>json_decode($args['body'],true)['hold_id']] :
            ['ok'=>true,'holds'=>[['hold_id'=>'batch:<script>alert(1)</script>','reason'=>'uncertain']]];
        return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if (str_ends_with($url, '/shop/order')) {
        $payload = json_decode($args['body'], true);
        return ['headers'=>[], 'body'=>wp_json_encode(['written'=>$http_mode==='order_written',
            'order_id'=>(string)$payload['order']['id']]), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    if ($http_mode === 'offline') { return new WP_Error('timeout', 'synthetic timeout'); }
    if ($http_mode === 'accepted') {
        return ['headers'=>[], 'body'=>wp_json_encode(array_merge(['ok'=>true],json_decode($args['body'],true))),
            'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
    }
    $body = $http_mode === 'missing' ? ['ok'=>true,'events'=>[array_merge($event,['snapshot_id'=>null])]]
        : ['ok'=>true,'events'=>[$event]];
    return ['headers'=>[], 'body'=>wp_json_encode($body), 'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[]];
}, 10, 3);
ref_assert(molavi_reference_owner(), 'configured owner with Woo capability allowed');
$other = wp_create_user('reference_other_admin', 'local-test-only-password', 'other@example.invalid');
(new WP_User($other))->set_role('administrator');
wp_set_current_user($other);
ref_assert(!molavi_reference_owner(), 'another administrator is not implicitly the owner');
ref_assert(molavi_reference_snapshots_browser_token()==='', 'non-owner cannot mint a browser capability');
ref_assert(molavi_owner_workflow_token('owner:/pipeline/operations-dashboard')==='', 'non-owner cannot mint a pipeline browser capability');
ref_assert(is_wp_error(molavi_reference_bridge('GET')), 'non-owner cannot access server bridge');
wp_set_current_user($owner->ID);
ref_assert((bool)preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', molavi_owner_workflow_token('owner:/pipeline/operations-dashboard')),
    'owner receives a short-lived scoped pipeline capability');
ref_assert(molavi_owner_workflow_token('owner:/pipeline/paid-orders')==='', 'pipeline capability cannot be minted for an unlisted route');
ref_prepare_owner_workflow_page('molavi-reference-decisions');
ob_start(); molavi_reference_screen(); $html=ob_get_clean();
ref_assert(strpos($html,'id="molavi-owner-workflow"')!==false, 'reference decisions load in the shared owner workflow UI');
ref_assert(strpos($html,MOLAVI_REFERENCE_BRIDGE_TOKEN)===false, 'bridge token absent from HTML');
ref_assert(!is_wp_error(molavi_reference_bridge('GET')), 'reference decision API returns the live workflow data contract');
ref_assert($captured[0]['headers']['X-Molavi-Actor']==='wp:'.$owner->ID, 'backend asserts logged-in actor');
ref_assert($captured[0]['redirection']===0, 'bridge does not follow redirects with token');
$http_mode='missing'; $missing=molavi_reference_bridge('GET');
ref_assert(!is_wp_error($missing) && $missing['events'][0]['snapshot_id']===null, 'missing snapshot state is returned to the browser UI');
$http_mode='queue';
ref_assert(is_wp_error(molavi_reference_bridge('POST',['event_id'=>'job-1','snapshot_id'=>'A','choice'=>'pause_review'])), 'generic success without matching receipt rejected');
$http_mode='accepted';
ref_assert(!is_wp_error(molavi_reference_bridge('POST',['event_id'=>'job-1','snapshot_id'=>'A','choice'=>'pause_review'])), 'matching durable decision receipt accepted');
$http_mode='offline'; ref_assert(is_wp_error(molavi_reference_bridge('POST',[])), 'network failure is not acknowledged');
$http_mode='queue'; ref_prepare_owner_workflow_page('molavi-reference-items');
ob_start(); molavi_reference_item_reviews_screen(); $item_html=ob_get_clean();
ref_assert(strpos($item_html,'id="molavi-owner-workflow"')!==false,
    'item review mounts the owner-scoped browser workflow UI');
ref_assert(strpos($item_html,MOLAVI_REFERENCE_BRIDGE_TOKEN)===false,
    'item review page does not expose the static bridge token');
$item_payload=['case_id'=>'item:runtime-case','offer_id'=>'runtime-offer:1','snapshot_id'=>'runtime-snapshot',
    'choice'=>'match','reference_id'=>'R-1','price_role'=>'package','note'=>'Owner verified carton price',
    'expected_decision_id'=>null];
$http_mode='item_bad_receipt'; ref_assert(is_wp_error(molavi_reference_item_review_request('POST',$item_payload)),
    'item review rejects a receipt for another offer');
$http_mode='accepted'; ref_assert(!is_wp_error(molavi_reference_item_review_request('POST',$item_payload)),
    'item review accepts matching decision and publication receipt');
$http_mode='queue'; ref_prepare_owner_workflow_page('molavi-ai-confirmations');
ob_start(); molavi_ai_confirmations_screen(); $ai_html=ob_get_clean();
ref_assert(strpos($ai_html,'id="molavi-owner-workflow"')!==false,
    'AI extraction review mounts the owner-scoped browser workflow UI');
ref_assert(strpos($ai_html,MOLAVI_REFERENCE_BRIDGE_TOKEN)===false,
    'AI review page does not expose the static bridge token');
 $workflow_js=file_get_contents(__DIR__ . '/../shop-wp/molavi-shop/includes/owner-workflows.js');
ref_assert(strpos($workflow_js,'original_message')!==false && strpos($workflow_js,'expected_decision_id')!==false,
    'browser review UI displays source messages and submits optimistic decision revisions');
$ai_payload=['case_id'=>$ai_case['case_id'],'offer_id'=>$ai_case['offer_id'],
    'signature'=>$ai_case['signature'],'choice'=>'confirm','fields'=>[
        'price_role'=>'package','sale_price'=>120000,'actual_price'=>150000,
        'package_count'=>12,'min_qty'=>12,'stock_status'=>'Available'],
    'note'=>'Owner checked original post','expected_decision_id'=>null];
$http_mode='ai_bad_receipt'; ref_assert(is_wp_error(molavi_ai_confirmation_request('POST',$ai_payload)),
    'AI confirmation rejects receipt for another case');
$http_mode='accepted'; ref_assert(!is_wp_error(molavi_ai_confirmation_request('POST',$ai_payload)),
    'AI confirmation accepts matching durable owner receipt');
$http_mode='queue'; ob_start(); molavi_reference_snapshots_screen(); $snapshot_html=ob_get_clean();
ref_assert(strpos($snapshot_html,'id="molavi-reference-snapshots"')!==false,
    'WooCommerce API snapshot review loads through the owner browser');
ref_assert(strpos($snapshot_html,MOLAVI_REFERENCE_BRIDGE_TOKEN)===false
    && file_exists('/plugin/includes/reference-snapshots.js'),
    'browser dashboard does not render the long-lived bridge secret');
$browser_token=molavi_reference_snapshots_browser_token();
ref_assert(strlen($browser_token)>64 && $browser_token!==MOLAVI_REFERENCE_BRIDGE_TOKEN,
    'owner browser receives a short-lived signed capability, not the bridge secret');
$snapshot_review=['action'=>'review','snapshot_id'=>$reference_snapshot['snapshot_id'],
    'note'=>'Reviewed coverage and sample prices','expected_active_id'=>null];
$http_mode='snapshot_bad_receipt'; ref_assert(is_wp_error(molavi_reference_snapshots_request('POST',$snapshot_review)),
    'snapshot review rejects a receipt for another candidate');
$http_mode='accepted'; ref_assert(!is_wp_error(molavi_reference_snapshots_request('POST',$snapshot_review)),
    'snapshot review accepts matching owner receipt');
$snapshot_activate=['action'=>'activate','snapshot_id'=>$reference_snapshot['snapshot_id'],
    'expected_active_id'=>null];
ref_assert(!is_wp_error(molavi_reference_snapshots_request('POST',$snapshot_activate)),
    'activation requires a matching ReferencePrices row receipt');
$snapshot_refresh=['action'=>'refresh','expected_last_attempt_at'=>'2026-10-28T10:00:00+00:00'];
$http_mode='accepted'; ref_assert(!is_wp_error(molavi_reference_snapshots_request('POST',$snapshot_refresh)),
    'manual reference retry accepts only a matching owner receipt');
$http_mode='snapshot_bad_receipt'; ref_assert(is_wp_error(molavi_reference_snapshots_request('POST',$snapshot_refresh)),
    'manual reference retry rejects an invalid server receipt');
$http_mode='snapshot_refresh_failed'; ob_start(); molavi_reference_snapshots_screen(); $failed_snapshot_html=ob_get_clean();
ref_assert(strpos($failed_snapshot_html,'id="molavi-reference-snapshots"')!==false
    && strpos($failed_snapshot_html,MOLAVI_REFERENCE_BRIDGE_TOKEN)===false,
    'owner browser dashboard stays available without a WordPress-to-Railway connection');
$http_mode='queue';
$http_mode='offline';
add_filter('wp_die_handler', static function () { return static function () { throw new RuntimeException('wp_die'); }; });
$_SERVER['REQUEST_METHOD']='POST';
$_POST=['event_id'=>'job-1','snapshot_id'=>'A','choice'=>'pause_review','_wpnonce'=>'invalid'];
$_REQUEST=$_POST;
$nonce_denied=false;
try { do_action('admin_post_molavi_reference_choice'); } catch (RuntimeException $e) { $nonce_denied=true; }
ref_assert($nonce_denied, 'invalid WordPress nonce rejected');
echo 'WordPress ' . get_bloginfo('version') . '; WooCommerce ' . WC_VERSION . "; runtime checks completed\n";

// Exercise the existing Woo order path, including block-checkout callback and durable retry.
wp_mkdir_p(ABSPATH . 'private');
file_put_contents(ABSPATH . 'private/config.php', "<?php return ['notify_secret'=>'synthetic','bot_url'=>'https://reference-test.invalid'];");
$order = wc_create_order();
$order->set_billing_first_name('Synthetic');
$order->set_billing_last_name('Order');
$orderProduct = new WC_Product_Simple();
$orderProduct->set_name('Synthetic purchased item');
$orderProduct->set_sku('synthetic-order-sku');
$orderProduct->set_regular_price('100');
$orderProduct->save();
$orderProduct->update_meta_data('_molavi_supplier_offer_id','synthetic-offer-1');
$orderProduct->save();
$order->add_product($orderProduct, 2);
$order->save();
$orderItem=array_values($order->get_items())[0];
do_action('woocommerce_checkout_create_order_line_item',$orderItem,'runtime-line',['data'=>$orderProduct],$order);
$orderItem->save();
$http_mode='order_pending';
do_action('woocommerce_store_api_checkout_order_processed', $order);
$order=wc_get_order($order->get_id());
ref_assert($order->get_meta('_molavi_published')!=='1', 'HTTP 200 without written acknowledgement does not transfer order');
ref_assert($order->get_meta('_molavi_handoff_status')==='pending', 'failed handoff remains visible on Woo order');
ref_assert((bool)as_has_scheduled_action('molavi_retry_order_notice',[$order->get_id(),1],'molavi-orders'), 'Woo Action Scheduler retains retry');
$http_mode='order_written';
do_action('molavi_retry_order_notice',$order->get_id(),1);
$order=wc_get_order($order->get_id());
ref_assert($order->get_meta('_molavi_published')==='1', 'matching written receipt marks order transferred');
ref_assert(has_action('woocommerce_checkout_order_processed','molavi_publish_order_notice')!==false, 'classic checkout uses same handoff');
$orderProduct->set_sku('changed-after-checkout-sku');
$orderProduct->update_meta_data('_molavi_supplier_offer_id','changed-after-checkout-offer');
$orderProduct->save();
$order->payment_complete('synthetic-transaction');
$order=wc_get_order($order->get_id());
$paidPayload=json_decode($captured[0]['body'] ?? '{}',true);
ref_assert($order->get_meta('_molavi_paid_handoff_status')==='written', 'verified Woo payment is mirrored with a receipt');
ref_assert(($paidPayload['order']['payment_status'] ?? '')==='verified', 'payment sync is emitted only from Woo paid state');
ref_assert(($paidPayload['order']['transaction_id'] ?? '')==='synthetic-transaction', 'payment snapshot carries transaction reference');
ref_assert(($paidPayload['order']['lines'][0]['sku'] ?? '')==='synthetic-order-sku', 'order line carries the catalog SKU for later stock allocation');
ref_assert(($paidPayload['order']['lines'][0]['supplier_offer_id'] ?? '')==='synthetic-offer-1', 'order line retains exact supplier offer provenance');
ref_assert(has_action('woocommerce_checkout_create_order_line_item','molavi_capture_order_line_source')!==false, 'source provenance is frozen onto order items at checkout');
ref_assert(has_action('woocommerce_payment_complete','molavi_sync_paid_order')!==false, 'Woo verified-payment hook is registered');
$order->set_status('cancelled');$order->save();do_action('woocommerce_order_status_cancelled',$order->get_id());
$terminalPayload=json_decode($captured[0]['body'] ?? '{}',true);
ref_assert(($terminalPayload['order']['woo_status'] ?? '')==='cancelled', 'Woo cancellation sends terminal state for open stock reservation release');
$http_mode='order_pending';
$retryOrder=wc_create_order();
$retryOrder->payment_complete('synthetic-retry-transaction');
$retryOrder=wc_get_order($retryOrder->get_id());
ref_assert($retryOrder->get_meta('_molavi_paid_handoff_status')==='pending', 'unconfirmed payment mirror remains pending');
ref_assert((bool)as_has_scheduled_action('molavi_retry_paid_order_handoff',[$retryOrder->get_id(),1],'molavi-orders'), 'paid-status update retains a retry');
$http_mode='order_written';
do_action('molavi_retry_paid_order_handoff',$retryOrder->get_id(),1);
$retryOrder=wc_get_order($retryOrder->get_id());
ref_assert($retryOrder->get_meta('_molavi_paid_handoff_status')==='written', 'paid-status retry completes only after a matching receipt');
unlink(ABSPATH . 'private/config.php');
echo "Order handoff runtime checks completed\n";

// Verify explicit supplier unavailability survives the Woo projection.
$row=['id'=>'a123456789ab','name'=>'Synthetic stock item','status'=>'sell','sale'=>1000,
      'supplier_availability'=>'outofstock'];
$clean=molavi_clean_product($row);
$product_id=molavi_upsert_product(0,$clean);
ref_assert(wc_get_product($product_id)->get_stock_status()==='outofstock', 'supplier out-of-stock becomes unavailable in Woo');
$row['supplier_availability']='unknown';
molavi_upsert_product($product_id,molavi_clean_product($row));
ref_assert(wc_get_product($product_id)->get_stock_status()==='outofstock', 'unknown supplier status cannot silently restore availability');
$row['supplier_availability']='instock';
molavi_upsert_product($product_id,molavi_clean_product($row));
ref_assert(wc_get_product($product_id)->get_stock_status()==='instock', 'explicit current supplier availability restores demand-led checkout');
$row['status']='hold';
molavi_upsert_product($product_id,molavi_clean_product($row));
ref_assert(wc_get_product($product_id)->get_stock_status()==='outofstock', 'held stale offer blocks Woo checkout');
$verified_row=['id'=>'a123456789ab','name'=>'Synthetic freshness item','status'=>'sell','sale'=>1000,'shelf'=>1200,
    'supplier_availability'=>'instock','source_record_id'=>'product-row-1','supplier_price_state'=>'warning',
    'supplier_price_verified_at'=>gmdate('c',time()-(8*DAY_IN_SECONDS))];
$freshness_product_id=molavi_upsert_product(0,molavi_clean_product($verified_row));
ref_assert(apply_filters('woocommerce_is_purchasable',true,wc_get_product($freshness_product_id))===true, 'verified supplier price remains purchasable during warning window');
$verified_row['supplier_price_state']='blocked';$verified_row['supplier_price_verified_at']=gmdate('c',time()-(15*DAY_IN_SECONDS));
molavi_upsert_product($freshness_product_id,molavi_clean_product($verified_row));
ref_assert(apply_filters('woocommerce_is_purchasable',true,wc_get_product($freshness_product_id))===false, '14-day supplier price limit blocks Woo cart purchase');
$verified_row['supplier_price_state']='current';$verified_row['supplier_price_verified_at']=gmdate('c',time()-(DAY_IN_SECONDS));
molavi_upsert_product($freshness_product_id,molavi_clean_product($verified_row));
ref_assert(apply_filters('woocommerce_is_purchasable',true,wc_get_product($freshness_product_id))===true, 'fresh supplier verification restores Woo purchase eligibility');
$priced_row=['id'=>'b123456789ab','name'=>'Synthetic customer price','status'=>'sell','sale'=>37500,'shelf'=>85000,
    'supplier_availability'=>'instock'];
$priced_product_id=molavi_upsert_product(0,molavi_clean_product($priced_row));
$priced_product=wc_get_product($priced_product_id);
$expected_curve=molavi_band(37500,85000);
ref_assert((int)$priced_product->get_regular_price()===85000
    && (int)$priced_product->get_sale_price()===$expected_curve['ceiling'], 'public Woo prices use the customer price band, not supplier cost');
ref_assert((int)$priced_product->get_meta('_molavi_supplier_cost_toman')===37500
    && molavi_curve($priced_product)['sale']===37500, 'private supplier cost remains the input to cart pricing');
$renamed_product=new WC_Product_Simple();$renamed_product->set_name('Old supplier name');$renamed_product->set_status('publish');
$renamed_product->set_regular_price('1200');$renamed_product->set_price('1200');$renamed_product->save();
update_post_meta($renamed_product->get_id(),'_molavi_source_id','old-source-key');
update_post_meta($renamed_product->get_id(),'_molavi_supplier_offer_id','stable-product-row');
$empty_hold=['id'=>'aabbccddeeff','name'=>'','status'=>'hold','sale'=>0,'source_record_id'=>'stable-product-row'];
$empty_clean=molavi_clean_product($empty_hold);
ref_assert(is_array($empty_clean) && molavi_match_product($empty_clean,molavi_product_index())===$renamed_product->get_id(), 'stable product row identity survives a blank Sheet name');
molavi_apply_catalog(['products'=>[$empty_hold]]);
ref_assert(wc_get_product($renamed_product->get_id())->get_status()==='draft', 'invalid Sheet name hides its existing Woo product');

// Test the registered shipping method without activating or changing live zones.
do_action('woocommerce_shipping_init');
ref_assert(isset(apply_filters('woocommerce_shipping_methods',[])['molavi_tehran_delivery']), 'native Woo delivery method registered');
update_option('woocommerce_currency','IRT');
WC()->initialize_session();
wc_load_cart();
$delivery_product = new WC_Product_Simple();
$delivery_product->set_name('Synthetic delivery item');
$delivery_product->set_status('publish');
$delivery_product->set_regular_price('4999999');
$delivery_product->set_price('4999999');
$delivery_product->set_virtual(false);
$delivery_product->save();
WC()->cart->empty_cart();
WC()->cart->add_to_cart($delivery_product->get_id(),1);
WC()->customer->set_shipping_country('IR');
WC()->customer->set_shipping_city('تهران');
WC()->cart->calculate_totals();
$method = new Molavi_Tehran_Delivery(0);
$method->calculate_shipping(WC()->cart->get_shipping_packages()[0]);
$rates=$method->rates;
echo 'delivery test currency=' . get_woocommerce_currency() . ', items=' . WC()->cart->get_cart_contents_count() . ', total=' . WC()->cart->get_cart_contents_total() . ', packages=' . count(WC()->cart->get_shipping_packages()) . ', rates=' . count($rates) . "\n";
ref_assert(count($rates)===1 && (float) reset($rates)->get_cost()===250000.0, 'below threshold fixed Tehran fee');
$threshold_product = new WC_Product_Simple();
$threshold_product->set_name('Synthetic threshold item');$threshold_product->set_status('publish');
$threshold_product->set_regular_price('5000000');$threshold_product->set_price('5000000');$threshold_product->set_virtual(false);$threshold_product->save();
WC()->cart->empty_cart();WC()->cart->add_to_cart($threshold_product->get_id(),1);WC()->cart->calculate_totals();
$method = new Molavi_Tehran_Delivery(0);$method->calculate_shipping(WC()->cart->get_shipping_packages()[0]);
$rates=$method->rates;
echo 'threshold total=' . WC()->cart->get_cart_contents_total() . ', rates=' . count($rates) . ', cost=' . (count($rates) ? reset($rates)->get_cost() : 'none') . PHP_EOL;
ref_assert(count($rates)===1 && (float) reset($rates)->get_cost()===0.0, 'at threshold delivery is free');
$coupon = new WC_Coupon();$coupon->set_code('synthetic-discount');$coupon->set_discount_type('percent');$coupon->set_amount(10);$coupon->save();
WC()->cart->apply_coupon('synthetic-discount');WC()->cart->calculate_totals();
$method=new Molavi_Tehran_Delivery(0);$method->calculate_shipping(WC()->cart->get_shipping_packages()[0]);
$rates=$method->rates;
ref_assert((float)WC()->cart->get_cart_contents_total()===4500000.0 && count($rates)===1 && (float)reset($rates)->get_cost()===250000.0, 'delivery threshold uses discounted goods subtotal');
update_option('woocommerce_currency','IRR');
$rial_product=new WC_Product_Simple();$rial_product->set_name('Synthetic rial item');$rial_product->set_status('publish');
$rial_product->set_regular_price('49999999');$rial_product->set_price('49999999');$rial_product->set_virtual(false);$rial_product->save();
WC()->cart->empty_cart();WC()->cart->remove_coupons();WC()->cart->add_to_cart($rial_product->get_id(),1);WC()->cart->calculate_totals();
$method=new Molavi_Tehran_Delivery(0);$method->calculate_shipping(WC()->cart->get_shipping_packages()[0]);$rates=$method->rates;
ref_assert(count($rates)===1 && (float)reset($rates)->get_cost()===2500000.0, 'toman delivery fee converts to rial exactly');
// Exercise the native zone path with synthetic Iranian address, not a direct method call.
update_option('woocommerce_currency','IRT');
update_option('woocommerce_ship_to_countries','all');
$zone=new WC_Shipping_Zone();$zone->set_zone_name('Synthetic Tehran');$zone->add_location('IR','country');$zone->save();
$zone_method=$zone->add_shipping_method('molavi_tehran_delivery');
ref_assert($zone_method > 0, 'delivery method can be assigned to a Woo shipping zone');
WC()->customer->set_shipping_country('IR');WC()->customer->set_shipping_city('تهران');WC()->customer->set_shipping_postcode('1111111111');
WC()->cart->empty_cart();WC()->cart->remove_coupons();WC()->cart->add_to_cart($delivery_product->get_id(),1);
WC()->cart->calculate_totals();
$zone_packages=WC()->shipping()->get_packages();
$zone_rates=$zone_packages[0]['rates'] ?? [];
$molavi_rate=null;foreach($zone_rates as $rate){if($rate->get_method_id()==='molavi_tehran_delivery'){$molavi_rate=$rate;break;}}
ref_assert($molavi_rate !== null && (float)$molavi_rate->get_cost()===250000.0, 'Woo shipping-zone calculation uses fixed fee');

wp_set_current_user($owner->ID);$http_mode='queue';
ob_start();molavi_publication_holds_screen();$holds_html=ob_get_clean();
ref_assert(strpos($holds_html,'<script>alert(1)</script>')===false, 'publication hold data escaped');
ref_assert(strpos($holds_html,'_wpnonce')!==false && strpos($holds_html,'name="note"')!==false && strpos($holds_html,'اجازه پردازش مجدد')!==false, 'publication recovery requires WP nonce, note and explicit replay');
wp_set_current_user($other);
ref_assert(is_wp_error(molavi_pipeline_holds_request('GET')), 'non-owner cannot access publication holds');
wp_set_current_user($owner->ID);$http_mode='offline';
ref_assert(is_wp_error(molavi_pipeline_holds_request('POST',['hold_id'=>'batch','expected_reason'=>'uncertain','note'=>'reviewed','resume_batch'=>true])), 'failed publication recovery is not acknowledged');
ref_assert(json_decode($captured[0]['body'],true)['resume_batch']===true, 'review authorizes explicit batch replay');

$http_mode='queue';ob_start();molavi_supplier_prices_screen();$price_html=ob_get_clean();
ref_assert(strpos($price_html,'<script>price</script>')===false, 'supplier price data is escaped in Woo dashboard');
ref_assert(strpos($price_html,'name="source_revision"')!==false && strpos($price_html,'name="note"')!==false, 'price verification is bound to source revision and note');
$price_request=molavi_supplier_prices_request('POST',['product_id'=>'price-1','expected_revision'=>'revision-1','note'=>'checked']);
ref_assert(!is_wp_error($price_request) && $price_request['source_revision']==='revision-1', 'manual price verification requires matching server receipt');
$http_mode='queue';ob_start();molavi_sheet_conflicts_screen();$conflict_html=ob_get_clean();
ref_assert(strpos($conflict_html,'<script>')===false && strpos($conflict_html,'مقدار سرور')!==false && strpos($conflict_html,'مقدار شیت')!==false, 'sheet conflict dashboard displays escaped values from both sides');
ref_assert(strpos($conflict_html,'name="choice" value="server"')!==false && strpos($conflict_html,'name="choice" value="sheet"')!==false, 'sheet conflict dashboard offers both reviewed resolutions');
$conflict_request=molavi_sheet_conflicts_request('POST',['conflict_id'=>'sheet:synthetic-conflict','choice'=>'sheet','note'=>'keep sheet edit']);
ref_assert(!is_wp_error($conflict_request) && $conflict_request['conflict_id']==='sheet:synthetic-conflict', 'sheet conflict resolution requires matching server receipt');
$http_mode='queue';ob_start();molavi_paid_orders_screen();$orders_html=ob_get_clean();
ref_assert(strpos($orders_html,'<script>')===false && strpos($orders_html,'runtime-tuna')!==false, 'paid-order dashboard safely displays stock shortfall');
ref_assert(strpos($orders_html,'name="source_offer_id" value="runtime-offer-1"')!==false && strpos($orders_html,'_wpnonce')!==false, 'supplier terms form binds exact source offer and WordPress nonce');
ref_assert(strpos($orders_html,'هیچ خریدی برای تأمین‌کننده ثبت نمی‌کند')!==false, 'dashboard states that proposal does not place a purchase');
ref_assert(strpos($orders_html,'ثبت مسئول و اقدام بعدی')!==false && strpos($orders_html,'propose_substitution')!==false, 'paid-order dashboard exposes owner assignment and reviewable replacement proposal');
ref_assert(strpos($orders_html,'record_customer_choice')!==false && strpos($orders_html,'full_cancellation')!==false, 'dashboard records a customer cancellation choice through a nonce-protected owner form');
ref_assert(strpos($orders_html,'paid-runtime-cancelled')!==false && strpos($orders_html,'ثبت دریافت برای موجودی کل فروشگاه')!==false, 'cancelled order keeps an already placed supplier receipt and refund visible for owner follow-up');
ref_assert(strpos($orders_html,'receipt-runtime-1')!==false && strpos($orders_html,'name="expiry_date"')!==false, 'dashboard lists lots and supports expiry-aware stock receipts');
$purchase_form_fields=[['key'=>'purchase_date','label'=>'تاریخ واقعی خرید','type'=>'date','required'=>true]];
ob_start();molavi_paid_order_operation_form('paid-runtime-1','record_supplier_order',
    ['sku'=>'runtime-tuna','packs'=>1],$purchase_form_fields,'ثبت خرید');$purchase_form=ob_get_clean();
ref_assert(strpos($purchase_form,'details[purchase_date]')!==false, 'supplier purchase action form captures actual purchase date');
$stock_payload=['lot_id'=>'receipt-runtime-1','sku'=>'runtime-tuna','supplier'=>'Supplier A',
    'purchase_date'=>'2026-10-01','received_at'=>'2026-10-28','production_date'=>'2026-09-01','expiry_date'=>'2027-09-01',
    'units'=>12,'unit_cost'=>700000,'note'=>'Counted received units.'];
$stock_request=molavi_stock_lots_request('POST',$stock_payload);
ref_assert(!is_wp_error($stock_request) && $stock_request['receipt_hash']===str_repeat('b',64), 'stock receipt requires a matching durable server hash');
$http_mode='stock_bad_receipt';
ref_assert(is_wp_error(molavi_stock_lots_request('POST',$stock_payload)), 'stock receipt without its hash is rejected');
$http_mode='queue';
$clearance_payload=['lot_id'=>'receipt-runtime-1','receipt_hash'=>str_repeat('b',64),
    'normal_price_toman'=>900000,'market_price_toman'=>950000,'market_source'=>'Synthetic comparable offer',
    'market_observed_date'=>'2026-10-28','note'=>'Same unit and basket checked.'];
$clearance_request=molavi_clearance_proposals_request('POST',$clearance_payload);
ref_assert(!is_wp_error($clearance_request) && $clearance_request['result']['suggested_price_toman']===780000,
    'clearance calculation requires a matching lot receipt and an auditable result');
ob_start();molavi_clearance_pricing_screen();$clearance_html=ob_get_clean();
ref_assert(strpos($clearance_html,'receipt-runtime-1')!==false && strpos($clearance_html,'name="market_source"')!==false
    && strpos($clearance_html,'_wpnonce')!==false, 'clearance proposal form is integrated into private Woo admin');
ref_assert(strpos($clearance_html,'قیمت محصول را تغییر نمی‌دهد')!==false
    && strpos($clearance_html,'name="market_price_toman"')!==false, 'clearance proposal is explicitly owner-reviewed and never auto-published');
$http_mode='clearance_missing_purchase';ob_start();molavi_clearance_pricing_screen();$legacy_clearance_html=ob_get_clean();
ref_assert(strpos($legacy_clearance_html,'name="expected_receipt_hash"')!==false
    && strpos($legacy_clearance_html,'تاریخ واقعی خرید')!==false, 'legacy lot with missing purchase date exposes a receipt-hash-bound correction form');
$purchase_date_fix=molavi_stock_purchase_date_request(['lot_id'=>'receipt-runtime-1',
    'expected_receipt_hash'=>str_repeat('b',64),'purchase_date'=>'2026-10-01','note'=>'Verified receipt']);
ref_assert(!is_wp_error($purchase_date_fix) && $purchase_date_fix['receipt_hash']===str_repeat('c',64),
    'historical purchase-date correction accepts only a matching server receipt');
$inventory_payload=['lot_id'=>'receipt-runtime-1','expected_revision'=>str_repeat('c',64),
    'action'=>'adjust','delta_units'=>-2,'reason'=>'damage','note'=>'Counted damaged units'];
$inventory_result=molavi_stock_lot_actions_request('POST',$inventory_payload);
ref_assert(!is_wp_error($inventory_result) && $inventory_result['units']===8,
    'stock correction requires a current lot revision and matching server event receipt');
$http_mode='stock_bad_event_receipt';
ref_assert(is_wp_error(molavi_stock_lot_actions_request('POST',$inventory_payload)),
    'stock correction rejects an event receipt tied to another reviewed revision');
$http_mode='queue';
ob_start();molavi_inventory_management_screen();$inventory_html=ob_get_clean();
ref_assert(strpos($inventory_html,'name="expected_revision"')!==false
    && strpos($inventory_html,'name="delta_units"')!==false, 'owner inventory screen binds stock edits to the lot revision');
ref_assert(strpos($inventory_html,'<script>stock</script>')===false
    && strpos($inventory_html,'damage')!==false, 'inventory event history is escaped and visible to the owner');
$terms_payload=['order_id'=>'paid-runtime-1','supplier_terms'=>[
    'runtime-tuna'=>['supplier'=>'Supplier A','units_per_pack'=>24,'pack_price_toman'=>900000,
        'minimum_packs'=>1,'pack_multiple'=>1,'source_offer_id'=>'runtime-offer-1']],
    'note'=>'Checked the source offer.'];
$paid_request=molavi_paid_orders_request('POST',$terms_payload);
ref_assert(!is_wp_error($paid_request) && $paid_request['state']==='procurement_proposed', 'pack calculation requires a matching owner dashboard receipt');
$http_mode='paid_bad_receipt';
ref_assert(is_wp_error(molavi_paid_orders_request('POST',$terms_payload)), 'paid-order result without a terms hash is rejected');
$http_mode='queue';
$operation_payload=['order_id'=>'paid-runtime-1','action'=>'assign','details'=>['assigned_to'=>'owner'], 'note'=>'Set an owner.'];
$operation_request=molavi_paid_orders_request('POST',$operation_payload);
ref_assert(!is_wp_error($operation_request) && $operation_request['workflow']['last_event']['action']==='assign', 'workflow action requires a matching durable owner receipt');
$http_mode='paid_bad_action';
ref_assert(is_wp_error(molavi_paid_orders_request('POST',$operation_payload)), 'workflow action with a mismatched receipt is rejected');

$http_mode='queue';ref_prepare_owner_workflow_page('molavi-operations');
ob_start();molavi_operations_dashboard_screen();$operations_html=ob_get_clean();
ref_assert(strpos($operations_html,'id="molavi-owner-workflow"')!==false, 'pipeline hub mounts its live status panel');
ref_assert(strpos($operations_html,'page=molavi-ai-confirmations')!==false && strpos($operations_html,'page=molavi-paid-orders')!==false, 'pipeline hub keeps all workflow destinations reachable');
wp_set_current_user($other);
ref_assert(is_wp_error(molavi_operations_dashboard_request()), 'non-owner cannot read unified operations dashboard');
