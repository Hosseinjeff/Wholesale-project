<?php
/** Plugin Name: Molavi Bot Capability Receipt */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Nonsensitive bot policy metadata lets local review reads stay local. */
function molavi_domain_validate_google_quota($quota): bool {
    if (!is_array($quota) || count($quota)!==2 || !is_string($quota['project']??null)
        || !preg_match('/^[A-Za-z0-9._:-]{1,120}$/D',$quota['project'])
        || !is_array($quota['models']??null) || !array_is_list($quota['models'])
        || !$quota['models'] || count($quota['models'])>32) return false;
    $groups=[];$models=[];
    foreach($quota['models'] as $model) {
        if (!is_array($model) || count($model)!==7 || !is_string($model['model']??null)
            || !preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$model['model'])
            || !is_string($model['bucket']??null) || !preg_match('/^[A-Za-z0-9._-]{1,120}$/D',$model['bucket'])
            || !is_array($model['tasks']??null) || !array_is_list($model['tasks']) || !$model['tasks']
            || count($model['tasks'])>3) return false;
        foreach($model['tasks'] as $task) if(!in_array($task,['extract','match','embed'],true)) return false;
        foreach(['rpm','tpm','rpd','reserve_rpd'] as $key) {
            if(!is_int($model[$key]??null) || $model[$key]<($key==='reserve_rpd'?0:1) || $model[$key]>1000000000)
                return false;
        }
        if ($model['reserve_rpd']>=$model['rpd'] || isset($models[$model['model']])) return false;
        $limits=[$model['rpm'],$model['tpm'],$model['rpd'],$model['reserve_rpd']];
        if (isset($groups[$model['bucket']]) && $groups[$model['bucket']]!==$limits) return false;
        $groups[$model['bucket']]=$limits;
        $models[$model['model']]=true;
    }
    return true;
}

function molavi_domain_validate_bot_capabilities($body): bool {
    if(!is_array($body))return false;
    $core=['ai_configured','extraction_policy_version','image_extraction_enabled','schema_version','suggestion_policy_version'];
    if (array_diff(array_keys($body),[...$core,'google_quota','fixed_extraction_model'])
        || array_diff($core,array_keys($body)))return false;
    if (array_key_exists('google_quota',$body)
        && (array_key_exists('fixed_extraction_model',$body)||!molavi_domain_validate_google_quota($body['google_quota']))) return false;
    $fixed=$body['fixed_extraction_model']??null;
    if ($fixed!==null && (!is_string($fixed)||!preg_match('/^[A-Za-z0-9._-]{1,100}$/D',$fixed))) return false;
    return $body['schema_version']===1 && is_bool($body['ai_configured']) && is_bool($body['image_extraction_enabled'])
        && is_string($body['extraction_policy_version']) && preg_match('/^gemini-[a-f0-9]{16}$/D',$body['extraction_policy_version'])
        && ($body['suggestion_policy_version']===null||(is_string($body['suggestion_policy_version'])&&preg_match('/^[a-f0-9]{64}$/D',$body['suggestion_policy_version'])));
}
function molavi_domain_bot_capabilities(): array {
    $body=get_option('molavi_bot_capabilities_v1',null);if(!molavi_domain_validate_bot_capabilities($body))molavi_domain_fail('bot_capabilities_unavailable',503);return $body;
}
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/bot-capabilities',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function($request){$body=$request->get_json_params();if(!molavi_domain_validate_bot_capabilities($body))return new WP_Error('invalid_bot_capabilities','Invalid capabilities',['status'=>400]);
            update_option('molavi_bot_capabilities_v1',$body,false);return ['ok'=>true,'schema_version'=>1];}]);
});
