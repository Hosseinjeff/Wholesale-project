<?php
/** Plugin Name: Molavi Native Reference Source Fetch */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** A refresh attempt that dies mid-fetch used to lose every page it had
 * already read: the next attempt restarted at page 1 and died at the
 * same place. Fetched pages are checkpointed here (one row per page, in
 * the reference store) so a later attempt resumes instead of restarting.
 * A checkpoint is trusted only while it matches the fresh page-1 totals
 * and is under six hours old; anything else is discarded. */
const MOLAVI_REFERENCE_CHECKPOINT_SECONDS = 21600;
/** Wall-clock budget for one fetch attempt. The staging host kills a PHP
 * process past its execution limit with no catch and no record, so the
 * fetch aborts itself cleanly (woocommerce_fetch_timeout) before that
 * limit, leaving the checkpoint for the next attempt to resume. */
const MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS = 90.0;
/** D2: the R1 budget is a wall-clock budget only, and it is checked
 * between calls — never during one. Live attempts die mid-batch during
 * millisecond per-page work (checkpoint timestamps: pages 18–23 stored
 * 14:01:33, death on page 24; pages 24–25 stored 14:32:15, death on page
 * 26; progress updated 7 s after attempt start, then nothing), at a
 * steady cumulative ~24–26 pages and at varying elapsed times (15 s,
 * 23 s, 33 s). A time limit cannot explain deaths that early and that
 * variable; a cumulative heap ceiling can. The fetch held every full
 * Rezvan product (images/srcset/descriptions/variations included) plus
 * the WordPress stack, and a memory fatal is uncatchable — so no budget
 * check ever ran and no `partial` was ever recorded. Two guards:
 *  (a) products are trimmed at decode to the six keys the parser/store
 *      ever read (id, sku, name, categories, attributes, prices);
 *  (b) a memory ceiling, checked before/after every page, aborts cleanly
 *      as `woocommerce_fetch_memory_budget` (a `partial` pause, like the
 *      time budget) instead of dying. The ceiling is the PHP memory_limit
 *      less a reserve for the stage phase that follows the fetch. */
const MOLAVI_REFERENCE_FETCH_MEMORY_RESERVE_BYTES = 67108864;
/** Codes the scheduler treats as a budgeted pause (`partial`), not a
 * failure: the checkpoint keeps its pages and the next tick resumes. */
const MOLAVI_REFERENCE_FETCH_BUDGET_CODES = ['woocommerce_fetch_timeout', 'woocommerce_fetch_memory_budget'];
/** Keep only the keys the parser/store ever read (reference_code,
 * reference_row, reference_incremental). Everything else in a Store API
 * product — images/srcset, descriptions, price_html, variations — is
 * dead weight the fetch used to hold for all 3,416 products. Trimming
 * is idempotent, so checkpointed rows trim cleanly on load too. */
function molavi_domain_reference_trim_product(array $product): array {
    return ['id' => $product['id'] ?? null, 'sku' => $product['sku'] ?? '', 'name' => $product['name'] ?? '',
        'categories' => $product['categories'] ?? [], 'attributes' => $product['attributes'] ?? [], 'prices' => $product['prices'] ?? null];
}
/** Heap ceiling for one fetch attempt: the PHP memory_limit less the
 * reserve for staging. An explicit override (bytes, >0) replaces it;
 * 0/null means "compute from the limit". Defaults to 448 MiB when the
 * limit is unlimited or unreadable. */
function molavi_domain_reference_memory_budget(?int $override = null): int {
    if ($override !== null && $override > 0) return $override;
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') return 448 * 1024 * 1024;
    $unit = strtolower(substr($limit, -1)); $num = (float) $limit;
    $bytes = (int) ($num * ($unit === 'g' ? 1073741824 : ($unit === 'm' ? 1048576 : ($unit === 'k' ? 1024 : 1))));
    return max(64 * 1024 * 1024, $bytes - MOLAVI_REFERENCE_FETCH_MEMORY_RESERVE_BYTES);
}

function molavi_domain_reference_page(array $response,int $page,int $pageSize,?int $expectedTotal=null,?int $expectedPages=null): array {
    $status=(int)($response['status']??0);if($status!==200)molavi_domain_fail('woocommerce_http_'.$status,503);
    $headers=[];foreach($response['headers']??[] as $key=>$value)$headers[strtolower((string)$key)]=(string)$value;
    $total=filter_var($headers['x-wp-total']??null,FILTER_VALIDATE_INT);$pages=filter_var($headers['x-wp-totalpages']??null,FILTER_VALIDATE_INT);
    if($total===false||$pages===false||$total<1||$pages<1||$pages>500)molavi_domain_fail('woocommerce_pagination_invalid',503);
    if(($expectedTotal!==null&&$total!==$expectedTotal)||($expectedPages!==null&&$pages!==$expectedPages))molavi_domain_fail('woocommerce_pagination_changed',503);
    try{$products=json_decode((string)($response['body']??''),true,128,JSON_THROW_ON_ERROR);}
    catch(JsonException $e){molavi_domain_fail('woocommerce_json_invalid',503);}
    if(!is_array($products)||!array_is_list($products))molavi_domain_fail('woocommerce_page_invalid',503);
    if(count($products)!==min($pageSize,max(0,$total-($page-1)*$pageSize)))molavi_domain_fail('woocommerce_page_incomplete_'.$page,503);
    // D2: trim at decode — the fetch never holds a full product again.
    return [array_map('molavi_domain_reference_trim_product', $products), $total, $pages];
}
function molavi_domain_reference_page_url(int $page,int $size): string {
    return molavi_domain_reference_source().'?'.http_build_query(['per_page'=>$size,'page'=>$page,'orderby'=>'id','order'=>'asc'],'','&',PHP_QUERY_RFC3986);
}
function molavi_domain_reference_fetch_page(int $page,int $size,?int $total=null,?int $pages=null,int $timeout=30): array {
    for($attempt=0;$attempt<3;$attempt++){
        try{$response=molavi_domain_http('GET',molavi_domain_reference_page_url($page,$size),['timeout'=>$timeout]);
            $headers=wp_remote_retrieve_headers($response);
            return molavi_domain_reference_page(['status'=>wp_remote_retrieve_response_code($response),'body'=>wp_remote_retrieve_body($response),
                'headers'=>is_object($headers)&&method_exists($headers,'getAll')?$headers->getAll():(array)$headers],$page,$size,$total,$pages);
        }catch(MolaviWorkflowError $e){if($attempt>=2||!molavi_domain_reference_retryable($e->getMessage()))throw $e;usleep(200000*(2**$attempt));}
    }
    molavi_domain_fail('woocommerce_transport_failed',503);
}
function molavi_domain_reference_retryable(string $code): bool {
    return $code==='native_connector_unavailable'||str_starts_with($code,'woocommerce_transport_')
        ||in_array($code,['woocommerce_http_429','woocommerce_http_500','woocommerce_http_502','woocommerce_http_503','woocommerce_http_504'],true);
}
/** Checkpointed pages for one page size: rows already fetched and trusted.
 * D2: streamed one row at a time, not materialised whole. The old
 * version read every stored payload_json string into memory at once
 * (24 banked pages x ~3 MB = ~72 MB of strings before a single product
 * was decoded) — on a resumed attempt that spike alone could cross the
 * ceiling before any guard ran, because the load happens before the
 * fetch attempt starts. Each row is decoded, trimmed and stored before
 * the next is read, so the load holds at most one page at a time. */
function molavi_domain_reference_checkpoint_load(int $size): array {
    return molavi_domain_read('reference',static function(SQLite3 $db)use($size):array {
        $out=['total'=>null,'pages'=>null,'rows'=>[]];$fresh=microtime(true)-MOLAVI_REFERENCE_CHECKPOINT_SECONDS;
        $stmt=$db->prepare('SELECT page,total,pages,payload_json,fetched_at FROM reference_refresh_pages WHERE page_size=? ORDER BY page');
        $stmt->bindValue(1,$size,SQLITE3_INTEGER);$result=$stmt->execute();
        while(($row=$result->fetchArray(SQLITE3_ASSOC))!==false){
            if((float)$row['fetched_at']<$fresh)continue;
            $page=(int)$row['page'];$rowTotal=(int)$row['total'];$rowPages=(int)$row['pages'];
            try{$decoded=molavi_domain_decode($row['payload_json']);}catch(Throwable $e){continue;}
            unset($row);
            if(!is_array($decoded)||!array_is_list($decoded))continue;
            // D2: checkpoints banked before the fix hold full products;
            // trim on load so a resumed attempt never re-inflates.
            $decoded=array_map('molavi_domain_reference_trim_product',$decoded);
            $expected=min($size,max(0,$rowTotal-($page-1)*$size));
            if(count($decoded)!==$expected)continue;
            if($out['total']===null){$out['total']=$rowTotal;$out['pages']=$rowPages;}
            if($rowTotal!==$out['total']||$rowPages!==$out['pages'])continue;
            $out['rows'][$page]=$decoded;
        }
        $result->finalize();$stmt->close();
        return $out;
    });
}
function molavi_domain_reference_checkpoint_store(int $size,int $page,array $rows,int $total,int $pages): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($size,$page,$rows,$total,$pages):void {
        molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_refresh_pages VALUES (?,?,?,?,?,?)',
            [$size,$page,$total,$pages,molavi_domain_json($rows),microtime(true)]);
    });
}
function molavi_domain_reference_checkpoint_clear(?int $size=null): void {
    molavi_domain_tx('reference',static function(SQLite3 $db)use($size):void {
        if($size===null)molavi_domain_sql($db,'DELETE FROM reference_refresh_pages');
        else molavi_domain_sql($db,'DELETE FROM reference_refresh_pages WHERE page_size=?',[$size]);
    });
}
/** D2: pages are fetched ONE AT A TIME, not in 8-way parallel batches.
 * `Requests::request_multiple` is a busy loop with no wall-clock guard
 * and no progress write inside it: a stalled multi call runs past the
 * 90 s budget (harness T1: an 8 s call vs a 3 s budget fires the budget
 * only after the call returns) and, while it runs, no checkpoint or
 * progress write can land. Sequential pages are each a bounded call
 * (30 s timeout, 3 attempts) with the time budget AND the memory
 * ceiling checked before and after every page, so the attempt always
 * aborts cleanly as `partial` instead of dying mid-batch. At ~6 s a
 * page a resumed tick banks ~10 pages and the refresh converges over
 * successive ticks. A zero/negative budget disables the time budget
 * (the manual /reference/check endpoint only — a full sequential check
 * takes ~210 s, past the 90 s tick budget); the memory ceiling still
 * applies there. */
function molavi_domain_reference_fetch_attempt(int $size=100,?callable $progress=null,array $checkpoint=[],float $budgetSeconds=MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS,?callable $clock=null,bool $persistCheckpoint=false,?int $memoryBudgetBytes=null): array {
    if($size<1||$size>100)molavi_domain_fail('woocommerce_pagination_invalid',400);
    $startedAt=$clock?$clock():microtime(true);$deadline=$budgetSeconds>0?$startedAt+$budgetSeconds:INF;
    $budgetCheck=static function(int $completedPages,int $totalPages,int $records,int $totalRecords)use($clock,$deadline):void {
        if(($clock?$clock():microtime(true))<$deadline)return;
        molavi_domain_fail('woocommerce_fetch_timeout',503,['completed_pages'=>$completedPages,'total_pages'=>$totalPages,
            'records'=>$records,'total_records'=>$totalRecords]);
    };
    // D2: a memory fatal is uncatchable, so the ceiling is checked at
    // page granularity — before a page is fetched and after it lands —
    // and aborts as a budgeted pause while there is still headroom.
    $memoryBudget=molavi_domain_reference_memory_budget($memoryBudgetBytes);
    $memoryCheck=static function(int $completedPages,int $totalPages,int $records,int $totalRecords)use($memoryBudget):void {
        if(memory_get_usage(true)<=$memoryBudget)return;
        molavi_domain_fail('woocommerce_fetch_memory_budget',503,['completed_pages'=>$completedPages,'total_pages'=>$totalPages,
            'records'=>$records,'total_records'=>$totalRecords]);
    };
    [$first,$total,$pages]=molavi_domain_reference_fetch_page(1,$size);$products=$first;$byPage=[1=>$first];
    // Page 1 is the authority for this generation: a checkpoint from a
    // changed source (different totals) is ignored, never trusted.
    if($checkpoint&&(($checkpoint['total']??null)!==$total||($checkpoint['pages']??null)!==$pages)){
        if($persistCheckpoint)molavi_domain_reference_checkpoint_clear($size);
        $checkpoint=['total'=>$total,'pages'=>$pages,'rows'=>[]];
    }
    $stored=$checkpoint['rows']??[];
    foreach($stored as $page=>$rows){$byPage[$page]=$rows;array_push($products,...$rows);}
    $completedPages=1+count($stored);$records=count($products);
    if ($progress) $progress('fetching',$completedPages,$pages,$records,$total);
    $budgetCheck($completedPages,$pages,$records,$total);
    $memoryCheck($completedPages,$pages,$records,$total);
    for($page=2;$page<=$pages;$page++){
        if(isset($stored[$page]))continue;
        $budgetCheck($completedPages,$pages,$records,$total);
        $memoryCheck($completedPages,$pages,$records,$total);
        [$rows]=molavi_domain_reference_fetch_page($page,$size,$total,$pages,30);
        $byPage[$page]=$rows;
        if($persistCheckpoint)molavi_domain_reference_checkpoint_store($size,$page,$rows,$total,$pages);
        array_push($products,...$rows);$completedPages++;$records+=count($rows);
        if ($progress) $progress('fetching',$completedPages,$pages,$records,$total);
        $budgetCheck($completedPages,$pages,$records,$total);
        $memoryCheck($completedPages,$pages,$records,$total);
    }
    unset($checkpoint,$stored);
    // Reassemble in page order: checkpointed pages were merged in above,
    // fresh pages appended in batch order, so rebuild from the page map.
    $products=[];for($page=1;$page<=$pages;$page++)array_push($products,...($byPage[$page]??[]));
    if ($progress) $progress('validating',$pages,$pages,count($products),$total);
    if(count($products)!==$total)molavi_domain_fail('woocommerce_product_coverage_incomplete',503);
    $ids=[];$skus=[];$currency=null;
    foreach($products as $product){if(!is_array($product))molavi_domain_fail('woocommerce_product_invalid',503);
        $code=molavi_domain_reference_code($product);$id=(string)$product['id'];if(isset($ids[$id]))molavi_domain_fail('woocommerce_duplicate_product_id',503);$ids[$id]=true;
        $sku=(string)($product['sku']??'');if($sku!==''){$key=function_exists('mb_strtolower')?mb_strtolower(trim($sku),'UTF-8'):strtolower(trim($sku));
            if(isset($skus[$key]))molavi_domain_fail('woocommerce_duplicate_sku',503);$skus[$key]=true;}
        [, $currentCurrency]=molavi_domain_reference_price($product['prices']??null);
        if($currency!==null&&$currentCurrency!==$currency)molavi_domain_fail('woocommerce_currency_mismatch',503);$currency=$currentCurrency;
    }
    return molavi_domain_reference_candidate($products,$pages,(string)$currency,molavi_domain_now());
}
function molavi_domain_reference_fetch_products(int $size=100,?callable $progress=null,float $budgetSeconds=MOLAVI_REFERENCE_FETCH_BUDGET_SECONDS,?callable $clock=null,bool $persistCheckpoint=false,?int $memoryBudgetBytes=null): array {
    $checkpoint=$persistCheckpoint?molavi_domain_reference_checkpoint_load($size):['total'=>null,'pages'=>null,'rows'=>[]];
    for($generation=0;$generation<2;$generation++){
        try{return molavi_domain_reference_fetch_attempt($size,$progress,$checkpoint,$budgetSeconds,$clock,$persistCheckpoint,$memoryBudgetBytes);}
        catch(MolaviWorkflowError $e){$code=$e->getMessage();
            // Discard the entire incomplete generation, then start once from page 1.
            if($generation===0&&($code==='woocommerce_pagination_changed'||str_starts_with($code,'woocommerce_page_incomplete_'))){
                if($persistCheckpoint)molavi_domain_reference_checkpoint_clear($size);
                $checkpoint=['total'=>null,'pages'=>null,'rows'=>[]];
                if ($progress) $progress('retrying',0,0,0,0);
                continue;
            }
            // A budget abort is a pause, not a transport failure: resume
            // it from the checkpoint instead of retrying smaller pages.
            if(in_array($code,MOLAVI_REFERENCE_FETCH_BUDGET_CODES,true))throw $e;
            if($size<=50||!molavi_domain_reference_retryable($code))throw $e;
            if ($progress) $progress('retrying',0,0,0,0);
            return molavi_domain_reference_fetch_attempt(intdiv($size,2),$progress,['total'=>null,'pages'=>null,'rows'=>[]],$budgetSeconds,$clock,$persistCheckpoint,$memoryBudgetBytes);
        }
    }
    molavi_domain_fail('woocommerce_source_unstable',503);
}
