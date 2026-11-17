<?php
/** Plugin Name: Molavi Native Publication Recovery */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_context_text($value,int $limit): string {
    $value=molavi_domain_py_value($value);$value=$value===true?'True':(string)$value;
    return function_exists('mb_substr')?mb_substr($value,0,$limit,'UTF-8'):implode('',array_slice(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY),0,$limit));
}
function molavi_domain_publication_context(string $id): array {
    if($id===''||molavi_domain_strlen($id)>100)return ['messages'=>[]];
    $row=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,"SELECT payload,state,attempts,error,result,created_at FROM jobs WHERE id=? AND kind='batch'",['batch:'.$id]));
    if(!$row)return ['messages'=>[]];$payload=molavi_domain_decode($row['payload']);$result=molavi_domain_decode($row['result']);$messages=[];
    foreach(array_slice(is_array($payload['messages']??null)?$payload['messages']:[],0,100) as $source){if(!is_array($source))continue;
        $content=molavi_domain_context_text($source['content']??'',PHP_INT_MAX);
        $messages[]=['message_id'=>molavi_domain_context_text($source['id']??'',100),
            'channel'=>molavi_domain_context_text(molavi_domain_py_value($source['channel_username']??'',($source['channel']??'')),200),
            'source_timestamp'=>molavi_domain_context_text($source['timestamp']??'',100),'source_timestamp_basis'=>molavi_domain_context_text($source['source_timestamp_basis']??'',80),
            'source_created_at'=>molavi_domain_context_text($source['source_created_at']??'',100),'source_updated_at'=>molavi_domain_context_text($source['source_update_at']??'',100),
            'source_url'=>molavi_domain_context_text($source['url']??'',1000),'content'=>molavi_domain_context_text($content,1800),'content_truncated'=>molavi_domain_strlen($content)>1800,
            'has_media'=>molavi_domain_py_value($source['has_media']??null,null)!==null,'media_type'=>molavi_domain_context_text($source['media_type']??'',80)];
    }
    return ['batch_state'=>(string)molavi_domain_py_value($row['state']),'batch_attempts'=>(int)$row['attempts'],'batch_error'=>molavi_domain_context_text($row['error'],300),
        'created_at'=>$row['created_at'],'expected_messages'=>(int)molavi_domain_py_value($result['expected_count']??null,molavi_domain_py_value($payload['expected_count']??null,count($messages))),
        'written_messages'=>(int)($result['written_count']??0),'processed_messages'=>(int)($result['processed_messages']??0),
        'extraction_state'=>(string)molavi_domain_py_value($result['extraction_status']??''),'processing_error'=>molavi_domain_context_text($result['extraction_error']??'',300),
        'processing_detail'=>molavi_domain_context_text($result['extraction_detail']??'',200),'rollback'=>molavi_domain_py_value($result['rollback']??null,null)!==null,'messages'=>$messages];
}
function molavi_domain_publication_holds(): array {
    $holds=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM publication_holds ORDER BY created_at'));
    foreach($holds as &$hold)$hold['context']=molavi_domain_publication_context((string)molavi_domain_py_value($hold['hold_id']));unset($hold);return ['ok'=>true,'holds'=>$holds];
}
function molavi_domain_publication_resolve(array $holds,string $actor,string $note,bool $resume,?float $clock=null): array {
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($holds,$actor,$note,$resume,$clock):array {
        foreach($holds as $hold){$saved=molavi_domain_row($db,'SELECT reason FROM publication_holds WHERE hold_id=?',[$hold['hold_id']]);
            if(!$saved||$saved['reason']!==$hold['expected_reason'])molavi_domain_fail('publication_hold_changed');
            if($resume&&(!($job=molavi_domain_row($db,"SELECT state FROM jobs WHERE id=?",['batch:'.$hold['hold_id']]))||$job['state']!=='needs_review'))molavi_domain_fail('publication_hold_changed');}
        $now=$clock??microtime(true);
        foreach($holds as $hold){if($resume)molavi_domain_sql($db,"UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL WHERE id=?",[$now,'batch:'.$hold['hold_id']]);
            molavi_domain_sql($db,'INSERT INTO publication_hold_resolutions VALUES (NULL,?,?,?,?,?)',[$hold['hold_id'],$hold['expected_reason'],$actor,$note,$now]);
            molavi_domain_sql($db,'DELETE FROM publication_holds WHERE hold_id=?',[$hold['hold_id']]);}
        if(!molavi_domain_row($db,'SELECT 1 FROM publication_holds LIMIT 1'))molavi_domain_sql($db,"UPDATE jobs SET state='queued',available=?,lease=NULL,expires=NULL,error=NULL WHERE kind='publish' AND state='waiting_publication'",[$now]);
        return array_column($holds,'hold_id');
    });
}
function molavi_domain_offer_ai_review(array $offer): bool {
    if(strtolower(trim((string)($offer['Status']??'')))!=='needs_review')return false;
    foreach(explode(';',(string)($offer['Review Reason']??'')) as $part){$reason=strtolower(trim($part));if($reason!==''&&!str_starts_with($reason,'reference:'))return true;}return false;
}
function molavi_domain_batch_sources_verified(string $id,array $tables): bool {
    if(!str_starts_with($id,'batch:'))return false;$batch=substr($id,6);$context=molavi_domain_publication_context($batch);$sources=$context['messages']??[];
    if(($context['batch_state']??null)!=='failed'||!$sources||(int)($context['expected_messages']??0)!==count($sources))return false;
    $messages=molavi_domain_records($tables['MessageData']??[]);$offers=molavi_domain_records($tables['Offers']??[]);
    foreach($sources as $source){$matches=array_values(array_filter($messages,static fn($row)=>(string)molavi_domain_py_value($row['ID']??'')===$source['message_id']
        &&(string)molavi_domain_py_value($row['Channel Username']??'')===$source['channel']&&(string)molavi_domain_py_value($row['Batch ID']??'')===$batch));
        $sourceOffers=array_values(array_filter($offers,static fn($row)=>(string)molavi_domain_py_value($row['Message ID']??'')===$source['message_id']
            &&(string)molavi_domain_py_value($row['Channel Username']??'')===$source['channel']&&(string)molavi_domain_py_value($row['Batch ID']??'')===$batch));
        if(count($matches)>1)return false;
        if(!$matches){if(!$sourceOffers)return false;foreach($sourceOffers as $offer)if(!molavi_domain_offer_ai_review($offer)||trim((string)molavi_domain_py_value($offer['Offer ID']??''))==='')return false;continue;}
        $row=$matches[0];$state=strtolower(trim((string)molavi_domain_py_value($row['AI Status']??'')));
        if(!in_array($state,['needs_confirmation','attachment_only','needs_media_review','ai_processed','no_product_candidate','owner_rejected','not_product'],true)||trim((string)molavi_domain_py_value($row['AI Processed At']??''))==='')return false;
        if($state==='needs_confirmation'){try{$cases=molavi_domain_decode($row['AI Review Case IDs']??'');}catch(JsonException $e){return false;}if(!$cases||!array_is_list($cases))return false;$actual=[];
            foreach($sourceOffers as $offer)if(molavi_domain_offer_ai_review($offer))$actual[]='ai:'.hash('sha256',(string)molavi_domain_py_value($offer['Offer ID']??''));
            $expected=array_values(array_unique($cases));$actual=array_values(array_unique($actual));sort($expected);sort($actual);if($expected!==$actual)return false;}
    }return true;
}
function molavi_domain_publication_holds_api(string $method,array $body,string $actor): array {
    if($method==='GET')return molavi_domain_publication_holds();
    if(($body['action']??null)==='resume_selected'){
        molavi_domain_body_keys($body,['action','holds']);$holds=$body['holds'];
        if(!is_array($holds)||!array_is_list($holds)||!$holds||count($holds)>100)molavi_domain_fail('invalid_body',400);$seen=[];
        foreach($holds as $hold){if(!is_array($hold))molavi_domain_fail('invalid_body',400);molavi_domain_body_keys($hold,['hold_id','expected_reason']);
            if(!is_string($hold['hold_id'])||$hold['hold_id']===''||molavi_domain_strlen($hold['hold_id'])>100||!is_string($hold['expected_reason'])||$hold['expected_reason']===''||molavi_domain_strlen($hold['expected_reason'])>300||isset($seen[$hold['hold_id']]))molavi_domain_fail('invalid_body',400);$seen[$hold['hold_id']]=true;}
        $resolved=molavi_domain_lock('publication',static fn()=>molavi_domain_publication_resolve($holds,$actor,'Owner reviewed the linked source messages and authorized one batch retry.',true));
        return ['ok'=>true,'resumed_count'=>count($resolved),'resumed_hold_ids'=>$resolved,'batch_resumed'=>true];
    }
    $resume=$body['resume_batch']??null;
    if($resume===false){molavi_domain_body_keys($body,['hold_id','expected_reason','note','resume_batch','expected_batch_state','expected_batch_error']);
        if(($body['expected_reason']??null)!=='batch_in_progress'||($body['expected_batch_state']??null)!=='failed'||($body['expected_batch_error']??null)!=='JobConflict'
            ||!is_string($body['hold_id'])||$body['hold_id']===''||molavi_domain_strlen($body['hold_id'])>100||!is_string($body['note'])||trim($body['note'])===''||molavi_domain_strlen($body['note'])>1000)molavi_domain_fail('invalid_body',400);
        return molavi_domain_lock('publication',static function()use($body,$actor):array {
            $job=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT state,error FROM jobs WHERE id=?',['batch:'.$body['hold_id']]));
            if(!$job||$job['state']!=='failed'||$job['error']!=='JobConflict')molavi_domain_fail('publication_hold_changed');
            if(!molavi_domain_batch_sources_verified('batch:'.$body['hold_id'],molavi_domain_sheets(['MessageData','Offers'],true)))molavi_domain_fail('batch_not_reconciled');
            molavi_domain_publication_resolve([['hold_id'=>$body['hold_id'],'expected_reason'=>$body['expected_reason']]],$actor,$body['note'],false);
            return ['ok'=>true,'hold_id'=>$body['hold_id'],'batch_resumed'=>false,'reconciled'=>true];
        });
    }
    molavi_domain_body_keys($body,['hold_id','expected_reason','note','resume_batch']);if($resume!==true)molavi_domain_fail('invalid_body',400);
    foreach(['hold_id','expected_reason','note'] as $key)if(!is_string($body[$key])||trim($body[$key])===''||molavi_domain_strlen($body[$key])>($key==='note'?1000:300))molavi_domain_fail('conflict_or_invalid');
    try{molavi_domain_lock('publication',static fn()=>molavi_domain_publication_resolve([['hold_id'=>$body['hold_id'],'expected_reason'=>$body['expected_reason']]],$actor,$body['note'],true));}
    catch(MolaviWorkflowError $e){if($e->getMessage()==='publication_hold_changed')molavi_domain_fail('conflict_or_invalid');throw $e;}
    return ['ok'=>true,'hold_id'=>$body['hold_id'],'batch_resumed'=>true];
}
function molavi_domain_failed_backlog(): array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db):array {
        $result=['publication_ids'=>[],'batch_ids'=>[]];foreach(molavi_domain_rows($db,"SELECT id,kind FROM jobs WHERE state='failed' AND kind IN ('publish','batch') ORDER BY id") as $row)
            $result[$row['kind']==='publish'?'publication_ids':'batch_ids'][]=$row['id'];return $result;
    });
}
function molavi_domain_sync_summary(array $sync): array {
    $result=[];foreach(['count','created','updated','hidden','attached','images_left'] as $key)$result[$key]=$sync[$key]??null;return $result;
}
function molavi_domain_recovery_images_left($value): int {
    $value=molavi_domain_py_value($value,0);
    if(is_bool($value)||is_int($value))return (int)$value;
    if(is_float($value))return is_finite($value)?(int)$value:-1;
    if(is_string($value)){$raw=trim(molavi_domain_digits($value));if(preg_match('/^[+-]?\d+(?:_\d+)*$/D',$raw))return (int)str_replace('_','',$raw);}
    return -1;
}
function molavi_domain_reconcile_failed(array $publications,array $batches,string $actor,string $digest,array $sync,?float $clock=null): array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||($sync['ok']??null)!==true||($sync['pushed']??null)!==true||molavi_domain_recovery_images_left($sync['images_left']??0)!==0)molavi_domain_fail('catalog_sync_incomplete',502);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($publications,$batches,$actor,$digest,$sync,$clock):array {
        $now=$clock??microtime(true);$summary=molavi_domain_sync_summary($sync);$selected=[];
        foreach([['ids'=>$publications,'kind'=>'publish'],['ids'=>$batches,'kind'=>'batch']] as $group){$ids=$group['ids'];$rows=[];
            if($ids)$rows=molavi_domain_rows($db,"SELECT id,error FROM jobs WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).") AND kind=? AND state='failed'",[...$ids,$group['kind']]);
            $actual=array_column($rows,'id');$expected=$ids;sort($actual);sort($expected);if($actual!==$expected)molavi_domain_fail('backlog_changed');$selected[$group['kind']]=$rows;
        }
        foreach($selected as $kind=>$rows)foreach($rows as $row){
            $receipt=$kind==='publish'?['ok'=>true,'pushed'=>true,'superseded_by_catalog_sync'=>true]:['ok'=>true,'reconciled_after_catalog_sync'=>true,'source_rows_verified'=>true];
            $receipt+=['reconciled_at'=>$now,'actor_id'=>$actor,'sheet_digest'=>$digest,'previous_error'=>(string)molavi_domain_py_value($row['error']),'sync'=>$summary];
            molavi_domain_sql($db,"UPDATE jobs SET state='done',result=?,error=NULL,lease=NULL,expires=NULL,updated_at=? WHERE id=? AND kind=? AND state='failed'",[molavi_domain_json($receipt,true),$now,$row['id'],$kind]);
        }
        return ['publication_ids'=>array_column($selected['publish'],'id'),'batch_ids'=>array_column($selected['batch'],'id')];
    });
}
function molavi_domain_reconcile_backlog_api(array $body,string $actor): array {
    molavi_domain_body_keys($body,['expected_publish_ids','expected_batch_ids']);$publications=$body['expected_publish_ids'];$batches=$body['expected_batch_ids'];
    foreach([[$publications,'publish:',500],[$batches,'batch:',100]] as [$ids,$prefix,$max]){
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>$max)molavi_domain_fail('invalid_body',400);
        foreach($ids as $id)if(!is_string($id)||!str_starts_with($id,$prefix)||molavi_domain_strlen($id)>300)molavi_domain_fail('invalid_body',400);
        if(count(array_unique($ids))!==count($ids))molavi_domain_fail('invalid_body',400);
    }
    if(!$publications&&!$batches)molavi_domain_fail('invalid_body',400);
    return molavi_domain_lock('publication',static function()use($publications,$batches,$actor):array {
        if(molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT 1 FROM publication_holds LIMIT 1')))molavi_domain_fail('publication_held_for_review');
        $backlog=molavi_domain_failed_backlog();if(array_diff($publications,$backlog['publication_ids'])||array_diff($batches,$backlog['batch_ids']))molavi_domain_fail('backlog_changed');
        $tables=molavi_domain_sheets(['Products','Offers','Shop'],true);$before=molavi_domain_projection_digest($tables);$sync=molavi_domain_publish_shop($tables,false,12);
        $left=molavi_domain_recovery_images_left($sync['images_left']??0);
        if(($sync['ok']??null)!==true||($sync['pushed']??null)!==true||$left!==0){$error=substr(preg_replace('/[^A-Za-z0-9_. -]+/','',(string)molavi_domain_py_value($sync['error']??null,($sync['reason']??''))),0,100);
            molavi_domain_fail('catalog_sync_incomplete',502,['sync_error'=>$error,'sync_row'=>is_int($sync['row']??null)&&$sync['row']>=0?$sync['row']:null,'images_left'=>$left>=0?$left:null]);}
        $fresh=molavi_domain_sheets(['Products','Shop'],true);$after=molavi_domain_projection_digest($fresh);
        if($after!==$before){molavi_domain_observe_projection($after);molavi_domain_fail('catalog_projection_changed',409,['current_projection_queued'=>true]);}
        $latest=molavi_domain_failed_backlog();if(array_diff($publications,$latest['publication_ids'])||array_diff($batches,$latest['batch_ids']))molavi_domain_fail('backlog_changed');
        molavi_domain_projection_published($after);$verified=[];$sources=molavi_domain_sheets(['MessageData','Offers'],true);
        foreach($batches as $id){try{if(molavi_domain_batch_sources_verified($id,$sources))$verified[]=$id;}catch(Throwable $e){}}
        $saved=molavi_domain_reconcile_failed($publications,$verified,$actor,$after,$sync);$unresolved=array_values(array_diff($batches,$saved['batch_ids']));sort($unresolved);
        return ['ok'=>true,'sheet_digest'=>$after,'catalog_sync'=>molavi_domain_sync_summary($sync),'failed_publications_reconciled'=>count($saved['publication_ids']),
            'failed_publication_ids'=>$saved['publication_ids'],'batch_jobs_reconciled'=>count($saved['batch_ids']),'batch_job_ids_reconciled'=>$saved['batch_ids'],'batch_job_ids_left_open'=>$unresolved];
    });
}
