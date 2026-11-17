<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_OWNER_IDS',[7]);define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-reference-review-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
function get_option($key,$default=null){return $GLOBALS['review_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['review_options'][$key]=$value;}
foreach(['state-host','domain-core','domain-schemas','domain-orders','domain-gateway','domain-reference-parser','domain-reference-store','domain-reference-workflow','domain-products','domain-publisher',
    'domain-product-derivation','domain-sheet-conflicts','domain-jobs','domain-bot-capabilities','domain-reference-matching','domain-reference-reviews'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$GLOBALS['review_options']=['molavi_bot_capabilities_v1'=>['schema_version'=>1,'ai_configured'=>true,'extraction_policy_version'=>'gemini-'.str_repeat('a',16),'suggestion_policy_version'=>str_repeat('b',64),'image_extraction_enabled'=>false]];
function molavi_domain_reference_use(){return $GLOBALS['review_eligibility'];}
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['review_tables'];}
function molavi_domain_sheet_fields($name,$row,$headers,$values){foreach($values as $field=>$value)$GLOBALS['review_tables'][$name][$row-1][array_search($field,$headers,true)]=$value;}
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}$count=0;
function review_same($actual,$expected,$label){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($label.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
function review_seed($store,$seed){$db=molavi_domain_db($store);foreach($store==='pipeline'?['jobs','ai_confirmation_decisions','sheet_projection_state','sheet_sync_conflicts','product_status_snapshots','product_status_records']:
    ['reference_snapshots','reference_active','reference_item_decisions','reference_item_inheritances'] as $table)$db->exec('DELETE FROM '.$table);$db->exec('DELETE FROM sqlite_sequence WHERE name=\'reference_item_decisions\'');
    foreach($seed as $step)molavi_domain_sql($db,$step['sql'],$step['params']);$db->close();}
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/native-reference-reviews-parity.json'),true,256,JSON_THROW_ON_ERROR);$clock='2026-11-17T00:00:00+00:00';
foreach($fixture['matching'] as $i=>$case){review_same(molavi_domain_reference_signature($case['product']),$case['signature'],'reference identity/'.$i);review_same(molavi_domain_reference_candidates($case['product'],$case['rows'],12),$case['candidates'],'reference ranking/'.$i);}
foreach($fixture['ratios'] as $i=>$case){$ratio=molavi_domain_match_ratio($case['a'],$case['b']);review_same(abs($ratio-$case['ratio'])<1e-14,true,'Unicode Indel similarity/'.$i);}
foreach($fixture['api'] as $case){review_seed('reference',$case['seed']);review_seed('pipeline',$case['pipeline_seed']);$GLOBALS['review_tables']=$case['tables'];$GLOBALS['review_eligibility']=$case['eligibility'];
    try{$actual=molavi_domain_reference_reviews_api($case['method'],$case['body'],'wp:7',$clock);$status=$actual['_http_status']??200;unset($actual['_http_status']);}catch(MolaviWorkflowError $e){$actual=['ok'=>false,'error'=>$e->getMessage()]+$e->details;$status=$e->status;}
    $jobs=molavi_domain_read('pipeline',static fn($db)=>molavi_domain_rows($db,'SELECT id,kind,payload,state FROM jobs ORDER BY id'));
    foreach($jobs as &$job){$job['payload']=molavi_domain_decode($job['payload']);if(str_starts_with($job['id'],'publish:sheet-change:')){if(($actual['publish_job']??null)===$job['id'])$actual['publish_job']='publish:synthetic-projection';$job['id']='publish:synthetic-projection';}}unset($job);
    review_same($status,$case['status'],'API status/'.$case['name']);review_same($actual,$case['expected'],'API receipt/'.$case['name']);
    review_same(molavi_domain_read('reference',static fn($db)=>molavi_domain_rows($db,'SELECT * FROM reference_item_decisions ORDER BY sequence')),$case['after_reference'],'audited decisions/'.$case['name']);
    review_same($GLOBALS['review_tables'],$case['after_tables'],'product projection/'.$case['name']);review_same($jobs,$case['jobs'],'queued work/'.$case['name']);}
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native reference reviews '.$count." checks\n";
