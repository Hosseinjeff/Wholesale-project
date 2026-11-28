// Deferred card still needs its price registered (G1 follow-up, owner
// request 2026-11-26): Node harness. Run: node tests/deferred_price_register_button.cjs
// The hotfix fixed the refresh regression and the register form renders in
// the rice card's «پیام تأمین‌کننده» tab — but «ثبت قیمت از پیام» still
// does NOT appear on the card's first view. Root cause, verified in code:
// the rice card's preferred action is 'deferred'. workbenchPendingAction
// returns 'source_integrity' (the missing URL is an integrity issue), and
// workbenchActionKey turns it into 'deferred' because that step was set
// aside — dismissals are keyed by PRODUCT (workbenchDismissalKey uses
// product_id), so setting the source_integrity step aside on one card for a
// product sets it aside for every card of that product: the live rice card
// inherited the dismissal from its duplicates. The first-view nextLine then
// renders ONLY the deferred banner + restore button, because the G1 button
// logic lives in the `preferred!=='next'` branch, which never runs for a
// deferred card. The dismissal is the owner's deliberate action and is
// product-keyed by design — re-scoping it by card would orphan existing
// dismissals and change what "set aside" means — so the scoped fix is to
// show the register button in the deferred branch too, when the card
// carries a no-revision row (the button follows the row, not the queue,
// exactly as the G1 follow-up did for source_integrity). The form lives in
// the source tab, the only tab a deferred card has, so the button opens
// 'source_integrity'. The dismissal stands: the restore button stays.
// This harness executes the REAL first-view nextLine block (extracted from
// includes/owner-dashboard.js) against the REAL router with the REAL
// dismissal machinery — every earlier harness stubbed
// workbenchIsDismissed to () => false, which is why the deferred path was
// never exercised and this gap shipped. It asserts:
//   (a) the rice live state (badge awaiting-price, no-revision offer,
//       preferred 'deferred' + deferred banner) shows «ثبت قیمت از پیام»
//       on its first view, and clicking it activates the source tab;
//       the same fixture through the f60ff80 base does NOT show it
//       (negative control — the harness would have caught the gap);
//   (b) a genuinely deferred card WITHOUT a no-revision row shows no
//       register button and keeps the primary restore button (unchanged);
//   (c) the anti-hangover card (offer HAS a revision) shows no register
//       button (unchanged);
//   (d) a source_integrity card with a no-revision row that was NOT
//       dismissed still shows the button (G1 follow-up behaviour, unchanged);
//   (e) a deferred card with a no-revision row but no restore keys shows
//       the button plus the «کارهای کنارگذاشته‌شده» link;
//   (f) differential: every fixture renders identically to the f60ff80 base
//       except the deferred + no-revision cases, which gain the button (and
//       demote the restore button to plain); the deferred fix's own
//       workflows.js blob is byte-identical to f60ff80 (the fix is
//       dashboard-only) and its dashboard blob differs by exactly the
//       register button + one restore-class change — both compared as
//       historical blobs, since the tree has moved on (G10+G12, hold-gate);
//       the workflows.js diff vs the rebase base db80c25 is exactly the
//       render-merged-cases fix (3 removed, 15 added).
// The tab form itself is proven by tests/no_revision_price_entry_decoupled.cjs
// and tests/price_register_context_scope.cjs (re-run separately); here the
// button's target is asserted to be the source tab, where the form lives.
// No browser, no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

const BASE = 'f60ff80';
let baseWorkflowsSrc = null;
let baseDashboardSrc = null;
try {
    baseWorkflowsSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
    baseDashboardSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
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

// --- Workflows sandbox: the REAL router with the REAL dismissal machinery.
// Unlike every earlier harness, workbenchIsDismissed is NOT stubbed to
// false here — the dismissal index is a live Map the test populates with
// the exact key the real workbenchDismissalKey produces, mirroring the
// server-stored record.
const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];
const G1_FNS = ['workbenchNeedsPriceRegistration', 'workbenchHasUnchangedLinkCheck'];
const DISMISSAL_FNS = ['workbenchDismissalKey', 'workbenchCurrentDismissalKey',
    'workbenchIsDismissed', 'workbenchDismissalToken', 'workbenchNameKey',
    'workbenchSourceRecord', 'workbenchSourceRecords', 'workbenchRestoreKeys',
    'workbenchSetDismissals'];
function buildWorkflowsSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    for (const name of G1_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
    }
    // FIX-4: workbenchNextStep now calls workbenchHasChangedLinkCheck; it
    // exists only in files newer than the 4ac67ae base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    if (source.includes('function workbenchHasChangedLinkCheck(')) pieces.push(extractFunction(source, 'workbenchHasChangedLinkCheck'));
    for (const name of DISMISSAL_FNS) pieces.push(extractFunction(source, name));
    const code = `
let workbenchData = null;
let data = null;
const workbenchErrors = {};
let workbenchDismissals = [];
let workbenchDismissalIndex = new Map();
const config = { reviewStoreUrl: 'https://example.test', reviewStoreNonce: 'nonce' };
const workbenchQueue = 'next';
const workbenchStageModel = [];
const workbenchWooState = (product) => product._wooState || '';
const safeSourceLink = () => null;
${pieces.join('\n')}
return {
    setData(v) { workbenchData = v; data = v; },
    setDismissals(items) { workbenchSetDismissals(items); },
    dismissalKey: (p, q) => workbenchDismissalKey(p, q),
    workbenchActionKey, workbenchNextStep, workbenchReadyAction,
    workbenchHoldRoute, workbenchNeedsPriceRegistration, workbenchRestoreKeys,
    workbenchIsDismissed,
};`;
    return new Function(code)();
}

// --- Dashboard helpers sandbox: the real first-view decision helpers.
const DASHBOARD_FNS = ['next', 'published', 'priceBlocked', 'nextDeadEnd',
    'nextDeadEndPublishable', 'saleClosed', 'saleClosedReason', 'ready'];
function buildDashboardSandbox(source) {
    const pieces = DASHBOARD_FNS.map(name => extractFunction(source, name));
    pieces.push(extractConstStatement(source, 'actionLabels'));
    const code = `
let model = null;
let state = {};
${pieces.join('\n')}
return {
    setModel(m) { model = m; },
    setState(s) { state = s; },
    nextDeadEnd, nextDeadEndPublishable, saleClosed, saleClosedReason, ready, next, published, actionLabels,
};`;
    return new Function(code)();
}

// --- The REAL first-view nextLine block, extracted from dashboard.js and
// executed with stubbed DOM + the real router. This is the rendering itself,
// not a source check: the block is the code that decides which buttons the
// first view shows.
function buildNextLineRenderer(dashboardSource) {
    const startMarker = "const nextLine=el('div',null,'md-next-action');";
    const endMarker = 'if (nextLine.childNodes.length) card.append(nextLine);';
    const start = dashboardSource.indexOf(startMarker);
    if (start < 0) throw new Error('nextLine block start not found');
    const end = dashboardSource.indexOf(endMarker, start) + endMarker.length;
    if (end < endMarker.length) throw new Error('nextLine block end not found');
    const block = dashboardSource.slice(start, end);
    const fn = new Function('product', 'preferred', 'model', 'state', 'hooks',
        'navigate', 'loading', 'activate', 'recoveryAction', 'nextDeadEnd',
        'nextDeadEndPublishable', 'saleClosed', 'saleClosedReason', 'ready',
        'actionLabels', 'el', 'button', 'card', block + '\nreturn nextLine;');
    return fn;
}

// --- Minimal DOM stub ---
function makeEl(tag, text, className) {
    const el = {
        tagName: String(tag).toUpperCase(),
        children: [],
        get childNodes() { return this.children; },
        className: className || '',
        textContent: text || '',
        disabled: false,
        value: '',
        name: '',
        dataset: {},
        listeners: {},
        classList: { add() {}, remove() {}, contains() { return false; } },
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
        appendChild(c) { this.children.push(c); return c; },
        append(...items) { items.forEach((item) => this.children.push(item)); return this; },
        prepend(...items) { this.children.unshift(...items); return this; },
        replaceChildren(...items) { this.children = [...items]; return this; },
        setAttribute() {},
    };
    return el;
}
function makeButton(label, onClick, className) {
    const b = makeEl('button', label, className);
    b.onClick = onClick;
    return b;
}
function describeNextLine(nextLine) {
    return nextLine.children.map(c => ({
        tag: c.tagName,
        text: c.textContent,
        className: c.className,
        onClick: typeof c.onClick === 'function' ? 'fn' : undefined,
    }));
}

const wf = buildWorkflowsSandbox(src);
const wfBase = baseWorkflowsSrc ? buildWorkflowsSandbox(baseWorkflowsSrc) : null;
const dash = buildDashboardSandbox(dashboardSrc);
const renderNextLine = buildNextLineRenderer(dashboardSrc);
const renderNextLineBase = baseDashboardSrc ? buildNextLineRenderer(baseDashboardSrc) : null;

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

// --- Fixtures (live-shaped) ---
function baseProduct(overrides) {
    return {
        product_id: 'product-rice-1',
        key: 'product-rice-1',
        name: 'برنج طارم هاشمی مازندارن',
        channel: 'bonakdarjavan',
        holds: [],
        ai: [],
        references: [],
        conflicts: [],
        supplierPrices: [],
        status: {
            status: 'imported',
            sale_offer_blocker: 'supplier_price',
            publication_needed: false,
            update_needed: false,
            regular_price_missing: false,
            regular_price_state: 'current',
            latest_offer_id: 'offer-rice-1',
            source_integrity_issues: [{ issue: 'missing_source_url' }],
            source_post_url: '',
            published: false,
        },
        ...overrides,
    };
}
function noRevisionOffer(overrides) {
    return {
        offer_id: 'offer-rice-1',
        product_id: 'product-rice-1',
        name: 'برنج طارم هاشمی مازندارن',
        channel: 'bonakdarjavan',
        acknowledged: false,
        source_revision: '',
        sale_price: '',
        source_post_url: 'https://t.me/bonakdarjavan/1515',
        source_timestamp: '2026-10-17T00:00:00Z',
        verified_at: '',
        ...overrides,
    };
}
function withRevisionOffer(overrides) {
    return noRevisionOffer({
        source_revision: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        sale_price: 450000,
        acknowledged: false,
        ...overrides,
    });
}

// The rice live state: badge «در انتظار قیمت تأمین‌کننده» (blocker
// supplier_price), a no-revision offer, source_integrity issues (missing
// URL), and the source_integrity step set aside — the dismissal record the
// server stores for the product, computed here with the REAL
// workbenchDismissalKey so the key is exactly what the server holds.
function riceProduct() {
    const product = baseProduct({ supplierPrices: [noRevisionOffer()] });
    return product;
}
function dismissSourceIntegrity(wfSandbox, product) {
    const key = wfSandbox.dismissalKey(product, 'source_integrity');
    if (!key) throw new Error('no dismissal key for the rice fixture');
    wfSandbox.setDismissals([{ task_key: key, queue: 'source_integrity' }]);
    return key;
}
function clearDismissals(wfSandbox) {
    wfSandbox.setDismissals([]);
}

const emptyData = { autopilot: { holds: [] } };

// Wire a fixture through the real router into the dashboard model stub,
// then render the real nextLine block. Returns {preferred, nextLine,
// activated} where `activated` records every activate() call (the register
// button's click target).
function renderFixture(wfSandbox, renderer, product, restoreKeysOverride) {
    wfSandbox.setData(emptyData);
    const preferred = wfSandbox.workbenchActionKey(product);
    const activated = [];
    const model = {
        primaryAction: (p) => wfSandbox.workbenchActionKey(p),
        readyAction: (p) => wfSandbox.workbenchReadyAction(p),
        autopilotHold: () => null,
        restoreKeys: restoreKeysOverride || ((p) => wfSandbox.workbenchRestoreKeys(p)),
        nextStep: (p, k) => wfSandbox.workbenchNextStep(p, k),
        needsPriceRegistration: (p) => wfSandbox.workbenchNeedsPriceRegistration(p),
        holdRoute: (p) => wfSandbox.workbenchHoldRoute(p),
        releaseAutopilotHold: async () => {},
        requestPublication: async () => {},
    };
    dash.setModel(model);
    dash.setState({ active: null, selected: new Set() });
    const state = { active: null, selected: new Set() };
    const hooks = { restore: async () => {} };
    const card = makeEl('article', null, 'md-product-card');
    const nextLine = renderer(product, preferred, model, state, hooks,
        () => {}, () => {}, (key) => { activated.push(key); },
        () => makeEl('button', 'بازبینی مشخصات', 'md-button'),
        dash.nextDeadEnd, dash.nextDeadEndPublishable, dash.saleClosed,
        dash.saleClosedReason, dash.ready, dash.actionLabels,
        makeEl, makeButton, card);
    return { preferred, nextLine, activated, model };
}
function buttonsOf(nextLine) {
    return nextLine.children.filter(c => c.tagName === 'BUTTON')
        .map(c => ({ label: c.textContent, className: c.className }));
}
function textsOf(nextLine) {
    // Recursive: the non-deferred branch nests the text inside a `div`
    // (reason), while the deferred branch appends the <p> directly.
    const out = [];
    (function walk(node) {
        for (const c of node.children || []) {
            if (c.tagName === 'P') out.push(c.textContent);
            walk(c);
        }
    })(nextLine);
    return out;
}

// --- (a) The rice live state: deferred + no-revision row ---
const rice = riceProduct();
const riceDismissalKey = dismissSourceIntegrity(wf, rice);
check('rice: dismissal key is product-keyed (source_integrity|product:…)',
    riceDismissalKey.startsWith('source_integrity|product'), true);
const riceRun = renderFixture(wf, renderNextLine, rice);
check('rice: preferred action is deferred (real router + real dismissals)',
    riceRun.preferred, 'deferred');
check('rice: first view shows the deferred banner text',
    textsOf(riceRun.nextLine), ['بررسی این کالا کنار گذاشته شده است. برای ادامه آن را بازگردانید.']);
check('rice: first view shows «ثبت قیمت از پیام» (primary) + restore (plain)',
    buttonsOf(riceRun.nextLine), [
        { label: 'ثبت قیمت از پیام', className: 'md-button md-primary' },
        { label: 'بازگرداندن همین بررسی', className: 'md-button' },
    ]);
// Clicking the register button opens the source tab, where the form lives.
const riceRegisterButton = riceRun.nextLine.children.find(c => c.tagName === 'BUTTON' && c.textContent === 'ثبت قیمت از پیام');
riceRegisterButton.onClick();
check('rice: register button activates the source tab', riceRun.activated, ['source_integrity']);

// Negative control: the same fixture through the f60ff80 base must NOT
// show the button — this harness would have caught the shipped gap.
if (renderNextLineBase && wfBase) {
    const riceBase = riceProduct();
    dismissSourceIntegrity(wfBase, riceBase);
    const riceBaseRun = renderFixture(wfBase, renderNextLineBase, riceBase);
    check('NEGATIVE CONTROL (base f60ff80): rice shows NO register button',
        buttonsOf(riceBaseRun.nextLine), [
            { label: 'بازگرداندن همین بررسی', className: 'md-button md-primary' },
        ]);
    check('NEGATIVE CONTROL (base f60ff80): rice preferred is still deferred',
        riceBaseRun.preferred, 'deferred');
} else {
    console.log('SKIP negative-control differential (base unreadable)');
}

// --- (b) Genuinely deferred card WITHOUT a no-revision row ---
clearDismissals(wf);
const deferredNoRow = baseProduct({ supplierPrices: [withRevisionOffer({ acknowledged: true })] });
dismissSourceIntegrity(wf, deferredNoRow);
const deferredNoRowRun = renderFixture(wf, renderNextLine, deferredNoRow);
check('deferred without a no-revision row: preferred is deferred',
    deferredNoRowRun.preferred, 'deferred');
check('deferred without a no-revision row: NO register button, restore stays primary',
    buttonsOf(deferredNoRowRun.nextLine), [
        { label: 'بازگرداندن همین بررسی', className: 'md-button md-primary' },
    ]);
if (renderNextLineBase && wfBase) {
    const baseRun = renderFixture(wfBase, renderNextLineBase, (() => {
        const p = baseProduct({ supplierPrices: [withRevisionOffer({ acknowledged: true })] });
        dismissSourceIntegrity(wfBase, p);
        return p;
    })());
    check('deferred without a row: identical to base',
        describeNextLine(baseRun.nextLine), describeNextLine(deferredNoRowRun.nextLine));
}

// --- (c) Anti-hangover card: offer HAS a revision, no dismissal ---
clearDismissals(wf);
const antihangover = baseProduct({
    name: 'آنتی هنگ اور لایف',
    supplierPrices: [withRevisionOffer()],
    status: {
        status: 'imported', sale_offer_blocker: 'supplier_price',
        publication_needed: false, update_needed: false,
        regular_price_missing: false, regular_price_state: 'current',
        latest_offer_id: 'offer-rice-1', source_integrity_issues: [],
        source_post_url: 'https://t.me/bonakdarjavan/1515', published: false,
    },
});
const antiRun = renderFixture(wf, renderNextLine, antihangover);
check('anti-hangover (with revision): preferred is supplier_prices',
    antiRun.preferred, 'supplier_prices');
check('anti-hangover (with revision): NO register button',
    buttonsOf(antiRun.nextLine).some(b => b.label === 'ثبت قیمت از پیام'), false);
check('anti-hangover (with revision): ordinary verify button',
    buttonsOf(antiRun.nextLine), [{ label: 'تأیید قیمت', className: 'md-button md-primary' }]);

// --- (d) source_integrity card with a no-revision row, NOT dismissed ---
clearDismissals(wf);
const sourceCard = riceProduct();
const sourceRun = renderFixture(wf, renderNextLine, sourceCard);
check('source_integrity no-revision, not dismissed: preferred is source_integrity',
    sourceRun.preferred, 'source_integrity');
check('source_integrity no-revision, not dismissed: register button present (G1 follow-up)',
    buttonsOf(sourceRun.nextLine), [
        { label: 'ثبت قیمت از پیام', className: 'md-button md-primary' },
    ]);
check('source_integrity no-revision, not dismissed: G1 next-step text',
    textsOf(sourceRun.nextLine),
    ['نسخهٔ معتبر قیمت برای این ردیف ثبت نشده است؛ قیمت را از پیام تأمین‌کننده ثبت کنید.']);

// --- (e) Deferred + no-revision row, but no restore keys ---
clearDismissals(wf);
const riceNoKeys = riceProduct();
dismissSourceIntegrity(wf, riceNoKeys);
const riceNoKeysRun = renderFixture(wf, renderNextLine, riceNoKeys, () => []);
check('deferred + no-revision, no restore keys: register button + system link',
    buttonsOf(riceNoKeysRun.nextLine), [
        { label: 'ثبت قیمت از پیام', className: 'md-button md-primary' },
        { label: 'کارهای کنارگذاشته‌شده', className: 'md-link' },
    ]);

// --- (f) Differential: the deferred fix is dashboard-only ---
// The check compares the deferred commit's own workflows.js blob with
// its base f60ff80, not the current file: the tree has moved on since
// (G10+G12 changed workflows.js with the G12 copy; the hold-gate changed
// dashboard.js), so the current file no longer equals f60ff80 through no
// fault of this fix.
if (baseWorkflowsSrc) {
    let deferredWorkflowsSrc = null;
    try {
        deferredWorkflowsSrc = execSync(`git show e18de2b:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
    } catch (error) {
        console.warn('WARNING: could not read the e18de2b workflows.js blob:', error.message);
    }
    if (deferredWorkflowsSrc) {
        check('the deferred fix left workflows.js byte-identical to f60ff80 (fix is dashboard-only)',
            deferredWorkflowsSrc, baseWorkflowsSrc);
    }
    // The branch also carries the render-merged-cases fix in workflows.js:
    // the ai candidate-deferred panel renders the case immediately instead
    // of showing only a loading line until the candidate lookup settles.
    // The diff vs the rebase base db80c25 (the deployed HEAD) must be
    // exactly that change (3 removed, 15 added) plus the
    // ai-confirm-section-visible fix (2026-11-26: 1 removed, 9 added —
    // the line that hid the confirmation section until the supplier price
    // was verified, replaced by an explanatory comment; the section is
    // now always visible, gated by the disabled confirm buttons) plus the
    // verification-section fix (2026-11-26: 12 removed, 36 added — the
    // «قیمت و موجودی امروز» block is extracted into the
    // buildSourceVerificationSection helper and rendered in BOTH the
    // reference-selection and confirmation paths; the checkbox local is
    // renamed currentPriceVerified -> checkbox inside the helper and the
    // append is split across the two call sites) plus the
    // source-post-status fix (2026-11-26: 1 removed, 41 added — the
    // renderCase call site passes product.link_check through
    // options.parentLinkCheck, and aiCaseCard renders the link-check
    // status for the source post in its source section, before the
    // reference-selection early return so it shows in BOTH ai paths) plus
    // the FIX-4 every-card fix (2026-11-26: 9 removed, 106 added — the
    // link-changed adopt form reusing the G1 register helper with an
    // optional options parameter, the uncheckable follow-up button, the
    // old-price verify guard, the unverified first-view line with the
    // deadline fact, and the no-picture / underlying-hold copy on the
    // autopilot card).
    // The G12 copy from G10+G12 is already in db80c25, so it is not part of this diff.
    let rebaseBaseWorkflowsSrc = null;
    try {
        rebaseBaseWorkflowsSrc = execSync(`git show db80c25:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
    } catch (error) {
        console.warn('WARNING: could not read the db80c25 workflows.js blob:', error.message);
    }
    if (rebaseBaseWorkflowsSrc) {
        const wfBaseLines = rebaseBaseWorkflowsSrc.split('\n');
        const wfNewLines = src.split('\n');
        const wfRemoved = wfBaseLines.filter(l => !wfNewLines.includes(l));
        const wfAdded = wfNewLines.filter(l => !wfBaseLines.includes(l));
        // Slice 3 (persistent matching) rewrote the three workbenchNextStep
        // one-liners, added the referenceApprovalStep block, the
        // marketPriceChangeLine helper + its quoteRow call, and the
        // reference_identity_changed label — all covered by
        // tests/match_feed_digest_card.cjs. Filter those lines out here
        // (order-free set difference) so the frozen expectations below
        // still guard every other line exactly.
        const SLICE3_WF_REMOVED = [
            "        if (key === 'references') return 'مرجع درست و مبنای قیمت را انتخاب کنید.';",
            "        if (key === 'status') return product.status?.review_reason",
            '            ? aiReviewReason(product.status.review_reason)',
            "            : 'مشخصات و قیمت‌های فعلی را بازبینی کنید تا درخواست انتشار بررسی شود.';",
            "        return 'برای این کالا اقدام بازی ثبت نشده است.';",
        ];
        const SLICE3_WF_ADDED = [
            "            reference_identity_changed:'هویت مرجع تغییر کرده',",
            '        // Slice 3 (persistent matching): the catalog change the reconcile',
            '        // writes to the sheet (Reference Approval State / Price Change',
            "        // Percent) is the card's notice channel — the state arrives on",
            '        // product.status, and the first-view line names the change and the',
            '        // path forward. outside_margin / reference_missing /',
            "        // price_unavailable already set update_needed server-side, so the",
            "        // card routes to 'status'; reference_identity_changed does NOT (it",
            "        // is not in the server's review-due list), so the same line also",
            "        // fires for the 'next' key — otherwise that card would show no",
            '        // action at all. The options are the same in every state:',
            '        // re-acknowledge the new reference, pick another reference, or',
            '        // proceed without one. Nested here (not top-level) so the routing',
            '        // sandboxes that extract workbenchNextStep keep working unchanged.',
            '        const referenceApprovalStep = () => {',
            "            const state = String(product.status?.reference_approval_state || '');",
            "            if (!['outside_margin','reference_missing','price_unavailable','reference_identity_changed'].includes(state)) return '';",
            "            if (state === 'outside_margin') {",
            '                const pct = Number(product.status?.reference_price_change_percent);',
            '                const change = Number.isFinite(pct) && pct !== 0',
            "                    ? ` (${Math.abs(pct).toLocaleString('fa-IR', {maximumFractionDigits: 2})}٪)` : '';",
            "                return `قیمت مرجع تغییر کرده است${change}؛ قیمت تازه را تأیید کنید یا مرجع دیگری انتخاب کنید.`;",
            "            if (state === 'reference_missing') return 'مرجع این کالا در فهرست نیست؛ مرجع دیگری انتخاب کنید یا بدون مرجع ادامه دهید.';",
            "            if (state === 'price_unavailable') return 'قیمت مرجع در دسترس نیست؛ مرجع دیگری انتخاب کنید یا بدون مرجع ادامه دهید.';",
            "            return 'مرجع این کالا عوض شده است؛ مرجع تازه را بررسی و تأیید کنید یا مرجع دیگری انتخاب کنید.';",
            "        if (key === 'references') return referenceApprovalStep() || 'مرجع درست و مبنای قیمت را انتخاب کنید.';",
            "        if (key === 'status') {",
            '            const referenceStep = referenceApprovalStep();',
            '            if (referenceStep) return referenceStep;',
            '            return product.status?.review_reason',
            '                ? aiReviewReason(product.status.review_reason)',
            "                : 'مشخصات و قیمت‌های فعلی را بازبینی کنید تا درخواست انتشار بررسی شود.';",
            "        return referenceApprovalStep() || 'برای این کالا اقدام بازی ثبت نشده است.';",
            '    // Slice 3 (persistent matching): one line under the selected market',
            "    // source, from the latest market_source_events 'changed' row the API",
            "    // returns as result.price_change. '' when there is no change to show",
            "    // (no row, missing prices, or an unchanged price) — the tab renders",
            '    // nothing instead of a stale line. No sheet write for market changes.',
            '    function marketPriceChangeLine(change) {',
            '        const oldPrice = Number(change?.old_price);',
            '        const newPrice = Number(change?.regular_price);',
            '        if (!change || !Number.isFinite(oldPrice) || !Number.isFinite(newPrice)',
            '            || oldPrice <= 0 || newPrice <= 0 || oldPrice === newPrice) return \'\';',
            '        const date = formatSourceTimestamp(change.occurred_at);',
            "        return `قیمت این فروشنده از ${amount(oldPrice)} به ${amount(newPrice)} تغییر کرد${date ? ` · ${date}` : ''}`;",
            '            if (selected) {',
            '                const changeLine = marketPriceChangeLine(result.price_change);',
            "                if (changeLine) row.append(node('small', changeLine, 'molavi-market-price-change'));",
        ];
        const wfRemovedChecked = wfRemoved.filter(l => !SLICE3_WF_REMOVED.includes(l));
        const wfAddedChecked = wfAdded.filter(l => !SLICE3_WF_ADDED.includes(l));
        check('workflows.js diff vs db80c25: exactly the render-merged-cases + ai-confirm-section-visible + verification-section + source-post-status + FIX-4 removals',
            wfRemovedChecked, [
                "    function workbenchPriceRegisterSection(product, offer, container, verify, reviewStoreAvailable) {",
                "        register.appendChild(node('summary', 'ثبت قیمت از پیام'));",
                "        const storedPrice = Number(offer.sale_price);",
                "        registerForm.appendChild(node('p', 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.', 'molavi-price-register-hint'));",
                "        const registerSubmit = node('button', 'ثبت قیمت و تأیید', 'button button-primary');",
                "            const registerNote = url !== '' ? `مالک قیمت را از پیام ثبت کرد؛ منبع: ${url}` : 'مالک قیمت را از پیام ثبت کرد.';",
                "            unverified:'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد.'})[product.status?.regular_price_state]||'قیمت عادی را بررسی کنید.';",
                "                    parentName:product.name, parentChannel:product.channel, parentImageId:product.ai[0]?.image_file_id",
                "                    content.replaceChildren(node('p','در حال بررسی مرجع این پیشنهاد…'));",
                "                        content.replaceChildren(renderCase());",
                "                        content.replaceChildren(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry,renderCase());",
                "                verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices;",
                "                if (!offer.acknowledged && sourceRevision) verify.addEventListener('click', async () => {",
                "        if (sourceVerificationRequired) {",
                "            currentPriceVerified = document.createElement('input');",
                "            currentPriceVerified.type = 'checkbox';",
                "            currentPriceVerified.checked = sourceVerificationItems.length > 0",
                "            checkLabel.append(currentPriceVerified, document.createTextNode(stockNeedsCurrentCheck",
                "            verification.append(checkLabel);card.appendChild(verification);",
                "            confirmation.hidden=sourceVerificationRequired&&!currentPriceVerified?.checked;",
                "        if (currentPriceVerified) currentPriceVerified.addEventListener('change', () => {",
                "            sourceVerificationItems.forEach(row => {",
                "                const key = currentPriceVerificationKey(row);",
                "                if (currentPriceVerified.checked) verifiedCurrentPriceCases.add(key);",
                "                else verifiedCurrentPriceCases.delete(key);",
                "            refreshConfirmationGates();"
            ]);
        check('workflows.js diff vs db80c25: exactly the render-merged-cases + ai-confirm-section-visible + verification-section + detected-vs-confirmed-comparison + source-post-status + FIX-4 additions',
            wfAddedChecked, [
                "    // `options` (FIX-4, link-changed adoption) reuses the same form for",
                "    // a row whose link check found a NEW price: the endpoint writes any",
                "    // price to the sheet and verifies the new revision — it does not",
                "    // require an empty revision — so the owner adopts the live price",
                "    // through the same action. `prefillPrice` (the live price), `title`,",
                "    // `submitLabel`, `hint` and `note` override the G1 wording; every",
                "    // default preserves the G1 form exactly.",
                "    function workbenchPriceRegisterSection(product, offer, container, verify, reviewStoreAvailable, options = {}) {",
                "        register.appendChild(node('summary', options.title || 'ثبت قیمت از پیام'));",
                "        const storedPrice = options.prefillPrice !== undefined",
                "            ? Number(options.prefillPrice) : Number(offer.sale_price);",
                "        registerForm.appendChild(node('p', options.hint || 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.', 'molavi-price-register-hint'));",
                "        const registerSubmit = node('button', options.submitLabel || 'ثبت قیمت و تأیید', 'button button-primary');",
                "            const registerNote = (options.note || 'مالک قیمت را از پیام ثبت کرد')",
                "                + (url !== '' ? `؛ منبع: ${url}` : '.');",
                "    // FIX-4 — a supplier-price row whose source link was re-read and the",
                "    // live post shows a NEW price: the owner decision is adopt the new",
                "    // price or set the check aside — verifying the stored (old) price",
                "    // would write false evidence. Returns the row, or null. The live",
                "    // price must be a positive number, or there is nothing to adopt.",
                "    function workbenchHasChangedLinkCheck(product) {",
                "            && offer.link_check && offer.link_check.outcome === 'changed'",
                "            && Number.isFinite(Number(offer.link_check.live_price))",
                "            && Number(offer.link_check.live_price) > 0) || null;",
                "    // FIX-4 — a supplier-price row whose source link could not be read",
                "    // (channel gone, price not found in the post, …): the card must offer",
                "    // a supplier-follow-up path instead of leaving only the bare notice.",
                "    // Returns the row, or null.",
                "    function workbenchHasUncheckableLinkCheck(product) {",
                "            && offer.link_check && offer.link_check.outcome === 'uncheckable') || null;",
                "            // FIX-4: the link shows a NEW price — verifying the stored",
                "            // price would be wrong. The decision is adopting the new",
                "            // price or setting the check aside; the tab carries the form.",
                "            if (workbenchHasChangedLinkCheck(product)) return 'قیمت در لینک منبع تغییر کرده است؛ قیمت تازه را بپذیرید یا بررسی را کنار بگذارید.';",
                "            // FIX-4: 'unverified' means a price is on file but no",
                "            // verification deadline is — no direct stamp, no reference",
                "            // deadline, no sync deadline (sale-offers.php). Say so, and",
                "            // name the options: pick a quote or confirm manually.",
                "            unverified:'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد؛ مهلت تأییدی برای آن ثبت نشده است. یک نقل‌قول را انتخاب کنید یا قیمت را دستی تأیید کنید.'})[product.status?.regular_price_state]||'قیمت عادی را بررسی کنید.';",
                "                    parentName:product.name, parentChannel:product.channel, parentImageId:product.ai[0]?.image_file_id,",
                "                    parentLinkCheck:product.link_check || null",
                "                // Render the case immediately: the owner's confirm/dismiss",
                "                // decision must never wait behind the reference-candidate",
                "                // lookup. Candidates enrich the reference area in the",
                "                // background when they arrive; a slow or hung lookup used",
                "                // to leave only «در حال بررسی مرجع این پیشنهاد…» and no",
                "                // case at all (beer message 7218).",
                "                content.replaceChildren(renderCase());",
                "                let candidateNotice=null;",
                "                        if (content.isConnected) content.replaceChildren(renderCase());",
                "                        // The case is already visible; surface a retry for",
                "                        // the reference suggestions without hiding it.",
                "                        if (!content.isConnected || candidateNotice) return;",
                "                        candidateNotice=node('div',null,'molavi-ai-candidate-error');",
                "                        candidateNotice.append(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry);",
                "                        content.prepend(candidateNotice);",
                "                // FIX-4 — uncheckable: the notice alone leaves the owner",
                "                // with nothing to do. Offer the supplier-follow-up path",
                "                // (the source review tool, already used by the «یافتن پیام",
                "                // تأمین‌کننده» button above) and say the manual verify",
                "                // below still works once the post has been read.",
                "                if (linkCheck && linkCheck.outcome === 'uncheckable' && !offer.acknowledged) {",
                "                    const followUp = node('div', null, 'molavi-card-source-tools');",
                "                    const followUpButton = node('button', 'پیگیری پیام تأمین‌کننده', 'button button-secondary');",
                "                    followUpButton.type = 'button';",
                "                    followUpButton.addEventListener('click', () => openSourceReviewTool(product));",
                "                    followUp.appendChild(followUpButton);",
                "                    section.appendChild(followUp);",
                "                    section.appendChild(node('p', 'پیام منبع را بخوانید؛ اگر قیمت در پیام درست است، آن را با دکمهٔ تأیید زیر بررسی کنید. اگر پیام تازه لازم است، از تأمین‌کننده بخواهید.'));",
                "                // FIX-4 — a changed link check disables the old-price",
                "                // verify: confirming the stored price while the live post",
                "                // shows a new one would write false evidence. The adopt",
                "                // form below is the path instead.",
                "                const linkChanged = linkCheck && linkCheck.outcome === 'changed';",
                "                verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices || linkChanged;",
                "                // FIX-4 — link-changed adoption (groups 5+6): the live",
                "                // price is prefilled; submitting writes it to the sheet",
                "                // and verifies the new revision through the same",
                "                // register_price_from_message action the G1 form uses.",
                "                // No new endpoint, no silent adoption — the owner clicks.",
                "                // If the live price is wrong, the owner sets the check",
                "                // aside with «کنارگذاشتن این کار» at the bottom of the tab.",
                "                if (!offer.acknowledged && linkChanged",
                "                        && Number.isFinite(Number(linkCheck.live_price)) && Number(linkCheck.live_price) > 0) {",
                "                    section.appendChild(node('p', 'قیمت ثبت‌شده دیگر جاری نیست؛ برای تأیید، قیمت تازهٔ لینک را بپذیرید. اگر قیمت تازه اشتباه است، این بررسی را از پایین برگه کنار بگذارید.'));",
                "                    section.appendChild(workbenchPriceRegisterSection(product, offer, section, verify, reviewStoreAvailable, {",
                "                        title: 'استفاده از قیمت تازهٔ لینک',",
                "                        prefillPrice: Number(linkCheck.live_price),",
                "                        submitLabel: 'پذیرفتن قیمت تازه و تأیید',",
                "                        hint: 'قیمت تازه از لینک اصلی خوانده شده است؛ پیش از پذیرفتن، پیام منبع را باز کنید و بررسی کنید.',",
                "                        note: 'مالک قیمت تازهٔ لینک را پذیرفت'",
                "                    }));",
                "                if (!offer.acknowledged && sourceRevision && !linkChanged) verify.addEventListener('click', async () => {",
                "        // FIX-4 — where a picture comes from (group 9): the hold reason",
                "        // says the picture is missing or broken, but never where one",
                "        // comes from. A market quote's photo can become the main image",
                "        // (the market tab's «استفاده به‌عنوان تصویر اصلی»), or a fresh",
                "        // supplier message can carry one. Copy only; the picture itself",
                "        // still has to come from the supplier or the market. Returns the",
                "        // line, or ''.",
                "        const autopilotImageLine = summary => {",
                "            if (!/تصویر/.test(`${summary.hold_reason || ''} ${summary.reason || ''}`)) return '';",
                "            return 'بدون تصویر منتشر نمی‌شود. تصویر را می‌توانید از نقل‌قول بازار بگیرید («استفاده به‌عنوان تصویر اصلی» در برگهٔ قیمت عادی) یا پیام تازهٔ تأمین‌کننده با عکس بخواهید.';",
                "        // FIX-4 — the generic admission hold (group 7b): «کالا در توقف",
                "        // است.» is arm 10 — another gate's hold (or a verdict flag) is",
                "        // the real block. Name it from the holds already on the card, so",
                "        // the owner sees the cause instead of a bare \"in hold\". Returns",
                "        // the line, or '' when there is no other hold for the product.",
                "        const autopilotUnderlyingHoldLine = (summary, holds, productId) => {",
                "            if (!summary || summary.hold_reason !== 'کالا در توقف است.') return '';",
                "            const underlying = (holds || []).filter(hold =>",
                "                String(hold.product_id) === String(productId) && hold.gate !== 'admission');",
                "            if (!underlying.length) return '';",
                "            return `توقف زیرین: ${underlying.map(hold =>",
                "                `${autopilotGateLabels[hold.gate] || hold.gate} — ${hold.reason || 'توقف ثبت شده است.'}`).join(' · ')}`;",
                "            // FIX-4: the picture where-from line (group 9) and the",
                "            // underlying-hold line for the generic «کالا در توقف است.»",
                "            // hold (group 7b). Both are copy from data already on the",
                "            // card; neither adds an action or a decision.",
                "            const imageLine = autopilotImageLine(summary);",
                "            if (imageLine) section.appendChild(node('p', imageLine, 'molavi-autopilot-state-reason'));",
                "            const underlyingLine = autopilotUnderlyingHoldLine(summary, holds, product.product_id);",
                "            if (underlyingLine) section.appendChild(node('p', underlyingLine, 'molavi-autopilot-state-reason'));",
                "        // Detected-vs-confirmed comparison (beer message 7218): the",
                "        // re-extract detects a new supplier price; the owner needs the",
                "        // currently confirmed Sale Price next to it. The confirmed price",
                "        // is carried on the case server-side (product record lookup, not",
                "        // extraction content). Shown only when both are positive and",
                "        // differ. The price facts are built before the",
                "        // reference-selection early return, so the line renders in BOTH",
                "        // ai paths.",
                "        const confirmedSalePrice = normalizedAiInteger(item.confirmed_sale_price);",
                "        if (confirmedSalePrice && visibleSupplierPrice && confirmedSalePrice !== visibleSupplierPrice) {",
                "            priceFacts.push(['قیمت شناسایی‌شده در برابر تأییدشده', `${amount(visibleSupplierPrice)} در برابر ${amount(confirmedSalePrice)}`]);",
                "        // Link-check status for the source post (cappuccino,",
                "        // t.me/bonakdarjavan/1337): the card must say whether the",
                "        // original Telegram post still exists. A deleted post used to",
                "        // be invisible — the stored copy and link stayed on the card",
                "        // with only a staleness warning. The status rides on the",
                "        // product payload (product.link_check, attached server-side",
                "        // for the current source revision) and is read here from",
                "        // options.parentLinkCheck. Only shown for the source this",
                "        // section displays: the check's URL must match the case's",
                "        // source URL (scheme-insensitive — the case carries it",
                "        // schemeless, the check row carries the full URL). Built",
                "        // before the reference-selection early return, so it renders",
                "        // in BOTH ai paths. Unchanged/changed/uncheckable reuse the",
                "        // supplier-prices view's exact lines; the deleted-post line",
                "        // and the never-checked line are new wording (flagged).",
                "        if (sourcePostUrl) {",
                "            const normalizeSourceUrl = value => String(value || '').trim()",
                "                .replace(/^https?:\\/\\//i, '').replace(/^www\\./i, '').replace(/\\/+$/, '');",
                "            const parentLinkCheck = options.parentLinkCheck || null;",
                "            const linkCheckForSource = parentLinkCheck && parentLinkCheck.url",
                "                && normalizeSourceUrl(parentLinkCheck.url) === normalizeSourceUrl(sourcePostUrl)",
                "                ? parentLinkCheck : null;",
                "            if (linkCheckForSource && linkCheckForSource.outcome) {",
                "                const checkedAt = linkCheckForSource.checked_at",
                "                    ? formatSourceTimestamp(new Date(linkCheckForSource.checked_at * 1000).toISOString()) : '';",
                "                if (linkCheckForSource.outcome === 'uncheckable' && linkCheckForSource.reason_code === 'post_not_found') {",
                "                    card.appendChild(notice(`پست منبع حذف شده است${checkedAt ? ` (بررسی لینک: ${checkedAt})` : ''}. پیام تازه را به ربات بفرستید؛ وقتی استخراج آماده شد، همین کارت به‌روز می‌شود.`, 'warning'));",
                "                } else if (linkCheckForSource.outcome === 'uncheckable') {",
                "                    card.appendChild(notice(`لینک منبع قابل بررسی نیست: ${linkCheckForSource.reason_label || linkCheckForSource.reason_code || 'نامشخص'}.`, 'warning'));",
                "                } else if (linkCheckForSource.outcome === 'unchanged') {",
                "                    card.appendChild(notice(`لینک منبع بررسی شد؛ قیمت بدون تغییر است (${linkCheckForSource.reason_label || ''} · ${checkedAt || 'زمان ثبت نشده'} · مرجع: ${linkCheckForSource.check_ref || '—'}). تأیید شما با تأمین‌کننده است که قیمت را تازه می‌کند.`, 'info'));",
                "                } else if (linkCheckForSource.outcome === 'changed') {",
                "                    card.appendChild(notice(`قیمت در لینک منبع تغییر کرده است: ثبت‌شده ${amount(visibleSupplierPrice)}، تازه ${amount(linkCheckForSource.live_price)} — قیمت تازه جایگزین نشده است و کالا همچنان متوقف است.`, 'warning'));",
                "                card.appendChild(notice('پیام منبع هنوز از لینک اصلی‌اش خوانده نشده است.', 'info'));",
                "        // The price/stock check is a precondition for confirming, not an",
                "        // alternative to the reference step, so it is built here — before",
                "        // the reference-selection early return below — and rendered in",
                "        // BOTH paths. The candidates fetch re-renders the card into the",
                "        // reference branch, which used to skip this block entirely: the",
                "        // «قیمت و موجودی امروز» section (and the owner's checkbox state)",
                "        // vanished until a full reload, then vanished again as soon as",
                "        // the fetch completed. (Beer message 7218.) The checkbox only",
                "        // records the check in the module-level verifiedCurrentPriceCases",
                "        // set; the confirm gate itself — disabled buttons until it is",
                "        // ticked, plus the server-side source_current_verified check —",
                "        // is unchanged.",
                "        const buildSourceVerificationSection = (onVerifiedChange) => {",
                "            if (!sourceVerificationRequired) return null;",
                "            checkbox.checked = sourceVerificationItems.length > 0",
                "            checkLabel.append(checkbox, document.createTextNode(stockNeedsCurrentCheck",
                "            verification.append(checkLabel);",
                "            checkbox.addEventListener('change', () => {",
                "                sourceVerificationItems.forEach(row => {",
                "                    const key = currentPriceVerificationKey(row);",
                "                    if (checkbox.checked) verifiedCurrentPriceCases.add(key);",
                "                    else verifiedCurrentPriceCases.delete(key);",
                "                if (typeof onVerifiedChange === 'function') onVerifiedChange();",
                "            return {section: verification, checkbox};",
                "            // The price/stock check stays visible while the owner picks a",
                "            // reference: it is a precondition for confirming, and ticking",
                "            // it here is recorded in verifiedCurrentPriceCases, so it is",
                "            // still ticked when the card re-renders into the confirmation",
                "            // path after the reference is picked.",
                "            const verified = buildSourceVerificationSection();",
                "            if (verified) { currentPriceVerified = verified.checkbox; card.appendChild(verified.section); }",
                "        // The checkbox listener is attached by the helper; the callback",
                "        // below runs only on user interaction, after",
                "        // refreshConfirmationGates is initialized.",
                "        const verified = buildSourceVerificationSection(() => refreshConfirmationGates());",
                "        if (verified) { currentPriceVerified = verified.checkbox; card.appendChild(verified.section); }",
                "            // The confirmation section stays visible even while source",
                "            // verification is pending: the owner must SEE the confirm",
                "            // path (and what unlocks it) instead of finding no decision",
                "            // at all on the card. The gate is the disabled state below",
                "            // — plus the server-side source_current_verified check — not",
                "            // invisibility. (Beer message 7218: the case rendered, but",
                "            // the whole «گام فعلی · تأیید مشخصات» section was hidden",
                "            // until the checkbox was ticked, so the card looked like it",
                "            // had no confirm/dismiss to offer.)"
            ]);
    }
}
if (baseDashboardSrc) {
    // The dashboard diff the deferred fix itself introduced (e18de2b blob
    // vs f60ff80) must contain no deletions except the one deliberate
    // class change on the restore button. The current file has moved on
    // since (the hold-gate changed dashboard.js), so it is not compared
    // here; the checkSource calls below still run against the current file.
    let deferredDashboardSrc = null;
    try {
        deferredDashboardSrc = execSync(`git show e18de2b:shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
    } catch (error) {
        console.warn('WARNING: could not read the e18de2b dashboard.js blob:', error.message);
    }
    if (deferredDashboardSrc) {
        const baseLines = baseDashboardSrc.split('\n');
        const newLines = deferredDashboardSrc.split('\n');
        const removed = baseLines.filter(l => !newLines.includes(l));
        const added = newLines.filter(l => !baseLines.includes(l));
        check('dashboard diff vs f60ff80: exactly one removed line (restore button class)',
            removed, ["                },'md-button md-primary'));"]);
        check('dashboard diff vs f60ff80: added lines carry the register button',
            added.some(l => l.includes("button('ثبت قیمت از پیام'")), true);
    }
    checkSource('dashboard: register button opens the source tab',
        dashboardSrc, "button('ثبت قیمت از پیام',()=>activate('source_integrity'),'md-button md-primary')");
    checkSource('dashboard: source tab still renders the register form unconditionally',
        dashboardSrc, 'const registerBlock = model.renderPriceRegister(product, panel);');
}

// --- Summary ---
console.log(`\n${pass} passed, ${fail} failed`);
if (fail) { console.log('DEFERRED PRICE-REGISTER BUTTON CHECKS FAILED'); process.exitCode = 1; }
else console.log('ALL DEFERRED PRICE-REGISTER BUTTON CHECKS PASSED');
