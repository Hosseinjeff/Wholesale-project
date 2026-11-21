<?php
/** Plugin Name: Molavi Native Source Projection */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_restore_source_product(array $source,?string $clock=null): void {
    molavi_domain_sheet_ensure('Products',molavi_domain_product_headers());$tables=molavi_domain_sheets(['Products','Offers'],true);
    $headers=array_map('strval',$tables['Products'][0]??[]);$products=molavi_domain_records($tables['Products']);$offers=molavi_domain_records($tables['Offers']);
    $name=(string)molavi_domain_py_value($source['Product Name']??null,$source['name']??'');$channel=(string)molavi_domain_py_value($source['Channel Username']??null,molavi_domain_py_value($source['channel_username']??null,$source['channel']??''));
    if($name==='')return;$matching=molavi_domain_offers_for_product($offers,$name,$channel,molavi_domain_offer_identity_fields($source));if(!$matching)return;
    usort($matching,static function($a,$b){$key=static function($offer){$import=molavi_domain_timestamp($offer['Import Timestamp']??null)??-62135596800.0;$basis=trim((string)molavi_domain_py_value($offer['Source Timestamp Basis']??null));
        $source=$basis!==''&&!in_array($basis,['supplier_channel_update','supplier_channel_forward'],true)?null:molavi_domain_timestamp($offer['Message Timestamp']??null);return [$source??$import,$import];};return $key($a)<=>$key($b);});
    $target=molavi_domain_product_identity(array_merge(['Product Name'=>$name,'Channel Username'=>$channel],molavi_domain_offer_identity_fields($source)));$indexes=[];
    foreach($products as $index=>$product)if(molavi_domain_product_identity($product)===$target)$indexes[]=$index;$index=count($indexes)===1?$indexes[0]:null;
    if($index===null){$ids=array_map(static fn($offer)=>(string)molavi_domain_py_value($offer['Offer ID']??null),$matching);
        foreach($products as $i=>$product)if(in_array((string)molavi_domain_py_value($product['Latest Offer ID']??null),$ids,true)){$index=$i;break;}}
    if($index===null){$ids=[];foreach($matching as $offer){$id=trim((string)molavi_domain_py_value($offer['Product ID']??null));if($id!=='')$ids[$id]=true;}
        if(count($ids)===1){$id=(string)array_key_first($ids);$matches=[];foreach($products as $i=>$product)if((string)molavi_domain_py_value($product['Product ID']??null)===$id&&(string)molavi_domain_py_value($product['Channel Username']??null)===$channel)$matches[]=$i;
            if(count($matches)===1)$index=$matches[0];}}
    if($index!==null){$existing=$products[$index];molavi_domain_refresh_conflict_product(['product_id'=>(string)molavi_domain_py_value($existing['Product ID']??null),'product_name'=>$name,'channel'=>$channel],$existing,$headers,$matching,$clock);return;}
    $latest=end($matching);$product=molavi_domain_product_reference_review(molavi_domain_product_from_offers($matching),$latest,null,$clock);
    $row=molavi_domain_product_row($headers,$product,molavi_domain_message_from_offer($latest),$clock);$record=array_combine($headers,$row);
    $record['Last Updated']=molavi_domain_py_value($latest['Import Timestamp']??null);$record['Import Timestamp']=molavi_domain_py_value($matching[0]['Import Timestamp']??null);
    molavi_domain_sheet_append('Products',[array_map(static fn($header)=>$record[$header]??'',$headers)]);
    $confirmed=molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products']);$found=false;
    foreach($confirmed as $check)if(($check['Latest Offer ID']??null)===($record['Latest Offer ID']??null)&&($check['Product ID']??null)===$record['Product ID']){$found=true;break;}
    if(!$found)molavi_domain_fail('product_projection_unconfirmed',503);
}
function molavi_domain_confirmed_offer(array $source,array $decision,string $now,array $products): array {
    $get=static fn($field,$fallback='')=>molavi_domain_py_value($source[$field]??null,$fallback);
    $product=['name'=>$get('Product Name'),'currency'=>'IRT','packaging'=>$get('Packaging'),'volume'=>$get('Volume'),'category'=>$get('Category'),'variation_type'=>$get('Variation Type'),
        'stock_status'=>$get('Stock Status','Unknown'),'description'=>$get('Description'),'confidence'=>1.0,'extraction_confidence'=>$get('Extraction Confidence Score',$get('Confidence',1.0)),
        'brand'=>$get('Brand'),'weight'=>$get('Weight'),'package_count'=>$get('Package Count'),'min_qty'=>$get('Min Qty'),'status'=>'not_product','price_type'=>$get('Price Type')];$reason='';
    if($decision['choice']==='confirm'){$fields=$decision['fields'];$count=$fields['package_count'];$price=$fields['sale_price'];
        if (isset($fields['category'])) $product['category']=trim($fields['category']);
        if($fields['price_role']==='package'){$unit=intdiv($price+intdiv($count,2),$count);$package=$price;$product['min_qty']=max((int)molavi_domain_py_value($fields['min_qty'],0),$count);}
        else{$unit=$price;$package=molavi_domain_positive_price($source['Package Price']??null)?:'';$product['min_qty']=(int)molavi_domain_py_value($fields['min_qty'],molavi_domain_positive_price($source['Min Qty']??null)?:1);}
        $product=array_replace($product,['sale_price'=>$unit,'price'=>$unit,'actual_price'=>molavi_domain_py_value($fields['actual_price']),'consumer_price'=>molavi_domain_py_value($fields['actual_price']),
            'package_price'=>$package,'package_count'=>molavi_domain_py_value($count,$get('Package Count')),'price_type'=>'unit','stock_status'=>$fields['stock_status'],'status'=>'imported']);
        $reasons=[];foreach(explode(';',(string)$get('Review Reason')) as $part)if(str_starts_with(strtolower(trim($part)),'reference:'))$reasons[]=trim($part);
        if($reasons){$reason=implode('; ',$reasons);$product['status']='needs_review';}}
    $id=(string)$get('Product ID');$channel=(string)$get('Channel Username');
    if($id===''){$name=trim((string)$product['name']);$target=molavi_domain_product_identity(array_merge($product,['Product Name'=>$name,'Channel Username'=>$channel]));$matches=[];
        foreach($products as $record)if(molavi_domain_product_identity($record)===$target)$matches[]=$record;
        if(count($matches)!==1){$matches=[];foreach($products as $record)if(trim((string)molavi_domain_py_value($record['Product Name']??null))===$name&&trim((string)molavi_domain_py_value($record['Channel Username']??null))===trim($channel))$matches[]=$record;}
        if(count($matches)===1)$id=trim((string)molavi_domain_py_value($matches[0]['Product ID']??null));
        if($id==='')$id=molavi_domain_product_row(['Product ID'],array_merge($product,['name'=>$name]),['channel_username'=>$channel],$now)[0];}
    $map=['Product Name'=>'name','Currency'=>'currency','Packaging'=>'packaging','Volume'=>'volume','Category'=>'category','Variation Type'=>'variation_type','Stock Status'=>'stock_status',
        'Description'=>'description','Brand'=>'brand','Weight'=>'weight','Package Count'=>'package_count','Min Qty'=>'min_qty','Price Type'=>'price_type','Status'=>'status','Confidence'=>'confidence','Extraction Confidence Score'=>'extraction_confidence'];$offer=[];
    foreach($map as $field=>$key)$offer[$field]=molavi_domain_py_value($product[$key]??null);
    $offer['Min Qty']=molavi_domain_py_value($product['min_qty']??null,in_array(molavi_domain_pack_count((string)$product['packaging'],(string)$product['name']),[6,12,24],true)?6:1);
    foreach(['Sale Price'=>'sale_price','Consumer Price'=>'consumer_price','Price'=>'price','Actual Price'=>'actual_price','Package Price'=>'package_price'] as $field=>$key)$offer[$field]=$product[$key]??'';
    foreach(['Channel','Channel Username','Original Message','Message Timestamp','Source Post URL','Message ID','Forwarded By','Image File ID','Image File Unique ID',
        'Reference Snapshot ID','Reference Match State','Reference Match ID','Reference Price Role Hint','Reference Decision ID','Reference Usage Warning'] as $field)$offer[$field]=$get($field);
    $offer=array_replace($offer,['Offer ID'=>'owner-confirm:'.$decision['decision_id'],'Product ID'=>$id,'Batch ID'=>'owner-ai-confirmation:'.$decision['decision_id'],'Import Timestamp'=>$now,
        'Review Reason'=>$product['status']==='needs_review'?$reason:'','Reference Approval State'=>'','Reference Price Change Percent'=>'','Reference Price Checked At'=>'',
        'Derived From Offer ID'=>(string)$get('Offer ID'),'Owner Decision ID'=>$decision['decision_id'],'Owner Decision Actor'=>$decision['actor_id'],'Owner Decision At'=>$now,'Media OCR Text'=>'']);return $offer;
}
function molavi_domain_ai_apply_decision(array $case,array $decision,?string $clock=null): string {
    $tables=molavi_domain_sheets(['Offers','Products'],true);$offers=molavi_domain_records($tables['Offers']);$source=null;
    foreach($offers as $offer)if((string)molavi_domain_py_value($offer['Offer ID']??null)===$case['offer_id']){$source=$offer;break;}
    if(!$source||molavi_domain_hash($source)!==$case['signature'])molavi_domain_fail('decision_conflict');$derived='owner-confirm:'.$decision['decision_id'];
    foreach($offers as $offer)if((string)molavi_domain_py_value($offer['Offer ID']??null)===$derived){molavi_domain_restore_source_product($source,$clock);return $derived;}
    molavi_domain_sheet_ensure('Offers',molavi_domain_offer_headers());$headers=array_map('strval',molavi_domain_sheets(['Offers'],true)['Offers'][0]??[]);
    $imported=molavi_domain_confirmed_offer($source,$decision,$clock??molavi_domain_now(),molavi_domain_records($tables['Products']));
    molavi_domain_sheet_append('Offers',[array_map(static fn($header)=>$imported[$header]??'',$headers)]);
    $fresh=molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']);$confirmed=null;foreach($fresh as $offer)if((string)molavi_domain_py_value($offer['Offer ID']??null)===$derived){$confirmed=$offer;break;}
    if(!$confirmed)molavi_domain_fail('offer_append_unconfirmed',503);
    foreach($headers as $header)if(!molavi_domain_cells_equal($header,$confirmed[$header]??'',$imported[$header]??''))molavi_domain_fail('offer_append_unconfirmed',503);
    molavi_domain_restore_source_product($imported,$clock);return $derived;
}
function molavi_domain_ai_refresh_message(array $case,?string $clock=null): bool {
    $id=$case['message_id'];if($id==='')return false;$batch=$case['historical_reextract']?'':$case['batch_id'];$offers=molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']);$messageOffers=[];$ids=[];
    foreach($offers as $offer)if((string)molavi_domain_py_value($offer['Message ID']??null)===$id){$messageOffers[]=$offer;if($batch===''||(string)molavi_domain_py_value($offer['Batch ID']??null)===$batch)$ids[]=(string)molavi_domain_py_value($offer['Offer ID']??null);}
    $latest=[];foreach($messageOffers as $offer)if($batch===''||(string)molavi_domain_py_value($offer['Batch ID']??null)===$batch||in_array((string)molavi_domain_py_value($offer['Derived From Offer ID']??null),$ids,true))
        $latest[molavi_domain_json([(string)molavi_domain_py_value($offer['Product Name']??null),(string)molavi_domain_py_value($offer['Channel Username']??null)])]=$offer;
    $cases=[];$allNotProduct=(bool)$latest;$allOwned=(bool)$latest;foreach($latest as $offer){if(molavi_domain_offer_ai_review($offer)&&(string)molavi_domain_py_value($offer['Offer ID']??null)!=='')$cases[]='ai:'.hash('sha256',(string)$offer['Offer ID']);
        if(strtolower(trim((string)molavi_domain_py_value($offer['Status']??null)))!=='not_product')$allNotProduct=false;if(trim((string)molavi_domain_py_value($offer['Owner Decision ID']??null))==='')$allOwned=false;}
    $status=$cases?'needs_confirmation':(!$latest?'no_product_candidate':($allNotProduct?($allOwned?'owner_rejected':'no_product_candidate'):'ai_processed'));
    return molavi_domain_ai_write_message_state($id,$batch,$case['source_channel'],$status,$cases,$clock);
}

function molavi_domain_ai_write_message_state(string $id,string $batch,string $channel,string $status,array $cases,?string $clock=null): bool {
    molavi_domain_sheet_ensure('MessageData',molavi_domain_message_headers());$rows=molavi_domain_sheets(['MessageData'],true)['MessageData'];$headers=array_map('strval',$rows[0]??[]);$changed=false;
    if(array_diff(['ID','Batch ID','AI Status','AI Review Case IDs','AI Processed At'],$headers))return false;
    foreach(molavi_domain_records($rows) as $index=>$record)if((string)($record['ID']??'')===$id&&($batch===''||(string)($record['Batch ID']??'')===$batch)&&(!in_array('Channel',$headers,true)||$channel===''||(string)($record['Channel']??'')===$channel)){
        molavi_domain_sheet_fields('MessageData',$index+2,$headers,['AI Status'=>$status,'AI Review Case IDs'=>molavi_domain_json($cases,true),'AI Processed At'=>$clock??molavi_domain_now()]);$changed=true;}
    return $changed;
}

function molavi_domain_offer_headers(): array {return ['Offer ID', 'Product ID', 'Product Name', 'Channel Username', 'Channel', 'Sale Price', 'Consumer Price', 'Price', 'Actual Price', 'Price Type', 'Currency', 'Packaging', 'Volume', 'Category', 'Variation Type', 'Stock Status', 'Description', 'Status', 'Review Reason', 'Extraction Confidence Score', 'Confidence', 'Message ID', 'Batch ID', 'Message Timestamp', 'Source Timestamp Basis', 'Forwarded By', 'Import Timestamp', 'Original Message', 'Package Price', 'Min Qty', 'Image File ID', 'Image File Unique ID', 'Brand', 'Weight', 'Package Count', 'Reference Snapshot ID', 'Reference Match State', 'Reference Match ID', 'Reference Price Role Hint', 'Reference Decision ID', 'Reference Usage Warning', 'Source Post URL', 'Reference Approval State', 'Reference Price Change Percent', 'Reference Price Checked At', 'Derived From Offer ID', 'Owner Decision ID', 'Owner Decision Actor', 'Owner Decision At', 'Detected Price', 'Price Anchor Type', 'Price Anchor ID', 'Price Anchor Name', 'Price Anchor Match Score', 'Price Scale Method', 'Price Scale Status', 'Price Scale Reason', 'Historical Median Unit Price', 'Category Reference Count', 'Media OCR Text', 'Source Post Created At', 'Source Post Updated At'];}
function molavi_domain_message_headers(): array {return ['ID', 'Channel', 'Channel Username', 'Author', 'Content', 'Timestamp', 'Source Timestamp Basis', 'URL', 'Forwarded By', 'Forwarded At', 'Has Media', 'Media Type', 'Import Timestamp', 'Status', 'Batch ID', 'Image File ID', 'Image File Unique ID', 'Media Group ID', 'AI Status', 'AI Review Case IDs', 'AI Processed At', 'Source Post Created At', 'Source Post Updated At'];}
