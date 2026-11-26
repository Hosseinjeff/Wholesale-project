<?php
// Source-post status on the card (cappuccino t.me/bonakdarjavan/1337).
// The owner found the card never says the source post is gone: the
// stored copy and link stay on the card with only a staleness warning.
// Three pieces, one fix:
//   (1) SEED GAP — the seed only covered products whose supplier-price
//       freshness state was needs_review/blocked, so a product whose
//       price was currently verified never got a link-check row. The
//       cappuccino (price owner-confirmed 2026-11-24, state 'current')
//       had no row, and neither did 12 other products. The seed now
//       covers every product; existing rows are left alone when nothing
//       changed, so the check cadence for products that already have
//       rows is untouched.
//   (2) CLASSIFIER — t.me serves HTTP 200 "Post not found" for a
//       deleted post, and the Railway fetch returns null text for it,
//       so the fetch mapped it to channel_unavailable. The execute
//       path now splits it the same way as post_maybe_gone: channel
//       exists => post_not_found, channel gone => channel_unavailable.
//       post_not_found already existed in the reason vocabulary (C-S3).
//   (3) CARD PAYLOAD — molavi_domain_attach_product_link_checks()
//       attaches the link check for the product's CURRENT source
//       revision to the product payload (the workbench renders the
//       snapshot branch of molavi_domain_status_api), so the card can
//       show deleted / never-checked / unchanged. A check for an old
//       revision, or no row at all, attaches nothing ("never checked").
// Run: php tests/native_source_post_status.php [link-checks.php path] [products.php path]
//   Negative control: git show 6fc9a4f:shop-wp/molavi-shop/runtime-host/molavi-domain-supplier-link-checks.php
//   and .../molavi-domain-products.php are the deployed bases (e01d4b41fbe2 / the 6fc9a4f products.php) —
//   swap them into the worktree, run the harness, and it PASSES by proving the bug
//   (no seed row, channel_unavailable, no attach helper); restore the fixed files after,
//   hash-verified both ways.
declare(strict_types=1);
define('ABSPATH','/synthetic/');
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-source-post-'.bin2hex(random_bytes(6)));
function add_action(...$args){}
function add_filter(...$args){}
function get_option($key,$fallback=false){return $GLOBALS['options'][$key]??$fallback;}
function molavi_domain_sheets($names,$fresh=false){return $GLOBALS['sheets'];}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-store','domain-products',
    'domain-supplier-link-checks'] as $file) require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();
register_shutdown_function(static function()use($root){foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);});
(new SQLite3($root.'/pipeline.sqlite3'))->close();
(new SQLite3($root.'/reference.sqlite3'))->close();
$GLOBALS['options']=['molavi_state_active'=>true];
$count=0;$fail=0;
function cmp_check($actual,$expected,$label){global $count,$fail;if(molavi_domain_json($actual)!==molavi_domain_json($expected)){echo 'FAIL '.$label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected)."\n";$fail++;return;}$count++;}
function cmp_true($actual,$label){cmp_check($actual,true,$label);}
function cmp_null($actual,$label){cmp_check($actual,null,$label);}

// --- Fixed-vs-base detection (the harness asserts the matching outcome in each mode) ---
$lcSrc=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-supplier-link-checks.php');
$prodSrc=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-products.php');
$seedFixed=!str_contains($lcSrc,"in_array(\$offer['state'], ['needs_review', 'blocked'], true)");
$classifierFixed=str_contains($lcSrc,"=== 'channel_unavailable') {");
$attachFixed=function_exists('molavi_domain_attach_product_link_checks');
$isFixed=$seedFixed&&$classifierFixed&&$attachFixed;
echo 'MODE: '.($isFixed?'FIXED (seed covers every product; deleted post => post_not_found; attach helper present)':'BASE 6fc9a4f (negative control: state-filtered seed; deleted post => channel_unavailable; no attach helper)')."\n";
echo '  seedFixed='.($seedFixed?'yes':'no').' classifierFixed='.($classifierFixed?'yes':'no').' attachFixed='.($attachFixed?'yes':'no')."\n";

$now=molavi_domain_timestamp('2026-11-26T15:45:00+00:00');
$nowInt=(int)$now;
$capId='p_2495a6cc4a8a643c0946ca0d';
$capOffer='owner-confirm:8153eb11fee7c30961bcc192df38faa0a489be18372771f9528503bd05f2edde';
$capUrl='https://t.me/bonakdarjavan/1337';
$capMessage="کاپوچینو ۲۰ عددی تیمز\nقیمت فروش ما: ۴۵۰/۰۰۰ تومان\nقیمت مصرف کننده: ندارد";
$capProduct=['Product ID'=>$capId,'Product Name'=>'کاپوچینو ۲۰ عددی تیمز','Channel Username'=>'bonakdarjavan','Channel'=>'bonakdarjavan',
    'Latest Offer ID'=>$capOffer,'Sale Price'=>'800000','Consumer Price'=>'','Status'=>'imported',
    'Message Timestamp'=>'2026-06-06T08:24:00+00:00','Source Timestamp Basis'=>'',
    'Source Post URL'=>$capUrl,'Original Message'=>$capMessage,'Stock Status'=>'Unknown'];
$capOfferRow=['Offer ID'=>$capOffer,'Product ID'=>$capId,'Product Name'=>'کاپوچینو ۲۰ عددی تیمز','Channel Username'=>'bonakdarjavan',
    'Channel'=>'bonakdarjavan','Batch ID'=>'','Message ID'=>'1337','Message Timestamp'=>'2026-06-06T08:24:00+00:00',
    'Source Post URL'=>$capUrl,'Original Message'=>$capMessage,'Sale Price'=>'450000','Status'=>'needs_review'];
$capMessageRow=['Channel Username'=>'bonakdarjavan','Batch ID'=>'','ID'=>'1337','URL'=>$capUrl,'Content'=>$capMessage];
// A second, already-checked product: its row must survive the seed untouched (cadence preserved).
$exId='p-existing-checked';
$exOffer='owner-confirm:existing0000000000000000000000000000000000000000000000000000000000';
$exUrl='https://t.me/supplier/7';
$exProduct=['Product ID'=>$exId,'Product Name'=>'Supplier item','Channel Username'=>'supplier','Channel'=>'supplier',
    'Latest Offer ID'=>$exOffer,'Sale Price'=>'50000','Consumer Price'=>'100000','Status'=>'imported',
    'Message Timestamp'=>gmdate('c',$nowInt-3600),'Source Timestamp Basis'=>'',
    'Source Post URL'=>$exUrl,'Original Message'=>'Supplier item 50000','Stock Status'=>'Available'];
$exOfferRow=['Offer ID'=>$exOffer,'Product ID'=>$exId,'Product Name'=>'Supplier item','Channel Username'=>'supplier',
    'Channel'=>'supplier','Batch ID'=>'','Message ID'=>'7','Message Timestamp'=>gmdate('c',$nowInt-3600),
    'Source Post URL'=>$exUrl,'Original Message'=>'Supplier item 50000','Sale Price'=>'50000','Status'=>'needs_review'];
// Classifier fixtures: no evidence, so freshness state is needs_review and the runner really fetches.
$goneId='p-classifier-gone';
$goneOffer='owner-confirm:gone0000000000000000000000000000000000000000000000000000000000000';
$goneProduct=['Product ID'=>$goneId,'Product Name'=>'Gone post item','Channel Username'=>'bonakdarjavan','Channel'=>'bonakdarjavan',
    'Latest Offer ID'=>$goneOffer,'Sale Price'=>'10000','Consumer Price'=>'','Status'=>'imported',
    'Message Timestamp'=>gmdate('c',$nowInt-86400*20),'Source Timestamp Basis'=>'',
    'Source Post URL'=>'https://t.me/bonakdarjavan/1338','Original Message'=>'Gone post item 10000','Stock Status'=>'Unknown'];
$goneOfferRow=['Offer ID'=>$goneOffer,'Product ID'=>$goneId,'Product Name'=>'Gone post item','Channel Username'=>'bonakdarjavan',
    'Channel'=>'bonakdarjavan','Batch ID'=>'','Message ID'=>'1338','Message Timestamp'=>gmdate('c',$nowInt-86400*20),
    'Source Post URL'=>'https://t.me/bonakdarjavan/1338','Original Message'=>'Gone post item 10000','Sale Price'=>'10000','Status'=>'needs_review'];
$nochanId='p-classifier-nochannel';
$nochanOffer='owner-confirm:nochan00000000000000000000000000000000000000000000000000000000000';
$nochanProduct=['Product ID'=>$nochanId,'Product Name'=>'Gone channel item','Channel Username'=>'gonechannelxyz','Channel'=>'gonechannelxyz',
    'Latest Offer ID'=>$nochanOffer,'Sale Price'=>'20000','Consumer Price'=>'','Status'=>'imported',
    'Message Timestamp'=>gmdate('c',$nowInt-86400*20),'Source Timestamp Basis'=>'',
    'Source Post URL'=>'https://t.me/gonechannelxyz/5','Original Message'=>'Gone channel item 20000','Stock Status'=>'Unknown'];
$nochanOfferRow=['Offer ID'=>$nochanOffer,'Product ID'=>$nochanId,'Product Name'=>'Gone channel item','Channel Username'=>'gonechannelxyz',
    'Channel'=>'gonechannelxyz','Batch ID'=>'','Message ID'=>'5','Message Timestamp'=>gmdate('c',$nowInt-86400*20),
    'Source Post URL'=>'https://t.me/gonechannelxyz/5','Original Message'=>'Gone channel item 20000','Sale Price'=>'20000','Status'=>'needs_review'];
$products=[$capProduct,$exProduct,$goneProduct,$nochanProduct];
$offers=[$capOfferRow,$exOfferRow,$goneOfferRow,$nochanOfferRow];
$prodHeaders=molavi_domain_product_headers();
$offerHeaders=array_values(array_unique(array_merge(array_keys($capOfferRow),array_keys($exOfferRow))));
$msgHeaders=['Channel Username','Batch ID','ID','URL','Content'];
$GLOBALS['sheets']=[
    'Products'=>array_merge([$prodHeaders],array_map(static fn($p)=>array_map(static fn($h)=>$p[$h]??'',$prodHeaders),$products)),
    'Offers'=>array_merge([$offerHeaders],array_map(static fn($o)=>array_map(static fn($h)=>$o[$h]??'',$offerHeaders),$offers)),
    'MessageData'=>[$msgHeaders,array_values($capMessageRow)],
];
// The cappuccino + the existing product have owner-confirmed prices
// (2026-11-24 / recent): freshness state 'current' — the exact state
// the old seed filter excluded. The classifier fixtures have no
// evidence, so their state is needs_review and the runner fetches.
$capRevision=molavi_domain_source_revision($capProduct);
$exRevision=molavi_domain_source_revision($exProduct);
molavi_domain_tx('pipeline',static function(SQLite3 $db)use($capId,$capRevision,$exId,$exRevision,$now,$nowInt,$exOffer,$exUrl):void{
    molavi_domain_sql($db,"INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)",
        [$capId,$capRevision,'2026-11-24T09:51:00+00:00','manual','wp:7']);
    molavi_domain_sql($db,"INSERT INTO supplier_price_evidence (offer_id,source_revision,verified_at,method,verified_by) VALUES (?,?,?,?,?)",
        [$exId,$exRevision,gmdate('c',$nowInt-3600),'manual','wp:7']);
    // Pre-existing checked row for the existing product: outcome
    // unchanged, confirmed — the seed must leave it exactly alone.
    molavi_domain_sql($db,"INSERT INTO supplier_link_checks (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,outcome,reason_code,checked_at,next_at,confirmed_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [$exId,$exOffer,'Supplier item',$exUrl,$exRevision,'50000','Supplier item 50000','unchanged','identical',(int)$now-100,(int)$now+86400,(int)$now-50,(int)$now-1000]);
});

// --- (1) Seed -------------------------------------------------------------
$seeded=molavi_domain_supplier_link_check_seed((int)$now);
$capRow=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_supplier_link_check_row($db,$capId));
$exRowAfter=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_supplier_link_check_row($db,$exId));
if ($seedFixed) {
    cmp_true($capRow!==null,'seed (FIXED): cappuccino gets a row despite state current');
    cmp_check($capRow['url']??null,$capUrl,'seed (FIXED): cappuccino row carries the source URL');
    cmp_check($capRow['source_revision']??null,$capRevision,'seed (FIXED): cappuccino row carries the current source revision');
    cmp_check($capRow['offer_id']??null,$capOffer,'seed (FIXED): cappuccino row carries the latest offer id');
    cmp_null($capRow['outcome']??null,'seed (FIXED): cappuccino row starts unchecked (outcome NULL)');
    cmp_true($seeded>=1,'seed (FIXED): seed reports rows seeded');
    // Second seed: no duplicates, no re-arm of an unchanged row.
    $seeded2=molavi_domain_supplier_link_check_seed((int)$now+60);
    cmp_check($seeded2,0,'seed (FIXED): second seed seeds nothing (no duplicates, no re-arm)');
    $capRows=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,'SELECT * FROM supplier_link_checks WHERE product_id=?',[$capId]));
    cmp_check(count($capRows),1,'seed (FIXED): exactly one row for the cappuccino after two seeds');
} else {
    cmp_null($capRow,'seed (BASE, negative control): cappuccino gets NO row — state current is excluded by the filter');
    cmp_true($seeded>=0,'seed (BASE): seed runs without error');
}
cmp_check($exRowAfter['outcome']??null,'unchanged','seed: existing checked row keeps its outcome');
cmp_check((int)($exRowAfter['checked_at']??0),(int)$now-100,'seed: existing checked row keeps its checked_at (cadence untouched)');
cmp_check((int)($exRowAfter['next_at']??0),(int)$now+86400,'seed: existing checked row keeps its next_at (cadence untouched)');
cmp_check((int)($exRowAfter['confirmed_at']??0),(int)$now-50,'seed: existing checked row keeps its confirmed_at');

// --- (2) Classifier -------------------------------------------------------
// Injected fetch: the post page answers 200 with "Post not found" and
// zero message-text divs (the live t.me behaviour for a deleted post);
// the channel page answers 200 for bonakdarjavan (alive) and 404 for
// gonechannelxyz (gone).
$fetch=function(string $url): array {
    if (str_ends_with($url,'?embed=1')) {
        return ['status'=>200,'body'=>'<html><body><div class="tgme_widget_message_error">Post not found</div></body></html>'];
    }
    if ($url==='https://t.me/bonakdarjavan') return ['status'=>200,'body'=>'<html><body><div class="tgme_channel_info">bonakdarjavan</div></body></html>'];
    return ['status'=>404,'body'=>''];
};
$goneResult=molavi_domain_supplier_link_check_run_product($goneId,(int)$now,$fetch);
$goneRow=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_supplier_link_check_row($db,$goneId));
cmp_check($goneRow['outcome']??null,'uncheckable','classifier: deleted post is uncheckable');
if ($classifierFixed) {
    cmp_check($goneRow['reason_code']??null,'post_not_found','classifier (FIXED): channel alive + no text => post_not_found');
    cmp_check($goneResult['reason_code']??null,'post_not_found','classifier (FIXED): run_product returns post_not_found');
} else {
    cmp_check($goneRow['reason_code']??null,'channel_unavailable','classifier (BASE, negative control): deleted post misclassified as channel_unavailable');
}
$nochanResult=molavi_domain_supplier_link_check_run_product($nochanId,(int)$now,$fetch);
$nochanRow=molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_supplier_link_check_row($db,$nochanId));
cmp_check($nochanRow['outcome']??null,'uncheckable','classifier: gone channel is uncheckable');
cmp_check($nochanRow['reason_code']??null,'channel_unavailable','classifier: channel gone + no text => channel_unavailable (preserved in both modes)');
cmp_check($nochanResult['reason_code']??null,'channel_unavailable','classifier: run_product returns channel_unavailable for a gone channel');
// The reason label for the deleted post already exists in the vocabulary.
cmp_check(molavi_domain_supplier_link_check_reason_label('post_not_found'),'پست منبع حذف شده (C-S3)','classifier: post_not_found label is the existing C-S3 label');

// --- (3) Card payload attachment ------------------------------------------
if ($attachFixed) {
    $productsById=array_column($products,null,'Product ID');
    $attached=molavi_domain_attach_product_link_checks([
        ['product_id'=>$capId,'product_name'=>'کاپوچینو ۲۰ عددی تیمز'],
        ['product_id'=>$goneId,'product_name'=>'Gone post item'],
        ['product_id'=>$nochanId,'product_name'=>'Gone channel item'],
        ['product_id'=>'p-no-row','product_name'=>'Never seeded'],
    ],$productsById);
    cmp_true(isset($attached[0]['link_check']),'attach (FIXED): cappuccino row attaches (revision matches)');
    cmp_null($attached[0]['link_check']['outcome']??null,'attach (FIXED): cappuccino link_check outcome is NULL — never checked, so the card shows never-checked');
    // A product whose row has a STALE revision must not attach it.
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($goneId):void{
        molavi_domain_sql($db,"UPDATE supplier_link_checks SET source_revision='stale-revision' WHERE product_id=?",[$goneId]);
    });
    $attachedStale=molavi_domain_attach_product_link_checks([
        ['product_id'=>$goneId,'product_name'=>'Gone post item'],
    ],$productsById);
    cmp_true(!isset($attachedStale[0]['link_check']),'attach (FIXED): a row with a stale revision attaches nothing');
    molavi_domain_tx('pipeline',static function(SQLite3 $db)use($goneId,$goneProduct):void{
        molavi_domain_sql($db,"UPDATE supplier_link_checks SET source_revision=? WHERE product_id=?",
            [molavi_domain_source_revision($goneProduct),$goneId]);
    });
    $attached2=molavi_domain_attach_product_link_checks([
        ['product_id'=>$goneId,'product_name'=>'Gone post item'],
        ['product_id'=>'p-no-row','product_name'=>'Never seeded'],
    ],$productsById);
    cmp_check($attached2[0]['link_check']['outcome']??null,'uncheckable','attach (FIXED): matching-revision row attaches its outcome');
    cmp_check($attached2[0]['link_check']['reason_code']??null,'post_not_found','attach (FIXED): attached row carries post_not_found');
    cmp_check($attached2[0]['link_check']['url']??null,'https://t.me/bonakdarjavan/1338','attach (FIXED): attached row carries the source URL');
    cmp_true(!isset($attached2[1]['link_check']),'attach (FIXED): a product with no row gets no link_check (never checked)');
    cmp_true(!isset($attached[3]['link_check']),'attach (FIXED): a product with no row gets no link_check (never checked)');
} else {
    cmp_true(!function_exists('molavi_domain_attach_product_link_checks'),'attach (BASE, negative control): no attach helper exists');
}

echo "\n$count PASS, $fail FAIL\n";
if ($fail) exit(1);
echo "ALL SOURCE-POST-STATUS CHECKS PASSED\n";
