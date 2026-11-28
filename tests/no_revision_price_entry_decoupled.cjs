// No-revision price entry, decoupled (G1 follow-up, owner request 2026-11-26):
// Node harness. Run: node tests/no_revision_price_entry_decoupled.cjs
// G1 deployed but the browser check FAILED: «ثبت قیمت از پیام» appeared on
// ZERO live cards. Root cause: both G1 surfaces were gated on the card's
// preferred action being 'supplier_prices' (the first-view button and the
// register form inside the supplier_prices tab), but the 25 empty-revision
// offers sit on cards whose preferred action is 'source_integrity' — their
// missing source URL is itself an integrity issue that outranks the price
// queue. The only card with a supplier tab is the anti-hangover card, whose
// offer HAS a revision. This follow-up decouples the register UI from the
// preferred action: the form is extracted into workbenchPriceRegisterSection
// and renders in the source tab («پیام تأمین‌کننده»), the tab the card
// actually has; the first-view button follows the no-revision row (for the
// supplier_prices and source_integrity queues); and the next-step text for a
// source_integrity card names the price registration. Other queues keep
// their own first-view action (an earlier queue naming real work is not
// overridden), and a hold-blocked card still routes to the hold's cause.
// Extracts the real functions from includes/owner-workflows.js by brace
// matching, evaluates them against stubbed module state and a minimal DOM
// stub, and asserts:
//   (a) a source_integrity card with an empty-revision offer shows the G1
//       next-step text, and the register form builds in the source tab
//       (structure + submit validation + success path, with and without a
//       verify button);
//   (b) a with-revision card is unchanged (no register form, ordinary text);
//   (c) a hold-blocked card still routes to the hold's cause (regression);
//   (d) the G1 submit path still writes evidence and re-arms verify —
//       proven by the PHP harness (tests/native_no_revision_price_entry.php),
//       re-run separately; here the JS submit wiring is proven end to end
//       against stubbed transport;
//   (e) no other card state changed its routing — a DIFFERENTIAL test: the
//       same fixtures run through the base (08202e8) file and the new file,
//       and the results must be identical except for the source_integrity
//       no-revision text.
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

const BASE = '08202e8';
let baseSrc = null;
try {
    baseSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn(`WARNING: could not read the ${BASE} base via git — differential tests skipped:`, error.message);
}

function extractFunction(source, name) {
    const start = source.indexOf(`    function ${name}(`);
    if (start < 0) throw new Error('function not found: ' + name);
    // The body brace is the first '{' AFTER the parameter list closes —
    // a naive search finds the '{}' of a default parameter (field's
    // `options = {}`) and returns only the signature.
    const paren = source.indexOf('(', start);
    let pdepth = 0;
    let quote = null;
    let escaped = false;
    let i = paren;
    for (; i < source.length; i++) {
        const ch = source[i];
        if (quote) {
            if (escaped) { escaped = false; continue; }
            if (ch === '\\') { escaped = true; continue; }
            if (ch === quote) quote = null;
            continue;
        }
        if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
        if (ch === '(') pdepth++;
        else if (ch === ')') { pdepth--; if (pdepth === 0) break; }
    }
    const open = source.indexOf('{', i);
    let depth = 0;
    for (let j = open; j < source.length; j++) {
        if (source[j] === '{') depth++;
        else if (source[j] === '}') {
            depth--;
            if (depth === 0) return source.slice(start, j + 1);
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
const G1_FNS = ['workbenchNeedsPriceRegistration'];
const G9_FNS = ['workbenchHasUnchangedLinkCheck'];

function buildRoutingSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    for (const name of G1_FNS) pieces.push(extractFunction(source, name));
    if (source.includes('function workbenchHasUnchangedLinkCheck(')) {
        for (const name of G9_FNS) pieces.push(extractFunction(source, name));
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
    workbenchHoldRoute, workbenchNeedsPriceRegistration,
    ${source.includes('function workbenchHasUnchangedLinkCheck(') ? 'workbenchHasUnchangedLinkCheck,' : ''}
};`;
    return new Function(code)();
}

const routing = buildRoutingSandbox(src);
const baseRouting = baseSrc ? buildRoutingSandbox(baseSrc) : null;

// --- Minimal DOM stub (same pattern as tests/step_trace_render.cjs) ---
function makeEl(tag) {
    const el = {
        tagName: String(tag).toUpperCase(),
        children: [],
        className: '',
        textContent: '',
        open: false,
        disabled: false,
        value: '',
        name: '',
        listeners: {},
        reportValidity() { return this._reportValidity !== false; },
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
        appendChild(c) { this.children.push(c); return c; },
        append(...items) { items.forEach((item) => this.children.push(item)); return this; },
        prepend(...items) { this.children.unshift(...items); return this; },
        replaceChildren(...items) { this.children = [...items]; return this; },
        setAttribute() {},
    };
    return el;
}
class StubFormData {
    constructor(form) { this.form = form; }
    get(name) {
        const walk = (el) => {
            for (const c of el.children || []) {
                if (c.name === name) return c.value;
                const v = walk(c);
                if (v !== undefined) return v;
            }
            return undefined;
        };
        return walk(this.form);
    }
}
function findInput(root, name) {
    const walk = (el) => {
        for (const c of el.children || []) {
            if (c.name === name) return c;
            const found = walk(c);
            if (found) return found;
        }
        return null;
    };
    return walk(root);
}
function findText(root, text) {
    const walk = (el) => {
        if (typeof el.textContent === 'string' && el.textContent.includes(text)) return el;
        for (const c of el.children || []) {
            const found = walk(c);
            if (found) return found;
        }
        return null;
    };
    return walk(root);
}

// Build a sandbox holding the extracted register-form helper plus stubbed
// transport. `storeOk` is passed as the helper's `reviewStoreAvailable`
// parameter (the submit button's disabled state). The transport stubs record
// their calls. NOTE: `contextStored` is deliberately NOT injected here —
// the G1 follow-up shipped a helper that read the dashboard-scoped
// `contextStored` object, and this harness masked it by injecting the
// binding. If any extracted function reintroduces a bare `contextStored`
// reference, this sandbox must throw ReferenceError, not pass.
function buildHelperSandbox(storeOk = true) {
    const calls = { persist: [], supplierPrice: [], dashboard: [] };
    const documentStub = { createElement: makeEl, createElementNS: (_ns, tag) => makeEl(tag) };
    const rootStub = makeEl('div');
    const fns = ['node', 'field', 'notice', 'errorBox', 'actionStatus', 'updateActionStatus',
        'workbenchPriceRegisterSection'].map((name) => extractFunction(src, name)).join('\n');
    const factory = new Function('document', 'FormData', 'reviewEventId',
        'reviewRecord', 'persistOwnerReviews', 'requestSupplierPrice', 'dashboard', 'errorText', 'root', `
${fns}
return { workbenchPriceRegisterSection };`);
    const api = factory(
        documentStub,
        StubFormData,
        () => 'evt-1',
        (id) => ({ eventId: id }),
        async (records) => { calls.persist.push(...records); return []; },
        async (payload) => {
            calls.supplierPrice.push(payload);
            return { product_id: payload.product_id, source_revision: 'rev-new-64hex',
                verified_at: '2026-11-26T00:00:00Z', publish_job: 'job-1' };
        },
        async (message, kind, keys) => { calls.dashboard.push({ message, kind, keys }); },
        (message) => String(message),
        rootStub,
    );
    return { ...api, calls, rootStub, storeOk };
}

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

// --- Fixtures ---
// Rice: the live target case — a no-revision offer on a card whose preferred
// action is source_integrity (its missing source URL is itself an integrity
// issue, which outranks the price queue).
const RICE_ID = 'rice-1';
const rice = withStatus(baseProduct({
    product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
    supplierPrices: [noRevOffer(RICE_ID)],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-rice', source_integrity_issues: ['missing_product_id'] });

// Same card, but WITH a source revision (the control): verify works.
const riceWithRev = withStatus(baseProduct({
    product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
    supplierPrices: [noRevOffer(RICE_ID, { source_revision: 'rev-abc', source_timestamp: '2026-11-20T00:00:00+00:00', source_timestamp_trusted: true })],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-rice', source_integrity_issues: ['missing_product_id'] });

// Acknowledged no-revision row: nothing to register.
const riceAck = withStatus(baseProduct({
    product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
    supplierPrices: [noRevOffer(RICE_ID, { acknowledged: true, state: 'current', verified_at: '2026-11-26T00:00:00+00:00' })],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-rice', source_integrity_issues: ['missing_product_id'] });

// An open AI case with a no-revision offer: the spec queue outranks the
// price queue, so the first-view action stays the spec — the register form
// is only added to the source tab, not the first view.
const aiNoRev = withStatus(baseProduct({
    product_id: 'ai-1', key: 'ai-1', name: 'کالای با استخراج باز',
    ai: [{ case_id: 'c1', offer_id: 'offer-ai', message_review: true, publication_held: false, sale_price: 1250000 }],
    supplierPrices: [noRevOffer('ai-1')],
}), { sale_offer_blocker: '', latest_offer_id: 'offer-ai', source_integrity_issues: [] });

// Cappuccino (market hold, no sale-offer blocker) — the G3 case, must be
// unchanged by the follow-up.
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
const emptyData = { autopilot: { holds: [] } };

const G1_TEXT = 'نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.';
// G12 replaced the ordinary source text with the fresh-message text;
// SOURCE_TEXT_BASE is what the pre-G12 base returns.
const SOURCE_TEXT = 'پیام تازه را به ربات بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.';
const SOURCE_TEXT_BASE = 'انتشار متوقف است؛ پیام اصلی یا پیشنهاد تازهٔ همین کالا را به ربات بفرستید.';

// --- (a) routing: the source_integrity no-revision card ---
routing.setData(emptyData);
check('(a) rice: needsPriceRegistration returns the offer',
    routing.workbenchNeedsPriceRegistration(rice)?.product_id, RICE_ID);
check('(a) rice: preferred action is source_integrity',
    routing.workbenchActionKey(rice), 'source_integrity');
check('(a) rice: next-step text is the G1 text',
    routing.workbenchNextStep(rice, 'source_integrity'), G1_TEXT);
check('(a) rice: next-step via the default queue is the G1 text',
    routing.workbenchNextStep(rice, 'next'), G1_TEXT);

// --- (b) with-revision / acknowledged cards are unchanged ---
check('(b) rice with revision: needsPriceRegistration is null',
    routing.workbenchNeedsPriceRegistration(riceWithRev), null);
check('(b) rice with revision: preferred action is still source_integrity',
    routing.workbenchActionKey(riceWithRev), 'source_integrity');
check('(b) rice with revision: next-step text is the ordinary source text',
    routing.workbenchNextStep(riceWithRev, 'source_integrity'), SOURCE_TEXT);
check('(b) acknowledged no-revision: needsPriceRegistration is null',
    routing.workbenchNeedsPriceRegistration(riceAck), null);
check('(b) acknowledged no-revision: next-step text is the ordinary source text',
    routing.workbenchNextStep(riceAck, 'source_integrity'), SOURCE_TEXT);

// --- earlier queues keep their routing ---
check('(a) ai card with no-revision offer: preferred action stays ai',
    routing.workbenchActionKey(aiNoRev), 'ai');
check('(a) ai card: needsPriceRegistration still finds the row (form is in the source tab)',
    routing.workbenchNeedsPriceRegistration(aiNoRev)?.product_id, 'ai-1');
check('(a) ai card: next-step text is the spec text, not the G1 text',
    routing.workbenchNextStep(aiNoRev, 'ai'), 'مشخصات استخراج‌شده را با پیام منبع بررسی کنید.');

// --- (c) hold-blocked card still routes to the hold's cause ---
routing.setData(cappuccinoData);
check('(c) cappuccino: preferred action is still market_prices (hold route)',
    routing.workbenchActionKey(cappuccino), 'market_prices');
check('(c) cappuccino: next-step is still the market-hold text',
    routing.workbenchNextStep(cappuccino, routing.workbenchActionKey(cappuccino)),
    'نقل‌قول‌های بازار همخوان نیستند؛ یک نقل‌قول را انتخاب کنید.');
routing.setData(emptyData);
check('(c) tuna: preferred action unchanged (published, no queue → next)',
    routing.workbenchActionKey(tuna), 'next');

// --- (a) the register form builds, in the source tab, for a no-revision row ---
async function helperTests() {
    const sandbox = buildHelperSandbox(true);
    const offer = noRevOffer(RICE_ID);
    const product = withStatus(baseProduct({
        product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
        supplierPrices: [offer],
    }), { source_post_url: 'https://t.me/supplier/123', source_integrity_issues: ['missing_product_id'] });
    const container = makeEl('div');
    const details = sandbox.workbenchPriceRegisterSection(product, offer, container, null, sandbox.storeOk);
    check('(a) helper returns a details element', details.tagName, 'DETAILS');
    check('(a) helper details carries the register class', details.className, 'molavi-price-register');
    check('(a) helper details is open by default', details.open, true);
    check('(a) helper summary says ثبت قیمت از پیام',
        findText(details, 'ثبت قیمت از پیام')?.textContent, 'ثبت قیمت از پیام');
    const priceInput = findInput(details, 'sale_price');
    check('(a) helper price field is pre-filled from the stored sale price',
        priceInput?.value, '450000');
    const sourceInput = findInput(details, 'source_url');
    check('(a) helper source field is pre-filled from the stored source URL',
        sourceInput?.value, 'https://t.me/supplier/123');
    check('(a) helper fresh-message hint is present (Telegram stays manual)',
        !!findText(details, 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.'), true);
    const form = details.children.find((c) => c.tagName === 'FORM');
    check('(a) helper form has a submit listener attached',
        (form.listeners.submit || []).length, 1);
    const submitBtn = form.children.find((c) => c.tagName === 'BUTTON');
    check('(a) helper submit button is enabled when the store is available',
        submitBtn.disabled, false);
    check('(a) helper submit button says ثبت قیمت و تأیید',
        submitBtn.textContent, 'ثبت قیمت و تأیید');

    // Invalid price: the error box fires, nothing is submitted.
    priceInput.value = '';
    await form.listeners.submit[0]({ preventDefault() {} });
    check('(a) invalid price: no supplier-price call is made',
        sandbox.calls.supplierPrice.length, 0);
    check('(a) invalid price: the offer is untouched',
        offer.acknowledged, false);
    check('(a) invalid price: the error notice names the problem',
        !!findText(container, 'قیمت معتبر وارد کنید.'), true);

    // Non-https source URL: rejected the same way.
    priceInput.value = '450000';
    sourceInput.value = 'http://t.me/supplier/123';
    await form.listeners.submit[0]({ preventDefault() {} });
    check('(a) non-https source: no supplier-price call is made',
        sandbox.calls.supplierPrice.length, 0);
    check('(a) non-https source: the error notice names the problem',
        !!findText(container, 'پیوند منبع باید با https:// شروع شود.'), true);

    // Valid submit, source-tab case (no verify button): the offer is
    // verified in place and the workbench refreshes.
    sourceInput.value = 'https://t.me/supplier/123';
    await form.listeners.submit[0]({ preventDefault() {} });
    check('(a) valid submit: one register_price_from_message call',
        sandbox.calls.supplierPrice.length, 1);
    check('(a) valid submit: the call carries the action',
        sandbox.calls.supplierPrice[0]?.action, 'register_price_from_message');
    check('(a) valid submit: the call carries the typed price',
        sandbox.calls.supplierPrice[0]?.sale_price, 450000);
    check('(a) valid submit: the call carries the source URL',
        sandbox.calls.supplierPrice[0]?.source_url, 'https://t.me/supplier/123');
    check('(a) valid submit: the offer is acknowledged in place',
        offer.acknowledged, true);
    check('(a) valid submit: the offer carries the new revision',
        offer.source_revision, 'rev-new-64hex');
    check('(a) valid submit: the details close',
        details.open, false);
    check('(a) valid submit: the dashboard refreshes the price reads',
        sandbox.calls.dashboard[0]?.keys,
        ['supplier_prices', 'supplier_link_checks', 'product_status', 'operations']);

    // Supplier-tab case (a verify button is passed): it flips to confirmed.
    const sandbox2 = buildHelperSandbox(true);
    const offer2 = noRevOffer(RICE_ID);
    const product2 = withStatus(baseProduct({
        product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
        supplierPrices: [offer2],
    }), { source_post_url: 'https://t.me/supplier/123' });
    const verifyBtn = makeEl('button');
    verifyBtn.textContent = 'منبع فعلی را دیدم؛ قیمت تأیید است';
    verifyBtn.disabled = true;
    const details2 = sandbox2.workbenchPriceRegisterSection(product2, offer2, makeEl('div'), verifyBtn, sandbox2.storeOk);
    const form2 = details2.children.find((c) => c.tagName === 'FORM');
    await form2.listeners.submit[0]({ preventDefault() {} });
    check('(a) supplier-tab submit: the verify button flips to confirmed',
        verifyBtn.textContent, 'قیمت تأیید شد');
    check('(a) supplier-tab submit: the verify button stays disabled',
        verifyBtn.disabled, true);

    // Store unavailable: the submit button starts disabled.
    const sandbox3 = buildHelperSandbox(false);
    const details3 = sandbox3.workbenchPriceRegisterSection(product, noRevOffer(RICE_ID), makeEl('div'), null, sandbox3.storeOk);
    const form3 = details3.children.find((c) => c.tagName === 'FORM');
    const submit3 = form3.children.find((c) => c.tagName === 'BUTTON');
    check('(a) store unavailable: the submit button starts disabled',
        submit3.disabled, true);
}

// --- (e) differential vs the 08202e8 base ---
function differentialTests() {
    if (!baseRouting) { console.log('SKIPPED differential tests (no base source)'); return; }
    const cases = [
        ['rice action key', rice],
        ['rice with revision action key', riceWithRev],
        ['acknowledged action key', riceAck],
        ['ai card action key', aiNoRev],
        ['tuna action key', tuna],
    ];
    for (const [label, product] of cases) {
        routing.setData(emptyData);
        baseRouting.setData(emptyData);
        check(`(e) differential: ${label} identical to base`,
            routing.workbenchActionKey(product), baseRouting.workbenchActionKey(product));
    }
    // The two source_integrity text cases are NOT in this loop: G12
    // changed the ordinary source text itself, so they differ from the
    // base by design (asserted explicitly below, like the rice case).
    const textCases = [
        ['ai card next-step', aiNoRev, 'ai'],
        ['tuna next-step', tuna, 'next'],
    ];
    for (const [label, product, queue] of textCases) {
        routing.setData(emptyData);
        baseRouting.setData(emptyData);
        check(`(e) differential: ${label} identical to base`,
            routing.workbenchNextStep(product, queue),
            baseRouting.workbenchNextStep(product, queue));
    }
    // The rice next-step text DOES change (that is the point of the follow-up).
    routing.setData(emptyData);
    baseRouting.setData(emptyData);
    check('(e) differential: rice next-step differs from base (expected)',
        routing.workbenchNextStep(rice, 'source_integrity') !== baseRouting.workbenchNextStep(rice, 'source_integrity'), true);
    check('(e) differential: rice next-step is the G1 text in the new file',
        routing.workbenchNextStep(rice, 'source_integrity'), G1_TEXT);
    check('(e) differential: rice next-step was the ordinary source text in the base',
        baseRouting.workbenchNextStep(rice, 'source_integrity'), SOURCE_TEXT_BASE);
    // G12 changed the ordinary source text itself (that is the point of
    // G12): the with-revision and acknowledged cards' next-step differs
    // from the base, is the new text in the current file, and was the old
    // text in the base.
    for (const [label, product] of [['rice with revision', riceWithRev], ['acknowledged', riceAck]]) {
        routing.setData(emptyData);
        baseRouting.setData(emptyData);
        check(`(e) differential: ${label} next-step differs from base (expected, G12)`,
            routing.workbenchNextStep(product, 'source_integrity') !== baseRouting.workbenchNextStep(product, 'source_integrity'), true);
        check(`(e) differential: ${label} next-step is the G12 text in the new file`,
            routing.workbenchNextStep(product, 'source_integrity'), SOURCE_TEXT);
        check(`(e) differential: ${label} next-step was the old text in the base`,
            baseRouting.workbenchNextStep(product, 'source_integrity'), SOURCE_TEXT_BASE);
    }
    // Cappuccino (G3) routing is unchanged by the follow-up.
    routing.setData(cappuccinoData);
    baseRouting.setData(cappuccinoData);
    check('(e) differential: cappuccino action key identical to base',
        routing.workbenchActionKey(cappuccino), baseRouting.workbenchActionKey(cappuccino));
    check('(e) differential: cappuccino next-step identical to base',
        routing.workbenchNextStep(cappuccino, routing.workbenchActionKey(cappuccino)),
        baseRouting.workbenchNextStep(cappuccino, baseRouting.workbenchActionKey(cappuccino)));
}

// --- (a) first view + source tab: source checks ---
function sourceTests() {
    checkSource('(a) first view: the register gate now covers source_integrity too',
        dashboardSrc, "['supplier_prices','source_integrity'].includes(preferred) && model.needsPriceRegistration");
    checkSourceAbsent('(a) first view: the old supplier-only gate is gone',
        dashboardSrc, "preferred==='supplier_prices' && model.needsPriceRegistration");
    checkSource('(a) first view: the register button is still primary',
        dashboardSrc, "(actionable||needsPriceRegistration)?'md-button md-primary':'md-button'");
    checkSource('(a) source tab: fill() renders the register form',
        dashboardSrc, 'model.renderPriceRegister(product, panel)');
    checkSource('(a) source tab: the form is appended to the panel',
        dashboardSrc, 'if (registerBlock) panel.append(registerBlock);');
    checkSource('(a) workflows: renderPriceRegister is exported on the model',
        src, 'renderPriceRegister: (product, container) => {');
    checkSource('(a) workflows: the helper is defined',
        src, 'function workbenchPriceRegisterSection(product, offer, container, verify, reviewStoreAvailable, options = {})');
    checkSource('(a) workflows: the supplier tab calls the helper with its section + verify button + store flag',
        src, 'workbenchPriceRegisterSection(product, offer, section, verify, reviewStoreAvailable)');
    checkSource('(a) workflows: the model export passes the store flag to the helper',
        src, 'workbenchPriceRegisterSection(product, offer, container, null, reviewStoreAvailable)');
    checkSource('(a) workflows: the supplier tab still shows the no-revision notice',
        src, 'برای این ردیف قیمت فروش معتبر در منبع فعلی ثبت نشده است؛ ابتدا داده قیمت را اصلاح کنید.');
    if (baseSrc) {
        checkSourceAbsent('(e) base has no register helper (differential is real)',
            baseSrc, 'workbenchPriceRegisterSection');
        checkSourceAbsent('(e) base has no renderPriceRegister export (differential is real)',
            baseSrc, 'renderPriceRegister');
    }
}

(async () => {
    await helperTests();
    differentialTests();
    sourceTests();
    console.log(`\n${pass} PASS, ${fail} FAIL`);
    if (fail) process.exit(1);
    console.log('ALL NO-REVISION PRICE-ENTRY DECOUPLED CHECKS PASSED');
})();
