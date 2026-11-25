<?php
/** Plugin Name: Molavi Autopilot Slice 1 */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Autopilot slice 1: three auto-rules (publish request, market-quote pick,
 * unchanged-price verify) plus settings, holds, and the API.
 *
 * Shadow mode is the default for every rule: the engine computes scores and
 * logs what it WOULD do, but takes no mutating action until the owner flips
 * a rule live (master switch + per-rule mode, both in settings). Auto only
 * ever confirms values already in the system; it never invents a price.
 * Every rule fails closed: no score means no action, and an exception in one
 * rule never breaks the tick or the other rules.
 *
 * The verdict is a post-decision second opinion, not a decision: after the
 * rules run, the tick asks Gemini (via molavi-domain-ai.php, in WordPress)
 * whether each logged decision's evidence supports it. A verdict of "flag"
 * sends the item to the top of the "not sure" queue; it never blocks, undoes
 * or changes the decision itself. If the model call fails, the row stays
 * verdict='pending' and the action stands on its deterministic rule.
 *
 * Evidence instrumentation (admission design §6): every log row carries its
 * provenance (live tick vs probe/test) and the global-hold state at
 * evaluation time; every gA row carries its closeness to the tightest arm
 * threshold at decision time as its confidence; molavi_domain_autopilot_
 * admission_evidence() counts distinct correct product-decisions under that
 * definition; every tick and every digest watch is persisted (autopilot_ticks,
 * autopilot_digest_watches), so the go-live bar is a checkable fact.
 *
 * Risk-flagged live admissions (round six, workstream 2 — the 20-distinct
 * bar is retired as the live gate): a live gA admission carries its risk
 * tier (green/amber/red, mapped from closeness by workstream 1's
 * molavi_domain_autopilot_risk_tier()) on the log row and the receipt. A
 * live admission is always verdict-pending at admission time (verdicts run
 * after the rules in the same tick), so its effective tier is red until the
 * second opinion lands. A flagged verdict on a live-admitted product
 * auto-re-holds it (existing hide/hold path, plus a hold row and a 'reheld'
 * log row). Live admissions roll out in stages — first 1 product, then up
 * to 3 total, then the 5/day cap governs — and the stage advances only by
 * an explicit advance_admission_rollout API action, never automatically.
 * Boundary-close items are admitted live with their tier attached; there
 * is no confidence gate on the live path. Hard limits: this path never
 * sets prices, never resolves holds, never approves chats; the thin-spread
 * hold (min_margin 12%) is untouched.
 * Digest risk section (round six): molavi_domain_autopilot_recent_risk_
 * decisions() lists the new amber/red admission decisions since the last
 * digest watch — tiered at read time from the stored closeness, one entry
 * per product, red before amber, tightest margin first, capped at 10 — and
 * the API GET exposes it as risk_digest, so the daily digest worker reads
 * it with no new endpoint and advances the window with record_digest_watch.
 */

// ---------------------------------------------------------------- settings

function molavi_autopilot_defaults(): array {
    return [
        'quote_band'=>0.05,
        'ai_price_tolerance'=>0.03,
        'supplier_price_tolerance'=>0.02,
        'reference_margin'=>0.05,
        'precedent_mechanical'=>5,
        'precedent_judgement'=>10,
        'cap_per_gate_per_day'=>20,
        'consecutive_auto_limit'=>3,
        'master_enabled'=>false,
        'shadow_enabled'=>true,
        'rule_modes'=>['g5'=>'shadow','g4'=>'shadow','g3'=>'shadow','gA'=>'shadow'],
        'verdict_sampling'=>'all',
        // Composite admission rule (gA): live admissions are capped tighter than
        // the per-gate daily cap. See molavi_domain_autopilot_rule_admission().
        'admission_cap_per_day'=>5,
        // Risk tiers (round six): the red cutoff is the §6 boundary-close
        // definition (MOLAVI_ADMISSION_BOUNDARY_CLOSE); the amber cutoff is
        // the quarter-scale point — see molavi_domain_autopilot_risk_tier().
        'risk_red_close'=>MOLAVI_ADMISSION_BOUNDARY_CLOSE,
        'risk_amber_close'=>MOLAVI_ADMISSION_AMBER_CLOSE,
    ];
}

function molavi_autopilot_settings(): array {
    $stored=get_option('molavi_autopilot_settings_v1',[]);
    if(!is_array($stored))$stored=[];
    $settings=array_merge(molavi_autopilot_defaults(),$stored);
    $modes=is_array($settings['rule_modes']??null)?$settings['rule_modes']:[];
    $settings['rule_modes']=array_merge(molavi_autopilot_defaults()['rule_modes'],$modes);
    return $settings;
}

function molavi_autopilot_settings_version(array $settings): string {
    return substr(molavi_domain_hash($settings),0,12);
}

// ------------------------------------------------------------------- tables

function molavi_domain_autopilot_ensure_tables(): void {
    molavi_domain_tx('pipeline',static function(SQLite3 $db): void {
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_rules (
            id TEXT PRIMARY KEY, gate TEXT NOT NULL, condition_json TEXT NOT NULL DEFAULT '{}',
            state TEXT NOT NULL DEFAULT 'active', created_by TEXT NOT NULL DEFAULT 'system',
            created_at REAL NOT NULL, last_applied_at REAL,
            apply_count INTEGER NOT NULL DEFAULT 0, override_count INTEGER NOT NULL DEFAULT 0)");
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_log (
            id INTEGER PRIMARY KEY, rule_id TEXT NOT NULL, gate TEXT NOT NULL,
            product_id TEXT NOT NULL DEFAULT '', case_id TEXT NOT NULL DEFAULT '',
            inputs_json TEXT NOT NULL DEFAULT '{}', confidence REAL,
            action TEXT NOT NULL, result TEXT NOT NULL,
            verdict TEXT NOT NULL DEFAULT 'pending',
            verdict_reason TEXT NOT NULL DEFAULT '', created_at REAL NOT NULL,
            provenance TEXT NOT NULL DEFAULT 'legacy', global_hold INTEGER NOT NULL DEFAULT 0,
            closeness REAL, closeness_arm TEXT NOT NULL DEFAULT '',
            risk_tier TEXT NOT NULL DEFAULT '')");
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_holds (
            product_id TEXT NOT NULL, gate TEXT NOT NULL, reason TEXT NOT NULL,
            priority REAL NOT NULL, score REAL NOT NULL, created_at REAL NOT NULL,
            PRIMARY KEY(product_id,gate))");
        // Admission receipts: one row per admission decision, linked to the
        // autopilot_log row that recorded it, so any admission is provable
        // after the fact. receipt_json holds the full admission state.
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_admission_receipts (
            id INTEGER PRIMARY KEY, log_id INTEGER NOT NULL, product_id TEXT NOT NULL DEFAULT '',
            offer_id TEXT NOT NULL DEFAULT '', receipt_json TEXT NOT NULL DEFAULT '{}',
            created_at REAL NOT NULL)");
        // Existing installs predate verdict_reason: migrate in place.
        $columns=array_column(molavi_domain_rows($db,'PRAGMA table_info(autopilot_log)'),'name');
        if(!in_array('verdict_reason',$columns,true))
            $db->exec("ALTER TABLE autopilot_log ADD COLUMN verdict_reason TEXT NOT NULL DEFAULT ''");
        // Evidence instrumentation (admission design §6): provenance,
        // global-hold state, and per-admission closeness. Pre-instrumentation
        // rows keep provenance 'legacy' (unknown) and never count toward the
        // bar — the default must NOT be 'live', or the test-run rows would be
        // grandfathered in as live-tick evidence.
        $evidenceColumns=[
            'provenance'=>"TEXT NOT NULL DEFAULT 'legacy'",
            'global_hold'=>'INTEGER NOT NULL DEFAULT 0',
            'closeness'=>'REAL',
            'closeness_arm'=>"TEXT NOT NULL DEFAULT ''",
            'risk_tier'=>"TEXT NOT NULL DEFAULT ''",
        ];
        foreach($evidenceColumns as $column=>$definition) {
            if(!in_array($column,$columns,true))
                $db->exec('ALTER TABLE autopilot_log ADD COLUMN '.$column.' '.$definition);
        }
        // Review surface (round six, workstream 3): the owner can mark a
        // decision reviewed, and the digest must stop listing it as new.
        // Kept as its own block (not folded into $evidenceColumns above)
        // so a merge with the round-six tiering slice, which extends that
        // array with risk_tier, stays trivial. ensure_tables runs this on
        // every call, so fresh installs get the columns here too.
        $reviewColumns=array_column(molavi_domain_rows($db,'PRAGMA table_info(autopilot_log)'),'name');
        $reviewAdds=[
            'reviewed_by'=>"TEXT NOT NULL DEFAULT ''",
            'reviewed_at'=>'REAL NOT NULL DEFAULT 0',
        ];
        foreach($reviewAdds as $column=>$definition) {
            if(!in_array($column,$reviewColumns,true))
                $db->exec('ALTER TABLE autopilot_log ADD COLUMN '.$column.' '.$definition);
        }
        // One row per tick, so the tick summary is stored, not just returned.
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_ticks (
            id INTEGER PRIMARY KEY, created_at REAL NOT NULL,
            provenance TEXT NOT NULL DEFAULT 'live', global_hold INTEGER NOT NULL DEFAULT 0,
            ok INTEGER NOT NULL DEFAULT 1, skipped TEXT NOT NULL DEFAULT '',
            shadow INTEGER NOT NULL DEFAULT 0, live INTEGER NOT NULL DEFAULT 0,
            holds INTEGER NOT NULL DEFAULT 0, errors_json TEXT NOT NULL DEFAULT '{}',
            verdict_json TEXT NOT NULL DEFAULT '{}', settings_version TEXT NOT NULL DEFAULT '',
            admission_evidence_json TEXT NOT NULL DEFAULT '{}')");
        // One row per digest that watched the shadow evidence, so "2 digests
        // watched" is a checkable fact. digest_date is unique: a re-run of
        // the same digest replaces its row.
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_digest_watches (
            id INTEGER PRIMARY KEY, digest_date TEXT NOT NULL UNIQUE, watched_at REAL NOT NULL,
            consecutive_correct INTEGER NOT NULL DEFAULT 0, boundary_close INTEGER NOT NULL DEFAULT 0,
            settings_version TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '')");
        // Staged live rollout (round six): one row (id=1) holding the live
        // admission stage and counts. Stages: 'first' (1 product), 'three'
        // (up to 3 total), 'open' (the per-day cap governs). The stage
        // advances only by an explicit API action, never on count.
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_admission_rollout (
            id INTEGER PRIMARY KEY, stage TEXT NOT NULL DEFAULT 'first',
            admitted_total INTEGER NOT NULL DEFAULT 0, admitted_today INTEGER NOT NULL DEFAULT 0,
            day TEXT NOT NULL DEFAULT '', updated_at REAL NOT NULL DEFAULT 0)");
        $now=microtime(true);
        $defaults=molavi_autopilot_defaults();
        $seed=[
            ['g5','publish',['note'=>'pure function of the other gates']],
            ['g4','market',['quote_band'=>$defaults['quote_band'],'reference_margin'=>$defaults['reference_margin']]],
            ['g3','supplier_price',['tolerance'=>$defaults['supplier_price_tolerance'],
                'consecutive_limit'=>$defaults['consecutive_auto_limit']]],
            ['gA','admission',['note'=>'composite admission rule','cap_per_day'=>$defaults['admission_cap_per_day']]],
        ];
        foreach($seed as [$id,$gate,$condition]) {
            molavi_domain_sql($db,"INSERT OR IGNORE INTO autopilot_rules
                (id,gate,condition_json,state,created_by,created_at) VALUES (?,?,?,'active','system',?)",
                [$id,$gate,molavi_domain_json($condition),$now]);
        }
    });
}

function molavi_autopilot_iso(float $stamp): string {
    $iso=DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',$stamp))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    return str_replace('.000000+00:00','+00:00',$iso);
}

// -------------------------------------------------------------------- logging

function molavi_domain_autopilot_log(SQLite3 $db,string $ruleId,string $gate,string $productId,
    string $caseId,array $inputs,?float $confidence,string $action,string $result,float $now,
    string $provenance='live',bool $globalHold=false,?float $closeness=null,string $closenessArm='',
    string $riskTier=''): int {
    // Provenance fails closed: an unknown value is recorded as a probe, never
    // as a live tick, so it can never count toward the §6 bar by accident.
    if(!in_array($provenance,['live','probe','test','legacy'],true))$provenance='probe';
    return (int)molavi_domain_sql($db,'INSERT INTO autopilot_log
        (rule_id,gate,product_id,case_id,inputs_json,confidence,action,result,verdict,created_at,
        provenance,global_hold,closeness,closeness_arm,risk_tier)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$ruleId,$gate,$productId,$caseId,molavi_domain_json($inputs),$confidence,$action,$result,'pending',$now,
            $provenance,(int)$globalHold,$closeness,$closenessArm,$riskTier])['last_id'];
}

function molavi_domain_autopilot_hold(SQLite3 $db,string $gate,string $productId,string $reason,
    float $score,float $value,float $ageDays,float $now): void {
    // Priority: lower = more urgent. Boundary distance (1 - score) dominates;
    // value and age only reorder holds whose distances are within ~0.013.
    $priority=(1-$score)-0.001*min(10,log10(max(1,$value)))-0.0001*min(30,$ageDays);
    molavi_domain_sql($db,'INSERT OR REPLACE INTO autopilot_holds
        (product_id,gate,reason,priority,score,created_at) VALUES (?,?,?,?,?,?)',
        [$productId,$gate,$reason,$priority,$score,$now]);
}

function molavi_domain_autopilot_live_count_today(SQLite3 $db,string $gate,float $dayStart): int {
    return (int)(molavi_domain_row($db,"SELECT COUNT(*) c FROM autopilot_log
        WHERE gate=? AND action='live' AND created_at>=?",[$gate,$dayStart])['c']??0);
}

function molavi_domain_autopilot_recently_logged(SQLite3 $db,string $gate,string $productId,
    string $action,float $since): bool {
    return (bool)molavi_domain_row($db,'SELECT 1 FROM autopilot_log
        WHERE gate=? AND product_id=? AND action=? AND created_at>=? LIMIT 1',
        [$gate,$productId,$action,$since]);
}

// ------------------------------------------- evidence: the §6 go-live bar
//
// Round-four finding: the go-live conditions in admission design §6 ("20
// consecutive correct shadow admissions, ≥3 boundary-close, 2 digests
// watched") existed only as prose — no counter, no boundary measure, no
// digest record anywhere in the code, and the count as practised (would_admit
// rows with verdict ok) was unsound: it counted test-run rows, repeat
// re-logs, and rows gathered under superseded settings. This section makes
// the bar a checkable fact:
//  * every log row carries its provenance (live tick vs probe/test) and the
//    global-hold state at evaluation time, so bypassed runs are excludable
//    by query, not by memory; rows written before this instrumentation carry
//    provenance 'legacy' (unknown) and never count;
//  * every gA row carries its closeness to the tightest arm threshold,
//    measured at decision time, stored as its confidence value;
//  * molavi_domain_autopilot_admission_evidence() counts distinct
//    product-decisions under that definition;
//  * every tick writes one autopilot_ticks row, and every digest that watches
//    the evidence writes one autopilot_digest_watches row.
//
// What this section deliberately does NOT do: durable hold/failure history
// (an arm failure between two admissions leaves no log row, so an
// intervening failure is invisible to the count — the count is over logged
// decisions only), and joins to owner-review events (an owner action outside
// the autopilot tab never touches autopilot_log, so "zero owner overrides on
// the same products" is only checkable for overrides made through the
// autopilot tab, which rewrite the row's result to 'overridden').

// Closeness of one admission to the tightest arm threshold, measured at
// decision time. Arms (each margin is 0 AT its threshold, growing as the
// decision moves clear of it):
//  * spread: (spread - min_margin) / min_margin, spread = (regular-cost) /
//    regular, min_margin from molavi_price_settings (0.12). Measured against
//    the min_margin floor, matching the existing Arm 7 hold score
//    (spread/0.12); min_profit (5000) can push the true band boundary
//    slightly higher for cheap products, so this margin is a lower bound on
//    the distance — a row it calls boundary-close is at most that far from
//    the floor. Boundary-close is spread 12–13.2%.
//  * supplier_age: (14 - supplier price age in days) / 14.
//  * regular_expiry: (regular price time remaining in days) / 14.
//  * quote_spread: (quote band - quote spread) / quote band, only when a
//    market source proves the regular price and ≥2 priced quotes exist.
// Returns ['closeness'=>float|null,'arm'=>string,'arms'=>[arm=>margin]].
// closeness is null only when no arm could be measured (missing timestamps
// and prices); gA then logs confidence 0.0 — fail closed, never 1.0.
function molavi_domain_autopilot_admission_closeness(array $settings,int $cost,int $regularAmount,
    ?float $supplierVerifiedAt,int $regularExpiresAt,?float $quoteSpread,float $now): array {
    $arms=[];
    $minMargin=function_exists('molavi_price_settings')
        ?(float)(molavi_price_settings()['min_margin']??0.12):0.12;
    if(!($minMargin>0))$minMargin=0.12;
    if($regularAmount>$cost&&$cost>0) {
        $spread=($regularAmount-$cost)/$regularAmount;
        $arms['spread']=($spread-$minMargin)/$minMargin;
    }
    if($supplierVerifiedAt!==null) {
        $ageDays=max(0.0,($now-$supplierVerifiedAt)/86400);
        $arms['supplier_age']=(MOLAVI_ADMISSION_PRICE_CLOCK_DAYS-$ageDays)/MOLAVI_ADMISSION_PRICE_CLOCK_DAYS;
    }
    if($regularExpiresAt>0)
        $arms['regular_expiry']=(($regularExpiresAt-$now)/86400)/MOLAVI_ADMISSION_PRICE_CLOCK_DAYS;
    $band=(float)($settings['quote_band']??0.05);
    if($quoteSpread!==null&&$band>0)
        $arms['quote_spread']=($band-$quoteSpread)/$band;
    if(!$arms)return ['closeness'=>null,'arm'=>'','arms'=>[]];
    $tightest=null;$arm='';
    foreach($arms as $name=>$margin) {
        if($tightest===null||$margin<$tightest) { $tightest=$margin;$arm=$name; }
    }
    return ['closeness'=>$tightest,'arm'=>$arm,'arms'=>$arms];
}

// Risk tier for one admission, mapped from the numeric closeness score
// (round six, workstream 1). Pure function of closeness + settings, so a
// stored row can be tiered retroactively from its closeness alone — the
// review surface re-runs this on old rows, it does not need a stored tier.
// Red is the §6 boundary-close band (settings risk_red_close, default
// MOLAVI_ADMISSION_BOUNDARY_CLOSE); amber is the quarter-scale band below.
//
// Why the amber cutoff is 0.25 — what that margin means on EACH arm, not
// just one (a margin is 0 AT its threshold, growing clear of it):
//  * spread: (spread-0.12)/0.12 — 0.25 is a 15% spread (boundary-close is
//    13.2%), so amber is a discount only 1.2–3 points above the 12% floor.
//  * supplier_age: (14-age)/14 — 0.25 is a 10.5-day-old supplier price
//    (boundary-close is 12.6 days), so amber is a price in the last
//    quarter of its 14-day clock.
//  * regular_expiry: remaining/14 — 0.25 is 3.5 days of regular price left
//    (boundary-close is 1.4 days).
//  * quote_spread: (0.05-spread)/0.05 — 0.25 is 3.75% quote disagreement
//    (boundary-close is 4.5%) against the 5% band.
// On the two clock arms the margin falls 1/14 per day, so an amber decision
// turns red within ~2 days of ordinary ageing alone — inside one digest
// cycle, which is exactly the band the owner needs to review promptly.
// Green therefore means every arm has at least a quarter of its scale in
// hand (spread ≥15%, supplier price ≤10.5 days old, regular price ≥3.5
// days to expiry, quote spread ≤3.75%). A smaller cutoff (e.g. 0.15) would
// leave the clock arms only ~12 hours from red — too late for a daily
// review; a larger one (e.g. 0.40) would flag spreads up to 16.8% and
// prices 8.4 days old, crying wolf on decisions with real headroom.
//
// Fail closed throughout: null / non-numeric / zero / negative closeness
// is red, and a missing or misconfigured cutoff (non-numeric, non-positive,
// or amber not above red) tiers red — tiering must never silently
// green-light a decision. A missing cutoff falls back to the settings
// default, never to 0 (which would make everything green).
function molavi_domain_autopilot_risk_tier($closeness,array $settings): string {
    if($closeness===null||!is_numeric($closeness))return 'red';
    $c=(float)$closeness;
    if($c<=0.0)return 'red';
    $red=$settings['risk_red_close']??MOLAVI_ADMISSION_BOUNDARY_CLOSE;
    $amber=$settings['risk_amber_close']??MOLAVI_ADMISSION_AMBER_CLOSE;
    if(!is_numeric($red)||!is_numeric($amber))return 'red';
    $red=(float)$red;$amber=(float)$amber;
    if($red<=0.0||$amber<=$red)return 'red';
    if($c<=$red)return 'red';
    if($c<=$amber)return 'amber';
    return 'green';
}

// Priced quotes from the market lookup behind a product's saved market
// source, cheapest first by per-unit regular price. [] when there is no
// lookup, no priced quote, or the market helpers are unavailable. Shared by
// the quote-spread arm and the verdict-prompt enrichment below, so both read
// the same rows.
function molavi_domain_autopilot_market_quotes(array $record): array {
    if(!function_exists('molavi_domain_market_identity'))return [];
    $identity=molavi_domain_market_identity($record);
    if(!$identity)return [];
    $row=molavi_domain_read('reference',static function(SQLite3 $db)use($identity): ?array {
        return molavi_domain_row($db,'SELECT candidates_json FROM market_price_lookups WHERE lookup_key=?',
            [molavi_domain_hash($identity)]);
    });
    if(!$row)return [];
    try { $quotes=molavi_domain_decode($row['candidates_json']??'[]',[]); }
    catch(Throwable $e) { return []; }
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

// One short line per quote list for the verdict prompt: per-unit regular
// price in تومان, merchant, pack count — cheapest first, at most 3. The
// prompt builder renders scalar inputs only, so the list travels as a
// string. '' when there is no priced quote.
function molavi_domain_autopilot_format_quotes(array $quotes): string {
    $parts=[];
    foreach(array_slice($quotes,0,3) as $quote) {
        $unit=(int)($quote['regular_unit_price']??$quote['unit_price']??0);
        if($unit<=0)continue;
        $label=trim((string)($quote['merchant']??''));
        if($label==='')$label='market';
        $pack=(int)($quote['pack_count']??0);
        if($pack>0)$label.=', pack '.$pack;
        $parts[]=$unit.' ('.$label.')';
    }
    return implode('; ',$parts);
}

// The market quote list behind a product, as one line for the verdict
// prompt. '' when there is no lookup or no priced quote.
function molavi_domain_autopilot_market_quote_list(array $record): string {
    return molavi_domain_autopilot_format_quotes(molavi_domain_autopilot_market_quotes($record));
}

// Rezvan catalog consumer price (pack basis) for a matched reference id,
// or 0 when there is no match or no catalog. The verdict sees the amount,
// not just the id, so it can check the regular price against the reference.
// Mirrors molavi_domain_reference_regular_deadline's snapshot scan.
function molavi_domain_autopilot_reference_price(string $referenceId): int {
    if($referenceId==='')return 0;
    if(!function_exists('molavi_domain_reference_active')
        ||!function_exists('molavi_domain_matching_reference'))return 0;
    static $prices=null;
    if($prices===null) {
        $prices=[];
        $source=molavi_domain_reference_active()??[];
        foreach($source['rows']??[] as $row) {
            if(!is_array($row)||count($row)<17)continue;
            $entry=molavi_domain_matching_reference($row);
            $prices[(string)($entry['reference_id']??'')]=(int)($entry['consumer_price']??0);
        }
    }
    return (int)($prices[$referenceId]??0);
}

// Quote spread for the market lookup behind a product's saved market source:
// (max - min) / median across priced quotes, mirroring G4's spread. Null
// when there is no lookup, fewer than two priced quotes, or the market
// helpers are unavailable — a single quote has no spread to measure.
function molavi_domain_autopilot_market_quote_spread(array $record): ?float {
    $quotes=molavi_domain_autopilot_market_quotes($record);
    $units=[];
    foreach($quotes as $quote)
        $units[]=(int)($quote['regular_unit_price']??$quote['unit_price']??0);
    if(count($units)<2)return null;
    sort($units);
    $median=$units[intdiv(count($units),2)];
    if($median<=0)return null;
    return (max($units)-min($units))/$median;
}

// The stored, queryable definition of consecutive-correct for the admission
// go-live bar (design §6). A row counts as one distinct correct
// product-decision only when ALL of these hold:
//  * rule gA, gate admission, action shadow, result would_admit;
//  * verdict 'ok' (pending and flag excluded);
//  * provenance 'live' — probe/test/legacy rows never count, so bypassed
//    runs are excludable by query, not by memory;
//  * global_hold 0 at evaluation time;
//  * inputs settings_version equals $settingsVersion — a settings move
//    restarts the count for the arm (design §8);
//  * a re-log of the same product counts only when a material input changed.
//    Material inputs are offer_id, supplier_price, regular_price, and the
//    spec revision (source_revision). A re-log with unchanged inputs is
//    excluded as 'relog'.
// A flag verdict or an owner override — or a revert of a live admission —
// on a product voids that product's earlier counted decisions: the streak
// for the product restarts after the failure. 'clean' reports whether ANY
// flag or override occurred in the period (the bar needs clean=true as well
// as count>=20). Boundary-close is a counted decision whose stored
// closeness is <= MOLAVI_ADMISSION_BOUNDARY_CLOSE; rows logged before this
// instrumentation have no closeness and can never count as boundary-close.
function molavi_domain_autopilot_admission_evidence(SQLite3 $db,string $settingsVersion): array {
    $rows=molavi_domain_rows($db,"SELECT id,product_id,inputs_json,verdict,result,provenance,global_hold,
        closeness,closeness_arm,created_at FROM autopilot_log
        WHERE rule_id='gA' AND gate='admission' AND action='shadow'
        AND result IN ('would_admit','overridden','reverted') ORDER BY created_at ASC, id ASC");
    $excluded=['provenance'=>0,'global_hold'=>0,'settings_version'=>0,'pending_verdict'=>0,
        'flag_verdict'=>0,'overridden'=>0,'relog'=>0];
    $counted=[]; // product_id => list of counted decisions, chronological
    $lastKey=[]; // product_id => material key of the last counted decision
    $flags=0;$overrides=0;
    foreach($rows as $row) {
        $productId=(string)($row['product_id']??'');
        if($productId==='')continue;
        try { $inputs=molavi_domain_decode($row['inputs_json']??'{}',[]); }
        catch(Throwable $e) { $inputs=[]; }
        if(!is_array($inputs))$inputs=[];
        // Probe/test/legacy rows are not live-tick evidence: excluded before
        // any verdict handling, so their verdicts cannot void live decisions.
        if((string)($row['provenance']??'legacy')!=='live') { $excluded['provenance']++; continue; }
        if(in_array((string)($row['result']??''),['overridden','reverted'],true)) {
            $overrides++;$excluded['overridden']++;
            unset($counted[$productId],$lastKey[$productId]);
            continue;
        }
        if((string)($row['verdict']??'')==='flag') {
            $flags++;$excluded['flag_verdict']++;
            unset($counted[$productId],$lastKey[$productId]);
            continue;
        }
        if((int)($row['global_hold']??0)!==0) { $excluded['global_hold']++; continue; }
        if((string)($inputs['settings_version']??'')!==$settingsVersion) { $excluded['settings_version']++; continue; }
        if((string)($row['verdict']??'')!=='ok') { $excluded['pending_verdict']++; continue; }
        $key=implode('|',[(string)($inputs['offer_id']??''),(string)($inputs['supplier_price']??''),
            (string)($inputs['regular_price']??''),(string)($inputs['source_revision']??'')]);
        if(($lastKey[$productId]??null)===$key) { $excluded['relog']++; continue; }
        $closeness=$row['closeness']!==null?(float)$row['closeness']
            :(isset($inputs['closeness'])&&is_numeric($inputs['closeness'])?(float)$inputs['closeness']:null);
        $arm=(string)($row['closeness_arm']??'')!==''?(string)$row['closeness_arm']:(string)($inputs['closeness_arm']??'');
        $lastKey[$productId]=$key;
        $counted[$productId][]=['log_id'=>(int)($row['id']??0),'closeness'=>$closeness,
            'closeness_arm'=>$arm,'created_at'=>(float)($row['created_at']??0)];
    }
    $decisions=[];$boundaryClose=0;$boundaryProducts=[];
    foreach($counted as $productId=>$list) {
        foreach($list as $decision) {
            $decision['product_id']=$productId;
            $decisions[]=$decision;
            if($decision['closeness']!==null&&$decision['closeness']<=MOLAVI_ADMISSION_BOUNDARY_CLOSE) {
                $boundaryClose++;$boundaryProducts[$productId]=true;
            }
        }
    }
    usort($decisions,static fn($a,$b)=>($a['created_at']<=>$b['created_at'])?:($a['log_id']<=>$b['log_id']));
    return [
        'settings_version'=>$settingsVersion,
        'count'=>count($decisions),
        'boundary_close'=>$boundaryClose,
        'boundary_close_products'=>array_keys($boundaryProducts),
        'flags'=>$flags,'overrides'=>$overrides,
        'clean'=>$flags===0&&$overrides===0,
        'products'=>array_map(static fn($list)=>count($list),$counted),
        'decisions'=>$decisions,
        'excluded'=>$excluded,
        'rows_examined'=>count($rows),
    ];
}

// ------------------------------------------- digest: new risk decisions
//
// Round six, workstream 4. The daily digest (09:25 Asia/Tehran) gains a
// section listing NEW medium/high-risk (amber/red) admission decisions.
// There is no digest-composition code in this repo — the digest is composed
// by a cron worker in the Main chat session that reads the staging API — so
// this section is the query + the section format + the exact worker call,
// not a sender. The worker reads GET /pipeline/autopilot field risk_digest
// and, after composing, POSTs record_digest_watch so the next digest's
// "new" window advances (see the API handler below for both).
//
// What "new" means, precisely. A gA decision row is NEW for a digest iff
// ALL of these hold:
//  1. rule gA, gate admission, action shadow/live, result would_admit /
//     admitted — a decision was actually made (shadow would-be or live).
//  2. provenance 'live' — probe/test/legacy rows never appear (same rule
//     as the §6 evidence count).
//  3. global_hold 0 at evaluation time.
//  4. tier amber or red, tiered at read time from the stored closeness
//     (green rows never appear). A live admission whose verdict is still
//     pending is forced red — the owner decision is that a live admission
//     may proceed while the verdict is pending, flagged red.
//  5. not overridden and not confirmed-as-reviewed (below).
//  6. timing: created after the last digest watch's watched_at — the
//     watches table (one row per digest date, INSERT OR REPLACE) is what
//     makes "not listed in a previous digest" checkable — OR its verdict
//     is currently 'flag'. The flag carve-out exists because a verdict is
//     stored IN PLACE on the log row (no verdict timestamp): a flag that
//     lands after the decision's digest is new information — the item was
//     auto-re-held and needs eyes now — so a flagged row keeps appearing
//     until the owner confirms it as reviewed. A flagged GREEN row is NOT
//     in this section; it stays in the digest's existing verdicts section
//     ("any flagged item at the very top").
//  7. one entry per product: the latest qualifying row per product_id.
//     A re-log only exists on a material input change or a shadow→live
//     flip (6h dedupe + material-change rule), so the latest row IS the
//     current decision; an older listed row never re-appears on its own.
// "New" is therefore checkable from stored rows alone: no digest-side
// memory beyond the watches table, and no listed-ids list to maintain.
//
// Confirm-as-reviewed (workstream 3 contract). A decision stops appearing
// once ANY of these is true — the first two shapes exist in this tree
// (override_log), the rest are the shapes workstream 3's confirm action
// must write for this query to honour it:
//  * the row's own result is 'overridden', 'reviewed' or 'confirmed';
//  * a later log row with action override/confirm/review references it in
//    inputs as overrides_log_id / confirms_log_id / reviewed_log_id /
//    confirmed_log_id / log_id;
//  * a later log row for the same product with action 'confirm' (or result
//    'reviewed'/'confirmed') — a product-level confirm suppresses that
//    product's decisions created BEFORE the confirm, never later ones.
//
// Ordering (autopilot design §10.2, uncertain-first): red before amber;
// within a tier, tightest closeness first (null closeness — unmeasurable —
// sorts first inside red, it is the riskiest); then highest regular price
// (value at stake); then oldest first (waiting time). Cap: top 10 entries
// (MOLAVI_RISK_DIGEST_LIMIT); total_new counts all qualifying decisions
// before the cap and omitted reports the remainder, so the digest can say
// how many it did not list.
//
// Digest section format (Persian, short lines — one line per decision):
//   تصمیم‌های پرریسک تازه (N مورد):
//   🔴 <نام کالا> — <تصمیم>، <ریسک>، حاشیه <closeness>؛ <چرا>
//   🟡 <نام کالا> — <تصمیم>، <ریسک>، حاشیه <closeness>؛ <چرا>
//   … و M مورد دیگر — همه در تب «تصمیم‌های خودکار»: <workbench URL>
// <تصمیم> is پذیرفته شد (زنده) / پذیرش در سایه / پرچم خورد / پذیرفته شد،
// بعد پرچم خورد و متوقف شد (decision admitted / would_admit / flagged /
// re_held). <ریسک> is پرخطر (red) / نیمه‌پرخطر (amber). <چرا> is the entry's
// why string (arm phrase + the raw numbers behind it). The section is
// omitted entirely when N is 0. The workbench URL is
// https://staging.molavisale.ir/wp-admin/admin.php?page=molavi-operations&view=autopilot
// (the 'تصمیم‌های خودکار' tab, owner-dashboard.js view=autopilot).
// Worked example (illustrative numbers, chosen so the spread arm yields
// closeness 0.05 — supplier 170,000 / regular 194,500 = 12.6% spread
// against the 12% floor; رانی's supplier-age arm yields 0.12 — 12.3 days
// old on a 14-day clock):
//   تصمیم‌های پرریسک تازه (۲ مورد):
//   🔴 کاپوچینو — پذیرفته شد (زنده)، پرخطر، حاشیه 0.05؛ اختلاف قیمت نزدیک کف تخفیف است (spread 12.6%، کف 12%)؛ نتیجهٔ بررسی مدل هنوز نیامده است.
//   🟡 رانی ۴ طعم — پذیرش در سایه، نیمه‌پرخطر، حاشیه 0.12؛ قیمت تأمین‌کننده نزدیک انقضاست (سن 12.3 روز از 14 روز).
//   همه در تب «تصمیم‌های خودکار»: https://staging.molavisale.ir/wp-admin/admin.php?page=molavi-operations&view=autopilot

const MOLAVI_RISK_DIGEST_LIMIT = 10;

// Risk tier for one decision, tiered at read time from its stored
// closeness. Workstream 1 owns molavi_domain_autopilot_risk_tier(); when
// it exists (post-merge) this defers to it, so the digest and the tab can
// never disagree. On this branch alone the fallback applies workstream 1's
// rules verbatim: null / non-numeric / zero / negative closeness is red,
// a missing or misconfigured cutoff tiers red, red is closeness <=
// risk_red_close (0.10, the §6 boundary-close band), amber is closeness <=
// risk_amber_close (0.25, quarter-scale), above that green.
function molavi_domain_autopilot_decision_risk_tier($closeness,array $settings): string {
    if(function_exists('molavi_domain_autopilot_risk_tier'))
        return molavi_domain_autopilot_risk_tier($closeness,$settings);
    if($closeness===null||!is_numeric($closeness))return 'red';
    $c=(float)$closeness;
    if($c<=0.0)return 'red';
    $red=$settings['risk_red_close']??MOLAVI_ADMISSION_BOUNDARY_CLOSE;
    $amber=$settings['risk_amber_close']??MOLAVI_ADMISSION_AMBER_CLOSE;
    if(!is_numeric($red)||!is_numeric($amber))return 'red';
    $red=(float)$red;$amber=(float)$amber;
    if($red<=0.0||$amber<=$red)return 'red';
    if($c<=$red)return 'red';
    if($c<=$amber)return 'amber';
    return 'green';
}

// The "why risky" line for one decision, in Persian: the tightest arm's
// phrase plus the raw numbers behind it. Numbers are included only when
// the inputs actually carry them — never faked, omitted when unavailable.
function molavi_domain_autopilot_risk_why(string $arm,?float $closeness,array $inputs,float $createdAt): string {
    switch($arm) {
        case 'spread':
            $why='اختلاف قیمت نزدیک کف تخفیف است';
            $spread=isset($inputs['spread'])&&is_numeric($inputs['spread'])?(float)$inputs['spread']:null;
            if($spread===null) {
                $cost=(int)($inputs['supplier_price']??0);$regular=(int)($inputs['regular_price']??0);
                if($regular>$cost&&$cost>0)$spread=($regular-$cost)/$regular;
            }
            if($spread!==null) {
                $floor=isset($inputs['min_margin'])&&is_numeric($inputs['min_margin'])
                    ?(float)$inputs['min_margin']:0.12;
                $why.=' (spread '.rtrim(rtrim(sprintf('%.1F',$spread*100),'0'),'.').'%، کف '
                    .rtrim(rtrim(sprintf('%.1F',$floor*100),'0'),'.').'%)';
            }
            return $why;
        case 'supplier_age':
            $why='قیمت تأمین‌کننده نزدیک انقضاست';
            if(isset($inputs['message_age_days'])&&is_numeric($inputs['message_age_days']))
                $why.=' (سن '.rtrim(rtrim(sprintf('%.1F',(float)$inputs['message_age_days']),'0'),'.')
                    .' روز از '.MOLAVI_ADMISSION_PRICE_CLOCK_DAYS.' روز)';
            return $why;
        case 'regular_expiry':
            $why='قیمت عادی نزدیک انقضاست';
            $expires=isset($inputs['regular_expires_at'])?strtotime((string)$inputs['regular_expires_at']):false;
            if($expires!==false) {
                $left=max(0.0,($expires-$createdAt)/86400);
                $why.=' ('.rtrim(rtrim(sprintf('%.1F',$left),'0'),'.').' روز مانده)';
            }
            return $why;
        case 'quote_spread':
            $why='نقل‌قول‌های بازار نزدیک حد اختلاف‌اند';
            $band=isset($inputs['quote_band'])&&is_numeric($inputs['quote_band'])
                ?(float)$inputs['quote_band']:0.05;
            $why.=' (باند '.rtrim(rtrim(sprintf('%.1F',$band*100),'0'),'.').'%)';
            return $why;
        default:
            return 'حاشیهٔ هیچ بازویی قابل‌اندازه‌گیری نیست';
    }
}

// New amber/red admission decisions since the last digest. $since is a
// unix timestamp, or null to derive the window from the latest digest
// watch (watched_at); with neither, the window is unbounded (first digest
// ever). $limit caps the returned entries (default 10); total_new counts
// all qualifying decisions before the cap. Returns ['since'=>?float,
// 'since_source'=>'param'|'watch'|'none', 'last_digest_date'=>?string,
// 'count'=>int, 'total_new'=>int, 'omitted'=>int, 'limit'=>int,
// 'decisions'=>[...]]; each decision carries log_id, product_id,
// product_name ('' here — the API GET fills it from the Products sheet),
// tier, closeness, closeness_arm, closeness_arms, decision, action,
// result, verdict, verdict_reason, verdict_pending, values (the numbers
// behind the risk), why (Persian), created_at, decided_at (ISO).
function molavi_domain_autopilot_recent_risk_decisions(SQLite3 $db,?float $since,array $settings,int $limit=10): array {
    $limit=max(1,$limit);
    $lastWatch=molavi_domain_row($db,'SELECT digest_date, watched_at FROM autopilot_digest_watches
        ORDER BY watched_at DESC, id DESC LIMIT 1');
    $sinceSource='none';$effectiveSince=$since;
    if($since!==null) {
        $sinceSource='param';
    } elseif(is_array($lastWatch)&&isset($lastWatch['watched_at'])&&is_numeric($lastWatch['watched_at'])) {
        $effectiveSince=(float)$lastWatch['watched_at'];
        $sinceSource='watch';
    }
    $lastDigestDate=is_array($lastWatch)&&(string)($lastWatch['digest_date']??'')!==''
        ?(string)$lastWatch['digest_date']:null;
    // Reviewed / overridden markers (workstream 3 contract — see the
    // section header): the row's own result, a later row referencing it,
    // or a later product-level confirm row.
    $reviewedIds=[];
    foreach(molavi_domain_rows($db,"SELECT id FROM autopilot_log
        WHERE result IN ('overridden','reviewed','confirmed')") as $row)
        $reviewedIds[(int)($row['id']??0)]=true;
    $confirmedAfter=[]; // product_id => latest confirm created_at
    foreach(molavi_domain_rows($db,"SELECT product_id, action, result, inputs_json, created_at FROM autopilot_log
        WHERE action IN ('override','confirm','review')
        OR result IN ('reviewed','confirmed','confirmed_reviewed')") as $row) {
        try { $ref=molavi_domain_decode($row['inputs_json']??'{}',[]); }
        catch(Throwable $e) { $ref=[]; }
        if(!is_array($ref))$ref=[];
        foreach(['overrides_log_id','confirms_log_id','reviewed_log_id','confirmed_log_id','log_id'] as $key) {
            if(isset($ref[$key])&&is_numeric($ref[$key]))$reviewedIds[(int)$ref[$key]]=true;
        }
        $productId=(string)($row['product_id']??'');
        $isConfirm=($row['action']??'')==='confirm'
            ||in_array($row['result']??'',['reviewed','confirmed','confirmed_reviewed'],true);
        if($isConfirm&&$productId!=='') {
            $at=(float)($row['created_at']??0);
            if(!isset($confirmedAfter[$productId])||$at>$confirmedAfter[$productId])
                $confirmedAfter[$productId]=$at;
        }
    }
    $rows=molavi_domain_rows($db,"SELECT id, product_id, inputs_json, action, result, verdict,
        verdict_reason, created_at, provenance, global_hold, closeness, closeness_arm
        FROM autopilot_log WHERE rule_id='gA' AND gate='admission'
        AND action IN ('shadow','live') AND result IN ('would_admit','admitted')
        ORDER BY created_at ASC, id ASC");
    $byProduct=[];
    foreach($rows as $row) {
        $logId=(int)($row['id']??0);
        $productId=(string)($row['product_id']??'');
        if($logId<=0||$productId==='')continue;
        // Probe/test/legacy rows and global-hold rows are not live evidence.
        if((string)($row['provenance']??'legacy')!=='live')continue;
        if((int)($row['global_hold']??0)!==0)continue;
        // Reviewed / overridden decisions never re-appear.
        if(isset($reviewedIds[$logId]))continue;
        $createdAt=(float)($row['created_at']??0);
        if(isset($confirmedAfter[$productId])&&$createdAt<=$confirmedAfter[$productId])continue;
        $verdict=(string)($row['verdict']??'pending');
        // New = created after the last digest watch, or currently flagged
        // (a flag landing after the decision's digest is new information).
        $inWindow=$effectiveSince===null||$createdAt>$effectiveSince;
        if(!$inWindow&&$verdict!=='flag')continue;
        try { $inputs=molavi_domain_decode($row['inputs_json']??'{}',[]); }
        catch(Throwable $e) { $inputs=[]; }
        if(!is_array($inputs))$inputs=[];
        $closeness=$row['closeness']!==null?(float)$row['closeness']
            :(isset($inputs['closeness'])&&is_numeric($inputs['closeness'])?(float)$inputs['closeness']:null);
        $arm=(string)($row['closeness_arm']??'')!==''?(string)$row['closeness_arm']:(string)($inputs['closeness_arm']??'');
        $action=(string)($row['action']??'');
        $verdictPending=$action==='live'&&$verdict==='pending';
        // A live admission may proceed while the verdict is pending —
        // flagged red. Every other tier comes from the closeness score.
        $tier=$verdictPending?'red':molavi_domain_autopilot_decision_risk_tier($closeness,$settings);
        if($tier==='green')continue;
        $decision=$verdict==='flag'
            ?($action==='live'?'re_held':'flagged')
            :($action==='live'?'admitted':'would_admit');
        $why=molavi_domain_autopilot_risk_why($arm,$closeness,$inputs,$createdAt);
        if($verdictPending)$why.='؛ نتیجهٔ بررسی مدل هنوز نیامده است.';
        if($verdict==='flag'&&trim((string)($row['verdict_reason']??''))!=='')
            $why.='؛ بررسی مدل: '.trim((string)$row['verdict_reason']);
        $supplierPrice=(int)($inputs['supplier_price']??0);
        $regularPrice=(int)($inputs['regular_price']??0);
        $spread=isset($inputs['spread'])&&is_numeric($inputs['spread'])?(float)$inputs['spread']
            :($regularPrice>$supplierPrice&&$supplierPrice>0
                ?($regularPrice-$supplierPrice)/$regularPrice:0.0);
        $entry=[
            'log_id'=>$logId,
            'product_id'=>$productId,
            'product_name'=>'',
            'tier'=>$tier,
            'closeness'=>$closeness,
            'closeness_arm'=>$arm,
            'closeness_arms'=>is_array($inputs['closeness_arms']??null)?$inputs['closeness_arms']:[],
            'decision'=>$decision,
            'action'=>$action,
            'result'=>(string)($row['result']??''),
            'verdict'=>$verdict,
            'verdict_reason'=>(string)($row['verdict_reason']??''),
            'verdict_pending'=>$verdictPending,
            'values'=>[
                'supplier_price'=>$supplierPrice,
                'regular_price'=>$regularPrice,
                'spread'=>$spread,
                'min_margin'=>isset($inputs['min_margin'])&&is_numeric($inputs['min_margin'])
                    ?(float)$inputs['min_margin']:0.12,
                'pack'=>(int)($inputs['pack']??0),
                'supplier_state'=>(string)($inputs['supplier_state']??''),
                'regular_state'=>(string)($inputs['regular_state']??''),
                'message_age_days'=>isset($inputs['message_age_days'])&&is_numeric($inputs['message_age_days'])
                    ?(float)$inputs['message_age_days']:null,
                'supplier_verified_at'=>(string)($inputs['supplier_verified_at']??''),
                'regular_expires_at'=>(string)($inputs['regular_expires_at']??''),
                'quote_list'=>(string)($inputs['quote_list']??''),
                'reference_price'=>(int)($inputs['reference_price']??0),
                'offer_id'=>(string)($inputs['offer_id']??''),
                'source_revision'=>(string)($inputs['source_revision']??''),
            ],
            'why'=>$why,
            'created_at'=>$createdAt,
            'decided_at'=>molavi_autopilot_iso($createdAt),
        ];
        // One entry per product: the latest qualifying row wins. A re-log
        // only exists on a material change or a shadow→live flip, so the
        // latest row is the current decision.
        if(!isset($byProduct[$productId])
            ||$createdAt>$byProduct[$productId]['created_at']
            ||($createdAt===$byProduct[$productId]['created_at']&&$logId>$byProduct[$productId]['log_id']))
            $byProduct[$productId]=$entry;
    }
    $decisions=array_values($byProduct);
    // Uncertain-first (§10.2): red before amber; tightest closeness first
    // (null — unmeasurable — first inside red); then highest regular price
    // (value at stake); then oldest first (waiting time).
    $rank=['red'=>0,'amber'=>1];
    usort($decisions,static function(array $a,array $b)use($rank): int {
        $tier=($rank[$a['tier']]??9)<=>($rank[$b['tier']]??9);
        if($tier!==0)return $tier;
        $ca=$a['closeness']===null?-INF:(float)$a['closeness'];
        $cb=$b['closeness']===null?-INF:(float)$b['closeness'];
        $close=$ca<=>$cb;
        if($close!==0)return $close;
        $value=(int)($b['values']['regular_price']??0)<=>(int)($a['values']['regular_price']??0);
        if($value!==0)return $value;
        return ($a['created_at']<=>$b['created_at'])?:($a['log_id']<=>$b['log_id']);
    });
    $totalNew=count($decisions);
    $decisions=array_slice($decisions,0,$limit);
    return [
        'since'=>$effectiveSince,
        'since_source'=>$sinceSource,
        'last_digest_date'=>$lastDigestDate,
        'count'=>count($decisions),
        'total_new'=>$totalNew,
        'omitted'=>max(0,$totalNew-count($decisions)),
        'limit'=>$limit,
        'decisions'=>$decisions,
    ];
}

// Product names for the digest entries, from the Products sheet (5s
// transient cache inside molavi_domain_sheets; a cold read is one Google
// batchGet). Best effort by design: any failure returns the names it has
// (or none) and the API GET leaves product_name '' — the digest worker
// then resolves the name from product_id. Never fails the API GET.
function molavi_domain_autopilot_product_names(array $productIds): array {
    $ids=array_values(array_unique(array_filter(array_map('strval',$productIds),
        static fn($id)=>$id!=='')));
    if(!$ids||!function_exists('molavi_domain_sheets')||!function_exists('molavi_domain_records'))
        return [];
    try {
        $tables=molavi_domain_sheets(['Products'],false);
        $records=molavi_domain_records($tables['Products']??[]);
        $names=[];
        foreach($records as $record) {
            $id=trim((string)($record['Product ID']??''));
            if($id!==''&&in_array($id,$ids,true)&&!isset($names[$id]))
                $names[$id]=trim((string)($record['Product Name']??''));
        }
        return $names;
    } catch(Throwable $e) {
        return [];
    }
}

// Review surface (round six, workstream 3): the effective risk tier for
// one log row, as the tab renders it. The tier is the STORED risk_tier
// written by workstream 1 (molavi_domain_autopilot_risk_tier) — on the log
// row, falling back to the admission receipt — and is NOT re-derived from
// closeness here: a row with no tier stored (logged before tiering)
// renders red, fail closed, never green. A pending verdict is red too: a
// live admission may proceed while the verdict is pending, but it is
// flagged red until the verdict lands. Tiering is defined only for
// admission decisions (the only rows with a closeness margin); every other
// gate returns 'none' — the tab renders those with a neutral "not tiered"
// badge, never green and never red.
function molavi_domain_autopilot_review_risk_tier(array $row,?string $receiptTier): string {
    if((string)($row['gate']??'')!=='admission')return 'none';
    if((string)($row['verdict']??'')==='pending')return 'red';
    $stored=(string)($row['risk_tier']??'');
    if(!in_array($stored,['green','amber','red'],true))$stored=(string)($receiptTier??'');
    return in_array($stored,['green','amber','red'],true)?$stored:'red';
}

// One Persian line stating WHY a row has its tier: the tightest arm, the
// stored margin, and the values behind it. Built from the stored closeness
// inputs only (no fresh reads), so the reason is the reason at decision
// time. '' when the row carries no closeness data.
function molavi_domain_autopilot_risk_reason(array $row,array $inputs): string {
    $arm=(string)($row['closeness_arm']??'')!==''?(string)$row['closeness_arm']:(string)($inputs['closeness_arm']??'');
    $closeness=$row['closeness']!==null?(float)$row['closeness']
        :(isset($inputs['closeness'])&&is_numeric($inputs['closeness'])?(float)$inputs['closeness']:null);
    if($arm===''||$closeness===null)return '';
    $labels=['spread'=>'اختلاف قیمت','supplier_age'=>'تازگی قیمت تأمین‌کننده',
        'regular_expiry'=>'انقضای قیمت عادی','quote_spread'=>'پراکندگی نقل‌قول‌ها'];
    $label=$labels[$arm]??$arm;
    $detail='';
    if($arm==='spread'&&isset($inputs['spread'],$inputs['min_margin'])) {
        $detail='اختلاف '.number_format((float)$inputs['spread']*100,1,'.','').'% در برابر کف '
            .number_format((float)$inputs['min_margin']*100,1,'.','').'%';
    } elseif($arm==='supplier_age'&&!empty($inputs['supplier_verified_at'])) {
        $verified=molavi_domain_timestamp($inputs['supplier_verified_at']);
        $age=$verified!==null?max(0.0,((float)($row['created_at']??0)-$verified)/86400):null;
        if($age!==null)$detail='قیمت تأمین‌کننده '.number_format($age,1,'.','').' روز پیش تأیید شده؛ سقف ۱۴ روز';
    } elseif($arm==='regular_expiry'&&!empty($inputs['regular_expires_at'])) {
        $expires=molavi_domain_timestamp($inputs['regular_expires_at']);
        $left=$expires!==null?(($expires-(float)($row['created_at']??0))/86400):null;
        if($left!==null)$detail='قیمت عادی '.number_format(max(0.0,$left),1,'.','').' روز دیگر معتبر بود';
    } elseif($arm==='quote_spread'&&isset($inputs['quote_band'])) {
        $arms=is_array($inputs['closeness_arms']??null)?$inputs['closeness_arms']:[];
        if(isset($arms['quote_spread'])&&is_numeric($arms['quote_spread'])) {
            $band=(float)$inputs['quote_band'];
            $spread=$band*(1-(float)$arms['quote_spread']);
            $detail='پراکندگی نقل‌قول‌ها '.number_format($spread*100,2,'.','').'% در برابر بازهٔ '
                .number_format($band*100,1,'.','').'%';
        }
    }
    $reason='نزدیک‌ترین آستانه: '.$label.' — حاشیه '.number_format($closeness,3,'.','');
    return $detail==='' ? $reason : $reason.' ('.$detail.')';
}

// "New" decisions for the digest (round six, workstream 4 reads this):
// a decision row the owner has not marked reviewed yet, that was not
// undone. Only live/shadow decision rows count — override/revert/hold/
// confirm/settings rows are audit trail, not decisions. A confirm action
// sets reviewed_at on the original row, which drops it from this list; an
// override/revert drops it by result instead.
function molavi_domain_autopilot_new_decisions(SQLite3 $db,int $limit=100): array {
    return molavi_domain_rows($db,"SELECT * FROM autopilot_log
        WHERE action IN ('shadow','live') AND result NOT IN ('overridden','reverted')
        AND reviewed_at=0 ORDER BY created_at DESC, id DESC LIMIT ?",[max(1,$limit)]);
}

function molavi_domain_autopilot_unreviewed_count(SQLite3 $db): int {
    $row=molavi_domain_row($db,"SELECT COUNT(*) c FROM autopilot_log
        WHERE action IN ('shadow','live') AND result NOT IN ('overridden','reverted') AND reviewed_at=0");
    return (int)($row['c']??0);
}

// One persisted tick record. Best effort by design: the caller wraps this in
// try/catch, so a logging failure never fails the tick.
function molavi_domain_autopilot_record_tick(SQLite3 $db,array $summary,string $provenance,float $now): int {
    $errors=is_array($summary['errors']??null)?$summary['errors']:[];
    $verdict=is_array($summary['verdict']??null)?$summary['verdict']:[];
    $evidence=is_array($summary['admission_evidence']??null)?$summary['admission_evidence']:[];
    return (int)molavi_domain_sql($db,'INSERT INTO autopilot_ticks
        (created_at,provenance,global_hold,ok,skipped,shadow,live,holds,errors_json,verdict_json,
        settings_version,admission_evidence_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$now,$provenance,(int)!empty($summary['global_hold']),(int)!empty($summary['ok']),
            (string)($summary['skipped']??''),(int)($summary['shadow']??0),(int)($summary['live']??0),
            (int)($summary['holds']??0),molavi_domain_json($errors),molavi_domain_json($verdict),
            (string)($summary['settings_version']??''),molavi_domain_json($evidence)])['last_id'];
}

function molavi_domain_autopilot_ticks(SQLite3 $db,int $limit=10): array {
    return molavi_domain_rows($db,'SELECT * FROM autopilot_ticks ORDER BY created_at DESC, id DESC LIMIT ?',[max(1,$limit)]);
}

// One persisted digest-watch record: the digest that ran on $digestDate
// watched the shadow evidence and saw this standing. digest_date is unique:
// a re-run of the same digest replaces its row.
function molavi_domain_autopilot_record_digest_watch(SQLite3 $db,string $digestDate,int $consecutiveCorrect,
    int $boundaryClose,string $settingsVersion,string $note,float $now): int {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$digestDate);
    if(!$date||$date->format('Y-m-d')!==$digestDate||$consecutiveCorrect<0||$boundaryClose<0)
        molavi_domain_fail('invalid_body',400);
    return (int)molavi_domain_sql($db,'INSERT OR REPLACE INTO autopilot_digest_watches
        (digest_date,watched_at,consecutive_correct,boundary_close,settings_version,note)
        VALUES (?,?,?,?,?,?)',
        [$digestDate,$now,$consecutiveCorrect,$boundaryClose,$settingsVersion,$note])['last_id'];
}

function molavi_domain_autopilot_digest_watches(SQLite3 $db,int $limit=10): array {
    return molavi_domain_rows($db,'SELECT * FROM autopilot_digest_watches ORDER BY digest_date DESC, id DESC LIMIT ?',[max(1,$limit)]);
}

// --------------------------------------------------------------- verdict (AI)

const MOLAVI_AUTOPILOT_VERDICT_MAX_PER_TICK = 10;

// Railway verdict round-trips (possible cold start + model call, 45s proxy
// timeout) are far slower than a direct call. Cap the verdicts per tick so
// the step stays inside the runtime-host lock window (stale after 100s);
// the pending queue drains over successive ticks, oldest first.
const MOLAVI_AUTOPILOT_VERDICT_RAILWAY_MAX_PER_TICK = 3;

// Evidence instrumentation (admission design §6). A margin is 0 AT its
// threshold and grows as the decision moves clear of it; boundary-close is a
// tightest margin within 10% of the threshold. The price clock is 14 days
// for both the supplier-age arm and the regular-expiry arm.
const MOLAVI_ADMISSION_BOUNDARY_CLOSE = 0.10;
const MOLAVI_ADMISSION_AMBER_CLOSE = 0.25;
const MOLAVI_ADMISSION_PRICE_CLOCK_DAYS = 14;

function molavi_domain_autopilot_verdict_prompt(array $row): string {
    try { $inputs=molavi_domain_decode($row['inputs_json']??'{}',[]); }
    catch(Throwable $e) { $inputs=[]; }
    if(!is_array($inputs))$inputs=[];
    $lines=[];
    $lines[]='Gate: '.(string)($row['gate']??'');
    $lines[]='Rule: '.(string)($row['rule_id']??'');
    $lines[]='Action: '.(string)($row['action']??'').' ('.(string)($row['result']??'').')';
    $lines[]='Confidence: '.(is_numeric($row['confidence']??null)
        ?(string)round((float)$row['confidence'],3):'unknown');
    foreach($inputs as $key=>$value) {
        if($key==='settings_version'||!is_scalar($value))continue;
        $lines[]=(string)$key.': '.(string)$value;
    }
    return "You are a second-opinion checker for an automated grocery-publish system. "
        ."A deterministic rule made the decision below. Judge ONLY whether the evidence supports it. "
        ."Do not invent prices or facts. Reply with JSON only, in this shape: "
        ."{\"verdict\":\"ok\",\"reason\":\"...\"}. Use verdict \"ok\" when the evidence supports the decision, "
        ."and \"flag\" when something looks wrong (for example prices that do not agree, a spread wider "
        ."than the claimed basis, or evidence missing). Write the reason in correct, standard Persian, "
        ."one short sentence, with proper spelling and نیم‌فاصله where Persian words need it.\n\n"
        .implode("\n",$lines);
}

// A flagged verdict on a LIVE-admitted product auto-re-holds it (round
// six). This is the one place a verdict changes product state: the
// product is un-published through the EXISTING hide/hold path in sync.php
// (molavi_mark_product_held + molavi_hide_product — the same pair the
// publisher's own hide sequence uses), never by writing Woo state here.
// It also writes a 'reheld' log row (rule_id '' so the verdict runner
// never re-judges this administrative row) carrying the flag reason, the
// Woo id, and whether the state actually changed — that row is what the
// tab shows. The autopilot hold row is written by the caller, before
// this runs, so a re-admission is blocked even if the hide itself fails.
// Never resolves a hold, never sets a price, never approves a chat: the
// only mutating calls here are the two hold-creating sync functions.
// Returns ['reheld'=>bool,'woo_id'=>?int,'hide_ok'=>bool,'held_ok'=>bool].
function molavi_domain_autopilot_rehold_live_admission(array $row,float $now): array {
    $logId=(int)($row['id']??0);
    $productId=(string)($row['product_id']??'');
    $reason=(string)($row['verdict_reason']??'');
    $out=['reheld'=>false,'woo_id'=>null,'hide_ok'=>false,'held_ok'=>false];
    if($logId<=0||$productId==='')return $out;
    // The Woo id comes from the admission receipt (the log row's own
    // link); fall back to the registered-products map.
    $clean=['sale_offer_expires_at'=>0,'market_image_id'=>0,'supplier_availability'=>'',
        'source_record_id'=>$productId,'supplier_price_state'=>'','supplier_price_verified_at'=>''];
    $wooId=null;
    $receipt=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_row($db,'SELECT receipt_json FROM autopilot_admission_receipts WHERE log_id=?',[$logId]));
    if(is_array($receipt)) {
        try { $decoded=molavi_domain_decode((string)($receipt['receipt_json']??'{}'),[]); }
        catch(Throwable $e) { $decoded=[]; }
        if(is_array($decoded)) {
            if(!empty($decoded['woo_id']))$wooId=(int)$decoded['woo_id'];
            $clean['sale_offer_expires_at']=(int)($decoded['sale_offer']['expires_at']??0);
            $clean['supplier_availability']=(string)($decoded['stock']??'');
            $clean['supplier_price_state']=(string)($decoded['supplier']['state']??'');
            $clean['supplier_price_verified_at']=(string)($decoded['supplier']['verified_at']??'');
        }
    }
    if($wooId===null&&function_exists('molavi_domain_registered_products')) {
        foreach(molavi_domain_registered_products() as $registered) {
            if((string)($registered['source_record_id']??'')===$productId&&!empty($registered['woo_product_id'])) {
                $wooId=(int)$registered['woo_product_id'];break;
            }
        }
    }
    $out['woo_id']=$wooId;
    if($wooId!==null&&$wooId>0) {
        try {
            if(function_exists('molavi_mark_product_held'))
                $out['held_ok']=(bool)molavi_mark_product_held($wooId,$clean);
        } catch(Throwable $e) { $out['held_ok']=false; }
        try {
            if(function_exists('molavi_hide_product'))
                $out['hide_ok']=(bool)molavi_hide_product($wooId);
        } catch(Throwable $e) { $out['hide_ok']=false; }
        // hide_product returns false when the product is ALREADY hidden
        // (draft/hidden/outofstock) — that still counts as held.
        if(!$out['hide_ok']&&function_exists('molavi_product_is_hidden')) {
            try { if(molavi_product_is_hidden($wooId))$out['hide_ok']=true; } catch(Throwable $e) {}
        }
    }
    $out['reheld']=$out['hide_ok']||$out['held_ok'];
    $inputs=['reholds_log_id'=>$logId,'woo_id'=>$wooId,'hide_ok'=>$out['hide_ok'],
        'held_ok'=>$out['held_ok'],'verdict_reason'=>$reason];
    $riskTier=(string)($row['risk_tier']??'');
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$inputs,$now,$riskTier): void {
        molavi_domain_autopilot_log($db,'','admission',$productId,'',$inputs,null,'live','reheld',$now,
            'live',false,null,'',$riskTier);
    });
    return $out;
}

function molavi_domain_autopilot_run_verdicts(array $settings,float $now): array {
    $counts=['checked'=>0,'approved'=>0,'flagged'=>0,'not_run'=>0,'reheld'=>0];
    $sampling=(string)($settings['verdict_sampling']??'all');
    if($sampling==='off')return $counts;
    $limit=min($sampling==='all'?MOLAVI_AUTOPILOT_VERDICT_MAX_PER_TICK:5,
        MOLAVI_AUTOPILOT_VERDICT_RAILWAY_MAX_PER_TICK);
    $rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_rows($db,"SELECT * FROM autopilot_log WHERE verdict='pending'
            AND rule_id<>'' AND action IN ('shadow','live') AND result NOT IN ('overridden','reverted')
            ORDER BY created_at ASC, id ASC LIMIT ?",[$limit]));
    foreach($rows as $row) {
        $id=(int)($row['id']??0);
        if($id<=0)continue;
        $counts['checked']++;
        $verdict=molavi_domain_gemini_generate(molavi_domain_autopilot_verdict_prompt($row));
        if(!$verdict) { $counts['not_run']++; continue; }
        $reason=trim((string)($verdict['reason']??''));
        // The model sometimes emits exotic space characters (U+2004/U+2005)
        // where Persian needs نیم‌فاصله or a plain space — one verdict came
        // back with "قمیت ها" (U+2004) instead of "قیمت‌ها". A plain space
        // is the safe fallback. This fixes spacing only: it cannot fix
        // misspelled words, which only a fresh verdict can.
        $reason=str_replace(["\xE2\x80\x84","\xE2\x80\x85"],' ',$reason);
        if(function_exists('mb_substr'))$reason=mb_substr($reason,0,500);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$verdict,$reason): void {
            molavi_domain_sql($db,'UPDATE autopilot_log SET verdict=?, verdict_reason=? WHERE id=?',
                [(string)$verdict['verdict'],$reason,$id]);
        });
        if($verdict['verdict']==='ok') { $counts['approved']++; continue; }
        $counts['flagged']++;
        $productId=(string)($row['product_id']??'');
        $gate=(string)($row['gate']??'');
        if($productId!==''&&$gate!=='') {
            // Score 1.0 puts the hold at priority 0.0: ahead of the closest
            // boundary hold (about 0.055 for 5.3% vs a 5% cap).
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($gate,$productId,$reason,$now): void {
                molavi_domain_autopilot_hold($db,$gate,$productId,$reason,1.0,0.0,0.0,$now);
            });
            // A flagged verdict on a live-admitted product auto-re-holds
            // it (round six): un-publish + hold + 'reheld' log row. The
            // helper never throws (it records a failed hide in its log
            // row instead), but the verdict run must survive it anyway.
            if($gate==='admission'&&($row['action']??'')==='live'&&($row['result']??'')==='admitted') {
                try {
                    unset($GLOBALS['molavi_storefront_blocks']);
                    $row['verdict_reason']=$reason;
                    $reheld=molavi_domain_autopilot_rehold_live_admission($row,$now);
                    if(!empty($reheld['reheld']))$counts['reheld']++;
                } catch(Throwable $e) {
                    error_log('[molavi-native] autopilot_rehold_failed');
                }
            }
        }
    }
    // The rules wipe every hold for their gate at the start of each tick, so
    // a flagged hold would vanish on the next tick unless re-asserted here.
    // Re-assert flags from the last 7 days (skipping overridden decisions).
    $flagged=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_rows($db,"SELECT product_id, gate, verdict_reason FROM autopilot_log AS flag
            WHERE verdict='flag' AND rule_id<>'' AND action IN ('shadow','live')
            AND result NOT IN ('overridden','reverted') AND created_at>=?
            AND NOT (flag.gate='admission' AND flag.action='shadow' AND EXISTS (
                SELECT 1 FROM autopilot_log newer WHERE newer.product_id=flag.product_id AND newer.id>flag.id
                AND newer.gate='admission' AND newer.action='live' AND newer.result='admitted'
                AND newer.provenance='live' AND newer.verdict='ok'))
            ORDER BY created_at ASC, id ASC",
            [$now-7*86400]));
    foreach($flagged as $flag) {
        $productId=(string)($flag['product_id']??'');
        $gate=(string)($flag['gate']??'');
        $reason=(string)($flag['verdict_reason']??'');
        if($productId===''||$gate===''||$reason==='')continue;
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($gate,$productId,$reason,$now): void {
            molavi_domain_autopilot_hold($db,$gate,$productId,$reason,1.0,0.0,0.0,$now);
        });
    }
    if($counts['not_run']>0&&$counts['approved']===0&&$counts['flagged']===0)
        $counts['error']='verdict_failed';
    return $counts;
}

// ---------------------------------------------------------------------- tick

function molavi_domain_autopilot_tick(?float $clock=null,string $provenance='live'): array {
    // Provenance: 'live' is the host cron (molavi-runtime-host.php calls the
    // tick with no provenance). Probes, test runs and manual invocations MUST
    // pass 'probe' or 'test' — otherwise their rows are indistinguishable
    // from live-tick rows and would wrongly count toward the §6 bar. An
    // unknown value fails closed to 'probe'.
    if(!in_array($provenance,['live','probe','test'],true))$provenance='probe';
    $now=$clock??microtime(true);
    $summary=['ok'=>true,'shadow'=>0,'live'=>0,'holds'=>0,'errors'=>[],'global_hold'=>false];
    $settings=null;
    try {
        molavi_domain_autopilot_ensure_tables();
        if(!get_option('molavi_state_active',false)) {
            $summary['skipped']='state_inactive';
        } else {
        $settings=molavi_autopilot_settings();
        $summary['settings_version']=molavi_autopilot_settings_version($settings);
        $tables=molavi_domain_sheets(['Products','Offers'],false);
        $dayStart=(float)strtotime(gmdate('Y-m-d',(int)$now).' 00:00:00 UTC');
        // The global hold state at evaluation time, read once and passed to
        // every rule so each log row records it (see §6 evidence).
        $globalHold=(bool)molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT 1 FROM publication_holds LIMIT 1'));
        $summary['global_hold']=$globalHold;
        foreach(['g5','g4','g3','gA'] as $gate) {
            try {
                $result=molavi_domain_autopilot_rule($gate,$settings,$tables,$now,$dayStart,$provenance,$globalHold);
                $summary['shadow']+=(int)($result['shadow']??0);
                $summary['live']+=(int)($result['live']??0);
                $summary['holds']+=(int)($result['holds']??0);
            } catch(Throwable $e) {
                $summary['errors'][$gate]='rule_failed';
                error_log('[molavi-native] autopilot_rule_failed gate='.$gate);
            }
        }
        // Post-decision verdicts. A verdict never fails the tick: its own
        // errors are recorded under errors['verdict'] and the rows stay pending.
        if(!function_exists('molavi_domain_gemini_generate')) {
            $summary['errors']['verdict']='ai_unavailable';
            $summary['verdict']=['checked'=>0,'approved'=>0,'flagged'=>0,'not_run'=>0];
        } else {
            try {
                $summary['verdict']=molavi_domain_autopilot_run_verdicts($settings,$now);
                if(!empty($summary['verdict']['error']))
                    $summary['errors']['verdict']=(string)$summary['verdict']['error'];
            } catch(Throwable $e) {
                $summary['errors']['verdict']='verdict_failed';
                error_log('[molavi-native] autopilot_verdict_failed');
            }
        }
        } // end state_active else
    } catch(Throwable $e) {
        $summary['ok']=false;$summary['errors']['tick']='tick_failed';
        error_log('[molavi-native] autopilot_tick_failed');
    }
    // The §6 bar, computed from the stored rows (not a hand count), and the
    // tick record itself — best effort, never fails the tick. A skipped or
    // failed tick still writes its record (with the standing evidence where
    // settings loaded), so gaps in the tick history are visible.
    try {
        if(is_array($settings)) {
            $summary['admission_evidence']=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_admission_evidence($db,(string)($summary['settings_version']??'')));
            $tickDayStart=(float)strtotime(gmdate('Y-m-d',(int)$now).' 00:00:00 UTC');
            $summary['admission_rollout']=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_admission_rollout($db,$tickDayStart,
                    (int)($settings['admission_cap_per_day']??5),$now));
        }
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($summary,$provenance,$now): void {
            molavi_domain_autopilot_record_tick($db,$summary,$provenance,$now);
        });
    } catch(Throwable $e) {
        error_log('[molavi-native] autopilot_tick_record_failed');
    }
    return $summary;
}

function molavi_domain_autopilot_rule(string $gate,array $settings,array $tables,float $now,float $dayStart,
    string $provenance='live',bool $globalHold=false): array {
    $state=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_row($db,'SELECT state FROM autopilot_rules WHERE id=?',[$gate]));
    if(($state['state']??'')==='suspended')return ['shadow'=>0,'live'=>0,'holds'=>0];
    $mode=(string)($settings['rule_modes'][$gate]??'shadow');
    $live=$provenance==='live'&&!empty($settings['master_enabled'])&&$mode==='live';
    $shadow=(bool)($settings['shadow_enabled']??true);
    if(!$live&&!$shadow)return ['shadow'=>0,'live'=>0,'holds'=>0];
    return match($gate){
        'g5'=>molavi_domain_autopilot_rule_publish($settings,$tables,$now,$dayStart,$live,$shadow,$provenance,$globalHold),
        'g4'=>molavi_domain_autopilot_rule_market($settings,$tables,$now,$dayStart,$live,$shadow,$provenance,$globalHold),
        'g3'=>molavi_domain_autopilot_rule_supplier_price($settings,$tables,$now,$dayStart,$live,$shadow,$provenance,$globalHold),
        'gA'=>molavi_domain_autopilot_rule_admission($settings,$tables,$now,$dayStart,$live,$shadow,$provenance,$globalHold),
        default=>['shadow'=>0,'live'=>0,'holds'=>0],
    };
}

// ------------------------------------------------------------- rule G5: publish

function molavi_domain_autopilot_rule_publish(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow,string $provenance='live',bool $globalHold=false): array {
    $counts=['shadow'=>0,'live'=>0,'holds'=>0];
    $records=molavi_domain_records($tables['Products']??[]);
    $offers=molavi_domain_records($tables['Offers']??[]);
    $offersById=array_column($offers,null,'Offer ID');
    $evidence=molavi_domain_read('pipeline','molavi_domain_price_evidence_by_product');
    $market=function_exists('molavi_domain_market_catalog_sources')
        ?molavi_domain_market_catalog_sources($records,$offers):[];
    $published=array_column(molavi_domain_published_review_catalog(),'purchasable','source_record_id');
    $issuesByProduct=[];
    foreach(molavi_domain_source_issues($records,$offers) as $issue)
        if(($issue['product_id']??'')!=='')$issuesByProduct[$issue['product_id']]=$issue['codes']??[];
    // The tick reads the global hold once and passes it in, so the hold rows
    // and the log rows below record the same state.
    $held=$globalHold;
    $version=molavi_autopilot_settings_version($settings);
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($now): void {
        molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate='publish'");
    });
    foreach($records as $record) {
        $id=trim((string)($record['Product ID']??''));
        if($id==='')continue;
        $offerId=trim((string)($record['Latest Offer ID']??''));
        if(($record['Status']??'')!=='imported'||$offerId===''||!isset($offersById[$offerId]))continue;
        $price=molavi_domain_sale_offer_evidence($record,$offersById[$offerId],
            molavi_domain_product_saved_price($record,$evidence,$now),$market[$id]['price']??null,(int)$now);
        if($price['sale_offer_blocker']!==''||!empty($published[$id]))continue;
        if(!molavi_domain_supplier_available($record))continue;
        if(!empty($issuesByProduct[$id]??[]))continue;
        // Candidate: publication_needed, a pure function of the other gates.
        $value=(float)molavi_domain_positive_price($record['Sale Price']??null);
        $ageDays=max(0,($now-(molavi_domain_timestamp($offersById[$offerId]['Import Timestamp']??null)??$now))/86400);
        if($held) {
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$now,$value,$ageDays): void {
                molavi_domain_autopilot_hold($db,'publish',$id,'انتشار متوقف است.',0.0,$value,$ageDays,$now);
            });
            $counts['holds']++;continue;
        }
        $inputs=['product_id'=>$id,'offer_id'=>$offerId,'settings_version'=>$version];
        // Verdict enrichment: the model verifies against the actual prices
        // the decision rests on (it still never decides).
        $inputs['supplier_price']=(int)molavi_domain_positive_price($record['Sale Price']??null);
        $inputs['regular_price']=(int)($price['regular_price']??0);
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'publish',$id,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now,$provenance,$globalHold): void {
                molavi_domain_autopilot_log($db,'g5','publish',$id,'',$inputs,1.0,'shadow','would_request',$now,$provenance,$globalHold);
            });
            $counts['shadow']++;continue;
        }
        $cap=(int)($settings['cap_per_gate_per_day']??20);
        $done=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_live_count_today($db,'publish',$dayStart));
        if($done>=$cap) {
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$now,$value,$ageDays): void {
                molavi_domain_autopilot_hold($db,'publish',$id,'سقف روزانهٔ اقدام خودکار پر شده است.',0.0,$value,$ageDays,$now);
            });
            $counts['holds']++;continue;
        }
        $already=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            (bool)molavi_domain_row($db,"SELECT 1 FROM autopilot_log WHERE gate='publish' AND product_id=?
                AND action='live' AND result IN ('queued','published') AND created_at>=? LIMIT 1",[$id,$dayStart]));
        if($already)continue;
        try {
            $result=molavi_domain_request_product_publication(
                ['action'=>'request_publication','product_id'=>$id,'offer_id'=>$offerId],'g5');
        } catch(MolaviWorkflowError $e) {
            $reason=match($e->getMessage()){
                'offer_changed'=>'پیشنهاد منبع تغییر کرده است.',
                'product_publication_prerequisite'=>'شرایط انتشار برقرار نیست.',
                'publication_held_for_review'=>'انتشار متوقف است.',
                default=>'درخواست انتشار ناموفق بود.',
            };
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$reason,$now,$value,$ageDays): void {
                molavi_domain_autopilot_hold($db,'publish',$id,$reason,0.0,$value,$ageDays,$now);
            });
            $counts['holds']++;continue;
        }
        if (($result['state']??'')==='published') continue;
        $inputs['publish_job']=$result['publish_job']??null;
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now,$result,$provenance,$globalHold): void {
            molavi_domain_autopilot_log($db,'g5','publish',$id,'',$inputs,1.0,'live',
                (string)($result['state']??'queued'),$now,$provenance,$globalHold);
            molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='g5'",[$now]);
        });
        $counts['live']++;
    }
    return $counts;
}

// -------------------------------------------------------------- rule G4: market

function molavi_domain_autopilot_rule_market(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow,string $provenance='live',bool $globalHold=false): array {
    $counts=['shadow'=>0,'live'=>0,'holds'=>0];
    $records=molavi_domain_records($tables['Products']??[]);
    $offers=molavi_domain_records($tables['Offers']??[]);
    $recordsById=array_column($records,null,'Product ID');
    $offersById=array_column($offers,null,'Offer ID');
    $offerIds=[];
    foreach($records as $record){$offer=trim((string)($record['Latest Offer ID']??''));if($offer!=='')$offerIds[$offer]=true;}
    foreach(array_keys(molavi_domain_market_records($tables)) as $offer)$offerIds[$offer]=true;
    $version=molavi_autopilot_settings_version($settings);
    $band=(float)($settings['quote_band']??0.05);
    $margin=(float)($settings['reference_margin']??0.05);
    molavi_domain_tx('pipeline',static function(SQLite3 $db): void {
        molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate='market'");
    });
    foreach(array_keys($offerIds) as $offer) {
        try {
            $subject=molavi_domain_market_subject((string)$offer,$tables);
        } catch(Throwable $e) { continue; }
        if(!$subject||empty($subject['missing']))continue;
        $identity=$subject['identity'];
        $productId=(string)($subject['product_id']??'');
        $record=$productId!==''?($recordsById[$productId]??[]):($offersById[$offer]??[]);
        $value=(float)molavi_domain_positive_price($record['Sale Price']??null);
        $ageDays=max(0,($now-(molavi_domain_timestamp($record['Import Timestamp']??null)??$now))/86400);
        $hold=function(string $reason,float $score)use(&$counts,$productId,$now,$value,$ageDays): void {
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$reason,$score,$now,$value,$ageDays): void {
                molavi_domain_autopilot_hold($db,'market',$productId,$reason,$score,$value,$ageDays,$now);
            });
            $counts['holds']++;
        };
        if(!empty($identity['assortment'])) { $hold('کالا چندطعم است و هویت بازار آن مشخص نیست.',0.0); continue; }
        $key=molavi_domain_hash($identity);
        $row=molavi_domain_read('reference',static function(SQLite3 $db)use($key,$identity): ?array {
            return molavi_domain_row($db,'SELECT * FROM market_price_lookups WHERE lookup_key=?',[$key])
                ??molavi_domain_row($db,'SELECT * FROM market_price_lookups WHERE lookup_key=? AND selected_url IS NOT NULL',
                    [molavi_domain_market_previous_key($identity)]);
        });
        if(!$row)continue;
        $state=(string)($row['state']??'');
        if($state!=='ready') {
            if($state==='no_match')$hold('جست‌وجوی بازار نتیجه‌ای نداشت.',0.0);
            elseif($state==='quota')$hold('سهمیهٔ جست‌وجوی امروز پر شده است.',0.0);
            elseif(in_array($state,['provider_auth','provider_unavailable'],true))$hold('خطای دسترسی به ارائه‌دهندهٔ قیمت بازار.',0.0);
            continue;
        }
        $quotes=molavi_domain_decode($row['candidates_json']??null);
        $priced=array_values(array_filter($quotes,static fn($quote)=>
            (int)($quote['regular_unit_price']??$quote['unit_price']??0)>0));
        if(!$priced) { $hold('هیچ نقل‌قول قابل‌استفاده‌ای نیست.',0.0); continue; }
        usort($priced,static fn($a,$b)=>($a['regular_unit_price']??$a['unit_price'])<=>($b['regular_unit_price']??$b['unit_price'])
            ?:strcmp((string)($a['url']??''),(string)($b['url']??'')));
        $regular=array_values(array_filter($priced,static fn($quote)=>isset($quote['regular_unit_price'])));
        $exact=array_values(array_filter($regular,static fn($quote)=>($quote['pack_count']??null)===($identity['pack_count']??1)));
        $ranked=$exact?:($regular?:$priced);
        $recommended=$ranked[intdiv(count($ranked),2)]??null;
        $sources=molavi_domain_market_source_for($subject['keys'],molavi_domain_market_source_rows(),$identity);
        $pinned=$sources['price']['url']??null;
        if($pinned)foreach($priced as $quote)if(($quote['url']??null)===$pinned)$recommended=$quote;
        if(!$recommended) { $hold('هیچ نقل‌قول قابل‌استفاده‌ای نیست.',0.0); continue; }
        $units=array_map(static fn($quote)=>(int)($quote['regular_unit_price']??$quote['unit_price']),$priced);
        sort($units);$median=$units[intdiv(count($units),2)];
        $spread=$median>0?(max($units)-min($units))/$median:1.0;
        $pick=null;$confidence=null;$basis='';
        if(count($priced)>=2&&$spread<=$band) {
            $pick=$recommended;$confidence=1-$spread;$basis='agreement';
        } elseif(count($priced)===1) {
            // Rezvan cross-check: the evidence with no market source carries
            // the reference (raw) regular price, pack basis; divide by pack.
            $ref=molavi_domain_regular_price_evidence($record,$offersById[$offer]??[],null,(int)$now);
            $pack=max(1,(int)($identity['pack_count']??1));
            if($ref['state']==='current'&&$ref['amount']>0) {
                $perUnit=$ref['amount']/$pack;
                $diff=$perUnit>0?abs($units[0]-$perUnit)/$perUnit:1.0;
                if($diff<=$margin) { $pick=$recommended;$confidence=1-$diff;$basis='rezvan_crosscheck'; }
                else $hold('فقط یک نقل‌قول هست و با مرجع تطبیق داده نشد.',
                    $margin/max($diff,$margin));
            } else $hold('فقط یک نقل‌قول هست و با مرجع تطبیق داده نشد.',0.5);
            if(!$pick)continue;
        } else {
            $hold('نقل‌قول‌ها '.round($spread*100,1).'٪ با هم اختلاف دارند (سقف '.round($band*100,1).'٪).',
                $band/max($spread,$band));
            continue;
        }
        // Staleness: re-check the quote before it can be selected.
        $checked=min((int)$now,(int)($pick['verified_at']??$row['checked_at']??$now));
        if($now-$checked>=MOLAVI_MARKET_SOURCE_CHECK_SECONDS) {
            try { $fresh=molavi_domain_market_check_url((string)$pick['url'],$identity); }
            catch(Throwable $e) { $fresh=null; }
            if(!$fresh) { $hold('نقل‌قول قدیمی است و بازبینی آن ناموفق بود.',0.0); continue; }
            $pick=$fresh;$checked=(int)$now;
        }
        $inputs=['product_id'=>$productId,'offer_id'=>(string)$offer,'url'=>(string)($pick['url']??''),
            'spread'=>round($spread,4),'basis'=>$basis,'settings_version'=>$version];
        // Verdict enrichment: the picked quote's price and the median, so the
        // model can check the pick against the evidence (it never decides).
        $inputs['regular_price']=(int)($pick['regular_unit_price']??$pick['unit_price']??0);
        $inputs['median_price']=(int)$median;
        // Verdict enrichment (design §10.4): the full quote list behind the
        // spread, the reference amount, message age, the quote's verified_at,
        // the pack, the sheet's raw regular price, and the band/threshold
        // values the pick rests on.
        $inputs['quotes']=molavi_domain_autopilot_format_quotes($priced);
        $inputs['quote_count']=count($priced);
        $inputs['message_age_days']=round($ageDays,1);
        $inputs['quote_verified_at']=molavi_autopilot_iso((float)$checked);
        $inputs['pack_count']=(int)($identity['pack_count']??1);
        $consumer=(int)molavi_domain_positive_price($record['Consumer Price']??null);
        if($consumer>0)$inputs['consumer_price']=$consumer;
        $matchId=trim((string)($record['Reference Match ID']??''));
        $referencePrice=$matchId!==''?molavi_domain_autopilot_reference_price($matchId):0;
        if($referencePrice>0)$inputs['reference_price']=$referencePrice;
        $inputs['quote_band']=$band;
        $inputs['reference_margin']=$margin;
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'market',$productId,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$inputs,$confidence,$now,$provenance,$globalHold): void {
                molavi_domain_autopilot_log($db,'g4','market',$productId,'',$inputs,$confidence,'shadow','would_pick',$now,$provenance,$globalHold);
            });
            $counts['shadow']++;continue;
        }
        $cap=(int)($settings['cap_per_gate_per_day']??20);
        $done=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_live_count_today($db,'market',$dayStart));
        if($done>=$cap) { $hold('سقف روزانهٔ اقدام خودکار پر شده است.',0.0); continue; }
        try {
            $event=molavi_domain_market_choose_source($subject,$pick,$sources['price']??null,(int)$now,$checked);
        } catch(MolaviWorkflowError $e) {
            $hold('انتخاب نقل‌قول ناموفق بود.',0.0);continue;
        }
        $inputs['event']=$event;
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$inputs,$confidence,$now,$provenance,$globalHold): void {
            molavi_domain_autopilot_log($db,'g4','market',$productId,'',$inputs,$confidence,'live','picked',$now,$provenance,$globalHold);
            molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='g4'",[$now]);
        });
        $counts['live']++;
    }
    return $counts;
}

// ------------------------------------------------------- rule G3: supplier price

function molavi_domain_autopilot_rule_supplier_price(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow,string $provenance='live',bool $globalHold=false): array {
    $counts=['shadow'=>0,'live'=>0,'holds'=>0];
    $records=molavi_domain_records($tables['Products']??[]);
    $offers=molavi_domain_records($tables['Offers']??[]);
    $offersById=array_column($offers,null,'Offer ID');
    $version=molavi_autopilot_settings_version($settings);
    $tolerance=(float)($settings['supplier_price_tolerance']??0.02);
    $chainLimit=(int)($settings['consecutive_auto_limit']??3);
    molavi_domain_tx('pipeline',static function(SQLite3 $db): void {
        molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate='supplier_price'");
    });
    foreach($records as $record) {
        $id=trim((string)($record['Product ID']??''));
        if($id==='')continue;
        $revision=molavi_domain_source_revision($record);
        if($revision==='')continue;
        // Candidate set: products with saved evidence (previously verified).
        // A product never verified waits for the owner's first verification.
        $saved=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT * FROM supplier_price_evidence WHERE offer_id=?',[$id]));
        if(!$saved)continue;
        $value=(float)molavi_domain_positive_price($record['Sale Price']??null);
        $offer=$offersById[trim((string)($record['Latest Offer ID']??''))]??[];
        $ageDays=max(0,($now-(molavi_domain_timestamp($offer['Import Timestamp']??null)??$now))/86400);
        $hold=function(string $reason,float $score)use(&$counts,$id,$now,$value,$ageDays): void {
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$reason,$score,$now,$value,$ageDays): void {
                molavi_domain_autopilot_hold($db,'supplier_price',$id,$reason,$score,$value,$ageDays,$now);
            });
            $counts['holds']++;
        };
        if(($saved['source_revision']??'')!==$revision) {
            $prev=molavi_domain_positive_price($record['Previous Sale Price']??null);
            $change=$prev>0&&$value>0?abs($value-$prev)/$prev:1.0;
            $hold('قیمت تأمین‌کننده تغییر کرده است.',$tolerance/max($change,$tolerance));
            continue;
        }
        $basis=trim((string)($record['Source Timestamp Basis']??''));
        if(in_array($basis,['supplier_channel_update','supplier_channel_forward'],true))continue;
        $state=molavi_domain_saved_price_state($revision,$saved,
            molavi_domain_timestamp($record['Message Timestamp']??null),$now);
        if(($state['state']??'')==='current')continue;
        $importAt=molavi_domain_timestamp($offer['Import Timestamp']??$record['Import Timestamp']??null);
        if($importAt===null||$now-$importAt>=14*86400) { $hold('پیام تأمین‌کننده قدیمی است.',0.0); continue; }
        $methods=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_rows($db,'SELECT method FROM supplier_price_evidence_history WHERE offer_id=? ORDER BY id DESC LIMIT 10',[$id]));
        $chain=0;foreach($methods as $row){if(($row['method']??'')==='manual')break;$chain++;}
        if($chain>=$chainLimit) { $hold('چند بار پیاپی خودکار تأیید شده؛ نوبت بررسی دستی است.',0.0); continue; }
        $inputs=['product_id'=>$id,'source_revision'=>$revision,'settings_version'=>$version];
        // Verdict enrichment: the current and previous supplier prices, so
        // the model can check the revision match (it never decides).
        $inputs['supplier_price']=(int)$value;
        $inputs['previous_price']=(int)molavi_domain_positive_price($record['Previous Sale Price']??null);
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'supplier_price',$id,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now,$provenance,$globalHold): void {
                molavi_domain_autopilot_log($db,'g3','supplier_price',$id,'',$inputs,1.0,'shadow','would_verify',$now,$provenance,$globalHold);
            });
            $counts['shadow']++;continue;
        }
        $cap=(int)($settings['cap_per_gate_per_day']??20);
        $done=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_live_count_today($db,'supplier_price',$dayStart));
        if($done>=$cap) { $hold('سقف روزانهٔ اقدام خودکار پر شده است.',0.0); continue; }
        $iso=molavi_autopilot_iso($now);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$revision,$iso): void {
            molavi_domain_sql($db,"INSERT INTO supplier_price_evidence VALUES (?,?,?,'autopilot','autopilot')
                ON CONFLICT(offer_id) DO UPDATE SET source_revision=excluded.source_revision,
                verified_at=excluded.verified_at,method='autopilot',verified_by='autopilot'",
                [$id,$revision,$iso]);
            molavi_domain_sql($db,"INSERT INTO supplier_price_evidence_history
                (offer_id,source_revision,verified_at,method,verified_by,note) VALUES (?,?,?,'autopilot','autopilot',?)",
                [$id,$revision,$iso,'Autopilot: price unchanged (revision match), untrusted basis, fresh import.']);
        });
        $inputs['verified_at']=$iso;
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now,$provenance,$globalHold): void {
            molavi_domain_autopilot_log($db,'g3','supplier_price',$id,'',$inputs,1.0,'live','verified',$now,$provenance,$globalHold);
            molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='g3'",[$now]);
        });
        $counts['live']++;
    }
    return $counts;
}

// ------------------------------------------- rule gA: composite admission (slice 2)
//
// Admission = every gate green at one moment. Each arm re-derives the
// downstream check at admission time instead of trusting an upstream pass
// (the thin_spread lesson: the sale-offer gate passed coffee while the
// discount band could not produce a discount). The hard never-admit list
// (design §7) is enforced by the arms below; the first failing arm is the
// hold reason, so the digest can name it.
// ------------------------------------------- round six: staged live rollout
//
// The 20-distinct / 3-boundary-close bar is retired as the live gate;
// risk-flagged live admissions replace it. The rollout is deliberately
// staged and NEVER advances on count alone:
//   stage 'first': exactly 1 live admission (admitted_total 0 -> 1);
//   stage 'three': up to 3 live admissions in total (admitted_total < 3);
//   stage 'open':  the per-day cap (admission_cap_per_day, 5) governs.
// Each stage advances only by an explicit advance_admission_rollout API
// action, after the owner reviews the admissions so far. The state lives
// in one autopilot_admission_rollout row (id=1), so the tab and the digest
// can read it from the API GET. A missing row seeds from the log itself,
// so live admissions logged before this table existed still count; an
// unknown stored stage fails closed (nothing admitted).
function molavi_domain_autopilot_admission_rollout(SQLite3 $db,float $dayStart,int $cap,float $now): array {
    $today=gmdate('Y-m-d',(int)$now);
    $row=molavi_domain_row($db,'SELECT * FROM autopilot_admission_rollout WHERE id=1');
    if($row) {
        $stage=(string)($row['stage']??'');
        $total=max(0,(int)($row['admitted_total']??0));
        $todayCount=((string)($row['day']??'')===$today)?max(0,(int)($row['admitted_today']??0)):0;
    } else {
        $total=(int)(molavi_domain_row($db,"SELECT COUNT(*) c FROM autopilot_log
            WHERE gate='admission' AND action='live' AND result='admitted'")['c']??0);
        $todayCount=(int)(molavi_domain_row($db,"SELECT COUNT(*) c FROM autopilot_log
            WHERE gate='admission' AND action='live' AND result='admitted' AND created_at>=?",
            [$dayStart])['c']??0);
        $stage=$total===0?'first':($total<3?'three':'open');
    }
    if($stage==='first')$remaining=1-$total;
    elseif($stage==='three')$remaining=3-$total;
    elseif($stage==='open')$remaining=$cap-$todayCount;
    else $remaining=0;
    return ['stage'=>$stage,'total'=>$total,'today'=>$todayCount,'day'=>$today,
        'cap_per_day'=>$cap,'remaining'=>max(0,$remaining),'allowed'=>$remaining>0,
        'next_stage'=>['first'=>'three','three'=>'open'][$stage]??null];
}

function molavi_domain_autopilot_admission_rollout_write(SQLite3 $db,array $rollout,float $now): void {
    molavi_domain_sql($db,"INSERT INTO autopilot_admission_rollout
        (id,stage,admitted_total,admitted_today,day,updated_at) VALUES (1,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET stage=excluded.stage,admitted_total=excluded.admitted_total,
        admitted_today=excluded.admitted_today,day=excluded.day,updated_at=excluded.updated_at",
        [(string)($rollout['stage']??'first'),(int)($rollout['total']??0),(int)($rollout['today']??0),
            (string)($rollout['day']??''),$now]);
}

function molavi_domain_autopilot_admission_rollout_record(SQLite3 $db,array $rollout,float $now): void {
    // Called in the same transaction as the 'admitted' log row, so the
    // counts and the row they count can never drift apart.
    $rollout['total']=(int)($rollout['total']??0)+1;
    $rollout['today']=(int)($rollout['today']??0)+1;
    molavi_domain_autopilot_admission_rollout_write($db,$rollout,$now);
}

// The composite admission rule (gA). The live branch mirrors G5's live
// path (the same owner publish-request flow); under the round-six rollout
// it admits live, risk-flagged, in stages — see the rollout helpers above.
function molavi_domain_autopilot_rule_admission(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow,string $provenance='live',bool $globalHold=false): array {
    $counts=['shadow'=>0,'live'=>0,'holds'=>0];
    $records=molavi_domain_records($tables['Products']??[]);
    $offers=molavi_domain_records($tables['Offers']??[]);
    $offersById=array_column($offers,null,'Offer ID');
    $evidence=molavi_domain_read('pipeline','molavi_domain_price_evidence_by_product');
    $market=function_exists('molavi_domain_market_catalog_sources')
        ?molavi_domain_market_catalog_sources($records,$offers):[];
    $issuesByProduct=[];
    foreach(molavi_domain_source_issues($records,$offers) as $issue)
        if(($issue['product_id']??'')!=='')$issuesByProduct[$issue['product_id']]=$issue['codes']??[];
    $version=molavi_autopilot_settings_version($settings);
    // Global hold first, before any per-product work: while ANY publication
    // hold row exists the publish shop refuses outright (publisher returns
    // publication_held_for_review), so there is nothing to admit. The
    // composite skips the tick and leaves the block visible through G5's
    // per-product 'انتشار متوقف است.' rows and the probe's global_hold flag.
    // (A publication hold on one product is subsumed: any row blocks all.)
    // The tick reads the global hold once and passes it in.
    $held=$globalHold;
    if($held)return ['shadow'=>0,'live'=>0,'holds'=>0,'global_hold'=>true];
    $conflicts=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        array_column(molavi_domain_rows($db,"SELECT DISTINCT product_id FROM sheet_sync_conflicts WHERE state='open'"),'product_id'));
    $waivers=is_array($w=get_option('molavi_autopilot_image_waivers',[]))?$w:[];
    $dead=function_exists('molavi_dead_image_files')?molavi_dead_image_files():[];
    $registered=function_exists('molavi_domain_registered_products')?molavi_domain_registered_products():[];
    $wooByRecord=[];
    $alreadyForSale=[];
    foreach($registered as $row) {
        $rid=(string)($row['source_record_id']??'');
        if(!empty($row['published'])&&!empty($row['purchasable']))$alreadyForSale[$rid]=(string)($row['active_offer_id']??'');
        if($rid!==''&&($row['woo_product_id']??null))$wooByRecord[$rid]=(int)$row['woo_product_id'];
    }
    // Candidate set: imported products with a latest offer. Their spec
    // decisions load in one query (a case id is 'ai:'.sha256(offer id); an
    // owner-confirm offer id is 'owner-confirm:'.decision id).
    $candidates=[];
    foreach($records as $record) {
        $id=trim((string)($record['Product ID']??''));
        if($id==='')continue;
        $offerId=trim((string)($record['Latest Offer ID']??''));
        if(($record['Status']??'')!=='imported'||$offerId===''||!isset($offersById[$offerId]))continue;
        $candidates[$id]=['record'=>$record,'offer_id'=>$offerId];
    }
    $decisions=[];
    if($candidates) {
        $caseIds=[];$decisionIds=[];
        foreach($candidates as $cand) {
            $offerId=$cand['offer_id'];
            if(str_starts_with($offerId,'owner-confirm:'))$decisionIds[]=substr($offerId,14);
            else $caseIds[]='ai:'.hash('sha256',$offerId);
        }
        $decisions=molavi_domain_read('pipeline',static function(SQLite3 $db)use($caseIds,$decisionIds): array {
            $out=['case'=>[],'dec'=>[]];
            if($caseIds) {
                $ph=implode(',',array_fill(0,count($caseIds),'?'));
                foreach(molavi_domain_rows($db,"SELECT * FROM ai_confirmation_decisions WHERE case_id IN ($ph) ORDER BY created_at DESC, decision_id DESC",$caseIds) as $row) {
                    $key=(string)($row['case_id']??'');
                    if($key!==''&&!isset($out['case'][$key]))$out['case'][$key]=$row;
                }
            }
            if($decisionIds) {
                $ph=implode(',',array_fill(0,count($decisionIds),'?'));
                foreach(molavi_domain_rows($db,"SELECT * FROM ai_confirmation_decisions WHERE decision_id IN ($ph) ORDER BY created_at DESC, decision_id DESC",$decisionIds) as $row) {
                    $key=(string)($row['decision_id']??'');
                    if($key!==''&&!isset($out['dec'][$key]))$out['dec'][$key]=$row;
                }
            }
            return $out;
        });
    }
    molavi_domain_tx('pipeline',static function(SQLite3 $db): void {
        molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate='admission'");
    });
    // Holds the other rules wrote this tick (g5/g4/g3 run before gA), plus
    // verdict flags re-asserted last tick: a held product is never admitted.
    $heldProducts=[];
    foreach(molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_rows($db,'SELECT product_id FROM autopilot_holds')) as $row)
        $heldProducts[(string)($row['product_id']??'')]=true;
    // A flagged verdict on a live admission is a durable hold, not just a
    // hold row: every rule wipes its own gate's holds at the start of the
    // tick (and the verdict runner only re-asserts flags AFTER the rules),
    // so a flag hold alone would never block the NEXT tick's evaluation —
    // a flagged product could be re-admitted before the flag is
    // re-asserted. The flag on the log row itself is the durable signal:
    // a product whose live admission was flagged in the last 7 days is
    // held here, so it can never be re-admitted while the flag stands. An
    // owner override clears it: override_log rewrites the row's result
    // to 'overridden', so it no longer matches result='admitted'.
    foreach(molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_rows($db,"SELECT product_id FROM autopilot_log WHERE verdict='flag'
            AND gate='admission' AND action='live' AND result='admitted' AND created_at>=?",
            [$now-7*86400])) as $flagRow)
        $heldProducts[(string)($flagRow['product_id']??'')]=true;
    foreach($candidates as $id=>$cand) {
        $record=$cand['record'];
        $offerId=$cand['offer_id'];
        $offer=$offersById[$offerId];
        $value=(float)molavi_domain_positive_price($record['Sale Price']??null);
        $ageDays=max(0,($now-(molavi_domain_timestamp($offer['Import Timestamp']??null)??$now))/86400);
        $fail=function(string $reason,float $score=0.0)use(&$counts,$id,$now,$value,$ageDays): void {
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$reason,$score,$now,$value,$ageDays): void {
                molavi_domain_autopilot_hold($db,'admission',$id,$reason,$score,$value,$ageDays,$now);
            });
            $counts['holds']++;
        };
        // Arm 1: source integrity (a duplicate product id blocks the whole
        // catalog upstream; any other integrity issue blocks this product).
        if(!empty($issuesByProduct[$id]??[])) { $fail('مشکل یکپارچگی منبع داده.'); continue; }
        // Arm 2: open sheet-sync conflicts.
        if(in_array($id,$conflicts,true)) { $fail('تعارض همگام‌سازی شیت باز است.'); continue; }
        // Arm 3: spec confirmed. A decision row, when present, must say
        // 'confirm' and (for ai-review cases) its signature must match the
        // current offer row. Most products were auto-imported with no
        // decision row: Status='imported' is their confirmation record.
        // An owner-confirm offer with no decision row fails closed.
        $spec=null;$specOk=false;
        if(str_starts_with($offerId,'owner-confirm:')) {
            $dec=$decisions['dec'][substr($offerId,14)]??null;
            $specOk=$dec&&($dec['choice']??'')==='confirm';
            $spec=$dec?['decision_id'=>(string)($dec['decision_id']??''),'choice'=>(string)($dec['choice']??''),'via'=>'owner-confirm']:null;
        } else {
            $caseId='ai:'.hash('sha256',$offerId);
            $dec=$decisions['case'][$caseId]??null;
            if($dec) {
                $specOk=($dec['choice']??'')==='confirm'&&($dec['signature']??'')===molavi_domain_hash($offer);
                $spec=['case_id'=>$caseId,'choice'=>(string)($dec['choice']??''),'via'=>'ai-review'];
            } else {
                $specOk=true;
                $spec=['via'=>'auto-import'];
            }
        }
        if(!$specOk) { $fail('مشخصات تأییدشده برای پیشنهاد فعلی نیست.'); continue; }
        // Arm 4: supplier price current (saved evidence, revision matches,
        // age under 14 days — the saved state already encodes all three).
        $saved=molavi_domain_product_saved_price($record,$evidence,$now);
        if(!in_array($saved['state']??'needs_review',['current','warning'],true)) { $fail('قیمت تأمین‌کننده جاری نیست.'); continue; }
        // Arm 5: regular price current from any source.
        $regular=molavi_domain_regular_price_evidence($record,$offer,$market[$id]['price']??null,(int)$now);
        if(($regular['state']??'')!=='current') { $fail('قیمت عادی جاری نیست.'); continue; }
        // Arm 6: unresolved reference proposal, unless a market source
        // currently proves the regular price.
        $approval=trim((string)($record['Reference Approval State']??''));
        $matchId=trim((string)($record['Reference Match ID']??''));
        $approved=in_array($approval,['owner_approved','approved_compatible','approved_within_margin'],true);
        $marketProves=isset($market[$id]['price']['regular_price'])
            &&(int)($market[$id]['price']['verified_at']??0)>0
            &&(int)($market[$id]['price']['verified_at']??0)+MOLAVI_MARKET_SOURCE_CHECK_SECONDS>(int)$now;
        if($matchId!==''&&!$approved&&!$marketProves) { $fail('پیشنهاد مرجع حل‌نشده است.'); continue; }
        // Arm 7: spread discountable, re-derived at admission time.
        $cost=(int)molavi_domain_positive_price($record['Sale Price']??null);
        $regularAmount=(int)($regular['amount']??0);
        $bandOk=$regularAmount>$cost&&$cost>0&&function_exists('molavi_band')&&molavi_band($cost,$regularAmount)['discountable'];
        if(!$bandOk) {
            $spread=$regularAmount>0?($regularAmount-$cost)/$regularAmount:0.0;
            $fail('اختلاف قیمت برای تخفیف کافی نیست.',(float)min(1,max(0,$spread/0.12)));
            continue;
        }
        // Arm 8: image present or owner-waived, and not dead.
        $imageId=trim((string)($record['Image File ID']??''));
        $waived=in_array($id,$waivers,true);
        if($imageId===''&&!$waived) { $fail('تصویر کالا نیست.'); continue; }
        if($imageId!==''&&isset($dead[$imageId])) { $fail('تصویر کالا خراب است.'); continue; }
        // Arm 9: supplier availability — the owner's rule: a product from a
        // supplier message is always available unless the message explicitly
        // says it is not (see molavi_domain_supplier_available). 'Unknown'
        // stock passes; only an explicit 'Out of Stock' fails.
        if(!molavi_domain_supplier_available($record)) { $fail('کالا ناموجود است.'); continue; }
        // Arm 10: product hold (any other gate's hold, or a verdict flag).
        if(!empty($heldProducts[$id])) { $fail('کالا در توقف است.'); continue; }
        if($live&&isset($alreadyForSale[$id])&&($alreadyForSale[$id]===''||$alreadyForSale[$id]===$offerId))continue;
        // All arms green.
        $saleEvidence=molavi_domain_sale_offer_evidence($record,$offer,$saved,$market[$id]['price']??null,(int)$now);
        // Closeness to the tightest arm threshold, measured at decision time
        // (§6 evidence): the quote-spread arm only applies when a market
        // source proves the regular price.
        $supplierVerifiedAt=molavi_domain_timestamp($saved['verified_at']??null);
        $quoteSpread=$marketProves?molavi_domain_autopilot_market_quote_spread($record):null;
        $closeness=molavi_domain_autopilot_admission_closeness($settings,$cost,$regularAmount,
            $supplierVerifiedAt,(int)($regular['expires_at']??0),$quoteSpread,$now);
        // Confidence is no longer a hardcoded 1.0: it is the tightest arm
        // margin, 0 at the threshold and 1 comfortably clear (null margin —
        // nothing measurable — fails closed to 0.0).
        $confidence=$closeness['closeness']===null?0.0:min(1.0,max(0.0,(float)$closeness['closeness']));
        // Risk tier (round six): the closeness mapped to green/amber/red
        // by molavi_domain_autopilot_risk_tier($closeness, $settings) —
        // the mapping lives there, it is NOT duplicated here. A live
        // admission is always verdict-pending at admission time (verdicts
        // run after the rules in the same tick), so its EFFECTIVE tier is
        // red regardless of closeness; the closeness tier is kept
        // alongside as risk_tier_closeness, so the review surface can
        // show what the tier becomes once the verdict lands.
        $closenessTier=molavi_domain_autopilot_risk_tier($closeness['closeness'],$settings);
        $inputs=['product_id'=>$id,'offer_id'=>$offerId,'settings_version'=>$version,
            'supplier_price'=>$cost,'regular_price'=>$regularAmount,
            'supplier_state'=>(string)($saved['state']??''),
            'regular_state'=>(string)($regular['state']??''),
            'supplier_available'=>molavi_domain_supplier_available($record),
            'reference_id'=>$matchId,'image_id'=>$imageId,'spec_via'=>(string)($spec['via']??''),
            // Material inputs for the distinct-decision count: a re-log
            // counts only when one of these changed.
            'source_revision'=>molavi_domain_source_revision($record),
            'closeness'=>$closeness['closeness'],'closeness_arm'=>$closeness['arm'],
            'closeness_arms'=>$closeness['arms'],
            'risk_tier'=>$closenessTier,'risk_tier_closeness'=>$closenessTier];
        // Verdict enrichment (design §10.4): the model re-examines the
        // evidence, so the log row carries it — the quote list, the reference
        // amount, message age, verified_at stamps, sheet values, the pack,
        // and the band/threshold values the arms rest on. The prompt renders
        // every scalar input, so these land in the verdict verbatim. The
        // model never decides. Fields already present above are untouched.
        $inputs['spread']=$regularAmount>$cost&&$cost>0
            ?round(($regularAmount-$cost)/$regularAmount,4):0.0;
        $inputs['consumer_price']=(int)molavi_domain_positive_price($record['Consumer Price']??null);
        $inputs['pack']=function_exists('molavi_domain_pack_count')
            ?molavi_domain_pack_count((string)($record['Packaging']??''),(string)($record['Product Name']??'')):0;
        $inputs['stock_status']=(string)($record['Stock Status']??'');
        $inputs['message_age_days']=round($ageDays,1);
        $inputs['supplier_verified_at']=(string)($saved['verified_at']??'');
        if(($regular['expires_at']??0)>0)
            $inputs['regular_expires_at']=molavi_autopilot_iso((float)$regular['expires_at']);
        if(!empty($market[$id]['price']['verified_at']))
            $inputs['regular_verified_at']=molavi_autopilot_iso((float)$market[$id]['price']['verified_at']);
        $prev=(int)molavi_domain_positive_price($record['Previous Sale Price']??null);
        if($prev>0)$inputs['previous_sale_price']=$prev;
        $quoteList=molavi_domain_autopilot_market_quote_list($record);
        if($quoteList!=='')$inputs['quote_list']=$quoteList;
        $referencePrice=$matchId!==''?molavi_domain_autopilot_reference_price($matchId):0;
        if($referencePrice>0)$inputs['reference_price']=$referencePrice;
        $inputs['min_margin']=function_exists('molavi_price_settings')
            ?(float)(molavi_price_settings()['min_margin']??0.12):0.12;
        $inputs['quote_band']=(float)($settings['quote_band']??0.05);
        $inputs['reference_margin']=(float)($settings['reference_margin']??0.05);
        $inputs['price_clock_days']=MOLAVI_ADMISSION_PRICE_CLOCK_DAYS;
        $receipt=[
            'product_id'=>$id,'offer_id'=>$offerId,
            'spec'=>$spec,
            'supplier'=>['price'=>$cost,'state'=>(string)($saved['state']??''),
                'verified_at'=>(string)($saved['verified_at']??''),'method'=>(string)($saved['method']??''),
                'revision'=>molavi_domain_source_revision($record)],
            'regular'=>['price'=>$regularAmount,'state'=>(string)($regular['state']??''),
                'expires_at'=>(int)($regular['expires_at']??0),
                'market_price'=>(int)($market[$id]['price']['regular_price']??0)],
            'pack'=>function_exists('molavi_domain_pack_count')
                ?molavi_domain_pack_count((string)($record['Packaging']??''),(string)($record['Product Name']??'')):0,
            'stock'=>(string)($record['Stock Status']??''),
            'image_id'=>$imageId,'image_waived'=>$waived,
            'reference'=>['id'=>$matchId,'approval_state'=>$approval,'market_proves'=>$marketProves],
            'sale_offer'=>['blocker'=>(string)($saleEvidence['sale_offer_blocker']??''),
                'expires_at'=>(int)($saleEvidence['sale_offer_expires_at']??0)],
            'woo_id'=>$wooByRecord[$id]??null,
            'settings_version'=>$version,'verdict'=>'pending','publish_job'=>null,
            'created_at'=>$now,
            'provenance'=>$provenance,'global_hold'=>$globalHold,
            'closeness'=>$closeness['closeness'],'closeness_arm'=>$closeness['arm'],
            'closeness_arms'=>$closeness['arms'],
            'risk_tier'=>$closenessTier,'risk_tier_closeness'=>$closenessTier,
        ];
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'admission',$id,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$offerId,$inputs,$receipt,$now,$confidence,$provenance,$globalHold,$closeness,$closenessTier): void {
                $logId=molavi_domain_autopilot_log($db,'gA','admission',$id,'',$inputs,$confidence,'shadow','would_admit',$now,$provenance,$globalHold,$closeness['closeness'],$closeness['arm'],$closenessTier);
                $receipt['log_id']=$logId;
                molavi_domain_autopilot_admission_receipt($db,$logId,$receipt);
            });
            $counts['shadow']++;continue;
        }
        // Live (Phase B, unreachable while master is off): risk-flagged
        // live admissions (round six). The admission cap is tighter than
        // the per-gate cap; then the same owner publish-request flow G5
        // uses. There is NO confidence/boundary gate on this path — the
        // confidence is recorded, never compared — so a boundary-close
        // product admits live with its tier attached (red on closeness,
        // and red anyway while its verdict is pending; see below).
        // Rollout first: stage 'first' allows exactly 1 live admission,
        // 'three' allows up to 3 in total, 'open' leaves the per-day cap
        // in charge. The stage advances only by an explicit API action.
        $cap=(int)($settings['admission_cap_per_day']??5);
        $admitted=molavi_domain_lock('publication',static function()use($dayStart,$cap,$now,$fail,$id,$offerId,
            $inputs,$receipt,$confidence,$provenance,$globalHold,$closeness): bool {
            $rollout=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_admission_rollout($db,$dayStart,$cap,$now));
            if(empty($rollout['allowed'])) {
                // In stage 'open' the rollout limit IS the per-day cap, so the
                // block is reported as the daily cap, not as a stage limit.
                $fail(($rollout['stage']??'')==='open'?'سقف روزانهٔ پذیرش پر شده است.':'سقف مرحله‌ای پذیرش زنده پر شده است.');
                return false;
            }
            // The per-day cap stays as an independent backstop, counted from
            // the log itself, so a lost or stale rollout row can never admit
            // past the cap.
            $done=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_live_count_today($db,'admission',$dayStart));
            if($done>=$cap) { $fail('سقف روزانهٔ پذیرش پر شده است.'); return false; }
            $already=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                (bool)molavi_domain_row($db,"SELECT 1 FROM autopilot_log WHERE gate='admission' AND product_id=?
                    AND action='live' AND result='admitted' AND created_at>=? LIMIT 1",[$id,$dayStart]));
            if($already)return false;
            try {
                $result=molavi_domain_request_product_publication(
                    ['action'=>'request_publication','product_id'=>$id,'offer_id'=>$offerId],'gA');
            } catch(MolaviWorkflowError $e) {
                $reason=match($e->getMessage()){
                    'offer_changed'=>'پیشنهاد منبع تغییر کرده است.',
                    'product_publication_prerequisite'=>'شرایط پذیرش برقرار نیست.',
                    'publication_held_for_review'=>'انتشار متوقف است.',
                    default=>'درخواست انتشار ناموفق بود.',
                };
                $fail($reason);return false;
            }
            if (($result['state']??'')==='published') return false;
            $pendingJob=$result['publish_job']??null;
            if (!$pendingJob) return false;
            $logged=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,
                "SELECT 1 FROM autopilot_log WHERE product_id=? AND gate='admission' AND action='live' AND result='admitted'
                    AND json_extract(inputs_json,'$.publish_job')=? AND json_extract(inputs_json,'$.offer_id')=? LIMIT 1",
                [$id,$pendingJob,$offerId]));
            if ($logged) return false;
            $inputs['publish_job']=$pendingJob;
            $receipt['publish_job']=$result['publish_job']??null;
            // Effective tier at admission: the verdict is still pending (it
            // runs after the rules in this tick), so the admission is flagged
            // red regardless of closeness. risk_tier_closeness keeps the
            // closeness-only tier for the review surface.
            $inputs['risk_tier']='red';
            $inputs['verdict_pending']=true;
            $receipt['risk_tier']='red';
            $receipt['verdict_pending']=true;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$receipt,$now,$confidence,$provenance,$globalHold,$closeness,$rollout): void {
                $logId=molavi_domain_autopilot_log($db,'gA','admission',$id,'',$inputs,$confidence,'live','admitted',$now,$provenance,$globalHold,$closeness['closeness'],$closeness['arm'],'red');
                $receipt['log_id']=$logId;
                molavi_domain_autopilot_admission_receipt($db,$logId,$receipt);
                molavi_domain_autopilot_admission_rollout_record($db,$rollout,$now);
                molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='gA'",[$now]);
            });
            return true;
        });
        if($admitted)$counts['live']++;
    }
    return $counts;
}

// One receipt row per admission decision, linked to the autopilot_log row
// that recorded it, so any admission is provable after the fact.
function molavi_domain_autopilot_admission_receipt(SQLite3 $db,int $logId,array $receipt): void {
    molavi_domain_sql($db,'INSERT INTO autopilot_admission_receipts
        (log_id,product_id,offer_id,receipt_json,created_at) VALUES (?,?,?,?,?)',
        [$logId,(string)($receipt['product_id']??''),(string)($receipt['offer_id']??''),
            molavi_domain_json($receipt),(float)($receipt['created_at']??microtime(true))]);
}

// ------------------------------------------------------------------------ API

function molavi_autopilot_validate_settings(array $input,array $current): array {
    $out=$current;
    $unknown=array_diff(array_keys($input),array_keys(molavi_autopilot_defaults()));
    if($unknown)molavi_domain_fail('invalid_body',400);
    $num=function(string $key,float $min,float $max)use($input,$out): float {
        if(!array_key_exists($key,$input))return (float)$out[$key];
        $value=$input[$key];
        if(!is_numeric($value))molavi_domain_fail('invalid_body',400);
        $value=(float)$value;
        if($value<$min||$value>$max)molavi_domain_fail('invalid_body',400);
        return $value;
    };
    $int=function(string $key,int $min,int $max)use($input,$out): int {
        if(!array_key_exists($key,$input))return (int)$out[$key];
        $value=$input[$key];
        if(!is_int($value)&&!(is_string($value)&&preg_match('/^-?\d+$/D',$value)))molavi_domain_fail('invalid_body',400);
        $value=(int)$value;
        if($value<$min||$value>$max)molavi_domain_fail('invalid_body',400);
        return $value;
    };
    $bool=function(string $key)use($input,$out): bool {
        if(!array_key_exists($key,$input))return (bool)$out[$key];
        if(!is_bool($input[$key]))molavi_domain_fail('invalid_body',400);
        return $input[$key];
    };
    $out['quote_band']=$num('quote_band',0.01,0.5);
    $out['ai_price_tolerance']=$num('ai_price_tolerance',0,0.2);
    $out['supplier_price_tolerance']=$num('supplier_price_tolerance',0,0.2);
    $out['reference_margin']=$num('reference_margin',0.01,0.5);
    $out['precedent_mechanical']=$int('precedent_mechanical',1,50);
    $out['precedent_judgement']=$int('precedent_judgement',1,50);
    $out['cap_per_gate_per_day']=$int('cap_per_gate_per_day',1,200);
    $out['consecutive_auto_limit']=$int('consecutive_auto_limit',1,10);
    $out['admission_cap_per_day']=$int('admission_cap_per_day',1,20);
    $out['risk_red_close']=$num('risk_red_close',0.01,0.5);
    $out['risk_amber_close']=$num('risk_amber_close',0.05,1.0);
    // The bands must nest: amber strictly above red, or a save could make
    // the red band swallow amber (or invert the tiers) by accident.
    if($out['risk_amber_close']<=$out['risk_red_close'])molavi_domain_fail('invalid_body',400);
    $out['master_enabled']=$bool('master_enabled');
    $out['shadow_enabled']=$bool('shadow_enabled');
    if(array_key_exists('rule_modes',$input)) {
        if(!is_array($input['rule_modes']))molavi_domain_fail('invalid_body',400);
        $modes=$out['rule_modes'];
        foreach($input['rule_modes'] as $gate=>$mode) {
            if(!in_array($gate,['g5','g4','g3','gA'],true)||!in_array($mode,['shadow','live'],true))
                molavi_domain_fail('invalid_body',400);
            $modes[$gate]=$mode;
        }
        $out['rule_modes']=$modes;
    }
    if(array_key_exists('verdict_sampling',$input)) {
        if(!in_array($input['verdict_sampling'],['all','sampled','off'],true))molavi_domain_fail('invalid_body',400);
        $out['verdict_sampling']=$input['verdict_sampling'];
    }
    return $out;
}

function molavi_domain_autopilot_api(string $method,array $query,?array $body,string $actor): array {
    molavi_domain_autopilot_ensure_tables();
    if($method==='GET') {
        $settings=molavi_autopilot_settings();
        $rules=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_rows($db,'SELECT * FROM autopilot_rules ORDER BY id'));
        foreach($rules as &$rule)$rule['condition']=molavi_domain_decode($rule['condition_json']??'{}');
        unset($rule);
        $log=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_rows($db,'SELECT * FROM autopilot_log ORDER BY created_at DESC, id DESC LIMIT 100'));
        // Stored tiers for the returned rows: the log row first (workstream
        // 1), the admission receipt second. Read defensively — this branch
        // predates the risk_tier column, so a table that has not run the
        // tiering migration simply has no stored tier (and renders red).
        $receiptTiers=molavi_domain_read('pipeline',static function(SQLite3 $db)use($log): array {
            $ids=array_values(array_unique(array_map(static fn($row)=>(int)($row['id']??0),$log)));
            if(!$ids)return [];
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $out=[];
            foreach(molavi_domain_rows($db,"SELECT log_id,receipt_json FROM autopilot_admission_receipts WHERE log_id IN ($ph)",$ids) as $receipt) {
                try { $decoded=molavi_domain_decode((string)($receipt['receipt_json']??'{}'),[]); }
                catch(Throwable $e) { $decoded=[]; }
                if(is_array($decoded)&&isset($decoded['risk_tier']))$out[(int)$receipt['log_id']]=(string)$decoded['risk_tier'];
            }
            return $out;
        });
        foreach($log as &$row){
            $row['inputs']=molavi_domain_decode($row['inputs_json']??'{}');unset($row['inputs_json']);
            if(!is_array($row['inputs']))$row['inputs']=[];
            // risk_tier is the EFFECTIVE tier the tab renders; risk_tier_stored
            // is what workstream 1 actually stored ('' when nothing was).
            $stored=in_array((string)($row['risk_tier']??''),['green','amber','red'],true)
                ?(string)$row['risk_tier']:'';
            if($stored==='') {
                $fromReceipt=$receiptTiers[(int)($row['id']??0)]??'';
                if(in_array($fromReceipt,['green','amber','red'],true))$stored=$fromReceipt;
            }
            $row['risk_tier_stored']=$stored;
            $row['risk_tier']=molavi_domain_autopilot_review_risk_tier(
                ['gate'=>(string)($row['gate']??''),'verdict'=>(string)($row['verdict']??''),
                    'risk_tier'=>$stored],null);
            $row['risk_reason']=molavi_domain_autopilot_risk_reason($row,$row['inputs']);
            $row['reviewed_at']=(float)($row['reviewed_at']??0);
            $row['reviewed_by']=(string)($row['reviewed_by']??'');
        }
        unset($row);
        $unreviewed=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_unreviewed_count($db));
        $holds=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_rows($db,'SELECT * FROM autopilot_holds ORDER BY priority ASC, created_at ASC'));
        // The §6 bar as a checkable fact, plus the persisted tick and
        // digest-watch history behind it.
        $version=molavi_autopilot_settings_version($settings);
        $evidence=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_admission_evidence($db,$version));
        $ticks=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_ticks($db,10));
        $watches=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_digest_watches($db,10));
        // Staged live rollout (round six): stage, counts, remaining
        // allowance and next stage, so the tab and the digest can show it.
        $dayStart=(float)strtotime(gmdate('Y-m-d').' 00:00:00 UTC');
        $rollout=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_admission_rollout($db,$dayStart,(int)($settings['admission_cap_per_day']??5),microtime(true)));
        // The digest's risk section: new amber/red admission decisions
        // since the last digest watch (null $since = derive from the
        // latest watch). Names resolve from the Products sheet, best
        // effort — a sheet failure leaves product_name '' and never
        // fails the GET; the digest worker resolves from product_id.
        $riskDigest=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_recent_risk_decisions($db,null,$settings));
        $names=molavi_domain_autopilot_product_names(
            array_column($riskDigest['decisions']??[],'product_id'));
        foreach($riskDigest['decisions'] as &$decision)
            $decision['product_name']=$names[$decision['product_id']]??'';
        unset($decision);
        return ['ok'=>true,'settings'=>$settings,'rules'=>$rules,'log'=>$log,'holds'=>$holds,
            'admission_evidence'=>$evidence,'ticks'=>$ticks,'digest_watches'=>$watches,
            'admission_rollout'=>$rollout,'risk_digest'=>$riskDigest,
            'unreviewed_decisions'=>$unreviewed];
    }
    if($method!=='POST'||!is_array($body))molavi_domain_fail('invalid_body',400);
    $action=(string)($body['action']??'');
    $now=microtime(true);
    if($action==='save_settings') {
        molavi_domain_body_keys($body,['action','settings']);
        if(!is_array($body['settings']))molavi_domain_fail('invalid_body',400);
        $current=molavi_autopilot_settings();
        $merged=molavi_autopilot_validate_settings($body['settings'],$current);
        $changed=[];
        foreach($merged as $key=>$value)
            if(molavi_domain_json($current[$key]??null)!==molavi_domain_json($value))
                $changed[$key]=[$current[$key]??null,$value];
        update_option('molavi_autopilot_settings_v1',$merged,false);
        if(is_array($body['settings']['rule_modes']??null)) {
            // CAVEAT (admission slice): a settings save used to re-activate
            // EVERY rule named in rule_modes — and the tab's form always
            // submits all three modes — so saving settings silently
            // un-suspended a rule the owner had suspended via override.
            // Only re-activate a rule whose mode actually changed in this
            // save: changing the mode is an explicit act, re-submitting the
            // same mode leaves a suspended rule suspended. A suspended rule
            // is resumed by changing its mode (or by a future resume action).
            $modes=$body['settings']['rule_modes'];
            $currentModes=$current['rule_modes']??[];
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($modes,$currentModes): void {
                foreach($modes as $gate=>$mode) {
                    if(!in_array($gate,['g5','g4','g3','gA'],true))continue;
                    if(($currentModes[$gate]??null)===$mode)continue;
                    molavi_domain_sql($db,"UPDATE autopilot_rules SET state='active' WHERE id=?",[$gate]);
                }
            });
        }
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($changed,$now): void {
            molavi_domain_autopilot_log($db,'','settings','','',
                ['changed'=>$changed],null,'settings','saved',$now);
        });
        return ['ok'=>true,'settings'=>$merged,'changed'=>$changed];
    }
    unset($GLOBALS['molavi_storefront_blocks']);
    if($action==='override_log') {
        molavi_domain_body_keys($body,['action','log_id']);
        if(!is_int($body['log_id']))molavi_domain_fail('invalid_body',400);
        $original=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT * FROM autopilot_log WHERE id=?',[$body['log_id']]));
        if(!$original)molavi_domain_fail('autopilot_log_not_found',404);
        $ruleId=(string)($original['rule_id']??'');
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($original,$ruleId,$now,$actor): void {
            molavi_domain_sql($db,"UPDATE autopilot_log SET result='overridden' WHERE id=?",[(int)$original['id']]);
            molavi_domain_autopilot_log($db,$ruleId,(string)($original['gate']??''),
                (string)($original['product_id']??''),(string)($original['case_id']??''),
                ['overrides_log_id'=>(int)$original['id'],'actor'=>$actor],null,'override','overridden',$now);
            if($ruleId!=='') {
                molavi_domain_sql($db,"UPDATE autopilot_rules
                    SET state='suspended',override_count=override_count+1 WHERE id=?",[$ruleId]);
                molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate=?",
                    [(string)($original['gate']??'')]);
            }
        });
        return ['ok'=>true,'log_id'=>(int)$original['id'],'rule_id'=>$ruleId,'state'=>'suspended'];
    }
    if($action==='revert_decision') {
        // Undo one admission decision: hide the Woo product through the
        // existing un-publish path, block any re-admission with an owner
        // hold, mark the row reverted, and log the revert. This never
        // suspends the rule (a per-product decision, not a rule problem)
        // and never touches the global publication hold — that hold stops
        // the WHOLE catalog, so it must never be used for one product.
        // The Woo hide runs only for a LIVE admission: a shadow would_admit
        // never published anything, so there is nothing to hide. If the
        // receipt carries no Woo id, the hide is skipped (hidden null) and
        // the owner hold below is the whole revert.
        molavi_domain_body_keys($body,['action','log_id']);
        if(!is_int($body['log_id']))molavi_domain_fail('invalid_body',400);
        $original=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT * FROM autopilot_log WHERE id=?',[$body['log_id']]));
        if(!$original)molavi_domain_fail('autopilot_log_not_found',404);
        if((string)($original['gate']??'')!=='admission')molavi_domain_fail('invalid_body',400);
        if(in_array((string)($original['result']??''),['reverted','overridden'],true))
            molavi_domain_fail('autopilot_already_reverted',409);
        $productId=(string)($original['product_id']??'');
        if($productId==='')molavi_domain_fail('invalid_body',400);
        try { $inputs=molavi_domain_decode((string)($original['inputs_json']??'{}'),[]); }
        catch(Throwable $e) { $inputs=[]; }
        if(!is_array($inputs))$inputs=[];
        // The receipt carries the Woo id the admission published to.
        $receipt=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT receipt_json FROM autopilot_admission_receipts WHERE log_id=?',[(int)$original['id']]));
        $wooId=null;
        if($receipt) {
            try { $decoded=molavi_domain_decode((string)($receipt['receipt_json']??'{}'),[]); }
            catch(Throwable $e) { $decoded=[]; }
            if(is_array($decoded)&&isset($decoded['woo_id'])&&is_numeric($decoded['woo_id']))
                $wooId=(int)$decoded['woo_id'];
        }
        $hidden=null;
        if((string)($original['action']??'')==='live'
            &&(string)($original['result']??'')==='admitted'&&$wooId!==null&&$wooId>0) {
            // The Woo un-publish path is the existing sync code — this file
            // only calls it, never reimplements it. Fail loudly if the
            // sync helpers are unavailable instead of pretending to revert.
            if(!function_exists('molavi_hide_product')||!function_exists('molavi_mark_product_held')
                ||!function_exists('molavi_product_is_hidden'))
                molavi_domain_fail('autopilot_revert_unavailable',503);
            molavi_mark_product_held($wooId,[
                'sale_offer_expires_at'=>0,'market_image_id'=>0,
                'supplier_availability'=>'outofstock','source_record_id'=>'']);
            molavi_hide_product($wooId);
            $hidden=molavi_product_is_hidden($wooId);
            if(!$hidden)molavi_domain_fail('autopilot_revert_failed',409);
        }
        $value=(float)($inputs['regular_price']??$inputs['supplier_price']??0);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($original,$productId,$wooId,$hidden,$value,$now,$actor): void {
            // Owner hold, gate 'admission_owner': Arm 10 blocks any fresh
            // admission while a row exists for the product in
            // autopilot_holds, whatever its gate — and the tick's gA pass
            // only wipes gate='admission', so this hold survives the next
            // tick. Release it with release_hold.
            molavi_domain_autopilot_hold($db,'admission_owner',$productId,
                'بازبینی مالک: پذیرش لغو شد.',0.0,$value,0.0,$now);
            molavi_domain_sql($db,"UPDATE autopilot_log SET result='reverted' WHERE id=?",[(int)$original['id']]);
            // The live admission executed the product's shadow would_admit
            // rows; undoing it undoes them too, so the §6 evidence count
            // (which voids a product's counted decisions on a 'reverted'
            // row, like an override) restarts for the product as well.
            $voided=molavi_domain_sql($db,"UPDATE autopilot_log SET result='reverted'
                WHERE product_id=? AND action='shadow' AND result='would_admit'",[$productId])['changes'];
            molavi_domain_autopilot_log($db,(string)($original['rule_id']??''),'admission',
                $productId,(string)($original['case_id']??''),
                ['reverts_log_id'=>(int)$original['id'],'actor'=>$actor,'woo_id'=>$wooId,'hidden'=>$hidden,
                    'voided_shadow'=>(int)$voided],
                null,'revert','reverted',$now);
        });
        return ['ok'=>true,'log_id'=>(int)$original['id'],'product_id'=>$productId,
            'woo_id'=>$wooId,'hidden'=>$hidden,'held'=>true];
    }
    if($action==='hold_decision') {
        // Suspend one product's admission: write an owner hold (see
        // revert_decision for why the gate is 'admission_owner' and why
        // this is not a global publication hold) and log it. The hold
        // blocks fresh gA admissions (Arm 10) until the owner releases it.
        // It does NOT change the original decision row — the decision
        // stands as recorded; the hold stops the NEXT one.
        molavi_domain_body_keys($body,['action','log_id']);
        if(!is_int($body['log_id']))molavi_domain_fail('invalid_body',400);
        $original=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT * FROM autopilot_log WHERE id=?',[$body['log_id']]));
        if(!$original)molavi_domain_fail('autopilot_log_not_found',404);
        if((string)($original['gate']??'')!=='admission')molavi_domain_fail('invalid_body',400);
        $productId=(string)($original['product_id']??'');
        if($productId==='')molavi_domain_fail('invalid_body',400);
        $existing=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,"SELECT 1 FROM autopilot_holds WHERE product_id=? AND gate='admission_owner'",[$productId]));
        if($existing)molavi_domain_fail('autopilot_already_held',409);
        try { $inputs=molavi_domain_decode((string)($original['inputs_json']??'{}'),[]); }
        catch(Throwable $e) { $inputs=[]; }
        if(!is_array($inputs))$inputs=[];
        $value=(float)($inputs['regular_price']??$inputs['supplier_price']??0);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($original,$productId,$value,$now,$actor): void {
            molavi_domain_autopilot_hold($db,'admission_owner',$productId,
                'توقف پذیرش به دستور مالک.',0.0,$value,0.0,$now);
            molavi_domain_autopilot_log($db,(string)($original['rule_id']??''),'admission',
                $productId,(string)($original['case_id']??''),
                ['holds_log_id'=>(int)$original['id'],'actor'=>$actor,'reason'=>'توقف پذیرش به دستور مالک.'],
                null,'hold','held',$now);
        });
        return ['ok'=>true,'log_id'=>(int)$original['id'],'product_id'=>$productId,'held'=>true];
    }
    if($action==='release_hold') {
        // Release an owner admission hold (the counterpart to hold_decision
        // and to the hold revert_decision writes). Only the owner's own
        // hold is released — never a gate hold, never a publication hold.
        molavi_domain_body_keys($body,['action','product_id']);
        $productId=(string)($body['product_id']??'');
        if($productId===''||molavi_domain_strlen($productId)>200)molavi_domain_fail('invalid_body',400);
        $released=molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$now,$actor): int {
            $row=molavi_domain_row($db,"SELECT 1 FROM autopilot_holds WHERE product_id=? AND gate='admission_owner'",[$productId]);
            if(!$row)return 0;
            molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE product_id=? AND gate='admission_owner'",[$productId]);
            molavi_domain_autopilot_log($db,'gA','admission',$productId,'',
                ['product_id'=>$productId,'actor'=>$actor],null,'release_hold','released',$now);
            return 1;
        });
        if(!$released)molavi_domain_fail('autopilot_hold_not_found',404);
        return ['ok'=>true,'product_id'=>$productId,'held'=>false];
    }
    if($action==='confirm_reviewed') {
        // Mark one decision reviewed by the owner. Only the reviewed_*
        // fields move — the decision itself (result, verdict) stands. The
        // digest's "new" list (molavi_domain_autopilot_new_decisions) is
        // exactly the rows this has not yet touched. Digest contract (WS4,
        // fix/admission-digest-risk): the confirm ALSO writes a later
        // 'confirm' log row for the same product whose inputs carry
        // reviewed_log_id — WS4 shape (b) — which is simultaneously WS4
        // shape (c) (a later confirm row for the same product). A flagged
        // row stays new until this confirm suppresses it, in both queries.
        molavi_domain_body_keys($body,['action','log_id']);
        if(!is_int($body['log_id']))molavi_domain_fail('invalid_body',400);
        $original=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT * FROM autopilot_log WHERE id=?',[$body['log_id']]));
        if(!$original)molavi_domain_fail('autopilot_log_not_found',404);
        if(!in_array((string)($original['action']??''),['shadow','live'],true))
            molavi_domain_fail('invalid_body',400);
        if((float)($original['reviewed_at']??0)>0)molavi_domain_fail('autopilot_already_reviewed',409);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($original,$now,$actor): void {
            molavi_domain_sql($db,"UPDATE autopilot_log SET reviewed_by=?, reviewed_at=? WHERE id=? AND reviewed_at=0",
                [$actor,$now,(int)$original['id']]);
            molavi_domain_autopilot_log($db,(string)($original['rule_id']??''),(string)($original['gate']??''),
                (string)($original['product_id']??''),(string)($original['case_id']??''),
                ['reviewed_log_id'=>(int)$original['id'],'actor'=>$actor],null,'confirm','reviewed',$now);
        });
        return ['ok'=>true,'log_id'=>(int)$original['id'],'reviewed_at'=>$now,'reviewed_by'=>$actor];
    }
    if($action==='suspend_rule') {
        molavi_domain_body_keys($body,['action','rule_id']);
        $ruleId=(string)($body['rule_id']??'');
        $exists=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT id FROM autopilot_rules WHERE id=?',[$ruleId]));
        if(!$exists)molavi_domain_fail('autopilot_rule_not_found',404);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($ruleId): void {
            molavi_domain_sql($db,"UPDATE autopilot_rules SET state='suspended' WHERE id=?",[$ruleId]);
            $holdGate=['g5'=>'publish','g4'=>'market','g3'=>'supplier_price','gA'=>'admission'][$ruleId]??'';
            if($holdGate!=='')molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate=?",[$holdGate]);
        });
        return ['ok'=>true,'rule_id'=>$ruleId,'state'=>'suspended'];
    }
    if($action==='record_digest_watch') {
        // Called by the digest (external cron) after it has watched the
        // shadow evidence, so "2 digests watched" is a stored fact rather
        // than a calendar claim. settings_version/note are optional.
        $unknown=array_diff(array_keys($body),['action','digest_date','consecutive_correct','boundary_close','settings_version','note']);
        if($unknown)molavi_domain_fail('invalid_body',400);
        foreach(['digest_date','consecutive_correct','boundary_close'] as $key)
            if(!array_key_exists($key,$body))molavi_domain_fail('invalid_body',400);
        if(!is_string($body['digest_date'])||!is_int($body['consecutive_correct'])||!is_int($body['boundary_close']))
            molavi_domain_fail('invalid_body',400);
        $settingsVersion=is_string($body['settings_version']??null)?(string)$body['settings_version']:'';
        $note=is_string($body['note']??null)?(string)$body['note']:'';
        if(molavi_domain_strlen($note)>500)molavi_domain_fail('invalid_body',400);
        $watchId=molavi_domain_tx('pipeline',static function(SQLite3 $db)use($body,$settingsVersion,$note,$now): int {
            return molavi_domain_autopilot_record_digest_watch($db,(string)$body['digest_date'],
                (int)$body['consecutive_correct'],(int)$body['boundary_close'],$settingsVersion,$note,$now);
        });
        return ['ok'=>true,'digest_date'=>(string)$body['digest_date'],'watch_id'=>$watchId];
    }
    if($action==='advance_admission_rollout') {
        // Advance the staged live rollout one step (first -> three ->
        // open), after the owner reviews the admissions so far. One step
        // only; advancing from 'open', or from an unknown stage, is
        // rejected. The advance is logged (rule_id '' — an administrative
        // row the verdict runner skips) so the audit trail shows who
        // moved the stage and when.
        molavi_domain_body_keys($body,['action']);
        $settings=molavi_autopilot_settings();
        $dayStart=(float)strtotime(gmdate('Y-m-d',(int)$now).' 00:00:00 UTC');
        $cap=(int)($settings['admission_cap_per_day']??5);
        $rollout=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_autopilot_admission_rollout($db,$dayStart,$cap,$now));
        $next=['first'=>'three','three'=>'open'][(string)($rollout['stage']??'')]??null;
        if($next===null)molavi_domain_fail('invalid_body',400);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($rollout,$next,$now,$actor): void {
            molavi_domain_autopilot_admission_rollout_write($db,array_merge($rollout,['stage'=>$next]),$now);
            molavi_domain_autopilot_log($db,'','admission','','',
                ['rollout_from'=>(string)($rollout['stage']??''),'rollout_to'=>$next,'actor'=>$actor],
                null,'rollout','advanced',$now);
        });
        return ['ok'=>true,'rollout_stage'=>$next,'from'=>(string)($rollout['stage']??'')];
    }
    molavi_domain_fail('invalid_body',400);
}
