// Streamline funnel («مسیر انتشار», owner request 2026-11-26): Node harness.
// Run: node tests/streamline_funnel.cjs
// The funnel is the aggregate view of the 7 publication steps the per-card
// step trace shows: for each step, how many products are stuck there, why,
// and what to do. The counts are computed from product.status.step_trace —
// the same evaluation the card renders — so the funnel and the cards can
// never disagree. This harness extracts the REAL funnel helpers and the
// REAL streamlineView from includes/owner-dashboard.js and asserts:
//   (a) the per-step stuck count equals the number of products whose
//       current blocker is that step, on a 144-product fixture built from
//       the known live numbers (144 total, 7 published, 129 stuck at
//       supplier-price verification — 46 awaiting owner confirm, 73 with
//       no source URL, 10 whose link could not be read — plus 2 regular,
//       2 market, 1 admission, 1 publication, 2 flowing);
//   (b) the bottleneck step is the largest count (supplier_price, 129);
//   (c) the reason breakdowns sum to the step count (no product counted
//       twice, none missing) and the step counts + published + flowing sum
//       to the total;
//   (d) the unblock buttons navigate to the filtered Products list for
//       that step/reason and change no product state;
//   (e) the Products list step filter (matches) shows exactly the funnel's
//       products for a step/reason, and an unknown step matches nothing.
// Negative control: the same extraction against the deployed dashboard.js
// (hash-verified 8b79a620b509) must find NO funnel — no streamlineView, no
// funnel helpers, no «مسیر انتشار» nav, no step filter.
// No browser, no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const crypto = require('crypto');

const repoRoot = path.join(__dirname, '..');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

const DEPLOYED_DASHBOARD_HASH = '8b79a620b509';
let deployedDashboardSrc = null;
try {
    deployedDashboardSrc = execSync(`git show 6fc9a4f:shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
} catch (error) {
    console.warn('WARNING: could not read the deployed dashboard.js blob:', error.message);
}
if (deployedDashboardSrc) {
    const hash = crypto.createHash('sha256').update(deployedDashboardSrc, 'utf8').digest('hex').slice(0, 12);
    if (hash !== DEPLOYED_DASHBOARD_HASH) {
        console.warn(`WARNING: deployed dashboard.js blob hashes ${hash}, not ${DEPLOYED_DASHBOARD_HASH} — negative control skipped`);
        deployedDashboardSrc = null;
    }
}

function extractTopConst(source, name) {
    const start = source.indexOf(`const ${name}`);
    if (start < 0) throw new Error('const not found: ' + name);
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

function extractTopFunction(source, name) {
    const start = source.indexOf(`\n    function ${name}(`);
    if (start < 0) throw new Error('function not found: ' + name);
    const open = source.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') depth++;
        else if (source[i] === '}') {
            depth--;
            if (depth === 0) return source.slice(start + 1, i + 1);
        }
    }
    throw new Error('unbalanced braces in ' + name);
}

// Functions declared inside create() (8-space indent). The body brace is
// the first '{' AFTER the parameter list closes — a naive search finds
// the '{}' of a default parameter and returns only the signature.
function extractInnerFunction(source, name) {
    const start = source.indexOf(`        function ${name}(`);
    if (start < 0) throw new Error('function not found: ' + name);
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

// --- Minimal DOM stub (same shape as the deferred-button harness) ---
function makeEl(tag, text, className) {
    const el = {
        tagName: String(tag).toUpperCase(),
        children: [],
        get childNodes() { return this.children; },
        className: className || '',
        textContent: text === undefined || text === null ? '' : String(text),
        disabled: false,
        value: '',
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
function findByClass(el, cls) {
    const out = [];
    const walk = (n) => {
        if ((n.className || '').split(' ').includes(cls)) out.push(n);
        (n.children || []).forEach(walk);
    };
    walk(el);
    return out;
}

const fa = (value) => Number(value || 0).toLocaleString('fa-IR');

// --- The REAL funnel helpers (top level in owner-dashboard.js) ---
const funnelCode = [
    extractTopConst(dashboardSrc, 'STREAMLINE_STEPS'),
    extractTopConst(dashboardSrc, 'STREAMLINE_REASONS'),
    extractTopFunction(dashboardSrc, 'streamlineStep'),
    extractTopFunction(dashboardSrc, 'streamlineStepFact'),
    extractTopFunction(dashboardSrc, 'streamlineStepBlocks'),
    extractTopFunction(dashboardSrc, 'streamlineStuckStep'),
    extractTopFunction(dashboardSrc, 'streamlineStuckReason'),
    extractTopFunction(dashboardSrc, 'streamlineFunnel'),
].join('\n');
const funnelSandbox = new Function(`
let sourceFor = (product) => product.ai[0] || product.supplierPrices[0] || product.references[0] || {};
${funnelCode}
return {STREAMLINE_STEPS, STREAMLINE_REASONS, streamlineStep, streamlineStepFact, streamlineStepBlocks, streamlineStuckStep, streamlineStuckReason, streamlineFunnel};
`)();
const { STREAMLINE_STEPS, streamlineStuckStep, streamlineStuckReason, streamlineFunnel } = funnelSandbox;

let pass = 0;
let fail = 0;
function check(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  expected:', JSON.stringify(expected), '\n  actual:  ', JSON.stringify(actual)); }
}
function checkTrue(label, condition) {
    if (condition) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label); }
}

// --- Fixtures: the 7 trace steps in the exact PHP shape ---
const STEP_LABELS = {
    supplier_price: 'قیمت تأمین‌کننده', regular_price: 'قیمت عادی', market_agreement: 'توافق بازار',
    availability: 'موجودی', admission: 'پذیرش خودکار (gA)', verdict: 'داوری', publication: 'انتشار / فروشگاه',
};
function mkStep(key, status, statusLabel, facts = [], note = null) {
    return { key, label: STEP_LABELS[key], status, status_label: statusLabel, facts, note };
}
function mkTrace(steps) {
    return { product_id: '', generated_at: '2026-11-26T00:00:00+00:00', steps };
}
function mkProduct(id, trace, statusOverrides = {}) {
    return {
        product_id: id, key: id, name: 'کالا ' + id, channel: 'bonakdarjavan',
        holds: [], ai: [], references: [], conflicts: [], supplierPrices: [],
        status: {
            status: 'imported', sale_offer_blocker: '', publication_needed: false,
            update_needed: false, regular_price_missing: false, regular_price_state: 'current',
            latest_offer_id: 'offer-' + id, source_post_url: 'https://t.me/bonakdarjavan/100',
            published: false, step_trace: trace, ...statusOverrides,
        },
    };
}
const OK_SUPPLIER = () => mkStep('supplier_price', 'ok', 'جاری', [['قیمت ثبت‌شده', 190000, 'amount'], ['روش تأیید', 'تأیید مالک', 'text']]);
const OK_REGULAR = () => mkStep('regular_price', 'ok', 'جاری', [['قیمت عادی', 290000, 'amount']]);
const OK_MARKET = () => mkStep('market_agreement', 'ok', 'همخوان', [['تعداد نقل‌قول', 2, 'int']]);
const OK_AVAIL = () => mkStep('availability', 'ok', 'موجود', [['وضعیت اعلام‌شده', 'نامشخص (در پیام ذکر نشده)', 'text']]);
const INFO_MARKET = () => mkStep('market_agreement', 'info', 'بررسی نشده', [['نقل‌قول بازار', 'هنوز نقل‌قولی نیست', 'text']]);
const INFO_ADMISSION = () => mkStep('admission', 'info', 'ارزیابی نشده', [['ارزیابی پذیرش', 'هنوز ارزیابی پذیرش ثبت نشده', 'text']]);
const INFO_VERDICT = () => mkStep('verdict', 'info', 'ثبت نشده', [['داوری', 'هنوز داوری ثبت نشده', 'text']]);
const INFO_PUBLICATION = () => mkStep('publication', 'info', 'ساخته نشده', [['وضعیت فروشگاه', 'هنوز در فروشگاه ساخته نشده', 'text']]);

// A published product: all 7 steps ok.
function publishedProduct(id) {
    const trace = mkTrace([
        OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
        mkStep('admission', 'ok', 'پذیرفته شد', [['نتیجه', 'پذیرفته شد (زنده)', 'text']]),
        mkStep('verdict', 'ok', 'تأیید شد', [['داوری', 'تأیید شد', 'text']]),
        mkStep('publication', 'ok', 'منتشر شده', [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['قابل خرید', 'بله', 'text']]),
    ]);
    return mkProduct(id, trace, { published: true });
}
// A product flowing through the pipeline: nothing blocks it, but the
// autopilot has not evaluated it yet and it is not published.
function flowingProduct(id) {
    const trace = mkTrace([OK_SUPPLIER(), OK_REGULAR(), INFO_MARKET(), OK_AVAIL(), INFO_ADMISSION(), INFO_VERDICT(), INFO_PUBLICATION()]);
    return mkProduct(id, trace);
}
// Supplier-stuck: the trace supplier step is 'warn' with status_label
// 'نیاز به بررسی' (state needs_review — the real shape for a price that
// was never verified) and the sale-offer blocker is 'supplier_price'.
// Later steps are bad too (regular missing) — the funnel must still put
// the product at supplier_price, the FIRST blocking step.
function supplierStuckProduct(id, kind) {
    const linkFact = kind === 'awaiting_confirm' ? [['بررسی لینک', 'بدون تغییر', 'text']]
        : kind === 'uncheckable' ? [['بررسی لینک', 'قابل بررسی نیست', 'text']] : [];
    const trace = mkTrace([
        mkStep('supplier_price', 'warn', 'نیاز به بررسی', [
            ['قیمت ثبت‌شده', 190000, 'amount'], ['روش تأیید', 'تأیید نشده', 'text'], ...linkFact,
        ]),
        mkStep('regular_price', 'bad', 'ثبت نشده', [['قیمت عادی', 'ثبت نشده', 'amount']]),
        INFO_MARKET(), OK_AVAIL(), INFO_ADMISSION(), INFO_VERDICT(), INFO_PUBLICATION(),
    ]);
    const status = { sale_offer_blocker: 'supplier_price' };
    if (kind === 'no_source_url') status.source_post_url = '';
    return mkProduct(id, trace, status);
}

// --- The 144-product fixture from the known live numbers ---
const products = [];
for (let i = 1; i <= 7; i++) products.push(publishedProduct('p-pub-' + i));
for (let i = 1; i <= 46; i++) products.push(supplierStuckProduct('p-confirm-' + i, 'awaiting_confirm'));
for (let i = 1; i <= 73; i++) products.push(supplierStuckProduct('p-nosrc-' + i, 'no_source_url'));
for (let i = 1; i <= 10; i++) products.push(supplierStuckProduct('p-uncheck-' + i, 'uncheckable'));
// 2 stuck at regular price: one missing, one thin spread (regular current
// but the spread blocker is set — the step itself looks ok).
products.push(mkProduct('p-reg-missing', mkTrace([
    OK_SUPPLIER(),
    mkStep('regular_price', 'bad', 'ثبت نشده', [['قیمت عادی', 'ثبت نشده', 'amount']]),
    INFO_MARKET(), OK_AVAIL(), INFO_ADMISSION(), INFO_VERDICT(), INFO_PUBLICATION(),
]), { sale_offer_blocker: 'regular_price' }));
products.push(mkProduct('p-reg-thin', mkTrace([
    OK_SUPPLIER(),
    mkStep('regular_price', 'ok', 'جاری', [['قیمت عادی', 290000, 'amount'], ['اختلاف با قیمت فروش', 0.05, 'pct'], ['قابلیت تخفیف', 'ندارد', 'text']]),
    INFO_MARKET(), OK_AVAIL(), INFO_ADMISSION(), INFO_VERDICT(), INFO_PUBLICATION(),
]), { sale_offer_blocker: 'thin_spread' }));
// 2 stuck at market agreement: quotes disagree (cappuccino shape, but
// unpublished — a published product with a hold counts as published, see
// the edge case below).
for (let i = 1; i <= 2; i++) {
    products.push(mkProduct('p-market-' + i, mkTrace([
        OK_SUPPLIER(), OK_REGULAR(),
        mkStep('market_agreement', 'warn', 'ناهمخوان', [['تعداد نقل‌قول', 3, 'int'], ['پراکندگی نقل‌قول‌ها', 0.182, 'pct'], ['نتیجهٔ پراکندگی', 'خارج از باند مجاز', 'text']]),
        OK_AVAIL(),
        mkStep('admission', 'bad', 'توقف پذیرش بعدی', [['توقف فعلی', 'نقل‌قول‌های بازار همخوان نیستند.', 'text']]),
        INFO_VERDICT(),
        mkStep('publication', 'warn', 'پیش‌نویس', [['وضعیت فروشگاه', 'پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)', 'text']]),
    ]), { sale_offer_blocker: '' }));
}
// 1 stuck at admission: owner hold, everything else green.
products.push(mkProduct('p-adm-owner', mkTrace([
    OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
    mkStep('admission', 'bad', 'توقف پذیرش بعدی', [['توقف فعلی', 'توقف مالک', 'text']]),
    INFO_VERDICT(),
    mkStep('publication', 'warn', 'پیش‌نویس', [['وضعیت فروشگاه', 'پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)', 'text']]),
])));
// 1 stuck at publication: a draft, everything else green.
products.push(mkProduct('p-pub-draft', mkTrace([
    OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
    mkStep('admission', 'ok', 'آمادهٔ پذیرش', [['نتیجه', 'همهٔ گیت‌ها سبز — آمادهٔ پذیرش', 'text']]),
    mkStep('verdict', 'warn', 'در انتظار', [['داوری', 'در انتظار', 'text']]),
    mkStep('publication', 'warn', 'پیش‌نویس', [['وضعیت فروشگاه', 'پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)', 'text']]),
])));
for (let i = 1; i <= 2; i++) products.push(flowingProduct('p-flow-' + i));

const holds = { 'p-adm-owner': { product_id: 'p-adm-owner', gate: 'admission_owner', reason: 'توقف مالک' } };
const holdFor = (product) => holds[product.product_id] || null;

check('fixture has 144 products', products.length, 144);

// --- (a) Per-step stuck counts ---
const funnel = streamlineFunnel(products, holdFor);
const counts = {};
funnel.steps.forEach((entry) => { counts[entry.key] = entry.count; });
check('(a) stuck counts per step', counts, {
    supplier_price: 129, regular_price: 2, market_agreement: 2, availability: 0,
    admission: 1, verdict: 0, publication: 1,
});
check('(a) total is 144', funnel.total, 144);
check('(a) published is 7', funnel.published, 7);
check('(a) flowing is 2', funnel.flowing, 2);
check('(a) untraced is 0', funnel.untraced, 0);

// --- (b) Bottleneck ---
check('(b) bottleneck is supplier_price', funnel.bottleneck, 'supplier_price');
check('(b) bottleneck top reason is no_source_url (73)', funnel.topReason && funnel.topReason.key, 'no_source_url');
check('(b) bottleneck top reason count is 73', funnel.topReason && funnel.topReason.count, 73);

// --- (c) Reasons sum to the step count; everything sums to the total ---
const stepTotal = funnel.steps.reduce((sum, entry) => sum + entry.count, 0);
check('(c) stuck products total 135', stepTotal, 135);
check('(c) stuck + published + flowing = total', stepTotal + funnel.published + funnel.flowing, funnel.total);
for (const entry of funnel.steps) {
    const reasonSum = entry.reasons.reduce((sum, reason) => sum + reason.count, 0);
    check(`(c) reasons sum to the count for ${entry.key}`, reasonSum, entry.count);
}
const supplierReasons = {};
funnel.steps.find((e) => e.key === 'supplier_price').reasons.forEach((r) => { supplierReasons[r.key] = r.count; });
check('(c) supplier reasons: 73 no source URL, 46 awaiting confirm, 10 uncheckable', supplierReasons, {
    no_source_url: 73, awaiting_confirm: 46, uncheckable: 10,
});
const regularReasons = {};
funnel.steps.find((e) => e.key === 'regular_price').reasons.forEach((r) => { regularReasons[r.key] = r.count; });
check('(c) regular reasons: 1 missing, 1 thin spread', regularReasons, { missing: 1, thin_spread: 1 });

// --- Edge cases: the stuck-step rule on its own ---
function edgeProduct(id, trace, statusOverrides = {}, hold = null) {
    const product = mkProduct(id, trace, statusOverrides);
    return { product, stuck: streamlineStuckStep(product), reason: streamlineStuckReason(product, hold) };
}
{
    const { stuck, reason } = edgeProduct('e-nospread', mkTrace([
        OK_SUPPLIER(),
        mkStep('regular_price', 'ok', 'جاری', [['قیمت عادی', 290000, 'amount']]),
        INFO_MARKET(), OK_AVAIL(), INFO_ADMISSION(), INFO_VERDICT(), INFO_PUBLICATION(),
    ]), { sale_offer_blocker: 'no_spread' });
    check('edge: no_spread blocker puts the product at regular_price', stuck, 'regular_price');
    check('edge: no_spread reason', reason, 'no_spread');
}
{
    // A supplier price in its 7–14 day warning window is still usable:
    // it must NOT block when the real blocker is the regular price.
    const { stuck } = edgeProduct('e-supwarn', mkTrace([
        mkStep('supplier_price', 'warn', 'هشدار', [['قیمت ثبت‌شده', 190000, 'amount'], ['روش تأیید', 'تأیید مالک', 'text']]),
        mkStep('regular_price', 'bad', 'منقضی شده', [['قیمت عادی', 290000, 'amount']]),
        INFO_MARKET(), OK_AVAIL(), INFO_ADMISSION(), INFO_VERDICT(), INFO_PUBLICATION(),
    ]), { sale_offer_blocker: 'regular_price' });
    check('edge: warning supplier price does not block (regular does)', stuck, 'regular_price');
}
{
    // A flagged verdict re-holds the admission: the product is stuck at
    // admission, and the reason names the verdict flag.
    const { stuck, reason } = edgeProduct('e-verdict', mkTrace([
        OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
        mkStep('admission', 'bad', 'توقف پذیرش بعدی', [['توقف فعلی', 'داوری پرچم خورد.', 'text']]),
        mkStep('verdict', 'bad', 'پرچم خورد', [['داوری', 'پرچم خورد', 'text'], ['دلیل', 'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.', 'text']]),
        INFO_PUBLICATION(),
    ]), {}, { product_id: 'e-verdict', gate: 'admission', reason: 'داوری پرچم خورد.' });
    check('edge: flagged verdict re-hold sits at admission', stuck, 'admission');
    check('edge: flagged verdict reason', reason, 'verdict_hold');
}
{
    // Sale-closed: published but the sale is closed — stuck at publication,
    // not counted as published.
    const { stuck, reason } = edgeProduct('e-saleclosed', mkTrace([
        OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
        mkStep('admission', 'ok', 'پذیرفته شد', [['نتیجه', 'پذیرفته شد (زنده)', 'text']]),
        mkStep('verdict', 'ok', 'تأیید شد', [['داوری', 'تأیید شد', 'text']]),
        mkStep('publication', 'warn', 'منتشر شده، فروش بسته', [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['قابل خرید', 'خیر', 'text'], ['مجوز فروش', 'توقف به دستور مالک', 'text']]),
    ]), { published: true });
    check('edge: sale-closed sits at publication', stuck, 'publication');
    check('edge: sale-closed reason', reason, 'sale_closed');
    check('edge: sale-closed is not counted as published',
        streamlineFunnel([mkProduct('e-saleclosed', mkTrace([
            OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
            mkStep('admission', 'ok', 'پذیرفته شد', [['نتیجه', 'پذیرفته شد (زنده)', 'text']]),
            mkStep('verdict', 'ok', 'تأیید شد', [['داوری', 'تأیید شد', 'text']]),
            mkStep('publication', 'warn', 'منتشر شده، فروش بسته', [['وضعیت فروشگاه', 'منتشر شده', 'text']]),
        ]), { published: true })], holdFor).published, 0);
}
{
    // Evaluated and passing, but the storefront was never built: the
    // publish is the remaining work.
    const { stuck, reason } = edgeProduct('e-awaitpub', mkTrace([
        OK_SUPPLIER(), OK_REGULAR(), OK_MARKET(), OK_AVAIL(),
        mkStep('admission', 'ok', 'آمادهٔ پذیرش', [['نتیجه', 'همهٔ گیت‌ها سبز — آمادهٔ پذیرش', 'text']]),
        INFO_VERDICT(), INFO_PUBLICATION(),
    ]));
    check('edge: evaluated but never built sits at publication', stuck, 'publication');
    check('edge: awaiting-publish reason', reason, 'awaiting_publish');
}
{
    // Market chain-limit ('نوبت دستی') is not a block: the product flows
    // past it to the draft publication step.
    const { stuck } = edgeProduct('e-chain', mkTrace([
        OK_SUPPLIER(), OK_REGULAR(),
        mkStep('market_agreement', 'warn', 'نوبت دستی', [['بررسی توافق', 'سه بار خودکار؛ نوبت دستی', 'text']]),
        OK_AVAIL(),
        mkStep('admission', 'ok', 'آمادهٔ پذیرش', [['نتیجه', 'همهٔ گیت‌ها سبز — آمادهٔ پذیرش', 'text']]),
        INFO_VERDICT(),
        mkStep('publication', 'warn', 'پیش‌نویس', [['وضعیت فروشگاه', 'پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)', 'text']]),
    ]));
    check('edge: market chain-limit does not block (publication does)', stuck, 'publication');
}
{
    // Cappuccino shape: published AND carrying a market disagreement +
    // admission hold. The funnel counts it as published (the pipeline is
    // done); the hold is the card's job, not the funnel's.
    const capp = mkProduct('p_cappuccino', mkTrace([
        OK_SUPPLIER(), OK_REGULAR(),
        mkStep('market_agreement', 'warn', 'ناهمخوان', [['پراکندگی نقل‌قول‌ها', 0.182, 'pct']]),
        OK_AVAIL(),
        mkStep('admission', 'bad', 'توقف پذیرش بعدی', [['توقف فعلی', 'نقل‌قول‌های بازار همخوان نیستند.', 'text']]),
        mkStep('verdict', 'warn', 'در انتظار', [['داوری', 'در انتظار', 'text']]),
        mkStep('publication', 'ok', 'منتشر شده', [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['قابل خرید', 'بله', 'text']]),
    ]), { published: true });
    check('edge: published + held counts as published, not stuck', streamlineStuckStep(capp), '');
    check('edge: published + held lands in the published count',
        streamlineFunnel([capp], holdFor).published, 1);
}
{
    // No trace at all: untraced, never assigned to a step.
    const bare = mkProduct('p-notrace', null);
    delete bare.status.step_trace;
    check('edge: product without a trace is not stuck anywhere', streamlineStuckStep(bare), '');
    const f = streamlineFunnel([bare], holdFor);
    check('edge: product without a trace counts as untraced', f.untraced, 1);
    check('edge: product without a trace is not flowing', f.flowing, 0);
}

// --- (d) The view renders, and its buttons navigate without changing state ---
const viewCode = [
    extractInnerFunction(dashboardSrc, 'openStepFilter'),
    extractInnerFunction(dashboardSrc, 'streamlineView'),
].join('\n');
const navigateCalls = [];
const clearCalls = [];
const viewState = {
    scope: 'ready', action: '', focus: '', search: '', channel: '',
    step: '', stepReason: '', limit: 24,
};
const viewModel = {
    products, autopilotHold: holdFor,
    data: { product_status: { generated_at: '2026-11-26T00:00:00+00:00' } },
    formatDate: (value) => value,
};
const renderStreamline = new Function('el', 'button', 'fa', 'model', 'state', 'navigate', 'clearSelection', 'streamlineFunnel',
    `${viewCode}\nreturn streamlineView;`)(makeEl, makeButton, fa, viewModel, viewState,
    (view) => { navigateCalls.push(view); }, () => { clearCalls.push(true); }, streamlineFunnel);
const beforeJson = JSON.stringify(products);
const view = renderStreamline();
check('(d) view renders a section', view.tagName, 'SECTION');
check('(d) view carries the streamline class', view.className.includes('md-streamline'), true);
const viewText = textOf(view);
check('(d) heading names the view', viewText.includes('مسیر انتشار'), true);
check('(d) summary names the total', viewText.includes('۱۴۴ کالا'), true);
check('(d) summary names the published count', viewText.includes('۷ منتشر شده'), true);
check('(d) summary names the bottleneck', viewText.includes('بیشترین گلوگاه'), true);
check('(d) summary names the bottleneck step', viewText.includes('قیمت تأمین‌کننده'), true);
const stepBlocks = findByClass(view, 'md-funnel-step');
check('(d) seven step blocks render', stepBlocks.length, 7);
const stepLabels = stepBlocks.map((block) => textOf(findAll(block, 'H2')[0]));
check('(d) step blocks are in pipeline order', stepLabels, [
    'قیمت تأمین‌کننده', 'قیمت عادی', 'توافق بازار', 'موجودی',
    'پذیرش خودکار (gA)', 'داوری', 'انتشار / فروشگاه',
]);
const stepCounts = stepBlocks.map((block) => textOf(findAll(block, 'P').find((p) => (p.className || '').includes('md-funnel-count'))));
check('(d) step blocks show the stuck counts', stepCounts, ['۱۲۹', '۲', '۲', '۰', '۱', '۰', '۱']);
const bottleneckBlocks = findByClass(view, 'md-funnel-step--bottleneck');
check('(d) exactly one bottleneck block', bottleneckBlocks.length, 1);
check('(d) the bottleneck block is the supplier step', textOf(bottleneckBlocks[0]).includes('قیمت تأمین‌کننده'), true);
check('(d) the bottleneck block carries the گلوگاه marker', textOf(bottleneckBlocks[0]).includes('گلوگاه'), true);
const supplierBlock = stepBlocks[0];
check('(d) supplier reasons name the top reason', textOf(supplierBlock).includes('پیام منبع ثبت نشده · ۷۳'), true);
check('(d) supplier reasons name the confirm queue', textOf(supplierBlock).includes('در انتظار تأیید مالک · ۴۶'), true);
check('(d) supplier reasons name the unreadable links', textOf(supplierBlock).includes('لینک منبع خوانده نشد · ۱۰'), true);
check('(d) a zero-count step shows no action button',
    findAll(stepBlocks[3], 'BUTTON').length, 0);
const supplierButton = findAll(supplierBlock, 'BUTTON').find((b) => b.textContent === 'تأیید قیمت تأمین‌کننده');
check('(d) supplier step has its unblock action button', Boolean(supplierButton), true);
supplierButton.onClick();
check('(d) step button navigates to the Products view', navigateCalls, ['products']);
check('(d) step button sets the step filter', viewState.step, 'supplier_price');
check('(d) step button clears the reason filter', viewState.stepReason, '');
check('(d) step button sets the scope to all', viewState.scope, 'all');
const reasonButton = findAll(supplierBlock, 'BUTTON').find((b) => b.textContent === 'در انتظار تأیید مالک · ۴۶');
check('(d) a reason line is a button', Boolean(reasonButton), true);
navigateCalls.length = 0;
reasonButton.onClick();
check('(d) reason button navigates to the Products view', navigateCalls, ['products']);
check('(d) reason button sets the step filter', viewState.step, 'supplier_price');
check('(d) reason button sets the reason filter', viewState.stepReason, 'awaiting_confirm');
check('(d) rendering + clicking changed no product state', JSON.stringify(products), beforeJson);

// --- (e) The Products list step filter shows exactly the funnel's products ---
const matchesCode = [
    extractTopConst(dashboardSrc, 'normalize'),
    extractTopFunction(dashboardSrc, 'identities'),
    extractInnerFunction(dashboardSrc, 'next'),
    extractInnerFunction(dashboardSrc, 'ready'),
    extractInnerFunction(dashboardSrc, 'waiting'),
    extractInnerFunction(dashboardSrc, 'published'),
    extractInnerFunction(dashboardSrc, 'priceBlocked'),
    extractInnerFunction(dashboardSrc, 'matches'),
].join('\n');
const matchesSandbox = new Function('streamlineStuckStep', 'streamlineStuckReason', `
let model = null;
let state = {};
${matchesCode}
return {
    setModel(m) { model = m; },
    setState(s) { state = s; },
    matches,
};`)(streamlineStuckStep, streamlineStuckReason);
matchesSandbox.setModel({
    primaryAction: () => 'next', readyAction: () => '', autopilotHold: holdFor,
});
function countMatching(step, reason) {
    matchesSandbox.setState({
        search: '', channel: '', risk: '', held: false, step: step || '', stepReason: reason || '',
        focus: '', focusType: 'product_id', action: '', scope: 'all',
    });
    return products.filter((p) => matchesSandbox.matches(p)).length;
}
check('(e) step filter supplier_price shows 129', countMatching('supplier_price', ''), 129);
check('(e) step+reason awaiting_confirm shows 46', countMatching('supplier_price', 'awaiting_confirm'), 46);
check('(e) step+reason no_source_url shows 73', countMatching('supplier_price', 'no_source_url'), 73);
check('(e) step filter market_agreement shows 2', countMatching('market_agreement', ''), 2);
check('(e) step filter admission shows 1', countMatching('admission', ''), 1);
check('(e) step filter availability shows 0', countMatching('availability', ''), 0);
check('(e) an unknown step matches nothing', countMatching('nope', ''), 0);

// --- Source checks: the funnel reads the same trace the card renders ---
checkTrue('funnel reads product.status.step_trace (same evaluation as the card)',
    dashboardSrc.includes('function streamlineStuckStep') && dashboardSrc.includes('product.status?.step_trace'));
checkTrue('dashboard nav lists the streamline view',
    dashboardSrc.includes("['streamline','مسیر انتشار']"));
checkTrue('paint dispatches the streamline view',
    dashboardSrc.includes("state.view==='streamline')main.replaceChildren(streamlineView())"));
checkTrue('the step filter is wired into the products list',
    dashboardSrc.includes("if(state.step){") && dashboardSrc.includes("streamlineStuckStep(product)!==state.step"));
checkTrue('the funnel is dashboard-only (workflows.js untouched by this change)',
    !fs.readFileSync(path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js'), 'utf8').includes('streamline'));

// --- Negative control: the deployed dashboard.js has no funnel ---
if (deployedDashboardSrc) {
    checkTrue('NEGATIVE CONTROL (deployed 8b79a620b509): no streamline view function',
        !deployedDashboardSrc.includes('function streamlineView'));
    checkTrue('NEGATIVE CONTROL (deployed 8b79a620b509): no funnel helpers',
        !deployedDashboardSrc.includes('streamlineFunnel') && !deployedDashboardSrc.includes('streamlineStuckStep'));
    checkTrue('NEGATIVE CONTROL (deployed 8b79a620b509): no «مسیر انتشار» nav',
        !deployedDashboardSrc.includes('مسیر انتشار'));
    checkTrue('NEGATIVE CONTROL (deployed 8b79a620b509): no step filter in matches',
        !deployedDashboardSrc.includes("state.step"));
    let threw = false;
    try { extractTopFunction(deployedDashboardSrc, 'streamlineFunnel'); } catch (_) { threw = true; }
    checkTrue('NEGATIVE CONTROL (deployed 8b79a620b509): funnel extraction throws (function absent)', threw);
} else {
    console.log('SKIP negative control (deployed blob unreadable or hash mismatch)');
}

console.log(`\n${pass} passed, ${fail} failed`);
if (fail) { console.log('STREAMLINE FUNNEL CHECKS FAILED'); process.exitCode = 1; }
else console.log('ALL STREAMLINE FUNNEL CHECKS PASSED');
