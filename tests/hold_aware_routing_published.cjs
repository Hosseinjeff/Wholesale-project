// Hold-aware routing, published+held follow-up (G3 follow-up, owner request
// 2026-11-26): Node harness. Run: node tests/hold_aware_routing_published.cjs
// G3 (7ee9e19) routed hold-blocked products to the hold's cause, but only
// when the normal step was 'status' (pre-publication). A published+held
// product — cappuccino: already live, regular price current, market hold —
// matches no queue, so its normal step is 'next' (a dead end: no next-step
// line, no tab marker) or 'storefront' (a reconcile diagnostic), and neither
// branch fired. This follow-up routes 'next' and 'storefront' to the hold's
// cause as well, and keeps the storefront tab on hold-routed cards.
// Extracts the real routing functions from includes/owner-workflows.js by
// brace matching, evaluates them against stubbed module state, and asserts:
//   (a) published cappuccino (market hold, regular price current) routes to
//       «بررسی قیمت عادی» with the market-hold text, the button is primary,
//       and the quote picker is reachable (marketPriceOffers non-empty);
//   (b) a published+held product with a supplier hold routes to the
//       supplier tab with the supplier-hold text;
//   (c) a no-hold publishable card is unchanged (still 'next' / 'status'
//       as before, no hold route);
//   (d) a pre-publication hold-blocked product still routes as G3 does
//       (the G3 harness is re-run separately and must still pass);
//   (e) no other card state changed its routing — a DIFFERENTIAL test: the
//       same fixtures run through the G3 commit (7ee9e19) and the current
//       file, and the results must be identical except for the hold-routed
//       'next'/'storefront' cases.
// The routing functions' orthogonal leaves (dismissals, Woo state, source
// links) are stubbed to neutral values, identically for both versions, so a
// stub-induced error cancels out of the differential comparison. No browser,
// no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

let g3Src = null;
try {
    g3Src = execSync('git show 7ee9e19:shop-wp/molavi-shop/includes/owner-workflows.js', { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn('WARNING: could not read the G3 base (7ee9e19) via git — differential tests skipped:', error.message);
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

function extractConstStatement(source, name) {
    const start = source.indexOf(`const ${name} = `);
    if (start < 0) throw new Error('const statement not found: ' + name);
    let i = source.indexOf('=', start) + 1;
    let depth = 0, quote = null, escaped = false;
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

// marketPriceOffers is an object property arrow function inside the model
// literal: marketPriceOffers:(product,key)=>{ ... }. Brace-match its body
// (no template literals or unbalanced braces inside) and re-wrap it.
function extractMarketPriceOffers(source) {
    const start = source.indexOf('marketPriceOffers:(product,key)=>{');
    if (start < 0) throw new Error('marketPriceOffers not found');
    const open = source.indexOf('{', source.indexOf('=>', start));
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) return `const marketPriceOffers = (product,key)=>${source.slice(open, i + 1)};`;
        }
    }
    throw new Error('unbalanced braces in marketPriceOffers');
}

const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];

// Build a sandbox holding the extracted routing functions plus stubbed
// module state. setData(v) installs the same autopilot payload as both
// workbenchData (what the router reads) and data (what autopilotHold reads).
function buildSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    pieces.push(extractConst(source, 'autopilotHold'));
    // G1: workbenchNextStep calls workbenchNeedsPriceRegistration; it exists
    // only in files newer than the G3 base, so extract it when present (the
    // G3 file's workbenchNextStep never calls it).
    const hasG1 = source.includes('function workbenchNeedsPriceRegistration(');
    if (hasG1) pieces.push(extractFunction(source, 'workbenchNeedsPriceRegistration'));
    // G9: workbenchNextStep now calls workbenchHasUnchangedLinkCheck; it
    // exists only in files newer than the 0862562 base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    const hasG9 = source.includes('function workbenchHasUnchangedLinkCheck(');
    if (hasG9) pieces.push(extractFunction(source, 'workbenchHasUnchangedLinkCheck'));
    pieces.push(extractMarketPriceOffers(source));
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
    workbenchHoldRoute, autopilotHold, marketPriceOffers,
    ${hasG1 ? 'workbenchNeedsPriceRegistration,' : ''}
    ${hasG9 ? 'workbenchHasUnchangedLinkCheck,' : ''}
};`;
    return new Function(code)();
}

const current = buildSandbox(src);
const g3 = g3Src ? buildSandbox(g3Src) : null;

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

// --- Fixtures (live values where they exist) ---
const CAP_ID = 'cappuccino-105';
// PUBLISHED cappuccino: publication_needed false (already live), regular
// price current (55,076/unit), market hold, no sale-offer blocker. This is
// the browser-check failure: G3 never routed it because its normal step is
// 'next', not 'status'.
const cappuccinoPublished = withStatus(baseProduct({ product_id: CAP_ID, key: CAP_ID, name: 'کاپوچینو ۲۰ عددی تیمز' }), {
    sale_offer_blocker: '',
    publication_needed: false,
    regular_price_state: 'current',
    latest_offer_id: 'offer-cappuccino',
    step_trace: {
        autopilot: {
            state: 'blocked', tier: 'red', verdict: 'ok',
            verdict_reason: 'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.',
            reason: 'پراکندگی نقل‌قول‌ها 23.6٪ در برابر بازهٔ 5٪',
            hold_reason: 'کالا در توقف است.',
            closeness: -3.7207, arm: 'quote_spread',
        },
    },
});
const cappuccinoData = { autopilot: { holds: [
    { product_id: CAP_ID, gate: 'market', reason: 'نقل‌قول‌ها 23.6٪ با هم اختلاف دارند (سقف 5٪).', priority: 0.788, score: 0.212, created_at: 1764060000 },
    { product_id: CAP_ID, gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

// Same product, but the flag re-hold hid it from the storefront, so the Woo
// state is a mismatch and the normal step is 'storefront' (a diagnostic).
const cappuccinoNotPurchasable = { ...cappuccinoPublished, _wooState: 'not_purchasable' };
const cappuccinoNotChecked = { ...cappuccinoPublished, _wooState: 'not_checked' };

// Published + supplier hold (Woo state unknown).
const supplierPublished = withStatus(baseProduct({ product_id: 'sp-1', key: 'sp-1' }), {
    sale_offer_blocker: '', publication_needed: false, latest_offer_id: 'offer-sp',
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.5, arm: 'supplier_age' } },
});
const supplierHoldData = { autopilot: { holds: [
    { product_id: 'sp-1', gate: 'supplier_price', reason: 'قیمت تأمین‌کننده تغییر کرده است.', priority: 0.5, score: 0.5, created_at: 1764060000 },
    { product_id: 'sp-1', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

// Published + flagged verdict naming market quotes (no gate hold of its own).
const flagPublished = withStatus(baseProduct({ product_id: 'fl-1', key: 'fl-1' }), {
    sale_offer_blocker: '', publication_needed: false, latest_offer_id: 'offer-fl',
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'flag', verdict_reason: 'نقل‌قول‌های بازار با هم همخوان نیستند.', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.1, arm: 'quote_spread' } },
});
const flagPublishedData = { autopilot: { holds: [
    { product_id: 'fl-1', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

// Published, no hold at all — must be unchanged (still 'next', dead-end
// text; G4's rule: a published product with no routable cause shows no line).
const publishedClean = withStatus(baseProduct({ product_id: 'ok-1', key: 'ok-1', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '', publication_needed: false, regular_price_state: 'current',
    latest_offer_id: 'offer-ok',
    step_trace: { autopilot: { state: 'admitted', tier: 'green', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: '', closeness: 0.816, arm: 'regular_expiry' } },
});
const emptyData = { autopilot: { holds: [] } };

// Pre-publication publishable (no hold) — must be unchanged ('status').
const publishable = withStatus(baseProduct({ product_id: 'tuna-100', key: 'tuna-100', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '', publication_needed: true, regular_price_state: 'current',
    latest_offer_id: 'offer-tuna',
    step_trace: { autopilot: { state: 'admitted', tier: 'green', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: '', closeness: 0.816, arm: 'regular_expiry' } },
});

// Pre-publication hold-blocked (the G3 case) — must still route as G3 does.
const cappuccinoPrepub = withStatus(cappuccinoPublished, { publication_needed: true });

// --- (a) published cappuccino routes to the market tab ---
current.setData(cappuccinoData);
check('(a) published cappuccino hold route', current.workbenchHoldRoute(cappuccinoPublished),
    { key: 'market_prices', reason: 'نقل‌قول‌ها 23.6٪ با هم اختلاف دارند (سقف 5٪).', gate: 'market' });
check('(a) published cappuccino action key', current.workbenchActionKey(cappuccinoPublished), 'market_prices');
check('(a) published cappuccino next-step text', current.workbenchNextStep(cappuccinoPublished, 'next'),
    'نقل‌قول‌های بازار همخوان نیستند؛ یک نقل‌قول را انتخاب کنید.');
check('(a) published cappuccino ready action is primary', current.workbenchReadyAction(cappuccinoPublished, 'market_prices'), 'market_prices');
check('(a) published cappuccino quote picker is reachable (marketPriceOffers non-empty)',
    current.marketPriceOffers(cappuccinoPublished, 'market_prices'),
    [{ offer_id: 'offer-cappuccino', name: 'کاپوچینو ۲۰ عددی تیمز' }]);
checkSource('(a) first-view button label for market_prices', dashboardSrc, "market_prices:'بررسی قیمت عادی'");
checkSource('(a) the preferred tab carries the «؛ نیازمند اقدام» marker',
    dashboardSrc, "tab.setAttribute('aria-label',`${title}؛ نیازمند اقدام`);");

// Same, with a Woo mismatch state (normal step 'storefront' before the fix).
check('(a) published cappuccino, Woo not_purchasable, action key', current.workbenchActionKey(cappuccinoNotPurchasable), 'market_prices');
check('(a) published cappuccino, Woo not_purchasable, next-step text', current.workbenchNextStep(cappuccinoNotPurchasable, 'next'),
    'نقل‌قول‌های بازار همخوان نیستند؛ یک نقل‌قول را انتخاب کنید.');
check('(a) published cappuccino, Woo not_checked, action key', current.workbenchActionKey(cappuccinoNotChecked), 'market_prices');
check('(a) published cappuccino, Woo not_checked, quote picker reachable',
    current.marketPriceOffers(cappuccinoNotChecked, 'market_prices'),
    [{ offer_id: 'offer-cappuccino', name: 'کاپوچینو ۲۰ عددی تیمز' }]);

// --- (b) published + supplier hold routes to the supplier tab ---
current.setData(supplierHoldData);
check('(b) published supplier-hold hold route', current.workbenchHoldRoute(supplierPublished),
    { key: 'supplier_prices', reason: 'قیمت تأمین‌کننده تغییر کرده است.', gate: 'supplier_price' });
check('(b) published supplier-hold action key', current.workbenchActionKey(supplierPublished), 'supplier_prices');
check('(b) published supplier-hold next-step text', current.workbenchNextStep(supplierPublished, 'next'),
    'قیمت تأمین‌کننده به بازبینی نیاز دارد؛ منبع فعلی را بررسی و تأیید کنید.');

// Published + flagged verdict naming market quotes.
current.setData(flagPublishedData);
check('(b) published flag (market) hold route', current.workbenchHoldRoute(flagPublished),
    { key: 'market_prices', reason: 'نقل‌قول‌های بازار با هم همخوان نیستند.', gate: 'verdict' });
check('(b) published flag (market) action key', current.workbenchActionKey(flagPublished), 'market_prices');

// --- (c) no-hold cards are unchanged ---
current.setData(emptyData);
check('(c) published, no hold: hold route is null', current.workbenchHoldRoute(publishedClean), null);
check('(c) published, no hold: action key stays next', current.workbenchActionKey(publishedClean), 'next');
check('(c) published, no hold: next-step text stays the dead-end text',
    current.workbenchNextStep(publishedClean, 'next'), 'برای این کالا اقدام بازی ثبت نشده است.');
check('(c) pre-publication publishable: action key stays status', current.workbenchActionKey(publishable), 'status');
check('(c) pre-publication publishable: next-step text stays the publication text',
    current.workbenchNextStep(publishable, 'next'), 'قیمت‌ها و مشخصات تأیید شده‌اند. انتشار را به صف بفرستید.');

// --- (d) pre-publication hold-blocked still routes as G3 does ---
current.setData(cappuccinoData);
check('(d) pre-publication hold-blocked action key', current.workbenchActionKey(cappuccinoPrepub), 'market_prices');
check('(d) pre-publication hold-blocked next-step text', current.workbenchNextStep(cappuccinoPrepub, 'next'),
    'نقل‌قول‌های بازار همخوان نیستند؛ یک نقل‌قول را انتخاب کنید.');

// --- storefront tab keep-alive (dashboard source checks) ---
checkSource('(storefront) hold-routed cards keep the storefront tab',
    dashboardSrc, "model.holdRoute(product) && record.woo_product_id");
checkSource('(storefront) keep-alive pushes the storefront tab',
    dashboardSrc, "productSections.push(['storefront','فروشگاه'])");

// --- (e) differential vs G3 (7ee9e19): no other card state changed ---
// Fixtures that must be IDENTICAL between G3 and this follow-up. The only
// intended differences are the hold-routed 'next'/'storefront' cases, which
// are asserted separately below (they are the fix, not a regression).
const differential = [
    ['supplier_price blocker still routes to supplier_prices',
        withStatus(baseProduct({ product_id: 'd1', key: 'd1', supplierPrices: [{ acknowledged: false, source_revision: 'rev1', offer_id: 'o1' }] }), {
            sale_offer_blocker: 'supplier_price', publication_needed: false, latest_offer_id: 'offer-d1',
        }), cappuccinoData],
    ['regular_price blocker still routes to market_prices with the ordinary text',
        withStatus(baseProduct({ product_id: 'd2', key: 'd2' }), {
            sale_offer_blocker: 'regular_price', regular_price_state: 'expired', publication_needed: false, latest_offer_id: 'offer-d2',
        }), emptyData],
    ['open AI case still routes to ai',
        baseProduct({ product_id: 'd3', key: 'd3', ai: [{ case_id: 'c1', publication_held: false, message_review: true, sale_price: 100000 }] }),
        emptyData],
    ['publication (batch) hold still routes to holds',
        baseProduct({ product_id: 'd4', key: 'd4', holds: [{ hold_id: 'h1', context: { messages: [{ id: 'm1' }] } }] }),
        emptyData],
    ['thin_spread blocker + market hold still routes to status (blocker gating)',
        withStatus(baseProduct({ product_id: 'd5', key: 'd5' }), {
            sale_offer_blocker: 'thin_spread', update_needed: true, publication_needed: false, latest_offer_id: 'offer-d5',
        }), cappuccinoData],
    ['owner hold alone does not reroute',
        withStatus(baseProduct({ product_id: 'd6', key: 'd6' }), {
            sale_offer_blocker: '', publication_needed: true, latest_offer_id: 'offer-d6',
        }), { autopilot: { holds: [{ product_id: 'd6', gate: 'admission_owner', reason: 'توقف به دستور مالک.', priority: 0.5, score: 0.5, created_at: 1764060000 }] } }],
    ['no queue matches, no hold, still routes to next',
        withStatus(baseProduct({ product_id: 'd7', key: 'd7' }), {
            sale_offer_blocker: '', publication_needed: false, update_needed: false, regular_price_missing: false, latest_offer_id: 'offer-d7',
        }), emptyData],
    ['lone admission hold (verdict ok) does not reroute',
        withStatus(baseProduct({ product_id: 'd8', key: 'd8' }), {
            sale_offer_blocker: '', publication_needed: true, latest_offer_id: 'offer-d8',
            step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.5, arm: 'supplier_age' } },
        }), { autopilot: { holds: [{ product_id: 'd8', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 }] } }],
    ['storefront mismatch, no hold, still routes to storefront',
        withStatus(baseProduct({ product_id: 'd9', key: 'd9', _wooState: 'not_purchasable' }), {
            sale_offer_blocker: '', publication_needed: false, latest_offer_id: 'offer-d9',
        }), emptyData],
    ['published, no hold, Woo not_checked, still routes to storefront',
        withStatus(baseProduct({ product_id: 'd10', key: 'd10', _wooState: 'not_checked' }), {
            sale_offer_blocker: '', publication_needed: false, latest_offer_id: 'offer-d10',
        }), emptyData],
];
if (g3) {
    for (const [label, product, data] of differential) {
        g3.setData(data);
        current.setData(data);
        check('(e) ' + label + ' — key', current.workbenchActionKey(product), g3.workbenchActionKey(product));
        check('(e) ' + label + ' — text', current.workbenchNextStep(product, 'next'), g3.workbenchNextStep(product, 'next'));
    }
    // And the changed cases, for the record: G3 left them at 'next'/'storefront'.
    g3.setData(cappuccinoData);
    check('(e) G3 left published cappuccino at next (the bug)', g3.workbenchActionKey(cappuccinoPublished), 'next');
    check('(e) G3 published cappuccino text was the dead-end text (the bug)', g3.workbenchNextStep(cappuccinoPublished, 'next'),
        'برای این کالا اقدام بازی ثبت نشده است.');
    check('(e) G3 left published cappuccino (not_purchasable) at storefront (the bug)',
        g3.workbenchActionKey(cappuccinoNotPurchasable), 'storefront');
    g3.setData(supplierHoldData);
    check('(e) G3 left published supplier-hold at next (the bug)', g3.workbenchActionKey(supplierPublished), 'next');
} else {
    console.log('SKIP (e) differential tests — G3 base unavailable');
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
if (fail) process.exit(1);
console.log('ALL PUBLISHED HOLD-AWARE ROUTING CHECKS PASSED');
