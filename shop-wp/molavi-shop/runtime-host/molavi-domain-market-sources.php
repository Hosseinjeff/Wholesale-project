<?php
/** Plugin Name: Molavi Selected Market Sources */
declare(strict_types=1);
defined('ABSPATH') || exit;

const MOLAVI_MARKET_SOURCE_CHECK_SECONDS = 86400;

function molavi_domain_market_source_rows(): array {
    return molavi_domain_read('reference',static fn(SQLite3 $db)=>array_column(
        molavi_domain_rows($db,'SELECT * FROM market_product_sources'),null,'target'));
}

function molavi_domain_market_source_matches(?string $stored,array $identity): bool {
    return $stored!==null && molavi_domain_hash(molavi_domain_decode($stored))===molavi_domain_hash($identity);
}

function molavi_domain_market_source_keys(string $product,string $offer,array $parents): array {
    $keys=$product!==''?['product:'.$product]:[];
    $seen=[];
    while ($offer!=='' && !isset($seen[$offer]) && count($seen)<40) {
        $keys[]='offer:'.$offer;
        $seen[$offer]=true;
        $offer=(string)($parents[$offer]??'');
    }
    return $keys;
}

function molavi_domain_market_source_for(array $keys,array $rows,array $identity): array {
    $result=[];
    foreach ($keys as $key) {
        $row=$rows[$key]??null;
        if (!$row) continue;
        if (!isset($result['price']) && $row['url'] && molavi_domain_market_source_matches($row['identity_json'],$identity)) $result['price']=$row;
        if (!isset($result['photo']) && $row['attachment_id'] && molavi_domain_market_source_matches($row['image_identity_json'],$identity)) $result['photo']=$row;
        if (count($result)===2) break;
    }
    return $result;
}

function molavi_domain_market_catalog_sources(array $products,array $offers,?array $rows=null): array {
    $rows=$rows??molavi_domain_market_source_rows();
    if (!$rows) return [];
    $parents=array_column($offers,'Derived From Offer ID','Offer ID');
    $result=[];
    foreach ($products as $product) {
        $id=(string)($product['Product ID']??'');
        if ($id==='' || !($identity=molavi_domain_market_identity($product))) continue;
        $source=molavi_domain_market_source_for(molavi_domain_market_source_keys($id,(string)($product['Latest Offer ID']??''),$parents),$rows,$identity);
        if ($source) $result[$id]=$source;
    }
    return $result;
}

function molavi_domain_market_subject(string $offer,array $tables): ?array {
    $products=molavi_domain_records($tables['Products']??[]);
    $offers=molavi_domain_records($tables['Offers']??[]);
    $matches=array_values(array_filter($products,static fn($row)=>(string)($row['Latest Offer ID']??'')===$offer));
    if (count($matches)>1) return null;
    $record=$matches[0]??null;
    $product=(string)($record['Product ID']??'');
    if ($product!=='' && count(array_filter($products,static fn($row)=>(string)($row['Product ID']??'')===$product))!==1) return null;
    if (!$record) {
        $matching=array_values(array_filter($offers,static fn($row)=>(string)($row['Offer ID']??'')===$offer));
        if (count($matching)!==1 || !in_array($offer,array_column(molavi_domain_ai_review_cases($tables,null,false,PHP_INT_MAX),'offer_id'),true)) return null;
        $record=$matching[0];
    }
    $identity=molavi_domain_market_identity($record);
    if (!$identity) return null;
    $parents=array_column($offers,'Derived From Offer ID','Offer ID');
    $offerRecord=array_column($offers,null,'Offer ID')[$offer]??[];
    $missing=molavi_domain_market_missing_price($record);
    if ($product!==''&&!$missing&&function_exists('molavi_domain_regular_price_evidence')) {
        $missing=molavi_domain_regular_price_evidence($record,$offerRecord,null,time())['state']!=='current';
    }
    return ['identity'=>$identity,'product_id'=>$product,'offer_id'=>$offer,
        'target'=>$product!==''?'product:'.$product:'offer:'.$offer,
        'keys'=>molavi_domain_market_source_keys($product,$offer,$parents),
        'title'=>(string)$record['Product Name'],'missing'=>$missing,
        'image_file_id'=>(string)($record['Image File ID']??''),
        'regular_price'=>molavi_domain_positive_price($record['Consumer Price']??null)?:molavi_domain_positive_price($record['Actual Price']??null)];
}

function molavi_domain_market_source_price(array $quote): ?int {
    $price=$quote['regular_unit_price']??null;
    return is_int($price) && $price>0 && $price<=100000000?$price:null;
}

function molavi_domain_market_record_event(SQLite3 $db,array $source,string $kind,?int $old,?int $price,?string $code,int $now,bool $publish=false): string {
    $id=bin2hex(random_bytes(16));
    molavi_domain_sql($db,'INSERT INTO market_source_events
        (id,product_id,offer_id,title,kind,old_price,regular_price,code,occurred_at,publish_pending) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$id,$source['product_id'],$source['offer_id'],$source['title'],$kind,$old,$price,$code,$now,(int)$publish]);
    return $id;
}

function molavi_domain_market_flush_events(): void {
    $pending=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        'SELECT id FROM market_source_events WHERE publish_pending=1 ORDER BY occurred_at LIMIT 20'));
    foreach ($pending as $event) {
        molavi_domain_queue('publish','market-source:'.$event['id'],['market_event'=>$event['id']]);
        molavi_domain_tx('reference',static fn(SQLite3 $db)=>molavi_domain_sql($db,
            'UPDATE market_source_events SET publish_pending=0 WHERE id=?',[$event['id']]));
    }
}

function molavi_domain_market_choose_source(array $subject,array $quote,?array $current,int $now,int $checked): ?string {
    $price=molavi_domain_market_source_price($quote);
    return molavi_domain_tx('reference',static function(SQLite3 $db)use($subject,$quote,$price,$current,$now,$checked): ?string {
        $old=molavi_domain_row($db,'SELECT * FROM market_product_sources WHERE target=?',[$subject['target']]);
        if ($old && $old['url']===$quote['url'] && molavi_domain_market_source_matches($old['identity_json'],$subject['identity'])) return null;
        $sameProduct=$old && molavi_domain_market_source_matches($old['identity_json'],$subject['identity']);
        $prior=($sameProduct?$old['regular_price']:null)??$current['regular_price']??null;
        $failure=$price===null?'regular_price_unverified':null;
        $effective=$price??$prior;
        molavi_domain_sql($db,'INSERT INTO market_product_sources
            (target,product_id,offer_id,title,identity_json,url,quote_json,regular_price,next_at,checked_at,failure,changed_at,verified_at)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(target) DO UPDATE SET offer_id=excluded.offer_id,title=excluded.title,
            identity_json=excluded.identity_json,url=excluded.url,quote_json=excluded.quote_json,regular_price=excluded.regular_price,
            next_at=excluded.next_at,checked_at=excluded.checked_at,lease=NULL,failure=excluded.failure,changed_at=excluded.changed_at,verified_at=excluded.verified_at',
            [$subject['target'],$subject['product_id'],$subject['offer_id'],$subject['title'],molavi_domain_json($subject['identity']),
                $quote['url'],molavi_domain_json($quote),$effective,$checked+MOLAVI_MARKET_SOURCE_CHECK_SECONDS,$checked,$failure,$now,$price===null?null:$checked]);
        $publish=$subject['product_id']!=='';
        if (!$publish) return null;
        return molavi_domain_market_record_event($db,$subject,'selected',$prior??$subject['regular_price'],$price,$failure,$now,true);
    });
}

function molavi_domain_market_refresh_source(int $now,?callable $check=null): bool {
    $lease=bin2hex(random_bytes(12));
    $source=molavi_domain_tx('reference',static function(SQLite3 $db)use($now,$lease): ?array {
        $row=molavi_domain_row($db,'SELECT * FROM market_product_sources WHERE url IS NOT NULL AND next_at<=?
            ORDER BY next_at,target LIMIT 1',[$now]);
        if ($row) molavi_domain_sql($db,'UPDATE market_product_sources SET lease=?,next_at=? WHERE target=?',[$lease,$now+300,$row['target']]);
        return $row;
    });
    if (!$source) return false;
    $quote=null;$failure=null;$price=null;
    try {
        $quote=($check??'molavi_domain_market_check_url')($source['url'],molavi_domain_decode($source['identity_json']));
        if (!$quote || $quote['url']!==$source['url']) $failure='listing_unavailable';
        elseif (($price=molavi_domain_market_source_price($quote))===null) $failure='regular_price_unverified';
    } catch (MolaviWorkflowError $e) {
        $failure=$e->getMessage();
    } catch (Throwable $e) {
        $failure='market_price_sources_unavailable';
    }
    $origin=$source;
    if ($source['product_id']==='') {
        try {
            $tables=molavi_domain_sheets(['Products','Offers']);
            $ids=array_keys(molavi_domain_market_catalog_sources(molavi_domain_records($tables['Products']),
                molavi_domain_records($tables['Offers']),[$source['target']=>$source]));
            if (count($ids)===1) $origin['product_id']=(string)$ids[0];
        } catch (MolaviWorkflowError $e) {
            // The saved offer still identifies the review if the catalog is unavailable.
        }
    }
    molavi_domain_tx('reference',static function(SQLite3 $db)use($source,$origin,$lease,$quote,$failure,$price,$now): void {
        $updated=molavi_domain_sql($db,'UPDATE market_product_sources SET lease=NULL,next_at=?,checked_at=?,failure=?,
            quote_json=COALESCE(?,quote_json),regular_price=COALESCE(?,regular_price),verified_at=COALESCE(?,verified_at) WHERE target=? AND lease=?',
            [$now+MOLAVI_MARKET_SOURCE_CHECK_SECONDS,$now,$failure,$failure===null?molavi_domain_json($quote):null,$price,
                $failure===null?$now:null,$source['target'],$lease]);
        if ($updated['changes']!==1) return;
        $old=$source['regular_price'];
        if ($failure!==null) {
            molavi_domain_market_record_event($db,$origin,'failed',$old,null,$failure,$now,$source['failure']===null&&$origin['product_id']!=='');
        } elseif ($old!==$price) {
            molavi_domain_market_record_event($db,$origin,'changed',$old,$price,null,$now,$origin['product_id']!=='');
        } elseif ($source['failure']!==null) {
            molavi_domain_market_record_event($db,$origin,'restored',$old,$price,null,$now,$origin['product_id']!=='');
        } elseif ($origin['product_id']!=='') {
            molavi_domain_market_record_event($db,$origin,'verified',$old,$price,null,$now,true);
        }
    });
    return true;
}

function molavi_domain_market_photo_url($value): ?string {
    if (!is_string($value)) return null;
    $url=molavi_domain_market_url($value);
    return $url && wp_http_validate_url($url)?$url:null;
}

function molavi_domain_market_download_photo(string $url,string $listing=''): array {
    $headers=['Accept'=>'image/avif,image/webp,image/png,image/jpeg,image/*'];
    if ($listing!=='') $headers['Referer']='https://'.parse_url($listing,PHP_URL_HOST).'/';
    for ($hop=0;$hop<3;$hop++) {
        if (!molavi_domain_market_photo_url($url)) break;
        $response=molavi_domain_http_exact('GET',$url,['timeout'=>6,'limit_response_size'=>4000001,'headers'=>$headers]);
        $status=wp_remote_retrieve_response_code($response);
        if (in_array($status,[301,302,303,307,308],true)&&function_exists('wp_remote_retrieve_header')) {
            $location=(string)wp_remote_retrieve_header($response,'location');
            $url=$location!==''&&class_exists('WP_Http')?WP_Http::make_absolute_url($location,$url):$location;
            continue;
        }
        $raw=wp_remote_retrieve_body($response);
        $image=$status===200&&strlen($raw)<=4000000?@getimagesizefromstring($raw):false;
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'];
        $extension=$extensions[$image['mime']??'']??null;
        if ($extension && $image[0]*$image[1]<=25000000) return [$raw,$image['mime'],$extension];
        break;
    }
    molavi_domain_fail('market_image_unavailable',422);
}

function molavi_domain_market_photo_meta(array $subject,?array $photo): array {
    $attachment=(int)($photo['attachment_id']??0);
    if (!$attachment && $subject['product_id']!=='' && function_exists('get_posts')) {
        $ids=get_posts(['post_type'=>'product','post_status'=>['publish','draft','private','pending'],
            'meta_key'=>'_molavi_supplier_offer_id','meta_value'=>$subject['product_id'],'posts_per_page'=>2,'fields'=>'ids']);
        if (count($ids)===1) $attachment=(int)get_post_thumbnail_id((int)$ids[0]);
    }
    return ['title'=>$subject['title'],'image_url'=>$attachment?(string)wp_get_attachment_image_url($attachment,'large'):'',
        'thumbnail_url'=>$attachment?(string)wp_get_attachment_image_url($attachment,'thumbnail'):'',
        'image_file_id'=>$subject['image_file_id'],'selected_photo'=>!empty($photo['attachment_id']),
        'selected_photo_url'=>$photo['image_source_url']??null];
}

function molavi_domain_market_adopt_photo(array $subject,array $quote,int $now): ?string {
    $url=molavi_domain_market_photo_url($quote['image_url']??null);
    if (!$url) molavi_domain_fail('market_image_unavailable',422);
    $existing=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,
        'SELECT attachment_id,image_identity_json,image_source_url FROM market_product_sources WHERE target=?',[$subject['target']]));
    if (($existing['image_source_url']??null)===$url && molavi_domain_market_source_matches($existing['image_identity_json'],$subject['identity'])
        && wp_attachment_is_image((int)$existing['attachment_id'])) return null;
    [$raw,,$extension]=molavi_domain_market_download_photo($url,$quote['url']);
    require_once ABSPATH.'wp-admin/includes/file.php';
    require_once ABSPATH.'wp-admin/includes/media.php';
    require_once ABSPATH.'wp-admin/includes/image.php';
    $tmp=wp_tempnam('molavi-market');
    if (!$tmp || file_put_contents($tmp,$raw)!==strlen($raw)) {
        if ($tmp) @unlink($tmp);
        molavi_domain_fail('market_image_unavailable',503);
    }
    $attachment=media_handle_sideload(['name'=>'molavi-market-'.substr(hash('sha256',$raw),0,16).'.'.$extension,'tmp_name'=>$tmp],0,$subject['title']);
    if (is_wp_error($attachment)) {
        @unlink($tmp);
        molavi_domain_fail('market_image_unavailable',503);
    }
    try {
        $result=molavi_domain_tx('reference',static function(SQLite3 $db)use($subject,$now,$attachment,$url): array {
            $identity=molavi_domain_json($subject['identity']);
            $current=molavi_domain_row($db,'SELECT attachment_id,image_identity_json,image_source_url FROM market_product_sources WHERE target=?',
                [$subject['target']]);
            if (($current['image_source_url']??null)===$url && molavi_domain_market_source_matches($current['image_identity_json'],$subject['identity'])
                && wp_attachment_is_image((int)$current['attachment_id'])) return ['duplicate'=>true,'event'=>null];
            molavi_domain_sql($db,'INSERT INTO market_product_sources
                (target,product_id,offer_id,title,identity_json,attachment_id,image_identity_json,image_source_url,changed_at) VALUES(?,?,?,?,?,?,?,?,?)
                ON CONFLICT(target) DO UPDATE SET attachment_id=excluded.attachment_id,image_identity_json=excluded.image_identity_json,
                image_source_url=excluded.image_source_url',
                [$subject['target'],$subject['product_id'],$subject['offer_id'],$subject['title'],$identity,$attachment,$identity,$url,$now]);
            $event=$subject['product_id']!==''?molavi_domain_market_record_event($db,$subject,'photo',null,null,null,$now,true):null;
            return ['duplicate'=>false,'event'=>$event];
        });
    } catch (Throwable $e) {
        wp_delete_attachment($attachment,true);
        throw $e;
    }
    if ($result['duplicate']) wp_delete_attachment($attachment,true);
    return $result['event'];
}
