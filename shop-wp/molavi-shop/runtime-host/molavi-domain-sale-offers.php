<?php
/** Plugin Name: Molavi Sale Offer Eligibility */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_reference_regular_deadline(array $record,int $price,int $now): int {
    $id=(string)($record['Reference Match ID']??'');
    if ($id===''||empty($record['Reference Snapshot ID'])||!in_array(($record['Reference Approval State']??''),['owner_approved','approved_compatible','approved_within_margin'],true)) return 0;
    static $source=null,$prices=[];
    if ($source===null) {
        $source=molavi_domain_reference_active()??[];
        foreach ($source['rows']??[] as $row) {
            if (count($row)<17) continue;
            $entry=molavi_domain_matching_reference($row);
            $prices[$entry['reference_id']]=(int)($entry['consumer_price']??0);
        }
    }
    if (($prices[$id]??0)!==$price) return 0;
    $at=molavi_domain_timestamp($source['last_checked_at']??$source['extracted_at']??null);
    return $at!==null&&$at<=$now?(int)$at+MOLAVI_REFERENCE_REFRESH_SECONDS:0;
}

function molavi_domain_regular_price_evidence(array $record,array $offer,?array $market,int $now): array {
    $raw=molavi_domain_positive_price($record['Consumer Price']??null)?:molavi_domain_positive_price($record['Actual Price']??null);
    // The market regular price is per single item (regular_unit_price), while the
    // record's sale price is per sellable unit (one pack). Scale the market price
    // to the pack basis before comparing, or every packed product looks spreadless.
    $pack=max(1,molavi_domain_positive_price($offer['Package Count']??null)?:molavi_domain_positive_price($record['Package Count']??null));
    $marketPrice=(int)($market['regular_price']??null);
    $price=$marketPrice>0?$marketPrice*$pack:$raw;
    if ($price<=0) return ['amount'=>0,'state'=>'missing','expires_at'=>0];
    $deadline=0;
    if (!empty($market['regular_price'])) {
        $at=(int)($market['verified_at']??0);
        if ($at>0&&$at<=$now) $deadline=$at+MOLAVI_MARKET_SOURCE_CHECK_SECONDS;
        if ($deadline>$now) return ['amount'=>$price,'state'=>'current','expires_at'=>$deadline];
    }
    if ($raw>0) {
        $directDeadline=0;
        $offerPrice=molavi_domain_positive_price($offer['Consumer Price']??null)?:molavi_domain_positive_price($offer['Actual Price']??null);
        if ($offerPrice===$raw && ($offer['Offer ID']??null)===($record['Latest Offer ID']??'')) {
            $stamp=null;
            if (!empty($offer['Owner Decision ID'])) $stamp=molavi_domain_timestamp($offer['Owner Decision At']??null);
            if ($stamp===null && in_array($offer['Source Timestamp Basis']??'',['supplier_channel_update','supplier_channel_forward'],true)) {
                $stamp=molavi_domain_timestamp(($offer['Source Post Updated At']??'')?:($offer['Message Timestamp']??null));
            }
            if ($stamp!==null&&$stamp<=$now) $directDeadline=(int)$stamp+14*86400;
        }
        if ($directDeadline<=$now) $directDeadline=max($directDeadline,molavi_domain_reference_regular_deadline($record,$raw,$now));
        if ($directDeadline>$now) return ['amount'=>$raw,'state'=>'current','expires_at'=>$directDeadline];
        $deadline=max($deadline,$directDeadline);
    }
    return ['amount'=>$price,'state'=>$deadline>$now?'current':($deadline?'expired':'unverified'),'expires_at'=>$deadline];
}

function molavi_domain_sale_offer_evidence(array $record,array $offer,array $supplier,?array $market,int $now): array {
    $regular=molavi_domain_regular_price_evidence($record,$offer,$market,$now);
    $cost=molavi_domain_positive_price($record['Sale Price']??null);
    $supplierAt=molavi_domain_timestamp($supplier['verified_at']??null);
    $supplierState=$cost>0?($supplier['state']??'needs_review'):'missing';
    if ($supplierAt===null||$supplierAt>$now||$supplierAt+14*86400<=$now) {
        if (in_array($supplierState,['warning','current'],true)) $supplierState=$supplierAt===null?'needs_review':'blocked';
    }
    $reason=match(true){
        !in_array($supplierState,['current','warning'],true)=>'supplier_price',
        $regular['state']!=='current'=>'regular_price',
        $regular['amount']<=$cost=>'no_spread',
        $regular['amount']>$cost&&$cost>0&&function_exists('molavi_band')&&!molavi_band($cost,$regular['amount'])['discountable']=>'thin_spread',
        default=>'',
    };
    return ['supplier_price_state'=>$supplierState,'regular_price_state'=>$regular['state'],'regular_price'=>$regular['amount'],
        'sale_offer_blocker'=>$reason,'sale_offer_expires_at'=>$reason===''?min((int)$supplierAt+14*86400,$regular['expires_at']):0];
}
