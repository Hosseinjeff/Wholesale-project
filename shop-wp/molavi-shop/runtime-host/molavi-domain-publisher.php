<?php
/** Plugin Name: Molavi Native Catalog Publication */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_positive_price($value): int {
    if(is_bool($value))return (int)$value;
    if(is_string($value))$value=molavi_domain_digits(trim($value));
    // float() accepts underscores only between numeric digits.
    if(is_string($value)){$value=preg_replace('/(?<=\d)_(?=\d)/u','',$value);}
    if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0)return 0;
    if((float)$value>PHP_INT_MAX)molavi_domain_fail('invalid_product_price',400);
    return max(0,(int)(float)$value);
}
function molavi_domain_legacy_name_key(string $name): string {
    $name=trim(preg_replace('/[\s،,:：\-]+$/u','',molavi_domain_peel_title($name)));
    return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
}
function molavi_domain_woo_sku(array $record): string {
    $name=(string)(($record['Product Name']??'')?:($record['name']??''));
    $channel=(string)(($record['Channel Username']??'')?:($record['channel_username']??'')?:($record['channel']??''));
    $id=trim((string)(($record['Product ID']??'')?:($record['product_id']??'')));
    $channelKey=function_exists('mb_strtolower')?mb_strtolower(trim($channel),'UTF-8'):strtolower(trim($channel));
    $legacy='p_'.substr(hash('sha256',$channelKey.'|'.molavi_domain_legacy_name_key($name)),0,24);
    $parts=molavi_domain_product_identity($record);$hasDimension=false;foreach(array_slice($parts,2) as $part)if($part!=='')$hasDimension=true;
    $dimension=$hasDimension?'p_'.substr(molavi_domain_hash($parts),0,24):$legacy;
    return substr(hash('sha256',$id===''||$id===$legacy||$id!==$dimension?$channel.'|'.$name:'product-row|'.$id),0,12);
}
function molavi_domain_stock_status($value): string {
    $text=strtr(molavi_domain_digits($value),['ي'=>'ی','ك'=>'ک']);
    $text=preg_replace('/[\x{200b}-\x{200f}\x{feff}ـ\p{Mn}]/u','',$text);$text=strtolower(trim(preg_replace('/\s+/u',' ',$text)));
    if(in_array($text,['out of stock','outofstock','unavailable','sold out','not available','not in stock','ناموجود','تمام شد','تمام شده'],true))return 'Out of Stock';
    if(in_array($text,['available','in stock','instock','موجود','موجود است','موجوده','قابل سفارش'],true))return 'Available';
    if($text===''||preg_match('/ناموجود\s+نیست|موجود\s+نیست\s+نیست/u',$text))return 'Unknown';
    if(preg_match('~(*UCP)(?:\b(?:out\s+of\s+stock|sold\s+out|unavailable|not\s+available|not\s+in\s+stock)\b|ناموجود|اتمام\s+موجودی|موجودی\s+(?:تمام|به\s+پایان)\s*(?:شد|شده|رسید|رسیده)|موجود\s+(?:نیست|نمی\s*باشد|نداریم)|فعلاً?\s+(?:موجود\s+)?نداریم|(?:^|[\n،؛.!])\s*تمام\s+شد(?:ه)?\s*(?:$|[\n،؛.!]))~ui',$text))return 'Out of Stock';
    return preg_match('~(*UCP)(?:\b(?:in\s+stock|available)\b|موجود\s+(?:است|می\s*باشد|داریم|شد|شده|هست)|(?<![آ-ی])موجود(?!ی)|قابل\s+(?:خرید|سفارش))~ui',$text)?'Available':'Unknown';
}
// Owner's availability rule: a product coming from a supplier message is
// always available unless the message explicitly says it is not. The only
// signal is the sheet's Stock Status, which the extraction writes as
// 'Out of Stock' only on an explicit supplier statement (Railway
// catalog/stock.py explicit_stock_status; the Gemini prompt says "Copy only
// explicit stock... Leave it Unknown if unstated") and 'Unknown' when
// unstated; molavi_domain_stock_status() normalizes it the same way. So
// this returns false ONLY for an explicit 'Out of Stock' — 'Unknown' is
// available, exactly as the sync projection already treats it ("a fresh,
// current offer with unknown stock remains visible").
// Circularity guard: never read WooCommerce stock_status here. Woo's
// outofstock is our own output (the sync projection and molavi_hide_product
// set it when hiding a product); reading it back would make held products
// permanently inadmissible. This reads only the supplier-message record.
// Seam for the owner's future own stock: when he adds it, this function
// gains the own-stock check (available = supplier says so AND own stock
// says so); every admission gate already calls this one function.
function molavi_domain_supplier_available(array $record): bool {
    return molavi_domain_stock_status($record['Stock Status']??null)!=='Out of Stock';
}
// Publisher-side flag/hold check (round seven). A flagged or reverted live
// admission must stay unpublished: the autopilot blocks re-admission (Arm
// 10 durable log-flag hold + admission_owner hold), but the flag/hold was
// never a publisher input, so a later full-catalog publish pass re-projected
// from Sheets and could re-publish the product. This closes that gap at the
// projection — the single choke point every publish path flows through
// (publish_job -> publish_shop, publication-recovery, legacy-commerce, and
// request_product_publication, which is also the G5-live and gA-live path).
//
// The blocking set mirrors Arm 10 exactly, so the two guards can never
// disagree about what counts:
//   * a live admission flagged in the last 7 days (same query as Arm 10:
//     verdict='flag', gate='admission', action='live', result='admitted',
//     created_at >= now - 7d). An owner override rewrites the row's result
//     to 'overridden', so it stops matching; a revert rewrites it to
//     'reverted' — the revert stays blocked via the owner hold below.
//   * an admission_owner hold (written by revert_decision / hold_decision).
//     It has no expiry: only release_hold (which deletes exactly that
//     gate's row) re-enables publication. A flag, by contrast, expires
//     after 7 days, exactly like Arm 10.
// Deliberately NOT other gates: g5/g4/g3 holds are routine admission-gate
// holds (a market quote disagreeing, a stale price) and must not block
// publication — the projection has its own sale-offer evidence for those.
// One batched read for the whole pass (two small SELECTs, no per-product
// query, no model call), intersected in PHP with this pass's product ids.
//
// Fail-open by design: the autopilot tables/columns exist only once the
// autopilot has run on this host. Pre-round-six staging has neither table,
// which means no flag or owner hold can exist there — there is nothing to
// block, so the pass publishes as before. Any read failure (missing table
// or column, locked database) logs once and returns an empty set instead
// of fataling: the check must never wedge the publish pass, and Arm 10
// remains the primary guard. The next pass re-checks.
function molavi_domain_publisher_flag_held_products(array $productIds,float $now): array {
    $blocked=[];
    if(!$productIds)return $blocked;
    try {
        $blocked=molavi_domain_read('pipeline',static function(SQLite3 $db)use($now): array {
            $tables=array_column(molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name IN ('autopilot_log','autopilot_holds')"),'name');
            if(!in_array('autopilot_log',$tables,true)||!in_array('autopilot_holds',$tables,true))return [];
            $logColumns=array_column(molavi_domain_rows($db,'PRAGMA table_info(autopilot_log)'),'name');
            foreach(['product_id','gate','action','result','verdict','created_at'] as $column)
                if(!in_array($column,$logColumns,true))return [];
            $out=[];
            foreach(molavi_domain_rows($db,"SELECT product_id FROM autopilot_log WHERE verdict='flag'
                AND gate='admission' AND action='live' AND result='admitted' AND created_at>=?",
                [$now-7*86400]) as $row)
                $out[(string)($row['product_id']??'')]='flagged';
            // An owner hold is the stronger signal: it wins over a flag
            // when a product carries both (a revert also rewrites the
            // admitted row to 'reverted', so the flag query above stops
            // matching it — the hold is what keeps the revert durable).
            foreach(molavi_domain_rows($db,"SELECT product_id FROM autopilot_holds WHERE gate='admission_owner'") as $row)
                $out[(string)($row['product_id']??'')]='owner_hold';
            return $out;
        });
    } catch(Throwable $e) {
        error_log('[molavi-publisher] flag_hold_check_failed');
        return [];
    }
    $wanted=array_flip($productIds);
    return array_intersect_key($blocked,$wanted);
}
function molavi_domain_site_products(array $tables,?array $freshness=null,?array $conflicts=null): ?array {
    if(empty($tables['Products']))return null;
    $records=molavi_domain_records($tables['Products']);$offers=molavi_domain_records($tables['Offers']??[]);
    $offersById=array_column($offers,null,'Offer ID');$now=time();
    $market=function_exists('molavi_domain_market_catalog_sources')?molavi_domain_market_catalog_sources($records,$offers):[];
    $registered=function_exists('molavi_domain_product_message_catalog_rules')?molavi_domain_product_message_catalog_rules($records,$offers):[];
    $aliases=function_exists('molavi_domain_product_message_aliases')?molavi_domain_product_message_aliases($records,$offers):[];
    $issues=[];foreach(molavi_domain_source_issues($records,$offers) as $issue){
        if(in_array('duplicate_product_id',$issue['codes'],true))return null;$issues[$issue['row_number']-2]=$issue;}
    if($freshness===null){$freshness=[];foreach(molavi_domain_freshness($records) as $row)$freshness[$row['product_id']]=$row;}
    if($conflicts===null)$conflicts=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(molavi_domain_rows($db,"SELECT DISTINCT product_id FROM sheet_sync_conflicts WHERE state='open'"),'product_id'));
    $suppliers=['@bonakdarjavan'=>'بنکدار جوان','@top_shop_rahimi'=>'تاپ‌شاپ رحیمی','@nobelshop118'=>'نوبل'];$products=[];
    // Round seven: one batched flag/hold read for the whole pass (see
    // molavi_domain_publisher_flag_held_products). Skips forced by this
    // check are collected in $GLOBALS for molavi_domain_publish_shop,
    // which carries them in the publish-job result.
    $flagHeld=molavi_domain_publisher_flag_held_products(array_column($records,'Product ID'),(float)$now);
    $GLOBALS['molavi_publisher_flag_hold_skipped']=[];
    foreach($records as $index=>$record){
        $status=strtolower(trim((string)($record['Status']??'')));$held=in_array($status,['skipped','needs_review','not_product'],true);
        $name=preg_replace('/^[ ،,]+|[ ،,]+$/u','',trim((string)($record['Product Name']??'')));
        $sale=molavi_domain_positive_price($record['Sale Price']??null);$id=trim((string)($record['Product ID']??''));
        if (isset($aliases[$id])) continue;
        if($name===''&&$id==='')continue;if($sale<=0&&!$held&&$id==='')continue;
        $issue=$issues[$index]??null;$channel=trim((string)($record['Channel Username']??''));$evidence=$freshness[$id]??[];
        $freshnessState=$evidence['state']??'needs_review';$shelf=molavi_domain_positive_price($record['Consumer Price']??null)?:molavi_domain_positive_price($record['Actual Price']??null);
        $shelf=$market[$id]['price']['regular_price']??$shelf;
        $eligibility=function_exists('molavi_domain_sale_offer_evidence')?molavi_domain_sale_offer_evidence($record,
            $offersById[$record['Latest Offer ID']??'']??[],$evidence,$market[$id]['price']??null,$now):[];
        $held=$held||!empty($eligibility['sale_offer_blocker']);
        $shelf=$eligibility['regular_price']??$shelf;
        $stock=molavi_domain_stock_status($record['Stock Status']??null);$availability=['Out of Stock'=>'outofstock','Available'=>'instock'][$stock]??'unknown';
        $held=$held||$name===''||$sale<=0||$shelf<=$sale||!in_array($freshnessState,['current','warning'],true)||in_array($id,$conflicts,true)||$availability==='outofstock'||$issue!==null;
        // Round seven: a flagged live admission or an owner hold forces
        // 'hold', so molavi_apply_catalog hides the product instead of
        // (re-)publishing it. Only a product that would otherwise project
        // 'sell' is recorded as a skip — one already held for another
        // reason stays held without a duplicate skip entry.
        $flagReason=$flagHeld[$id]??'';
        if($flagReason!==''&&!$held){
            $GLOBALS['molavi_publisher_flag_hold_skipped'][]=['product_id'=>$id,'reason'=>$flagReason];
            error_log('[molavi-publisher] publish_flag_hold_skip product='.$id.' reason='.$flagReason);
        }
        $held=$held||$flagReason!=='';
        $packaging=trim((string)($record['Packaging']??''));
        $minimum=molavi_domain_positive_price($record['Min Qty']??null);
        if(!$minimum)$minimum=in_array(molavi_domain_pack_count($packaging,$name),[6,12,24],true)?6:1;
        $pack=molavi_domain_positive_price($record['Package Count']??null);
        if($pack<1||$pack>999){
            $pack=function_exists('molavi_message_pack_size')?molavi_message_pack_size($packaging,$name):0;
        }
        $source=trim((string)($record['Source Post URL']??''));$url=parse_url($source);
        if(!$url||($url['scheme']??'')!=='https'||!in_array($url['host']??'',['t.me','www.t.me','bale.ai','www.bale.ai'],true))$source='';
        $image=(string)($record['Image File ID']??'');if(!preg_match('/^[A-Za-z0-9_-]{8,300}$/D',$image))$image='';
        $products[]=['id'=>molavi_domain_woo_sku($record),'name'=>$name,'key'=>molavi_domain_legacy_name_key($name),
            'channel'=>$channel,'supplier'=>$suppliers[$channel]??$channel,'packaging'=>$packaging,
            'category'=>trim((string)($record['Category']??'')),'sale'=>$sale,'shelf'=>$shelf,'min'=>$minimum,'pack_size'=>$pack,'pack_size_source'=>$pack>0?'message':'unknown',
            'source_post_url'=>$source,'reference_snapshot_id'=>trim((string)($record['Reference Snapshot ID']??'')),
            'reference_match_id'=>trim((string)($record['Reference Match ID']??'')),'reference_price_role'=>trim((string)($record['Reference Price Role']??'')),
            'reference_approval_state'=>trim((string)($record['Reference Approval State']??'')),
            'reference_price_change_percent'=>molavi_domain_positive_price($record['Reference Price Change Percent']??null),
            'reference_price_checked_at'=>trim((string)($record['Reference Price Checked At']??'')),'reference_review_reason'=>trim((string)($record['Reference Review Reason']??'')),
            'image_file_id'=>$image,'status'=>$held?'hold':'sell','supplier_availability'=>$availability,
            'source_integrity_state'=>$issue?'blocked':'verified','source_integrity_issues'=>$issue['codes']??[],
            'source_record_id'=>$id,'supplier_price_state'=>$freshnessState,'supplier_price_verified_at'=>($evidence['verified_at']??'')?:'']
            + (isset($market[$id]['photo'])?['market_image_id'=>(int)$market[$id]['photo']['attachment_id']]:[])
            + $eligibility
            + ($registered[$id]??[])
            + (function_exists('molavi_domain_product_message_catalog_rules')&&function_exists('wc_get_products')
                ?['active_offer_id'=>(string)($record['Latest Offer ID']??'')]:[]);
    }
    usort($products,static fn($a,$b)=>strcmp($a['name'],$b['name']));return $products;
}
function molavi_domain_site_settings(array $rows): array {
    $fields=[['Min Margin','min_margin',0.12,true],['Min Profit','min_profit',5000,false],['Spread Low','spread_low',0.10,true],
        ['Spread High','spread_high',0.60,true],['Profit Share Low','profit_share_low',0.70,true],['Profit Share High','profit_share_high',0.35,true],
        ['Opening Share Low','opening_share_low',0.20,true],['Opening Share High','opening_share_high',0.25,true],['Unlock Target','unlock_target',2000000,false]];
    $found=[];foreach($rows as $row){if(!$row)continue;$label=trim((string)($row[0]?:''));if(!isset($found[$label])&&isset($row[1])&&$row[1]!=='')$found[$label]=$row[1];}
    $settings=[];foreach($fields as [$label,$field,$default,$percent]){$value=$found[$label]??null;$number=$default;
        if($value!==null&&$value!==''&&!is_bool($value)){
            $raw=is_string($value)?str_replace(['%',','],'',trim(molavi_domain_digits($value))):$value;
            if(is_numeric($raw))$number=(float)$raw;if($percent&&$number>=2)$number/=100;
        }
        $settings[$field]=in_array($field,['min_profit','unlock_target'],true)?(int)round($number,0,PHP_ROUND_HALF_EVEN):(float)$number;
    }return $settings;
}

function molavi_domain_request_product_publication(array $body): array {
    molavi_domain_body_keys($body,['action','product_id','offer_id']);
    $id=$body['product_id'];$offer=$body['offer_id'];
    if (!is_string($id)||$id===''||strlen($id)>200||!is_string($offer)||$offer===''||strlen($offer)>300)
        molavi_domain_fail('invalid_fields',400);
    return molavi_domain_lock('publication',static function()use($id,$offer): array {
        $tables=molavi_domain_sheets(['Products','Offers','Shop'],true);
        $records=array_values(array_filter(molavi_domain_records($tables['Products']),static fn($record)=>($record['Product ID']??'')===$id));
        if (count($records)!==1||($records[0]['Latest Offer ID']??'')!==$offer) molavi_domain_fail('offer_changed');
        $projected=array_column(molavi_domain_site_products($tables)??[],null,'source_record_id');
        if (($projected[$id]['status']??'')!=='sell'||!empty($projected[$id]['keep_registered']))
            molavi_domain_fail('product_publication_prerequisite');
        $published=array_column(molavi_domain_published_review_catalog(),'purchasable','source_record_id');
        if (!empty($published[$id])) return ['ok'=>true,'product_id'=>$id,'publish_job'=>null,'state'=>'published'];
        $job=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
            "SELECT id FROM jobs WHERE kind='publish' AND state IN ('queued','running','waiting_publication')
             AND COALESCE(json_extract(payload,'$.images_only'),0)=0 ORDER BY created_at LIMIT 1"));
        $publish=$job['id']??molavi_domain_queue('publish','owner-catalog:'.bin2hex(random_bytes(8)),[]);
        return ['ok'=>true,'product_id'=>$id,'publish_job'=>$publish,'state'=>'queued'];
    });
}

function molavi_domain_publish_shop(array $tables,bool $imagesOnly=false,int $maxRounds=8): array {
    return molavi_domain_lock('publication',static function()use($tables,$imagesOnly,$maxRounds):array {
        $GLOBALS['molavi_publisher_flag_hold_skipped']=[];
        $held=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT 1 FROM publication_holds LIMIT 1'));
        if($held)return ['ok'=>false,'pushed'=>false,'reason'=>'publication_held_for_review'];
        if(!function_exists('molavi_apply_catalog')||!function_exists('molavi_receive_images'))molavi_domain_fail('native_publisher_unavailable',503);
        if($imagesOnly){$products=[];$result=molavi_receive_images([]);}
        else{
            $products=molavi_domain_site_products($tables);if($products===null)return ['ok'=>false,'pushed'=>false,'reason'=>'sheet unread'];
            $payload=['products'=>$products,'catalog_complete'=>true];if(!empty($tables['Shop']))$payload['settings']=molavi_domain_site_settings($tables['Shop']);
            $result=molavi_apply_catalog($payload,true);
            // Round seven: products the flag/hold check held out of this
            // pass, so the publish-job result shows what was skipped and
            // why (the projection already logged each skip).
            $flagSkipped=$GLOBALS['molavi_publisher_flag_hold_skipped']??[];
            if(!empty($flagSkipped))$result['flag_hold_skipped']=$flagSkipped;
        }
        if(empty($result['ok'])){$result['pushed']=false;return $result;}
        $pending=is_array($result['pending']??null)?$result['pending']:[];$rounds=0;
        while($pending&&$rounds<max(0,min(40,$maxRounds))){
            molavi_domain_worker_progress($GLOBALS['molavi_native_job_current']??null);
            $item=is_array($pending[0])?$pending[0]:[];$file=(string)($item['file']??'');$photo=null;$mime='image/jpeg';$dead=false;
            if(preg_match('/^[A-Za-z0-9_-]{8,300}$/D',$file)){
                try{$response=molavi_domain_http('GET',rtrim(MOLAVI_REFERENCE_SERVER_URL,'/').'/media/file/'.rawurlencode($file),['timeout'=>30,'limit_response_size'=>4000001]);
                    $body=wp_remote_retrieve_body($response);$type=(string)wp_remote_retrieve_header($response,'content-type');
                    $status=wp_remote_retrieve_response_code($response);
                    if($status===200&&strlen($body)>0&&strlen($body)<=4000000){$photo=$body;
                        if(str_starts_with($type,'image/png'))$mime='image/png';elseif(str_starts_with($type,'image/webp'))$mime='image/webp';}
                    // 404/410 means the file is gone from the reference server for
                    // good: drop it instead of deferring it, or every publish
                    // retries it forever. Any other failure still defers.
                    elseif(in_array($status,[404,410],true)){$dead=true;if(function_exists('molavi_mark_image_dead'))molavi_mark_image_dead($file);}
                }catch(MolaviWorkflowError $e){$photo=null;}
            }
            $payload=$photo!==null?['images'=>[['product'=>$item['product']??null,'file'=>$file,'mime'=>$mime,'data'=>base64_encode($photo)]]]:($dead?['drop'=>[$item]]:['skip'=>[$item]]);
            $next=molavi_receive_images($payload);if(empty($next['ok']))break;
            $result['attached']=(int)($result['attached']??0)+(int)($next['attached']??0);$result['images_left']=(int)($next['images_left']??0);
            $pending=is_array($next['pending']??null)?$next['pending']:[];$rounds++;
            if($photo!==null&&(int)($next['attached']??0)<=0)break;
        }
        $result['pushed']=true;$result['count']=count($products);return $result;
    });
}
function molavi_domain_publish_job(array $payload): array {
    if(!empty($payload['images_only']))return molavi_domain_publish_shop([],true,(int)($payload['max_rounds']??8));
    $tables=molavi_domain_sheets(['Products','Offers','Shop'],true);$before=molavi_domain_projection_digest($tables);$expected=$payload['sheet_digest']??null;
    if($expected&&$expected!==$before){
        $state=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT * FROM sheet_projection_state WHERE singleton=1'));
        if($before!==($state['published_digest']??null))molavi_domain_observe_projection($before);
        return ['ok'=>true,'pushed'=>true,'images_left'=>0,'superseded'=>true];
    }
    $result=molavi_domain_publish_shop($tables,false,(int)($payload['max_rounds']??8));
    if(!empty($result['ok'])&&!empty($result['pushed'])&&(int)($result['images_left']??0)===0){
        $after=molavi_domain_projection_digest(molavi_domain_sheets(['Products','Shop'],true));
        if($before!==$after){molavi_domain_observe_projection($after);return ['ok'=>true,'pushed'=>true,'images_left'=>0,'superseded'=>true];}
        molavi_domain_projection_published($after);
    }return $result;
}
