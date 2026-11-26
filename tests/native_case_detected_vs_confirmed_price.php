<?php
// Detected-vs-confirmed price comparison (beer message 7218): the
// re-extract detects 75000; the owner-confirmed Sale Price is 85000.
// The case as rendered showed the detected 75000 (in the price facts)
// but NO explicit comparison against the confirmed price, so the owner
// could not tell at a glance what changed. The case carries no
// confirmed-price field (actual_price '', consumer_price None); the
// confirmed price is the product record's Sale Price — server-side
// only, not in the JS render context. The fix includes it in the case
// payload: molavi_domain_ai_case() takes an optional product_id =>
// Sale Price map (built once in molavi_domain_ai_review_cases from
// the Products sheet) and sets confirmed_sale_price when a product +
// positive price exist; cases without one keep the exact old shape.
// The field is a WP-side lookup, not extraction content, so it is
// added to the case-diff $skip list (product_id precedent).
// Run: php tests/native_case_detected_vs_confirmed_price.php
//   Negative control: git show 5a771a8:shop-wp/molavi-shop/runtime-host/molavi-domain-ai-review-model.php
//   is the deployed base (da25f014a085) — the same harness run against a
//   worktree at 5a771a8 proves the field is ABSENT there.
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-price-compare-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-store','domain-products','domain-publisher','domain-product-derivation','domain-sheet-conflicts',
    'domain-reference-matching','domain-reference-reviews','domain-publication-recovery','domain-ai-review-model','domain-ai-confirmations'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}
$count=0;$fail=0;
function cmp_check($actual,$expected,$label){global $count,$fail;if(molavi_domain_json($actual)!==molavi_domain_json($expected)){echo 'FAIL '.$label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected)."\n";$fail++;return;}$count++;}
function cmp_true($actual,$label){cmp_check($actual,true,$label);}
$clock=molavi_domain_timestamp('2026-11-19T00:00:00+00:00');
$beerOfferId='reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab:7218:0';
$beerProductId='_____________________167421';
// The live beer re-extract offer: message 7218, @bonakdarjavan, imported
// 2026-11-18, status needs_review, offer Product ID _____________________167421,
// detected supplier price 75000. Message dated 2026-11-04 (15 days before
// the clock) so the case is a historical re-extract, as live.
$beerOffer=['Offer ID'=>$beerOfferId,'Product ID'=>$beerProductId,'Product Name'=>'آب جو بلک لیبل،۱۲ عددی','Channel Username'=>'bonakdarjavan','Channel'=>'bonakdarjavan',
    'Batch ID'=>'reextract:50115195ed438942d3490cb3e0011d64','Message ID'=>'7218','Message Timestamp'=>'2026-11-04T00:00:00+00:00',
    'Source Post Created At'=>'2026-11-04T00:00:00+00:00','Source Post Updated At'=>'2026-11-04T00:00:00+00:00','Source Timestamp Basis'=>'supplier_channel_update',
    'Source Post URL'=>'https://t.me/bonakdarjavan/1523','Original Message'=>'آب جو بلک لیبل ۱۲ عددی','Sale Price'=>75000,'Detected Price'=>75000,'Package Count'=>12,
    'Status'=>'needs_review','Review Reason'=>'price_changed','Stock Status'=>'Unknown','Currency'=>'IRT','Price Type'=>'unit'];
$beerProducts=[$beerProductId=>85000];
// (1) Direct ai_case with the products map: the case carries the
// confirmed Sale Price as an integer, alongside the detected price.
$beerCase=molavi_domain_ai_case($beerOffer,null,$clock,$beerProducts);
cmp_true($beerCase!==null,'beer re-extract offer builds a reviewable case');
cmp_check($beerCase['confirmed_sale_price']??null,85000,'beer case carries confirmed_sale_price 85000 (int)');
cmp_check($beerCase['detected_price']??null,75000,'beer case keeps detected_price 75000');
cmp_check($beerCase['product_id']??null,$beerProductId,'beer case still carries the offer Product ID');
cmp_check($beerCase['case_id']??null,'ai:'.hash('sha256',$beerOfferId),'beer case_id is still sha256(offer_id)');
cmp_check($beerCase['signature']??null,molavi_domain_hash($beerOffer),'beer case signature is still hash(offer) — the new field does not change it');
cmp_true($beerCase['historical_reextract']??false,'beer case is a historical re-extract (probe-exact)');
// (2) Direct ai_case WITHOUT the map: the exact old shape — no
// confirmed_sale_price key appears (the single-case and find_case
// paths pass no map, and the case-diff path passes Offers only).
$beerCaseNoMap=molavi_domain_ai_case($beerOffer,null,$clock);
cmp_true($beerCaseNoMap!==null,'beer offer without a map still builds a case');
cmp_true(!isset($beerCaseNoMap['confirmed_sale_price']),'no map => no confirmed_sale_price key (old shape kept)');
cmp_true(!isset($beerCaseNoMap['confirmed_sale_price'])&&isset($beerCaseNoMap['product_id']),'no map => product_id still carried, confirmed price absent');
// (3) Map present but the product is missing from it: no key.
$beerCaseMissing=molavi_domain_ai_case($beerOffer,null,$clock,['other-product'=>90000]);
cmp_true(!isset($beerCaseMissing['confirmed_sale_price']),'product missing from the map => no confirmed_sale_price key');
// (4) Map present but the price is zero / empty: no key.
$beerCaseZero=molavi_domain_ai_case($beerOffer,null,$clock,[$beerProductId=>0]);
cmp_true(!isset($beerCaseZero['confirmed_sale_price']),'zero Sale Price in the map => no confirmed_sale_price key');
$beerCaseEmpty=molavi_domain_ai_case($beerOffer,null,$clock,[$beerProductId=>'']);
cmp_true(!isset($beerCaseEmpty['confirmed_sale_price']),'empty Sale Price in the map => no confirmed_sale_price key');
// (5) String price in the map normalises to an int (sheet values may
// arrive as strings; positive_price is the same normaliser the
// autopilot uses on this column).
$beerCaseString=molavi_domain_ai_case($beerOffer,null,$clock,[$beerProductId=>'85000']);
cmp_check($beerCaseString['confirmed_sale_price']??null,85000,'string Sale Price in the map normalises to int 85000');
// (6) Offer without a Product ID, map present: no key (old shape).
$freshOffer=array_replace($beerOffer,['Offer ID'=>'fresh-offer-1','Product ID'=>'','Batch ID'=>'batch-fresh','Message ID'=>'9001',
    'Message Timestamp'=>'2026-11-19T00:00:00+00:00','Source Post Created At'=>'2026-11-19T00:00:00+00:00','Source Post Updated At'=>'2026-11-19T00:00:00+00:00']);
$freshCase=molavi_domain_ai_case($freshOffer,null,$clock,$beerProducts);
cmp_true($freshCase!==null,'fresh offer builds a reviewable case');
cmp_true(!isset($freshCase['confirmed_sale_price']),'offer without a Product ID => no confirmed_sale_price key');
// (7) End-to-end ai_review_cases with Offers + Products (the workbench
// path): the map is built from the Products sheet and the case
// carries the confirmed price. This proves the wiring, not just the
// case builder.
$offerHeaders=array_keys($beerOffer);
$tables=[
    'Offers'=>[$offerHeaders,array_values($beerOffer)],
    'Products'=>[['Product ID','Product Name','Sale Price'],[$beerProductId,'آب جو بلک لیبل،۱۲ عددی',85000]],
];
[$cases,$recent]=molavi_domain_ai_review_cases($tables,null,true,200,$clock);
cmp_check(count($cases),1,'ai_review_cases returns exactly the beer case');
$listed=$cases[0]??[];
cmp_check($listed['confirmed_sale_price']??null,85000,'ai_review_cases case carries confirmed_sale_price 85000 (wiring proven)');
cmp_check($listed['offer_id']??null,$beerOfferId,'ai_review_cases case keeps its re-extract offer_id');
// (8) End-to-end ai_review_cases with Offers ONLY (the case-diff and
// single-case paths): the map stays empty, old shape stands.
[$casesOffersOnly,$recentOffersOnly]=molavi_domain_ai_review_cases(['Offers'=>[$offerHeaders,array_values($beerOffer)]],null,true,200,$clock);
cmp_check(count($casesOffersOnly),1,'Offers-only ai_review_cases still returns the beer case');
cmp_true(!isset($casesOffersOnly[0]['confirmed_sale_price']),'Offers-only ai_review_cases => no confirmed_sale_price key (old shape kept)');
// (9) The case-diff parity guard: confirmed_sale_price is skipped (a
// WP-side lookup, not extraction content), and an absent key compares
// equal to ''.
$confirmationsSrc=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-ai-confirmations.php');
cmp_true(str_contains($confirmationsSrc,"'confirmed_sale_price' => true"),'case-diff skip list contains confirmed_sale_price');
cmp_true(str_contains($confirmationsSrc,"'product_id' => true"),'case-diff skip list still contains product_id');
cmp_check(molavi_domain_ai_confirmations_norm(null),molavi_domain_ai_confirmations_norm(''),'case-diff norm: absent key equals empty string');
// (10) The wiring is in the source: the map is built from Products and
// passed at both ai_case call sites in ai_review_cases.
$modelSrc=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-ai-review-model.php');
cmp_true(str_contains($modelSrc,"molavi_domain_records(\$tables['Products']"),'ai_review_cases builds the map from the Products table');
cmp_true(substr_count($modelSrc,'molavi_domain_ai_case($offer,$decision,$clock,$confirmedPrices)')===1,'ai_review_cases main loop passes the map to ai_case');
cmp_true(substr_count($modelSrc,'$clock,$confirmedPrices)')>=2,'both ai_review_cases call sites pass the map');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);
if($fail){echo "FAIL native case detected-vs-confirmed price $fail checks\n";exit(1);}
echo 'PASS native case detected-vs-confirmed price '.$count." checks\n";
