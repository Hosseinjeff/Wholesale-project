<?php
/** Plugin Name: Molavi Bot Capability Receipt */
declare(strict_types=1);
defined('ABSPATH') || exit;

/** Nonsensitive bot policy metadata lets local review reads stay local. */
function molavi_domain_validate_bot_capabilities($body): bool {
    if(!is_array($body))return false;$keys=array_keys($body);sort($keys);
    return $keys===['ai_configured','extraction_policy_version','image_extraction_enabled','schema_version','suggestion_policy_version']
        &&($body['schema_version']??null)===1&&is_bool($body['ai_configured'])&&is_bool($body['image_extraction_enabled'])
        &&is_string($body['extraction_policy_version'])&&preg_match('/^gemini-[a-f0-9]{16}$/D',$body['extraction_policy_version'])
        &&($body['suggestion_policy_version']===null||(is_string($body['suggestion_policy_version'])&&preg_match('/^[a-f0-9]{64}$/D',$body['suggestion_policy_version'])));
}
function molavi_domain_bot_capabilities(): array {
    $body=get_option('molavi_bot_capabilities_v1',null);if(!molavi_domain_validate_bot_capabilities($body))molavi_domain_fail('bot_capabilities_unavailable',503);return $body;
}
add_action('rest_api_init',static function():void {
    register_rest_route('molavi-workflow/v1','/bot-capabilities',['methods'=>'POST','permission_callback'=>'molavi_runtime_permission',
        'callback'=>static function($request){$body=$request->get_json_params();if(!molavi_domain_validate_bot_capabilities($body))return new WP_Error('invalid_bot_capabilities','Invalid capabilities',['status'=>400]);
            update_option('molavi_bot_capabilities_v1',$body,false);return ['ok'=>true,'schema_version'=>1];}]);
});
