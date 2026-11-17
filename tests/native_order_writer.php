<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-order-writer-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){$GLOBALS['filters'][$args[0]][]=$args[1];}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-inventory','domain-orders','domain-order-workflows','domain-order-writer','domain-jobs'] as $file)
 require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$count=0;function writer_same($actual,$expected,$name){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($name.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
function molavi_domain_sheet_column(int $number):string{$out='';while($number>0){$number--;$out=chr(65+$number%26).$out;$number=intdiv($number,26);}return $out;}
function molavi_domain_sheet_ensure($name,$headers){$existing=$GLOBALS['sheets'][$name][0]??[];foreach($headers as $header)if(!in_array($header,$existing,true))$existing[]=$header;$GLOBALS['sheets'][$name][0]=$existing;}
function molavi_domain_sheets($names,$fresh=false,$raw=false){$GLOBALS['sheet_reads']=($GLOBALS['sheet_reads']??0)+1;$out=[];foreach($names as $name)$out[$name]=$GLOBALS['sheets'][$name]??[];
 if(!empty($GLOBALS['corrupt_receipt'])&&$raw)$out['Orders'][1][10]=999;return $out;}
function molavi_domain_sheet_append($name,$rows){foreach($rows as $row)$GLOBALS['sheets'][$name][]=$row;return [];}
function molavi_domain_sheet_cells($name,$updates){foreach($updates as $update){preg_match('/^([A-Z]+)([0-9]+)/',$update['range'],$m);$column=0;foreach(str_split($m[1]) as $letter)$column=$column*26+ord($letter)-64;
 foreach($update['values'] as $offset=>$values)foreach($values as $index=>$value)$GLOBALS['sheets'][$name][(int)$m[2]+$offset-1][$column+$index-1]=$value;}}
function molavi_domain_sheet_properties(){return ['Orders'=>['sheetId'=>1],'OrderLines'=>['sheetId'=>2]];}
function molavi_domain_sheet_invalidate($name){}
function molavi_domain_google($method,$path,$body){foreach($body['requests'] as $request){$range=$request['deleteDimension']['range'];$name=$range['sheetId']===1?'Orders':'OrderLines';array_splice($GLOBALS['sheets'][$name],$range['startIndex'],$range['endIndex']-$range['startIndex']);}return [];}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/native-order-writer-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($cases as $index=>$case){$GLOBALS['sheets']=$case['before'];writer_same(molavi_domain_normalize_order($case['source']),$case['normalized'],$index.'/normalized');
 writer_same(molavi_domain_order_sheet_records($case['source'],$case['updated']),$case['records'],$index.'/records');
 writer_same(molavi_domain_order_upsert($case['source'],$case['updated']),$case['expected'],$index.'/write receipt');writer_same($GLOBALS['sheets'],$case['after'],$index.'/all Sheet cells');
 molavi_domain_order_upsert($case['source'],$case['updated']);writer_same($GLOBALS['sheets'],$case['after'],$index.'/retry creates no duplicate order or lines');}
$GLOBALS['corrupt_receipt']=true;try{molavi_domain_order_upsert($cases[0]['source'],$cases[0]['updated']);$rejected=false;}catch(MolaviWorkflowError $e){$rejected=$e->getMessage()==='order_sheet_receipt_mismatch';}writer_same($rejected,true,'unverified write cannot finish a job');$GLOBALS['corrupt_receipt']=false;
$root=molavi_state_root();$initial=new SQLite3($root.'/pipeline.sqlite3');$initial->close();$GLOBALS['sheets']=[];
$payload=['order'=>$cases[2]['source'],'notify'=>true];$result=molavi_domain_order_job($payload);
writer_same($result['written'],true,'native order job writes locally');writer_same($result['fulfillment']['purchase_authorized'],false,'native job never authorizes a supplier purchase');
$jobs=molavi_domain_read('pipeline',static fn($db)=>molavi_domain_rows($db,"SELECT id,payload FROM jobs WHERE kind='notice'"));writer_same(count($jobs),1,'only the bot notification is queued');
writer_same(molavi_domain_decode($jobs[0]['payload'])['type'],'order_notice','bot receives a transport dispatch task');
molavi_domain_order_job($payload);writer_same(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,"SELECT COUNT(*) AS n FROM jobs WHERE kind='notice'")['n']),1,'order retry reuses notification receipt');
class SyntheticReceiptOrder {public array $meta=[];public int $saves=0;public int $id=70001;
 function get_id(){return $this->id;}
 function get_meta($key){return $this->meta[$key]??'';}
 function update_meta_data($key,$value){$this->meta[$key]=$value;}
 function delete_meta_data($key){unset($this->meta[$key]);}
 function save(){$this->saves++;}}
function wc_get_order($id){return $GLOBALS['receipt_orders'][$id]??null;}
require __DIR__.'/../shop-wp/molavi-shop/includes/order-handoff.php';
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,"INSERT INTO molavi_state_executors VALUES ('order','wordpress',0)"));
$first=['order'=>array_merge($cases[0]['source'],['id'=>'70001']),'notify'=>true];$woo=new SyntheticReceiptOrder();$woo->meta['_molavi_handoff_payload']=$first;$GLOBALS['receipt_orders'][70001]=$woo;
$reads=$GLOBALS['sheet_reads'];$pending=molavi_domain_order_handoff($first);writer_same($pending['body']['written'],false,'checkout waits only for a durable queued receipt');
writer_same($GLOBALS['sheet_reads'],$reads,'checkout never waits for Sheets I/O');writer_same($woo->get_meta('_molavi_published'),'','enqueue does not acknowledge an unverified mirror');
writer_same(molavi_domain_run_jobs(1,40.0,$pending['body']['receipt_id'])['completed'],1,'minute worker drains the native order');
writer_same($woo->get_meta('_molavi_published'),'1','verified mirror acknowledges the original Woo receipt');writer_same(molavi_domain_order_handoff($first)['body']['written'],true,'confirmed handoff replay uses the durable receipt');
$paid=['order'=>array_merge($cases[2]['source'],['id'=>'70001']),'notify'=>false];$woo->meta['_molavi_paid_handoff_pending_key']='synthetic-event-key';$woo->meta['_molavi_paid_handoff_pending_payload']=$paid;
$pending=molavi_domain_order_handoff($paid);molavi_domain_run_jobs(1,40.0,$pending['body']['receipt_id']);
writer_same($woo->get_meta('_molavi_paid_handoff_complete'),'synthetic-event-key','paid mirror uses the existing Woo completion function');writer_same($woo->get_meta('_molavi_paid_handoff_pending_payload'),'','paid completion clears only its matching pending payload');
$woo->meta['_molavi_paid_handoff_pending_key']='synthetic-newer-event';$woo->meta['_molavi_paid_handoff_pending_payload']=['order'=>array_merge($paid['order'],['total'=>200000]),'notify'=>false];
molavi_domain_order_acknowledge($paid);writer_same($woo->get_meta('_molavi_paid_handoff_pending_key'),'synthetic-newer-event','old receipt never acknowledges a newer Woo event');
$old=molavi_domain_queue('order','synthetic-sequence-old',['order'=>['id'=>'synthetic-sequence'],'notify'=>false]);$new=molavi_domain_queue('order','synthetic-sequence-new',['order'=>['id'=>'synthetic-sequence','payment_status'=>'verified'],'notify'=>false]);
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'UPDATE jobs SET available=? WHERE id=?',[microtime(true)+3600,$old]));
writer_same(molavi_domain_claim_job($new),null,'paid update cannot overtake an older queued order retry');
molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,"UPDATE jobs SET state='done' WHERE id=?",[$old]));
$claimed=molavi_domain_claim_job($new);writer_same($claimed['id'],$new,'next order update becomes eligible after its predecessor');molavi_domain_finish_job($claimed,['written'=>true]);
function get_option($key,$default=false){return $key==='molavi_state_active'?true:$default;}
function molavi_legacy_config(){return $GLOBALS['legacy_override']??['bot_url'=>'https://bot.synthetic.test','notify_secret'=>str_repeat('s',40)];}
function wp_json_encode($value){return json_encode($value);}
function get_status_header_desc($status){return 'Synthetic status';}
$filter=$GLOBALS['filters']['pre_http_request'][0];$args=['method'=>'POST','headers'=>['X-Shop-Token'=>str_repeat('s',40)],'body'=>'{"order":null}'];$before=$GLOBALS['sheet_reads'];
$intercepted=$filter(false,$args,'https://bot.synthetic.test/shop/order');writer_same($intercepted['response']['code'],400,'real handoff filter rejects invalid orders locally');
writer_same($intercepted['headers']['x-molavi-executor'],'wordpress','handoff response identifies WordPress execution');writer_same($GLOBALS['sheet_reads'],$before,'interception check has no Sheet writes or reads');
$args['headers']['X-Shop-Token']='wrong';writer_same($filter(false,$args,'https://bot.synthetic.test/shop/order'),false,'handoff filter requires the configured shared token');
function wp_next_scheduled($hook,$args=[]){return 1;}
$GLOBALS['legacy_override']=[];$local=['order'=>array_merge($cases[0]['source'],['id'=>'70003']),'notify'=>true];$wooLocal=new SyntheticReceiptOrder();$wooLocal->id=70003;$wooLocal->meta['_molavi_handoff_payload']=$local;$GLOBALS['receipt_orders'][70003]=$wooLocal;
$before=$GLOBALS['sheet_reads'];molavi_publish_order_notice(70003);
writer_same($GLOBALS['sheet_reads'],$before,'native checkout queues without legacy bot configuration or Sheet I/O');
$queued=molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,"SELECT id FROM jobs WHERE kind='order' AND json_extract(payload,'$.order.id')='70003'"));
writer_same($queued!==null,true,'real Woo notice entrypoint uses the native durable queue');
writer_same($wooLocal->get_meta('_molavi_handoff_status'),'pending','unverified native mirror stays pending in Woo');molavi_domain_run_jobs(1,40.0,$queued['id']);
writer_same($wooLocal->get_meta('_molavi_published'),'1','native completion reaches Woo without legacy credentials');$before=$GLOBALS['sheet_reads'];
writer_same(molavi_post_order_handoff($local),true,'actual Woo post adapter replays its native receipt');writer_same($GLOBALS['sheet_reads'],$before,'native receipt replay has no external I/O');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native order writer '.$count." cases\n";
