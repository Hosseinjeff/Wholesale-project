<?php
/** Plugin Name: Molavi Native Re-extraction Changes */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_comparison_fields(array $offer): array {
    return [
        'Sale Price'=>$offer['Sale Price']??'',
        'Consumer Price'=>molavi_domain_py_value($offer['Consumer Price']??null,$offer['Actual Price']??''),
        'Package Price'=>$offer['Package Price']??'',
        'Currency'=>$offer['Currency']??'',
        'Stock Status'=>molavi_domain_stock_status($offer['Stock Status']??null),
        'Package Count'=>$offer['Package Count']??'',
        'Min Qty'=>$offer['Min Qty']??'',
        'Variation Type'=>$offer['Variation Type']??'',
        'Volume'=>$offer['Volume']??'',
        'Weight'=>$offer['Weight']??'',
    ];
}

function molavi_domain_ai_compare_offers(array $before,array $after): array {
    $identities=static fn(array $offers): array => array_map('molavi_domain_product_identity',$offers);
    $identityKey=static fn(array $identity): string => molavi_domain_json($identity);
    $nameKey=static fn(array $identity): string => molavi_domain_json(array_slice($identity,0,2));
    $old=$identities($before);$new=$identities($after);
    $oldByIdentity=[];$oldByName=[];
    foreach($old as $index=>$identity) {
        $oldByIdentity[$identityKey($identity)][]=$index;
        $oldByName[$nameKey($identity)][]=$index;
    }
    $newIdentities=array_count_values(array_map($identityKey,$new));
    $newNames=array_count_values(array_map($nameKey,$new));
    $results=[];
    foreach($after as $index=>$offer) {
        $identity=$new[$index];$key=$identityKey($identity);$name=$nameKey($identity);
        $matches=$oldByIdentity[$key]??[];
        $prior=null;
        if($identity[1]!==''&&count($matches)===1&&$newIdentities[$key]===1) {
            $prior=$matches[0];
        } elseif($identity[1]!==''&&$newNames[$name]===1&&count($oldByName[$name]??[])===1) {
            // A unique item can gain missing dimensions. Conflicting known dimensions leave it unpaired.
            $candidate=$oldByName[$name][0];
            $prior=$candidate;
            foreach(array_slice($identity,2,null,true) as $part=>$value) {
                $previous=$old[$candidate][$part];
                if($previous!==$value&&!in_array($previous,['','0'],true)&&!in_array($value,['','0'],true)) $prior=null;
            }
        }
        $changes=[];
        if($prior!==null) {
            $previous=molavi_domain_ai_comparison_fields($before[$prior]);
            foreach(molavi_domain_ai_comparison_fields($offer) as $field=>$value) {
                if(!molavi_domain_cells_equal($field,$previous[$field],$value)) {
                    $changes[]=['field'=>$field,'before'=>$previous[$field],'after'=>$value];
                }
            }
        }
        $results[]=['offer_id'=>(string)$offer['Offer ID'],'product_name'=>(string)($offer['Product Name']??''),
            'state'=>$prior===null?'unmatched':($changes?'changed':'unchanged'),'changes'=>$changes];
    }
    return $results;
}

function molavi_domain_ai_run_changes(array $query): array {
    $run=$query['run_id']??'';
    $status=molavi_domain_ai_run_status($run);
    if(!$status) molavi_domain_fail('unknown_reextraction_run',404);
    if($status['state']!=='complete') molavi_domain_fail('reextraction_in_progress',409);
    $batches=[];
    foreach($status['items'] as $item) {
        $result=$item['result']??[];
        $count=(int)molavi_domain_py_value($result['offers_written']??null,$result['staged_offers']??0);
        if($count>0) $batches[$item['job_id']]=$count;
    }
    $expected=array_sum($batches);$results=[];
    if($expected) {
        $rows=molavi_domain_records(molavi_domain_sheets(['Offers'],true)['Offers']);
        $written=[];$first=[];$previous=[];
        foreach($rows as $index=>$offer) {
            if(!isset($batches[$offer['Batch ID']??''])||empty($offer['Offer ID'])||!empty($offer['Derived From Offer ID'])) continue;
            $source=molavi_domain_ai_source_key($offer);
            $written[$source][]=$offer;
            $first[$source]??=$index;
        }
        // Offers are appended in extraction order. Use the preceding batch for the same source.
        $last=$first?max($first):0;
        foreach($rows as $index=>$offer) {
            if($index>=$last) break;
            $batch=(string)($offer['Batch ID']??'');
            if($batch===''||str_starts_with($batch,'reextract:'.$run.':')||empty($offer['Offer ID'])||!empty($offer['Derived From Offer ID'])) continue;
            $source=molavi_domain_ai_source_key($offer);
            if(!isset($first[$source])||$index>=$first[$source]) continue;
            if(($previous[$source]['batch']??null)!==$batch) $previous[$source]=['batch'=>$batch,'offers'=>[]];
            $previous[$source]['offers'][]=$offer;
        }
        foreach($written as $source=>$offers) {
            array_push($results,...molavi_domain_ai_compare_offers($previous[$source]['offers']??[],$offers));
        }
    }
    $limit=20;
    $priority=['changed'=>0,'unmatched'=>1,'unchanged'=>2];
    usort($results,static fn(array $a,array $b): int => $priority[$a['state']]<=>$priority[$b['state']]);
    return ['ok'=>true,'run_id'=>$run,'offers'=>array_slice($results,0,$limit),
        'omitted'=>max(0,count($results)-$limit),'missing'=>max(0,$expected-count($results))];
}
