<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_BRIDGE_TOKEN',str_repeat('s',40));
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-domain-parity-'.bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_OWNER_IDS',[7]);
$GLOBALS['options']=['molavi_state_active'=>true];function add_action(...$args){}function add_filter(...$args){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-state-host.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-core.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-schemas.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-inventory.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-orders.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-read-models.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-gateway.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-parser.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-store.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-products.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-supplier-prices.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-outbox.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-publisher.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-jobs.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-fetch.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-workflow.php';
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-reference-scheduler.php';
$root=molavi_state_root();$initial=new SQLite3($root.'/pipeline.sqlite3');$initial->exec('PRAGMA user_version=1');$initial->close();
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/native-domain-parity.json'),true,128,JSON_THROW_ON_ERROR);$count=0;
function compare_native($actual,$expected,string $name):void {
    global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected)){
        echo molavi_domain_json(['case'=>$name,'actual'=>$actual,'expected'=>$expected])."\n";
        throw new RuntimeException('Native domain parity failed: '.$name);
    }$count++;
}
foreach($fixtures['inventory'] as $case){
    $db=molavi_domain_db();foreach(['stock_allocations','stock_reservation_requests','stock_lot_events','stock_lot_receipt_audit','stock_lots'] as $table)$db->exec('DELETE FROM '.$table);$db->close();
    foreach($case['operations'] as $index=>$step){$a=$step['arguments'];try{
        switch($step['operation']){
            case 'receive':$actual=molavi_domain_receive_lot($a);break;
            case 'get_lot':$actual=molavi_domain_lot($a['lot_id']);break;
            case 'list_lots':$actual=molavi_domain_lots($a['today']);break;
            case 'apply_operator_event':$actual=molavi_domain_lot_action($a,$a['actor_id'],$a['today'],$a['created_at']);break;
            case 'reserve':$actual=molavi_domain_reserve($a['order_id'],$a['sku'],$a['units'],$a['today']);break;
            case 'release_order':$actual=molavi_domain_release_order($a['order_id']);break;
            case 'record_receipt':molavi_domain_receipt_audit($a['lot_id'],$a['actor_id'],$a['note'],$a['created_at']);$actual=null;break;
            case 'add_missing_purchase_date':$actual=molavi_domain_lot_purchase_date($a['lot_id'],$a['expected_receipt_hash'],$a['purchase_date']);break;
            case 'change_reservation':$actual=molavi_domain_reservation_state($a['order_id'],$a['sku'],$a['from_state'],$a['to_state']);break;
            default:throw new RuntimeException('Unknown fixture operation');
        }
    }catch(MolaviWorkflowError|TypeError $e){$actual=['error'=>true];}
    compare_native($actual,$step['expected'],$case['name'].'/'.$index);}
}
foreach($fixtures['clearance'] as $index=>$case)compare_native(molavi_domain_clearance($case['arguments']),$case['expected'],'clearance/'.$index);
foreach($fixtures['packs'] as $index=>$case)compare_native(molavi_domain_plan_packs(...$case['arguments']),$case['expected'],'packs/'.$index);
$planning=json_decode(file_get_contents(__DIR__.'/fixtures/native-paid-plan-parity.json'),true,128,JSON_THROW_ON_ERROR);
$db=molavi_domain_db();foreach(['stock_allocations','stock_reservation_requests','stock_lot_events','stock_lot_receipt_audit','stock_lots','paid_order_plans','paid_order_decisions'] as $table)$db->exec('DELETE FROM '.$table);$db->close();
molavi_domain_receive_lot($planning['lot']);
foreach($planning['steps'] as $index=>$step){$a=$step['arguments'];try{
    $actual=molavi_domain_order_plan($a['order'],$a['today'],$a['supplier_terms']??[],$a['actor_id']??'system',$a['note']??'');
}catch(MolaviWorkflowError|TypeError $e){$actual=['error'=>true];}
compare_native($actual,$step['expected'],'paid-plan/'.$index);}
$reads=json_decode(file_get_contents(__DIR__.'/fixtures/native-read-parity.json'),true,128,JSON_THROW_ON_ERROR);
$initial=new SQLite3($root.'/reference.sqlite3');$initial->exec('PRAGMA user_version=1');$initial->close();
foreach($reads['seed'] as $store=>$seeds){$db=molavi_domain_db($store);
    if($store==='pipeline')$db->exec('DELETE FROM jobs');
    foreach($seeds as $seed)molavi_domain_sql($db,$seed['sql'],$seed['params']);$db->close();}
foreach($reads['operations'] as $index=>$case)compare_native(molavi_domain_operations($case['query'],1000.0),$case['expected'],'operations/'.$index);
foreach($reads['reference'] as $index=>$case)compare_native(molavi_domain_reference_snapshots($case['query']),$case['expected'],'references/'.$index);
compare_native(molavi_domain_dispatch('GET','/pipeline/stock-lots',[],null,'wp:99')['status'],403,'gateway owner guard');
compare_native(molavi_domain_dispatch('PUT','/reference/snapshots',[],[], 'wp:7')['status'],404,'gateway method guard');
compare_native(molavi_domain_dispatch('POST','/pipeline/stock-lots',[],['lot_id'=>'synthetic'], 'wp:7')['status'],400,'gateway strict body');
try{molavi_domain_operations(['cursor'=>'invalid'],1000.0);$invalid=false;}catch(MolaviWorkflowError $e){$invalid=$e->status===400;}
compare_native($invalid,true,'gateway cursor guard');
$parser=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-parser-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($parser['rows'] as $index=>$case)compare_native(molavi_domain_reference_row($case['product'],$index+1,'2026-11-17T00:00:00+00:00'),$case['expected'],'reference parser/'.$index);
foreach($parser['float_json'] as $index=>$case)compare_native(molavi_domain_json($case['value']),$case['expected'],'hash float encoding/'.$index);
$incremental=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-store-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($incremental as $case){$db=molavi_domain_db('reference');
    foreach(molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%'") as $table)$db->exec('DELETE FROM '.$table['name']);
    foreach($case['seed'] as $seed)molavi_domain_sql($db,$seed['sql'],$seed['params']);$db->close();
    compare_native(molavi_domain_reference_stage($case['candidate']),$case['expected'],'incremental reference/'.$case['name']);
    if($case['expected']['state']==='staged'){
        $id=$case['expected']['snapshot_id'];$previous=molavi_domain_reference_active()['snapshot_id']??null;$automatic=$case['expected']['automatic_update'];
        if(!$automatic)molavi_domain_reference_review($id,'wp:7','Synthetic owner review');
        molavi_domain_reference_activate($id,$previous,$automatic?'system:reference-refresh':'wp:7',$automatic);
        compare_native(molavi_domain_reference_active()['snapshot_id'],$id,'reference activation/'.$case['name']);
        compare_native(molavi_domain_read('reference',static fn($db)=>molavi_domain_row($db,'SELECT COUNT(*) AS n FROM reference_source_products')['n']),count($case['candidate']['_source_products']),'raw custody/'.$case['name']);
    }
}
$products=json_decode(file_get_contents(__DIR__.'/fixtures/native-product-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($products['records'] as $index=>$record){
    compare_native(molavi_domain_product_identity($record),$products['identities'][$index],'product identity/'.$index);
    compare_native(molavi_domain_source_revision($record),$products['revisions'][$index],'source price revision/'.$index);
}
compare_native(molavi_domain_source_issues($products['records'],$products['offers']),$products['source_issues'],'source provenance');
$db=molavi_domain_db();$db->exec('DELETE FROM supplier_price_evidence');$db->exec('DELETE FROM supplier_price_evidence_history');$db->close();
compare_native(molavi_domain_freshness($products['records'],(float)$products['clock']),$products['freshness'],'supplier freshness');
compare_native(molavi_domain_current_products($products['tables'],(float)$products['clock']),$products['current'],'current product status');
foreach($products['states'] as $index=>$case)compare_native(molavi_domain_product_state($case['record'],$case['evidence'],$case['issues']),$case['expected'],'product state/'.$index);
$db=molavi_domain_db();$db->exec('DELETE FROM product_status_records');$db->exec('DELETE FROM product_status_snapshots');$db->close();
compare_native(molavi_domain_status_capture($products['tables'],'synthetic-native-parity',(float)$products['clock']),$products['capture'],'status capture digest');
compare_native(molavi_domain_status_history(),$products['history'],'status history');
$supplier=json_decode(file_get_contents(__DIR__.'/fixtures/native-supplier-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($supplier['revisions'] as $index=>$case)compare_native(molavi_domain_source_revision($case['record']),$case['expected'],'source decimal precision/'.$index);
foreach($supplier['verification'] as $index=>$case){$a=$case['arguments'];try{
    $actual=molavi_domain_verify_price($a['offer_id'],$a['source_revision'],$a['actor_id'],$a['note'],$a['now'],$a['command_id']);
}catch(MolaviWorkflowError $e){$actual=['error'=>true];}
compare_native($actual,$case['expected'],'supplier verification/'.$index);}
$q=$supplier['queue'];compare_native(molavi_domain_queue($q['kind'],$q['key'],$q['payload']),$q['expected_id'],'shared queue identity');
compare_native(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT payload FROM jobs WHERE id=?',[$q['expected_id']])['payload']),$q['expected_payload'],'shared queue encoding');
class NativeOutboxDB {function prepare($sql,...$args){return $sql;}function get_var($sql){return $GLOBALS['outbox_actor']??7;}}
function molavi_workflow_decisions_table(){return 'synthetic_decisions';}
function molavi_workflow_outbox_table(){return 'synthetic_outbox';}
function molavi_owner_review_store_pending_commands($limit,$domains){$GLOBALS['claimed_domains']=$domains;
    if(!empty($GLOBALS['claim_unavailable']))return null;$item=array_shift($GLOBALS['outbox_commands']);return $item?[$item]:[];}
function molavi_owner_review_outbox_store_receipt($input){$GLOBALS['outbox_receipts'][]=$input;return empty($GLOBALS['receipt_reject']);}
$GLOBALS['wpdb']=new NativeOutboxDB();$GLOBALS['options']['molavi_native_routes_v1']=['POST /pipeline/supplier-price-evidence'];
$command=['command_id'=>str_repeat('c',64),'command_type'=>'supplier_price','event_id'=>'synthetic-event',
    'payload'=>['product_id'=>'synthetic-supplier-product','expected_revision'=>'synthetic-revision','note'=>'Synthetic verification','command_id'=>str_repeat('c',64)]];
$GLOBALS['outbox_commands']=[$command];$GLOBALS['outbox_receipts']=[];
compare_native(molavi_domain_drain_outbox(),['claimed'=>1,'delivered'=>1,'retried'=>0,'failed'=>0,'error'=>''],'native outbox existing command replay');
compare_native($GLOBALS['claimed_domains'],['supplier_price'],'native outbox leaves unsupported domains');
compare_native($GLOBALS['outbox_receipts'][0]['receipt']['idempotent'],true,'native outbox keeps prior verification receipt');
$GLOBALS['outbox_actor']=99;$GLOBALS['outbox_commands']=[$command];
compare_native(molavi_domain_drain_outbox()['failed'],1,'native outbox respects recorded owner');
$GLOBALS['outbox_actor']=7;$GLOBALS['receipt_reject']=true;$GLOBALS['outbox_commands']=[$command];
compare_native(molavi_domain_drain_outbox(),['claimed'=>1,'delivered'=>0,'retried'=>0,'failed'=>0,'error'=>'outbox_receipt_rejected'],'native outbox requires durable acknowledgement');
$publication=json_decode(file_get_contents(__DIR__.'/fixtures/native-publication-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($publication['scenarios'] as $case)compare_native(molavi_domain_site_products($case['tables'],$case['freshness'],$case['conflicts']),$case['expected'],'publication/'.$case['name']);
foreach($publication['settings'] as $index=>$case)compare_native(molavi_domain_site_settings($case['rows']),$case['expected'],'shop settings/'.$index);
foreach($publication['stock'] as $index=>$case)compare_native(molavi_domain_stock_status($case['raw']),$case['expected'],'supplier availability/'.$index);
foreach($publication['skus'] as $index=>$case)compare_native(molavi_domain_woo_sku($case['record']),$case['expected'],'published SKU/'.$index);
function molavi_apply_catalog($payload){$GLOBALS['native_catalog_calls'][]=$payload;return ['ok'=>true,'images_left'=>0,'pending'=>[],'attached'=>0];}
function molavi_receive_images($payload){$GLOBALS['native_image_calls'][]=$payload;return ['ok'=>true,'images_left'=>0,'pending'=>[],'attached'=>0];}
$GLOBALS['native_catalog_calls']=[];$GLOBALS['native_image_calls']=[];
$db=molavi_domain_db();foreach(['jobs','inbox','pipeline_workers','molavi_state_executors','sheet_projection_state','publication_holds'] as $table)$db->exec('DELETE FROM '.$table);$db->close();
compare_native(molavi_domain_job_host('publish'),'railway','default queue executor is preserved');
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,"INSERT INTO molavi_state_executors VALUES ('publish','wordpress',0)"));
$pub=molavi_domain_queue('publish','synthetic-native-job',['images_only'=>true]);$bot=molavi_domain_queue('notice','synthetic-bot-job',['synthetic'=>true]);
$job=molavi_domain_claim_job($pub);compare_native($job['id'],$pub,'native job lease claims assigned work');
compare_native(molavi_domain_claim_job($bot),null,'native executor never claims bot work');
try{$stale=$job;$stale['lease']=str_repeat('x',32);molavi_domain_finish_job($stale,['ok'=>true]);$rejected=false;}catch(MolaviWorkflowError $e){$rejected=true;}
compare_native($rejected,true,'job receipt rejects another lease');
molavi_domain_finish_job($job,['ok'=>true]);
compare_native(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT state FROM jobs WHERE id=?',[$pub])['state']),'done','native lease receipt commits once');
molavi_domain_tx('pipeline',static function($db)use($pub,$bot){molavi_domain_sql($db,"UPDATE jobs SET state='running',expires=0 WHERE id=?",[$pub]);molavi_domain_sql($db,'UPDATE jobs SET available=0 WHERE id=?',[$bot]);molavi_domain_sql($db,"INSERT INTO pipeline_workers (worker_id,started_at,heartbeat_at,progress_at,state) VALUES ('wordpress:synthetic',?,?,?,'processing')",[microtime(true),microtime(true),microtime(true)]);});
compare_native(molavi_state_due_work(),true,'local worker does not suppress a pending bot wake');
$reclaimed=molavi_domain_claim_job($pub);compare_native($reclaimed['id'],$pub,'native executor recovers expired native lease');molavi_domain_finish_job($reclaimed,['ok'=>true]);
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'DELETE FROM jobs WHERE id=?',[$bot]));
$onlyNative=molavi_domain_queue('publish','only-native-pending',['images_only'=>true]);molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'UPDATE jobs SET available=0 WHERE id=?',[$onlyNative]));compare_native(molavi_state_due_work(),false,'native pending jobs do not wake Railway');
compare_native(molavi_domain_run_jobs()['completed'],1,'native job runs local image workflow');
compare_native(count($GLOBALS['native_catalog_calls']),0,'image-only work never rewrites catalog');
$workerCount=molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT COUNT(*) AS n FROM pipeline_workers')['n']);molavi_domain_run_jobs();
compare_native(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT COUNT(*) AS n FROM pipeline_workers')['n']),$workerCount,'idle minute creates no worker record');
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'INSERT INTO publication_holds VALUES (?,?,?)',['synthetic-held','synthetic-review',microtime(true)]));
compare_native(molavi_domain_publish_shop([],true),['ok'=>false,'pushed'=>false,'reason'=>'publication_held_for_review'],'native publisher preserves review holds');
$heldJob=molavi_domain_queue('publish','synthetic-resume-after-hold',['images_only'=>true]);molavi_domain_run_jobs();
compare_native(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT state FROM jobs WHERE id=?',[$heldJob])['state']),'waiting_publication','held publication remains parked');
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'DELETE FROM publication_holds'));
compare_native(molavi_domain_run_jobs()['completed'],1,'native worker resumes work after the hold was cleared');
$first=molavi_domain_observe_projection(str_repeat('a',64));compare_native(molavi_domain_observe_projection(str_repeat('a',64)),$first,'unchanged sheet reuses publication receipt');
compare_native(molavi_domain_observe_projection(str_repeat('b',64)),$first,'new sheet replaces queued payload without extra job');
$running=molavi_domain_claim_job($first);$next=molavi_domain_observe_projection(str_repeat('c',64));compare_native($next!==$first,true,'changed sheet queues separately from active lease');
molavi_domain_projection_published(str_repeat('c',64));
compare_native(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT state FROM jobs WHERE id=?',[$next])['state']),'done','verified projection closes superseded queued job');
$sourcePages=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-fetch-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($sourcePages as $case){try{$actual=molavi_domain_reference_page(['status'=>$case['status'],'headers'=>$case['headers'],'body'=>$case['body']],$case['page'],$case['size'],$case['total'],$case['pages']);}
    catch(MolaviWorkflowError $e){$actual=['error'=>true];}
    compare_native($actual,$case['expected'],'reference page/'.$case['name']);}
$referenceWorkflow=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-workflow-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($referenceWorkflow['inherit'] as $case){$db=molavi_domain_db('reference');
    foreach(molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%'") as $table)$db->exec('DELETE FROM '.$table['name']);
    foreach($case['seed'] as $seed)molavi_domain_sql($db,$seed['sql'],$seed['params']);$db->close();
    compare_native(molavi_domain_inherit_reference_mappings($case['target'],$referenceWorkflow['clock']),$case['expected'],'reference inheritance/'.$case['name']);}
foreach($referenceWorkflow['product_changes'] as $case)compare_native(molavi_domain_reference_product_changes($case['products'],$case['previous'],$case['next'],[],$case['affected'],$referenceWorkflow['clock']),$case['expected'],'reference approval changes/'.$case['name']);
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native domain parity '.$count." cases\n";
