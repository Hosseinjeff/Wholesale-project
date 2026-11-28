<?php
/** Plugin Name: Molavi Supplier Link Checks (round eight) */
declare(strict_types=1);
defined('ABSPATH') || exit;

/**
 * Autonomous re-verification of stale supplier prices by re-reading the
 * price's ORIGINAL LINK (the supplier's Telegram post) live and comparing
 * it with the stored baseline (Original Message + extracted Sale Price).
 *
 * Three distinct outcomes per item, kept separate everywhere:
 *   (a) unchanged — link checked, no price change. The item goes on the
 *       owner's confirm list; ONLY his confirmation (the existing manual
 *       supplier-price verification endpoint) or the owner-approved
 *       auto-verify (both evidences solid: link-check 'unchanged' AND
 *       the reference catalog holds the same regular price, method
 *       'auto', molavi-domain-supplier-auto-verify.php) re-verifies it.
 *   (b) changed — link checked, price changed. The new price is recorded
 *       as surfaced evidence ONLY. It is never written to any price field,
 *       never written as verification, and the item stays held.
 *   (c) uncheckable — the link cannot be checked (no URL, Bale source,
 *       private/deleted post, fetch blocked/failed, price not readable).
 *       Reported separately, grouped by reason code. NEVER on the
 *       owner's confirm list.
 *
 * The stored MessageData/Offers copy is the BASELINE for comparison only.
 * A supplier can edit a Telegram post at any time, and an edit is only
 * captured if an edited_* update is delivered over the webhook; absence
 * of a delivered edit is NOT proof of no edit. The stored copy alone
 * therefore never produces outcome (a).
 *
 * This runner never sets or adopts a supplier price and never writes
 * supplier_price_evidence. Only the owner's manual verify (method
 * 'manual', molavi_domain_verify_price) or the owner-approved
 * auto-verify (method 'auto', molavi-domain-supplier-auto-verify.php)
 * resets the 14-day clock.
 *
 * Cadence/lease/event pattern reused from the market-source refresh
 * (molavi-domain-market-sources.php): one item per tick, 5-minute lease,
 * 24-hour re-check cadence, an events table for audit. No publish job is
 * queued: a link check changes no WooCommerce state (no price is adopted,
 * no product is re-projected), so there is nothing for the publish
 * worker to project; the workbench reads these tables directly.
 */

const MOLAVI_LINK_CHECK_SECONDS = 86400;
const MOLAVI_LINK_CHECK_LEASE_SECONDS = 300;
const MOLAVI_LINK_CHECK_RETRY_SECONDS = 3600;
const MOLAVI_LINK_CHECK_NO_URL_SECONDS = 604800;

/** Reason codes. C-S* map to the investigation's uncheckable categories. */
function molavi_domain_supplier_link_check_reason_label(string $code): string {
    return [
        'identical'=>'متن پیام بدون تغییر',
        'price_unchanged'=>'قیمت بدون تغییر (متن پیام تغییر کرده)',
        'price_changed'=>'قیمت در پیام منبع تغییر کرده',
        'no_url'=>'پیوند منبع ثبت نشده (C-S1)',
        'channel_unavailable'=>'کانال خصوصی یا در دسترس نیست (C-S2)',
        'post_not_found'=>'پست منبع حذف شده (C-S3)',
        'bale_source'=>'منبع بله است؛ خوانندهٔ بله نداریم (C-S4)',
        'legacy_no_reference'=>'ردیف قدیمی؛ مرجع قابل‌استفاده ندارد (C-S5)',
        'fetch_blocked'=>'تلگرام جلوی خواندن را گرفت (C-S2؟)',
        'fetch_failed'=>'خواندن لینک ناموفق بود',
        'price_not_found'=>'قیمت در متن خوانده‌شده پیدا نشد (C-S6؟)',
        'source_stock_changed'=>'پیام تأمین‌کننده اکنون به اتمام موجودی اشاره دارد؛ موجودی کالا بررسی شود',
        'source_pack_changed'=>'مشخصات بسته یا اندازه با پیام قبلی تطابق ندارد',
        'price_ambiguous'=>'چند قیمت در پیام است؛ قیمت کالا مشخص نیست',
        'bot_forward_unavailable'=>'خواندن با ربات تنظیم نشده',
    ][$code] ?? $code;
}

/** Parse a stored Source Post URL. Returns ['kind'=>telegram|bale,'channel'=>,'message_id'=>] or null. */
function molavi_domain_supplier_link_check_url(?string $url): ?array {
    if (!is_string($url) || $url === '' || strlen($url) > 1600) return null;
    if (!preg_match('~^https://(?:t\.me|bale\.ai)/([A-Za-z0-9_]{5,64})/([0-9]{1,12})$~D', trim($url), $m)) return null;
    return ['kind'=>str_starts_with($url, 'https://bale.ai/') ? 'bale' : 'telegram',
        'channel'=>$m[1], 'message_id'=>$m[2]];
}

/** Stable check reference for the owner's confirmation note: link-check:<product>@<checked_at>. */
function molavi_domain_supplier_link_check_ref(string $productId, int $checkedAt): string {
    return 'link-check:'.$productId.'@'.$checkedAt;
}

/** Structured provenance note for the manual-verify call after a no-change check. */
function molavi_domain_supplier_link_check_confirm_note(string $productId, string $url, int $checkedAt): string {
    $at = gmdate('Y-m-d\TH:i:s\Z', $checkedAt);
    return 'بررسی لینک تأمین‌کننده: قیمت بدون تغییر · '.molavi_domain_supplier_link_check_ref($productId, $checkedAt)
        .' · '.$url.' · '.$at.' — مالک با تأمین‌کننده تأیید کرد.';
}

/**
 * Extract the visible text of a t.me embed page.
 * Returns the message text, or null when the page carries no message
 * (channel preview, deleted post, or an unexpected layout).
 */
function molavi_domain_supplier_link_check_text(string $html): ?string {
    if (class_exists('DOMDocument')) {
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html); } catch (Throwable $e) { libxml_clear_errors(); libxml_use_internal_errors($prev); return null; }
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' tgme_widget_message_text ')]");
        if ($nodes !== false && $nodes->length > 0) {
            $text = trim(html_entity_decode((string)$nodes->item(0)->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            return $text !== '' ? $text : null;
        }
        return null;
    }
    if (!preg_match('~<div[^>]*\btgme_widget_message_text\b[^>]*>(.*?)</div>~is', $html, $m)) return null;
    $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $text !== '' ? $text : null;
}

/** Fetch a t.me post page. $fetch is an injected fn(string $url): ['status'=>int,'body'=>string] (harness seam). */
function molavi_domain_supplier_link_check_fetch_tme(string $url, ?callable $fetch = null): array {
    if ($fetch !== null) {
        $response = $fetch($url.'?embed=1');
        $status = (int)($response['status'] ?? 0);
        $body = (string)($response['body'] ?? '');
    } else {
        // Default path: the staging host cannot reach t.me at all, so the
        // fetch runs on Railway (POST /pipeline/telegram-fetch), which
        // appends ?embed=1 itself for post URLs — the CLEAN url goes over,
        // no suffix. Railway answers with the extracted message text.
        $fetched = molavi_domain_telegram_fetch($url);
        if ($fetched === null) return ['code'=>'fetch_failed'];
        $status = (int)$fetched['status'];
        if ($status === 429 || $status === 403) return ['code'=>'fetch_blocked', 'status'=>$status];
        if ($status === 404) return ['code'=>'post_maybe_gone', 'status'=>$status];
        if ($status !== 200) return ['code'=>'fetch_failed', 'status'=>$status];
        $text = $fetched['text'];
        if ($text === null) return ['code'=>'channel_unavailable', 'status'=>$status];
        return ['text'=>$text, 'route'=>'railway'];
    }
    if ($status === 429 || $status === 403) return ['code'=>'fetch_blocked', 'status'=>$status];
    if ($status === 404) return ['code'=>'post_maybe_gone', 'status'=>$status];
    if ($status !== 200) return ['code'=>'fetch_failed', 'status'=>$status];
    $text = molavi_domain_supplier_link_check_text($body);
    if ($text === null) return ['code'=>'channel_unavailable', 'status'=>$status];
    return ['text'=>$text, 'route'=>'tme'];
}

/** Fetch a t.me channel page (200 = public channel exists; anything else = private/gone). */
function molavi_domain_supplier_link_check_channel_exists(string $channel, ?callable $fetch = null): bool {
    $url = 'https://t.me/'.$channel;
    if ($fetch !== null) return (int)($fetch($url)['status'] ?? 0) === 200;
    // Default path runs on Railway like the post fetch: the staging host
    // cannot reach t.me, so the channel page is fetched there instead.
    $fetched = molavi_domain_telegram_fetch($url);
    return $fetched !== null && (int)$fetched['status'] === 200;
}

/**
 * Bot API forward route (G(2)) — DESIGNED, NOT BUILT.
 * A private post cannot be read from t.me, but the bot can forward it
 * (forwardMessage/copyMessage from from_chat_id + message_id) into a
 * staging/operator chat and read the forwarded text — READING, never
 * messaging the supplier. Not built in this round because no code calls
 * forwardMessage/copyMessage today, bot membership in supplier channels
 * is not recorded, and no staging/operator target chat is configured for
 * this. Seam: the runner calls this only when the t.me route fails with
 * fetch_blocked/fetch_failed; it returns not-configured today, so the
 * t.me outcome stands. To build it: record bot membership per supplier
 * channel, configure a target chat, call forwardMessage, read the
 * forwarded message text, and return ['text'=>..., 'route'=>'bot_forward'].
 */
function molavi_domain_supplier_link_check_bot_forward(string $channel, string $messageId): array {
    return ['ok'=>false, 'code'=>'bot_forward_unavailable',
        'detail'=>'Bot API forward route is designed but not built: no bot membership or target chat is configured.'];
}

/** Read money only from the identified supplier item. Keep retail, unit and carton roles separate. */
function molavi_domain_supplier_item_prices(string $text, string $productName, array &$sourceSpec): array {
    $sourceSpec = [];
    $titleKey=static fn(string $value): string => trim((string)preg_replace('/[^\pL\pN]+/u',' ',molavi_domain_extract_label_text($value)));
    $key=$titleKey($productName);
    if($key==='')return [];
    $active=false;$seen=false;$prices=[];
    // Slash and Arabic separators may group thousands. A decimal with a multiplier
    // (1.5 million) is distinct from an amount grouped as 1/500/000.
    $number='(?<amount>\d{1,3}(?:[,٬٫./]\d{3})+|\d+(?:[.٫]\d{1,2})?)';
    $count = '(?:(?:در\s+)?(?:کارتن|باکس|بسته|تعداد)\s*[:：]?\s*)?\d{1,4}\s*(?:عددی|تایی|عدد)';
    $measure = '\d+(?:[٫./]\d+)?\s*(?:میلی\s*لیتری?|لیتری?|کیلوگرمی?|گرمی?)';
    $metadata = '~^\s*(?:'.$count.'|'.$measure.')(?:\s*[،,]\s*(?:'.$count.'|'.$measure.'))*\s*$~uD';
    $money='~^\s*[:：=\-–]?\s*'.$number.'\s*(?<scale>هزار|میلیون)?\s*(?<currency>تومان|تومن|ریال)?\s*[.!،,؛]*\s*$~uD';
    foreach(preg_split('/\R/u',$text) ?: [] as $line) {
        $line=trim(molavi_domain_extract_label_text($line));
        if($line==='')continue;
        $labels=molavi_domain_extract_price_labels($line);
        $prefix=$labels?substr($line,0,$labels[0]['start']):$line;
        $lineKey = $titleKey($prefix);
        $withoutCount = preg_replace('~\s+'.$count.'$~uD', '', $lineKey);
        if ($lineKey === $key || $withoutCount === $key) {
            if ($seen) return []; // Multiple matching pack variants need an owner.
            $sourceSpec[] = $lineKey;
            $seen=$active=true;
            if(!$labels)continue;
        } elseif(!$active)continue;
        elseif($labels && $titleKey($prefix)!=='') { $active=false;continue; }
        if(!$labels) {
            $details = preg_replace('/^[✅✔\x{FE0F}\s]+/u', '', $line);
            if (preg_match($metadata, $details)) {
                // Keep the decimal separator in a volume or weight.
                $sourceSpec[] = preg_replace('/\s+/u', ' ', $details);
                continue;
            }
            if(!preg_match($money,$line,$m) || empty($m['currency'])) { $active=false;continue; }
            $labels=[['role'=>'stated','end'=>0]];
        }
        foreach($labels as $i=>$label) {
            $role=$label['role'];
            if(!in_array($role,['unit','package','sale','stated'],true))continue;
            $end=$labels[$i+1]['start']??strlen($line);
            $suffix=substr($line,$label['end'],$end-$label['end']);
            if(!preg_match($money,$suffix,$m))continue;
            $raw=$m['amount'];
            $grouped=preg_match('~^\d{1,3}(?:[,٬٫./]\d{3})+$~uD',$raw);
            $value=(float)($grouped?preg_replace('/\D/','',$raw):str_replace('٫','.',$raw));
            if(($m['scale']??'')==='هزار')$value*=1000;
            if(($m['scale']??'')==='میلیون')$value*=1000000;
            if(($m['currency']??'')==='ریال')$value/=10;
            $amount=(int)round($value);
            if($amount>=1000 && $amount<=100000000)$prices[$role][$amount]=true;
        }
    }
    return $prices;
}

/**
 * Pure outcome decision. $stored: url, message (stored Original Message),
 * sale_price (stored Sale Price), name. $live: null (no fetch attempted)
 * or the fetch result from molavi_domain_supplier_link_check_fetch_tme
 * (['text'=>...] or ['code'=>...]). Never touches the database, never
 * writes evidence or any price. Returns outcome/reason/live_price/live_text.
 */
function molavi_domain_supplier_link_check_classify(array $stored, ?array $live): array {
    $url = trim((string)($stored['url'] ?? ''));
    $message = (string)($stored['message'] ?? '');
    $parsed = $url !== '' ? molavi_domain_supplier_link_check_url($url) : null;
    if ($url === '') {
        return ['outcome'=>'uncheckable', 'reason_code'=>$message !== '' ? 'no_url' : 'legacy_no_reference',
            'live_price'=>null, 'live_text'=>null, 'route'=>null];
    }
    if ($parsed === null) {
        return ['outcome'=>'uncheckable', 'reason_code'=>'no_url', 'live_price'=>null, 'live_text'=>null, 'route'=>null];
    }
    if ($parsed['kind'] === 'bale') {
        return ['outcome'=>'uncheckable', 'reason_code'=>'bale_source', 'live_price'=>null, 'live_text'=>null, 'route'=>null];
    }
    if ($live === null) {
        // No live re-read happened: the stored copy alone is never evidence.
        return ['outcome'=>'uncheckable', 'reason_code'=>'fetch_failed', 'live_price'=>null, 'live_text'=>null, 'route'=>null];
    }
    $code = (string)($live['code'] ?? '');
    if ($code !== '') {
        return ['outcome'=>'uncheckable', 'reason_code'=>$code, 'live_price'=>null, 'live_text'=>null,
            'route'=>$live['route'] ?? null];
    }
    $liveText = (string)($live['text'] ?? '');
    if ($liveText === '') {
        return ['outcome'=>'uncheckable', 'reason_code'=>'price_not_found', 'live_price'=>null, 'live_text'=>null, 'route'=>$live['route'] ?? null];
    }
    $storedPrice = molavi_domain_positive_price($stored['sale_price'] ?? null);
    $storedText = molavi_domain_identity_text($message);
    $liveNormalized = molavi_domain_identity_text($liveText);
    // The route the live text came over ('railway' now, 'tme' for injected
    // fetches, 'bot_forward' for the forward seam) travels with the decision.
    $route = $live['route'] ?? null;
    if ($storedText !== '' && $storedText === $liveNormalized) {
        return ['outcome'=>'unchanged', 'reason_code'=>'identical',
            'live_price'=>$storedPrice > 0 ? (string)$storedPrice : null, 'live_text'=>$liveText, 'route'=>$route];
    }
    $name=(string)($stored['name']??'');
    // A post can describe several products. A new sold-out notice needs an owner to resolve its scope.
    if (molavi_domain_extract_explicit_stock_status($liveText) === 'Out of Stock'
        && molavi_domain_extract_explicit_stock_status($message) !== 'Out of Stock') {
        return ['outcome'=>'uncheckable','reason_code'=>'source_stock_changed','live_price'=>null,'live_text'=>$liveText,'route'=>$route];
    }
    $beforeSpec = $afterSpec = [];
    $before=molavi_domain_supplier_item_prices($message,$name,$beforeSpec);
    $after=molavi_domain_supplier_item_prices($liveText,$name,$afterSpec);
    if ($before && $after && $beforeSpec !== $afterSpec) {
        return ['outcome'=>'uncheckable','reason_code'=>'source_pack_changed','live_price'=>null,'live_text'=>$liveText,'route'=>$route];
    }
    $roles=array_keys(array_filter($before,static fn($amounts)=>count($amounts)===1&&isset($amounts[$storedPrice])));
    $prices=count($roles)===1?array_keys($after[$roles[0]]??[]):[];
    if(count($prices)!==1) {
        return ['outcome'=>'uncheckable','reason_code'=>$after?'price_ambiguous':'price_not_found',
            'live_price'=>null,'live_text'=>$liveText,'route'=>$route];
    }
    $livePrice = (string)$prices[0];
    if ($storedPrice > 0 && $prices[0] === $storedPrice) {
        return ['outcome'=>'unchanged', 'reason_code'=>'price_unchanged', 'live_price'=>$livePrice, 'live_text'=>$liveText, 'route'=>$route];
    }
    return ['outcome'=>'changed', 'reason_code'=>'price_changed', 'live_price'=>$livePrice, 'live_text'=>$liveText, 'route'=>$route];
}

function molavi_domain_supplier_link_check_record_event(SQLite3 $db, array $row, string $kind, ?string $old, ?string $live, ?string $code, int $now): string {
    $id = bin2hex(random_bytes(16));
    molavi_domain_sql($db, 'INSERT INTO supplier_link_check_events
        (id,product_id,offer_id,title,kind,old_price,live_price,code,url,occurred_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$id, $row['product_id'], $row['offer_id'], $row['title'], $kind, $old, $live, $code, $row['url'], $now]);
    return $id;
}

function molavi_domain_supplier_link_check_row(SQLite3 $db, string $productId): ?array {
    return molavi_domain_row($db, 'SELECT * FROM supplier_link_checks WHERE product_id=?', [$productId]);
}

function molavi_domain_supplier_link_check_public_row(array $row): array {
    return [
        'product_id'=>$row['product_id'], 'offer_id'=>$row['offer_id'], 'title'=>$row['title'], 'url'=>$row['url'],
        'source_revision'=>$row['source_revision'], 'stored_sale_price'=>$row['stored_sale_price'],
        'outcome'=>$row['outcome'], 'reason_code'=>$row['reason_code'], 'reason_label'=>molavi_domain_supplier_link_check_reason_label((string)($row['reason_code'] ?? '')),
        'live_price'=>$row['live_price'], 'checked_at'=>$row['checked_at'] === null ? null : (int)$row['checked_at'],
        'next_at'=>(int)$row['next_at'], 'route'=>$row['route'],
        'check_ref'=>$row['checked_at'] === null ? null : molavi_domain_supplier_link_check_ref($row['product_id'], (int)$row['checked_at']),
        'confirmed_at'=>$row['confirmed_at'] === null ? null : (int)$row['confirmed_at'],
        'confirmed_verified_at'=>$row['confirmed_verified_at'],
    ];
}

/** Recover only a link already recorded for this exact supplier message. */
function molavi_domain_supplier_recorded_links(array $offers, array $messages, array $products = []): array {
    // Older offers may only be linked in the Products -> Latest Offer ID direction.
    $productCounts = [];
    $currentProduct = [];
    foreach ($products as $product) {
        $productId = trim((string)($product['Product ID'] ?? ''));
        $offerId = trim((string)($product['Latest Offer ID'] ?? ''));
        $productCounts[$productId] = ($productCounts[$productId] ?? 0) + 1;
        if ($offerId !== '') {
            $currentProduct[$offerId] = array_key_exists($offerId, $currentProduct) ? '' : $productId;
        }
    }
    $messageLinks=[];
    foreach ($messages as $message) {
        $channel=(string)($message['Channel Username']??'');
        $batch=(string)($message['Batch ID']??'');
        $id=(string)($message['ID']??'');
        $url=trim((string)($message['URL']??''));
        if ($channel==='' || $batch==='' || $id==='' || !molavi_domain_supplier_link_check_url($url)) continue;
        $key=molavi_domain_json([$channel,$batch,$id,(string)($message['Content']??'')]);
        $messageLinks[$key][$url]=true;
    }
    $links=[];
    foreach ($offers as $offer) {
        $id=(string)($offer['Offer ID']??'');
        if ($id==='') continue;
        $url=trim((string)($offer['Source Post URL']??''));
        $channel = (string)($offer['Channel Username'] ?? '');
        $message = (string)($offer['Original Message'] ?? '');
        $key = molavi_domain_json([$channel, (string)($offer['Batch ID'] ?? ''), (string)($offer['Message ID'] ?? ''), $message]);
        $matches = $messageLinks[$key] ?? [];
        if ($url === '' && count($matches) === 1) $url = array_key_first($matches);
        $productId = trim((string)($offer['Product ID'] ?? ''));
        if ($productId === '') {
            $productId = $currentProduct[$id] ?? '';
            $parsed = molavi_domain_supplier_link_check_url($url);
            if (($productCounts[$productId] ?? 0) !== 1 || !$parsed
                || strcasecmp($parsed['channel'], ltrim($channel, '@')) !== 0
                || ($matches && (count($matches) !== 1 || !isset($matches[$url])))) {
                $productId = '';
            }
        }
        // Duplicate offer IDs cannot authorize a source selection.
        $links[$id] = !array_key_exists($id, $links) && $productId !== '' && $url !== ''
            ? ['product'=>$productId, 'channel'=>$channel, 'message'=>$message, 'url'=>$url] : null;
    }
    return $links;
}

/**
 * Seed due rows from the Products sheet: EVERY product gets a row, not
 * only items whose supplier price is stale. The state filter used to
 * be ['needs_review', 'blocked'], so a product whose price was
 * currently verified never got a row — the cappuccino
 * (p_2495a6cc4a8a643c0946ca0d, price owner-confirmed 2026-11-24) had
 * none, and neither did 12 other products, so the card could never
 * show whether their source post still exists. A new row (or a changed
 * revision) is due now; uncheckable rows (no URL / Bale) are classified
 * at once, without a fetch, and revisited in a week or when the revision
 * changes. Existing rows are left alone when nothing changed, so the
 * check cadence for products that already have rows is untouched.
 * Returns the number of rows seeded/updated.
 */
function molavi_domain_supplier_link_check_seed(int $now): int {
    $tables = molavi_domain_sheets(['Products','Offers','MessageData'], true);
    $records = molavi_domain_records($tables['Products'] ?? []);
    $links = molavi_domain_supplier_recorded_links(molavi_domain_records($tables['Offers'] ?? []),
        molavi_domain_records($tables['MessageData'] ?? []), $records);
    $fresh = molavi_domain_freshness($records);
    $byId = [];
    foreach ($fresh as $offer) $byId[$offer['product_id']] = $offer;
    $seeded = 0;
    molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($records, $byId, $links, $now, &$seeded): void {
        foreach ($records as $record) {
            $id = trim((string)($record['Product ID'] ?? ''));
            if ($id === '' || !isset($byId[$id])) continue;
            $offer = $byId[$id];
            $url = trim((string)($record['Source Post URL'] ?? ''));
            $source = $links[(string)($record['Latest Offer ID']??'')]??null;
            if ($url==='' && $source && $source['product']===$id && $source['channel']===($record['Channel Username']??'')
                && $source['message']!=='' && $source['message']===($record['Original Message']??'')) $url=$source['url'];
            $revision = (string)($offer['source_revision'] ?? '');
            $existing = molavi_domain_supplier_link_check_row($db, $id);
            $stored = ['url'=>$url, 'message'=>(string)($record['Original Message'] ?? ''),
                'sale_price'=>$record['Sale Price'] ?? null, 'name'=>(string)($record['Product Name'] ?? '')];
            if ($existing && $existing['url'] === $url && $existing['offer_id'] === (string)($offer['offer_id'] ?? ($record['Latest Offer ID'] ?? ''))
                && $existing['stored_message'] === $stored['message'] && $existing['source_revision'] === $revision && $existing['stored_sale_price'] === (string)($record['Sale Price'] ?? '')) continue;
            $row = [
                'product_id'=>$id, 'offer_id'=>(string)($offer['offer_id'] ?? ($record['Latest Offer ID'] ?? '')),
                'title'=>(string)($record['Product Name'] ?? ''), 'url'=>$url, 'source_revision'=>$revision,
                'stored_sale_price'=>(string)($record['Sale Price'] ?? ''), 'stored_message'=>$stored['message'],
            ];
            if ($existing) {
                molavi_domain_sql($db, "UPDATE supplier_link_checks SET offer_id=?,title=?,url=?,source_revision=?,stored_sale_price=?,
                    stored_message=?,outcome=NULL,reason_code=NULL,live_price=NULL,live_text=NULL,route=NULL,checked_at=NULL,
                    next_at=?,lease=NULL,confirmed_at=NULL,confirmed_verified_at=NULL WHERE product_id=?",
                    [$row['offer_id'], $row['title'], $url, $revision, $row['stored_sale_price'], $row['stored_message'], $now, $id]);
                $row = array_merge($existing, $row);
            } else {
                molavi_domain_sql($db, 'INSERT INTO supplier_link_checks
                    (product_id,offer_id,title,url,source_revision,stored_sale_price,stored_message,next_at,created_at)
                    VALUES(?,?,?,?,?,?,?,?,?)',
                    [$id, $row['offer_id'], $row['title'], $url, $revision, $row['stored_sale_price'], $row['stored_message'], $now, $now]);
            }
            $seeded++;
            // Uncheckable without any network: classify at once, revisit weekly.
            $parsed = $url !== '' ? molavi_domain_supplier_link_check_url($url) : null;
            if ($url === '' || $parsed === null || $parsed['kind'] === 'bale') {
                $decision = molavi_domain_supplier_link_check_classify($stored, null);
                molavi_domain_sql($db, 'UPDATE supplier_link_checks SET outcome=?,reason_code=?,next_at=? WHERE product_id=?',
                    [$decision['outcome'], $decision['reason_code'], $now + MOLAVI_LINK_CHECK_NO_URL_SECONDS, $id]);
                molavi_domain_supplier_link_check_record_event($db, $row, 'uncheckable', null, null, $decision['reason_code'], $now);
            }
        }
    });
    return $seeded;
}

/** Run one due check (lease 5 min). Returns the outcome row, or null when nothing is due. */
function molavi_domain_supplier_link_check_run_one(int $now, ?callable $fetch = null): ?array {
    $lease = bin2hex(random_bytes(12));
    $row = molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($now, $lease): ?array {
        $due = molavi_domain_row($db, 'SELECT * FROM supplier_link_checks
            WHERE next_at<=? AND confirmed_at IS NULL ORDER BY next_at,product_id LIMIT 1', [$now]);
        if ($due) molavi_domain_sql($db, 'UPDATE supplier_link_checks SET lease=?,next_at=? WHERE product_id=?',
            [$lease, $now + MOLAVI_LINK_CHECK_LEASE_SECONDS, $due['product_id']]);
        return $due;
    });
    if (!$row) return null;
    return molavi_domain_supplier_link_check_execute($row, $lease, $now, $fetch);
}

/** Force-check one product now (owner-triggered), regardless of cadence. */
function molavi_domain_supplier_link_check_run_product(string $productId, int $now, ?callable $fetch = null): ?array {
    $lease = bin2hex(random_bytes(12));
    $row = molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($productId, $now, $lease): ?array {
        $due = molavi_domain_row($db, 'SELECT * FROM supplier_link_checks
            WHERE product_id=? AND (lease IS NULL OR next_at<=?) AND confirmed_at IS NULL', [$productId, $now]);
        if ($due) molavi_domain_sql($db, 'UPDATE supplier_link_checks SET lease=?,next_at=? WHERE product_id=?',
            [$lease, $now + MOLAVI_LINK_CHECK_LEASE_SECONDS, $productId]);
        return $due;
    });
    if (!$row) return molavi_domain_read('pipeline', static fn(SQLite3 $db) => ($found = molavi_domain_supplier_link_check_row($db, $productId))
        ? molavi_domain_supplier_link_check_public_row($found) : null);
    return molavi_domain_supplier_link_check_execute($row, $lease, $now, $fetch);
}

/** Execute a check for an already-leased row. Writes the outcome row + event. Never writes evidence or any price. */
function molavi_domain_supplier_link_check_execute(array $row, string $lease, int $now, ?callable $fetch = null): array {
    // A due row whose price is current again (owner confirmed, or a fresh
    // source observation) needs no check: park it for a day, no event.
    try {
        $tables = molavi_domain_sheets(['Products'], true);
        foreach (molavi_domain_records($tables['Products'] ?? []) as $record) {
            if (trim((string)($record['Product ID'] ?? '')) !== $row['product_id']) continue;
            $state = molavi_domain_freshness([$record], null, false)[0]['state'] ?? null;
            if ($state === 'current') {
                molavi_domain_tx('pipeline', static fn(SQLite3 $db) => molavi_domain_sql($db,
                    'UPDATE supplier_link_checks SET lease=NULL,next_at=? WHERE product_id=? AND lease=?',
                    [$now + MOLAVI_LINK_CHECK_SECONDS, $row['product_id'], $lease]));
                return array_merge(molavi_domain_supplier_link_check_public_row($row), ['skipped'=>'already_current']);
            }
            break;
        }
    } catch (Throwable $e) {
        // A sheet read failure must not lose the check: fall through and fetch.
    }
    $stored = ['url'=>$row['url'], 'message'=>$row['stored_message'], 'sale_price'=>$row['stored_sale_price'], 'name'=>$row['title']];
    $parsed = molavi_domain_supplier_link_check_url($row['url']);
    $live = null;
    if ($parsed !== null && $parsed['kind'] === 'telegram') {
        $live = molavi_domain_supplier_link_check_fetch_tme($row['url'], $fetch);
        if (($live['code'] ?? '') === 'post_maybe_gone') {
            // Split C-S3 (deleted post) from C-S2 (private/gone channel):
            // a public channel page still resolves when only the post is gone.
            $live = ['code'=>molavi_domain_supplier_link_check_channel_exists($parsed['channel'], $fetch)
                ? 'post_not_found' : 'channel_unavailable'];
        } elseif (($live['code'] ?? '') === 'channel_unavailable') {
            // Same split for the 200-with-no-text fetch: t.me serves
            // HTTP 200 "Post not found" for a deleted post, and the
            // Railway fetch returns null text for it — indistinguishable
            // from a channel problem without this check. The cappuccino
            // (t.me/bonakdarjavan/1337) was misclassified as
            // channel_unavailable this way; the channel is alive, only
            // the post is gone. A channel that genuinely does not exist
            // still lands on channel_unavailable.
            $live = ['code'=>molavi_domain_supplier_link_check_channel_exists($parsed['channel'], $fetch)
                ? 'post_not_found' : 'channel_unavailable'];
        } elseif (in_array($live['code'] ?? '', ['fetch_blocked', 'fetch_failed'], true)) {
            $forward = molavi_domain_supplier_link_check_bot_forward($parsed['channel'], $parsed['message_id']);
            if (!empty($forward['ok']) && !empty($forward['text'])) $live = ['text'=>$forward['text'], 'route'=>'bot_forward'];
            // Otherwise the t.me outcome stands; the forward seam stays recorded in the code path.
        }
    }
    $decision = molavi_domain_supplier_link_check_classify($stored, $live);
    $nextAt = $decision['outcome'] === 'uncheckable' && in_array($decision['reason_code'], ['fetch_blocked', 'fetch_failed'], true)
        ? $now + MOLAVI_LINK_CHECK_RETRY_SECONDS
        : $now + MOLAVI_LINK_CHECK_SECONDS;
    $result = molavi_domain_tx('pipeline', static function (SQLite3 $db) use ($row, $lease, $decision, $nextAt, $now): array {
        $updated = molavi_domain_sql($db, 'UPDATE supplier_link_checks SET lease=NULL,confirmed_at=NULL,confirmed_verified_at=NULL,outcome=?,reason_code=?,live_price=?,live_text=?,route=?,checked_at=?,next_at=? WHERE product_id=? AND lease=?',
            [$decision['outcome'], $decision['reason_code'], $decision['live_price'], $decision['live_text'],
                $decision['route'], $now, $nextAt, $row['product_id'], $lease]);
        if ($updated['changes'] !== 1) {
            return molavi_domain_supplier_link_check_public_row(molavi_domain_supplier_link_check_row($db, $row['product_id']) ?? $row);
        }
        molavi_domain_supplier_link_check_record_event($db, $row, $decision['outcome'],
            $row['stored_sale_price'], $decision['live_price'], $decision['reason_code'], $now);
        $fresh = molavi_domain_supplier_link_check_row($db, $row['product_id']);
        return $fresh ? molavi_domain_supplier_link_check_public_row($fresh) : molavi_domain_supplier_link_check_public_row($row);
    });
    return $result;
}

/** Mark a no-change check confirmed after the owner's manual verify. Idempotent on the same verified_at. */
function molavi_domain_supplier_link_check_confirm(string $productId, string $checkRef, string $verifiedAt, string $actor, int $now): array {
    return molavi_domain_tx('pipeline', static fn(SQLite3 $db) =>
        molavi_domain_supplier_link_check_confirm_receipt($db, $productId, $checkRef, $verifiedAt, $actor, $now));
}

/** Called in the verification transaction, or by an older client acknowledging its saved receipt. */
function molavi_domain_supplier_link_check_confirm_receipt(SQLite3 $db, string $productId, string $checkRef,
    string $verifiedAt, string $actor, int $now): array {
    $row = molavi_domain_supplier_link_check_row($db, $productId);
    if (!$row || $row['lease'] !== null || $row['outcome'] !== 'unchanged') molavi_domain_fail('link_check_not_confirmable', 409);
    if ($row['checked_at'] === null || molavi_domain_supplier_link_check_ref($productId, (int)$row['checked_at']) !== $checkRef)
        molavi_domain_fail('link_check_mismatch', 409);
    $stamp = molavi_domain_timestamp($verifiedAt);
    if ($stamp === null || $stamp < (int)$row['checked_at']) molavi_domain_fail('link_check_mismatch', 409);
    $proof = molavi_domain_row($db, "SELECT 1 FROM supplier_price_evidence_history
        WHERE offer_id=? AND source_revision=? AND verified_at=? AND verified_by=? AND method IN ('manual','auto')",
        [$productId, $row['source_revision'], $verifiedAt, $actor]);
    if (!$proof) molavi_domain_fail('link_check_mismatch', 409);
    if ($row['confirmed_at'] !== null) {
        if ($row['confirmed_verified_at'] === $verifiedAt) return ['ok'=>true, 'product_id'=>$productId, 'idempotent'=>true];
        molavi_domain_fail('link_check_already_confirmed', 409);
    }
    molavi_domain_sql($db, 'UPDATE supplier_link_checks SET confirmed_at=?,confirmed_verified_at=? WHERE product_id=?',
        [$now, $verifiedAt, $productId]);
    molavi_domain_supplier_link_check_record_event($db, $row, 'confirmed', $row['stored_sale_price'], null, $verifiedAt, $now);
    return ['ok'=>true, 'product_id'=>$productId, 'idempotent'=>false];
}

function molavi_domain_supplier_link_check_lists(): array {
    return molavi_domain_read('pipeline', static function (SQLite3 $db): array {
        $rows = molavi_domain_rows($db, 'SELECT * FROM supplier_link_checks ORDER BY checked_at DESC, product_id');
        $public = array_map('molavi_domain_supplier_link_check_public_row', $rows);
        $confirm = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'unchanged' && $row['confirmed_at'] === null));
        $changed = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'changed'));
        $uncheckable = array_values(array_filter($public, static fn($row) => $row['outcome'] === 'uncheckable'));
        $byReason = [];
        foreach ($uncheckable as $row) $byReason[(string)($row['reason_code'] ?? 'unknown')][] = $row;
        ksort($byReason);
        $counts = ['total'=>count($public), 'unchanged'=>count($confirm),
            'changed'=>count($changed), 'uncheckable'=>count($uncheckable),
            'confirmed'=>count(array_filter($public, static fn($row) => $row['confirmed_at'] !== null)),
            'pending'=>count(array_filter($public, static fn($row) => $row['outcome'] === null))];
        return ['ok'=>true, 'generated_at'=>time(), 'counts'=>$counts,
            'confirm_list'=>$confirm, 'changed'=>$changed, 'uncheckable'=>$uncheckable,
            'uncheckable_by_reason'=>$byReason];
    });
}

function molavi_domain_supplier_link_check_api(string $method, array $query, ?array $body, string $actor): array {
    if ($method === 'GET') {
        $product = trim((string)($query['product_id'] ?? ''));
        if ($product !== '') {
            $row = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_supplier_link_check_row($db, $product));
            if (!$row) molavi_domain_fail('link_check_not_found', 404);
            $events = molavi_domain_read('pipeline', static fn(SQLite3 $db) => molavi_domain_rows($db,
                'SELECT * FROM supplier_link_check_events WHERE product_id=? ORDER BY occurred_at DESC LIMIT 20', [$product]));
            return ['ok'=>true, 'check'=>molavi_domain_supplier_link_check_public_row($row), 'events'=>$events];
        }
        return molavi_domain_supplier_link_check_lists();
    }
    if ($method !== 'POST' || $body === null) molavi_domain_fail('invalid_body', 400);
    $action = $body['action'] ?? '';
    if ($action === 'run') {
        molavi_domain_body_keys($body, ['action']);
        $result = molavi_domain_supplier_link_check_run_one(time());
        return ['ok'=>true, 'ran'=>$result !== null, 'result'=>$result];
    }
    if ($action === 'run_product') {
        molavi_domain_body_keys($body, ['action', 'product_id']);
        $product = $body['product_id'];
        if (!is_string($product) || $product === '' || molavi_domain_strlen($product) > 200) molavi_domain_fail('invalid_product_id', 400);
        $result = molavi_domain_supplier_link_check_run_product($product, time());
        if ($result === null) molavi_domain_fail('link_check_not_found', 404);
        return ['ok'=>true, 'result'=>$result];
    }
    if ($action === 'confirm') {
        molavi_domain_body_keys($body, ['action', 'product_id', 'check_ref', 'verified_at']);
        $product = $body['product_id'];
        $ref = $body['check_ref'];
        $verifiedAt = $body['verified_at'];
        if (!is_string($product) || $product === '' || molavi_domain_strlen($product) > 200
            || !is_string($ref) || $ref === '' || !is_string($verifiedAt) || $verifiedAt === '') molavi_domain_fail('invalid_body', 400);
        return molavi_domain_supplier_link_check_confirm($product, $ref, $verifiedAt, $actor, time());
    }
    if ($action === 'auto_verify') {
        // Owner-approved auto-verify batch (molavi-domain-supplier-auto-verify.php).
        // dry_run=true computes and reports the eligible list + count WITHOUT
        // writing anything; dry_run=false (or omitted) writes, capped at 5/tick.
        $keys = array_keys($body); sort($keys);
        if ($keys !== ['action'] && $keys !== ['action', 'dry_run']) molavi_domain_fail('invalid_body', 400);
        if (array_key_exists('dry_run', $body) && !is_bool($body['dry_run'])) molavi_domain_fail('invalid_body', 400);
        return molavi_domain_auto_verify_tick(time(), null, (bool)($body['dry_run'] ?? false));
    }
    if ($action === 'regular_price_sync') {
        // Owner-approved regular-price catalogue/sheet sync batch
        // (molavi-domain-regular-price-sync.php). dry_run=true computes and
        // reports every decision WITHOUT writing anything; dry_run=false (or
        // omitted) writes, capped at 50 products/tick.
        $keys = array_keys($body); sort($keys);
        if ($keys !== ['action'] && $keys !== ['action', 'dry_run']) molavi_domain_fail('invalid_body', 400);
        if (array_key_exists('dry_run', $body) && !is_bool($body['dry_run'])) molavi_domain_fail('invalid_body', 400);
        return molavi_domain_regular_price_sync_tick(time(), null, (bool)($body['dry_run'] ?? false));
    }
    molavi_domain_fail('invalid_body', 400);
}

/** One minute-tick step: seed stale items (daily), then run a single due check. */
function molavi_domain_supplier_link_check_tick(int $now, ?callable $fetch = null): void {
    if ((int)get_option('molavi_link_check_seed_v3_at', 0) <= $now) {
        molavi_domain_supplier_link_check_seed($now);
        update_option('molavi_link_check_seed_v3_at', $now + MOLAVI_LINK_CHECK_SECONDS, false);
    }
    molavi_domain_supplier_link_check_run_one($now, $fetch);
}
