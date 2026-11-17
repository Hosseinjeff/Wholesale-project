<?php
/** Plugin Name: Molavi Native Sheet Conflict Review */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_sheet_provenance(): array {
    return ['Product ID','Channel ID','Channel','Channel Username','Original Message','Message Timestamp','Source Post URL',
        'Source Timestamp Basis','Forwarded By','Import Timestamp','Last Updated','Batch ID','Latest Offer ID','Offer Count',
        'Previous Sale Price','Previous Consumer Price','Price Changed At','Image File Unique ID','Reference Snapshot ID','Reference Match ID','Reference Decision ID'];
}
function molavi_domain_cell_text($value): string {
    if(is_bool($value))return $value?'True':'False';if(is_float($value))return molavi_domain_json($value);return (string)$value;
}
/** Decimal cell comparison must not collapse distinct large values into a float. */
function molavi_domain_normalize_cell(string $field,$value): array {
    if($value===null||$value==='')return ['empty',''];
    $text=['Product Name','Variation Type','Price Type','Currency','Packaging','Category','Description','Stock Status','Location','Contact Info',
        'Original Message','Channel','Channel Username','Forwarded By','Image File ID','Image File Unique ID','Status'];
    if(in_array($field,$text,true))return ['text',trim(molavi_domain_cell_text($value))];
    $numeric=in_array($field,['Sale Price','Actual Price','Price','Consumer Price','Double Pack Price','Double Pack Consumer Price','Min Qty','Offer Count','Confidence','Extraction Confidence Score'],true);
    if(is_bool($value)){if($numeric)throw new InvalidArgumentException('invalid numeric cell');return ['decimal',$value?'1e0':'0'];}
    if(!is_scalar($value))throw new InvalidArgumentException('invalid cell');
    $raw=str_replace([',','٬','_'],'',molavi_domain_digits(trim(molavi_domain_cell_text($value))));
    if(preg_match('/^[+-]?(?:s?nan\d*|inf(?:inity)?)$/iD',$raw))throw new InvalidArgumentException('non-finite numeric cell');
    if(!preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))(?:[eE]([+-]?\d+))?$/D',$raw,$m)){
        if($numeric)throw new InvalidArgumentException('invalid numeric cell');return ['text',trim(molavi_domain_cell_text($value))];}
    $whole=($m[2]??'')!==''?$m[2]:'0';$fraction=($m[4]??'')!==''?$m[4]:($m[3]??'');$exp=(int)($m[5]??0);
    if(abs($exp)>10000)throw new InvalidArgumentException('cell exponent out of range');
    $digits=$whole.$fraction;$leading=strlen($digits)-strlen(ltrim($digits,'0'));$point=strlen($whole)+$exp-$leading;$digits=substr($digits,$leading);
    if($digits==='')return ['decimal','0'];
    // Decimal.normalize() uses the existing Python context (28 significant digits, half even).
    if(strlen($digits)>28){$tail=substr($digits,28);$head=substr($digits,0,28);$up=$tail[0]>'5'||($tail[0]==='5'&&(trim(substr($tail,1),'0')!==''||((int)$head[27])%2));
        if($up){for($i=27;$i>=0&&$head[$i]==='9';$i--)$head[$i]='0';if($i>=0)$head[$i]=(string)((int)$head[$i]+1);else{$head='1'.$head;$point++;}}
        $digits=$head;}
    $digits=rtrim($digits,'0');return ['decimal',($m[1]==='-'?'-':'').$digits.'e'.($point-1)];
}
function molavi_domain_cells_equal(string $field,$left,$right): bool {
    try{return molavi_domain_normalize_cell($field,$left)===molavi_domain_normalize_cell($field,$right);}
    catch(InvalidArgumentException $e){return gettype($left)===gettype($right)&&$left===$right;}
}
function molavi_domain_reconcile_cell(string $field,$base,$server,$sheet): string {
    try{$b=molavi_domain_normalize_cell($field,$base);$a=molavi_domain_normalize_cell($field,$server);$c=molavi_domain_normalize_cell($field,$sheet);}
    catch(InvalidArgumentException $e){return 'needs_review';}
    return $a===$c?'in_sync':($a===$b?'sheet_to_server':($c===$b?'server_to_sheet':'conflict'));
}
function molavi_domain_sheet_conflict_decode(array $row): array {
    foreach(['base','server','sheet'] as $key){$row[$key]=molavi_domain_decode($row[$key.'_value']);unset($row[$key.'_value']);}return $row;
}
function molavi_domain_sheet_conflict(string $id): ?array {
    $row=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT * FROM sheet_sync_conflicts WHERE conflict_id=?',[$id]));
    return $row?molavi_domain_sheet_conflict_decode($row):null;
}
function molavi_domain_record_sheet_conflict(array $identity,$base,$server,$sheet): string {
    $id='sheet:'.molavi_domain_hash([$identity['product_id'],$identity['field'],$identity['source_revision'],$base,$server,$sheet]);
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
        "INSERT OR IGNORE INTO sheet_sync_conflicts (conflict_id,product_id,product_name,channel,field,source_revision,base_value,server_value,sheet_value,state,created_at) VALUES (?,?,?,?,?,?,?,?,?,'open',?)",
        [$id,$identity['product_id'],$identity['product_name'],$identity['channel'],$identity['field'],$identity['source_revision'],molavi_domain_json($base,true),molavi_domain_json($server,true),molavi_domain_json($sheet,true),microtime(true)]));return $id;
}
function molavi_domain_find_product(array $records,string $id): ?int {
    foreach($records as $index=>$record)if((string)molavi_domain_py_value($record['Product ID']??null)===$id)return $index;return null;
}
/** Reconcile an existing product after its source changes, preserving concurrent Sheet edits. */
function molavi_domain_reconcile_product(array $existing,array $base,array $server,array $identity): void {
    $targets=[];$active=[];$provenance=molavi_domain_sheet_provenance();
    foreach($server as $field=>$value){$sheet=$existing[$field]??'';$b=$base[$field]??'';
        if(in_array($field,$provenance,true)){$target=$field==='Product ID'||($field==='Import Timestamp'&&$sheet!==''&&$sheet!==null)?$sheet:$value;
            if($target!==$sheet)$targets[$field]=$target;continue;}
        $action=molavi_domain_reconcile_cell($field,$b,$value,$sheet);
        if(in_array($action,['in_sync','server_to_sheet'],true)){if($action==='server_to_sheet'&&$value!==$sheet)$targets[$field]=$value;continue;}
        if($action==='sheet_to_server')continue;
        $id=molavi_domain_record_sheet_conflict($identity+['field'=>$field],$b,$value,$sheet);$active[]=$id;$saved=molavi_domain_sheet_conflict($id);
        if($saved&&$saved['state']==='resolved'&&$saved['choice']==='server'&&$value!==$sheet)$targets[$field]=$value;
    }
    $rows=molavi_domain_sheets(['Products'],true)['Products'];$headers=array_map('strval',$rows[0]??[]);$records=molavi_domain_records($rows);
    $index=molavi_domain_find_product($records,$identity['product_id']);
    if($index===null){$prior=(string)molavi_domain_py_value($existing['Latest Offer ID']??null);
        if($prior!=='')foreach($records as $i=>$record)if((string)molavi_domain_py_value($record['Latest Offer ID']??null)===$prior){$index=$i;break;}}
    if($index===null){$matches=[];$key=molavi_domain_product_identity($server);
        foreach($records as $i=>$record)if(molavi_domain_product_identity($record)===$key)$matches[]=$i;if(count($matches)===1)$index=$matches[0];}
    if($index===null)return;$live=$records[$index];$safe=[];
    foreach($targets as $field=>$value){if(!in_array($field,$headers,true))continue;$current=$live[$field]??'';
        if(!molavi_domain_cells_equal($field,$current,$existing[$field]??'')&&!molavi_domain_cells_equal($field,$current,$value)){
            $active[]=molavi_domain_record_sheet_conflict($identity+['field'=>$field],$base[$field]??'',$value,$current);continue;}$safe[$field]=$value;}
    if($safe){molavi_domain_sheet_fields('Products',$index+2,$headers,$safe);$confirmed=molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products']);
        foreach($safe as $field=>$value){$actual=$confirmed[$index][$field]??'';if(!molavi_domain_cells_equal($field,$actual,$value))
            $active[]=molavi_domain_record_sheet_conflict($identity+['field'=>$field],$base[$field]??'',$value,$actual);}}
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($identity,$active){$rows=molavi_domain_rows($db,"SELECT conflict_id FROM sheet_sync_conflicts WHERE product_id=? AND channel=? AND state='open'",[$identity['product_id'],$identity['channel']]);
        foreach($rows as $row)if(!in_array($row['conflict_id'],$active,true))molavi_domain_sql($db,
            "UPDATE sheet_sync_conflicts SET state='superseded',resolved_at=?,choice='superseded',actor_id='system',note='Values or source revision changed during reconciliation' WHERE conflict_id=? AND state='open'",[microtime(true),$row['conflict_id']]);});
}
function molavi_domain_refresh_conflict_product(array $conflict,array $existing,array $headers,array $matching,?string $clock=null): void {
    usort($matching,static function($a,$b){$key=static function($offer){$import=molavi_domain_timestamp($offer['Import Timestamp']??null)??-62135596800.0;
        $basis=trim((string)molavi_domain_py_value($offer['Source Timestamp Basis']??null));$source=$basis!==''&&!in_array($basis,['supplier_channel_update','supplier_channel_forward'],true)?null:molavi_domain_timestamp($offer['Message Timestamp']??null);
        return [$source??$import,$import];};return $key($a)<=>$key($b);});
    $latest=$matching[count($matching)-1];$product=molavi_domain_product_from_offers($matching);$changed=false;
    foreach([['previous_sale_price','sale_price',['Sale Price'],'Previous Sale Price'],['previous_consumer_price','consumer_price',['Consumer Price','Actual Price'],'Previous Consumer Price']] as [$previous,$current,$columns,$history]){
        if(($product[$previous]??'')!==''&&($product[$previous]??null)!==null)continue;$new=molavi_domain_positive_price($product[$current]??null);
        if($current==='consumer_price'&&!$new)$new=molavi_domain_positive_price($product['actual_price']??null);$old=0;foreach($columns as $column)if($old=molavi_domain_positive_price($existing[$column]??null))break;
        if(!$new||!$old)continue;if($new!==$old){$product[$previous]=$old;$changed=true;}elseif($kept=molavi_domain_positive_price($existing[$history]??null))$product[$previous]=$kept;
    }
    if($changed&&!molavi_domain_py_value($product['price_changed_at']??null))$product['price_changed_at']=molavi_domain_py_value($latest['Import Timestamp']??null);
    elseif(!molavi_domain_py_value($product['price_changed_at']??null)&&molavi_domain_py_value($existing['Price Changed At']??null))$product['price_changed_at']=$existing['Price Changed At'];
    $active=molavi_domain_reference_active();$activeId=(string)($active['snapshot_id']??'');
    if((string)molavi_domain_py_value($latest['Reference Snapshot ID']??null)!==$activeId&&in_array($existing['Reference Approval State']??null,['approved_within_margin','outside_margin','reference_missing','price_unavailable'],true)){
        foreach(['Status'=>'status','Reference Snapshot ID'=>'reference_snapshot_id','Reference Match ID'=>'reference_match_id','Reference Price Role'=>'reference_price_role',
            'Reference Decision ID'=>'reference_decision_id','Reference Approval State'=>'reference_approval_state','Reference Price Change Percent'=>'reference_price_change_percent',
            'Reference Price Checked At'=>'reference_price_checked_at','Reference Review Reason'=>'reference_review_reason'] as $field=>$key)$product[$key]=$existing[$field]??'';}
    $product=molavi_domain_product_reference_review($product,$latest,$activeId,$clock);$row=molavi_domain_product_row($headers,$product,molavi_domain_message_from_offer($latest),$clock);
    $server=array_combine($headers,$row);$server['Last Updated']=molavi_domain_py_value($latest['Import Timestamp']??null);$base=$existing;
    if(count($matching)>1){$prior=array_slice($matching,0,-1);$base=array_combine($headers,molavi_domain_product_row($headers,molavi_domain_product_from_offers($prior),molavi_domain_message_from_offer(end($prior)),$clock));}
    molavi_domain_reconcile_product($existing,$base,$server,['product_id'=>$conflict['product_id'],'product_name'=>$conflict['product_name'],'channel'=>$conflict['channel'],'source_revision'=>(string)molavi_domain_py_value($latest['Offer ID']??null)]);
}
function molavi_domain_sheet_conflicts_api(string $method,?array $body,string $actor): array {
    if($method==='GET'){$rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,"SELECT * FROM sheet_sync_conflicts WHERE state='open' ORDER BY created_at,conflict_id"));$conflicts=[];
        foreach($rows as $row){$row=molavi_domain_sheet_conflict_decode($row);$row['sheet_preserved']=in_array($row['field'],['Product ID','Import Timestamp'],true);
            $row['server_owned']=in_array($row['field'],molavi_domain_sheet_provenance(),true)&&!$row['sheet_preserved'];$conflicts[]=$row;}return ['ok'=>true,'conflicts'=>$conflicts];}
    $keys=array_keys($body??[]);sort($keys);if($keys!==['choice','conflict_id','note']&&$keys!==['choice','conflict_id','expected_revision','note'])molavi_domain_fail('invalid_body',400);
    $id=$body['conflict_id'];$choice=$body['choice'];$note=$body['note'];$revision=$body['expected_revision']??null;
    if(!is_string($id)||!str_starts_with($id,'sheet:')||molavi_domain_strlen($id)>80||!in_array($choice,['server','sheet','preserve'],true)||!is_string($note)||molavi_domain_strlen($note)>1000
        ||($revision!==null&&(!is_string($revision)||!preg_match('/^[A-Za-z0-9:_-]{1,191}$/D',$revision))))molavi_domain_fail('invalid_body',400);
    $note=trim($note)?:['server'=>'Owner selected the current server-derived value under the server-first conflict policy.',
        'sheet'=>'Owner confirmed that the current Sheet cell was manually edited.','preserve'=>'Preserve the existing canonical Sheet value for this immutable identity field.'][$choice];
    return molavi_domain_lock('publication',static function()use($id,$choice,$note,$revision,$actor):array {
        $conflict=molavi_domain_sheet_conflict($id);if(!$conflict||($revision!==null&&$revision!==(string)($conflict['source_revision']??'')))molavi_domain_fail('conflict_changed');
        $field=$conflict['field'];$preserved=in_array($field,['Product ID','Import Timestamp'],true);$owned=in_array($field,molavi_domain_sheet_provenance(),true)&&!$preserved;
        if(($owned&&$choice!=='server')||($preserved&&$choice!=='preserve')||($choice==='preserve'&&!$preserved))molavi_domain_fail('field_read_only');
        $result=['ok'=>true,'conflict_id'=>$id,'choice'=>$choice,'publish_job'=>null,'server_owned'=>$owned,'sheet_preserved'=>$preserved];
        if($conflict['state']==='resolved'&&$conflict['choice']===$choice){if(!$owned&&!$preserved)$result['publish_job']=molavi_domain_queue('publish','sheet-conflict:'.$id,['conflict_id'=>$id,'product_id'=>$conflict['product_id']]);return $result+['idempotent'=>true];}
        if($conflict['state']!=='open')molavi_domain_fail('conflict_changed');
        $products=molavi_domain_sheets(['Products'],true)['Products'];if(!$products)molavi_domain_fail('sheet_unavailable',503);
        $headers=array_map('strval',$products[0]);$records=molavi_domain_records($products);$index=molavi_domain_find_product($records,$conflict['product_id']);
        if($index===null||!in_array($field,$headers,true))molavi_domain_fail('row_changed');$product=$records[$index];$sheet=$product[$field]??'';
        $same=molavi_domain_cells_equal($field,$sheet,$conflict['sheet']);$already=molavi_domain_cells_equal($field,$sheet,$conflict['server']);
        $offers=molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']);$linked=(string)molavi_domain_py_value($product['Latest Offer ID']??null);$source=null;
        if($linked!=='')foreach($offers as $offer)if((string)molavi_domain_py_value($offer['Offer ID']??null)===$linked){$source=$offer;break;}
        $matching=molavi_domain_offers_for_product($offers,$conflict['product_name'],$conflict['channel'],molavi_domain_offer_identity_fields($source??$product));
        if(!$matching)molavi_domain_fail('source_changed');$latest=$matching[count($matching)-1];
        if((string)molavi_domain_py_value($latest['Offer ID']??null)!==$conflict['source_revision']){
            molavi_domain_refresh_conflict_product($conflict,$product,$headers,$matching);molavi_domain_fail('source_changed',409,['refreshed'=>true]);}
        $derived=molavi_domain_product_reference_review(molavi_domain_product_from_offers($matching),$latest);
        $server=array_combine($headers,molavi_domain_product_row($headers,$derived,molavi_domain_message_from_offer($latest)))[$field]??'';
        if($field==='Last Updated')$server=(string)molavi_domain_py_value($latest['Import Timestamp']??null);
        if(!$preserved&&!molavi_domain_cells_equal($field,$server,$conflict['server']))molavi_domain_fail('server_value_changed');
        if(($choice==='server'&&!($same||$already))||($choice!=='server'&&!$same))molavi_domain_fail('sheet_value_changed');
        $rows=molavi_domain_sheets(['Products'],true)['Products'];$headers=array_map('strval',$rows[0]??[]);$records=molavi_domain_records($rows);$index=molavi_domain_find_product($records,$conflict['product_id']);
        if($index===null||!in_array($field,$headers,true))molavi_domain_fail('row_changed');$pre=$records[$index][$field]??'';
        $same=molavi_domain_cells_equal($field,$pre,$conflict['sheet']);$already=molavi_domain_cells_equal($field,$pre,$server);
        if(($choice==='server'&&!($same||$already))||($choice!=='server'&&!$same))molavi_domain_fail('sheet_value_changed');
        if($choice==='server'&&!$already){molavi_domain_sheet_fields('Products',$index+2,$headers,[$field=>$server]);$confirmed=molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products']);
            $check=molavi_domain_find_product($confirmed,$conflict['product_id']);if($check===null||!molavi_domain_cells_equal($field,$confirmed[$check][$field]??'',$server))molavi_domain_fail('write_unconfirmed');}
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$choice,$actor,$note){$updated=molavi_domain_sql($db,
            "UPDATE sheet_sync_conflicts SET state='resolved',resolved_at=?,choice=?,actor_id=?,note=? WHERE conflict_id=? AND state='open'",[microtime(true),$choice,$actor,$note,$id]);
            if($updated['changes']!==1)molavi_domain_fail('conflict_or_invalid');});
        if(!$owned&&!$preserved)$result['publish_job']=molavi_domain_queue('publish','sheet-conflict:'.$id,['conflict_id'=>$id,'product_id'=>$conflict['product_id']]);return $result;
    });
}
