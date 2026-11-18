<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-ai-preview-'.bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_OWNER_IDS',[7]);
function add_action(...$args){}
function add_filter(...$args){}
function get_option($name,$default=false){return $GLOBALS['preview_options'][$name]??$default;}
function molavi_domain_sheets($names,$fresh=false){return array_intersect_key($GLOBALS['preview_tables'],array_flip($names));}
foreach(['state-host','domain-core','domain-schemas','domain-gateway','domain-ai-review-model','domain-ai-inventory','domain-ai-preview',
    'domain-ai-review-api','domain-publication-recovery','domain-ai-commands','domain-ai-dismissals','domain-bot-capabilities','domain-sheet-conflicts','domain-publisher'] as $file)
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();$db=new SQLite3($root.'/pipeline.sqlite3');$db->close();$count=0;
function check_preview($actual,$expected,$label):void {
    global $count;
    if(molavi_domain_json($actual)!==molavi_domain_json($expected))
        throw new RuntimeException($label.' '.substr(molavi_domain_json(['actual'=>$actual,'expected'=>$expected]),0,1200));
    $count++;
}
$fixtures=json_decode(file_get_contents(__DIR__.'/fixtures/native-ai-preview-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($fixtures as $fixture){
    $GLOBALS['preview_tables']=$fixture['tables'];
    $GLOBALS['preview_options']=['molavi_state_active'=>true,'molavi_bot_capabilities_v1'=>['schema_version'=>1,
        'ai_configured'=>true,'extraction_policy_version'=>$fixture['policy'],'suggestion_policy_version'=>null,'image_extraction_enabled'=>$fixture['images']]];
    $db=molavi_domain_db();
    foreach(['jobs','ai_message_dismissals','ai_source_reextractions'] as $table)$db->exec('DELETE FROM '.$table);
    foreach($fixture['seed'] as $step)molavi_domain_sql($db,$step['sql'],$step['params']);
    $db->close();
    foreach($fixture['responses'] as $i=>$response){
        $status=200;
        try{
            $query=$response['query'];$clock=strtotime('2026-11-17T00:00:00+00:00');
            $actual=$query['reextract']==='preview'?molavi_domain_ai_preview($query,$fixture['tables'],$clock):molavi_domain_ai_run_report($query,$fixture['tables'],$clock);
        }catch(MolaviWorkflowError $e){$status=$e->status;$actual=['ok'=>false,'error'=>$e->getMessage()];}
        $expected=$response['body'];if(($expected['catalog_publication']['result']??null)===[])$expected['catalog_publication']['result']=(object)[];
        check_preview($status,$response['status'],$fixture['site'].' preview status '.$i);
        check_preview($actual,$expected,$fixture['site'].' preview body '.$i);
    }
    $run=$fixture['run'];
    molavi_domain_ai_publish_completed_runs();
    $db=molavi_domain_db();$pending=molavi_domain_row($db,'SELECT id FROM jobs WHERE id=?',['publish:ai-reextraction:'.$run]);$db->close();
    check_preview($pending,null,'unfinished run must not publish');
    check_preview(molavi_domain_ai_cancel(['action'=>'cancel_reextraction','run_id'=>$run],'wp:7'),
        ['ok'=>true,'run_id'=>$run,'cancelled'=>1,'running'=>0],'only queued work cancelled');
    molavi_domain_ai_publish_completed_runs();molavi_domain_ai_publish_completed_runs();
    $db=molavi_domain_db();$receipt=molavi_domain_row($db,'SELECT state,payload FROM jobs WHERE id=?',['publish:ai-reextraction:'.$run]);$db->close();
    check_preview($receipt,['state'=>'queued','payload'=>molavi_domain_json(['source'=>'ai_reextraction','run_id'=>$run],true)],'completed offers queue an idempotent publication');
    $preview=$fixture['responses'][0]['body'];
    $command=['action'=>'dismiss_pending_messages','expected_count'=>$preview['counts']['pending'],'expected_digest'=>$preview['pending_digest'],'note'=>'Synthetic dismissal'];
    $dismissed=molavi_domain_ai_dismiss_messages($command,'wp:7');
    check_preview($dismissed['dismissed_count'],$preview['counts']['pending'],'owner dismissal matches the preview revision');
    $updated=molavi_domain_ai_inventory($fixture['tables'],$fixture['images']);
    check_preview($updated['counts']['pending'],0,'dismissals persist in the existing message ledger');
    $queued=molavi_domain_ai_start(['action'=>'start_reextraction','scope'=>'all','expected_count'=>$updated['counts']['all_available']],'wp:7');
    check_preview($queued['queued'],$updated['counts']['all_available'],'start writes the selected bot jobs');
    $status=molavi_domain_ai_run_status($queued['run_id']);
    check_preview($status['counts'],['queued'=>$queued['queued']],'queued run read stays local');
}
$offer='source-offer';
$policy=$GLOBALS['preview_options']['molavi_bot_capabilities_v1']['extraction_policy_version'];
$stamp='2026-09-16T11:00:00+00:00';
$GLOBALS['preview_tables']=[
    'MessageData'=>[
        ['ID','Batch ID','Channel Username','Content','Timestamp','URL'],
        ['11','source-batch','supplier','Pack of six',$stamp,'https://t.me/supplier/11'],
        ['12','other-batch','supplier','Another product',$stamp,'https://t.me/supplier/12'],
    ],
    'Offers'=>[
        ['Offer ID','Message ID','Batch ID','Channel Username','Original Message','Message Timestamp','Source Post URL','Owner Decision ID'],
        [$offer,'11','source-batch','supplier','Pack of six',$stamp,'https://t.me/supplier/11',''],
    ],
];
$GLOBALS['preview_options']['molavi_bot_capabilities_v1']['image_extraction_enabled']=false;
$db=molavi_domain_db();
foreach(['jobs','ai_message_dismissals','ai_source_reextractions'] as $table)$db->exec('DELETE FROM '.$table);
$sourceKey=molavi_domain_ai_source_key(molavi_domain_records($GLOBALS['preview_tables']['Offers'])[0]);
molavi_domain_sql($db,'INSERT INTO ai_source_reextractions VALUES (?,?,?,?,?,?,?)',
    [$sourceKey,'earlier','products',str_repeat('4',32),'wp:7',123,'gemini-'.str_repeat('0',16)]);
$db->close();
$selection=molavi_domain_ai_reviews_api('GET',['reextract'=>'preview','selection'=>'1','scope'=>'all'],null,'wp:7');
check_preview($selection['selection']['count'],1,'bulk filter returns a server-verified count');
check_preview(isset($selection['selection']['messages']),false,'bulk preview does not return raw messages');
$preview=molavi_domain_ai_reviews_api('GET',['reextract'=>'offer','offer_id'=>$offer],null,'wp:7');
check_preview($preview['reason'],'updated_policy','one-card preview detects a prior extraction policy');
$command=['action'=>'start_offer_reextraction','offer_id'=>$offer,'policy_version'=>$policy,
    'expected_digest'=>$preview['digest'],'run_id'=>str_repeat('a',32)];
try {
    molavi_domain_ai_start(array_replace($command,['expected_digest'=>str_repeat('9',64)]),'wp:7');
    throw new RuntimeException('stale digest accepted');
} catch (MolaviWorkflowError $e) { check_preview($e->getMessage(),'reextraction_preview_changed','reject stale digest'); }
$receipt=molavi_domain_ai_reviews_api('POST',[],$command,'wp:7');
check_preview($receipt['queued'],1,'one-card request queues exactly one job');
$db=molavi_domain_db();
$job=molavi_domain_decode(molavi_domain_row($db,"SELECT payload FROM jobs WHERE kind='reextract'")['payload']);
check_preview([$job['message_id'],$job['batch_id'],$job['policy_version']],['11','source-batch',$policy],'job targets the exact saved message and policy');
molavi_domain_sql($db,'UPDATE ai_source_reextractions SET policy_version=? WHERE source_key=?',[$policy,$sourceKey]);
$db->close();
$preview=molavi_domain_ai_offer_preview(['offer_id'=>$offer],$GLOBALS['preview_tables']);
check_preview($preview['reason'],'already_current','same-version run already recorded');
$GLOBALS['preview_tables']['Offers'][1][7]='owner-final';
$preview=molavi_domain_ai_offer_preview(['offer_id'=>$offer],$GLOBALS['preview_tables']);
check_preview($preview['reason'],'owner_decision','protect the owner decision');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);
echo 'PASS native AI previews and commands '.$count." checks\n";
