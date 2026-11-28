<?php
/** Plugin Name: Molavi Native Incremental Reference Store */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_reference_headers(): array {
    return ['Product Name','Clean Product Name','Normalized Key','Reference Package Price','Reference Unit Price','Consumer Unit Price',
        'Package Count','Unit Label','Volume','Weight','Set Count','Variant','Brand','Category','Raw Name Details','Source Row Number',
        'Product Code','Currency','Source','Source URL','Last Updated',
        'Reference Supply Pack Price','Reference Supply Unit Price','Reference Regular Pack Price','Reference Regular Unit Price',
        'Reference Price Anomaly'];
}
function molavi_domain_reference_active(): ?array {
    return molavi_domain_read('reference',static function(SQLite3 $db):?array {
        $row=molavi_domain_row($db,'SELECT s.snapshot_id,s.payload FROM reference_active a JOIN reference_snapshots s ON s.snapshot_id=a.snapshot_id WHERE a.singleton=1');
        if(!$row)return null;$result=molavi_domain_decode($row['payload']);$result['snapshot_id']=$row['snapshot_id'];
        $check=molavi_domain_row($db,'SELECT snapshot_id,checked_at FROM reference_source_check WHERE singleton=1');
        if(($check['snapshot_id']??null)===$row['snapshot_id'])$result['last_checked_at']=$check['checked_at'];return $result;
    });
}
function molavi_domain_reference_candidate(array $products,int $pages,string $currency,string $timestamp): array {
    return ['schema_version'=>2,'status'=>'staged','requires_review'=>true,'source_type'=>'woocommerce_store_api',
        'source_url'=>molavi_domain_reference_source(),'source_currency'=>$currency,'source_total'=>count($products),
        'source_page_count'=>$pages,'processed_page_count'=>$pages,'first_source_row_number'=>1,'last_source_row_number'=>count($products),
        'model'=>null,'extracted_at'=>$timestamp,'row_count'=>0,'coverage_verified'=>false,'headers'=>molavi_domain_reference_headers(),
        'rows'=>[],'_source_products'=>$products];
}
function molavi_domain_reference_incremental(array $candidate): array {
    $products=$candidate['_source_products']??null;if(!is_array($products))return [$candidate,null];
    $active=molavi_domain_reference_active();$oldRows=$active['rows']??[];$activeIds=$active['source_product_ids']??null;
    if(!is_array($activeIds)||count($activeIds)!==count($oldRows)||count(array_unique(array_map('strval',$activeIds)))!==count($activeIds))$activeIds=null;
    $oldById=[];
    if($activeIds){foreach($activeIds as $index=>$id)$oldById[(string)$id]=$oldRows[$index];}
    else{$oldByCode=[];foreach($oldRows as $row)if(is_array($row)&&(count($row)===21||count($row)===26))$oldByCode[(string)molavi_domain_py_value($row[16])]=$row;
        foreach($products as $product){$code=molavi_domain_reference_code($product);if(isset($oldByCode[$code]))$oldById[(string)$product['id']]=$oldByCode[$code];}}
    $cached=molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT source_product_id,product_code,payload FROM reference_source_products'));
    $oldRaw=[];$oldCodes=[];foreach($cached as $row){$oldRaw[(string)$row['source_product_id']]=molavi_domain_decode($row['payload']);$oldCodes[(string)$row['source_product_id']]=$row['product_code'];}
    $timestamp=$candidate['extracted_at'];$sourceIds=[];$rows=[];$changed=[];$added=[];$seen=[];$cachedRows=$candidate['rows']??[];
    $groups=['name'=>[0,1,2,4,5,6,7,8,9,10],'prices'=>[3,4,21,22,23,24,25],'sku'=>[16],'categories'=>[13],'attributes'=>[11,12]];
    $legacyUpgraded=false;
    foreach($products as $offset=>$product){
        if(!is_array($product))molavi_domain_fail('woocommerce_product_invalid',400);
        $code=molavi_domain_reference_code($product);$id=(string)$product['id'];$ordinal=$offset+1;
        if(isset($seen[$id]))molavi_domain_fail('woocommerce_duplicate_product_id',400);$seen[$id]=true;$sourceIds[]=$id;
        $prior=$oldById[$id]??null;$priorRaw=$oldRaw[$id]??null;
        if($prior!==null&&(!is_array($prior)||(count($prior)!==21&&count($prior)!==26)))$prior=null;
        if($prior!==null&&count($prior)===21){
            // Schema v1 rows predate the two-price columns: fill the new
            // columns from the current parse so the upgrade is a metadata
            // change, not a re-add of every product.
            $freshRow=molavi_domain_reference_row($product,$ordinal,$timestamp);
            $prior=array_merge($prior,array_slice($freshRow,21,5));$legacyUpgraded=true;
        }
        if($prior===null){$rows[]=count($cachedRows)===count($products)?$cachedRows[$offset]:molavi_domain_reference_row($product,$ordinal,$timestamp);
            $added[]=['source_product_id'=>$id,'product_code'=>$code];continue;}
        $rawChanged=[];$fresh=null;
        if($priorRaw!==null){foreach(array_keys($groups) as $node)
            if(molavi_domain_hash($priorRaw[$node]??null)!==molavi_domain_hash($product[$node]??null))$rawChanged[]=$node;}
        else{$fresh=count($cachedRows)===count($products)?$cachedRows[$offset]:molavi_domain_reference_row($product,$ordinal,$timestamp);
            foreach($groups as $node=>$columns)foreach($columns as $column)if($prior[$column]!=$fresh[$column]){$rawChanged[]=$node;break;}}
        if($rawChanged){$fresh=$fresh??molavi_domain_reference_row($product,$ordinal,$timestamp);$merged=$prior;
            foreach($rawChanged as $node)foreach($groups[$node] as $column)$merged[$column]=$fresh[$column];$merged[20]=$timestamp;
            $priceChanged=false;foreach($groups['prices'] as $column)if($prior[$column]!=$merged[$column]){$priceChanged=true;break;}
            $changed[]=['source_product_id'=>$id,'old_product_code'=>(string)($prior[16]?:($oldCodes[$id]??'')),
                'product_code'=>$code,'nodes'=>$rawChanged,'price_changed'=>$priceChanged];$rows[]=$merged;
        }else $rows[]=$prior;
        $rows[count($rows)-1][15]=(string)$ordinal;
    }
    $removed=[];
    if($activeIds){foreach($activeIds as $id)if(!isset($seen[(string)$id]))$removed[]=['source_product_id'=>(string)$id,
        'product_code'=>(string)molavi_domain_py_value($oldById[(string)$id][16]??'',($oldCodes[(string)$id]??''))];}
    else{$currentCodes=[];foreach($products as $product)$currentCodes[molavi_domain_reference_code($product)]=true;
        foreach($oldCodes as $id=>$code)if(!isset($currentCodes[$code]))$removed[]=['source_product_id'=>(string)$id,'product_code'=>$code];
        if($oldRows&&!$oldCodes){$previous=[];foreach($oldRows as $row)if(is_array($row)&&(count($row)===21||count($row)===26))$previous[(string)molavi_domain_py_value($row[16])]=true;
            foreach($previous as $code=>$_)if(!isset($currentCodes[$code]))$removed[]=['source_product_id'=>'','product_code'=>(string)$code];}}
    $reconcile=[];foreach($changed as $item)if($item['price_changed']||in_array('sku',$item['nodes'],true))
        foreach([$item['old_product_code'],$item['product_code']] as $code)if($code!=='')$reconcile[$code]=true;
    foreach($removed as $item)if($item['product_code']!=='')$reconcile[$item['product_code']]=true;
    $reconcile=array_map('strval',array_keys($reconcile));sort($reconcile,SORT_STRING);
    $upgrade=(bool)($active&&($active['source_type']??null)==='woocommerce_store_api'&&($activeIds===null||$legacyUpgraded));
    $reordered=$activeIds!==null&&array_map('strval',$activeIds)!==$sourceIds;
    $summary=['added_count'=>count($added),'removed_count'=>count($removed),'updated_count'=>count($changed),
        'added'=>$added,'removed'=>$removed,'changed'=>$changed,'reconcile_reference_ids'=>$reconcile,
        'metadata_upgrade'=>$upgrade,'order_changed'=>$reordered,'has_changes'=>(bool)($added||$removed||$changed||$upgrade||$reordered)];
    unset($candidate['_source_products']);$candidate['rows']=$rows;$candidate['row_count']=count($rows);$candidate['source_total']=count($rows);
    $candidate['last_source_row_number']=count($rows);$candidate['coverage_verified']=true;$candidate['source_product_ids']=$sourceIds;
    $candidate['change_summary']=$summary;$candidate['previous_snapshot_id']=$active['snapshot_id']??null;
    $candidate['automatic_update']=(bool)($active&&($active['source_type']??null)==='woocommerce_store_api'&&$summary['has_changes']);
    $candidate['requires_review']=!$candidate['automatic_update'];return [$candidate,$products];
}
function molavi_domain_reference_source_check(SQLite3 $db,string $id,string $checkedAt): void {
    molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_source_check VALUES (1,?,?)',[$id,$checkedAt]);
    molavi_domain_sql($db,"INSERT OR IGNORE INTO reference_retirements SELECT event_id,?,? FROM reference_events WHERE snapshot_id=? AND reason IN ('stale','refresh_failed')",[$id,$checkedAt,$id]);
}
function molavi_domain_reference_stage(array $candidate): array {
    [$candidate,$products]=molavi_domain_reference_incremental($candidate);
    if(($candidate['status']??null)!=='staged'||($candidate['headers']??null)!==molavi_domain_reference_headers()
        ||!molavi_domain_reference_coverage($candidate))molavi_domain_fail('extraction_coverage_incomplete',400);
    $timestamp=molavi_domain_timestamp($candidate['extracted_at']??null);if($timestamp===null||$timestamp>microtime(true))molavi_domain_fail('invalid_extraction_timestamp',400);
    foreach($candidate['rows'] as $row){if(!is_array($row)||count($row)!==26)molavi_domain_fail('invalid_row_shape',400);
        foreach([0,1,2] as $i)if(!is_string($row[$i])||trim($row[$i])==='')molavi_domain_fail('invalid_row_identity',400);
        if(array_slice($row,17,3)!==['TOMAN','rezvanpakhsh_woocommerce_store_api',molavi_domain_reference_source()])molavi_domain_fail('invalid_row_provenance',400);
        $rowTime=molavi_domain_timestamp($row[20]);if($rowTime===null||$rowTime>$timestamp)molavi_domain_fail('invalid_row_timestamp',400);
        foreach([3,4,5,6,10,21,22,23,24] as $i){$value=$row[$i];if($value===''&&$i!==3&&$i!==21)continue;
            if((!is_int($value)&&!is_float($value))||!is_finite((float)$value)||$value<0)molavi_domain_fail('invalid_numeric_column',400);}
        if(!is_string($row[25]))molavi_domain_fail('invalid_anomaly_flag',400);
        if($row[3]<=0||$row[21]<=0)molavi_domain_fail('invalid_package_price',400);
    }
    $payload=molavi_domain_json($candidate,true);$id=hash('sha256',$payload);
    return molavi_domain_tx('reference',static function(SQLite3 $db)use($candidate,$products,$payload,$id):array {
        $active=molavi_domain_row($db,'SELECT snapshot_id FROM reference_active WHERE singleton=1')['snapshot_id']??null;
        if($products!==null&&$active!==($candidate['previous_snapshot_id']??null))molavi_domain_fail('reference_active_changed');
        $summary=$candidate['change_summary']??[];
        if($products!==null&&$active&&!($summary['has_changes']??false)){
            molavi_domain_sql($db,'DELETE FROM reference_source_products');
            foreach($products as $product)molavi_domain_sql($db,'INSERT INTO reference_source_products VALUES (?,?,?)',
                [(string)$product['id'],molavi_domain_reference_code($product),molavi_domain_json($product,true)]);
            molavi_domain_reference_source_check($db,$active,$candidate['extracted_at']);
            return ['state'=>'unchanged','snapshot_id'=>$active,'change_summary'=>$summary,'row_count'=>count($candidate['rows'])];
        }
        molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)',[$id,$payload]);
        if($products!==null){molavi_domain_sql($db,'DELETE FROM reference_snapshot_source_products WHERE snapshot_id=?',[$id]);
            foreach($products as $product)molavi_domain_sql($db,'INSERT INTO reference_snapshot_source_products VALUES (?,?,?,?)',
                [$id,(string)$product['id'],molavi_domain_reference_code($product),molavi_domain_json($product,true)]);}
        return ['state'=>'staged','snapshot_id'=>$id,'change_summary'=>$summary,'row_count'=>count($candidate['rows']),
            'automatic_update'=>(bool)($candidate['automatic_update']??false)];
    });
}
function molavi_domain_reference_review(string $id,string $actor,string $note): void {
    if(!molavi_domain_owner_actor($actor))molavi_domain_fail('owner_required',403);
    if(trim($note)==='')molavi_domain_fail('review_note_required',400);
    molavi_domain_tx('reference',static function(SQLite3 $db)use($id,$actor,$note):void {
        $row=molavi_domain_row($db,'SELECT payload,reviewed_by,review_note FROM reference_snapshots WHERE snapshot_id=?',[$id]);
        if(!$row||!molavi_domain_reference_coverage(molavi_domain_decode($row['payload'])))molavi_domain_fail('snapshot_incomplete');
        if($row['reviewed_by']){if($row['reviewed_by']===$actor&&$row['review_note']===trim($note))return;molavi_domain_fail('snapshot_already_reviewed');}
        molavi_domain_sql($db,'UPDATE reference_snapshots SET reviewed_by=?,review_note=?,reviewed_at=? WHERE snapshot_id=? AND reviewed_by IS NULL',[$actor,trim($note),molavi_domain_now(),$id]);
    });
}
function molavi_domain_reference_activate_locked(string $id,?string $expected,string $actor,bool $automatic=false): void {
    if(!$automatic&&!molavi_domain_owner_actor($actor))molavi_domain_fail('owner_required',403);
    molavi_domain_tx('reference',static function(SQLite3 $db)use($id,$expected,$actor,$automatic):void {
        $previous=molavi_domain_row($db,'SELECT snapshot_id FROM reference_active WHERE singleton=1')['snapshot_id']??null;
        if($previous!==$expected)molavi_domain_fail('active_snapshot_changed');
        $row=molavi_domain_row($db,'SELECT * FROM reference_snapshots WHERE snapshot_id=?',[$id]);
        if(!$row||(!$automatic&&!$row['reviewed_by']))molavi_domain_fail('snapshot_changed_or_unreviewed');
        $candidate=molavi_domain_decode($row['payload']);
        if($automatic&&(($candidate['automatic_update']??null)!==true||($candidate['source_type']??null)!=='woocommerce_store_api'
            ||empty($candidate['change_summary']['has_changes'])))molavi_domain_fail('incremental_snapshot_ineligible');
        if(!molavi_domain_reference_coverage($candidate))molavi_domain_fail('snapshot_incomplete');
        if($previous!==$id){
            if(!empty($candidate['source_product_ids'])){
                $count=molavi_domain_row($db,'SELECT COUNT(*) AS n FROM reference_snapshot_source_products WHERE snapshot_id=?',[$id])['n'];
                if($count!==count($candidate['source_product_ids']))molavi_domain_fail('raw_source_records_incomplete');
            }
            if(molavi_domain_row($db,'SELECT 1 FROM reference_activations WHERE snapshot_id=?',[$id]))molavi_domain_fail('snapshot_reactivation_requires_recovery');
            if($previous){$old=molavi_domain_decode(molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$previous])['payload']);
                if(molavi_domain_timestamp($candidate['extracted_at'])<=molavi_domain_timestamp($old['extracted_at']))molavi_domain_fail('replacement_snapshot_must_be_newer');}
            $now=molavi_domain_now();molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_active VALUES (1,?)',[$id]);
            molavi_domain_sql($db,'INSERT INTO reference_activations VALUES (NULL,?,?,?,?)',[$id,$previous,$actor,$now]);
            molavi_domain_sql($db,'INSERT OR IGNORE INTO reference_retirements SELECT event_id,?,? FROM reference_events WHERE snapshot_id IS ?',[$id,$now,$previous]);
        }
        $raw=molavi_domain_rows($db,'SELECT source_product_id,product_code,payload FROM reference_snapshot_source_products WHERE snapshot_id=?',[$id]);
        if($raw){molavi_domain_sql($db,'DELETE FROM reference_source_products');foreach($raw as $source)molavi_domain_sql($db,'INSERT INTO reference_source_products VALUES (?,?,?)',array_values($source));
            molavi_domain_sql($db,'DELETE FROM reference_snapshot_source_products WHERE snapshot_id=?',[$id]);}
        if(!empty($candidate['source_product_ids']))molavi_domain_reference_source_check($db,$id,$candidate['extracted_at']);
    });
}
function molavi_domain_reference_activate(string $id,?string $expected,string $actor,bool $automatic=false): void {
    molavi_domain_lock('activation',static fn()=>molavi_domain_reference_activate_locked($id,$expected,$actor,$automatic),'reference');
}
