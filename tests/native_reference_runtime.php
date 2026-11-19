<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_BRIDGE_TOKEN',str_repeat('s',40));
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-reference-runtime-'.bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_OWNER_IDS',[7]);$GLOBALS['options']=['molavi_state_active'=>true];
function add_action(...$args){}function add_filter(...$args){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
foreach(['state-host','domain-core','domain-schemas','domain-read-models','domain-reference-parser','domain-reference-store','domain-reference-fetch','domain-reference-workflow','domain-reference-scheduler','domain-gateway'] as $file)
 require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$count=0;function same($actual,$expected,string $name):void{global $count;
 if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($name.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
$root=molavi_state_root();$initial=new SQLite3($root.'/reference.sqlite3');$initial->close();
function reset_reference():void{$db=molavi_domain_db('reference');foreach(molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%'") as $row)$db->exec('DELETE FROM '.$row['name']);$db->close();}
function molavi_domain_sheet_column(int $number):string{$out='';while($number>0){$number--;$out=chr(65+$number%26).$out;$number=intdiv($number,26);}return $out;}
function molavi_domain_sheet_properties():array{return $GLOBALS['sheet']?['ReferencePrices'=>[]]:[];}
function molavi_domain_sheet_ensure($name,$headers):void{if($name==='ReferencePrices')$GLOBALS['sheet']=[$headers];}
function molavi_domain_sheets($names,$fresh=false,$raw=false):array{if(in_array('Products',$names,true))return ['Products'=>$GLOBALS['products'],'Offers'=>[],'Shop'=>[]];$table=$GLOBALS['sheet'];if(!empty($GLOBALS['bad_receipt']))$table[1][3]=123;return ['ReferencePrices'=>$table];}
function molavi_domain_sheet_cells($name,$updates):void{
 $kind=count($updates)===1&&str_contains($updates[0]['range'],':')?'full':'delta';$cells=0;
 foreach($updates as $update){preg_match('/^([A-Z]+)([0-9]+)/',$update['range'],$m);$column=0;foreach(str_split($m[1]) as $letter)$column=$column*26+ord($letter)-64;
  foreach($update['values'] as $offset=>$values)foreach($values as $index=>$value){if($name==='Products')$GLOBALS['products'][(int)$m[2]+$offset-1][$column+$index-1]=$value;else $GLOBALS['sheet'][(int)$m[2]+$offset-1][$column+$index-1]=$value;$cells++;}}
 $GLOBALS['writes'][]=['kind'=>$kind,'cells'=>$cells];
}
function molavi_domain_positive_price($value){return (int)$value;}
function molavi_domain_product_headers(){return $GLOBALS['products'][0];}
function molavi_domain_status_capture($tables,$reason){return ['snapshot_id'=>'synthetic-status'];}
function molavi_domain_projection_digest($tables){return 'synthetic-digest';}
function molavi_domain_observe_projection($digest){$GLOBALS['observed']=$digest;}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-projection-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($cases as $case){reset_reference();$GLOBALS['sheet']=$case['initial'];$GLOBALS['writes']=[];
 if($case['previous'])molavi_domain_tx('reference',static fn($db)=>molavi_domain_sql($db,'INSERT INTO reference_projection_state VALUES (1,?)',[$case['previous']['snapshot_id']]));
 same(molavi_domain_reference_project($case['snapshot'],$case['previous'],true),$case['expected_result'],$case['name'].' result');
 same($GLOBALS['sheet'],$case['expected_table'],$case['name'].' cells');same($GLOBALS['writes'],$case['expected_calls'],$case['name'].' bounded writes');
 same(molavi_domain_reference_project($case['snapshot'],$case['previous'],true)['mode'],'already_projected',$case['name'].' receipt replay');
}
reset_reference();$GLOBALS['sheet']=[];$GLOBALS['writes']=[];$GLOBALS['bad_receipt']=true;
try{molavi_domain_reference_project($cases[0]['snapshot'],null,false);$error=false;}catch(MolaviWorkflowError $e){$error=$e->getMessage()==='reference_projection_receipt_mismatch';}
same($error,true,'mismatched projection refuses receipt');same(molavi_domain_read('reference',static fn($db)=>molavi_domain_row($db,'SELECT snapshot_id FROM reference_projection_state')),null,'failed projection stays retryable');$GLOBALS['bad_receipt']=false;
reset_reference();$storeCases=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-store-parity.json'),true,128,JSON_THROW_ON_ERROR);
$base=$storeCases[0]['candidate'];$clock=molavi_domain_timestamp($base['extracted_at']);
$first=molavi_domain_reference_refresh(false,static fn()=>$base,$clock);same($first['error_code']??$first['state'],'staged','initial refresh stages full raw records');
same(molavi_domain_reference_refresh(false,static function(){throw new RuntimeException('must not fetch');},$clock+10)['state'],'not_due','daily check avoids network');
same(molavi_domain_reference_refresh(true,null,$clock+10)['retry_after_seconds'],50,'manual retry cooldown');
try{molavi_domain_reference_refresh(true,null,$clock+120,'stale',true);$error=false;}catch(MolaviWorkflowError $e){$error=$e->getMessage()==='refresh_state_changed';}same($error,true,'manual source revision checked inside lease');
molavi_domain_reference_review($first['snapshot_id'],'wp:7','Synthetic review');molavi_domain_reference_activate($first['snapshot_id'],null,'wp:7');
same(molavi_domain_reference_use($clock+100)['allow_automatic'],true,'fresh reference gate');
$unchanged=$base;$unchanged['extracted_at']='2026-11-16T02:00:00+00:00';$second=molavi_domain_reference_refresh(true,static fn()=>$unchanged,$clock+3600);
same($second['state'],'unchanged','unchanged reference retains snapshot');same($second['snapshot_id'],$first['snapshot_id'],'unchanged reference identity stable');
$failure=molavi_domain_reference_refresh(true,static function(){molavi_domain_fail('woocommerce_http_503',503);},$clock+7200);
same($failure['state'],'failed','fetch failure recorded');same(molavi_domain_reference_active()['snapshot_id'],$first['snapshot_id'],'fetch failure preserves active reference');
same(molavi_domain_reference_use($clock+7201)['allow_automatic'],false,'refresh failure blocks automatic use');
$event='refresh:'.$failure['occurrence_id'];$body=['event_id'=>$event,'snapshot_id'=>$first['snapshot_id'],'choice'=>'use_last_snapshot'];
// Test the decision ledger directly: wake uses the real clock and is covered separately by native queue tests.
molavi_domain_tx('reference',static fn($db)=>molavi_domain_sql($db,'UPDATE reference_events SET choice=?,actor_id=? WHERE event_id=?',['use_last_snapshot','wp:7',$event]));
same(molavi_domain_reference_use($clock+7202)['state'],'use_last_snapshot','explicit permission retained');
$unchanged['extracted_at']='2026-11-16T04:00:00+00:00';molavi_domain_reference_refresh(true,static fn()=>$unchanged,$clock+10800);
same(molavi_domain_reference_use($clock+10801)['state'],'fresh','successful unchanged fetch retires failure');
molavi_domain_tx('reference',static fn($db)=>molavi_domain_sql($db,"UPDATE reference_refresh_state SET state='running',occurrence_id='synthetic-crash' WHERE singleton=1"));
molavi_domain_reference_refresh(false,static function(){molavi_domain_fail('woocommerce_http_503',503);},$clock+10802);
same(molavi_domain_read('reference',static fn($db)=>molavi_domain_row($db,'SELECT reason FROM reference_events WHERE event_id=?',['refresh:synthetic-crash'])['reason']),'refresh_failed','interrupted fetch gets durable incident');
$locked=['store'=>'reference','name'=>'refresh','owner'=>str_repeat('b',32),'action'=>'acquire'];molavi_state_lease($locked);
try{molavi_domain_reference_refresh(true,null,$clock+12000);$error=false;}catch(MolaviWorkflowError $e){$error=$e->getMessage()==='workflow_busy_retry';}same($error,true,'concurrent refresh cannot run twice');$locked['action']='release';molavi_state_lease($locked);
reset_reference();$workflow=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-workflow-parity.json'),true,128,JSON_THROW_ON_ERROR);$case=$workflow['product_changes'][0];
$headers=array_keys($case['products'][0]);foreach(array_keys($case['expected']['updates'][0]['fields']) as $header)if(!in_array($header,$headers,true))$headers[]=$header;
$records=[$case['products'][0],array_merge($case['products'][0],['Product ID'=>'synthetic-second'])];
$GLOBALS['products']=molavi_domain_rows_from_records($headers,$records);$GLOBALS['sheet']=[];$GLOBALS['writes']=[];
$initial=new SQLite3($root.'/pipeline.sqlite3');$initial->close();
$complete=molavi_domain_reference_complete($case['next'],$case['previous'],false);
same($complete['product_reconciliation_state'],'complete','full reference reconciliation receipt');
same($complete['product_reference_updates'],['checked'=>2,'retained'=>2,'needs_review'=>0],'two reference approvals updated');
same(count($GLOBALS['writes']),2,'full reference projection and both products use two batched requests');
$records=molavi_domain_records($GLOBALS['products']);same($records[0]['Reference Snapshot ID'],$case['next']['snapshot_id'],'product reference moved to verified snapshot');
same($records[1]['Reference Approval State'],'approved_within_margin','second product receipt');
same($GLOBALS['observed'],'synthetic-digest','changed products queue the native publication digest');
same(molavi_domain_read('reference',static fn($db)=>molavi_domain_row($db,'SELECT snapshot_id FROM reference_reconciliation_state')['snapshot_id']),$case['next']['snapshot_id'],'reconciliation marker committed after receipts');
$before=count($GLOBALS['writes']);molavi_domain_reference_complete($case['next'],$case['previous'],false);same(count($GLOBALS['writes']),$before,'completed reconciliation replays without Sheet writes');
reset_reference();$case=$storeCases[2];$db=molavi_domain_db('reference');foreach($case['seed'] as $seed)molavi_domain_sql($db,$seed['sql'],$seed['params']);$db->close();
$previous=molavi_domain_reference_active();$staged=molavi_domain_reference_stage($case['candidate']);
molavi_domain_reference_activate($staged['snapshot_id'],$previous['snapshot_id'],'system:reference-refresh',true);
molavi_domain_tx('reference',static function($db)use($staged){molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_refresh_state(singleton) VALUES (1)');molavi_domain_sql($db,"UPDATE reference_refresh_state SET state='staged',snapshot_id=? WHERE singleton=1",[$staged['snapshot_id']]);});
$GLOBALS['sheet']=[];$completion=molavi_domain_reference_finish_refresh(['state'=>'not_due']);
same($completion['product_reconciliation_state'],'complete','activation committed before interruption resumes without reactivation');
same(molavi_domain_reference_refresh_state()['state'],'updated','interrupted activation finishes its existing refresh receipt');
function wp_remote_retrieve_headers($response){return $response['headers'];}
function wp_remote_retrieve_response_code($response){return $response['status'];}
function wp_remote_retrieve_body($response){return $response['body'];}
function molavi_domain_http($method,$url,$args){parse_str((string)parse_url($url,PHP_URL_QUERY),$query);$page=(int)$query['page'];$GLOBALS['source_calls']++;
 $generation=(int)ceil($GLOBALS['source_calls']/2);$total=$page===2&&($generation===1||!empty($GLOBALS['source_unstable']))?102:101;
 $all=$GLOBALS['source_products'];$rows=array_slice($all,($page-1)*100,100);
 return ['status'=>200,'headers'=>['x-wp-total'=>(string)$total,'x-wp-totalpages'=>'2'],'body'=>json_encode($rows)];}
$GLOBALS['source_products']=[];for($i=0;$i<101;$i++){$raw=$base['_source_products'][0];$raw['id']=60000+$i;$raw['sku']='SYNTH-GENERATION-'.$i;$GLOBALS['source_products'][]=$raw;}
$GLOBALS['source_calls']=0;$candidate=molavi_domain_reference_fetch_products();
same(count($candidate['_source_products']),101,'source change retries a complete generation');same($GLOBALS['source_calls'],4,'source retry starts at page one exactly once');
$GLOBALS['source_calls']=0;$GLOBALS['source_unstable']=true;
try{molavi_domain_reference_fetch_products();$error=false;}catch(MolaviWorkflowError $e){$error=$e->getMessage()==='woocommerce_pagination_changed';}
same($error,true,'continuously changing source stays unaccepted');same($GLOBALS['source_calls'],4,'unstable source retry is bounded');
reset_reference();
$base['extracted_at']=gmdate('c');
$initial=molavi_domain_reference_refresh(true,static fn()=>$base);
$active=$initial['snapshot_id'];
molavi_domain_reference_review($active,'wp:7','Synthetic reference');
molavi_domain_reference_activate($active,null,'wp:7');
molavi_domain_reference_failure('attempt-one');
molavi_domain_reference_failure('attempt-two');
molavi_domain_reference_event('older-attempt','refresh_failed',str_repeat('b',64));
$status=molavi_domain_reference_snapshots([])['reference_review'];
same([$status['state'],$status['pending_count'],$status['failed_attempts']],
    ['awaiting_owner',2,2],'current reference summary groups its own events only');
$decision=['action'=>'decide_reference_use','snapshot_id'=>$active,'decision_token'=>$status['decision_token'],'choice'=>'use_last_snapshot'];
molavi_domain_reference_failure('attempt-three');
try{molavi_domain_reference_api($decision,'wp:7');$error=false;}catch(MolaviWorkflowError $e){$error=$e->getMessage()==='reference_review_changed';}
same($error,true,'a late failure invalidates the displayed set of events');
$status=molavi_domain_reference_snapshots([])['reference_review'];
same($status['pending_count'],3,'a stale request leaves every decision intact');
$decision['decision_token']=$status['decision_token'];
same(molavi_domain_reference_api($decision,'wp:7')['applied_count'],3,'one receipt resolves all displayed failures');
same(molavi_domain_reference_use()['state'],'use_last_snapshot','the saved-reference decision opens the matching gate');
same(molavi_domain_reference_snapshots([])['reference_review']['state'],'using_saved','the reference tab reflects the open gate');
same(molavi_domain_read('reference',static fn($db)=>molavi_domain_row($db,'SELECT choice FROM reference_events WHERE event_id=?',['older-attempt'])['choice']),
    null,'group decisions leave other snapshots untouched');
molavi_domain_reference_failure('attempt-four');
$decision['decision_token']=molavi_domain_reference_snapshots([])['reference_review']['decision_token'];
$decision['choice']='pause_review';
same(molavi_domain_reference_api($decision,'wp:7')['applied_count'],1,'a pause applies to the outstanding failure');
$paused=molavi_domain_reference_snapshots([])['reference_review'];
same([$paused['state'],$paused['decision_token']],['paused',null],'the paused panel leads the owner to refresh');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native reference runtime '.$count." cases\n";
