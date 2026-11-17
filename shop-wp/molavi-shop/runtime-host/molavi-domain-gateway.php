<?php
/** Plugin Name: Molavi Native Workflow Gateway */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** A route is switched only after its native handler and custody checks pass. */
function molavi_domain_routes(): array {
    return [
        '/pipeline/operations-dashboard'=>['GET'],
        '/pipeline/paid-orders'=>['GET','POST'],
        '/pipeline/publication-holds'=>['GET','POST'],
        '/pipeline/reconcile-publication-backlog'=>['POST'],
        '/pipeline/reconciliation'=>['GET'],
        '/pipeline/product-status-snapshots'=>['GET','POST'],
        '/pipeline/supplier-price-evidence'=>['GET','POST'],
        '/pipeline/sheet-conflicts'=>['GET','POST'],
        '/pipeline/ai-confirmations'=>['GET','POST'],
        '/reference/snapshots'=>['GET','POST'],
        '/reference/decisions'=>['GET','POST'],
        '/reference/item-reviews'=>['GET','POST'],
        '/pipeline/stock-lots'=>['GET','POST'],
        '/pipeline/stock-lots/purchase-date'=>['POST'],
        '/pipeline/stock-lot-actions'=>['GET','POST'],
        '/pipeline/clearance-proposals'=>['GET','POST'],
        '/pipeline/telegram-operator-chats'=>['POST'],
    ];
}
function molavi_domain_route_enabled(string $method,string $path): bool {
    $active=get_option('molavi_native_routes_v1',[]);
    return in_array($method,molavi_domain_routes()[$path]??[],true)
        &&is_array($active)&&in_array($method.' '.$path,$active,true);
}
function molavi_domain_body_keys(array $body,array $keys): void {
    $actual=array_keys($body);sort($actual);sort($keys);
    if($actual!==$keys)molavi_domain_fail('invalid_body',400);
}
function molavi_domain_required_text(array $body,string $key,int $limit,bool $empty=false): string {
    $value=$body[$key]??null;
    if(!is_string($value)||(!$empty&&trim($value)==='')||molavi_domain_strlen($value)>$limit)molavi_domain_fail('invalid_body',400);
    return $value;
}
function molavi_domain_required_hash(array $body,string $key): string {
    $value=molavi_domain_required_text($body,$key,64);
    if(!preg_match('/^[a-f0-9]{64}$/D',$value))molavi_domain_fail('invalid_body',400);return $value;
}
function molavi_domain_price(array $body,string $key,int $max=1000000000000): int {
    $value=$body[$key]??null;if(!is_int($value)||$value<1||$value>$max)molavi_domain_fail('invalid_body',400);return $value;
}
function molavi_domain_owner_actor(string $actor): bool {
    $owners=defined('MOLAVI_REFERENCE_OWNER_IDS')?MOLAVI_REFERENCE_OWNER_IDS:[];
    return is_array($owners)&&preg_match('/^wp:([1-9][0-9]*)$/D',$actor,$match)
        &&in_array((int)$match[1],$owners,true);
}
function molavi_domain_enrollment(array $body,string $actor): array {
    molavi_domain_body_keys($body,['action','chat_id','expected_revision']);
    if(!in_array($body['action']??null,['approve','reject'],true)
        ||!is_string($body['chat_id']??null)||!preg_match('/^[0-9]{1,20}$/D',$body['chat_id'])
        ||!is_int($body['expected_revision']??null)||$body['expected_revision']<1)molavi_domain_fail('invalid_body',400);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($body,$actor):array {
        $id=$body['chat_id'];$state=$body['action']==='approve'?'approved':'rejected';$revision=$body['expected_revision'];
        $row=molavi_domain_row($db,'SELECT revision,state,decided_by,decided_at FROM pending_telegram_chats WHERE chat_id=?',[$id]);
        if($row&&$row['state']===$state&&$row['revision']===$revision&&$row['decided_by']===$actor)
            return ['ok'=>true,'chat_id'=>$id,'state'=>$state,'revision'=>$revision,'decided_at'=>$row['decided_at'],'already_done'=>true];
        if(!$row||$row['state']!=='pending'||$row['revision']!==$revision)molavi_domain_fail('telegram_account_changed');
        $now=microtime(true);molavi_domain_sql($db,"UPDATE pending_telegram_chats SET state=?,decided_at=?,decided_by=? WHERE chat_id=? AND state='pending' AND revision=?",[$state,$now,$actor,$id,$revision]);
        return ['ok'=>true,'chat_id'=>$id,'state'=>$state,'revision'=>$revision,'decided_at'=>$now,'already_done'=>false];
    });
}
function molavi_domain_inventory_api(string $method,string $path,array $body,string $actor): array {
    $today=molavi_domain_today();$now=molavi_domain_now();
    if($path==='/pipeline/stock-lots') {
        if($method==='GET'){$fields=array_flip(['lot_id','sku','supplier','purchase_date','received_at','production_date','expiry_date','units','received_units','adjustment_units','unit_cost','available_units','committed_units','state','receipt_hash','revision']);
            return ['ok'=>true,'lots'=>array_map(static fn($row)=>array_intersect_key($row,$fields),molavi_domain_lots($today))];}
        molavi_domain_body_keys($body,['lot_id','sku','supplier','purchase_date','received_at','production_date','expiry_date','units','unit_cost','note']);
        $data=[];foreach(['lot_id'=>120,'sku'=>80,'supplier'=>120] as $key=>$limit)$data[$key]=trim(molavi_domain_required_text($body,$key,$limit));
        $note=molavi_domain_required_text($body,'note',1000);
        foreach(['purchase_date','received_at','production_date','expiry_date'] as $key){$value=molavi_domain_required_text($body,$key,10,$key!=='received_at');
            if($value!=='')molavi_domain_date($value);$data[$key]=$value===''?null:$value;}
        $data['units']=molavi_domain_price($body,'units',1000000);$data['unit_cost']=molavi_domain_price($body,'unit_cost');
        $state=molavi_domain_receive_lot($data);molavi_domain_receipt_audit($data['lot_id'],$actor,$note,$now);
        return ['ok'=>true,'lot_id'=>$data['lot_id'],'state'=>$state,'receipt_hash'=>molavi_domain_lot($data['lot_id'])['receipt_hash']];
    }
    if($path==='/pipeline/stock-lots/purchase-date'){
        molavi_domain_body_keys($body,['lot_id','receipt_hash','purchase_date','note']);
        $id=trim(molavi_domain_required_text($body,'lot_id',120));$hash=molavi_domain_required_hash($body,'receipt_hash');
        $date=molavi_domain_required_text($body,'purchase_date',10);$note=molavi_domain_required_text($body,'note',1000);molavi_domain_date($date);
        $hash=molavi_domain_lot_purchase_date($id,$hash,$date);molavi_domain_receipt_audit($id,$actor,$note,$now);
        return ['ok'=>true,'lot_id'=>$id,'state'=>molavi_domain_lot($id)['state'],'receipt_hash'=>$hash];
    }
    if($path==='/pipeline/stock-lot-actions'){
        if($method==='GET')return ['ok'=>true,'events'=>molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM stock_lot_events ORDER BY sequence DESC LIMIT 100'))];
        molavi_domain_body_keys($body,['lot_id','expected_revision','action','delta_units','reason','note']);
        $body['lot_id']=trim(molavi_domain_required_text($body,'lot_id',120));molavi_domain_required_hash($body,'expected_revision');
        molavi_domain_required_text($body,'action',50);molavi_domain_required_text($body,'reason',1000,true);molavi_domain_required_text($body,'note',1000);
        if(!is_int($body['delta_units']))molavi_domain_fail('invalid_body',400);
        $saved=molavi_domain_lot_action($body,$actor,$today,$now);
        return array_merge(['ok'=>true,'lot_id'=>$body['lot_id'],'receipt_hash'=>molavi_domain_lot($body['lot_id'])['receipt_hash']],
            array_intersect_key($saved,array_flip(['state','event_id','revision','previous_revision','action','units','idempotent'])));
    }
    if($path==='/pipeline/clearance-proposals')return molavi_domain_clearance_api($method,$body,$actor,$today,$now);
    molavi_domain_fail('workflow_route_unavailable',404);
}
function molavi_domain_clearance_api(string $method,array $body,string $actor,string $today,string $now): array {
    $threshold=get_option('molavi_clearance_near_cost_threshold',null);
    if($threshold!==null){$value=filter_var($threshold,FILTER_VALIDATE_INT);if($value===false||$value<0||$value>1000000000000)molavi_domain_fail('invalid_clearance_threshold',503);$threshold=$value;}
    if($method==='GET'){
        $proposals=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM clearance_proposals ORDER BY created_at DESC,proposal_id LIMIT 100'));
        foreach($proposals as &$row){$row['inputs']=molavi_domain_decode($row['inputs']);$row['result']=molavi_domain_decode($row['result']);}unset($row);
        return ['ok'=>true,'today'=>$today,'near_cost_alert_threshold_toman'=>$threshold===null?null:(string)$threshold,'lots'=>molavi_domain_lots($today),'proposals'=>$proposals];
    }
    molavi_domain_body_keys($body,['lot_id','receipt_hash','normal_price_toman','market_price_toman','market_source','market_observed_date','note']);
    $id=trim(molavi_domain_required_text($body,'lot_id',120));$hash=molavi_domain_required_hash($body,'receipt_hash');
    $normal=molavi_domain_price($body,'normal_price_toman');$market=molavi_domain_price($body,'market_price_toman');
    $source=molavi_domain_required_text($body,'market_source',200);$date=molavi_domain_required_text($body,'market_observed_date',10);
    $note=molavi_domain_required_text($body,'note',1000);molavi_domain_date($date);
    $lot=molavi_domain_lot($id);if(!$lot||$lot['receipt_hash']!==$hash)molavi_domain_fail('lot_changed');
    $current=null;foreach(molavi_domain_lots($today) as $row)if($row['lot_id']===$id)$current=$row;
    if(!$current||$current['state']!=='ready'||$current['available_units']<=0)molavi_domain_fail('lot_not_eligible');
    if(empty($lot['purchase_date']))molavi_domain_fail('purchase_date_required');
    $result=molavi_domain_clearance(['purchase_date'=>$lot['purchase_date'],'production_date'=>$lot['production_date'],
        'expiry_date'=>$lot['expiry_date'],'today'=>$today,'purchase_cost'=>$lot['unit_cost'],'normal_price'=>$normal,
        'market_price'=>$market,'market_source'=>trim($source),'market_observed_date'=>$date,'near_cost_threshold_toman'=>$threshold]);
    $inputs=$body;unset($inputs['note']);$inputs+=['purchase_date'=>$lot['purchase_date'],'production_date'=>$lot['production_date'],
        'expiry_date'=>$lot['expiry_date'],'purchase_cost_toman'=>$lot['unit_cost'],'available_units'=>$current['available_units']];
    $identity=['lot_id'=>$id,'sku'=>$lot['sku'],'receipt_hash'=>$hash,'actor_id'=>$actor,'note'=>trim($note),'inputs'=>$inputs,'result'=>$result];
    $proposal=molavi_domain_hash($identity);
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,'INSERT OR IGNORE INTO clearance_proposals VALUES (?,?,?,?,?,?,?,?,?)',
        [$proposal,$id,$lot['sku'],$hash,$actor,trim($note),molavi_domain_json($inputs,true),molavi_domain_json($result,true),$now]));
    return ['ok'=>true,'proposal_id'=>$proposal,'lot_id'=>$id,'state'=>$lot['state'],'receipt_hash'=>$hash,'result'=>$result];
}
function molavi_domain_dispatch(string $method,string $path,array $query,?array $body,string $actor): array {
    try {
        if(!molavi_domain_owner_actor($actor))molavi_domain_fail('owner_required',403);
        if(!get_option('molavi_state_active',false))molavi_domain_fail('native_state_inactive',409);
        if(!in_array($method,molavi_domain_routes()[$path]??[],true))molavi_domain_fail('workflow_route_unavailable',404);
        if($method==='POST'&&$body===null)molavi_domain_fail('invalid_body',400);
        if($path==='/pipeline/operations-dashboard')$result=molavi_domain_operations($query);
        elseif($path==='/pipeline/paid-orders')$result=molavi_domain_paid_orders_api($method,$body??[],$actor);
        elseif($path==='/pipeline/publication-holds')$result=molavi_domain_publication_holds_api($method,$body??[],$actor);
        elseif($path==='/pipeline/reconcile-publication-backlog')$result=molavi_domain_reconcile_backlog_api($body,$actor);
        elseif($path==='/pipeline/reconciliation')$result=molavi_domain_reconciliation_api($query);
        elseif($path==='/pipeline/product-status-snapshots')$result=molavi_domain_status_api($method,$query,$body,$actor);
        elseif($path==='/pipeline/supplier-price-evidence')$result=molavi_domain_supplier_prices_api($method,$body,$actor);
        elseif($path==='/pipeline/sheet-conflicts')$result=molavi_domain_sheet_conflicts_api($method,$body,$actor);
        elseif($path==='/pipeline/ai-confirmations')$result=molavi_domain_ai_reviews_api($method,$query,$body,$actor);
        elseif($path==='/reference/snapshots')$result=$method==='POST'?molavi_domain_reference_api($body,$actor):molavi_domain_reference_snapshots($query);
        elseif($path==='/reference/item-reviews')$result=molavi_domain_reference_reviews_api($method,$body,$actor);
        elseif($path==='/reference/decisions')$result=$method==='POST'?molavi_domain_reference_decision_api($body,$actor):['ok'=>true,'events'=>molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,
            'SELECT * FROM reference_events e WHERE choice IS NULL AND NOT EXISTS (SELECT 1 FROM reference_retirements r WHERE r.event_id=e.event_id) ORDER BY created_at,event_id'))];
        elseif($path==='/pipeline/telegram-operator-chats')$result=molavi_domain_enrollment($body,$actor);
        else $result=molavi_domain_inventory_api($method,$path,$body??[],$actor);
        $status=$result['_http_status']??200;unset($result['_http_status']);return ['status'=>$status,'body'=>$result];
    }catch(MolaviWorkflowError $e){return ['status'=>$e->status,'body'=>array_merge(['ok'=>false,'error'=>$e->getMessage()],$e->details)];}
    catch(Throwable $e){error_log('[molavi-native] route='.$path.' code='.get_class($e));return ['status'=>503,'body'=>['ok'=>false,'error'=>'native_workflow_unavailable']];}
}
add_filter('pre_http_request',static function($pre,array $args,string $url){
    if(!defined('MOLAVI_REFERENCE_SERVER_URL')||!defined('MOLAVI_REFERENCE_BRIDGE_TOKEN'))return $pre;
    $base=rtrim(MOLAVI_REFERENCE_SERVER_URL,'/');if(!str_starts_with($url,$base.'/'))return $pre;
    $path=(string)wp_parse_url($url,PHP_URL_PATH);$method=strtoupper((string)($args['method']??'GET'));
    if(!molavi_domain_route_enabled($method,$path))return $pre;
    $headers=[];foreach($args['headers']??[] as $key=>$value)$headers[strtolower((string)$key)]=$value;
    if(!hash_equals(MOLAVI_REFERENCE_BRIDGE_TOKEN,(string)($headers['x-molavi-reference-token']??'')))return $pre;
    $query=[];parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query);
    $body=$method==='POST'?json_decode((string)($args['body']??''),true):null;
    $result=molavi_domain_dispatch($method,$path,$query,is_array($body)?$body:null,(string)($headers['x-molavi-actor']??''));
    return ['headers'=>['content-type'=>'application/json; charset=utf-8','cache-control'=>'no-store','x-molavi-executor'=>'wordpress'],
        'body'=>wp_json_encode($result['body']),'response'=>['code'=>$result['status'],'message'=>get_status_header_desc($result['status'])],
        'cookies'=>[],'filename'=>null];
},1000,3);
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/status',['methods'=>'GET','permission_callback'=>'molavi_runtime_permission','callback'=>static function(){
        return ['ok'=>true,'php_version'=>PHP_VERSION,'state_active'=>(bool)get_option('molavi_state_active',false),
            'unicode_capabilities'=>['normalizer'=>class_exists('Normalizer'),'casefold'=>function_exists('mb_convert_case')],
            'native_routes'=>get_option('molavi_native_routes_v1',[]),'available_routes'=>molavi_domain_routes()];}]);
    register_rest_route('molavi-workflow/v1','/configure',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission','callback'=>static function($request){
        $body=$request->get_json_params();$routes=$body['routes']??null;
        if(!is_array($routes)||!array_is_list($routes))return new WP_Error('invalid_routes','Invalid routes',['status'=>400]);
        if($routes&&(!get_option('molavi_state_active',false)||!class_exists('SQLite3')||PHP_VERSION_ID<80100))return new WP_Error('native_prerequisites','Native custody unavailable',['status'=>409]);
        if(array_intersect($routes,['GET /pipeline/paid-orders','POST /pipeline/paid-orders'])
            &&(!class_exists('Normalizer')||!function_exists('mb_convert_case')||!function_exists('molavi_band')||(new ReflectionFunction('molavi_band'))->getNumberOfParameters()<3))
            return new WP_Error('native_order_prerequisites','Native order dependencies unavailable',['status'=>409]);
        foreach($routes as $route){if(!is_string($route)||!preg_match('/^(GET|POST) (\/[^ ]+)$/D',$route,$match)
            ||!in_array($match[1],molavi_domain_routes()[$match[2]]??[],true))return new WP_Error('invalid_routes','Invalid routes',['status'=>400]);}
        $previous=get_option('molavi_native_routes_v1',[]);
        update_option('molavi_native_routes_v1',array_values(array_unique($routes)),false);
        if(in_array('POST /reference/snapshots',$routes,true)&&!in_array('POST /reference/snapshots',is_array($previous)?$previous:[],true)){
            $state=molavi_runtime_state();$state['reference_at']=time();molavi_runtime_save_state($state);
        }
        return ['ok'=>true,'native_routes'=>get_option('molavi_native_routes_v1',[])];}]);
    register_rest_route('molavi-workflow/v1','/request',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission','callback'=>static function($request){
        $body=$request->get_json_params();if(!is_array($body))return new WP_Error('invalid_body','Invalid body',['status'=>400]);
        $actor=(string)($body['actor']??'');$method=(string)($body['method']??'');$path=(string)($body['path']??'');
        $result=molavi_domain_dispatch($method,$path,is_array($body['query']??null)?$body['query']:[],is_array($body['body']??null)?$body['body']:null,$actor);
        return new WP_REST_Response($result['body'],$result['status'],['Cache-Control'=>'no-store','X-Molavi-Executor'=>'wordpress']);}]);
});
