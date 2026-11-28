<?php
/** Market-match wiring harness: choose_source stamps the match columns and
 * records the kept match; refresh_source moves match_status on failed /
 * restored; the pinned-404 re-queue in market_run is unchanged.
 * Negative control: on a tree without molavi_domain_market_match_payload
 * (the wiring), the first owner pick creates no kept match and the harness
 * reports the new behaviour absent instead of failing.
 */
declare(strict_types=1);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-market-match-'.bin2hex(random_bytes(5)));
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('ABSPATH', MOLAVI_STATE_ROOT.'/wp/');
function add_action(...$args) {}
function add_filter(...$args) {}
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function get_current_user_id() { return (int) ($GLOBALS['current_user_id'] ?? 0); }
function wp_http_validate_url($url) { return $url; }
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
function molavi_domain_ai_review_cases(...$args) { return [['offer_id' => 'rani'], ['offer_id' => 'orphan-offer']]; }
function molavi_domain_http($method, $url, $args) {
    if (str_starts_with($url, 'https://openapi.basalam.com/v1/products/')) {
        $id = (int) basename($url);
        return isset($GLOBALS['basalam_products'][$id])
            ? ['status' => 200, 'body' => json_encode($GLOBALS['basalam_products'][$id])] : ['status' => 404, 'body' => '{}'];
    }
    return ['status' => 404, 'body' => '{}'];
}
$root = __DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-reference-scheduler',
    'domain-publisher', 'domain-products', 'domain-source-projection', 'domain-market-basalam', 'domain-market-prices',
    'domain-market-sources', 'domain-reference-matches'] as $module) require $root.'molavi-'.$module.'.php';

$count = 0;
function check($actual, $expected, $label) {
    global $count;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected))
        throw new RuntimeException($label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected));
    $count++;
    echo 'PASS '.$label."\n";
}
function check_true($actual, $label) { check($actual, true, $label); }
function market_row($target) {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
        'SELECT * FROM market_product_sources WHERE target=?', [$target]));
}
function match_row($product) { return molavi_domain_reference_match_get($product, 'market'); }
function source_events($kind, $product) {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
        'SELECT * FROM market_source_events WHERE kind=? AND product_id=? ORDER BY rowid', [$kind, $product]));
}
function match_events($product) {
    return molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_rows($db,
        'SELECT * FROM reference_match_events WHERE product_id=? AND source=? ORDER BY rowid', [$product, 'market']));
}
function product_record($id, $name, $brand, $category, $variant, $volume, $consumer, $offer, $extra = []) {
    return array_merge(['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => 'imported', 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''], $extra);
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $consumer, $productId, $extra = []) {
    return array_merge(['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => '', 'Package Count' => 1,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => $consumer, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-'.$id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-01T00:00:00+00:00',
        'Source Timestamp Basis' => '', 'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''], $extra);
}

$stateRoot = molavi_state_root();
foreach (['pipeline', 'reference'] as $store) { $db = new SQLite3($stateRoot.'/'.$store.'.sqlite3'); $db->close(); }
$products = [product_record('rani-1', 'آبمیوه رانی هلو ۲۴۰ میل', 'رانی', 'آبمیوه', 'هلو', '', '', 'rani',
    ['Package Count' => 24, 'Sale Price' => 68750])];
$offers = [
    offer_record('rani', 'آبمیوه رانی هلو ۲۴۰ میل', 'رانی', 'آبمیوه', 'هلو', '', '', 'rani-1',
        ['Package Count' => 24, 'Sale Price' => 68750]),
    offer_record('orphan-offer', 'رانی هلو ۲۴۰ میل', 'رانی', 'آبمیوه', 'هلو', '', '', '',
        ['Package Count' => 1, 'Sale Price' => 100000]),
];
$GLOBALS['sheets'] = [
    'Products' => molavi_domain_rows_from_records(molavi_domain_product_headers(), $products),
    'Offers' => molavi_domain_rows_from_records(molavi_domain_offer_headers(), $offers),
    'Shop' => [],
];
$cartonA = ['id' => 62769014, 'title' => 'رانی هلو 240 میل باکس 24 عددی', 'description' => 'تعداد ۲۴ عدد',
    'category' => ['title' => 'آبمیوه و شربت'], 'primary_price' => 26260000, 'price' => 21220000, 'inventory' => 9,
    'photo' => ['lg' => 'https://img.example/rani.jpg', 'xs' => 'https://img.example/rani-xs.jpg'],
    'status' => ['value' => 2976], 'variants' => null, 'vendor' => ['id' => 2, 'title' => 'فروشگاه نمونه']];
$cartonB = array_replace($cartonA, ['id' => 62769015, 'primary_price' => 27000000, 'price' => 22000000,
    'vendor' => ['id' => 5, 'title' => 'فروشگاه دوم']]);
$single = array_replace($cartonA, ['id' => 3, 'title' => 'رانی هلو ۲۴۰ میل', 'description' => 'تکی',
    'primary_price' => 1265000, 'price' => 1265000, 'vendor' => ['id' => 3]]);
$GLOBALS['basalam_products'] = [62769014 => $cartonA, 62769015 => $cartonB, 3 => $single];

/* --- Owner pick of carton A (logged-in owner, derived actor/basis) --- */
$GLOBALS['current_user_id'] = 7;
$now = time();
$tables = $GLOBALS['sheets'];
$subject = molavi_domain_market_subject('rani', $tables);
check_true($subject !== null && $subject['product_id'] === 'rani-1' && $subject['target'] === 'product:rani-1',
    'subject resolves the rani product');
$identity = $subject['identity'];
$quoteA = molavi_domain_basalam_quote(62769014, $identity);
check_true($quoteA !== null && $quoteA['regular_unit_price'] === 109417 && $quoteA['pack_count'] === 24,
    'carton A quote carries the per-unit regular price');
$event = molavi_domain_market_choose_source($subject, $quoteA, null, $now, $now);
check_true(is_string($event), 'owner pick records a selected event');

/* --- NEGATIVE CONTROL: the base has no wiring helper, so no kept match --- */
if (!function_exists('molavi_domain_market_match_payload')) {
    check(match_row('rani-1'), null, 'negative: no kept market match on the base');
    check(market_row('product:rani-1')['match_basis'] ?? null, null, 'negative: no match columns stamped on the base');
    foreach (glob(MOLAVI_STATE_ROOT.'/*') as $file) unlink($file);
    rmdir(MOLAVI_STATE_ROOT);
    echo 'NEGATIVE native market match wiring '.$count." cases (new behaviour absent on the base)\n";
    exit(0);
}

$row = market_row('product:rani-1');
check($row['url'], 'https://basalam.com/p/62769014', 'choose: market row keeps the picked listing');
check($row['regular_price'], 109417, 'choose: market row keeps the per-unit regular price');
check($row['match_basis'], 'owner_pick', 'choose: owner pick stamps owner_pick');
check($row['matched_by'], 'wp:7', 'choose: owner pick stamps the owner actor');
check_true(is_int($row['matched_at']) && $row['matched_at'] >= $now && $row['matched_at'] <= time(),
    'choose: matched_at stamped at pick time');
check($row['match_status'], 'active', 'choose: new pick is active');
$match = match_row('rani-1');
check($match['reference_id'], 'basalam-62769014', 'choose: kept match references the Basalam listing');
check($match['listing_id'], 'basalam-62769014', 'choose: kept match carries the listing id');
check($match['provider'], 'basalam', 'choose: kept match provider is basalam');
check($match['merchant'], 'باسلام · فروشگاه نمونه', 'choose: kept match merchant kept');
check($match['regular_price'], 109417, 'choose: kept match regular price is per-unit');
check($match['pack_count'], 24, 'choose: kept match pack count kept');
check($match['url'], 'https://basalam.com/p/62769014', 'choose: kept match url kept');
check($match['actor_id'], 'wp:7', 'choose: kept match actor is the owner');
check($match['status'], 'active', 'choose: kept match is active');
check($match['match_basis'], 'owner_pick', 'choose: kept match basis is owner_pick');
check($match['decision_id'], null, 'choose: market pick has no decision row');
check_true(!molavi_domain_reference_match_is_trusted($match), 'choose: market pick without a decision is untrusted');
check(molavi_domain_decode($match['quote_json'])['url'], 'https://basalam.com/p/62769014', 'choose: kept match quote kept');
check(molavi_domain_hash(molavi_domain_decode($match['identity_json'])), molavi_domain_hash($identity),
    'choose: kept match identity kept');
$selected = source_events('selected', 'rani-1');
check(count($selected), 1, 'choose: one selected event');
check([$selected[0]['regular_price'], $selected[0]['publish_pending']], [109417, 1],
    'choose: selected event carries the price and waits for dispatch');
check(count(match_events('rani-1')), 1, 'choose: one created match event');
check(match_events('rani-1')[0]['kind'], 'created', 'choose: match event is created');
molavi_domain_market_flush_events();
check(source_events('selected', 'rani-1')[0]['publish_pending'], 0, 'choose: flush dispatches the selected event');

/* --- Same pick through the owner tab API: reaffirm, no new match event --- */
$again = molavi_domain_market_price_api('POST', ['offer_id' => 'rani', 'action' => 'select_market_price_source',
    'url' => 'https://basalam.com/p/62769014'], 'wp:7');
check($again['selected_url'], 'https://basalam.com/p/62769014', 'api: the owner tab re-pick keeps the selected source');
check($again['tracking']['regular_price'], 109417, 'api: the owner tab re-pick keeps the regular price');
check(count(match_events('rani-1')), 1, 'api: re-picking the same listing writes no new match event');
check(match_row('rani-1')['match_basis'], 'owner_pick', 'api: re-pick keeps the owner basis');

/* --- Refresh: price changed, the row keeps the new price, match stays active --- */
$GLOBALS['basalam_products'][62769014]['primary_price'] = 28800000;
molavi_domain_market_tick((int) market_row('product:rani-1')['next_at']);
$row = market_row('product:rani-1');
check($row['regular_price'], 120000, 'refresh changed: the row keeps the new regular price');
check($row['failure'], null, 'refresh changed: no failure recorded');
check($row['match_status'], 'active', 'refresh changed: the match stays active');
$changed = source_events('changed', 'rani-1');
check(count($changed), 1, 'refresh changed: one changed event');
check([$changed[0]['old_price'], $changed[0]['regular_price'], $changed[0]['publish_pending']], [109417, 120000, 0],
    'refresh changed: event carries old and new prices and is dispatched');

/* --- Refresh: listing gone, the match needs review, last price kept --- */
unset($GLOBALS['basalam_products'][62769014]);
molavi_domain_market_tick((int) market_row('product:rani-1')['next_at']);
$row = market_row('product:rani-1');
check($row['failure'], 'listing_unavailable', 'refresh failed: the listing is marked unavailable');
check($row['regular_price'], 120000, 'refresh failed: the last verified price is kept');
check($row['match_status'], 'needs_review', 'refresh failed: the match needs review');
$failed = source_events('failed', 'rani-1');
check(count($failed), 1, 'refresh failed: one failed event');
check([$failed[0]['code'], $failed[0]['old_price'], $failed[0]['regular_price'], $failed[0]['publish_pending']],
    ['listing_unavailable', 120000, null, 0], 'refresh failed: event carries the code and the last price');

/* --- Refresh: listing back at the same price, the match is active again --- */
$GLOBALS['basalam_products'][62769014] = array_replace($cartonA, ['primary_price' => 28800000]);
molavi_domain_market_tick((int) market_row('product:rani-1')['next_at']);
$row = market_row('product:rani-1');
check($row['failure'], null, 'refresh restored: the failure clears');
check($row['regular_price'], 120000, 'refresh restored: the regular price is unchanged');
check($row['match_status'], 'active', 'refresh restored: the match is active again');
$restored = source_events('restored', 'rani-1');
check(count($restored), 1, 'refresh restored: one restored event');
check([$restored[0]['old_price'], $restored[0]['regular_price']], [120000, 120000],
    'refresh restored: event carries the kept price');

/* --- System pick (no logged-in user): carton B, auto_discovery --- */
$GLOBALS['current_user_id'] = 0;
$nowB = time();
$quoteB = molavi_domain_basalam_quote(62769015, $identity);
check_true($quoteB !== null && $quoteB['regular_unit_price'] === 112500, 'system pick: carton B quote verified');
$eventB = molavi_domain_market_choose_source($subject, $quoteB, market_row('product:rani-1'), $nowB, $nowB);
check_true(is_string($eventB), 'system pick: records a selected event');
$row = market_row('product:rani-1');
check($row['url'], 'https://basalam.com/p/62769015', 'system pick: market row moves to the new listing');
check($row['regular_price'], 112500, 'system pick: market row keeps the new regular price');
check($row['match_basis'], 'auto_discovery', 'system pick: system selection stamps auto_discovery');
check($row['matched_by'], 'system:market', 'system pick: system selection stamps the market actor');
check($row['match_status'], 'active', 'system pick: new pick is active');
$match = match_row('rani-1');
check($match['reference_id'], 'basalam-62769015', 'system pick: kept match moves to the new listing');
check($match['match_basis'], 'auto_discovery', 'system pick: kept match basis is auto_discovery');
check($match['actor_id'], 'system:market', 'system pick: kept match actor is the market system');
check($match['regular_price'], 112500, 'system pick: kept match regular price follows the pick');
$replaced = array_values(array_filter(match_events('rani-1'), static fn($e) => $e['kind'] === 'replaced'));
check(count($replaced), 1, 'system pick: one replaced match event');
check([$replaced[0]['old_reference_id'], $replaced[0]['new_reference_id']],
    ['basalam-62769014', 'basalam-62769015'], 'system pick: replaced event carries old and new listing');

/* --- Explicit actor/basis: owner pasted a URL, re-pick carton A --- */
$GLOBALS['current_user_id'] = 7;
$now7 = time();
$quoteA2 = molavi_domain_basalam_quote(62769014, $identity);
check_true($quoteA2 !== null && $quoteA2['regular_unit_price'] === 120000, 'explicit pick: carton A quote at the changed price');
$event7 = molavi_domain_market_choose_source($subject, $quoteA2, market_row('product:rani-1'), $now7, $now7, 'wp:9', 'owner_url');
check_true(is_string($event7), 'explicit pick: records a selected event');
$row = market_row('product:rani-1');
check([$row['match_basis'], $row['matched_by']], ['owner_url', 'wp:9'],
    'explicit pick: explicit basis and actor win over the derived ones');
$match = match_row('rani-1');
check([$match['match_basis'], $match['actor_id'], $match['reference_id']], ['owner_url', 'wp:9', 'basalam-62769014'],
    'explicit pick: kept match carries the explicit basis, actor and listing');

/* --- Offer-only pick: row stamped, no kept match, no crash --- */
$orphanSubject = molavi_domain_market_subject('orphan-offer', $tables);
check_true($orphanSubject !== null && $orphanSubject['product_id'] === '' && $orphanSubject['target'] === 'offer:orphan-offer',
    'offer-only: subject has no product');
$orphanQuote = molavi_domain_basalam_quote(3, $orphanSubject['identity']);
check_true($orphanQuote !== null && $orphanQuote['regular_unit_price'] === 126500, 'offer-only: single-unit quote verified');
$nowG = time();
$eventG = molavi_domain_market_choose_source($orphanSubject, $orphanQuote, null, $nowG, $nowG);
check($eventG, null, 'offer-only: no event without a product');
$orphanRow = market_row('offer:orphan-offer');
check($orphanRow['url'], 'https://basalam.com/p/3', 'offer-only: market row keeps the picked listing');
check([$orphanRow['match_basis'], $orphanRow['matched_by'], $orphanRow['match_status']], ['owner_pick', 'wp:7', 'active'],
    'offer-only: match columns stamped on the row');
check((int) molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
    "SELECT COUNT(*) AS n FROM reference_product_matches WHERE product_id=''")['n']), 0,
    'offer-only: no kept match without a product');

/* --- Pinned 404: the dead pin goes back through discovery --- */
$nowF = time();
molavi_domain_tx('reference', static function (SQLite3 $db) use ($identity, $quoteA2, $nowF): void {
    molavi_domain_sql($db, "INSERT INTO market_price_lookups(lookup_key,identity_json,active,state,next_at,checked_at,candidates_json,selected_url) VALUES(?,?,1,'ready',?,?,?,?)",
        [molavi_domain_hash($identity), molavi_domain_json($identity), $nowF, $nowF, molavi_domain_json([$quoteA2]), 'https://basalam.com/p/62769014']);
});
unset($GLOBALS['basalam_products'][62769014]);
molavi_domain_market_run($nowF);
$lookup = molavi_domain_read('reference', static fn(SQLite3 $db) => molavi_domain_row($db,
    'SELECT * FROM market_price_lookups WHERE lookup_key=?', [molavi_domain_hash($identity)]));
check($lookup['state'], 'queued', 'pinned 404: the missing listing goes back to discovery');
check($lookup['selected_url'], null, 'pinned 404: the dead pin is cleared');
check((int) $lookup['next_at'], $nowF, 'pinned 404: rediscovery is due immediately');

foreach (glob(MOLAVI_STATE_ROOT.'/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'ALL MARKET MATCH WIRING CHECKS PASSED '.$count." cases\n";
