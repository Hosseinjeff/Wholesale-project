<?php
/** No-picture gate harness — FIX 2026-11-27 (owner rule 2026-11-26 ~23:24).
 * Synthetic runtime: no WordPress database, network or order writes.
 * Exercises the gate at its convergence choke point (molavi_apply_catalog)
 * plus the read-time backstop (molavi_sale_offer_woo_allowed), the
 * projection (molavi_domain_site_products), the owner-request refusal
 * (molavi_domain_request_product_publication), the publish-shop sweep
 * (molavi_domain_publish_shop) and the g5 image arm.
 * Negative control: a no-picture product MUST be refused at the gate on
 * every path exercised here; a with-picture product MUST still publish.
 */
declare(strict_types=1);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir().'/molavi-nopicture-'.bin2hex(random_bytes(5)));
define('MOLAVI_REFERENCE_SERVER_URL', 'https://reference.test');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('ABSPATH', MOLAVI_STATE_ROOT.'/wp/');
define('DAY_IN_SECONDS', 86400);
@mkdir(ABSPATH.'wp-admin/includes/', 0755, true);
foreach (['file', 'image', 'media'] as $stub) touch(ABSPATH.'wp-admin/includes/'.$stub.'.php');
$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['postmeta'] = [];
$GLOBALS['thumbs'] = [];
$GLOBALS['image_attachments'] = [88];
$GLOBALS['fake_products'] = [];
$GLOBALS['next_woo_id'] = 100;
$GLOBALS['sheets'] = [];
$GLOBALS['fetch_mode'] = '404';
$GLOBALS['fetch_jpeg'] = "\xFF\xD8\xFF\xE0".str_repeat("\x00", 100);
$count = 0;
function check_gate(bool $condition, string $label): void {
    global $count;
    if (!$condition) throw new RuntimeException('FAIL '.$label);
    $count++;
    echo 'PASS '.$label."\n";
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
function molavi_domain_sheets($names, $fresh = false) { return $GLOBALS['sheets']; }
function molavi_domain_sale_offer_evidence(array $record, array $offer, array $supplier, ?array $market, int $now): array {
    return ['sale_offer_blocker' => '', 'regular_price' => 150000];
}
function molavi_domain_http($method, $url, $args = []) {
    if ($GLOBALS['fetch_mode'] === '200') {
        return ['status' => 200, 'body' => $GLOBALS['fetch_jpeg'], 'content-type' => 'image/jpeg'];
    }
    return ['status' => 404, 'body' => ''];
}
function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); }
function wp_remote_retrieve_header($r, $h) { return (string) ($r['content-type'] ?? ''); }
function wp_remote_retrieve_response_code($r) { return (int) ($r['status'] ?? 0); }
function molavi_domain_worker_progress($job = null) {}

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
        // Real WooCommerce persists internal meta to postmeta on save;
        // mirror that so get_post_meta reads see upserted values.
        foreach ($this->meta as $k => $v) $GLOBALS['postmeta'][(int) $this->id][$k] = $v;
        return $this->id;
    }
}
class WC_Product_Simple extends WC_Product {}

require __DIR__.'/../shop-wp/molavi-shop/includes/pricing.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/sync.php';

function gate_row(string $id, string $name, string $source, string $image, ?int $market = null): array {
    return ['id' => $id, 'name' => $name, 'key' => strtolower($name), 'supplier' => 'Test Supplier',
        'packaging' => '۲۴ عددی', 'category' => 'چای', 'sale' => 100000, 'shelf' => 150000, 'min' => 1,
        'pack_size' => 24, 'pack_size_source' => 'message', 'source_post_url' => 'https://t.me/test/123',
        'reference_snapshot_id' => '', 'reference_match_id' => '', 'reference_price_role' => '',
        'reference_approval_state' => '', 'reference_price_change_percent' => 0, 'reference_price_checked_at' => '',
        'reference_review_reason' => '', 'image_file_id' => $image, 'market_image_id' => $market, 'status' => 'sell',
        'supplier_availability' => 'instock', 'source_record_id' => $source, 'supplier_price_state' => 'current',
        'supplier_price_verified_at' => gmdate('c', time() - 3600), 'sale_offer_expires_at' => time() + 86400];
}
function gate_product(int $id): ?WC_Product { return wc_get_product($id); }

// ---- Part A1: pure verdict mirrors admission Arm 8 ----
check_gate(molavi_image_gate_verdict('', false, [], []) === 'no_image', 'verdict: empty image, not waived -> no_image');
check_gate(molavi_image_gate_verdict('', true, [], []) === 'ok', 'verdict: empty image, waived -> ok');
check_gate(molavi_image_gate_verdict('deadfile01', true, ['deadfile01' => true], []) === 'dead_image', 'verdict: dead image blocks even when waived');
check_gate(molavi_image_gate_verdict('suspect01', true, [], ['suspect01' => time()]) === 'suspect_image', 'verdict: suspect image blocks even when waived');
check_gate(molavi_image_gate_verdict('goodfile01', false, ['deadfile01' => true], ['suspect01' => time()]) === 'ok', 'verdict: healthy image -> ok');
$GLOBALS['options']['molavi_dead_image_files'] = ['deadfile01'];
$GLOBALS['options']['molavi_image_404_suspects'] = ['suspect01' => time() - 10];

// ---- Part A2: apply gate, new products (direct-endpoint shape, native=false) ----
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000001', 'Noimg Tea', 'prod-noimg', '')]]);
check_gate($r['ok'] && $r['created'] === 0 && count($GLOBALS['fake_products']) === 0
    && ($r['image_blocked'][0]['reason'] ?? '') === 'no_image', 'apply refuses a new no-picture product (nothing created)');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000002', 'Dead Tea', 'prod-dead', 'deadfile01')]]);
check_gate($r['ok'] && $r['created'] === 0 && count($GLOBALS['fake_products']) === 0
    && ($r['image_blocked'][0]['reason'] ?? '') === 'dead_image', 'apply refuses a new dead-image product');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000003', 'Suspect Tea', 'prod-suspect', 'suspect01')]]);
check_gate($r['ok'] && $r['created'] === 0 && count($GLOBALS['fake_products']) === 0
    && ($r['image_blocked'][0]['reason'] ?? '') === 'suspect_image', 'apply refuses a new suspect-image product');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000004', 'Good Tea', 'prod-good', 'goodfile01')]]);
$goodId = $r['published_ids'][0] ?? 0;
check_gate($r['ok'] && $r['created'] === 1 && $goodId > 0 && ($r['images_left'] ?? 0) === 1
    && gate_product($goodId)->get_status() === 'publish' && gate_product($goodId)->get_catalog_visibility() === 'visible'
    && gate_product($goodId)->get_stock_status() === 'instock' && empty($r['image_blocked']),
    'apply publishes a with-picture product (native=false, image queued)');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000005', 'Market Tea', 'prod-market', '', 88)]], true);
$marketId = $r['published_ids'][0] ?? 0;
check_gate($r['ok'] && $r['created'] === 1 && gate_product($marketId)->get_image_id() === 88
    && ($r['images_left'] ?? -1) === 0, 'apply publishes a market-photo product with the photo attached (native=true)');
$GLOBALS['options']['molavi_autopilot_image_waivers'] = ['prod-waived'];
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000006', 'Waived Tea', 'prod-waived', '')]]);
check_gate($r['ok'] && $r['created'] === 1 && empty($r['image_blocked']), 'apply publishes an owner-waived no-picture product');
unset($GLOBALS['options']['molavi_autopilot_image_waivers']);

// ---- Part A3: apply gate, existing products ----
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000004', 'Good Tea', 'prod-good', '')]]);
check_gate($r['ok'] && gate_product($goodId)->get_status() === 'draft'
    && gate_product($goodId)->get_catalog_visibility() === 'hidden'
    && gate_product($goodId)->get_stock_status() === 'outofstock'
    && ($r['image_blocked'][0]['reason'] ?? '') === 'no_image' && ($r['hidden'] ?? 0) === 1,
    'apply hides a published product whose picture is gone (no_image)');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000004', 'Good Tea', 'prod-good', 'goodfile01')]]);
check_gate($r['ok'] && gate_product($goodId)->get_status() === 'publish' && empty($r['image_blocked']),
    'apply re-publishes once an attachable image returns');
// ID 53 case: attached store picture survives a dead source file.
set_post_thumbnail($goodId, 99);
$GLOBALS['image_attachments'][] = 99;
update_post_meta($goodId, '_molavi_image_id', 'goodfile01');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000004', 'Good Tea', 'prod-good', 'deadfile01')]]);
check_gate($r['ok'] && gate_product($goodId)->get_status() === 'publish'
    && gate_product($goodId)->get_catalog_visibility() === 'visible' && empty($r['image_blocked']),
    'a product with an attached picture stays published when its source file dies (ID 53)');
// Suspect cycle: hide + keep queued, attach, re-publish.
$GLOBALS['options']['molavi_image_404_suspects']['goodfile01'] = time() - 10;
set_post_thumbnail($goodId, 0);
update_post_meta($goodId, '_molavi_image_id', '');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000004', 'Good Tea', 'prod-good', 'goodfile01')]]);
check_gate($r['ok'] && gate_product($goodId)->get_status() === 'draft'
    && ($r['image_blocked'][0]['reason'] ?? '') === 'suspect_image' && ($r['images_left'] ?? 0) === 1,
    'a suspect image hides the product but stays queued for re-fetch');
$recv = molavi_receive_images(['images' => [['product' => $goodId, 'file' => 'goodfile01', 'mime' => 'image/jpeg', 'data' => base64_encode($GLOBALS['fetch_jpeg'])]]]);
check_gate(($recv['attached'] ?? 0) === 1 && get_post_thumbnail_id($goodId) === 99, 'the queued suspect image attaches on re-fetch');
$r = molavi_apply_catalog(['products' => [gate_row('aa0000000004', 'Good Tea', 'prod-good', 'goodfile01')]]);
check_gate($r['ok'] && gate_product($goodId)->get_status() === 'publish' && empty($r['image_blocked']),
    'the product re-publishes once its picture attaches');
unset($GLOBALS['options']['molavi_image_404_suspects']['goodfile01']);

// ---- Part A4: read-time backstop ----
$GLOBALS['options']['molavi_state_active'] = true;
$allowed = molavi_sale_offer_woo_allowed(gate_product($goodId));
check_gate($allowed === true, 'woo_allowed: with-picture product is sellable');
set_post_thumbnail($goodId, 0);
check_gate(molavi_sale_offer_woo_allowed(gate_product($goodId)) === false, 'woo_allowed: the same product without a picture is not sellable');
$GLOBALS['options']['molavi_autopilot_image_waivers'] = ['prod-good'];
check_gate(molavi_sale_offer_woo_allowed(gate_product($goodId)) === true, 'woo_allowed: an owner-waived product stays sellable without a picture');
unset($GLOBALS['options']['molavi_autopilot_image_waivers']);
set_post_thumbnail($goodId, 99);
check_gate(molavi_sale_offer_woo_allowed(gate_product($goodId)) === true, 'woo_allowed: re-attaching the picture restores sellability');

// ---- Part B: projection, owner request, publish-shop sweep, g5 arm ----
$root = __DIR__.'/../shop-wp/molavi-shop/runtime-host/';
foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-reference-parser', 'domain-gateway',
          'domain-products', 'domain-publisher', 'domain-product-inbox', 'domain-ai-published-reviews',
          'domain-autopilot'] as $module) require $root.'molavi-'.$module.'.php';
foreach (['pipeline', 'reference'] as $store) { $db = new SQLite3(molavi_state_root().'/'.$store.'.sqlite3'); $db->close(); }

$prodHeaders = ['Product Name', 'Sale Price', 'Consumer Price', 'Product ID', 'Latest Offer ID', 'Status',
    'Stock Status', 'Channel Username', 'Packaging', 'Min Qty', 'Package Count', 'Source Post URL', 'Image File ID',
    'Category', 'Reference Snapshot ID', 'Reference Match ID', 'Reference Price Role', 'Reference Approval State',
    'Reference Price Change Percent', 'Reference Price Checked At', 'Reference Review Reason',
    'Message Timestamp', 'Source Timestamp Basis'];
$offerHeaders = ['Offer ID', 'Product ID', 'Channel Username', 'Channel', 'Batch ID', 'Message Timestamp',
    'Source Timestamp Basis', 'Source Post URL', 'Import Timestamp'];
function sheet_rows(array $headers, array $assocs): array {
    $out = [$headers];
    foreach ($assocs as $a) {
        $row = [];
        foreach ($headers as $h) $row[] = $a[$h] ?? '';
        $out[] = $row;
    }
    return $out;
}
function proj_product(string $id, string $name, string $image): array {
    return ['Product Name' => $name, 'Sale Price' => '100000', 'Consumer Price' => '150000', 'Product ID' => $id,
        'Latest Offer ID' => 'offer-'.$id, 'Status' => 'imported', 'Stock Status' => 'Available',
        'Channel Username' => '@test', 'Packaging' => '۲۴ عددی', 'Min Qty' => '1', 'Package Count' => '24',
        'Source Post URL' => 'https://t.me/test/123', 'Image File ID' => $image, 'Category' => 'چای',
        'Message Timestamp' => '2026-11-27T10:00:00+00:00', 'Source Timestamp Basis' => ''];
}
function proj_offer(string $id): array {
    return ['Offer ID' => 'offer-'.$id, 'Product ID' => $id, 'Channel Username' => '@test', 'Channel' => '',
        'Batch ID' => '', 'Message Timestamp' => '2026-11-27T10:00:00+00:00', 'Source Timestamp Basis' => '',
        'Source Post URL' => 'https://t.me/test/123', 'Import Timestamp' => '2026-11-27T10:00:00+00:00'];
}
$projIds = ['proj-noimg', 'proj-dead', 'proj-suspect', 'proj-good', 'proj-waived', 'proj-onshelf'];
$projImages = ['proj-noimg' => '', 'proj-dead' => 'deadfile01', 'proj-suspect' => 'suspect01',
    'proj-good' => 'goodfile01', 'proj-waived' => '', 'proj-onshelf' => 'deadfile01'];
$projNames = ['proj-noimg' => 'Proj Noimg Tea', 'proj-dead' => 'Proj Dead Tea', 'proj-suspect' => 'Proj Suspect Tea',
    'proj-good' => 'Proj Good Tea', 'proj-waived' => 'Proj Waived Tea', 'proj-onshelf' => 'Proj Onshelf Tea'];
$GLOBALS['options']['molavi_autopilot_image_waivers'] = ['proj-waived'];
// On-shelf fixture product first: the projection's on-shelf read caches
// per request, so the Woo product must exist before the first projection.
$onshelf = molavi_apply_catalog(['products' => [gate_row('aa0000000007', 'Proj Onshelf Tea', 'proj-onshelf', 'goodfile03')]]);
$onshelfId = $onshelf['published_ids'][0] ?? 0;
check_gate($onshelfId > 0 && gate_product($onshelfId)->get_status() === 'publish', 'fixture: on-shelf product exists before the projection runs');
set_post_thumbnail($onshelfId, 99);
update_post_meta($onshelfId, '_molavi_image_id', 'goodfile03');
$products = [];
$offers = [];
foreach ($projIds as $pid) {
    $products[] = proj_product($pid, $projNames[$pid], $projImages[$pid]);
    $offers[] = proj_offer($pid);
}
$GLOBALS['sheets'] = ['Products' => sheet_rows($prodHeaders, $products),
    'Offers' => sheet_rows($offerHeaders, $offers), 'Shop' => []];
$freshness = [];
foreach ($projIds as $pid) {
    $freshness[$pid] = ['state' => 'current', 'verified_at' => gmdate('c', time() - 3600), 'source_revision' => ''];
}
$proj = array_column(molavi_domain_site_products($GLOBALS['sheets'], $freshness, []), null, 'source_record_id');
check_gate(($proj['proj-noimg']['status'] ?? '') === 'hold' && ($proj['proj-noimg']['image_hold_reason'] ?? '') === 'no_image',
    'projection holds a new no-picture product (no_image)');
check_gate(($proj['proj-dead']['status'] ?? '') === 'hold' && ($proj['proj-dead']['image_hold_reason'] ?? '') === 'dead_image',
    'projection holds a new dead-image product (dead_image)');
check_gate(($proj['proj-suspect']['status'] ?? '') === 'sell' && ($proj['proj-suspect']['image_hold_reason'] ?? '') === '',
    'projection leaves a suspect row sellable so the re-fetch can clear it (apply still refuses it)');
check_gate(($proj['proj-good']['status'] ?? '') === 'sell' && ($proj['proj-good']['image_hold_reason'] ?? '') === '',
    'projection sells a healthy-image product');
check_gate(($proj['proj-waived']['status'] ?? '') === 'sell' && ($proj['proj-waived']['image_hold_reason'] ?? '') === '',
    'projection sells an owner-waived no-picture product');
check_gate(($proj['proj-onshelf']['status'] ?? '') === 'sell' && ($proj['proj-onshelf']['image_hold_reason'] ?? '') === 'dead_image',
    'projection leaves an on-shelf product sellable when its source file dies (reason recorded, ID 53)');
unset($GLOBALS['options']['molavi_autopilot_image_waivers']);
// Same projection with live admission enabled (the production state):
// permission rows exist for every fixture product, so the permission
// check must not change any image verdict above.
$GLOBALS['options']['molavi_autopilot_settings_v1'] = ['master_enabled' => true, 'rule_modes' => ['gA' => 'live']];
$GLOBALS['options']['molavi_autopilot_image_waivers'] = ['proj-waived'];
// The temp schema does not create the admission-receipts table that the
// live-permissions read joins; create the three tables it needs.
molavi_domain_tx('pipeline', static function ($db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS autopilot_admission_receipts (product_id TEXT, offer_id TEXT, log_id INTEGER)');
    $db->exec("CREATE TABLE IF NOT EXISTS autopilot_log (id INTEGER PRIMARY KEY, gate TEXT, product_id TEXT, action TEXT, result TEXT, verdict TEXT, rule_id TEXT, provenance TEXT, inputs_json TEXT, created_at REAL)");
    $db->exec('CREATE TABLE IF NOT EXISTS owner_publication_permissions (product_id TEXT PRIMARY KEY, offer_id TEXT, source_revision TEXT, actor TEXT, requested_at REAL)');
});
molavi_domain_tx('pipeline', static function ($db) use ($projIds): void {
    foreach ($projIds as $pid) {
        molavi_domain_sql($db, 'INSERT OR REPLACE INTO owner_publication_permissions'
            .'(product_id,offer_id,source_revision,actor,requested_at) VALUES(?,?,?,?,?)',
            [$pid, 'offer-'.$pid, '', 'wp:7', microtime(true)]);
    }
});
$projLive = array_column(molavi_domain_site_products($GLOBALS['sheets'], $freshness, []), null, 'source_record_id');
check_gate(($projLive['proj-noimg']['status'] ?? '') === 'hold' && ($projLive['proj-dead']['status'] ?? '') === 'hold'
    && ($projLive['proj-suspect']['status'] ?? '') === 'sell' && ($projLive['proj-good']['status'] ?? '') === 'sell'
    && ($projLive['proj-waived']['status'] ?? '') === 'sell' && ($projLive['proj-onshelf']['status'] ?? '') === 'sell',
    'projection image verdicts are identical with live admission enabled');
unset($GLOBALS['options']['molavi_autopilot_image_waivers']);
unset($GLOBALS['options']['molavi_autopilot_settings_v1']);

// ---- Part B2: owner manual publish refuses no-picture products ----
// The request path computes freshness live (no explicit $freshness), so
// seed current price evidence for the fixture products first —
// otherwise every row is freshness-held and the image reason is masked.
molavi_domain_tx('pipeline', static function ($db): void {
    foreach (molavi_domain_records($GLOBALS['sheets']['Products']) as $record) {
        $id = trim((string) ($record['Product ID'] ?? ''));
        if ($id === '') continue;
        molavi_domain_sql($db, 'INSERT OR REPLACE INTO supplier_price_evidence'
            .'(offer_id,source_revision,verified_at,method) VALUES(?,?,?,?)',
            [$id, molavi_domain_source_revision($record), gmdate('c', time() - 3600), 'source']);
    }
});
foreach (['proj-noimg' => 'product_publication_no_image', 'proj-dead' => 'product_publication_no_image',
          'proj-suspect' => 'product_publication_no_image'] as $pid => $want) {
    try {
        molavi_domain_request_product_publication(
            ['action' => 'request_publication', 'product_id' => $pid, 'offer_id' => 'offer-'.$pid], 'wp:7');
        throw new RuntimeException('unexpected acceptance for '.$pid);
    } catch (MolaviWorkflowError $e) {
        check_gate($e->getMessage() === $want, 'owner request refuses '.$pid.' with '.$want);
    }
}

// ---- Part B3: g5 image arm writes a publish-gate hold with the reason ----
$GLOBALS['options']['molavi_autopilot_image_waivers'] = ['proj-waived'];
molavi_domain_autopilot_ensure_tables();
$g5 = molavi_domain_autopilot_rule_publish(['cap_per_gate_per_day' => 20], $GLOBALS['sheets'],
    (float) time(), (float) strtotime('today'), false, false, 'live', false);
$holds = molavi_domain_read('pipeline', static fn($db) => molavi_domain_rows($db,
    "SELECT product_id,reason FROM autopilot_holds WHERE gate='publish' ORDER BY product_id"));
$holdReasons = array_column($holds, 'reason', 'product_id');
check_gate(($g5['holds'] ?? 0) === 3 && ($holdReasons['proj-noimg'] ?? '') === 'تصویر کالا نیست.'
    && ($holdReasons['proj-dead'] ?? '') === 'تصویر کالا خراب است.'
    && ($holdReasons['proj-suspect'] ?? '') === 'تصویر کالا در حال بررسی است.'
    && !isset($holdReasons['proj-good']) && !isset($holdReasons['proj-waived']),
    'g5 holds no-picture/dead/suspect rows with the image reason and passes healthy/waived rows');

// ---- Part B4: publish-shop sweep ----
// NOTE: publish_shop runs with catalog_complete=true, so products outside
// the fixture payload are marked removed — these sweep runs are last.
function sweep_sheets(string $pid, string $name, string $image): array {
    global $prodHeaders, $offerHeaders;
    return ['Products' => sheet_rows($prodHeaders, [proj_product($pid, $name, $image)]),
        'Offers' => sheet_rows($offerHeaders, [proj_offer($pid)]), 'Shop' => []];
}
function sweep_freshness(string $pid): array {
    return [$pid => ['state' => 'current', 'verified_at' => gmdate('c', time() - 3600), 'source_revision' => '']];
}
function seed_evidence(): void {
    // publish_shop computes freshness live; without current evidence the
    // row is freshness-held and never reaches the gate.
    molavi_domain_tx('pipeline', static function ($db): void {
        foreach (molavi_domain_records($GLOBALS['sheets']['Products']) as $record) {
            $id = trim((string) ($record['Product ID'] ?? ''));
            if ($id === '') continue;
            molavi_domain_sql($db, 'INSERT OR REPLACE INTO supplier_price_evidence'
                .'(offer_id,source_revision,verified_at,method) VALUES(?,?,?,?)',
                [$id, molavi_domain_source_revision($record), gmdate('c', time() - 3600), 'source']);
        }
    });
}
$GLOBALS['sheets'] = sweep_sheets('sweep-404', 'Sweep 404 Tea', 'sweep404f1');
seed_evidence();
$GLOBALS['fetch_mode'] = '404';
$sweep404 = molavi_domain_publish_shop($GLOBALS['sheets'], false, 8);
$sweep404Id = null;
foreach ($GLOBALS['fake_products'] as $id => $p) {
    if (($p->get_meta('_molavi_supplier_offer_id', true) ?? '') === 'sweep-404') $sweep404Id = $id;
}
check_gate(!empty($sweep404['ok']) && !empty($sweep404['pushed']) && $sweep404Id !== null
    && gate_product($sweep404Id)->get_status() === 'draft'
    && isset(molavi_suspect_image_files()['sweep404f1'])
    && in_array($sweep404Id, array_column($sweep404['image_blocked'] ?? [], 'product'), true),
    'publish-shop sweep hides a product whose image 404s (suspect noted, not dead)');
$GLOBALS['sheets'] = sweep_sheets('sweep-200', 'Sweep 200 Tea', 'sweep200f1');
seed_evidence();
$GLOBALS['fetch_mode'] = '200';
$sweep200 = molavi_domain_publish_shop($GLOBALS['sheets'], false, 8);
$sweep200Id = null;
foreach ($GLOBALS['fake_products'] as $id => $p) {
    if (($p->get_meta('_molavi_supplier_offer_id', true) ?? '') === 'sweep-200') $sweep200Id = $id;
}
check_gate(!empty($sweep200['ok']) && ($sweep200['attached'] ?? 0) === 1 && ($sweep200['images_left'] ?? -1) === 0
    && $sweep200Id !== null && gate_product($sweep200Id)->get_status() === 'publish'
    && get_post_thumbnail_id($sweep200Id) === 99 && empty($sweep200['image_blocked']),
    'publish-shop keeps a product whose image attaches (200)');

foreach (glob(MOLAVI_STATE_ROOT.'/wp/wp-admin/includes/*') as $file) unlink($file);
@rmdir(MOLAVI_STATE_ROOT.'/wp/wp-admin/includes');
@rmdir(MOLAVI_STATE_ROOT.'/wp/wp-admin');
@rmdir(MOLAVI_STATE_ROOT.'/wp');
foreach (glob(MOLAVI_STATE_ROOT.'/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native no-picture gate '.$count." cases\n";
