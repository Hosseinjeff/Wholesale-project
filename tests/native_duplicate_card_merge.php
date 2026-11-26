<?php
// Duplicate-card merge (owner request 2026-11-26): ONE product showed as TWO
// cards. «آب جو بلک لیبل،۱۲ عددی» appears twice — card 1 is the confirmed
// pipeline product (Product ID _____________________167421, HAS an autopilot
// block) and card 2 is an AI-confirmation CASE for the unconfirmed re-extract
// offer (message 7218, @bonakdarjavan, status needs_review, NO autopilot
// block, NO step trace). The offer row's Product ID IS _____________________167421,
// but the case object never carried it: molavi_domain_ai_case maps no
// 'Product ID' field, so ensureProduct could only match on offer_id — and
// the re-extract offer_id differs from the product's latest_offer_id
// (the owner-confirm ID), so a second entry was created (key
// offer:<reextract id>) with status null. No JS-only fix can match on a key
// the payload does not carry, so the fix exposes it: the case now carries
// its offer row's Product ID (only when non-empty), and ensureProduct's
// existing product_id lookup merges the case into its product. The JS merge
// behaviour itself is proven by tests/duplicate_card_merge.cjs.
// Run: php tests/native_duplicate_card_merge.php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-dup-card-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-reference-store','domain-products','domain-publisher','domain-product-derivation','domain-sheet-conflicts',
    'domain-reference-matching','domain-reference-reviews','domain-publication-recovery','domain-ai-review-model','domain-ai-confirmations'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();foreach(['pipeline','reference'] as $store){$db=new SQLite3($root.'/'.$store.'.sqlite3');$db->close();}
$count=0;$fail=0;
function dup_check($actual,$expected,$label){global $count,$fail;if(molavi_domain_json($actual)!==molavi_domain_json($expected)){echo 'FAIL '.$label.' actual='.molavi_domain_json($actual).' expected='.molavi_domain_json($expected)."\n";$fail++;return;}$count++;}
function dup_true($actual,$label){dup_check($actual,true,$label);}
$clock=molavi_domain_timestamp('2026-11-19T00:00:00+00:00');
$beerOfferId='reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab:7218:0';
$beerProductId='_____________________167421';
// The live beer re-extract offer: message 7218, @bonakdarjavan, imported
// 2026-11-18, status needs_review, offer Product ID _____________________167421.
$beerOffer=['Offer ID'=>$beerOfferId,'Product ID'=>$beerProductId,'Product Name'=>'آب جو بلک لیبل،۱۲ عددی','Channel Username'=>'bonakdarjavan','Channel'=>'bonakdarjavan',
    'Batch ID'=>'reextract:50115195ed438942d3490cb3e0011d64','Message ID'=>'7218','Message Timestamp'=>'2026-11-18T00:00:00+00:00',
    'Source Post Created At'=>'2026-11-18T00:00:00+00:00','Source Post Updated At'=>'2026-11-18T00:00:00+00:00','Source Timestamp Basis'=>'supplier_channel_update',
    'Source Post URL'=>'https://t.me/bonakdarjavan/7218','Original Message'=>'آب جو بلک لیبل ۱۲ عددی','Sale Price'=>450000,'Package Count'=>12,
    'Status'=>'needs_review','Review Reason'=>'price_changed','Stock Status'=>'Unknown','Currency'=>'IRT','Price Type'=>'unit'];
$beerCase=molavi_domain_ai_case($beerOffer,null,$clock);
dup_true($beerCase!==null,'beer re-extract offer builds a reviewable case');
dup_check($beerCase['product_id']??null,$beerProductId,'beer re-extract case carries the offer Product ID');
dup_check($beerCase['offer_id']??null,$beerOfferId,'beer re-extract case keeps its re-extract offer_id');
dup_check($beerCase['case_id']??null,'ai:'.hash('sha256',$beerOfferId),'beer re-extract case_id is still sha256(offer_id)');
dup_check($beerCase['product_name']??null,'آب جو بلک لیبل،۱۲ عددی','beer re-extract case keeps its product name');
// A fresh offer with no Product ID: the case shape is exactly the old one —
// no product_id key appears, so the existing parity fixtures stay valid.
$freshOffer=array_replace($beerOffer,['Offer ID'=>'fresh-offer-1','Product ID'=>'','Batch ID'=>'batch-fresh','Message ID'=>'9001',
    'Message Timestamp'=>'2026-11-19T00:00:00+00:00','Source Post Created At'=>'2026-11-19T00:00:00+00:00','Source Post Updated At'=>'2026-11-19T00:00:00+00:00']);
$freshCase=molavi_domain_ai_case($freshOffer,null,$clock);
dup_true($freshCase!==null,'fresh offer builds a reviewable case');
dup_true(!isset($freshCase['product_id']),'fresh offer case carries no product_id key (old shape kept)');
// A new message for an existing product (not a re-extract): the key is
// carried too, so the case merges into its product the same way.
$newMessageOffer=array_replace($beerOffer,['Offer ID'=>'offer-new-message-1','Batch ID'=>'batch-new','Message ID'=>'7219',
    'Message Timestamp'=>'2026-11-19T00:00:00+00:00','Source Post Created At'=>'2026-11-19T00:00:00+00:00','Source Post Updated At'=>'2026-11-19T00:00:00+00:00']);
$newMessageCase=molavi_domain_ai_case($newMessageOffer,null,$clock);
dup_true($newMessageCase!==null,'new-message offer builds a reviewable case');
dup_check($newMessageCase['product_id']??null,$beerProductId,'new-message case carries the offer Product ID');
// Control: reviewability is unchanged — a reference-only reason still
// builds no case.
$refOnlyOffer=array_replace($beerOffer,['Offer ID'=>'ref-only-1','Review Reason'=>'reference: no_compatible_reference']);
dup_check(molavi_domain_ai_case($refOnlyOffer,null,$clock),null,'reference-only reason still builds no case');
// The case-diff parity guard: product_id is skipped (a WP-side join key,
// not extraction content), and an absent key compares equal to ''.
$confirmationsSrc=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-ai-confirmations.php');
dup_true(str_contains($confirmationsSrc,"'product_id' => true"),'case-diff skip list contains product_id');
dup_check(molavi_domain_ai_confirmations_norm(null),molavi_domain_ai_confirmations_norm(''),'case-diff norm: absent key equals empty string');
dup_check(molavi_domain_ai_confirmations_norm($beerProductId),$beerProductId,'case-diff norm: a real Product ID passes through unchanged');
$modelSrc=file_get_contents(__DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-ai-review-model.php');
dup_true(str_contains($modelSrc,"\$offer['Product ID']"),'case builder reads the offer Product ID');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);
if($fail){echo "FAIL native duplicate-card merge $fail checks\n";exit(1);}
echo 'PASS native duplicate-card merge '.$count." checks\n";
