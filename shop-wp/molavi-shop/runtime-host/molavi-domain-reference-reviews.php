<?php
/** Plugin Name: Molavi Native Reference Item Review */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_reference_case_id(string $offer,string $snapshot): string {return 'item:'.hash('sha256',$offer."\0".$snapshot);}

function molavi_domain_reference_extracted_offer(array $offer,string $sourceOfferId): array {
    $columns=[
        'sale_price'=>'Sale Price','consumer_price'=>'Consumer Price','actual_price'=>'Actual Price',
        'price'=>'Price','detected_price'=>'Detected Price','package_price'=>'Package Price',
        'double_pack_price'=>'Double Pack Price','consumer_package_price'=>'Double Pack Consumer Price',
        'price_type'=>'Price Type','currency'=>'Currency','packaging'=>'Packaging','package_count'=>'Package Count',
        'volume'=>'Volume','weight'=>'Weight','min_qty'=>'Min Qty','brand'=>'Brand','category'=>'Category',
        'stock_status'=>'Stock Status','confidence'=>'Extraction Confidence Score','review_reason'=>'Review Reason',
        'description'=>'Description',
    ];
    $fields=[];
    foreach($columns as $field=>$column) {
        $value=trim(molavi_domain_cell_text($offer[$column]??''));
        if($value!=='') $fields[$field]=$value;
    }
    foreach(['confidence'=>'Confidence','consumer_package_price'=>'Consumer Package Price'] as $field=>$column) {
        $value=trim(molavi_domain_cell_text($offer[$column]??''));
        if($value!==''&&!isset($fields[$field])) $fields[$field]=$value;
    }
    if(($fields['description']??null)===trim((string)($offer['Original Message']??''))) unset($fields['description']);
    return ['title'=>trim((string)($offer['Product Name']??'')),
        'variant'=>trim((string)($offer['Variation Type']??'')),
        'current'=>(string)$offer['Offer ID']===$sourceOfferId,'fields'=>(object)$fields];
}

function molavi_domain_reference_extracted_offers(array $offers,string $offerId,bool $includeIds=false): array {
    $byId=static fn(string $id): array=>array_values(array_filter($offers,
        static fn(array $offer): bool=>(string)($offer['Offer ID']??'')===$id));
    $matches=$byId($offerId);
    if(count($matches)!==1) molavi_domain_fail('extracted_titles_unavailable',404);
    $source=$matches[0];
    $original=(string)($source['Derived From Offer ID']??'');
    if($original!=='') {
        $parents=$byId($original);
        if(count($parents)===1) $source=$parents[0];
    }

    $sourceOfferId=(string)$source['Offer ID'];
    $channel=static fn(array $offer): string=>(string)molavi_domain_py_value($offer['Channel Username']??null,$offer['Channel']??'');
    $sourceChannel=$channel($source);
    $batch=(string)($source['Batch ID']??'');
    $message=(string)($source['Message ID']??'');
    $post=molavi_domain_telegram_source_url($source['Source Post URL']??null);
    $sourceKey=($message!==''||$post!=='')&&trim((string)($source['Original Message']??''))!==''
        ? molavi_domain_ai_source_key($source) : '';
    $latest=[];$generation=null;
    foreach($offers as $offer) {
        $id=(string)($offer['Offer ID']??'');
        $offerBatch=(string)($offer['Batch ID']??'');
        $offerMessage=(string)($offer['Message ID']??'');
        if($id===''||$offerBatch===''||!empty($offer['Derived From Offer ID'])||$channel($offer)!==$sourceChannel) continue;
        $sameReceipt=$offerBatch===$batch&&($message!==''?$offerMessage===$message:
            $post!==''&&molavi_domain_telegram_source_url($offer['Source Post URL']??null)===$post);
        if(!$sameReceipt&&($sourceKey===''||molavi_domain_ai_source_key($offer)!==$sourceKey)) continue;
        // Each extraction appends its offers together. Keep the latest generation of this source.
        $key=[$offerBatch,$offerMessage];
        if($key!==$generation) {$latest=[];$generation=$key;}
        $latest[$id]=$offer;
    }
    $extracted=[];
    foreach($latest?:[$source] as $offer) {
        $item=molavi_domain_reference_extracted_offer($offer,$sourceOfferId);
        if($item['title']!=='') $extracted[]=$includeIds?$item+['offer_id'=>(string)$offer['Offer ID']]:$item;
    }
    return ['titles'=>$extracted,'reviewed_offer'=>molavi_domain_reference_extracted_offer($source,$sourceOfferId)];
}

function molavi_domain_reference_latest(string $case): ?array {
    return molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE case_id=? ORDER BY sequence DESC LIMIT 1',[$case]));
}
/** reference_import/item_reviews.py ItemReviewLedger.overrides: exact owner mappings + inherited mappings within 5%. */
function molavi_domain_reference_overrides(string $snapshotId): array {
    if($snapshotId==='')return [];
    // Latest decision per case_id WITHIN this snapshot (Python's join form).
    // ORDER BY sequence makes the values[-1] pick deterministic; Python fetches
    // with no ORDER BY, so which identical mapping wins there is unspecified.
    $rows=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        'SELECT d.* FROM reference_item_decisions d JOIN (SELECT case_id, MAX(sequence) AS sequence FROM reference_item_decisions WHERE snapshot_id=? GROUP BY case_id) latest ON latest.sequence=d.sequence ORDER BY d.sequence',
        [$snapshotId]));
    $grouped=[];
    foreach($rows as $row){
        $grouped[(string)($row['signature']??'')][]=[
            'choice'=>(string)($row['choice']??''),'reference_id'=>(string)($row['reference_id']??''),
            'price_role'=>(string)($row['price_role']??''),'decision_id'=>(string)($row['decision_id']??'')];
    }
    $result=[];
    foreach($grouped as $signature=>$values){
        $mappings=[];foreach($values as $value)$mappings[$value['reference_id']."\0".$value['price_role']]=true;
        $allMatch=true;foreach($values as $value)if($value['choice']!=='match'){$allMatch=false;break;}
        if($allMatch&&count($mappings)===1)$result[$signature]=end($values);
    }
    foreach(molavi_domain_inherit_reference_mappings($snapshotId) as $signature=>$value)
        if(!isset($result[$signature]))$result[$signature]=$value;
    return $result;
}
function molavi_domain_reference_review_cases(array $tables,array $snapshot,bool $includeDecided=false,bool $candidates=true,string $manualOffer=''): array {
    $id=(string)molavi_domain_py_value($snapshot['snapshot_id']??null);if($id==='')return [];$offers=[];
    $sources=molavi_domain_records($tables['Offers']??[]);$products=molavi_domain_records($tables['Products']??[]);
    foreach($sources as $offer){$offerId=(string)molavi_domain_py_value($offer['Offer ID']??null);if($offerId!=='')$offers[$offerId]=$offer;}
    $manualAvailable=$manualOffer!==''&&count(array_keys(array_column($products,'Latest Offer ID'),$manualOffer,true))===1
        &&count(array_keys(array_column($sources,'Offer ID'),$manualOffer,true))===1;
    $sourceIssues=$manualAvailable?array_column(molavi_domain_source_issues($products,$sources),'codes','product_id'):[];
    $decisions=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT d.* FROM reference_item_decisions d WHERE snapshot_id=? AND sequence=(SELECT MAX(p.sequence) FROM reference_item_decisions p WHERE p.case_id=d.case_id)',[$id]));
    $byCase=[];foreach($decisions as $decision)$byCase[$decision['case_id']]=$decision;$cases=[];
    foreach($products as $product){$offerId=(string)molavi_domain_py_value($product['Latest Offer ID']??null);$offer=$offers[$offerId]??null;
        $status=strtolower(trim((string)molavi_domain_py_value($product['Status']??null)));
        if ($manualOffer!==''&&$offerId===$manualOffer&&in_array($status,['not_product','skipped'],true)) continue;
        $manual=$manualAvailable&&$offerId===$manualOffer&&!isset($sourceIssues[$product['Product ID']??'']);
        $approval=(string)molavi_domain_py_value($product['Reference Approval State']??null);
        $waived=$approval==='owner_without_reference';
        if($waived&&!$includeDecided)continue;
        $due=in_array($approval,['outside_margin','reference_missing','price_unavailable'],true);
        if(!$offer||(!$manual&&(string)molavi_domain_py_value($offer['Reference Snapshot ID']??null)!==$id&&!$due&&!$waived))continue;
        $match=(string)molavi_domain_py_value($offer['Reference Match State']??null);$role=(string)molavi_domain_py_value($offer['Reference Price Role Hint']??null);
        if($due){$match='reference_price_changed';$role=(string)molavi_domain_py_value($product['Reference Price Role']??null,$role);}
        elseif(!$manual&&!$waived&&in_array($match,['exact_compatible_identity','owner_approved_mapping'],true)&&in_array($role,['unit','package'],true))continue;
        $case=molavi_domain_reference_case_id($offerId,$id);$decision=$byCase[$case]??null;
        $marker=(bool)($decision&&(string)molavi_domain_py_value($product['Reference Decision ID']??null)===(string)molavi_domain_py_value($decision['decision_id']??null));
        $projected=$marker&&!($decision['choice']==='no_match'&&$status!=='needs_review');
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
            'decision'=>$decision]+($waived?['reference_waived'=>true]:[])
            +($manual?['current_reference_id'=>(string)($product['Reference Match ID']??'')]:[]);
    }return $cases;
}
function molavi_domain_reference_decide(array $payload,$expected,?string $clock=null): array {
    foreach(['case_id'=>300,'offer_id'=>300,'snapshot_id'=>300,'signature'=>128,'actor_id'=>300,'note'=>1000] as $key=>$limit)
        if(!is_string($payload[$key]??null)||trim($payload[$key])===''||molavi_domain_strlen($payload[$key])>$limit)molavi_domain_fail('invalid_fields',400);
    if(!molavi_domain_owner_actor($payload['actor_id']))molavi_domain_fail('owner_required',403);
    if($payload['choice']==='match'){if(!is_string($payload['reference_id'])||trim($payload['reference_id'])===''||molavi_domain_strlen($payload['reference_id'])>300||!in_array($payload['price_role'],['unit','package'],true))molavi_domain_fail('invalid_fields',400);}
    elseif(!in_array($payload['choice'],['no_match','proceed_without_reference'],true)||!in_array($payload['reference_id'],['',null],true)||$payload['price_role']!=='unresolved')molavi_domain_fail('invalid_fields',400);
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
            $field=$kind==='ai'?'reference_candidates':'candidates';$existing=$cases[$index][$field]??[];
            // During a rolling upgrade, a legacy suggestion must not replace a current shortlist.
            if($suggestion&&$existing&&empty($suggestion['retrieval_policy']))continue;
            $cases[$index]['reference_suggestion']=['state'=>$row['state'],'retry_at'=>$row['state']==='queued'?$row['available']:null,'result'=>$suggestion];
            if($suggestion){$shortlist=$suggestion['candidates']??[];$byId=[];$order=[];
                foreach($existing as $item)$byId[$item['reference_id']]=$item;
                foreach($shortlist as $item)$byId[$item['reference_id']]=array_replace($byId[$item['reference_id']]??[],$item);
                foreach(array_merge([$suggestion['reference_id']??null],array_column($shortlist,'reference_id'),array_keys($byId)) as $key)if(isset($byId[$key])&&!in_array($key,$order,true))$order[]=$key;
                $cases[$index][$field]=array_map(static fn($key)=>$byId[$key],array_slice($order,0,12));}
        }
    }return $cases;
}
function molavi_domain_reference_waiver_case(string $offer): string {
    return molavi_domain_reference_case_id($offer,'owner-without-reference');
}

function molavi_domain_online_reference_queries(string $text): array {
    $name=molavi_domain_digits($text);
    foreach(['volume','weight'] as $kind) $name=preg_replace(molavi_domain_measure_pattern($kind),' ',$name);
    $name=preg_replace('~(*UCP)(?:\b(?:بسته|کارتن|باکس|شیرینگ)\s*)?\d+\s*(?:عددی|عدد|تایی|بسته|ع)(?!\pL)~u',' ',$name);
    $name=molavi_domain_normalize_name($name);
    $first=explode(' ',$name,2)[0];
    return array_values(array_unique(array_filter([$text,$name,$first],static fn(string $term): bool=>molavi_domain_strlen($term)>=2)));
}

function molavi_domain_search_online_reference(array $query): array {
    $text=trim((string)($query['q']??''));
    if (molavi_domain_strlen($text)<2 || molavi_domain_strlen($text)>120) molavi_domain_fail('invalid_search_query',400);
    $key='molavi_online_reference_v2_'.hash('sha256',$text);
    $cached=get_transient($key);
    if (is_array($cached)) return $cached;
    $source=molavi_domain_reference_source();
    $results=[];
    $terms=molavi_domain_online_reference_queries($text);
    foreach($terms as $term) {
        try {
            $response=molavi_domain_http('GET',$source.'?'.http_build_query(['search'=>$term,'per_page'=>8,'_fields'=>'name,permalink'],'','&',PHP_QUERY_RFC3986),
                ['timeout'=>5,'limit_response_size'=>50000]);
        } catch (MolaviWorkflowError $error) {
            if($error->status!==503) throw $error;
            molavi_domain_fail('online_reference_unavailable',503);
        }
        $rows=json_decode(wp_remote_retrieve_body($response),true);
        if (wp_remote_retrieve_response_code($response)!==200 || !is_array($rows) || !array_is_list($rows))
            molavi_domain_fail('online_reference_unavailable',503);
        foreach($rows as $row) {
            $link=$row['permalink']??'';
            if (!is_string($link)||wp_parse_url($link,PHP_URL_SCHEME)!=='https'
                ||wp_parse_url($link,PHP_URL_HOST)!==wp_parse_url($source,PHP_URL_HOST)) continue;
            $title=trim(html_entity_decode(strip_tags((string)($row['name']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8'));
            if ($title!=='') $results[]=['title'=>$title,'url'=>$link];
        }
        if ($results) break;
    }
    $result=['ok'=>true,'results'=>$results,'matched_query'=>$results?$term:null];
    set_transient($key,$result,180);
    return $result;
}

function molavi_domain_search_active_reference(array $query): array {
    $raw=$query['q']??null;
    if (!is_string($raw)||molavi_domain_strlen($raw)>120) molavi_domain_fail('invalid_search_query',400);
    $search=molavi_domain_match_name($raw);
    if (molavi_domain_strlen($search)<2) molavi_domain_fail('invalid_search_query',400);
    $state=molavi_domain_reference_use();$snapshot=molavi_domain_reference_active();
    $id=$snapshot['snapshot_id']??'';
    if (!$id||!$state['allow_automatic']||($state['snapshot_id']??null)!==$id||($query['snapshot_id']??null)!==$id)
        molavi_domain_fail('stale_snapshot');
    $terms=explode(' ',$search);$matches=[];
    foreach (molavi_domain_matching_index($snapshot['rows']??[]) as [$row,$fields,$title]) {
        $code=molavi_domain_match_name($row['reference_id']);
        $text=implode(' ',[$title,$fields['brand'],$fields['variant'],$fields['volume'],$fields['weight'],$code]);
        $covered=count(array_filter($terms,static fn($term)=>str_contains($text,$term)));
        if ($covered!==count($terms)) continue;
        $rank=$code===$search||$title===$search?0:(str_contains($title,$search)?1:2);
        $matches[]=[$rank,$row];
    }
    usort($matches,static fn($a,$b)=>$a[0]<=>$b[0]?:strcmp($a[1]['name'],$b[1]['name'])?:strcmp($a[1]['reference_id'],$b[1]['reference_id']));
    return ['ok'=>true,'snapshot_id'=>$id,'results'=>array_column(array_slice($matches,0,12),1)];
}

function molavi_domain_reference_proceed(array $body,string $actor,?string $clock=null): array {
    molavi_domain_body_keys($body,['action','case_kind','case_id','offer_id','signature']);
    foreach(['case_kind'=>20,'case_id'=>300,'offer_id'=>300,'signature'=>64] as $field=>$limit) {
        if (!is_string($body[$field]) || strlen($body[$field])>$limit) molavi_domain_fail('invalid_fields',400);
    }
    if (!in_array($body['case_kind'],['ai','reference'],true) || $body['offer_id']==='') molavi_domain_fail('invalid_fields',400);
    return molavi_domain_lock('publication',static function()use($body,$actor,$clock):array {
        $tables=molavi_domain_sheets(['Products','Offers'],true);
        $offers=molavi_domain_records($tables['Offers']);
        $offerId=$body['offer_id'];$isAi=$body['case_kind']==='ai';
        if ($isAi) {
            $offer=molavi_domain_ai_reference_source($offers,$body['case_id'],$offerId,$body['signature']);
        } else {
            $current=array_values(array_filter(molavi_domain_records($tables['Products']),
                static fn($row)=>(string)($row['Latest Offer ID']??'')===$offerId));
            $matches=array_values(array_filter($offers,static fn($row)=>(string)($row['Offer ID']??'')===$offerId));
            $offer=count($current)===1 && count($matches)===1 ? $matches[0] : null;
        }
        if (!$offer) molavi_domain_fail('stale_case');
        $case=molavi_domain_reference_waiver_case($offerId);
        $prior=molavi_domain_reference_latest($case);
        $match=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,
            "SELECT decision_id FROM reference_item_decisions WHERE offer_id=? AND choice='match' ORDER BY sequence DESC LIMIT 1",[$offerId]));
        $decision=molavi_domain_reference_decide(['case_id'=>$case,'offer_id'=>$offerId,'snapshot_id'=>'owner-without-reference',
            'signature'=>molavi_domain_reference_signature(molavi_domain_offer_attributes($offer)),
            'choice'=>'proceed_without_reference','reference_id'=>'','price_role'=>'unresolved','actor_id'=>$actor,
            'note'=>'Owner chose to review this offer without a catalog reference.'.($match?' After match '.$match['decision_id']:'')],$prior['decision_id']??null,$clock);
        $receipt=['ok'=>true,'offer_id'=>$offerId,'choice'=>$decision['choice'],'decision_id'=>$decision['decision_id'],
            'projection_state'=>'awaiting_ai_confirmation','publish_job'=>null];
        if (!$isAi) {
            molavi_domain_restore_source_product($offer,$clock);
            $fresh=molavi_domain_sheets(['Products','Offers','Shop'],true);
            foreach(molavi_domain_records($fresh['Products']) as $product) {
                if (($product['Latest Offer ID']??null)===$offerId && ($product['Reference Decision ID']??null)===$decision['decision_id']) {
                    $receipt['projection_state']=(string)($product['Status']??'');
                    $receipt['publish_job']=molavi_domain_observe_projection(molavi_domain_projection_digest($fresh));
                    return $receipt;
                }
            }
            molavi_domain_fail('product_projection_unconfirmed',503);
        }
        return $receipt;
    });
}

function molavi_domain_reference_reviews_api(string $method,?array $body,string $actor,?string $clock=null,array $query=[]): array {
    if ($method==='GET'&&($query['view']??'')==='catalog-search') return molavi_domain_search_active_reference($query);
    if ($method==='GET' && ($query['view']??'')==='market-prices') return molavi_domain_market_price_api($method,$query,$actor);
    if ($method==='POST' && in_array($body['action']??'',['request_market_prices','select_market_price_source','add_market_price_url','use_market_product_image'],true)) {
        $keys=$body['action']==='request_market_prices'?['action','offer_id']:['action','offer_id','url'];
        molavi_domain_body_keys($body,$keys);
        return molavi_domain_market_price_api($method,$body,$actor);
    }
    if ($method==='GET' && ($query['online']??'')==='1') return molavi_domain_search_online_reference($query);
    if ($method==='GET' && in_array($query['view']??'',['extracted-titles','workbench-link'],true)) {
        $offerId=$query['offer_id']??null;
        if(!is_string($offerId)||trim($offerId)===''||strlen($offerId)>300) molavi_domain_fail('invalid_query',400);
        $tables=molavi_domain_sheets(['Offers']);
        return ['ok'=>true]+molavi_domain_reference_extracted_offers(molavi_domain_records($tables['Offers']),$offerId,$query['view']==='workbench-link');
    }
    if ($method==='POST' && ($body['action']??'')==='proceed_without_reference') return molavi_domain_reference_proceed($body,$actor,$clock);
    $snapshot=molavi_domain_reference_active();$eligibility=molavi_domain_reference_use();
    if(!$eligibility['allow_automatic']||!$snapshot||$snapshot['snapshot_id']!==$eligibility['snapshot_id']){
        if($method==='POST'&&($body['action']??null)==='suggest_reference')molavi_domain_fail('stale_snapshot');return ['ok'=>true,'reference_state'=>$eligibility,'cases'=>[],'recent_decisions'=>[]];}
    $tables=molavi_domain_sheets(['Products','Offers'],true);
    if($method==='GET'){$cases=[];$recent=[];
        $focused=trim((string)($query['offer_id']??''));
        $all=molavi_domain_reference_review_cases($tables,$snapshot,true,false,($query['view']??'')==='reference-choose'?$focused:'');
        $defer=($query['view']??'')==='workbench-summary';
        foreach($all as $case)if(($focused!==''&&$case['offer_id']===$focused)
            || ($focused===''&&empty($case['reference_waived'])&&(!$case['decision']||$case['projection_pending']))){if(!$defer)$case['candidates']=molavi_domain_reference_candidates($case['attributes']+['supplier_price'=>molavi_domain_py_value($case['supplier_price'])],$snapshot['rows']??[],12);$cases[]=$case;}elseif($focused===''&&empty($case['reference_waived'])) $recent[]=$case;
        $cases=molavi_domain_reference_receipts($cases,'reference',$snapshot['snapshot_id']);usort($recent,static fn($a,$b)=>strcmp((string)($b['decision']['created_at']??''),(string)($a['decision']['created_at']??'')));
        return ['ok'=>true,'reference_state'=>$eligibility,'cases'=>$cases,'recent_decisions'=>array_slice($recent,0,50)]
            +($defer?['candidates_deferred'=>true]:[]);}
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
        molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
            "UPDATE jobs SET state='queued',available=?,updated_at=?,error=NULL WHERE id=? AND state='cancelled'",
            [microtime(true),microtime(true),$job]));
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
    $current=null;$selected=null;foreach(molavi_domain_reference_review_cases($tables,$snapshot,true,false,$body['offer_id']) as $case)if($case['case_id']===$body['case_id']){$current=$case;break;}
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
        'choice'=>$body['choice'],'reference_id'=>$body['reference_id'],'price_role'=>$body['price_role'],'actor_id'=>$actor,'note'=>$body['note'].(!empty($current['reference_waived'])?' (after choosing no reference)':'')],$expected,$clock);
    return molavi_domain_lock('publication',static function()use($tables,$current,$decision,$body,$clock,$selected,$snapshot,$actor):array {
        $products=molavi_domain_records($tables['Products']);$offers=molavi_domain_records($tables['Offers']);$record=null;
        foreach($products as $product)if((string)molavi_domain_py_value($product['Latest Offer ID']??null)===$current['offer_id']){$record=$product;break;}
        if($record&&$body['choice']==='match'&&$selected&&function_exists('molavi_domain_reference_match_record')){
            // Kept match, same publication lock as the decision + projection.
            // A recording failure must not undo the owner decision: the match
            // is rebuildable from the decision row by backfill adoption.
            try{
                $match=molavi_domain_reference_match_from_snapshot([
                    'product_id'=>(string)molavi_domain_py_value($record['Product ID']??null),
                    'source'=>'catalog','offer_id'=>$current['offer_id'],'signature'=>$current['signature'],
                    'reference_id'=>$body['reference_id'],'price_role'=>$body['price_role'],
                    'snapshot_id'=>$current['snapshot_id'],'decision_id'=>$decision['decision_id'],
                    'match_basis'=>'owner_pick','attributes'=>$current['attributes']??[],
                    'sheet_consumer_price'=>molavi_domain_positive_price($record['Consumer Price']??null)?:null,
                    'title'=>$current['product_name']],$snapshot);
                if($match!==null&&(string)($match['product_id']??'')!=='')
                    molavi_domain_reference_match_record($match,$actor);
            }catch(Throwable $e){error_log('[molavi-native] reference_match_record_failed product='.(string)molavi_domain_py_value($record['Product ID']??null));}
        }
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
            'choice'=>'match','reference_id'=>$body['reference_id'],'price_role'=>$body['price_role'],'actor_id'=>$actor,'note'=>'Owner assigned a reference from the in-progress extraction card.'.(($waiver=molavi_domain_reference_latest(molavi_domain_reference_waiver_case($body['offer_id'])))?' After waiver '.$waiver['decision_id']:'' )],$body['expected_decision_id'],$clock);
        // Kept match, same publication/activation lock as the decision. The
        // sheet projection happens later (awaiting_ai_confirmation); a
        // recording failure must not undo the owner decision — backfill
        // adoption rebuilds the match from the decision row. When no product
        // row exists yet for the AI card, there is nothing to key the match
        // by and adoption creates it once the product exists.
        if(function_exists('molavi_domain_reference_match_record'))try{
            $productId='';$sheetPrice=null;
            try{foreach(molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products']??[]) as $productRow)
                if((string)molavi_domain_py_value($productRow['Latest Offer ID']??null)===$body['offer_id']){
                    $productId=(string)molavi_domain_py_value($productRow['Product ID']??null,'');
                    $sheetPrice=molavi_domain_positive_price($productRow['Consumer Price']??null)?:null;break;}}catch(Throwable $ignored){}
            if($productId!==''){
                $match=molavi_domain_reference_match_from_snapshot([
                    'product_id'=>$productId,'source'=>'catalog','offer_id'=>$body['offer_id'],
                    'signature'=>molavi_domain_reference_signature($attrs),
                    'reference_id'=>$body['reference_id'],'price_role'=>$body['price_role'],
                    'snapshot_id'=>$body['snapshot_id'],'decision_id'=>$decision['decision_id'],
                    'match_basis'=>'owner_assign_ai','attributes'=>$attrs,
                    'sheet_consumer_price'=>$sheetPrice,
                    'title'=>(string)molavi_domain_py_value($source['Product Name']??null,'')],$snapshot);
                if($match!==null)molavi_domain_reference_match_record($match,$actor);
            }
        }catch(Throwable $e){error_log('[molavi-native] reference_match_record_failed offer='.$body['offer_id']);}
        return ['ok'=>true,'case_id'=>$body['case_id'],'offer_id'=>$body['offer_id'],'snapshot_id'=>$body['snapshot_id'],'choice'=>'match','reference_id'=>$body['reference_id'],
            'reference_name'=>(string)molavi_domain_py_value($selected[0],$body['reference_id']),'decision_id'=>$decision['decision_id'],'price_role'=>$decision['price_role'],'projection_state'=>'awaiting_ai_confirmation','publish_job'=>null];
    },'reference'));
}
