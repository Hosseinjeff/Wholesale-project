<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');define('MOLAVI_STATE_ROOT',sys_get_temp_dir().'/molavi-reconciliation-'.bin2hex(random_bytes(6)));
function add_action(...$args){}function add_filter(...$args){}
foreach(['state-host','domain-core','domain-schemas','domain-reference-parser','domain-products','domain-publisher','domain-publication-recovery','domain-reconciliation'] as $file)require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-'.$file.'.php';
function canonical_report($r){foreach($r['receipts'] as &$row){usort($row['product_details'],static fn($a,$b)=>strcmp($a['product_id'],$b['product_id']));sort($row['woo_states'],SORT_STRING);}unset($row);return $r;}
$count=0;function same_report($a,$b,$name){global $count;if(molavi_domain_json($a)!==molavi_domain_json($b))throw new RuntimeException($name.' '.molavi_domain_json(['actual'=>$a,'expected'=>$b]));$count++;}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/native-reconciliation-parity.json'),true,256,JSON_THROW_ON_ERROR);
foreach($cases['reports'] as $index=>$case){$result=molavi_domain_reconciliation_render($case['receipt_data'],$case['tables'],$case['projection'],$case['issues'],$case['woo'],$case['limit'],$case['expected']['generated_at']);same_report(canonical_report($result),canonical_report($case['expected']),'reconciliation/'.$index);}
$root=molavi_state_root();$initial=new SQLite3($root.'/pipeline.sqlite3');$initial->close();
foreach($cases['receipts'] as $index=>$case){$db=molavi_domain_db();$db->exec('DELETE FROM jobs');$db->exec('DELETE FROM inbox');foreach($case['seed'] as $step)molavi_domain_sql($db,$step['sql'],$step['params']);$db->close();same_report(molavi_domain_json(molavi_domain_receipt_data($case['limit'])),$case['expected_json'],'receipt join/'.$index);}
class WP_REST_Request {public array $params=[];function __construct(public $method,public $route){}function set_query_params($params){$this->params=$params;}}
class SyntheticStoreResponse {function __construct(public $status,public $rows,public $pages=1){}function get_status(){return $this->status;}function get_data(){return $this->rows;}function get_headers(){return ['X-WP-TotalPages'=>$this->pages];}}
function is_wp_error($value){return $value instanceof RuntimeException;}
function rest_do_request($request){$GLOBALS['store_calls'][]=$request->params;return $GLOBALS['store_pages'][$request->params['page']]??new SyntheticStoreResponse(503,[]);}
function molavi_catalog_identity_snapshot(){return [['sku'=>'synthetic-sku','published'=>true,'purchasable'=>true]];}
$GLOBALS['store_calls']=[];$GLOBALS['store_pages']=[1=>new SyntheticStoreResponse(200,[['sku'=>'synthetic-sku','is_purchasable'=>true,'is_in_stock'=>true]],2),2=>new SyntheticStoreResponse(200,[],2)];$catalog=molavi_domain_woo_catalog('2026-11-17T00:00:00+00:00');
same_report($catalog['state'],'checked','in-process Store API succeeds');same_report(count($GLOBALS['store_calls']),2,'Store API pagination stays inside WordPress');same_report($catalog['products']['synthetic-sku']['purchasable'],true,'public visibility fact');same_report(count($catalog['identity_records']),1,'private durable identity read needs no legacy secret');
$GLOBALS['store_pages']=[1=>new SyntheticStoreResponse(503,[])];same_report(molavi_domain_woo_catalog()['state'],'unavailable','controller failure stays partial');
$GLOBALS['store_pages']=[];for($page=1;$page<=10;$page++)$GLOBALS['store_pages'][$page]=new SyntheticStoreResponse(200,[],11);$GLOBALS['store_calls']=[];
same_report(molavi_domain_woo_catalog()['state'],'truncated','catalog ceiling is explicit');same_report(count($GLOBALS['store_calls']),10,'catalog remains bounded to ten pages');
foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);
echo 'PASS native reconciliation '.$count." cases\n";
