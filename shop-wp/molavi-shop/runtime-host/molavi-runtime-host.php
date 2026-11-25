<?php
/** Plugin Name: Molavi Runtime Host
 * Description: WordPress-owned schedules for the sleeping Railway executor. No customer-facing routes.
 */
declare(strict_types=1);
// A plain PHP-file cron command avoids shell expressions and runs only this scheduler.
if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__&&!defined('ABSPATH')){
    $lock=fopen(sys_get_temp_dir().'/molavi-runtime-'.md5(__DIR__).'.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
    define('DISABLE_WP_CRON',true);define('DOING_CRON',true);
    require dirname(__DIR__,2).'/wp-load.php';
    update_option('molavi_runtime_real_cron_at',time(),false);
    molavi_runtime_tick();
    exit;
}
defined('ABSPATH') || exit;

function molavi_runtime_configured(): bool {
    return defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') && is_string(MOLAVI_REFERENCE_BRIDGE_TOKEN)
        && strlen(MOLAVI_REFERENCE_BRIDGE_TOKEN)>=32 && defined('MOLAVI_REFERENCE_SERVER_URL')
        && wp_parse_url((string)MOLAVI_REFERENCE_SERVER_URL,PHP_URL_SCHEME)==='https';
}
function molavi_runtime_permission($request) {
    if(!molavi_runtime_configured())return new WP_Error('runtime_unconfigured','Runtime unavailable',['status'=>503]);
    $expected=hash_hmac('sha256','molavi-runtime-v1',MOLAVI_REFERENCE_BRIDGE_TOKEN);
    return hash_equals($expected,(string)$request->get_header('x-molavi-runtime-token'))
        ? true : new WP_Error('runtime_forbidden','Access denied',['status'=>403]);
}
add_filter('cron_schedules',static function(array $schedules):array {
    $schedules['molavi_runtime_minute']=['interval'=>60,'display'=>'Molavi due work'];return $schedules;
});
add_action('init',static function():void {
    if(molavi_runtime_configured()&&!wp_next_scheduled('molavi_runtime_tick'))
        wp_schedule_event(time()+60,'molavi_runtime_minute','molavi_runtime_tick');
});
function molavi_runtime_state():array {
    $state=get_option('molavi_runtime_host_v1',[]);
    $wake=get_option('molavi_runtime_wake_v1',[]);
    return array_merge(is_array($state)?$state:[],is_array($wake)?$wake:[])+['enabled'=>false,'wake_at'=>null,'sheet_at'=>0,'reference_at'=>0,'housekeeping_at'=>0,'last_tick'=>0,'last_observed'=>0,'last_remote'=>0,'failures'=>0];
}
function molavi_runtime_save_state(array $state):void {
    // Worker callbacks own the wake option; an in-flight cron request must not overwrite their receipt.
    unset($state['wake_at'],$state['last_observed']);
    update_option('molavi_runtime_host_v1',$state,false);
}
function molavi_runtime_save_wake($wake,float $observed):void {
    update_option('molavi_runtime_wake_v1',['wake_at'=>$wake,'last_observed'=>$observed],false);
}
function molavi_runtime_outbox_due():bool {
    global $wpdb;
    $table=$wpdb->prefix.'molavi_workflow_outbox';
    if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return false;
    $native=function_exists('molavi_domain_outbox_domains')?molavi_domain_outbox_domains():[];
    $where=$native?' AND command_type NOT IN ('.implode(',',array_fill(0,count($native),'%s')).')':'';
    return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$table} WHERE state='queued' AND available_at<=%s{$where} LIMIT 1",gmdate('Y-m-d H:i:s'),...$native));
}
function molavi_runtime_staging_http_exception($pre,array $args,string $url) {
    if(!is_wp_error($pre)||$pre->get_error_code()!=='molavi_staging_external_blocked')return $pre;
    if(strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='staging.molavisale.ir'
        &&strtolower((string)wp_parse_url($url,PHP_URL_HOST))==='wholesale-project-staging-staging.up.railway.app'
        &&wp_parse_url($url,PHP_URL_SCHEME)==='https'&&wp_parse_url($url,PHP_URL_PATH)==='/pipeline/runtime'
        &&strtoupper((string)($args['method']??''))==='POST')return false;
    return $pre;
}
function molavi_runtime_tick():void {
    $state=molavi_runtime_state();$now=time();$state['last_tick']=$now;
    molavi_runtime_save_state($state);
    if(!$state['enabled']||!molavi_runtime_configured())return;
    if(!add_option('molavi_runtime_host_lock',$now,'',false)){
        $locked=(int)get_option('molavi_runtime_host_lock');
        if($now-$locked<100)return;delete_option('molavi_runtime_host_lock');
        if(!add_option('molavi_runtime_host_lock',$now,'',false))return;
    }
    try {
        if(function_exists('molavi_reprice_tick') && get_option('molavi_state_active',false)){
            try {molavi_reprice_tick();}
            catch(MolaviWorkflowError $e){if($e->getMessage()!=='workflow_busy_retry')error_log('[molavi] discount_repricing_pending');}
            catch(Throwable $e){error_log('[molavi] discount_repricing_pending');}
        }
        if(function_exists('molavi_domain_drain_outbox')){
            $nativeOutbox=molavi_domain_drain_outbox();
            if($nativeOutbox['claimed']||$nativeOutbox['error'])update_option('molavi_native_outbox_receipt_v1',[
                'at'=>$now,'claimed'=>$nativeOutbox['claimed'],'delivered'=>$nativeOutbox['delivered'],
                'retried'=>$nativeOutbox['retried'],'failed'=>$nativeOutbox['failed'],'error'=>$nativeOutbox['error']],false);
        }
        if(function_exists('molavi_domain_job_host')&&get_option('molavi_state_active',false)
            &&(molavi_domain_job_host('publish')==='wordpress'||molavi_domain_job_host('order')==='wordpress'
                ||molavi_domain_job_host('batch')==='wordpress'||molavi_domain_job_host('reextract')==='wordpress')){
            if (molavi_domain_job_host('publish')==='wordpress'&&function_exists('molavi_domain_sale_offer_evidence')
                &&!get_option('molavi_sale_offer_eligibility_queued',false)) {
                molavi_domain_queue('publish','sale-offer-eligibility-v1',[]);
                update_option('molavi_sale_offer_eligibility_queued',true,false);
            }
            if(molavi_domain_job_host('publish')==='wordpress'&&(int)$state['sheet_at']<=$now){
                try{
                    molavi_domain_observe_projection(molavi_domain_projection_digest(molavi_domain_sheets(['Products','Shop'],true)));
                    $state['sheet_at']=$now+3600;update_option('molavi_native_sheet_receipt_v1',['at'=>$now,'ok'=>true,'error'=>''],false);
                }catch(Throwable $e){$state['sheet_at']=$now+60;
                    update_option('molavi_native_sheet_receipt_v1',['at'=>$now,'ok'=>false,'error'=>'sheet_observation_failed'],false);}
                molavi_runtime_save_state($state);
            }
            if(molavi_domain_job_host('publish')==='wordpress'&&function_exists('molavi_domain_ai_publish_completed_runs')
                &&molavi_domain_route_enabled('POST','/pipeline/ai-confirmations')){
                try{molavi_domain_ai_publish_completed_runs();}catch(Throwable $e){error_log('[molavi-native] reextraction_publication_pending');}
            }
            if(molavi_domain_job_host('batch')==='wordpress'&&function_exists('molavi_domain_batch_inbox')){
                try{molavi_domain_batch_inbox();}catch(Throwable $e){error_log('[molavi-native] ingestion_batch_pending');}
            }
            $nativeJobs=molavi_domain_run_jobs();
            if($nativeJobs['claimed']||$nativeJobs['error'])update_option('molavi_native_jobs_receipt_v1',[
                'at'=>$now,'claimed'=>$nativeJobs['claimed'],'completed'=>$nativeJobs['completed'],'error'=>$nativeJobs['error']],false);
        }
        $tasks=[];
        if((int)$state['sheet_at']<=$now)$tasks[]='sheet_sync';
        if((int)$state['reference_at']<=$now){
            if(function_exists('molavi_domain_route_enabled')&&molavi_domain_route_enabled('POST','/reference/snapshots')){
                try{
                    $refresh=molavi_domain_reference_refresh();$completed=molavi_domain_reference_finish_refresh($refresh);
                    $reconciled=$completed['product_reconciliation_state']??'complete';
                    $ok=!in_array($refresh['state']??null,['failed','running'],true)&&$reconciled==='complete';
                    $wait=$reconciled==='complete'?(int)($refresh['next_check_seconds']??86400):60;$state['reference_at']=$now+max(60,$wait);
                    update_option('molavi_native_reference_receipt_v1',['at'=>$now,'ok'=>$ok,'state'=>$refresh['state']??'unknown',
                        'reconciliation_state'=>$reconciled,'product_reference_updates'=>$completed['product_reference_updates']??null,
                        'error'=>$refresh['error_code']??''],false);
                }catch(Throwable $e){$state['reference_at']=$now+60;
                    update_option('molavi_native_reference_receipt_v1',['at'=>$now,'ok'=>false,'state'=>'retry','error'=>'reference_refresh_incomplete'],false);}
                molavi_runtime_save_state($state);
            }else $tasks[]='reference_refresh';
        }
        if(function_exists('molavi_domain_market_tick')&&get_option('molavi_state_active',false)
            &&molavi_domain_route_enabled('GET','/reference/item-reviews')){
            try{molavi_domain_market_tick($now);}catch(Throwable $e){error_log('[molavi-native] market_price_refresh_pending');}
        }
        if(function_exists('molavi_domain_supplier_link_check_tick')&&get_option('molavi_state_active',false)
            &&molavi_domain_route_enabled('GET','/pipeline/supplier-link-checks')){
            try{molavi_domain_supplier_link_check_tick($now);}catch(Throwable $e){error_log('[molavi-native] supplier_link_check_pending');}
        }
        if(function_exists('molavi_domain_market_agreement_tick')&&get_option('molavi_state_active',false)
            &&molavi_domain_route_enabled('GET','/pipeline/market-agreement')){
            try{molavi_domain_market_agreement_tick($now);}catch(Throwable $e){error_log('[molavi-native] market_agreement_pending');}
        }
        if(function_exists('molavi_domain_autopilot_tick')&&get_option('molavi_state_active',false)){
            try{molavi_domain_autopilot_tick($now);}catch(Throwable $e){error_log('[molavi-native] autopilot_pending');}
        }
        if((int)$state['housekeeping_at']<=$now){
            if(function_exists('molavi_state_housekeeping')&&get_option('molavi_state_active',false)
                &&molavi_state_retention_days()!==null){
                molavi_state_housekeeping($now);$state['housekeeping_at']=$now+86400;molavi_runtime_save_state($state);
            }else $tasks[]='housekeeping';
        }
        if(molavi_runtime_outbox_due())$tasks[]='outbox';
        $wake=($state['wake_at']!==null&&(float)$state['wake_at']<=$now)
            ||(function_exists('molavi_state_due_work')&&molavi_state_due_work());
        if(!$tasks&&!$wake)return;
        if($now-(int)$state['last_remote']<min(900,30*(2**min((int)$state['failures'],5))))return;
        $admins=get_users(['role'=>'administrator','number'=>1,'orderby'=>'ID','order'=>'ASC','fields'=>'ID']);
        $actor='wp:'.(int)($admins[0]??1);
        // Persist the watchdog BEFORE the call; a worker callback can finish before the HTTP response.
        $state['last_remote']=$now;molavi_runtime_save_wake($now+180,(float)$state['last_observed']);
        molavi_runtime_save_state($state);
        add_filter('pre_http_request','molavi_runtime_staging_http_exception',PHP_INT_MAX,3);
        try {$response=wp_safe_remote_post(rtrim(MOLAVI_REFERENCE_SERVER_URL,'/').'/pipeline/runtime',[
            'timeout'=>35,'redirection'=>0,'headers'=>['Content-Type'=>'application/json',
                'X-Molavi-Reference-Token'=>MOLAVI_REFERENCE_BRIDGE_TOKEN,'X-Molavi-Actor'=>$actor],
            'body'=>wp_json_encode(['tasks'=>array_values(array_unique($tasks))])]);
        } finally {remove_filter('pre_http_request','molavi_runtime_staging_http_exception',PHP_INT_MAX);}
        $state=molavi_runtime_state();
        $body=is_wp_error($response)?null:json_decode(wp_remote_retrieve_body($response),true);
        if(!is_wp_error($response)&&wp_remote_retrieve_response_code($response)===202&&($body['ok']??false)===true){
            foreach(['sheet_sync'=>'sheet_at','reference_refresh'=>'reference_at','housekeeping'=>'housekeeping_at'] as $task=>$field)
                if(in_array($task,$tasks,true))$state[$field]=$now+($task==='sheet_sync'?3600:86400);
            $state['failures']=0;$state['last_error']='';$state['last_error_detail']='';
        }else{$state['failures']++;$state['last_error']=is_wp_error($response)?sanitize_key($response->get_error_code()):'http_'.wp_remote_retrieve_response_code($response);$state['last_error_detail']=is_wp_error($response)?substr($response->get_error_message(),0,300):'';}
        molavi_runtime_save_state($state);
    } finally {delete_option('molavi_runtime_host_lock');}
}
add_action('molavi_runtime_tick','molavi_runtime_tick');
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-runtime/v1','/status',['methods'=>'GET','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function(){return ['ok'=>true,'state'=>molavi_runtime_state(),'next_tick'=>wp_next_scheduled('molavi_runtime_tick'),
            'home_host'=>wp_parse_url(home_url('/'),PHP_URL_HOST),'runtime_host'=>wp_parse_url(MOLAVI_REFERENCE_SERVER_URL,PHP_URL_HOST),'php_memory_limit'=>ini_get('memory_limit'),'cron_disabled'=>defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON,'real_cron_at'=>(int)get_option('molavi_runtime_real_cron_at',0),
            'native_capabilities'=>['sqlite3'=>class_exists('SQLite3'),'subprocess'=>function_exists('proc_open'),
                'python_paths'=>array_values(array_filter(['/usr/local/bin/python3','/usr/bin/python3','/opt/alt/python311/bin/python3'],static function($path){return is_executable($path);}))]];}]);
    register_rest_route('molavi-runtime/v1','/configure',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function($request){$body=$request->get_json_params();if(!is_array($body)||!is_bool($body['enabled']??null))return new WP_Error('runtime_config','Invalid configuration',['status'=>400]);
            $state=molavi_runtime_state();$state['enabled']=$body['enabled'];$state['failures']=0;$state['last_remote']=0;$state['last_error']='';
            if($state['enabled']){molavi_runtime_save_wake(time()+60,0.0);$state['sheet_at']=time()+3600;$state['reference_at']=time()+86400;$state['housekeeping_at']=time()+3600;}
            molavi_runtime_save_state($state);return ['ok'=>true,'state'=>$state];}]);
    register_rest_route('molavi-runtime/v1','/schedule',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function($request){$body=$request->get_json_params();
            if(!is_array($body)||!is_numeric($body['observed_at']??null)||!array_key_exists('wake_at',$body)
                ||($body['wake_at']!==null&&!is_numeric($body['wake_at'])))return new WP_Error('runtime_schedule','Invalid schedule',['status'=>400]);
            $state=molavi_runtime_state();$observed=(float)$body['observed_at'];
            if(abs($observed-time())>300)return new WP_Error('runtime_clock','Invalid clock',['status'=>409]);
            if($observed>=(float)$state['last_observed']){molavi_runtime_save_wake($body['wake_at']===null?null:max(time(),min(time()+604800,(float)$body['wake_at'])),$observed);}
            $retention=$body['retention_days']??null;
            if($observed>=(float)$state['last_observed']&&isset($body['bot_capabilities'])&&function_exists('molavi_domain_validate_bot_capabilities')&&molavi_domain_validate_bot_capabilities($body['bot_capabilities']))
                update_option('molavi_bot_capabilities_v1',$body['bot_capabilities'],false);
            if(is_int($retention)&&$retention>=30&&$retention<=365)update_option('molavi_pipeline_payload_retention_days',$retention,false);
            return ['ok'=>true,'wake_at'=>molavi_runtime_state()['wake_at']];}]);
});
