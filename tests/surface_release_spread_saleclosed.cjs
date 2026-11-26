// Surface release, thin-spread diagnostic, sale-closed badge (G7 + G8 +
// G11, owner request 2026-11-26): Node harness.
// Run: node tests/surface_release_spread_saleclosed.cjs
// G7 — an owner-held blocked/reverted card shows «رفع توقف پذیرش» as a
// primary button directly under the autopilot explanation (not only inside
// the collapsed history). Same release_hold endpoint the history row calls.
// A gate hold (market/admission) is NOT releasable here.
// G8 — a thin-spread card names both prices in the next-step text
// («قیمت تأمین‌کننده X است و قیمت عادی Y؛ …») and shows BOTH «بررسی قیمت
// عادی» and «بازبینی مشخصات» on the first view. Values come from the step
// trace; a missing price falls back to the generic line.
// G11 — a published-but-sale-closed card gets the badge «منتشر شده، فروش
// بسته» (warning tone, not good) and a first-view line with the reason
// («مجوز فروش: …») and the matching button (release the owner hold, or the
// storefront tab for a flag-based block).
// Extracts the real functions from includes/owner-workflows.js and
// includes/owner-dashboard.js by brace matching, evaluates them against
// stubbed module state and a minimal DOM stub, and asserts:
//   (a) G7: the surfaced release button appears for owner-held
//       blocked/reverted cards, calls release_hold with the product id,
//       and is absent for gate-held / admitted / no-hold cards;
//   (b) G8: the two-sided text with both values, the fallback when a price
//       is missing, and the two first-view buttons + market-tab push;
//   (c) G11: the sale-closed badge + reason, and a published+purchasable
//       card unchanged;
//   (d) differential: next-step text identical to the 08202e8 base except
//       the thin-spread case; the dashboard diff vs 08202e8 is
//       additive-only.
// No browser, no jsdom. Regression re-runs of the G3/G1/G4/G9+G5 harnesses
// are run separately and must pass.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const cssPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.css');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');
const cssSrc = fs.readFileSync(cssPath, 'utf8');

const BASE = '08202e8';
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

// --- Workflows sandbox (routing): the real workbenchNextStep ---
const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];
const G9_FNS = ['workbenchNeedsPriceRegistration', 'workbenchHasUnchangedLinkCheck'];
const G5_CONSTS = ['autopilotStateExplanation', 'autopilotWaitLine'];
function buildWorkflowsSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    for (const name of G9_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
    }
    for (const name of G5_CONSTS) {
        if (source.includes(`const ${name} = `)) pieces.push(extractConst(source, name));
    }
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
};`;
    return new Function(code)();
}

// --- Minimal DOM stub (as in tests/step_trace_render.cjs) ---
function makeEl(tag) {
    const el = {
        tagName: String(tag).toUpperCase(),
        children: [],
        className: '',
        textContent: '',
        open: false,
        disabled: false,
        listeners: {},
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
        click() { return Promise.all((this.listeners.click || []).map((fn) => fn())); },
        appendChild(c) { this.children.push(c); return c; },
        append(...items) { items.forEach((item) => this.children.push(item)); return this; },
        setAttribute() {},
    };
    return el;
}
const document = { createElement: makeEl, createElementNS: (_ns, tag) => makeEl(tag) };

// --- Autopilot card sandbox: the real renderAutopilotCard ---
const requestCalls = [];
const dashboardCalls = [];
const request = async (method, body, _url, apiKey) => {
    requestCalls.push({ method, body, apiKey });
    return {};
};
const dashboard = async (message, kind, keys) => {
    dashboardCalls.push({ message, kind, keys });
};
const errorText = (code) => `خطا: ${code}`;
function buildAutopilotSandbox(dataValue) {
    const autoFns = [
        ...['node', 'reviewFacts', 'formatSourceTimestamp'].map((name) => extractFunction(src, name)),
        ...['autopilotRiskLabels', 'autopilotGateLabels', 'autopilotRuleLabels', 'autopilotActionLabels', 'autopilotVerdictLabels',
            'autopilotRiskTier', 'autopilotRiskIcon', 'autopilotRiskBadge', 'autopilotStateExplanation', 'autopilotWaitLine',
            'renderAutopilotHistoryRow', 'renderAutopilotCard'].map((name) => extractConst(src, name)),
    ].join('\n');
    const autoSandbox = new Function('document', 'data', 'request', 'dashboard', 'errorText',
        `${autoFns}\nreturn {renderAutopilotCard, renderAutopilotHistoryRow, autopilotStateExplanation};`);
    return autoSandbox(document, dataValue, request, dashboard, errorText);
}

// --- Dashboard sandbox: the real stateLabel + saleClosed helpers ---
// model.primaryAction / readyAction are fed the real router's answer per
// fixture, so the badge decision is end-to-end.
const DASHBOARD_FNS = ['next', 'ready', 'waiting', 'published', 'priceBlocked', 'stateLabel', 'saleClosed', 'saleClosedReason'];
function buildDashboardSandbox(source) {
    const pieces = DASHBOARD_FNS.map((name) => extractFunction(source, name));
    const code = `
let model = null;
${pieces.join('\n')}
return {
    setModel(m) { model = m; },
    stateLabel, saleClosed, saleClosedReason, next, published,
};`;
    return new Function(code)();
}

const wf = buildWorkflowsSandbox(src);
const wfBase = baseWorkflowsSrc ? buildWorkflowsSandbox(baseWorkflowsSrc) : null;
const dash = buildDashboardSandbox(dashboardSrc);

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
function findAll(el, tag) {
    const out = [];
    const walk = (n) => {
        if (n.tagName === tag) out.push(n);
        (n.children || []).forEach(walk);
    };
    walk(el);
    return out;
}
function textOf(el) {
    let out = el.textContent || '';
    (el.children || []).forEach((c) => { out += textOf(c); });
    return out;
}
function findButtons(el, label) {
    return findAll(el, 'BUTTON').filter((b) => textOf(b) === label);
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
function wire(product, data, hold) {
    wf.setData(data);
    const actionKey = wf.workbenchActionKey(product);
    const readyKey = wf.workbenchReadyAction(product, actionKey);
    dash.setModel({
        primaryAction: () => actionKey,
        readyAction: () => readyKey,
        autopilotHold: () => hold || null,
        wooState: () => product._wooState || '',
        requestPublication: async () => {},
    });
    return actionKey;
}
const emptyData = { autopilot: { holds: [] } };

// --- G8 fixtures: coffee بلک تیمز, thin spread 900,000 vs 950,000 ---
function coffeeTrace(supplierPrice, regularPrice) {
    return { steps: [
        { key: 'supplier_price', label: 'قیمت تأمین‌کننده', status: 'warn', status_label: 'هشدار',
          facts: [['قیمت ثبت‌شده', supplierPrice, 'amount'], ['وضعیت', 'هشدار (۷ تا ۱۴ روز)', 'text']], note: null },
        { key: 'regular_price', label: 'قیمت عادی', status: 'ok', status_label: 'جاری',
          facts: [['قیمت عادی', regularPrice, 'amount'], ['منبع', 'شیت (قیمت مصرف‌کننده)', 'text']], note: null },
    ] };
}
const coffee = withStatus(baseProduct({ product_id: 'coffee-1', key: 'coffee-1', name: 'قهوه بلک تیمز ۴۰ عددی' }), {
    sale_offer_blocker: 'thin_spread', update_needed: true, publication_needed: false,
    latest_offer_id: 'offer-coffee', step_trace: coffeeTrace(900000, 950000),
});
const coffeeNoTrace = withStatus(baseProduct({ product_id: 'coffee-2', key: 'coffee-2', name: 'قهوه بلک تیمز ۴۰ عددی' }), {
    sale_offer_blocker: 'thin_spread', update_needed: true, publication_needed: false,
    latest_offer_id: 'offer-coffee-2',
});
const coffeeMissingRegular = withStatus(baseProduct({ product_id: 'coffee-3', key: 'coffee-3', name: 'قهوه بلک تیمز ۴۰ عددی' }), {
    sale_offer_blocker: 'thin_spread', update_needed: true, publication_needed: false,
    latest_offer_id: 'offer-coffee-3', step_trace: coffeeTrace(900000, 'ثبت نشده'),
});
const coffeeMissingSupplier = withStatus(baseProduct({ product_id: 'coffee-4', key: 'coffee-4', name: 'قهوه بلک تیمز ۴۰ عددی' }), {
    sale_offer_blocker: 'thin_spread', update_needed: true, publication_needed: false,
    latest_offer_id: 'offer-coffee-4', step_trace: coffeeTrace(0, 950000),
});
const GENERIC_THIN = 'اختلاف قیمت تأمین‌کننده و قیمت عادی برای تخفیف کافی نیست؛ قیمت‌ها را بازبینی کنید.';
const TWO_SIDED = 'قیمت تأمین‌کننده ۹۰۰٬۰۰۰ است و قیمت عادی ۹۵۰٬۰۰۰؛ برای تخفیف باید قیمت تأمین‌کننده کمتر یا قیمت عادی بیشتر شود.';

// --- (b) G8: the two-sided text names both prices ---
wf.setData(emptyData);
check('(b) thin spread + both prices → two-sided text', wf.workbenchNextStep(coffee, 'next'), TWO_SIDED);
check('(b) thin spread action key stays status', wf.workbenchActionKey(coffee), 'status');
check('(b) thin spread + no trace → generic fallback (nothing invented)', wf.workbenchNextStep(coffeeNoTrace, 'next'), GENERIC_THIN);
check('(b) thin spread + regular missing → generic fallback', wf.workbenchNextStep(coffeeMissingRegular, 'next'), GENERIC_THIN);
check('(b) thin spread + supplier zero → generic fallback', wf.workbenchNextStep(coffeeMissingSupplier, 'next'), GENERIC_THIN);
check('(b) no_spread keeps its own line (G8 is thin_spread only)',
    wf.workbenchNextStep(withStatus(baseProduct({ product_id: 'ns-1', key: 'ns-1' }), { sale_offer_blocker: 'no_spread', update_needed: true, latest_offer_id: 'offer-ns' }), 'next'),
    'برای عرضه، قیمت عادی باید از قیمت تأمین‌کننده بیشتر باشد.');

// --- (b) G8: the two first-view buttons + market-tab push (source) ---
checkSource('(b) first view: thin-spread branch is gated on status + thin_spread',
    dashboardSrc, "}else if(preferred==='status'&&product.status?.sale_offer_blocker==='thin_spread'){");
checkSource('(b) first view: «بررسی قیمت عادی» is the primary button opening the market tab',
    dashboardSrc, "button('بررسی قیمت عادی',()=>activate('market_prices'),'md-button md-primary')");
checkSource('(b) first view: «بازبینی مشخصات» reuses the status tab recovery action (opens the spec/edit form)',
    dashboardSrc, "recoveryAction(product,'بازبینی مشخصات')");
checkSource('(b) first view: the G8 branch runs before the generic non-next branch',
    dashboardSrc, "}else if(preferred==='status'&&product.status?.sale_offer_blocker==='thin_spread'){");
{
    const g8At = dashboardSrc.indexOf("}else if(preferred==='status'&&product.status?.sale_offer_blocker==='thin_spread'){");
    const genericAt = dashboardSrc.indexOf("}else if(preferred!=='next'){");
    check('(b) first view: G8 branch precedes the generic branch in source order', g8At > 0 && genericAt > g8At, true);
}
checkSource('(b) tab bar: a thin-spread product keeps its market tab',
    dashboardSrc, "if (preferred==='status'&&record.sale_offer_blocker==='thin_spread'&&!productSections.some(([key])=>key==='market_prices')) productSections.push(['market_prices','قیمت عادی']);");

// --- G7 fixtures: owner-held blocked / reverted ---
function autoSummary(overrides) {
    return {
        active: true, master_on: true, rule_mode: 'live', mode_label: 'فعال — پذیرش زنده',
        state: 'blocked', state_label: 'مسدود',
        tier: 'red', tier_label: 'پرخطر',
        verdict: 'ok', verdict_label: 'تأیید شد', verdict_reason: '',
        reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.5, arm: 'supplier_age',
        created_at: 1764000000, history: [],
        ...overrides,
    };
}
function historyRow(overrides) {
    return {
        id: 41, rule_id: 'gA', gate: 'admission', product_id: 'p_held',
        action: 'live', result: 'admitted', verdict: 'ok', verdict_reason: '',
        confidence: 0.8, created_at: 1764000000, reviewed_at: 0, reviewed_by: '',
        inputs: { spread: 0.3, supplier_price: 100000, regular_price: 150000 },
        risk_tier: 'green', risk_tier_stored: 'green', risk_reason: '',
        ...overrides,
    };
}
const heldProduct = { product_id: 'p_held', status: { step_trace: { product_id: 'p_held', steps: [], autopilot: autoSummary({ history: [historyRow({})] }) } } };
const heldData = { autopilot: { holds: [{ product_id: 'p_held', gate: 'admission_owner', reason: 'توقف به دستور مالک.' }] } };
const revertedProduct = { product_id: 'p_held', status: { step_trace: { product_id: 'p_held', steps: [], autopilot: autoSummary({
    state: 'reverted', state_label: 'لغو شد',
    history: [historyRow({ action: 'revert', result: 'reverted' })],
}) } } };
const gateHeldProduct = { product_id: 'p_gate', status: { step_trace: { product_id: 'p_gate', steps: [], autopilot: autoSummary({
    history: [historyRow({ product_id: 'p_gate' })],
}) } } };
const gateHeldData = { autopilot: { holds: [{ product_id: 'p_gate', gate: 'admission', reason: 'کالا در توقف است.' }] } };
const admittedProduct = { product_id: 'p_adm', status: { step_trace: { product_id: 'p_adm', steps: [], autopilot: autoSummary({
    state: 'admitted', state_label: 'پذیرفته شد (زنده)', hold_reason: '',
    history: [historyRow({ product_id: 'p_adm' })],
}) } } };

const { renderAutopilotCard: renderHeld } = buildAutopilotSandbox(heldData);
const { renderAutopilotCard: renderGate } = buildAutopilotSandbox(gateHeldData);
const { renderAutopilotCard: renderNone } = buildAutopilotSandbox(emptyData);

// --- (a) G7: surfaced release for owner-held blocked ---
const heldCard = renderHeld(heldProduct);
check('(a) owner-held blocked: surfaced release button exists', findButtons(heldCard, 'رفع توقف پذیرش').length, 2);
{
    // The surfaced button lives in its own wrapper div, a direct child of
    // the section — outside the collapsed history DETAILS, which holds the
    // row's own release button.
    const outsideHistory = heldCard.children.filter((c) => c.tagName !== 'DETAILS');
    const surfaced = [];
    outsideHistory.forEach((c) => { findButtons(c, 'رفع توقف پذیرش').forEach((b) => surfaced.push(b)); });
    check('(a) owner-held blocked: the surfaced button sits outside the collapsed history', surfaced.length, 1);
    const history = findAll(heldCard, 'DETAILS')[0];
    check('(a) owner-held blocked: the history row keeps its own release button', findButtons(history, 'رفع توقف پذیرش').length, 1);
    check('(a) owner-held blocked: the surfaced button is primary', surfaced[0].className.includes('button-primary'), true);
    check('(a) owner-held blocked: the surfaced button sits under the explanation (after the reason line)',
        textOf(heldCard).indexOf('کالا در توقف است.') < textOf(heldCard).indexOf('رفع توقف پذیرش'), true);
}
// --- (a) G7: reverted + owner-held also surfaces the release ---
const revertedCard = renderHeld(revertedProduct);
check('(a) owner-held reverted: surfaced release button exists', findButtons(revertedCard, 'رفع توقف پذیرش').length, 2);
// --- (a) G7: a gate hold is NOT releasable from here ---
const gateCard = renderGate(gateHeldProduct);
check('(a) gate-held blocked: no surfaced release button', findButtons(gateCard, 'رفع توقف پذیرش').length, 0);
// --- (a) G7: admitted / no-hold cards have no release button ---
const admittedCard = renderNone(admittedProduct);
check('(a) admitted, no hold: no release button', findButtons(admittedCard, 'رفع توقف پذیرش').length, 0);
check('(a) blocked, no hold data: no release button', findButtons(renderNone(heldProduct), 'رفع توقف پذیرش').length, 0);

// --- G11 fixtures: published but sale closed ---
function publicationStep(overrides) {
    return {
        key: 'publication', label: 'انتشار / فروشگاه', status: 'warn', status_label: 'منتشر شده، فروش بسته',
        facts: [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['شناسهٔ ووکامرس', 100, 'int'], ['قابل خرید', 'خیر', 'text'],
            ['مجوز فروش', 'توقف به دستور مالک', 'text']],
        note: null,
        ...overrides,
    };
}
const saleClosedOwner = withStatus(baseProduct({ product_id: 'sc-1', key: 'sc-1', name: 'کالای فروش‌بسته (مالک)' }), {
    sale_offer_blocker: '', publication_needed: false, update_needed: false,
    regular_price_missing: false, latest_offer_id: 'offer-sc1', published: true,
    step_trace: { steps: [publicationStep({})], autopilot: autoSummary({ state: 'blocked', hold_reason: 'توقف به دستور مالک.' }) },
});
const saleClosedFlag = withStatus(baseProduct({ product_id: 'sc-2', key: 'sc-2', name: 'کالای فروش‌بسته (داوری)' }), {
    sale_offer_blocker: '', publication_needed: false, update_needed: false,
    regular_price_missing: false, latest_offer_id: 'offer-sc2', published: true,
    step_trace: { steps: [publicationStep({ facts: [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['شناسهٔ ووکامرس', 101, 'int'], ['قابل خرید', 'خیر', 'text'],
        ['مجوز فروش', 'توقف به‌دلیل داوری پذیرش زنده', 'text']] })], autopilot: autoSummary({ state: 'blocked', hold_reason: 'کالا در توقف است.' }) },
});
const publishedOk = withStatus(baseProduct({ product_id: 'sc-3', key: 'sc-3', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '', publication_needed: false, update_needed: false,
    regular_price_missing: false, latest_offer_id: 'offer-sc3', published: true,
    step_trace: { steps: [publicationStep({ status: 'ok', status_label: 'منتشر شده',
        facts: [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['شناسهٔ ووکامرس', 100, 'int'], ['قابل خرید', 'بله', 'text']] })],
        autopilot: autoSummary({ state: 'admitted', state_label: 'پذیرفته شد (زنده)', hold_reason: '' }) },
});
const noTraceProduct = baseProduct({ product_id: 'sc-4', key: 'sc-4', name: 'کالای بدون ردیابی' });

// --- (c) G11: the helpers detect the sale-closed step + reason ---
check('(c) saleClosed detects the sale-closed step', dash.saleClosed(saleClosedOwner) !== null, true);
check('(c) saleClosed detects the flag-based sale-closed step', dash.saleClosed(saleClosedFlag) !== null, true);
check('(c) saleClosed is null for a published+purchasable product', dash.saleClosed(publishedOk), null);
check('(c) saleClosed is null without a trace', dash.saleClosed(noTraceProduct), null);
check('(c) saleClosedReason is the owner-hold reason', dash.saleClosedReason(saleClosedOwner), 'توقف به دستور مالک');
check('(c) saleClosedReason is the verdict re-hold reason', dash.saleClosedReason(saleClosedFlag), 'توقف به‌دلیل داوری پذیرش زنده');
check('(c) saleClosedReason is empty for a purchasable product', dash.saleClosedReason(publishedOk), '');

// --- (c) G11: the badge is the sale-closed text in warning tone ---
wire(saleClosedOwner, emptyData, null);
check('(c) sale-closed (owner hold): badge is «منتشر شده، فروش بسته» in warning tone',
    dash.stateLabel(saleClosedOwner), ['منتشر شده، فروش بسته', 'attention']);
wire(saleClosedFlag, emptyData, null);
check('(c) sale-closed (flag): badge is «منتشر شده، فروش بسته» in warning tone',
    dash.stateLabel(saleClosedFlag), ['منتشر شده، فروش بسته', 'attention']);
wire(publishedOk, emptyData, null);
check('(c) published+purchasable: badge stays «منتشرشده» (good)', dash.stateLabel(publishedOk), ['منتشرشده', 'good']);

// --- (c) G11: the first-view line + buttons + storefront-tab push (source) ---
checkSource('(c) first view: the sale-closed line is rendered when the step says so',
    dashboardSrc, 'if (saleClosed(product)) {');
checkSource('(c) first view: the line shows the «مجوز فروش» reason',
    dashboardSrc, 'مجوز فروش: ${closedReason}');
checkSource('(c) first view: an owner hold gets the primary «رفع توقف پذیرش» button',
    dashboardSrc, "button('رفع توقف پذیرش',async event=>{");
checkSource('(c) first view: the release button calls the model release helper',
    dashboardSrc, 'await model.releaseAutopilotHold(product);');
checkSource('(c) first view: a flag-based block gets the storefront button',
    dashboardSrc, "button('بررسی فروشگاه',()=>activate('storefront'),'md-button')");
checkSource('(c) tab bar: a sale-closed card keeps its storefront tab',
    dashboardSrc, "if (saleClosed(product)&&!productSections.some(([key])=>key==='storefront')) productSections.push(['storefront','فروشگاه']);");
checkSource('(c) badge: stateLabel checks the sale-closed step before the published badge',
    dashboardSrc, "if(saleClosed(product))return ['منتشر شده، فروش بسته','attention'];");
checkSource('(c) model: releaseAutopilotHold is exported next to requestPublication',
    src, 'releaseAutopilotHold:async product=>{');
checkSource('(c) model: the release helper posts release_hold with the product id',
    src, "{action:'release_hold',product_id:String(product.product_id)},undefined,'autopilot'");
checkSource('(c) CSS: the surfaced release wrapper has its margin rule',
    cssSrc, '.molavi-autopilot-state-release');

// --- (d) DIFFERENTIAL: next-step text identical to the 08202e8 base except thin_spread ---
if (wfBase) {
    const fixtures = [
        ['thin spread + both prices (G8)', coffee, TWO_SIDED, GENERIC_THIN],
        ['thin spread + no trace (G8 fallback)', coffeeNoTrace, GENERIC_THIN, GENERIC_THIN],
        ['no_spread (unchanged)', withStatus(baseProduct({ product_id: 'ns-1', key: 'ns-1' }), { sale_offer_blocker: 'no_spread', update_needed: true, latest_offer_id: 'offer-ns' }),
            'برای عرضه، قیمت عادی باید از قیمت تأمین‌کننده بیشتر باشد.', 'برای عرضه، قیمت عادی باید از قیمت تأمین‌کننده بیشتر باشد.'],
    ];
    for (const [label, fixture, expected, baseExpected] of fixtures) {
        wf.setData(emptyData);
        wfBase.setData(emptyData);
        check(`(d) differential ${label} — new text is the expected text`, wf.workbenchNextStep(fixture, 'next'), expected);
        check(`(d) differential ${label} — base text is the expected base text`, wfBase.workbenchNextStep(fixture, 'next'), baseExpected);
    }
} else {
    console.log('SKIP (d) routing differential — base unavailable');
}

// --- (d) the dashboard diff vs 08202e8: two lines changed by the G1 follow-up
// and the deferred-button follow-up ---
// The dashboard change was additive-only vs the 08202e8 base until the G1
// follow-up changed exactly one line: the register button's gate
// (preferred==='supplier_prices' → the supplier/source list). The
// deferred-button follow-up changes exactly one more line: the restore
// button's class becomes conditional (primary only when no register button
// is shown), so the old closing line `},'md-button md-primary'));` is the
// second removed line vs 08202e8. Those two lines are the only dashboard
// lines removed vs the 08202e8 base — plus, since the streamline funnel
// view (2026-11-26), the eight lines the view rewrote: the view whitelist,
// the nav list, the saveURL param-clear list, the scope-button handler,
// the active-filter chip, the action-select handler, the paint guard, and
// the click handler — each gained the 'streamline' view / the step+reason
// filter.
// The eight streamline lines, in the order git diff emits them (file
// order); shared by the (d) check below.
const STREAMLINE_REMOVED = [
    "view:['products','references','sources','system','pricing'].includes(params.get('view')) ? params.get('view')",
    "[['products','محصولات'],['references','فهرست مرجع'],['sources','پیام‌های منبع'],['system','وضعیت سیستم']].forEach(([key,label])=>{",
    "['view','scope','q','channel','workbench_action','product_id','offer_id','case_id','conflict_id','hold_id','reference_id','risk','held'].forEach(key=>url.searchParams.delete(key));",
    "clearSelection();state.scope=key;state.action='';state.focus='';state.limit=24;saveURL();paint();",
    "const focus=el('div',null,'md-active-filter');focus.hidden=!state.focus;",
    "action.addEventListener('change',()=>{clearSelection();state.action=action.value;state.limit=24;saveURL();paintList();});",
    "if(!model&&['products','system'].includes(state.view)) {",
    "if(['products','sources','references','system','pricing'].includes(view)) {",
];
let dashRemoved = null;
try {
    const dashDiff = execSync('git diff 08202e8 -- shop-wp/molavi-shop/includes/owner-dashboard.js', { cwd: repoRoot, encoding: 'utf8' });
    dashRemoved = dashDiff.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('---'))
        .map((line) => line.slice(1).trim()).filter(Boolean);
} catch (error) {
    console.warn('WARNING: could not run git diff for the (d) removed-lines check:', error.message);
}
if (dashRemoved) check('(d) dashboard.js removals vs the 08202e8 base are exactly the old register gate + the restore-button class line + the eight streamline-view lines',
    dashRemoved, [STREAMLINE_REMOVED[0], STREAMLINE_REMOVED[1], STREAMLINE_REMOVED[2], STREAMLINE_REMOVED[3], STREAMLINE_REMOVED[4], STREAMLINE_REMOVED[5],
        "},'md-button md-primary'));",
        "const needsPriceRegistration = preferred==='supplier_prices' && model.needsPriceRegistration",
        STREAMLINE_REMOVED[6], STREAMLINE_REMOVED[7]]);
checkSource('(d) the deferred restore button class is conditional on the register button',
    dashboardSrc, "},needsPriceRegistration?'md-button':'md-button md-primary'));");
// --- (d) the workflows diff vs the G4 commit: deletions are only moved lines ---
// The diff was additive-only until the G1 follow-up moved the register form
// out of the supplier_prices section into the extracted
// workbenchPriceRegisterSection helper (and rewrote the source_integrity
// next-step line as a braced block). Every deleted line must still exist in
// the current file (the helper renames the `section` container to
// `container`, so the comparison normalises that), except the two named
// rewrites (the append that moved to the caller, and the source_integrity
// one-liner whose return text is preserved in the new block).
let wfMoved = null;
try {
    const wfDiff = execSync('git diff 0862562 -- shop-wp/molavi-shop/includes/owner-workflows.js', { cwd: repoRoot, encoding: 'utf8' });
    const wfDeleted = wfDiff.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('---'))
        .map((line) => line.slice(1).trim()).filter(Boolean);
    const currentWf = fs.readFileSync(workflowsPath, 'utf8').replace(/container/g, 'section');
    const wfRewritten = [
        'section.appendChild(register);',
        "if (key === 'source_integrity') return 'انتشار متوقف است؛ پیام اصلی یا پیشنهاد تازهٔ همین کالا را به ربات بفرستید.';",
        // G12 replaced the stock first-view line with the fresh-message
        // text; the old one-liner is a named rewrite, not a lost line.
        "if (key === 'stock') return 'پس از اعلام موجودی، پیام تازهٔ تأمین‌کننده را به ربات بفرستید.';",
        // The scope hotfix replaced the dashboard-scoped read with the
        // threaded parameter (both occurrences); the replacement line
        // must exist in the current file (checked below).
        'registerSubmit.disabled = !contextStored.supplier_prices;',
        // The verification-section fix (2026-11-26) extracted the
        // «قیمت و موجودی امروز» block into the buildSourceVerificationSection
        // helper and renders it in both paths: the guard became an early
        // return, the checkbox local was renamed currentPriceVerified ->
        // checkbox inside the helper, and the append was split across the
        // two call sites. The logic survives verbatim under the new names.
        'if (sourceVerificationRequired) {',
        "currentPriceVerified = document.createElement('input');",
        "currentPriceVerified.type = 'checkbox';",
        'currentPriceVerified.checked = sourceVerificationItems.length > 0',
        'checkLabel.append(currentPriceVerified, document.createTextNode(stockNeedsCurrentCheck',
        'verification.append(checkLabel);card.appendChild(verification);',
        "if (currentPriceVerified) currentPriceVerified.addEventListener('change', () => {",
        'if (currentPriceVerified.checked) verifiedCurrentPriceCases.add(key);',
    ];
    // The render-merged-cases fix (2026-11-26) deliberately REMOVES two
    // lines in the ai candidate-deferred panel (they are not moved): the
    // loading line (the case now renders immediately, so there is no
    // loading state) and the catch-path re-render (the case is already
    // visible, so a failed lookup only prepends a retry). Covered by
    // tests/render_merged_cases_dom.cjs.
    // The ai-confirm-section-visible fix (2026-11-26) deliberately REMOVES
    // the line that hid the whole confirmation section until the supplier
    // price was verified: the section is now always visible, with the
    // confirm buttons disabled until the checkbox is ticked (the gate is
    // the disabled state, not invisibility). Covered by the same harness.
    const wfRemoved = [
        "content.replaceChildren(node('p','در حال بررسی مرجع این پیشنهاد…'));",
        "content.replaceChildren(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry,renderCase());",
        'confirmation.hidden=sourceVerificationRequired&&!currentPriceVerified?.checked;',
    ];
    wfMoved = {
        deleted: wfDeleted.length,
        lost: wfDeleted.filter((line) => !wfRewritten.includes(line) && !wfRemoved.includes(line) && !currentWf.includes(line)),
    };
} catch (error) {
    console.warn('WARNING: could not run git diff for the (d) moved-lines check:', error.message);
}
if (wfMoved) {
    check('(d) workflows.js deletions vs the G4 commit are only moved lines (none lost)', wfMoved.lost, []);
    check('(d) the scope-fix replacement is present',
        src.includes('registerSubmit.disabled = !reviewStoreAvailable;'), true);
    // G12 replaced the return text in the new block with the fresh-message
    // text (the G1-follow-up block itself is unchanged).
    check('(d) the source_integrity return text is the G12 text in the new block',
        src.includes("return 'پیام تازه را به ربات بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.';"), true);
}

// --- (a) Clicking the surfaced release button calls release_hold ---
// The handlers are async (request, then a dashboard refresh), so the
// assertions run in an async block and the final line moves here.
(async () => {
    const outsideHistory = heldCard.children.filter((c) => c.tagName !== 'DETAILS');
    const surfaced = [];
    outsideHistory.forEach((c) => { findButtons(c, 'رفع توقف پذیرش').forEach((b) => surfaced.push(b)); });
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    await surfaced[0].click();
    check('surfaced release calls the autopilot release_hold endpoint with the product id',
        requestCalls.length === 1 && requestCalls[0].body.action === 'release_hold' && requestCalls[0].body.product_id === 'p_held', true);
    check('surfaced release calls the autopilot API section', requestCalls[0].apiKey, 'autopilot');
    check('surfaced release refreshes product_status + autopilot so the card re-renders',
        JSON.stringify(dashboardCalls[0].keys), JSON.stringify(['product_status', 'autopilot', 'operations']));
    check('surfaced release reports the outcome', dashboardCalls[0].message.includes('توقف پذیرش رفع شد'), true);
    // The history-row button still works the same way.
    const history = findAll(heldCard, 'DETAILS')[0];
    const historyRelease = findButtons(history, 'رفع توقف پذیرش')[0];
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    await historyRelease.click();
    check('history-row release still calls release_hold with the product id',
        requestCalls.length === 1 && requestCalls[0].body.action === 'release_hold' && requestCalls[0].body.product_id === 'p_held', true);
    check('history-row release still reports the outcome', dashboardCalls[0].message.includes('توقف پذیرش رفع شد'), true);

    console.log(`\n${pass} PASS, ${fail} FAIL`);
    if (fail) process.exit(1);
    console.log('ALL SURFACE-RELEASE-SPREAD-SALECLOSED CHECKS PASSED');
})().catch((error) => { console.error(error); process.exit(1); });
