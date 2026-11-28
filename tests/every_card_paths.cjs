// Every-card paths (FIX-4, owner request 2026-11-26): Node harness.
// Run: node tests/every_card_paths.cjs
// Negative control: EVERY_CARD_SRC=<4ac67ae base file> node tests/every_card_paths.cjs
//   (the new-behaviour checks must fail there; see the differential section).
// Extracts the real functions from includes/owner-workflows.js by brace
// matching, evaluates them against stubbed module state, and asserts:
//   (a) a link-changed card (groups 5+6: stored price vs a new live price)
//       shows the adopt decision as its first-view line, the helper finds
//       the row, and the old-price verify is disabled in the supplier tab
//       (source checks — the DOM rendering itself is verified live);
//   (b) an uncheckable-link card (group 10) gets a supplier-follow-up path
//       (helper + the «پیگیری پیام تأمین‌کننده» button in the supplier tab);
//   (c) an unverified regular-price card (group 2) names the missing
//       deadline and the options in its first-view line;
//   (d) a no-picture card (group 9) gets the where-from line in the
//       autopilot block;
//   (e) a generic «کالا در توقف است.» hold (group 7b) names the underlying
//       gate hold in the autopilot block;
//   (f) regressions — the G1 no-revision path, the G9 fast-confirm text,
//       the G3 hold routing and the expired/missing market texts are
//       unchanged (differential vs the 4ac67ae base);
//   (g) dashboard.js and owner-workflows.css are untouched.
// The routing functions' orthogonal leaves are stubbed to neutral values,
// identically for both versions, so a stub-induced error cancels out of
// the differential comparison. No browser, no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = process.env.EVERY_CARD_SRC
    || path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const cssPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.css');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');
const cssSrc = fs.readFileSync(cssPath, 'utf8');

const BASE = '4ac67ae';
let baseWorkflowsSrc = null;
try {
    baseWorkflowsSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn(`WARNING: could not read the ${BASE} base via git — differential tests skipped:`, error.message);
}

function extractFunction(source, name) {
    const start = source.indexOf(`    function ${name}(`);
    if (start < 0) throw new Error('function not found: ' + name);
    const open = source.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) return source.slice(start, i + 1);
        }
    }
    throw new Error('unbalanced braces in ' + name);
}

function extractConst(source, name) {
    const start = source.indexOf(`    function ${name}(`);
    if (start >= 0) return extractFunction(source, name);
    const constStart = source.indexOf(`const ${name} = `);
    if (constStart < 0) throw new Error('const not found: ' + name);
    const arrowBody = source.indexOf('=> {', constStart);
    const valueStart = source.indexOf('=', constStart) + 1;
    const open = source.indexOf('{', arrowBody >= 0 && arrowBody < valueStart + 60 ? arrowBody : valueStart);
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) {
                let end = i + 1;
                if (source[end] === ';') end++;
                return source.slice(constStart, end);
            }
        }
    }
    throw new Error('unbalanced braces in const ' + name);
}

function extractConstStatement(source, name) {
    const start = source.indexOf(`const ${name} = `);
    if (start < 0) throw new Error('const statement not found: ' + name);
    let i = source.indexOf('=', start) + 1;
    let depth = 0;
    let quote = null;
    let escaped = false;
    for (; i < source.length; i++) {
        const ch = source[i];
        if (quote) {
            if (escaped) { escaped = false; continue; }
            if (ch === '\\') { escaped = true; continue; }
            if (ch === quote) quote = null;
            continue;
        }
        if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
        if (ch === '{' || ch === '[' || ch === '(') depth++;
        else if (ch === '}' || ch === ']' || ch === ')') depth--;
        else if (ch === ';' && depth === 0) return source.slice(start, i + 1);
    }
    throw new Error('unterminated const statement: ' + name);
}

const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];
const G1_FNS = ['workbenchNeedsPriceRegistration'];
const G9_FNS = ['workbenchHasUnchangedLinkCheck'];
const FIX4_FNS = ['workbenchHasChangedLinkCheck', 'workbenchHasUncheckableLinkCheck'];
const FIX4_CONSTS = ['autopilotImageLine', 'autopilotUnderlyingHoldLine'];

function buildSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    for (const name of G1_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
    }
    for (const name of G9_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
    }
    for (const name of FIX4_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
        // Negative-control stub: when the source under test is the
        // unmodified base, the helper does not exist; stub it so the
        // checks FAIL instead of throwing.
        else pieces.push(`function ${name}() { return undefined; }`);
    }
    const hasImageLine = source.includes('const autopilotImageLine = ');
    const hasUnderlyingLine = source.includes('const autopilotUnderlyingHoldLine = ');
    if (hasImageLine) pieces.push(extractConst(source, 'autopilotImageLine'));
    else pieces.push(`const autopilotImageLine = () => '';`);
    if (hasUnderlyingLine) {
        pieces.push(extractConstStatement(source, 'autopilotGateLabels'));
        pieces.push(extractConst(source, 'autopilotUnderlyingHoldLine'));
    } else pieces.push(`const autopilotUnderlyingHoldLine = () => '';`);
    const code = `
let workbenchData = null;
let data = null;
const workbenchErrors = {};
const workbenchDismissalIndex = new Map();
const config = { reviewStoreUrl: 'https://example.test', reviewStoreNonce: 'nonce' };
const workbenchQueue = 'next';
const workbenchStageModel = [];
const workbenchIsDismissed = () => false;
const workbenchWooState = (product) => product._wooState || '';
const safeSourceLink = () => null;
${pieces.join('\n')}
return {
    setData(v) { workbenchData = v; data = v; },
    workbenchActionKey, workbenchNextStep, workbenchReadyAction,
    workbenchHoldRoute,
    ${source.includes('function workbenchNeedsPriceRegistration(') ? 'workbenchNeedsPriceRegistration,' : ''}
    ${source.includes('function workbenchHasUnchangedLinkCheck(') ? 'workbenchHasUnchangedLinkCheck,' : ''}
    workbenchHasChangedLinkCheck, workbenchHasUncheckableLinkCheck,
    autopilotImageLine, autopilotUnderlyingHoldLine,
};`;
    return new Function(code)();
}

const current = buildSandbox(src);
const base = baseWorkflowsSrc ? buildSandbox(baseWorkflowsSrc) : null;

let pass = 0;
let fail = 0;
function check(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  expected:', JSON.stringify(expected), '\n  actual:  ', JSON.stringify(actual)); }
}
function checkSource(label, source, needle) {
    const ok = source.includes(needle);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  missing from source:', JSON.stringify(needle)); }
}

function baseProduct(overrides) {
    return {
        product_id: 'p1',
        key: 'p1',
        name: 'کالای تست',
        channel: 'telegram',
        holds: [],
        ai: [],
        references: [],
        conflicts: [],
        supplierPrices: [],
        status: {
            status: 'imported',
            sale_offer_blocker: '',
            publication_needed: false,
            update_needed: false,
            regular_price_missing: false,
            regular_price_state: 'current',
            latest_offer_id: 'offer-1',
            source_integrity_issues: [],
        },
        ...overrides,
    };
}
function withStatus(product, status) {
    return { ...product, status: { ...product.status, ...status } };
}
function supplierOffer(overrides) {
    return {
        offer_id: 'offer-1', product_id: 'p1', acknowledged: false, source_revision: 'rev-abc123',
        sale_price: 295000, source_post_url: 'https://t.me/channel/123',
        link_check: null,
        ...overrides,
    };
}
const emptyData = { autopilot: { holds: [] } };

// --- (a) link-changed fixtures: رب روژین ۸۰۰ گرم, 295,000 → 298,000 ---
const changedCard = withStatus(baseProduct({ product_id: 'ch-rojin', key: 'ch-rojin', name: 'رب روژین ۸۰۰ گرم' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-ch1',
});
changedCard.supplierPrices = [supplierOffer({ product_id: 'ch-rojin', offer_id: 'offer-ch1',
    sale_price: 295000,
    link_check: { outcome: 'changed', reason_code: 'price_changed', reason_label: 'قیمت تغییر کرده',
        checked_at: 1764100000, check_ref: 'lc-ch1', live_price: 298000, url: 'https://t.me/channel/123' } })];

const changedNoLivePrice = withStatus(baseProduct({ product_id: 'ch-nolive', key: 'ch-nolive' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-ch2',
});
changedNoLivePrice.supplierPrices = [supplierOffer({ product_id: 'ch-nolive', offer_id: 'offer-ch2',
    link_check: { outcome: 'changed', reason_code: 'price_changed', live_price: 0 } })];

const unchangedCard = withStatus(baseProduct({ product_id: 'un-1', key: 'un-1' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-un1',
});
unchangedCard.supplierPrices = [supplierOffer({ product_id: 'un-1', offer_id: 'offer-un1',
    link_check: { outcome: 'unchanged', reason: 'identical', reason_label: 'بدون تغییر',
        checked_at: 1764100000, check_ref: 'lc-un1', confirmed_at: 0 } })];

const noCheckCard = withStatus(baseProduct({ product_id: 'nc-1', key: 'nc-1' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-nc1',
});
noCheckCard.supplierPrices = [supplierOffer({ product_id: 'nc-1', offer_id: 'offer-nc1', link_check: null })];

// --- (b) uncheckable fixture: channel gone ---
const uncheckableCard = withStatus(baseProduct({ product_id: 'uc-1', key: 'uc-1' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-uc1',
});
uncheckableCard.supplierPrices = [supplierOffer({ product_id: 'uc-1', offer_id: 'offer-uc1',
    link_check: { outcome: 'uncheckable', reason_code: 'channel_unavailable', reason_label: 'کانال در دسترس نیست',
        checked_at: 1764100000, check_ref: 'lc-uc1' } })];

// --- (c) regular-price fixtures ---
const unverifiedCard = withStatus(baseProduct({ product_id: 'uv-1', key: 'uv-1' }), {
    sale_offer_blocker: 'regular_price',
    regular_price_state: 'unverified',
    latest_offer_id: 'offer-uv1',
});
const expiredCard = withStatus(baseProduct({ product_id: 'ex-1', key: 'ex-1' }), {
    sale_offer_blocker: 'regular_price',
    regular_price_state: 'expired',
    latest_offer_id: 'offer-ex1',
});
const missingCard = withStatus(baseProduct({ product_id: 'mi-1', key: 'mi-1' }), {
    sale_offer_blocker: 'regular_price',
    regular_price_state: 'missing',
    regular_price_missing: true,
    latest_offer_id: 'offer-mi1',
});

// --- (a) link-changed: first-view decision text + helper ---
current.setData(emptyData);
check('(a) changed card next-step text names the adopt decision',
    current.workbenchNextStep(changedCard, 'next'),
    'قیمت در لینک منبع تغییر کرده است؛ قیمت تازه را بپذیرید یا بررسی را کنار بگذارید.');
check('(a) changed card action key stays supplier_prices',
    current.workbenchActionKey(changedCard), 'supplier_prices');
const changedRow = current.workbenchHasChangedLinkCheck(changedCard);
check('(a) helper finds the changed row',
    changedRow && changedRow.link_check.live_price, 298000);
check('(a) helper is null without a positive live price',
    current.workbenchHasChangedLinkCheck(changedNoLivePrice), null);
check('(a) helper is null for an unchanged check',
    current.workbenchHasChangedLinkCheck(unchangedCard), null);
check('(a) helper is null with no check at all',
    current.workbenchHasChangedLinkCheck(noCheckCard), null);
check('(a) changed card is not a G1 no-revision card (it has a revision)',
    current.workbenchNeedsPriceRegistration(changedCard), null);

// --- (a) supplier-tab wiring (source checks; DOM verified live) ---
checkSource('(a) adopt form title is the live-price wording', src, "title: 'استفاده از قیمت تازهٔ لینک'");
checkSource('(a) adopt form prefills the live price', src, 'prefillPrice: Number(linkCheck.live_price)');
checkSource('(a) adopt form submit label', src, "submitLabel: 'پذیرفتن قیمت تازه و تأیید'");
checkSource('(a) adopt form hint tells the owner to read the source post first', src, 'پیش از پذیرفتن، پیام منبع را باز کنید و بررسی کنید.');
checkSource('(a) adopt note names the live-price adoption', src, "note: 'مالک قیمت تازهٔ لینک را پذیرفت'");
checkSource('(a) adopt form reuses the G1 register section', src,
    'workbenchPriceRegisterSection(product, offer, section, verify, reviewStoreAvailable, {');
checkSource('(a) adopt form uses the same register_price_from_message action', src,
    "const registerDecision = {action:'register_price_from_message', note:registerNote};");
checkSource('(a) old-price verify is disabled while the link is changed', src,
    'verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices || linkChanged;');
checkSource('(a) verify listener is not attached while the link is changed', src,
    "if (!offer.acknowledged && sourceRevision && !linkChanged) verify.addEventListener('click', async () => {");
checkSource('(a) adopt block points at the tab-bottom dismiss for a wrong live price', src,
    'اگر قیمت تازه اشتباه است، این بررسی را از پایین برگه کنار بگذارید.');
checkSource('(a) register form options keep the G1 defaults', src, "options.title || 'ثبت قیمت از پیام'");
checkSource('(a) register form prefill falls back to the stored price', src,
    'const storedPrice = options.prefillPrice !== undefined');

// --- (b) uncheckable: helper + follow-up path ---
const uncheckableRow = current.workbenchHasUncheckableLinkCheck(uncheckableCard);
check('(b) helper finds the uncheckable row',
    uncheckableRow && uncheckableRow.link_check.reason_code, 'channel_unavailable');
check('(b) helper is null for a changed check',
    current.workbenchHasUncheckableLinkCheck(changedCard), null);
check('(b) helper is null for an unchanged check',
    current.workbenchHasUncheckableLinkCheck(unchangedCard), null);
check('(b) helper is null with no check at all',
    current.workbenchHasUncheckableLinkCheck(noCheckCard), null);
checkSource('(b) supplier tab offers the follow-up button', src, "node('button', 'پیگیری پیام تأمین‌کننده', 'button button-secondary')");
checkSource('(b) follow-up button opens the source review tool', src,
    "followUpButton.addEventListener('click', () => openSourceReviewTool(product));");
checkSource('(b) follow-up line says the manual verify still works', src,
    'اگر قیمت در پیام درست است، آن را با دکمهٔ تأیید زیر بررسی کنید.');

// --- (c) unverified regular price: the why + the options ---
check('(c) unverified next-step text names the missing deadline and the options',
    current.workbenchNextStep(unverifiedCard, 'next'),
    'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد؛ مهلت تأییدی برای آن ثبت نشده است. یک نقل‌قول را انتخاب کنید یا قیمت را دستی تأیید کنید.');
check('(c) expired text is unchanged',
    current.workbenchNextStep(expiredCard, 'next'),
    'اعتبار قیمت عادی پایان یافته است. منبع قیمت را به‌روزرسانی کنید.');
check('(c) missing text is unchanged',
    current.workbenchNextStep(missingCard, 'next'),
    'قیمت عادی را از یک منبع به‌روز پیدا و تأیید کنید.');

// --- (d) no-picture: the where-from line ---
function summary(overrides) {
    return {
        state: 'blocked', state_label: 'مسدود',
        tier: 'red', tier_label: 'پرخطر',
        verdict: 'ok', verdict_label: 'تأیید شد', verdict_reason: '',
        reason: '', hold_reason: '', closeness: null, arm: '',
        active: true, master_on: true, rule_mode: 'live',
        ...overrides,
    };
}
check('(d) no-picture hold reason → where-from line',
    current.autopilotImageLine(summary({ hold_reason: 'تصویر کالا نیست.' })),
    'بدون تصویر منتشر نمی‌شود. تصویر را می‌توانید از نقل‌قول بازار بگیرید («استفاده به‌عنوان تصویر اصلی» در برگهٔ قیمت عادی) یا پیام تازهٔ تأمین‌کننده با عکس بخواهید.');
check('(d) broken-picture hold reason → where-from line',
    current.autopilotImageLine(summary({ hold_reason: 'تصویر کالا خراب است.' })),
    'بدون تصویر منتشر نمی‌شود. تصویر را می‌توانید از نقل‌قول بازار بگیرید («استفاده به‌عنوان تصویر اصلی» در برگهٔ قیمت عادی) یا پیام تازهٔ تأمین‌کننده با عکس بخواهید.');
check('(d) image named in the risk reason (not the hold) → line too',
    current.autopilotImageLine(summary({ reason: 'تصویر کالا نیست.' })),
    'بدون تصویر منتشر نمی‌شود. تصویر را می‌توانید از نقل‌قول بازار بگیرید («استفاده به‌عنوان تصویر اصلی» در برگهٔ قیمت عادی) یا پیام تازهٔ تأمین‌کننده با عکس بخواهید.');
check('(d) no image mention → no line',
    current.autopilotImageLine(summary({ hold_reason: 'قیمت تأمین‌کننده جاری نیست.' })), '');
check('(d) null summary → no line', current.autopilotImageLine(null), '');
checkSource('(d) the image line is wired into the autopilot block', src,
    'const imageLine = autopilotImageLine(summary);');

// --- (e) generic hold: name the underlying gate hold ---
const genericHold = summary({ hold_reason: 'کالا در توقف است.' });
check('(e) generic hold + market hold underneath → names the market gate',
    current.autopilotUnderlyingHoldLine(genericHold,
        [{ product_id: 'p1', gate: 'admission', reason: 'کالا در توقف است.' },
         { product_id: 'p1', gate: 'market', reason: 'نقل‌قول‌ها 12٪ با هم اختلاف دارند (سقف 5٪).' }], 'p1'),
    'توقف زیرین: قیمت عادی — نقل‌قول‌ها 12٪ با هم اختلاف دارند (سقف 5٪).');
check('(e) generic hold + supplier hold underneath → names the supplier gate',
    current.autopilotUnderlyingHoldLine(genericHold,
        [{ product_id: 'p1', gate: 'admission', reason: 'کالا در توقف است.' },
         { product_id: 'p1', gate: 'supplier_price', reason: 'قیمت تأمین‌کننده تغییر کرده است.' }], 'p1'),
    'توقف زیرین: قیمت تأمین‌کننده — قیمت تأمین‌کننده تغییر کرده است.');
check('(e) generic hold + no other hold → no line (nothing invented)',
    current.autopilotUnderlyingHoldLine(genericHold,
        [{ product_id: 'p1', gate: 'admission', reason: 'کالا در توقف است.' }], 'p1'), '');
check('(e) generic hold + another product’s hold only → no line',
    current.autopilotUnderlyingHoldLine(genericHold,
        [{ product_id: 'p2', gate: 'market', reason: 'نقل‌قول‌ها 12٪ با هم اختلاف دارند (سقف 5٪).' }], 'p1'), '');
check('(e) specific hold reason → no line',
    current.autopilotUnderlyingHoldLine(summary({ hold_reason: 'تصویر کالا نیست.' }),
        [{ product_id: 'p1', gate: 'market', reason: 'x' }], 'p1'), '');
check('(e) null summary → no line',
    current.autopilotUnderlyingHoldLine(null, [], 'p1'), '');
checkSource('(e) the underlying-hold line is wired into the autopilot block', src,
    'const underlyingLine = autopilotUnderlyingHoldLine(summary, holds, product.product_id);');

// --- (f) regressions: G9 fast-confirm + G1 still intact ---
check('(f) unchanged check still shows the fast-confirm text',
    current.workbenchNextStep(unchangedCard, 'next'),
    'لینک منبع بررسی شد؛ قیمت بدون تغییر است. با تأمین‌کننده تأیید کنید.');
check('(f) no-check card still shows the generic supplier text',
    current.workbenchNextStep(noCheckCard, 'next'),
    'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.');
const noRevisionCard = withStatus(baseProduct({ product_id: 'nr-1', key: 'nr-1' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-nr1',
});
noRevisionCard.supplierPrices = [supplierOffer({ product_id: 'nr-1', offer_id: 'offer-nr1',
    source_revision: '', link_check: null })];
check('(f) no-revision card still shows the G1 register text',
    current.workbenchNextStep(noRevisionCard, 'next'),
    'نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.');

// --- (g) dashboard.js and CSS are untouched vs the 4ac67ae base, except
// the slice-3 feed addition (persistent matching): the match_events loop
// in the activity feed plus the widened dismiss condition. Covered by
// tests/match_feed_digest_card.cjs. CSS is still untouched.
let dashDiff = null;
let cssDiff = null;
try {
    dashDiff = execSync(`git diff ${BASE} -- shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
    cssDiff = execSync(`git diff ${BASE} -- shop-wp/molavi-shop/includes/owner-workflows.css`, { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn('WARNING: could not run git diff for the (g) untouched check:', error.message);
}
if (dashDiff !== null) {
    const dashAdded = dashDiff.split('\n').filter(line => line.startsWith('+') && !line.startsWith('+++')).map(line => line.slice(1));
    const dashRemoved = dashDiff.split('\n').filter(line => line.startsWith('-') && !line.startsWith('---')).map(line => line.slice(1));
    // Either untouched (the pre-slice-3 tree) or exactly the slice-3
    // feed addition — nothing else may touch this file.
    const slice3 = {
        added: [
            '                    if (item.automaticMarket||item.automaticMatch) {',
            '                // Catalog-side kept matches: the same list shape as the',
            '                // market events above (same row columns — new_price is',
            '                // aliased to regular_price server-side). The origin opens',
            '                // the reference section of the product card, not the',
            '                // market tab.',
            '                for (const event of [...(report.match_events||[])].reverse()) {',
            "                    const id='match:'+event.id;",
            '                    if (actions.has(id)||dismissedMarket.has(id)) continue;',
            '                    const prices=[event.old_price,event.regular_price].filter(price=>price>0).map(price=>fa(price));',
            "                    const labels={price_changed:'قیمت مرجع تغییر کرد',reference_removed:'مرجع حذف شد',",
            "                        identity_changed:'مرجع کالا تغییر کرد',replaced:'مرجع جایگزین شد',retired:'مرجع کنار گذاشته شد'};",
            "                    actions.set(id,{id,automaticMatch:true,state:'completed',",
            "                        label:labels[event.kind]||'تطبیق مرجع تغییر کرد',",
            "                        detail:prices.length===2?prices.join(' ← ')+' تومان':'',",
            "                        origin:{view:'products',key:event.product_id?'product:'+event.product_id:'offer:'+event.offer_id,",
            "                            offer:event.offer_id,product:event.title,section:'references'}});",
            '                    received=true;',
            '                }',
        ],
        removed: ['                    if (item.automaticMarket) {'],
    };
    const untouched = dashAdded.length === 0 && dashRemoved.length === 0;
    const exactlySlice3 = JSON.stringify({ added: dashAdded, removed: dashRemoved }) === JSON.stringify(slice3);
    check('(g) owner-dashboard.js vs 4ac67ae is untouched or exactly the slice-3 feed addition',
        untouched || exactlySlice3, true);
}
if (cssDiff !== null) check('(g) owner-workflows.css is untouched vs 4ac67ae', cssDiff.trim(), '');

// --- (h) DIFFERENTIAL: next-step text identical to base except the FIX-4 cases ---
if (base) {
    const fixtures = [
        ['changed card (FIX-4)', changedCard,
            'قیمت در لینک منبع تغییر کرده است؛ قیمت تازه را بپذیرید یا بررسی را کنار بگذارید.',
            'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.'],
        ['unverified card (FIX-4)', unverifiedCard,
            'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد؛ مهلت تأییدی برای آن ثبت نشده است. یک نقل‌قول را انتخاب کنید یا قیمت را دستی تأیید کنید.',
            'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد.'],
        ['unchanged check', unchangedCard,
            'لینک منبع بررسی شد؛ قیمت بدون تغییر است. با تأمین‌کننده تأیید کنید.',
            'لینک منبع بررسی شد؛ قیمت بدون تغییر است. با تأمین‌کننده تأیید کنید.'],
        ['no link check', noCheckCard,
            'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.',
            'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.'],
        ['expired card', expiredCard,
            'اعتبار قیمت عادی پایان یافته است. منبع قیمت را به‌روزرسانی کنید.',
            'اعتبار قیمت عادی پایان یافته است. منبع قیمت را به‌روزرسانی کنید.'],
        ['missing card', missingCard,
            'قیمت عادی را از یک منبع به‌روز پیدا و تأیید کنید.',
            'قیمت عادی را از یک منبع به‌روز پیدا و تأیید کنید.'],
        ['no-revision card', noRevisionCard,
            'نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.',
            'نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.'],
    ];
    base.setData(emptyData);
    for (const [label, fixture, expectedNew, expectedBase] of fixtures) {
        const baseText = base.workbenchNextStep(fixture, 'next');
        const newText = current.workbenchNextStep(fixture, 'next');
        check(`(h) differential ${label} — new text is the expected text`, newText, expectedNew);
        check(`(h) differential ${label} — base text is the expected base text`, baseText, expectedBase);
    }
    check('(h) base has no changed-link helper', baseWorkflowsSrc.includes('function workbenchHasChangedLinkCheck('), false);
    check('(h) base has no uncheckable-link helper', baseWorkflowsSrc.includes('function workbenchHasUncheckableLinkCheck('), false);
    check('(h) base has no image line', baseWorkflowsSrc.includes('const autopilotImageLine = '), false);
    check('(h) base has no underlying-hold line', baseWorkflowsSrc.includes('const autopilotUnderlyingHoldLine = '), false);
    check('(h) base source has no adopt form', baseWorkflowsSrc.includes('استفاده از قیمت تازهٔ لینک'), false);
    check('(h) base source has no follow-up button', baseWorkflowsSrc.includes('پیگیری پیام تأمین‌کننده'), false);
    current.setData(emptyData);
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
process.exit(fail ? 1 : 0);
