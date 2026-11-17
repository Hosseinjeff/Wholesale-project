<?php
/** Plugin Name: Molavi Legacy Commerce API Adapter */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Supply the old public contract from the same source checks and pricing as WordPress. */
function molavi_domain_legacy_catalog(): array {
    $tables = molavi_domain_sheets(['Products','Offers','Shop'],true);
    $products = molavi_domain_records($tables['Products']);
    $offers = molavi_domain_records($tables['Offers']);
    $blocked = array_fill_keys(array_column(molavi_domain_source_issues($products,$offers),'product_id'),true);
    $conflicts = molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        "SELECT DISTINCT product_id FROM sheet_sync_conflicts WHERE state='open'"));
    foreach ($conflicts as $row) $blocked[$row['product_id']] = true;
    $eligible = [];
    foreach (molavi_domain_freshness($products) as $row) {
        $id = $row['product_id'];
        if (!isset($blocked[$id]) && in_array($row['state'],['current','warning'],true)) $eligible[$id] = true;
    }
    $catalog = molavi_domain_order_catalog($tables);
    return [
        'products'=>array_values(array_filter($catalog,static fn($row)=>isset($eligible[$row['product_id']]))),
        'target'=>molavi_domain_site_settings($tables['Shop'])['unlock_target'],
    ];
}

function molavi_domain_legacy_quote(array $products, array $lines, int $target): array {
    $byId = array_column($products,null,'product_id');
    $quantities = [];
    $missing = [];
    foreach ($lines as $line) {
        if (!is_array($line)) molavi_domain_fail('invalid_cart_line',400);
        $id = (string)molavi_domain_py_value($line['product_id'] ?? null);
        $raw = molavi_domain_py_value($line['qty'] ?? null,0);
        if (!is_int($raw) && !is_bool($raw) && !is_float($raw)
            && !(is_string($raw) && preg_match('/^[+-]?\d+$/D',trim(molavi_domain_digits($raw))))) {
            $missing[] = $id;
            continue;
        }
        $quantity = is_string($raw) ? (int)molavi_domain_digits($raw) : (int)$raw;
        if ($id==='' || $quantity<=0) continue;
        if (!isset($byId[$id])) {
            $missing[] = $id;
            continue;
        }
        // Prevent requests that cannot be totalled in integer Toman.
        if ($quantity>PHP_INT_MAX-($quantities[$id]??0) || $byId[$id]['regular']>intdiv(PHP_INT_MAX,$quantity)) {
            molavi_domain_fail('invalid_cart_quantity',400);
        }
        $quantities[$id] = ($quantities[$id]??0)+$quantity;
    }
    $regular = 0;
    $discountable = 0;
    foreach ($quantities as $id=>$quantity) {
        $row = $byId[$id];
        if ($row['regular']>intdiv(PHP_INT_MAX-$regular,$quantity)) molavi_domain_fail('invalid_cart_quantity',400);
        $value = $row['regular']*$quantity;
        $regular += $value;
        if ($row['max_discount']>0) $discountable += $value;
    }
    $progress = $target>0 ? min(1.0,$discountable/$target) : 0.0;
    $quoteLines = [];
    $payable = 0;
    foreach ($quantities as $id=>$quantity) {
        $row = $byId[$id];
        $price = (int)round($row['ceiling']+($row['floor']-$row['ceiling'])*$progress,0,PHP_ROUND_HALF_UP);
        $price = max($row['floor'],min($row['ceiling'],$price));
        $discount = $row['regular']-$price;
        $payable += $price*$quantity;
        $quoteLines[] = [
            'product_id'=>(string)$id,'name'=>$row['name'],'qty'=>$quantity,
            'regular_price'=>$row['regular'],'price'=>$price,
            'discount'=>$discount/$row['regular'],'discount_amount'=>$discount*$quantity,
        ];
    }
    return ['progress'=>$progress,'regular_total'=>$regular,'discount_total'=>$regular-$payable,
        'pay_total'=>$payable,'discount_rate'=>$regular?($regular-$payable)/$regular:0.0,
        'lines'=>$quoteLines,'missing'=>$missing];
}

function molavi_domain_legacy_commerce($request): WP_REST_Response {
    $input = $request->get_json_params();
    $route = $input['route'] ?? '';
    $body = $input['body'] ?? null;
    $headers = ['Cache-Control'=>'no-store','X-Molavi-Executor'=>'wordpress'];
    try {
        if (!get_option('molavi_state_active',false)) molavi_domain_fail('native_state_inactive',503);
        switch ($route) {
            case 'order':
                if (!molavi_domain_order_native_enabled()) molavi_domain_fail('native_orders_inactive',503);
                $result = molavi_domain_order_handoff(is_array($body)?$body:[]);
                return new WP_REST_Response($result['body'],$result['code'],$headers);
            case 'publish':
                if (molavi_domain_job_host('publish')!=='wordpress') molavi_domain_fail('native_publication_inactive',503);
                $imagesOnly = molavi_domain_py_value($body['images_only'] ?? null,null)!==null;
                $result = molavi_domain_lock('publication',static function() use ($imagesOnly): array {
                    $tables = $imagesOnly ? [] : molavi_domain_sheets(['Products','Offers','Shop'],true);
                    return molavi_domain_publish_shop($tables,$imagesOnly,12);
                });
                return new WP_REST_Response($result,empty($result['queued'])?200:202,$headers);
            case 'catalog':
                $catalog = molavi_domain_legacy_catalog();
                $products = array_map(static function(array $row): array {
                    return ['product_id'=>$row['product_id'],'name'=>$row['name'],'category'=>$row['category'],
                        'regular_price'=>$row['regular'],'price'=>$row['ceiling'],
                        'current_discount'=>($row['regular']-$row['ceiling'])/$row['regular'],
                        'stock_status'=>$row['stock_status']];
                },$catalog['products']);
                return new WP_REST_Response(['products'=>$products,'settings'=>['discount_target'=>$catalog['target']]],200,$headers);
            case 'cart':
                if (!is_array($body['lines']??null) || !array_is_list($body['lines'])) {
                    return new WP_REST_Response(['status'=>'error','message'=>'lines must be a list'],400,$headers);
                }
                $catalog = molavi_domain_legacy_catalog();
                return new WP_REST_Response(molavi_domain_legacy_quote($catalog['products'],$body['lines'],$catalog['target']),200,$headers);
            default:
                molavi_domain_fail('legacy_route_unavailable',404);
        }
    } catch (MolaviWorkflowError $e) {
        return new WP_REST_Response(['ok'=>false,'error'=>$e->getMessage()],$e->status,$headers);
    } catch (Throwable $e) {
        error_log('[molavi-native] legacy_commerce_unavailable');
        return new WP_REST_Response(['ok'=>false,'error'=>'native_workflow_unavailable'],503,$headers);
    }
}

add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/legacy-commerce',['methods'=>'POST',
        'permission_callback'=>'molavi_runtime_permission','callback'=>'molavi_domain_legacy_commerce']);
});
