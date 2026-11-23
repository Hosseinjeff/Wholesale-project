<?php
/** Plugin Name: Molavi AI Client */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * WordPress-side AI client. The model itself runs on Railway: the staging
 * host's egress IP is blocked by Google, so WordPress asks the Railway
 * executor for one judgement per pending decision via POST /pipeline/verdict.
 * WordPress keeps the rules, the holds, the verdict records and the verdict
 * runner; Railway only runs the model.
 *
 * Only one caller exists today: the autopilot post-decision verdict
 * (molavi_domain_autopilot_run_verdicts). The model verifies evidence; it
 * never decides, never sets a price, never acts.
 */

/**
 * Ask Railway for a structured JSON verdict. Returns the decoded verdict
 * array ['verdict'=>'ok'|'flag','reason'=>string] on success, null on ANY
 * failure (unconfigured bridge, transport error, non-200, invalid JSON,
 * Railway model failure, verdict outside the enum). Never throws, never
 * logs the bridge token.
 */
function molavi_domain_gemini_generate(string $prompt, array $opts=[]): ?array {
    try {
        if($prompt==='')return null;
        $base=defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
        $token=defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
        if(!is_string($base)||!is_string($token)||strlen($token)<32
            ||wp_parse_url($base,PHP_URL_SCHEME)!=='https')return null;
        // Server-to-server actor, mirroring molavi-runtime-host.php: the
        // first administrator. The browser proxy's get_current_user_id() is
        // 0 in the cron tick and would not authenticate on Railway.
        $admins=get_users(['role'=>'administrator','number'=>1,'orderby'=>'ID','order'=>'ASC','fields'=>'ID']);
        $actor='wp:'.(int)($admins[0]??1);
        $payload=['prompt'=>$prompt];
        $model=(string)($opts['model']??'');
        if($model!==''&&preg_match('/^[A-Za-z0-9._-]+$/D',$model))$payload['model']=$model;
        $body=wp_json_encode($payload);
        if(!is_string($body))return null;
        $url=rtrim($base,'/').'/pipeline/verdict';
        // The staging safety MU-plugin blocks all outbound HTTP except to the
        // site itself. Allow only this client's POST to the Railway verdict
        // route, mirroring the owner proxy's exception: the filter is added
        // at call time (so it runs after the safety filter) and removed
        // right after the request.
        $railwayHttpException=static function($pre,$parsedArgs,$requestUrl) {
            if(!is_wp_error($pre)||$pre->get_error_code()!=='molavi_staging_external_blocked')
                return $pre;
            $homeHost=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
            $requestHost=strtolower((string)wp_parse_url((string)$requestUrl,PHP_URL_HOST));
            $requestPath=(string)wp_parse_url((string)$requestUrl,PHP_URL_PATH);
            $requestScheme=strtolower((string)wp_parse_url((string)$requestUrl,PHP_URL_SCHEME));
            $requestMethod=strtoupper((string)(($parsedArgs['method']??'')));
            if($homeHost==='staging.molavisale.ir'
                &&$requestHost==='wholesale-project-staging-staging.up.railway.app'
                &&$requestScheme==='https'
                &&$requestMethod==='POST'
                &&$requestPath==='/pipeline/verdict')
                return false;
            return $pre;
        };
        add_filter('pre_http_request',$railwayHttpException,PHP_INT_MAX,3);
        try {
            $response=wp_safe_remote_post($url,[
                'timeout'=>45,
                'redirection'=>0,
                'headers'=>[
                    'X-Molavi-Reference-Token'=>$token,
                    'X-Molavi-Actor'=>$actor,
                    'Content-Type'=>'application/json',
                ],
                'body'=>$body,
            ]);
        } finally {
            remove_filter('pre_http_request',$railwayHttpException,PHP_INT_MAX);
        }
        if(is_wp_error($response))return null;
        if((int)wp_remote_retrieve_response_code($response)!==200)return null;
        $data=json_decode((string)wp_remote_retrieve_body($response),true);
        if(!is_array($data)||($data['ok']??false)!==true)return null;
        $verdict=$data['verdict']??null;
        if(!in_array($verdict,['ok','flag'],true))return null;
        $reason=$data['reason']??'';
        if(!is_string($reason))$reason='';
        return ['verdict'=>$verdict,'reason'=>$reason];
    } catch(Throwable $e) {
        return null;
    }
}
