<?php
/** Plugin Name: Molavi Native Supplier Price Verification */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_verification_receipt(string $command,string $product,string $revision): ?array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db)use($command,$product,$revision):?array {
        $row=molavi_domain_row($db,'SELECT * FROM supplier_price_verification_commands WHERE command_id=?',[$command]);
        if(!$row)return null;
        if($row['offer_id']!==$product||$row['source_revision']!==$revision)molavi_domain_fail('verification_rejected');
        return ['verified_at'=>$row['verified_at'],'idempotent'=>true];
    });
}
function molavi_domain_verify_price(string $product,string $revision,string $actor,string $note,string $now,?string $command=null): array {
    if($product===''||$revision===''||$actor===''||trim($note)===''||molavi_domain_strlen($note)>1000)molavi_domain_fail('verification_rejected');
    if($command!==null&&!preg_match('/^[a-f0-9]{64}$/D',$command))molavi_domain_fail('verification_rejected');
    $stamp=molavi_domain_timestamp($now);if($stamp===null)molavi_domain_fail('verification_rejected');
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($product,$revision,$actor,$note,$now,$command,$stamp):array {
        if($command!==null){$prior=molavi_domain_row($db,'SELECT * FROM supplier_price_verification_commands WHERE command_id=?',[$command]);
            if($prior){if($prior['offer_id']!==$product||$prior['source_revision']!==$revision)molavi_domain_fail('verification_rejected');
                return ['verified_at'=>$prior['verified_at'],'idempotent'=>true];}}
        $old=molavi_domain_row($db,'SELECT * FROM supplier_price_evidence WHERE offer_id=?',[$product]);
        if($old&&$stamp<(molavi_domain_timestamp($old['verified_at'])??INF))molavi_domain_fail('verification_rejected');
        molavi_domain_sql($db,"INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,'manual',?) ON CONFLICT(offer_id) DO UPDATE SET source_revision=excluded.source_revision,verified_at=excluded.verified_at,method='manual',verified_by=excluded.verified_by",[$product,$revision,$now,$actor]);
        molavi_domain_sql($db,"INSERT INTO supplier_price_evidence_history (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,'manual',?,?)",[$product,$revision,$now,$actor,trim($note)]);
        if($command!==null)molavi_domain_sql($db,'INSERT INTO supplier_price_verification_commands (command_id,offer_id,source_revision,verified_at,verified_by,note) VALUES (?,?,?,?,?,?)',[$command,$product,$revision,$now,$actor,trim($note)]);
        return ['verified_at'=>$now,'idempotent'=>false];
    });
}
function molavi_domain_supplier_prices_api(string $method,?array $body,string $actor): array {
    if($method==='GET'){$tables=molavi_domain_sheets(['Products'],true);$offers=molavi_domain_freshness(molavi_domain_records($tables['Products']));
        return ['ok'=>true,'offers'=>array_values(array_filter($offers,static fn($row)=>$row['state']!=='current'))];}
    $keys=array_keys($body??[]);sort($keys);if($keys!==['expected_revision','note','product_id']&&$keys!==['command_id','expected_revision','note','product_id'])molavi_domain_fail('invalid_body',400);
    $id=$body['product_id'];$revision=$body['expected_revision'];$note=$body['note'];$command=$body['command_id']??null;
    if(!is_string($id)||$id===''||molavi_domain_strlen($id)>200)molavi_domain_fail('invalid_product_id',400);
    if(!is_string($revision)||$revision==='')molavi_domain_fail('price_source_missing');
    if(molavi_domain_strlen($revision)>200)molavi_domain_fail('invalid_source_revision',400);
    if(!is_string($note)||trim($note)===''||molavi_domain_strlen($note)>1000)molavi_domain_fail('invalid_note',400);
    if($command!==null&&(!is_string($command)||!preg_match('/^[a-f0-9]{64}$/D',$command)))molavi_domain_fail('invalid_command_id',400);
    $verification=$command!==null?molavi_domain_verification_receipt($command,$id,$revision):null;
    if($verification===null){
        $tables=molavi_domain_sheets(['Products'],true);$offers=molavi_domain_freshness(molavi_domain_records($tables['Products']));$offer=null;
        foreach($offers as $row)if($row['product_id']===$id){$offer=$row;break;}
        if(!$offer||$offer['source_revision']!==$revision)molavi_domain_fail('offer_changed');
        $verification=molavi_domain_verify_price($id,$revision,$actor,$note,molavi_domain_now(),$command);
    }
    $job=molavi_domain_queue('publish','supplier-verification:'.$id.':'.$revision,['product_id'=>$id,'source_revision'=>$revision]);
    $result=['ok'=>true,'product_id'=>$id,'source_revision'=>$revision,'verified_at'=>$verification['verified_at'],
        'publish_job'=>$job,'idempotent'=>$verification['idempotent']];if($command!==null)$result['command_id']=$command;return $result;
}
