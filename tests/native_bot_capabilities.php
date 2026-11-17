<?php
declare(strict_types=1);define('ABSPATH','/synthetic/');$GLOBALS['caps_options']=[];$GLOBALS['caps_actions']=[];
function get_option($key,$default=null){return $GLOBALS['caps_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['caps_options'][$key]=$value;}
function add_action($name,$callback){$GLOBALS['caps_actions'][$name][]=$callback;}
function register_rest_route($namespace,$route,$args){$GLOBALS['caps_routes'][$route]=$args;}
function molavi_domain_fail($code,$status){throw new RuntimeException($code,$status);}
class WP_Error {function __construct(...$args){}}
class SyntheticCapsRequest {function __construct(public $data){}function get_json_params(){return $this->data;}}
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-domain-bot-capabilities.php';$count=0;
function caps_check($ok,$label){global $count;if(!$ok)throw new RuntimeException($label);$count++;}
$caps=['schema_version'=>1,'ai_configured'=>true,'extraction_policy_version'=>'gemini-'.str_repeat('a',16),'suggestion_policy_version'=>str_repeat('b',64),'image_extraction_enabled'=>false];
caps_check(molavi_domain_validate_bot_capabilities($caps),'bounded policy receipt accepted');
foreach([null,[],array_merge($caps,['token'=>'synthetic-secret']),array_merge($caps,['ai_configured'=>'true']),array_merge($caps,['schema_version'=>2]),array_merge($caps,['suggestion_policy_version'=>'invalid'])] as $invalid)
    caps_check(!molavi_domain_validate_bot_capabilities($invalid),'invalid/extra fields rejected');
try{molavi_domain_bot_capabilities();throw new RuntimeException('missing configuration accepted');}catch(RuntimeException $e){caps_check($e->getMessage()==='bot_capabilities_unavailable','missing receipt fails locally');}
foreach($GLOBALS['caps_actions']['rest_api_init'] as $callback)$callback();$route=$GLOBALS['caps_routes']['/bot-capabilities'];
caps_check($route['permission_callback']==='molavi_runtime_permission','callback uses runtime-scoped authentication');
caps_check($route['callback'](new SyntheticCapsRequest($caps))===['ok'=>true,'schema_version'=>1],'protected callback receipt');
caps_check(molavi_domain_bot_capabilities()===$caps,'read uses local persistent receipt');
caps_check($route['callback'](new SyntheticCapsRequest(['schema_version'=>1])) instanceof WP_Error,'invalid callback refused');
echo 'PASS native bot capabilities '.$count." checks\n";
