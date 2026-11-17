<?php
/** Plugin Name: Molavi Native Paid Order Planning */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_text($value, int $limit): string {
    $value = trim((string)($value ?? ''));
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8')
        : implode('', array_slice(preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY), 0, $limit));
}
function molavi_domain_rounded($value): int {
    if(is_bool($value))return (int)$value;
    if(is_string($value))$value=trim(molavi_domain_digits($value));
    return is_numeric($value)&&is_finite((float)$value)?(int)round((float)$value,0,PHP_ROUND_HALF_EVEN):0;
}
function molavi_domain_normalize_order(array $order): array {
    $lines = [];
    foreach (is_array($order['lines'] ?? null) ? $order['lines'] : [] as $raw) {
        if (!is_array($raw)) continue;
        $line = [];
        foreach (['id'=>40,'name'=>200,'packaging'=>120,'volume'=>80,'supplier'=>80,'sku'=>80,'supplier_offer_id'=>120] as $field=>$limit) {
            $value = $raw[$field] ?? '';
            if ($field === 'id') $value = $value ?: ($raw['product_id'] ?? '');
            if ($field === 'sku') $value = $value ?: ($raw['id'] ?? '') ?: ($raw['product_id'] ?? '');
            $line[$field] = molavi_domain_text($value, $limit);
        }
        foreach (['qty','min','order_unit','sale','shelf','unit','total'] as $field) {
            $value = $raw[$field] ?? 0;
            if ($field === 'qty') $value = $value ?: ($raw['quantity'] ?? 0);
            if ($field === 'min') $value = $value ?: ($raw['min_qty'] ?? 0);
            if ($field === 'shelf') $value = $value ?: ($raw['consumer'] ?? 0);
            $line[$field] = molavi_domain_rounded($value);
        }
        $line['qty'] = max(0, $line['qty']); $line['min'] = max(0, $line['min']);
        $line['order_unit'] = max(0, min(999, $line['order_unit']));
        if ($line['name'] !== '' && $line['qty'] > 0) $lines[] = $line;
    }
    $result = [];
    foreach (['id'=>40,'name'=>80,'username'=>40,'phone'=>20,'email'=>120,'address'=>300,'note'=>500,
        'woo_status'=>32,'payment_method'=>80,'transaction_id'=>120,'paid_at'=>40] as $field=>$limit) {
        $result[$field] = molavi_domain_text($order[$field] ?? '', $limit);
    }
    $result['woo_status'] = strtolower($result['woo_status']);
    if ($result['woo_status'] !== '' && !preg_match('/^[\pL\pN_-]+$/u', $result['woo_status'])) $result['woo_status'] = 'unknown';
    $result['at'] = molavi_domain_text(($order['at'] ?? '') ?: ($order['created_at'] ?? ''), 40);
    $result['progress'] = is_int($order['progress'] ?? null) || is_float($order['progress'] ?? null) ? $order['progress'] : 0;
    $result['total'] = molavi_domain_rounded($order['total'] ?? 0);
    $result['status'] = molavi_domain_text(($order['status'] ?? '') ?: 'new', 20);
    if (!in_array($result['status'], ['new','review','confirmed','cancelled'], true)) $result['status'] = 'new';
    $result['payment_status'] = strtolower(molavi_domain_text(($order['payment_status'] ?? '') ?: 'pending', 20));
    if (!in_array($result['payment_status'], ['pending','verified','failed','refunded','unknown'], true)) $result['payment_status'] = 'unknown';
    $result['buyer_type'] = strtolower(molavi_domain_text($order['buyer_type'] ?? '', 20));
    if (!in_array($result['buyer_type'], ['household','shop'], true)) $result['buyer_type'] = 'unknown';
    $result['lines'] = $lines;
    return $result;
}
function molavi_domain_order_record(string $orderId): ?array {
    return molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT * FROM paid_order_plans WHERE order_id=?', [$orderId]));
}
function molavi_domain_purchase_proposal(string $orderId, string $sku, int $demand, array $offer): array {
    if (!is_string($offer['supplier'] ?? null) || $offer['supplier'] === '') molavi_domain_fail('supplier_required',400);
    $pack=molavi_domain_whole($offer['units_per_pack']??null);
    $price=molavi_domain_whole($offer['pack_price_toman']??null);
    $minimum=molavi_domain_whole($offer['minimum_packs']??1);
    $multiple=molavi_domain_whole($offer['pack_multiple']??1);
    $packs=intdiv($demand+$pack-1,$pack);
    if($packs) $packs=intdiv(max($packs,$minimum)+$multiple-1,$multiple)*$multiple;
    return ['sku'=>$sku,'supplier'=>$offer['supplier'],'customer_units'=>$demand,'owned_units_allocated'=>0,
        'shortfall'=>$demand,'supplier_packs'=>$packs,'purchase_cost_toman'=>$packs*$price,
        'supplier_units'=>$packs*$pack,'expected_surplus_units'=>$packs*$pack-$demand];
}
function molavi_domain_order_plan(array $source, string $today, array $suppliedTerms = [], string $actor = 'system', string $note = '', bool $force = false): array {
    $order = molavi_domain_normalize_order($source); $id = $order['id'];
    if ($id === '') molavi_domain_fail('order_id_required', 400);
    if (in_array($order['woo_status'], ['cancelled','refunded','failed'], true) || $order['payment_status'] === 'refunded') {
        $released = molavi_domain_release_order($id);
        $result = ['order_id'=>$id,'state'=>'released','released_units'=>$released,'purchase_authorized'=>false];
        molavi_domain_tx('pipeline', static function(SQLite3 $db) use ($order,$id,$today,$result): void {
            $prior = molavi_domain_row($db, 'SELECT state FROM paid_order_plans WHERE order_id=?', [$id]);
            if (($prior['state'] ?? '') === 'released') return;
            $hash = molavi_domain_hash(['order_id'=>$id,'terminal_state'=>'released','payment_status'=>$order['payment_status']], true);
            molavi_domain_sql($db, "INSERT INTO paid_order_plans (order_id,request_hash,terms_hash,source_order,result,state,updated_at) VALUES (?,?,?, ?,?,'released',?) ON CONFLICT(order_id) DO UPDATE SET result=excluded.result,state=excluded.state,updated_at=excluded.updated_at",
                [$id,$hash,'',molavi_domain_json($order,true),molavi_domain_json($result,true),$today]);
        });
        return $result;
    }
    if ($order['payment_status'] !== 'verified') return ['order_id'=>$id,'state'=>'payment_pending','purchase_authorized'=>false];
    $quantities = []; $offerIds = [];
    foreach ($order['lines'] as $line) {
        if ($line['sku'] === '' || !is_int($line['qty']) || $line['qty'] <= 0) molavi_domain_fail('invalid_paid_order_line',400);
        $sku = $line['sku']; $quantities[$sku] = ($quantities[$sku] ?? 0) + $line['qty'];
        if ($line['supplier_offer_id'] !== '') $offerIds[$sku][$line['supplier_offer_id']] = true;
    }
    if (!$quantities) molavi_domain_fail('paid_order_has_no_valid_lines',400);
    ksort($quantities, SORT_STRING); $requestLines = [];
    foreach ($quantities as $sku=>$units) { $ids = array_keys($offerIds[$sku] ?? []); sort($ids, SORT_STRING);
        $requestLines[] = ['sku'=>(string)$sku,'units'=>$units,'supplier_offer_ids'=>$ids]; }
    $hash = molavi_domain_hash(['order_id'=>$id,'total'=>$order['total'],'currency'=>'IRT','lines'=>$requestLines],true);
    $prepared = molavi_domain_tx('pipeline', static function(SQLite3 $db) use ($id,$hash,$order,$today,$suppliedTerms,$actor,$note,$force,$offerIds): array {
        $prior = molavi_domain_row($db, 'SELECT * FROM paid_order_plans WHERE order_id=?', [$id]);
        if ($prior && $prior['request_hash'] !== $hash) molavi_domain_fail('paid_order_content_changed');
        if (($prior['state'] ?? '') === 'released') molavi_domain_fail('released_order_cannot_reserve_again');
        $terms = $prior ? molavi_domain_decode($prior['supplier_terms']) : [];
        if ($suppliedTerms && ($actor === 'system' || trim($note) === '')) molavi_domain_fail('supplier_terms_require_owner_and_note',400);
        $eligible = null;
        if ($suppliedTerms && !empty($prior['result'])) {
            $eligible = [];
            foreach (molavi_domain_decode($prior['result'])['procurement'] ?? [] as $row)
                if (in_array($row['state'], ['needs_supplier_terms','invalid_supplier_terms','proposed'],true)) $eligible[]=$row['sku'];
        }
        foreach ($suppliedTerms as $sku=>$offer) {
            if (!is_string($sku) || trim($sku) === '' || !is_array($offer)) molavi_domain_fail('invalid_supplier_terms',400);
            $sku = trim($sku);
            if ($eligible !== null && !in_array($sku,$eligible,true)) molavi_domain_fail('terms_require_recorded_shortfall');
            $terms[$sku] = array_intersect_key($offer, array_flip(['supplier','units_per_pack','pack_price_toman','minimum_packs','pack_multiple','source_offer_id']));
        }
        foreach ($terms as $sku=>$offer)
            if (empty($offer['source_offer_id']) || empty($offerIds[$sku][$offer['source_offer_id']])) molavi_domain_fail('supplier_terms_must_cite_order_offer',400);
        $encoded = molavi_domain_json((object)$terms,true); $termsHash=hash('sha256',$encoded);
        if (!$force && $prior && $prior['terms_hash']===$termsHash && !empty($prior['result']) && $prior['state']!=='planning')
            return ['result'=>molavi_domain_decode($prior['result'])];
        molavi_domain_sql($db, "INSERT INTO paid_order_plans (order_id,request_hash,terms_hash,source_order,result,state,updated_at,supplier_terms) VALUES (?,?,?,?,NULL,'planning',?,?) ON CONFLICT(order_id) DO UPDATE SET terms_hash=excluded.terms_hash,source_order=excluded.source_order,state='planning',updated_at=excluded.updated_at,supplier_terms=excluded.supplier_terms",
            [$id,$hash,$termsHash,molavi_domain_json($order,true),$today,$encoded]);
        if ($suppliedTerms && (!$prior || $prior['terms_hash'] !== $termsHash)) molavi_domain_sql($db,
            'INSERT INTO paid_order_decisions (order_id,actor_id,note,terms,created_at) VALUES (?,?,?,?,?)',[$id,$actor,molavi_domain_text($note,1000),$encoded,$today]);
        return ['terms'=>$terms,'terms_hash'=>$termsHash];
    });
    if (isset($prepared['result'])) return $prepared['result'];
    $lines=[]; $procurement=[]; $shortage=false; $review=false;
    foreach ($quantities as $sku=>$demand) {
        $sku=(string)$sku; $allocations=molavi_domain_reserve($id,$sku,$demand,$today);
        $reserved=0; foreach ($allocations as $row) if($row['state']==='reserved') $reserved+=$row['units'];
        $missing=max(0,$demand-$reserved);
        $lines[]=['sku'=>$sku,'customer_units'=>$demand,'owned_units_reserved'=>$reserved,'shortfall_units'=>$missing,'lots'=>$allocations];
        if (!$missing) continue; $shortage=true; $offer=$prepared['terms'][$sku]??null;
        if ($offer===null) { $review=true; $procurement[]=['sku'=>$sku,'state'=>'needs_supplier_terms','shortfall_units'=>$missing,'purchase_authorized'=>false]; continue; }
        try { $proposal=molavi_domain_purchase_proposal($id,$sku,$missing,$offer); }
        catch (MolaviWorkflowError|TypeError $e) { $review=true; $procurement[]=['sku'=>$sku,'state'=>'invalid_supplier_terms','shortfall_units'=>$missing,'purchase_authorized'=>false]; continue; }
        $procurement[]=array_merge($proposal,['state'=>'proposed','purchase_authorized'=>false,'source_offer_id'=>(string)($offer['source_offer_id']??'')]);
    }
    $summary=[]; foreach($order['lines'] as $line) if($line['sku']!=='')$summary[]=['sku'=>$line['sku'],'supplier_offer_id'=>$line['supplier_offer_id']];
    $result=['order_id'=>$id,'state'=>$review?'needs_owner_review':($shortage?'procurement_proposed':'reserved'),
        'lines'=>$lines,'source_lines'=>$summary,'procurement'=>$procurement,'purchase_authorized'=>false,'terms_hash'=>$prepared['terms_hash']];
    molavi_domain_tx('pipeline', static fn(SQLite3 $db)=>molavi_domain_sql($db,
        'UPDATE paid_order_plans SET result=?,state=?,updated_at=? WHERE order_id=? AND request_hash=?',
        [molavi_domain_json($result,true),$result['state'],$today,$id,$hash]));
    return $result;
}
