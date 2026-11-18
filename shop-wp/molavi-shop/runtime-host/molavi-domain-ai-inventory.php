<?php
/** Plugin Name: Molavi Native Extraction Inventory */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_message_key(array $message): string {
    $parts=[];foreach(['message_id','batch_id','channel_username'] as $field)$parts[]=(string)molavi_domain_py_value($message[$field]??null);
    $parts[]=hash('sha256',(string)molavi_domain_py_value($message['content']??null));$parts[]=(string)molavi_domain_py_value($message['timestamp']??null);
    $media=(string)molavi_domain_py_value($message['media_identity']??null,molavi_domain_py_value($message['image_file_unique_id']??null));if($media!=='')$parts[]=$media;return molavi_domain_hash($parts);
}
function molavi_domain_ai_inventory(array $tables,bool $images,?string $policy=null): array {
    $offers=molavi_domain_records($tables['Offers']??[]);$messages=molavi_domain_records($tables['MessageData']??[]);$owners=[];$staged=[];
    foreach($offers as $offer){$id=(string)molavi_domain_py_value($offer['Message ID']??null);if($id==='')continue;$marker=molavi_domain_json([$id,(string)molavi_domain_py_value($offer['Channel Username']??null)]);
        if(trim((string)molavi_domain_py_value($offer['Owner Decision ID']??null))!=='')$owners[$marker]=true;
        if(str_starts_with((string)molavi_domain_py_value($offer['Batch ID']??null),'reextract:')&&strtolower(trim((string)molavi_domain_py_value($offer['Status']??null)))==='needs_review')$staged[$marker]=true;}
    $data=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>['dismissed'=>molavi_domain_rows($db,'SELECT message_key FROM ai_message_dismissals'),'sources'=>molavi_domain_rows($db,'SELECT * FROM ai_source_reextractions')]);
    $dismissed=array_fill_keys(array_column($data['dismissed'],'message_key'),true);$reprocessed=[];foreach($data['sources'] as $source)if($policy!==null?($source['policy_version']===$policy):($source['outcome']==='products'))$reprocessed[$source['source_key']]=true;
    $seen=[];$all=[];$pending=[];$media=[];$counts=['all_available'=>0,'pending'=>0,'without_text'=>0,'media_review'=>0,'media_without_file'=>0,'unsupported_media'=>0,
        'owner_decisions_preserved'=>0,'not_product_preserved'=>0,'no_product_candidates'=>0,'already_staged'=>0,'already_reprocessed'=>0,'dismissed_by_owner'=>0];$attachments=0;
    foreach($messages as $message){$id=trim((string)molavi_domain_py_value($message['ID']??null));if($id==='')continue;$batch=trim((string)molavi_domain_py_value($message['Batch ID']??null));$channel=trim((string)molavi_domain_py_value($message['Channel Username']??null));
        $content=(string)molavi_domain_py_value($message['Content']??null);$image=trim((string)molavi_domain_py_value($message['Image File ID']??null));$unique=trim((string)molavi_domain_py_value($message['Image File Unique ID']??null));$unique=$unique!==''?$unique:$image;
        $type=trim((string)molavi_domain_py_value($message['Media Type']??null,'image'));$album=trim((string)molavi_domain_py_value($message['Media Group ID']??null));
        $hasMedia=in_array(strtolower(trim(molavi_domain_cell_text(molavi_domain_py_value($message['Has Media']??null)))),['true','1','yes'],true)||$image!=='';
        $mediaId=$unique!==''?$unique:($album!==''?'group:'.$album:($hasMedia?'type:'.$type:''));$identity=molavi_domain_json([$id,$batch,$channel,$content,$unique]);if(isset($seen[$identity]))continue;$seen[$identity]=true;
        $marker=molavi_domain_json([$id,$channel]);$status=strtolower(trim((string)molavi_domain_py_value($message['AI Status']??null)));$mediaNoProduct=in_array($status,['no_product_candidate','not_product'],true);
        $mediaCandidate=$hasMedia&&(trim($content)===''||$mediaNoProduct);$needsImage=$mediaCandidate&&in_array(strtolower($type),['image','photo','document','image_document','document_image'],true);
        $sourceKey=molavi_domain_ai_source_key(['Source Post URL'=>$message['URL']??'','Channel Username'=>$channel,'Message ID'=>$id,'Message Timestamp'=>(string)molavi_domain_py_value($message['Timestamp']??null),'Original Message'=>$content]);
        if(isset($reprocessed[$sourceKey])){$counts['already_reprocessed']++;continue;}
        $item=['message_id'=>$id,'batch_id'=>$batch,'channel_username'=>$channel,'channel'=>(string)molavi_domain_py_value($message['Channel']??null),'timestamp'=>(string)molavi_domain_py_value($message['Timestamp']??null),
            'content'=>$content,'source_post_url'=>(string)molavi_domain_py_value($message['URL']??null)];
        $key=molavi_domain_ai_message_key($item+['media_identity'=>($images?$mediaCandidate:trim($content)==='')?$mediaId:'']);
        $legacy=molavi_domain_ai_message_key($item+['media_identity'=>$mediaCandidate?$mediaId:'']);
        if(isset($dismissed[$key])||(!$images&&isset($dismissed[$legacy]))){$counts['dismissed_by_owner']++;continue;}
        if($status==='owner_rejected'){$counts['owner_decisions_preserved']++;$counts['not_product_preserved']++;continue;}
        if(isset($owners[$marker])){$counts['owner_decisions_preserved']++;continue;}if(isset($staged[$marker])&&$policy===null){$counts['already_staged']++;continue;}
        if($images){if($mediaCandidate&&!$needsImage){$counts['unsupported_media']++;if(trim($content)==='')continue;}
            if($needsImage){if(trim($content)==='')$counts['without_text']++;if($image==='')$counts['media_without_file']++;
                $media[]=$item+['media_type'=>$type,'media_group_id'=>$album,'image_file_id'=>$image,'image_file_unique_id'=>$unique,'media_identity'=>$mediaId,'message_key'=>$key];
                if($mediaNoProduct)$counts['no_product_candidates']++;continue;}}
        elseif(trim($content)===''){$counts['without_text']++;if($hasMedia)$attachments++;continue;}
        $item+=['ai_status'=>$status==='not_product'?'no_product_candidate':$status,'message_key'=>$key];$all[]=$item;
        if($item['ai_status']==='no_product_candidate')$counts['no_product_candidates']++;
        if(in_array($item['ai_status'],['','pending_ai','needs_confirmation','needs_review','pending','not_product','no_product_candidate'],true))$pending[]=$item;
    }
    $counts['all_available']=count($all);$counts['pending']=count($pending);$counts['media_review']=count($media);
    $result=['all_messages'=>$all,'pending_messages'=>$pending,'media_review_messages'=>$media,'counts'=>$counts];
    if(!$images){$result+=['media_albums'=>[],'media_album_message_keys'=>[],'blocked_media_album_message_keys'=>[]];$result['counts']+=['media_review_images'=>0,'media_review_missing_images'=>0,'attachment_only'=>$attachments];}return $result;
}
function molavi_domain_ai_saved_source_key(array $item): string {
    return molavi_domain_ai_source_key([
        'Source Post URL'=>$item['source_post_url']??'', 'Channel Username'=>$item['channel_username']??'',
        'Message ID'=>$item['message_id']??'', 'Message Timestamp'=>$item['timestamp']??'', 'Original Message'=>$item['content']??'',
    ]);
}

function molavi_domain_ai_offer_selection(array $tables, string $offerId, string $policy): array {
    $offers=molavi_domain_records($tables['Offers']??[]);
    $matches=array_values(array_filter($offers,static fn($offer)=>(string)($offer['Offer ID']??'')===$offerId));
    if(count($matches)!==1) return ['reason'=>'missing_offer','messages'=>[]];
    $offer=$matches[0];
    $sourceKey=molavi_domain_ai_source_key($offer);
    $source=['source_key'=>$sourceKey];
    $sameSource=array_filter($offers,static fn($row)=>molavi_domain_ai_source_key($row)===$sourceKey);
    foreach($sameSource as $row) {
        if(trim((string)($row['Owner Decision ID']??''))!=='') return ['reason'=>'owner_decision','messages'=>[]]+$source;
    }
    $previous=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
        'SELECT policy_version FROM ai_source_reextractions WHERE source_key=? ORDER BY completed_at DESC LIMIT 1',[$sourceKey]));
    $lastPolicy=(string)($previous['policy_version']??'');
    if($lastPolicy===$policy) return ['reason'=>'already_current','messages'=>[]]+$source;
    $inventory=molavi_domain_ai_inventory($tables,false,$policy);
    $messages=array_values(array_filter($inventory['all_messages'],
        static fn($message)=>molavi_domain_ai_saved_source_key($message)===$sourceKey));
    if(count($messages)>1) {
        $messageId=trim((string)($offer['Message ID']??''));
        $batchId=trim((string)($offer['Batch ID']??''));
        $exact=array_values(array_filter($messages,static fn($message)=>
            $message['message_id']===$messageId && $message['batch_id']===$batchId));
        if(count($exact)===1) $messages=$exact;
    }
    if(count($messages)!==1) return ['reason'=>count($messages)?'ambiguous_source':'source_unavailable','messages'=>[]]+$source;
    $item=$messages[0];
    if(molavi_domain_ai_held(molavi_domain_ai_hold_scope(),$item)) return ['reason'=>'held','messages'=>[]]+$source;
    return [
        'reason'=>$lastPolicy!==''?'updated_policy':'ready',
        'messages'=>[$item+$source],
        'digest'=>molavi_domain_hash([$policy,$offerId,$sourceKey,$item['message_key']]),
    ]+$source;
}

function molavi_domain_ai_case_inventory(array $tables,?float $clock=null): array {
    $cases=molavi_domain_ai_review_cases($tables,null,false,1000,$clock);$groups=[];$unmatched=[];$identities=[];$messages=[];
    foreach($cases as $case){$identities[]=[$case['case_id'],$case['offer_id'],$case['signature']];$content=(string)molavi_domain_py_value($case['original_message']);if(trim($content)===''){$unmatched[]=$case['case_id'];continue;}
        $key=molavi_domain_ai_source_key(['Source Post URL'=>$case['source_post_url'],'Channel Username'=>$case['channel'],'Message ID'=>$case['message_id'],'Message Timestamp'=>$case['message_timestamp'],'Original Message'=>$content]);
        if(!isset($groups[$key]))$groups[$key]=['cases'=>[],'content'=>$content];$groups[$key]['cases'][]=$case;}
    ksort($groups,SORT_STRING);foreach($groups as $key=>$group){$case=$group['cases'][0];$hash=hash('sha256',$group['content']);$timestamp=(string)molavi_domain_py_value($case['message_timestamp']);$ids=array_column($group['cases'],'case_id');sort($ids,SORT_STRING);
        $messages[]=['message_id'=>(string)molavi_domain_py_value($case['message_id'],$case['offer_id']),'batch_id'=>(string)molavi_domain_py_value($case['batch_id']),
            'channel_username'=>(string)molavi_domain_py_value($case['channel']),'channel'=>(string)molavi_domain_py_value($case['source_channel']),'timestamp'=>$timestamp,'content_sha256'=>$hash,
            'message_key'=>molavi_domain_hash(['case-source',$key,$case['offer_id'],$case['signature'],$hash,$timestamp]),'source_message_id'=>(string)molavi_domain_py_value($case['message_id']),
            'source_batch_id'=>(string)molavi_domain_py_value($case['batch_id']),'source_offer_id'=>(string)$case['offer_id'],'source_offer_signature'=>(string)$case['signature'],
            'source_key'=>$key,'source_post_url'=>(string)molavi_domain_py_value($case['source_post_url']),'case_ids'=>$ids,'case_count'=>count($group['cases'])];}
    sort($identities);$messageIdentities=[];foreach($messages as $message)$messageIdentities[]=[$message['source_key'],$message['source_offer_id'],$message['source_offer_signature'],$message['content_sha256']];sort($messageIdentities);$sorted=$unmatched;sort($sorted,SORT_STRING);
    return ['case_count'=>count($cases),'message_count'=>count($messages),'unmatched_case_ids'=>$unmatched,'digest'=>molavi_domain_hash(['cases'=>$identities,'messages'=>$messageIdentities,'unmatched'=>$sorted]),'messages'=>$messages];
}
function molavi_domain_ai_recent_inventory(array $inventory,string $policy,bool $images,?float $clock=null): array {
    $now=$clock??microtime(true);$candidates=$inventory['all_messages'];if($images)foreach($inventory['media_review_messages'] as $item)if(trim((string)($item['image_file_id']??''))!=='')$candidates[]=$item+['media_ocr'=>true];
    $eligible=[];$unknown=0;$old=0;foreach($candidates as $item){$stamp=molavi_domain_timestamp($item['timestamp']??null);if($stamp===null||$stamp>$now){$unknown++;continue;}if($now-$stamp>=14*86400){$old++;continue;}
        $item['source_key']=molavi_domain_ai_saved_source_key($item);$eligible[]=$item;}
    $identities=[];$media=0;foreach($eligible as $item){$identities[]=[$item['source_key'],$item['message_key'],$item['message_id'],$item['batch_id'],$item['timestamp']];if(!empty($item['media_ocr']))$media++;}sort($identities);
    $result=['policy_version'=>$policy,'window_days'=>14,'digest'=>molavi_domain_hash(['policy_version'=>$policy,'window_seconds'=>14*86400,'messages'=>$identities]),'messages'=>$eligible,'eligible_count'=>count($eligible),
        'media_ocr_count'=>$media,'unknown_or_future_timestamp_count'=>$unknown,'outside_window_count'=>$old];if(!$images)$result['media_ocr_image_count']=0;return $result;
}
function molavi_domain_ai_selection(array $inventory,string $scope='all',$channels=null,$from='',$to='',string $groupBy='channel'): array {
    if(!in_array($scope,['all','pending','linked'],true)||!in_array($groupBy,['channel','date'],true))molavi_domain_fail('invalid_reextraction_filter',400);
    if($channels===null)$channels=[];if(!is_array($channels)||!array_is_list($channels)||count($channels)>30)molavi_domain_fail('invalid_reextraction_filter',400);
    foreach($channels as $value)if(!is_string($value)||molavi_domain_strlen($value)>200)molavi_domain_fail('invalid_reextraction_filter',400);
    $channelKey=static fn($value)=>molavi_domain_unicode_fold(ltrim(trim((string)molavi_domain_py_value($value)),'@'));$labels=[];
    foreach($inventory['all_messages'] as $item){$value=trim((string)molavi_domain_py_value($item['channel_username']??null));$key=$channelKey($value);if($key!==''&&!isset($labels[$key]))$labels[$key]=$value;}
    $keys=[];foreach($channels as $value){$key=$channelKey($value);if($key!=='')$keys[$key]=true;}if(array_diff(array_keys($keys),array_keys($labels)))molavi_domain_fail('invalid_reextraction_filter',400);
    $first=trim((string)molavi_domain_py_value($from));$last=trim((string)molavi_domain_py_value($to));
    try{if($first!=='')molavi_domain_date($first);if($last!=='')molavi_domain_date($last);}catch(MolaviWorkflowError $e){molavi_domain_fail('invalid_reextraction_filter',400);}
    if($first!==''&&$last!==''&&strcmp($first,$last)>0)molavi_domain_fail('invalid_reextraction_filter',400);
    $pending=array_fill_keys(array_column($inventory['pending_messages'],'message_key'),true);$selected=[];$dates=[];
    foreach($inventory['all_messages'] as $item){if($scope==='pending'&&!isset($pending[$item['message_key']]))continue;if($scope==='linked'&&empty($item['catalog_product_ids']))continue;
        $channel=$channelKey($item['channel_username']??null);if($keys&&!isset($keys[$channel]))continue;$stamp=molavi_domain_timestamp($item['timestamp']??null);
        $day=$stamp===null?null:(new DateTimeImmutable('@'.(string)(int)$stamp))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');
        if(($first!==''||$last!=='')&&($day===null||($first!==''&&strcmp($day,$first)<0)||($last!==''&&strcmp($day,$last)>0)))continue;$selected[]=$item;$dates[$item['message_key']]=$day;}
    $ordered=array_map('strval',array_keys($keys));sort($ordered,SORT_STRING);$messageKeys=array_column($selected,'message_key');sort($messageKeys,SORT_STRING);
    $digest=molavi_domain_hash(['scope'=>$scope,'channels'=>$ordered,'date_from'=>(string)molavi_domain_py_value($from),'date_to'=>(string)molavi_domain_py_value($to),'message_keys'=>$messageKeys]);$groups=[];
    foreach($selected as $item){if($groupBy==='date'){$key=$dates[$item['message_key']]??'';$label=$key!==''?$key:'زمان ثبت نشده';}
        else{$raw=trim((string)molavi_domain_py_value($item['channel_username']??null));$key=$channelKey($raw);$label=$raw!==''?$raw:(string)molavi_domain_py_value($item['channel']??null,'منبع نامشخص');}
        if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'label'=>$label,'count'=>0];$groups[$key]['count']++;}
    $groups=array_values($groups);usort($groups,static fn($a,$b)=>($a['key']===''?1:0)<=>($b['key']===''?1:0)?:($groupBy==='date'?strcmp($b['key'],$a['key']):strcmp($a['key'],$b['key'])));$sourceChannels=[];
    foreach($inventory['all_messages'] as $item){$key=$channelKey($item['channel_username']??null);if($key==='')continue;if(!isset($sourceChannels[$key]))$sourceChannels[$key]=['value'=>$labels[$key],'count'=>0];$sourceChannels[$key]['count']++;}ksort($sourceChannels,SORT_STRING);
    return ['scope'=>$scope,'count'=>count($selected),'digest'=>$digest,'channels'=>$ordered,'date_from'=>(string)molavi_domain_py_value($from),'date_to'=>(string)molavi_domain_py_value($to),
        'group_by'=>$groupBy,'groups'=>$groups,'source_channels'=>array_values($sourceChannels),'messages'=>$selected];
}
