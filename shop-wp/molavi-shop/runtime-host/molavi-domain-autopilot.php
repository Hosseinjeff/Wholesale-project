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
        'rule_modes'=>['g5'=>'shadow','g4'=>'shadow','g3'=>'shadow'],
        'verdict_sampling'=>'all',
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
            verdict_reason TEXT NOT NULL DEFAULT '', created_at REAL NOT NULL)");
        molavi_domain_sql($db,"CREATE TABLE IF NOT EXISTS autopilot_holds (
            product_id TEXT NOT NULL, gate TEXT NOT NULL, reason TEXT NOT NULL,
            priority REAL NOT NULL, score REAL NOT NULL, created_at REAL NOT NULL,
            PRIMARY KEY(product_id,gate))");
        // Existing installs predate verdict_reason: migrate in place.
        $columns=array_column(molavi_domain_rows($db,'PRAGMA table_info(autopilot_log)'),'name');
        if(!in_array('verdict_reason',$columns,true))
            $db->exec("ALTER TABLE autopilot_log ADD COLUMN verdict_reason TEXT NOT NULL DEFAULT ''");
        $now=microtime(true);
        $defaults=molavi_autopilot_defaults();
        $seed=[
            ['g5','publish',['note'=>'pure function of the other gates']],
            ['g4','market',['quote_band'=>$defaults['quote_band'],'reference_margin'=>$defaults['reference_margin']]],
            ['g3','supplier_price',['tolerance'=>$defaults['supplier_price_tolerance'],
                'consecutive_limit'=>$defaults['consecutive_auto_limit']]],
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
    string $caseId,array $inputs,?float $confidence,string $action,string $result,float $now): int {
    return (int)molavi_domain_sql($db,'INSERT INTO autopilot_log
        (rule_id,gate,product_id,case_id,inputs_json,confidence,action,result,verdict,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)',
        [$ruleId,$gate,$productId,$caseId,molavi_domain_json($inputs),$confidence,$action,$result,'pending',$now])['last_id'];
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

// --------------------------------------------------------------- verdict (AI)

const MOLAVI_AUTOPILOT_VERDICT_MAX_PER_TICK = 10;

// Railway verdict round-trips (possible cold start + model call, 45s proxy
// timeout) are far slower than a direct call. Cap the verdicts per tick so
// the step stays inside the runtime-host lock window (stale after 100s);
// the pending queue drains over successive ticks, oldest first.
const MOLAVI_AUTOPILOT_VERDICT_RAILWAY_MAX_PER_TICK = 3;

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
        ."than the claimed basis, or evidence missing). Write the reason in Persian, one short sentence.\n\n"
        .implode("\n",$lines);
}

function molavi_domain_autopilot_run_verdicts(array $settings,float $now): array {
    $counts=['checked'=>0,'approved'=>0,'flagged'=>0,'not_run'=>0];
    $sampling=(string)($settings['verdict_sampling']??'all');
    if($sampling==='off')return $counts;
    $limit=min($sampling==='all'?MOLAVI_AUTOPILOT_VERDICT_MAX_PER_TICK:5,
        MOLAVI_AUTOPILOT_VERDICT_RAILWAY_MAX_PER_TICK);
    $rows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_rows($db,"SELECT * FROM autopilot_log WHERE verdict='pending'
            AND rule_id<>'' AND action IN ('shadow','live')
            ORDER BY created_at ASC, id ASC LIMIT ?",[$limit]));
    foreach($rows as $row) {
        $id=(int)($row['id']??0);
        if($id<=0)continue;
        $counts['checked']++;
        $verdict=molavi_domain_gemini_generate(molavi_domain_autopilot_verdict_prompt($row));
        if(!$verdict) { $counts['not_run']++; continue; }
        $reason=trim((string)($verdict['reason']??''));
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
        }
    }
    // The rules wipe every hold for their gate at the start of each tick, so
    // a flagged hold would vanish on the next tick unless re-asserted here.
    // Re-assert flags from the last 7 days (skipping overridden decisions).
    $flagged=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_rows($db,"SELECT product_id, gate, verdict_reason FROM autopilot_log
            WHERE verdict='flag' AND rule_id<>'' AND action IN ('shadow','live')
            AND result<>'overridden' AND created_at>=? ORDER BY created_at DESC LIMIT 50",
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

function molavi_domain_autopilot_tick(?float $clock=null): array {
    $now=$clock??microtime(true);
    $summary=['ok'=>true,'shadow'=>0,'live'=>0,'holds'=>0,'errors'=>[]];
    try {
        molavi_domain_autopilot_ensure_tables();
        if(!get_option('molavi_state_active',false))return $summary+['skipped'=>'state_inactive'];
        $settings=molavi_autopilot_settings();
        $tables=molavi_domain_sheets(['Products','Offers'],false);
        $dayStart=(float)strtotime(gmdate('Y-m-d',(int)$now).' 00:00:00 UTC');
        foreach(['g5','g4','g3'] as $gate) {
            try {
                $result=molavi_domain_autopilot_rule($gate,$settings,$tables,$now,$dayStart);
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
    } catch(Throwable $e) {
        $summary['ok']=false;$summary['errors']['tick']='tick_failed';
        error_log('[molavi-native] autopilot_tick_failed');
    }
    return $summary;
}

function molavi_domain_autopilot_rule(string $gate,array $settings,array $tables,float $now,float $dayStart): array {
    $state=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_row($db,'SELECT state FROM autopilot_rules WHERE id=?',[$gate]));
    if(($state['state']??'')==='suspended')return ['shadow'=>0,'live'=>0,'holds'=>0];
    $mode=(string)($settings['rule_modes'][$gate]??'shadow');
    $live=(bool)($settings['master_enabled']??false)&&$mode==='live';
    $shadow=(bool)($settings['shadow_enabled']??true);
    if(!$live&&!$shadow)return ['shadow'=>0,'live'=>0,'holds'=>0];
    return match($gate){
        'g5'=>molavi_domain_autopilot_rule_publish($settings,$tables,$now,$dayStart,$live,$shadow),
        'g4'=>molavi_domain_autopilot_rule_market($settings,$tables,$now,$dayStart,$live,$shadow),
        'g3'=>molavi_domain_autopilot_rule_supplier_price($settings,$tables,$now,$dayStart,$live,$shadow),
        default=>['shadow'=>0,'live'=>0,'holds'=>0],
    };
}

// ------------------------------------------------------------- rule G5: publish

function molavi_domain_autopilot_rule_publish(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow): array {
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
    $held=(bool)molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
        molavi_domain_row($db,'SELECT 1 FROM publication_holds LIMIT 1'));
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
        if(molavi_domain_stock_status($record['Stock Status']??null)==='Out of Stock')continue;
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
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'publish',$id,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now): void {
                molavi_domain_autopilot_log($db,'g5','publish',$id,'',$inputs,1.0,'shadow','would_request',$now);
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
                ['action'=>'request_publication','product_id'=>$id,'offer_id'=>$offerId]);
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
        $inputs['publish_job']=$result['publish_job']??null;
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now,$result): void {
            molavi_domain_autopilot_log($db,'g5','publish',$id,'',$inputs,1.0,'live',
                (string)($result['state']??'queued'),$now);
            molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='g5'",[$now]);
        });
        $counts['live']++;
    }
    return $counts;
}

// -------------------------------------------------------------- rule G4: market

function molavi_domain_autopilot_rule_market(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow): array {
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
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'market',$productId,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$inputs,$confidence,$now): void {
                molavi_domain_autopilot_log($db,'g4','market',$productId,'',$inputs,$confidence,'shadow','would_pick',$now);
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
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($productId,$inputs,$confidence,$now): void {
            molavi_domain_autopilot_log($db,'g4','market',$productId,'',$inputs,$confidence,'live','picked',$now);
            molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='g4'",[$now]);
        });
        $counts['live']++;
    }
    return $counts;
}

// ------------------------------------------------------- rule G3: supplier price

function molavi_domain_autopilot_rule_supplier_price(array $settings,array $tables,float $now,float $dayStart,
    bool $live,bool $shadow): array {
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
        if(!$live) {
            if(!$shadow)continue;
            $recent=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
                molavi_domain_autopilot_recently_logged($db,'supplier_price',$id,'shadow',$now-6*3600));
            if($recent)continue;
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now): void {
                molavi_domain_autopilot_log($db,'g3','supplier_price',$id,'',$inputs,1.0,'shadow','would_verify',$now);
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
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($id,$inputs,$now): void {
            molavi_domain_autopilot_log($db,'g3','supplier_price',$id,'',$inputs,1.0,'live','verified',$now);
            molavi_domain_sql($db,"UPDATE autopilot_rules SET last_applied_at=?,apply_count=apply_count+1 WHERE id='g3'",[$now]);
        });
        $counts['live']++;
    }
    return $counts;
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
    $out['master_enabled']=$bool('master_enabled');
    $out['shadow_enabled']=$bool('shadow_enabled');
    if(array_key_exists('rule_modes',$input)) {
        if(!is_array($input['rule_modes']))molavi_domain_fail('invalid_body',400);
        $modes=$out['rule_modes'];
        foreach($input['rule_modes'] as $gate=>$mode) {
            if(!in_array($gate,['g5','g4','g3'],true)||!in_array($mode,['shadow','live'],true))
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
        foreach($log as &$row){$row['inputs']=molavi_domain_decode($row['inputs_json']??'{}');unset($row['inputs_json']);}
        unset($row);
        $holds=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_rows($db,'SELECT * FROM autopilot_holds ORDER BY priority ASC, created_at ASC'));
        return ['ok'=>true,'settings'=>$settings,'rules'=>$rules,'log'=>$log,'holds'=>$holds];
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
            $modes=array_keys($body['settings']['rule_modes']);
            molavi_domain_tx('pipeline',static function(SQLite3 $db)use($modes): void {
                foreach($modes as $gate) {
                    if(!in_array($gate,['g5','g4','g3'],true))continue;
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
    if($action==='suspend_rule') {
        molavi_domain_body_keys($body,['action','rule_id']);
        $ruleId=(string)($body['rule_id']??'');
        $exists=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>
            molavi_domain_row($db,'SELECT id FROM autopilot_rules WHERE id=?',[$ruleId]));
        if(!$exists)molavi_domain_fail('autopilot_rule_not_found',404);
        molavi_domain_tx('pipeline',static function(SQLite3 $db)use($ruleId): void {
            molavi_domain_sql($db,"UPDATE autopilot_rules SET state='suspended' WHERE id=?",[$ruleId]);
            $holdGate=['g5'=>'publish','g4'=>'market','g3'=>'supplier_price'][$ruleId]??'';
            if($holdGate!=='')molavi_domain_sql($db,"DELETE FROM autopilot_holds WHERE gate=?",[$holdGate]);
        });
        return ['ok'=>true,'rule_id'=>$ruleId,'state'=>'suspended'];
    }
    molavi_domain_fail('invalid_body',400);
}
