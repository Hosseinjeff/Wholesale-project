<?php
/** Plugin Name: Molavi Native Durable Workflow Jobs */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_job_host(string $kind): string {
    return molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT host FROM molavi_state_executors WHERE kind=?',[$kind])['host']??'railway');
}
function molavi_domain_claim_job(?string $id=null,?float $clock=null): ?array {
    $now=$clock??microtime(true);$lease=bin2hex(random_bytes(16));
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$now,$lease):?array {
        $row=molavi_domain_row($db,"SELECT * FROM jobs WHERE ((state='queued' AND available<=?) OR (state='running' AND expires<?))
            AND kind IN ('publish','order','batch','reextract') AND kind IN (SELECT kind FROM molavi_state_executors WHERE host='wordpress')
            AND (kind!='order' OR NOT EXISTS (SELECT 1 FROM jobs older WHERE older.kind='order' AND older.rowid<jobs.rowid
                AND older.state IN ('queued','running') AND CAST(json_extract(older.payload,'$.order.id') AS TEXT)=CAST(json_extract(jobs.payload,'$.order.id') AS TEXT)))
            AND (? IS NULL OR id=?) ORDER BY CASE kind WHEN 'order' THEN 0 ELSE 1 END,rowid LIMIT 1",[$now,$now,$id,$id]);
        if(!$row)return null;
        molavi_domain_sql($db,"UPDATE jobs SET state='running',lease=?,expires=?,attempts=attempts+1 WHERE id=?",[$lease,$now+900,$row['id']]);
        $row['payload']=molavi_domain_decode($row['payload']);$row['lease']=$lease;$row['attempts']++;return $row;
    });
}
function molavi_domain_finish_job(array $job,array $result,string $state='done',?string $error=null,?float $retry=null,bool $countAttempt=true): void {
    $available=$retry??(microtime(true)+min(300,2**min($job['attempts'],8)));
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($job,$result,$state,$error,$available,$countAttempt):void {
        $updated=molavi_domain_sql($db,"UPDATE jobs SET state=?,result=?,error=?,available=?,lease=NULL,expires=NULL,attempts=MAX(0,attempts-?) WHERE id=? AND state='running' AND lease=?",
            [$state,molavi_domain_json((object)$result,true),$error,$available,(int)!$countAttempt,$job['id'],$job['lease']]);
        if($updated['changes']!==1)molavi_domain_fail('worker_lease_lost');
    });
}
function molavi_domain_projection_digest(array $tables): string {
    return molavi_domain_hash([['Products',$tables['Products']??[]],['Shop',$tables['Shop']??[]]]);
}
/** Mirror of the Railway worker's DurableQueue.hold_publication()
 * (catalog/durable.py:208). A WP-custody batch that finishes unverified
 * must leave a publication hold behind: the workbench resume path
 * (molavi_domain_publication_resolve) fails with publication_hold_changed
 * when no hold row exists, so a needs_review batch with no hold cannot be
 * resumed at all — batch 53, 2026-11-24. Same UPSERT semantics as Railway:
 * insert fresh, or upgrade an existing batch_in_progress hold; a hold
 * already carrying another reason is left alone. Best-effort by design: a
 * hold failure must never break the job run itself.
 * Scope: only the needs_review finish calls this. A batch that will retry
 * (queued) gets no hold — the retry may succeed and a hold would freeze
 * publication meanwhile — and a terminal 'failed' batch goes through the
 * reconcile-backlog path, not the hold-resume path. */
function molavi_domain_hold_publication(string $batchId,string $reason): void {
    if($batchId===''||molavi_domain_strlen($batchId)>100||$reason===''||molavi_domain_strlen($reason)>300)return;
    try{molavi_domain_tx('pipeline',static function(SQLite3 $db)use($batchId,$reason):void {
        molavi_domain_sql($db,"INSERT INTO publication_holds VALUES (?,?,?) ON CONFLICT(hold_id) DO UPDATE SET reason=excluded.reason WHERE publication_holds.reason='batch_in_progress'",[$batchId,$reason,microtime(true)]);
    });}catch(Throwable $e){error_log('[molavi-native] hold_publication_failed batch='.$batchId.' reason='.$reason);}
}
/** Batch-level transport retry: did this batch fail on a transport error?
 * The job layer never sees the WP_Error itself: the extraction transport
 * call throws MolaviExtractError('Extraction transport failed: <wp_error
 * message>') (molavi-domain-ai.php), which the batch pipeline stores as
 * extraction_detail (molavi-domain-extraction.php). On this host WP's
 * http_request_failed failures are cURL errors ('cURL error 28: Resolving
 * timed out...'), so that message shape IS the transport class. The one
 * known non-transport WP_Error — the staging safety block
 * (molavi_staging_external_blocked) — carries a plugin message, never a
 * cURL error, so it does not match and goes straight to needs_review +
 * hold, as it should (a config state, not a transient). Model and
 * validation errors ('Gemini returned...') never match either. */
function molavi_domain_batch_transport_failed(array $result): bool {
    return str_starts_with((string)($result['extraction_detail']??''),'Extraction transport failed: cURL error');
}
/** Has this job already used its one batch-level transport retry? The
 * re-queue stores transport_retry_used in the job's stored result; the
 * next claim reads it back from the row. A job that never re-queued has
 * no stored result (NULL) or no marker. A corrupt stored result is
 * treated as unused — the worst case is one extra re-queue, after which
 * the marker is written cleanly. */
function molavi_domain_batch_transport_retry_used(array $job): bool {
    $stored=$job['result']??null;
    if(is_array($stored))return !empty($stored['transport_retry_used']);
    if(!is_string($stored)||$stored==='')return false;
    try{$prev=molavi_domain_decode($stored);}catch(Throwable $e){return false;}
    return is_array($prev)&&!empty($prev['transport_retry_used']);
}
function molavi_domain_observe_projection(string $digest): ?string {
    if(!preg_match('/^[a-f0-9]{64}$/D',$digest))molavi_domain_fail('invalid_projection_digest',400);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($digest):?string {
        molavi_domain_sql($db,'INSERT OR IGNORE INTO sheet_projection_state(singleton) VALUES (1)');
        $state=molavi_domain_row($db,'SELECT * FROM sheet_projection_state WHERE singleton=1');
        $old=$state['job_id']?molavi_domain_row($db,'SELECT state FROM jobs WHERE id=?',[$state['job_id']]):null;
        if($digest===$state['published_digest']){
            if(($old['state']??'')==='queued')molavi_domain_sql($db,"UPDATE jobs SET state='done',result=?,lease=NULL,expires=NULL,error=NULL WHERE id=?",
                [molavi_domain_json(['ok'=>true,'pushed'=>true,'superseded'=>true],true),$state['job_id']]);
            molavi_domain_sql($db,'UPDATE sheet_projection_state SET observed_digest=?,job_id=NULL WHERE singleton=1',[$digest]);return null;
        }
        if($digest===$state['observed_digest']&&in_array($old['state']??null,['queued','running'],true))return $state['job_id'];
        $now=microtime(true);$payload=molavi_domain_json(['sheet_digest'=>$digest],true);
        if(($old['state']??null)==='queued'){
            $id=$state['job_id'];molavi_domain_sql($db,"UPDATE jobs SET payload=?,attempts=0,available=?,lease=NULL,expires=NULL,result=NULL,error=NULL WHERE id=? AND state='queued'",[$payload,$now,$id]);
        }else{$id='publish:sheet-change:'.bin2hex(random_bytes(16));molavi_domain_sql($db,"INSERT INTO jobs (id,kind,payload,state,attempts,available) VALUES (?,'publish',?,'queued',0,?)",[$id,$payload,$now]);}
        molavi_domain_sql($db,'UPDATE sheet_projection_state SET observed_digest=?,job_id=? WHERE singleton=1',[$digest,$id]);return $id;
    });
}
function molavi_domain_projection_published(string $digest): void {
    if(!preg_match('/^[a-f0-9]{64}$/D',$digest))molavi_domain_fail('invalid_projection_digest',400);
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($digest):void {
        molavi_domain_sql($db,'INSERT OR IGNORE INTO sheet_projection_state (singleton,observed_digest,published_digest,job_id) VALUES (1,?,?,NULL)',[$digest,$digest]);
        $state=molavi_domain_row($db,'SELECT observed_digest,job_id FROM sheet_projection_state WHERE singleton=1');
        if($state['observed_digest']===$digest&&$state['job_id']){
            $job=molavi_domain_row($db,'SELECT state,payload FROM jobs WHERE id=?',[$state['job_id']]);
            if(($job['state']??'')==='queued'&&(molavi_domain_decode($job['payload'])['sheet_digest']??null)===$digest)
                molavi_domain_sql($db,"UPDATE jobs SET state='done',result=?,lease=NULL,expires=NULL,error=NULL WHERE id=?",
                    [molavi_domain_json(['ok'=>true,'pushed'=>true,'superseded'=>true],true),$state['job_id']]);
        }
        molavi_domain_sql($db,'UPDATE sheet_projection_state SET published_digest=?,job_id=CASE WHEN observed_digest=? THEN NULL ELSE job_id END WHERE singleton=1',[$digest,$digest]);
    });
}
function molavi_domain_worker_progress(?string $job=null): void {
    $worker=$GLOBALS['molavi_native_worker']??null;if(!is_string($worker))return;$now=microtime(true);
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
        'UPDATE pipeline_workers SET heartbeat_at=?,progress_at=?,state=?,current_job_id=? WHERE worker_id=?',
        [$now,$now,$job===null?'idle':'processing',$job,$worker]));
}
function molavi_domain_run_jobs(int $limit=2,float $budget=40.0,?string $onlyId=null): array {
    $summary=['claimed'=>0,'completed'=>0,'error'=>''];$until=microtime(true)+$budget;$worker='wordpress:'.bin2hex(random_bytes(12));$now=microtime(true);
    if($limit<1||$budget<=0)return $summary;
    if(molavi_domain_job_host('publish')!=='wordpress'&&molavi_domain_job_host('order')!=='wordpress'
        &&molavi_domain_job_host('batch')!=='wordpress'&&molavi_domain_job_host('reextract')!=='wordpress')return $summary;
    // Match the legacy worker's restart recovery. A hold can be cleared after
    // a publisher reads it but before the waiting receipt is committed.
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
        "UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL WHERE kind='publish' AND state='waiting_publication' AND EXISTS (SELECT 1 FROM molavi_state_executors WHERE kind='publish' AND host='wordpress') AND NOT EXISTS (SELECT 1 FROM publication_holds)",[microtime(true)]));
    $job=molavi_domain_claim_job($onlyId);if(!$job)return $summary;
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($worker,$now):void {
        molavi_domain_sql($db,"DELETE FROM pipeline_workers WHERE worker_id LIKE 'wordpress:%' AND started_at<?",[$now-30*86400]);
        molavi_domain_sql($db,"INSERT INTO pipeline_workers (worker_id,started_at,heartbeat_at,progress_at,state) VALUES (?,?,?,?,'starting')",[$worker,$now,$now,$now]);
    });$GLOBALS['molavi_native_worker']=$worker;
    try{
        for($i=0;$i<$limit&&($i===0||microtime(true)<$until);$i++){
            $summary['claimed']++;$GLOBALS['molavi_native_job_current']=$job['id'];molavi_domain_worker_progress($job['id']);
            try{
                $result=$job['kind']==='order'?molavi_domain_order_job($job['payload'])
                    :($job['kind']==='batch'?molavi_domain_batch_job($job['payload'])
                    :($job['kind']==='reextract'?molavi_domain_reextract_job($job['payload'])
                    :molavi_domain_publish_job($job['payload'])));
                if($job['kind']==='batch'){
                    if(($result['reason']??null)==='reference_pending')molavi_domain_finish_job($job,$result,'waiting_reference','reference_pending');
                    elseif(($result['status']??null)!=='success')molavi_domain_finish_job($job,$result,$job['attempts']>=8?'failed':'queued',
                        preg_replace('/[^A-Za-z0-9_. -]/','',(string)($result['reason']??$result['error']??'ingestion_incomplete')));
                    elseif(($result['extraction_status']??null)!=='success'||!empty($result['rollback'])){
                        // Batch-level transport retry (parent decision 2026-11-24): a
                        // WP-custody batch that fails on a transport error only is
                        // re-queued ONCE after ~5 minutes instead of landing in
                        // needs_review immediately. The in-process WS1 retry (5s
                        // backoff) cannot cover the observed ~97s DNS outage; the
                        // 300s delay does. A second failure (any reason) lands in
                        // needs_review + hold via the 502fc27 call below, and a
                        // non-transport failure never re-queues. The delay is
                        // enforced by the job's available column: the claim query
                        // only claims queued jobs with available<=now, so the batch
                        // cannot be claimed twice while the retry is pending.
                        if(!molavi_domain_batch_transport_retry_used($job)&&molavi_domain_batch_transport_failed($result)){
                            $retryResult=$result;$retryResult['transport_retry_used']=true;
                            error_log('[molavi-native] batch_transport_retry batch='.(string)($job['payload']['batch_id']??$result['batch_id']??'').' detail='.(string)($result['extraction_detail']??''));
                            molavi_domain_finish_job($job,$retryResult,'queued','extraction_transport_retry',microtime(true)+300);}
                        else{molavi_domain_finish_job($job,$result,'needs_review',
                            preg_replace('/[^A-Za-z0-9_. -]/','',(string)($result['extraction_error']??'extraction_failed')));
                            // WP-custody failure used to leave needs_review with NO hold, so the
                            // owner could not resume the batch (publication_hold_changed). Mirror
                            // Railway: an unverified batch holds publication for owner review.
                            molavi_domain_hold_publication((string)($job['payload']['batch_id']??$result['batch_id']??''),'batch_unverified');}}
                    else{molavi_domain_finish_job($job,$result);$summary['completed']++;
                        // Slice 3: one notice job per supplier chat; Railway's worker delivers them.
                        if(function_exists('molavi_domain_ingest_queue_batch_notices')){try{molavi_domain_ingest_queue_batch_notices($job['payload'],$result);}catch(Throwable $e){error_log('[molavi-native] batch_notice_pending');}}}
                }elseif($job['kind']==='reextract'){
                    if(empty($result['ok']))molavi_domain_finish_job($job,$result,$job['attempts']>=8?'failed':'queued',
                        preg_replace('/[^A-Za-z0-9_. -]/','',(string)($result['status']??'reextraction_failed')));
                    else{molavi_domain_finish_job($job,$result);$summary['completed']++;}
                }elseif($job['kind']==='order'&&($result['written']??null)===true){molavi_domain_finish_job($job,$result);$summary['completed']++;}
                elseif(($result['reason']??null)==='publication_held_for_review')molavi_domain_finish_job($job,$result,'waiting_publication','publication_held_for_review');
                elseif(!empty($result['ok'])&&!empty($result['pushed'])&&($result['images_left']??0)>0&&($result['attached']??0)>0)
                    molavi_domain_finish_job($job,$result,'queued','images_pending',microtime(true)+1,false);
                elseif(empty($result['ok'])||empty($result['pushed'])||($result['images_left']??0)>0)
                    molavi_domain_finish_job($job,array_intersect_key($result,array_flip(['ok','status','error','row','reason','images_left'])),$job['attempts']>=8?'failed':'queued',
                        preg_replace('/[^A-Za-z0-9_. -]/','',(string)($result['error']??$result['reason']??'publication_incomplete')));
                else{molavi_domain_finish_job($job,$result);$summary['completed']++;}
            }catch(MolaviExtractQuotaDeferred $e){
                // The batch/reextract path already rolled the batch back before
                // re-raising; re-queue for the quota window Railway reported.
                molavi_domain_finish_job($job,[],$job['attempts']>=8?'failed':'queued','quota_deferred',
                    $e->retryAt>0?$e->retryAt:null);
                $summary['error']='quota_deferred';
            }catch(MolaviWorkflowError $e){
                $busy=$e->getMessage()==='workflow_busy_retry';
                molavi_domain_finish_job($job,[],$busy?'queued':($job['attempts']>=8?'failed':'queued'),$e->getMessage(),$busy?microtime(true)+30:null,!$busy);
                $summary['error']=$e->getMessage();
            }catch(Throwable $e){$failure=$job['kind']==='order'?'native_order_failed':'native_publication_failed';
                molavi_domain_finish_job($job,[],$job['attempts']>=8?'failed':'queued',$failure);$summary['error']=$failure;}
            molavi_domain_worker_progress();
            if($onlyId!==null||$i+1>=$limit||microtime(true)>=$until)break;$job=molavi_domain_claim_job();if(!$job)break;
        }
    }finally{
        molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE pipeline_workers SET state='stopped',heartbeat_at=?,current_job_id=NULL WHERE worker_id=?",[microtime(true),$worker]));
        unset($GLOBALS['molavi_native_worker'],$GLOBALS['molavi_native_job_current']);
    }return $summary;
}
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/executors',['methods'=>'GET','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static fn()=>['ok'=>true,'hosts'=>['publish'=>molavi_domain_job_host('publish'),'order'=>molavi_domain_job_host('order'),
            'batch'=>molavi_domain_job_host('batch'),'reextract'=>molavi_domain_job_host('reextract')],
            'last_publication_tick'=>get_option('molavi_native_jobs_receipt_v1',null),
            'last_sheet_observation'=>get_option('molavi_native_sheet_receipt_v1',null),
            'last_reference_refresh'=>get_option('molavi_native_reference_receipt_v1',null),
            'last_owner_outbox'=>get_option('molavi_native_outbox_receipt_v1',null)]]);
    register_rest_route('molavi-workflow/v1','/executors',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission','callback'=>static function($request){
        try{$body=$request->get_json_params();$hosts=$body['hosts']??null;
            if(!is_array($hosts)||!$hosts||array_diff(array_keys($hosts),['publish','order','batch','reextract']))molavi_domain_fail('invalid_executors',400);
            foreach($hosts as $host)if(!in_array($host,['wordpress','railway'],true))molavi_domain_fail('invalid_executors',400);
            if(in_array('wordpress',$hosts,true)){
                if(($body['backend_filter_version']??null)!==1||!get_option('molavi_state_active',false))molavi_domain_fail('executor_filter_not_verified');
                molavi_domain_config();if(!function_exists('molavi_apply_catalog')||!function_exists('molavi_receive_images'))molavi_domain_fail('native_publisher_unavailable',503);
                if(($hosts['order']??null)==='wordpress'&&(($body['backend_order_notice_version']??null)!==1||!function_exists('molavi_domain_order_job')))molavi_domain_fail('order_notice_not_verified');
                if(($hosts['batch']??null)==='wordpress'&&!function_exists('molavi_domain_batch_job'))molavi_domain_fail('native_batch_unavailable',503);
                if(($hosts['reextract']??null)==='wordpress'&&!function_exists('molavi_domain_reextract_job'))molavi_domain_fail('native_reextract_unavailable',503);
            }
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($hosts):void {foreach($hosts as $kind=>$host){
                if(molavi_domain_row($db,"SELECT 1 FROM jobs WHERE kind=? AND state='running' AND expires>? LIMIT 1",[$kind,microtime(true)]))molavi_domain_fail('executor_work_active');
                molavi_domain_sql($db,'INSERT INTO molavi_state_executors VALUES (?,?,?) ON CONFLICT(kind) DO UPDATE SET host=excluded.host,updated_at=excluded.updated_at',[$kind,$host,microtime(true)]);
            }});
            if(($hosts['publish']??null)==='wordpress'&&function_exists('molavi_runtime_state')){
                $state=molavi_runtime_state();$state['sheet_at']=min((int)$state['sheet_at'],time());molavi_runtime_save_state($state);
            }
            return ['ok'=>true,'hosts'=>['publish'=>molavi_domain_job_host('publish'),'order'=>molavi_domain_job_host('order'),
                'batch'=>molavi_domain_job_host('batch'),'reextract'=>molavi_domain_job_host('reextract')]];
        }catch(MolaviWorkflowError $e){return new WP_Error($e->getMessage(),'Executor configuration failed',['status'=>$e->status]);}
        catch(Throwable $e){return new WP_Error('executor_unavailable','Executor configuration failed',['status'=>503]);}}]);
});
