<?php
/** Plugin Name: Molavi Product Message Review */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_registered_products(): array {
    if (!function_exists('wc_get_products')||!function_exists('molavi_catalog_identity_snapshot')) return [];
    static $catalog=null;
    return $catalog??=array_column(array_filter(molavi_catalog_identity_snapshot(false,true),
        static fn($row)=>!empty($row['woo_product_id'])),null,'woo_product_id');
}

function molavi_domain_registered_name_key(string $name): string {
    static $keys=[];
    if (isset($keys[$name])) return $keys[$name];
    $tokens=preg_split('/[^\pL\pN]+/u',molavi_domain_name_key($name),-1,PREG_SPLIT_NO_EMPTY);
    sort($tokens,SORT_STRING);
    return $keys[$name]=implode(' ',$tokens);
}

function molavi_domain_product_message_matches(array $offer,array $catalog): array {
    $id=(string)($offer['Product ID']??'');
    $name=molavi_domain_registered_name_key((string)($offer['Product Name']??''));
    if ($name==='') return [];
    $exact=[];
    $byName=[];
    foreach ($catalog as $listing) {
        if ($id!=='' && $id===$listing['source_record_id']) $exact[]=$listing;
        if ($name!=='' && $name===molavi_domain_registered_name_key($listing['name'])) $byName[]=$listing;
    }
    if ($exact) return $exact;
    return $byName;
}

function molavi_domain_product_message_choices(): array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db): array {
        $indexed=[];
        foreach (molavi_domain_rows($db,'SELECT * FROM product_message_choices') as $row) $indexed[$row['offer_id']][$row['woo_product_id']]=$row;
        return $indexed;
    });
}

function molavi_domain_product_message_queue(array $offers,array $catalog,?array $choices=null): array {
    if (!$catalog) return [];
    $choices=$choices??molavi_domain_product_message_choices();
    $accepted=[];$latest=[];
    $messageKey=static fn($offer)=>molavi_domain_json([
        $offer['Message ID']??'',$offer['Channel Username']??'',molavi_domain_product_identity($offer)]);
    foreach ($offers as $row) if (!empty($row['Owner Decision ID']) && !empty($row['Derived From Offer ID'])) {
        $accepted[$row['Derived From Offer ID']][$row['Product ID']??'']=true;
    }
    foreach ($offers as $row) if (!empty($row['Message ID'])&&empty($row['Derived From Offer ID'])) {
        $key=$messageKey($row);$time=molavi_domain_timestamp($row['Import Timestamp']??null)??0;
        if ($time>=($latest[$key]['time']??0)) $latest[$key]=['id'=>$row['Offer ID']??'','time'=>$time];
    }
    $queues=[];
    foreach ($offers as $offer) {
        $id=(string)($offer['Offer ID']??'');
        if ($id===''||!empty($offer['Derived From Offer ID'])||empty($offer['Message ID'])
            ||($offer['Status']??'')==='not_product') continue;
        foreach (molavi_domain_product_message_matches($offer,$catalog) as $listing) {
            $woo=$listing['woo_product_id'];
            $choice=$choices[$id][$woo]??null;
            if (!$choice&&$latest[$messageKey($offer)]['id']!==$id) continue;
            if (!$choice && ($id===($listing['active_offer_id']??'')
                || (empty($listing['active_offer_id'])&&!empty($listing['source_post_url'])&&$listing['source_post_url']===($offer['Source Post URL']??''))
                || isset($accepted[$id][$listing['source_record_id']]))) continue;
            $received=molavi_domain_timestamp($offer['Import Timestamp']??null);
            if (!$choice && $received!==null && $received<($listing['registered_at']??0)) continue;
            $queues[$woo][$id]=[
                'offer_id'=>$id,'title'=>$offer['Product Name'],'channel'=>(string)($offer['Channel Username']??''),
                'received_at'=>(string)($offer['Import Timestamp']??''),'source_url'=>(string)($offer['Source Post URL']??''),
                'excerpt'=>molavi_domain_context_text($offer['Original Message']??'',600),
                'category'=>(string)(($offer['Category']??'')?:($listing['category']??'')),
                'sale_price'=>($offer['Detected Price']??'')?:($offer['Sale Price']??''),'consumer_price'=>$offer['Consumer Price']??'',
                'package_count'=>$offer['Package Count']??'','price_type'=>$offer['Price Type']??'',
                'state'=>$choice['state']??'pending','case_id'=>$choice['case_id']??null,
            ];
        }
    }
    return $queues;
}

function molavi_domain_product_message_api(array $query): array {
    $woo=(int)($query['woo_product_id']??0);
    $catalog=molavi_domain_registered_products();
    if (!$woo||!isset($catalog[$woo])) molavi_domain_fail('product_message_target_missing',404);
    $offers=molavi_domain_records(molavi_domain_sheets(['Offers'])['Offers']);
    $rows=array_values(molavi_domain_product_message_queue($offers,$catalog)[$woo]??[]);
    usort($rows,static fn($a,$b)=>strcmp($b['received_at'],$a['received_at'])?:strcmp($b['offer_id'],$a['offer_id']));
    $active=array_values(array_filter($rows,static fn($row)=>in_array($row['state'],['pending','review'],true)));
    $archived=array_values(array_filter($rows,static fn($row)=>in_array($row['state'],['dismissed','used'],true)));
    return ['ok'=>true,'pending'=>$active,'history'=>array_slice($archived,0,20),'product_name'=>$catalog[$woo]['name']];
}

function molavi_domain_product_message_review_ids(array $offers): array {
    $ids=[];
    foreach (molavi_domain_product_message_queue($offers,molavi_domain_registered_products()) as $rows) {
        foreach ($rows as $id=>$row) $ids[$id]=true;
    }
    return $ids;
}

function molavi_domain_product_message_text_matches(string $text,array $catalog): array {
    $text=' '.molavi_domain_name_key($text).' ';
    $result=[];
    foreach ($catalog as $row) {
        $name=molavi_domain_name_key($row['name']);
        if (substr_count($name,' ')<1||molavi_domain_strlen($name)<8||!str_contains($text,' '.$name.' ')) continue;
        $result[]=['woo_product_id'=>$row['woo_product_id'],'product_id'=>$row['source_record_id'],'name'=>$row['name']];
        if (count($result)===6) break;
    }
    return $result;
}

function molavi_domain_product_message_choose(array $body,string $actor): array {
    molavi_domain_body_keys($body,['action','woo_product_id','offer_id','choice']);
    $woo=$body['woo_product_id'];$offer=$body['offer_id'];$choice=$body['choice'];
    if (!is_int($woo)||$woo<=0||!is_string($offer)||$offer===''||strlen($offer)>300
        ||!in_array($choice,['review','dismiss','restore'],true)) molavi_domain_fail('invalid_fields',400);
    return molavi_domain_lock('publication',static function()use($woo,$offer,$choice,$actor): array {
        $catalog=molavi_domain_registered_products();
        $listing=$catalog[$woo]??null;
        if (!$listing) molavi_domain_fail('product_message_target_missing',404);
        $tables=molavi_domain_sheets(['Products','Offers'],true);
        $offers=molavi_domain_records($tables['Offers']);
        $choices=molavi_domain_product_message_choices();
        $candidate=molavi_domain_product_message_queue($offers,$catalog,$choices)[$woo][$offer]??null;
        $old=$choices[$offer][$woo]??null;
        if (!$candidate||($old['state']??null)==='used') molavi_domain_fail('stale_case');
        $sources=array_values(array_filter($offers,static fn($row)=>($row['Offer ID']??null)===$offer));
        if (count($sources)!==1) molavi_domain_fail('stale_case');
        $source=$sources[0];
        $product=$listing['source_record_id']?:((string)($source['Product ID']??'')?:'woo-'.$woo);
        $products=molavi_domain_records($tables['Products']);
        foreach ($products as $row) {
            if (($row['Product ID']??null)===$product && $listing['source_record_id']===''
                && !in_array($offer,[$row['Latest Offer ID']??'',($old['case_id']??null)?'owner-confirm:'.(molavi_domain_ai_decision_history($old['case_id'],1)[0]['decision_id']??''):''],true))
                molavi_domain_fail('product_message_target_changed');
        }
        return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($woo,$offer,$choice,$actor,$old,$source,$product): array {
            $case=$old['case_id']??null;
            if ($choice==='review' && ($old['state']??null)==='review') return ['ok'=>true,'case_id'=>$case,'state'=>'review'];
            if ($choice==='review' && molavi_domain_row($db,
                "SELECT 1 FROM product_message_choices WHERE (woo_product_id=? AND state='review')
                OR (offer_id=? AND woo_product_id!=? AND state IN ('review','used'))",[$woo,$offer,$woo]))
                molavi_domain_fail('product_message_decision_pending');
            if ($choice==='restore' && ($old['state']??null)!=='dismissed') molavi_domain_fail('stale_case');
            if ($case && molavi_domain_row($db,'SELECT 1 FROM ai_confirmation_decisions WHERE case_id=?',[$case]))
                molavi_domain_fail('product_message_decision_pending');
            if ($case) molavi_domain_sql($db,'DELETE FROM published_product_reviews WHERE case_id=? AND resolved_decision_id IS NULL',[$case]);
            $case=null;
            if ($choice==='review') {
                $case='ai:'.bin2hex(random_bytes(32));
                molavi_domain_sql($db,'INSERT INTO published_product_reviews
                    (case_id,product_id,offer_id,signature,opened_by,opened_at,review_kind,woo_product_id) VALUES(?,?,?,?,?,?,?,?)',
                    [$case,$product,$offer,molavi_domain_published_review_offer_signature($source),$actor,microtime(true),'incoming',$woo]);
            }
            $state=match($choice){'review'=>'review','dismiss'=>'dismissed',default=>'pending'};
            molavi_domain_sql($db,'INSERT INTO product_message_choices VALUES(?,?,?,?,?,?)
                ON CONFLICT(woo_product_id,offer_id) DO UPDATE SET state=excluded.state,case_id=excluded.case_id,actor=excluded.actor,decided_at=excluded.decided_at',
                [$woo,$offer,$state,$case,$actor,microtime(true)]);
            return ['ok'=>true,'case_id'=>$case,'state'=>$state];
        });
    });
}

function molavi_domain_product_message_catalog_rules(array $products,array $offers): array {
    $catalog=molavi_domain_registered_products();
    if (!$catalog) return [];
    $byOffer=array_column($offers,null,'Offer ID');$rules=[];
    $approved=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(molavi_domain_rows($db,
        "SELECT product_id,woo_product_id,'owner-confirm:'||resolved_decision_id AS offer_id FROM published_product_reviews
         WHERE review_kind='incoming' AND resolved_decision_id IS NOT NULL"),'woo_product_id','offer_id'));
    foreach ($products as $record) {
        $id=(string)($record['Product ID']??'');$offer=(string)($record['Latest Offer ID']??'');
        if (isset($approved[$offer],$catalog[$approved[$offer]])) {
            $woo=$approved[$offer];
            if (in_array($catalog[$woo]['source_record_id'],['',$id],true)) $rules[$id]=['registered_woo_id'=>$woo];
            continue;
        }
        $source=$byOffer[$offer]??null;
        if (!$source||!empty($source['Owner Decision ID'])||empty($source['Message ID'])) continue;
        $matches=molavi_domain_product_message_matches($source,$catalog);
        if (!$matches) continue;
        $needsChoice=array_filter($matches,static fn($row)=>!empty($row['active_offer_id'])?$row['active_offer_id']!==$offer
            :(empty($row['source_post_url'])||$row['source_post_url']!==($source['Source Post URL']??'')));
        if ($needsChoice) $rules[$id]=['keep_registered'=>array_column($needsChoice,'woo_product_id')];
    }
    return $rules;
}

function molavi_domain_product_message_aliases(array $products,array $offers): array {
    if (!$products||!$offers) return [];
    $reviewed=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        "SELECT product_id,offer_id FROM published_product_reviews WHERE review_kind='incoming' AND resolved_decision_id IS NOT NULL"));
    if (!$reviewed) return [];
    $byId=array_column($products,null,'Product ID');$byOffer=array_column($offers,null,'Offer ID');
    $registered=array_column(molavi_domain_registered_products(),'woo_product_id','source_record_id');
    $aliases=[];
    foreach ($reviewed as $row) {
        $source=$byOffer[$row['offer_id']]??[];
        $alias=$source['Product ID']??'';
        if ($alias===''||$alias===$row['product_id']||isset($registered[$alias])||!isset($byId[$alias],$byId[$row['product_id']])) continue;
        if (molavi_domain_product_identity($byId[$alias])!==molavi_domain_product_identity($source)) continue;
        $aliases[$alias]=true;
    }
    return $aliases;
}
