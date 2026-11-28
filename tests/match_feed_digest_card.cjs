// Persistent matching slice 3 (feed + digest + card wiring) — Node harness.
// Run: node tests/match_feed_digest_card.cjs
// Negative control: MATCH_SRC=<deff6ce base file> MATCH_NEGATIVE=1 node tests/match_feed_digest_card.cjs
//   (the new-behaviour checks must fail there; see the differential section).
// Extracts the real functions from includes/owner-workflows.js by brace
// matching, evaluates them against stubbed module state, and asserts:
//   (a) a needs_review catalog match renders with a path forward: each of
//       outside_margin / reference_missing / price_unavailable /
//       reference_identity_changed names the change and the options
//       (re-acknowledge / pick another reference / proceed without one)
//       as the card's first-view line — including reference_identity_changed,
//       whose card would otherwise show no action at all ('next' dead end);
//   (b) an approved reference shows no such line (regression);
//   (c) marketPriceChangeLine renders «قیمت این فروشنده از X به Y تومان
//       تغییر کرد» (+ date) for a changed source and '' otherwise;
//   (d) source checks: owner-dashboard.js renders match_events in the
//       same list component as market_events (same row shape, 'match:'
//       ids, references origin, dismissable), and owner-workflows.js
//       calls the line helper under the selected market quote.
// The routing functions' orthogonal leaves are stubbed to neutral values,
// identically for both versions, so a stub-induced error cancels out of
// the differential comparison. No browser, no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = process.env.MATCH_SRC
    || path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');
const negative = process.env.MATCH_NEGATIVE === '1';

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
const OPTIONAL_FNS = ['workbenchNeedsPriceRegistration', 'workbenchHasUnchangedLinkCheck',
    'workbenchHasChangedLinkCheck', 'workbenchHasUncheckableLinkCheck'];
const LINE_FNS = ['amount', 'formatSourceTimestamp'];

function buildSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    for (const name of OPTIONAL_FNS) {
        if (source.includes(`function ${name}(`)) pieces.push(extractFunction(source, name));
        else pieces.push(`function ${name}() { return undefined; }`);
    }
    for (const name of LINE_FNS) pieces.push(extractFunction(source, name));
    // marketPriceChangeLine is the new top-level helper: on the base it
    // does not exist; stub it so the checks FAIL instead of throwing.
    if (source.includes('function marketPriceChangeLine(')) pieces.push(extractFunction(source, 'marketPriceChangeLine'));
    else pieces.push(`function marketPriceChangeLine() { return ''; }`);
    // marketQuoteSanityLine (Basalam owner-force flag render): same stub
    // treatment on the base.
    if (source.includes('function marketQuoteSanityLine(')) pieces.push(extractFunction(source, 'marketQuoteSanityLine'));
    else pieces.push(`function marketQuoteSanityLine() { return ''; }`);
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
    workbenchActionKey, workbenchNextStep, workbenchReadyAction,
    workbenchHoldRoute, marketPriceChangeLine, marketQuoteSanityLine,
};`;
    return new Function(code)();
}

const sandbox = buildSandbox(src);

let pass = 0;
let fail = 0;
function check(label, actual, expected) {
    const ok = JSON.stringify(actual) === JSON.stringify(expected);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  expected:', JSON.stringify(expected), '\n  actual:  ', JSON.stringify(actual)); }
}
function checkContains(label, actual, needle) {
    const ok = typeof actual === 'string' && actual.includes(needle);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  expected to contain:', JSON.stringify(needle), '\n  actual:  ', JSON.stringify(actual)); }
}
function checkAbsent(label, source, needle) {
    // Negative control: the new behaviour must be absent from the source.
    const ok = !source.includes(needle);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  should be absent from source:', JSON.stringify(needle)); }
}
function checkSource(label, source, needle) {
    const ok = source.includes(needle);
    if (ok) { pass++; console.log('PASS', label); }
    else { fail++; console.log('FAIL', label, '\n  missing from source:', JSON.stringify(needle)); }
}

function cardProduct(approvalState, pct, updateNeeded, references) {
    return {
        product_id: 'p1',
        key: 'p1',
        name: 'کالای تست',
        channel: 'telegram',
        holds: [],
        ai: [],
        references: references || [],
        conflicts: [],
        supplierPrices: [],
        status: {
            status: 'needs_review',
            sale_offer_blocker: '',
            publication_needed: false,
            latest_offer_id: 'offer-1',
            update_needed: updateNeeded,
            reference_approval_state: approvalState,
            reference_price_change_percent: pct,
            review_reason: '',
            step_trace: null,
            regular_price_state: '',
            supplier_out_of_stock: false,
            source_integrity_issues: [],
        },
    };
}

const changed = { old_price: 90000, regular_price: 95000, occurred_at: 1764200000 };

if (!negative) {
    // (a) needs_review catalog matches render with a path forward.
    const outside = sandbox.workbenchNextStep(cardProduct('outside_margin', 12.5, true));
    checkContains('outside_margin names the price change', outside, 'قیمت مرجع تغییر کرده است');
    checkContains('outside_margin shows the change percent', outside, '۱۲٫۵٪');
    checkContains('outside_margin offers re-acknowledge or another reference', outside, 'قیمت تازه را تأیید کنید یا مرجع دیگری انتخاب کنید');
    const missing = sandbox.workbenchNextStep(cardProduct('reference_missing', '', true));
    checkContains('reference_missing offers another reference or no reference', missing, 'مرجع دیگری انتخاب کنید یا بدون مرجع ادامه دهید');
    const unavailable = sandbox.workbenchNextStep(cardProduct('price_unavailable', '', true));
    checkContains('price_unavailable offers another reference or no reference', unavailable, 'مرجع دیگری انتخاب کنید یا بدون مرجع ادامه دهید');
    // reference_identity_changed sets no update_needed server-side, so the
    // card's pending action is 'next' — the line must still fire there.
    const identity = sandbox.workbenchNextStep(cardProduct('reference_identity_changed', '', false));
    check('identity_changed card is otherwise a dead end (action next)',
        sandbox.workbenchActionKey(cardProduct('reference_identity_changed', '', false)), 'next');
    checkContains('identity_changed names the new reference and the options', identity, 'مرجع تازه را بررسی و تأیید کنید یا مرجع دیگری انتخاب کنید');
    // The same state also fires through the references queue.
    const identityRef = sandbox.workbenchNextStep(
        cardProduct('reference_identity_changed', '', false, [{ case_id: 'c1', signature: 's1' }]), 'references');
    checkContains('identity_changed fires through the references queue', identityRef, 'مرجع تازه را بررسی و تأیید کنید');
    // (b) an approved reference shows no such line (regression).
    const approved = sandbox.workbenchNextStep(cardProduct('approved_within_margin', '', false));
    check('approved reference shows the generic no-action line', approved, 'برای این کالا اقدام بازی ثبت نشده است.');
    const plain = sandbox.workbenchNextStep(cardProduct('', '', false));
    check('a card with no approval state is unchanged', plain, 'برای این کالا اقدام بازی ثبت نشده است.');
    // (c) the market price-change line.
    const line = sandbox.marketPriceChangeLine(changed);
    checkContains('changed market source renders the price-change line', line, 'قیمت این فروشنده از');
    checkContains('price-change line shows the old price', line, '۹۰٬۰۰۰ تومان');
    checkContains('price-change line shows the new price', line, '۹۵٬۰۰۰ تومان');
    checkContains('price-change line names the change', line, 'تغییر کرد');
    check('no change row renders nothing', sandbox.marketPriceChangeLine(null), '');
    check('an unchanged price renders nothing',
        sandbox.marketPriceChangeLine({ old_price: 95000, regular_price: 95000, occurred_at: 1764200000 }), '');
    check('a missing price renders nothing',
        sandbox.marketPriceChangeLine({ old_price: null, regular_price: 95000, occurred_at: 1764200000 }), '');
    // (d) the Basalam sanity-flag line on market quotes.
    checkContains('reject-tier quote renders the below-cost warning',
        sandbox.marketQuoteSanityLine({ sanity_flag: 'regular_at_or_below_cost', implied_spread: -0.05 }),
        'برابر یا کمتر از قیمت فروش است');
    checkContains('reject-tier line says the quote is not trusted',
        sandbox.marketQuoteSanityLine({ sanity_flag: 'regular_at_or_below_cost', implied_spread: -0.05 }),
        'قابل اعتماد نیست');
    checkContains('reject-tier line shows the implied spread',
        sandbox.marketQuoteSanityLine({ sanity_flag: 'regular_at_or_below_cost', implied_spread: -0.05 }),
        'حاشیهٔ ضمنی');
    checkContains('thin-spread quote renders the under-12% line',
        sandbox.marketQuoteSanityLine({ sanity_flag: 'spread_too_thin', implied_spread: 0.08 }),
        'کمتر از ۱۲٪ است');
    check('an unflagged quote renders nothing', sandbox.marketQuoteSanityLine({}), '');
    check('a quote without a flag renders nothing', sandbox.marketQuoteSanityLine({ sanity_flag: undefined }), '');
    // (e) source checks: the feed and the tab wiring.
    checkSource('quote rows render the sanity-flag line', src,
        'marketQuoteSanityLine(quote)');
    checkSource('dashboard renders match_events in the feed', dashboardSrc, "for (const event of [...(report.match_events||[])].reverse())");
    checkSource('feed match rows use match: ids', dashboardSrc, "const id='match:'+event.id;");
    checkSource('feed match rows open the references section', dashboardSrc, "section:'references'");
    checkSource('feed match rows are dismissable', dashboardSrc, 'item.automaticMarket||item.automaticMatch');
    checkSource('feed match rows label price changes', dashboardSrc, "price_changed:'قیمت مرجع تغییر کرد'");
    checkSource('workflows renders the change line under the selected quote', src,
        'marketPriceChangeLine(result.price_change)');
    checkSource('workflows labels the identity-changed state', src,
        "reference_identity_changed:'هویت مرجع تغییر کرده'");
} else {
    // Negative control: the same assertions must show the behaviour ABSENT.
    const outside = sandbox.workbenchNextStep(cardProduct('outside_margin', 12.5, true));
    check('NEGATIVE outside_margin shows no re-acknowledge line on base',
        outside.includes('قیمت تازه را تأیید کنید یا مرجع دیگری انتخاب کنید'), false);
    const identity = sandbox.workbenchNextStep(cardProduct('reference_identity_changed', '', false));
    check('NEGATIVE identity_changed is a dead end on base',
        identity, 'برای این کالا اقدام بازی ثبت نشده است.');
    check('NEGATIVE marketPriceChangeLine renders nothing on base',
        sandbox.marketPriceChangeLine(changed), '');
    check('NEGATIVE marketQuoteSanityLine renders nothing on base',
        sandbox.marketQuoteSanityLine({ sanity_flag: 'regular_at_or_below_cost', implied_spread: -0.05 }), '');
    checkAbsent('NEGATIVE workflows has no sanity-flag helper on base', src, 'marketQuoteSanityLine');
    checkAbsent('NEGATIVE dashboard has no match_events on base', dashboardSrc, 'match_events');
    checkAbsent('NEGATIVE workflows has no price-change helper on base', src, 'marketPriceChangeLine');
    checkAbsent('NEGATIVE workflows has no identity-changed label on base', src, 'reference_identity_changed');
}

console.log(fail === 0 ? `PASS all (${pass} checks)` : `FAIL ${fail} of ${pass + fail} checks`);
process.exit(fail === 0 ? 0 : 1);
