<?php
/** Plugin Name: Molavi Native Workflow Connectors */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_connector_permission($request){
    if(!defined('MOLAVI_REFERENCE_BRIDGE_TOKEN'))return new WP_Error('unconfigured','Connector unavailable',['status'=>503]);
    return hash_equals(hash_hmac('sha256','molavi-connectors-v1',MOLAVI_REFERENCE_BRIDGE_TOKEN),(string)$request->get_header('x-molavi-connector-token'))
        ?true:new WP_Error('forbidden','Access denied',['status'=>403]);
}
function molavi_domain_config():array {
    $path=molavi_state_root().'/connectors.json';
    if(!is_file($path))molavi_domain_fail('native_connectors_unconfigured',503);
    $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($data)||!preg_match('/^[A-Za-z0-9_-]{10,200}$/',(string)($data['sheet_id']??'')))molavi_domain_fail('native_connectors_invalid',503);
    return $data;
}
/** The staging exception applies only while this exact native request is running. */
function molavi_domain_http(string $method,string $url,array $args=[]):array {
    $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));$path=(string)wp_parse_url($url,PHP_URL_PATH);
    $allowed=wp_parse_url($url,PHP_URL_SCHEME)==='https'&&(
        ($host==='oauth2.googleapis.com'&&$path==='/token'&&$method==='POST')
        ||($host==='sheets.googleapis.com'&&str_starts_with($path,'/v4/spreadsheets/'))
        ||($url==='https://api.tavily.com/search'&&$method==='POST')
        ||($url==='https://openapi.basalam.com/v1/products/search'&&$method==='POST')
        ||($host==='openapi.basalam.com'&&$method==='GET'&&preg_match('~^/v1/products/[1-9][0-9]{0,11}$~D',$path))
        ||($host==='wholesale-project-staging-staging.up.railway.app'&&$method==='GET'&&str_starts_with($path,'/media/file/'))
        ||($host==='wholesale-project-production.up.railway.app'&&$method==='GET'&&str_starts_with($path,'/media/file/'))
        // Supplier link checks (round eight): re-read a supplier's public
        // Telegram post live. Channel page or single post only; no other
        // path, no other method. The embed page carries the current post
        // text — exactly the signal a no-change check needs.
        ||($host==='t.me'&&$method==='GET'&&preg_match('~^/[A-Za-z0-9_]{5,64}(/[0-9]{1,12})?$~D',$path))
        ||(function_exists('molavi_domain_reference_source')&&$host===wp_parse_url(molavi_domain_reference_source(),PHP_URL_HOST)&&$method==='GET'&&str_starts_with($path,'/wp-json/wc/store/v1/')));
    if(!$allowed)molavi_domain_fail('native_connector_destination_rejected',400);
    if(function_exists('home_url')&&strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))==='staging.molavisale.ir'
        &&$host==='wholesale-project-production.up.railway.app')molavi_domain_fail('staging_production_destination_rejected',400);
    return molavi_domain_http_exact($method,$url,$args);
}
function molavi_domain_http_exact(string $method,string $url,array $args=[]): array {
    $exception=static function($pre,$parsed,$requestUrl)use($url,$method){
        if($requestUrl===$url&&strtoupper((string)($parsed['method']??''))===$method&&is_wp_error($pre)&&$pre->get_error_code()==='molavi_staging_external_blocked')return false;return $pre;
    };
    add_filter('pre_http_request',$exception,PHP_INT_MAX,3);
    try{$response=wp_safe_remote_request($url,array_replace(['method'=>$method,'timeout'=>30,'redirection'=>0],$args));}
    finally{remove_filter('pre_http_request',$exception,PHP_INT_MAX);}
    if(is_wp_error($response)){
        $details=['transport_error'=>sanitize_key((string)$response->get_error_code())];
        if(preg_match('/cURL error (\d{1,3}):/i',$response->get_error_message(),$match))$details['curl_error']=(int)$match[1];
        molavi_domain_fail('native_connector_unavailable',503,$details);
    }
    return $response;
}
function molavi_domain_google_token():string {
    $config=molavi_domain_config();$account=$config['google_service_account']??[];
    if(!is_array($account)||empty($account['client_email'])||empty($account['private_key'])||!function_exists('openssl_sign'))molavi_domain_fail('google_service_account_unavailable',503);
    $cache='molavi_google_'.substr(hash('sha256',(string)$account['private_key']),0,24);$token=get_transient($cache);
    if(is_string($token)&&$token!=='')return $token;
    $encode=static fn(string $s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');$now=time()-30;
    $jwt=$encode(molavi_domain_json(['alg'=>'RS256','typ'=>'JWT'])).'.'.$encode(molavi_domain_json([
        'iss'=>$account['client_email'],'scope'=>'https://www.googleapis.com/auth/spreadsheets','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600]));
    $signature='';if(!openssl_sign($jwt,$signature,$account['private_key'],OPENSSL_ALGO_SHA256))molavi_domain_fail('google_signature_failed',503);
    $response=molavi_domain_http('POST','https://oauth2.googleapis.com/token',['body'=>['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt.'.'.$encode($signature)]]);
    $body=json_decode(wp_remote_retrieve_body($response),true);
    if(wp_remote_retrieve_response_code($response)!==200||!is_string($body['access_token']??null))molavi_domain_fail('google_authorization_failed',503);
    set_transient($cache,$body['access_token'],max(60,min(3300,(int)($body['expires_in']??3600)-120)));return $body['access_token'];
}
function molavi_domain_google(string $method,string $path,?array $body=null):array {
    $config=molavi_domain_config();$url='https://sheets.googleapis.com/v4/spreadsheets/'.$config['sheet_id'].$path;
    $args=['headers'=>['Authorization'=>'Bearer '.molavi_domain_google_token(),'Content-Type'=>'application/json']];
    if($body!==null)$args['body']=molavi_domain_json($body,false,false,false);
    $response=molavi_domain_http($method,$url,$args);$status=wp_remote_retrieve_response_code($response);$data=json_decode(wp_remote_retrieve_body($response),true);
    if($status<200||$status>=300||!is_array($data))molavi_domain_fail('google_sheet_operation_failed',503,['upstream_status'=>$status]);return $data;
}
function molavi_domain_sheet_name(string $name):string {
    if(!preg_match('/^[A-Za-z][A-Za-z0-9_ ]{0,60}$/',$name))molavi_domain_fail('invalid_sheet_name',400);return "'".str_replace("'","''",$name)."'";
}
function molavi_domain_sheet_cache_key(string $name,bool $unformatted=false):string {
    return 'molavi_sheet_'.hash('sha256',molavi_domain_config()['sheet_id'].'|'.$name.'|'.($unformatted?'raw':'formatted'));
}
function molavi_domain_sheet_invalidate(string $name):void {
    foreach([false,true] as $raw)delete_transient(molavi_domain_sheet_cache_key($name,$raw));
    if(in_array($name,['Products','Offers'],true) && function_exists('molavi_owner_dashboard_cache_clear')) molavi_owner_dashboard_cache_clear();
}
function molavi_domain_dashboard_snapshot(callable $read): array {
    // One Sheets snapshot serves the owner-only group of reads in this request.
    $GLOBALS['molavi_dashboard_sheets']=molavi_domain_sheets(['Products','Offers'],true);
    try { return $read(); }
    finally { unset($GLOBALS['molavi_dashboard_sheets']); }
}
function molavi_domain_sheets(array $names,bool $fresh=false,bool $unformatted=false):array {
    $snapshot=$GLOBALS['molavi_dashboard_sheets']??null;
    if(!$unformatted && is_array($snapshot) && !array_diff($names,array_keys($snapshot))) {
        return array_intersect_key($snapshot,array_fill_keys($names,true));
    }
    $out=[];$missing=[];
    foreach($names as $name){$key=molavi_domain_sheet_cache_key($name,$unformatted);$cached=$fresh?false:get_transient($key);
        if(is_array($cached))$out[$name]=$cached;else $missing[]=$name;}
    if($missing){$query=implode('&',array_map(static fn($name)=>'ranges='.rawurlencode(molavi_domain_sheet_name($name)),$missing));
        $result=molavi_domain_google('GET','/values:batchGet?'.$query.'&valueRenderOption='.($unformatted?'UNFORMATTED_VALUE':'FORMATTED_VALUE'));
        foreach($missing as $index=>$name){$rows=$result['valueRanges'][$index]['values']??[];if(!is_array($rows))molavi_domain_fail('invalid_sheet_response',503);
            $out[$name]=$rows;set_transient(molavi_domain_sheet_cache_key($name,$unformatted),$rows,5);}}
    return $out;
}
function molavi_domain_sheet_column(int $number):string {$out='';while($number>0){$number--;$out=chr(65+$number%26).$out;$number=intdiv($number,26);}return $out;}
function molavi_domain_sheet_cells(string $name,array $updates):void {
    $data=[];foreach($updates as $update)$data[]=['range'=>molavi_domain_sheet_name($name).'!'.$update['range'],'values'=>$update['values']];
    if($data)molavi_domain_google('POST','/values:batchUpdate',['valueInputOption'=>'RAW','data'=>$data]);
    molavi_domain_sheet_invalidate($name);
}
function molavi_domain_sheet_fields(string $name,int $row,array $headers,array $values):void {
    $updates=[];foreach($values as $field=>$value){$column=array_search($field,$headers,true);if($column===false)molavi_domain_fail('sheet_header_missing',409,['field'=>$field]);
        $updates[]=['range'=>molavi_domain_sheet_column($column+1).$row,'values'=>[[$value]]];}molavi_domain_sheet_cells($name,$updates);
}
function molavi_domain_sheet_append(string $name,array $rows):array {
    $result=molavi_domain_google('POST','/values/'.rawurlencode(molavi_domain_sheet_name($name).'!A1').':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS',['values'=>$rows]);
    molavi_domain_sheet_invalidate($name);return $result;
}
function molavi_domain_sheet_properties(bool $fresh=false): array {
    $key='molavi_sheet_meta_'.hash('sha256',molavi_domain_config()['sheet_id']);$cached=$fresh?false:get_transient($key);
    if(is_array($cached))return $cached;$data=molavi_domain_google('GET','?fields=sheets.properties');$properties=[];
    foreach($data['sheets']??[] as $sheet){$props=$sheet['properties']??[];if(isset($props['title'],$props['sheetId']))$properties[(string)$props['title']]=$props;}
    set_transient($key,$properties,60);return $properties;
}
function molavi_domain_sheet_ensure(string $name,array $headers): void {
    $properties=molavi_domain_sheet_properties();
    if(!isset($properties[$name])){molavi_domain_google('POST',':batchUpdate',['requests'=>[['addSheet'=>['properties'=>['title'=>$name]]]]]);molavi_domain_sheet_properties(true);}
    $rows=molavi_domain_sheets([$name],true)[$name];$current=$rows[0]??[];$next=array_map('strval',$current);
    foreach($headers as $header)if(!in_array($header,$next,true))$next[]=$header;
    if($next!==$current||!$current)molavi_domain_sheet_cells($name,[['range'=>'A1:'.molavi_domain_sheet_column(count($next)).'1','values'=>[$next]]]);
}
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/connectors/check',['methods'=>'GET','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function(){try{$tables=molavi_domain_sheets(['Products','Offers','Shop','ReferencePrices'],true);$summary=[];
            foreach($tables as $name=>$rows)$summary[$name]=['rows'=>max(0,count($rows)-1),'columns'=>count($rows[0]??[])];
            return ['ok'=>true,'connector'=>'google_sheets','tables'=>$summary];
        }catch(MolaviWorkflowError $e){return new WP_Error($e->getMessage(),'Connector verification failed',['status'=>$e->status]);}
        catch(Throwable $e){return new WP_Error('connector_check_failed','Connector verification failed',['status'=>503]);}}]);
    register_rest_route('molavi-workflow/v1','/connectors',['methods'=>'POST','permission_callback'=>'molavi_domain_connector_permission',
        'callback'=>static function($request){try{$body=$request->get_json_params();
            if(!is_array($body)||!preg_match('/^[A-Za-z0-9_-]{10,200}$/',(string)($body['sheet_id']??''))||!is_array($body['google_service_account']??null)
                ||($body['google_service_account']['type']??'')!=='service_account')molavi_domain_fail('invalid_connector_configuration',400);
            $path=molavi_state_root().'/connectors.json';$temporary=$path.'.'.bin2hex(random_bytes(6));
            $encoded=molavi_domain_json($body);
            if(file_put_contents($temporary,$encoded,LOCK_EX)!==strlen($encoded)||!chmod($temporary,0600)||!rename($temporary,$path))
                molavi_domain_fail('connector_private_storage_unavailable',503);
            return ['ok'=>true,'configured'=>['google_sheets'],'configuration_digest'=>hash_file('sha256',$path)];
        }catch(MolaviWorkflowError $e){return new WP_Error($e->getMessage(),'Connector setup failed',['status'=>$e->status]);}
        catch(Throwable $e){return new WP_Error('configuration_failed','Connector setup failed',['status'=>503]);}}]);
});
