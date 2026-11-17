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
            AND kind IN ('publish') AND kind IN (SELECT kind FROM molavi_state_executors WHERE host='wordpress')
            AND (? IS NULL OR id=?) ORDER BY rowid LIMIT 1",[$now,$now,$id,$id]);
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
function molavi_domain_run_jobs(int $limit=2,float $budget=40.0): array {
    $summary=['claimed'=>0,'completed'=>0,'error'=>''];$until=microtime(true)+$budget;$worker='wordpress:'.bin2hex(random_bytes(12));$now=microtime(true);
    if($limit<1||$budget<=0)return $summary;
    if(molavi_domain_job_host('publish')!=='wordpress')return $summary;
    $job=molavi_domain_claim_job();if(!$job)return $summary;
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($worker,$now):void {
        molavi_domain_sql($db,"DELETE FROM pipeline_workers WHERE worker_id LIKE 'wordpress:%' AND started_at<?",[$now-30*86400]);
        molavi_domain_sql($db,"INSERT INTO pipeline_workers (worker_id,started_at,heartbeat_at,progress_at,state) VALUES (?,?,?,?,'starting')",[$worker,$now,$now,$now]);
    });$GLOBALS['molavi_native_worker']=$worker;
    try{
        for($i=0;$i<$limit&&($i===0||microtime(true)<$until);$i++){
            $summary['claimed']++;$GLOBALS['molavi_native_job_current']=$job['id'];molavi_domain_worker_progress($job['id']);
            try{
                $result=molavi_domain_publish_job($job['payload']);
                if(($result['reason']??null)==='publication_held_for_review')molavi_domain_finish_job($job,$result,'waiting_publication','publication_held_for_review');
                elseif(!empty($result['ok'])&&!empty($result['pushed'])&&($result['images_left']??0)>0&&($result['attached']??0)>0)
                    molavi_domain_finish_job($job,$result,'queued','images_pending',microtime(true)+1,false);
                elseif(empty($result['ok'])||empty($result['pushed'])||($result['images_left']??0)>0)
                    molavi_domain_finish_job($job,array_intersect_key($result,array_flip(['ok','status','error','row','reason','images_left'])),$job['attempts']>=8?'failed':'queued',
                        preg_replace('/[^A-Za-z0-9_. -]/','',(string)($result['error']??$result['reason']??'publication_incomplete')));
                else{molavi_domain_finish_job($job,$result);$summary['completed']++;}
            }catch(MolaviWorkflowError $e){
                $busy=$e->getMessage()==='workflow_busy_retry';
                molavi_domain_finish_job($job,[],$busy?'queued':($job['attempts']>=8?'failed':'queued'),$e->getMessage(),$busy?microtime(true)+30:null,!$busy);
                $summary['error']=$e->getMessage();
            }catch(Throwable $e){molavi_domain_finish_job($job,[],$job['attempts']>=8?'failed':'queued','native_publication_failed');$summary['error']='native_publication_failed';}
            molavi_domain_worker_progress();
            if($i+1>=$limit||microtime(true)>=$until)break;$job=molavi_domain_claim_job();if(!$job)break;
        }
    }finally{
        molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE pipeline_workers SET state='stopped',heartbeat_at=?,current_job_id=NULL WHERE worker_id=?",[microtime(true),$worker]));
        unset($GLOBALS['molavi_native_worker'],$GLOBALS['molavi_native_job_current']);
    }return $summary;
}
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/executors',['methods'=>'GET','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static fn()=>['ok'=>true,'hosts'=>['publish'=>molavi_domain_job_host('publish')],
            'last_publication_tick'=>get_option('molavi_native_jobs_receipt_v1',null),
            'last_sheet_observation'=>get_option('molavi_native_sheet_receipt_v1',null),
            'last_owner_outbox'=>get_option('molavi_native_outbox_receipt_v1',null)]]);
    register_rest_route('molavi-workflow/v1','/executors',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission','callback'=>static function($request){
        try{$body=$request->get_json_params();$hosts=$body['hosts']??null;
            if(!is_array($hosts)||!$hosts||array_diff(array_keys($hosts),['publish']))molavi_domain_fail('invalid_executors',400);
            foreach($hosts as $host)if(!in_array($host,['wordpress','railway'],true))molavi_domain_fail('invalid_executors',400);
            if(in_array('wordpress',$hosts,true)){
                if(($body['backend_filter_version']??null)!==1||!get_option('molavi_state_active',false))molavi_domain_fail('executor_filter_not_verified');
                molavi_domain_config();if(!function_exists('molavi_apply_catalog')||!function_exists('molavi_receive_images'))molavi_domain_fail('native_publisher_unavailable',503);
            }
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($hosts):void {foreach($hosts as $kind=>$host){
                if(molavi_domain_row($db,"SELECT 1 FROM jobs WHERE kind=? AND state='running' AND expires>? LIMIT 1",[$kind,microtime(true)]))molavi_domain_fail('executor_work_active');
                molavi_domain_sql($db,'INSERT INTO molavi_state_executors VALUES (?,?,?) ON CONFLICT(kind) DO UPDATE SET host=excluded.host,updated_at=excluded.updated_at',[$kind,$host,microtime(true)]);
            }});
            if(($hosts['publish']??null)==='wordpress'&&function_exists('molavi_runtime_state')){
                $state=molavi_runtime_state();$state['sheet_at']=min((int)$state['sheet_at'],time());molavi_runtime_save_state($state);
            }
            return ['ok'=>true,'hosts'=>['publish'=>molavi_domain_job_host('publish')]];
        }catch(MolaviWorkflowError $e){return new WP_Error($e->getMessage(),'Executor configuration failed',['status'=>$e->status]);}
        catch(Throwable $e){return new WP_Error('executor_unavailable','Executor configuration failed',['status'=>503]);}}]);
});
