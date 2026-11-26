<?php
/** Plugin Name: Molavi Product Step Status Trace (read-only reporting) */
declare(strict_types=1);
defined('ABSPATH') || exit;

// Per-product step-status trace for the workbench card (owner request
// 2026-11-25): when he opens any product card, published or not, it shows
// what inferences or comparisons the system made at each step on the way
// to publication, with the actual values compared — never invented.
//
// READ-ONLY: this file only SELECTs. It never writes to any table, never
// sets a price, never changes a hold, a rule mode, or the publish path.
// It reuses the existing sources: supplier_price_evidence, the Products /
// Offers sheet records, supplier_link_checks, market_agreement_checks,
// the market lookup quotes, autopilot_log / autopilot_holds, and the Woo
// catalog. A missing step says so plainly instead of guessing.
//
// Shape (one trace per product):
//   ['product_id'=>..., 'generated_at'=>ISO, 'steps'=>[
//     ['key'=>'supplier_price','label'=>'قیمت تأمین‌کننده','status'=>'ok|warn|bad|info',
//      'status_label'=>'جاری', 'facts'=>[['قیمت ثبت‌شده',190000,'amount'],...],
//      'note'=>'...'], ... 7 steps ]]
// Fact kinds: 'amount' (تومان), 'date' (ISO, JS formats fa-IR), 'pct'
// (0.236 -> ۲۳٫۶٪), 'int', 'text'. The JS formats by kind, so PHP never
// invents a display string for a missing value.

// Method labels for supplier_price_evidence.method.
function molavi_domain_step_trace_method_label(?string $method): string {
    return match($method){
        'source'=>'مشاهدهٔ پیام منبع',
        'manual'=>'تأیید مالک',
        'market'=>'توافق بازار',
        'autopilot'=>'خلبان خودکار (G3)',
        default=>'تأیید نشده',
    };
}

// Sale-offer blocker labels (molavi_domain_sale_offer_evidence).
function molavi_domain_step_trace_blocker_label(string $blocker): string {
    return match($blocker){
        'supplier_price'=>'قیمت تأمین‌کننده جاری نیست',
        'regular_price'=>'قیمت عادی جاری نیست',
        'no_spread'=>'قیمت فروش از قیمت عادی بیشتر است',
        'thin_spread'=>'اختلاف قیمت برای تخفیف کافی نیست',
        default=>'مانعی ثبت نشده',
    };
}

// Market quotes behind one product, from a bulk-loaded lookup map.
// Mirrors molavi_domain_autopilot_market_quotes (same parsing, same
// cheapest-first order) but reads the pre-loaded candidates_json instead
// of one SELECT per product. Returns priced quotes only.
function molavi_domain_step_trace_quotes(array $record, array $lookups): array {
    if(!function_exists('molavi_domain_market_identity')||!function_exists('molavi_domain_hash'))return [];
    $identity=molavi_domain_market_identity($record);
    if(!$identity)return [];
    $json=$lookups[molavi_domain_hash($identity)]??null;
    if(!is_string($json)||$json==='')return [];
    try { $quotes=molavi_domain_decode($json,[]); } catch(Throwable $e) { return []; }
    if(!is_array($quotes))return [];
    $priced=[];
    foreach($quotes as $quote) {
        $unit=(int)($quote['regular_unit_price']??$quote['unit_price']??0);
        if($unit>0)$priced[]=$quote;
    }
    usort($priced,static fn($a,$b)=>((int)($a['regular_unit_price']??$a['unit_price']??0))
        <=> ((int)($b['regular_unit_price']??$b['unit_price']??0)));
    return $priced;
}

// Median of the priced quotes on the product's pack basis (regular price
// per sellable unit), plus the (max-min)/median spread G4 measures.
// Null spread when fewer than two priced quotes — a single quote has no
// spread to measure. Mirrors molavi_domain_autopilot_market_quote_spread.
function molavi_domain_step_trace_quote_stats(array $quotes,int $pack): array {
    $units=[];
    foreach($quotes as $quote) {
        $unit=(int)($quote['regular_unit_price']??$quote['unit_price']??0);
        if($unit>0)$units[]=$unit;
    }
    if(!$units)return ['count'=>0,'median_pack'=>0,'spread'=>null];
    sort($units);
    $median=$units[intdiv(count($units),2)];
    $spread=count($units)<2||$median<=0?null:(max($units)-min($units))/$median;
    return ['count'=>count($units),'median_pack'=>$median*max(1,$pack),'spread'=>$spread];
}

// ISO string for a unix stamp, or '' when the stamp is not positive.
function molavi_domain_step_trace_iso(int $stamp): string {
    if($stamp<=0)return '';
    return function_exists('molavi_autopilot_iso')
        ?molavi_autopilot_iso((float)$stamp)
        :gmdate('Y-m-d\\TH:i:s+00:00',$stamp);
}

// Effective risk tier for one log row, as the decisions tab renders it
// (mirrors molavi_domain_autopilot_review_risk_tier): a pending verdict
// is red; otherwise the stored tier; non-admission gates are 'none'.
// Fail closed to red for an admission row with no usable tier.
function molavi_domain_step_trace_effective_tier(array $row): string {
    if(function_exists('molavi_domain_autopilot_review_risk_tier'))
        return molavi_domain_autopilot_review_risk_tier($row,null);
    if((string)($row['gate']??'')!=='admission')return 'none';
    if((string)($row['verdict']??'')==='pending')return 'red';
    $stored=(string)($row['risk_tier']??'');
    return in_array($stored,['green','amber','red'],true)?$stored:'red';
}

// Compact autopilot summary for the card's first view: the current state,
// the effective risk tier, the verdict, and the one-line reason — the same
// values the decisions tab showed, so the card and the tab cannot disagree.
// $decision is the latest DECISION row (action shadow/live), not the latest
// log row: audit rows (confirm/revert/hold/release/override) carry the
// same gate and would otherwise masquerade as the evaluation.
function molavi_domain_step_trace_autopilot_summary(?array $decision, ?array $hold, array $inputs, array $settings): array {
    $masterOn=(bool)($settings['master_enabled']??false);
    $ruleMode=(string)($settings['rule_modes']['gA']??'shadow');
    $active=$masterOn&&$ruleMode==='live';
    $result=(string)($decision['result']??'');
    $state=$hold?'blocked'
        :($result==='admitted'?'admitted'
        :($result==='would_admit'?'would_admit'
        :(in_array($result,['reverted','overridden','reheld'],true)?'reverted'
        :'not_evaluated')));
    $stateLabels=['admitted'=>'پذیرفته شد (زنده)','would_admit'=>'آمادهٔ پذیرش (سایه)',
        'blocked'=>'مسدود','reverted'=>'لغو شد','not_evaluated'=>'ارزیابی نشده'];
    $verdict=$decision?(string)($decision['verdict']??'pending'):'';
    $verdictLabels=['ok'=>'تأیید شد','flag'=>'پرچم خورد','pending'=>'در انتظار'];
    $tier='none';
    $reason='';
    if($decision) {
        $tier=molavi_domain_step_trace_effective_tier($decision);
        if(function_exists('molavi_domain_autopilot_risk_reason'))
            $reason=(string)molavi_domain_autopilot_risk_reason($decision,$inputs);
    }
    $tierLabels=['red'=>'پرخطر','amber'=>'ریسک متوسط','green'=>'کم‌ریسک','none'=>'بدون درجه‌بندی'];
    return [
        'active'=>$active,
        'master_on'=>$masterOn,
        'rule_mode'=>$ruleMode,
        'mode_label'=>$active?'فعال — پذیرش زنده':($masterOn?'سایه — فقط ثبت می‌کند':'خاموش'),
        'state'=>$state,
        'state_label'=>$stateLabels[$state]??'ارزیابی نشده',
        'tier'=>$tier,
        'tier_label'=>$tierLabels[$tier]??'بدون درجه‌بندی',
        'verdict'=>$verdict,
        'verdict_label'=>$verdict===''?'ثبت نشده':($verdictLabels[$verdict]??'در انتظار'),
        'verdict_reason'=>$decision?(string)($decision['verdict_reason']??''):'',
        'reason'=>$reason,
        'hold_reason'=>$hold?(string)($hold['reason']??''):'',
        'closeness'=>($decision&&isset($decision['closeness'])&&$decision['closeness']!==null)?(float)$decision['closeness']:null,
        'arm'=>($decision&&!empty($decision['closeness_arm']))?(string)$decision['closeness_arm']:'',
        'created_at'=>$decision?(int)($decision['created_at']??0):0,
    ];
}

// One log row shaped like the decisions tab's API row: decoded inputs,
// the effective tier, the stored tier, and the risk reason. The tab and
// the card render the same row shape, so they cannot disagree.
function molavi_domain_step_trace_history_row(array $row): array {
    $inputs=[];
    if(isset($row['inputs_json'])) {
        try { $inputs=molavi_domain_decode((string)$row['inputs_json'],[]); } catch(Throwable $e) { $inputs=[]; }
        if(!is_array($inputs))$inputs=[];
    }
    $stored=in_array((string)($row['risk_tier']??''),['green','amber','red'],true)?(string)$row['risk_tier']:'';
    $reason='';
    if(function_exists('molavi_domain_autopilot_risk_reason'))
        $reason=(string)molavi_domain_autopilot_risk_reason($row,$inputs);
    return [
        'id'=>(int)($row['id']??0),
        'rule_id'=>(string)($row['rule_id']??''),
        'gate'=>(string)($row['gate']??''),
        'product_id'=>(string)($row['product_id']??''),
        'action'=>(string)($row['action']??''),
        'result'=>(string)($row['result']??''),
        'verdict'=>(string)($row['verdict']??''),
        'verdict_reason'=>(string)($row['verdict_reason']??''),
        'confidence'=>isset($row['confidence'])&&is_numeric($row['confidence'])?(float)$row['confidence']:null,
        'created_at'=>(int)($row['created_at']??0),
        'reviewed_at'=>(float)($row['reviewed_at']??0),
        'reviewed_by'=>(string)($row['reviewed_by']??''),
        'inputs'=>$inputs,
        'risk_tier'=>molavi_domain_step_trace_effective_tier($row),
        'risk_tier_stored'=>$stored,
        'risk_reason'=>$reason,
    ];
}

// One product's seven-step trace. $maps is the bulk-loaded context from
// molavi_domain_product_step_traces(); $now is microtime(true).
function molavi_domain_product_step_trace(array $record,array $offer,array $maps,int $now): array {
    $id=trim((string)($record['Product ID']??''));
    $sale=function_exists('molavi_domain_positive_price')
        ?molavi_domain_positive_price($record['Sale Price']??null):0;
    $pack=0;
    if(function_exists('molavi_domain_pack_count'))
        $pack=molavi_domain_pack_count((string)($record['Packaging']??''),(string)($record['Product Name']??''));
    $saved=$maps['evidence'][$id]??null;
    $supplier=function_exists('molavi_domain_product_saved_price')
        ?molavi_domain_product_saved_price($record,$maps['evidence'],$now):['state'=>'needs_review'];
    $market=$maps['market_sources'][$id]['price']??null;
    $regular=function_exists('molavi_domain_regular_price_evidence')
        ?molavi_domain_regular_price_evidence($record,$offer,$market,$now)
        :['amount'=>0,'state'=>'missing','expires_at'=>0];
    $saleOffer=function_exists('molavi_domain_sale_offer_evidence')
        ?molavi_domain_sale_offer_evidence($record,$offer,$supplier,$market,$now)
        :['sale_offer_blocker'=>''];
    $quotes=molavi_domain_step_trace_quotes($record,$maps['market_lookups']);
    $quoteStats=molavi_domain_step_trace_quote_stats($quotes,$pack);
    $gaLog=$maps['ga_log'][$id]??null;
    $gaHold=$maps['ga_holds'][$id]??null;
    $saleBlock=$maps['sale_blocks'][$id]??null;
    $gaInputs=[];
    if($gaLog&&isset($gaLog['inputs_json'])) {
        try { $gaInputs=molavi_domain_decode((string)$gaLog['inputs_json'],[]); }
        catch(Throwable $e) { $gaInputs=[]; }
        if(!is_array($gaInputs))$gaInputs=[];
    }
    $steps=[];

    // (a) Supplier price verification: how, when, which price.
    $supplierState=(string)($supplier['state']??'needs_review');
    $method=$saved?(string)($saved['method']??''):null;
    $verifiedAt=(string)($saved['verified_at']??($supplier['verified_at']??''));
    $sourceStamp=function_exists('molavi_domain_timestamp')
        ?molavi_domain_timestamp($record['Message Timestamp']??null):null;
    $ageDays=$sourceStamp!==null&&$sourceStamp<=$now?(int)floor(($now-$sourceStamp)/86400):null;
    $supplierFacts=[['قیمت ثبت‌شده',$sale,'amount'],
        ['وضعیت',match($supplierState){
            'current'=>'جاری','warning'=>'هشدار (۷ تا ۱۴ روز)','blocked'=>'کهنه (بیش از ۱۴ روز)',
            'missing'=>'قیمت ثبت نشده',default=>'نیاز به بررسی'},'text'],
        ['روش تأیید',molavi_domain_step_trace_method_label($method),'text']];
    if($verifiedAt!=='')$supplierFacts[]=['تأیید شده در',$verifiedAt,'date'];
    if($sourceStamp!==null)$supplierFacts[]=['پیام منبع',$ageDays===null?'ثبت شده':$ageDays.' روز پیش','text'];
    $linkCheck=$maps['link_checks'][$id]??null;
    if($linkCheck&&!empty($linkCheck['outcome'])) {
        $supplierFacts[]=['بررسی لینک',match((string)$linkCheck['outcome']){
            'unchanged'=>'بدون تغییر','changed'=>'تغییر کرده','uncheckable'=>'قابل بررسی نیست',
            default=>(string)$linkCheck['outcome']},'text'];
        if(!empty($linkCheck['checked_at']))$supplierFacts[]=['بررسی لینک در',(int)$linkCheck['checked_at'],'date'];
        if(!empty($linkCheck['live_price']))$supplierFacts[]=['قیمت تازه در لینک',(int)$linkCheck['live_price'],'amount'];
    }
    $agreement=$maps['market_agreements'][$id]??null;
    if($agreement&&($agreement['outcome']??'')==='agree')
        $supplierFacts[]=['توافق بازار','تأیید شد (روش market)','text'];
    $steps[]=['key'=>'supplier_price','label'=>'قیمت تأمین‌کننده',
        'status'=>match($supplierState){'current'=>'ok','warning'=>'warn','blocked'=>'bad','missing'=>'bad',default=>'warn'},
        'status_label'=>match($supplierState){'current'=>'جاری','warning'=>'هشدار','blocked'=>'کهنه','missing'=>'ثبت نشده',default=>'نیاز به بررسی'},
        'facts'=>$supplierFacts,
        'note'=>$supplierState==='current'?'قیمت تأمین‌کننده در ۱۴ روز گذشته تأیید شده است.':null];

    // (b) Regular price: source, state, expiry.
    $regularAmount=(int)($regular['amount']??0);
    $regularState=(string)($regular['state']??'missing');
    $regularExpires=(int)($regular['expires_at']??0);
    $marketProves=is_array($market)&&(int)($market['verified_at']??0)>0
        &&(int)($market['verified_at']??0)+86400>$now&&(int)($market['regular_price']??0)>0;
    $approval=trim((string)($record['Reference Approval State']??''));
    $matchId=trim((string)($record['Reference Match ID']??''));
    $regularSource=$marketProves?'بازار (Basalam)'
        :($matchId!==''&&in_array($approval,['owner_approved','approved_compatible','approved_within_margin'],true)
            ?'مرجع (تأیید مالک)':'شیت (قیمت مصرف‌کننده)');
    $regularFacts=[['قیمت عادی',$regularAmount>0?$regularAmount:'ثبت نشده','amount'],
        ['منبع',$regularSource,'text'],
        ['وضعیت',match($regularState){'current'=>'جاری','expired'=>'منقضی شده','unverified'=>'تأیید نشده',default=>'ثبت نشده'},'text']];
    if($regularExpires>0)$regularFacts[]=['انقضا',molavi_domain_step_trace_iso($regularExpires),'date'];
    if($sale>0&&$regularAmount>0) {
        $spread=($regularAmount-$sale)/$regularAmount;
        $regularFacts[]=['اختلاف با قیمت فروش',$spread,'pct'];
        $regularFacts[]=['قابلیت تخفیف',function_exists('molavi_band')&&molavi_band($sale,$regularAmount)['discountable']?'دارد':'ندارد','text'];
    }
    if($matchId!=='') {
        $refPrice=function_exists('molavi_domain_autopilot_reference_price')
            ?molavi_domain_autopilot_reference_price($matchId):0;
        if($refPrice>0)$regularFacts[]=['قیمت مرجع (رزوان)',$refPrice,'amount'];
    }
    $steps[]=['key'=>'regular_price','label'=>'قیمت عادی',
        'status'=>match($regularState){'current'=>'ok','expired'=>'bad','unverified'=>'warn',default=>'bad'},
        'status_label'=>match($regularState){'current'=>'جاری','expired'=>'منقضی شده','unverified'=>'تأیید نشده',default=>'ثبت نشده'},
        'facts'=>$regularFacts,
        'note'=>$regularState==='current'?'قیمت عادی جاری است و مبنای تخفیف همین است.':null];

    // (c) Market agreement: quotes, median, spread vs the 5% band.
    $quoteBand=0.05;
    if(isset($maps['settings']['quote_band'])&&is_numeric($maps['settings']['quote_band']))
        $quoteBand=(float)$maps['settings']['quote_band'];
    $agreementFacts=[];
    if($quoteStats['count']>0) {
        $agreementFacts[]=['تعداد نقل‌قول',$quoteStats['count'],'int'];
        $agreementFacts[]=['میانهٔ نقل‌قول (بسته)', $quoteStats['median_pack'],'amount'];
        if($quoteStats['spread']!==null) {
            $agreementFacts[]=['پراکندگی نقل‌قول‌ها',$quoteStats['spread'],'pct'];
            $agreementFacts[]=['باند مجاز',$quoteBand,'pct'];
            $agreementFacts[]=['نتیجهٔ پراکندگی',$quoteStats['spread']<=$quoteBand?'در باند مجاز':'خارج از باند مجاز','text'];
        } else {
            $agreementFacts[]=['پراکندگی نقل‌قول‌ها','تک‌نقل‌قول؛ پراکندگی ندارد','text'];
        }
    } else {
        $agreementFacts[]=['نقل‌قول بازار','هنوز نقل‌قولی نیست','text'];
    }
    if($agreement&&!empty($agreement['outcome'])) {
        $agreementFacts[]=['بررسی توافق',match((string)$agreement['outcome']){
            'agree'=>'همخوان است','disagree'=>'ناهمخوان','no_quotes'=>'نقل‌قولی نیست',
            'chain_limit'=>'سه بار خودکار؛ نوبت دستی',default=>(string)$agreement['outcome']},'text'];
        if(isset($agreement['implied_spread'])&&$agreement['implied_spread']!==null)
            $agreementFacts[]=['اختلاف ضمنی',(float)$agreement['implied_spread'],'pct'];
        if(!empty($agreement['checked_at']))$agreementFacts[]=['بررسی توافق در',(int)$agreement['checked_at'],'date'];
    } else {
        $agreementFacts[]=['بررسی توافق','هنوز بررسی نشده','text'];
    }
    $agreementStatus=($agreement['outcome']??'')==='agree'?'ok'
        :(($agreement['outcome']??'')==='disagree'||($agreement['outcome']??'')==='chain_limit'?'warn':'info');
    $steps[]=['key'=>'market_agreement','label'=>'توافق بازار',
        'status'=>$agreementStatus,
        'status_label'=>match($agreement['outcome']??''){ 'agree'=>'همخوان','disagree'=>'ناهمخوان','no_quotes'=>'بدون نقل‌قول','chain_limit'=>'نوبت دستی',default=>'بررسی نشده'},
        'facts'=>$agreementFacts,
        'note'=>$quoteStats['count']===0?'برای این کالا هنوز نقل‌قولی از بازار ثبت نشده است.':null];

    // (d) Availability: the supplier message is the source of truth.
    $stockRaw=(string)($record['Stock Status']??'');
    $stock=function_exists('molavi_domain_stock_status')
        ?molavi_domain_stock_status($stockRaw):($stockRaw===''?'Unknown':$stockRaw);
    $available=function_exists('molavi_domain_supplier_available')
        ?molavi_domain_supplier_available($record):$stock!=='Out of Stock';
    $steps[]=['key'=>'availability','label'=>'موجودی',
        'status'=>$available?'ok':'bad',
        'status_label'=>$available?($stock==='Unknown'?'نامشخص — موجود فرض می‌شود':'موجود'):'ناموجود (صریح)',
        'facts'=>[['وضعیت اعلام‌شده',match($stock){'Out of Stock'=>'ناموجود','Available'=>'موجود',default=>'نامشخص (در پیام ذکر نشده)'},'text']],
        'note'=>'پیام تأمین‌کننده مبناست؛ کالا همیشه موجود است مگر اینکه پیام صریحاً «ناموجود» بگوید.'];

    // (e) Admission gates (gA): which arms pass or fail, closeness, tier.
    // The latest log row is the last moment all arms were green; a current
    // hold is the live block. Both are shown when both exist — a shadow
    // would_admit from an earlier tick does not hide a hold that stands now.
    $admissionFacts=[];
    $admissionNote=null;
    if($gaLog) {
        $admissionFacts[]=['آخرین ارزیابی',($gaLog['action']??'')==='live'?'زنده':'سایه','text'];
        $admissionFacts[]=['نتیجه',match((string)($gaLog['result']??'')){
            'would_admit'=>'همهٔ گیت‌ها سبز — آمادهٔ پذیرش','admitted'=>'پذیرفته شد (زنده)',
            'reverted'=>'پذیرش لغو شد','overridden'=>'لغو شده توسط مالک',default=>(string)($gaLog['result']??'')},'text'];
        if(isset($gaLog['closeness'])&&$gaLog['closeness']!==null)
            $admissionFacts[]=['نزدیکی به آستانه',(float)$gaLog['closeness'],'pct'];
        if(!empty($gaLog['closeness_arm']))
            $admissionFacts[]=['تنگ‌ترین بازو',(string)$gaLog['closeness_arm'],'text'];
        $arms=is_array($gaInputs['closeness_arms']??null)?$gaInputs['closeness_arms']:[];
        $armLabels=['spread'=>'اختلاف قیمت','supplier_age'=>'سن قیمت تأمین‌کننده',
            'regular_expiry'=>'انقضای قیمت عادی','quote_spread'=>'پراکندگی نقل‌قول‌ها'];
        foreach($arms as $arm=>$margin)
            $admissionFacts[]=['حاشیهٔ '.($armLabels[$arm]??$arm),(float)$margin,'pct'];
        if(!empty($gaLog['risk_tier']))
            $admissionFacts[]=['درجهٔ ریسک',match((string)$gaLog['risk_tier']){
                'green'=>'کم‌ریسک','amber'=>'ریسک متوسط','red'=>'پرخطر',default=>(string)$gaLog['risk_tier']},'text'];
        if(!empty($gaLog['created_at']))$admissionFacts[]=['ارزیابی در',(int)$gaLog['created_at'],'date'];
        if(($gaLog['result']??'')==='admitted'&&($gaLog['verdict']??'pending')==='pending')
            $admissionNote='پذیرش زنده در لحظهٔ صدور، داوری‌اش در انتظار بود؛ به همین دلیل درجهٔ ریسک مؤثر «پرخطر» ثبت شد.';
    }
    if($gaHold) {
        $admissionFacts[]=['توقف فعلی',(string)($gaHold['reason']??''),'text'];
        if(!empty($gaHold['created_at']))$admissionFacts[]=['ثبت توقف در',(int)$gaHold['created_at'],'date'];
        $admissionNote='یک بازوی پذیرش رد شد؛ تا رفع آن، پذیرش خودکار انجام نمی‌شود.';
    }
    if(!$gaLog&&!$gaHold) {
        $admissionFacts[]=['ارزیابی پذیرش','هنوز ارزیابی پذیرش ثبت نشده','text'];
        $admissionNote='این کالا هنوز در هیچ تیک پذیرش ارزیابی نشده است.';
    }
    $admissionStatus=$gaHold?'bad':($gaLog && !in_array($gaLog['result'],['reverted','overridden'],true)?'ok':'info');
    $steps[]=['key'=>'admission','label'=>'پذیرش خودکار (gA)',
        'status'=>$admissionStatus,
        'status_label'=>$gaHold?'توقف پذیرش بعدی':match($gaLog['result']??''){
            'admitted'=>'پذیرفته شد','would_admit'=>'آمادهٔ پذیرش','reverted','overridden'=>'لغو شد',default=>'ارزیابی نشده'},
        'facts'=>$admissionFacts,'note'=>$admissionNote];

    // (f) Verdict: ok / flag / pending and its reason.
    $verdict=$gaLog?(string)($gaLog['verdict']??'pending'):'';
    $verdictReason=$gaLog?(string)($gaLog['verdict_reason']??''):'';
    $verdictFacts=[];
    if($gaLog) {
        $verdictFacts[]=['داوری',match($verdict){'ok'=>'تأیید شد','flag'=>'پرچم خورد',default=>'در انتظار'},'text'];
        if($verdictReason!=='')$verdictFacts[]=['دلیل',$verdictReason,'text'];
    } else {
        $verdictFacts[]=['داوری','هنوز داوری ثبت نشده','text'];
    }
    $steps[]=['key'=>'verdict','label'=>'داوری',
        'status'=>$gaLog?match($verdict){'ok'=>'ok','flag'=>'bad',default=>'warn'}:'info',
        'status_label'=>$gaLog?match($verdict){'ok'=>'تأیید شد','flag'=>'پرچم خورد',default=>'در انتظار'}:'ثبت نشده',
        'facts'=>$verdictFacts,
        'note'=>$verdict==='flag'&&($gaLog['action']??'')==='live'?'داوری پرچم‌خورده، پذیرش زنده را به‌صورت خودکار برمی‌گرداند و کالا را متوقف می‌کند.':null];

    // (g) Publication / storefront: published / draft / hidden and why.
    $blocker=(string)($saleOffer['sale_offer_blocker']??'');
    $published=!empty($maps['published'][$id]);
    $registered=$maps['registered'][$id]??null;
    $wooId=$registered?(int)($registered['woo_product_id']??0):0;
    if($published) {
        $pubFacts=[['وضعیت فروشگاه','منتشر شده','text']];
        if($wooId>0)$pubFacts[]=['شناسهٔ ووکامرس',$wooId,'int'];
        $canBuy=!empty($registered['purchasable']);
        $pubFacts[]=['قابل خرید',$canBuy?'بله':'خیر','text'];
        $pubStatus=$canBuy?'ok':'warn';$pubLabel=$canBuy?'منتشر شده':'منتشر شده، فروش بسته';$pubNote=null;
    } elseif($registered) {
        $pubFacts=[['وضعیت فروشگاه','پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)','text']];
        if($wooId>0)$pubFacts[]=['شناسهٔ ووکامرس',$wooId,'int'];
        $pubFacts[]=['دلیل',molavi_domain_step_trace_blocker_label($blocker),'text'];
        $pubStatus='warn';$pubLabel='پیش‌نویس';$pubNote=null;
    } else {
        $pubFacts=[['وضعیت فروشگاه','هنوز در فروشگاه ساخته نشده','text'],
            ['دلیل',molavi_domain_step_trace_blocker_label($blocker),'text']];
        $pubStatus='info';$pubLabel='ساخته نشده';$pubNote=null;
    }
    $permissions=$maps['offer_permissions']??null;
    $revision=(string)($record['Latest Offer ID']??'');
    if(!$published && $permissions!==null && empty($permissions[$id][$revision][molavi_domain_source_revision($record)]))
        $pubFacts[]=['مجوز انتشار','در انتظار پذیرش خودکار یا درخواست انتشار مالک برای همین پیشنهاد','text'];
    if ($saleBlock!==null) {
        $pubFacts[]=['مجوز فروش',$saleBlock==='owner_hold'?'توقف به دستور مالک':'توقف به‌دلیل داوری پذیرش زنده','text'];
        $pubStatus='bad';
    }
    if($blocker!==''&&!$published)
        $pubFacts[]=['مانع انتشار',molavi_domain_step_trace_blocker_label($blocker),'text'];
    $steps[]=['key'=>'publication','label'=>'انتشار / فروشگاه',
        'status'=>$pubStatus,'status_label'=>$pubLabel,'facts'=>$pubFacts,'note'=>$pubNote];

    // Autopilot summary + decision history (owner request 2026-11-26):
    // the decisions tab dissolves into the card. The summary uses the
    // latest DECISION row (action shadow/live) from $maps['ga_decision'],
    // not $gaLog — audit rows (confirm/revert/hold/release/override)
    // carry the same gate and would otherwise masquerade as the
    // evaluation. The history is the product's recent log rows, all
    // gates, newest first, shaped like the tab's API rows.
    $gaDecision=$maps['ga_decision'][$id]??null;
    $decisionInputs=[];
    if($gaDecision&&isset($gaDecision['inputs_json'])) {
        try { $decisionInputs=molavi_domain_decode((string)$gaDecision['inputs_json'],[]); }
        catch(Throwable $e) { $decisionInputs=[]; }
        if(!is_array($decisionInputs))$decisionInputs=[];
    }
    $autopilot=molavi_domain_step_trace_autopilot_summary($gaDecision,$gaHold,$decisionInputs,$maps['settings']??[]);
    $autopilot['history']=[];
    foreach(($maps['ga_history'][$id]??[]) as $historyRow) {
        try { $autopilot['history'][]=molavi_domain_step_trace_history_row($historyRow); }
        catch(Throwable $e) { continue; }
    }

    return ['product_id'=>$id,'generated_at'=>function_exists('molavi_domain_now')?molavi_domain_now():gmdate('Y-m-d\\TH:i:s+00:00'),
        'steps'=>$steps,'autopilot'=>$autopilot];
}

// Bulk entry point: one trace per product in the Products sheet.
// All table reads are bulk (one SELECT per table); per-product work is
// pure PHP. Read-only — molavi_domain_read only, never molavi_domain_tx.
function molavi_domain_product_step_traces(array $tables): array {
    $now=(int)microtime(true);
    $products=function_exists('molavi_domain_records')?molavi_domain_records($tables['Products']??[]):[];
    $offers=function_exists('molavi_domain_records')?molavi_domain_records($tables['Offers']??[]):[];
    $offersById=[];
    foreach($offers as $offer) {
        $oid=trim((string)($offer['Offer ID']??''));
        if($oid!==''&&!isset($offersById[$oid]))$offersById[$oid]=$offer;
    }
    $maps=['evidence'=>[],'link_checks'=>[],'market_agreements'=>[],'market_lookups'=>[],
        'market_sources'=>[],'ga_log'=>[],'ga_decision'=>[],'ga_history'=>[],'ga_holds'=>[],'published'=>[],'registered'=>[],
        'settings'=>[]];
    try {
        if(function_exists('molavi_domain_read')) {
            $maps['evidence']=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,'SELECT offer_id,source_revision,verified_at,method FROM supplier_price_evidence'),null,'offer_id'));
            $maps['link_checks']=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,'SELECT * FROM supplier_link_checks'),null,'product_id'));
            $maps['market_agreements']=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,'SELECT * FROM market_agreement_checks'),null,'product_id'));
            $maps['market_lookups']=molavi_domain_read('reference',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,'SELECT lookup_key,candidates_json FROM market_price_lookups'),'candidates_json','lookup_key'));
            // Latest gA log row per product (all arms green at that moment).
            $gaRows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,
                "SELECT * FROM autopilot_log WHERE id IN (
                    SELECT MAX(id) FROM autopilot_log WHERE gate='admission' AND rule_id='gA'
                    AND action IN ('live','shadow') AND provenance IN ('live','legacy') GROUP BY product_id)"));
            foreach($gaRows as $row) {
                $pid=(string)($row['product_id']??'');
                if($pid==='')continue;
                if(!isset($maps['ga_log'][$pid]))$maps['ga_log'][$pid]=$row;
                // Latest DECISION row per product (action shadow/live):
                // audit rows (confirm/revert/hold/release/override) carry
                // the same gate and must not masquerade as the evaluation.
                if(!isset($maps['ga_decision'][$pid])
                    &&in_array((string)($row['action']??''),['shadow','live'],true))
                    $maps['ga_decision'][$pid]=$row;
            }
            // Decision history per product (all gates, newest first,
            // capped at 5): the same rows the decisions tab showed.
            $historyRows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,
                'SELECT * FROM autopilot_log ORDER BY created_at DESC, id DESC LIMIT 1000'));
            foreach($historyRows as $row) {
                $pid=(string)($row['product_id']??'');
                if($pid==='')continue;
                if(!isset($maps['ga_history'][$pid]))$maps['ga_history'][$pid]=[];
                if(count($maps['ga_history'][$pid])<5)$maps['ga_history'][$pid][]=$row;
            }
            $maps['ga_holds']=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>array_column(
                molavi_domain_rows($db,"SELECT * FROM autopilot_holds WHERE gate IN ('admission','admission_owner') ORDER BY gate"),null,'product_id'));
            if(function_exists('molavi_domain_offer_permissions'))$maps['offer_permissions']=molavi_domain_offer_permissions();
            if(function_exists('molavi_domain_publisher_flag_held_products'))
                $maps['sale_blocks']=molavi_domain_publisher_flag_held_products(array_column($products,'Product ID'),(float)$now);
        }
        if(function_exists('molavi_domain_market_catalog_sources'))
            $maps['market_sources']=molavi_domain_market_catalog_sources($products,$offers);
        if(function_exists('molavi_domain_published_review_catalog')
            &&function_exists('molavi_domain_published_review_ids'))
            $maps['published']=molavi_domain_published_review_ids(molavi_domain_published_review_catalog());
        if(function_exists('molavi_catalog_identity_snapshot')) {
            foreach(molavi_catalog_identity_snapshot(false,true) as $row) {
                $rid=(string)($row['source_record_id']??'');
                if($rid!==''&&!isset($maps['registered'][$rid]))$maps['registered'][$rid]=$row;
            }
        }
        if(function_exists('molavi_autopilot_settings'))
            $maps['settings']=molavi_autopilot_settings();
    } catch(Throwable $e) {
        // A trace must never break the product list: on any read failure
        // the caller still gets the products, just without traces.
        return [];
    }
    $out=[];
    foreach($products as $record) {
        $id=trim((string)($record['Product ID']??''));
        if($id==='')continue;
        $latest=trim((string)($record['Latest Offer ID']??''));
        $offer=$latest!==''&&isset($offersById[$latest])?$offersById[$latest]:[];
        try { $out[$id]=molavi_domain_product_step_trace($record,$offer,$maps,$now); }
        catch(Throwable $e) { continue; }
    }
    return $out;
}
