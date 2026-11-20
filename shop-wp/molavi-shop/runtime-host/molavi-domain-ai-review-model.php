<?php
/** Plugin Name: Molavi Native AI Review Read Model */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_decision_decode(array $row): array {$row['fields']=molavi_domain_decode($row['fields_json'],(object)[]);if($row['fields']===[]&&str_starts_with(ltrim($row['fields_json']),'{'))$row['fields']=(object)[];unset($row['fields_json']);$row['created_at']=(float)$row['created_at'];return $row;}
function molavi_domain_ai_decision_history(?string $case=null,int $limit=100): array {
    $rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM ai_confirmation_decisions'.($case!==null?' WHERE case_id=?':'').' ORDER BY created_at DESC LIMIT ?',
        $case!==null?[$case,$limit]:[$limit]));return array_map('molavi_domain_ai_decision_decode',$rows);
}
function molavi_domain_ai_reviewable(array $offer): bool {
    if(strtolower(trim((string)molavi_domain_py_value($offer['Status']??null)))!=='needs_review')return false;
    $raw=(string)molavi_domain_py_value($offer['Review Reason']??null);if($raw==='')return false;
    $parts=array_values(array_filter(array_map('trim',explode(';',strtolower($raw))),static fn($part)=>$part!==''));
    if(!$parts)return true;foreach($parts as $part)if(!str_starts_with($part,'reference:'))return true;return false;
}
function molavi_domain_ai_case(array $offer,?array $latest=null,?float $clock=null): ?array {
    $id=(string)molavi_domain_py_value($offer['Offer ID']??null);if($id===''||!molavi_domain_ai_reviewable($offer))return null;
    $now=$clock??microtime(true);$timestamp=molavi_domain_timestamp(molavi_domain_py_value($offer['Source Post Updated At']??null,molavi_domain_py_value($offer['Message Timestamp']??null,$offer['Source Post Created At']??null)));
    $old=$timestamp===null||$timestamp>$now||$now-$timestamp>=14*86400;$basis=trim((string)molavi_domain_py_value($offer['Source Timestamp Basis']??null));
    $case=['case_id'=>'ai:'.hash('sha256',$id),'offer_id'=>$id,'signature'=>molavi_domain_hash($offer)];
    $map=['product_name'=>'Product Name','channel'=>'Channel Username','source_channel'=>'Channel','batch_id'=>'Batch ID','message_id'=>'Message ID','message_timestamp'=>'Message Timestamp',
        'source_timestamp_basis'=>'Source Timestamp Basis','source_created_at'=>'Source Post Created At','source_updated_at'=>'Source Post Updated At','source_post_url'=>'Source Post URL',
        'original_message'=>'Original Message','media_ocr_text'=>'Media OCR Text','image_file_id'=>'Image File ID','image_file_unique_id'=>'Image File Unique ID',
        'brand'=>'Brand','category'=>'Category','variation_type'=>'Variation Type','volume'=>'Volume','weight'=>'Weight','reason'=>'Review Reason','price_type'=>'Price Type','currency'=>'Currency',
        'packaging'=>'Packaging','reference_match_state'=>'Reference Match State','reference_match_id'=>'Reference Match ID','reference_price_role_hint'=>'Reference Price Role Hint'];
    foreach($map as $key=>$field)$case[$key]=(string)molavi_domain_py_value($offer[$field]??null);
    $case['historical_reextract']=str_starts_with($case['batch_id'],'reextract:')&&$old;
    $case['source_current_check_required']=!in_array($basis,['supplier_channel_update','supplier_channel_forward'],true)||$old;
    foreach(['sale_price'=>'Sale Price','package_price'=>'Package Price','package_count'=>'Package Count','min_qty'=>'Min Qty'] as $key=>$field)$case[$key]=molavi_domain_py_value($offer[$field]??null);
    $case['detected_price']=molavi_domain_py_value($offer['Detected Price']??null,$case['sale_price']);
    $case['actual_price']=molavi_domain_py_value($offer['Actual Price']??null,molavi_domain_py_value($offer['Consumer Price']??null));
    $case['stock_status']=molavi_domain_stock_status($offer['Stock Status']??null);$case['previous_decision_id']=$latest['decision_id']??null;return $case;
}
function molavi_domain_telegram_source_url($value): string {
    $url=trim((string)molavi_domain_py_value($value));$parsed=parse_url($url);if(!is_array($parsed))return '';
    $host=strtolower($parsed['host']??'');$path=rtrim(preg_replace('~^/s(?=/)~','',$parsed['path']??''),'/');
    return strtolower($parsed['scheme']??'')==='https'&&in_array($host,['t.me','www.t.me'],true)&&preg_match('~(*UCP)^/[^/]+/\d+$~uD',$path)?'https://t.me'.$path:'';
}
function molavi_domain_ai_source_key(array $offer): string {
    $url=molavi_domain_telegram_source_url($offer['Source Post URL']??null);$channel=(string)molavi_domain_py_value($offer['Channel Username']??null);
    $stamp=(string)molavi_domain_py_value($offer['Message Timestamp']??null);$content=hash('sha256',(string)molavi_domain_py_value($offer['Original Message']??null));
    $identity=trim($stamp)!==''?['supplier_message',$channel,$stamp,$content]:($url!==''?['telegram_post',$url,$channel,$content]:['saved_message',$channel,(string)molavi_domain_py_value($offer['Message ID']??null),$content]);return molavi_domain_hash($identity);
}
function molavi_domain_unicode_fold($value): string {
    $text=(string)molavi_domain_py_value($value);if(class_exists('Normalizer'))$text=Normalizer::normalize($text,Normalizer::FORM_KC);
    return function_exists('mb_convert_case')?mb_convert_case($text,MB_CASE_FOLD,'UTF-8'):strtolower($text);
}
function molavi_domain_ai_duplicate_key(array $offer): ?string {
    $url=molavi_domain_telegram_source_url($offer['Source Post URL']??null);if($url==='')return null;
    $normalize=static fn($value)=>trim(preg_replace('/\s+/u',' ',molavi_domain_digits(molavi_domain_unicode_fold($value))));
    $name=$normalize($offer['Product Name']??null);$original=$normalize($offer['Original Message']??null);if($name===''||$original==='')return null;
    $parts=[$url,$name,$original];foreach(['Channel Username','Channel','Sale Price','Detected Price'] as $field)$parts[]=$normalize($offer[$field]??null);
    $parts[]=$normalize(molavi_domain_py_value($offer['Actual Price']??null,$offer['Consumer Price']??null));
    foreach(['Package Price','Package Count','Min Qty','Currency','Stock Status'] as $field)$parts[]=$normalize($offer[$field]??null);return molavi_domain_hash($parts);
}
function molavi_domain_ai_variant_text($value): string {preg_match_all('/[\pL\pN_]+/u',molavi_domain_unicode_fold($value),$m);return implode(' ',$m[0]);}
function molavi_domain_ai_variant_groups(array $cases): array {
    $groups=[];$termsFields=['source_channel','reason','sale_price','detected_price','actual_price','package_price','package_count','min_qty','price_type','currency',
        'packaging','volume','weight','brand','category','stock_status','historical_reextract','reference_match_state','reference_match_id','reference_price_role_hint','previous_decision_id'];
    foreach($cases as $index=>$case){unset($cases[$index]['variant_group_id']);$variant=molavi_domain_ai_variant_text($case['variation_type']??null);$source=[$case['channel']??null,$case['message_id']??null,$case['batch_id']??null];
        $name=molavi_domain_ai_variant_text($case['product_name']??null);if($variant!=='')$name=trim(preg_replace('/\s+/u',' ',preg_replace('~(*UCP)(?<!\w)'.preg_quote($variant,'~').'(?!\w)~u',' ',$name)));
        $hasPrice=false;foreach(['sale_price','detected_price','package_price'] as $field)if((float)preg_replace('/\D/u','',molavi_domain_digits(molavi_domain_py_value($case[$field]??null)))>0)$hasPrice=true;
        if($variant===''||in_array(null,$source,true)||in_array('',$source,true)||$name===''||!$hasPrice)continue;
        $terms=array_map(static fn($field)=>$case[$field]??null,$termsFields);$key=molavi_domain_json([$source,$name,$terms]);$groups[$key][]=['index'=>$index,'variant'=>$variant];}
    foreach($groups as $key=>$members){if(count($members)<2||count(array_unique(array_column($members,'variant')))<2)continue;$id=hash('sha256',$key);foreach($members as $member)$cases[$member['index']]['variant_group_id']=$id;}return $cases;
}
function molavi_domain_ai_find_case(array $offers,string $case,string $id,?float $clock=null): ?array {
    $source=null;foreach($offers as $offer)if((string)molavi_domain_py_value($offer['Offer ID']??null)===$id){$source=$offer;break;}
    if(!$source||'ai:'.hash('sha256',$id)!==$case)return null;
    if(!str_starts_with((string)molavi_domain_py_value($source['Batch ID']??null),'reextract:'))foreach($offers as $offer)
        if(str_starts_with((string)molavi_domain_py_value($offer['Batch ID']??null),'reextract:')&&[(string)molavi_domain_py_value($offer['Message ID']??null),(string)molavi_domain_py_value($offer['Channel Username']??null)]===
            [(string)molavi_domain_py_value($source['Message ID']??null),(string)molavi_domain_py_value($source['Channel Username']??null)])return null;
    return molavi_domain_ai_case($source,molavi_domain_ai_decision_history($case,1)[0]??null,$clock);
}
function molavi_domain_ai_candidates(array $case,array $snapshot): array {
    return molavi_domain_reference_candidates([
        'name'=>$case['product_name'],'clean_name'=>$case['product_name'],'brand'=>$case['brand'],
        'category'=>$case['category'],'variant'=>$case['variation_type'],'volume'=>$case['volume'],
        'weight'=>$case['weight'],'package_count'=>$case['package_count'],
        'supplier_price'=>molavi_domain_py_value($case['detected_price'],$case['sale_price']),
    ],$snapshot['rows']??[],12);
}
function molavi_domain_ai_review_cases(array $tables,?array $snapshot=null,bool $history=false,int $limit=200,?float $clock=null,bool $candidates=true): array {
    $offers=molavi_domain_records($tables['Offers']??[]);$snapshotId=(string)($snapshot['snapshot_id']??'');$referenceNames=[];
    foreach($snapshot['rows']??[] as $row)if(is_array($row)&&count($row)>16)$referenceNames[(string)molavi_domain_py_value($row[16],molavi_domain_py_value($row[2]))]=(string)molavi_domain_py_value($row[0]);
    $data=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>['decisions'=>molavi_domain_rows($db,'SELECT d.* FROM ai_confirmation_decisions d WHERE rowid=(SELECT p.rowid FROM ai_confirmation_decisions p WHERE p.case_id=d.case_id ORDER BY p.created_at DESC,p.rowid DESC LIMIT 1)'),
        'source_results'=>molavi_domain_rows($db,'SELECT * FROM ai_source_reextractions')]);$latest=[];$sourceResults=[];$referenceDecisions=[];
    foreach($data['decisions'] as $row)$latest[$row['case_id']]=molavi_domain_ai_decision_decode($row);foreach($data['source_results'] as $row)$sourceResults[$row['source_key']]=$row;
    if($snapshotId!=='')foreach(molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM reference_item_decisions WHERE snapshot_id=? ORDER BY sequence',[$snapshotId])) as $row)$referenceDecisions[$row['case_id']]=$row;
    $markers=[];$latestBatch=[];$cases=[];$duplicates=[];$all=[];
    foreach($offers as $offer)if(str_starts_with((string)molavi_domain_py_value($offer['Batch ID']??null),'reextract:')){
        $markers[molavi_domain_json([(string)molavi_domain_py_value($offer['Message ID']??null),(string)molavi_domain_py_value($offer['Channel Username']??null)])]=true;
        $latestBatch[molavi_domain_ai_source_key($offer)]=(string)$offer['Batch ID'];}
    foreach($offers as $offer){$id=(string)molavi_domain_py_value($offer['Offer ID']??null);if($id==='')continue;$caseId='ai:'.hash('sha256',$id);$decision=molavi_domain_ai_reviewable($offer)?($latest[$caseId]??null):null;
        $batch=(string)molavi_domain_py_value($offer['Batch ID']??null);$marker=molavi_domain_json([(string)molavi_domain_py_value($offer['Message ID']??null),(string)molavi_domain_py_value($offer['Channel Username']??null)]);
        $key=molavi_domain_ai_source_key($offer);$superseded=isset($markers[$marker])&&!str_starts_with($batch,'reextract:');$source=$sourceResults[$key]??[];$sourceBatch=($source['outcome']??null)==='products'?($source['batch_id']??null):null;
        if($sourceBatch)$superseded=$superseded||$batch!==$sourceBatch;elseif(str_starts_with($batch,'reextract:'))$superseded=$superseded||$batch!==($latestBatch[$key]??null);elseif(isset($latestBatch[$key]))$superseded=true;
        $case=$superseded?null:molavi_domain_ai_case($offer,$decision,$clock);
        if($case){$case['reference_match_name']=$referenceNames[(string)molavi_domain_py_value($case['reference_match_id'])]??'';$case['reference_candidates']=[];
            if($snapshotId!==''){$case['reference_snapshot_id']=$snapshotId;$attrs=molavi_domain_offer_attributes($offer);$refDecision=$referenceDecisions[molavi_domain_reference_case_id($id,$snapshotId)]??null;
                if($refDecision&&$refDecision['snapshot_id']===$snapshotId&&$refDecision['signature']===molavi_domain_reference_signature($attrs)){$refId=(string)molavi_domain_py_value($refDecision['reference_id']);
                    $case['reference_assignment']=['decision_id'=>$refDecision['decision_id'],'snapshot_id'=>$snapshotId,'choice'=>$refDecision['choice'],'reference_id'=>$refId,'reference_name'=>$referenceNames[$refId]??$refId,'price_role'=>$refDecision['price_role']];}
                if($candidates)$case['reference_candidates']=molavi_domain_ai_candidates($case,$snapshot);}
            $decided=(bool)($decision&&$decision['signature']===$case['signature']);$duplicate=molavi_domain_ai_duplicate_key($offer);
            if($duplicate!==null)$duplicates[$duplicate][]=[$case,$decided];elseif(!$decided)$cases[]=$case;}$all[$id]=[$offer,$decision];
    }
    foreach($duplicates as $group){$pending=[];$decided=false;foreach($group as [$case,$done]){if($done)$decided=true;else $pending[]=$case;}if($decided||!$pending)continue;$case=end($pending);if(count($group)>1)$case['duplicate_count']=count($group);$cases[]=$case;}
    usort($cases,static fn($a,$b)=>strcmp($b['message_timestamp'],$a['message_timestamp'])?:strcmp($b['offer_id'],$a['offer_id']));$cases=molavi_domain_ai_variant_groups($cases);
    if(!$history)return array_slice($cases,0,$limit);$recent=[];$seen=[];
    foreach(molavi_domain_ai_decision_history(null,$limit) as $decision){if(isset($seen[$decision['case_id']]))continue;$seen[$decision['case_id']]=true;$pair=$all[$decision['offer_id']]??null;if(!$pair)continue;$source=$pair[0];
        $marker=molavi_domain_json([(string)molavi_domain_py_value($source['Message ID']??null),(string)molavi_domain_py_value($source['Channel Username']??null)]);
        if(isset($markers[$marker])&&!str_starts_with((string)molavi_domain_py_value($source['Batch ID']??null),'reextract:'))continue;
        $case=molavi_domain_ai_case($source,molavi_domain_ai_reviewable($source)?($latest['ai:'.hash('sha256',(string)molavi_domain_py_value($source['Offer ID']??null))]??null):null,$clock);
        if($case)$recent[]=$case+['decision'=>$decision];}
    $ids=array_values(array_unique(array_merge(array_column(array_slice($cases,0,$limit),'case_id'),array_column(array_slice($recent,0,$limit),'case_id'))));$histories=[];
    if($ids){$rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM ai_confirmation_decisions WHERE case_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY created_at DESC LIMIT 2000',$ids));
        foreach($rows as $row){$id=$row['case_id'];if(count($histories[$id]??[])<10)$histories[$id][]=molavi_domain_ai_decision_decode($row);}}
    $recent=molavi_domain_ai_variant_groups($recent);foreach($cases as &$case)$case['decision_history']=$histories[$case['case_id']]??[];unset($case);foreach($recent as &$case)$case['decision_history']=$histories[$case['case_id']]??[];unset($case);
    return [array_slice($cases,0,$limit),array_slice($recent,0,$limit)];
}
function molavi_domain_ai_hold_scope(): array {
    $scope=['global'=>false,'batch_ids'=>[],'source_messages'=>[]];foreach(molavi_domain_publication_holds()['holds'] as $hold){$id=(string)molavi_domain_py_value($hold['hold_id']);if($id!=='')$scope['batch_ids'][]=$id;
        $messages=$hold['context']['messages']??[];if(!$messages){$scope['global']=true;continue;}foreach($messages as $message){$id=(string)molavi_domain_py_value($message['message_id']);$channel=(string)molavi_domain_py_value($message['channel']);
            if($id!==''&&$channel!=='')$scope['source_messages'][]=[$id,$channel];}}return $scope;
}
function molavi_domain_ai_held(array $scope,array $case): bool {
    return $scope['global']||in_array((string)molavi_domain_py_value($case['batch_id']??null),$scope['batch_ids'],true)||
        ((string)molavi_domain_py_value($case['message_id']??null)!==''&&(string)molavi_domain_py_value($case['channel']??null,$case['channel_username']??null)!==''&&in_array([(string)molavi_domain_py_value($case['message_id']??null),(string)molavi_domain_py_value($case['channel']??null,$case['channel_username']??null)],$scope['source_messages'],true));
}
