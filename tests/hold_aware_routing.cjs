// Hold-aware next-step routing (G3, owner request 2026-11-26): Node harness.
// Run: node tests/hold_aware_routing.cjs
// Extracts the real routing functions from includes/owner-workflows.js by
// brace matching, evaluates them against stubbed module state, and asserts:
//   (a) cappuccino (market hold, no sale-offer blocker) routes to
//       «بررسی قیمت عادی» with the market-hold text;
//   (b) a product with no hold and no blocker still routes to publication;
//   (c) the status tab's publish decision is disabled while a hold stands
//       and enabled when none stands (decision inputs + source checks on
//       owner-dashboard.js — the DOM rendering itself is verified live);
//   (d) no other card state changed its routing — a DIFFERENTIAL test: the
//       same fixtures run through the base (9b26310) file and the G3 file,
//       and the results must be identical except for the hold-routed cases.
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

let baseSrc = null;
try {
    baseSrc = execSync('git show 9b26310:shop-wp/molavi-shop/includes/owner-workflows.js', { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn('WARNING: could not read the 9b26310 base via git — differential tests skipped:', error.message);
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
    // Arrow function with a brace body.
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

// A const array/object literal statement (workbenchActionOrder,
// workbenchActionSections). Scans to the terminating ';' at depth 0,
// skipping string literals so brackets inside text do not count.
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

// Build a sandbox holding the extracted routing functions plus stubbed
// module state. setData(v) installs the same autopilot payload as both
// workbenchData (what the router reads) and data (what autopilotHold reads).
function buildSandbox(source, withHoldFns) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    if (withHoldFns) {
        for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
        pieces.push(extractConst(source, 'autopilotHold'));
    }
    // G1: workbenchNextStep now calls workbenchNeedsPriceRegistration; it
    // exists only in files newer than the 9b26310 base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    const hasG1 = source.includes('function workbenchNeedsPriceRegistration(');
    if (hasG1) pieces.push(extractFunction(source, 'workbenchNeedsPriceRegistration'));
    // G9: workbenchNextStep now calls workbenchHasUnchangedLinkCheck; it
    // exists only in files newer than the 0862562 base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    const hasG9 = source.includes('function workbenchHasUnchangedLinkCheck(');
    if (hasG9) pieces.push(extractFunction(source, 'workbenchHasUnchangedLinkCheck'));
    // FIX-4: workbenchNextStep now calls workbenchHasChangedLinkCheck; it
    // exists only in files newer than the 4ac67ae base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    if (source.includes('function workbenchHasChangedLinkCheck(')) pieces.push(extractFunction(source, 'workbenchHasChangedLinkCheck'));
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
    ${withHoldFns ? 'workbenchHoldRoute, autopilotHold,' : ''}
    ${hasG1 ? 'workbenchNeedsPriceRegistration,' : ''}
    ${hasG9 ? 'workbenchHasUnchangedLinkCheck,' : ''}
};`;
    return new Function(code)();
}

const g3 = buildSandbox(src, true);
const base = baseSrc ? buildSandbox(baseSrc, false) : null;

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
const cappuccino = withStatus(baseProduct({ product_id: CAP_ID, key: CAP_ID, name: 'کاپوچینو ۲۰ عددی تیمز' }), {
    sale_offer_blocker: '',
    publication_needed: true,
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

const publishable = withStatus(baseProduct({ product_id: 'tuna-100', key: 'tuna-100', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '',
    publication_needed: true,
    regular_price_state: 'current',
    latest_offer_id: 'offer-tuna',
    step_trace: { autopilot: { state: 'admitted', tier: 'green', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: '', closeness: 0.816, arm: 'regular_expiry' } },
});
const emptyData = { autopilot: { holds: [] } };

const supplierHoldProduct = withStatus(baseProduct({ product_id: 'sp-1', key: 'sp-1' }), {
    sale_offer_blocker: '',
    publication_needed: true,
    latest_offer_id: 'offer-sp',
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.5, arm: 'supplier_age' } },
});
const supplierHoldData = { autopilot: { holds: [
    { product_id: 'sp-1', gate: 'supplier_price', reason: 'قیمت تأمین‌کننده تغییر کرده است.', priority: 0.5, score: 0.5, created_at: 1764060000 },
    { product_id: 'sp-1', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

const flagMarket = withStatus(baseProduct({ product_id: 'fl-1', key: 'fl-1' }), {
    sale_offer_blocker: '',
    publication_needed: true,
    latest_offer_id: 'offer-fl',
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'flag', verdict_reason: 'نقل‌قول‌های بازار با هم همخوان نیستند.', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.1, arm: 'quote_spread' } },
});
const flagMarketData = { autopilot: { holds: [
    { product_id: 'fl-1', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

const flagSupplier = withStatus(baseProduct({ product_id: 'fl-2', key: 'fl-2' }), {
    sale_offer_blocker: '',
    publication_needed: true,
    latest_offer_id: 'offer-fl2',
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'flag', verdict_reason: 'قیمت تأمین‌کننده تأیید نشده است.', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.1, arm: 'supplier_age' } },
});
const flagSupplierData = { autopilot: { holds: [
    { product_id: 'fl-2', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

// --- (a) cappuccino routes to the market tab with the hold text ---
g3.setData(cappuccinoData);
check('(a) cappuccino hold route', g3.workbenchHoldRoute(cappuccino),
    { key: 'market_prices', reason: 'نقل‌قول‌ها 23.6٪ با هم اختلاف دارند (سقف 5٪).', gate: 'market' });
check('(a) cappuccino action key', g3.workbenchActionKey(cappuccino), 'market_prices');
check('(a) cappuccino next-step text', g3.workbenchNextStep(cappuccino, 'next'),
    'نقل‌قول‌های بازار همخوان نیستند؛ یک نقل‌قول را انتخاب کنید.');
check('(a) cappuccino ready action is primary', g3.workbenchReadyAction(cappuccino, 'market_prices'), 'market_prices');
checkSource('(a) first-view button label for market_prices', dashboardSrc, "market_prices:'بررسی قیمت عادی'");

// --- (b) no hold, no blocker: still publication ---
g3.setData(emptyData);
check('(b) publishable hold route is null', g3.workbenchHoldRoute(publishable), null);
check('(b) publishable action key', g3.workbenchActionKey(publishable), 'status');
check('(b) publishable next-step text', g3.workbenchNextStep(publishable, 'next'),
    'قیمت‌ها و مشخصات تأیید شده‌اند. انتشار را به صف بفرستید.');

// --- hold-route classification ---
g3.setData(supplierHoldData);
check('supplier-price hold routes to supplier_prices', g3.workbenchHoldRoute(supplierHoldProduct),
    { key: 'supplier_prices', reason: 'قیمت تأمین‌کننده تغییر کرده است.', gate: 'supplier_price' });
check('supplier-price hold action key', g3.workbenchActionKey(supplierHoldProduct), 'supplier_prices');
check('supplier-price hold next-step text', g3.workbenchNextStep(supplierHoldProduct, 'next'),
    'قیمت تأمین‌کننده به بازبینی نیاز دارد؛ منبع فعلی را بررسی و تأیید کنید.');

g3.setData(flagMarketData);
check('flag verdict naming market quotes routes to market_prices', g3.workbenchHoldRoute(flagMarket),
    { key: 'market_prices', reason: 'نقل‌قول‌های بازار با هم همخوان نیستند.', gate: 'verdict' });
check('flag verdict (market) action key', g3.workbenchActionKey(flagMarket), 'market_prices');

g3.setData(flagSupplierData);
check('flag verdict naming only the supplier price routes to supplier_prices', g3.workbenchHoldRoute(flagSupplier),
    { key: 'supplier_prices', reason: 'قیمت تأمین‌کننده تأیید نشده است.', gate: 'verdict' });

// --- (c) status-tab publish decision: disabled while a hold stands ---
g3.setData(cappuccinoData);
check('(c) cappuccino has an autopilot hold (publish must be disabled)',
    Boolean(g3.autopilotHold(cappuccino)), true);
check('(c) cappuccino hold-route reason is the market reason',
    g3.workbenchHoldRoute(cappuccino).reason, 'نقل‌قول‌ها 23.6٪ با هم اختلاف دارند (سقف 5٪).');
g3.setData(emptyData);
check('(c) publishable product has no autopilot hold (publish stays enabled)',
    g3.autopilotHold(publishable), null);
check('(c) publishable product has no hold route', g3.workbenchHoldRoute(publishable), null);
checkSource('(c) status tab renders a disabled publish button while a hold stands',
    dashboardSrc, "disabledBtn.disabled=true;");
checkSource('(c) disabled publish button carries the hold reason',
    dashboardSrc, 'انتشار متوقف است: ${');
checkSource('(c) status tab is kept on a hold-routed card',
    dashboardSrc, "model.holdRoute(product) && record.publication_needed");

// --- (d) differential: no other card state changed its routing ---
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
    ['no queue matches still routes to next',
        withStatus(baseProduct({ product_id: 'd7', key: 'd7' }), {
            sale_offer_blocker: '', publication_needed: false, update_needed: false, regular_price_missing: false, latest_offer_id: 'offer-d7',
        }), emptyData],
    ['lone admission hold (verdict ok) does not reroute',
        withStatus(baseProduct({ product_id: 'd8', key: 'd8' }), {
            sale_offer_blocker: '', publication_needed: true, latest_offer_id: 'offer-d8',
            step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.5, arm: 'supplier_age' } },
        }), { autopilot: { holds: [{ product_id: 'd8', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 }] } }],
];
if (base) {
    for (const [label, product, data] of differential) {
        base.setData(data);
        g3.setData(data);
        check('(d) ' + label + ' — key', g3.workbenchActionKey(product), base.workbenchActionKey(product));
        check('(d) ' + label + ' — text', g3.workbenchNextStep(product, 'next'), base.workbenchNextStep(product, 'next'));
    }
    // And the changed cases, for the record: base routes them to status.
    base.setData(cappuccinoData);
    check('(d) base routes cappuccino to status (the bug)', base.workbenchActionKey(cappuccino), 'status');
    check('(d) base cappuccino text is the publication text (the bug)', base.workbenchNextStep(cappuccino, 'next'),
        'قیمت‌ها و مشخصات تأیید شده‌اند. انتشار را به صف بفرستید.');
    base.setData(supplierHoldData);
    check('(d) base routes the supplier-hold product to status (the bug)', base.workbenchActionKey(supplierHoldProduct), 'status');
} else {
    console.log('SKIP (d) differential tests — base file unavailable');
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
if (fail) process.exit(1);
console.log('ALL HOLD-AWARE ROUTING CHECKS PASSED');
