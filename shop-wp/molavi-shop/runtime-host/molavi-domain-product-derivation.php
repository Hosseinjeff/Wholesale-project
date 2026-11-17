<?php
/** Plugin Name: Molavi Native Offer Projection Core */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Rebuild from append-only offers; never infer missing supplier facts. */
function molavi_domain_product_from_offers(array $offers): array {
    if(!$offers)molavi_domain_fail('source_changed');$latest=$offers[count($offers)-1];$product=[];
    $map=['name'=>'Product Name','variation_type'=>'Variation Type','brand'=>'Brand','weight'=>'Weight','package_count'=>'Package Count',
        'price_type'=>'Price Type','currency'=>'Currency','packaging'=>'Packaging','volume'=>'Volume','double_pack_price'=>'Package Price',
        'category'=>'Category','description'=>'Description','stock_status'=>'Stock Status','latest_offer_id'=>'Offer ID',
        'reference_snapshot_id'=>'Reference Snapshot ID','reference_match_id'=>'Reference Match ID','reference_price_role'=>'Reference Price Role Hint',
        'reference_decision_id'=>'Reference Decision ID','source_post_url'=>'Source Post URL','reference_price_change_percent'=>'Reference Price Change Percent',
        'reference_price_checked_at'=>'Reference Price Checked At'];
    foreach($map as $key=>$field)$product[$key]=molavi_domain_py_value($latest[$field]??'');
    foreach(['sale_price'=>'Sale Price','actual_price'=>'Actual Price','price'=>'Price','consumer_price'=>'Consumer Price'] as $key=>$field)$product[$key]=$latest[$field]??null;
    foreach(['extraction_confidence'=>'Extraction Confidence Score','confidence'=>'Confidence'] as $key=>$field)$product[$key]=molavi_domain_py_value($latest[$field]??null,0);
    $product['status']=molavi_domain_py_value($latest['Status']??null,'imported');
    $product['reference_approval_state']=(string)molavi_domain_py_value($latest['Reference Approval State']??null);
    if($product['reference_approval_state']==='')$product['reference_approval_state']=trim((string)molavi_domain_py_value($latest['Reference Decision ID']??null))!==''?'owner_approved':
        ((string)molavi_domain_py_value($latest['Reference Match State']??null)==='exact_compatible_identity'?'approved_compatible':'needs_review');
    $product['previous_sale_price']='';$product['previous_consumer_price']='';$product['price_changed_at']='';
    $consumer=static fn($row)=>molavi_domain_positive_price($row['Consumer Price']??null)?:molavi_domain_positive_price($row['Actual Price']??null);
    $sale=molavi_domain_positive_price($latest['Sale Price']??null);$regular=$consumer($latest);$prior=array_reverse(array_slice($offers,0,-1));
    foreach($prior as $older){$old=molavi_domain_positive_price($older['Sale Price']??null);if($sale&&$old&&$old!==$sale){$product['previous_sale_price']=$old;break;}}
    foreach($prior as $older){$old=$consumer($older);if($regular&&$old&&$old!==$regular){$product['previous_consumer_price']=$old;break;}}
    if($prior){$product['price_changed_at']=(string)molavi_domain_py_value($latest['Import Timestamp']??null);
        foreach($prior as $older){if(molavi_domain_positive_price($older['Sale Price']??null)!==$sale||$consumer($older)!==$regular)break;
            if(molavi_domain_py_value($older['Import Timestamp']??null)!=='')$product['price_changed_at']=(string)$older['Import Timestamp'];}}
    $product['offer_count']=count($offers);$pack=molavi_domain_pack_count((string)$product['packaging'],(string)$product['name']);
    $product['min_qty']=max(molavi_domain_positive_price($latest['Min Qty']??null),in_array($pack,[6,12,24],true)?6:1);
    $product['image_file_id']='';$product['image_file_unique_id']='';
    foreach(array_reverse($offers) as $offer){$image=(string)molavi_domain_py_value($offer['Image File ID']??null);if($image!==''){
        $product['image_file_id']=$image;$product['image_file_unique_id']=(string)molavi_domain_py_value($offer['Image File Unique ID']??null);break;}}
    return $product;
}
function molavi_domain_message_from_offer(array $offer): array {
    $map=['channel_username'=>'Channel Username','channel'=>'Channel','content'=>'Original Message','timestamp'=>'Message Timestamp',
        'source_created_at'=>'Source Post Created At','source_update_at'=>'Source Post Updated At','source_timestamp_basis'=>'Source Timestamp Basis',
        'forwarded_by'=>'Forwarded By','batch_id'=>'Batch ID','url'=>'Source Post URL'];$message=[];
    foreach($map as $key=>$field)$message[$key]=molavi_domain_py_value($offer[$field]??null);return $message;
}
function molavi_domain_product_row(array $headers,array $product,array $message,?string $clock=null): array {
    $now=$clock??molavi_domain_now();$record=[];
    $map=['Product Name'=>'name','Variation Type'=>'variation_type','Price Type'=>'price_type','Currency'=>'currency','Packaging'=>'packaging','Volume'=>'volume',
        'Category'=>'category','Description'=>'description','Stock Status'=>'stock_status','Location'=>'location','Contact Info'=>'contact_info',
        'Previous Sale Price'=>'previous_sale_price','Previous Consumer Price'=>'previous_consumer_price','Price Changed At'=>'price_changed_at',
        'Latest Offer ID'=>'latest_offer_id','Offer Count'=>'offer_count','Min Qty'=>'min_qty','Image File ID'=>'image_file_id','Image File Unique ID'=>'image_file_unique_id',
        'Brand'=>'brand','Weight'=>'weight','Package Count'=>'package_count','Reference Snapshot ID'=>'reference_snapshot_id','Reference Match ID'=>'reference_match_id',
        'Reference Price Role'=>'reference_price_role','Reference Decision ID'=>'reference_decision_id','Reference Approval State'=>'reference_approval_state',
        'Reference Price Change Percent'=>'reference_price_change_percent','Reference Price Checked At'=>'reference_price_checked_at','Reference Review Reason'=>'reference_review_reason'];
    foreach($map as $field=>$key)$record[$field]=molavi_domain_py_value($product[$key]??null);
    foreach(['Sale Price'=>'sale_price','Actual Price'=>'actual_price','Price'=>'price','Consumer Price'=>'consumer_price','Double Pack Price'=>'double_pack_price',
        'Double Pack Consumer Price'=>'double_pack_consumer_price'] as $field=>$key)$record[$field]=$product[$key]??'';
    foreach(['Original Message'=>'content','Channel'=>'channel','Channel Username'=>'channel_username','Message Timestamp'=>'timestamp',
        'Source Timestamp Basis'=>'source_timestamp_basis','Source Post Created At'=>'source_created_at','Source Post Updated At'=>'source_update_at',
        'Forwarded By'=>'forwarded_by','Batch ID'=>'batch_id','Source Post URL'=>'url'] as $field=>$key)$record[$field]=molavi_domain_py_value($message[$key]??null);
    $channel=molavi_domain_py_value($message['channel_username']??null,molavi_domain_py_value($message['channel']??null));$record['Channel ID']=$channel;
    $parts=molavi_domain_product_identity(array_merge($product,['Product Name'=>$product['name']??'','Channel Username'=>$channel]));
    $record['Product ID']='p_'.substr(molavi_domain_hash($parts),0,24); // Package count "0" is a nonempty Python identity part.
    $record['Import Timestamp']=$now;$record['Last Updated']=$now;
    $record['Extraction Confidence Score']=molavi_domain_py_value($product['extraction_confidence']??null,molavi_domain_py_value($product['confidence']??null,0));
    $record['Confidence']=molavi_domain_py_value($product['confidence']??null,0);$record['Status']=molavi_domain_py_value($product['status']??null,'imported');
    return array_map(static fn($header)=>$record[$header]??'',$headers);
}
function molavi_domain_offer_identity_fields(array $record): array {
    $out=[];foreach(['Variation Type'=>['variation_type','variant'],'Volume'=>['volume'],'Weight'=>['weight'],'Package Count'=>['package_count'],'Packaging'=>['packaging']] as $field=>$keys){
        $value=molavi_domain_py_value($record[$field]??null);foreach($keys as $key)if($value==='')$value=molavi_domain_py_value($record[$key]??null);$out[$field]=$value;}return $out;
}
function molavi_domain_offers_for_product(array $offers,string $name,string $channel,array $identity=[]): array {
    $target=molavi_domain_product_identity(array_merge(['Product Name'=>$name,'Channel Username'=>$channel],$identity));
    return array_values(array_filter($offers,static fn($offer)=>molavi_domain_product_identity($offer)===$target));
}
function molavi_domain_product_reference_review(array $product,array $offer,?string $activeId=null,?string $clock=null): array {
    $active=$activeId===null?molavi_domain_reference_active():null;$activeId=$activeId??(string)($active['snapshot_id']??'');
    $offerId=(string)molavi_domain_py_value($offer['Offer ID']??null);if($activeId===''||$offerId==='')return $product;
    $decision=molavi_domain_read('reference',static function(SQLite3 $db)use($offer,$offerId,$activeId):?array {
        $case=static fn($id,$snapshot)=>'item:'.hash('sha256',$id."\0".$snapshot);
        $get=static fn($id,$snapshot)=>molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE case_id=? ORDER BY sequence DESC LIMIT 1',[$case($id,$snapshot)]);
        $decision=$get($offerId,$activeId);$source=(string)molavi_domain_py_value($offer['Derived From Offer ID']??null);
        if(!$decision&&$source!=='')$decision=$get($source,$activeId);
        $id=(string)molavi_domain_py_value($offer['Reference Decision ID']??null);
        if(!$decision&&$id!==''){$decision=molavi_domain_row($db,'SELECT * FROM reference_item_decisions WHERE decision_id=?',[$id]);
            if(!$decision){$inherited=molavi_domain_row($db,"SELECT * FROM reference_item_inheritances WHERE inheritance_id=? AND state='within_margin'",[$id]);
                if($inherited)$decision=['decision_id'=>$inherited['inheritance_id'],'snapshot_id'=>$inherited['snapshot_id'],'choice'=>'match',
                    'reference_id'=>$inherited['reference_id'],'price_role'=>$inherited['price_role'],'inherited'=>true,'change_ratio'=>$inherited['change_ratio']];}}
        return $decision;
    });
    if(!$decision||$decision['snapshot_id']!==$activeId)return $product;
    $product['reference_snapshot_id']=$activeId;$product['reference_decision_id']=molavi_domain_py_value($decision['decision_id']??null);
    if($decision['choice']==='no_match'){$product['reference_match_id']='';$product['reference_price_role']='unresolved';$product['reference_approval_state']='needs_review';$product['status']='needs_review';return $product;}
    if($decision['choice']!=='match')return $product;$product['reference_match_id']=molavi_domain_py_value($decision['reference_id']??null);
    $product['reference_price_role']=molavi_domain_py_value($decision['price_role']??null);$due=in_array($product['reference_approval_state']??null,['outside_margin','reference_missing','price_unavailable'],true);
    $product['reference_approval_state']=!empty($decision['inherited'])?'approved_within_margin':'owner_approved';
    if(isset($decision['change_ratio']))$product['reference_price_change_percent']=round((float)$decision['change_ratio']*100,4,PHP_ROUND_HALF_EVEN);
    $product['reference_price_checked_at']=$clock??molavi_domain_now();
    if($decision['price_role']==='package'){
        $snapshot=$active??molavi_domain_reference_snapshot($activeId);$referencePack=0;
        foreach($snapshot['rows']??[] as $row)if((string)molavi_domain_py_value($row[16]??null,$row[2]??'')===$decision['reference_id']){$referencePack=(int)(float)molavi_domain_py_value($row[6]??null,0);break;}
        $count=(int)(molavi_domain_review_package_count($product)?:$referencePack);$sourceCost=molavi_domain_positive_price($offer['Sale Price']??null);
        $unitConfirmed=trim((string)molavi_domain_py_value($offer['Owner Decision ID']??null))!=='';
        if($count>0&&$sourceCost&&!$unitConfirmed){$unit=(int)floor($sourceCost/$count+.5);$product['sale_price']=$unit;$product['price']=$unit;
            $product['package_count']=$count;$product['min_qty']=max(molavi_domain_positive_price($product['min_qty']??null),$count);}
        elseif($count>0&&$unitConfirmed){$product['package_count']=$count;$product['min_qty']=max(molavi_domain_positive_price($product['min_qty']??null),$count);}
    }
    $reasons=array_values(array_filter(array_map('trim',explode(';',(string)molavi_domain_py_value($offer['Review Reason']??null))),static fn($s)=>$s!==''));
    $onlyReference=(bool)$reasons;foreach($reasons as $reason)if(!str_starts_with(strtolower($reason),'reference:'))$onlyReference=false;
    if(strtolower((string)($product['status']??''))==='needs_review'&&($onlyReference||$due))$product['status']='imported';return $product;
}
function molavi_domain_review_package_count(array $product): float {
    $raw=molavi_domain_digits(molavi_domain_py_value($product['package_count']??null,0));$value=is_numeric($raw)&&is_finite((float)$raw)&&(float)$raw>0?(float)$raw:0;
    if($value)return $value;
    $packaging=(string)molavi_domain_py_value($product['packaging']??null,molavi_domain_py_value($product['name']??null));
    if(preg_match('/(*UCP)(?<!\d)(\d{1,4})\s*(?:عددی|تایی|عدد|ف|تکه|قوطی|بسته)/u',molavi_domain_digits($packaging),$m))return (int)$m[1];return 0;
}
