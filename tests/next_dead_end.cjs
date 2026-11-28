// The 'next' dead end (G4, owner request 2026-11-26): Node harness.
// Run: node tests/next_dead_end.cjs
// When a product's primary action is 'next' (NO queue matches) the card
// showed NO next-step line at all — no path-forward line, no button. It is
// the only state with literally no path. G4 gives an unpublished 'next'
// product a first-view line «وضعیت این کالا روشن نیست — دلیل را بررسی
// کنید.» with a primary «بررسی انتشار» button opening the status tab, plus
// «درخواست انتشار» when the gates look clear. A published+purchasable
// product shows no line (unchanged).
// Extracts the real routing functions from includes/owner-workflows.js and
// the real G4 decision helpers from includes/owner-dashboard.js by brace
// matching, evaluates them against stubbed module state, and asserts:
//   (a) an unpublished 'next' product is a dead end and is publishable when
//       it has an offer, no blocker and no hold — the G4 line shows with
//       both buttons; without an offer (or with a hold) only «بررسی انتشار»;
//   (b) a published+purchasable product is NOT a dead end (no line);
//   (c) a published+held product still routes to the hold's cause
//       (regression — the G3 harnesses are re-run separately and must pass);
//   (d) no other card state changed its routing — workflows.js is
//       byte-identical to the base (a78212a), and the routing decisions for
//       a set of fixtures are identical between base and current.
// The routing functions' orthogonal leaves (dismissals, Woo state, source
// links) are stubbed to neutral values, identically for both versions, so a
// stub-induced error cancels out of the differential comparison. The DOM
// rendering itself is verified live (source checks here pin the exact text,
// button labels, tab push and fill branch). No browser, no jsdom.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const crypto = require('crypto');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

const BASE = 'a78212a';
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

const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];

// Workflows sandbox: the real router, as in the G3 harnesses.
function buildWorkflowsSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    const hasG1 = source.includes('function workbenchNeedsPriceRegistration(');
    if (hasG1) pieces.push(extractFunction(source, 'workbenchNeedsPriceRegistration'));
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

// Dashboard sandbox: the real G4 decision helpers. model.primaryAction is
// fed the real router's answer per fixture, so the decision is end-to-end:
// the router says 'next' -> the dashboard says dead end.
const DASHBOARD_FNS = ['next', 'published', 'priceBlocked', 'nextDeadEnd', 'nextDeadEndPublishable'];
function buildDashboardSandbox(source) {
    const pieces = DASHBOARD_FNS.map(name => extractFunction(source, name));
    const code = `
let model = null;
${pieces.join('\n')}
return {
    setModel(m) { model = m; },
    nextDeadEnd, nextDeadEndPublishable, next, published,
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

// Wire a fixture's real router answer into the dashboard model stub.
function wire(product, data, hold) {
    wf.setData(data);
    const actionKey = wf.workbenchActionKey(product);
    dash.setModel({
        primaryAction: () => actionKey,
        readyAction: () => '',
        autopilotHold: () => hold || null,
        requestPublication: async () => {},
    });
    return actionKey;
}

const emptyData = { autopilot: { holds: [] } };

// --- Fixtures ---
// G4 main case: unpublished, no queue matches, no hold, has an offer.
// Nothing is wrong on paper, yet the card showed no path at all.
const G4_ID = 'g4-1';
const g4 = withStatus(baseProduct({ product_id: G4_ID, key: G4_ID, name: 'کالای بی‌اقدام' }), {
    sale_offer_blocker: '', publication_needed: false, update_needed: false,
    regular_price_missing: false, latest_offer_id: 'offer-g4',
});

// Same, but with NO latest offer — nothing to publish yet.
const g4NoOffer = withStatus(baseProduct({ product_id: 'g4-2', key: 'g4-2', name: 'کالای بدون پیشنهاد' }), {
    sale_offer_blocker: '', publication_needed: false, update_needed: false,
    regular_price_missing: false, latest_offer_id: '',
});

// Same, but an owner hold stands (no routable cause — the G3 router leaves
// it at 'next', and the hold blocks a publish request).
const g4Held = withStatus(baseProduct({ product_id: 'g4-3', key: 'g4-3', name: 'کالای در توقف مالک' }), {
    sale_offer_blocker: '', publication_needed: false, update_needed: false,
    regular_price_missing: false, latest_offer_id: 'offer-g4-3',
});
const ownerHold = { reason: 'توقف به دستور مالک.' };

// A not_product item matching no queue: a dead end, but never publishable.
const g4NotProduct = withStatus(baseProduct({ product_id: 'g4-4', key: 'g4-4', name: 'کالای ردشده' }), {
    status: 'not_product', sale_offer_blocker: '', publication_needed: false,
    update_needed: false, regular_price_missing: false, latest_offer_id: 'offer-g4-4',
});

// Published+purchasable tuna: no line (unchanged). The G3 harness already
// asserts its router answer stays 'next'; here the dashboard must agree
// it is NOT a dead end.
const tuna = withStatus(baseProduct({ product_id: 'tuna-100', key: 'tuna-100', name: 'تن ماهی خوش خوراک' }), {
    sale_offer_blocker: '', publication_needed: false, regular_price_state: 'current',
    latest_offer_id: 'offer-tuna', published: true,
    step_trace: { autopilot: { state: 'admitted', tier: 'green', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: '', closeness: 0.816, arm: 'regular_expiry' } },
});

// Published+held cappuccino (G3 follow-up case): the router must still
// send it to the hold's cause, and it is NOT a G4 dead end (published).
const CAP_ID = 'cappuccino-105';
const cappuccino = withStatus(baseProduct({ product_id: CAP_ID, key: CAP_ID, name: 'کاپوچینو ۲۰ عددی تیمز' }), {
    sale_offer_blocker: '', publication_needed: false, regular_price_state: 'current',
    latest_offer_id: 'offer-cappuccino', published: true,
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'ok',
        verdict_reason: 'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.',
        reason: 'پراکندگی نقل‌قول‌ها 23.6٪ در برابر بازهٔ 5٪',
        hold_reason: 'کالا در توقف است.', closeness: -3.7207, arm: 'quote_spread' } },
});
const cappuccinoData = { autopilot: { holds: [
    { product_id: CAP_ID, gate: 'market', reason: 'نقل‌قول‌ها 23.6٪ با هم اختلاف دارند (سقف 5٪).', priority: 0.788, score: 0.212, created_at: 1764060000 },
    { product_id: CAP_ID, gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

// A pre-publication publishable card: action key 'status', not a dead end.
const publishable = withStatus(baseProduct({ product_id: 'pub-1', key: 'pub-1', name: 'کالای آمادهٔ انتشار' }), {
    sale_offer_blocker: '', publication_needed: true, regular_price_state: 'current',
    latest_offer_id: 'offer-pub',
});

// --- (a) the G4 main case: dead end, and publishable ---
let key = wire(g4, emptyData, null);
check('(a) G4 product: router still says next', key, 'next');
check('(a) G4 product: nextDeadEnd is true', dash.nextDeadEnd(g4), true);
check('(a) G4 product: nextDeadEndPublishable is true (has offer, no blocker, no hold)',
    dash.nextDeadEndPublishable(g4), true);
check('(a) G4 product: next-step text is still the dead-end text at the router level (dashboard renders the G4 line)',
    wf.workbenchNextStep(g4, 'next'), 'برای این کالا اقدام بازی ثبت نشده است.');

// No offer: dead end, but no direct publish button.
key = wire(g4NoOffer, emptyData, null);
check('(a) G4 no-offer: router still says next', key, 'next');
check('(a) G4 no-offer: nextDeadEnd is true', dash.nextDeadEnd(g4NoOffer), true);
check('(a) G4 no-offer: nextDeadEndPublishable is false (no latest_offer_id)',
    dash.nextDeadEndPublishable(g4NoOffer), false);

// Owner hold: dead end, but the hold blocks a publish request.
key = wire(g4Held, emptyData, ownerHold);
check('(a) G4 held: router still says next (owner hold has no routable cause)', key, 'next');
check('(a) G4 held: nextDeadEnd is true', dash.nextDeadEnd(g4Held), true);
check('(a) G4 held: nextDeadEndPublishable is false (autopilot hold stands)',
    dash.nextDeadEndPublishable(g4Held), false);

// Not a product: dead end, never publishable.
key = wire(g4NotProduct, emptyData, null);
check('(a) G4 not_product: router still says next', key, 'next');
check('(a) G4 not_product: nextDeadEnd is true', dash.nextDeadEnd(g4NotProduct), true);
check('(a) G4 not_product: nextDeadEndPublishable is false',
    dash.nextDeadEndPublishable(g4NotProduct), false);

// --- (b) published+purchasable: NOT a dead end ---
key = wire(tuna, emptyData, null);
check('(b) published tuna: router says next', key, 'next');
check('(b) published tuna: nextDeadEnd is false (published — no line)', dash.nextDeadEnd(tuna), false);

// --- (c) published+held cappuccino: still routed to the hold's cause ---
key = wire(cappuccino, cappuccinoData, { reason: 'کالا در توقف است.' });
check('(c) published cappuccino: router still says market_prices', key, 'market_prices');
check('(c) published cappuccino: nextDeadEnd is false (published)', dash.nextDeadEnd(cappuccino), false);
check('(c) published cappuccino: next-step text is still the market-hold text',
    wf.workbenchNextStep(cappuccino, 'next'), 'نقل‌قول‌های بازار همخوان نیستند؛ یک نقل‌قول را انتخاب کنید.');

// --- (c) publishable card: not a dead end ---
key = wire(publishable, emptyData, null);
check('(c) publishable card: router says status', key, 'status');
check('(c) publishable card: nextDeadEnd is false', dash.nextDeadEnd(publishable), false);

// --- (d) differential vs the base: routing is unchanged ---
// workflows.js was byte-identical to the a78212a base when G4 landed (G4
// touches only the dashboard). Later copy-only additions legitimately
// change it — the G9 fast-confirm text and the G5 wait-state line (both
// additive, both covered by tests/fast_confirm_wait_copy.cjs). The routing
// is proven unchanged by the differential cases below. The diff vs the
// G4 commit (0862562, this branch's parent) was additive-only until the
// G1 follow-up moved the register form out of the supplier_prices section
// into the extracted workbenchPriceRegisterSection helper (and rewrote the
// source_integrity next-step line as a braced block) — so deletions are
// allowed ONLY when they are moved lines: every deleted line must still
// exist in the current file (the helper renames the `section` container to
// `container`, so the comparison normalises that), except the two named
// rewrites (the append that moved to the caller, and the source_integrity
// one-liner whose return text is preserved in the new block).
let wfMoved = null;
try {
    const wfDiff = execSync('git diff 0862562 -- shop-wp/molavi-shop/includes/owner-workflows.js', { cwd: repoRoot, encoding: 'utf8' });
    const wfDeleted = wfDiff.split('\n').filter(line => line.startsWith('-') && !line.startsWith('---'))
        .map(line => line.slice(1).trim()).filter(Boolean);
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
        // FIX-4 rewrote the G1 register form (optional `options` parameter:
        // title/prefill/submit/hint/note) and the supplier-tab verify guard,
        // and rewrote the unverified first-view line with the deadline fact
        // — the old one-liners survive only in their new wording, so they
        // are named rewrites, not lost lines. Covered by
        // tests/every_card_paths.cjs.
        "unverified:'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد.'})[product.status?.regular_price_state]||'قیمت عادی را بررسی کنید.';",
        'verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices;',
        "register.appendChild(node('summary', 'ثبت قیمت از پیام'));",
        'const storedPrice = Number(offer.sale_price);',
        "registerForm.appendChild(node('p', 'اگر قیمت را نمی‌دانید، پیام تازه از تأمین‌کننده بخواهید.', 'molavi-price-register-hint'));",
        "const registerSubmit = node('button', 'ثبت قیمت و تأیید', 'button button-primary');",
        "const registerNote = url !== '' ? `مالک قیمت را از پیام ثبت کرد؛ منبع: ${url}` : 'مالک قیمت را از پیام ثبت کرد.';",
        "if (!offer.acknowledged && sourceRevision) verify.addEventListener('click', async () => {",
        // Slice 3 (persistent matching) rewrote the three workbenchNextStep
        // one-liners so a reconcile-written Reference Approval State names
        // its path forward: the old texts survive as the fallbacks inside
        // the state-aware returns. Covered by
        // tests/match_feed_digest_card.cjs.
        "if (key === 'references') return 'مرجع درست و مبنای قیمت را انتخاب کنید.';",
        "if (key === 'status') return product.status?.review_reason",
        '? aiReviewReason(product.status.review_reason)',
        ": 'مشخصات و قیمت‌های فعلی را بازبینی کنید تا درخواست انتشار بررسی شود.';",
        "return 'برای این کالا اقدام بازی ثبت نشده است.';",
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
        lost: wfDeleted.filter(line => !wfRewritten.includes(line) && !wfRemoved.includes(line) && !currentWf.includes(line)),
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
if (wfBase) {
    const cases = [
        ['G4 product', g4, emptyData],
        ['G4 no-offer', g4NoOffer, emptyData],
        ['G4 held', g4Held, emptyData],
        ['G4 not_product', g4NotProduct, emptyData],
        ['published tuna', tuna, emptyData],
        ['published cappuccino', cappuccino, cappuccinoData],
        ['publishable', publishable, emptyData],
    ];
    for (const [label, product, data] of cases) {
        wf.setData(data);
        wfBase.setData(data);
        check(`(d) differential: ${label} action key identical to base`,
            wf.workbenchActionKey(product), wfBase.workbenchActionKey(product));
        check(`(d) differential: ${label} next-step text identical to base`,
            wf.workbenchNextStep(product, 'next'), wfBase.workbenchNextStep(product, 'next'));
    }
} else {
    console.log('SKIP (d) routing differential — base unavailable');
}

// --- (a/b) first view + tab bar + fill: source checks ---
checkSource('(a) first view: G4 line text is present',
    dashboardSrc, 'وضعیت این کالا روشن نیست — دلیل را بررسی کنید.');
checkSource('(a) first view: G4 branch is gated on the dead-end helper',
    dashboardSrc, '}else if(nextDeadEnd(product)){');
checkSource('(a) first view: «بررسی انتشار» is the primary button opening the status tab',
    dashboardSrc, "button('بررسی انتشار',()=>activate('status'),'md-button md-primary')");
checkSource('(a) first view: «درخواست انتشار» appears only when the gates look clear',
    dashboardSrc, "if (nextDeadEndPublishable(product)) nextLine.append(button('درخواست انتشار'");
checkSource('(a) first view: the direct publish button calls requestPublication',
    dashboardSrc, 'await model.requestPublication(product);');
checkSource('(a) first view: the G4 branch runs before the generic non-next branch',
    dashboardSrc, '}else if(nextDeadEnd(product)){');
{
    const g4At = dashboardSrc.indexOf('}else if(nextDeadEnd(product)){');
    const genericAt = dashboardSrc.indexOf("}else if(preferred!=='next'){");
    check('(a) first view: G4 branch precedes the generic branch in source order', g4At > 0 && genericAt > g4At, true);
}
checkSource('(a) tab bar: a G4 product keeps its status tab',
    dashboardSrc, "if (nextDeadEnd(product)&&!productSections.some(([key])=>key==='status')) productSections.push(['status','انتشار']);");
checkSource('(a) fill: the G4 status tab renders the publication check',
    dashboardSrc, "if (key==='status' && !hasAction && nextDeadEnd(product)) {");
checkSource('(a) fill: the G4 tab shows the hold-disabled button when a hold stands',
    dashboardSrc, 'انتشار متوقف است: ${hold.reason||');
checkSource('(a) fill: the G4 tab falls back to the spec/market checks',
    dashboardSrc, "recoveryAction(product,'بازبینی مشخصات برای انتشار')");
checkSource('(a) helpers: nextDeadEnd is defined',
    dashboardSrc, 'function nextDeadEnd(product)');
checkSource('(a) helpers: nextDeadEndPublishable is defined',
    dashboardSrc, 'function nextDeadEndPublishable(product)');
// The base must NOT have the G4 surface — the differential is real.
if (baseDashboardSrc) {
    checkSourceAbsent('(d) base has no G4 line (differential is real)',
        baseDashboardSrc, 'وضعیت این کالا روشن نیست — دلیل را بررسی کنید.');
    checkSourceAbsent('(d) base has no nextDeadEnd helper (differential is real)',
        baseDashboardSrc, 'function nextDeadEnd(product)');
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
if (fail) process.exit(1);
console.log('ALL NEXT-DEAD-END CHECKS PASSED');
