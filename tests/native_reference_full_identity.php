<?php
/** Full-identity matcher harness (catalog-extraction iteration 1):
 * traced rescues + E-verified rescues + false-positive guards, through
 * the REAL candidate matcher, backfill decide and extraction junction.
 * Run: php tests/native_reference_full_identity.php
 * Negative control (pristine deployed base): run the same traced cases
 * against the base checkout — see tests/neg_control_full_identity.php
 * (ro-jin must fail tier, tohfeh must be absent from ranking there).
 * Sheet prices for the E items are harness placeholders: the assertions
 * check routing/reason, never price values, for those. */
declare(strict_types=1);
define('ABSPATH', '/synthetic/');
define('MOLAVI_REFERENCE_OWNER_IDS', [7]);
define('MOLAVI_STATE_ROOT', sys_get_temp_dir() . '/molavi-reference-full-identity-' . bin2hex(random_bytes(6)));
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $GLOBALS['full_identity_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['full_identity_options'][$key] = $value; }

/* Sheets are stubbed in-memory (domain-connectors.php is NOT required, so no HTTP happens). */
function molavi_domain_sheets($names, $fresh = false, $unformatted = false) {
    $out = [];
    foreach ($names as $name) $out[$name] = $GLOBALS['full_identity_tables'][$name] ?? [];
    return $out;
}
function molavi_domain_sheet_fields($name, $row, $headers, $values) {
    foreach ($values as $field => $value) $GLOBALS['full_identity_tables'][$name][$row - 1][array_search($field, $headers, true)] = $value;
}

foreach (['state-host', 'domain-core', 'domain-schemas', 'domain-gateway', 'domain-reference-parser',
    'domain-reference-store', 'domain-reference-matching', 'domain-products', 'domain-publisher',
    'domain-product-derivation', 'domain-sheet-conflicts', 'domain-jobs', 'domain-source-projection',
    'domain-sale-offers', 'domain-orders', 'domain-reference-reviews', 'domain-reference-scheduler',
    'domain-reference-backfill','domain-reference-matches'] as $file) require __DIR__ . '/../shop-wp/molavi-shop/runtime-host/molavi-' . $file . '.php';

$root = molavi_state_root();
foreach (['pipeline', 'reference'] as $store) {
    $db = new SQLite3($root . '/' . $store . '.sqlite3');
    $db->close();
}
$count = 0;
function check($actual, $expected, $label) {
    global $count;
    if (molavi_domain_json($actual) !== molavi_domain_json($expected))
        throw new RuntimeException($label . ' ' . molavi_domain_json(['actual' => $actual, 'expected' => $expected]));
    $count++;
}
function check_true($actual, $label) { check($actual, true, $label); }

/* --- Fixtures: 21-column catalog rows (reference headers order).
 * cat($raw, $clean, $code, $consumer, $brand, $category, $variant, $volume, $weight, $count).
 * Brand column is EMPTY for every Rezvan row (D proved it for all 3,392):
 * the brand must be inferred from the title. Clean names mirror the
 * current Nov-26 snapshot (pack formats still present in clean text). */
function cat($raw, $clean, $code, $consumer, $brand, $category, $variant = '', $volume = '', $weight = '', $count = 0) {
    return [$raw, $clean, 'key-' . $code, 0, 0, $consumer, $count, '', $volume, $weight, 0,
        $variant, $brand, $category, '', 1, $code, 'TOMAN', 'rezvanpakhsh_woocommerce_store_api',
        'https://rezvanpakhsh.com/wp-json/wc/store/v1/products', '2026-11-26T00:00:00+00:00'];
}
$catalogMain = [
    cat('سس روژین هزارجزیره470گ(320ف)12ع', 'سس روژین هزارجزیره470 گرم', '26451', 320000, '', 'سس', '', '', '470 گرم', 12),
    cat('تن ماهی تحفه180گ(290ف)24ع ***(قدیم) (R150)', 'تن ماهی تحفه180 گرم', '12144', 290000, '', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن ماهی تحفه دودی180گ(334ف)24ع', 'تن ماهی تحفه دودی180 گرم', '25343', 334000, '', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن ماهی تحفه فلفل180گ(334ف)24ع', 'تن ماهی تحفه فلفل180 گرم', '25846', 334000, '', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن با لوبیا محفل230گ(260ف)24ع***', 'تن با لوبیا محفل230 گرم', '26121', 260000, '', 'کنسرو', '', '', '230 گرم', 24),
    /* محفل biscuit title is representative (D traced only its sim 0.4935):
     * the assertion is that it never identity-matches a tuna. */
    cat('بیسکویت محفل شکلاتی 200 گرم', 'بیسکویت محفل شکلاتی 200 گرم', '22405', 0, '', 'بیسکویت', '', '', '200 گرم', 0),
    cat('کافی میکس تیمز(1*3)(بزرگ) 48ع20بسته***!', 'کافی میکس تیمز(1*3)(بزرگ) 48ع20بسته', '19957', 0, '', '', '', '', '', 0),
    cat('کافی میکس تیمز(1*3)(کوچک)20ع12بسته***!', 'کافی میکس تیمز(1*3)(کوچک)20ع12بسته', '20917', 0, '', '', '', '', '', 0),
    cat('کافی میکس تیمز(1*2)رژیمی 20تا12بسته***!', 'کافی میکس تیمز(1*2)رژیمی 20تا12بسته', '19956', 0, '', '', '', '', '', 0),
    cat('کافی میکس تیمز هات چاکلت20ع18بسته***!', 'کافی میکس تیمز هات چاکلت20ع18بسته', '19101', 0, '', '', '', '', '', 0),
    cat('انرژی زا بلک ولف1ل کلاسیک12ع**', 'انرژی زا بلک ولف1 لیتر کلاسیک', '22539', 0, '', '', '', '1 لیتر', '', 12),
    cat('نوشابه 1ل کیکس مکس آلبالو(199ف)6ع**', 'نوشابه 1ل کیکس مکس آلبالو', '26303', 199000, '', '', '', '1 لیتر', '', 6),
    cat('نوشابه 1ل کیکس مکس میکس بری(199ف)6ع**', 'نوشابه 1ل کیکس مکس میکس بری', '26305', 199000, '', '', '', '1 لیتر', '', 6),
    cat('نوشابه 1ل کیکس مکس بلوبری(199ف)6ع**', 'نوشابه 1ل کیکس مکس بلوبری', '26306', 199000, '', '', '', '1 لیتر', '', 6),
    cat('آبمیوه رانی هلو 24ع(95ف)**', 'آبمیوه رانی هلو 24ع', '22062', 95000, '', '', '', '', '', 24),
    cat('آبمیوه رانی انبه (95ف)24ع**', 'آبمیوه رانی انبه 24ع', '13098', 95000, '', '', '', '', '', 24),
    cat('آبمیوه رانی آناناس(100ف)24ع**', 'آبمیوه رانی آناناس 24ع', '13096', 100000, '', '', '', '', '', 24),
    cat('چای100گ شهرزاد عطری(289ف)24ع***', 'چای100گ شهرزاد عطری', '26352', 289000, '', '', '', '', '100 گرم', 24),
    cat('چای100گ شهرزاد ساده(289ف)24ع***', 'چای100گ شهرزاد ساده', '26351', 289000, '', '', '', '', '100 گرم', 24),
    cat('چای325گ شهرزاد فلزی عطری(مشکی)(1/028ف)6ع***', 'چای325گ شهرزاد فلزی عطری', '26354', 1028000, '', '', '', '', '325 گرم', 6),
    cat('تن ماهی گلکسی دودی180گ(310ف)24ع', 'تن ماهی گلکسی دودی180 گرم', '23637', 310000, '', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن ماهی گلکسی180گ(330ف)24ع**', 'تن ماهی گلکسی180 گرم', '12136', 330000, '', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن ماهی گلکسی شوید180گ(365ف)24ع', 'تن ماهی گلکسی شوید180 گرم', '21799', 365000, '', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن ماهی گلکسی فلفل180گ(365ف)24ع', 'تن ماهی گلکسی فلفل180 گرم', '21798', 365000, '', 'تن ماهی', '', '', '180 گرم', 24),
    /* سن ایچ / soda titles are representative (D quoted only "1L سن ایچ
     * آلبالو/آناناس" and "a soda"): the assertion is that the brand
     * سون / بلک لیبل is nowhere in the catalog, so nothing identity-matches. */
    cat('آبمیوه سن ایچ آلبالو 1 لیتر', 'آبمیوه سن ایچ آلبالو 1 لیتر', 'SANECH-A', 0, 'سن ایچ', '', '', '1 لیتر', '', 0),
    cat('آبمیوه سن ایچ آناناس 1 لیتر', 'آبمیوه سن ایچ آناناس 1 لیتر', 'SANECH-P', 0, 'سن ایچ', '', '', '1 لیتر', '', 0),
    cat('نوشابه گازدار کوکا 330 میلی لیتر', 'نوشابه گازدار کوکا 330 میلی لیتر', 'SODA', 0, 'کوکا', '', '', '330 میلی لیتر', '', 0),
    cat('تن ماهی شباب 180 گرم 24 عددی', 'تن ماهی شباب 180 گرم 24 عددی', 'SHABAB', 0, 'شباب', 'تن ماهی', '', '', '180 گرم', 24),
    cat('تن ماهی شیلتون رژیمی 180 گرم', 'تن ماهی شیلتون رژیمی 180 گرم', 'SHIL-180', 0, '', 'تن ماهی', 'رژیمی', '', '180 گرم', 24),
    cat('تن ماهی شیلتون 230 گرم', 'تن ماهی شیلتون 230 گرم', 'SHIL-230', 0, '', 'تن ماهی', '', '', '230 گرم', 24),
    cat('سرم ویتامین سی 30 میلی لیتر', 'سرم ویتامین سی 30 میلی لیتر', 'VITC-S', 0, '', '', '', '30 میلی لیتر', '', 0),
    cat('کرم صورت ویتامین سی 50 گرم', 'کرم صورت ویتامین سی 50 گرم', 'VITC-C', 0, '', '', '', '', '50 گرم', 0),
    /* شف هو titles are representative (E traced ids + forms + the (قدیم)
     * markers, not the full titles). */
    cat('نودل شف هو قارچ و پنیر لیوانی 12ع', 'نودل شف هو قارچ و پنیر لیوانی 12ع', '25116', 0, '', '', '', '', '', 12),
    cat('نودل شف هو گوشت لیوانی 12ع (قدیم)', 'نودل شف هو گوشت لیوانی 12ع', '26497', 0, '', '', '', '', '', 12),
    cat('نودل شف هو مرغ لیوانی 12ع (قدیم)', 'نودل شف هو مرغ لیوانی 12ع', '26498', 0, '', '', '', '', '', 12),
    cat('نودل شف هو سبزیجات لیوانی 12ع (قدیم)', 'نودل شف هو سبزیجات لیوانی 12ع', '26499', 0, '', '', '', '', '', 12),
    cat('کافی میکس تیمز کاپوچینو20تا12بسته', 'کافی میکس تیمز کاپوچینو20تا12بسته', '19099', 0, '', '', '', '', '', 0),
];
/* تیمز rows with the parser fix applied (counts the old parser missed). */
$catalogFixed = $catalogMain;
foreach ($catalogFixed as &$row) {
    if ($row[16] === '19957') $row[6] = 48;
    if ($row[16] === '20917') $row[6] = 20;
    if ($row[16] === '19956') $row[6] = 20;
    if ($row[16] === '19101') $row[6] = 20;
    if ($row[16] === '19099') $row[6] = 20;
}
unset($row);

/* --- Fixtures: Products + Offers (incoming values are D/E's exact probe values) --- */
function product_record($id, $name, $brand, $category, $variant, $volume, $weight, $pack, $consumer, $offer) {
    return ['Product ID' => $id, 'Product Name' => $name, 'Brand' => $brand, 'Category' => $category,
        'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => $weight, 'Package Count' => $pack, 'Packaging' => '',
        'Consumer Price' => $consumer, 'Sale Price' => 80000, 'Status' => 'imported', 'Latest Offer ID' => $offer,
        'Channel Username' => 'testchannel', 'Reference Match ID' => '', 'Reference Snapshot ID' => '',
        'Reference Approval State' => '', 'Reference Decision ID' => '', 'Reference Price Role' => '',
        'Reference Price Change Percent' => '', 'Reference Price Checked At' => '', 'Reference Review Reason' => ''];
}
function offer_record($id, $name, $brand, $category, $variant, $volume, $weight, $pack, $consumer, $productId) {
    return ['Offer ID' => $id, 'Product ID' => $productId, 'Product Name' => $name, 'Brand' => $brand,
        'Category' => $category, 'Variation Type' => $variant, 'Volume' => $volume, 'Weight' => $weight, 'Package Count' => $pack,
        'Packaging' => '', 'Sale Price' => 80000, 'Consumer Price' => $consumer, 'Price' => 80000, 'Actual Price' => '',
        'Status' => 'imported', 'Review Reason' => '', 'Channel Username' => 'testchannel', 'Channel' => 'testchannel',
        'Message ID' => 'msg-' . $id, 'Batch ID' => 'batch-1', 'Message Timestamp' => '2026-11-01T00:00:00+00:00',
        'Source Timestamp Basis' => '', 'Import Timestamp' => '2026-11-20T00:00:00+00:00', 'Original Message' => 'test',
        'Source Post URL' => '', 'Reference Snapshot ID' => '', 'Reference Match State' => '', 'Reference Match ID' => '',
        'Reference Price Role Hint' => '', 'Reference Decision ID' => '', 'Owner Decision ID' => '', 'Derived From Offer ID' => ''];
}
$products = [
    product_record('p_rojin', 'سس هزار جزیره روژین 470 گرم', 'روژین', 'سس', '', '', '470 گرم', '', 320000, 'o-rojin'),
    product_record('p_tohfeh', 'تن ماهی تحفه 180 گرم 24 عددی', 'تحفه', 'general', '', '', '180 گرم', 24, 290000, 'o-tohfeh'),
    product_record('p_mahfel', 'تن و لوبیا محفل 230 گرم 24عددی', 'محفل', 'کنسرو', '', '', '230 گرم', 24, 290000, 'o-mahfel'),
    product_record('p_b1', 'کافی میکس 1 3 تیمز 48 عددی', 'تیمز', '', '', '', '', 48, 100000, 'o-b1'),
    product_record('p_b2', 'کافی میکس 1 3 تیمز 20 عددی', 'تیمز', '', '', '', '', 20, 100000, 'o-b2'),
    product_record('p_b3', 'کافی میکس 1 2 تمیز 20 عددی', 'تمیز', '', '', '', '', 20, 100000, 'o-b3'),
    product_record('p_b3b', 'کافی میکس 1 2 تیمز 20 عددی', 'تیمز', '', '', '', '', 20, 100000, 'o-b3b'),
    product_record('p_b6', 'بلک ولف 1 لیتر 12 عددی', 'بلک ولف', '', '', '1 لیتر', '', 12, 100000, 'o-b6'),
    product_record('p_b7', 'کیکس مکس 1 لیتر', 'کیکس مکس', '', '', '1 لیتر', '', '', 199000, 'o-b7'),
    product_record('p_b10', 'آبمیوه رانی 4 طعم 24 عددی', 'رانی', '', '4 طعم', '', '', 24, 100000, 'o-b10'),
    product_record('p_b5', 'چای شهرزاد 400 گرم 10 عددی', 'شهرزاد', '', '', '', '400 گرم', 10, 100000, 'o-b5'),
    product_record('p_b9', 'تن ماهی گالکسی در زیتون 24 عددی', 'گالکسی', '', '', '', '', 24, 100000, 'o-b9'),
    product_record('p_b9b', 'تن ماهی گلکسی در زیتون 24 عددی', 'گلکسی', '', '', '', '', 24, 100000, 'o-b9b'),
    product_record('p_b9d', 'تن ماهی گالکسی در زیتون 24 عددی', '', 'تن ماهی', 'در زیتون', '', '', '', 100000, 'o-b9d'),
    product_record('p_b4', 'چای دوغزال 500 گرم 24 عددی', 'دوغزال', '', '', '', '500 گرم', 24, 100000, 'o-b4'),
    product_record('p_seven', 'آبمیوه تتراپک سون 220 میل طعم آلبالو', 'سون', 'food', 'آلبالو', '220 میل', '', 36, 100000, 'o-seven'),
    product_record('p_blacklabel', 'آب جو بلک لیبل 12 عددی', 'بلک لیبل', 'general', '', '', '', 12, 100000, 'o-blacklabel'),
    product_record('p_khosh', 'تن ماهی خوش خوراک 24 عددی', 'خوش خوراک', 'تن ماهی', '', '', '', 24, 100000, 'o-khosh'),
    product_record('p_shilton', 'تن 120 گرم شیلتون 24 عددی', 'شیلتون', 'general', '', '', '120 گرم', 24, 100000, 'o-shilton'),
    product_record('p_vitc', 'ویتامین c', '', '', '', '', '', '', 100000, 'o-vitc'),
    product_record('p_chef_flat', 'نودل تخت شف هو 40 عددی', 'شف هو', '', '', '', '', 40, 100000, 'o-chef-flat'),
    product_record('p_chef_cup24', 'نودل شف هو لیوانی 24 عددی', 'شف هو', '', '', '', '', 24, 100000, 'o-chef-cup24'),
    product_record('p_chef_pepper', 'نودل شف هو فلفلی', 'شف هو', '', '', '', '', '', 100000, 'o-chef-pepper'),
    product_record('p_cappu', 'کاپوچینو تیمز', '', '', '', '', '', '', 100000, 'o-cappu'),
    product_record('p_teams_typo', 'کافی میکس 1 3 تیم 48 عددی', 'تیم', '', '', '', '', 48, 100000, 'o-teams-typo'),
];
$offers = [];
foreach ($products as $p) {
    $offers[] = offer_record('o-' . substr($p['Product ID'], 2), $p['Product Name'], $p['Brand'], $p['Category'],
        $p['Variation Type'], $p['Volume'], $p['Weight'], $p['Package Count'], $p['Consumer Price'], $p['Product ID']);
}
$byId = static fn($id) => array_values(array_filter($products, static fn($r) => $r['Product ID'] === $id))[0];
$offerById = static fn($id) => array_values(array_filter($offers, static fn($r) => $r['Offer ID'] === $id))[0];
$decideFor = static function (array $record, array $offer, array $catalog): array {
    $attrs = molavi_domain_offer_attributes($offer);
    $attrs['supplier_price'] = molavi_domain_py_value($offer['Price'] ?? null, molavi_domain_py_value($offer['Sale Price'] ?? null));
    return molavi_domain_reference_backfill_decide($record, molavi_domain_reference_candidates($attrs, $catalog, 20));
};
$candsFor = static function (array $offer, array $catalog): array {
    $attrs = molavi_domain_offer_attributes($offer);
    return molavi_domain_reference_candidates($attrs, $catalog, 20);
};
$identityFor = static function (array $offer, array $catalog) use ($candsFor): array {
    return array_values(array_filter($candsFor($offer, $catalog), static fn($c) => !empty($c['identity_match'])));
};

/* --- (1) Tokenizer / parser unit checks through the real functions --- */
check(molavi_domain_identity_parse_title('سس روژین هزارجزیره470گ(320ف)12ع')['tokens'], ['سس', 'روژین', 'هزارجزیره'], 'tokenizer: روژین de-glues to three tokens');
$tohfehParsed = molavi_domain_identity_parse_title('تن ماهی تحفه180گ(290ف)24ع ***(قدیم) (R150)');
check($tohfehParsed['tokens'], ['تن', 'ماهی', 'تحفه'], 'tokenizer: تحفه drops the price/pack/R codes, no stray r token');
check_true($tohfehParsed['stale'], 'tokenizer: تحفه is flagged stale (قدیم)');
check(molavi_domain_identity_parse_title('کافی میکس تیمز(1*3)(بزرگ) 48ع20بسته')['tokens'], ['کافی', 'میکس', 'تیمز', 'بزرگ', '1x3'], 'tokenizer: تیمز keeps the (1*3) format token');
check(molavi_domain_identity_parse_title('کافی میکس 1 3 تیمز 48 عددی')['tokens'], ['کافی', 'میکس', 'تیمز', '1x3'], 'tokenizer: bare "1 3" reads as the same format token');
check(molavi_domain_identity_parse_title('آجیل تست ۲۵۰ گرمی')['tokens'], ['آجیل', 'تست'], 'tokenizer: adjective size گرمی is stripped');
check(molavi_domain_identity_alias_brand('تمیز'), 'تیمز', 'alias: تمیز maps to تیمز');
check(molavi_domain_identity_alias_brand('تیم'), 'تیم', 'alias: unlisted misspelling تیم does NOT map');
check(molavi_domain_name_key('رشته آش حميد'), molavi_domain_name_key('رشته آش حمید'), 'name_key: حميد and حمید hash alike');
check(molavi_domain_title_count('کافی میکس تیمز(1*3)(بزرگ) 48ع20بسته'), 48, 'parser: 48ع20بسته reads pack 48');
check(molavi_domain_title_count('کافی میکس تیمز(1*2)رژیمی 20تا12بسته'), 20, 'parser: 20تا12بسته reads pack 20');
check(molavi_domain_title_count('انرژی زا بلک ولف1ل کلاسیک12ع'), 12, 'parser: 12ع reads pack 12');
check(molavi_domain_title_count('رب خوشاب ۱۲ عددی'), 12, 'parser: ۱۲ عددی reads pack 12');
check_true(!str_contains(molavi_domain_clean_title('کافی میکس تیمز(1*3)(بزرگ) 48ع20بسته'), '48ع'), 'parser: clean title strips 48ع');
check_true(!str_contains(molavi_domain_clean_title('کافی میکس تیمز(1*3)(بزرگ) 48ع20بسته'), '20بسته'), 'parser: clean title strips 20بسته');

/* --- (2) Traced rescues (D) --- */
$cands = $candsFor($offerById('o-rojin'), $catalogMain);
check($cands[0]['reference_id'], '26451', 'rojin: 26451 ranks first');
check_true($cands[0]['identity_match'], 'rojin: 26451 is a full-identity match');
$d = $decideFor($byId('p_rojin'), $offerById('o-rojin'), $catalogMain);
check_true($d['matched'], 'rojin: tier-0 auto-matches');
check($d['tier'], 'full_identity', 'rojin: tier is full_identity');
check($d['reason'], 'unique_full_identity_price_agreed', 'rojin: reason');
check($d['reference_id'], '26451', 'rojin: reference id');

$cands = $candsFor($offerById('o-tohfeh'), $catalogMain);
check($cands[0]['reference_id'], '12144', 'tohfeh: the true row 12144 ranks first (was never ranked)');
check_true($cands[0]['identity_match'], 'tohfeh: 12144 is a full-identity match');
check_true($cands[0]['stale_packaging'], 'tohfeh: 12144 carries the stale-packaging flag');
$d = $decideFor($byId('p_tohfeh'), $offerById('o-tohfeh'), $catalogMain);
check($d['matched'], false, 'tohfeh: a stale row never auto-matches');
check_true($d['candidate'], 'tohfeh: it surfaces as an owner candidate');
check($d['reason'], 'identity_match_stale_packaging', 'tohfeh: reason');
check($d['tier'], 'identity_stale', 'tohfeh: tier is identity_stale');
check($d['reference_id'], '12144', 'tohfeh: reference id');

$d = $decideFor($byId('p_mahfel'), $offerById('o-mahfel'), $catalogMain);
check($d['matched'], false, 'mahfel: 10.3% out never auto-matches');
check_true($d['candidate'], 'mahfel: it surfaces as an owner candidate (not hidden behind biscuits)');
check($d['reason'], 'identity_match_price_outside_margin', 'mahfel: reason');
check($d['tier'], 'identity_candidate', 'mahfel: tier is identity_candidate');
check($d['reference_id'], '26121', 'mahfel: reference id');

/* --- (3) E-verified rescues: تیمز + بلک ولف --- */
/* Current snapshot shape (counts the old parser missed): B1/B2 are
 * ambiguous between the two (1*3) rows — they surface as multiple, never
 * as a wrong single pick. */
$d = $decideFor($byId('p_b1'), $offerById('o-b1'), $catalogMain);
check($d, ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => 2], 'B1 current-shape: two (1*3) rows, pack unknown — multiple, no wrong pick');
$d = $decideFor($byId('p_b2'), $offerById('o-b2'), $catalogMain);
check($d, ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => 2], 'B2 current-shape: multiple, no wrong pick');
/* Parser-fixed shape: pack counts disambiguate. */
$d = $decideFor($byId('p_b1'), $offerById('o-b1'), $catalogFixed);
check_true($d['candidate'], 'B1 fixed-shape: owner candidate (catalog consumer price is 0)');
check($d['reason'], 'identity_match_no_catalog_price', 'B1 fixed-shape: reason');
check($d['reference_id'], '19957', 'B1 fixed-shape: reference id');
$d = $decideFor($byId('p_b2'), $offerById('o-b2'), $catalogFixed);
check_true($d['candidate'], 'B2 fixed-shape: owner candidate');
check($d['reason'], 'identity_match_no_catalog_price', 'B2 fixed-shape: reason');
check($d['reference_id'], '20917', 'B2 fixed-shape: reference id');
/* B3 resolves in BOTH shapes: the 1x2 format token kills the (1*3) rows. */
foreach (['current' => $catalogMain, 'fixed' => $catalogFixed] as $shape => $catalog) {
    $d = $decideFor($byId('p_b3'), $offerById('o-b3'), $catalog);
    check_true($d['candidate'], "B3 $shape-shape: typo brand matches via the alias, owner candidate");
    check($d['reason'], 'identity_match_no_catalog_price', "B3 $shape-shape: reason");
    check($d['reference_id'], '19956', "B3 $shape-shape: reference id");
    $d = $decideFor($byId('p_b3b'), $offerById('o-b3b'), $catalog);
    check($d['reference_id'], '19956', "B3b $shape-shape: fixed brand matches the same row");
}
$d = $decideFor($byId('p_b6'), $offerById('o-b6'), $catalogMain);
check_true($d['candidate'], 'B6: owner candidate (catalog consumer price is 0)');
check($d['reason'], 'identity_match_no_catalog_price', 'B6: reason');
check($d['reference_id'], '22539', 'B6: reference id');
check_true($identityFor($offerById('o-b6'), $catalogMain)[0]['identity_match'], 'B6: name-only-brand still identity-matches (brand words are the product identity there)');

/* --- (4) Generic items must NOT auto-match a single flavour row --- */
$d = $decideFor($byId('p_b7'), $offerById('o-b7'), $catalogMain);
check($d, ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => 3], 'B7: generic کیکس مکس 1L sees three flavour rows — multiple');
$d = $decideFor($byId('p_b10'), $offerById('o-b10'), $catalogMain);
check($d, ['matched' => false, 'reason' => 'multiple_identity_candidates', 'candidate_count' => 3], 'B10: رانی 4-flavour sees three flavour rows — multiple');

/* --- (5) Extraction junction (candidate_match) --- */
$robRow = fn($count) => cat('رب خوشاب', 'رب خوشاب', 'ROB-KH', 0, '', '', '', '', '', $count);
$robSupplier = ['name' => 'رب خوشاب 12 عددی', 'brand' => '', 'category' => 'رب', 'variant' => '', 'package_count' => '', 'volume' => '', 'weight' => ''];
$r = molavi_domain_reference_candidate_match($robSupplier, [$robRow(0)]);
check($r['state'], 'candidate', 'junction rob count-empty: catalog count 0 is unknown, not a mismatch');
check($r['reason'], 'exact_compatible_identity', 'junction rob count-empty: reason');
$robSupplierBrand = $robSupplier;
$robSupplierBrand['brand'] = 'خوشاب';
$r = molavi_domain_reference_candidate_match($robSupplierBrand, [$robRow(0)]);
check($r['state'], 'candidate', 'junction rob brand-filled: supplier brand خوشاب + empty catalog brand infers from the title');
$r = molavi_domain_reference_candidate_match($robSupplierBrand, [$robRow(12)]);
check($r['state'], 'candidate', 'junction rob count-12: pack wording does not veto when counts agree');
$aloeRow = cat('نوشیدنی آلوورا ۱/۵ لیتر (۱۵۰ف)', 'نوشیدنی آلوورا 1.5 لیتر', 'ALOE', 150000, '', '', '', '1.5 لیتر', '', 0);
$aloeSupplier = ['name' => 'نوشيدنى آلوورا بر پايه عسل', 'brand' => '', 'category' => '', 'variant' => '', 'package_count' => 6, 'volume' => '1.5 لیتر', 'weight' => ''];
$r = molavi_domain_reference_candidate_match($aloeSupplier, [$aloeRow]);
check($r, ['state' => 'needs_review', 'reason' => 'no_compatible_reference', 'candidates' => []], 'junction aloe: the honey qualifier is not in the catalog title, so identity cannot confirm it (honest non-match; iteration 2 could add qualifier handling)');
$r = molavi_domain_reference_candidate_match(
    ['name' => 'تن ماهی تحفه 180 گرم 24 عددی', 'brand' => 'تحفه', 'category' => 'general', 'variant' => '', 'package_count' => 24, 'volume' => '', 'weight' => '180 گرم'],
    [$catalogMain[1]]);
check($r['state'], 'needs_review', 'junction tohfeh: stale never returns candidate');
check($r['reason'], 'stale_packaging_reference', 'junction tohfeh: reason');
check(array_column($r['candidates'], 'reference_id'), ['12144'], 'junction tohfeh: the stale row is returned for human confirmation');

/* --- (6) False-positive guards: nothing here may auto-match --- */
$guardCases = [
    'p_b5' => 'B5 شهرزاد 400g: only 100g/325g/تی‌بگ exist',
    'p_b9' => 'B9 گلکسی زیتون as written (گالکسی): unlisted misspelling never matches',
    'p_b9b' => 'B9b گلکسی زیتون fixed: no olive row exists',
    'p_b9d' => 'B9d گلکسی زیتون, brand empty: tokens alone cannot confirm it',
    'p_b4' => 'B4 دوغزال: zero rows of any kind in the snapshot',
    'p_seven' => 'سون 220ml: brand absent from the catalog',
    'p_blacklabel' => 'بلک لیبل: brand absent from the catalog',
    'p_khosh' => 'خوش خوراک tuna: brand absent from the catalog',
    'p_shilton' => 'شیلتون 120g: only 180g/230g rows exist (size conflict)',
    'p_vitc' => 'vitamin C: no brand, no form, no size — genuinely ambiguous',
    'p_chef_flat' => 'شف هو تخت 40 عددی: different form and pack',
    'p_chef_cup24' => 'شف هو لیوانی 24 عددی: catalog لیوانی is 12ع',
    'p_chef_pepper' => 'شف هو فلفلی: variant absent from the catalog',
    'p_cappu' => 'کاپوچینو تیمز bare: brand empty, no auto-match',
    'p_teams_typo' => 'تیم (unlisted): never identity-matches',
];
foreach ($guardCases as $id => $label) {
    $d = $decideFor($byId($id), $offerById('o-' . substr($id, 2)), $catalogMain);
    check($d['matched'], false, "guard $label: never auto-matches");
    check_true(empty($identityFor($offerById('o-' . substr($id, 2)), $catalogMain)), "guard $label: no full-identity match");
}
$d = $decideFor($byId('p_vitc'), $offerById('o-vitc'), $catalogMain);
check($d['reason'], 'no_candidate', 'guard vitamin C: reason is no_candidate');

foreach (glob(MOLAVI_STATE_ROOT . '/*') as $file) unlink($file);
rmdir(MOLAVI_STATE_ROOT);
echo 'PASS native reference full identity ' . $count . " cases\n";
