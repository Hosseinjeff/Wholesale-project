<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');
define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-product-price-'.bin2hex(random_bytes(6)));

function add_action(...$args){}
function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-orders','domain-reference-parser','domain-products'] as $part) {
    require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$part.'.php';
}
function price_status_check(bool $result,string $message): void {
    if(!$result)throw new RuntimeException($message);
}

$root=molavi_state_root();
$initial=new SQLite3($root.'/pipeline.sqlite3');
$initial->close();
try {
    $now=time();
    $record=['Product ID'=>'product-1','Product Name'=>'Rani','Latest Offer ID'=>'approved-offer-1',
        'Sale Price'=>'1650000','Currency'=>'IRT','Status'=>'imported','Reference Approval State'=>'owner_approved'];
    $tables=['Products'=>[array_keys($record),array_values($record)],'Offers'=>[['Offer ID'],['approved-offer-1']]];
    $archived=molavi_domain_status_capture($tables,'before_verification',$now)['snapshot_id'];
    $revision=molavi_domain_source_revision($record);
    $verified=gmdate('c',$now-60);
    molavi_domain_tx('pipeline',static fn(SQLite3 $db)=>molavi_domain_sql($db,
        "INSERT INTO supplier_price_evidence VALUES (?,?,?,'manual','wp:7')",['product-1',$revision,$verified]));

    $summary=molavi_domain_current_products($tables,$now)['products'][0];
    $queue=molavi_domain_freshness([$record],$now,false)[0];
    price_status_check($summary['supplier_price_state']==='current' && $queue['state']==='current',
        'a verification tied to the product ID clears both the dashboard and the queue');
    price_status_check($summary['supplier_price_verified_at']===$verified && !$summary['update_needed'],
        'a current price clears the false next action and shows the verification date');

    $latest=molavi_domain_status_api('GET',[],null,'wp:7')['products'][0];
    price_status_check($latest['supplier_price_state']==='current' && !$latest['update_needed'],
        'the dashboard reads the corrected state even if the latest snapshot predates verification');
    $history=molavi_domain_status_api('GET',['snapshot_id'=>$archived],null,'wp:7')['products'][0];
    price_status_check($history['supplier_price_state']==='needs_review' && $history['update_needed'],
        'the original snapshot remains available as recorded');

    $tables['Products'][1][4]='1700000';
    $changed=molavi_domain_current_products($tables,$now)['products'][0];
    price_status_check($changed['supplier_price_state']==='needs_review' && $changed['update_needed'],
        'a changed supplier price needs fresh evidence');
    $expired=molavi_domain_current_products(
        ['Products'=>[array_keys($record),array_values($record)],'Offers'=>$tables['Offers']],$now+15*86400
    )['products'][0];
    price_status_check($expired['supplier_price_state']==='blocked' && $expired['update_needed'],
        'verified evidence still expires normally');
    echo "PASS product price verification and snapshot status\n";
} finally {
    foreach(glob($root.'/*') as $file)unlink($file);
    rmdir($root);
}
