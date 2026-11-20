<?php
/** Plugin Name: Molavi Native AI Review Decisions */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_reviews_api(string $method, array $query, ?array $body, string $actor): array {
    if ($method==='GET') {
        $mode = $query['reextract'] ?? '';
        $view = $query['view'] ?? '';
        if ($view==='workbench-offer') {
            $id=$query['offer_id']??'';
            if(!is_string($id)||$id===''||strlen($id)>300)molavi_domain_fail('invalid_query',400);
            $tables=molavi_domain_sheets(['Offers','Products']);
            $data=molavi_domain_ai_reviews_get($tables,null,false,$id);
            if($data['cases'])return $data;
            return $data+molavi_domain_reference_extracted_offers(molavi_domain_records($tables['Offers']),$id,true);
        }
        if ($view==='workbench-candidates') {
            $id=$query['offer_id']??'';
            if(!is_string($id)||$id===''||strlen($id)>300)molavi_domain_fail('invalid_query',400);
            $snapshot=molavi_domain_reference_active();
            $use=molavi_domain_reference_use();
            if(!$snapshot||!$use['allow_automatic']||($use['snapshot_id']??null)!==$snapshot['snapshot_id'])
                return ['ok'=>true,'snapshot_id'=>'','candidates'=>[]];
            if(isset($query['snapshot_id'])&&$query['snapshot_id']!==$snapshot['snapshot_id'])molavi_domain_fail('stale_snapshot');
            $table=molavi_domain_sheets(['Offers'])['Offers'];
            foreach(molavi_domain_records($table) as $row) {
                if(($row['Offer ID']??null)===$id && ($case=molavi_domain_ai_case($row)))
                    return ['ok'=>true,'snapshot_id'=>$snapshot['snapshot_id'],'candidates'=>molavi_domain_ai_candidates($case,$snapshot)];
            }
            molavi_domain_fail('stale_case');
        }
        if ($mode==='offer') return molavi_domain_ai_offer_preview($query,molavi_domain_sheets(['MessageData','Offers'],true));
        if ($mode==='preview') return molavi_domain_ai_preview($query,molavi_domain_sheets(['MessageData','Offers'],true),null,$actor);
        if ($mode==='archive-review') {
            $cases=molavi_domain_ai_review_cases(molavi_domain_sheets(['Offers'],true),null,false,PHP_INT_MAX);
            return ['ok'=>true,'case_ids'=>array_column($cases,'case_id')];
        }
        if ($mode==='run-changes') return molavi_domain_ai_run_changes($query);
        if ($mode==='run') {
            $tables=($query['summary']??'')==='1'?null:molavi_domain_sheets(['Offers'],true);
            return molavi_domain_ai_run_report($query,$tables);
        }
        return molavi_domain_ai_reviews_get(molavi_domain_sheets(['Offers','Products'],true),null,$view!=='workbench-summary');
    }
    return match ($body['action'] ?? '') {
        'start_reextraction','start_open_case_reextraction','start_recent_policy_reextraction','start_offer_reextraction','start_media_reextraction' => molavi_domain_ai_start($body,$actor),
        'dismiss_cases' => molavi_domain_ai_dismiss_cases($body,$actor),
        'reopen_products' => molavi_domain_published_review_reopen($body,$actor),
        'dismiss_pending_messages','dismiss_recent_policy_messages','dismiss_media_message' => molavi_domain_ai_dismiss_messages($body,$actor),
        'cancel_reextraction' => molavi_domain_ai_cancel($body,$actor),
        default => molavi_domain_ai_reviews_decide($body,$actor),
    };
}

function molavi_domain_ai_validate_choice(?array $body): void {
    $required=['case_id','offer_id','signature','choice','fields','note','expected_decision_id'];
    if($body===null||array_diff($required,array_keys($body))||array_diff(array_keys($body),array_merge($required,['source_current_verified'])))molavi_domain_fail('invalid_body',400);
    if(array_key_exists('source_current_verified',$body)&&!is_bool($body['source_current_verified']))molavi_domain_fail('invalid_fields',400);
    foreach(['case_id'=>100,'offer_id'=>300,'signature'=>64,'choice'=>20,'note'=>1000] as $key=>$limit)if(!is_string($body[$key])||molavi_domain_strlen($body[$key])>$limit)molavi_domain_fail('invalid_fields',400);
    if(($body['choice']!=='not_product'&&trim($body['note'])==='')||strlen($body['signature'])!==64||($body['expected_decision_id']!==null&&(!is_string($body['expected_decision_id'])||strlen($body['expected_decision_id'])!==64)))molavi_domain_fail('invalid_fields',400);
    $fields=$body['fields'];if($body['choice']==='not_product'){if($fields!==[]&&!(is_object($fields)&&get_object_vars($fields)===[]))molavi_domain_fail('invalid_fields',400);return;}
    if($body['choice']!=='confirm'||!is_array($fields))molavi_domain_fail('invalid_fields',400);
    $keys=array_keys($fields);sort($keys);if($keys!==['actual_price','min_qty','package_count','price_role','sale_price','stock_status'])molavi_domain_fail('invalid_fields',400);
    if(!in_array($fields['price_role'],['unit','package'],true))molavi_domain_fail('invalid_price_role',400);
    foreach(['sale_price','actual_price','package_count','min_qty'] as $key){$value=$fields[$key];if($key!=='sale_price'&&in_array($value,['',null],true))continue;
        if(!is_int($value)||$value<0)molavi_domain_fail('invalid_price',400);}
    if($fields['sale_price']<=0||(is_int($fields['actual_price'])&&$fields['actual_price']<=0))molavi_domain_fail('invalid_price',400);
    if($fields['price_role']==='package'&&(!molavi_domain_py_value($fields['package_count'],0)||$fields['package_count']<=0))molavi_domain_fail('package_count_required',400);
    if((is_int($fields['package_count'])&&$fields['package_count']>100000)||(is_int($fields['min_qty'])&&$fields['min_qty']>100000))molavi_domain_fail('invalid_quantity',400);
    if(!in_array($fields['stock_status'],['Available','Out of Stock','Unknown'],true))molavi_domain_fail('invalid_stock_status',400);
}
function molavi_domain_ai_decide(array $payload,?float $clock=null): array {
    if($payload['choice']==='not_product')$payload['fields']=(object)[];$payload['note']=trim($payload['note']);$id=molavi_domain_hash($payload);$now=$clock??microtime(true);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($payload,$id,$now):array {
        $existing=molavi_domain_row($db,'SELECT * FROM ai_confirmation_decisions WHERE decision_id=?',[$id]);
        $current=molavi_domain_row($db,'SELECT decision_id FROM ai_confirmation_decisions WHERE case_id=? ORDER BY created_at DESC LIMIT 1',[$payload['case_id']]);
        if($existing){if(($current['decision_id']??null)!==$id)molavi_domain_fail('decision_conflict');return molavi_domain_ai_decision_decode($existing);}
        if(($current['decision_id']??null)!==$payload['expected_decision_id'])molavi_domain_fail('decision_conflict');
        molavi_domain_sql($db,'INSERT INTO ai_confirmation_decisions (decision_id,case_id,offer_id,signature,choice,fields_json,actor_id,note,created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$id,$payload['case_id'],$payload['offer_id'],$payload['signature'],$payload['choice'],molavi_domain_json($payload['fields']),$payload['actor_id'],$payload['note'],$now]);
        return molavi_domain_ai_decision_decode(molavi_domain_row($db,'SELECT * FROM ai_confirmation_decisions WHERE decision_id=?',[$id]));
    });
}
function molavi_domain_ai_reviews_get(array $tables,?float $clock=null,bool $candidates=true,string $focused=''): array {
    $snapshot=null;try{$candidate=molavi_domain_reference_active();$state=molavi_domain_reference_use();if($candidate&&$state['allow_automatic']&&$candidate['snapshot_id']===$state['snapshot_id'])$snapshot=$candidate;}catch(Throwable $e){}
    [$cases,$recent]=molavi_domain_ai_review_cases($tables,$snapshot,true,200,$clock,$candidates,$focused);if($snapshot)$cases=molavi_domain_reference_receipts($cases,'ai',$snapshot['snapshot_id']);$scope=molavi_domain_ai_hold_scope();
    $cases=array_merge(molavi_domain_published_review_cases($tables,$focused,$clock),$cases);
    if (function_exists('molavi_domain_market_source_rows') && ($market=molavi_domain_market_source_rows())) {
        foreach ($cases as &$case) {
            if (isset($market['offer:'.$case['offer_id']])||isset($market['product:'.($case['product_id']??'')])) $case['market_tracked']=true;
        }
        unset($case);
    }
    foreach($cases as &$case)$case['publication_held']=molavi_domain_ai_held($scope,$case);unset($case);foreach($recent as &$case)$case['publication_held']=molavi_domain_ai_held($scope,$case);unset($case);
    $snapshotId=$snapshot['snapshot_id']??'';
    $waivers=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        "SELECT w.offer_id,w.decision_id FROM reference_item_decisions w WHERE w.choice='proceed_without_reference'
         AND NOT EXISTS (SELECT 1 FROM reference_item_decisions m WHERE m.offer_id=w.offer_id
             AND m.snapshot_id=? AND m.choice='match' AND m.sequence>w.sequence) ORDER BY w.sequence",[$snapshotId]));
    $waived=array_column($waivers,'decision_id','offer_id');
    foreach($cases as &$case) if(empty($case['reopened'])&&isset($waived[$case['offer_id']])) $case['reference_waived']=$waived[$case['offer_id']];
    unset($case);
    $policy=null;
    try {$policy=molavi_domain_bot_capabilities()['extraction_policy_version'];} catch(MolaviWorkflowError $e) {}
    return ['ok'=>true,'cases'=>$cases,'recent_decisions'=>$recent]+($candidates?[]:['candidates_deferred'=>true])+['publication_held'=>(bool)$scope['batch_ids'],
        'publication_hold_scope'=>'source_batch','extraction_policy_version'=>$policy];
}
function molavi_domain_ai_reviews_decide(?array $body,string $actor,?string $clock=null): array {
    molavi_domain_ai_validate_choice($body);return molavi_domain_lock('publication',static function()use($body,$actor,$clock):array {
        $review=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
            'SELECT * FROM published_product_reviews WHERE case_id=?',[$body['case_id']]));
        if ($review) return molavi_domain_published_review_resolve($review,$body,$actor,$clock);
        $offers=molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']);$case=molavi_domain_ai_find_case($offers,$body['case_id'],$body['offer_id'],$clock===null?null:molavi_domain_timestamp($clock));
        if(!$case||$case['signature']!==$body['signature'])molavi_domain_fail('stale_case');if(molavi_domain_ai_held(molavi_domain_ai_hold_scope(),$case))molavi_domain_fail('publication_held_for_review');
        if($body['choice']==='confirm'&&$case['historical_reextract']&&($body['source_current_verified']??null)!==true)molavi_domain_fail('reextraction_price_not_verified',400);
        if($body['choice']==='confirm'&&$case['source_current_check_required']&&($body['source_current_verified']??null)!==true)molavi_domain_fail('source_current_verification_required',400);
        $note=trim($body['note']);if($body['choice']==='confirm'&&($case['historical_reextract']||$case['source_current_check_required']))
            $note=($body['fields']['stock_status']==='Unknown'?'قیمت با منبع فعلی بررسی شد؛ موجودی همچنان نامشخص است. ':'قیمت و موجودی با منبع فعلی بررسی شد. ').$note;
        $decision=molavi_domain_ai_decide(['case_id'=>$case['case_id'],'offer_id'=>$case['offer_id'],'signature'=>$case['signature'],'choice'=>$body['choice'],'fields'=>$body['fields'],
            'actor_id'=>$actor,'note'=>$note,'expected_decision_id'=>$body['expected_decision_id']],$clock===null?null:molavi_domain_timestamp($clock));
        molavi_domain_ai_apply_decision($case,$decision,$clock);
        try{molavi_domain_status_capture(molavi_domain_sheets(['Products','Offers'],true),'owner_ai_decision:'.$decision['decision_id'],$clock===null?null:molavi_domain_timestamp($clock));}catch(Throwable $e){error_log('[molavi-native] ai_decision_snapshot_failed');}
        molavi_domain_ai_refresh_message($case,$clock);$reference=false;
        if($body['choice']==='confirm')foreach(explode(';',$case['reason']) as $part)if(str_starts_with(strtolower(trim($part)),'reference:'))$reference=true;
        $job=molavi_domain_queue('publish','ai-confirmation:'.$decision['decision_id'],['decision_id'=>$decision['decision_id']]);
        return ['ok'=>true,'case_id'=>$case['case_id'],'offer_id'=>$case['offer_id'],'signature'=>$case['signature'],'choice'=>$decision['choice'],'decision_id'=>$decision['decision_id'],
            'projection_state'=>$body['choice']==='not_product'?'not_product':($reference?'needs_review':'imported'),'publish_job'=>$job];
    });
}
