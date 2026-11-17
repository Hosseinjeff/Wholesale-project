<?php
/** Plugin Name: Molavi Native Reference Item Review */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_reference_case_id(string $offer,string $snapshot): string {return 'item:'.hash('sha256',$offer."\0".$snapshot);}
function molavi_domain_reference_latest(string $case): ?array {
    return molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE case_id=? ORDER BY sequence DESC LIMIT 1',[$case]));
}
function molavi_domain_reference_review_cases(array $tables,array $snapshot,bool $includeDecided=false,bool $candidates=true): array {
    $id=(string)molavi_domain_py_value($snapshot['snapshot_id']??null);if($id==='')return [];$offers=[];
    foreach(molavi_domain_records($tables['Offers']??[]) as $offer){$offerId=(string)molavi_domain_py_value($offer['Offer ID']??null);if($offerId!=='')$offers[$offerId]=$offer;}
    $decisions=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT d.* FROM reference_item_decisions d WHERE snapshot_id=? AND sequence=(SELECT MAX(p.sequence) FROM reference_item_decisions p WHERE p.case_id=d.case_id)',[$id]));
    $byCase=[];foreach($decisions as $decision)$byCase[$decision['case_id']]=$decision;$cases=[];
    foreach(molavi_domain_records($tables['Products']??[]) as $product){$offerId=(string)molavi_domain_py_value($product['Latest Offer ID']??null);$offer=$offers[$offerId]??null;
        $approval=(string)molavi_domain_py_value($product['Reference Approval State']??null);$due=in_array($approval,['outside_margin','reference_missing','price_unavailable'],true);
        if(!$offer||((string)molavi_domain_py_value($offer['Reference Snapshot ID']??null)!==$id&&!$due))continue;
        $match=(string)molavi_domain_py_value($offer['Reference Match State']??null);$role=(string)molavi_domain_py_value($offer['Reference Price Role Hint']??null);
        if($due){$match='reference_price_changed';$role=(string)molavi_domain_py_value($product['Reference Price Role']??null,$role);}
        elseif(in_array($match,['exact_compatible_identity','owner_approved_mapping'],true)&&in_array($role,['unit','package'],true))continue;
        $case=molavi_domain_reference_case_id($offerId,$id);$decision=$byCase[$case]??null;
        $marker=(bool)($decision&&(string)molavi_domain_py_value($product['Reference Decision ID']??null)===(string)molavi_domain_py_value($decision['decision_id']??null));
        $status=strtolower(trim((string)molavi_domain_py_value($product['Status']??null)));$projected=$marker&&!($decision['choice']==='no_match'&&$status!=='needs_review');
        if($decision&&$projected&&!$includeDecided)continue;$attrs=molavi_domain_offer_attributes($offer);
        $supplier=molavi_domain_py_value($offer['Price']??null,molavi_domain_py_value($offer['Sale Price']??null));
        $cases[]=['case_id'=>$case,'offer_id'=>$offerId,'snapshot_id'=>$id,'product_name'=>(string)molavi_domain_py_value($offer['Product Name']??null),
            'channel'=>(string)molavi_domain_py_value($offer['Channel Username']??null),'source_post_url'=>(string)molavi_domain_py_value($product['Source Post URL']??null,molavi_domain_py_value($offer['Source Post URL']??null)),
            'supplier_price'=>molavi_domain_cell_text($supplier),'match_state'=>$match,'price_role_hint'=>$role,
            'reason'=>$due?'مرجع کالا بیش از ۵٪ تغییر کرده؛ اطلاعات برگرفته از پیام اصلی را دوباره بررسی کنید.':(string)molavi_domain_py_value($offer['Review Reason']??null,$match!==''?$match:'reference_review'),
            'reference_update'=>$due?['approval_state'=>$approval,'change_percent'=>molavi_domain_cell_text(molavi_domain_py_value($product['Reference Price Change Percent']??null)),
                'reference_id'=>(string)molavi_domain_py_value($product['Reference Match ID']??null),'source_snapshot_id'=>(string)molavi_domain_py_value($product['Reference Snapshot ID']??null)]:null,
            'attributes'=>$attrs,'signature'=>molavi_domain_reference_signature($attrs),'candidates'=>$candidates?molavi_domain_reference_candidates($attrs+['supplier_price'=>$supplier],$snapshot['rows']??[],12):[],
            'projection_pending'=>(bool)($decision&&!$projected),'projection_pending_reason'=>$decision&&!$marker?'decision_not_projected':($decision&&$decision['choice']==='no_match'&&$status!=='needs_review'?'product_not_held':''),
            'decision'=>$decision];
    }return $cases;
}
function molavi_domain_reference_decide(array $payload,$expected,?string $clock=null): array {
    foreach(['case_id'=>300,'offer_id'=>300,'snapshot_id'=>300,'signature'=>128,'actor_id'=>300,'note'=>1000] as $key=>$limit)
        if(!is_string($payload[$key]??null)||trim($payload[$key])===''||molavi_domain_strlen($payload[$key])>$limit)molavi_domain_fail('invalid_fields',400);
    if(!molavi_domain_owner_actor($payload['actor_id']))molavi_domain_fail('owner_required',403);
    if($payload['choice']==='match'){if(!is_string($payload['reference_id'])||trim($payload['reference_id'])===''||molavi_domain_strlen($payload['reference_id'])>300||!in_array($payload['price_role'],['unit','package'],true))molavi_domain_fail('invalid_fields',400);}
    elseif($payload['choice']!=='no_match'||!in_array($payload['reference_id'],['',null],true)||$payload['price_role']!=='unresolved')molavi_domain_fail('invalid_fields',400);
    $payload['note']=trim($payload['note']);$payload['reference_id']=molavi_domain_py_value($payload['reference_id']);$id=molavi_domain_hash($payload);$now=$clock??molavi_domain_now();
    return molavi_domain_tx('reference',static function(SQLite3 $db)use($payload,$expected,$id,$now):array {
        $prior=molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE decision_id=?',[$id]);if($prior)return $prior;
        $latest=molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE case_id=? ORDER BY sequence DESC LIMIT 1',[$payload['case_id']]);
        if(($latest['decision_id']??null)!==$expected||($latest&&[$latest['offer_id'],$latest['snapshot_id'],$latest['signature']]!==[$payload['offer_id'],$payload['snapshot_id'],$payload['signature']]))molavi_domain_fail('review_conflict');
        molavi_domain_sql($db,'INSERT INTO reference_item_decisions (decision_id,case_id,offer_id,snapshot_id,signature,choice,reference_id,price_role,actor_id,note,created_at,supersedes_decision_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id,$payload['case_id'],$payload['offer_id'],$payload['snapshot_id'],$payload['signature'],$payload['choice'],$payload['reference_id'],$payload['price_role'],$payload['actor_id'],$payload['note'],$now,$latest['decision_id']??null]);
        return molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE decision_id=?',[$id]);
    });
}
/** Verify an undecided AI source without invoking extraction or suggestion APIs. */
function molavi_domain_ai_reference_source(array $offers,string $caseId,string $offerId,string $signature): ?array {
    if('ai:'.hash('sha256',$offerId)!==$caseId)return null;$source=null;
    foreach($offers as $offer)if((string)molavi_domain_py_value($offer['Offer ID']??null)===$offerId){$source=$offer;break;}if(!$source)return null;
    $reason=(string)molavi_domain_py_value($source['Review Reason']??null);$reasons=array_values(array_filter(array_map('trim',explode(';',strtolower($reason))),static fn($part)=>$part!==''));
    $referenceOnly=(bool)$reasons;foreach($reasons as $part)if(!str_starts_with($part,'reference:'))$referenceOnly=false;
    if(strtolower(trim((string)molavi_domain_py_value($source['Status']??null)))!=='needs_review'||$reason===''||$referenceOnly||molavi_domain_hash($source)!==$signature)return null;
    if(!str_starts_with((string)molavi_domain_py_value($source['Batch ID']??null),'reextract:'))foreach($offers as $offer)
        if(str_starts_with((string)molavi_domain_py_value($offer['Batch ID']??null),'reextract:')&&[(string)molavi_domain_py_value($source['Message ID']??null),(string)molavi_domain_py_value($source['Channel Username']??null)]===
            [(string)molavi_domain_py_value($offer['Message ID']??null),(string)molavi_domain_py_value($offer['Channel Username']??null)])return null;
    $latest=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT signature FROM ai_confirmation_decisions WHERE case_id=? ORDER BY created_at DESC LIMIT 1',[$caseId]));
    if(($latest['signature']??null)===$signature)return null;return $source;
}
function molavi_domain_reference_receipts(array $cases,string $kind,string $snapshot): array {
    $caps=molavi_domain_bot_capabilities();$policy=$caps['suggestion_policy_version'];if($policy===null)return $cases;$keys=[];
    foreach($cases as $index=>$case){$context=['case_kind'=>$kind,'case_id'=>$case['case_id'],'offer_id'=>$case['offer_id'],'signature'=>$case['signature'],'snapshot_id'=>$snapshot,'policy_version'=>$policy];
        $keys['reference_suggest:'.molavi_domain_hash($context,true,true)]=$index;}
    foreach(array_chunk(array_keys($keys),300) as $group){$rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT id,state,result,available FROM jobs WHERE id IN ('.implode(',',array_fill(0,count($group),'?')).')',$group));
        foreach($rows as $row){$index=$keys[$row['id']];$result=molavi_domain_decode($row['result']);$suggestion=$row['state']==='done'?($result['suggestion']??null):null;
            $cases[$index]['reference_suggestion']=['state'=>$row['state'],'retry_at'=>$row['state']==='queued'?$row['available']:null,'result'=>$suggestion];
            if($suggestion){$field=$kind==='ai'?'reference_candidates':'candidates';$existing=$cases[$index][$field]??[];$byId=[];$order=[];
                foreach($existing as $item)$byId[$item['reference_id']]=$item;
                foreach($suggestion['candidates']??[] as $item)$byId[$item['reference_id']]=array_replace($byId[$item['reference_id']]??[],$item);
                foreach(array_merge([$suggestion['reference_id']??null],array_column($existing,'reference_id'),array_keys($byId)) as $key)if(isset($byId[$key])&&!in_array($key,$order,true))$order[]=$key;
                $cases[$index][$field]=array_map(static fn($key)=>$byId[$key],array_slice($order,0,12));}
        }
    }return $cases;
}
function molavi_domain_reference_reviews_api(string $method,?array $body,string $actor,?string $clock=null): array {
    $snapshot=molavi_domain_reference_active();$eligibility=molavi_domain_reference_use();
    if(!$eligibility['allow_automatic']||!$snapshot||$snapshot['snapshot_id']!==$eligibility['snapshot_id']){
        if($method==='POST'&&($body['action']??null)==='suggest_reference')molavi_domain_fail('stale_snapshot');return ['ok'=>true,'reference_state'=>$eligibility,'cases'=>[],'recent_decisions'=>[]];}
    $tables=molavi_domain_sheets(['Products','Offers'],true);
    if($method==='GET'){$all=molavi_domain_reference_review_cases($tables,$snapshot,true,false);$cases=[];$recent=[];
        foreach($all as $case)if(!$case['decision']||$case['projection_pending']){$case['candidates']=molavi_domain_reference_candidates($case['attributes']+['supplier_price'=>molavi_domain_py_value($case['supplier_price'])],$snapshot['rows']??[],12);$cases[]=$case;}else $recent[]=$case;
        $cases=molavi_domain_reference_receipts($cases,'reference',$snapshot['snapshot_id']);usort($recent,static fn($a,$b)=>strcmp((string)($b['decision']['created_at']??''),(string)($a['decision']['created_at']??'')));
        return ['ok'=>true,'reference_state'=>$eligibility,'cases'=>$cases,'recent_decisions'=>array_slice($recent,0,50)];}
    if(($body['action']??null)==='suggest_reference'){
        if(molavi_domain_bot_capabilities()['suggestion_policy_version']===null)molavi_domain_fail('invalid_body',400);
        molavi_domain_body_keys($body,['action','case_kind','case_id','offer_id','signature','snapshot_id']);
        if(!in_array($body['case_kind'],['ai','reference'],true))molavi_domain_fail('invalid_fields',400);
        foreach(['case_id','offer_id','signature','snapshot_id'] as $key)if(!is_string($body[$key])||$body[$key]===''||molavi_domain_strlen($body[$key])>300)molavi_domain_fail('invalid_fields',400);
        if(!preg_match('/^[a-f0-9]{64}$/D',$body['signature']))molavi_domain_fail('invalid_fields',400);
        if($body['snapshot_id']!==$snapshot['snapshot_id'])molavi_domain_fail('stale_snapshot');$source=null;
        if($body['case_kind']==='ai')$source=molavi_domain_ai_reference_source(molavi_domain_records($tables['Offers']),$body['case_id'],$body['offer_id'],$body['signature']);
        else foreach(molavi_domain_reference_review_cases($tables,$snapshot,false,false) as $case)if($case['case_id']===$body['case_id']&&$case['offer_id']===$body['offer_id']&&$case['signature']===$body['signature']&&!$case['decision']){$source=$case;break;}
        if(!$source)molavi_domain_fail('stale_case');$context=$body;unset($context['action']);$context['policy_version']=molavi_domain_bot_capabilities()['suggestion_policy_version'];
        $job=molavi_domain_queue('reference_suggest',molavi_domain_hash($context,true,true),$context);
        return ['ok'=>true,'job_id'=>$job,'state'=>'queued','snapshot_id'=>$context['snapshot_id'],'case_id'=>$context['case_id'],'_http_status'=>202];
    }
    $assign=($body['action']??null)==='assign_ai_reference';
    molavi_domain_body_keys($body,$assign?['action','case_id','offer_id','signature','snapshot_id','reference_id','price_role','expected_decision_id']:
        ['case_id','offer_id','snapshot_id','choice','reference_id','price_role','note','expected_decision_id']);
    foreach($assign?['case_id'=>100,'offer_id'=>300,'signature'=>64,'snapshot_id'=>300,'reference_id'=>300,'price_role'=>20]:
        ['case_id'=>300,'offer_id'=>300,'snapshot_id'=>300,'choice'=>20,'reference_id'=>300,'price_role'=>20,'note'=>1000] as $key=>$limit)
        if(!is_string($body[$key])||molavi_domain_strlen($body[$key])>$limit||($assign&&$body[$key]===''))molavi_domain_fail('invalid_fields',400);
    $expected=$body['expected_decision_id'];if($expected!==null&&(!is_string($expected)||($assign?!preg_match('/^[a-f0-9]{64}$/D',$expected):strlen($expected)!==64)))molavi_domain_fail('invalid_fields',400);
    if($assign&&!preg_match('/^[a-f0-9]{64}$/D',$body['signature']))molavi_domain_fail('invalid_fields',400);
    if($body['snapshot_id']!==$snapshot['snapshot_id'])molavi_domain_fail('stale_snapshot');$references=[];
    foreach($snapshot['rows']??[] as $row)if(is_array($row)&&count($row)>16)$references[(string)molavi_domain_py_value($row[16],$row[2])]=$row;
    if($assign)return molavi_domain_assign_ai_reference($body,$actor,$references,$clock);
    $current=null;foreach(molavi_domain_reference_review_cases($tables,$snapshot,true,false) as $case)if($case['case_id']===$body['case_id']){$current=$case;break;}
    if(!$current||$current['offer_id']!==$body['offer_id']||$current['snapshot_id']!==$body['snapshot_id'])molavi_domain_fail('stale_case');
    if($body['choice']==='match'){$manual=trim($body['reference_id']);$selected=$references[$manual]??null;
        if($manual!==''&&!$selected){$normalized=molavi_domain_normalize_name($manual);$matches=[];
            foreach($references as $id=>$row)if($normalized!==''&&in_array($normalized,[molavi_domain_normalize_name((string)molavi_domain_py_value($row[0])),molavi_domain_normalize_name((string)molavi_domain_py_value($row[1]))],true))$matches[$id]=$row;
            if(count($matches)>1)molavi_domain_fail('ambiguous_reference_name',400);if(count($matches)===1){$body['reference_id']=(string)array_key_first($matches);$selected=reset($matches);}}
        if($body['reference_id']===''||!$selected)molavi_domain_fail('unknown_reference',400);
        if(!in_array($body['price_role'],['unit','package'],true))molavi_domain_fail('invalid_price_role',400);
        if($body['price_role']==='package'&&!molavi_domain_match_package($current['attributes'])&&!(float)molavi_domain_py_value($selected[6],0))molavi_domain_fail('package_count_required',400);
    }elseif($body['choice']==='no_match'){if($body['reference_id']!==''||$body['price_role']!=='unresolved')molavi_domain_fail('invalid_no_match',400);}
    else molavi_domain_fail('invalid_choice',400);
    $decision=molavi_domain_reference_decide(['case_id'=>$current['case_id'],'offer_id'=>$current['offer_id'],'snapshot_id'=>$current['snapshot_id'],'signature'=>$current['signature'],
        'choice'=>$body['choice'],'reference_id'=>$body['reference_id'],'price_role'=>$body['price_role'],'actor_id'=>$actor,'note'=>$body['note']],$expected,$clock);
    return molavi_domain_lock('publication',static function()use($tables,$current,$decision,$body,$clock):array {
        $products=molavi_domain_records($tables['Products']);$offers=molavi_domain_records($tables['Offers']);$record=null;
        foreach($products as $product)if((string)molavi_domain_py_value($product['Latest Offer ID']??null)===$current['offer_id']){$record=$product;break;}
        if($record){$matching=molavi_domain_offers_for_product($offers,$current['product_name'],$current['channel'],molavi_domain_offer_identity_fields($current['attributes']));
            if($matching)molavi_domain_refresh_conflict_product(['product_id'=>(string)molavi_domain_py_value($record['Product ID']??null),'product_name'=>$current['product_name'],'channel'=>$current['channel']],$record,array_map('strval',$tables['Products'][0]),$matching,$clock);}
        $fresh=molavi_domain_sheets(['Products','Offers','Shop'],true);$projected=null;foreach(molavi_domain_records($fresh['Products']) as $product)if((string)molavi_domain_py_value($product['Latest Offer ID']??null)===$current['offer_id']){$projected=$product;break;}
        $result=['ok'=>true,'case_id'=>$current['case_id'],'offer_id'=>$current['offer_id'],'snapshot_id'=>$current['snapshot_id'],'choice'=>$decision['choice'],
            'decision_id'=>$decision['decision_id'],'price_role'=>$decision['price_role'],'projection_state'=>'pending_verification','publish_job'=>null];
        if(!$projected||(string)molavi_domain_py_value($projected['Reference Decision ID']??null)!==$decision['decision_id']||($body['choice']==='no_match'&&strtolower((string)molavi_domain_py_value($projected['Status']??null))!=='needs_review'))return $result+['_http_status'=>202];
        $result['projection_state']=$body['choice']==='match'?strtolower((string)molavi_domain_py_value($projected['Status']??null)):'held_for_review';
        $result['publish_job']=molavi_domain_observe_projection(molavi_domain_projection_digest($fresh));
        try{molavi_domain_status_capture($fresh,'owner_reference_decision:'.$decision['decision_id'],$clock===null?null:molavi_domain_timestamp($clock));}catch(Throwable $e){error_log('[molavi-native] reference_decision_snapshot_failed');}return $result;
    });
}
function molavi_domain_assign_ai_reference(array $body,string $actor,array $references,?string $clock=null): array {
    if(!in_array($body['price_role'],['unit','package'],true))molavi_domain_fail('invalid_price_role',400);$selected=$references[$body['reference_id']]??null;
    if(!$selected)molavi_domain_fail('unknown_reference',400);
    return molavi_domain_lock('publication',static fn()=>molavi_domain_lock('activation',static function()use($body,$actor,$selected,$clock):array {
        $snapshot=molavi_domain_reference_active();$state=molavi_domain_reference_use();
        if(!$state['allow_automatic']||!$snapshot||$snapshot['snapshot_id']!==$body['snapshot_id'])molavi_domain_fail('stale_snapshot');
        $source=molavi_domain_ai_reference_source(molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']),$body['case_id'],$body['offer_id'],$body['signature']);
        if(!$source)molavi_domain_fail('stale_case');$case=molavi_domain_reference_case_id($body['offer_id'],$body['snapshot_id']);$latest=molavi_domain_reference_latest($case);
        if(($latest['decision_id']??null)!==$body['expected_decision_id'])molavi_domain_fail('review_conflict');$attrs=molavi_domain_offer_attributes($source);
        if($body['price_role']==='package'&&!molavi_domain_match_package($attrs)&&!(float)molavi_domain_py_value($selected[6],0))molavi_domain_fail('package_count_required',400);
        $decision=molavi_domain_reference_decide(['case_id'=>$case,'offer_id'=>$body['offer_id'],'snapshot_id'=>$body['snapshot_id'],'signature'=>molavi_domain_reference_signature($attrs),
            'choice'=>'match','reference_id'=>$body['reference_id'],'price_role'=>$body['price_role'],'actor_id'=>$actor,'note'=>'Owner assigned a reference from the in-progress extraction card.'],$body['expected_decision_id'],$clock);
        return ['ok'=>true,'case_id'=>$body['case_id'],'offer_id'=>$body['offer_id'],'snapshot_id'=>$body['snapshot_id'],'choice'=>'match','reference_id'=>$body['reference_id'],
            'reference_name'=>(string)molavi_domain_py_value($selected[0],$body['reference_id']),'decision_id'=>$decision['decision_id'],'price_role'=>$decision['price_role'],'projection_state'=>'awaiting_ai_confirmation','publish_job'=>null];
    },'reference'));
}
