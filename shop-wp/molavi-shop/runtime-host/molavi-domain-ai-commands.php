<?php
/** Plugin Name: Molavi Native Extraction Commands */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_ai_enqueue_run(string $run, string $actor, array $items): int {
    $prepared = [];
    foreach ($items as $item) {
        if (empty($item['message_id']) || !molavi_domain_ai_hash_valid($item['message_key'] ?? null)
            || !molavi_domain_ai_hash_valid($item['content_sha256'] ?? null)) {
            molavi_domain_fail('reextraction_conflict');
        }
        $prepared['reextract:'.$run.':'.$item['message_key']] = molavi_domain_json(
            $item + ['run_id'=>$run, 'actor_id'=>$actor], true
        );
    }
    return molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($prepared): int {
        if (molavi_domain_row($db, "SELECT 1 FROM jobs WHERE kind='reextract' AND state IN ('queued','running') LIMIT 1")) {
            molavi_domain_fail('reextraction_active');
        }
        $now = microtime(true);
        foreach ($prepared as $id => $payload) {
            $old = molavi_domain_row($db, 'SELECT payload FROM jobs WHERE id=?', [$id]);
            if ($old && $old['payload'] !== $payload) {
                molavi_domain_fail('reextraction_conflict');
            }
            molavi_domain_sql($db, "INSERT OR IGNORE INTO jobs (id,kind,payload,state,available) VALUES (?,'reextract',?,'queued',?)", [$id,$payload,$now]);
        }
        return count($prepared);
    });
}

function molavi_domain_ai_hash_valid($value, int $length=64): bool {
    return is_string($value) && strlen($value)===$length && preg_match('/^[a-f0-9]+$/D', $value);
}

function molavi_domain_ai_cancel(array $body, string $actor): array {
    molavi_domain_body_keys($body, ['action','run_id']);
    $run = $body['run_id'];
    if (!molavi_domain_ai_hash_valid($run,32)) {
        molavi_domain_fail('reextraction_conflict');
    }
    return molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($run,$actor): array {
        $prefix = 'reextract:'.$run.':%';
        $rows = molavi_domain_rows($db, "SELECT payload FROM jobs WHERE kind='reextract' AND id LIKE ?", [$prefix]);
        if (!$rows) molavi_domain_fail('reextraction_conflict');
        foreach ($rows as $row) {
            if ((molavi_domain_decode($row['payload'])['actor_id'] ?? null) !== $actor) {
                molavi_domain_fail('reextraction_conflict');
            }
        }
        $changed = molavi_domain_sql($db, "UPDATE jobs SET state='cancelled',result=?,error=NULL,lease=NULL,expires=NULL WHERE kind='reextract' AND id LIKE ? AND state='queued'",
            [molavi_domain_json(['status'=>'cancelled'],true),$prefix]);
        $running = molavi_domain_row($db, "SELECT COUNT(*) AS n FROM jobs WHERE kind='reextract' AND id LIKE ? AND state='running'", [$prefix]);
        return ['ok'=>true,'run_id'=>$run,'cancelled'=>$changed['changes'],'running'=>$running['n']];
    });
}

function molavi_domain_ai_start(array $body, string $actor): array {
    $caps = molavi_domain_bot_capabilities();
    $action = $body['action'];
    $images = $caps['image_extraction_enabled'];
    if ($action==='start_media_reextraction' && !$images) molavi_domain_fail('image_extraction_disabled',410);
    $keys = array_keys($body);
    sort($keys);
    $scope = '';
    $filtered = false;
    if ($action==='start_media_reextraction') {
        if ($keys!==['action','message_key','run_id'] || !molavi_domain_ai_hash_valid($body['message_key'])
            || !molavi_domain_ai_hash_valid($body['run_id'],32)) molavi_domain_fail('invalid_media_reextraction',400);
        $scope = 'media_review';
    } elseif ($action==='start_open_case_reextraction') {
        if ($keys!==['action','expected_case_count','expected_digest','expected_message_count','run_id']) molavi_domain_fail('invalid_reextraction_request',400);
        if (!molavi_domain_ai_hash_valid($body['run_id'],32)) molavi_domain_fail('invalid_reextraction_run',400);
        foreach (['expected_case_count','expected_message_count'] as $field) {
            if (!is_int($body[$field]) || $body[$field]<1) molavi_domain_fail('invalid_reextraction_count',400);
        }
        if (!molavi_domain_ai_hash_valid($body['expected_digest'])) molavi_domain_fail('invalid_reextraction_count',400);
        $scope = 'open_cases';
    } elseif ($action==='start_offer_reextraction') {
        if($keys!==['action','expected_digest','offer_id','policy_version','run_id']
            || !molavi_domain_ai_hash_valid($body['run_id'],32) || !molavi_domain_ai_hash_valid($body['expected_digest'])
            || !is_string($body['offer_id']) || trim($body['offer_id'])==='' || strlen($body['offer_id'])>300
            || !is_string($body['policy_version'])) molavi_domain_fail('invalid_reextraction_request',400);
        if($body['policy_version']!==$caps['extraction_policy_version']) molavi_domain_fail('extraction_policy_changed',409);
        // Use the existing version-bound executor and publication safeguards.
        $scope='recent_policy';
    } elseif ($action==='start_recent_policy_reextraction') {
        if ($keys!==['action','expected_count','expected_digest','policy_version','run_id']
            || !molavi_domain_ai_hash_valid($body['run_id'],32) || !molavi_domain_ai_hash_valid($body['expected_digest'])
            || !is_int($body['expected_count']) || $body['expected_count']<1 || !is_string($body['policy_version'])
            || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$body['policy_version'])) molavi_domain_fail('invalid_recent_reextraction_request',400);
        if ($body['policy_version']!==$caps['extraction_policy_version']) molavi_domain_fail('extraction_policy_changed',409,['policy_version'=>$caps['extraction_policy_version']]);
        $scope = 'recent_policy';
    } else {
        $scope = $body['scope'] ?? '';
        $legacy = $keys===['action','expected_count','scope'] && in_array($scope,['pending','all'],true);
        $filtered = !$images && $keys===['action','channels','date_from','date_to','expected_count','expected_digest','run_id','scope']
            && in_array($scope,['all','pending','linked'],true);
        if (!$legacy && !$filtered) molavi_domain_fail('invalid_reextraction_request',400);
        if (!is_int($body['expected_count']) || $body['expected_count']<1) molavi_domain_fail('invalid_reextraction_count',400);
        if ($filtered && (!molavi_domain_ai_hash_valid($body['expected_digest']) || !molavi_domain_ai_hash_valid($body['run_id'],32))) {
            molavi_domain_fail('invalid_reextraction_request',400);
        }
    }
    if (!$caps['ai_configured']) molavi_domain_fail('ai_extraction_unconfigured');
    $run = $body['run_id'] ?? bin2hex(random_bytes(16));
    return molavi_domain_lock('publication', static function () use ($body,$actor,$images,$scope,$filtered,$run): array {
        if (molavi_domain_ai_active_run()) molavi_domain_fail('reextraction_active');
        $tables = molavi_domain_sheets($scope==='open_cases'?['Offers']:['Offers','MessageData'],true);
        $receipt = ['ok'=>true,'run_id'=>$run,'scope'=>$scope,'_http_status'=>202];
        if ($scope==='open_cases') {
            $inventory = molavi_domain_ai_case_inventory($tables);
            if ($inventory['case_count']!==$body['expected_case_count'] || $inventory['message_count']!==$body['expected_message_count']
                || $inventory['digest']!==$body['expected_digest']) {
                molavi_domain_fail('reextraction_preview_changed',409,['current_case_count'=>$inventory['case_count'],'current_message_count'=>$inventory['message_count']]);
            }
            if ($inventory['unmatched_case_ids']) molavi_domain_fail('reextraction_sources_unavailable',409,['unmatched_case_count'=>count($inventory['unmatched_case_ids'])]);
            $items = array_map(static fn($item)=>$item+['reextraction_scope'=>$scope,'reextraction_digest'=>$inventory['digest']],$inventory['messages']);
            $receipt += ['case_count'=>$inventory['case_count'],'message_count'=>$inventory['message_count']];
        } else {
            $policy = $scope==='recent_policy' ? $body['policy_version'] : null;
            $digest = null;
            if ($scope==='recent_policy') {
                $recent = isset($body['offer_id'])
                    ? molavi_domain_ai_offer_selection($tables,$body['offer_id'],$policy)
                    : molavi_domain_ai_recent_inventory(molavi_domain_ai_inventory($tables,$images,$policy),$policy,$images);
                $currentCount=count($recent['messages']);
                if ($currentCount!==($body['expected_count']??1) || ($recent['digest']??null)!==$body['expected_digest']) {
                    molavi_domain_fail('reextraction_preview_changed',409,['current_count'=>$currentCount]);
                }
                $selected = $recent['messages'];
                $digest = $recent['digest'];
                $receipt += ['policy_version'=>$policy,'digest'=>$digest];
            } elseif ($scope==='media_review') {
                $inventory=molavi_domain_ai_inventory($tables,$images);
                $selected = array_values(array_filter($inventory['media_review_messages'],static fn($item)=>$item['message_key']===$body['message_key']));
                if (!$selected) molavi_domain_fail('stale_media_review');
                $selected = array_slice($selected,0,1);
                $receipt['message_key'] = $body['message_key'];
            } else {
                $inventory=molavi_domain_ai_inventory($tables,$images);
                if ($filtered) {
                    $selection = molavi_domain_ai_selection($inventory,$scope,$body['channels'],$body['date_from'],$body['date_to']);
                    $selected = $selection['messages'];
                    $digest = $selection['digest'];
                } else {
                    $selected = $inventory[$scope==='pending'?'pending_messages':'all_messages'];
                }
                if (count($selected)!==$body['expected_count'] || ($filtered && $digest!==$body['expected_digest'])) {
                    $details = ['current_count'=>count($selected)];
                    if (!$images) $details['current_digest'] = $digest ?? '';
                    molavi_domain_fail('reextraction_preview_changed',409,$details);
                }
            }
            $items = [];
            foreach ($selected as $item) {
                $hash = hash('sha256',$item['content']);
                if ($scope==='media_review') {
                    $items[] = $item + ['content_sha256'=>$hash,'reextraction_scope'=>$scope];
                    continue;
                }
                $payload = array_intersect_key($item,array_flip(['message_id','batch_id','channel_username','message_key','timestamp']));
                $payload += ['content_sha256'=>$hash,'reextraction_scope'=>$scope];
                if ($scope==='recent_policy') {
                    $payload += ['source_key'=>$item['source_key'],'policy_version'=>$policy];
                    if(isset($body['offer_id'])) $payload['requested_offer_id']=$body['offer_id'];
                    if ($images) $payload['media_ocr'] = !empty($item['media_ocr']);
                } elseif (!$images) {
                    $payload['media_ocr'] = false;
                }
                if ($digest!==null) $payload['reextraction_digest'] = $digest;
                $items[] = $payload;
            }
        }
        if (!$items) molavi_domain_fail($scope==='recent_policy'?'no_recent_reextraction_messages':'no_reextraction_messages');
        $receipt['queued'] = molavi_domain_ai_enqueue_run($run,$actor,$items);
        return $receipt;
    });
}

/** Complete the existing run-to-publication handoff after the bot has finished. */
function molavi_domain_ai_publish_completed_runs(): void {
    $runs = molavi_domain_read('pipeline',static fn(SQLite3 $db)=>molavi_domain_rows($db,
        "SELECT DISTINCT substr(j.id,11,32) AS run_id FROM jobs j
         WHERE j.kind='reextract' AND j.state='done' AND json_valid(j.result)
           AND CAST(json_extract(j.result,'$.offers_written') AS INTEGER)>0
           AND NOT EXISTS (SELECT 1 FROM jobs p WHERE p.id='publish:ai-reextraction:'||substr(j.id,11,32))
           AND NOT EXISTS (SELECT 1 FROM jobs r WHERE r.kind='reextract'
              AND r.id LIKE substr(j.id,1,43)||'%' AND r.state NOT IN ('done','failed','needs_review','cancelled'))
         LIMIT 10"));
    foreach ($runs as $row) {
        $run = $row['run_id'];
        if (molavi_domain_ai_hash_valid($run,32)) molavi_domain_queue('publish','ai-reextraction:'.$run,['source'=>'ai_reextraction','run_id'=>$run]);
    }
}
