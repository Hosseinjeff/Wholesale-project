<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-sheet-conflicts-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-gateway','domain-reference-parser','domain-reference-store','domain-reference-workflow',
    'domain-products','domain-publisher','domain-product-derivation','domain-sheet-conflicts'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}$count=0;
function conflict_same($actual,$expected,$label){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($label.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
function conflict_seed($store,$seed){$db=molavi_domain_db($store);foreach($store==='pipeline'?['jobs','sheet_sync_conflicts']:['reference_snapshots','reference_active','reference_item_decisions','reference_item_inheritances'] as $table)$db->exec('DELETE FROM '.$table);
    foreach($seed as $step)molavi_domain_sql($db,$step['sql'],$step['params']);$db->close();}
function molavi_domain_sheets($names,$fresh=false){
    if(in_array('Products',$names,true)){$GLOBALS['conflict_reads']++;$mode=$GLOBALS['conflict_mode'];
        if($GLOBALS['conflict_reads']===2&&$mode==='prewrite-changed'){$headers=$GLOBALS['conflict_tables']['Products'][0];$GLOBALS['conflict_tables']['Products'][1][array_search('Consumer Price',$headers,true)]=24000;}
        if($GLOBALS['conflict_reads']===2&&$mode==='row-moved')array_splice($GLOBALS['conflict_tables']['Products'],1,0,[array_fill(0,count($GLOBALS['conflict_tables']['Products'][0]),'Synthetic unrelated')]);}
    return $GLOBALS['conflict_tables'];
}
function molavi_domain_sheet_fields($name,$row,$headers,$values){if($GLOBALS['conflict_mode']==='write-unconfirmed')return;
    foreach($values as $field=>$value)$GLOBALS['conflict_tables'][$name][$row-1][array_search($field,$headers,true)]=$value;}
function conflict_state(){return molavi_domain_read('pipeline',static function($db){$conflicts=molavi_domain_rows($db,'SELECT * FROM sheet_sync_conflicts ORDER BY conflict_id');foreach($conflicts as &$row){unset($row['created_at'],$row['resolved_at']);}unset($row);
    return ['conflicts'=>$conflicts,'jobs'=>molavi_domain_rows($db,'SELECT id,kind,payload,state FROM jobs ORDER BY id')];});}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/native-sheet-conflicts-parity.json'),true,256,JSON_THROW_ON_ERROR);$clock='2026-11-17T01:00:00+00:00';
foreach($fixture['cells'] as $index=>$case){conflict_same(molavi_domain_cells_equal($case['field'],$case['left'],$case['right']),$case['equal'],'cell equality/'.$index);
    conflict_same(molavi_domain_reconcile_cell($case['field'],$case['base'],$case['left'],$case['right']),$case['action'],'three-way classification/'.$index);}
foreach($fixture['derivation'] as $case){$product=molavi_domain_product_from_offers($case['offers']);conflict_same($product,$case['product'],'offer projection/'.$case['name']);
    conflict_same(molavi_domain_product_row(molavi_domain_product_headers(),$product,molavi_domain_message_from_offer(end($case['offers'])),$clock),$case['row'],'product row/'.$case['name']);}
foreach($fixture['reviews'] as $case){conflict_seed('reference',$case['seed']);conflict_same(molavi_domain_product_reference_review(molavi_domain_product_from_offers([$case['offer']]),$case['offer'],'synthetic-snapshot',$clock),$case['product'],'reference decision projection/'.$case['name']);}
conflict_seed('reference',[]);
foreach($fixture['api'] as $case){conflict_seed('pipeline',$case['seed']);$GLOBALS['conflict_tables']=$case['tables'];$GLOBALS['conflict_reads']=0;$GLOBALS['conflict_mode']=$case['name'];
    try{$actual=molavi_domain_sheet_conflicts_api($case['method'],$case['body'],'wp:7');$status=200;}catch(MolaviWorkflowError $e){$actual=['ok'=>false,'error'=>$e->getMessage()]+$e->details;$status=$e->status;}
    conflict_same($status,$case['status'],'API status/'.$case['name']);conflict_same($actual,$case['expected'],'API receipt/'.$case['name']);
    conflict_same(conflict_state(),$case['after'],'durable audit/'.$case['name']);conflict_same($GLOBALS['conflict_tables'],$case['after_tables'],'cell writes/'.$case['name']);}
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native Sheet conflicts '.$count." checks\n";
