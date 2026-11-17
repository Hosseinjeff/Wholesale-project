<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_OWNER_IDS',[7]);define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-ai-decisions-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-orders','domain-gateway','domain-reference-parser','domain-reference-store','domain-reference-workflow','domain-products','domain-publisher','domain-product-derivation',
    'domain-sheet-conflicts','domain-reference-matching','domain-reference-reviews','domain-publication-recovery','domain-ai-review-model','domain-ai-review-api','domain-source-projection'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['ai_tables'];}
function molavi_domain_sheet_ensure($name,$headers){if(!isset($GLOBALS['ai_tables'][$name]))$GLOBALS['ai_tables'][$name]=[$headers];$old=$GLOBALS['ai_tables'][$name][0];foreach($headers as $header)if(!in_array($header,$old,true))$old[]=$header;$GLOBALS['ai_tables'][$name][0]=$old;}
function molavi_domain_sheet_fields($name,$row,$headers,$values){foreach($values as $field=>$value)$GLOBALS['ai_tables'][$name][$row-1][array_search($field,$headers,true)]=$value;}
function molavi_domain_sheet_append($name,$rows){foreach($rows as $row)$GLOBALS['ai_tables'][$name][]=$row;return [];}
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}$count=0;
function ai_decision_diff($a,$b,$path=''){if(molavi_domain_json($a)===molavi_domain_json($b))return '';if(is_array($a)&&is_array($b)){
    foreach(array_unique(array_merge(array_keys($a),array_keys($b))) as $key){if(!array_key_exists($key,$a)||!array_key_exists($key,$b))return $path.'/'.$key.' missing';$diff=ai_decision_diff($a[$key],$b[$key],$path.'/'.$key);if($diff!=='')return $diff;}}
    return $path.' '.substr(molavi_domain_json(['actual'=>$a,'expected'=>$b]),0,350);}
function ai_decision_same($actual,$expected,$label){global $count;$diff=ai_decision_diff($actual,$expected);if($diff!=='')throw new RuntimeException($label.' '.$diff);$count++;}
function ai_decision_seed($seed){$db=molavi_domain_db();foreach(['jobs','publication_holds','ai_confirmation_decisions','sheet_sync_conflicts','product_status_snapshots','product_status_records'] as $table)$db->exec('DELETE FROM '.$table);foreach($seed as $step)molavi_domain_sql($db,$step['sql'],$step['params']);$db->close();}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/native-ai-review-decisions-parity.json'),true,256,JSON_THROW_ON_ERROR);$clock='2026-11-17T00:00:00+00:00';
foreach($fixture as $case){ai_decision_seed($case['seed']);$GLOBALS['ai_tables']=$case['tables'];
    try{$actual=molavi_domain_ai_reviews_decide($case['body'],'wp:7',$clock);if($case['repeat'])$actual=molavi_domain_ai_reviews_decide($case['body'],'wp:7',$clock);$status=200;}
    catch(MolaviWorkflowError $e){$actual=['ok'=>false,'error'=>$e->getMessage()]+$e->details;$status=$e->status;}
    ai_decision_same($status,$case['status'],'status/'.$case['name']);ai_decision_same($actual,$case['expected'],'receipt/'.$case['name']);
    ai_decision_same($GLOBALS['ai_tables'],$case['after_tables'],'append-only source and product projection/'.$case['name']);
    ai_decision_same(molavi_domain_read('pipeline',static fn($db)=>molavi_domain_rows($db,'SELECT * FROM ai_confirmation_decisions ORDER BY created_at')),$case['decisions'],'audit/'.$case['name']);
    $jobs=molavi_domain_read('pipeline',static fn($db)=>molavi_domain_rows($db,'SELECT id,kind,payload,state FROM jobs ORDER BY id'));foreach($jobs as &$job)$job['payload']=molavi_domain_decode($job['payload']);unset($job);
    ai_decision_same($jobs,$case['jobs'],'publication receipts/'.$case['name']);}
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native AI review decisions '.$count." checks\n";
