<?php
/** Plugin Name: Molavi Native Order Follow-up */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_order_workflow(string $id): ?array {
    return molavi_domain_read('pipeline',static function(SQLite3 $db)use($id):?array {
        $row=molavi_domain_row($db,'SELECT workflow FROM order_workflows WHERE order_id=?',[$id]);return $row?molavi_domain_decode($row['workflow']):null;
    });
}
function molavi_domain_order_workflow_json(array $workflow): string {
    foreach(['procurement','resolutions'] as $key)$workflow[$key]=(object)($workflow[$key]??[]);return molavi_domain_json($workflow,true);
}
function molavi_domain_order_workflow_ensure(string $id,array $plan,string $today): array {
    if($id==='')molavi_domain_fail('order_required',400);$short=false;
    foreach($plan['lines']??[] as $line)if(($line['shortfall_units']??0)>0)$short=true;
    $workflow=['order_id'=>$id,'state'=>($plan['state']??null)==='needs_owner_review'?'needs_owner_review':($short?'supplier_followup':'ready_to_pack'),
        'assigned_to'=>'','next_action'=>$short?'Review supplier terms or unavailable lines':'Check and pack the complete order','due_at'=>'',
        'procurement'=>[],'resolutions'=>[],'refunds'=>[],'settlements'=>[],'packing'=>['status'=>'pending'],'dispatch'=>['status'=>'pending'],
        'delivery'=>['status'=>'pending'],'aftercare'=>['status'=>'none']];
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,'INSERT OR IGNORE INTO order_workflows VALUES (?,1,?,?)',[$id,molavi_domain_order_workflow_json($workflow),$today]));
    return molavi_domain_order_workflow($id);
}
function molavi_domain_order_required(array $details,string $key,int $limit): string {
    $value=molavi_domain_text(molavi_domain_py_value($details[$key]??null),$limit);if($value==='')molavi_domain_fail($key.'_required',400);return $value;
}
function molavi_domain_order_amount($value,bool $zero=false): int {
    if(!is_int($value)||$value<($zero?0:1))molavi_domain_fail('whole_amount_required',400);return $value;
}
function molavi_domain_order_line(array $plan,string $sku,string $key='lines'): array {
    foreach($plan[$key]??[] as $line)if(($line['sku']??null)===$sku)return $line;return [];
}
function molavi_domain_order_line_total(array $order,string $sku): int {
    $total=0;foreach($order['lines']??[] as $line)if(($line['sku']??null)===$sku)$total+=(int)($line['total']??0);return $total;
}
function molavi_domain_order_supplier_closed(array $workflow,string $sku): void {
    if(in_array($workflow['procurement'][$sku]['state']??null,['ordered','partially_received'],true))molavi_domain_fail('supplier_status_required');
}
function molavi_domain_order_refund_capacity(array $workflow,array $order,int $amount,bool $full=false): void {
    $confirmed=0;foreach($workflow['refunds']??[] as $refund){if(($refund['status']??null)==='requested')molavi_domain_fail('confirm_open_refund');
        if(($refund['status']??null)==='confirmed')$confirmed+=(int)($refund['amount_toman']??0);}
    $remaining=(int)($order['total']??0)-$confirmed;
    if($amount<=0||$amount>$remaining||($full&&$amount!==$remaining))molavi_domain_fail('refund_exceeds_paid_remainder');
}
function molavi_domain_order_request_refund(array &$workflow,string $order,string $sku,int $amount,string $reason,string $today): void {
    $id=substr(hash('sha256',$order.'|'.$sku.'|'.$reason.'|'.$amount),0,32);
    foreach($workflow['refunds'] as $refund)if($refund['refund_id']===$id)return;
    $workflow['refunds'][]=['refund_id'=>$id,'sku'=>$sku,'reason'=>$reason,'amount_toman'=>$amount,'status'=>'requested','requested_at'=>$today];
}
function molavi_domain_order_packable(array $plan,array $workflow): void {
    if(in_array($workflow['state'],['cancelled_refund_pending','cancelled','closed'],true))molavi_domain_fail('order_not_packable');
    foreach($plan['lines']??[] as $line)if(($line['shortfall_units']??0)>0&&!in_array($workflow['resolutions'][$line['sku']]['state']??null,['replacement_accepted','partial_refund'],true))molavi_domain_fail('unresolved_order_shortfall');
    foreach($workflow['refunds']??[] as $refund)if($refund['status']==='requested')molavi_domain_fail('confirm_open_refund');
    foreach($workflow['resolutions']??[] as $resolution)if(($resolution['settlement_status']??null)==='additional_payment_required')molavi_domain_fail('confirm_replacement_payment');
}
function molavi_domain_order_customer_choice(array &$workflow,array $order,array $plan,array $details,string $today): void {
    $sku=molavi_domain_order_required($details,'sku',80);$choice=$details['choice']??null;$resolution=$workflow['resolutions'][$sku]??[];
    if($choice==='replacement'){
        $proposal=$resolution['proposal']??[];if(($resolution['state']??null)!=='awaiting_customer'||($details['replacement_sku']??null)!==($proposal['replacement_sku']??null))molavi_domain_fail('replacement_proposal_changed');
        molavi_domain_order_supplier_closed($workflow,$sku);$total=molavi_domain_order_amount($details['approved_line_total_toman']??null,true);
        if($total!==$proposal['proposed_line_total_toman'])molavi_domain_fail('replacement_amount_changed');
        $difference=$total-molavi_domain_order_line_total($order,$sku);
        $resolution=array_merge($resolution,['state'=>'replacement_accepted','choice'=>$choice,'replacement_sku'=>$proposal['replacement_sku'],
            'replacement_units'=>$proposal['replacement_units'],'approved_line_total_toman'=>$total,
            'settlement_status'=>$difference===0?'equal':($difference>0?'additional_payment_required':'refund_requested'),
            'customer_difference_approved'=>($details['customer_difference_approved']??null)===true]);
        if($difference>0&&!$resolution['customer_difference_approved'])molavi_domain_fail('customer_price_approval_required');
        $workflow['resolutions'][$sku]=$resolution;
        if($difference<0){molavi_domain_order_refund_capacity($workflow,$order,abs($difference));molavi_domain_order_request_refund($workflow,$order['id'],$sku,abs($difference),'replacement_price_difference',$today);}
        $workflow['state']='customer_decision_recorded';
    }elseif($choice==='partial_refund'){
        $amount=molavi_domain_order_amount($details['refund_amount_toman']??null);molavi_domain_order_supplier_closed($workflow,$sku);
        if((molavi_domain_order_line($plan,$sku)['shortfall_units']??0)<=0)molavi_domain_fail('order_has_no_shortfall');
        if($amount>molavi_domain_order_line_total($order,$sku))molavi_domain_fail('refund_exceeds_line_total');
        if(in_array($resolution['state']??null,['partial_refund','replacement_accepted','full_cancellation'],true))molavi_domain_fail('customer_resolution_exists');
        molavi_domain_order_refund_capacity($workflow,$order,$amount);$workflow['resolutions'][$sku]=['state'=>'partial_refund','choice'=>$choice,'refund_amount_toman'=>$amount];
        molavi_domain_order_request_refund($workflow,$order['id'],$sku,$amount,'shortage',$today);$workflow['state']='refund_pending';
    }elseif($choice==='full_cancellation'){
        $amount=molavi_domain_order_amount($details['refund_amount_toman']??null);molavi_domain_order_refund_capacity($workflow,$order,$amount,true);
        foreach($plan['lines']??[] as $line)$workflow['resolutions'][$line['sku']]=['state'=>'full_cancellation','choice'=>$choice];
        molavi_domain_order_request_refund($workflow,$order['id'],'',$amount,'full_cancellation',$today);$workflow['state']='cancelled_refund_pending';
    }else molavi_domain_fail('unsupported_customer_choice',400);
}
function molavi_domain_order_transition(array &$workflow,array $order,array $plan,string $action,array $details,string $actor,string $note,string $today): void {
    if($workflow['state']==='closed')molavi_domain_fail('order_closed');$text=static fn(string $key,int $limit)=>molavi_domain_text(molavi_domain_py_value($details[$key]??null),$limit);
    if($action==='assign'){$workflow['assigned_to']=$text('assigned_to',120);$workflow['next_action']=$text('next_action',300);$workflow['due_at']=$text('due_at',40);}
    elseif(in_array($action,['record_supplier_order','mark_supplier_unavailable'],true)){
        $sku=molavi_domain_order_required($details,'sku',80);$prior=$workflow['procurement'][$sku]??[];
        if($action==='record_supplier_order'){$proposal=molavi_domain_order_line($plan,$sku,'procurement');
            if(($proposal['state']??null)!=='proposed')molavi_domain_fail('reviewed_supplier_proposal_required');
            if(!in_array($workflow['resolutions'][$sku]['state']??null,[null,'withdrawn'],true))molavi_domain_fail('resolve_customer_choice');
            $packs=molavi_domain_order_amount($details['packs']??null);if($packs!==$proposal['supplier_packs'])molavi_domain_fail('supplier_pack_count_changed');
            $reference=molavi_domain_order_required($details,'supplier_reference',120);$date=molavi_domain_order_required($details,'purchase_date',10);molavi_domain_date($date);if($date>$today)molavi_domain_fail('future_purchase_date');
            $prior=array_merge($prior,['state'=>'ordered','supplier'=>$proposal['supplier'],'supplier_reference'=>$reference,'packs'=>$packs,
                'expected_supplier_units'=>$proposal['supplier_units'],'purchase_date'=>$date,'expected_at'=>$text('expected_at',40)]);
        }else{if(!in_array($prior['state']??null,['ordered','partially_received'],true)&&!molavi_domain_order_line($plan,$sku,'procurement'))molavi_domain_fail('procurement_proposal_missing');$prior['state']='unavailable';}
        $workflow['procurement'][$sku]=$prior;if(!in_array($workflow['state'],['cancelled_refund_pending','cancelled'],true))$workflow['state']='supplier_followup';
    }elseif($action==='mark_supplier_received'){
        $sku=molavi_domain_order_required($details,'sku',80);$lot=molavi_domain_order_required($details,'lot_id',120);$accepted=molavi_domain_order_amount($details['accepted_units']??null);$proc=$workflow['procurement'][$sku]??[];
        if(!in_array($proc['state']??null,['ordered','partially_received','unavailable'],true))molavi_domain_fail('supplier_order_required');
        $unavailable=$proc['state']==='unavailable';$proc['received_units']=($proc['received_units']??0)+$accepted;$proc['receipt_lots']=array_values(array_unique([...($proc['receipt_lots']??[]),$lot]));
        $proc['state']=$proc['received_units']>=$proc['expected_supplier_units']?'received':($unavailable?'unavailable':'partially_received');$workflow['procurement'][$sku]=$proc;
        $resolved=in_array($workflow['resolutions'][$sku]['state']??null,['partial_refund','replacement_accepted','full_cancellation'],true);
        if(!in_array($workflow['state'],['cancelled_refund_pending','cancelled'],true)&&!$resolved)$workflow['state']=($plan['state']??null)==='reserved'?'ready_to_pack':'supplier_followup';
    }elseif($action==='propose_substitution'){
        $sku=molavi_domain_order_required($details,'sku',80);$replacement=molavi_domain_order_required($details,'replacement_sku',80);$units=molavi_domain_order_amount($details['replacement_units']??null);$total=molavi_domain_order_amount($details['proposed_line_total_toman']??null,true);
        if(($workflow['resolutions'][$sku]['state']??null)==='awaiting_customer')molavi_domain_fail('withdraw_existing_proposal');
        if(in_array($workflow['procurement'][$sku]['state']??null,['ordered','partially_received','received'],true))molavi_domain_fail('supplier_purchase_open');
        if((molavi_domain_order_line($plan,$sku)['shortfall_units']??0)<=0)molavi_domain_fail('order_has_no_shortfall');
        $workflow['resolutions'][$sku]=['state'=>'awaiting_customer','choice'=>'','proposal'=>['replacement_sku'=>$replacement,'replacement_units'=>$units,'proposed_line_total_toman'=>$total,'offered_at'=>$today]];$workflow['state']='awaiting_customer';
    }elseif($action==='withdraw_substitution'){
        $sku=molavi_domain_order_required($details,'sku',80);if(($workflow['resolutions'][$sku]['state']??null)!=='awaiting_customer')molavi_domain_fail('customer_proposal_missing');
        $workflow['resolutions'][$sku]=['state'=>'withdrawn'];$workflow['state']='supplier_followup';
    }elseif($action==='record_customer_choice')molavi_domain_order_customer_choice($workflow,$order,$plan,$details,$today);
    elseif($action==='confirm_additional_payment'){
        $sku=molavi_domain_order_required($details,'sku',80);$reference=molavi_domain_order_required($details,'transaction_reference',120);$amount=molavi_domain_order_amount($details['amount_toman']??null);$resolution=$workflow['resolutions'][$sku]??[];
        $expected=max(0,(int)($resolution['approved_line_total_toman']??0)-molavi_domain_order_line_total($order,$sku));
        if(($resolution['settlement_status']??null)!=='additional_payment_required'||$amount!==$expected)molavi_domain_fail('replacement_payment_mismatch');
        $workflow['resolutions'][$sku]=array_merge($resolution,['settlement_status'=>'confirmed','transaction_reference'=>$reference,'settled_at'=>$today]);
    }elseif($action==='confirm_refund'){
        $id=molavi_domain_order_required($details,'refund_id',64);$reference=molavi_domain_order_required($details,'transaction_reference',120);$amount=molavi_domain_order_amount($details['amount_toman']??null);$found=false;$all=true;
        foreach($workflow['refunds'] as &$refund){if($refund['refund_id']===$id){if($refund['status']!=='requested'||$amount!==$refund['amount_toman'])molavi_domain_fail('refund_receipt_mismatch');$refund=array_merge($refund,['status'=>'confirmed','transaction_reference'=>$reference,'confirmed_at'=>$today]);$found=true;}if($refund['status']!=='confirmed')$all=false;}unset($refund);
        if(!$found)molavi_domain_fail('refund_receipt_mismatch');if($workflow['state']==='cancelled_refund_pending'&&$all)$workflow['state']='cancelled';
    }elseif($action==='mark_packed'){$workflow['packing']=['status'=>'packed','packed_at'=>$today,'note'=>molavi_domain_text($note,1000)];$workflow['state']='packed';}
    elseif($action==='mark_dispatched'){
        if(($workflow['packing']['status']??null)!=='packed')molavi_domain_fail('pack_before_dispatch');
        $workflow['dispatch']=['status'=>'dispatched','tracking_reference'=>molavi_domain_order_required($details,'tracking_reference',120),'carrier'=>$text('carrier',120),'handed_over_at'=>$text('handed_over_at',40)?:$today];$workflow['state']='dispatched';
    }elseif($action==='mark_delivered'){
        if(($workflow['dispatch']['status']??null)!=='dispatched')molavi_domain_fail('dispatch_before_delivery');
        $workflow['delivery']=['status'=>'delivered','delivered_at'=>$text('delivered_at',40)?:$today];$workflow['state']='delivered';
    }elseif($action==='open_aftercare'){$workflow['aftercare']=['status'=>'open','issue'=>molavi_domain_order_required($details,'issue',500),'next_action'=>$text('next_action',300)];$workflow['state']='aftercare';}
    elseif($action==='resolve_aftercare'){
        if(($workflow['aftercare']['status']??null)!=='open')molavi_domain_fail('aftercare_not_open');
        $workflow['aftercare']=array_merge($workflow['aftercare'],['status'=>'resolved','resolved_at'=>$today,'resolution'=>$text('resolution',500)]);$workflow['state']='delivered';
    }elseif($action==='close'){
        if(($workflow['delivery']['status']??null)!=='delivered'||($workflow['aftercare']['status']??null)==='open')molavi_domain_fail('order_not_ready_to_close');
        foreach($workflow['refunds'] as $refund)if($refund['status']==='requested')molavi_domain_fail('confirm_open_refund');$workflow['state']='closed';$workflow['closed_at']=$today;
    }else molavi_domain_fail('unsupported_order_action',400);
    $workflow['last_event']=['action'=>$action,'actor_id'=>$actor,'note'=>molavi_domain_text($note,1000),'at'=>$today];
}
function molavi_domain_order_stock_transition(array $order,array $plan,array $workflow,string $action,array $details,string $today): array {
    $id=(string)$order['id'];
    if($action==='record_customer_choice'&&($details['choice']??null)==='replacement'){
        $sku=molavi_domain_order_required($details,'sku',80);$resolution=$workflow['resolutions'][$sku]??[];$proposal=$resolution['proposal']??[];
        if(($resolution['state']??null)!=='awaiting_customer'||($details['replacement_sku']??null)!==($proposal['replacement_sku']??null))molavi_domain_fail('replacement_proposal_changed');
        $total=molavi_domain_order_amount($details['approved_line_total_toman']??null,true);if($total!==($proposal['proposed_line_total_toman']??null))molavi_domain_fail('replacement_amount_changed');
        $difference=$total-molavi_domain_order_line_total($order,$sku);
        if($difference>0&&($details['customer_difference_approved']??null)!==true)molavi_domain_fail('customer_price_approval_required');
        if($difference<0)molavi_domain_order_refund_capacity($workflow,$order,abs($difference));molavi_domain_order_supplier_closed($workflow,$sku);
        $candidate=$proposal['replacement_sku'];foreach($order['lines']??[] as $line)if(($line['sku']??null)===$candidate)molavi_domain_fail('replacement_sku_in_order');
        foreach($workflow['resolutions']??[] as $row)if(($row['replacement_sku']??null)===$candidate)molavi_domain_fail('replacement_sku_in_order');
        $allocations=molavi_domain_reserve($id,$candidate,$proposal['replacement_units'],$today);$reserved=0;
        foreach($allocations as $row)if($row['state']==='reserved')$reserved+=$row['units'];
        if($reserved!==$proposal['replacement_units']){molavi_domain_reservation_state($id,$candidate,'reserved','released');molavi_domain_fail('replacement_stock_unavailable');}
    }elseif($action==='record_customer_choice'&&($details['choice']??null)==='full_cancellation'){
        $amount=$details['refund_amount_toman']??null;if(!is_int($amount))molavi_domain_fail('refund_exceeds_paid_remainder');
        molavi_domain_order_refund_capacity($workflow,$order,$amount,true);molavi_domain_release_order($id);
    }elseif($action==='mark_packed'){
        molavi_domain_order_packable($plan,$workflow);molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,"UPDATE stock_allocations SET state='fulfilled' WHERE order_id=? AND state='reserved'",[$id]));
    }elseif($action==='mark_supplier_received'){
        $sku=molavi_domain_order_required($details,'sku',80);$lotId=molavi_domain_order_required($details,'lot_id',120);$accepted=molavi_domain_order_amount($details['accepted_units']??null);$proc=$workflow['procurement'][$sku]??[];
        if(!in_array($proc['state']??null,['ordered','partially_received','unavailable'],true))molavi_domain_fail('supplier_order_required');
        if($accepted>(int)($proc['expected_supplier_units']??0)-(int)($proc['received_units']??0))molavi_domain_fail('receipt_exceeds_open_purchase');
        $lot=molavi_domain_lot($lotId);if(!$lot||$lot['sku']!==$sku||$lot['units']<$accepted)molavi_domain_fail('supplier_receipt_mismatch');
        if(!empty($proc['purchase_date'])&&$lot['purchase_date']!==$proc['purchase_date'])molavi_domain_fail('supplier_purchase_date_mismatch');
        $eligible=null;foreach(molavi_domain_lots($today) as $row)if($row['lot_id']===$lotId)$eligible=$row;
        if(!$eligible||$eligible['available_units']<$accepted)molavi_domain_fail('supplier_receipt_unavailable');
        $resolved=in_array($workflow['resolutions'][$sku]['state']??null,['partial_refund','replacement_accepted','full_cancellation'],true);
        if(!in_array($workflow['state'],['cancelled_refund_pending','cancelled'],true)&&!$resolved){
            $demand=0;foreach($order['lines']??[] as $line)if($line['sku']===$sku)$demand+=$line['qty'];
            if(!molavi_domain_reserve($id,$sku,$demand,$today)&&$accepted)molavi_domain_fail('supplier_receipt_unavailable');
            $saved=molavi_domain_order_record($id);if(!$saved)molavi_domain_fail('paid_order_source_unavailable');
            $source=molavi_domain_decode($saved['source_order']);$plan=molavi_domain_order_plan($source,$today,[],'system','',true);
        }
    }return $plan;
}
function molavi_domain_order_workflow_apply(array $order,array $plan,string $action,array $details,string $actor,string $note,string $today): array {
    $id=(string)molavi_domain_py_value($order['id']??'');if($id===''||$actor===''||trim($note)==='')molavi_domain_fail('order_action_required',400);
    $workflow=molavi_domain_order_workflow_ensure($id,$plan,$today);
    $event=['order_id'=>$id,'action'=>$action,'details'=>(object)$details,'actor_id'=>$actor,'note'=>molavi_domain_text($note,1000)];$eventId=hash('sha256',molavi_domain_json($event,true));
    $prior=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT 1 FROM order_workflow_events WHERE event_id=?',[$eventId]));if($prior)return $workflow;
    if($workflow['state']==='cancelled_refund_pending'&&!in_array($action,['confirm_refund','mark_supplier_received','mark_supplier_unavailable'],true))molavi_domain_fail('cancellation_refund_pending');
    if($workflow['state']==='cancelled'&&!in_array($action,['mark_supplier_received','mark_supplier_unavailable'],true))molavi_domain_fail('order_cancelled');
    $plan=molavi_domain_order_stock_transition($order,$plan,$workflow,$action,$details,$today);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$order,$plan,$action,$details,$actor,$note,$today,$eventId):array {
        $row=molavi_domain_row($db,'SELECT revision,workflow FROM order_workflows WHERE order_id=?',[$id]);if(!$row)molavi_domain_fail('order_workflow_missing');
        $workflow=molavi_domain_decode($row['workflow']);molavi_domain_order_transition($workflow,$order,$plan,$action,$details,$actor,$note,$today);
        molavi_domain_sql($db,'INSERT INTO order_workflow_events VALUES (?,?,?,?,?,?,?)',[$eventId,$id,$actor,$action,molavi_domain_json((object)$details,true),molavi_domain_text($note,1000),$today]);
        molavi_domain_sql($db,'UPDATE order_workflows SET revision=?,workflow=?,updated_at=? WHERE order_id=?',[$row['revision']+1,molavi_domain_order_workflow_json($workflow),$today,$id]);return $workflow;
    });
}
