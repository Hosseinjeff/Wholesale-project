// Price-register context scope (G1 follow-up hotfix, 2026-11-26): Node harness.
// Run: node tests/price_register_context_scope.cjs
// The G1 follow-up (23a6589) shipped a live regression: the register form was
// extracted into workbenchPriceRegisterSection, which read the dashboard-scoped
// `contextStored.supplier_prices` — but the helper lives at module scope,
// outside async function dashboard(), where `contextStored` is defined. The
// moment it ran for a no-revision product it threw
// `ReferenceError: contextStored is not defined`, aborting the panel render
// (the rice card's «پیام تأمین‌کننده» tab rendered COMPLETELY EMPTY) and the
// dashboard refresh (the «تازه‌سازی» spinner stayed stuck). The decoupled
// harness masked it by injecting `contextStored` into its evaluation scope.
// The fix threads the dashboard's review-store flag through as a parameter:
// workbenchPriceRegisterSection(product, offer, container, verify,
// reviewStoreAvailable), passed from both call sites (the supplier tab and
// the model export renderPriceRegister, whose closure is created inside
// dashboard() where the flag is in scope).
// This harness proves, with NO `contextStored` binding anywhere in scope:
//   (a) the helper body contains no `contextStored` reference at all, and
//       both call sites pass the flag;
//   (b) the rice source_integrity fixture renders the form through the REAL
//       renderPriceRegister closure without throwing — the exact path the
//       source tab takes — with the submit enabled when the store is
//       available and disabled when it is not;
//   (c) the supplier-tab path (helper called with a verify button) still
//       renders and the valid submit flips the verify button;
//   (d) a with-revision card is unchanged (the closure returns null);
//   (e) NEGATIVE CONTROL: the 23a6589 base helper, evaluated the same way
//       (no `contextStored` in scope), DOES throw ReferenceError — so this
//       harness would have caught the shipped bug.
// Extracts the real functions from includes/owner-workflows.js by brace
// matching and evaluates them against stubbed transport and a minimal DOM
// stub. No browser, no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const src = fs.readFileSync(workflowsPath, 'utf8');

const BASE = '23a6589';
let baseSrc = null;
try {
    baseSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn(`WARNING: could not read the ${BASE} base via git — negative-control tests skipped:`, error.message);
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
    let j = open;
    for (; j < source.length; j++) {
        const ch = source[j];
        if (ch === '{') depth++;
        else if (ch === '}') { depth--; if (depth === 0) break; }
    }
    return source.slice(start, j + 1);
}

// Extract the renderPriceRegister arrow closure from the dashboardApp.update
// object literal. Returns the arrow text `(product, container) => {...}`.
function extractRenderPriceRegister(source) {
    const marker = 'renderPriceRegister: (product, container) => {';
    const start = source.indexOf(marker);
    if (start < 0) throw new Error('renderPriceRegister closure not found');
    const arrowStart = source.indexOf('(product, container)', start);
    const open = source.indexOf('{', arrowStart);
    let depth = 0;
    let j = open;
    for (; j < source.length; j++) {
        const ch = source[j];
        if (ch === '{') depth++;
        else if (ch === '}') { depth--; if (depth === 0) break; }
    }
    return source.slice(arrowStart, j + 1);
}

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
// transport. Deliberately NO `contextStored` binding anywhere in scope —
// that is the point: a helper that reads the dashboard-scoped object must
// throw here, exactly as it did live. `storeOk` is the review-store flag
// the caller passes as the helper's 5th argument.
function buildSandbox(source, storeOk = true) {
    const calls = { persist: [], supplierPrice: [], dashboard: [] };
    const documentStub = { createElement: makeEl, createElementNS: (_ns, tag) => makeEl(tag) };
    const rootStub = makeEl('div');
    const fns = ['node', 'field', 'notice', 'errorBox', 'actionStatus', 'updateActionStatus',
        'workbenchPriceRegisterSection', 'workbenchNeedsPriceRegistration']
        .map((name) => extractFunction(source, name)).join('\n');
    const factory = new Function('document', 'FormData', 'reviewEventId',
        'reviewRecord', 'persistOwnerReviews', 'requestSupplierPrice', 'dashboard', 'errorText', 'root', `
${fns}
return { workbenchPriceRegisterSection, workbenchNeedsPriceRegistration };`);
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
    // The real renderPriceRegister closure, evaluated with the real helper
    // and the real needsPriceRegistration — the exact path the source tab
    // takes (dashboard.js fill() calls model.renderPriceRegister).
    const arrow = extractRenderPriceRegister(source);
    const closureFactory = new Function('workbenchNeedsPriceRegistration',
        'workbenchPriceRegisterSection', 'reviewStoreAvailable', `return ${arrow};`);
    const renderPriceRegister = closureFactory(
        api.workbenchNeedsPriceRegistration, api.workbenchPriceRegisterSection, storeOk);
    return { ...api, calls, rootStub, storeOk, renderPriceRegister };
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

const RICE_ID = 'rice-product-1';
function noRevOffer(id) {
    return { product_id: id, name: 'برنج طارم هاشمی مازندارن', channel: 'telegram',
        sale_price: 450000, source_revision: '', source_post_url: 'https://t.me/supplier/123',
        source_timestamp: '2026-11-20T00:00:00Z', verified_at: '', acknowledged: false };
}
function withRevOffer(id) {
    const offer = noRevOffer(id);
    offer.source_revision = 'rev-64hex-present';
    return offer;
}
function riceProduct(offer) {
    return { product_id: RICE_ID, key: RICE_ID, name: 'برنج طارم هاشمی مازندارن',
        supplierPrices: [offer],
        status: { source_post_url: 'https://t.me/supplier/123', source_integrity_issues: ['missing_product_id'] } };
}

async function run() {
    // --- (a) the helper no longer references the out-of-scope object ---
    const helperSrc = extractFunction(src, 'workbenchPriceRegisterSection');
    check('(a) helper body has no contextStored reference',
        (helperSrc.match(/contextStored/g) || []).length, 0);
    check('(a) helper signature takes the store flag as 5th parameter',
        helperSrc.startsWith('    function workbenchPriceRegisterSection(product, offer, container, verify, reviewStoreAvailable, options = {})'), true);
    checkSource('(a) supplier-tab call site passes the store flag',
        src, 'workbenchPriceRegisterSection(product, offer, section, verify, reviewStoreAvailable)');
    checkSource('(a) model export passes the store flag',
        src, 'workbenchPriceRegisterSection(product, offer, container, null, reviewStoreAvailable)');
    check('(a) the only contextStored mention near the helper is the comment explaining the fix',
        (src.slice(src.indexOf('// G1 follow-up — the register-price-from-message form'),
            src.indexOf('function workbenchPriceRegisterSection')).match(/contextStored/g) || []).length, 1);
    if (baseSrc) {
        const baseHelper = extractFunction(baseSrc, 'workbenchPriceRegisterSection');
        check('(a) negative control: the 23a6589 base helper DOES read contextStored (2 refs)',
            (baseHelper.match(/contextStored/g) || []).length, 2);
    }

    // --- (b) the rice card renders through the REAL source-tab closure ---
    const sandbox = buildSandbox(src, true);
    const rice = riceProduct(noRevOffer(RICE_ID));
    const container = makeEl('div');
    let details = null;
    let threw = null;
    try {
        details = sandbox.renderPriceRegister(rice, container);
    } catch (error) { threw = error; }
    check('(b) source-tab closure does not throw (no contextStored in scope)', threw, null);
    check('(b) source-tab closure returns the register details', details?.tagName, 'DETAILS');
    check('(b) source-tab closure returns the register class', details?.className, 'molavi-price-register');
    check('(b) price field is pre-filled from the stored sale price',
        findInput(details, 'sale_price')?.value, '450000');
    const form = details.children.find((c) => c.tagName === 'FORM');
    const submitBtn = form.children.find((c) => c.tagName === 'BUTTON');
    check('(b) submit button is enabled when the store is available', submitBtn.disabled, false);
    // Valid submit through the closure: the offer is verified in place.
    const priceInput = findInput(details, 'sale_price');
    priceInput.value = '450000';
    await form.listeners.submit[0]({ preventDefault() {} });
    check('(b) submit through the closure: one register_price_from_message call',
        sandbox.calls.supplierPrice.length, 1);
    check('(b) submit through the closure: the offer carries the new revision',
        rice.supplierPrices[0].source_revision, 'rev-new-64hex');
    check('(b) submit through the closure: the dashboard refreshes',
        sandbox.calls.dashboard[0]?.keys,
        ['supplier_prices', 'supplier_link_checks', 'product_status', 'operations']);

    // Store unavailable: the closure still renders, submit starts disabled.
    const sandboxOff = buildSandbox(src, false);
    const riceOff = riceProduct(noRevOffer(RICE_ID));
    let detailsOff = null;
    let threwOff = null;
    try {
        detailsOff = sandboxOff.renderPriceRegister(riceOff, makeEl('div'));
    } catch (error) { threwOff = error; }
    check('(b) store unavailable: the closure does not throw', threwOff, null);
    const formOff = detailsOff.children.find((c) => c.tagName === 'FORM');
    check('(b) store unavailable: the submit button starts disabled',
        formOff.children.find((c) => c.tagName === 'BUTTON').disabled, true);

    // --- (c) the supplier-tab path (verify button) still renders ---
    const sandbox2 = buildSandbox(src, true);
    const offer2 = noRevOffer(RICE_ID);
    const product2 = riceProduct(offer2);
    const verifyBtn = makeEl('button');
    verifyBtn.textContent = 'منبع فعلی را دیدم؛ قیمت تأیید است';
    verifyBtn.disabled = true;
    let details2 = null;
    let threw2 = null;
    try {
        details2 = sandbox2.workbenchPriceRegisterSection(product2, offer2, makeEl('div'), verifyBtn, sandbox2.storeOk);
    } catch (error) { threw2 = error; }
    check('(c) supplier-tab helper call does not throw', threw2, null);
    const form2 = details2.children.find((c) => c.tagName === 'FORM');
    check('(c) supplier-tab submit button is enabled when the store is available',
        form2.children.find((c) => c.tagName === 'BUTTON').disabled, false);
    await form2.listeners.submit[0]({ preventDefault() {} });
    check('(c) supplier-tab submit: the verify button flips to confirmed',
        verifyBtn.textContent, 'قیمت تأیید شد');

    // --- (d) a with-revision card is unchanged (closure returns null) ---
    const riceRev = riceProduct(withRevOffer(RICE_ID));
    check('(d) with-revision card: needsPriceRegistration finds no row',
        sandbox.workbenchNeedsPriceRegistration(riceRev), null);
    check('(d) with-revision card: the closure returns null (no form)',
        sandbox.renderPriceRegister(riceRev, makeEl('div')), null);

    // --- (e) NEGATIVE CONTROL: the 23a6589 base throws without the binding ---
    if (baseSrc) {
        const baseSandbox = buildSandbox(baseSrc, true);
        const baseRice = riceProduct(noRevOffer(RICE_ID));
        let baseThrew = null;
        try {
            baseSandbox.workbenchPriceRegisterSection(baseRice, baseRice.supplierPrices[0], makeEl('div'), null);
        } catch (error) { baseThrew = error; }
        check('(e) negative control: the base helper throws ReferenceError without contextStored',
            baseThrew instanceof ReferenceError, true);
        check('(e) negative control: the error names contextStored',
            String(baseThrew?.message || '').includes('contextStored'), true);
    }

    console.log(`\n${pass} PASS, ${fail} FAIL`);
    if (fail) process.exit(1);
    console.log('ALL PRICE-REGISTER CONTEXT-SCOPE CHECKS PASSED');
}

run().catch((error) => { console.error('HARNESS ERROR', error); process.exit(1); });
