// Fast-confirm copy + wait-state copy (G9 + G5, owner request 2026-11-26):
// Node harness. Run: node tests/fast_confirm_wait_copy.cjs
// G9 — a supplier-price row whose source link was checked and found
// unchanged (the confirm queue, 46 items) gets a first-view line saying
// the confirm is fast: «لینک منبع بررسی شد؛ قیمت بدون تغییر است.
// با تأمین‌کننده تأیید کنید.» The button stays «تأیید قیمت» (primary).
// G5 — a product that is only waiting gets a line in the autopilot block:
// not_evaluated (no hold, no blocker) → «در تیک بعدی خلبان خودکار ارزیابی
// می‌شود.»; would_admit + gA live + cap remaining → «در تیک بعدی پذیرفته
// می‌شود.»; would_admit + cap reached → «سقف پذیرش امروز پر شده؛ فردا
// پذیرفته می‌شود.»; would_admit + gA shadow / master off → «خلبان خودکار
// خاموش است؛ پذیرش انجام نمی‌شود.» A held, blocked or flagged product is
// unchanged. Copy only — no endpoint, no decision logic.
// Extracts the real functions from includes/owner-workflows.js by brace
// matching, evaluates them against stubbed module state, and asserts:
//   (a) a link-checked 'unchanged' card shows the fast-confirm text with
//       the «تأیید قیمت» button (primary);
//   (b) a not_evaluated card with no hold/blocker shows the next-tick
//       evaluation line;
//   (c) a would_admit card shows the right line for each of the four live
//       states (cap remaining / cap reached / gA shadow / master off);
//   (d) a held/blocked/flagged card is unchanged (no wait line; the hold
//       text wins over the fast-confirm text);
//   (e) regression — the G3/G1/G4 harnesses are re-run separately and must
//       pass; here a DIFFERENTIAL test runs the same fixtures through the
//       base (0862562) file and the new file, and the next-step text must
//       be identical except for the G9 fixture. dashboard.js is
//       byte-identical to the base (no change there).
// The routing functions' orthogonal leaves (dismissals, Woo state, source
// links) are stubbed to neutral values, identically for both versions, so a
// stub-induced error cancels out of the differential comparison. No browser,
// no jsdom.
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

const BASE = '0862562';
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

const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];
const G9_FNS = ['workbenchNeedsPriceRegistration', 'workbenchHasUnchangedLinkCheck'];
const G5_CONSTS = ['autopilotStateExplanation', 'autopilotWaitLine'];

// Build a sandbox holding the extracted functions plus stubbed module
// state. setData(v) installs the same autopilot payload as both
// workbenchData (what the router reads) and data (what autopilotHold and
// renderAutopilotCard read).
function buildSandbox(source) {
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
    for (const name of G5_CONSTS) {
        if (source.includes(`const ${name} = `)) pieces.push(extractConst(source, name));
    }
    const hasG9 = source.includes('function workbenchHasUnchangedLinkCheck(');
    const hasG5 = source.includes('const autopilotWaitLine = ');
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
    ${hasG9 ? 'workbenchHasUnchangedLinkCheck,' : ''}
    ${hasG5 ? 'autopilotStateExplanation, autopilotWaitLine,' : ''}
};`;
    return new Function(code)();
}

const current = buildSandbox(src);
const base = baseWorkflowsSrc ? buildSandbox(baseWorkflowsSrc) : null;

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
function supplierOffer(overrides) {
    return {
        offer_id: 'offer-1', acknowledged: false, source_revision: 'rev-abc123',
        sale_price: 190000, source_post_url: 'https://t.me/channel/123',
        link_check: null,
        ...overrides,
    };
}
const unchangedCheck = {
    outcome: 'unchanged', reason: 'identical', reason_label: 'بدون تغییر',
    checked_at: 1764060000, check_ref: 'lc-1', confirmed_at: 0,
    url: 'https://t.me/channel/123',
};
const emptyData = { autopilot: { holds: [] } };

// --- G9 fixtures: the confirm queue ---
const confirmQueue = withStatus(baseProduct({ product_id: 'cq-1', key: 'cq-1', name: 'کالای تأیید لینک' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-cq1',
});
confirmQueue.supplierPrices = [supplierOffer({ offer_id: 'offer-cq1', link_check: { ...unchangedCheck } })];

const noCheck = withStatus(baseProduct({ product_id: 'nc-1', key: 'nc-1', name: 'کالای بدون بررسی لینک' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-nc1',
});
noCheck.supplierPrices = [supplierOffer({ offer_id: 'offer-nc1', link_check: null })];

const changedCheck = withStatus(baseProduct({ product_id: 'ch-1', key: 'ch-1', name: 'کالای قیمت تغییرکرده' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-ch1',
});
changedCheck.supplierPrices = [supplierOffer({ offer_id: 'offer-ch1',
    link_check: { ...unchangedCheck, outcome: 'changed', live_price: 200000 } })];

const confirmedCheck = withStatus(baseProduct({ product_id: 'cf-1', key: 'cf-1', name: 'کالای تأییدشده' }), {
    sale_offer_blocker: 'supplier_price',
    latest_offer_id: 'offer-cf1',
});
confirmedCheck.supplierPrices = [supplierOffer({ offer_id: 'offer-cf1',
    link_check: { ...unchangedCheck, confirmed_at: 1764061000 } })];

// A supplier hold + an unchanged link check: the hold text must win.
const holdPlusCheck = withStatus(baseProduct({ product_id: 'hc-1', key: 'hc-1', name: 'کالای متوقف با بررسی لینک' }), {
    sale_offer_blocker: '',
    publication_needed: true,
    latest_offer_id: 'offer-hc1',
    step_trace: { autopilot: { state: 'blocked', tier: 'red', verdict: 'ok', verdict_reason: '', reason: '', hold_reason: 'کالا در توقف است.', closeness: 0.5, arm: 'supplier_age' } },
});
holdPlusCheck.supplierPrices = [supplierOffer({ offer_id: 'offer-hc1', link_check: { ...unchangedCheck } })];
const holdPlusCheckData = { autopilot: { holds: [
    { product_id: 'hc-1', gate: 'supplier_price', reason: 'قیمت تأمین‌کننده تغییر کرده است.', priority: 0.5, score: 0.5, created_at: 1764060000 },
    { product_id: 'hc-1', gate: 'admission', reason: 'کالا در توقف است.', priority: 0.9, score: 0.1, created_at: 1764060000 },
] } };

// --- (a) G9: the confirm-queue card shows the fast-confirm text ---
current.setData(emptyData);
check('(a) confirm-queue next-step text', current.workbenchNextStep(confirmQueue, 'next'),
    'لینک منبع بررسی شد؛ قیمت بدون تغییر است. با تأمین‌کننده تأیید کنید.');
check('(a) confirm-queue action key is supplier_prices', current.workbenchActionKey(confirmQueue), 'supplier_prices');
check('(a) confirm-queue ready action is primary', current.workbenchReadyAction(confirmQueue, 'supplier_prices'), 'supplier_prices');
checkSource('(a) first-view button label for supplier_prices', dashboardSrc, "supplier_prices:'تأیید قیمت'");
check('(a) helper detects the unchanged check', current.workbenchHasUnchangedLinkCheck(confirmQueue), true);

// --- (b) G9: no unchanged check, no fast-confirm text ---
check('(b) no link check → generic supplier text', current.workbenchNextStep(noCheck, 'next'),
    'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.');
check('(b) helper is false without a check', current.workbenchHasUnchangedLinkCheck(noCheck), false);
// FIX-4: a changed check now shows the adopt-or-dismiss text instead of
// the G2 generic supplier text — verifying the stored price would write
// false evidence, so the path is adopting the live price.
check('(b) changed check → FIX-4 adopt/dismiss text (not G2 generic, not G9)', current.workbenchNextStep(changedCheck, 'next'),
    'قیمت در لینک منبع تغییر کرده است؛ قیمت تازه را بپذیرید یا بررسی را کنار بگذارید.');
check('(b) helper is false for a changed check', current.workbenchHasUnchangedLinkCheck(changedCheck), false);
check('(b) confirmed check → generic supplier text', current.workbenchNextStep(confirmedCheck, 'next'),
    'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.');
check('(b) helper is false for a confirmed check', current.workbenchHasUnchangedLinkCheck(confirmedCheck), false);

// --- (c) G9: a hold wins over the fast-confirm text ---
current.setData(holdPlusCheckData);
check('(c) hold + unchanged check → hold text, not fast-confirm', current.workbenchNextStep(holdPlusCheck, 'next'),
    'قیمت تأمین‌کننده به بازبینی نیاز دارد؛ منبع فعلی را بررسی و تأیید کنید.');
check('(c) helper still detects the check (text precedence is the router’s)', current.workbenchHasUnchangedLinkCheck(holdPlusCheck), true);
current.setData(emptyData);

// --- G5 fixtures: the autopilot wait line ---
function summary(overrides) {
    return {
        state: 'not_evaluated', state_label: 'ارزیابی نشده',
        tier: 'none', tier_label: 'بدون درجه‌بندی',
        verdict: '', verdict_label: 'ثبت نشده', verdict_reason: '',
        reason: '', hold_reason: '', closeness: null, arm: '',
        active: true, master_on: true, rule_mode: 'live',
        mode_label: 'فعال — پذیرش زنده',
        ...overrides,
    };
}
function productWithBlocker(blocker) {
    return withStatus(baseProduct({ product_id: 'w-1', key: 'w-1' }), { sale_offer_blocker: blocker || '' });
}
const rolloutOpen = { admission_rollout: { stage: 'open', total: 4, today: 3, day: '2026-11-26', cap_per_day: 5, remaining: 2, allowed: true, next_stage: null } };
const rolloutFull = { admission_rollout: { stage: 'open', total: 5, today: 5, day: '2026-11-26', cap_per_day: 5, remaining: 0, allowed: false, next_stage: null } };
const rolloutAllowedOnly = { admission_rollout: { stage: 'open', allowed: true } };
const rolloutBlockedOnly = { admission_rollout: { stage: 'open', allowed: false } };
const wouldAdmit = summary({ state: 'would_admit', state_label: 'آمادهٔ پذیرش (سایه)',
    verdict: 'ok', verdict_label: 'تأیید شد',
    reason: 'نزدیک‌ترین آستانه: انقضای قیمت عادی — حاشیه 0.816',
    closeness: 0.816, arm: 'regular_expiry' });

// --- (d) G5: not_evaluated with no hold/blocker → next-tick line ---
check('(d) not_evaluated, no blocker → next-tick line',
    current.autopilotWaitLine(summary({}), productWithBlocker(''), null),
    'در تیک بعدی خلبان خودکار ارزیابی می‌شود.');
check('(d) not_evaluated + blocker → no line',
    current.autopilotWaitLine(summary({}), productWithBlocker('supplier_price'), null), '');
check('(d) not_evaluated + hold reason → no line',
    current.autopilotWaitLine(summary({ hold_reason: 'کالا در توقف است.' }), productWithBlocker(''), null), '');
check('(d) explanation for not_evaluated is unchanged',
    current.autopilotStateExplanation(summary({})), 'هنوز ارزیابی پذیرش ثبت نشده است.');

// --- (e) G5: would_admit, the four live states ---
check('(e) would_admit + live + cap remaining → next-tick admission',
    current.autopilotWaitLine(wouldAdmit, productWithBlocker(''), rolloutOpen),
    'در تیک بعدی پذیرفته می‌شود.');
check('(e) would_admit + live + cap reached → cap line',
    current.autopilotWaitLine(wouldAdmit, productWithBlocker(''), rolloutFull),
    'سقف پذیرش امروز پر شده؛ فردا پذیرفته می‌شود.');
check('(e) would_admit + master off → autopilot-off line',
    current.autopilotWaitLine({ ...wouldAdmit, active: false, master_on: false, rule_mode: 'live', mode_label: 'خاموش' }, productWithBlocker(''), rolloutOpen),
    'خلبان خودکار خاموش است؛ پذیرش انجام نمی‌شود.');
check('(e) would_admit + gA shadow → autopilot-off line',
    current.autopilotWaitLine({ ...wouldAdmit, active: false, master_on: true, rule_mode: 'shadow', mode_label: 'سایه — فقط ثبت می‌کند' }, productWithBlocker(''), rolloutOpen),
    'خلبان خودکار خاموش است؛ پذیرش انجام نمی‌شود.');
check('(e) would_admit + live + rollout missing → no claim',
    current.autopilotWaitLine(wouldAdmit, productWithBlocker(''), null), '');
check('(e) would_admit + live + allowed-only rollout → next-tick admission',
    current.autopilotWaitLine(wouldAdmit, productWithBlocker(''), rolloutAllowedOnly),
    'در تیک بعدی پذیرفته می‌شود.');
check('(e) would_admit + live + blocked-only rollout → cap line',
    current.autopilotWaitLine(wouldAdmit, productWithBlocker(''), rolloutBlockedOnly),
    'سقف پذیرش امروز پر شده؛ فردا پذیرفته می‌شود.');

// --- (f) G5: held / blocked / flagged / admitted / reverted → no line ---
check('(f) blocked → no line',
    current.autopilotWaitLine(summary({ state: 'blocked', state_label: 'مسدود', hold_reason: 'کالا در توقف است.' }), productWithBlocker(''), rolloutOpen), '');
check('(f) reverted → no line',
    current.autopilotWaitLine(summary({ state: 'reverted', state_label: 'لغو شد', hold_reason: 'کالا در توقف است.' }), productWithBlocker(''), rolloutOpen), '');
check('(f) would_admit + verdict flag → no line',
    current.autopilotWaitLine({ ...wouldAdmit, verdict: 'flag', verdict_reason: 'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.' }, productWithBlocker(''), rolloutOpen), '');
check('(f) admitted → no line',
    current.autopilotWaitLine(summary({ state: 'admitted', state_label: 'پذیرفته شد (زنده)', verdict: 'ok' }), productWithBlocker(''), rolloutOpen), '');
check('(f) no summary → no line',
    current.autopilotWaitLine(null, productWithBlocker(''), rolloutOpen), '');
check('(f) would_admit + blocker → no line',
    current.autopilotWaitLine(wouldAdmit, productWithBlocker('regular_price'), rolloutOpen), '');

// --- (g) G5 is wired into the card, and the CSS exists ---
checkSource('(g) renderAutopilotCard calls autopilotWaitLine', src, 'autopilotWaitLine(summary, product, data?.autopilot)');
checkSource('(g) the wait line is appended with its own class', src, "node('p', waitLine, 'molavi-autopilot-state-wait')");
checkSource('(g) the wait line only appends when non-empty', src, 'if (waitLine) section.appendChild');
checkSource('(g) CSS rule for the wait line', cssSrc, '.molavi-autopilot-state-wait');

// --- (h) G9 text is in the source; the dashboard changed, additively ---
checkSource('(h) G9 text in workbenchNextStep', src, 'لینک منبع بررسی شد؛ قیمت بدون تغییر است. با تأمین‌کننده تأیید کنید.');
// The dashboard is no longer byte-identical to the 0862562 base: G7+G8+G11
// touch it (thin-spread buttons, sale-closed badge/line). The change was
// additive-only vs the 08202e8 base until the G1 follow-up changed exactly
// one dashboard line: the register button's gate (preferred==='supplier_prices'
// → the supplier/source list). The deferred-button follow-up changes exactly
// one more line: the restore button's class becomes conditional
// (primary only when no register button is shown), so the old closing line
// `},'md-button md-primary'));` is the second removed line vs 08202e8.
// Those two lines are the only dashboard lines removed vs the 08202e8 base
// — plus, since the streamline funnel view (2026-11-26), the eight lines
// the view rewrote: the view whitelist, the nav list, the saveURL
// param-clear list, the scope-button handler, the active-filter chip,
// the action-select handler, the paint guard, and the click handler —
// each gained the 'streamline' view / the step+reason filter. The G4
// surface is intact.
// The eight streamline lines, in the order git diff emits them (file
// order); shared by the (h) check below.
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
    console.warn('WARNING: could not run git diff for the (h) removed-lines check:', error.message);
}
if (dashRemoved) {
    // Either the pre-slice-3 list, or that list plus the slice-3 dismiss
    // widening (see the comment above) — nothing else may remove lines.
    const expectedBefore = [STREAMLINE_REMOVED[0], STREAMLINE_REMOVED[1], STREAMLINE_REMOVED[2], STREAMLINE_REMOVED[3], STREAMLINE_REMOVED[4], STREAMLINE_REMOVED[5],
        "},'md-button md-primary'));",
        "const needsPriceRegistration = preferred==='supplier_prices' && model.needsPriceRegistration",
        STREAMLINE_REMOVED[6], STREAMLINE_REMOVED[7]];
    const expectedAfter = [STREAMLINE_REMOVED[0], STREAMLINE_REMOVED[1], "if (item.automaticMarket) {",
        STREAMLINE_REMOVED[2], STREAMLINE_REMOVED[3], STREAMLINE_REMOVED[4], STREAMLINE_REMOVED[5],
        "},'md-button md-primary'));",
        "const needsPriceRegistration = preferred==='supplier_prices' && model.needsPriceRegistration",
        STREAMLINE_REMOVED[6], STREAMLINE_REMOVED[7]];
    const removedJson = JSON.stringify(dashRemoved);
    check('(h) dashboard.js removals vs the 08202e8 base are exactly the old register gate + the restore-button class line + the eight streamline-view lines',
        removedJson === JSON.stringify(expectedBefore) || removedJson === JSON.stringify(expectedAfter), true);
}
checkSource('(h) the deferred restore button class is conditional on the register button',
    dashboardSrc, "},needsPriceRegistration?'md-button':'md-button md-primary'));");
checkSource('(h) the G4 dead-end line is still in the dashboard', dashboardSrc, 'وضعیت این کالا روشن نیست — دلیل را بررسی کنید.');

// --- (i) DIFFERENTIAL: next-step text identical to base except the G9 case ---
if (base) {
    const fixtures = [
        ['confirm-queue (G9)', confirmQueue, 'لینک منبع بررسی شد؛ قیمت بدون تغییر است. با تأمین‌کننده تأیید کنید.'],
        ['no link check', noCheck, 'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.'],
        // FIX-4: a changed check now shows the adopt/dismiss text; the base
        // had the G2 generic text (checked below, like the G9 case).
        ['changed check', changedCheck, 'قیمت در لینک منبع تغییر کرده است؛ قیمت تازه را بپذیرید یا بررسی را کنار بگذارید.'],
        ['confirmed check', confirmedCheck, 'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.'],
    ];
    base.setData(emptyData);
    for (const [label, fixture, expected] of fixtures) {
        const baseText = base.workbenchNextStep(fixture, 'next');
        const newText = current.workbenchNextStep(fixture, 'next');
        check(`(i) differential ${label} — new text is the expected text`, newText, expected);
        if (label === 'confirm-queue (G9)' || label === 'changed check') {
            check(`(i) differential ${label} — base had the generic text`, baseText,
                'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.');
        } else {
            check(`(i) differential ${label} — unchanged from base`, newText, baseText);
        }
    }
    base.setData(holdPlusCheckData);
    current.setData(holdPlusCheckData);
    check('(i) differential hold + check — unchanged from base',
        current.workbenchNextStep(holdPlusCheck, 'next'),
        base.workbenchNextStep(holdPlusCheck, 'next'));
    current.setData(emptyData);
}

console.log(`\n${pass} PASS, ${fail} FAIL`);
process.exit(fail ? 1 : 0);
