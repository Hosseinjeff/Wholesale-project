<?php
declare(strict_types=1);
define('ABSPATH','/synthetic/');define('MOLAVI_REFERENCE_BRIDGE_TOKEN',str_repeat('s',40));define('MOLAVI_REFERENCE_SERVER_URL','https://wholesale-project-staging-staging.up.railway.app');
$GLOBALS['options']=[];$GLOBALS['routes']=[];$GLOBALS['actions']=[];$GLOBALS['calls']=[];
function add_action($name,$callback,$priority=10){$GLOBALS['actions'][$name][]=$callback;}
function add_filter($name,$callback,$priority=10,$accepted=1){add_action($name,$callback);}
function remove_filter($name,$callback,$priority=10){}
function home_url($path='/'){return 'https://staging.molavisale.ir'.$path;}
function sanitize_key($value){return $value;}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function add_option($key,$value,$deprecated='',$autoload=false){if(isset($GLOBALS['options'][$key]))return false;$GLOBALS['options'][$key]=$value;return true;}
function delete_option($key){unset($GLOBALS['options'][$key]);return true;}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function wp_next_scheduled($hook){return null;}function wp_schedule_event($time,$interval,$hook){}
function register_rest_route($namespace,$route,$args){$GLOBALS['routes'][$route]=$args;}
function get_users($args){return [1];}function wp_json_encode($value){return json_encode($value);}
class WP_Error {public $code;public $data;function __construct($code,$message,$data=[]){$this->code=$code;$this->data=$data;}function get_error_code(){return $this->code;}}
function is_wp_error($value){return $value instanceof WP_Error;}
function wp_remote_retrieve_body($response){return json_encode($response['body']);}function wp_remote_retrieve_response_code($response){return $response['status'];}
class FakeDB {public $prefix='wp_';function prepare($sql,...$args){return $sql;}function get_var($sql){return null;}}
$GLOBALS['wpdb']=new FakeDB();
class Request {function __construct(public $body=[],public $token=''){}function get_json_params(){return $this->body;}function get_header($name){return $this->token;}}
function wp_safe_remote_post($url,$args){
 $GLOBALS['calls'][]=[$url,$args];
 // A fast worker completes while cron is waiting for the wake response.
 $callback=$GLOBALS['routes']['/schedule']['callback'];$callback(new Request(['observed_at'=>microtime(true),'wake_at'=>null]));
 return ['status'=>202,'body'=>['ok'=>true]];
}
function check($ok,$name){if(!$ok)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
require __DIR__.'/../shop-wp/molavi-shop/runtime-host/molavi-runtime-host.php';
foreach($GLOBALS['actions']['rest_api_init'] as $callback)$callback();
check(is_wp_error(molavi_runtime_permission(new Request())),'unauthenticated callbacks are rejected');
check(molavi_runtime_permission(new Request([],hash_hmac('sha256','molavi-runtime-v1',MOLAVI_REFERENCE_BRIDGE_TOKEN)))===true,'derived scoped token is accepted');
molavi_runtime_tick();check(count($GLOBALS['calls'])===0,'disabled host never wakes Railway');
$configure=$GLOBALS['routes']['/configure']['callback'];check(is_wp_error($configure(new Request(['enabled'=>'true']))),'configuration requires a boolean');
$configure(new Request(['enabled'=>true]));
$schedule=$GLOBALS['routes']['/schedule']['callback'];$schedule(new Request(['observed_at'=>microtime(true),'wake_at'=>null]));
molavi_runtime_tick();check(count($GLOBALS['calls'])===0,'minute tick stays local when no work is due');
$state=molavi_runtime_state();$state['sheet_at']=time()-1;molavi_runtime_save_state($state);molavi_runtime_tick();
check(count($GLOBALS['calls'])===1&&json_decode($GLOBALS['calls'][0][1]['body'],true)['tasks']===['sheet_sync'],'only due maintenance is requested');
check(molavi_runtime_state()['wake_at']===null,'worker completion is not overwritten by the cron response');
$schedule(new Request(['observed_at'=>time()-30,'wake_at'=>time()+50]));check(molavi_runtime_state()['wake_at']===null,'older callbacks cannot restore an obsolete wake');
molavi_runtime_tick();check(count($GLOBALS['calls'])===1,'completed maintenance does not keep Railway awake');

$blocked=new WP_Error('molavi_staging_external_blocked','blocked');
check(molavi_runtime_staging_http_exception($blocked,['method'=>'POST'],MOLAVI_REFERENCE_SERVER_URL.'/pipeline/runtime')===false,'only the exact staged runtime wake may pass the safety gate');
check(molavi_runtime_staging_http_exception($blocked,['method'=>'GET'],MOLAVI_REFERENCE_SERVER_URL.'/pipeline/runtime')===$blocked,'other HTTP methods stay blocked');
check(molavi_runtime_staging_http_exception($blocked,['method'=>'POST'],'https://wholesale-project-production.up.railway.app/pipeline/runtime')===$blocked,'production requests from staging stay blocked');
check(molavi_runtime_staging_http_exception($blocked,['method'=>'POST'],MOLAVI_REFERENCE_SERVER_URL.'/other')===$blocked,'other runtime routes stay blocked');
