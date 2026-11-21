<?php
/** Plugin Name: Molavi Dashboard Activity */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_dashboard_job_state(array $row,string $kind): array {
    $queued=(int)$row['queued'];
    $running=(int)$row['running'];
    $quota=(int)$row['quota_wait'];
    $state=$running?'running':($queued&&$quota===$queued?'quota_wait':($queued?'queued':'needs_reference'));
    return ['kind'=>$kind,'state'=>$state,'running'=>$running,'queued'=>$queued,
        'needs_reference'=>(int)($row['needs_reference']??0),
        'retry_at'=>$state==='quota_wait'?(float)$row['retry_at']:null];
}

function molavi_domain_dashboard_current_job(SQLite3 $db,string $kind,?string $run=null): array {
    $job=molavi_domain_row($db,"SELECT id,state,payload,available,created_at,updated_at,error FROM jobs
        WHERE kind=? AND state IN ('running','queued','waiting_reference','waiting_publication')
        AND (? IS NULL OR id LIKE ?) ORDER BY CASE state WHEN 'running' THEN 0 ELSE 1 END,available,rowid LIMIT 1",
        [$kind,$run,$run?'reextract:'.$run.':%':null]);
    if (!$job) return [];
    $payload=molavi_domain_decode($job['payload']);
    return ['phase'=>$job['error']==='google_free_quota_wait'?'quota_wait':$job['state'],
        'started_at'=>$job['created_at'],'updated_at'=>$job['updated_at'],
        'message_id'=>(string)($payload['message_id']??''),'channel'=>(string)($payload['channel_username']??''),
        'retry_at'=>$job['state']==='queued'?(float)$job['available']:null,'error'=>$job['error']];
}

function molavi_domain_dashboard_job_groups(SQLite3 $db,float $now): array {
    $counts="SUM(CASE WHEN state='running' AND (expires IS NULL OR expires>?) THEN 1 ELSE 0 END) AS running,
        SUM(CASE WHEN state='queued' OR (state='running' AND expires<=?) THEN 1 ELSE 0 END) AS queued,
        SUM(CASE WHEN state='queued' AND error='google_free_quota_wait' AND available>? THEN 1 ELSE 0 END) AS quota_wait,
        MIN(CASE WHEN state='queued' AND error='google_free_quota_wait' AND available>? THEN available END) AS retry_at";
    $args=[$now,$now,$now,$now];
    $active=molavi_domain_row($db,"SELECT substr(id,11,32) AS run_id FROM jobs
        WHERE kind='reextract' AND state IN ('queued','running') ORDER BY rowid DESC LIMIT 1");
    $groups=[];
    if (preg_match('/^[a-f0-9]{32}$/D',(string)($active['run_id']??''))) {
        $runId=$active['run_id'];
        $run=molavi_domain_row($db,"SELECT $counts,COUNT(*) AS total FROM jobs
            WHERE kind='reextract' AND id LIKE ?",[...$args,'reextract:'.$runId.':%']);
        // A run can finish between the two reads.
        if ($run && ($run['running']||$run['queued'])) {
            $groups[]=molavi_domain_dashboard_job_state($run,'reextract')+[
                'run_id'=>$runId,
                'total'=>(int)$run['total'],
                'completed'=>(int)$run['total']-(int)$run['queued']-(int)$run['running'],
                'current'=>molavi_domain_dashboard_current_job($db,'reextract',$runId),
            ];
        }
    }
    $other=molavi_domain_rows($db,"SELECT kind,$counts,
        SUM(CASE WHEN state='waiting_reference' THEN 1 ELSE 0 END) AS needs_reference FROM jobs
        WHERE (kind='batch' AND state IN ('queued','running','waiting_reference'))
           OR (kind='reference_suggest' AND state IN ('queued','running'))
           OR (kind='publish' AND state IN ('queued','running','waiting_publication'))
        GROUP BY kind",$args);
    foreach($other as $row) {
        $group=molavi_domain_dashboard_job_state($row,$row['kind']);
        $current=molavi_domain_dashboard_current_job($db,$row['kind']);
        if ($row['kind']==='publish'&&($current['phase']??'')==='waiting_publication') $group['state']='waiting_publication';
        $groups[]=$group+['current'=>$current];
    }
    $unbatched=molavi_domain_row($db,'SELECT COUNT(*) AS total FROM inbox WHERE batch_id IS NULL');
    if ($unbatched['total']) $groups[]=['kind'=>'intake','state'=>'queued','queued'=>(int)$unbatched['total']];
    $index=molavi_domain_row($db,"SELECT payload,state,error,available,expires FROM jobs
        WHERE kind='maintenance' AND id LIKE 'maintenance:google_reference_index:%'
        ORDER BY rowid DESC LIMIT 1");
    if (in_array($index['state']??null,['queued','running'],true)) {
        $payload=molavi_domain_decode($index['payload']);
        $running=$index['state']==='running' && ($index['expires']===null || $index['expires']>$now);
        $wait=!$running && $index['error']==='google_free_quota_wait' && $index['available']>$now;
        $groups[]=['kind'=>'reference_index','state'=>$running?'running':($wait?'quota_wait':'queued'),
            'total'=>(int)($payload['total']??0),'completed'=>(int)($payload['offset']??0),
            'model'=>(string)($payload['model']??''),'retry_at'=>$wait?(float)$index['available']:null];
    }
    return $groups;
}

function molavi_domain_dashboard_quota(SQLite3 $db,array $caps,float $now): array {
    $config=$caps['google_quota']??null;
    if (!$config) return ['state'=>'unavailable','fixed_extraction_model'=>$caps['fixed_extraction_model']??null];
    $pacific=(new DateTimeImmutable('@'.(int)$now))->setTimezone(new DateTimeZone('America/Los_Angeles'));
    $day=$pacific->format('Y-m-d');
    $reset=$pacific->modify('tomorrow')->setTime(0,0)->getTimestamp();
    $limits=[];
    foreach($config['models'] as $model) {
        $key=$model['bucket'];
        $limits[$key]??=['models'=>[],'tasks'=>[],'rpd_limit'=>$model['rpd'],
            'rpm_limit'=>$model['rpm'],'tpm_limit'=>$model['tpm'],'reserve_rpd'=>$model['reserve_rpd']];
        $limits[$key]['models'][]=$model['model'];
        $limits[$key]['tasks']=array_values(array_unique([...$limits[$key]['tasks'],...$model['tasks']]));
    }
    $project=$config['project'];
    $ledger=molavi_domain_row($db,"SELECT 1 AS present FROM sqlite_master WHERE type='table' AND name='google_quota_requests'");
    $usage=[];$blocks=[];$selected=[];
    if ($ledger) {
        $usage=array_column(molavi_domain_rows($db,"SELECT bucket,
            SUM(CASE WHEN day=? THEN 1 ELSE 0 END) AS used,
            SUM(CASE WHEN started>? THEN 1 ELSE 0 END) AS rpm_used,
            SUM(CASE WHEN started>? THEN tokens ELSE 0 END) AS tpm_used
            FROM google_quota_requests WHERE project=? AND (day=? OR started>?) GROUP BY bucket",
            [$day,$now-60,$now-60,$project,$day,$now-60]),null,'bucket');
        $blocks=array_column(molavi_domain_rows($db,
            'SELECT bucket,until FROM google_quota_blocks WHERE project=? AND until>?',[$project,$now]),'until','bucket');
        $recent=molavi_domain_rows($db,"SELECT task,model,started FROM (
            SELECT task,model,started,ROW_NUMBER() OVER (PARTITION BY task ORDER BY started DESC,id DESC) AS rank
            FROM google_quota_requests WHERE project=? AND day=? AND task IN ('extract','match','embed')
        ) WHERE rank=1",[$project,$day]);
        foreach($recent as $row) $selected[$row['task']]=['model'=>$row['model'],'at'=>(float)$row['started']];
    }
    $buckets=[];$total=['limit'=>0,'used'=>0,'remaining'=>0];
    foreach($limits as $key=>$limit) {
        $row=$usage[$key]??[];
        $used=(int)($row['used']??0);
        $remaining=max(0,$limit['rpd_limit']-$used);
        $buckets[]=$limit+['rpd_used'=>$used,'rpd_remaining'=>$remaining,'rpm_used'=>(int)($row['rpm_used']??0),
            'tpm_used'=>(int)($row['tpm_used']??0),'blocked_until'=>isset($blocks[$key])?(float)$blocks[$key]:null];
        $total['limit']+=$limit['rpd_limit'];
        $total['used']+=$used;
        $total['remaining']+=$remaining;
    }
    return ['state'=>$ledger?'tracked':'awaiting_ledger','day'=>$day,'reset_at'=>$reset,
        'total'=>$total,'buckets'=>$buckets,'last_models'=>(object)$selected];
}

function molavi_domain_dashboard_watch_valid(array $watch): bool {
    if(!is_string($watch['kind']??null)||!is_string($watch['key']??null)) return false;
    $pattern=match($watch['kind']){
        'reextract'=>'/^[a-f0-9]{32}$/D',
        'market_prices'=>'/^[a-f0-9]{64}$/D',
        'reference_suggest'=>'/^reference_suggest:[a-f0-9]{64}$/D',
        'job'=>'/^(?:publish:|publish-)[A-Za-z0-9_:-]{1,180}$/D',
        default=>null,
    };
    return $pattern!==null&&(bool)preg_match($pattern,$watch['key']);
}
function molavi_domain_dashboard_watched_pipeline(SQLite3 $db,array $watch,float $now): array {
    $key=$watch['key'];
    if($watch['kind']==='reextract') {
        $counts=array_column(molavi_domain_rows($db,"SELECT state,COUNT(*) AS n FROM jobs WHERE kind='reextract'
            AND id LIKE ? GROUP BY state",['reextract:'.$key.':%']),'n','state');
        $total=array_sum($counts);
        $complete=($counts['done']??0)+($counts['failed']??0)+($counts['cancelled']??0)+($counts['needs_review']??0);
        $state=match(true){
            !$total=>'unknown',!empty($counts['running'])=>'running',!empty($counts['queued'])=>'queued',
            ($counts['failed']??0)===$total=>'failed',
            !empty($counts['failed'])||!empty($counts['needs_review'])=>'partial',
            !empty($counts['cancelled'])=>empty($counts['done'])?'cancelled':'partial',
            default=>'completed',
        };
        return ['state'=>$state,'completed'=>$complete,'total'=>$total,'failed'=>($counts['failed']??0)+($counts['needs_review']??0),
            'cancelled'=>$counts['cancelled']??0,'current'=>molavi_domain_dashboard_current_job($db,'reextract',$key)];
    }
    $job=molavi_domain_row($db,'SELECT state,error,available,result,created_at,updated_at FROM jobs WHERE id=?',[$key]);
    $state=match($job['state']??null){
        'queued'=>($job['error']==='google_free_quota_wait'&&$job['available']>$now)?'quota_wait':'queued',
        'running'=>'running','done'=>'completed','cancelled'=>'cancelled','needs_review'=>'partial','failed'=>'failed',
        'waiting_publication'=>'waiting_publication','waiting_reference'=>'needs_reference',
        default=>'unknown',
    };
    $code=null;
    if ($state==='completed' && $watch['kind']==='reference_suggest') {
        $result=molavi_domain_decode($job['result']??null);
        if(in_array($result['state']??'',['no_match','no_suggestion','low_confidence'],true))$code='no_suggestion';
    }
    return ['state'=>$state,'code'=>$code,'current'=>$job?['phase'=>$job['state'],'started_at'=>$job['created_at'],
        'updated_at'=>$job['updated_at'],'retry_at'=>$state==='quota_wait'?(float)$job['available']:null,'error'=>$job['error']]:[]];
}
function molavi_domain_dashboard_watched_market(SQLite3 $db,array $watch,float $now): array {
    $row=molavi_domain_row($db,'SELECT state,selected_url FROM market_price_lookups WHERE lookup_key=?',[$watch['key']]);
    $state=$row['state']??null;
    if($state==='queued'&&!$row['selected_url']){
        $used=(int)(molavi_domain_row($db,'SELECT used FROM market_price_budget WHERE day=?',[gmdate('Y-m-d',(int)$now)])['used']??0);
        if($used>=MOLAVI_MARKET_DAILY_LOOKUPS)$state='quota';
    }
    return ['state'=>match($state){
        'queued'=>'queued','searching'=>'running','quota'=>'quota_wait','ready','no_match'=>'completed',
        'retry','provider_auth'=>'failed','cancelled'=>'cancelled',default=>'unknown',
    },'code'=>match($state){
        'no_match'=>'no_match','retry','provider_auth'=>'unavailable',default=>null,
    }];
}
function molavi_domain_dashboard_cancel(array $watch,string $actor): array {
    if(!molavi_domain_dashboard_watch_valid($watch))molavi_domain_fail('invalid_body',400);
    $key=$watch['key'];
    if($watch['kind']==='reextract'){
        $receipt=molavi_domain_ai_cancel(['action'=>'cancel_reextraction','run_id'=>$key],$actor);
        if (!$receipt['running']&&!$receipt['cancelled'])molavi_domain_fail('dashboard_action_changed');
        return ['ok'=>true,'state'=>$receipt['running']?'running':'cancelled','running'=>$receipt['running']];
    }
    if($watch['kind']==='reference_suggest'){
        $changed=molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
            "UPDATE jobs SET state='cancelled',updated_at=?,error=NULL WHERE id=? AND kind='reference_suggest' AND state='queued'",
            [microtime(true),$key])['changes']);
    }elseif($watch['kind']==='market_prices'){
        $changed=molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,
            "UPDATE market_price_lookups SET state='cancelled',priority=0,next_at=?
            WHERE lookup_key=? AND state='queued' AND priority=1 AND selected_url IS NULL",
            [time()+MOLAVI_REFERENCE_REFRESH_SECONDS,$key])['changes']);
    }else molavi_domain_fail('dashboard_action_changed');
    if(!$changed)molavi_domain_fail('dashboard_action_changed');
    return ['ok'=>true,'state'=>'cancelled'];
}

function molavi_domain_dashboard_activity(?float $clock=null,array $watching=[]): array {
    $now=$clock??microtime(true);
    try { $caps=molavi_domain_bot_capabilities(); }
    catch (MolaviWorkflowError $e) { $caps=[]; }
    if(count($watching)>12)molavi_domain_fail('invalid_query',400);
    foreach($watching as $watch)if(!is_array($watch)||!molavi_domain_dashboard_watch_valid($watch))molavi_domain_fail('invalid_query',400);
    $activity=molavi_domain_read('pipeline',static function(SQLite3 $db)use($now,$caps,$watching): array{
        $watched=[];
        foreach($watching as $watch)if($watch['kind']!=='market_prices')
            $watched[$watch['key']]=molavi_domain_dashboard_watched_pipeline($db,$watch,$now);
        return [
        'watched'=>$watched,
        'groups'=>molavi_domain_dashboard_job_groups($db,$now),
        'quota'=>molavi_domain_dashboard_quota($db,$caps,$now),
        'inbox_revision'=>molavi_domain_hash(molavi_domain_rows($db,"SELECT i.rowid,i.batch_id,j.state,j.updated_at,j.available,j.error,h.hold_id
            FROM inbox i LEFT JOIN jobs j ON j.id=i.batch_id
            LEFT JOIN publication_holds h ON i.batch_id LIKE 'batch:%' AND h.hold_id=substr(i.batch_id,7) ORDER BY i.rowid DESC LIMIT 12")),
        ];
    });
    $reference=molavi_domain_read('reference',static function(SQLite3 $db)use($now,$watching): array{
        $watched=[];
        foreach($watching as $watch)if($watch['kind']==='market_prices')
            $watched[$watch['key']]=molavi_domain_dashboard_watched_market($db,$watch,$now);
        return [
        'watched'=>$watched,
        'refresh'=>molavi_domain_reference_refresh_view($db,$now),
        'market_events'=>molavi_domain_rows($db,
            "SELECT id,product_id,offer_id,title,kind,old_price,regular_price,code,occurred_at FROM market_source_events
             WHERE kind IN ('changed','failed','restored') AND occurred_at>=? ORDER BY occurred_at DESC,rowid DESC LIMIT 30",[(int)$now-1209600]),
        'pricing'=>molavi_domain_row($db,
            "SELECT 1 FROM market_price_lookups WHERE active=1 AND state='searching' AND attempted_at>? LIMIT 1",[$now-60]),
        ];
    });
    $activity['watched']=(object)($activity['watched']+$reference['watched']);
    $activity['reference_revision']=$reference['refresh']['revision']??null;
    if ($reference['market_events']) $activity['market_events']=$reference['market_events'];
    if ($reference['refresh']) {
        $refresh=$reference['refresh'];
        $progress=['kind'=>'reference_refresh','state'=>$refresh['in_progress']?'running':match($refresh['state']){
            'failed'=>'failed','staged'=>'partial','updated','unchanged'=>'completed',default=>'unknown'},
            'current'=>($refresh['progress']??['phase'=>'connecting'])+['started_at'=>molavi_domain_timestamp($refresh['last_attempt_at'])],
            'total'=>$refresh['progress']['total']??0,'completed'=>$refresh['progress']['completed']??0,'unit'=>'pages'];
        $activity['reference_progress']=$progress;
        if ($refresh['in_progress']) $activity['groups'][]=$progress;
    }
    if ($reference['pricing']) $activity['groups'][]=['kind'=>'market_prices','state'=>'running'];
    return ['ok'=>true,'observed_at'=>$now]+$activity;
}
