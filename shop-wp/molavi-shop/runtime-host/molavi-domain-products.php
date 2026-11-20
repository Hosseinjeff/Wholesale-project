<?php
/** Plugin Name: Molavi Native Product Status and Source Evidence */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_product_headers(): array {
    return ["Channel ID", "Product ID", "Product Name", "Variation Type", "Sale Price", "Actual Price", "Price Type", "Price", "Currency", "Consumer Price", "Double Pack Price", "Double Pack Consumer Price", "Packaging", "Volume", "Category", "Description", "Stock Status", "Location", "Contact Info", "Original Message", "Channel", "Channel Username", "Message Timestamp", "Source Timestamp Basis", "Forwarded By", "Import Timestamp", "Last Updated", "Extraction Confidence Score", "Confidence", "Status", "Batch ID", "Previous Sale Price", "Previous Consumer Price", "Price Changed At", "Latest Offer ID", "Offer Count", "Min Qty", "Image File ID", "Image File Unique ID", "Brand", "Weight", "Package Count", "Reference Snapshot ID", "Reference Match ID", "Reference Price Role", "Reference Decision ID", "Source Post URL", "Reference Approval State", "Reference Price Change Percent", "Reference Price Checked At", "Reference Review Reason", "Source Post Created At", "Source Post Updated At"];
}
function molavi_domain_source_revision(array $record): string {
    $offer=trim((string)($record['Latest Offer ID']??''));
    if($offer===''){$id=trim((string)($record['Product ID']??''));$offer=$id!==''?'sheet:'.$id:'';}
    $raw=$record['Sale Price']??'';if(is_bool($raw)||!is_scalar($raw)||(is_float($raw)&&!is_finite($raw)))return '';
    $sale=str_replace([',','٬','_'],'',trim(molavi_domain_digits(is_float($raw)?molavi_domain_json($raw):$raw)));
    // Canonicalize the source decimal as text, including exponent notation.
    // The revision must change even when a decimal difference is smaller than
    // floating-point precision; the existing ledger uses Python Decimal.
    if($offer===''||!preg_match('/^\+?(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D',$sale,$m))return '';
    $whole=($m[1]??'')!==''?$m[1]:'0';$fraction=($m[3]??'')!==''?$m[3]:($m[2]??'');$exponent=(int)($m[4]??0);
    if(abs($exponent)>10000)return '';$digits=$whole.$fraction;$leading=strlen($digits)-strlen(ltrim($digits,'0'));
    $point=strlen($whole)+$exponent-$leading;$digits=rtrim(substr($digits,$leading),'0');if($digits==='')return '';
    if($point<=0)$sale='0.'.str_repeat('0',-$point).$digits;
    elseif($point>=strlen($digits))$sale=$digits.str_repeat('0',$point-strlen($digits));
    else $sale=substr($digits,0,$point).'.'.substr($digits,$point);
    $parts=[$offer,$sale,trim((string)($record['Currency']??''))];$basis=trim((string)($record['Source Timestamp Basis']??''));
    if($basis!=='')$parts[]=$basis;return molavi_domain_hash($parts);
}
function molavi_domain_price_age(?string $verified,float $now): string {
    $stamp=molavi_domain_timestamp($verified);
    if($stamp===null||$stamp>$now)return 'needs_review';$age=$now-$stamp;
    return $age>=14*86400?'blocked':($age>=7*86400?'warning':'current');
}
function molavi_domain_saved_price_state(string $revision,?array $saved,?float $sourceAt,float $now): array {
    $verified=$saved['verified_at']??null;
    $matches=$saved&&$revision!==''&&$saved['source_revision']===$revision;
    $state=$matches&&($sourceAt===null||$sourceAt<=$now)?molavi_domain_price_age($verified,$now):'needs_review';
    return ['state'=>$state,'verified_at'=>$verified,'method'=>$matches?($saved['method']??null):null];
}
function molavi_domain_product_saved_price(array $record,array $saved,float $now): array {
    $id=trim((string)($record['Product ID']??''));
    if($id==='')return [];
    return molavi_domain_saved_price_state(molavi_domain_source_revision($record),$saved[$id]??null,
        molavi_domain_timestamp($record['Message Timestamp']??null),$now);
}
function molavi_domain_price_evidence_by_product(SQLite3 $db): array {
    // The legacy offer_id column stores the product ID. The revision identifies the offer and price.
    return array_column(molavi_domain_rows($db,'SELECT offer_id,source_revision,verified_at,method FROM supplier_price_evidence'),null,'offer_id');
}
function molavi_domain_freshness(array $records,?float $clock=null,bool $observe=true): array {
    $now=$clock??microtime(true);$out=[];
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($records,$now,$observe):array {
        $out=[];
        foreach($records as $record){$id=trim((string)($record['Product ID']??''));if($id==='')continue;
            $basis=trim((string)($record['Source Timestamp Basis']??''));$trusted=in_array($basis,['supplier_channel_update','supplier_channel_forward'],true);
            $revision=molavi_domain_source_revision($record);$stamp=molavi_domain_timestamp($record['Message Timestamp']??null);
            $saved=molavi_domain_row($db,'SELECT * FROM supplier_price_evidence WHERE offer_id=?',[$id]);
            $iso=$stamp===null?null:DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',$stamp))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
            // Python omits fractional seconds when they are zero.
            if($iso!==null)$iso=str_replace('.000000+00:00','+00:00',$iso);
            if($observe&&$revision!==''&&$stamp!==null&&$stamp<=$now&&$trusted&&(!$saved
                ||($saved['source_revision']!==$revision&&$stamp>(molavi_domain_timestamp($saved['verified_at'])??INF)))){
                molavi_domain_sql($db,"INSERT INTO supplier_price_evidence VALUES (?,?,?,'source',NULL) ON CONFLICT(offer_id) DO UPDATE SET source_revision=excluded.source_revision,verified_at=excluded.verified_at,method='source',verified_by=NULL",[$id,$revision,$iso]);
                molavi_domain_sql($db,"INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,'source',NULL,'')",[$id,$revision,$iso]);
                $saved=['source_revision'=>$revision,'verified_at'=>$iso,'method'=>'source'];
            }
            $state=molavi_domain_saved_price_state($revision,$saved,$stamp,$now);
            if($state['method']===null)unset($state['method']);
            $out[]=array_merge(['product_id'=>$id,'name'=>(string)($record['Product Name']??''),'channel'=>(string)($record['Channel Username']??''),
                'sale_price'=>molavi_domain_py_value($record['Sale Price']??null),'source_revision'=>$revision,'source_timestamp'=>$iso,
                'source_timestamp_basis'=>$basis?:'legacy_unknown','source_timestamp_trusted'=>$trusted],$state);
        }return $out;
    });
}
function molavi_domain_identity_text($value): string {
    $value=strtr((string)($value?:''),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),str_split('0123456789')));
    return trim(preg_replace('/\s+/u',' ',preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u','',$value)));
}
function molavi_domain_peel_title(string $text): string {
    $patterns=['~(*UCP)^(?<name>.+?)(?:\s*[،,]\s*|\s+)(?<pack>(?:در\s*)?(?:کارتن|باکس|بسته)\s*[\d۰-۹]+\s*(?:عددی|عدد|تایی))\s*$~u',
        '~(*UCP)^(?<name>.+?)\s*[،,]\s*(?<pack>[\d۰-۹]+\s*(?:عددی|عدد|تایی))\s*$~u'];
    $text=trim($text);foreach($patterns as $pattern)if(preg_match($pattern,$text,$m)){
        $name=trim(preg_replace('/[\s،,:：\-]+$/u','',$m['name']));if(molavi_domain_strlen($name)>2&&trim($m['pack'])!=='')return $name;}
    return $text;
}
function molavi_domain_name_key($value): string {
    $name=trim(preg_replace('/[\s،,:：\-]+$/u','',molavi_domain_peel_title(molavi_domain_identity_text($value))));
    return function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
}
function molavi_domain_pack_count(string $packaging,string $name=''): int {
    $text=molavi_domain_identity_text($packaging.' '.$name);
    if(preg_match_all('~(*UCP)(?:کارتن|باکس|شیرینگ|shell|شل(?![\x{0600}-\x{06FF}]))\s*(\d+)~ui',$text,$m))return (int)end($m[1]);
    if(preg_match_all('~(*UCP)(\d+)\s*(?:عددی|عدد|تایی)~u',$text,$m))return (int)end($m[1]);return 0;
}
function molavi_domain_measure_key($supplied,$name,string $kind): string {
    $text=molavi_domain_identity_text($supplied);$title=molavi_domain_identity_text($name);
    $matches=molavi_domain_measurement_matches($text,$kind);if(!$matches)$matches=molavi_domain_measurement_matches($title,$kind);$values=[];$seen=[];
    foreach($matches as $match){if(isset($seen[$match['display']]))continue;$seen[$match['display']]=true;
        $parts=explode(' ',$match['display'],2);$fraction=molavi_domain_fraction($parts[0]);[$n,$d]=$fraction;
        if(in_array($parts[1],['لیتر','کیلوگرم'],true))$n*=1000;elseif($parts[1]==='میلی‌گرم')$d*=1000;
        $a=$n;$b=$d;while($b){$next=$a%$b;$a=$b;$b=$next;}$n=intdiv($n,$a);$d=intdiv($d,$a);$value=(string)$n.($d!==1?'/'.$d:'');
        $values[]=$value;
    }
    return $values?implode(',',$values):($text!==''?molavi_domain_name_key($text):'');
}
function molavi_domain_product_identity(array $record): array {
    $name=($record['Product Name']??'')?:($record['name']??'');$channel=($record['Channel Username']??'')?:($record['channel_username']??'')?:($record['channel']??'');
    $key=molavi_domain_name_key($name);$variant=molavi_domain_name_key(($record['Variation Type']??'')?:($record['variation_type']??'')?:($record['variant']??''));
    if($variant!==''&&preg_match('~(*UCP)(?<!\w)'.preg_quote($variant,'~').'(?!\w)~u',$key))$variant='';
    $volume=molavi_domain_measure_key(($record['Volume']??'')?:($record['volume']??''),$name,'volume');
    $weight=molavi_domain_measure_key(($record['Weight']??'')?:($record['weight']??''),$name,'weight');
    $pack=0;if(preg_match('/\d+/u',molavi_domain_identity_text(($record['Package Count']??'')?:($record['package_count']??'')),$m))$pack=(int)$m[0];
    $pack=$pack?:molavi_domain_pack_count((string)(($record['Packaging']??'')?:($record['packaging']??'')),(string)$name);
    $channel=trim((string)$channel);$channel=function_exists('mb_convert_case')?mb_convert_case($channel,MB_CASE_FOLD,'UTF-8'):strtolower($channel);
    return [$channel,$key,$variant,$volume,$weight,(string)$pack];
}
function molavi_domain_source_issues(array $products,array $offers): array {
    $byOffer=[];$ids=[];$identities=[];
    foreach($offers as $offer){$id=trim((string)($offer['Offer ID']??''));if($id!=='')$byOffer[$id][]=$offer;}
    foreach($products as $record){$id=trim((string)($record['Product ID']??''));if($id!=='')$ids[$id]=($ids[$id]??0)+1;
        if(trim((string)($record['Product Name']??''))!==''){$key=molavi_domain_json(molavi_domain_product_identity($record));$identities[$key]=($identities[$key]??0)+1;}}
    $issues=[];
    foreach($products as $index=>$record){$id=trim((string)($record['Product ID']??''));$latest=trim((string)($record['Latest Offer ID']??''));$codes=[];$mismatched=[];
        if($id==='')$codes[]='missing_product_id';elseif(($ids[$id]??0)>1)$codes[]='duplicate_product_id';
        if(trim((string)($record['Product Name']??''))!==''&&($identities[molavi_domain_json(molavi_domain_product_identity($record))]??0)>1)$codes[]='duplicate_product_identity';
        if($latest==='')$codes[]='missing_latest_offer';else{
            $linked=$byOffer[$latest]??[];if(!$linked)$codes[]='offer_not_found';elseif(count($linked)>1)$codes[]='duplicate_offer_id';else{
                $offer=$linked[0];$offerId=trim((string)($offer['Product ID']??''));if($id!==''&&$offerId!==''&&$id!==$offerId)$codes[]='product_id_mismatch';
                foreach(['Channel Username','Channel','Batch ID','Message Timestamp','Source Timestamp Basis','Source Post URL'] as $field)
                    if((string)(($record[$field]??'')?:'')!==(string)(($offer[$field]??'')?:''))$mismatched[]=$field;
                if($mismatched)$codes[]='source_provenance_mismatch';}}
        if($codes)$issues[]=['row_number'=>$index+2,'product_id'=>$id,'product_name'=>(string)($record['Product Name']??''),
            'channel'=>(string)($record['Channel Username']??''),'status'=>(string)($record['Status']??''),'latest_offer_id'=>$latest,
            'source_post_url'=>(string)($record['Source Post URL']??''),'codes'=>$codes,'mismatched_fields'=>$mismatched];
    }return $issues;
}
function molavi_domain_product_state(array $record,array $evidence=[],array $issues=[]): array {
    $approval=(string)($record['Reference Approval State']??'');$status=(string)($record['Status']??'');
    $referenceDue=in_array($approval,['outside_margin','reference_missing','price_unavailable'],true);
    $result=$approval==='approved_within_margin'?'due_no_update_within_margin':($referenceDue?'update_required':($approval!==''?'checked':'not_checked'));
    $supplierState=(string)($evidence['state']??'');$supplierDue=in_array($supplierState,['blocked','needs_review'],true);
    $state=['status_schema_version'=>2,'product_id'=>(string)($record['Product ID']??''),'product_name'=>(string)($record['Product Name']??''),
        'channel'=>(string)($record['Channel Username']??''),'status'=>$status,'latest_offer_id'=>(string)($record['Latest Offer ID']??''),
        'source_post_url'=>(string)($record['Source Post URL']??''),'reference_snapshot_id'=>(string)($record['Reference Snapshot ID']??''),
        'reference_match_id'=>(string)($record['Reference Match ID']??''),'reference_price_role'=>(string)($record['Reference Price Role']??''),
        'reference_approval_state'=>$approval,'reference_price_change_percent'=>molavi_domain_py_value($record['Reference Price Change Percent']??null),
        'reference_price_checked_at'=>(string)($record['Reference Price Checked At']??''),'reference_review_due'=>$referenceDue||$approval==='approved_within_margin',
        'reference_review_result'=>$result,'reference_update_needed'=>$referenceDue,'supplier_price_state'=>$supplierState,
        'supplier_price_verified_at'=>(string)(($evidence['verified_at']??'')?:''),'supplier_price_update_due'=>$supplierDue,
        'source_integrity_issues'=>$issues,'source_integrity_update_due'=>(bool)$issues,
        'update_needed'=>in_array(strtolower(trim($status)),['needs_review','held','blocked'],true)||$referenceDue||$supplierDue||(bool)$issues,
        'review_reason'=>(string)($record['Reference Review Reason']??'')];
    return $state;
}
function molavi_domain_current_products(?array $tables=null,?float $clock=null,bool $withRecords=false): array {
    $tables=$tables??molavi_domain_sheets(['Products','Offers'],true);$now=$clock??microtime(true);
    $products=molavi_domain_records($tables['Products']??[]);$offers=molavi_domain_records($tables['Offers']??[]);$issues=[];
    foreach(molavi_domain_source_issues($products,$offers) as $issue)if($issue['product_id']!=='')$issues[$issue['product_id']]=$issue['codes'];
    $evidence=molavi_domain_read('pipeline','molavi_domain_price_evidence_by_product');
    $result=[];
    foreach($products as $record){if(empty($record['Product Name'])&&empty($record['Product ID']))continue;
        $state=molavi_domain_product_saved_price($record,$evidence,$now);
        $item=molavi_domain_product_state($record,$state,$issues[trim((string)($record['Product ID']??''))]??[]);
        if($withRecords)$item['product_record']=array_diff_key($record,array_flip(['Original Message','Contact Info']));$result[]=$item;
    }
    if(!$withRecords)usort($result,static fn($a,$b)=>strcmp($a['product_name'],$b['product_name'])?:strcmp($a['product_id'],$b['product_id']));
    $stamp=DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',$now))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    return ['generated_at'=>str_replace('.000000+00:00','+00:00',$stamp),'products'=>$result];
}
function molavi_domain_status_capture(array $tables,string $reason,?float $clock=null): array {
    $current=molavi_domain_current_products($tables,$clock,true);$records=[];
    foreach($current['products'] as $state){$record=$state['product_record'];$key=trim((string)($record['Product ID']??''));
        if($key==='')$key=hash('sha256',implode('|',array_map(static fn($field)=>(string)(($record[$field]??'')?:''),['Product Name','Channel Username','Latest Offer ID'])));
        $records[]=[$key,$state['product_name'],$state];}
    usort($records,static fn($a,$b)=>strcmp($a[0],$b[0]));$states=[];foreach($records as [$key,$name,$state])$states[$key]=$state;
    $digest=molavi_domain_hash((object)$states);$at=$current['generated_at'];$id=hash('sha256',$at."\0".$reason."\0".$digest);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($records,$digest,$at,$id,$reason):array {
        $latest=molavi_domain_row($db,'SELECT snapshot_id FROM product_status_snapshots ORDER BY captured_at DESC LIMIT 1');$previous=[];
        if($latest)foreach(molavi_domain_rows($db,'SELECT product_key,state_json FROM product_status_records WHERE snapshot_id=?',[$latest['snapshot_id']]) as $row)$previous[$row['product_key']]=$row['state_json'];
        $changed=0;foreach($records as [$key,$name,$state])if(($previous[$key]??null)!==molavi_domain_json($state))$changed++;
        molavi_domain_sql($db,'INSERT OR IGNORE INTO product_status_snapshots (snapshot_id,captured_at,reason,product_count,changed_count,record_digest,schema_version) VALUES (?,?,?,?,?,?,?)',
            [$id,$at,molavi_domain_text($reason,120),count($records),$changed,$digest,2]);
        foreach($records as [$key,$name,$state])molavi_domain_sql($db,'INSERT OR IGNORE INTO product_status_records (snapshot_id,product_key,product_name,state_json) VALUES (?,?,?,?)',[$id,$key,$name,molavi_domain_json($state)]);
        return ['snapshot_id'=>$id,'captured_at'=>$at,'reason'=>$reason,'product_count'=>count($records),'changed_count'=>$changed];
    });
}
function molavi_domain_status_history(?string $selected=null,bool $marketEligibility=false,bool $livePrices=false): array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db)use($selected,$marketEligibility,$livePrices):array {
        $snapshots=molavi_domain_rows($db,'SELECT snapshot_id,captured_at,reason,product_count,changed_count,schema_version FROM product_status_snapshots ORDER BY captured_at DESC LIMIT 30');
        $latest=$snapshots[0]['snapshot_id']??null;$id=$selected?:$latest;$exists=$id?molavi_domain_row($db,'SELECT snapshot_id FROM product_status_snapshots WHERE snapshot_id=?',[$id]):null;
        $livePrices=$livePrices&&$selected===null;
        $evidence=$livePrices?molavi_domain_price_evidence_by_product($db):[];
        $now=microtime(true);
        $products=[];if($exists)foreach(molavi_domain_rows($db,'SELECT state_json FROM product_status_records WHERE snapshot_id=? ORDER BY product_name',[$id]) as $row){
            $state=molavi_domain_decode($row['state_json']);$record=$state['product_record']??null;
            if(is_array($record)){
                if($livePrices){
                    $price=molavi_domain_product_saved_price($record,$evidence,$now);
                    $state=array_replace($state,molavi_domain_product_state($record,$price,$state['source_integrity_issues']??[]));
                }
                if($marketEligibility)$state['regular_price_missing']=molavi_domain_market_missing_price($record);
            }
            unset($state['product_record']);$products[]=$state;}
        return ['snapshots'=>$snapshots,'latest_snapshot_id'=>$latest,'selected_snapshot_id'=>$exists?$id:null,'products'=>$products];
    });
}
function molavi_domain_status_api(string $method,array $query,?array $body,string $actor): array {
    if($method==='GET'&&($query['view']??null)==='current')return array_merge(['ok'=>true],molavi_domain_current_products());
    if($method==='POST'){
        if($body!==['action'=>'capture'])molavi_domain_fail('invalid_body',400);
        $capture=molavi_domain_status_capture(molavi_domain_sheets(['Products','Offers'],true),'owner_capture:'.$actor);
        return array_merge(['ok'=>true,'capture'=>$capture],molavi_domain_status_history());
    }
    $selected=trim((string)($query['snapshot_id']??''));if($selected!==''&&!preg_match('/^[a-f0-9]{64}$/D',$selected))molavi_domain_fail('invalid_snapshot_id',400);
    $latest=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT captured_at,schema_version FROM product_status_snapshots ORDER BY captured_at DESC LIMIT 1'));
    if(!$latest||$latest['schema_version']<2||(molavi_domain_timestamp($latest['captured_at'])??0)<=microtime(true)-86400)
        molavi_domain_status_capture(molavi_domain_sheets(['Products','Offers'],true),'daily_status_checkpoint');
    $history=molavi_domain_status_history($selected?:null,$selected===''&&function_exists('molavi_domain_market_missing_price'),$selected==='');if($selected!==''&&$history['selected_snapshot_id']!==$selected)molavi_domain_fail('snapshot_not_found',404);
    return array_merge(['ok'=>true],$history);
}
