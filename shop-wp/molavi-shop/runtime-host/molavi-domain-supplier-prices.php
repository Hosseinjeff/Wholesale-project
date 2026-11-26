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
    if(!in_array($method,['manual','market'],true))molavi_domain_fail('verification_rejected');
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
