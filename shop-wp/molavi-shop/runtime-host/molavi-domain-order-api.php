<?php
/** Plugin Name: Molavi Native Owner Order API */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_order_workflow_public(?array $workflow): ?array {
    if($workflow!==null)foreach(['procurement','resolutions'] as $key)$workflow[$key]=(object)($workflow[$key]??[]);return $workflow;
}
function molavi_domain_paid_orders_api(string $method,array $body,string $actor,?string $clock=null): array {
    $today=$clock??molavi_domain_today();
    if($method==='GET'){
        $plans=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,"SELECT order_id,result,source_order FROM paid_order_plans WHERE result IS NOT NULL AND state IN ('needs_owner_review','procurement_proposed','reserved','released') ORDER BY updated_at,order_id"));
        if(!$plans)return ['ok'=>true,'orders'=>[]];$lots=molavi_domain_lots($today);$products=[];
        try{$products=molavi_domain_order_catalog(molavi_domain_sheets(['Products','Shop'],true));}catch(Throwable $e){}
        $orders=[];foreach($plans as $row){$plan=molavi_domain_decode($row['result']);$source=molavi_domain_decode($row['source_order']);$id=(string)molavi_domain_py_value($plan['order_id']??'');
            $workflow=($plan['state']??null)!=='released'?molavi_domain_order_workflow_ensure($id,$plan,$today):molavi_domain_order_workflow($id);
            $excluded=[];foreach($source['lines']??[] as $line)$excluded[]=(string)molavi_domain_py_value($line['sku']??'');$suggestions=[];
            foreach($plan['lines']??[] as $line)if(($line['shortfall_units']??0)>0){$target=[];foreach($source['lines']??[] as $item)if(($item['sku']??null)===$line['sku']){$target=$item;break;}
                $suggestions[$line['sku']]=molavi_domain_owned_substitutes($target,(int)$line['shortfall_units'],$products,$lots,$excluded);}
            $totals=[];foreach($source['lines']??[] as $line)if(molavi_domain_py_value($line['sku']??'',null)!==null)$totals[(string)$line['sku']]=(int)($line['total']??0);
            $orders[]=array_merge($plan,['workflow'=>molavi_domain_order_workflow_public($workflow),'substitution_candidates'=>(object)$suggestions,
                'order_total_toman'=>(int)($source['total']??0),'original_line_totals_toman'=>(object)$totals]);
        }return ['ok'=>true,'orders'=>$orders];
    }
    if(!is_string($body['order_id']??null)||trim($body['order_id'])===''||molavi_domain_strlen($body['order_id'])>40
        ||!is_string($body['note']??null)||trim($body['note'])===''||molavi_domain_strlen($body['note'])>1000)molavi_domain_fail('invalid_body',400);
    $saved=molavi_domain_order_record($body['order_id']);if(!$saved)molavi_domain_fail('order_not_found',404);$source=molavi_domain_decode($saved['source_order']);
    if(array_key_exists('supplier_terms',$body)){
        molavi_domain_body_keys($body,['order_id','supplier_terms','note']);$terms=$body['supplier_terms'];
        if(!is_array($terms)||count($terms)<1||count($terms)>20)molavi_domain_fail('invalid_body',400);
        foreach($terms as $sku=>$offer){if((!is_string($sku)&&!is_int($sku))||trim((string)$sku)===''||molavi_domain_strlen((string)$sku)>80||!is_array($offer)
            ||array_diff(array_keys($offer),['supplier','units_per_pack','pack_price_toman','minimum_packs','pack_multiple','source_offer_id'])
            ||!is_string($offer['supplier']??null)||trim($offer['supplier'])===''||molavi_domain_strlen($offer['supplier'])>120
            ||!is_string($offer['source_offer_id']??null)||trim($offer['source_offer_id'])===''||molavi_domain_strlen($offer['source_offer_id'])>120)molavi_domain_fail('invalid_supplier_terms',400);
            foreach(['units_per_pack','pack_price_toman','minimum_packs','pack_multiple'] as $key){$value=$offer[$key]??(in_array($key,['minimum_packs','pack_multiple'],true)?1:null);$max=$key==='pack_price_toman'?1000000000000:1000000;
                if(!is_int($value)||$value<1||$value>$max)molavi_domain_fail('invalid_supplier_terms',400);}
        }
    }else{
        molavi_domain_body_keys($body,['order_id','action','details','note']);if(!is_string($body['action'])||!is_array($body['details']))molavi_domain_fail('invalid_body',400);
    }
    try{
        return molavi_domain_lock('publication',static function()use($body,$actor,$source,$today):array {
            $freshSource=molavi_domain_order_record($body['order_id']);if(!$freshSource)molavi_domain_fail('order_not_found',404);$source=molavi_domain_decode($freshSource['source_order']);
            $hasTerms=array_key_exists('supplier_terms',$body);
            if($hasTerms){$plan=molavi_domain_order_plan($source,$today,$body['supplier_terms'],$actor,$body['note']);$workflow=molavi_domain_order_workflow_ensure($body['order_id'],$plan,$today);}
            else{$action=$body['action'];$details=$body['details'];$saved=molavi_domain_order_record($body['order_id']);$plan=$saved?molavi_domain_decode($saved['result']):[];
                if($action==='propose_substitution'){
                    $sku=(string)molavi_domain_py_value($details['sku']??'');$replacement=(string)molavi_domain_py_value($details['replacement_sku']??'');$target=[];$excluded=[];
                    foreach($source['lines']??[] as $line){$excluded[]=(string)molavi_domain_py_value($line['sku']??'');if(($line['sku']??null)===$sku)$target=$line;}
                    $workflow=molavi_domain_order_workflow($body['order_id'])??[];foreach($workflow['resolutions']??[] as $row)$excluded[]=(string)molavi_domain_py_value($row['replacement_sku']??'');
                    $candidates=molavi_domain_owned_substitutes($target,(int)(molavi_domain_order_line($plan,$sku)['shortfall_units']??0),
                        molavi_domain_order_catalog(molavi_domain_sheets(['Products','Shop'],true)),molavi_domain_lots($today),$excluded);$candidate=null;
                    foreach($candidates as $row)if($row['sku']===$replacement){$candidate=$row;break;}
                    if(!$candidate||($details['replacement_units']??null)!==$candidate['required_units'])molavi_domain_fail('candidate_unavailable');
                    $total=$details['proposed_line_total_toman']??null;$floor=$candidate['price_floor_toman']*$candidate['required_units'];$ceiling=$candidate['price_ceiling_toman']*$candidate['required_units'];
                    if(!is_int($total)||$total<=0||$floor<=0||$ceiling<$floor||$total<$floor||$total>$ceiling)molavi_domain_fail('replacement_price_out_of_current_range');
                }
                $workflow=molavi_domain_order_workflow_apply($source,$plan,$action,$details,$actor,$body['note'],$today);
                $saved=molavi_domain_order_record($body['order_id']);$plan=$saved?molavi_domain_decode($saved['result']):[];
            }
            $result=['ok'=>true,'order_id'=>$body['order_id'],'state'=>$plan['state']??'needs_owner_review','workflow'=>molavi_domain_order_workflow_public($workflow),'plan'=>$plan];
            if($hasTerms)$result['terms_hash']=$plan['terms_hash']??'';else $result['action']=$body['action'];return $result;
        });
    }catch(MolaviWorkflowError $e){if($e->status>=500||in_array($e->getMessage(),['candidate_unavailable','replacement_price_out_of_current_range','workflow_busy_retry'],true))throw $e;
        molavi_domain_fail('conflict_or_invalid',409,['message'=>substr($e->getMessage(),0,160)]);}
}
