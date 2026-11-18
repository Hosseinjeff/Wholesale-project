<?php
/** Plugin Name: Molavi Native Intake Reconciliation */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_audit_text($value): string {return trim(molavi_domain_context_text($value,PHP_INT_MAX));}
function molavi_domain_audit_fold($value): string {$value=molavi_domain_audit_text($value);return function_exists('mb_convert_case')?mb_convert_case($value,MB_CASE_FOLD,'UTF-8'):strtolower($value);}
function molavi_domain_audit_time($value): string {
    if(!is_numeric($value)||!is_finite((float)$value))return '';
    try{$date=DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',(float)$value));return $date?str_replace('.000000+00:00','+00:00',$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP')):'';}catch(Throwable $e){return '';}
}
function molavi_domain_woo_identity_match(array $expected,array $woo): array {
    $records=$woo['identity_records']??null;if(!is_array($records))return [null,''];$checks=[];
    foreach([['source_record_id','source_record_id'],['source_sku','source_id'],['sku','sku']] as [$method,$field]){$value=molavi_domain_audit_text($expected[$method==='source_record_id'?'source_record_id':'id']??'');
        if($value!=='')$checks[]=[$method,static fn($row)=>molavi_domain_audit_text($row[$field]??'')===$value];}
    $supplier=molavi_domain_audit_fold($expected['supplier']??'');$name=molavi_domain_audit_fold(preg_replace('/\s+/u',' ',molavi_domain_audit_text(molavi_domain_py_value($expected['display_name']??'',($expected['product_name']??'')))));
    if($supplier!==''&&$name!==''&&empty($expected['supplier_name_ambiguous']))$checks[]=['supplier_name',static fn($row)=>molavi_domain_audit_fold($row['supplier']??'')===$supplier&&molavi_domain_audit_fold(preg_replace('/\s+/u',' ',molavi_domain_audit_text($row['name']??'')))===$name];
    foreach($checks as [$method,$predicate]){$matches=array_values(array_filter($records,$predicate));if(count($matches)===1)return [$matches[0],$method];if(count($matches)>1)return [null,'ambiguous'];}return [null,''];
}
function molavi_domain_woo_state(?array $expected,array $woo): string {
    if(!$expected||($expected['status']??null)==='no_projection')return 'no_catalog_projection';[$identity]=molavi_domain_woo_identity_match($expected,$woo);$held=in_array(molavi_domain_audit_text($expected['status']??''),['hold','needs_review','not_product','skipped'],true);
    if($identity!==null){$published=molavi_domain_py_value($identity['published']??null,null)!==null;$purchasable=molavi_domain_py_value($identity['purchasable']??null,null)!==null;
        if($held)return $published&&$purchasable?'held_but_purchasable':'held';if(!$published)return 'not_visible';if(!$purchasable)return 'not_purchasable';
        return molavi_domain_audit_text($identity['sku']??'')!==molavi_domain_audit_text($expected['id']??'')?'sku_mismatch':'purchasable';}
    $published=$woo['products'][molavi_domain_audit_text($expected['id']??'')]??null;
    if($held){if($published&&!empty($published['purchasable']))return 'held_but_purchasable';if($published||$woo['state']==='checked')return 'held';return $woo['state']==='truncated'?'not_checked_catalog_truncated':'not_checked';}
    if(!$published)return $woo['state']==='checked'?'not_visible':($woo['state']==='truncated'?'not_checked_catalog_truncated':'not_checked');
    if(empty($published['purchasable']))return in_array(strtolower(molavi_domain_audit_text($expected['supplier_availability']??'')),['out of stock','outofstock','ناموجود'],true)?'supplier_out_of_stock':'not_purchasable';return 'purchasable';
}
function molavi_domain_audit_offers(array $message,array $offers): array {
    $id=molavi_domain_audit_text($message['message_id']??'');$channel=molavi_domain_audit_text($message['channel']??'');$stamp=molavi_domain_audit_text($message['source_timestamp']??'');$batch=molavi_domain_audit_text($message['batch_id']??'');$text=molavi_domain_audit_text($message['_source_text']??'');
    $matches=array_values(array_filter($offers,static fn($row)=>molavi_domain_audit_text($row['Message ID']??'')===$id&&molavi_domain_audit_text($row['Channel Username']??'')===$channel&&($stamp===''||molavi_domain_audit_text($row['Message Timestamp']??'')===$stamp)&&($text===''||molavi_domain_audit_text($row['Original Message']??'')===$text)));
    if(!$matches&&$batch!=='')$matches=array_values(array_filter($offers,static fn($row)=>molavi_domain_audit_text($row['Batch ID']??'')===$batch&&molavi_domain_audit_text($row['Message ID']??'')===$id&&molavi_domain_audit_text($row['Channel Username']??'')===$channel));
    $known=[];foreach($matches as $row)$known[molavi_domain_audit_text($row['Offer ID']??'')]=true;$raw=$known;unset($raw['']);
    foreach($offers as $row)if(isset($raw[molavi_domain_audit_text($row['Derived From Offer ID']??'')])&&!isset($known[molavi_domain_audit_text($row['Offer ID']??'')]))$matches[]=$row;return $matches;
}
function molavi_domain_receipt_data(int $limit): array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db)use($limit):array {
        $receipts=molavi_domain_rows($db,'SELECT id,payload,batch_id,received_at FROM inbox ORDER BY rowid DESC LIMIT ?',[$limit]);$jobs=molavi_domain_rows($db,"SELECT id,kind,state,attempts,error,result,payload,created_at,updated_at FROM jobs WHERE kind IN ('batch','publish') ORDER BY rowid DESC");
        $total=molavi_domain_row($db,'SELECT COUNT(*) AS n FROM inbox')['n'];$byId=[];$byBatch=[];$byDecision=[];
        foreach($jobs as $job){$job['payload']=molavi_domain_decode($job['payload']);$job['result']=molavi_domain_decode($job['result']);$byId[$job['id']]=$job;$batch=molavi_domain_audit_text($job['payload']['batch_id']??'');$decision=molavi_domain_audit_text($job['payload']['decision_id']??'');
            if($job['kind']==='publish'&&$batch!==''&&!isset($byBatch[$batch]))$byBatch[$batch]=$job;
            if($job['kind']==='publish'&&$decision!==''&&!isset($byDecision[$decision]))$byDecision[$decision]=['state'=>$job['state'],'attempts'=>(int)$job['attempts'],'error'=>molavi_domain_py_value($job['error']),'created_at'=>$job['created_at'],'updated_at'=>$job['updated_at'],'pushed'=>!empty($job['result']['pushed']),'count'=>(int)($job['result']['count']??0)];
        }
        $items=[];foreach($receipts as $row){$source=molavi_domain_decode($row['payload']);$jobId=(string)molavi_domain_py_value($row['batch_id']);$job=$byId[$jobId]??[];$payload=$job['payload']??[];$batch=(string)molavi_domain_py_value($payload['batch_id']??'');$publication=$byBatch[$batch]??[];$result=$job['result']??[];$pResult=$publication['result']??[];
            $item=['receipt_id'=>(string)$row['id'],'message_id'=>(string)molavi_domain_py_value($source['id']??''),'channel'=>(string)molavi_domain_py_value($source['channel_username']??'',($source['channel']??''))];
            foreach(['source_channel'=>'source_channel','source_timestamp'=>'timestamp','source_timestamp_basis'=>'source_timestamp_basis','source_created_at'=>'source_created_at','source_updated_at'=>'source_update_at'] as $key=>$field)$item[$key]=(string)molavi_domain_py_value($source[$field]??'');
            $item+=['received_at'=>$row['received_at'],'batch_id'=>$batch,'batch_job_id'=>$jobId,'batch_state'=>molavi_domain_py_value($job['state']??'','unbatched'),'batch_attempts'=>(int)($job['attempts']??0),'batch_error'=>molavi_domain_py_value($job['error']??''),'batch_created_at'=>$job['created_at']??null,'batch_updated_at'=>$job['updated_at']??null,
                'expected_messages'=>(int)molavi_domain_py_value($result['expected_count']??null,($payload['expected_count']??0)),'processed_messages'=>(int)($result['processed_messages']??0),'extraction_state'=>(string)molavi_domain_py_value($result['extraction_status']??''),'rollback'=>!empty($result['rollback']),
                'publication_state'=>molavi_domain_py_value($publication['state']??'','not_queued'),'publication_attempts'=>(int)($publication['attempts']??0),'publication_error'=>molavi_domain_py_value($publication['error']??''),'publication_created_at'=>$publication['created_at']??null,'publication_updated_at'=>$publication['updated_at']??null,'publication_pushed'=>!empty($pResult['pushed']),'publication_count'=>(int)($pResult['count']??0)];$items[]=$item;
        }
        return ['receipts'=>$items,'total_receipts'=>$total,'truncated'=>$total>count($items),'publication_by_decision'=>(object)$byDecision];
    });
}
function molavi_domain_reconciliation_render(array $data,array $tables,?array $projection,array $issues,array $woo,int $limit=200,?string $clock=null): array {
    $text='molavi_domain_audit_text';$messages=molavi_domain_records($tables['MessageData']??[]);$offers=molavi_domain_records($tables['Offers']??[]);$products=molavi_domain_records($tables['Products']??[]);$messageIndex=[];$byId=[];$byOffer=[];
    foreach($messages as $row)$messageIndex[molavi_domain_json([$text($row['ID']??''),$text($row['Channel Username']??''),$text($row['Batch ID']??'')])][]=$row;
    foreach($products as $row){$id=$text($row['Product ID']??'');$offer=$text($row['Latest Offer ID']??'');if($id!=='')$byId[$id][]=$row;if($offer!=='')$byOffer[$offer][]=$row;}
    $projected=[];foreach($projection??[] as $row)if($text($row['id']??'')!=='')$projected[$text($row['id'])]=$row;$sourceIssues=[];foreach($issues as $issue)$sourceIssues[(int)$issue['row_number']-2]=$issue;
    $expected=[];$keysById=[];$names=[];
    foreach($products as $index=>$row){$id=$text($row['Product ID']??'');$name=$text($row['Product Name']??'');$channel=$text($row['Channel Username']??'');
        if($id===''||($name===''&&!in_array(strtolower($text($row['Status']??'')),['needs_review','not_product','skipped'],true)))continue;
        $key=array_key_exists($id,$expected)?$id.'#row-'.($index+2):$id;$sku=molavi_domain_woo_sku($row);$item=$projected[$sku]??null;
        $entry=['product_id'=>$id,'product_name'=>$name,'display_name'=>$item?$text($item['name']??''):$name,'supplier'=>$item?$text($item['supplier']??''):'','source_record_id'=>$id,'channel'=>$channel,'id'=>$sku,
            'status'=>$item?$text($item['status']??''):($projection!==null?'hold':'no_projection'),'supplier_availability'=>$item?$text($item['supplier_availability']??''):'',
            'latest_offer_id'=>$text($row['Latest Offer ID']??''),'source_post_url'=>$text($row['Source Post URL']??''),'source_integrity_codes'=>$sourceIssues[$index]['codes']??[]];
        $expected[$key]=$entry;$keysById[$id][]=$key;if($entry['supplier']!==''&&$entry['display_name']!==''){$identity=molavi_domain_json([molavi_domain_audit_fold($entry['supplier']),molavi_domain_audit_fold(preg_replace('/\s+/u',' ',$entry['display_name']))]);$names[$identity]=($names[$identity]??0)+1;}
    }
    $states=[];foreach($expected as $key=>&$entry){$identity=molavi_domain_json([molavi_domain_audit_fold($entry['supplier']),molavi_domain_audit_fold(preg_replace('/\s+/u',' ',$entry['display_name']))]);$entry['supplier_name_ambiguous']=($names[$identity]??0)>1;$states[$key]=molavi_domain_woo_state($entry,$woo);}unset($entry);
    foreach($keysById as $keys)if(count($keys)>1)foreach($keys as $key)$states[$key]='ambiguous_product_id';
    $catalog=['products_checked'=>count($expected),'woo_state'=>$woo['state']];foreach(['purchasable','held','not_visible','sku_mismatch','not_purchasable','supplier_out_of_stock','held_but_purchasable','no_catalog_projection','not_checked','not_checked_catalog_truncated','ambiguous_product_id'] as $key)$catalog[$key]=0;
    foreach($states as $state){if(array_key_exists($state,$catalog))$catalog[$state]++;if($state==='sku_mismatch')$catalog['purchasable']++;}
    $details=[];$productStates=[];foreach($expected as $key=>$entry){$state=$states[$key];$base=['product_id'=>$entry['product_id'],'product_name'=>$entry['product_name'],'channel'=>$entry['channel'],'woo_sku'=>$entry['id']];
        $productStates[]=$base+['woo_state'=>$state,'product_status'=>$entry['status'],'latest_offer_id'=>$entry['latest_offer_id'],'source_post_url'=>$entry['source_post_url'],'source_integrity_codes'=>$entry['source_integrity_codes']];
        if(in_array($state,['not_visible','sku_mismatch','held_but_purchasable','ambiguous_product_id','no_catalog_projection','not_checked','not_checked_catalog_truncated'],true)){[$identity,$method]=molavi_domain_woo_identity_match($entry,$woo);
            $details[]=$base+['actual_woo_sku'=>$identity?$text($identity['sku']??''):'','woo_match_method'=>$method,'state'=>$state,'product_status'=>$entry['status'],'latest_offer_id'=>$entry['latest_offer_id'],'source_post_url'=>$entry['source_post_url'],'source_integrity_codes'=>$entry['source_integrity_codes']];}}
    $catalog['issues']=array_slice($details,0,$limit);$rows=[];$latencies=[];
    $totals=['receipts'=>count($data['receipts']),'message_data'=>0,'offers'=>0,'offers_needing_review'=>0,'products'=>0,'publication_done'=>0,'publication_pending'=>0,'woo_missing'=>$catalog['not_visible'],
        'woo_blocked'=>$catalog['not_purchasable']+$catalog['supplier_out_of_stock']+$catalog['held_but_purchasable'],'products_with_source_integrity_issues'=>count($issues)];
    $decisions=(array)($data['publication_by_decision']??[]);
    foreach($data['receipts'] as $receipt){$key=molavi_domain_json([$text($receipt['message_id']??''),$text($receipt['channel']??''),$text($receipt['batch_id']??'')]);$matched=$messageIndex[$key]??[];$message=$matched?end($matched):[];
        $joined=$receipt;$joined['_source_text']=$text($message['Content']??'');$matchedOffers=molavi_domain_audit_offers($joined,$offers);$offerIds=[];$legacyIds=[];$offersById=[];$ownerDecisions=[];
        foreach($matchedOffers as $offer){$oid=$text($offer['Offer ID']??'');$pid=$text($offer['Product ID']??'');if($oid!==''){$offerIds[$oid]=true;$offersById[$oid]=$offer;}if($pid!=='')$legacyIds[$pid]=true;if($text($offer['Owner Decision ID']??'')!=='')$ownerDecisions[]=$offer;}
        $current=[];foreach($legacyIds as $pid=>$_)foreach($byId[$pid]??[] as $product){$oid=$text($product['Latest Offer ID']??'');if($oid===''||isset($offerIds[$oid]))$current[$text($product['Product ID']??'')]=$product;}
        foreach($offerIds as $oid=>$_)foreach($byOffer[$oid]??[] as $product)$current[$text($product['Product ID']??'')]=$product;
        $currentOffers=[];$productDetails=[];$productIds=[];$statuses=[];$priceChecks=0;
        foreach($current as $product){$pid=$text($product['Product ID']??'');$oid=$text($product['Latest Offer ID']??'');$offer=$offersById[$oid]??[];if($oid!=='')$currentOffers[$oid]=true;if($pid!=='')$productIds[$pid]=true;if($text($product['Status']??'')!=='')$statuses[$text($product['Status'])]=true;
            $productDetails[]=['product_id'=>$pid,'name'=>$text($product['Product Name']??''),'status'=>$text($product['Status']??''),'supplier_price'=>$text($product['Sale Price']??''),
                'customer_price'=>$text(molavi_domain_py_value($product['Consumer Price']??'',($product['Actual Price']??''))),'price_basis'=>$text(molavi_domain_py_value($offer['Price Type']??'',($product['Price Type']??''))),
                'package_price'=>$text($offer['Package Price']??''),'package_count'=>$text(molavi_domain_py_value($product['Package Count']??'',($offer['Package Count']??''))),
                'reference_match_id'=>$text(molavi_domain_py_value($product['Reference Match ID']??'',($offer['Reference Match ID']??''))),'reference_approval_state'=>$text($product['Reference Approval State']??''),
                'reference_price_change_percent'=>$text($product['Reference Price Change Percent']??''),'source_post_url'=>$text(molavi_domain_py_value($product['Source Post URL']??'',($offer['Source Post URL']??''))),
                'woo_sku'=>molavi_domain_woo_sku($product),'latest_offer_id'=>$oid];
            $sale=molavi_domain_positive_price($product['Sale Price']??null);$consumer=molavi_domain_positive_price(molavi_domain_py_value($product['Consumer Price']??'',($product['Actual Price']??'')));if($sale>0&&$consumer>$sale)$priceChecks++;
        }
        $open=[];foreach($matchedOffers as $offer)if((!$currentOffers||isset($currentOffers[$text($offer['Offer ID']??'')]))&&strtolower($text($offer['Status']??''))==='needs_review')$open[]=$offer;
        $wooStates=[];$wooByProduct=[];foreach($productIds as $pid=>$_){$keys=$keysById[$pid]??[];$state=count($keys)===1?($states[$keys[0]]??'no_catalog_projection'):(count($keys)>1?'ambiguous_product_id':'no_catalog_projection');$wooByProduct[$pid]=$state;$wooStates[]=$state;}
        foreach($productDetails as &$product)$product['woo_state']=$wooByProduct[$product['product_id']]??'not_checked';unset($product);
        usort($productDetails,static fn($a,$b)=>strcmp($a['product_id'],$b['product_id']));sort($wooStates,SORT_STRING);
        $candidates=[];if($text($receipt['publication_state']??'')!=='not_queued')$candidates[]=['state'=>$text($receipt['publication_state']??''),'attempts'=>(int)($receipt['publication_attempts']??0),'error'=>$text($receipt['publication_error']??''),
            'created_at'=>$receipt['publication_created_at']??null,'updated_at'=>$receipt['publication_updated_at']??null,'pushed'=>!empty($receipt['publication_pushed']),'count'=>(int)($receipt['publication_count']??0)];
        foreach($ownerDecisions as $offer){$id=$text($offer['Owner Decision ID']??'');if(!empty($decisions[$id]))$candidates[]=$decisions[$id];}usort($candidates,static fn($a,$b)=>((float)($a['created_at']??0))<=>((float)($b['created_at']??0)));$latest=$candidates?end($candidates):[];$pState=$text($latest['state']??'')?:'not_queued';
        if($pState==='done')$totals['publication_done']++;elseif($pState!=='not_queued')$totals['publication_pending']++;$totals['message_data']+=(int)(bool)$message;$totals['offers']+=count($matchedOffers);$totals['offers_needing_review']+=count($open);$totals['products']+=count($current);
        $imported=$text($message['Import Timestamp']??'');if(!empty($receipt['received_at'])&&$imported!==''){$stamp=molavi_domain_timestamp($imported);if($stamp!==null)$latencies[]=max(0.0,$stamp-(float)$receipt['received_at']);}
        $productStatuses=array_map('strval',array_keys($statuses));sort($productStatuses);
        $rows[]=['message_id'=>$text($receipt['message_id']??''),'channel'=>$text($receipt['channel']??''),'source_timestamp'=>$text($receipt['source_timestamp']??''),'received_at'=>molavi_domain_audit_time($receipt['received_at']??null),
            'batch_state'=>$text($receipt['batch_state']??''),'batch_attempts'=>(int)($receipt['batch_attempts']??0),'batch_error'=>$text($receipt['batch_error']??''),'extraction_state'=>$text($message['AI Status']??'')?:'not_in_sheets',
            'offers'=>count($matchedOffers),'offers_needing_review'=>count($open),'owner_decisions'=>count($ownerDecisions),'product_count'=>count($current),'product_statuses'=>$productStatuses,'product_details'=>$productDetails,'price_order_checks'=>$priceChecks,
            'publication_state'=>$pState,'publication_attempts'=>(int)($latest['attempts']??0),'publication_error'=>$text($latest['error']??''),'publication_pushed'=>!empty($latest['pushed']),'woo_states'=>$wooStates,'source_row_found'=>(bool)$message];
    }
    sort($latencies,SORT_NUMERIC);$n=count($latencies);$median=$n?($n%2?$latencies[intdiv($n,2)]:($latencies[$n/2-1]+$latencies[$n/2])/2):null;
    return ['ok'=>true,'generated_at'=>$clock??molavi_domain_now(),'summary'=>$totals,
        'validation'=>['state'=>!$products||$projection===null||in_array($woo['state'],['unavailable','not_configured','truncated'],true)?'partial':'checked','catalog_projection'=>$projection!==null?'checked':'unavailable','woo_state'=>$woo['state'],'woo_checked_at'=>$woo['checked_at'],
            'woo_product_count'=>count($woo['products']),'expected_product_count'=>count($expected),'receipt_rows_shown'=>count($rows),'total_receipts'=>(int)$data['total_receipts'],'receipt_rows_truncated'=>$data['truncated'],'intake_to_sheet_latency_samples'=>$n,
            'intake_to_sheet_median_seconds'=>$median!==null?round($median,1,PHP_ROUND_HALF_EVEN):null,'ai_accuracy'=>'not_measured','ai_accuracy_note'=>'AI field accuracy needs an owner-labelled message set; this report shows operational outcomes only.'],
        'catalog_woo'=>$catalog,'product_states'=>array_slice($productStates,0,$limit),'product_states_truncated'=>count($productStates)>$limit,
        'source_integrity'=>['products_checked'=>count($products),'products_with_issues'=>count($issues),'issues'=>array_slice($issues,0,$limit),'issues_truncated'=>count($issues)>$limit],'receipts'=>$rows];
}
/** Use Woo's own Store API controller in-process; no HTTP request or bot wake. */
function molavi_domain_woo_catalog(?string $clock=null): array {
    $found=[];$pages=1;
    if(!function_exists('rest_do_request'))return ['state'=>'unavailable','products'=>[],'checked_at'=>''];
    try{
        for($page=1;$page<=$pages&&$page<=10;$page++){$request=new WP_REST_Request('GET','/wc/store/v1/products');$request->set_query_params(['per_page'=>100,'page'=>$page]);$response=rest_do_request($request);
            if(is_wp_error($response)||$response->get_status()!==200)return ['state'=>'unavailable','products'=>[],'checked_at'=>''];$rows=$response->get_data();
            if(!is_array($rows)||!array_is_list($rows))return ['state'=>'unavailable','products'=>[],'checked_at'=>''];
            foreach($rows as $row){if(!is_array($row))continue;$sku=molavi_domain_audit_text($row['sku']??'');if($sku!=='')$found[$sku]=['purchasable'=>molavi_domain_py_value($row['is_purchasable']??null,null)!==null,'in_stock'=>molavi_domain_py_value($row['is_in_stock']??null,null)!==null];}
            $headers=[];foreach($response->get_headers() as $key=>$value)$headers[strtolower((string)$key)]=$value;$total=filter_var($headers['x-wp-totalpages']??1,FILTER_VALIDATE_INT);$pages=$total===false?1:max(1,$total);
        }
        $identities=null;if(function_exists('molavi_catalog_identity_snapshot')){try{$candidate=molavi_catalog_identity_snapshot();if(is_array($candidate)&&array_is_list($candidate)&&count($candidate)<=2000)$identities=array_values(array_filter($candidate,'is_array'));}catch(Throwable $e){}}
        return ['state'=>$pages>10?'truncated':'checked','products'=>$found,'identity_records'=>$identities,'checked_at'=>$clock??molavi_domain_now()];
    }catch(Throwable $e){return ['state'=>'unavailable','products'=>[],'checked_at'=>''];}
}
function molavi_domain_inbox_recent(): array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db): array {
        $rows=molavi_domain_rows($db,"SELECT i.payload,i.received_at,i.batch_id,j.state,j.result,j.updated_at
            FROM inbox i LEFT JOIN jobs j ON j.id=i.batch_id ORDER BY i.rowid DESC LIMIT 12");
        $messages=[];
        foreach($rows as $row) {
            $source=molavi_domain_decode($row['payload']);
            $result=molavi_domain_decode($row['result']);
            $messages[]=[
                'received_at'=>molavi_domain_audit_time($row['received_at']),
                'source_id'=>(string)($source['id']??''),
                'batch_id'=>str_starts_with((string)$row['batch_id'],'batch:')?substr($row['batch_id'],6):'',
                'finished_at'=>in_array($row['state'],['done','failed','needs_review'],true)
                    ?molavi_domain_audit_time($row['updated_at']):null,
                'channel'=>(string)molavi_domain_py_value($source['channel_username']??null,$source['channel']??''),
                'excerpt'=>molavi_domain_context_text($source['content']??'',600),
                'state'=>$row['state']??'queued',
                'extraction_state'=>(string)($result['extraction_status']??''),
            ];
        }
        return ['ok'=>true,'messages'=>$messages];
    });
}
function molavi_domain_inbox_offer_summary(array $offers,array $products,string $batch,string $message,string $channel): array {
    $previous=[];
    $related=[];
    $current=[];
    foreach($products as $product) {
        $id=trim((string)($product['Product ID']??''));
        if ($id==='') continue;
        $identity=molavi_domain_product_identity($product);
        $related[molavi_domain_json(array_slice($identity,0,2))][] = [
            'product_id'=>$id,'name'=>(string)($product['Product Name']??''),
            'attributes'=>array_values(array_filter(array_map('strval',array_intersect_key($product,
                array_flip(['Variation Type','Volume','Weight','Packaging','Package Count']))),static fn($value)=>$value!=='')),
            'identity'=>$identity,
        ];
        $current[$id]=$identity;
    }
    $result=[];
    foreach($offers as $offer) {
        $id=trim((string)($offer['Offer ID']??''));
        if ($id==='' || !empty($offer['Derived From Offer ID'])) continue;
        $identity=molavi_domain_product_identity($offer);
        $offerBatch=(string)($offer['Batch ID']??'');
        $offerMessage=(string)($offer['Message ID']??'');
        $offerChannel=(string)molavi_domain_py_value($offer['Channel Username']??null,$offer['Channel']??'');
        $key=molavi_domain_json($identity);
        if ($offerBatch!==$batch || $offerMessage!==$message || $offerChannel!==$channel) {
            $previous[$key]=true;
            continue;
        }
        $similar=[];
        foreach($related[molavi_domain_json(array_slice($identity,0,2))]??[] as $product) {
            if ($product['identity']===$identity) continue;
            unset($product['identity']);
            $similar[]=$product;
            if (count($similar)>=3) break;
        }
        $productId=trim((string)($offer['Product ID']??''));
        $result[]=['offer_id'=>$id,'name'=>(string)($offer['Product Name']??''),
            'product_id'=>($current[$productId]??null)===$identity?$productId:'',
            'relationship'=>isset($previous[$key])?'known':($similar?'compare':'new'),
            'similar'=>$similar];
        if (count($result)>=20) break;
    }
    return $result;
}

function molavi_domain_inbox_offers(array $query): array {
    $batch=(string)($query['batch_id']??'');
    $message=(string)($query['message_id']??'');
    $channel=(string)($query['channel']??'');
    if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/D',$batch) || $message==='' || strlen($message)>100 || strlen($channel)>200)
        molavi_domain_fail('invalid_inbox_message',400);
    $tables=molavi_domain_sheets(['Products','Offers']);
    return ['ok'=>true,'offers'=>molavi_domain_inbox_offer_summary(molavi_domain_records($tables['Offers']),
        molavi_domain_records($tables['Products']),$batch,$message,$channel)];
}

function molavi_domain_reconciliation_api(array $query): array {
    if(($query['view']??'')==='inbox') return molavi_domain_inbox_recent();
    if(($query['view']??'')==='inbox-offers') return molavi_domain_inbox_offers($query);
    $raw=trim((string)($query['limit']??'200'));$limit=preg_match('/^[+-]?\d+$/D',$raw)?(int)$raw:200;$limit=max(1,min($limit?:200,500));
    $data=molavi_domain_receipt_data($limit);$tables=molavi_domain_sheets(['MessageData','Offers','Products','Shop'],true);$products=molavi_domain_records($tables['Products']);$offers=molavi_domain_records($tables['Offers']);
    $issues=molavi_domain_source_issues($products,$offers);try{$projection=molavi_domain_site_products($tables);}catch(Throwable $e){$projection=null;}
    return molavi_domain_reconciliation_render($data,$tables,$projection,$issues,molavi_domain_woo_catalog(),$limit);
}
