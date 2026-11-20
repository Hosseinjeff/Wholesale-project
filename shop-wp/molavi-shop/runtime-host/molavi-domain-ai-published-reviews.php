<?php
/** Plugin Name: Molavi Published Product Review */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_published_review_catalog(): array {
    if (!function_exists('molavi_catalog_identity_snapshot')||!function_exists('wc_get_products')) return [];
    return array_values(array_filter(molavi_catalog_identity_snapshot(true),static fn($row)=>!empty($row['published'])));
}

function molavi_domain_published_review_ids(array $catalog): array {
    $counts=array_count_values(array_column($catalog,'source_record_id'));
    unset($counts['']);
    return array_filter($counts,static fn($count)=>$count===1);
}

function molavi_domain_published_review_reopen(array $body,string $actor): array {
    molavi_domain_body_keys($body,['action','items']);
    $items=$body['items'];
    if (!is_array($items)||!array_is_list($items)||!$items||count($items)>1000) molavi_domain_fail('invalid_fields',400);
    $requested=[];
    foreach ($items as $item) {
        if (!is_array($item)) molavi_domain_fail('invalid_fields',400);
        molavi_domain_body_keys($item,['product_id','offer_id']);
        $id=$item['product_id'];$offer=$item['offer_id'];
        if (!is_string($id)||$id===''||strlen($id)>200||isset($requested[$id])
            ||!is_string($offer)||$offer===''||strlen($offer)>300) molavi_domain_fail('invalid_fields',400);
        $requested[$id]=$offer;
    }

    return molavi_domain_lock('publication',static function()use($requested,$actor):array {
        $tables=molavi_domain_sheets(['Products','Offers'],true);
        $products=molavi_domain_records($tables['Products']);$offers=molavi_domain_records($tables['Offers']);
        $sourceIssues=array_column(molavi_domain_source_issues($products,$offers),'codes','product_id');
        $offerCounts=array_count_values(array_column($offers,'Offer ID'));
        $products=array_column($products,null,'Product ID');$offers=array_column($offers,null,'Offer ID');
        $published=molavi_domain_published_review_ids(molavi_domain_published_review_catalog());
        return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($requested,$products,$offers,$published,$sourceIssues,$offerCounts,$actor):array{
            $results=[];$opened=0;$already=0;
            foreach($requested as $id=>$offer) {
                $product=$products[$id]??[];
                $source=$offers[$offer]??[];
                $base=['product_id'=>(string)$id,'offer_id'=>$offer];
                if (empty($published[$id])||($product['Latest Offer ID']??'')!==$offer
                    ||isset($sourceIssues[$id])||($offerCounts[$offer]??0)!==1) {
                    $results[]=$base+['state'=>'unavailable'];
                    continue;
                }
                $signature=molavi_domain_published_review_offer_signature($source);
                $existing=molavi_domain_row($db,"SELECT r.case_id FROM published_product_reviews r
                    LEFT JOIN ai_confirmation_decisions d ON r.case_id=d.case_id
                    WHERE r.product_id=? AND r.resolved_decision_id IS NULL
                    AND ((r.offer_id=? AND r.signature=?) OR 'owner-confirm:'||d.decision_id=?)",
                    [$id,$offer,$signature,$offer]);
                if ($existing) {
                    $already++;
                    $results[]=$base+['state'=>'already_open','case_id'=>$existing['case_id']];
                    continue;
                }
                $case='ai:'.bin2hex(random_bytes(32));
                molavi_domain_sql($db,'INSERT INTO published_product_reviews
                    (case_id,product_id,offer_id,signature,opened_by,opened_at) VALUES (?,?,?,?,?,?)',
                    [$case,$id,$offer,$signature,$actor,microtime(true)]);
                $opened++;
                $results[]=$base+['state'=>'opened','case_id'=>$case];
            }
            return ['ok'=>true,'opened'=>$opened,'already_open'=>$already,'unavailable'=>count($results)-$opened-$already,'items'=>$results];
        });
    });
}

function molavi_domain_published_review_offer_signature(array $source): string {
    return molavi_domain_hash(array_filter($source,static fn($value)=>$value!==''&&$value!==null));
}

function molavi_domain_published_review_signature(array $review,array $product): string {
    return molavi_domain_hash([$review['signature'],array_intersect_key($product,array_flip([
        'Latest Offer ID','Sale Price','Actual Price','Consumer Price','Currency','Price Type','Package Count',
        'Double Pack Price','Min Qty','Stock Status','Reference Decision ID','Status',
    ]))]);
}

function molavi_domain_published_review_case(array $review,array $source,array $product,?array $decision=null,?float $clock=null): ?array {
    $offer=$review['offer_id'];
    $projected=$decision?'owner-confirm:'.$decision['decision_id']:'';
    if (!$source||!$product||molavi_domain_published_review_offer_signature($source)!==$review['signature']
        ||!in_array((string)($product['Latest Offer ID']??''),array_filter([$offer,$projected]),true)) return null;
    $case=molavi_domain_ai_case(array_replace($source,[
        'Status'=>'needs_review','Review Reason'=>'owner_reopened',
        'Sale Price'=>$product['Sale Price']??$source['Sale Price']??'',
        'Detected Price'=>$product['Sale Price']??$source['Sale Price']??'',
        'Actual Price'=>molavi_domain_py_value($product['Consumer Price']??null,$product['Actual Price']??''),
        'Consumer Price'=>$product['Consumer Price']??'',
        'Price Type'=>$product['Price Type']??'unit',
        'Currency'=>$product['Currency']??$source['Currency']??'IRT',
        'Package Count'=>$product['Package Count']??'',
        'Package Price'=>$product['Double Pack Price']??$source['Package Price']??'',
        'Min Qty'=>$product['Min Qty']??'',
        'Stock Status'=>$product['Stock Status']??'Unknown',
        'Reference Match ID'=>$product['Reference Match ID']??'',
    ]),$decision,$clock);
    return array_replace($case,[
        'case_id'=>$review['case_id'],'signature'=>$decision['signature']??molavi_domain_published_review_signature($review,$product),
        'product_id'=>$review['product_id'],
        'reopened'=>true,'source_current_check_required'=>true,
        'decision'=>$decision,'reference_snapshot_id'=>'',
    ]);
}

function molavi_domain_published_review_cases(array $tables,string $focused='',?float $clock=null): array {
    if (!isset($tables['Products'])) return [];
    [$reviews,$decisions]=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>[
        molavi_domain_rows($db,"SELECT * FROM published_product_reviews
            WHERE resolved_decision_id IS NULL AND (?='' OR offer_id=?) ORDER BY opened_at DESC,case_id",[$focused,$focused]),
        molavi_domain_rows($db,"SELECT d.* FROM ai_confirmation_decisions d JOIN published_product_reviews r USING(case_id)
            WHERE r.resolved_decision_id IS NULL AND (?='' OR r.offer_id=?)",[$focused,$focused]),
    ]);
    if (!$reviews) return [];
    $decisions=array_column(array_map('molavi_domain_ai_decision_decode',$decisions),null,'case_id');
    $source=array_column(molavi_domain_records($tables['Offers']??[]),null,'Offer ID');
    $products=array_column(molavi_domain_records($tables['Products']),null,'Product ID');
    $cases=[];
    foreach($reviews as $review) {
        $decision=$decisions[$review['case_id']]??null;
        $case=molavi_domain_published_review_case($review,$source[$review['offer_id']]??[],
            $products[$review['product_id']]??[],$decision,$clock);
        if ($case) $cases[]=$case;
    }
    return $cases;
}

function molavi_domain_published_review_project(array $source,array $product,int $row,array $tables,array $decision): string {
    $derivedId='owner-confirm:'.$decision['decision_id'];
    if (($product['Latest Offer ID']??null)===$derivedId) return $derivedId;
    $at=(new DateTimeImmutable('@'.sprintf('%.6F',$decision['created_at'])))->format('Y-m-d\TH:i:s.uP');
    $source['Package Price']=$product['Double Pack Price']??$source['Package Price']??'';
    $derived=molavi_domain_confirmed_offer($source,$decision,$at,[$product]);
    $derived['Product ID']=$product['Product ID'];
    // Keep the original supplier timestamps and the current, separately approved reference.
    foreach(['Batch ID','Source Timestamp Basis','Source Post Created At','Source Post Updated At'] as $key) $derived[$key]=$source[$key]??'';
    foreach(['Reference Snapshot ID','Reference Match ID','Reference Decision ID','Reference Approval State',
        'Reference Price Change Percent','Reference Price Checked At'] as $key) $derived[$key]=$product[$key]??'';
    $derived['Reference Price Role Hint']=$product['Reference Price Role']??'';
    if ($decision['choice']==='confirm') {
        $derived['Status']=$product['Status'];
        $derived['Currency']=$product['Currency']?:$derived['Currency'];
        $derived['Review Reason']=$source['Review Reason']??'';
    }
    $header=array_map('strval',$tables['Products'][0]??[]);
    $values=['Latest Offer ID'=>$derivedId,'Last Updated'=>$at,'Offer Count'=>(int)($product['Offer Count']??0)+1];
    foreach(['Batch ID','Sale Price','Actual Price','Consumer Price','Price','Price Type',
        'Currency','Package Count','Min Qty','Stock Status','Status'] as $field) $values[$field]=$derived[$field]??'';
    $values['Double Pack Price']=$derived['Package Price']??'';
    if ($decision['choice']==='not_product') {
        $values=array_intersect_key($values,array_flip(['Latest Offer ID','Last Updated','Offer Count','Batch ID','Status']));
    } else {
        foreach(['Sale Price'=>'Previous Sale Price','Consumer Price'=>'Previous Consumer Price'] as $field=>$previous) {
            if ((string)($product[$field]??'')!==(string)$values[$field]) {
                $values[$previous]=$product[$field]??'';
                $values['Price Changed At']=$at;
            }
        }
    }
    // Check the destination schema before appending a receipt-bound offer.
    if (array_diff(array_keys($values),$header)) molavi_domain_fail('sheet_header_missing');
    $saved=array_column(molavi_domain_records($tables['Offers']),null,'Offer ID')[$derivedId]??null;
    if (!$saved) {
        molavi_domain_sheet_ensure('Offers',molavi_domain_offer_headers());
        $offerHeader=array_map('strval',molavi_domain_sheets(['Offers'],true)['Offers'][0]??[]);
        if (!in_array('Offer ID',$offerHeader,true)||!in_array('Owner Decision ID',$offerHeader,true)) molavi_domain_fail('sheet_header_missing');
        molavi_domain_sheet_append('Offers',[array_map(static fn($key)=>$derived[$key]??'',$offerHeader)]);
        $saved=array_column(molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']),null,'Offer ID')[$derivedId]??null;
    }
    if (($saved['Owner Decision ID']??null)!==$decision['decision_id']
        ||($saved['Product ID']??null)!==$product['Product ID']
        ||($saved['Derived From Offer ID']??null)!==$source['Offer ID']) molavi_domain_fail('offer_append_unconfirmed',503);
    foreach ($derived as $key=>$value) {
        if (!molavi_domain_cells_equal($key,$saved[$key]??'',$value)) molavi_domain_fail('offer_append_unconfirmed',503);
    }
    $fresh=molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products'])[$row-2]??[];
    if ($fresh!==$product) molavi_domain_fail('stale_case');
    molavi_domain_sheet_fields('Products',$row,$header,$values);
    $checked=molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products'])[$row-2]??[];
    if (($checked['Product ID']??null)!==$product['Product ID']) molavi_domain_fail('product_projection_unconfirmed',503);
    foreach($values as $key=>$value) {
        if (!molavi_domain_cells_equal($key,$checked[$key]??'',$value)) molavi_domain_fail('product_projection_unconfirmed',503);
    }
    return $derivedId;
}

function molavi_domain_published_review_resolve(array $review,array $body,string $actor,?string $clock): array {
    if ($review['offer_id']!==$body['offer_id']) molavi_domain_fail('stale_case');
    if ($body['choice']==='confirm'&&($body['source_current_verified']??false)!==true) molavi_domain_fail('source_current_verification_required',400);
    $decision=molavi_domain_ai_decision_history($review['case_id'],1)[0]??null;
    if ($decision && ($decision['signature']!==$body['signature']||$decision['choice']!==$body['choice']
        ||molavi_domain_hash($decision['fields'])!==molavi_domain_hash($body['choice']==='not_product'?(object)[]:$body['fields']))) {
        molavi_domain_fail('reopened_decision_conflict');
    }
    if ($review['resolved_decision_id']!==null) {
        $job='publish:ai-confirmation:'.$review['resolved_decision_id'];
        return ['ok'=>true,'case_id'=>$review['case_id'],'offer_id'=>$review['offer_id'],'signature'=>$review['signature'],
            'choice'=>$decision['choice'],'decision_id'=>$decision['decision_id'],
            'projection_state'=>$decision['choice']==='confirm'?'imported':'not_product','publish_job'=>$job];
    }

    $tables=molavi_domain_sheets(['Products','Offers'],true);
    $products=molavi_domain_records($tables['Products']);
    $matches=array_keys(array_filter($products,static fn($row)=>(string)($row['Product ID']??'')===$review['product_id']));
    $sources=array_values(array_filter(molavi_domain_records($tables['Offers']),
        static fn($row)=>(string)($row['Offer ID']??'')===$review['offer_id']));
    if (count($matches)!==1||count($sources)!==1) molavi_domain_fail('stale_case');
    $row=$matches[0];$product=$products[$row];$source=$sources[0];
    $case=molavi_domain_published_review_case($review,$source,$product,$decision);
    if (!$case||$case['signature']!==$body['signature']||
        ($product['Latest Offer ID']===$review['offer_id']&&$case['signature']!==molavi_domain_published_review_signature($review,$product))) {
        molavi_domain_fail('stale_case');
    }
    if (molavi_domain_ai_held(molavi_domain_ai_hold_scope(),$case)) molavi_domain_fail('publication_held_for_review');
    if (!$decision) {
        if ($body['choice']==='confirm'&&$body['fields']['price_role']==='package'
            &&2*$body['fields']['sale_price']<$body['fields']['package_count']) molavi_domain_fail('invalid_price',400);
        $decision=molavi_domain_ai_decide([
            'case_id'=>$case['case_id'],'offer_id'=>$case['offer_id'],'signature'=>$case['signature'],
            'choice'=>$body['choice'],'fields'=>$body['fields'],'actor_id'=>$actor,
            'note'=>$body['note'],'expected_decision_id'=>$body['expected_decision_id'],
        ],molavi_domain_timestamp($clock));
    }
    $derived=molavi_domain_published_review_project($source,$product,$row+2,$tables,$decision);
    $fresh=molavi_domain_sheets(['Products','Offers'],true);
    $confirmed=molavi_domain_records($fresh['Products'])[$row]??[];
    if (($confirmed['Product ID']??null)!==$review['product_id']||($confirmed['Latest Offer ID']??null)!==$derived) {
        molavi_domain_fail('product_projection_unconfirmed',503);
    }
    if ($decision['choice']==='confirm') {
        $revision=molavi_domain_source_revision($confirmed);
        if ($revision==='') molavi_domain_fail('price_source_missing');
        molavi_domain_verify_price($review['product_id'],$revision,$decision['actor_id'],
            'Owner verified the supplier terms during reopened review.',molavi_domain_now(),$decision['decision_id']);
    }
    molavi_domain_status_capture($fresh,'published_review:'.$decision['decision_id'],molavi_domain_timestamp($clock));
    $job=molavi_domain_queue('publish','ai-confirmation:'.$decision['decision_id'],['decision_id'=>$decision['decision_id']]);
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
        'UPDATE published_product_reviews SET resolved_decision_id=? WHERE case_id=?',
        [$decision['decision_id'],$review['case_id']]));
    return ['ok'=>true,'case_id'=>$review['case_id'],'offer_id'=>$review['offer_id'],'signature'=>$review['signature'],
        'choice'=>$decision['choice'],'decision_id'=>$decision['decision_id'],
        'projection_state'=>$decision['choice']==='confirm'?'imported':'not_product','publish_job'=>$job];
}
