<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_OWNER_IDS',[7]);define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-ai-decisions-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-orders','domain-gateway','domain-reference-parser','domain-reference-store','domain-reference-workflow','domain-products','domain-publisher','domain-product-derivation',
    'domain-sheet-conflicts','domain-reference-matching','domain-reference-reviews','domain-publication-recovery','domain-ai-review-model','domain-ai-review-api',
    'domain-source-projection','domain-ai-published-reviews','domain-supplier-prices'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['ai_tables'];}
function molavi_domain_sheet_ensure($name,$headers){if(!isset($GLOBALS['ai_tables'][$name]))$GLOBALS['ai_tables'][$name]=[$headers];$old=$GLOBALS['ai_tables'][$name][0];foreach($headers as $header)if(!in_array($header,$old,true))$old[]=$header;$GLOBALS['ai_tables'][$name][0]=$old;}
function molavi_domain_sheet_fields($name,$row,$headers,$values){
    if(!empty($GLOBALS['interrupt_projection'])) molavi_domain_fail('sheet_write_unavailable',503);
    foreach($values as $field=>$value)$GLOBALS['ai_tables'][$name][$row-1][array_search($field,$headers,true)]=$value;
}
function molavi_domain_sheet_append($name,$rows){foreach($rows as $row)$GLOBALS['ai_tables'][$name][]=$row;return [];}
function wc_get_products(...$args){}
function get_edit_post_link($id,$context){return '/wp-admin/post.php?post='.$id.'&action=edit';}
function molavi_catalog_identity_snapshot(bool $publishedOnly=false): array {
    return [
        ['source_record_id'=>'product-1','published'=>true,'woo_product_id'=>1,'name'=>'Sample juice','supplier'=>'supplier'],
        ['source_record_id'=>'','published'=>true,'woo_product_id'=>2,'name'=>'Manual listing','supplier'=>''],
    ];
}
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
ai_decision_seed([]);
$terms=['Product ID'=>'product-1','Product Name'=>'Sample juice', 'Status'=>'imported','Channel Username'=>'supplier',
    'Channel'=>'42','Batch ID'=>'approved-batch','Source Post URL'=>'https://t.me/supplier/25',
    'Message Timestamp'=>'2026-11-16T10:00:00+00:00','Source Timestamp Basis'=>'supplier_channel_forward',
    'Sale Price'=>110000,'Consumer Price'=>170000,'Price Type'=>'unit','Currency'=>'IRT','Package Count'=>12,
    'Min Qty'=>6,'Stock Status'=>'Available','Reference Match ID'=>'REFERENCE-1','Reference Approval State'=>'approved'];
$product=$terms+['Latest Offer ID'=>'approved-offer','Offer Count'=>1,'Double Pack Price'=>1320000];
$source=$terms+['Offer ID'=>'approved-offer','Package Price'=>1320000,'Message ID'=>'25'];
$GLOBALS['ai_tables']=[
    'Products'=>molavi_domain_rows_from_records(molavi_domain_product_headers(),[$product]),
    'Offers'=>molavi_domain_rows_from_records(molavi_domain_offer_headers(),[$source])
];
$before=$GLOBALS['ai_tables'];
$status=molavi_domain_status_api('GET',[],null,'wp:7');
ai_decision_same($status['products'][0]['published'],true,'a linked WooCommerce listing is published');
ai_decision_same(array_column($status['published_unlinked'],'woo_product_id'),[2],'manual listing stays visible with its own WooCommerce identity');
$open=['action'=>'reopen_products','items'=>[['product_id'=>'product-1','offer_id'=>'approved-offer'],
    ['product_id'=>'other-product','offer_id'=>'other-offer']]];
$opened=molavi_domain_ai_reviews_api('POST',[],$open,'wp:7');
ai_decision_same([$opened['opened'],$opened['unavailable']],[1,1],'only eligible published records open');
$duplicate=molavi_domain_ai_reviews_api('POST',[],$open,'wp:7');
ai_decision_same($duplicate['items'][0]['case_id'],$opened['items'][0]['case_id'],'retry returns the existing open case');
ai_decision_same($GLOBALS['ai_tables'],$before,'opening leaves approved storefront data untouched');
$cases=molavi_domain_published_review_cases($GLOBALS['ai_tables']);
$case=$cases[0];
ai_decision_same([$case['reopened'],$case['sale_price'],$case['actual_price'],$case['reference_match_id']],
    [true,110000,170000,'REFERENCE-1'],'review starts with the current approved terms');
$body=['case_id'=>$case['case_id'],'offer_id'=>$case['offer_id'],'signature'=>$case['signature'],'choice'=>'confirm',
    'note'=>'verified current source','expected_decision_id'=>null,'fields'=>[
        'price_role'=>'unit','sale_price'=>100000,'actual_price'=>175000,'package_count'=>12,'min_qty'=>6,'stock_status'=>'Available']];
try {molavi_domain_ai_reviews_decide($body,'wp:7',$clock);throw new RuntimeException('source verification missing');}
catch(MolaviWorkflowError $error){ai_decision_same($error->getMessage(),'source_current_verification_required','reopen preserves the source check');}
$body['source_current_verified']=true;
$priceIndex=array_search('Sale Price',$GLOBALS['ai_tables']['Products'][0],true);
$GLOBALS['ai_tables']['Products'][1][$priceIndex]=120000;
try {molavi_domain_ai_reviews_decide($body,'wp:7',$clock);throw new RuntimeException('accepted outdated terms');}
catch(MolaviWorkflowError $error){ai_decision_same($error->getMessage(),'stale_case','reject a decision based on outdated terms');}
$GLOBALS['ai_tables']=$before;
$GLOBALS['interrupt_projection']=true;
try {molavi_domain_ai_reviews_decide($body,'wp:7',$clock);throw new RuntimeException('expected broken projection');}
catch(MolaviWorkflowError $error){ai_decision_same($error->getMessage(),'sheet_write_unavailable','interrupt after appending offer');}
$GLOBALS['interrupt_projection']=false;
$pending=molavi_domain_published_review_cases($GLOBALS['ai_tables'])[0];
$body['expected_decision_id']=$pending['previous_decision_id'];
$receipt=molavi_domain_ai_reviews_decide($body,'wp:7',$clock);
$written=molavi_domain_records($GLOBALS['ai_tables']['Products'])[0];
ai_decision_same(count($GLOBALS['ai_tables']['Offers']),3,'partial success retry creates one derived offer');
ai_decision_same([$written['Sale Price'],$written['Consumer Price'],$written['Reference Match ID'],$written['Batch ID']],
    [100000,175000,'REFERENCE-1','approved-batch'],'apply the new terms and preserve the approved reference and source lineage');
ai_decision_same(molavi_domain_source_issues([$written],molavi_domain_records($GLOBALS['ai_tables']['Offers'])),[],'published projection has valid source integrity');
ai_decision_same(molavi_domain_ai_reviews_decide($body,'wp:7',$clock),$receipt,'completed decision replays its original receipt');
ai_decision_same(molavi_domain_published_review_cases($GLOBALS['ai_tables']),[],'resolved case leaves the review queue');
$job=molavi_domain_read('pipeline',static fn($db)=>molavi_domain_row($db,'SELECT id FROM jobs'));
ai_decision_same($job['id'],$receipt['publish_job'],'the explicit decision queues publication');

foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native AI review decisions '.$count." checks\n";
