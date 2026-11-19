<?php
/** Plugin Name: Molavi Native Workflow Read Models */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_operations(array $query, ?float $clock = null): array {
    $now=$clock??microtime(true); $limit=(string)($query['limit']??'100');
    if(!ctype_digit($limit))molavi_domain_fail('invalid_queue_page',400);
    $limit=max(1,min(200,(int)$limit)); $snapshot=$now; $after=null;
    if(!empty($query['cursor'])) {
        $cursor=$query['cursor'];
        if(!is_string($cursor)||strlen($cursor)>2048)molavi_domain_fail('invalid_queue_page',400);
        $raw=base64_decode(strtr($cursor,'-_','+/'),true); $after=$raw===false?null:json_decode($raw,true);
        if(!is_array($after)||($after['v']??null)!==1
            ||!is_numeric($after['snapshot_time']??null)||!is_finite((float)$after['snapshot_time'])
            ||!is_numeric($after['last_created_at']??null)||!is_finite((float)$after['last_created_at'])
            ||!is_string($after['last_id']??null)||$after['last_id']===''||strlen($after['last_id'])>300)
            molavi_domain_fail('invalid_queue_page',400);
        $snapshot=(float)$after['snapshot_time'];
    }
    return molavi_domain_read('pipeline', static function(SQLite3 $db)use($now,$limit,$snapshot,$after):array {
        $db->exec('BEGIN');
        $states=['queued','running','waiting_reference','waiting_publication','failed','needs_review'];
        $byState=[]; foreach(molavi_domain_rows($db,'SELECT state,COUNT(*) AS count FROM jobs GROUP BY state') as $row)$byState[$row['state']]=$row['count'];
        $created='COALESCE(created_at,updated_at,available,0)';
        $where="state IN (?,?,?,?,?,?) AND $created<=?"; $params=[...$states,$snapshot];
        if($after){$where.=" AND ($created>? OR ($created=? AND id>?))";array_push($params,$after['last_created_at'],$after['last_created_at'],$after['last_id']);}
        $rows=molavi_domain_rows($db,"SELECT id,kind,state,attempts,available,expires,error,created_at,$created AS cursor_created_at FROM jobs WHERE $where ORDER BY cursor_created_at,id LIMIT ?",[...$params,$limit+1]);
        $more=count($rows)>$limit;$rows=array_slice($rows,0,$limit);$items=[];
        foreach($rows as $row){$item=array_intersect_key($row,array_flip(['id','kind','state','attempts','available','error','created_at']));
            $item['lease_expired']=$row['state']==='running'&&$row['expires']!==null&&$row['expires']<=$now;
            $item['age_seconds']=max(0,(int)($now-(float)($row['cursor_created_at']?:0)));
            $item['next_action']=['queued'=>'automatic_retry','running'=>$item['lease_expired']?'recover_expired_lease':'wait_for_processing',
                'waiting_reference'=>'resolve_reference_decision','waiting_publication'=>'resolve_publication_hold','failed'=>'inspect_failure_and_reconcile',
                'needs_review'=>'inspect_result_and_decide'][$row['state']]??'inspect_receipt';$items[]=$item;}
        $cursor=null;
        if($more&&$rows){$last=end($rows);$cursor=rtrim(strtr(base64_encode(molavi_domain_json(['v'=>1,'snapshot_time'=>$snapshot,
            'last_created_at'=>$last['cursor_created_at'],'last_id'=>$last['id']],false,false,false)),'+/','-_'),'=');}
        $worker=molavi_domain_row($db,'SELECT worker_id,started_at,heartbeat_at,state,current_job_id,progress_at,last_claimed_job_id,last_claimed_at,last_completed_job_id,last_completed_at,last_error_code FROM pipeline_workers ORDER BY heartbeat_at DESC,started_at DESC LIMIT 1');
        if($worker){$age=max(0,$now-$worker['heartbeat_at']);$progress=max(0,$now-($worker['progress_at']?:$worker['started_at']));
            $worker+=['status'=>$worker['state']==='stopped'?'stopped':($age>45?'stale':'alive'),'heartbeat_age_seconds'=>round($age,1),
                'progress_status'=>$worker['state']==='stopped'?'stopped':($progress>300?'stalled':($worker['state']==='idle'?'idle':'active')),'progress_age_seconds'=>round($progress,1)];
        }else $worker=['status'=>'unknown','heartbeat_age_seconds'=>null,'progress_status'=>'unknown','progress_age_seconds'=>null];
        $failed=['publication_ids'=>[],'batch_ids'=>[]];foreach(molavi_domain_rows($db,"SELECT id,kind FROM jobs WHERE state='failed' AND kind IN ('publish','batch') ORDER BY id") as $row)
            $failed[$row['kind']==='publish'?'publication_ids':'batch_ids'][]=$row['id'];
        $summary=['snapshot_time'=>$snapshot,'by_state'=>(object)$byState,'actionable_count'=>array_sum(array_intersect_key($byState,array_flip($states))),
            'attention_count'=>array_sum(array_intersect_key($byState,array_flip(['waiting_reference','waiting_publication','failed','needs_review']))),
            'items'=>$items,'page_count'=>count($items),'has_more'=>$cursor!==null,'next_cursor'=>$cursor,'items_truncated'=>$cursor!==null,
            'publication_holds'=>molavi_domain_row($db,'SELECT COUNT(*) AS n FROM publication_holds')['n'],
            'sheet_conflicts'=>molavi_domain_row($db,"SELECT COUNT(*) AS n FROM sheet_sync_conflicts WHERE state='open'")['n'],
            'worker'=>$worker,'pending_telegram_chats'=>molavi_domain_rows($db,"SELECT chat_id,first_seen_at,last_seen_at,seen_count,revision FROM pending_telegram_chats WHERE state='pending' ORDER BY last_seen_at DESC,chat_id DESC LIMIT 50"),
            'failed_publication_ids'=>$failed['publication_ids'],'failed_batch_ids'=>$failed['batch_ids']];
        $db->exec('COMMIT');return ['ok'=>true,'operations'=>$summary];
    });
}
function molavi_domain_reference_source(): string {return 'https://rezvanpakhsh.com/wp-json/wc/store/v1/products';}
function molavi_domain_reference_coverage(array $candidate): bool {
    $rows=$candidate['rows']??null; $total=$candidate['source_page_count']??null;
    if(($candidate['coverage_verified']??null)!==true||($candidate['source_type']??null)!=='woocommerce_store_api'
        ||($candidate['source_url']??null)!==molavi_domain_reference_source()||!is_int($total)||$total<1
        ||($candidate['processed_page_count']??null)!==$total||!is_array($rows)||!$rows
        ||($candidate['source_total']??null)!==count($rows)||($candidate['row_count']??null)!==count($rows)
        ||!in_array($candidate['source_currency']??null,['IRR','IRT'],true)
        ||($candidate['first_source_row_number']??null)!==1||($candidate['last_source_row_number']??null)!==count($rows))return false;
    if(isset($candidate['source_product_ids'])){$ids=$candidate['source_product_ids'];
        if(!is_array($ids)||count($ids)!==count($rows)||count(array_unique($ids))!==count($ids))return false;
        foreach($ids as $id)if(!is_string($id)||!preg_match('/^[1-9][0-9]*$/D',$id))return false;}
    foreach($rows as $index=>$row)if(!is_array($row)||!is_string($row[15]??null)||!preg_match('/^[0-9]{1,6}$/D',$row[15])||(int)$row[15]!==$index+1)return false;
    return true;
}
function molavi_domain_reference_preview(array $values, string $sourceId=''): array {
    $fields=['product_name','clean_name','normalized_key','package_price','unit_price','consumer_unit_price','package_count','unit_label',
        'volume','weight','set_count','variant','brand','category','raw_name_details','source_row_number','product_code'];
    $out=['source_product_id'=>$sourceId];foreach($fields as $index=>$field)$out[$field]=$values[$index]??'';return $out;
}
function molavi_domain_reference_snapshots(array $query): array {
    return molavi_domain_read('reference',static function(SQLite3 $db)use($query):array {
        if(($query['include_rows']??'')==='1'){
            $id=$query['snapshot_id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))molavi_domain_fail('invalid_fields',400);
            $row=molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$id]);if(!$row)molavi_domain_fail('snapshot_not_found',404);
            $candidate=molavi_domain_decode($row['payload']);$rows=[];
            foreach($candidate['rows']??[] as $index=>$values)if(is_array($values)&&count($values)===21)$rows[]=molavi_domain_reference_preview($values,(string)($candidate['source_product_ids'][$index]??''));
            return ['ok'=>true,'snapshot_id'=>$id,'row_count'=>$candidate['row_count']??null,'coverage_verified'=>molavi_domain_reference_coverage($candidate),'rows'=>$rows];
        }
        $active=molavi_domain_row($db,'SELECT snapshot_id FROM reference_active WHERE singleton=1')['snapshot_id']??null;
        $check=molavi_domain_row($db,'SELECT snapshot_id,checked_at FROM reference_source_check WHERE singleton=1');$snapshots=[];$activeCheckedAt=null;
        foreach(molavi_domain_rows($db,'SELECT * FROM reference_snapshots ORDER BY rowid DESC LIMIT 50') as $row){
            $candidate=molavi_domain_decode($row['payload']);$summary=[];foreach(['added_count','updated_count','removed_count','has_changes','metadata_upgrade','order_changed'] as $field)$summary[$field]=$candidate['change_summary'][$field]??0;
            $preview=[];foreach(array_slice($candidate['rows']??[],0,8) as $index=>$values)if(is_array($values)&&count($values)===21)$preview[]=molavi_domain_reference_preview($values,(string)($candidate['source_product_ids'][$index]??''));
            $item=['snapshot_id'=>$row['snapshot_id']];foreach(['extracted_at','row_count','pdf_sha256','source_type','previous_snapshot_id','source_total','source_currency','model','source_url','source_page_count','processed_page_count','first_source_row_number','last_source_row_number'] as $field)$item[$field]=$candidate[$field]??null;
            if($row['snapshot_id']===$active)$activeCheckedAt=($check['snapshot_id']??null)===$active?$check['checked_at']:$item['extracted_at'];
            $snapshots[]=array_merge($item,['automatic_update'=>(bool)($candidate['automatic_update']??false),'change_summary'=>$summary,
                'coverage_verified'=>molavi_domain_reference_coverage($candidate),'preview'=>$preview,'preview_total'=>$candidate['row_count']??0,
                'reviewed_by'=>$row['reviewed_by'],'review_note'=>$row['review_note'],'reviewed_at'=>$row['reviewed_at'],
                'active'=>$row['snapshot_id']===$active,'last_checked_at'=>($check['snapshot_id']??null)===$row['snapshot_id']?$check['checked_at']:null]);
        }
        $refresh=molavi_domain_row($db,'SELECT * FROM reference_refresh_state WHERE singleton=1');
        if($refresh){$summary=molavi_domain_decode($refresh['change_summary'],null);if(is_array($summary)){$compact=[];foreach(['added_count','updated_count','removed_count','has_changes','metadata_upgrade','order_changed'] as $field)$compact[$field]=$summary[$field]??0;$summary=$compact;}$refresh['change_summary']=$summary;
            $refresh['in_progress']=$refresh['state']==='running'&&(bool)molavi_domain_row($db,
                "SELECT 1 FROM molavi_state_locks WHERE name='refresh' AND expires>?",[microtime(true)]);
        }
        $review=molavi_domain_reference_review_status($active,molavi_domain_reference_live_events($db,$active));
        $checkedAt=molavi_domain_timestamp($activeCheckedAt);
        if($review['state']==='ready'&&($checkedAt===null||microtime(true)-$checkedAt>=MOLAVI_REFERENCE_REFRESH_SECONDS))
            $review['state']='refresh_due';
        return ['ok'=>true,'active_snapshot_id'=>$active,'snapshots'=>$snapshots,'refresh_state'=>$refresh,'reference_review'=>$review];
    });
}
