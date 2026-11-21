<?php
/** Short-lived, route-scoped browser access for owner-only workflow screens. */
declare(strict_types=1);
if (!defined('ABSPATH')) { exit; }

function molavi_owner_workflow_pages(): array {
    return [
        'molavi-operations' => ['/pipeline/operations-dashboard', 'owner:/pipeline/operations-dashboard'],
        'molavi-ai-confirmations' => ['/pipeline/ai-confirmations', 'owner:/pipeline/ai-confirmations'],
        'molavi-reference-items' => ['/reference/item-reviews', 'owner:/reference/item-reviews'],
        'molavi-reference-decisions' => ['/reference/decisions', 'owner:/reference/decisions'],
        'molavi-publication-holds' => ['/pipeline/publication-holds', 'owner:/pipeline/publication-holds'],
        'molavi-supplier-prices' => ['/pipeline/supplier-price-evidence', 'owner:/pipeline/supplier-price-evidence'],
        'molavi-sheet-conflicts' => ['/pipeline/sheet-conflicts', 'owner:/pipeline/sheet-conflicts'],
        'molavi-product-status-snapshots' => ['/pipeline/product-status-snapshots', 'owner:/pipeline/product-status-snapshots'],
    ];
}

function molavi_owner_workflow_relative_admin_url(string $path): string {
    return wp_make_link_relative(admin_url($path));
}

function molavi_owner_workflow_page_urls(): array {
    $pages = [
        'molavi-operations', 'molavi-ai-confirmations', 'molavi-reference-items',
        'molavi-reference-decisions', 'molavi-reference-snapshots', 'molavi-publication-holds',
        'molavi-sheet-conflicts', 'molavi-supplier-prices', 'molavi-clearance-pricing',
        'molavi-inventory-management', 'molavi-paid-orders', 'molavi-product-status-snapshots',
    ];
    $urls = [];
    foreach ($pages as $page) {
        $urls[$page] = molavi_owner_workflow_relative_admin_url('admin.php?page=' . $page);
    }
    return $urls;
}

function molavi_owner_workflow_proxy_paths(): array {
    $paths = array_column(molavi_owner_workflow_pages(), 0);
    return array_values(array_unique(array_merge($paths, [
        '/pipeline/reconciliation',
        '/pipeline/reconcile-publication-backlog',
        '/pipeline/migration-shadow',
        '/pipeline/telegram-operator-chats',
        '/reference/snapshots',
    ])));
}

/** Forward one owner-only browser request from WordPress to the private workflow API. */
function molavi_owner_workflow_proxy_call(string $method, string $path, array $query = [], ?array $payload = null): array {
    if (!in_array($method, ['GET', 'POST'], true)
        || !in_array($path, molavi_owner_workflow_proxy_paths(), true)) {
        return ['status'=>400, 'body'=>['ok'=>false, 'error'=>'workflow_route_unavailable']];
    }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $token = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($base) || !is_string($token) || strlen($token) < 32
        || wp_parse_url($base, PHP_URL_SCHEME) !== 'https') {
        return ['status'=>503, 'body'=>['ok'=>false, 'error'=>'unconfigured']];
    }
    $queryString = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    $url = rtrim($base, '/') . $path . ($queryString === '' ? '' : '?' . $queryString);
    $args = [
        'method'=>$method,
        'timeout'=>$method === 'GET' ? 90 : 45,
        'redirection'=>0,
        'headers'=>[
            'X-Molavi-Reference-Token'=>$token,
            'X-Molavi-Actor'=>'wp:' . get_current_user_id(),
            'Content-Type'=>'application/json',
        ],
    ];
    if ($method === 'POST') {
        $body = wp_json_encode($payload ?? []);
        if (!is_string($body) || strlen($body) > 2000000) {
            return ['status'=>400, 'body'=>['ok'=>false, 'error'=>'invalid_body']];
        }
        $args['body'] = $body;
    }
    // The staging safety MU-plugin blocks cross-host requests. Temporarily allow
    // only this owner proxy's HTTPS calls to the staging Railway host and route allowlist.
    $stagingWorkflowHttpException = static function ($pre, $parsedArgs, $requestUrl) use ($method) {
        if (!is_wp_error($pre) || $pre->get_error_code() !== 'molavi_staging_external_blocked') {
            return $pre;
        }
        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $requestHost = strtolower((string) wp_parse_url((string) $requestUrl, PHP_URL_HOST));
        $requestScheme = strtolower((string) wp_parse_url((string) $requestUrl, PHP_URL_SCHEME));
        $requestPath = (string) wp_parse_url((string) $requestUrl, PHP_URL_PATH);
        $requestMethod = strtoupper((string) (($parsedArgs['method'] ?? '')));
        if ($homeHost === 'staging.molavisale.ir'
            && $requestHost === 'wholesale-project-staging-staging.up.railway.app'
            && $requestScheme === 'https'
            && $requestMethod === $method
            && in_array($requestPath, molavi_owner_workflow_proxy_paths(), true)) {
            return false;
        }
        return $pre;
    };
    add_filter('pre_http_request', $stagingWorkflowHttpException, PHP_INT_MAX, 3);
    try {
        $response = wp_safe_remote_request($url, $args);
    } finally {
        remove_filter('pre_http_request', $stagingWorkflowHttpException, PHP_INT_MAX);
    }
    if (is_wp_error($response)) {
        $wpErrorCode = sanitize_key((string) $response->get_error_code());
        $wpErrorCode = $wpErrorCode === '' ? 'unknown' : $wpErrorCode;
        $errorMessage = $response->get_error_message();
        $transportCategory = 'other';
        if (preg_match('/\\bcurl error\\s+(\\d+)\\b/i', $errorMessage, $matches)) {
            $transportCategory = 'curl_' . (string) ((int) $matches[1]);
        } elseif (preg_match('/could not resolve|name or service not known|php_network_getaddresses/i', $errorMessage)) {
            $transportCategory = 'dns';
        } elseif (preg_match('/timed out|operation timed out/i', $errorMessage)) {
            $transportCategory = 'timeout';
        } elseif (preg_match('/certificate verify|ssl certificate|tls handshake/i', $errorMessage)) {
            $transportCategory = 'tls';
        } elseif (preg_match('/failed to connect|connection refused|network is unreachable/i', $errorMessage)) {
            $transportCategory = 'connect';
        }
        $siteHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($siteHost === 'staging.molavisale.ir') {
            error_log('[molavi-owner-workflow-upstream] code=' . $wpErrorCode . ' transport=' . $transportCategory);
        }
        return ['status'=>502, 'body'=>['ok'=>false, 'error'=>'upstream_unavailable']];
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($status < 200 || $status > 599 || !is_array($body)) {
        return ['status'=>502, 'body'=>['ok'=>false, 'error'=>'invalid_upstream_response']];
    }
    return ['status'=>$status, 'body'=>$body];
}

function molavi_owner_dashboard_routes(): array {
    return [
        'product_status'=>'/pipeline/product-status-snapshots',
        'ai_confirmations'=>'/pipeline/ai-confirmations',
        'reference_items'=>'/reference/item-reviews',
        'supplier_prices'=>'/pipeline/supplier-price-evidence',
        'sheet_conflicts'=>'/pipeline/sheet-conflicts',
        'publication_holds'=>'/pipeline/publication-holds',
        'reference_decisions'=>'/reference/decisions',
        'operations'=>'/pipeline/operations-dashboard',
    ];
}

function molavi_owner_dashboard_native(): bool {
    return function_exists('molavi_domain_dashboard_snapshot')
        && function_exists('molavi_domain_route_enabled')
        && (bool)get_option('molavi_state_active',false);
}

function molavi_owner_dashboard_cache_key(): string {
    return 'molavi_owner_dashboard_' . filemtime(__FILE__);
}

function molavi_owner_dashboard_cache_clear(): void {
    delete_transient(molavi_owner_dashboard_cache_key());
}

function molavi_owner_dashboard_bootstrap(bool $force, array $sections=[]): array {
    if (!molavi_owner_dashboard_native()) return ['status'=>503,'body'=>['ok'=>false,'error'=>'workflow_route_unavailable']];
    $routes=molavi_owner_dashboard_routes();
    if ($sections && array_diff($sections,array_keys($routes))) return ['status'=>400,'body'=>['ok'=>false,'error'=>'invalid_query']];
    $key=molavi_owner_dashboard_cache_key();
    $cached=($force||$sections)?false:get_transient($key);
    if (is_array($cached)) return ['status'=>200,'body'=>$cached+['cached'=>true]];
    $actor='wp:'.get_current_user_id();
    $read=static function() use ($actor,$routes,$sections): array {
        $sources=[];
        foreach ($routes as $name=>$path) {
            if ($sections && !in_array($name,$sections,true)) continue;
            $query=in_array($name,['ai_confirmations','reference_items'],true)?['view'=>'workbench-summary']:[];
            $result=molavi_domain_route_enabled('GET',$path)
                ? molavi_domain_dispatch('GET',$path,$query,null,$actor)
                : molavi_owner_workflow_proxy_call('GET',$path);
            $sources[$name]=$result['body'];
        }
        return $sources;
    };
    try { $sources=molavi_domain_dashboard_snapshot($read); }
    catch (Throwable $error) { return ['status'=>503,'body'=>['ok'=>false,'error'=>'native_workflow_unavailable']]; }
    $body=['ok'=>true,'sources'=>$sources];
    if (!$sections&&!array_filter($sources,static fn($source)=>empty($source['ok']))) set_transient($key,$body,90);
    return ['status'=>200,'body'=>$body+['cached'=>false]];
}

function molavi_owner_workflow_proxy_ajax(): void {
    if (!current_user_can('manage_woocommerce') || !molavi_reference_owner()) {
        wp_send_json(['ok'=>false, 'error'=>'owner_required'], 403);
    }
    $nonce = isset($_POST['nonce']) && is_string($_POST['nonce'])
        ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, 'molavi_owner_workflow_proxy')) {
        wp_send_json(['ok'=>false, 'error'=>'invalid_nonce'], 403);
    }
    $method = isset($_POST['method']) && is_string($_POST['method'])
        ? strtoupper(sanitize_text_field(wp_unslash($_POST['method']))) : '';
    $path = isset($_POST['path']) && is_string($_POST['path'])
        ? wp_unslash($_POST['path']) : '';
    if ($path==='/catalog/discount-policy' && in_array($method, ['GET', 'POST'], true)) {
        nocache_headers();
        try {
            $raw=is_string($_POST['payload']??null)?wp_unslash($_POST['payload']):'';
            $payload=$method==='POST' && strlen($raw)<=800 ? json_decode($raw,true) : [];
            if ($method==='POST' && (!is_array($payload) || !get_option('molavi_state_active',false))) {
                molavi_domain_fail('invalid_body',400);
            }
            wp_send_json(molavi_discount_policy_api($method,$payload));
        } catch (MolaviWorkflowError $error) {
            wp_send_json(['ok'=>false,'error'=>$error->getMessage()],$error->status);
        } catch (Throwable $error) {
            wp_send_json(['ok'=>false,'error'=>'discount_policy_unavailable'],503);
        }
        return;
    }
    if ($path==='/pipeline/dashboard-activity' && in_array($method,['GET','POST'],true)) {
        nocache_headers();
        if (!function_exists('molavi_domain_dashboard_activity') || !get_option('molavi_state_active',false)) {
            wp_send_json(['ok'=>false,'error'=>'workflow_route_unavailable'],503);
            return;
        }
        try {
            if ($method==='POST') {
                $raw=is_string($_POST['payload']??null)?wp_unslash($_POST['payload']):'';
                $watch=strlen($raw)<=350?json_decode($raw,true):null;
                if (!is_array($watch) || !function_exists('molavi_domain_dashboard_cancel')) molavi_domain_fail('invalid_body',400);
                $report=molavi_domain_dashboard_cancel($watch,'wp:'.get_current_user_id());
            } else {
                $raw=is_string($_POST['query']??null)?wp_unslash($_POST['query']):'';
                if (strlen($raw)>6500) molavi_domain_fail('invalid_query',400);
                parse_str(ltrim($raw,'?'),$query);
                $watches=is_string($query['watch']??null)?json_decode($query['watch'],true):[];
                if (!is_array($watches) || count($watches)>12) molavi_domain_fail('invalid_query',400);
                $pricing=[];$native=[];
                foreach($watches as $watch){
                    if(is_array($watch)&&($watch['kind']??null)==='repricing'){
                        if(!is_string($watch['key']??null)||!preg_match('/^[a-f0-9]{32}$/D',$watch['key']))molavi_domain_fail('invalid_query',400);
                        $pricing[]=$watch;
                    }else $native[]=$watch;
                }
                $report=molavi_discount_activity(molavi_domain_dashboard_activity(null,$native),$pricing);
            }
        } catch (MolaviWorkflowError $error) {
            wp_send_json(['ok'=>false,'error'=>$error->getMessage()],$error->status);
            return;
        } catch (Throwable $error) {
            wp_send_json(['ok'=>false,'error'=>'native_workflow_unavailable'],503);
            return;
        }
        wp_send_json($report);
        return;
    }
    if ($path==='/pipeline/dashboard-bootstrap' && $method==='GET') {
        $raw=isset($_POST['query'])&&is_string($_POST['query'])?wp_unslash($_POST['query']):'';
        parse_str(ltrim(substr($raw,0,512),'?'),$query);
        $sections=is_string($query['sections']??null)?explode(',',$query['sections']):[];
        $result=molavi_owner_dashboard_bootstrap(($query['force']??'')==='1',$sections);
        nocache_headers();
        wp_send_json($result['body'],$result['status']);
        return;
    }
    if (!in_array($path, molavi_owner_workflow_proxy_paths(), true)) {
        wp_send_json(['ok'=>false, 'error'=>'workflow_route_unavailable'], 400);
    }
    $rawQuery = isset($_POST['query']) && is_string($_POST['query'])
        ? wp_unslash($_POST['query']) : '';
    if (strlen($rawQuery) > 2048 || ($rawQuery !== '' && $rawQuery[0] !== '?')) {
        wp_send_json(['ok'=>false, 'error'=>'invalid_query'], 400);
    }
    $query = [];
    if ($rawQuery !== '') {
        parse_str(substr($rawQuery, 1), $query);
        foreach ($query as $key=>$value) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)$key) || !is_scalar($value)) {
                wp_send_json(['ok'=>false, 'error'=>'invalid_query'], 400);
            }
            $query[$key] = sanitize_text_field((string)$value);
        }
    }
    $payload = null;
    if ($method === 'POST') {
        $rawPayload = isset($_POST['payload']) && is_string($_POST['payload'])
            ? wp_unslash($_POST['payload']) : '';
        $payload = $rawPayload === '' ? [] : json_decode($rawPayload, true);
        if (!is_array($payload) || strlen($rawPayload) > 2000000) {
            wp_send_json(['ok'=>false, 'error'=>'invalid_body'], 400);
        }
    } elseif ($method !== 'GET') {
        wp_send_json(['ok'=>false, 'error'=>'workflow_route_unavailable'], 400);
    }
    $result = molavi_owner_dashboard_native() && molavi_domain_route_enabled($method,$path)
        ? molavi_domain_dispatch($method,$path,$query,$payload,'wp:'.get_current_user_id())
        : molavi_owner_workflow_proxy_call($method, $path, $query, $payload);
    if ($method==='POST' && $result['status']<300) molavi_owner_dashboard_cache_clear();
    nocache_headers();
    wp_send_json($result['body'], $result['status']);
}
add_action('wp_ajax_molavi_owner_workflow_proxy', 'molavi_owner_workflow_proxy_ajax');

add_action('wp_ajax_molavi_owner_market_photo',static function(): void {
    $nonce=is_string($_GET['nonce']??null)?wp_unslash($_GET['nonce']):'';
    if (!current_user_can('manage_woocommerce')||!molavi_reference_owner()||!wp_verify_nonce($nonce,'molavi_owner_workflow_proxy')) {
        status_header(403);
        exit;
    }
    $offer=is_string($_GET['offer_id']??null)?wp_unslash($_GET['offer_id']):'';
    $listing=is_string($_GET['listing']??null)?wp_unslash($_GET['listing']):'';
    try {
        $data=molavi_domain_market_price_api('GET',['offer_id'=>$offer],'wp:'.get_current_user_id());
        $quote=array_column($data['candidates']??[],null,'url')[$listing]??null;
        if (!$quote) molavi_domain_fail('market_image_unavailable',404);
        if (empty($quote['image_url'])) {
            $cached=get_transient('molavi_market_subject_v1_'.hash('sha256',$offer));
            $identity=$cached['subject']['identity']??null;
            if ($identity) $quote=molavi_domain_market_check_url($listing,$identity)??$quote;
        }
        if (empty($quote['image_url'])) molavi_domain_fail('market_image_unavailable',404);
        [$image,$mime]=molavi_domain_market_download_photo($quote['image_url'],$listing);
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');
        echo $image;
    } catch (Throwable $error) {status_header(404);}
    exit;
});

function molavi_owner_workbench_dismissals_meta_key(): string {
    return 'molavi_owner_workbench_dismissals_v1';
}

function molavi_owner_workbench_dismissals_get(int $userId = 0): array {
    $userId = $userId > 0 ? $userId : get_current_user_id();
    if ($userId <= 0) { return []; }
    $items = get_user_meta($userId, molavi_owner_workbench_dismissals_meta_key(), true);
    return is_array($items) ? array_values($items) : [];
}

function molavi_owner_workbench_dismissal_key_is_valid(string $key, string $queue): bool {
    if (strlen($key) > 520 || !preg_match('/^([a-z_]{2,32})\\|([A-Za-z0-9_:@.+%,-]{1,480})$/D', $key, $matches)) {
        return false;
    }
    return hash_equals($queue, $matches[1]);
}

function molavi_owner_workbench_dismissals_ajax(): void {
    if (!current_user_can('manage_woocommerce') || !function_exists('molavi_reference_owner')
        || !molavi_reference_owner()) {
        wp_send_json_error(['code'=>'owner_required'], 403);
    }
    check_ajax_referer('molavi_owner_workbench_dismissals', 'nonce');

    $operation = isset($_POST['operation']) && is_string($_POST['operation'])
        ? sanitize_key(wp_unslash($_POST['operation'])) : '';
    $allowedQueues = [
        'operations'=>'intake', 'source_integrity'=>'identity', 'references'=>'identity', 'ai'=>'extract',
            'reextract'=>'extract', 'media_review'=>'extract', 'supplier_prices'=>'evidence', 'conflicts'=>'evidence',
        'holds'=>'publish', 'status'=>'publish', 'storefront'=>'storefront',
    ];
    $allowedReasons = ['duplicate','no_action','outside_scope','later','other'];
    $userId = get_current_user_id();
    $items = molavi_owner_workbench_dismissals_get($userId);
    $byKey = [];
    foreach ($items as $item) {
        if (is_array($item) && !empty($item['task_key'])) {
            $byKey[(string)$item['task_key']] = $item;
        }
    }

    if ($operation === 'dismiss') {
        $raw = isset($_POST['records']) && is_string($_POST['records']) ? wp_unslash($_POST['records']) : '';
        $records = $raw !== '' && strlen($raw) <= 400000 ? json_decode($raw, true) : null;
        if (!is_array($records) || !$records || count($records) > 1000) {
            wp_send_json_error(['code'=>'invalid_records'], 400);
        }
        foreach ($records as $record) {
            if (!is_array($record)) { wp_send_json_error(['code'=>'invalid_records'], 400); }
            $taskKey = isset($record['task_key']) && is_string($record['task_key']) ? $record['task_key'] : '';
            $queue = isset($record['queue']) && is_string($record['queue']) ? sanitize_key($record['queue']) : '';
            $stage = isset($record['stage']) && is_string($record['stage']) ? sanitize_key($record['stage']) : '';
            $reason = isset($record['reason']) && is_string($record['reason']) ? sanitize_key($record['reason']) : '';
            $scope = isset($record['scope']) && is_string($record['scope']) ? sanitize_key($record['scope']) : 'filtered';
            $label = isset($record['label']) && is_string($record['label'])
                ? sanitize_text_field($record['label']) : '';
            $note = isset($record['note']) && is_string($record['note'])
                ? sanitize_textarea_field($record['note']) : '';
            if (!isset($allowedQueues[$queue]) || $allowedQueues[$queue] !== $stage
                || !molavi_owner_workbench_dismissal_key_is_valid($taskKey, $queue)
                || !in_array($reason, $allowedReasons, true) || !in_array($scope, ['selected','queue','stage','filtered','all_open'], true)
                || strlen($label) > 160 || strlen($note) > 400 || ($reason === 'other' && trim($note) === '')) {
                wp_send_json_error(['code'=>'invalid_records'], 400);
            }
            $byKey[$taskKey] = [
                'task_key'=>$taskKey, 'queue'=>$queue, 'stage'=>$stage,
                'label'=>$label, 'reason'=>$reason, 'note'=>$note, 'scope'=>$scope,
                'dismissed_at'=>current_time('mysql', true),
            ];
        }
        if (count($byKey) > 3000) { wp_send_json_error(['code'=>'dismissal_limit'], 400); }
        update_user_meta($userId, molavi_owner_workbench_dismissals_meta_key(), array_values($byKey));
    } elseif ($operation === 'restore') {
        $raw = isset($_POST['keys']) && is_string($_POST['keys']) ? wp_unslash($_POST['keys']) : '';
        $keys = $raw !== '' && strlen($raw) <= 100000 ? json_decode($raw, true) : null;
        if (!is_array($keys) || !$keys || count($keys) > 1000) {
            wp_send_json_error(['code'=>'invalid_records'], 400);
        }
        foreach ($keys as $key) {
            if (!is_string($key) || strlen($key) > 520) { wp_send_json_error(['code'=>'invalid_records'], 400); }
            unset($byKey[$key]);
        }
        update_user_meta($userId, molavi_owner_workbench_dismissals_meta_key(), array_values($byKey));
    } elseif ($operation === 'restore_all') {
        delete_user_meta($userId, molavi_owner_workbench_dismissals_meta_key());
        $byKey = [];
    } else {
        wp_send_json_error(['code'=>'invalid_operation'], 400);
    }

    nocache_headers();
    wp_send_json_success(['items'=>array_values($byKey)]);
}
add_action('wp_ajax_molavi_owner_workbench_dismissals', 'molavi_owner_workbench_dismissals_ajax');

function molavi_owner_workflow_token(string $scope): string {
    if (!molavi_reference_owner()) { return ''; }
    $allowed = array_merge(array_column(molavi_owner_workflow_pages(), 1),
        ['owner:/pipeline/telegram-operator-chats','owner:/pipeline/migration-shadow']);
    if (!in_array($scope, $allowed, true)) { return ''; }
    $secret = defined('MOLAVI_REFERENCE_BRIDGE_TOKEN') ? MOLAVI_REFERENCE_BRIDGE_TOKEN : '';
    if (!is_string($secret) || strlen($secret) < 32) { return ''; }
    $issued = time();
    $claims = wp_json_encode([
        'iss' => 'molavi-wordpress',
        'aud' => 'molavi-owner-workflows',
        'scope' => $scope,
        'actor' => 'wp:' . get_current_user_id(),
        'iat' => $issued,
        'exp' => $issued + 600,
    ]);
    if (!is_string($claims)) { return ''; }
    $payload = rtrim(strtr(base64_encode($claims), '+/', '-_'), '=');
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    return $payload . '.' . $signature;
}

add_action('admin_enqueue_scripts', static function (): void {
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $allPages = molavi_owner_workflow_page_urls();
    if (!isset($allPages[$page]) || !molavi_reference_owner()) { return; }

    // The route-backed owner dashboard already uses fetch. This handler adds the
    // same no-reload behavior to the remaining server-rendered action forms.
    $formsScript = __DIR__ . '/admin-ajax-forms.js';
    if (is_readable($formsScript)) {
        wp_enqueue_script('molavi-admin-ajax-forms', plugins_url('admin-ajax-forms.js', __FILE__), [],
            (string) filemtime($formsScript), true);
    }

    $pages = molavi_owner_workflow_pages();
    if (!isset($pages[$page])) { return; }
    $base = defined('MOLAVI_REFERENCE_SERVER_URL') ? MOLAVI_REFERENCE_SERVER_URL : '';
    $script = __DIR__ . '/owner-workflows.js';
    $token = molavi_owner_workflow_token($pages[$page][1]);
    if (!is_string($base) || wp_parse_url($base, PHP_URL_SCHEME) !== 'https'
        || $token === '' || !is_readable($script)) { return; }
    $dependencies = [];
    if ($page === 'molavi-operations') {
        foreach (['reference-snapshots', 'owner-dashboard'] as $asset) {
            $assetPath = __DIR__ . '/' . $asset . '.js';
            wp_enqueue_script('molavi-' . $asset, plugins_url($asset . '.js', __FILE__), [],
                (string) filemtime($assetPath), true);
            $dependencies[] = 'molavi-' . $asset;
        }
        wp_enqueue_style('molavi-owner-dashboard', plugins_url('owner-dashboard.css', __FILE__),
            ['molavi-owner-workflows', 'dashicons'], (string) filemtime(__DIR__ . '/owner-dashboard.css'));
    }
    wp_enqueue_script('molavi-owner-workflows', plugins_url('owner-workflows.js', __FILE__), $dependencies,
        (string) filemtime($script), true);
    $style = __DIR__ . '/owner-workflows.css';
    if (is_readable($style)) {
        wp_enqueue_style('molavi-owner-workflows', plugins_url('owner-workflows.css', __FILE__), [],
            (string) filemtime($style));
    }
    $browserConfig = [
        'page' => $page,
        'ownerCacheKey' => (string)get_current_user_id() . ':' . filemtime($script),
        'apiUrl' => rtrim($base, '/') . $pages[$page][0],
        'apiProxyUrl' => molavi_owner_workflow_relative_admin_url('admin-ajax.php'),
        'apiProxyNonce' => wp_create_nonce('molavi_owner_workflow_proxy'),
        'pipelineUrl' => molavi_owner_workflow_relative_admin_url('admin.php?page=molavi-operations'),
        'pageUrls' => molavi_owner_workflow_page_urls(),
    ];
    if ($page === 'molavi-operations') {
        $browserConfig['dashboardActivity'] = function_exists('molavi_domain_dashboard_activity') && (bool)get_option('molavi_state_active',false);
        $browserConfig['dashboardBootstrap'] = molavi_owner_dashboard_native();
        $browserConfig['workbenchDismissalNonce'] = wp_create_nonce('molavi_owner_workbench_dismissals');
        $browserConfig['workbenchDismissals'] = molavi_owner_workbench_dismissals_get();
        $workflowSources = [
            'ai_confirmations' => ['/pipeline/ai-confirmations', 'owner:/pipeline/ai-confirmations'],
            'reference_items' => ['/reference/item-reviews', 'owner:/reference/item-reviews'],
            'supplier_prices' => ['/pipeline/supplier-price-evidence', 'owner:/pipeline/supplier-price-evidence'],
            'sheet_conflicts' => ['/pipeline/sheet-conflicts', 'owner:/pipeline/sheet-conflicts'],
            'publication_holds' => ['/pipeline/publication-holds', 'owner:/pipeline/publication-holds'],
            'product_status' => ['/pipeline/product-status-snapshots?view=current', 'owner:/pipeline/product-status-snapshots'],
            'reference_decisions' => ['/reference/decisions', 'owner:/reference/decisions'],
            'reconciliation' => ['/pipeline/reconciliation?limit=200', 'owner:/pipeline/operations-dashboard'],
            'migration_shadow' => ['/pipeline/migration-shadow', 'owner:/pipeline/migration-shadow'],
            'telegram_operators' => ['/pipeline/telegram-operator-chats', 'owner:/pipeline/telegram-operator-chats'],
        ];
        $browserConfig['workflowApis'] = [];
        foreach ($workflowSources as $key=>$source) {
            $scopedToken = molavi_owner_workflow_token($source[1]);
            if ($scopedToken === '') { continue; }
            $browserConfig['workflowApis'][$key] = [
                'url' => rtrim($base, '/') . $source[0],
            ];
        }
    }
    if (in_array($page, ['molavi-operations','molavi-publication-holds','molavi-sheet-conflicts',
        'molavi-ai-confirmations','molavi-reference-items','molavi-supplier-prices'], true)) {
        $browserConfig['reviewStoreUrl'] = molavi_owner_workflow_relative_admin_url('admin-ajax.php');
        $browserConfig['reviewStoreNonce'] = wp_create_nonce('molavi_owner_review_store');
    }
    wp_localize_script('molavi-owner-workflows', 'molaviOwnerWorkflow', $browserConfig);
});

function molavi_owner_workflow_mount(string $loading): void {
    if (function_exists('wp_script_is') && !wp_script_is('molavi-owner-workflows', 'enqueued')) {
        echo '<div class="notice notice-error"><p>'
            . esc_html('اتصال امن این صفحه آماده نشد. تنظیم سرور و دسترسی مالک را بررسی کنید و صفحه را تازه کنید.')
            . '</p></div>';
        return;
    }
    echo '<div id="molavi-owner-workflow" aria-live="polite"><p>' . esc_html($loading) . '</p></div>';
}
