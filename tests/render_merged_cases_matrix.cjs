// Render merged secondary cases — ROOT-CAUSE MATRIX (follow-up to the
// duplicate-card merge, owner request 2026-11-26). The merge deployed and
// the cards merged (beer = 1 card, autopilot block + step trace present),
// but the pending re-extract case (message 7218) is NOT visible on the
// merged card — no confirm/dismiss in «مشخصات», «پیام تأمین‌کننده» or
// «پیامهای منبع». The merge harness only proved the MODEL merge
// (ensureProduct puts the case in product.ai) and the routing on a
// no-holds fixture; it never proved the case RENDERS.
// This harness extracts the real routing/gating functions and computes,
// for each candidate live state, the three values that decide whether the
// case renders:
//   (1) preferred = workbenchActionKey(product) — the «مشخصات» tab exists
//       only when preferred === 'ai' (dashboard.js builds productSections
//       from sections.find(key===preferred); 'ai' enters only that way —
//       source-checked below);
//   (2) renderGroups = groupAiVariantCases(product.ai excluding dismissed
//       cases) — renderAction's ai branch iterates exactly this, and
//       groupAiVariantCases never drops a case, so the branch renders
//       nothing iff every case is dismissed;
//   (3) readyAction — whether the action is actionable at all.
// The matrix answers: is there a live-state combination where the tab
// EXISTS but renders NOTHING? If yes, that combination is the root-cause
// candidate; if no, the premise is wrong and the case must render (and
// the live check needs re-examination).
// Run: node tests/render_merged_cases_matrix.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

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

const FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchQueueMatches',
    'workbenchIsDismissed', 'workbenchDismissalKey', 'workbenchCurrentDismissalKey',
    'workbenchDismissalToken', 'workbenchSourceRecord', 'workbenchSourceRecords',
    'workbenchAiCaseIsDismissed', 'workbenchSetDismissals', 'workbenchReadyAction',
    'workbenchNextStep', 'workbenchActionForQueue', 'workbenchHoldRoute',
    'workbenchAutopilotHolds', 'workbenchNeedsPriceRegistration',
    'workbenchHasUnchangedLinkCheck', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger',
    'groupAiVariantCases', 'aiProductAttributes', 'aiVariantBaseName', 'aiCaseGroupTerms',
    'normalizeAiVariant', 'splitAiVariantValues', 'cleanAiVariantToken', 'aiDisplayKey'];
function buildSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of FNS) pieces.push(extractFunction(source, name));
    // FIX-4: workbenchNextStep now calls workbenchHasChangedLinkCheck; it
    // exists only in files newer than the 4ac67ae base, so extract it when
    // present (the base's workbenchNextStep never calls it).
    if (source.includes('function workbenchHasChangedLinkCheck(')) pieces.push(extractFunction(source, 'workbenchHasChangedLinkCheck'));
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
function tabExists(preferred) { return preferred === 'ai'; }
function renderGroups(product) {
    return groupAiVariantCases(product.ai.filter(item => !workbenchAiCaseIsDismissed(item)));
}
return {
    setData(v) { workbenchData = v; data = v; },
    setDismissals(items) { workbenchSetDismissals(items); },
    workbenchActionKey, workbenchQueueMatches, workbenchIsDismissed,
    workbenchReadyAction, workbenchNextStep, workbenchAiCaseIsDismissed,
    tabExists, renderGroups,
};`;
    return new Function(code)();
}

const sb = buildSandbox(src);
sb.setData({ autopilot: { holds: [] } });

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

// --- Fixtures: the live beer ids where the brief gives them ---
const BEER_ID = '_____________________167421';
const BEER_OWNER_CONFIRM = 'owner-confirm:66844eca-beer';
const BEER_REEXTRACT = 'reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab:7218:0';
const CASE_7218 = 'ai:4de46c523f00000000000000000000000000000000000000000000000000000000';
const CASE_15 = 'ai:' + crypto.createHash('sha256').update('offer-beer-msg15').digest('hex');

function beerStatus(overrides = {}) {
    return {
        status: 'imported', sale_offer_blocker: 'regular_price',
        latest_offer_id: BEER_OWNER_CONFIRM,
        publication_needed: false, update_needed: false, regular_price_missing: false,
        regular_price_state: 'missing', source_integrity_issues: [],
        message_count: 0, ...overrides,
    };
}
function beerCase(offerId, caseId, messageId, extra = {}) {
    return {
        case_id: caseId, offer_id: offerId, product_id: BEER_ID,
        product_name: 'آب جو بلک لیبل،۱۲ عددی', channel: 'bonakdarjavan',
        message_id: messageId, batch_id: 'reextract:50115195ed438942d3490cb3e0011d64',
        sale_price: 450000, publication_held: false, message_review: false,
        reopened: false, ...extra,
    };
}
function beerProduct(cases, statusOverrides = {}, extra = {}) {
    return {
        product_id: BEER_ID, key: 'product:' + BEER_ID,
        name: 'آب جو بلک لیبل،۱۲ عددی', channel: 'bonakdarjavan',
        holds: [], ai: cases, references: [], conflicts: [], supplierPrices: [],
        status: beerStatus(statusOverrides), ...extra,
    };
}
function outcome(product) {
    return {
        preferred: sb.workbenchActionKey(product),
        tabExists: sb.tabExists(sb.workbenchActionKey(product)),
        renderGroups: sb.renderGroups(product).length,
        ready: sb.workbenchReadyAction(product),
    };
}

// --- Source facts the matrix rests on ---
checkSource('tab bar: current section is sections.find(key===preferred)',
    dashboardSrc, "const currentSection=preferred==='status'?['status','انتشار']:sections.find(([key])=>key===preferred);");
checkSource('tab bar: no other push adds the ai tab',
    dashboardSrc, "productSections.push(['status','انتشار']);");
checkSource('fill: renderAction runs only when the tab IS the preferred action',
    dashboardSrc, "const hasAction=key===preferred;");
checkSource('renderAction: ai branch iterates non-dismissed cases only',
    src, "groupAiVariantCases(product.ai.filter(item=>!workbenchAiCaseIsDismissed(item)))");
checkSource('renderAction: returns null unless the action key matches',
    src, 'if (workbenchActionKey(product)!==primaryKey) return null;');
checkSource('aiCaseCard: a publication-held group renders the hold notice and returns early (no confirm/dismiss)',
    src, "if (held) {");
checkSource('queue match ai: needs no publication holds AND a non-held case',
    src, "if (key === 'ai') matched = !product.holds.length && product.ai.some(item => !item.publication_held);");

// --- Matrix ---
// M0 — baseline (the merge harness fixture): 1 free case, no holds.
{
    sb.setDismissals([]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218')]);
    check('M0 baseline: preferred ai, tab exists, 1 render group, ready ai',
        outcome(p), { preferred: 'ai', tabExists: true, renderGroups: 1, ready: 'ai' });
}
// M1 — both cases dismissed: the card routes to 'deferred' and the ai
// tab VANISHES (workbenchIsDismissed's first branch fires). So dismissal
// does NOT produce "tab exists but renders nothing" — refuted.
{
    sb.setDismissals([{ task_key: 'ai|case:' + CASE_7218 }, { task_key: 'ai|case:' + CASE_15 }]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218'), beerCase('offer-beer-msg15', CASE_15, '15')]);
    const o = outcome(p);
    check('M1 both dismissed: preferred deferred, NO ai tab (dismissal refuted as the cause)', o,
        { preferred: 'deferred', tabExists: false, renderGroups: 0, ready: '' });
}
// M2 — both cases publication-held.
{
    sb.setDismissals([]);
    const p = beerProduct([
        beerCase(BEER_REEXTRACT, CASE_7218, '7218', { publication_held: true }),
        beerCase('offer-beer-msg15', CASE_15, '15', { publication_held: true }),
    ]);
    check('M2 both held: preferred is market_prices, NO ai tab at all',
        outcome(p), { preferred: 'market_prices', tabExists: false, renderGroups: 2, ready: 'market_prices' });
}
// M3 — 7218 held, 15 free: the held case renders in short form, the free one with confirm/dismiss.
{
    sb.setDismissals([]);
    const p = beerProduct([
        beerCase(BEER_REEXTRACT, CASE_7218, '7218', { publication_held: true }),
        beerCase('offer-beer-msg15', CASE_15, '15'),
    ]);
    check('M3 one held + one free: preferred ai, tab exists, 2 render groups',
        outcome(p), { preferred: 'ai', tabExists: true, renderGroups: 2, ready: 'ai' });
}
// M4 — 7218 dismissed, 15 free.
{
    sb.setDismissals([{ task_key: 'ai|case:' + CASE_7218 }]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218'), beerCase('offer-beer-msg15', CASE_15, '15')]);
    check('M4 7218 dismissed + 15 free: preferred ai, tab exists, 1 render group (only 15)',
        outcome(p), { preferred: 'ai', tabExists: true, renderGroups: 1, ready: 'ai' });
}
// M5 — 7218 free, 15 dismissed.
{
    sb.setDismissals([{ task_key: 'ai|case:' + CASE_15 }]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218'), beerCase('offer-beer-msg15', CASE_15, '15')]);
    check('M5 7218 free + 15 dismissed: preferred ai, tab exists, 1 render group (only 7218)',
        outcome(p), { preferred: 'ai', tabExists: true, renderGroups: 1, ready: 'ai' });
}
// M6 — a publication hold on the product (batch hold) + 2 free cases.
{
    sb.setDismissals([]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218'), beerCase('offer-beer-msg15', CASE_15, '15')],
        {}, { holds: [{ hold_id: 'reextract:50115195ed438942d3490cb3e0011d64', context: { messages: [{ message_id: '7218', channel: 'bonakdarjavan' }] } }] });
    check('M6 product publication hold: preferred holds, NO ai tab (ready holds — the hold has messages)',
        outcome(p), { preferred: 'holds', tabExists: false, renderGroups: 2, ready: 'holds' });
}
// M7 — message_count present, no message_review case: product_messages outranks ai.
{
    sb.setDismissals([]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218')], { message_count: 2 });
    check('M7 message_count + no message_review: preferred product_messages, NO ai tab',
        outcome(p), { preferred: 'product_messages', tabExists: false, renderGroups: 1, ready: 'product_messages' });
}
// M8 — 7218 held + 15 dismissed: no free non-dismissed case, so the ai
// queue is dismissed as a whole (first branch of workbenchIsDismissed) and
// the card routes to 'deferred'. The held 7218 case is still in the render
// groups (the filter is dismissal-only), but the tab does not exist, so it
// never renders.
{
    sb.setDismissals([{ task_key: 'ai|case:' + CASE_15 }]);
    const p = beerProduct([
        beerCase(BEER_REEXTRACT, CASE_7218, '7218', { publication_held: true }),
        beerCase('offer-beer-msg15', CASE_15, '15'),
    ]);
    check('M8 7218 held + 15 dismissed: preferred deferred, NO ai tab',
        outcome(p), { preferred: 'deferred', tabExists: false, renderGroups: 1, ready: '' });
}
// M11 — a single held case: no free case, so 'ai' never matches; the card
// routes to market_prices (blocker regular_price) and has no ai tab. A
// held-only product's case is reachable only via the holds queue.
{
    sb.setDismissals([]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218', { publication_held: true })]);
    check('M11 single held case: preferred market_prices, NO ai tab',
        outcome(p), { preferred: 'market_prices', tabExists: false, renderGroups: 1, ready: 'market_prices' });
}
// M9 — a message_review case: the first branch of workbenchPendingAction returns ai directly.
{
    sb.setDismissals([]);
    const p = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218', { message_review: true })]);
    check('M9 message_review case: preferred ai, tab exists, 1 render group',
        outcome(p), { preferred: 'ai', tabExists: true, renderGroups: 1, ready: 'ai' });
}
// M10 — the whole ai action dismissed via the action key: preferred becomes deferred.
{
    const product = beerProduct([beerCase(BEER_REEXTRACT, CASE_7218, '7218')]);
    const actionKey = 'ai|' + encodeURIComponent('product:' + BEER_ID).replace(/[!'()*~]/g, c => '%' + c.charCodeAt(0).toString(16).toUpperCase());
    sb.setDismissals([{ task_key: actionKey }]);
    check('M10 ai action dismissed: preferred deferred, NO ai tab',
        outcome(product), { preferred: 'deferred', tabExists: false, renderGroups: 1, ready: '' });
}

// --- P0 — the EXACT probe #3/#4 live state (g7_probe3_out.json + g7_probe4_out.json).
// This is the decisive fixture: the real functions must reproduce the live
// symptom (preferred market_prices, case A not rendered) for the probe's
// mechanism to hold. If they return preferred 'ai', the probe's mechanism
// is wrong and the real block is elsewhere.
const CASE_A_ID = 'ai:4de46c523f579de0a69fa13c79d0798d4399d7c0f53a06fca137de62e25b2a50';
const CASE_B_ID = 'ai:50c431a0cdf8406400227241527514d51ca62fca259e6714ee09461dd4f37870';
const CASE_A_OFFER = 'reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab:7218:0';
const CASE_B_OFFER = 'reextract:50115195ed438942d3490cb3e0011d64:11df1b60b5de96b08f6c27906c5074da6c4d45b60f501ef6123a6d029435d4d4:15:0';
function probeCaseA() {
    return {
        case_id: CASE_A_ID, offer_id: CASE_A_OFFER, product_id: BEER_ID,
        product_name: 'آب جو بلک لیبل،۱۲ عددی', channel: '@bonakdarjavan',
        message_id: '7218',
        batch_id: 'reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab',
        sale_price: '75000', detected_price: '75000', publication_held: false,
        message_review: null, reopened: null, decision: null,
        reference_snapshot_id: '0938f15edc83bc5b7317d0cfccfbaf3f299ecc9634bbb0cd9bd6eec63423ca93',
        reference_match_id: '', reference_assignment: null,
        historical_reextract: true, source_current_check_required: true,
        reason: 'Historical price needs current supplier verification; reference: no_compatible_reference',
    };
}
function probeCaseB() {
    return {
        case_id: CASE_B_ID, offer_id: CASE_B_OFFER, product_id: BEER_ID,
        product_name: 'آب جو بلک لیبل،۱۲ عددی', channel: '@bonakdarjavan',
        message_id: '15',
        batch_id: 'reextract:50115195ed438942d3490cb3e0011d64:11df1b60b5de96b08f6c27906c5074da6c4d45b60f501ef6123a6d029435d4d4',
        sale_price: '85000', detected_price: '85000', publication_held: false,
        message_review: null, reopened: null, decision: null,
        reference_snapshot_id: '0938f15edc83bc5b7317d0cfccfbaf3f299ecc9634bbb0cd9bd6eec63423ca93',
        reference_match_id: '', reference_assignment: null,
        historical_reextract: true, source_current_check_required: true,
        reason: 'Historical price needs current supplier verification; reference: no_compatible_reference',
    };
}
function probeBeerProduct() {
    return {
        product_id: BEER_ID, key: 'product:' + BEER_ID,
        name: 'آب جو بلک لیبل،۱۲ عددی', channel: '@bonakdarjavan',
        holds: [], ai: [probeCaseA(), probeCaseB()], references: [], conflicts: [], supplierPrices: [],
        status: {
            status: 'imported', sale_offer_blocker: 'regular_price',
            latest_offer_id: 'owner-confirm:66844eca5300460bfe2b67ea180aed17127822b2519d056845e993223af54f06',
            publication_needed: false, update_needed: false, regular_price_missing: true,
            regular_price_state: 'missing', source_integrity_issues: [],
            supplier_price_state: 'current', reference_price_role: 'needs_review',
        },
    };
}
{
    // Dismiss only case B, exactly as live (ai|case:ai:50c431a0…, bulk 'fresh start' set-aside).
    sb.setDismissals([{ task_key: 'ai|case:' + CASE_B_ID }]);
    // The 2 live autopilot holds, for the hold-route computation.
    sb.setData({ autopilot: { holds: [
        { product_id: BEER_ID, gate: 'market', reason: 'جست‌وجوی بازار نتیجه‌ای نداشت.' },
        { product_id: BEER_ID, gate: 'admission', reason: 'قیمت عادی جاری نیست.' },
    ] } });
    const p = probeBeerProduct();
    const o = outcome(p);
    console.log('P0 probe-exact outcome:', JSON.stringify(o));
    check('P0 probe-exact: preferred ai, tab exists, 1 render group (case A only), ready ai',
        o, { preferred: 'ai', tabExists: true, renderGroups: 1, ready: 'ai' });
    // And the merge harness's (g) claim on the same fixture: the ai queue matches.
    check('P0 probe-exact: ai queue matches', sb.workbenchQueueMatches(p, 'ai'), true);
    check('P0 probe-exact: case A is not dismissed', sb.workbenchAiCaseIsDismissed(p.ai[0]), false);
    check('P0 probe-exact: case B is dismissed', sb.workbenchAiCaseIsDismissed(p.ai[1]), true);
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
if (fail) process.exit(1);
console.log('ALL RENDER-MERGED-CASES MATRIX CHECKS PASSED');
