<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-order-parity-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-inventory','domain-orders','domain-order-workflows','domain-publisher','domain-order-catalog'] as $file)
 require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
require __DIR__.'/../shop-wp/molavi-shop/includes/pricing.php';
$root=molavi_state_root();$initial=new SQLite3($root.'/pipeline.sqlite3');$initial->close();$count=0;
function same_order($actual,$expected,$name){global $count;if(molavi_domain_json($actual)!==molavi_domain_json($expected))throw new RuntimeException($name.' '.molavi_domain_json(['actual'=>$actual,'expected'=>$expected]));$count++;}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/native-order-workflows-parity.json'),true,256,JSON_THROW_ON_ERROR);
foreach($cases as $index=>$case){$db=molavi_domain_db();
 foreach(molavi_domain_rows($db,"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'molavi_state_%'") as $row)$db->exec('DELETE FROM '.$row['name']);
 foreach($case['before'] as $table=>$data)foreach($data['rows'] as $row)molavi_domain_sql($db,'INSERT INTO '.$table.' ('.implode(',',$data['columns']).') VALUES ('.implode(',',array_fill(0,count($row),'?')).')',$row);$db->close();
 $a=$case['args'];try{$actual=molavi_domain_order_workflow_apply($a['order'],$a['plan'],$a['action'],$a['details'],$a['actor_id'],$a['note'],$a['today']);}catch(MolaviWorkflowError $e){$actual=['error'=>true];}
 same_order($actual,$case['expected'],$index.'/'.$a['action']);
 $db=molavi_domain_db();foreach($case['after'] as $table=>$data){$rows=molavi_domain_rows($db,'SELECT * FROM '.$table.' ORDER BY rowid');$rows=array_map('array_values',$rows);
  same_order($rows,$data['rows'],$index.'/state/'.$table);}$db->close();
}
$catalog=json_decode(file_get_contents(__DIR__.'/fixtures/native-order-catalog-parity.json'),true,128,JSON_THROW_ON_ERROR);
foreach($catalog['catalogs'] as $index=>$case)same_order(molavi_domain_order_catalog($case['tables']),$case['expected'],'order catalog/'.$index);
foreach($catalog['suggestions'] as $index=>$case){$a=$case['args'];same_order(molavi_domain_owned_substitutes($a['target_line'],$a['shortfall'],$a['products'],$a['lots'],$a['excluded_skus'],$a['limit']),$case['expected'],'owned substitutes/'.$index);}
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);echo 'PASS native order workflows '.$count." cases\n";
