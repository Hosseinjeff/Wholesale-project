<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_OWNER_IDS',[7]);define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-reference-review-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
function get_option($key,$default=null){return $GLOBALS['review_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['review_options'][$key]=$value;}
function get_transient($key){return $GLOBALS['search_cache'][$key]??false;}
function set_transient($key,$value,$ttl){$GLOBALS['search_cache'][$key]=$value;}
function wp_parse_url($url,$component){return parse_url($url,$component);}
function wp_remote_retrieve_body($response){return $response['body'];}
function wp_remote_retrieve_response_code($response){return 200;}
function molavi_domain_reference_source(){return 'https://rezvanpakhsh.com/wp-json/wc/store/v1/products';}
function molavi_domain_http($method,$url,$options){
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    $GLOBALS['search_terms'][]=$query['search'];
    return ['body'=>molavi_domain_json($query['search']==='بیسکویت شکلاتی'
        ? [['name'=>'بیسکویت شکلاتی بنیس','permalink'=>'https://rezvanpakhsh.com/product/sample']] : [])];
}
foreach(['state-host','domain-core','domain-schemas','domain-orders','domain-gateway','domain-reference-parser','domain-reference-store','domain-reference-workflow','domain-products','domain-publisher',
    'domain-product-derivation','domain-sheet-conflicts','domain-jobs','domain-bot-capabilities','domain-reference-matching','domain-ai-review-model','domain-reference-reviews'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$GLOBALS['review_options']=['molavi_bot_capabilities_v1'=>['schema_version'=>1,'ai_configured'=>true,'extraction_policy_version'=>'gemini-'.str_repeat('a',16),'suggestion_policy_version'=>str_repeat('b',64),'image_extraction_enabled'=>false]];
function molavi_domain_reference_use(){return $GLOBALS['review_eligibility'];}
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['review_tables'];}
function molavi_domain_sheet_fields($name,$row,$headers,$values){foreach($values as $field=>$value)$GLOBALS['review_tables'][$name][$row-1][array_search($field,$headers,true)]=$value;}
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}$count=0;
function review_same($actual,$expected,$label){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($label.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
$searchQuery=['q'=>'بیسکویت شکلاتی ۱۲ عددی'];
$search=molavi_domain_search_online_reference($searchQuery);
review_same($search['matched_query'],'بیسکویت شکلاتی','search retries without pack count');
review_same(count($search['results']),1,'fallback returns a comparison result');
review_same(molavi_domain_search_online_reference($searchQuery),$search,'fallback reply is cached');
review_same($GLOBALS['search_terms'],['بیسکویت شکلاتی ۱۲ عددی','بیسکویت شکلاتی'],'fallback stops after the first useful query');
$columns=['Offer ID','Product Name','Variation Type','Batch ID','Channel Username','Message ID','Message Timestamp','Original Message','Derived From Offer ID'];
$stamp='2026-11-17T05:00:00+00:00';
$entries=[['old','Previous title','','original','supplier','11',$stamp,'Shared source','']];
for($i=1;$i<=24;$i++) $entries[]=['new-'.$i,'Extraction title '.$i,$i===2?'Orange':'','reextract:run:message','supplier','12',$stamp,'Shared source',''];
$entries[]=['other','Another message','','reextract:run:message','supplier','13',$stamp,'Different source',''];
$entries[]=['derived','Owner edited title','','owner-batch','supplier','12',$stamp,'Shared source','new-2'];
$GLOBALS['review_tables']=['Offers'=>[$columns,...$entries]];
$extracted=static fn(string $id): array=>molavi_domain_reference_reviews_api('GET',null,'wp:7',null,
    ['view'=>'extracted-titles','offer_id'=>$id])['titles'];
$titles=$extracted('old');
review_same(array_column($titles,'title'),array_map(static fn(int $i): string=>'Extraction title '.$i,range(1,24)),
    'all titles from the newest extraction of the source, including titles beyond the first twenty');
review_same($titles[1],['title'=>'Extraction title 2','variant'=>'Orange','current'=>false],
    'an older offer does not mark an unrelated new title as the current one');
review_same($extracted('derived')[1]['current'],true,'a derived offer marks its original extraction title');
review_same(array_column($extracted('other'),'title'),['Another message'],'separate messages in one batch keep separate title lists');
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
$GLOBALS['review_tables']=['Products'=>[],'Offers'=>[
    ['Offer ID','Product Name','Channel Username','Status','Review Reason'],
    ['no-reference-offer','Synthetic offer','sample','needs_review','price needs review; reference: no match'],
]];
review_seed('reference',[]);
review_seed('pipeline',[]);
$offer=molavi_domain_records($GLOBALS['review_tables']['Offers'])[0];
$id=$offer['Offer ID'];
$choice=molavi_domain_reference_reviews_api('POST',[
    'action'=>'proceed_without_reference','case_kind'=>'ai','case_id'=>'ai:'.hash('sha256',$id),
    'offer_id'=>$id,'signature'=>molavi_domain_hash($offer),
],'wp:7',$clock);
review_same($choice['projection_state'],'awaiting_ai_confirmation','waiving a reference preserves the extraction decision');
$held=molavi_domain_product_reference_review(['status'=>'needs_review'],$offer,'',$clock);
review_same($held['status'],'needs_review','independent price warning remains in review');
$offer['Review Reason']='reference: no match';
$derived=molavi_domain_product_reference_review(['status'=>'needs_review'],$offer,'',$clock);
review_same([$derived['status'],$derived['reference_approval_state']],['imported','owner_without_reference'],'reference-only hold releases with an explicit owner choice');
$match=molavi_domain_reference_decide(['case_id'=>molavi_domain_reference_case_id($id,'future-list'),
    'offer_id'=>$id,'snapshot_id'=>'future-list','signature'=>'catalog-signature','choice'=>'match',
    'reference_id'=>'REF-44','price_role'=>'unit','actor_id'=>'wp:7','note'=>'Later catalog match.'],null,$clock);
$derived=molavi_domain_product_reference_review(['status'=>'needs_review'],$offer,'future-list',$clock);
review_same([$derived['reference_match_id'],$derived['reference_decision_id']],['REF-44',$match['decision_id']],'later assignment replaces waiver');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native reference reviews '.$count." checks\n";
