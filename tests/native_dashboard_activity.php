<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-activity-'.bin2hex(random_bytes(6)));
function add_action(...$args){}
function add_filter(...$args){}
function get_option($name,$default=null){return $GLOBALS['activity_options'][$name]??$default;}

foreach(['state-host','domain-core','domain-schemas','domain-bot-capabilities','domain-dashboard-activity','domain-reference-scheduler','domain-market-prices','domain-reference-parser','domain-products','domain-publication-recovery','domain-reconciliation'] as $part) {
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$part.'.php';
}
function activity_check(bool $condition,string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$now=strtotime('2026-11-18T07:59:50Z'); // The prior quota day in California.
$model=['model'=>'gemini-fixture','bucket'=>'shared','tasks'=>['extract'],'rpm'=>5,'tpm'=>10000,'rpd'=>10,'reserve_rpd'=>0];
$GLOBALS['activity_options']['molavi_bot_capabilities_v1']=[
    'schema_version'=>1,'ai_configured'=>true,'extraction_policy_version'=>'gemini-'.str_repeat('a',16),
    'suggestion_policy_version'=>null,'image_extraction_enabled'=>false,
    'google_quota'=>['project'=>'project-fixture','models'=>[$model,array_replace($model,['model'=>'alias','tasks'=>['match']])]],
];
$root=molavi_state_root();
foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}
try {
    $db=molavi_domain_db();
    $db->exec('CREATE TABLE google_quota_requests(id TEXT PRIMARY KEY,project TEXT,bucket TEXT,model TEXT,task TEXT,started REAL,day TEXT,tokens INTEGER)');
    $db->exec('CREATE TABLE google_quota_blocks(project TEXT,bucket TEXT,until REAL)');
    $args=['project-fixture','shared','gemini-fixture','extract',$now-1,'2026-11-17',400];
    molavi_domain_sql($db,'INSERT INTO google_quota_requests VALUES (?,?,?,?,?,?,?,?)',['1',...$args]);
    molavi_domain_sql($db,'INSERT INTO google_quota_requests VALUES (?,?,?,?,?,?,?,?)',
        ['2','other-project','shared','private-model','extract',$now,'2026-11-17',100]);
    molavi_domain_sql($db,'INSERT INTO google_quota_blocks VALUES (?,?,?)',['project-fixture','shared',$now+60]);
    $run='reextract:'.str_repeat('f',32).':';
    molavi_domain_sql($db,"INSERT INTO jobs (id,kind,payload,state,available) VALUES (?,'reextract','{}','done',?)",[$run.'one',$now]);
    molavi_domain_sql($db,"INSERT INTO jobs (id,kind,payload,state,available,error) VALUES (?,'reextract','{}','queued',?,'google_free_quota_wait')",[$run.'two',$now+180]);
    molavi_domain_sql($db,"INSERT INTO jobs (id,kind,payload,state,available,expires) VALUES ('reference_suggest:test','reference_suggest','{}','running',?,?)",[$now,$now+10]);
    $db->close();

    $r=molavi_domain_dashboard_activity($now);
    activity_check($r['groups'][0]['completed']===1 && $r['groups'][0]['total']===2,'batch progress includes completed messages');
    activity_check($r['groups'][0]['state']==='quota_wait' && $r['groups'][0]['retry_at']===$now+180.0,'quota waits show the scheduled retry');
    activity_check($r['groups'][0]['current']['phase']==='quota_wait','progress identifies the current wait phase');
    activity_check($r['groups'][1]['kind']==='reference_suggest' && $r['groups'][1]['state']==='running','reference work appears separately');
    molavi_domain_tx('reference',static function(SQLite3 $db)use($now): void {
        molavi_domain_sql($db,"INSERT INTO reference_refresh_state (singleton,last_attempt_at,state,occurrence_id) VALUES (1,?,'running','progress-test')",
            [gmdate('c',(int)$now)]);
        molavi_domain_sql($db,"INSERT INTO molavi_state_locks VALUES ('refresh','test',?)",[(int)$now+60]);
    });
    molavi_domain_reference_progress('progress-test','fetching',['completed'=>3,'total'=>8,'records'=>240,'total_records'=>631]);
    $progress=molavi_domain_dashboard_activity($now)['reference_progress'];
    activity_check($progress['total']===8&&$progress['completed']===3&&$progress['current']['records']===240,
        'reference progress reports real pages and records');
    molavi_domain_tx('reference',static function(SQLite3 $db): void {
        molavi_domain_sql($db,"DELETE FROM reference_refresh_state WHERE occurrence_id='progress-test'");
        molavi_domain_sql($db,"DELETE FROM molavi_state_locks WHERE name='refresh' AND owner='test'");
    });
    activity_check($r['quota']['total']===['limit'=>10,'used'=>1,'remaining'=>9],'aliases share one allocation and usage is scoped to the active project');
    activity_check($r['quota']['last_models']->extract['model']==='gemini-fixture','last actual model follows the task ledger');
    activity_check(strpos(json_encode($r),'project-fixture')===false,'project identifier stays private');
    activity_check($r['quota']['day']==='2026-11-17' && $r['quota']['reset_at']===$now+10,'quota follows the provider day');
    $next=molavi_domain_dashboard_activity($now+11);
    activity_check($next['quota']['total']['used']===0 && $next['quota']['buckets'][0]['rpm_used']===1,'day resets while the rolling minute continues');
    activity_check($next['groups'][1]['state']==='queued','expired leases no longer appear active');
    $db=molavi_domain_db();
    molavi_domain_sql($db,"INSERT INTO inbox (id,payload,received_at,batch_id) VALUES (?,?,?,'batch:receipt')",
        ['fixture-receipt','{"id":"123","channel_username":"supplier","content":"Supplier post"}',$now]);
    molavi_domain_sql($db,"INSERT INTO jobs (id,kind,payload,state,available,error) VALUES ('batch:receipt','batch','{}','queued',?,'google_free_quota_wait')",[$now+60]);
    $db->close();
    $queued=molavi_domain_inbox_recent()['messages'][0];
    $queuedRevision=molavi_domain_dashboard_activity($now)['inbox_revision'];
    activity_check($queued['receipt_id']>0&&$queued['retry_at']===$now+60.0,'inbox exposes the saved receipt and quota retry');
    $db=molavi_domain_db();
    molavi_domain_sql($db,"UPDATE jobs SET state='done',result=?,error=NULL,updated_at=? WHERE id='batch:receipt'",['{"extraction_status":"success"}',$now+1]);
    $db->close();
    $complete=molavi_domain_inbox_recent()['messages'][0];
    activity_check($complete['receipt_id']===$queued['receipt_id']&&$complete['state']==='done'&&$complete['retry_at']===null,'one receipt survives the transition to completed');
    activity_check(molavi_domain_dashboard_activity($now+1)['inbox_revision']!==$queuedRevision,'local activity revision changes when a receipt completes');
    $offer=['Offer ID'=>'new','Product ID'=>'same','Batch ID'=>'batch','Message ID'=>'123',
        'Product Name'=>'نمونه','Channel Username'=>'supplier','Package Count'=>'12'];
    $other=array_replace($offer,['Offer ID'=>'earlier','Batch ID'=>'old']);
    $products=[$offer,array_replace($offer,['Product ID'=>'alternate','Package Count'=>'24']),
        array_replace($offer,['Product ID'=>'different-supplier','Channel Username'=>'other','Package Count'=>'24'])];
    $summary=molavi_domain_inbox_offer_summary([$other,$offer],$products,'batch','123','supplier');
    activity_check($summary[0]['relationship']==='known' && $summary[0]['product_id']==='same','inbox links a stable same-item identity');
    activity_check(array_column($summary[0]['similar'],'product_id')===['alternate'],'dimension conflicts expose comparisons, not cross-supplier links');
    $summary=molavi_domain_inbox_offer_summary([$offer],$products,'batch','123','supplier');
    activity_check($summary[0]['relationship']==='compare','same title with a different pack should prompt comparison');
    $summary=molavi_domain_inbox_offer_summary([$offer],[$offer,$offer],'batch','123','supplier');
    activity_check($summary[0]['product_id']==='','an ambiguous product ID is not offered as a fallback link');
    $record=['Batch ID'=>'batch','ID'=>'123','Channel Username'=>'supplier','AI Status'=>'no_product_candidate'];
    activity_check(molavi_domain_inbox_message_outcome([$record],'batch','123','supplier')==='no_product','explicit no-product receipt has a distinct outcome');
    activity_check(molavi_domain_inbox_message_outcome([$record,$record],'batch','123','supplier')==='unverified','duplicate messages cannot establish an extraction outcome');
    activity_check(molavi_domain_inbox_message_outcome([$record],'batch','123','other')==='unverified','an unrelated source cannot establish an outcome');
    $referenceId=str_repeat('b',64);
    $db=molavi_domain_db('reference');
    molavi_domain_sql($db,"INSERT INTO reference_refresh_state(singleton,last_attempt_at,state,occurrence_id)
        VALUES(1,?,'running','attempt-1')",[gmdate('c',$now)]);
    molavi_domain_sql($db,"INSERT INTO molavi_state_locks VALUES('refresh','fixture',?)",[$now+10]);
    $db->close();
    $fetching=molavi_domain_dashboard_activity($now);
    activity_check(in_array('reference_refresh',array_column($fetching['groups'],'kind'),true)
        && strlen($fetching['reference_revision'])===64,'a leased catalog fetch appears in the activity feed');
    $expired=molavi_domain_dashboard_activity($now+11);
    activity_check(!in_array('reference_refresh',array_column($expired['groups'],'kind'),true)
        && $expired['reference_revision']!==$fetching['reference_revision'],'an expired fetch changes the revision without displaying active work');
    $db=molavi_domain_db('reference');
    molavi_domain_sql($db,"UPDATE reference_refresh_state SET state='staged',snapshot_id=?",[$referenceId]);
    molavi_domain_sql($db,"INSERT INTO reference_snapshots(snapshot_id,payload) VALUES (?,'{}')",[$referenceId]);
    $staged=molavi_domain_reference_refresh_view($db,$now+11);
    activity_check($staged['retry_at']===$now+60.0 && $staged['revision']!==$expired['reference_revision'],'the first catalog is staged with the actual retry time');
    molavi_domain_sql($db,"UPDATE reference_snapshots SET reviewed_at=? WHERE snapshot_id=?",[gmdate('c',$now+12),$referenceId]);
    $reviewed=molavi_domain_reference_refresh_view($db,$now+12);
    activity_check($reviewed['revision']!==$staged['revision'],'catalog approval advances the activity revision');
    molavi_domain_sql($db,'INSERT INTO reference_active VALUES (1,?)',[$referenceId]);
    $db->close();
    $activated=molavi_domain_dashboard_activity($now+13);
    activity_check($activated['reference_revision']!==$reviewed['revision'],'catalog activation advances the activity revision');
    $runWatch=['kind'=>'reextract','key'=>str_repeat('f',32)];
    $marketWatch=['kind'=>'market_prices','key'=>str_repeat('c',64)];
    $matchWatch=['kind'=>'reference_suggest','key'=>'reference_suggest:'.str_repeat('b',64)];
    $db=molavi_domain_db('pipeline');
    molavi_domain_sql($db,"INSERT INTO jobs(id,kind,payload,state,available) VALUES (?,'reference_suggest','{}','queued',?)",[$matchWatch['key'],$now]);
    $db->close();
    $db=molavi_domain_db('reference');
    molavi_domain_sql($db,"INSERT INTO market_price_lookups(lookup_key,identity_json,next_at,priority) VALUES (?,'{}',?,1)",
        [$marketWatch['key'],$now]);
    $db->close();
    $watched=(array)molavi_domain_dashboard_activity($now,[$runWatch,$marketWatch,$matchWatch])['watched'];
    activity_check($watched[$runWatch['key']]['completed']===1 && $watched[$runWatch['key']]['total']===2,'tracked runs return compact progress');
    foreach([$marketWatch,$matchWatch] as $watch) {
        activity_check(molavi_domain_dashboard_cancel($watch,'wp:1')['state']==='cancelled','queued work can be cancelled');
        $state=(array)molavi_domain_dashboard_activity($now,[$watch])['watched'];
        activity_check($state[$watch['key']]['state']==='cancelled','the activity feed returns the durable cancellation receipt');
    }
    echo "PASS dashboard activity and daily quota\n";
} finally {
    foreach (glob($root.'/*') as $file) unlink($file);
    rmdir($root);
}
