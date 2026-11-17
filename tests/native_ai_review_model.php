<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-ai-review-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-store','domain-products','domain-publisher','domain-product-derivation','domain-sheet-conflicts',
    'domain-reference-matching','domain-reference-reviews','domain-publication-recovery','domain-ai-review-model'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}$count=0;
function ai_model_same($actual,$expected,$label){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($label.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
function ai_model_seed($store,$seed){$db=molavi_domain_db($store);foreach($store==='pipeline'?['ai_confirmation_decisions','ai_source_reextractions']:['reference_item_decisions'] as $table)$db->exec('DELETE FROM '.$table);
    foreach($seed as $step)molavi_domain_sql($db,$step['sql'],$step['params']);$db->close();}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/native-ai-review-model-parity.json'),true,256,JSON_THROW_ON_ERROR);$clock=molavi_domain_timestamp('2026-11-17T00:00:00+00:00');
foreach($fixture['pure'] as $i=>$case){ai_model_same(molavi_domain_ai_case($case['offer'],$case['latest'],$clock),$case['case'],'AI case/'.$i);ai_model_same(molavi_domain_ai_duplicate_key($case['offer']),$case['duplicate'],'duplicate identity/'.$i);ai_model_same(molavi_domain_ai_source_key($case['offer']),$case['source_key'],'source identity/'.$i);}
foreach($fixture['reports'] as $case){ai_model_seed('pipeline',$case['seed']);ai_model_seed('reference',$case['reference_seed']);$result=molavi_domain_ai_review_cases($case['tables'],$case['snapshot'],true,200,$clock);
    // JSON objects with no fields must remain objects in review decision history.
    $expected=$case['expected'];foreach($expected[0] as &$item)foreach($item['decision_history'] as &$d)if($d['fields']===[])$d['fields']=(object)[];unset($item,$d);
    foreach($expected[1] as &$item){if($item['decision']['fields']===[])$item['decision']['fields']=(object)[];foreach($item['decision_history'] as &$d)if($d['fields']===[])$d['fields']=(object)[];}unset($item,$d);
    ai_model_same($result,$expected,'review report/'.$case['name']);}
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native AI review model '.$count." checks\n";
