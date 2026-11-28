<?php
/** Plugin Name: Molavi Native Supplier Price Verification */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_verification_receipt(string $command,string $product,string $revision,?string $checkRef=null): ?array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db)use($command,$product,$revision,$checkRef):?array {
        $row=molavi_domain_row($db,'SELECT * FROM supplier_price_verification_commands WHERE command_id=?',[$command]);
        if(!$row)return null;
        if($row['offer_id']!==$product||$row['source_revision']!==$revision||($checkRef!==null&&!str_contains($row['note'],' · '.$checkRef.' · ')))molavi_domain_fail('verification_rejected');
        return ['verified_at'=>$row['verified_at'],'idempotent'=>true];
    });
}
function molavi_domain_verify_price(string $product,string $revision,string $actor,string $note,string $now,?string $command=null,string $method='manual',?string $checkRef=null): array {
    return molavi_domain_tx('pipeline', static fn(SQLite3 $db) => molavi_domain_verify_price_in_transaction(
        $db, $product, $revision, $actor, $note, $now, $command, $method, $checkRef));
}
function molavi_domain_verify_price_in_transaction(SQLite3 $db, string $product, string $revision, string $actor,
    string $note, string $now, ?string $command, string $method, ?string $checkRef = null): array {
    if($product===''||$revision===''||$actor===''||trim($note)===''||molavi_domain_strlen($note)>1000)molavi_domain_fail('verification_rejected');
    if(!in_array($method,['manual','market','auto'],true))molavi_domain_fail('verification_rejected');
    if($command!==null&&!preg_match('/^[a-f0-9]{64}$/D',$command))molavi_domain_fail('verification_rejected');
    $stamp=molavi_domain_timestamp($now);if($stamp===null)molavi_domain_fail('verification_rejected');
    if($command!==null){$prior=molavi_domain_row($db,'SELECT * FROM supplier_price_verification_commands WHERE command_id=?',[$command]);
        if($prior){if($prior['offer_id']!==$product||$prior['source_revision']!==$revision||($checkRef!==null&&!str_contains($prior['note'],' · '.$checkRef.' · ')))molavi_domain_fail('verification_rejected');
            return ['verified_at'=>$prior['verified_at'],'idempotent'=>true];}}
    if($checkRef!==null){
        $link=molavi_domain_supplier_link_check_row($db,$product);
        if(!$link||$link['lease']!==null||$link['source_revision']!==$revision||$link['outcome']!=='unchanged'||$link['checked_at']===null
            ||molavi_domain_supplier_link_check_ref($product,(int)$link['checked_at'])!==$checkRef)molavi_domain_fail('link_check_mismatch',409);
        $note=molavi_domain_supplier_link_check_confirm_note($product,$link['url'],(int)$link['checked_at']);
    }
    $old=molavi_domain_row($db,'SELECT * FROM supplier_price_evidence WHERE offer_id=?',[$product]);
    if($old&&$stamp<(molavi_domain_timestamp($old['verified_at'])??INF))molavi_domain_fail('verification_rejected');
    molavi_domain_sql($db,"INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?) ON CONFLICT(offer_id) DO UPDATE SET source_revision=excluded.source_revision,verified_at=excluded.verified_at,method=?,verified_by=excluded.verified_by",[$product,$revision,$now,$method,$actor,$method]);
    molavi_domain_sql($db,"INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,?,?,?)",[$product,$revision,$now,$method,$actor,trim($note)]);
    if($command!==null)molavi_domain_sql($db,'INSERT INTO supplier_price_verification_commands (command_id,offer_id,source_revision,verified_at,verified_by,note) VALUES (?,?,?,?,?,?)',[$command,$product,$revision,$now,$actor,trim($note)]);
    if ($checkRef!==null) molavi_domain_supplier_link_check_confirm_receipt($db,$product,$checkRef,$now,$actor,(int)$stamp);
    return ['verified_at'=>$now,'idempotent'=>false];
}
// G1 — register a supplier price from the message for a legacy row whose
// source revision is empty (the verify button is disabled and there is no
// open AI case to edit through). The owner types the price (and, when known,
// the source post link). The price is written to the Products sheet — the
// system of record — so the live revision becomes computable; the evidence
// is then written with method 'manual' and the same publish job is queued
// as the manual verify. The sheet write is confirmed by re-reading, exactly
// like the sheet-conflict reconciliation. Retrying is safe: the same price
// written twice is a no-op (Previous is only set when the price changes),
// and the evidence write is idempotent on (product, revision).
function molavi_domain_register_price_from_message(array $body,string $actor): array {
    $keys=array_keys($body);sort($keys);
    foreach($keys as $key)if(!in_array($key,['action','note','product_id','sale_price','source_url'],true))molavi_domain_fail('invalid_body',400);
    if(!in_array('product_id',$keys,true)||!in_array('sale_price',$keys,true))molavi_domain_fail('invalid_body',400);
    $id=(string)($body['product_id']??'');
    if($id===''||molavi_domain_strlen($id)>200)molavi_domain_fail('invalid_product_id',400);
    $raw=$body['sale_price']??null;
    if(is_bool($raw)||!is_scalar($raw))molavi_domain_fail('invalid_sale_price',400);
    $digits=str_replace([',','٬','_',' '],'',trim(molavi_domain_digits($raw)));
    if(!preg_match('/^\+?\d+$/D',$digits))molavi_domain_fail('invalid_sale_price',400);
    $price=(int)$digits;
    if($price<1||$price>999999999999)molavi_domain_fail('invalid_sale_price',400);
    $sourceUrl=trim((string)($body['source_url']??''));
    if($sourceUrl!==''&&(molavi_domain_strlen($sourceUrl)>500||!str_starts_with($sourceUrl,'https://')))molavi_domain_fail('invalid_source_url',400);
    $note=trim((string)($body['note']??''));
    if(molavi_domain_strlen($note)>1000)molavi_domain_fail('invalid_note',400);
    $now=molavi_domain_now();
    $tables=molavi_domain_sheets(['Products'],true);$rows=$tables['Products']??[];
    $headers=array_map('strval',$rows[0]??[]);
    if(!in_array('Sale Price',$headers,true)||!in_array('Product ID',$headers,true))molavi_domain_fail('sheet_header_missing',409);
    $records=molavi_domain_records($rows);
    $index=null;
    foreach($records as $i=>$record)if((string)molavi_domain_py_value($record['Product ID']??null)===$id){$index=$i;break;}
    if($index===null)molavi_domain_fail('product_not_found',404);
    $record=$records[$index];
    $values=['Sale Price'=>(string)$price];
    if((string)molavi_domain_py_value($record['Sale Price']??null)!==(string)$price){
        $values['Previous Sale Price']=(string)molavi_domain_py_value($record['Sale Price']??null);
        $values['Price Changed At']=$now;
    }
    if($sourceUrl!==''&&in_array('Source Post URL',$headers,true))$values['Source Post URL']=$sourceUrl;
    foreach(array_keys($values) as $field)if(!in_array($field,$headers,true))molavi_domain_fail('sheet_header_missing',409);
    molavi_domain_sheet_fields('Products',$index+2,$headers,$values);
    $confirmed=molavi_domain_records(molavi_domain_sheets(['Products'],true)['Products']);
    $updated=$confirmed[$index]??null;
    if($updated===null)molavi_domain_fail('sheet_write_unconfirmed',503);
    foreach($values as $field=>$value){
        $actual=(string)molavi_domain_py_value($updated[$field]??null);
        $same=function_exists('molavi_domain_cells_equal')
            ?molavi_domain_cells_equal($field,$actual,$value):$actual===$value;
        if(!$same)molavi_domain_fail('sheet_write_unconfirmed',503);
    }
    $revision=molavi_domain_source_revision($updated);
    if($revision==='')molavi_domain_fail('price_source_missing',400);
    $evidenceNote='مالک قیمت را از پیام ثبت کرد؛ منبع: '.($sourceUrl!==''?$sourceUrl:'بدون پیوند');
    if($note!==''&&molavi_domain_strlen($evidenceNote)+molavi_domain_strlen($note)+2<=1000)$evidenceNote.='؛ '.$note;
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$revision,$actor,$evidenceNote,$now,$price):array {
        $verified=molavi_domain_verify_price_in_transaction($db,$id,$revision,$actor,$evidenceNote,$now,null,'manual',null);
        $receipt=hash('sha256',$revision.'|'.$verified['verified_at']);
        $job=molavi_domain_queue_in_transaction($db,'publish','supplier-verification:'.$id.':'.$receipt,['product_id'=>$id,'source_revision'=>$revision]);
        return ['ok'=>true,'product_id'=>$id,'source_revision'=>$revision,'verified_at'=>$verified['verified_at'],
            'sale_price'=>$price,'publish_job'=>$job,'idempotent'=>$verified['idempotent']];
    });
}
function molavi_domain_supplier_prices_api(string $method,?array $body,string $actor): array {
    if($method==='GET'){$tables=molavi_domain_sheets(['Products'],true);$offers=molavi_domain_freshness(molavi_domain_records($tables['Products']));
        $offers=array_values(array_filter($offers,static fn($row)=>$row['state']!=='current'));
        // Attach the latest supplier link check per product (round eight):
        // the card shows whether the original link was re-read, with what
        // outcome, so the owner can confirm a no-change check from the card.
        if(function_exists('molavi_domain_supplier_link_check_public_row')){
            $checks=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,'SELECT * FROM supplier_link_checks'),null,'product_id'));
            foreach($offers as &$offer)$offer['link_check']=isset($checks[$offer['product_id']]) && $checks[$offer['product_id']]['source_revision']===$offer['source_revision']
                ?molavi_domain_supplier_link_check_public_row($checks[$offer['product_id']]):null;
            unset($offer);
        }
        // Attach the latest market-agreement check per product (round nine):
        // the card shows whether the supplier price was re-verified by
        // market agreement (evidence method 'market'), distinct from a
        // link-check confirmation (method 'manual').
        if(function_exists('molavi_domain_market_agreement_public_row')){
            $agreements=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,'SELECT * FROM market_agreement_checks'),null,'product_id'));
            foreach($offers as &$offer)$offer['market_agreement']=isset($agreements[$offer['product_id']])
                ?molavi_domain_market_agreement_public_row($agreements[$offer['product_id']]):null;
            unset($offer);
        }
        return ['ok'=>true,'offers'=>$offers];}
    $checkRef=$body['check_ref']??null;
    // G1 — register a supplier price from the message for a legacy row whose
    // source revision is empty (verify is disabled, no AI case to edit
    // through). Handled before the verify body check: it carries its own
    // body shape and writes the price to the sheet first.
    if(($body['action']??'')==='register_price_from_message')return molavi_domain_register_price_from_message($body,$actor);
    if (array_key_exists('check_ref',$body??[]) && (!is_string($checkRef)||$checkRef===''||strlen($checkRef)>300)) molavi_domain_fail('invalid_body',400);
    unset($body['check_ref']);
    $keys=array_keys($body??[]);sort($keys);if($keys!==['expected_revision','note','product_id']&&$keys!==['command_id','expected_revision','note','product_id'])molavi_domain_fail('invalid_body',400);
    $id=$body['product_id'];$revision=$body['expected_revision'];$note=$body['note'];$command=$body['command_id']??null;
    if(!is_string($id)||$id===''||molavi_domain_strlen($id)>200)molavi_domain_fail('invalid_product_id',400);
    if(!is_string($revision)||$revision==='')molavi_domain_fail('price_source_missing');
    if(molavi_domain_strlen($revision)>200)molavi_domain_fail('invalid_source_revision',400);
    if(!is_string($note)||trim($note)===''||molavi_domain_strlen($note)>1000)molavi_domain_fail('invalid_note',400);
    if($command!==null&&(!is_string($command)||!preg_match('/^[a-f0-9]{64}$/D',$command)))molavi_domain_fail('invalid_command_id',400);
    $verification=$command!==null?molavi_domain_verification_receipt($command,$id,$revision,$checkRef):null;
    if($verification===null){
        $tables=molavi_domain_sheets(['Products'],true);$offers=molavi_domain_freshness(molavi_domain_records($tables['Products']));$offer=null;
        foreach($offers as $row)if($row['product_id']===$id){$offer=$row;break;}
        if(!$offer||$offer['source_revision']!==$revision)molavi_domain_fail('offer_changed');
    }
    return molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($verification,$id,$revision,$actor,$note,$command,$checkRef): array {
        $verified = $verification ?? molavi_domain_verify_price_in_transaction($db, $id, $revision, $actor, $note,
            molavi_domain_now(), $command, 'manual', $checkRef);
        $receipt = hash('sha256', $revision.'|'.$verified['verified_at']);
        $job = molavi_domain_queue_in_transaction($db, 'publish', 'supplier-verification:'.$id.':'.$receipt,
            ['product_id'=>$id, 'source_revision'=>$revision]);
        $result = ['ok'=>true, 'product_id'=>$id, 'source_revision'=>$revision, 'verified_at'=>$verified['verified_at'],
            'publish_job'=>$job, 'idempotent'=>$verified['idempotent']];
        if ($command !== null) $result['command_id'] = $command;
        return $result;
    });
}
