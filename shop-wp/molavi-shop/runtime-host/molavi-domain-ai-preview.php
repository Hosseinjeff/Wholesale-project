<?php
/** Plugin Name: Molavi Native Extraction Progress and Preview */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_run_status($run): ?array {
    if(!is_string($run)||!preg_match('/^[a-f0-9]{32}$/D',$run))return null;
    $rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,"SELECT id,state,error,result,payload FROM jobs WHERE kind='reextract' AND id LIKE ? ORDER BY id",['reextract:'.$run.':%']));if(!$rows)return null;
    $items=[];$counts=[];$scope='saved_messages';$caseCount=0;$digest='';$policy='';foreach($rows as $row){$state=(string)$row['state'];$counts[$state]=($counts[$state]??0)+1;$payload=molavi_domain_decode($row['payload']);
        $scope=(string)molavi_domain_py_value($payload['reextraction_scope']??null,$scope);$caseCount+=(int)molavi_domain_py_value($payload['case_count']??null,0);
        $digest=(string)molavi_domain_py_value($payload['reextraction_digest']??null,$digest);$policy=(string)molavi_domain_py_value($payload['policy_version']??null,$policy);
        $items[]=['job_id'=>$row['id'],'state'=>$state,'error'=>molavi_domain_py_value($row['error']),'result'=>molavi_domain_decode($row['result'],null)];}
    $completed=0;foreach(['done','failed','needs_review','cancelled'] as $state)$completed+=$counts[$state]??0;
    return ['run_id'=>$run,'total'=>count($items),'completed'=>$completed,'counts'=>$counts,'items'=>$items,'scope'=>$scope,'case_count'=>$caseCount,'digest'=>$digest,'policy_version'=>$policy,'state'=>$completed===count($items)?'complete':'running'];
}
function molavi_domain_ai_active_run(): ?array {
    $row=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,"SELECT payload FROM jobs WHERE kind='reextract' AND state IN ('queued','running') ORDER BY rowid LIMIT 1"));
    if(!$row)return null;$payload=molavi_domain_decode($row['payload']);$status=molavi_domain_ai_run_status((string)molavi_domain_py_value($payload['run_id']??null));
    if(!$status)return null;unset($status['items']);return $status;
}
function molavi_domain_ai_latest_run_id(string $offerId='', string $sourceKey=''): ?string {
    return molavi_domain_read('pipeline',static function(SQLite3 $db)use($offerId,$sourceKey): ?string {
        if ($offerId==='') {
            $job=molavi_domain_row($db,"SELECT id FROM jobs WHERE kind='reextract' ORDER BY rowid DESC LIMIT 1");
        } else {
            $job=molavi_domain_row($db,"SELECT id FROM jobs WHERE kind='reextract' AND json_valid(payload)
                AND (json_extract(payload,'$.requested_offer_id')=? OR (?<>'' AND json_extract(payload,'$.source_key')=?))
                ORDER BY rowid DESC LIMIT 1",[$offerId,$sourceKey,$sourceKey]);
        }
        return preg_match('/^reextract:([a-f0-9]{32}):/D',(string)($job['id']??''),$match)?$match[1]:null;
    });
}

function molavi_domain_ai_preview(array $query,array $tables,?float $clock=null,string $actor=''): array {
    $caps=molavi_domain_bot_capabilities();$images=$caps['image_extraction_enabled'];$inventory=molavi_domain_ai_inventory($tables,$images);
    $selection=null;
    if(!$images){$channels=$query['channels']??($query['channel']??[]);if(isset($query['channels'])){
            if(!is_string($channels)||strlen($channels)>25000)molavi_domain_fail('invalid_reextraction_filter',400);
            try{$channels=json_decode($channels,false,32,JSON_THROW_ON_ERROR);}catch(JsonException $e){molavi_domain_fail('invalid_reextraction_filter',400);}}
        elseif(is_string($channels))$channels=[$channels];
        $selection=molavi_domain_ai_selection($inventory,(string)molavi_domain_py_value($query['scope']??null,'all'),$channels,$query['date_from']??'',$query['date_to']??'',(string)molavi_domain_py_value($query['group_by']??null,'channel'));}
    if($selection!==null && ($query['selection']??'')==='1') {
        unset($selection['messages']);
        return ['ok'=>true,'ai_configured'=>$caps['ai_configured'],'active_run'=>molavi_domain_ai_active_run(),
            'selection'=>$selection,'selection_counts'=>['all'=>$inventory['counts']['all_available'],'pending'=>$inventory['counts']['pending']]];
    }
    $recent=molavi_domain_ai_recent_inventory(molavi_domain_ai_inventory($tables,$images,$caps['extraction_policy_version']),$caps['extraction_policy_version'],$images,$clock);
    $open=molavi_domain_ai_case_inventory($tables,$clock,$actor);$posts=$recent['messages'];usort($posts,static fn($a,$b)=>(molavi_domain_timestamp($b['timestamp']??null)??-62135596800.0)<=>(molavi_domain_timestamp($a['timestamp']??null)??-62135596800.0));$sourcePosts=[];
    foreach(array_slice($posts,0,12) as $item){$post=['source_key'=>$item['source_key'],'message_id'=>$item['message_id'],'channel'=>molavi_domain_py_value($item['channel']??null,molavi_domain_py_value($item['channel_username']??null)),
        'timestamp'=>molavi_domain_py_value($item['timestamp']??null),'source_post_url'=>molavi_domain_py_value($item['source_post_url']??null)];if($images)$post['media_ocr']=!empty($item['media_ocr']);$sourcePosts[]=$post;}
    $keys=array_column($inventory['pending_messages'],'message_key');sort($keys,SORT_STRING);$recentInfo=array_intersect_key($recent,array_flip(['policy_version','window_days','eligible_count','media_ocr_count','media_ocr_image_count','digest','unknown_or_future_timestamp_count','outside_window_count']));$recentInfo['source_posts']=$sourcePosts;
    $result=['ok'=>true,'counts'=>$inventory['counts'],'ai_configured'=>$caps['ai_configured'],'recent_policy'=>$recentInfo,'pending_digest'=>molavi_domain_hash($keys),
        'media_review_messages'=>[],'media_review_total'=>0,'media_review_next_after'=>null,'open_cases'=>['case_count'=>$open['case_count'],'message_count'=>$open['message_count'],
            'unmatched_case_count'=>count($open['unmatched_case_ids']),'digest'=>$open['digest']],'active_run'=>molavi_domain_ai_active_run()];
    if(($query['latest_run']??'')==='1') $result['latest_run_id']=molavi_domain_ai_latest_run_id();
    if(!$images){$result['image_extraction_enabled']=false;return $result;}
    $media=$inventory['media_review_messages'];$keys=array_column($media,'message_key');sort($keys,SORT_STRING);$result['media_review_digest']=molavi_domain_hash($keys);
    usort($media,static fn($a,$b)=>strcmp((string)($a['message_key']??''),(string)($b['message_key']??'')));$after=(string)($query['media_after']??'');
    if($after!==''&&!preg_match('/^[a-f0-9]{64}$/D',$after))molavi_domain_fail('invalid_media_page',400);
    $eligible=array_values(array_filter($media,static fn($item)=>strcmp((string)($item['message_key']??''),$after)>0));$page=array_slice($eligible,0,12);
    $result['media_review_messages']=$page;$result['media_review_total']=count($media);$result['media_review_after']=$after;
    $result['media_review_next_after']=count($eligible)>12?$page[count($page)-1]['message_key']:null;return $result;
}
function molavi_domain_ai_offer_preview(array $query,array $tables): array {
    $offerId=$query['offer_id']??null;
    if(!is_string($offerId)||trim($offerId)===''||strlen($offerId)>300) molavi_domain_fail('invalid_reextraction_request',400);
    $caps=molavi_domain_bot_capabilities();
    $policy=$caps['extraction_policy_version'];
    $selection=molavi_domain_ai_offer_selection($tables,$offerId,$policy);
    $message=$selection['messages'][0]??null;
    return [
        'ok'=>true,'offer_id'=>$offerId,'ai_configured'=>$caps['ai_configured'],'policy_version'=>$policy,
        'reason'=>$selection['reason'],'digest'=>$selection['digest']??null,'active_run'=>molavi_domain_ai_active_run(),
        'latest_run_id'=>molavi_domain_ai_latest_run_id($offerId,$selection['source_key']??''),
        'source'=>$message?array_intersect_key($message,array_flip(['timestamp','source_post_url'])):null,
    ];
}

function molavi_domain_ai_run_report(array $query,?array $tables,?float $clock=null): array {
    $run=$query['run_id']??'';$status=molavi_domain_ai_run_status($run);if(!$status)molavi_domain_fail('unknown_reextraction_run',404);$outcomes=[];$products=0;$offers=0;$reviewCases=0;
    foreach($status['items'] as $item){$result=$item['result']??[];$outcome=(string)molavi_domain_py_value($result['status']??null,molavi_domain_py_value($item['state']??null,'unknown'));
        $outcomes[$outcome]=($outcomes[$outcome]??0)+1;$reviewCases+=(int)($result['open_cases_remaining']??0);$products+=(int)molavi_domain_py_value($result['products_found']??null,0);$offers+=(int)molavi_domain_py_value($result['offers_written']??null,molavi_domain_py_value($result['staged_offers']??null,0));}
    $publication=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT state,result FROM jobs WHERE id=?',['publish:ai-reextraction:'.$run]));$response=$status;unset($response['items']);
    $response=['ok'=>true]+$response+['outcomes'=>$outcomes,'products_found'=>$products,'offers_written'=>$offers,'open_cases_remaining'=>$tables===null?$reviewCases:count(molavi_domain_ai_review_cases($tables,null,false,1000,$clock)),
        'catalog_publication'=>['state'=>$publication?(string)molavi_domain_py_value($publication['state']):'','result'=>$publication?molavi_domain_decode($publication['result'],(object)[]):(object)[]]];
    if($response['catalog_publication']['result']===[])$response['catalog_publication']['result']=(object)[];return $response;
}
