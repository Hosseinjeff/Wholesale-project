<?php
/** Plugin Name: Molavi Native AI Review Dismissals */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_dismiss_cases(array $body, string $actor): array {
    $selected = $body['cases'] ?? null;
    $note = $body['note'] ?? null;
    if (count($body)!==3 || !is_array($selected) || !array_is_list($selected) || !$selected || count($selected)>200
        || !is_string($note) || molavi_domain_strlen($note)>1000) molavi_domain_fail('invalid_dismissal_request',400);
    $seen = [];
    foreach ($selected as $item) {
        $caseId = $item['case_id'] ?? null;
        $offerId = $item['offer_id'] ?? null;
        $expected = $item['expected_decision_id'] ?? null;
        if (!is_array($item) || count($item)!==4 || !array_key_exists('expected_decision_id',$item)
            || !is_string($caseId) || $caseId==='' || molavi_domain_strlen($caseId)>100 || isset($seen[$caseId])
            || !is_string($offerId) || $offerId==='' || molavi_domain_strlen($offerId)>300
            || !molavi_domain_ai_hash_valid($item['signature'] ?? null)
            || ($expected!==null && !molavi_domain_ai_hash_valid($expected))) molavi_domain_fail('invalid_dismissal_request',400);
        $seen[$caseId] = true;
    }
    return molavi_domain_lock('publication', static function () use ($selected,$actor,$note): array {
        $scope = molavi_domain_ai_hold_scope();
        $tables = molavi_domain_sheets(['Offers'],true);
        $available = array_column(molavi_domain_ai_review_cases($tables,null,false,200),null,'case_id');
        $cases = [];
        foreach ($selected as $item) {
            $case = $available[$item['case_id']] ?? null;
            if (!$case || $case['offer_id']!==$item['offer_id'] || $case['signature']!==$item['signature']
                || $case['previous_decision_id']!==$item['expected_decision_id']) molavi_domain_fail('stale_case');
            $cases[] = $case;
        }
        foreach ($cases as $case) if (molavi_domain_ai_held($scope,$case)) molavi_domain_fail('publication_held_for_review');
        $dismissed = [];
        $failed = [];
        foreach ($cases as $case) {
            try {
                $dismissed[] = molavi_domain_lock('publication', static function () use ($case,$actor,$note): array {
                    $decision = molavi_domain_ai_decide([
                        'case_id'=>$case['case_id'],'offer_id'=>$case['offer_id'],'signature'=>$case['signature'],
                        'choice'=>'not_product','fields'=>[],'actor_id'=>$actor,'note'=>$note,'expected_decision_id'=>$case['previous_decision_id'],
                    ]);
                    molavi_domain_ai_apply_decision($case,$decision);
                    $job = molavi_domain_queue('publish','ai-confirmation:'.$decision['decision_id'],['decision_id'=>$decision['decision_id']]);
                    try {
                        molavi_domain_ai_refresh_message($case);
                    } catch (Throwable $e) {
                        error_log('[molavi-native] ai_dismissal_message_status_failed');
                    }
                    return ['case_id'=>$case['case_id'],'decision_id'=>$decision['decision_id'],'publish_job'=>$job];
                });
            } catch (Throwable $e) {
                $failed[] = ['case_id'=>$case['case_id'],'error'=>'dismiss_failed'];
            }
        }
        if ($dismissed) {
            try {
                molavi_domain_status_capture(molavi_domain_sheets(['Products','Offers'],true),'owner_ai_bulk_decision');
            } catch (Throwable $e) {
                error_log('[molavi-native] ai_bulk_decision_snapshot_failed');
            }
        }
        return ['ok'=>true,'requested'=>count($selected),'dismissed_count'=>count($dismissed),'failed'=>$failed,'dismissed'=>$dismissed];
    });
}

function molavi_domain_ai_save_dismissals(array $messages, string $actor, string $note): void {
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($messages,$actor,$note): void {
        foreach ($messages as $item) {
            $media = (string)molavi_domain_py_value($item['media_identity'] ?? null,molavi_domain_py_value($item['image_file_unique_id'] ?? null,$item['image_file_id'] ?? ''));
            $key = molavi_domain_ai_message_key($item + ['media_identity'=>$media]);
            $identity = [$item['message_id'],$item['batch_id'],$item['channel_username'],$item['timestamp'],hash('sha256',$item['content']),$media];
            molavi_domain_sql($db,
                'INSERT OR IGNORE INTO ai_message_dismissals (message_key,message_id,batch_id,channel_username,source_timestamp,content_sha256,media_unique_id,actor_id,note,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                array_merge([$key],$identity,[$actor,trim($note),microtime(true)]));
            $row = molavi_domain_row($db,'SELECT message_id,batch_id,channel_username,source_timestamp,content_sha256,media_unique_id FROM ai_message_dismissals WHERE message_key=?',[$key]);
            if (!$row || array_values($row)!==$identity) molavi_domain_fail('decision_conflict');
        }
    });
}

function molavi_domain_ai_dismiss_messages(array $body, string $actor): array {
    $caps = molavi_domain_bot_capabilities();
    $images = $caps['image_extraction_enabled'];
    $policy = $body['action']==='dismiss_recent_policy_messages' ? $caps['extraction_policy_version'] : null;
    $media = $body['action']==='dismiss_media_message';
    if ($media) {
        if (!$images) molavi_domain_fail('image_extraction_disabled',410);
        if (count($body)!==2 || !molavi_domain_ai_hash_valid($body['message_key'] ?? null)) molavi_domain_fail('invalid_media_decision',400);
    } elseif (count($body)!==4 || !is_int($body['expected_count'] ?? null) || $body['expected_count']<1
        || !molavi_domain_ai_hash_valid($body['expected_digest'] ?? null)
        || !is_string($body['note'] ?? null) || molavi_domain_strlen($body['note'])>1000) {
        molavi_domain_fail('invalid_dismissal_request',400);
    }
    return molavi_domain_lock('publication', static function () use ($body,$actor,$images,$media,$policy): array {
        if (molavi_domain_ai_active_run()) molavi_domain_fail('reextraction_active');
        if ($media) {
            $key = $body['message_key'];
            $existing = molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_row($db,'SELECT message_id FROM ai_message_dismissals WHERE message_key=?',[$key]));
            if ($existing) return ['ok'=>true,'decision_id'=>$key,'already_decided'=>true,'choice'=>'not_product','message_id'=>$existing['message_id']];
        }
        $inventory = molavi_domain_ai_inventory(molavi_domain_sheets(['MessageData','Offers'],true),$images,$policy);
        if ($media) {
            $items = array_column($inventory['media_review_messages'],null,'message_key');
            $item = $items[$key] ?? null;
            if (!$item) molavi_domain_fail('stale_media_review');
            molavi_domain_ai_save_dismissals([$item],$actor,'');
            molavi_domain_ai_write_message_state($item['message_id'],$item['batch_id'],$item['channel'],'owner_rejected',[]);
            return ['ok'=>true,'decision_id'=>$key,'choice'=>'not_product','message_id'=>$item['message_id']];
        }
        if ($policy!==null) {
            $recent = molavi_domain_ai_recent_inventory($inventory,$policy,$images);
            $selected = $recent['messages'];
            $digest = $recent['digest'];
        } else {
            $selected = $inventory['pending_messages'];
            $keys = array_column($selected,'message_key');
            sort($keys,SORT_STRING);
            $digest = molavi_domain_hash($keys);
        }
        if (count($selected)!==$body['expected_count'] || $digest!==$body['expected_digest']) {
            molavi_domain_fail('reextraction_preview_changed',409,['current_count'=>count($selected)]);
        }
        molavi_domain_ai_save_dismissals($selected,$actor,$body['note']);
        return ['ok'=>true,'dismissed_count'=>count($selected),'dismissed_by_owner'=>$inventory['counts']['dismissed_by_owner']+count($selected)];
    });
}
