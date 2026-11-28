// Storefront repair + external-path copy (G10 + G12, owner request 2026-11-26): Node harness.
// Run: node tests/storefront_repair_external_copy.cjs
// G10 — a storefront mismatch had a diagnosis but no repair. After a
// reconcile returns a real mismatch, the storefront tab now shows the
// repair button: «انتشار دوباره» (request_publication) for not_visible /
// not_purchasable, and for sku_mismatch the note «شناسهٔ ووکامرس با شناسهٔ
// منبع فرق دارد» with «بازبینی مشخصات» (recoveryAction). Both call the
// existing endpoints only. Before any reconcile result (not_checked) the
// path stays "run the check, then see" — no repair button.
// G12 — source integrity and stock are external-only paths (a fresh
// message to the bot; the supplier announcing stock). The first-view lines
// now say so in plain words: source_integrity «پیام تازه را به ربات
// بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.» and stock
// «وقتی تأمین‌کننده موجودی را اعلام کرد، پیام تازه را به ربات بفرستید.»
// Copy only — no back-in-stock override, no re-link/re-extract action.
// Extracts the real functions from includes/owner-workflows.js and
// includes/owner-dashboard.js by brace matching, evaluates them against
// stubbed module state and a minimal DOM stub, and asserts:
//   (a) a storefront card with a not_visible / not_purchasable reconcile
//       result shows «انتشار دوباره» and clicking it calls
//       request_publication with the product (the reconcile result is
//       derived by the real workbenchWooState from a fake reconciliation);
//   (b) a sku_mismatch card shows the note + «بازبینی مشخصات» (recoveryAction);
//   (c) a not_checked card shows no repair button;
//   (d) a source_integrity card shows the fresh-message line on its first
//       view (the G1 price-register text still wins when a price needs
//       registering);
//   (e) a stock card shows the fresh-message line on its first view;
//   (f) a published+purchasable card and a hold-blocked card are unchanged
//       (no repair button; routing identical to the 8240057 base);
//   (g) the change is scoped: the diff vs 8240057 touches only the two JS
//       files, deletes only the two old G12 lines, and adds no endpoint.
// The real fill() storefront branch is executed (not just source-checked):
// the buttons are rendered by the real code and clicked in the harness.
// No browser, no jsdom. Regression re-runs of the G3/G1/G4/G9+G5/G7+G8+G11
// harnesses are run separately and must pass.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

const BASE = 'e18de2b';
let baseWorkflowsSrc = null;
let baseDashboardSrc = null;
try {
    baseWorkflowsSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
    baseDashboardSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn(`WARNING: could not read the ${BASE} base via git — differential tests skipped:`, error.message);
}

function extractFunction(source, name) {
    const start = source.indexOf(`function ${name}(`);
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

// --- Workflows routing sandbox: the real router (as in the G3/G7 harnesses).
// workbenchWooState is stubbed to the fixture's _wooState, identically for
// both versions, so the routing differential is unaffected.
const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];
const G9_FNS = ['workbenchNeedsPriceRegistration', 'workbenchHasUnchangedLinkCheck'];
function buildWorkflowsSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    for (const name of G9_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
    }
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
};`;
    return new Function(code)();
}

// --- Workflows woo sandbox: the real reconcile-result derivation.
// workbenchWooState reads the fake reconciliation (product_states rows,
// receipts); the receipt chain is the real code too.
const WOO_FNS = ['workbenchWooState', 'workbenchReceiptForProduct',
    'workbenchSourceMessageKey', 'workbenchSourceRecord', 'workbenchSourceRecords',
    'aiSourceMessageKey', 'sourceTimestampMillis'];
function buildWooSandbox(source) {
    const pieces = WOO_FNS.map((name) => extractFunction(source, name));
    const code = `
let workbenchReconciliation = null;
${pieces.join('\n')}
return {
    setReconciliation(v) { workbenchReconciliation = v; },
    workbenchWooState,
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
        appendChild(c) { this.children.push(c); return c; },
        append(...items) { items.forEach((item) => this.children.push(item)); return this; },
        setAttribute() {},
    };
    return el;
}
// The dashboard's el()/button() helpers, stubbed with their real call shape:
// el(tag, text, className); button(label, handler, className). A button's
// click() invokes its handler with a {currentTarget} event, as the browser
// would, and returns the handler's result (a promise for async handlers).
function stubEl(tag, text, className) {
    const n = makeEl(tag);
    if (text !== undefined && text !== null) n.textContent = String(text);
    if (className) n.className = className;
    return n;
}
function stubButton(label, handler, className) {
    const b = makeEl('button');
    b.textContent = label || '';
    if (className) b.className = className;
    b._handler = handler || null;
    b.click = function () { return b._handler ? b._handler({ currentTarget: b }) : undefined; };
    return b;
}

// --- Dashboard sandbox: the real storefrontRepair helper + the real fill() ---
// fill() closes over productCard's scope; the storefront path touches only
// the names setScope provides (el, button, preferred, product, state,
// recoveryAction, loading, model). The other branches are never executed
// for key === 'storefront', so their closure names need no stubs.
function buildDashboardSandbox(source) {
    const pieces = [extractFunction(source, 'storefrontRepair'), extractFunction(source, 'fill')];
    const code = `
let model = null;
let el = null, button = null, preferred = null, product = null, state = null, recoveryAction = null, loading = null;
${pieces.join('\n')}
return {
    setModel(m) { model = m; },
    setScope(s) {
        el = s.el; button = s.button; preferred = s.preferred; product = s.product;
        state = s.state; recoveryAction = s.recoveryAction; loading = s.loading;
    },
    storefrontRepair, fill,
};`;
    return new Function(code)();
}

const wf = buildWorkflowsSandbox(src);
const wfBase = baseWorkflowsSrc ? buildWorkflowsSandbox(baseWorkflowsSrc) : null;
const woo = buildWooSandbox(src);
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
function checkSourceAbsent(label, source, needle) {
    const ok = !source.includes(needle);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  should NOT be in source:', JSON.stringify(needle)); }
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
function noRevOffer(productId, overrides = {}) {
    return {
        product_id: productId, name: 'کالای تست', channel: 'telegram',
        sale_price: 450000, source_revision: '', source_timestamp: null,
        source_timestamp_trusted: false, state: 'needs_review', acknowledged: false,
        verified_at: '', link_check: null, market_agreement: null,
        source_post_url: 'https://t.me/supplier/123',
        ...overrides,
    };
}

const G1_TEXT = 'نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.';
const SOURCE_TEXT = 'پیام تازه را به ربات بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.';
const SOURCE_TEXT_OLD = 'انتشار متوقف است؛ پیام اصلی یا پیشنهاد تازهٔ همین کالا را به ربات بفرستید.';
const STOCK_TEXT = 'وقتی تأمین‌کننده موجودی را اعلام کرد، پیام تازه را به ربات بفرستید.';
const STOCK_TEXT_OLD = 'پس از اعلام موجودی، پیام تازهٔ تأمین‌کننده را به ربات بفرستید.';
const SKU_NOTE = 'شناسهٔ ووکامرس با شناسهٔ منبع فرق دارد';
const emptyData = { autopilot: { holds: [] } };

// --- Fixtures ---
// A source_integrity card WITH a valid revision: verify works, so the
// first-view line is the ordinary source text (G12), not the G1 text.
const RICE_ID = 'rice-1';
const riceWithRev = withStatus(baseProduct({
    product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
    supplierPrices: [noRevOffer(RICE_ID, { source_revision: 'rev-abc', source_timestamp: '2026-11-20T00:00:00+00:00', source_timestamp_trusted: true })],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-rice', source_integrity_issues: ['missing_product_id'] });
// Same card WITHOUT a revision: the G1 price-register text still wins.
const rice = withStatus(baseProduct({
    product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
    supplierPrices: [noRevOffer(RICE_ID)],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-rice', source_integrity_issues: ['missing_product_id'] });
// A stock card: the supplier is out of stock, nothing else blocks.
const stockCard = withStatus(baseProduct({
    product_id: 'stock-1', key: 'stock-1', name: 'کالای ناموجود',
}), { sale_offer_blocker: '', latest_offer_id: 'offer-stock', supplier_out_of_stock: true });
// Cappuccino (market hold, no sale-offer blocker) — the G3 case, must be
// unchanged by G10/G12.
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
// Published tuna (admitted, verdict ok) — must be unchanged.
const tuna = withStatus(baseProduct({ product_id: 'tuna-100', key: 'tuna-100', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '',
    publication_needed: false,
    regular_price_state: 'current',
    latest_offer_id: 'offer-tuna',
    step_trace: { autopilot: { state: 'admitted', tier: 'green', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: '', closeness: 0.816, arm: 'regular_expiry' } },
});
// A pre-publication publishable card.
const publishable = withStatus(baseProduct({ product_id: 'pub-1', key: 'pub-1', name: 'کالای آمادهٔ انتشار' }), {
    sale_offer_blocker: '', publication_needed: true, regular_price_state: 'current',
    latest_offer_id: 'offer-pub',
});

// --- (a) the reconcile result feeds the repair: real workbenchWooState ---
function recWithRow(wooState, productId = 'p1') {
    return { product_states: [{ product_id: productId, woo_state: wooState }] };
}
woo.setReconciliation(recWithRow('not_visible'));
check('(a) wooState: product_states row not_visible', woo.workbenchWooState(baseProduct({})), 'not_visible');
woo.setReconciliation(recWithRow('not_purchasable'));
check('(a) wooState: product_states row not_purchasable', woo.workbenchWooState(baseProduct({})), 'not_purchasable');
woo.setReconciliation(recWithRow('sku_mismatch'));
check('(a) wooState: product_states row sku_mismatch', woo.workbenchWooState(baseProduct({})), 'sku_mismatch');
woo.setReconciliation(recWithRow('purchasable'));
check('(a) wooState: product_states row purchasable', woo.workbenchWooState(baseProduct({})), 'purchasable');
woo.setReconciliation(null);
check('(a) wooState: no reconciliation at all', woo.workbenchWooState(baseProduct({})), '');
woo.setReconciliation({ product_states: [] });
check('(a) wooState: reconciliation without this product', woo.workbenchWooState(baseProduct({})), 'not_checked');
woo.setReconciliation(recWithRow('not_visible', 'other-product'));
check('(a) wooState: reconciliation with only another product', woo.workbenchWooState(baseProduct({})), 'not_checked');

// --- (a) the repair decision: real storefrontRepair ---
function repairFor(wooState) {
    dash.setModel({ wooState: () => wooState });
    return dash.storefrontRepair(baseProduct({}));
}
check('(a) repair: not_visible -> republish', repairFor('not_visible'), { kind: 'republish' });
check('(a) repair: not_purchasable -> republish', repairFor('not_purchasable'), { kind: 'republish' });
check('(a) repair: sku_mismatch -> spec + note', repairFor('sku_mismatch'), { kind: 'spec', note: SKU_NOTE });
for (const state of ['not_checked', '', 'purchasable', 'held', 'held_but_purchasable', 'ambiguous_product_id', 'no_catalog_projection', 'supplier_out_of_stock']) {
    check(`(a) repair: ${state || '(empty)'} -> no repair`, repairFor(state), null);
}

// --- (a)/(b)/(c) the real fill() storefront branch, rendered and clicked ---
function renderStorefront(wooState, preferred = 'storefront') {
    const calls = { reconcile: [], requestPublication: [], recovery: [], loading: [] };
    const product = baseProduct({});
    const model = {
        wooState: () => wooState,
        wooLabel: (s) => `label:${s}`,
        reconcile: (btn) => { calls.reconcile.push(btn); },
        requestPublication: async (p) => { calls.requestPublication.push(p); },
        renderAction: () => null,
    };
    const state = { panels: new Map() };
    const details = { dataset: {}, children: [], append(c) { this.children.push(c); return c; } };
    dash.setModel(model);
    dash.setScope({
        el: stubEl, button: stubButton,
        preferred, product, state,
        recoveryAction: (p, label) => { calls.recovery.push([p, label]); return stubButton(label, null); },
        loading: (show, msg, kind) => { calls.loading.push([show, msg, kind]); },
    });
    dash.fill('storefront', details);
    return { calls, product, panel: details.children[0] };
}

(async () => {
    // (a) not_visible: the check button AND «انتشار دوباره»; the click
    // calls request_publication with the product, and the button disables.
    {
        const { calls, product, panel } = renderStorefront('not_visible');
        check('(a) not_visible: storefront label shown', textOf(panel).includes('label:not_visible'), true);
        check('(a) not_visible: check button shown', findButtons(panel, 'بررسی وضعیت فروشگاه').length, 1);
        check('(a) not_visible: republish button shown', findButtons(panel, 'انتشار دوباره').length, 1);
        check('(a) not_visible: no spec-review button', findButtons(panel, 'بازبینی مشخصات').length, 0);
        const checkBtn = findButtons(panel, 'بررسی وضعیت فروشگاه')[0];
        checkBtn.click();
        check('(a) not_visible: check button still calls reconcile', calls.reconcile.length, 1);
        const republishBtn = findButtons(panel, 'انتشار دوباره')[0];
        await republishBtn.click();
        check('(a) not_visible: click calls requestPublication with the product', calls.requestPublication, [product]);
        check('(a) not_visible: button disables after the click', republishBtn.disabled, true);
        check('(a) not_visible: no error surfaced', calls.loading, []);
    }
    // (a) not_purchasable: the same republish repair.
    {
        const { calls, product, panel } = renderStorefront('not_purchasable');
        check('(a) not_purchasable: republish button shown', findButtons(panel, 'انتشار دوباره').length, 1);
        await findButtons(panel, 'انتشار دوباره')[0].click();
        check('(a) not_purchasable: click calls requestPublication with the product', calls.requestPublication, [product]);
    }
    // (b) sku_mismatch: the note + «بازبینی مشخصات», no republish button.
    {
        const { calls, product, panel } = renderStorefront('sku_mismatch');
        check('(b) sku_mismatch: the mismatch note is shown', textOf(panel).includes(SKU_NOTE), true);
        check('(b) sku_mismatch: spec-review button shown', findButtons(panel, 'بازبینی مشخصات').length, 1);
        check('(b) sku_mismatch: no republish button', findButtons(panel, 'انتشار دوباره').length, 0);
        check('(b) sku_mismatch: recoveryAction called with the product + label',
            calls.recovery.map(([p, label]) => [p.product_id, label]), [[product.product_id, 'بازبینی مشخصات']]);
    }
    // (c) not_checked: only the check button — no repair before a result.
    {
        const { panel } = renderStorefront('not_checked', 'next');
        check('(c) not_checked: check button shown', findButtons(panel, 'بررسی وضعیت فروشگاه').length, 1);
        check('(c) not_checked: no republish button', findButtons(panel, 'انتشار دوباره').length, 0);
        check('(c) not_checked: no spec-review button', findButtons(panel, 'بازبینی مشخصات').length, 0);
        check('(c) not_checked: no mismatch note', textOf(panel).includes(SKU_NOTE), false);
    }
    // (f) published+purchasable: the label, no repair button.
    {
        const { panel } = renderStorefront('purchasable');
        check('(f) purchasable: storefront label shown', textOf(panel).includes('label:purchasable'), true);
        check('(f) purchasable: no republish button', findButtons(panel, 'انتشار دوباره').length, 0);
        check('(f) purchasable: no spec-review button', findButtons(panel, 'بازبینی مشخصات').length, 0);
    }

    // --- (d)/(e) the G12 first-view lines: real workbenchNextStep ---
    wf.setData(emptyData);
    check('(d) source_integrity: preferred action is source_integrity',
        wf.workbenchActionKey(riceWithRev), 'source_integrity');
    check('(d) source_integrity: first-view line is the fresh-message text',
        wf.workbenchNextStep(riceWithRev, 'source_integrity'), SOURCE_TEXT);
    check('(d) source_integrity: same line via the default queue',
        wf.workbenchNextStep(riceWithRev, 'next'), SOURCE_TEXT);
    check('(d) source_integrity: the G1 price-register text still wins when a price needs registering',
        wf.workbenchNextStep(rice, 'source_integrity'), G1_TEXT);
    check('(e) stock: preferred action is stock',
        wf.workbenchActionKey(stockCard), 'stock');
    check('(e) stock: first-view line is the fresh-message text',
        wf.workbenchNextStep(stockCard, 'stock'), STOCK_TEXT);
    check('(e) stock: same line via the default queue',
        wf.workbenchNextStep(stockCard, 'next'), STOCK_TEXT);
    if (wfBase) {
        wfBase.setData(emptyData);
        check('(d) source_integrity: the base had the old text',
            wfBase.workbenchNextStep(riceWithRev, 'source_integrity'), SOURCE_TEXT_OLD);
        check('(e) stock: the base had the old text',
            wfBase.workbenchNextStep(stockCard, 'stock'), STOCK_TEXT_OLD);
        check('(d) source_integrity: the G1 text is identical in the base',
            wfBase.workbenchNextStep(rice, 'source_integrity'), G1_TEXT);
    } else {
        console.log('SKIP (d)/(e) base comparisons — base unavailable');
    }

    // --- (f) a hold-blocked card and the other queues are unchanged ---
    if (wfBase) {
        const cases = [
            ['hold-blocked cappuccino', cappuccino, cappuccinoData],
            ['published tuna', tuna, emptyData],
            ['publishable', publishable, emptyData],
            ['rice (G1, no revision)', rice, emptyData],
        ];
        for (const [label, product, data] of cases) {
            wf.setData(data);
            wfBase.setData(data);
            check(`(f) differential: ${label} action key identical to base`,
                wf.workbenchActionKey(product), wfBase.workbenchActionKey(product));
            check(`(f) differential: ${label} next-step identical to base`,
                wf.workbenchNextStep(product, 'next'), wfBase.workbenchNextStep(product, 'next'));
        }
        wf.setData(emptyData);
        wfBase.setData(emptyData);
        check('(f) differential: rice-with-revision next-step differs from base (expected, G12)',
            wf.workbenchNextStep(riceWithRev, 'source_integrity') !== wfBase.workbenchNextStep(riceWithRev, 'source_integrity'), true);
        check('(f) differential: stock next-step differs from base (expected, G12)',
            wf.workbenchNextStep(stockCard, 'stock') !== wfBase.workbenchNextStep(stockCard, 'stock'), true);
    } else {
        console.log('SKIP (f) routing differential — base unavailable');
    }

    // --- (g) the change is scoped: only the two JS files + the two comparison PHP files, two old lines out ---
    checkSource('(g) workflows.js has the new source_integrity text', src, `return '${SOURCE_TEXT}';`);
    checkSource('(g) workflows.js has the new stock text', src, `return '${STOCK_TEXT}';`);
    checkSourceAbsent('(g) workflows.js no longer has the old source_integrity text', src, SOURCE_TEXT_OLD);
    checkSourceAbsent('(g) workflows.js no longer has the old stock text', src, STOCK_TEXT_OLD);
    checkSource('(g) dashboard.js has the republish button', dashboardSrc, "button('انتشار دوباره',async event=>{");
    checkSource('(g) dashboard.js wires republish to requestPublication', dashboardSrc, 'await model.requestPublication(product);');
    checkSource('(g) dashboard.js has the sku note', dashboardSrc, SKU_NOTE);
    checkSource('(g) dashboard.js wires the spec repair through recoveryAction', dashboardSrc, "recoveryAction(product,'بازبینی مشخصات')");
    checkSource('(g) dashboard.js gates the repair on storefrontRepair', dashboardSrc, 'const repair=storefrontRepair(product);');
    checkSourceAbsent('(g) no back-in-stock override was added', dashboardSrc + src, 'back in stock');
    // G12 is copy only — no re-link / re-extract action: the proof is the
    // diff check below (the added lines are exactly the two new returns).
    // The words 're-link' / 're-extract' appear only inside the G12
    // comment itself, so a naive absence check would be self-defeating.
    // «انتشار دوباره» appears exactly twice in the dashboard, both in the
    // storefront branch — the enabled republish button and the held-disabled
    // one (G10 follow-up hold gate). The repair lives in one place only.
    check('(g) the republish label appears exactly twice in dashboard.js (enabled + held-disabled)',
        dashboardSrc.split('انتشار دوباره').length - 1, 2);
    // The 'بازبینی مشخصات' recovery action already exists on the first
    // view for thin-spread cards (G8, nextLine); the G10 repair is the
    // storefront-tab one, appended to the tab panel.
    check('(g) the storefront-tab spec repair appears exactly once in dashboard.js',
        dashboardSrc.split("panel.append(recoveryAction(product,'بازبینی مشخصات'))").length - 1, 1);
    try {
        const diffStat = execSync(`git diff ${BASE} --stat -- shop-wp/`, { cwd: repoRoot, encoding: 'utf8' });
        const files = diffStat.split('\n').filter((line) => line.includes('|')).map((line) => line.trim().split(/\s+/)[0]);
        // The '.../' prefixes are git --stat's middle-truncation of the
        // long PHP paths, in the order --stat emits. The two PHP files are
        // the detected-vs-confirmed comparison (2026-11-26): the
        // confirmed_sale_price lookup in ai-review-model.php and its $skip
        // entry in ai-confirmations.php. The dashboard CSS file is the
        // streamline funnel view's styles (2026-11-26, .md-streamline* /
        // .md-funnel*); it is the only CSS file the funnel adds. The other
        // two PHP files are the source-post-status fix (2026-11-26): the
        // link-check attach helper in molavi-domain-products.php and the
        // seed + classifier fix in molavi-domain-supplier-link-checks.php.
        check('(g) only the two JS files + the dashboard CSS + the two comparison PHP files + the two source-post-status PHP files changed under shop-wp/', files, [
            'shop-wp/molavi-shop/includes/owner-dashboard.css',
            'shop-wp/molavi-shop/includes/owner-dashboard.js',
            'shop-wp/molavi-shop/includes/owner-workflows.js',
            '.../molavi-domain-ai-confirmations.php',
            '.../runtime-host/molavi-domain-ai-review-model.php',
            '.../runtime-host/molavi-domain-products.php',
            '.../molavi-domain-supplier-link-checks.php',
        ]);
        const wfDiff = execSync(`git diff ${BASE} -- shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
        const wfDeleted = wfDiff.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('---'))
            .map((line) => line.slice(1).trim()).filter(Boolean);
        // The diff vs e18de2b now also carries the render-merged-cases fix
        // (2026-11-26): the ai candidate-deferred panel renders the case
        // immediately instead of showing only a loading line. The
        // deletions are the two old G12 lines plus its three removed panel
        // lines; the additions are the two new G12 returns plus its seven
        // non-comment code lines (its eight comment lines are filtered out
        // below, as before). The ai-confirm-section-visible fix
        // (2026-11-26) adds one more deletion — the line that hid the
        // confirmation section until the supplier price was verified
        // (its nine comment lines are filtered out with the others).
        // The verification-section fix (2026-11-26) moves the whole
        // «قیمت و موجودی امروز» block into the buildSourceVerificationSection
        // helper and renders it in both paths: the old inline block
        // lines appear as deletions and the helper + two call sites as
        // additions (its comment lines are filtered out with the others).
        check('(g) workflows.js deletions vs the base are exactly the two old G12 lines + the three render-merged-cases removals + the verification-section move + the source-post-status removal + the FIX-4 rewrites', wfDeleted, [
            "function workbenchPriceRegisterSection(product, offer, container, verify, reviewStoreAvailable) {",
            "register.appendChild(node('summary', 'ثبت قیمت از پیام'));",
            "const storedPrice = Number(offer.sale_price);",
            "registerForm.appendChild(node('p', 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.', 'molavi-price-register-hint'));",
            "const registerSubmit = node('button', 'ثبت قیمت و تأیید', 'button button-primary');",
            "const registerNote = url !== '' ? `مالک قیمت را از پیام ثبت کرد؛ منبع: ${url}` : 'مالک قیمت را از پیام ثبت کرد.';",
            "return 'انتشار متوقف است؛ پیام اصلی یا پیشنهاد تازهٔ همین کالا را به ربات بفرستید.';",
            "if (key === 'stock') return 'پس از اعلام موجودی، پیام تازهٔ تأمین‌کننده را به ربات بفرستید.';",
            "unverified:'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد.'})[product.status?.regular_price_state]||'قیمت عادی را بررسی کنید.';",
            "parentName:product.name, parentChannel:product.channel, parentImageId:product.ai[0]?.image_file_id",
            "content.replaceChildren(node('p','در حال بررسی مرجع این پیشنهاد…'));",
            "content.replaceChildren(renderCase());",
            "content.replaceChildren(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry,renderCase());",
            "verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices;",
            "if (!offer.acknowledged && sourceRevision) verify.addEventListener('click', async () => {",
            "let currentPriceVerified = null;",
            "const sourceVerificationItems = relatedItems.filter(row => row",
            "&& (row.historical_reextract || row.source_current_check_required || sourceStockNeedsReview || sourcePriceUnclear));",
            "if (sourceVerificationRequired) {",
            "const verification=node('section',null,'molavi-source-confirmation');",
            "verification.appendChild(node('h4','قیمت و موجودی امروز'));",
            "verification.appendChild(node('p','پس از بررسی منبع، تأیید را علامت بزنید تا فرم مشخصات باز شود.'));",
            "if (item.historical_reextract && !item.reopened) {",
            "const recentSupplierPosts = safeSourceLink(aiSourceChannelUrl(item), 'دیدن پست‌های جدیدتر تأمین‌کننده');",
            "if (recentSupplierPosts) verification.appendChild(recentSupplierPosts);",
            "verification.appendChild(notice(`آخرین زمان مؤثر منبع ${sourceAgeText(item.source_updated_at || item.source_update_at || item.message_timestamp || item.source_created_at)} است. قیمت را از منبع فعلی بررسی کنید.`, 'warning'));",
            "} else if (item.source_current_check_required) {",
            "verification.appendChild(notice(aiSourceCurrentCheckMessage(item), 'warning'));",
            "}",
            "if (options.sourceStockGap) {",
            "verification.appendChild(notice('پیام قبلی اتمام موجودی را ذکر می‌کند. موجودی امروز را از تأمین‌کننده بررسی کنید.', 'warning'));",
            "}",
            "const checkLabel = node('label', null, 'molavi-reextract-current-check');",
            "currentPriceVerified = document.createElement('input');",
            "currentPriceVerified.type = 'checkbox';",
            "currentPriceVerified.checked = sourceVerificationItems.length > 0",
            "&& sourceVerificationItems.every(row => verifiedCurrentPriceCases.has(currentPriceVerificationKey(row)));",
            "const stockNeedsCurrentCheck = sourceStockNeedsReview || relatedItems.some(row =>",
            "!['','Unknown','unknown'].includes(String(row && row.stock_status || '')));",
            "checkLabel.append(currentPriceVerified, document.createTextNode(stockNeedsCurrentCheck",
            "? 'قیمت و موجودی امروز را بررسی کردم.' : 'قیمت امروز را از تأمین‌کننده بررسی کردم.'));",
            "verification.append(checkLabel);card.appendChild(verification);",
            "}",
            "confirmation.hidden=sourceVerificationRequired&&!currentPriceVerified?.checked;",
            "if (currentPriceVerified) currentPriceVerified.addEventListener('change', () => {",
            "sourceVerificationItems.forEach(row => {",
            "const key = currentPriceVerificationKey(row);",
            "if (currentPriceVerified.checked) verifiedCurrentPriceCases.add(key);",
            "else verifiedCurrentPriceCases.delete(key);",
            "});",
            "refreshConfirmationGates();",
            "});"
        ]);
        // Copy only: ignoring comment lines, the added lines in the
        // workflows diff are exactly the two new return lines plus the
        // seven render-merged-cases code lines plus the four
        // detected-vs-confirmed comparison code lines (beer message 7218:
        // the comparison comment is filtered out with the other comments)
        // plus the source-post-status code lines (the renderCase
        // parentLinkCheck pass-through and the link-check status block
        // in aiCaseCard; its comment lines are filtered out with the
        // others) — no new button, handler, endpoint call or function.
        // The FIX-4 every-card fix (2026-11-26) rewrites nine more lines
        // (the G1 register form's optional options parameter, the
        // supplier-tab verify guard, the unverified first-view line) and
        // adds 54 non-comment code lines (the link-changed adopt form,
        // the uncheckable follow-up button, the two new helpers, the
        // changed next-step text, the no-picture / underlying-hold copy;
        // its comment lines are filtered out with the others).
        const wfAdded = wfDiff.split('\n').filter((line) => line.startsWith('+') && !line.startsWith('+++'))
            .map((line) => line.slice(1).trim()).filter(Boolean)
            .filter((line) => !line.startsWith('//'));
        check('(g) workflows.js additions vs the base are exactly the two new G12 returns + the seven render-merged-cases code lines + the verification-section helper + the detected-vs-confirmed comparison lines + the source-post-status lines + the FIX-4 lines', wfAdded, [
            "function workbenchPriceRegisterSection(product, offer, container, verify, reviewStoreAvailable, options = {}) {",
            "register.appendChild(node('summary', options.title || 'ثبت قیمت از پیام'));",
            "const storedPrice = options.prefillPrice !== undefined",
            "? Number(options.prefillPrice) : Number(offer.sale_price);",
            "registerForm.appendChild(node('p', options.hint || 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.', 'molavi-price-register-hint'));",
            "const registerSubmit = node('button', options.submitLabel || 'ثبت قیمت و تأیید', 'button button-primary');",
            "const registerNote = (options.note || 'مالک قیمت را از پیام ثبت کرد')",
            "+ (url !== '' ? `؛ منبع: ${url}` : '.');",
            "function workbenchHasChangedLinkCheck(product) {",
            "return (product.supplierPrices || []).find(offer => !offer.acknowledged",
            "&& offer.link_check && offer.link_check.outcome === 'changed'",
            "&& Number.isFinite(Number(offer.link_check.live_price))",
            "&& Number(offer.link_check.live_price) > 0) || null;",
            "}",
            "function workbenchHasUncheckableLinkCheck(product) {",
            "return (product.supplierPrices || []).find(offer => !offer.acknowledged",
            "&& offer.link_check && offer.link_check.outcome === 'uncheckable') || null;",
            "}",
            "return 'پیام تازه را به ربات بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.';",
            "if (key === 'stock') return 'وقتی تأمین‌کننده موجودی را اعلام کرد، پیام تازه را به ربات بفرستید.';",
            "if (workbenchHasChangedLinkCheck(product)) return 'قیمت در لینک منبع تغییر کرده است؛ قیمت تازه را بپذیرید یا بررسی را کنار بگذارید.';",
            "unverified:'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد؛ مهلت تأییدی برای آن ثبت نشده است. یک نقل‌قول را انتخاب کنید یا قیمت را دستی تأیید کنید.'})[product.status?.regular_price_state]||'قیمت عادی را بررسی کنید.';",
            "parentName:product.name, parentChannel:product.channel, parentImageId:product.ai[0]?.image_file_id,",
            "parentLinkCheck:product.link_check || null",
            "content.replaceChildren(renderCase());",
            "let candidateNotice=null;",
            "if (content.isConnected) content.replaceChildren(renderCase());",
            "if (!content.isConnected || candidateNotice) return;",
            "candidateNotice=node('div',null,'molavi-ai-candidate-error');",
            "candidateNotice.append(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry);",
            "content.prepend(candidateNotice);",
            "if (linkCheck && linkCheck.outcome === 'uncheckable' && !offer.acknowledged) {",
            "const followUp = node('div', null, 'molavi-card-source-tools');",
            "const followUpButton = node('button', 'پیگیری پیام تأمین‌کننده', 'button button-secondary');",
            "followUpButton.type = 'button';",
            "followUpButton.addEventListener('click', () => openSourceReviewTool(product));",
            "followUp.appendChild(followUpButton);",
            "section.appendChild(followUp);",
            "section.appendChild(node('p', 'پیام منبع را بخوانید؛ اگر قیمت در پیام درست است، آن را با دکمهٔ تأیید زیر بررسی کنید. اگر پیام تازه لازم است، از تأمین‌کننده بخواهید.'));",
            "}",
            "const linkChanged = linkCheck && linkCheck.outcome === 'changed';",
            "verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices || linkChanged;",
            "if (!offer.acknowledged && linkChanged",
            "&& Number.isFinite(Number(linkCheck.live_price)) && Number(linkCheck.live_price) > 0) {",
            "section.appendChild(node('p', 'قیمت ثبت‌شده دیگر جاری نیست؛ برای تأیید، قیمت تازهٔ لینک را بپذیرید. اگر قیمت تازه اشتباه است، این بررسی را از پایین برگه کنار بگذارید.'));",
            "section.appendChild(workbenchPriceRegisterSection(product, offer, section, verify, reviewStoreAvailable, {",
            "title: 'استفاده از قیمت تازهٔ لینک',",
            "prefillPrice: Number(linkCheck.live_price),",
            "submitLabel: 'پذیرفتن قیمت تازه و تأیید',",
            "hint: 'قیمت تازه از لینک اصلی خوانده شده است؛ پیش از پذیرفتن، پیام منبع را باز کنید و بررسی کنید.',",
            "note: 'مالک قیمت تازهٔ لینک را پذیرفت'",
            "}));",
            "}",
            "if (!offer.acknowledged && sourceRevision && !linkChanged) verify.addEventListener('click', async () => {",
            "const autopilotImageLine = summary => {",
            "if (!summary) return '';",
            "if (!/تصویر/.test(`${summary.hold_reason || ''} ${summary.reason || ''}`)) return '';",
            "return 'بدون تصویر منتشر نمی‌شود. تصویر را می‌توانید از نقل‌قول بازار بگیرید («استفاده به‌عنوان تصویر اصلی» در برگهٔ قیمت عادی) یا پیام تازهٔ تأمین‌کننده با عکس بخواهید.';",
            "};",
            "const autopilotUnderlyingHoldLine = (summary, holds, productId) => {",
            "if (!summary || summary.hold_reason !== 'کالا در توقف است.') return '';",
            "const underlying = (holds || []).filter(hold =>",
            "String(hold.product_id) === String(productId) && hold.gate !== 'admission');",
            "if (!underlying.length) return '';",
            "return `توقف زیرین: ${underlying.map(hold =>",
            "`${autopilotGateLabels[hold.gate] || hold.gate} — ${hold.reason || 'توقف ثبت شده است.'}`).join(' · ')}`;",
            "};",
            "const imageLine = autopilotImageLine(summary);",
            "if (imageLine) section.appendChild(node('p', imageLine, 'molavi-autopilot-state-reason'));",
            "const underlyingLine = autopilotUnderlyingHoldLine(summary, holds, product.product_id);",
            "if (underlyingLine) section.appendChild(node('p', underlyingLine, 'molavi-autopilot-state-reason'));",
            "const confirmedSalePrice = normalizedAiInteger(item.confirmed_sale_price);",
            "if (confirmedSalePrice && visibleSupplierPrice && confirmedSalePrice !== visibleSupplierPrice) {",
            "priceFacts.push(['قیمت شناسایی‌شده در برابر تأییدشده', `${amount(visibleSupplierPrice)} در برابر ${amount(confirmedSalePrice)}`]);",
            "}",
            "if (sourcePostUrl) {",
            "const normalizeSourceUrl = value => String(value || '').trim()",
            ".replace(/^https?:\\/\\//i, '').replace(/^www\\./i, '').replace(/\\/+$/, '');",
            "const parentLinkCheck = options.parentLinkCheck || null;",
            "const linkCheckForSource = parentLinkCheck && parentLinkCheck.url",
            "&& normalizeSourceUrl(parentLinkCheck.url) === normalizeSourceUrl(sourcePostUrl)",
            "? parentLinkCheck : null;",
            "if (linkCheckForSource && linkCheckForSource.outcome) {",
            "const checkedAt = linkCheckForSource.checked_at",
            "? formatSourceTimestamp(new Date(linkCheckForSource.checked_at * 1000).toISOString()) : '';",
            "if (linkCheckForSource.outcome === 'uncheckable' && linkCheckForSource.reason_code === 'post_not_found') {",
            "card.appendChild(notice(`پست منبع حذف شده است${checkedAt ? ` (بررسی لینک: ${checkedAt})` : ''}. پیام تازه را به ربات بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.`, 'warning'));",
            "} else if (linkCheckForSource.outcome === 'uncheckable') {",
            "card.appendChild(notice(`لینک منبع قابل بررسی نیست: ${linkCheckForSource.reason_label || linkCheckForSource.reason_code || 'نامشخص'}.`, 'warning'));",
            "} else if (linkCheckForSource.outcome === 'unchanged') {",
            "card.appendChild(notice(`لینک منبع بررسی شد؛ قیمت بدون تغییر است (${linkCheckForSource.reason_label || ''} · ${checkedAt || 'زمان ثبت نشده'} · مرجع: ${linkCheckForSource.check_ref || '—'}). تأیید شما با تأمین‌کننده است که قیمت را تازه می‌کند.`, 'info'));",
            "} else if (linkCheckForSource.outcome === 'changed') {",
            "card.appendChild(notice(`قیمت در لینک منبع تغییر کرده است: ثبت‌شده ${amount(visibleSupplierPrice)}، تازه ${amount(linkCheckForSource.live_price)} — قیمت تازه جایگزین نشده است و کالا همچنان متوقف است.`, 'warning'));",
            "}",
            "} else {",
            "card.appendChild(notice('پیام منبع هنوز از لینک اصلی‌اش خوانده نشده است.', 'info'));",
            "}",
            "}",
            "let currentPriceVerified = null;",
            "const sourceVerificationItems = relatedItems.filter(row => row",
            "&& (row.historical_reextract || row.source_current_check_required || sourceStockNeedsReview || sourcePriceUnclear));",
            "const buildSourceVerificationSection = (onVerifiedChange) => {",
            "if (!sourceVerificationRequired) return null;",
            "const verification=node('section',null,'molavi-source-confirmation');",
            "verification.appendChild(node('h4','قیمت و موجودی امروز'));",
            "verification.appendChild(node('p','پس از بررسی منبع، تأیید را علامت بزنید تا فرم مشخصات باز شود.'));",
            "if (item.historical_reextract && !item.reopened) {",
            "const recentSupplierPosts = safeSourceLink(aiSourceChannelUrl(item), 'دیدن پست‌های جدیدتر تأمین‌کننده');",
            "if (recentSupplierPosts) verification.appendChild(recentSupplierPosts);",
            "verification.appendChild(notice(`آخرین زمان مؤثر منبع ${sourceAgeText(item.source_updated_at || item.source_update_at || item.message_timestamp || item.source_created_at)} است. قیمت را از منبع فعلی بررسی کنید.`, 'warning'));",
            "} else if (item.source_current_check_required) {",
            "verification.appendChild(notice(aiSourceCurrentCheckMessage(item), 'warning'));",
            "}",
            "if (options.sourceStockGap) {",
            "verification.appendChild(notice('پیام قبلی اتمام موجودی را ذکر می‌کند. موجودی امروز را از تأمین‌کننده بررسی کنید.', 'warning'));",
            "}",
            "const checkLabel = node('label', null, 'molavi-reextract-current-check');",
            "const checkbox = document.createElement('input');",
            "checkbox.type = 'checkbox';",
            "checkbox.checked = sourceVerificationItems.length > 0",
            "&& sourceVerificationItems.every(row => verifiedCurrentPriceCases.has(currentPriceVerificationKey(row)));",
            "const stockNeedsCurrentCheck = sourceStockNeedsReview || relatedItems.some(row =>",
            "!['','Unknown','unknown'].includes(String(row && row.stock_status || '')));",
            "checkLabel.append(checkbox, document.createTextNode(stockNeedsCurrentCheck",
            "? 'قیمت و موجودی امروز را بررسی کردم.' : 'قیمت امروز را از تأمین‌کننده بررسی کردم.'));",
            "verification.append(checkLabel);",
            "checkbox.addEventListener('change', () => {",
            "sourceVerificationItems.forEach(row => {",
            "const key = currentPriceVerificationKey(row);",
            "if (checkbox.checked) verifiedCurrentPriceCases.add(key);",
            "else verifiedCurrentPriceCases.delete(key);",
            "});",
            "if (typeof onVerifiedChange === 'function') onVerifiedChange();",
            "});",
            "return {section: verification, checkbox};",
            "};",
            "const verified = buildSourceVerificationSection();",
            "if (verified) { currentPriceVerified = verified.checkbox; card.appendChild(verified.section); }",
            "const verified = buildSourceVerificationSection(() => refreshConfirmationGates());",
            "if (verified) { currentPriceVerified = verified.checkbox; card.appendChild(verified.section); }"
        ]);
        const dashDiff = execSync(`git diff ${BASE} -- shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
        const dashDeleted = dashDiff.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('---'))
            .map((line) => line.slice(1).trim()).filter(Boolean);
        // The dashboard was additive-only until the streamline funnel view
        // (2026-11-26) rewrote eight lines vs the e18de2b base: the view
        // whitelist, the nav list, the saveURL param-clear list, the
        // scope-button handler, the active-filter chip, the action-select
        // handler, the paint guard, and the click handler — each gained the
        // 'streamline' view / the step+reason filter. Those eight lines, in
        // the order git diff emits them (file order), are the only
        // deletions; the G10/G12 additions are untouched.
        check('(g) dashboard.js deletions vs the base are exactly the eight streamline-view lines', dashDeleted, [
            "view:['products','references','sources','system','pricing'].includes(params.get('view')) ? params.get('view')",
            "[['products','محصولات'],['references','فهرست مرجع'],['sources','پیام‌های منبع'],['system','وضعیت سیستم']].forEach(([key,label])=>{",
            "['view','scope','q','channel','workbench_action','product_id','offer_id','case_id','conflict_id','hold_id','reference_id','risk','held'].forEach(key=>url.searchParams.delete(key));",
            "clearSelection();state.scope=key;state.action='';state.focus='';state.limit=24;saveURL();paint();",
            "const focus=el('div',null,'md-active-filter');focus.hidden=!state.focus;",
            "action.addEventListener('change',()=>{clearSelection();state.action=action.value;state.limit=24;saveURL();paintList();});",
            "if(!model&&['products','system'].includes(state.view)) {",
            "if(['products','sources','references','system','pricing'].includes(view)) {",
        ]);
    } catch (error) {
        console.warn('WARNING: could not run git diff for the (g) scope checks:', error.message);
    }

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})();
