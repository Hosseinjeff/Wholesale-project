<?php
/** Two-price model for the Rezvan catalog: the Store API `prices` object
 *  carries price / regular_price / sale_price, and the reference row keeps
 *  an explicit supply price and regular price in five new trailing columns
 *  (schema_version 2, 26 columns). Run: php tests/native_catalog_two_price.php */
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_BRIDGE_TOKEN',str_repeat('s',40));
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-catalog-two-price-'.bin2hex(random_bytes(6)));
define('MOLAVI_REFERENCE_OWNER_IDS',[7]);$GLOBALS['options']=['molavi_state_active'=>true];
function add_action(...$args){}function add_filter(...$args){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
foreach(['state-host','domain-core','domain-schemas','domain-read-models','domain-reference-parser','domain-reference-store','domain-gateway'] as $file)
 require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
$root=molavi_state_root();$initial=new SQLite3($root.'/reference.sqlite3');$initial->close();
$count=0;function check($actual,$expected,string $name):void{global $count;
 if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($name.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
function reset_reference():void{$db=molavi_domain_db('reference');foreach(molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%'") as $row)$db->exec('DELETE FROM '.$row['name']);$db->close();}
function two_price_product(int $id,string $sku,string $name,$price,$regular,$sale,string $currency='IRT',int $minor=0):array{
    return ['id'=>$id,'sku'=>$sku,'name'=>$name,'categories'=>[['id'=>12,'name'=>'نوشیدنی']],'attributes'=>[],
        'prices'=>['price'=>$price,'regular_price'=>$regular,'sale_price'=>$sale,'currency_code'=>$currency,'currency_minor_unit'=>$minor]];
}
$tea='چای نمونه ۱۲ عددی (۴۵ف) ۲۵۰گرم ۵۰۰میل';$time='2026-11-17T00:00:00+00:00';
function tail(array $row):array{return array_slice($row,21,5);}
function head(array $row):array{return [$row[3],$row[4],$row[5]];}

// On sale: supply is the sale price, regular is the regular price.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'480000','500000','480000'),1,$time);
check(head($row),[480000,40000,45000],'on-sale legacy columns unchanged');
check(tail($row),[480000,40000,500000,41667,''],'on-sale supply and regular');
check(count($row),26,'on-sale row is 26 columns');
// Single price (sale empty): regular comes from elsewhere, never copied.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'500000','500000',''),1,$time);
check(tail($row),[500000,41667,'','',''],'single-price regular stays null');
// Single price (sale equal to regular and price): no sale at all.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'480000','480000','480000'),1,$time);
check(tail($row),[480000,40000,'','',''],'sale equal to regular is a single price');
// Single price with no regular/sale fields at all.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'480000',null,null),1,$time);
check(tail($row),[480000,40000,'','',''],'no regular or sale is a single price');
// Anomaly: sale at/above regular is flagged, supply falls back to price.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'520000','500000','520000'),1,$time);
check(tail($row),[520000,43333,'','','sale_not_below_regular'],'sale above regular flags and falls back');
// Anomaly: effective price equals neither sale nor regular.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'492000','500000','480000'),1,$time);
check(tail($row),[492000,41000,'','','price_mismatch'],'price equal to neither flags and falls back');
// Anomaly: effective price is the regular price while a lower sale is claimed.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'500000','500000','480000'),1,$time);
check(tail($row),[500000,41667,'','','price_mismatch'],'price is regular but sale claimed flags');
// Per-unit derivation is independent per price; no count means no unit.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0','روغن ۱/۵لیتری ۶ع','480000','500000','480000'),1,$time);
check(tail($row),[480000,80000,500000,83333,''],'six-pack units round per price');
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0','پنیر 1⁄8192 کیلوگرم','480000','500000','480000'),1,$time);
check(tail($row),[480000,'',500000,'',''],'no pack count leaves both units empty');
// Minor-unit / IRR conversion applies to all three fields alike.
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'48000000','50000000','48000000','IRR',2),1,$time);
check(tail($row),[48000,4000,50000,4167,''],'IRR minor-unit conversion on all prices');
// Malformed regular/sale fail soft: null, logged, never a hard fail.
// (A malformed value is logged, not flagged: with price == sale the row
// is a single price whose regular price is simply unknown to the API.)
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'480000','not-a-price','480000'),1,$time);
check(tail($row),[480000,40000,'','',''],'malformed regular is soft null');
$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'480000','500000',''),1,$time);
check(tail($row),[480000,40000,'','','price_mismatch'],'empty sale with price below regular flags');
// The effective price still hard-fails exactly as before.
foreach([['missing',null,'woocommerce_prices_missing'],['invalid','abc','woocommerce_price_invalid'],
    ['zero','0','woocommerce_price_invalid'],['negative','-5','woocommerce_price_invalid']] as [$label,$price,$code]){
    try{$row=molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,$price,'500000','480000'),1,$time);$error='no-fail';}
    catch(MolaviWorkflowError $e){$error=$e->getMessage();}
    check($error,$code,'hard fail on '.$label.' effective price');
}
try{molavi_domain_reference_row(two_price_product(1,'SYNTH-0',$tea,'480000','500000','480000'),1,$time);
    $prices=['price'=>'480000','regular_price'=>'500000','sale_price'=>'480000','currency_code'=>'IRT','currency_minor_unit'=>0];
    check(molavi_domain_reference_price($prices),[480000,'IRT'],'fetch path still reads price and currency');}
catch(MolaviWorkflowError $e){throw new RuntimeException('fetch path broke: '.$e->getMessage());}

// Store: a two-price candidate stages with the new headers and schema.
reset_reference();
$products=[two_price_product(86163,'TEA-12',$tea,'480000','500000','480000'),
    two_price_product(86164,'SYNTH-2','روغن نمونه ۶ عددی ۱/۵لیتری','480000','500000','480000')];
$candidate=molavi_domain_reference_candidate($products,1,'IRT','2026-11-16T01:00:00+00:00');
check($candidate['schema_version'],2,'candidate schema_version is 2');
check(count($candidate['headers']),26,'candidate headers are 26 columns');
check(array_slice($candidate['headers'],21,5),['Reference Supply Pack Price','Reference Supply Unit Price','Reference Regular Pack Price','Reference Regular Unit Price','Reference Price Anomaly'],'new header names');
$staged=molavi_domain_reference_stage($candidate);
check($staged['state'],'staged','two-price candidate stages');
check($staged['change_summary']['added_count'],2,'two-price candidate adds two products');
$payload=molavi_domain_decode(molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$staged['snapshot_id']])['payload']));
check($payload['schema_version'],2,'staged payload schema_version is 2');
check(count($payload['rows'][0]),26,'staged rows are 26 columns');
check(tail($payload['rows'][0]),[480000,40000,500000,41667,''],'staged on-sale row keeps both prices');
check(tail($payload['rows'][1]),[480000,80000,500000,83333,''],'staged six-pack row keeps both prices');
molavi_domain_reference_review($staged['snapshot_id'],'wp:7','Synthetic review');
molavi_domain_reference_activate($staged['snapshot_id'],null,'wp:7');
check(molavi_domain_reference_active()['snapshot_id'],$staged['snapshot_id'],'two-price snapshot activates');
// A regular-price-only change is a prices change and reconciles.
$changedProducts=[two_price_product(86163,'TEA-12',$tea,'480000','520000','480000'),
    two_price_product(86164,'SYNTH-2','روغن نمونه ۶ عددی ۱/۵لیتری','480000','500000','480000')];
$second=molavi_domain_reference_stage(molavi_domain_reference_candidate($changedProducts,1,'IRT','2026-11-16T03:00:00+00:00'));
check($second['state'],'staged','regular-only change stages');
check($second['change_summary']['changed'],[['source_product_id'=>'86163','old_product_code'=>'TEA-12','product_code'=>'TEA-12','nodes'=>['prices'],'price_changed'=>true]],'regular-only change is a prices change');
check($second['change_summary']['reconcile_reference_ids'],['TEA-12'],'regular-only change reconciles');
$payload2=molavi_domain_decode(molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$second['snapshot_id']])['payload']));
check(tail($payload2['rows'][0]),[480000,40000,520000,43333,''],'merged row carries the new regular price');
// Schema v1 rows upgrade in place instead of re-adding every product.
reset_reference();
$v1=$payload;$v1['schema_version']=1;$v1['headers']=array_slice($v1['headers'],0,21);
$v1['rows']=array_map(static fn(array $r)=>array_slice($r,0,21),$v1['rows']);
$v1id=hash('sha256',molavi_domain_json($v1,true));
molavi_domain_tx('reference',static function(SQLite3 $db)use($v1,$v1id,$products):void{
    molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_snapshots VALUES (?,?,NULL,NULL,NULL)',[$v1id,molavi_domain_json($v1,true)]);
    molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_active VALUES (1,?)',[$v1id]);
    molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_activations VALUES (?,?,?,?,?)',[1,$v1id,null,'wp:7','2026-11-17T08:36:59.519349+00:00']);
    foreach($products as $product)molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_source_products VALUES (?,?,?)',[(string)$product['id'],molavi_domain_reference_code($product),molavi_domain_json($product,true)]);
    molavi_domain_sql($db,'INSERT OR REPLACE INTO reference_source_check VALUES (?,?,?)',[1,$v1id,'2026-11-16T01:00:00+00:00']);
});
$upgraded=molavi_domain_reference_stage(molavi_domain_reference_candidate($products,1,'IRT','2026-11-16T08:00:00+00:00'));
check($upgraded['state'],'staged','v1 snapshot upgrades instead of staying unchanged');
check($upgraded['change_summary']['metadata_upgrade'],true,'v1 upgrade is a metadata upgrade');
check($upgraded['change_summary']['added_count'],0,'v1 upgrade adds nothing');
check($upgraded['change_summary']['updated_count'],0,'v1 upgrade updates nothing');
check($upgraded['automatic_update'],true,'v1 upgrade activates automatically');
$payload3=molavi_domain_decode(molavi_domain_read('reference',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT payload FROM reference_snapshots WHERE snapshot_id=?',[$upgraded['snapshot_id']])['payload']));
check(tail($payload3['rows'][0]),[480000,40000,500000,41667,''],'upgraded rows carry the two-price columns');
molavi_domain_reference_activate($upgraded['snapshot_id'],$v1id,'system:reference-refresh',true);
check(molavi_domain_reference_active()['snapshot_id'],$upgraded['snapshot_id'],'upgraded snapshot activates');
echo 'PASS native catalog two price '.$count." cases\n";
