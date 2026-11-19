<?php
/** Plugin Name: Molavi Native Reference Refresh Scheduler */
declare(strict_types=1);
defined('ABSPATH') || exit;

const MOLAVI_REFERENCE_REFRESH_SECONDS = 86400;

function molavi_domain_reference_event(string $event,string $reason,?string $id): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($event,$reason,$id):void {
        $old=molavi_domain_row($db,'SELECT reason,snapshot_id FROM reference_events WHERE event_id=?',[$event]);
        if($old&&($old['reason']!==$reason||$old['snapshot_id']!==$id))molavi_domain_fail('reference_event_changed');
        molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_events VALUES (?,?,?,?,NULL,NULL,NULL)',[$event,$reason,$id,molavi_domain_now()]);
    });
}
function molavi_domain_reference_failure(string $occurrence): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($occurrence):void {
        $id=molavi_domain_row($db,'SELECT snapshot_id FROM reference_active WHERE singleton=1')['snapshot_id']??null;
        molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_events VALUES (?,?,?,?,NULL,NULL,NULL)',['refresh:'.$occurrence,'refresh_failed',$id,molavi_domain_now()]);
    });
}
function molavi_domain_reference_use(?float $clock=null): array {
    $snapshot=molavi_domain_reference_active();$id=$snapshot['snapshot_id']??null;$event=null;$now=$clock??microtime(true);
    if(!$snapshot){$event='missing-reference';molavi_domain_reference_event($event,'missing',null);}
    else{$time=molavi_domain_timestamp($snapshot['last_checked_at']??$snapshot['extracted_at']);
        if($time===null||$time>$now)molavi_domain_fail('reference_clock_invalid');
        if($now-$time>=MOLAVI_REFERENCE_REFRESH_SECONDS){$event='stale:'.$id;molavi_domain_reference_event($event,'stale',$id);}}
    $events=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        'SELECT * FROM reference_events e WHERE snapshot_id IS ? AND NOT EXISTS (SELECT 1 FROM reference_retirements r WHERE r.event_id=e.event_id) ORDER BY created_at,event_id',[$id]));
    $blocked=array_values(array_filter($events,static fn($row)=>$row['choice']!=='use_last_snapshot'));
    if($blocked)return ['state'=>in_array('pause_review',array_column($blocked,'choice'),true)?'pause_review':'awaiting_owner',
        'allow_automatic'=>false,'warning'=>null,'event_id'=>$blocked[0]['event_id'],'snapshot_id'=>$id];
    return ['state'=>$events?'use_last_snapshot':'fresh','allow_automatic'=>$snapshot!==null,
        'warning'=>$events?'Using previous reference snapshot by owner decision':null,'event_id'=>$event,'snapshot_id'=>$id];
}
function molavi_domain_reference_wake(): int {
    if(!molavi_domain_reference_use()['allow_automatic'])return 0;
    return molavi_domain_tx('pipeline',static function(SQLite3 $db):int {
        molavi_domain_sql($db,"UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL WHERE kind='batch' AND state='waiting_reference'",[microtime(true)]);return $db->changes();
    });
}
function molavi_domain_reference_refresh_state(): ?array {
    return molavi_domain_read('reference',static function(SQLite3 $db):?array {
        $row=molavi_domain_row($db,'SELECT * FROM reference_refresh_state WHERE singleton=1');if(!$row)return null;
        $row['change_summary']=!empty($row['change_summary'])?molavi_domain_decode($row['change_summary']):null;return $row;
    });
}
function molavi_domain_reference_refresh(bool $force=false,?callable $fetch=null,?float $clock=null,?string $expected=null,bool $checkExpected=false): array {
    return molavi_domain_lock('refresh',static function()use($force,$fetch,$clock,$expected,$checkExpected):array {
        $now=$clock??microtime(true);$previous=molavi_domain_reference_refresh_state()??[];$last=$previous['last_attempt_at']??null;
        if($checkExpected&&$last!==$expected)molavi_domain_fail('refresh_state_changed');
        $running=($previous['state']??null)==='running';$time=molavi_domain_timestamp($last);$elapsed=$time!==null?$now-$time:MOLAVI_REFERENCE_REFRESH_SECONDS;
        if($force){if(!in_array($previous['state']??null,[null,'failed','running','staged','updated','unchanged'],true))return ['state'=>'not_due'];
            if($last&&!$running&&$elapsed<60)return ['state'=>'cooldown','retry_after_seconds'=>max(0,60-(int)$elapsed)];}
        elseif(!$running&&$last&&$elapsed<MOLAVI_REFERENCE_REFRESH_SECONDS)return ['state'=>'not_due','next_check_seconds'=>max(0,MOLAVI_REFERENCE_REFRESH_SECONDS-(int)$elapsed)];
        if($running&&!empty($previous['occurrence_id']))molavi_domain_reference_failure($previous['occurrence_id']);
        $occurrence=gmdate('Ymd\THis',(int)$now).'Z-'.bin2hex(random_bytes(16));
        $stamp=(new DateTimeImmutable('@'.sprintf('%.6F',$now)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        molavi_domain_tx('reference',static function(SQLite3 $db)use($stamp,$occurrence):void {
            molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_refresh_state(singleton) VALUES (1)');
            molavi_domain_sql($db,"UPDATE reference_refresh_state SET last_attempt_at=?,state='running',occurrence_id=?,snapshot_id=NULL,error_code=NULL,change_summary=NULL WHERE singleton=1",[$stamp,$occurrence]);
        });
        try{
            $candidate=$fetch?$fetch():molavi_domain_reference_fetch_products();
            $staged=molavi_domain_lock('activation',static fn()=>molavi_domain_reference_stage($candidate),'reference');unset($candidate);
            $summary=array_intersect_key($staged['change_summary']??[],array_flip(['added_count','updated_count','removed_count','has_changes','metadata_upgrade','order_changed']));
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'UPDATE reference_refresh_state SET state=?,snapshot_id=?,error_code=NULL,change_summary=? WHERE singleton=1 AND occurrence_id=?',
                [$staged['state'],$staged['snapshot_id'],molavi_domain_json($summary),$occurrence]));
            return $staged+['occurrence_id'=>$occurrence];
        }catch(Throwable $e){
            molavi_domain_reference_failure($occurrence);$code=$e instanceof MolaviWorkflowError?$e->getMessage():get_class($e);
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$code))$code='refresh_failed';$code=substr($code,0,80);
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE reference_refresh_state SET state='failed',error_code=?,change_summary=NULL WHERE singleton=1 AND occurrence_id=?",[$code,$occurrence]));
            return ['state'=>'failed','occurrence_id'=>$occurrence,'error_code'=>$code];
        }
    },'reference');
}
function molavi_domain_reference_finish_refresh(array $outcome): array {
    $result=['projection'=>null,'product_reconciliation_state'=>'complete','product_reference_updates'=>['checked'=>0,'retained'=>0,'needs_review'=>0]];
    $state=$outcome['state']??'';$id=$outcome['snapshot_id']??null;
    // Resume committed activation/projection work without refetching before the next source check.
    if($state==='not_due'){$saved=molavi_domain_reference_refresh_state()??[];$id=$saved['snapshot_id']??null;
        if($id){$candidate=molavi_domain_reference_snapshot($id);
            if(($saved['state']??null)==='staged'&&!empty($candidate['automatic_update'])){$state='staged';$outcome['automatic_update']=true;}
            elseif(in_array($saved['state']??null,['updated','unchanged'],true))$state='unchanged';}}
    if(($state==='staged'&&!empty($outcome['automatic_update']))||$state==='unchanged'){
        molavi_domain_lock('activation',static function()use($outcome,$state,$id,&$result):void {
            if($state==='staged'){$candidate=molavi_domain_reference_snapshot($id);
                if(!$candidate)molavi_domain_fail('snapshot_not_found',404);
                // Activation may have committed before the prior projection request stopped.
                if((molavi_domain_reference_active()['snapshot_id']??null)!==$id)
                    molavi_domain_reference_activate_locked($id,$candidate['previous_snapshot_id']??null,'system:reference-refresh',true);}
            $active=molavi_domain_reference_active();if(!$active)return;$activeId=$active['snapshot_id'];
            $previous=!empty($active['previous_snapshot_id'])?molavi_domain_reference_snapshot($active['previous_snapshot_id']):null;
            $result=molavi_domain_reference_complete($active,$previous,(bool)($active['automatic_update']??false));
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'UPDATE reference_refresh_state SET state=? WHERE singleton=1 AND snapshot_id=?',[$state==='staged'?'updated':'unchanged',$activeId]));
            if($result['product_reconciliation_state']==='complete')molavi_domain_reference_wake();
        },'reference');
    }return $result;
}
function molavi_domain_reference_api(array $body,string $actor): array {
    $action=$body['action']??null;if(!in_array($action,['refresh','review','activate'],true))molavi_domain_fail('invalid_body',400);
    if($action==='refresh'){
        molavi_domain_body_keys($body,['action','expected_last_attempt_at']);$expected=$body['expected_last_attempt_at'];
        if($expected!==null&&(!is_string($expected)||strlen($expected)>80))molavi_domain_fail('invalid_fields',400);
        $outcome=molavi_domain_reference_refresh(true,null,null,$expected,true);
        if($outcome['state']==='cooldown')molavi_domain_fail('refresh_cooldown',429,['retry_after_seconds'=>$outcome['retry_after_seconds']]);
        if($outcome['state']==='not_due')molavi_domain_fail('refresh_not_retryable');
        // Real cron repairs any remaining projection work after this dashboard request.
        if(function_exists('molavi_runtime_state')){$host=molavi_runtime_state();$host['reference_at']=time()+60;molavi_runtime_save_state($host);}
        $completed=molavi_domain_reference_finish_refresh($outcome);$state=molavi_domain_reference_refresh_state()??[];
        $result=['ok'=>true,'action'=>'refresh','state'=>$state['state']??null,'last_attempt_at'=>$state['last_attempt_at']??null,
            'error_code'=>$state['error_code']??null,'change_summary'=>$state['change_summary']??null,
            'product_reconciliation_state'=>$completed['product_reconciliation_state'],'product_reference_updates'=>$completed['product_reference_updates']];
        if($outcome['snapshot_id']??null)$result['snapshot_id']=$outcome['snapshot_id'];if($outcome['row_count']??0)$result['row_count']=$outcome['row_count'];return $result;
    }
    molavi_domain_body_keys($body,$action==='review'?['action','snapshot_id','note','expected_active_id']:['action','snapshot_id','expected_active_id']);
    $id=$body['snapshot_id'];$expected=$body['expected_active_id'];
    if(!is_string($id)||strlen($id)!==64||($expected!==null&&(!is_string($expected)||strlen($expected)!==64)))molavi_domain_fail('invalid_fields',400);
    return molavi_domain_lock('activation',static function()use($body,$action,$id,$expected,$actor):array {
        $current=molavi_domain_reference_active();if(($current['snapshot_id']??null)!==$expected)molavi_domain_fail('active_snapshot_changed');
        $candidate=molavi_domain_reference_snapshot($id);if(!$candidate)molavi_domain_fail('snapshot_not_found',404);
        if(!molavi_domain_reference_coverage($candidate))molavi_domain_fail('snapshot_incomplete');
        if($action==='review'){
            if(!is_string($body['note'])||trim($body['note'])===''||molavi_domain_strlen($body['note'])>1000)molavi_domain_fail('review_note_required',400);
            molavi_domain_reference_review($id,$actor,$body['note']);return ['ok'=>true,'action'=>'review','snapshot_id'=>$id,'reviewed_by'=>$actor,'expected_active_id'=>$expected];
        }
        molavi_domain_reference_activate_locked($id,$expected,$actor);$active=molavi_domain_reference_active();
        $completed=molavi_domain_reference_complete($active,$current,false);
        if($completed['product_reconciliation_state']==='complete')molavi_domain_reference_wake();
        return ['ok'=>true,'action'=>'activate','snapshot_id'=>$id,'active_snapshot_id'=>$id,'projection_state'=>'written','row_count'=>$completed['projection']['row_count'],
            'product_reconciliation_state'=>$completed['product_reconciliation_state'],'product_reference_updates'=>$completed['product_reference_updates'],
            'product_status_snapshot'=>$completed['product_status_snapshot']];
    },'reference');
}
function molavi_domain_reference_decision_api(array $body,string $actor): array {
    molavi_domain_body_keys($body,['event_id','snapshot_id','choice']);$event=$body['event_id'];$id=$body['snapshot_id'];$choice=$body['choice'];
    if(!is_string($event)||strlen($event)<1||molavi_domain_strlen($event)>300||!in_array($choice,['pause_review','use_last_snapshot'],true)
        ||($id!==null&&(!is_string($id)||strlen($id)<1||molavi_domain_strlen($id)>300)))molavi_domain_fail('invalid_fields',400);
    molavi_domain_tx('reference',static function(SQLite3 $db)use($event,$id,$choice,$actor):void {
        $row=molavi_domain_row($db,'SELECT * FROM reference_events WHERE event_id=?',[$event]);
        if(!$row||$row['snapshot_id']!==$id||molavi_domain_row($db,'SELECT 1 FROM reference_retirements WHERE event_id=?',[$event])
            ||($choice==='use_last_snapshot'&&!$id)||($row['choice']&&($row['choice']!==$choice||$row['actor_id']!==$actor)))molavi_domain_fail('stale_or_conflicting_decision');
        molavi_domain_sql($db,'UPDATE reference_events SET choice=?,actor_id=?,decided_at=? WHERE event_id=? AND choice IS NULL',[$choice,$actor,molavi_domain_now(),$event]);
    });
    molavi_domain_reference_wake();return ['ok'=>true,'event_id'=>$event,'choice'=>$choice,'snapshot_id'=>$id];
}
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/reference/check',['methods'=>'GET','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function(){try{
            $started=microtime(true);$candidate=molavi_domain_reference_fetch_products();
            [$prepared]=molavi_domain_reference_incremental($candidate);unset($candidate);
            $summary=array_intersect_key($prepared['change_summary']??[],array_flip(['added_count','updated_count','removed_count','has_changes','metadata_upgrade','order_changed']));
            return ['ok'=>true,'source'=>'woocommerce_store_api','coverage_verified'=>molavi_domain_reference_coverage($prepared),
                'row_count'=>$prepared['row_count'],'source_page_count'=>$prepared['source_page_count'],'change_summary'=>$summary,
                'elapsed_seconds'=>round(microtime(true)-$started,3),'writes'=>false];
        }catch(Throwable $e){return new WP_Error('reference_check_failed','Reference source check unavailable',['status'=>503]);}}]);
});
