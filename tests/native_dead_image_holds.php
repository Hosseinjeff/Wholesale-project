<?php
/** Dead-image holds — round-3 follow-up (2026-11-28).
 *
 * Fresh probe 03:31 Nov 28: 15 publish-gate + 16 admission-gate holds with
 * reason «تصویر کالا خراب است.», all from the same 10 pre-R3 dead file
 * IDs. Three procedure defects:
 *
 * (1) FALSE POSITIVE — the autopilot image arms (admission Arm 8, g5
 * publish arm) judge ONLY the sheet source file ID against the dead
 * list, ignoring a picture the shopper already sees. ID 53 سانی نس has
 * a dead source file but a WORKING store image (GET 200, 32,150 B) and
 * is published and sellable — yet Arm 8 holds it. The apply-side gate
 * (molavi_product_image_gate) and the projection's on-shelf carve-out
 * already encode "attached image = usable"; the autopilot arms were the
 * only judges that did not. Fix: shared pure helper
 * molavi_domain_autopilot_image_reason(), used by both arms; an attached
 * featured image or a market photo satisfies the arm.
 *
 * (2) GRANDFATHERED DEAD LIST — R3 two-strike governs only NEW 404s.
 * The 10 pre-R3 entries were marked on a SINGLE 404 and are never
 * fetched again (apply excludes dead files from the image queue), so a
 * transient 404 can never clear. Fix: bounded re-validation in
 * molavi_domain_publish_shop — at most 2 due dead files per run, at
 * most once per 7 days per file; a 200 un-marks the file (same evidence
 * standard R3 uses for suspects), a 404 keeps it dead.
 *
 * (3) CARD PATH — a dead-image card showed only the reason plus a
 * generic next-step line (which talks about prices/specs, not the
 * image), with no action. Fix: image-specific guidance under the hold
 * notice in owner-dashboard.js (verified by node --check, not here —
 * the dashboard JS suite runs in Chromium).
 *
 * (4) JOB-RESULT SURFACING (round-4 follow-up, 2026-11-28): the
 * round-4 probe found 10 recheck stamps but 0 of 20 sampled
 * publish-job results carrying image_rechecks. publish_shop DOES set
 * $result['image_rechecks'] (Part 5), so the drops are downstream:
 * (6a) molavi_domain_publish_job's superseded return rebuilt the
 * result from scratch, discarding the recheck block's work; (6b) the
 * incomplete branch of molavi_domain_run_jobs stored only 6 keys,
 * dropping image_rechecks. Fix: carry image_rechecks through the
 * superseded return, and add it to the stored-result allowlist.
 * Part 6 pins both. Note the probe also misread recency: it ordered
 * jobs by id DESC, but publish job ids end in random hex, so that is
 * an arbitrary sample, not the 20 most recent — the correct order is
 * updated_at DESC.
 *
 * Harness: synthetic runtime, no WP database, no network, no tick.
 * Negative control: on the pristine gate tip (37db720) the new helper
 * and recheck helpers do not exist, so Parts 1+5 fail, and Parts 2–4
 * fail on the attached-image / recheck behaviour; Part 6 fails too
 * (no recheck block exists there, so no run produces image_rechecks).
 * Run:
 * php tests/native_dead_image_holds.php
 */
declare(strict_types=1);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-dead-image-' . bin2hex(random_bytes(5)));
define('MOLAVI_REFERENCE_SERVER_URL', 'https://reference.test');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('ABSPATH', MOLAVI_STATE_ROOT . '/wp/');
define('DAY_IN_SECONDS', 86400);
@mkdir(ABSPATH . 'wp-admin/includes/', 0755, true);
foreach (['file', 'image', 'media'] as $stub) touch(ABSPATH . 'wp-admin/includes/' . $stub . '.php');
$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['postmeta'] = [];
$GLOBALS['thumbs'] = [];
$GLOBALS['image_attachments'] = [88];
$GLOBALS['fake_products'] = [];
$GLOBALS['next_woo_id'] = 100;
$GLOBALS['sheets'] = [];
$GLOBALS['fetch_map'] = [];
$GLOBALS['fetch_default'] = '404';
$GLOBALS['fetch_calls'] = [];
$GLOBALS['fetch_jpeg'] = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100);
$pass = 0; $fail = 0;
function di_check(bool $ok, string $label, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS $label\n"; }
    else { $fail++; echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function add_option($key, $value, $a = '', $b = false) {
    if (isset($GLOBALS['options'][$key])) return false;
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key]); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function get_post_meta($id, $key, $single = true) {
    $v = $GLOBALS['postmeta'][(int) $id][$key] ?? '';
    return $single ? $v : ($v === '' ? [] : [$v]);
}
function update_post_meta($id, $key, $value) { $GLOBALS['postmeta'][(int) $id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['postmeta'][(int) $id][$key]); return true; }
function get_post_thumbnail_id($id) { return (int) ($GLOBALS['thumbs'][(int) $id] ?? 0); }
function set_post_thumbnail($id, $attach) { $GLOBALS['thumbs'][(int) $id] = (int) $attach; return true; }
function wp_attachment_is_image($id) { return in_array((int) $id, $GLOBALS['image_attachments'], true); }
function wp_tempnam($name) { return tempnam(sys_get_temp_dir(), $name); }
function is_wp_error($value) { return false; }
function media_handle_sideload($image, $post, $title) {
    if (!is_file($image['tmp_name'])) throw new RuntimeException('bad tmp image');
    unlink($image['tmp_name']);
    $GLOBALS['image_attachments'][] = 99;
    return 99;
}
function get_the_title($id) { return $GLOBALS['fake_products'][(int) $id]->name ?? ''; }
function get_posts($args = []) { return array_keys($GLOBALS['fake_products']); }
function wc_get_products($args = []) {
    $ids = array_keys($GLOBALS['fake_products']);
    if (isset($args['status'])) {
        $want = (array) $args['status'];
        $ids = array_values(array_filter($ids, static fn($id) => in_array($GLOBALS['fake_products'][$id]->status, $want, true)));
    }
    return ($args['return'] ?? '') === 'ids' ? $ids : array_values(array_map(static fn($id) => $GLOBALS['fake_products'][$id], $ids));
}
function wc_get_product($id) { return $GLOBALS['fake_products'][(int) $id] ?? null; }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
function wp_parse_url($url) { return parse_url((string) $url) ?: []; }
function esc_url_raw($url, $schemes = []) { return (string) $url; }
function wp_json_encode($v) { return json_encode($v); }
function status_header($code) {}
function nocache_headers() {}
function molavi_legacy_config(): array { return ['notify_secret' => 'test-secret']; }
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) { return $GLOBALS['sheets']; }
function molavi_domain_http($method, $url, $args = []) {
    $file = '';
    if (preg_match('~/media/file/([^/?#]+)~', (string) $url, $m)) $file = urldecode($m[1]);
    // Part 6 hook: a one-shot mid-run sheet change (a sheet edit landing
    // while a publish run is in flight). Fires on the first fetch after
    // the flag is set, then clears itself; inert unless Part 6a sets it.
    if (!empty($GLOBALS['di_mid_run_sheet_change'])) {
        $GLOBALS['di_mid_run_sheet_change'] = false;
        if (isset($GLOBALS['sheets']['Products'][1]) && is_array($GLOBALS['sheets']['Products'][1])) {
            $changed = $GLOBALS['sheets']['Products'][1];
            $changed[0] = 'Mid-run Sheet Change Tea';
            $GLOBALS['sheets']['Products'][] = $changed;
        }
    }
    $GLOBALS['fetch_calls'][$file] = ($GLOBALS['fetch_calls'][$file] ?? 0) + 1;
    $mode = $GLOBALS['fetch_map'][$file] ?? $GLOBALS['fetch_default'];
    if ($mode === '200') return ['status' => 200, 'body' => $GLOBALS['fetch_jpeg'], 'content-type' => 'image/jpeg'];
    if ($mode === '500') return ['status' => 500, 'body' => ''];
    return ['status' => 404, 'body' => ''];
}
function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); }
function wp_remote_retrieve_header($r, $h) { return (string) ($r['content-type'] ?? ''); }
function wp_remote_retrieve_response_code($r) { return (int) ($r['status'] ?? 0); }
// NOTE: no molavi_domain_worker_progress stub — domain-jobs.php (required
// below, Part 6) provides the real one; it no-ops when no worker is set.

class WC_Product {
    public $id = 0;
    public $name = '';
    public $status = 'draft';
    public $visibility = 'hidden';
    public $regular = '';
    public $sale = '';
    public $image_id = 0;
    public $stock = 'outofstock';
    public $short = '';
    public $sku = '';
    public $meta = [];
    public $purchasable = true;
    public function get_id() { return (int) $this->id; }
    public function get_name($ctx = 'view') { return $this->name; }
    public function set_name($v) { $this->name = (string) $v; }
    public function get_status() { return $this->status; }
    public function set_status($v) { $this->status = (string) $v; }
    public function get_catalog_visibility() { return $this->visibility; }
    public function set_catalog_visibility($v) { $this->visibility = (string) $v; }
    public function get_regular_price($ctx = 'view') { return $this->regular; }
    public function set_regular_price($v) { $this->regular = (string) $v; }
    public function get_sale_price($ctx = 'view') { return $this->sale; }
    public function set_sale_price($v) { $this->sale = (string) $v; }
    public function get_image_id($ctx = 'view') { return (int) $this->image_id; }
    public function set_image_id($v) { $this->image_id = (int) $v; }
    public function get_stock_status($ctx = 'view') { return $this->stock; }
    public function set_stock_status($v) { $this->stock = (string) $v; }
    public function get_short_description($ctx = 'view') { return $this->short; }
    public function set_short_description($v) { $this->short = (string) $v; }
    public function get_sku($ctx = 'view') { return $this->sku; }
    public function set_sku($v) { $this->sku = (string) $v; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
    public function get_meta($k, $single = true) {
        if (array_key_exists($k, $this->meta)) return $this->meta[$k];
        return get_post_meta($this->id, $k, $single);
    }
    public function get_date_created() { return null; }
    public function is_purchasable() { return $this->purchasable; }
    public function save() {
        if ($this->id <= 0) $this->id = $GLOBALS['next_woo_id']++;
        $GLOBALS['fake_products'][(int) $this->id] = $this;
        foreach ($this->meta as $k => $v) $GLOBALS['postmeta'][(int) $this->id][$k] = $v;
        return $this->id;
    }
}
class WC_Product_Simple extends WC_Product {}

foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-gateway', 'domain-reference-parser',
    'domain-reference-store', 'domain-reference-matching', 'domain-reference-matches', 'domain-products', 'domain-publisher',
    'domain-product-derivation', 'domain-sheet-conflicts', 'domain-source-projection',
    'domain-sale-offers', 'domain-orders', 'domain-reference-reviews', 'domain-reference-scheduler',
    'domain-reference-backfill', 'domain-supplier-link-checks', 'domain-supplier-prices',
    'domain-market-basalam', 'domain-market-prices', 'domain-market-sources',
    'domain-supplier-auto-verify', 'domain-autopilot', 'domain-product-inbox',
    'domain-ai-published-reviews', 'domain-jobs'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';
require __DIR__ . '/../shop-wp/molavi-shop/includes/pricing.php';
require __DIR__ . '/../shop-wp/molavi-shop/includes/sync.php';

// ---------------------------------------------------------------- fixtures
$now = time();
$recent = gmdate('c', $now - 3600);
$GLOBALS['options']['molavi_dead_image_files'] = ['deadfile01', 'recheck200f1', 'recheck404f1', 'recheck404f2'];
$GLOBALS['options']['molavi_image_404_suspects'] = [];

function di_row(string $id, string $name, string $image, string $ts): array {
    return ['Product Name' => $name, 'Sale Price' => '100000', 'Consumer Price' => '150000', 'Product ID' => $id,
        'Latest Offer ID' => 'offer-' . $id, 'Status' => 'imported', 'Stock Status' => 'Available',
        'Channel Username' => '@test', 'Packaging' => '۲۴ عددی', 'Min Qty' => '1', 'Package Count' => '24',
        'Source Post URL' => 'https://t.me/test/123', 'Image File ID' => $image, 'Category' => 'چای',
        // The record and its offer must agree on the provenance fields
        // (Channel Username, Channel, Batch ID, Message Timestamp, Source
        // Timestamp Basis, Source Post URL) or molavi_domain_source_issues
        // flags source_provenance_mismatch and both rules skip the row.
        'Message Timestamp' => $ts, 'Source Timestamp Basis' => 'supplier_channel_update'];
}
function di_offer(string $id, string $ts): array {
    return ['Offer ID' => 'offer-' . $id, 'Product ID' => $id, 'Channel Username' => '@test', 'Channel' => '',
        'Batch ID' => '', 'Message Timestamp' => $ts,
        'Source Timestamp Basis' => 'supplier_channel_update', 'Consumer Price' => '150000',
        'Source Post URL' => 'https://t.me/test/123', 'Import Timestamp' => $ts];
}
function di_gate_row(string $id, string $name, string $source, string $image): array {
    return ['id' => $id, 'name' => $name, 'key' => strtolower($name), 'supplier' => 'Test Supplier',
        'packaging' => '۲۴ عددی', 'category' => 'چای', 'sale' => 100000, 'shelf' => 150000, 'min' => 1,
        'pack_size' => 24, 'pack_size_source' => 'message', 'source_post_url' => 'https://t.me/test/123',
        'reference_snapshot_id' => '', 'reference_match_id' => '', 'reference_price_role' => '',
        'reference_approval_state' => '', 'reference_price_change_percent' => 0, 'reference_price_checked_at' => '',
        'reference_review_reason' => '', 'image_file_id' => $image, 'market_image_id' => null, 'status' => 'sell',
        'supplier_availability' => 'instock', 'source_record_id' => $source, 'supplier_price_state' => 'current',
        'supplier_price_verified_at' => gmdate('c', time() - 3600), 'sale_offer_expires_at' => time() + 86400];
}

$prodHeaders = ['Product Name', 'Sale Price', 'Consumer Price', 'Product ID', 'Latest Offer ID', 'Status',
    'Stock Status', 'Channel Username', 'Packaging', 'Min Qty', 'Package Count', 'Source Post URL', 'Image File ID',
    'Category', 'Reference Snapshot ID', 'Reference Match ID', 'Reference Price Role', 'Reference Approval State',
    'Reference Price Change Percent', 'Reference Price Checked At', 'Reference Review Reason',
    'Message Timestamp', 'Source Timestamp Basis'];
$offerHeaders = ['Offer ID', 'Product ID', 'Channel Username', 'Channel', 'Batch ID', 'Message Timestamp',
    'Source Timestamp Basis', 'Consumer Price', 'Source Post URL', 'Import Timestamp'];
function di_sheet_rows(array $headers, array $assocs): array {
    $out = [$headers];
    foreach ($assocs as $a) {
        $row = [];
        foreach ($headers as $h) $row[] = $a[$h] ?? '';
        $out[] = $row;
    }
    return $out;
}
$diIds = [
    'adm-dead' => ['Adm Dead Tea', 'deadfile01'],
    'adm-attached' => ['Adm Attached Tea', 'deadfile01'],
    'adm-noimg' => ['Adm Noimg Tea', ''],
    'adm-healthy' => ['Adm Healthy Tea', 'goodfile01'],
    'recheck-200' => ['Recheck 200 Tea', 'recheck200f1'],
    'recheck-404' => ['Recheck 404 Tea', 'recheck404f1'],
    'recheck-404b' => ['Recheck 404b Tea', 'recheck404f2'],
];
$diProducts = [];
$diOffers = [];
$diTs = gmdate('c', time() - 3600);
foreach ($diIds as $pid => [$name, $image]) {
    $diProducts[] = di_row($pid, $name, $image, $diTs);
    $diOffers[] = di_offer($pid, $diTs);
}
$GLOBALS['sheets'] = ['Products' => di_sheet_rows($prodHeaders, $diProducts),
    'Offers' => di_sheet_rows($offerHeaders, $diOffers), 'Shop' => []];

// Woo fixtures FIRST: the registered-products read caches per request,
// so the attached-image products must exist before the first rule call.
$r = molavi_apply_catalog(['products' => [di_gate_row('aa0000000010', 'Adm Attached Tea', 'adm-attached', 'goodfile01')]]);
$attachedId = $r['published_ids'][0] ?? 0;
di_check($attachedId > 0, 'fixture: attached product created');
set_post_thumbnail($attachedId, 99);
$GLOBALS['image_attachments'][] = 99;
update_post_meta($attachedId, '_molavi_image_id', 'goodfile01');
$r = molavi_apply_catalog(['products' => [di_gate_row('aa0000000011', 'Adm Noimg Tea', 'adm-noimg', 'goodfile01')]]);
$noimgId = $r['published_ids'][0] ?? 0;
di_check($noimgId > 0, 'fixture: noimg-attached product created');
set_post_thumbnail($noimgId, 99);
update_post_meta($noimgId, '_molavi_image_id', 'goodfile01');

// Current supplier evidence for every fixture product (same seed the
// no-picture harness uses: offer_id stores the product id).
foreach (['pipeline', 'reference'] as $store) { $db = new SQLite3(molavi_state_root() . '/' . $store . '.sqlite3'); $db->close(); }
molavi_domain_autopilot_ensure_tables();
molavi_domain_tx('pipeline', static function ($db): void {
    foreach (molavi_domain_records($GLOBALS['sheets']['Products']) as $record) {
        $id = trim((string) ($record['Product ID'] ?? ''));
        if ($id === '') continue;
        molavi_domain_sql($db, 'INSERT OR REPLACE INTO supplier_price_evidence'
            . '(offer_id,source_revision,verified_at,method) VALUES(?,?,?,?)',
            [$id, molavi_domain_source_revision($record), gmdate('c', time() - 3600), 'source']);
    }
});
function di_holds(string $gate): array {
    return molavi_domain_read('pipeline', static fn($db) => array_column(
        molavi_domain_rows($db, "SELECT product_id,reason FROM autopilot_holds WHERE gate=?", [$gate]),
        'reason', 'product_id'));
}
function di_clear_holds(): void {
    molavi_domain_tx('pipeline', static fn($db) => molavi_domain_sql($db, 'DELETE FROM autopilot_holds'));
}

// ---------------------------------------------------------------- Part 1: shared image-arm verdict (pure)
$hasHelper = function_exists('molavi_domain_autopilot_image_reason');
di_check($hasHelper, 'helper-exists', 'image_reason helper missing (base behaviour)');
$GLOBALS['thumbs'][101] = 99;
$dead = ['deadfile01' => true];
$suspects = ['suspect01' => time() - 10];
$helperCases = [
    ['attached excuses dead', 'p1', 'deadfile01', false, [], ['p1' => 101], ''],
    ['dead without picture', 'p2', 'deadfile01', false, [], [], 'تصویر کالا خراب است.'],
    ['attached + waived still passes', 'p1', 'deadfile01', true, [], ['p1' => 101], ''],
    ['waiver never excuses dead', 'p2', 'deadfile01', true, [], [], 'تصویر کالا خراب است.'],
    ['attached excuses missing', 'p1', '', false, [], ['p1' => 101], ''],
    ['missing without picture', 'p2', '', false, [], [], 'تصویر کالا نیست.'],
    ['waived missing passes', 'p2', '', true, [], [], ''],
    ['attached excuses suspect', 'p1', 'suspect01', false, [], ['p1' => 101], ''],
    ['suspect without picture', 'p2', 'suspect01', false, [], [], 'تصویر کالا در حال بررسی است.'],
    ['healthy passes', 'p2', 'goodfile01', false, [], [], ''],
    ['market photo excuses dead', 'p3', 'deadfile01', false, ['p3' => ['photo' => ['attachment_id' => 77]]], [], ''],
    ['market photo id 0 does not excuse', 'p3', 'deadfile01', false, ['p3' => ['photo' => ['attachment_id' => 0]]], [], 'تصویر کالا خراب است.'],
];
foreach ($helperCases as [$label, $pid, $image, $waived, $market, $woo, $want]) {
    if (!$hasHelper) { di_check(false, 'helper: ' . $label, 'helper missing'); continue; }
    $got = molavi_domain_autopilot_image_reason($pid, $image, $waived, $dead, $suspects, $market, $woo);
    di_check($got === $want, 'helper: ' . $label, 'got=' . var_export($got, true));
}

// ---------------------------------------------------------------- Part 2: g5 publish arm end to end
$g5 = molavi_domain_autopilot_rule_publish(['cap_per_gate_per_day' => 20], $GLOBALS['sheets'],
    (float) time(), (float) strtotime('today'), false, false, 'test', false);
$pub = di_holds('publish');
di_check(($pub['adm-dead'] ?? '') === 'تصویر کالا خراب است.', 'g5: dead file held', 'holds=' . json_encode($pub));
di_check(!isset($pub['adm-attached']), 'g5: dead file + attached picture NOT held (ID 53)', 'holds=' . json_encode($pub));
di_check(!isset($pub['adm-noimg']), 'g5: missing file + attached picture NOT held', 'holds=' . json_encode($pub));
di_check(!isset($pub['adm-healthy']), 'g5: healthy image NOT held', 'holds=' . json_encode($pub));
di_check(($pub['recheck-200'] ?? '') === 'تصویر کالا خراب است.', 'g5: recheck-200 file held', 'holds=' . json_encode($pub));

// ---------------------------------------------------------------- Part 3: admission Arm 8 end to end
di_clear_holds();
$adm = molavi_domain_autopilot_rule_admission([], $GLOBALS['sheets'],
    (float) time(), (float) strtotime('today'), false, false, 'test', false);
$admHolds = di_holds('admission');
di_check(($admHolds['adm-dead'] ?? '') === 'تصویر کالا خراب است.', 'admission: dead file held', 'holds=' . json_encode($admHolds));
di_check(!isset($admHolds['adm-attached']), 'admission: dead file + attached picture NOT held (ID 53)', 'holds=' . json_encode($admHolds));
di_check(!isset($admHolds['adm-noimg']), 'admission: missing file + attached picture NOT held', 'holds=' . json_encode($admHolds));
di_check(!isset($admHolds['adm-healthy']), 'admission: healthy image NOT held', 'holds=' . json_encode($admHolds));
di_check(($admHolds['recheck-200'] ?? '') === 'تصویر کالا خراب است.', 'admission: recheck-200 file held', 'holds=' . json_encode($admHolds));

// ---------------------------------------------------------------- Part 4: recheck helpers (pure)
di_check(defined('MOLAVI_DEAD_IMAGE_RECHECK_SECONDS') && MOLAVI_DEAD_IMAGE_RECHECK_SECONDS === 7 * 86400,
    'recheck interval is 7 days', 'value=' . var_export(defined('MOLAVI_DEAD_IMAGE_RECHECK_SECONDS') ? MOLAVI_DEAD_IMAGE_RECHECK_SECONDS : null, true));
di_check(defined('MOLAVI_DEAD_IMAGE_RECHECK_PER_RUN') && MOLAVI_DEAD_IMAGE_RECHECK_PER_RUN === 2,
    'recheck cap is 2 per run', 'value=' . var_export(defined('MOLAVI_DEAD_IMAGE_RECHECK_PER_RUN') ? MOLAVI_DEAD_IMAGE_RECHECK_PER_RUN : null, true));
$hasRecheck = function_exists('molavi_dead_image_recheck_due') && function_exists('molavi_note_dead_image_recheck')
    && function_exists('molavi_clear_image_dead') && function_exists('molavi_dead_image_rechecks');
di_check($hasRecheck, 'recheck helpers exist', 'helpers missing (base behaviour)');
if ($hasRecheck) {
    $t = 1700000000;
    di_check(molavi_dead_image_recheck_due('recheck200f1', $t, []) === true, 'recheck due: never checked');
    di_check(molavi_dead_image_recheck_due('bad!', $t, []) === false, 'recheck due: invalid file never due');
    molavi_note_dead_image_recheck('recheck200f1', $t);
    $stamps = molavi_dead_image_rechecks();
    di_check(($stamps['recheck200f1'] ?? null) === $t, 'recheck stamped', 'stamps=' . json_encode($stamps));
    di_check(molavi_dead_image_recheck_due('recheck200f1', $t + 86400, $stamps) === false, 'recheck not due 1 day later');
    di_check(molavi_dead_image_recheck_due('recheck200f1', $t + 7 * 86400 - 1, $stamps) === false, 'recheck not due 1s before interval');
    di_check(molavi_dead_image_recheck_due('recheck200f1', $t + 7 * 86400, $stamps) === true, 'recheck due at interval');
    molavi_note_dead_image_recheck('bad!', $t);
    di_check(!isset(molavi_dead_image_rechecks()['bad!']), 'recheck stamp ignores invalid file');
    // Clear: un-marks the dead entry and its suspect, idempotent.
    molavi_mark_image_dead('clearme01');
    di_check(isset(molavi_dead_image_files()['clearme01']), 'clear fixture: file marked dead');
    molavi_note_image_404_suspect('clearme01', $t);
    molavi_clear_image_dead('clearme01');
    di_check(!isset(molavi_dead_image_files()['clearme01']), 'clear: dead entry removed');
    di_check(!isset(molavi_suspect_image_files()['clearme01']), 'clear: suspect removed too');
    molavi_clear_image_dead('clearme01');
    di_check(!isset(molavi_dead_image_files()['clearme01']), 'clear: idempotent');
    molavi_clear_image_dead('bad!');
    di_check(true, 'clear: invalid file no-op');
    // The Part-4 run below reads the live stamps, so reset them here.
    unset($GLOBALS['options']['molavi_dead_image_rechecks']);
} else {
    foreach (['recheck due: never checked', 'recheck due: invalid file never due', 'recheck stamped',
        'recheck not due 1 day later', 'recheck not due 1s before interval', 'recheck due at interval',
        'recheck stamp ignores invalid file', 'clear fixture: file marked dead', 'clear: dead entry removed',
        'clear: suspect removed too', 'clear: idempotent', 'clear: invalid file no-op'] as $n) di_check(false, $n, 'helpers missing');
}

// ---------------------------------------------------------------- Part 5: recheck probe end to end
// Recheck-only sheets (publish_shop with catalog_complete=true hides
// products outside the payload, so these runs go last).
$GLOBALS['fetch_map'] = ['recheck200f1' => '200', 'recheck404f1' => '404', 'recheck404f2' => '404'];
$GLOBALS['fetch_calls'] = [];
$recheckProducts = [];
$recheckOffers = [];
foreach (['recheck-200' => 'Recheck 200 Tea', 'recheck-404' => 'Recheck 404 Tea', 'recheck-404b' => 'Recheck 404b Tea'] as $pid => $name) {
    $recheckProducts[] = di_row($pid, $name, $diIds[$pid][1], $diTs);
    $recheckOffers[] = di_offer($pid, $diTs);
}
$GLOBALS['sheets'] = ['Products' => di_sheet_rows($prodHeaders, $recheckProducts),
    'Offers' => di_sheet_rows($offerHeaders, $recheckOffers), 'Shop' => []];
$run1 = molavi_domain_publish_shop($GLOBALS['sheets'], false, 8);
di_check(($run1['image_rechecks']['checked'] ?? -1) === 2, 'recheck run 1: cap 2 checked', 'rechecks=' . json_encode($run1['image_rechecks'] ?? null));
di_check(($run1['image_rechecks']['cleared'] ?? []) === ['recheck200f1'], 'recheck run 1: 200 clears the file', 'rechecks=' . json_encode($run1['image_rechecks'] ?? null));
di_check(($run1['image_rechecks']['still_dead'] ?? []) === ['recheck404f1'], 'recheck run 1: 404 keeps the file dead', 'rechecks=' . json_encode($run1['image_rechecks'] ?? null));
di_check(!isset(molavi_dead_image_files()['recheck200f1']), 'recheck run 1: cleared file leaves the dead list');
di_check(isset(molavi_dead_image_files()['recheck404f1']) && isset(molavi_dead_image_files()['recheck404f2']), 'recheck run 1: 404 files stay dead');
di_check(($GLOBALS['fetch_calls']['recheck200f1'] ?? 0) === 1, 'recheck run 1: 200 file fetched once', 'calls=' . json_encode($GLOBALS['fetch_calls']));
di_check(($GLOBALS['fetch_calls']['recheck404f1'] ?? 0) === 1, 'recheck run 1: 404 file fetched once', 'calls=' . json_encode($GLOBALS['fetch_calls']));
di_check(($GLOBALS['fetch_calls']['recheck404f2'] ?? 0) === 0, 'recheck run 1: third due file NOT fetched (cap)', 'calls=' . json_encode($GLOBALS['fetch_calls']));
$run2 = molavi_domain_publish_shop($GLOBALS['sheets'], false, 8);
di_check(($run2['image_rechecks']['checked'] ?? -1) === 1, 'recheck run 2: only the unrechecked file is due', 'rechecks=' . json_encode($run2['image_rechecks'] ?? null));
di_check(($run2['image_rechecks']['still_dead'] ?? []) === ['recheck404f2'], 'recheck run 2: third file 404s and stays dead', 'rechecks=' . json_encode($run2['image_rechecks'] ?? null));
di_check(($GLOBALS['fetch_calls']['recheck404f1'] ?? 0) === 1, 'recheck run 2: stamped 404 file NOT re-fetched', 'calls=' . json_encode($GLOBALS['fetch_calls']));
di_check(($GLOBALS['fetch_calls']['recheck404f2'] ?? 0) === 1, 'recheck run 2: third file fetched once', 'calls=' . json_encode($GLOBALS['fetch_calls']));
// The cleared file flows through the normal path on the next run: the
// product is created and its picture attaches.
$recheck200Woo = null;
foreach ($GLOBALS['fake_products'] as $id => $p) {
    if (($p->get_meta('_molavi_supplier_offer_id', true) ?? '') === 'recheck-200') $recheck200Woo = $id;
}
di_check($recheck200Woo !== null && gate_product_exists($recheck200Woo), 'recheck run 2: cleared product created');
function gate_product_exists(int $id): bool {
    $p = wc_get_product($id);
    return $p !== null && $p->get_status() === 'publish' && get_post_thumbnail_id($id) === 99;
}
di_check($recheck200Woo !== null && get_post_thumbnail_id($recheck200Woo) === 99, 'recheck run 2: cleared product picture attached');

// ---------------------------------------------------------------- Part 6: recheck result survives the job-result paths
// Round-4 probe (2026-11-28 15:56): 10 recheck stamps exist but 0 of 20
// sampled publish-job results carry image_rechecks. publish_shop DOES set
// $result['image_rechecks'] (Part 5), so the drops are downstream: the
// superseded return in molavi_domain_publish_job (6a) and the incomplete
// branch of molavi_domain_run_jobs (6b). Both fixes are one-line
// carry-throughs; these checks pin them at the level the owner reads.
function di_evidence(array $products): void {
    molavi_domain_tx('pipeline', static function ($db) use ($products): void {
        foreach ($products as $record) {
            $id = trim((string) ($record['Product ID'] ?? ''));
            if ($id === '') continue;
            molavi_domain_sql($db, 'INSERT OR REPLACE INTO supplier_price_evidence'
                . '(offer_id,source_revision,verified_at,method) VALUES(?,?,?,?)',
                [$id, molavi_domain_source_revision($record), gmdate('c', time() - 3600), 'source']);
        }
    });
}

// 6a: a sheet change landing mid-run supersedes the job — but the run
// still re-checked its due dead file, and the stored result must say so.
$GLOBALS['fetch_map'] = ['recheck200f9' => '200'];
$GLOBALS['fetch_calls'] = [];
$GLOBALS['options']['molavi_dead_image_files'] = ['recheck200f9'];
$GLOBALS['options']['molavi_image_404_suspects'] = [];
unset($GLOBALS['options']['molavi_dead_image_rechecks']);
$supProducts = [di_row('recheck-200c', 'Recheck 200c Tea', 'recheck200f9', $diTs)];
$supOffers = [di_offer('recheck-200c', $diTs)];
di_evidence($supProducts);
$GLOBALS['sheets'] = ['Products' => di_sheet_rows($prodHeaders, $supProducts),
    'Offers' => di_sheet_rows($offerHeaders, $supOffers), 'Shop' => []];
$GLOBALS['di_mid_run_sheet_change'] = true;
$supResult = molavi_domain_publish_job([]);
di_check(($supResult['superseded'] ?? false) === true, 'job superseded: sheet changed mid-run', 'result=' . json_encode($supResult));
di_check(($supResult['image_rechecks']['checked'] ?? -1) === 1, 'job superseded: image_rechecks carried through', 'rechecks=' . json_encode($supResult['image_rechecks'] ?? null));
di_check(($supResult['image_rechecks']['cleared'] ?? []) === ['recheck200f9'], 'job superseded: cleared file listed', 'rechecks=' . json_encode($supResult['image_rechecks'] ?? null));

// 6b: an incomplete run (images still pending, nothing attached) is
// stored stripped to 6 keys — image_rechecks must survive the strip.
// The skip file 404s on first sighting (suspect, not dead), so the
// attach loop spins its 8 rounds with images_left>0 and attached==0,
// while the due dead file is re-checked in the same run.
$GLOBALS['fetch_map'] = ['skipfile01' => '404', 'recheck404f9' => '404'];
$GLOBALS['fetch_calls'] = [];
$GLOBALS['options']['molavi_dead_image_files'] = ['recheck404f9'];
$GLOBALS['options']['molavi_image_404_suspects'] = [];
unset($GLOBALS['options']['molavi_dead_image_rechecks']);
$stripProducts = [
    di_row('skip-404', 'Skip 404 Tea', 'skipfile01', $diTs),
    di_row('recheck-404c', 'Recheck 404c Tea', 'recheck404f9', $diTs),
];
$stripOffers = [di_offer('skip-404', $diTs), di_offer('recheck-404c', $diTs)];
di_evidence($stripProducts);
$GLOBALS['sheets'] = ['Products' => di_sheet_rows($prodHeaders, $stripProducts),
    'Offers' => di_sheet_rows($offerHeaders, $stripOffers), 'Shop' => []];
molavi_domain_tx('pipeline', static fn(SQLite3 $db) => molavi_domain_sql($db,
    "INSERT INTO molavi_state_executors VALUES ('publish','wordpress',?) ON CONFLICT(kind) DO UPDATE SET host='wordpress'",
    [microtime(true)]));
$stripJob = molavi_domain_queue('publish', 'di-strip-test', []);
molavi_domain_run_jobs(1, 40.0, $stripJob);
$stripStored = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_row($db,
    'SELECT state, result FROM jobs WHERE id=?', [$stripJob]));
$stripResult = json_decode((string) ($stripStored['result'] ?? ''), true);
di_check(($stripStored['state'] ?? '') === 'queued', 'job incomplete: re-queued, not done', 'state=' . ($stripStored['state'] ?? 'null'));
di_check(($stripResult['images_left'] ?? 0) > 0 && !isset($stripResult['count']), 'job incomplete: stored result is the stripped one', 'result=' . json_encode($stripResult));
di_check(($stripResult['image_rechecks']['checked'] ?? -1) === 1, 'job incomplete: image_rechecks stored', 'rechecks=' . json_encode($stripResult['image_rechecks'] ?? null));
di_check(($stripResult['image_rechecks']['still_dead'] ?? []) === ['recheck404f9'], 'job incomplete: still-dead file listed', 'rechecks=' . json_encode($stripResult['image_rechecks'] ?? null));

foreach (glob(MOLAVI_STATE_ROOT . '/wp/wp-admin/includes/*') as $file) unlink($file);
@rmdir(MOLAVI_STATE_ROOT . '/wp/wp-admin/includes');
@rmdir(MOLAVI_STATE_ROOT . '/wp/wp-admin');
@rmdir(MOLAVI_STATE_ROOT . '/wp');
foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) if (is_file($file)) unlink($file);
@rmdir(MOLAVI_STATE_ROOT);
echo "\n$pass PASS, $fail FAIL\n";
exit($fail === 0 ? 0 : 1);
