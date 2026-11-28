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
function molavi_domain_reference_live_events(SQLite3 $db,?string $id): array {
    return molavi_domain_rows($db,
        'SELECT * FROM reference_events e WHERE snapshot_id IS ? AND NOT EXISTS
        (SELECT 1 FROM reference_retirements r WHERE r.event_id=e.event_id) ORDER BY created_at,event_id',[$id]);
}
function molavi_domain_reference_review_status(?string $id,array $events): array {
    $pending=array_values(array_filter($events,static fn($event)=>$event['choice']!=='use_last_snapshot'));
    $paused=in_array('pause_review',array_column($pending,'choice'),true);
    return [
        'state'=>$paused?'paused':($pending?'awaiting_owner':($id?($events?'using_saved':'ready'):'missing')),
        'pending_count'=>count($pending),
        'failed_attempts'=>count(array_filter($events,static fn($event)=>$event['reason']==='refresh_failed')),
        'decision_token'=>$pending&&!$paused?molavi_domain_hash([$id,$pending]):null,
    ];
}
function molavi_domain_reference_use(?float $clock=null): array {
    $snapshot=molavi_domain_reference_active();$id=$snapshot['snapshot_id']??null;$event=null;$now=$clock??microtime(true);
    if(!$snapshot){$event='missing-reference';molavi_domain_reference_event($event,'missing',null);}
    else{$time=molavi_domain_timestamp($snapshot['last_checked_at']??$snapshot['extracted_at']);
        if($time===null||$time>$now)molavi_domain_fail('reference_clock_invalid');
        if($now-$time>=MOLAVI_REFERENCE_REFRESH_SECONDS){$event='stale:'.$id;molavi_domain_reference_event($event,'stale',$id);}}
    $events=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_reference_live_events($db,$id));
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
function molavi_domain_reference_progress(string $occurrence,string $phase,?array $counts=null): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($occurrence,$phase,$counts): void {
        if ((molavi_domain_row($db,'SELECT occurrence_id FROM reference_refresh_state WHERE singleton=1')['occurrence_id']??null)!==$occurrence) return;
        $current=molavi_domain_row($db,'SELECT detail_json FROM reference_refresh_progress WHERE occurrence_id=?',[$occurrence]);
        $previous=molavi_domain_decode($current['detail_json']??null);
        $detail=['phase'=>$phase,'updated_at'=>microtime(true)]+($counts??[])+$previous;
        molavi_domain_sql($db,'INSERT INTO reference_refresh_progress VALUES(1,?,?)
            ON CONFLICT(singleton) DO UPDATE SET occurrence_id=excluded.occurrence_id,detail_json=excluded.detail_json',
            [$occurrence,molavi_domain_json($detail)]);
    });
}

function molavi_domain_reference_previous_progress(string $occurrence,float $now): ?array {
    $detail=molavi_domain_read('reference',static function(SQLite3 $db)use($occurrence):?array {
        $row=molavi_domain_row($db,'SELECT detail_json FROM reference_refresh_progress WHERE occurrence_id=?',[$occurrence]);
        return $row?molavi_domain_decode($row['detail_json']):null;
    });
    if(!$detail||!is_array($detail))return ['occurrence'=>$occurrence,'phase'=>'unknown','stalled_seconds'=>null];
    $updated=(float)($detail['updated_at']??0);
    return ['occurrence'=>$occurrence,'phase'=>(string)($detail['phase']??'unknown'),
        'completed'=>(int)($detail['completed']??0),'total'=>(int)($detail['total']??0),
        'records'=>(int)($detail['records']??0),'total_records'=>(int)($detail['total_records']??0),
        'stalled_seconds'=>$updated>0?(int)max(0,$now-$updated):null];
}

function molavi_domain_reference_refresh_view(SQLite3 $db,float $now): ?array {
    $row=molavi_domain_row($db,"SELECT r.*, EXISTS(
        SELECT 1 FROM molavi_state_locks WHERE name='refresh' AND expires>=? AND r.state='running'
    ) AS in_progress, a.snapshot_id AS active_id, s.reviewed_at AS candidate_reviewed_at,
        (SELECT expires FROM molavi_state_locks WHERE name='refresh') AS lock_expires
        FROM reference_refresh_state r
        LEFT JOIN reference_active a ON a.singleton=r.singleton
        LEFT JOIN reference_snapshots s ON s.snapshot_id=r.snapshot_id
        WHERE r.singleton=1",[(int)$now]);
    if(!$row)return null;
    $row['in_progress']=(bool)$row['in_progress'];
    $progress=molavi_domain_row($db,'SELECT detail_json FROM reference_refresh_progress WHERE occurrence_id=?',[$row['occurrence_id']]);
    if ($progress) {
        $row['progress']=molavi_domain_decode($progress['detail_json']);
        if ($row['progress']['phase']==='applying'&&$row['progress']['updated_at']+120>$now) $row['in_progress']=true;
    }
    $row['revision']=molavi_domain_hash([
        $row['occurrence_id'],$row['state'],$row['snapshot_id'],$row['in_progress'],$row['active_id'],$row['candidate_reviewed_at'],$row['progress']??null
    ]);
    unset($row['active_id'],$row['candidate_reviewed_at']);
    $last=molavi_domain_timestamp($row['last_attempt_at']);
    $row['retry_at']=$last!==null&&$row['state']!=='running'&&$last+60>$now?$last+60:null;
    // A live attempt always holds the refresh lease; a 'running' attempt
    // whose lease has expired and whose progress is older than the
    // dashboard's own stuck threshold (600s) is dead, not slow. The
    // lease expiry is also when the next tick can start a new attempt.
    $updated=(float)($row['progress']['updated_at']??0);
    $lockExpired=$row['lock_expires']===null||(int)$row['lock_expires']<=(int)$now;
    $row['stalled']=$row['state']==='running'&&$lockExpired
        &&(($updated>0&&$now-$updated>600)||($updated===0&&$last!==null&&$now-$last>600));
    if($row['stalled'])$row['retry_at']=$row['lock_expires']!==null?(int)max((int)$now,(int)$row['lock_expires']):(int)$now;
    return $row;
}
function molavi_domain_reference_refresh(bool $force=false,?callable $fetch=null,?float $clock=null,?string $expected=null,bool $checkExpected=false): array {
    return molavi_domain_lock('refresh',static function()use($force,$fetch,$clock,$expected,$checkExpected):array {
        $now=$clock??microtime(true);$previous=molavi_domain_reference_refresh_state()??[];$last=$previous['last_attempt_at']??null;
        if($checkExpected&&$last!==$expected)molavi_domain_fail('refresh_state_changed');
        $running=($previous['state']??null)==='running';$time=molavi_domain_timestamp($last);$elapsed=$time!==null?$now-$time:MOLAVI_REFERENCE_REFRESH_SECONDS;
        // A budgeted pause ('partial') is still in progress across ticks:
        // the next tick resumes it from the checkpoint instead of waiting
        // a day for the next daily interval.
        $resumable=$running||($previous['state']??null)==='partial';
        if($force){if(!in_array($previous['state']??null,[null,'failed','running','staged','updated','unchanged','partial'],true))return ['state'=>'not_due'];
            if($last&&!$resumable&&$elapsed<60)return ['state'=>'cooldown','retry_after_seconds'=>max(0,60-(int)$elapsed)];}
        elseif(!$resumable&&$last&&$elapsed<MOLAVI_REFERENCE_REFRESH_SECONDS)return ['state'=>'not_due','next_check_seconds'=>max(0,MOLAVI_REFERENCE_REFRESH_SECONDS-(int)$elapsed)];
        $stalled=null;
        if($running&&!empty($previous['occurrence_id'])){
            molavi_domain_reference_failure($previous['occurrence_id']);
            // The dead attempt left no error of its own, so the stall IS
            // the recorded error: where it stopped stays visible until
            // the next attempt succeeds.
            $stalled=molavi_domain_reference_previous_progress($previous['occurrence_id'],$now);
        }
        $occurrence=gmdate('Ymd\THis',(int)$now).'Z-'.bin2hex(random_bytes(16));
        $stamp=(new DateTimeImmutable('@'.sprintf('%.6F',$now)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        // Never wipe the last known failure when a new attempt starts: a
        // running attempt shows the previous attempt's error until it
        // succeeds (cleared) or fails (replaced). A crashed attempt
        // carries 'refresh_stalled' instead.
        $carriedError=$stalled!==null?'refresh_stalled':($previous['error_code']??null);
        molavi_domain_tx('reference',static function(SQLite3 $db)use($stamp,$occurrence,$carriedError):void {
            molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_refresh_state(singleton) VALUES (1)');
            molavi_domain_sql($db,"UPDATE reference_refresh_state SET last_attempt_at=?,state='running',occurrence_id=?,snapshot_id=NULL,error_code=?,change_summary=NULL WHERE singleton=1",[$stamp,$occurrence,$carriedError]);
        });
        try{
            molavi_domain_reference_progress($occurrence,'connecting',$stalled!==null?['previous_stall'=>$stalled]:null);
            $candidate=$fetch?$fetch():molavi_domain_reference_fetch_products(100,static function($phase,$pages,$totalPages,$rows,$totalRows)use($occurrence): void {
                molavi_domain_reference_progress($occurrence,$phase,['completed'=>$pages,'total'=>$totalPages,'records'=>$rows,'total_records'=>$totalRows]);
            },MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS,null,true);
            molavi_domain_reference_progress($occurrence,'comparing');
            $staged=molavi_domain_lock('activation',static fn()=>molavi_domain_reference_stage($candidate),'reference');unset($candidate);
            molavi_domain_reference_checkpoint_clear();
            $summary=array_intersect_key($staged['change_summary']??[],array_flip(['added_count','updated_count','removed_count','has_changes','metadata_upgrade','order_changed']));
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,'UPDATE reference_refresh_state SET state=?,snapshot_id=?,error_code=NULL,change_summary=? WHERE singleton=1 AND occurrence_id=?',
                [$staged['state'],$staged['snapshot_id'],molavi_domain_json($summary),$occurrence]));
            molavi_domain_reference_progress($occurrence,'saved');
            return $staged+['occurrence_id'=>$occurrence];
        }catch(Throwable $e){
            // A fetch that ran out of its time budget paused, it did not
            // fail: the checkpoint keeps its pages, no incident opens, and
            // the next tick resumes it. Failing here would wait a day.
            if($e instanceof MolaviWorkflowError&&$e->getMessage()==='woocommerce_fetch_timeout'){
                $details=$e->details;
                molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE reference_refresh_state SET state='partial',error_code='woocommerce_fetch_timeout',change_summary=NULL WHERE singleton=1 AND occurrence_id=?",[$occurrence]));
                molavi_domain_reference_progress($occurrence,'paused',['completed'=>$details['completed_pages']??0,'total'=>$details['total_pages']??0,
                    'records'=>$details['records']??0,'total_records'=>$details['total_records']??0]);
                return ['state'=>'partial','occurrence_id'=>$occurrence,'error_code'=>'woocommerce_fetch_timeout'];
            }
            molavi_domain_reference_failure($occurrence);$code=$e instanceof MolaviWorkflowError?$e->getMessage():get_class($e);
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$code))$code='refresh_failed';$code=substr($code,0,80);
            molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE reference_refresh_state SET state='failed',error_code=?,change_summary=NULL WHERE singleton=1 AND occurrence_id=?",[$code,$occurrence]));
            molavi_domain_reference_progress($occurrence,'failed');
            return ['state'=>'failed','occurrence_id'=>$occurrence,'error_code'=>$code];
        }
    },'reference');
}
function molavi_domain_reference_finish_refresh(array $outcome): array {
    $result=['projection'=>null,'product_reconciliation_state'=>'complete','product_reference_updates'=>['checked'=>0,'retained'=>0,'needs_review'=>0]];
    $state=$outcome['state']??'';$id=$outcome['snapshot_id']??null;
    // Resume committed activation/projection work without refetching before the next source check.
    if($state==='not_due'){$saved=molavi_domain_reference_refresh_state()??[];$id=$saved['snapshot_id']??null;
        if(($saved['state']??null)==='partial')return ['projection'=>null,'product_reconciliation_state'=>'partial','product_reference_updates'=>['checked'=>0,'retained'=>0,'needs_review'=>0]];
        if($id){$candidate=molavi_domain_reference_snapshot($id);
            if(($saved['state']??null)==='staged'&&!empty($candidate['automatic_update'])){$state='staged';$outcome['automatic_update']=true;}
            elseif(in_array($saved['state']??null,['updated','unchanged'],true))$state='unchanged';}}
    // A paused fetch is not complete: the tick retries it in a minute
    // instead of scheduling the next daily refresh.
    if($state==='partial')return ['projection'=>null,'product_reconciliation_state'=>'partial','product_reference_updates'=>['checked'=>0,'retained'=>0,'needs_review'=>0]];
    if(($state==='staged'&&!empty($outcome['automatic_update']))||$state==='unchanged'){
        $occurrence=$outcome['occurrence_id']??(molavi_domain_reference_refresh_state()['occurrence_id']??null);
        if ($occurrence) molavi_domain_reference_progress($occurrence,'applying');
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
            if ($result['product_reconciliation_state']==='complete'&&!empty($active['last_checked_at'])
                &&function_exists('molavi_domain_sale_offer_evidence')&&molavi_domain_job_host('publish')==='wordpress') {
                molavi_domain_queue('publish','reference-prices:'.hash('sha256',$activeId.'|'.$active['last_checked_at']),[]);
            }
            if($result['product_reconciliation_state']==='complete')molavi_domain_reference_wake();
        },'reference');
        if ($occurrence) molavi_domain_reference_progress($occurrence,$result['product_reconciliation_state']==='complete'?'complete':'waiting',
            ['products'=>$result['product_reference_updates']['checked']??0]);
    }return $result;
}
function molavi_domain_reference_api(array $body,string $actor): array {
    $action=$body['action']??null;
    if($action==='decide_reference_use')return molavi_domain_reference_group_decision($body,$actor);
    if($action==='backfill_reference_matches')return molavi_domain_reference_backfill_api($body,$actor);
    if($action==='retire_reference_match')return molavi_domain_reference_match_retire_api($body,$actor);
    if(!in_array($action,['refresh','review','activate'],true))molavi_domain_fail('invalid_body',400);
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
function molavi_domain_reference_group_decision(array $body,string $actor): array {
    molavi_domain_body_keys($body,['action','snapshot_id','decision_token','choice']);
    $id=$body['snapshot_id'];$token=$body['decision_token'];$choice=$body['choice'];
    if(($id!==null&&(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id)))
        ||!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)
        ||!in_array($choice,['pause_review','use_last_snapshot'],true))molavi_domain_fail('invalid_fields',400);
    $count=molavi_domain_tx('reference',static function(SQLite3 $db)use($id,$token,$choice,$actor): int {
        $active=molavi_domain_row($db,'SELECT snapshot_id FROM reference_active WHERE singleton=1')['snapshot_id']??null;
        if($active!==$id)molavi_domain_fail('reference_review_changed');
        $status=molavi_domain_reference_review_status($id,molavi_domain_reference_live_events($db,$id));
        if($status['decision_token']!==$token)molavi_domain_fail('reference_review_changed');
        if($choice==='use_last_snapshot'){
            $row=molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$id]);
            if(!$row||!molavi_domain_reference_coverage(molavi_domain_decode($row['payload'])))
                molavi_domain_fail('snapshot_incomplete',409);
        }
        molavi_domain_sql($db,'UPDATE reference_events AS e SET choice=?,actor_id=?,decided_at=?
            WHERE snapshot_id IS ? AND choice IS NULL AND NOT EXISTS
            (SELECT 1 FROM reference_retirements r WHERE r.event_id=e.event_id)',
            [$choice,$actor,molavi_domain_now(),$id]);
        return $db->changes();
    });
    if($choice==='use_last_snapshot')molavi_domain_reference_wake();
    return ['ok'=>true,'action'=>'decide_reference_use','snapshot_id'=>$id,'choice'=>$choice,'applied_count'=>$count];
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
