<?php
/** Plugin Name: Molavi Native Inventory and Clearance */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_plan_packs(int $demand,int $stock,int $pack):array {
    molavi_domain_whole($demand,true);molavi_domain_whole($stock,true);molavi_domain_whole($pack);
    $used=min($demand,$stock);$packs=intdiv($demand-$used+$pack-1,$pack);
    return ['customer_units'=>$demand,'owned_stock_used'=>$used,'supplier_packs'=>$packs,'supplier_units'=>$packs*$pack,'unallocated_after_fulfillment'=>$packs*$pack-($demand-$used)];
}
function molavi_domain_lot_events(array $item,array $events):array {
    $item['received_units']=$item['units'];$item['adjustment_units']=array_sum(array_column($events,'delta_units'));$item['units']+=$item['adjustment_units'];
    $material=[];foreach($events as $event){if($event['new_state']!==null)$item['state']=$event['new_state'];$material[]=[$event['event_id'],$event['action'],$event['delta_units'],$event['reason'],$event['new_state']];}
    $item['revision']=molavi_domain_hash(['receipt_hash'=>$item['receipt_hash'],'events'=>$material]);return $item;
}
function molavi_domain_lot_from_db(SQLite3 $db,string $id):?array {
    $row=molavi_domain_row($db,'SELECT * FROM stock_lots WHERE lot_id=?',[$id]);if(!$row)return null;
    $committed=molavi_domain_row($db,"SELECT COALESCE(SUM(units),0) AS units FROM stock_allocations WHERE lot_id=? AND state IN ('reserved','fulfilled')",[$id]);
    $row['committed_units']=$committed['units'];return molavi_domain_lot_events($row,molavi_domain_rows($db,'SELECT * FROM stock_lot_events WHERE lot_id=? ORDER BY sequence',[$id]));
}
function molavi_domain_lot(string $id):?array {return molavi_domain_read('pipeline',static fn($db)=>molavi_domain_lot_from_db($db,$id));}
function molavi_domain_lots(string $today):array {
    molavi_domain_date($today);return molavi_domain_read('pipeline',static function(SQLite3 $db)use($today){
        $rows=molavi_domain_rows($db,"SELECT l.*,COALESCE(SUM(CASE WHEN a.state IN ('reserved','fulfilled') THEN a.units ELSE 0 END),0) AS committed_units FROM stock_lots l LEFT JOIN stock_allocations a ON a.lot_id=l.lot_id GROUP BY l.lot_id ORDER BY l.expiry_date IS NULL,l.expiry_date,l.received_at,l.lot_id");
        $events=[];foreach(molavi_domain_rows($db,'SELECT * FROM stock_lot_events ORDER BY sequence') as $event)$events[$event['lot_id']][]=$event;
        $out=[];foreach($rows as $row){$row=molavi_domain_lot_events($row,$events[$row['lot_id']]??[]);$row['available_units']=$row['state']==='ready'&&$row['received_at']<=$today&&!empty($row['expiry_date'])&&$row['expiry_date']>$today?max(0,$row['units']-$row['committed_units']):0;$out[]=$row;}return $out;
    });
}
function molavi_domain_receive_lot(array $facts):string {
    foreach(['lot_id','sku','supplier'] as $key)if(!is_string($facts[$key]??null)||trim($facts[$key])==='')molavi_domain_fail('lot_identity_required',400);
    molavi_domain_whole($facts['units']??null);molavi_domain_whole($facts['unit_cost']??null,true);
    $received=molavi_domain_date($facts['received_at']??null);$purchase=empty($facts['purchase_date'])?null:molavi_domain_date($facts['purchase_date']);
    $production=empty($facts['production_date'])?null:molavi_domain_date($facts['production_date']);$expiry=empty($facts['expiry_date'])?null:molavi_domain_date($facts['expiry_date']);
    if(($purchase&&$purchase>$received)||($production&&$purchase&&$production>$purchase)||($production&&$production>$received)||($production&&$expiry&&$production>=$expiry))molavi_domain_fail('invalid_lot_date_order',400);
    $state=$expiry&&$expiry>$received?'ready':'review';$material=[];
    foreach(['lot_id','sku','supplier','received_at','production_date','expiry_date','units','unit_cost'] as $key)$material[$key]=$facts[$key]??null;
    if(!empty($facts['purchase_date']))$material['purchase_date']=$facts['purchase_date'];$hash=molavi_domain_hash($material,true,true);
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($facts,$hash,$state){
        $old=molavi_domain_row($db,'SELECT receipt_hash FROM stock_lots WHERE lot_id=?',[$facts['lot_id']]);if($old&&$old['receipt_hash']!==$hash)molavi_domain_fail('lot_conflict');
        if(!$old)molavi_domain_sql($db,'INSERT INTO stock_lots (lot_id,sku,supplier,purchase_date,received_at,production_date,expiry_date,units,unit_cost,receipt_hash,state) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$facts['lot_id'],$facts['sku'],$facts['supplier'],$facts['purchase_date']??null,$facts['received_at'],$facts['production_date']??null,$facts['expiry_date']??null,$facts['units'],$facts['unit_cost'],$hash,$state]);
    });return $state;
}
function molavi_domain_receipt_audit(string $lotId,string $actor,string $note,string $created):void {
    $lot=molavi_domain_lot($lotId);if(!$lot||trim($actor)===''||trim($note)==='')molavi_domain_fail('receipt_audit_required',400);
    molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'INSERT OR IGNORE INTO stock_lot_receipt_audit VALUES (?,?,?,?,?)',[$lotId,$lot['receipt_hash'],$actor,trim($note),$created]));
}
function molavi_domain_lot_action(array $input,string $actor,string $today,string $created):array {
    molavi_domain_date($today);$allowed=['adjust'=>['count_correction','damage','expiry'],'hold'=>['quality','record_review','supplier_issue'],'release'=>['inspection_passed']];
    $action=$input['action']??'';$reason=$input['reason']??'';$delta=$input['delta_units']??null;$note=$input['note']??'';$expected=$input['expected_revision']??'';$id=(string)($input['lot_id']??'');
    if(!in_array($reason,$allowed[$action]??[],true)||!is_int($delta)||abs($delta)>1000000||($action==='adjust'?$delta===0:$delta!==0)
        ||(in_array($reason,['damage','expiry'],true)&&$delta>=0))molavi_domain_fail('invalid_stock_action',400);
    if(!is_string($note)||trim($note)===''||molavi_domain_strlen($note)>1000||$actor===''||!is_string($expected)||strlen($expected)!==64)molavi_domain_fail('invalid_stock_action',400);
    $eventId=molavi_domain_hash(['lot_id'=>$id,'expected_revision'=>$expected,'actor_id'=>$actor,'action'=>$action,'delta_units'=>$delta,'reason'=>$reason,'note'=>trim($note)]);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$actor,$action,$reason,$delta,$note,$expected,$eventId,$today,$created){
        $current=molavi_domain_lot_from_db($db,$id);if(!$current)molavi_domain_fail('lot_not_found');
        $prior=molavi_domain_row($db,'SELECT * FROM stock_lot_events WHERE event_id=?',[$eventId]);
        if($prior)return ['event_id'=>$eventId,'revision'=>$current['revision'],'units'=>$current['units'],'state'=>$current['state'],'action'=>$prior['action'],'previous_revision'=>$prior['expected_revision'],'idempotent'=>true];
        if($current['revision']!==$expected)molavi_domain_fail('lot_changed_or_action_conflict');$state=null;
        if($action==='hold'){
            if($current['state']==='held')molavi_domain_fail('lot_already_held');$reserved=molavi_domain_row($db,"SELECT COALESCE(SUM(units),0) AS units FROM stock_allocations WHERE lot_id=? AND state='reserved'",[$id]);
            if($reserved['units'])molavi_domain_fail('resolve_reservations_before_holding');$state='held';
        }elseif($action==='release'){
            if($current['state']!=='held'||empty($current['expiry_date'])||$current['expiry_date']<=$today)molavi_domain_fail('lot_cannot_be_released');$state='ready';
        }elseif($current['units']+$delta<$current['committed_units'])molavi_domain_fail('adjustment_would_remove_committed_units');
        molavi_domain_sql($db,'INSERT INTO stock_lot_events (event_id,lot_id,actor_id,action,delta_units,reason,note,created_at,new_state,expected_revision) VALUES (?,?,?,?,?,?,?,?,?,?)',[$eventId,$id,$actor,$action,$delta,$reason,trim($note),$created,$state,$expected]);
        $saved=molavi_domain_lot_from_db($db,$id);return ['event_id'=>$eventId,'revision'=>$saved['revision'],'units'=>$saved['units'],'state'=>$saved['state'],'action'=>$action,'previous_revision'=>$expected,'idempotent'=>false];
    });
}
function molavi_domain_lot_purchase_date(string $id,string $expected,string $purchase):string {
    molavi_domain_date($purchase);return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$expected,$purchase){
        $row=molavi_domain_row($db,'SELECT * FROM stock_lots WHERE lot_id=?',[$id]);if(!$row||$row['receipt_hash']!==$expected||$row['purchase_date']!==null)molavi_domain_fail('lot_changed');
        if($purchase>$row['received_at']||(!empty($row['production_date'])&&$row['production_date']>$purchase))molavi_domain_fail('invalid_purchase_date',400);
        $facts=[];foreach(['lot_id','sku','supplier','received_at','production_date','expiry_date','units','unit_cost'] as $key)$facts[$key]=$row[$key];
        if(molavi_domain_hash($facts,true,true)!==$expected)molavi_domain_fail('historical_receipt_hash_mismatch');$facts['purchase_date']=$purchase;$hash=molavi_domain_hash($facts,true,true);
        molavi_domain_sql($db,'UPDATE stock_lots SET purchase_date=?,receipt_hash=? WHERE lot_id=? AND receipt_hash=?',[$purchase,$hash,$id,$expected]);return $hash;
    });
}
function molavi_domain_reserve(string $order,string $sku,int $units,string $today):array {
    molavi_domain_whole($units);molavi_domain_date($today);if($order===''||$sku==='')molavi_domain_fail('order_and_sku_required',400);
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($order,$sku,$units,$today){
        $prior=molavi_domain_row($db,'SELECT requested FROM stock_reservation_requests WHERE order_id=? AND sku=?',[$order,$sku]);
        if($prior&&$prior['requested']!==$units)molavi_domain_fail('order_quantity_changed');
        if(!$prior)molavi_domain_sql($db,'INSERT INTO stock_reservation_requests VALUES (?,?,?)',[$order,$sku,$units]);
        $allocated=molavi_domain_row($db,"SELECT COALESCE(SUM(units),0) AS units FROM stock_allocations WHERE order_id=? AND sku=? AND state IN ('reserved','fulfilled')",[$order,$sku]);$remaining=max(0,$units-$allocated['units']);
        if($remaining){$rows=molavi_domain_rows($db,"SELECT l.lot_id,l.units+COALESCE((SELECT SUM(e.delta_units) FROM stock_lot_events e WHERE e.lot_id=l.lot_id AND e.action='adjust'),0)-COALESCE(SUM(CASE WHEN a.state IN ('reserved','fulfilled') THEN a.units ELSE 0 END),0) AS available FROM stock_lots l LEFT JOIN stock_allocations a ON a.lot_id=l.lot_id WHERE l.sku=? AND COALESCE((SELECT e.new_state FROM stock_lot_events e WHERE e.lot_id=l.lot_id AND e.new_state IS NOT NULL ORDER BY e.sequence DESC LIMIT 1),l.state)='ready' AND l.expiry_date>? AND l.received_at<=? GROUP BY l.lot_id HAVING available>0 ORDER BY l.expiry_date,l.received_at,l.lot_id",[$sku,$today,$today]);
            foreach($rows as $row){$count=min($remaining,$row['available']);if($count){
                molavi_domain_sql($db,"INSERT INTO stock_allocations VALUES (?,?,?,?,'reserved') ON CONFLICT(order_id,sku,lot_id) DO UPDATE SET units=stock_allocations.units+excluded.units WHERE stock_allocations.state='reserved'",[$order,$sku,$row['lot_id'],$count]);
                if($db->changes()!==1)molavi_domain_fail('reservation_state_changed');$remaining-=$count;}if(!$remaining)break;}}
        return molavi_domain_rows($db,'SELECT lot_id,units,state FROM stock_allocations WHERE order_id=? AND sku=? ORDER BY lot_id',[$order,$sku]);
    });
}
function molavi_domain_reservation_state(string $order,string $sku,string $from,string $to):int {
    if($from!=='reserved'||!in_array($to,['released','fulfilled'],true))molavi_domain_fail('invalid_reservation_transition',400);
    return molavi_domain_tx('pipeline',static fn($db)=>molavi_domain_sql($db,'UPDATE stock_allocations SET state=? WHERE order_id=? AND sku=? AND state=?',[$to,$order,$sku,$from])['changes']);
}
function molavi_domain_release_order(string $order):int {
    return molavi_domain_tx('pipeline',static function(SQLite3 $db)use($order){$row=molavi_domain_row($db,"SELECT COALESCE(SUM(units),0) AS units FROM stock_allocations WHERE order_id=? AND state='reserved'",[$order]);molavi_domain_sql($db,"UPDATE stock_allocations SET state='released' WHERE order_id=? AND state='reserved'",[$order]);return $row['units'];});
}
function molavi_domain_clearance(array $input):array {
    try{$bought=molavi_domain_date($input['purchase_date']??null);$made=empty($input['production_date'])?null:molavi_domain_date($input['production_date']);$expires=molavi_domain_date($input['expiry_date']??null);$today=molavi_domain_date($input['today']??null);$observed=molavi_domain_date($input['market_observed_date']??null);}
    catch(MolaviWorkflowError $e){return ['state'=>'needs_review','reason'=>'invalid_or_missing_date'];}
    if($bought>$today||($made&&($made>$bought||$made>$today))||$expires<=$today)return ['state'=>'needs_review','reason'=>'date_order_or_expiry_requires_review'];
    if($observed!=$today)return ['state'=>'needs_review','reason'=>'market_price_must_be_observed_today'];
    foreach(['purchase_cost','normal_price','market_price'] as $key)if(!is_int($input[$key]??null)||$input[$key]<=0)return ['state'=>'needs_review','reason'=>'positive_integer_toman_prices_required'];
    $source=$input['market_source']??'';if(!is_string($source)||trim($source)===''||molavi_domain_strlen($source)>200)return ['state'=>'needs_review','reason'=>'market_source_required'];
    $threshold=$input['near_cost_threshold_toman']??null;if($threshold!==null&&(!is_int($threshold)||$threshold<=0))return ['state'=>'needs_review','reason'=>'invalid_near_cost_threshold'];
    $held=(int)$bought->diff($today)->format('%a');$age=$made?(int)$made->diff($today)->format('%a'):null;$remaining=(int)$today->diff($expires)->format('%a');
    if($held+$remaining<=0||($age!==null&&$age+$remaining<=0))return ['state'=>'needs_review','reason'=>'invalid_shelf_life'];
    $holding=$held/($held+$remaining);$life=$age!==null?$age/($age+$remaining):$holding;$urgency=$made?($holding+$life)/2:$holding;
    $cost=$input['purchase_cost'];$normal=$input['normal_price'];$market=$input['market_price'];
    $result=['purchase_date'=>$bought->format('Y-m-d'),'production_date'=>$made?$made->format('Y-m-d'):null,'expiry_date'=>$expires->format('Y-m-d'),'today'=>$today->format('Y-m-d'),'age_basis'=>$made?'purchase_and_production':'purchase_date_fallback',
        'days_since_purchase'=>$held,'days_since_production'=>$age,'holding_urgency'=>$holding,'shelf_life_urgency'=>$life,'days_until_expiry'=>$remaining,'urgency'=>$urgency,'purchase_cost_toman'=>$cost,'normal_price_toman'=>$normal,'market_price_toman'=>$market,'market_source'=>trim($source),'market_observed_date'=>$observed->format('Y-m-d'),
        'near_cost_alert_configured'=>$threshold!==null,'near_cost_threshold_toman'=>$threshold,'near_cost_alert'=>false];
    if($urgency<0.5)return array_replace($result,['state'=>'normal_period','suggested_price_toman'=>null,'discount_from_normal_toman'=>0,'reason'=>'clearance_urgency_below_proposed_start']);
    $starting=min($normal,$market);if($starting<=$cost)return array_replace($result,['state'=>'near_cost_review','suggested_price_toman'=>null,'discount_from_normal_toman'=>0,'near_cost_alert'=>$threshold!==null&&$starting<=$cost+$threshold,'reason'=>'normal_or_market_price_is_at_or_below_purchase_cost']);
    $suggested=max($cost,min((int)($cost+($starting-$cost)*(1-$urgency)),$normal-1));$alert=$threshold!==null&&$suggested<=$cost+$threshold;
    return array_replace($result,['state'=>$alert?'near_cost_alert':($threshold===null?'alert_threshold_required':'proposed'),'suggested_price_toman'=>$suggested,'discount_from_normal_toman'=>$normal-$suggested,'near_cost_alert'=>$alert,'reason'=>'owner_review_only; price is not published automatically']);
}
