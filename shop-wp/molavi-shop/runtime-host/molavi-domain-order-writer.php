<?php
/** Plugin Name: Molavi Native Order Sheet Writer */
declare(strict_types=1);
defined('ABSPATH') || exit;
function molavi_domain_order_headers(): array {return ["Order ID", "Created At", "Updated At", "Customer Name", "Customer Username", "Phone", "Email", "Address", "Note", "Progress", "Total", "Currency", "Status", "Woo Status", "Payment Status", "Payment Method", "Transaction ID", "Paid At", "Line Count", "Buyer Type"];}
function molavi_domain_order_line_headers(): array {return ["Order ID", "Line", "Created At", "Product ID", "Product Name", "Packaging", "Volume", "Supplier", "SKU", "Supplier Offer ID", "Quantity", "Min Qty", "Buyer Order Unit", "Sale Price", "Consumer Price", "Unit Price", "Line Total", "Currency"];}
function molavi_domain_order_sheet_records(array $source,string $updated=''): array {
    $o=molavi_domain_normalize_order($source);if($o['id']==='')molavi_domain_fail('order_required',400);
    $order=['Order ID'=>$o['id'],'Created At'=>$o['at'],'Updated At'=>$updated?:$o['at'],'Customer Name'=>$o['name'],
        'Customer Username'=>$o['username'],'Phone'=>$o['phone'],'Email'=>$o['email'],'Address'=>$o['address'],'Note'=>$o['note'],
        'Progress'=>$o['progress'],'Total'=>$o['total'],'Currency'=>'IRT','Status'=>$o['status'],'Woo Status'=>$o['woo_status'],
        'Payment Status'=>$o['payment_status'],'Payment Method'=>$o['payment_method'],'Transaction ID'=>$o['transaction_id'],
        'Paid At'=>$o['paid_at'],'Line Count'=>count($o['lines']),'Buyer Type'=>$o['buyer_type']];$lines=[];
    foreach($o['lines'] as $index=>$line)$lines[]=['Order ID'=>$o['id'],'Line'=>$index+1,'Created At'=>$o['at'],'Product ID'=>$line['id'],
        'Product Name'=>$line['name'],'Packaging'=>$line['packaging'],'Volume'=>$line['volume'],'Supplier'=>$line['supplier'],
        'SKU'=>$line['sku'],'Supplier Offer ID'=>$line['supplier_offer_id'],'Quantity'=>$line['qty'],'Min Qty'=>$line['min'],
        'Buyer Order Unit'=>$line['order_unit'],'Sale Price'=>$line['sale'],'Consumer Price'=>$line['shelf'],'Unit Price'=>$line['unit'],'Line Total'=>$line['total'],'Currency'=>'IRT'];
    return ['order'=>$order,'lines'=>$lines];
}
function molavi_domain_order_sheet_find(array $table,string $id): ?int {
    $column=array_search('Order ID',$table[0]??[],true);if($column===false)return null;
    foreach(array_slice($table,1) as $index=>$row)if((string)molavi_domain_py_value($row[$column]??'')===$id)return $index+2;return null;
}
function molavi_domain_order_sheet_delete(string $name,array $rows): void {
    if(!$rows)return;$properties=molavi_domain_sheet_properties();$id=$properties[$name]['sheetId']??null;if(!is_int($id))molavi_domain_fail('order_sheet_missing',503);
    rsort($rows,SORT_NUMERIC);$requests=[];foreach(array_unique($rows) as $row)$requests[]=['deleteDimension'=>['range'=>['sheetId'=>$id,'dimension'=>'ROWS','startIndex'=>$row-1,'endIndex'=>$row]]];
    foreach(array_chunk($requests,200) as $chunk)molavi_domain_google('POST',':batchUpdate',['requests'=>$chunk]);molavi_domain_sheet_invalidate($name);
}
function molavi_domain_order_upsert(array $source,string $updated=''): array {
    $records=molavi_domain_order_sheet_records($source,$updated);$id=$records['order']['Order ID'];
    molavi_domain_sheet_ensure('Orders',molavi_domain_order_headers());molavi_domain_sheet_ensure('OrderLines',molavi_domain_order_line_headers());
    $tables=molavi_domain_sheets(['Orders','OrderLines'],true);$headers=$tables['Orders'][0];$lineHeaders=$tables['OrderLines'][0];
    $row=molavi_domain_order_sheet_find($tables['Orders'],$id);$values=molavi_domain_rows_from_records($headers,[$records['order']])[1];
    if($row)molavi_domain_sheet_cells('Orders',[['range'=>'A'.$row.':'.molavi_domain_sheet_column(count($headers)).$row,'values'=>[$values]]]);
    else molavi_domain_sheet_append('Orders',[$values]);
    $column=array_search('Order ID',$lineHeaders,true);if($column===false)molavi_domain_fail('order_sheet_header_missing',503);$deletes=[];
    foreach(array_slice($tables['OrderLines'],1) as $index=>$line)if((string)molavi_domain_py_value($line[$column]??'')===$id)$deletes[]=$index+2;
    molavi_domain_order_sheet_delete('OrderLines',$deletes);
    if($records['lines'])molavi_domain_sheet_append('OrderLines',array_slice(molavi_domain_rows_from_records($lineHeaders,$records['lines']),1));
    $verified=molavi_domain_sheets(['Orders','OrderLines'],true,true);$row=molavi_domain_order_sheet_find($verified['Orders'],$id);
    if(!$row)molavi_domain_fail('order_sheet_receipt_missing',503);$current=molavi_domain_records($verified['Orders'])[$row-2];
    foreach($records['order'] as $field=>$value)if((string)($current[$field]??'')!==(string)$value)molavi_domain_fail('order_sheet_receipt_mismatch',503);
    $actual=[];foreach(molavi_domain_records($verified['OrderLines']) as $line)if((string)molavi_domain_py_value($line['Order ID']??'')===$id)$actual[]=$line;
    if(count($actual)!==count($records['lines']))molavi_domain_fail('order_lines_receipt_mismatch',503);
    foreach($records['lines'] as $index=>$expected)foreach($expected as $field=>$value)if((string)($actual[$index][$field]??'')!==(string)$value)molavi_domain_fail('order_lines_receipt_mismatch',503);
    return ['written'=>true,'order_id'=>$id,'lines'=>count($records['lines'])];
}
function molavi_domain_order_job(array $payload): array {
    $order=$payload['order']??null;if(!is_array($order))molavi_domain_fail('invalid_order_job',400);
    return molavi_domain_lock('publication',static function()use($order,$payload):array {
        $result=molavi_domain_order_upsert($order);$payment=strtolower((string)molavi_domain_py_value($order['payment_status']??''));$terminal=strtolower((string)molavi_domain_py_value($order['woo_status']??''));
        if(in_array($payment,['verified','refunded'],true)||in_array($terminal,['cancelled','refunded','failed'],true)){
            $result['fulfillment']=molavi_domain_order_plan($order,molavi_domain_today());
            if($payment==='verified')molavi_domain_order_workflow_ensure((string)molavi_domain_py_value($order['id']??''),$result['fulfillment'],molavi_domain_today());
        }
        if(!empty($payload['notify'])){$key=hash('sha256',molavi_domain_json((object)$payload,true));
            molavi_domain_queue('notice','order-dispatch:'.$key,['type'=>'order_notice','order'=>$order,'dispatch_key'=>$key]);}
        molavi_domain_order_acknowledge($payload);
        return $result;
    });
}
function molavi_domain_order_handoff(array $body): array {
    $order=$body['order']??null;$id=is_array($order)?(string)molavi_domain_py_value($order['id']??''):'';
    if(!is_array($order)||trim($id)==='')return ['code'=>400,'body'=>['status'=>'error','message'=>'missing order']];
    $notify=molavi_domain_py_value($body['notify']??null,null)!==null;$payload=['order'=>$order,'notify'=>$notify];
    $key=hash('sha256',molavi_domain_json($payload,true));$job=molavi_domain_queue('order',$key,$payload);
    // Checkout only persists its task. The existing minute worker performs Sheets I/O.
    $saved=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT state FROM jobs WHERE id=?',[$job]));$written=($saved['state']??null)==='done';
    return ['code'=>$written?200:503,'body'=>['status'=>$written?'success':'pending','written'=>$written,'order_id'=>$id,'receipt_id'=>$job,
        'notified'=>0,'notifications'=>$notify?'queued':'not_requested']];
}
function molavi_domain_order_acknowledge(array $payload): void {
    $id=(string)($payload['order']['id']??'');if(!preg_match('/^[1-9][0-9]*$/D',$id)||!function_exists('wc_get_order'))return;
    $order=wc_get_order((int)$id);if(!$order)return;$digest=hash('sha256',molavi_domain_json((object)$payload,true));
    $pending=$order->get_meta('_molavi_paid_handoff_pending_payload');$key=(string)$order->get_meta('_molavi_paid_handoff_pending_key');
    if($key!==''&&is_array($pending)&&hash_equals($digest,hash('sha256',molavi_domain_json((object)$pending,true)))){
        if(!function_exists('molavi_mark_paid_handoff_complete'))molavi_domain_fail('native_order_receipt_unavailable',503);
        molavi_mark_paid_handoff_complete($order,$key,$payload);return;
    }
    $saved=$order->get_meta('_molavi_handoff_payload');
    if(is_array($saved)&&hash_equals($digest,hash('sha256',molavi_domain_json((object)$saved,true)))){
        $order->update_meta_data('_molavi_published','1');$order->update_meta_data('_molavi_handoff_status','written');$order->save();
    }
}
add_filter('pre_http_request',static function($pre,array $args,string $url){
    if(!function_exists('molavi_legacy_config')||strtoupper((string)($args['method']??'GET'))!=='POST'||!get_option('molavi_state_active',false))return $pre;
    $config=molavi_legacy_config();$base=rtrim((string)($config['bot_url']??''),'/');$secret=(string)($config['notify_secret']??'');
    if($secret===''||$url!==$base.'/shop/order'||molavi_domain_job_host('order')!=='wordpress')return $pre;
    $headers=[];foreach($args['headers']??[] as $key=>$value)$headers[strtolower((string)$key)]=$value;
    if(!hash_equals($secret,(string)($headers['x-shop-token']??'')))return $pre;
    $body=json_decode((string)($args['body']??''),true);
    try{$result=is_array($body)?molavi_domain_order_handoff($body):['code'=>400,'body'=>['status'=>'error','message'=>'missing order']];}
    catch(Throwable $e){$result=['code'=>503,'body'=>['status'=>'pending','written'=>false,'error'=>'native_order_pending']];}
    return ['headers'=>['content-type'=>'application/json; charset=utf-8','cache-control'=>'no-store','x-molavi-executor'=>'wordpress'],
        'body'=>wp_json_encode($result['body']),'response'=>['code'=>$result['code'],'message'=>get_status_header_desc($result['code'])],'cookies'=>[],'filename'=>null];
},1001,3);
