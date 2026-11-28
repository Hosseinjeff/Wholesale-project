// No-revision price entry (G1, owner request 2026-11-26): Node harness.
// Run: node tests/no_revision_price_entry.cjs
// Extracts the real routing functions from includes/owner-workflows.js by
// brace matching, evaluates them against stubbed module state, and asserts:
//   (a) a no-revision card (supplier price, no source_revision, verify
//       disabled) shows «ثبت قیمت از پیام» as its first-view action and the
//       G1 next-step text, and the supplier tab carries the register form
//       (source checks — the DOM rendering itself is verified live);
//   (b) submitting the form writes the price evidence (method manual) and
//       re-arms verify — proven by the PHP harness
//       (tests/native_no_revision_price_entry.php), not here;
//   (c) a card WITH a source_revision is unchanged (differential vs the G3
//       base 7ee9e19);
//   (d) no other card state changed its routing — a DIFFERENTIAL test: the
//       same fixtures run through the base (7ee9e19) file and the G1 file,
//       and the results must be identical except for the no-revision cases.
// The routing functions' orthogonal leaves are stubbed to neutral values,
// identically for both versions, so a stub-induced error cancels out of the
// differential comparison. No browser, no jsdom.
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
    baseSrc = execSync('git show 7ee9e19:shop-wp/molavi-shop/includes/owner-workflows.js', { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn('WARNING: could not read the 7ee9e19 base via git — differential tests skipped:', error.message);
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
// G1-only: absent from the 7ee9e19 base, so extracted only when present.
const G1_FNS = ['workbenchNeedsPriceRegistration'];
// G9-only: absent from the 0862562 base, so extracted only when present.
const G9_FNS = ['workbenchHasUnchangedLinkCheck'];

// Build a sandbox holding the extracted routing functions plus stubbed
// module state. withG1 adds the G1-only helper (absent from the base).
function buildSandbox(source, withG1) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    if (withG1) for (const name of G1_FNS) pieces.push(extractFunction(source, name));
    // G9: workbenchNextStep now calls workbenchHasUnchangedLinkCheck; it
    // exists only in files newer than the 0862562 base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    const hasG9 = source.includes('function workbenchHasUnchangedLinkCheck(');
    if (hasG9) for (const name of G9_FNS) pieces.push(extractFunction(source, name));
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
    workbenchHoldRoute,
    ${withG1 ? 'workbenchNeedsPriceRegistration,' : ''}
    ${hasG9 ? 'workbenchHasUnchangedLinkCheck,' : ''}
};`;
    return new Function(code)();
}

const g1 = buildSandbox(src, true);
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
function checkSourceAbsent(label, source, needle) {
    const ok = !source.includes(needle);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  should NOT be in source:', JSON.stringify(needle)); }
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

// --- Fixtures ---
// No-revision legacy item: a supplier price exists, but the source revision
// is empty, so verify is disabled and there is no open AI case. The legacy
// row carries no Latest Offer ID either, so the ready action is empty and
// the first-view button is the plain «مدرک قیمت» (audit A8c).
const NOREV_ID = 'p_norev';
const noRevision = withStatus(baseProduct({
    product_id: NOREV_ID, key: NOREV_ID, name: 'کالای بدون نسخهٔ منبع',
    supplierPrices: [{
        product_id: NOREV_ID, name: 'کالای بدون نسخهٔ منبع', channel: 'telegram',
        sale_price: 1250000, source_revision: '', source_timestamp: null,
        source_timestamp_trusted: false, state: 'needs_review', acknowledged: false,
        verified_at: '', link_check: null, market_agreement: null,
    }],
}), { sale_offer_blocker: '', latest_offer_id: '', update_needed: true });

// Same product, but WITH a source revision (the control): verify works.
const WITHREV_ID = 'p_withrev';
const withRevision = withStatus(baseProduct({
    product_id: WITHREV_ID, key: WITHREV_ID, name: 'کالای با نسخهٔ منبع',
    supplierPrices: [{
        product_id: WITHREV_ID, name: 'کالای با نسخهٔ منبع', channel: 'telegram',
        sale_price: 1250000, source_revision: 'rev-abc', source_timestamp: '2026-11-20T00:00:00+00:00',
        source_timestamp_trusted: true, state: 'needs_review', acknowledged: false,
        verified_at: '', link_check: null, market_agreement: null,
    }],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-withrev', update_needed: true });

// No-revision row that is ALREADY acknowledged: nothing to register.
const acknowledgedNoRev = withStatus(baseProduct({
    product_id: 'p_ack', key: 'p_ack', name: 'کالای تأییدشده',
    supplierPrices: [{
        product_id: 'p_ack', name: 'کالای تأییدشده', channel: 'telegram',
        sale_price: 1250000, source_revision: '', source_timestamp: null,
        source_timestamp_trusted: false, state: 'current', acknowledged: true,
        verified_at: '2026-11-26T00:00:00+00:00', link_check: null, market_agreement: null,
    }],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-ack', update_needed: false });

// Cappuccino (market hold, no sale-offer blocker) — the G3 case, must be
// unchanged by G1.
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

// Published tuna (admitted, verdict ok) — must be unchanged by G1.
const tuna = withStatus(baseProduct({ product_id: 'tuna-100', key: 'tuna-100', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '',
    publication_needed: false,
    regular_price_state: 'current',
    latest_offer_id: 'offer-tuna',
    step_trace: { autopilot: { state: 'admitted', tier: 'green', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: '', closeness: 0.816, arm: 'regular_expiry' } },
});
const emptyData = { autopilot: { holds: [] } };

const G1_TEXT = 'نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.';

// --- (a) no-revision detection + next-step text ---
g1.setData(emptyData);
check('no-revision: needsPriceRegistration returns the offer',
    g1.workbenchNeedsPriceRegistration(noRevision)?.product_id, NOREV_ID);
check('no-revision: preferred action is supplier_prices',
    g1.workbenchActionKey(noRevision), 'supplier_prices');
check('no-revision: next-step text is the G1 text',
    g1.workbenchNextStep(noRevision, 'supplier_prices'), G1_TEXT);
check('no-revision: ready action is empty (verify disabled, not "ready")',
    g1.workbenchReadyAction(noRevision), '');

// --- (c) with-revision card is unchanged ---
check('with-revision: needsPriceRegistration is null',
    g1.workbenchNeedsPriceRegistration(withRevision), null);
check('with-revision: preferred action is still supplier_prices',
    g1.workbenchActionKey(withRevision), 'supplier_prices');
check('with-revision: next-step text is the ordinary ready text',
    g1.workbenchNextStep(withRevision, 'supplier_prices'),
    'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.');

// Acknowledged no-revision row: nothing to register.
check('acknowledged no-revision: needsPriceRegistration is null',
    g1.workbenchNeedsPriceRegistration(acknowledgedNoRev), null);

// --- (d) differential vs the G3 base: only the no-revision cases change ---
if (base) {
    base.setData(emptyData);
    const cases = [
        ['no-revision action key', noRevision],
        ['with-revision action key', withRevision],
        ['acknowledged action key', acknowledgedNoRev],
        ['tuna action key', tuna],
    ];
    for (const [label, product] of cases) {
        check(`differential: ${label} identical to base`,
            g1.workbenchActionKey(product), base.workbenchActionKey(product));
    }
    check('differential: with-revision next-step identical to base',
        g1.workbenchNextStep(withRevision, 'supplier_prices'),
        base.workbenchNextStep(withRevision, 'supplier_prices'));
    check('differential: acknowledged next-step identical to base',
        g1.workbenchNextStep(acknowledgedNoRev, 'supplier_prices'),
        base.workbenchNextStep(acknowledgedNoRev, 'supplier_prices'));
    check('differential: tuna next-step identical to base',
        g1.workbenchNextStep(tuna, 'status'),
        base.workbenchNextStep(tuna, 'status'));
    // The no-revision next-step text DOES change (that is the point of G1).
    check('differential: no-revision next-step differs from base (expected)',
        g1.workbenchNextStep(noRevision, 'supplier_prices') !== base.workbenchNextStep(noRevision, 'supplier_prices'), true);
    // Cappuccino (G3) routing is unchanged by G1.
    g1.setData(cappuccinoData);
    base.setData(cappuccinoData);
    check('differential: cappuccino action key identical to base',
        g1.workbenchActionKey(cappuccino), base.workbenchActionKey(cappuccino));
    check('differential: cappuccino next-step identical to base',
        g1.workbenchNextStep(cappuccino, g1.workbenchActionKey(cappuccino)),
        base.workbenchNextStep(cappuccino, base.workbenchActionKey(cappuccino)));
} else {
    console.log('SKIPPED differential tests (no base source)');
}

// --- (a) first view + tab: source checks ---
checkSource('first view: dashboard shows «ثبت قیمت از پیام» for no-revision cards',
    dashboardSrc, "needsPriceRegistration ? 'ثبت قیمت از پیام'");
checkSource('first view: dashboard makes the register button primary',
    dashboardSrc, "(actionable||needsPriceRegistration)?'md-button md-primary':'md-button'");
checkSource('tab: register form details is rendered for no-revision offers',
    src, "register.className = 'molavi-price-register'");
checkSource('tab: register form is open by default',
    src, 'register.open = true;');
checkSource('tab: price field is pre-filled from the stored sale price',
    src, "field('قیمت تأییدشده به تومان', 'sale_price'");
checkSource('tab: source field is present',
    src, "field('منبع قیمت (پیوند پیام تأمین‌کننده — اختیاری)', 'source_url'");
checkSource('tab: fresh-message note is present (Telegram stays manual)',
    src, 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.');
checkSource('tab: submit posts register_price_from_message',
    src, "action:'register_price_from_message'");
checkSource('tab: submit validates the source URL is https',
    src, "!url.startsWith('https://')");
checkSource('tab: success marks the offer acknowledged and re-arms verify',
    src, 'offer.source_revision = receipt.source_revision;');
checkSource('workflows: register action has a progress label',
    src, "register_price_from_message:'ثبت قیمت از پیام'");
checkSource('workflows: needsPriceRegistration is exported on the model',
    src, 'needsPriceRegistration: workbenchNeedsPriceRegistration,');
// The base (7ee9e19) must NOT have the G1 surface — the differential is real.
if (baseSrc) {
    checkSourceAbsent('base has no register form (differential is real)',
        baseSrc, 'molavi-price-register');
    checkSourceAbsent('base has no register action (differential is real)',
        baseSrc, 'register_price_from_message');
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
if (fail) process.exit(1);
console.log('ALL NO-REVISION PRICE-ENTRY CHECKS PASSED');
