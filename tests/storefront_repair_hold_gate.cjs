// Storefront repair hold gate (G10 follow-up, owner request 2026-11-26): Node harness.
// Run: node tests/storefront_repair_hold_gate.cjs
// G10 gave a storefront mismatch its repair button, but with no hold
// exception: «انتشار دوباره» appeared on a held card's storefront tab,
// and clicking it would fail server-side (the round-seven publisher
// flag/hold check blocks re-publication of held products) with no clear
// explanation on the card — a dead end under the every-card rule. Now
// the repair carries the hold: storefrontRepair returns
// {kind:'republish_held', reason} when an autopilot hold stands, and the
// tab renders the button DISABLED with the hold's reason
// («انتشار متوقف است: …»), the G3 pattern for «درخواست انتشار». The
// hold shown is the one the «نیازمند تصمیم» notice already shows, so
// the card cannot disagree with itself. The sku_mismatch
// «بازبینی مشخصات» button is a spec review, not a publish — a hold
// never disables it. No publish path, decision logic, price, hold, rule
// mode, or G10 repair logic for non-held cards changed.
// Extracts the real functions from includes/owner-dashboard.js by brace
// matching, evaluates them against stubbed module state and a minimal DOM
// stub, and asserts:
//   (a) a held card with a not_visible / not_purchasable reconcile result
//       shows «انتشار دوباره» DISABLED with the hold reason, and clicking
//       it calls nothing (no request_publication);
//   (b) a non-held card with the same mismatch shows the button enabled
//       and clicking it calls request_publication with the product;
//   (c) a held card with a sku_mismatch still shows «بازبینی مشخصات»
//       enabled (a hold never disables the spec review);
//   (d) a not_checked card shows no repair button (unchanged);
//   (e) the repair decision itself: held + not_visible -> republish_held
//       with the hold reason (default reason when the hold has none),
//       non-held + not_visible -> republish, held + sku_mismatch -> spec;
//   (f) negative control: the same held fixture through the 4576b70 base
//       shows the ENABLED button (the base had no gate) — the harness
//       would have caught the shipped gap;
//   (g) the change is scoped: the diff vs 4576b70 touches only
//       owner-dashboard.js under shop-wp/, deletes exactly the one helper
//       line, and workflows.js is byte-identical to the base.
// The real fill() storefront branch is executed (not just source-checked):
// the buttons are rendered by the real code and clicked in the harness.
// No browser, no jsdom. Regression re-runs of the G10/G3/G1/G4/G9+G5/
// G7+G8+G11 harnesses are run separately and must pass.
'use strict';
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const dashboardPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const dashboardSrc = fs.readFileSync(dashboardPath, 'utf8');

const BASE = '4576b70';
let baseDashboardSrc = null;
let baseWorkflowsSrc = null;
try {
    baseDashboardSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
    baseWorkflowsSrc = execSync(`git show ${BASE}:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
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

const dash = buildDashboardSandbox(dashboardSrc);
const dashBase = baseDashboardSrc ? buildDashboardSandbox(baseDashboardSrc) : null;

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

// The cappuccino market hold, as the «نیازمند تصمیم» notice shows it.
const HOLD_REASON = 'نقل‌قول‌ها 18.2٪ با هم اختلاف دارند (سقف 5٪).';
const holdObj = { product_id: 'p1', gate: 'market', reason: HOLD_REASON, priority: 0.788, score: 0.212, created_at: 1764060000 };
const holdNoReason = { product_id: 'p1', gate: 'admission', reason: '', priority: 0.9, score: 0.1, created_at: 1764060000 };

// --- (e) the repair decision itself: real storefrontRepair ---
function repairFor(wooState, hold) {
    dash.setModel({
        wooState: () => wooState,
        autopilotHold: (product) => (hold && product.product_id === hold.product_id ? hold : null),
    });
    return dash.storefrontRepair(baseProduct({}));
}
check('(e) repair: held + not_visible -> republish_held with the hold reason',
    repairFor('not_visible', holdObj), { kind: 'republish_held', reason: HOLD_REASON });
check('(e) repair: held + not_purchasable -> republish_held with the hold reason',
    repairFor('not_purchasable', holdObj), { kind: 'republish_held', reason: HOLD_REASON });
check('(e) repair: hold with no reason -> the default reason',
    repairFor('not_visible', holdNoReason), { kind: 'republish_held', reason: 'توقف پذیرش ثبت شده است.' });
check('(e) repair: non-held + not_visible -> republish (unchanged)',
    repairFor('not_visible', null), { kind: 'republish' });
check('(e) repair: non-held + not_purchasable -> republish (unchanged)',
    repairFor('not_purchasable', null), { kind: 'republish' });
check('(e) repair: held + sku_mismatch -> spec, hold never disables the review',
    repairFor('sku_mismatch', holdObj), { kind: 'spec', note: 'شناسهٔ ووکامرس با شناسهٔ منبع فرق دارد' });
check('(e) repair: held + not_checked -> no repair (unchanged)',
    repairFor('not_checked', holdObj), null);
check('(e) repair: held + purchasable -> no repair (unchanged)',
    repairFor('purchasable', holdObj), null);
// The guard: a model without autopilotHold at all (the old G10 harness's
// model) still returns the plain republish — the gate degrades to enabled.
{
    dash.setModel({ wooState: () => 'not_visible' });
    check('(e) repair: model without autopilotHold -> republish (guard)',
        dash.storefrontRepair(baseProduct({})), { kind: 'republish' });
}

// --- (a)/(b)/(c)/(d) the real fill() storefront branch, rendered and clicked ---
function renderStorefront(wooState, hold, sandbox) {
    const calls = { reconcile: [], requestPublication: [], recovery: [], loading: [] };
    const product = baseProduct({});
    const model = {
        wooState: () => wooState,
        wooLabel: (s) => `label:${s}`,
        reconcile: (btn) => { calls.reconcile.push(btn); },
        requestPublication: async (p) => { calls.requestPublication.push(p); },
        renderAction: () => null,
        autopilotHold: (p) => (hold && p.product_id === hold.product_id ? hold : null),
    };
    const state = { panels: new Map() };
    const details = { dataset: {}, children: [], append(c) { this.children.push(c); return c; } };
    sandbox.setModel(model);
    sandbox.setScope({
        el: stubEl, button: stubButton,
        preferred: 'storefront', product, state,
        recoveryAction: (p, label) => { calls.recovery.push([p, label]); return stubButton(label, null); },
        loading: (show, msg, kind) => { calls.loading.push([show, msg, kind]); },
    });
    sandbox.fill('storefront', details);
    return { calls, product, panel: details.children[0] };
}

(async () => {
    // (a) held + not_visible: the disabled republish button + the hold
    // reason notice; clicking it calls nothing.
    {
        const { calls, panel } = renderStorefront('not_visible', holdObj, dash);
        const buttons = findButtons(panel, 'انتشار دوباره');
        check('(a) held + not_visible: republish button shown', buttons.length, 1);
        check('(a) held + not_visible: the button is disabled', buttons[0].disabled, true);
        check('(a) held + not_visible: the hold reason is shown',
            textOf(panel).includes(`انتشار متوقف است: ${HOLD_REASON}`), true);
        check('(a) held + not_visible: the notice has the warning class',
            findAll(panel, 'P').some((p) => p.className === 'notice notice-warning' && textOf(p).includes(HOLD_REASON)), true);
        await buttons[0].click();
        check('(a) held + not_visible: clicking calls nothing (no requestPublication)',
            calls.requestPublication, []);
        check('(a) held + not_visible: no error surfaced', calls.loading, []);
        check('(a) held + not_visible: the check button still works',
            findButtons(panel, 'بررسی وضعیت فروشگاه').length, 1);
    }
    // (a) held + not_purchasable: the same disabled repair.
    {
        const { calls, panel } = renderStorefront('not_purchasable', holdObj, dash);
        check('(a) held + not_purchasable: republish button shown', findButtons(panel, 'انتشار دوباره').length, 1);
        check('(a) held + not_purchasable: the button is disabled',
            findButtons(panel, 'انتشار دوباره')[0].disabled, true);
        check('(a) held + not_purchasable: the hold reason is shown',
            textOf(panel).includes(`انتشار متوقف است: ${HOLD_REASON}`), true);
        await findButtons(panel, 'انتشار دوباره')[0].click();
        check('(a) held + not_purchasable: clicking calls nothing',
            calls.requestPublication, []);
    }
    // (a) held with no reason: the default reason is shown.
    {
        const { panel } = renderStorefront('not_visible', holdNoReason, dash);
        check('(a) held, no reason: the default reason is shown',
            textOf(panel).includes('انتشار متوقف است: توقف پذیرش ثبت شده است.'), true);
        check('(a) held, no reason: the button is disabled',
            findButtons(panel, 'انتشار دوباره')[0].disabled, true);
    }
    // (b) non-held + not_visible: the button is enabled and the click
    // calls request_publication with the product (the G10 behaviour).
    {
        const { calls, product, panel } = renderStorefront('not_visible', null, dash);
        const buttons = findButtons(panel, 'انتشار دوباره');
        check('(b) non-held + not_visible: republish button shown', buttons.length, 1);
        check('(b) non-held + not_visible: the button is enabled', buttons[0].disabled, false);
        check('(b) non-held + not_visible: no hold notice',
            textOf(panel).includes('انتشار متوقف است:'), false);
        await buttons[0].click();
        check('(b) non-held + not_visible: click calls requestPublication with the product',
            calls.requestPublication, [product]);
        check('(b) non-held + not_visible: button disables after the click', buttons[0].disabled, true);
    }
    // (c) held + sku_mismatch: the spec review stays enabled — a hold
    // never disables a review.
    {
        const { calls, panel } = renderStorefront('sku_mismatch', holdObj, dash);
        check('(c) held + sku_mismatch: the mismatch note is shown',
            textOf(panel).includes('شناسهٔ ووکامرس با شناسهٔ منبع فرق دارد'), true);
        const buttons = findButtons(panel, 'بازبینی مشخصات');
        check('(c) held + sku_mismatch: spec-review button shown', buttons.length, 1);
        check('(c) held + sku_mismatch: the spec-review button is enabled', buttons[0].disabled, false);
        check('(c) held + sku_mismatch: no republish button', findButtons(panel, 'انتشار دوباره').length, 0);
        check('(c) held + sku_mismatch: no hold notice (the review needs none)',
            textOf(panel).includes('انتشار متوقف است:'), false);
        check('(c) held + sku_mismatch: recoveryAction called with the product + label',
            calls.recovery.map(([p, label]) => [p.product_id, label]), [['p1', 'بازبینی مشخصات']]);
    }
    // (d) not_checked: only the check button — no repair before a result,
    // held or not.
    {
        const { panel } = renderStorefront('not_checked', holdObj, dash);
        check('(d) held + not_checked: check button shown', findButtons(panel, 'بررسی وضعیت فروشگاه').length, 1);
        check('(d) held + not_checked: no republish button', findButtons(panel, 'انتشار دوباره').length, 0);
        check('(d) held + not_checked: no spec-review button', findButtons(panel, 'بازبینی مشخصات').length, 0);
    }

    // --- (f) negative control: the 4576b70 base has no gate ---
    if (dashBase) {
        const { calls, panel } = renderStorefront('not_visible', holdObj, dashBase);
        const buttons = findButtons(panel, 'انتشار دوباره');
        check('(f) base: held + not_visible shows the button', buttons.length, 1);
        check('(f) base: the button is ENABLED (no gate in the base)',
            buttons[0].disabled, false);
        check('(f) base: no hold notice',
            textOf(panel).includes('انتشار متوقف است:'), false);
        await buttons[0].click();
        check('(f) base: clicking calls requestPublication (the shipped gap)',
            calls.requestPublication.length, 1);
        check('(f) base: the repair decision is the plain republish',
            (() => {
                dashBase.setModel({
                    wooState: () => 'not_visible',
                    autopilotHold: (p) => (p.product_id === holdObj.product_id ? holdObj : null),
                });
                return dashBase.storefrontRepair(baseProduct({}));
            })(), { kind: 'republish' });
    } else {
        console.log('SKIP (f) negative control — base unavailable');
    }

    // --- (g) the change is scoped ---
    checkSource('(g) dashboard.js has the republish_held kind', dashboardSrc, "kind:'republish_held'");
    checkSource('(g) dashboard.js renders the disabled republish button',
        dashboardSrc, "const disabledBtn=button('انتشار دوباره',()=>{},'md-button md-primary');");
    checkSource('(g) dashboard.js shows the hold reason with the G3 wording',
        dashboardSrc, 'انتشار متوقف است: ${repair.reason}');
    checkSource('(g) dashboard.js guards a model without autopilotHold',
        dashboardSrc, 'const hold = model.autopilotHold ? model.autopilotHold(product) : null;');
    check('(g) the republish label appears exactly twice in dashboard.js (enabled + held-disabled)',
        dashboardSrc.split('انتشار دوباره').length - 1, 2);
    if (baseWorkflowsSrc) {
        // The hold-gate fix itself is dashboard-only: the db80c25 workflows
        // blob is byte-identical to the 4576b70 blob. The current file has
        // moved on since (the render-merged-cases fix), so it is not
        // compared to 4576b70 directly.
        let holdGateWorkflowsSrc = null;
        try {
            holdGateWorkflowsSrc = execSync(`git show db80c25:shop-wp/molavi-shop/includes/owner-workflows.js`, { cwd: repoRoot, encoding: 'utf8' });
        } catch (error) {
            console.warn('WARNING: could not read the db80c25 workflows.js blob:', error.message);
        }
        if (holdGateWorkflowsSrc) {
            check('(g) the hold-gate fix left workflows.js byte-identical to 4576b70 (fix is dashboard-only)',
                holdGateWorkflowsSrc, baseWorkflowsSrc);
            // The only workflows.js change since db80c25 is the
            // render-merged-cases fix (3 removed, 15 added) plus the
            // ai-confirm-section-visible fix (2026-11-26: 1 removed,
            // 9 added — the line that hid the confirmation section
            // until the supplier price was verified, replaced by an
            // explanatory comment; the section is now always visible,
            // gated by the disabled confirm buttons) plus the
            // verification-section fix (2026-11-26: 12 removed, 36 added —
            // the «قیمت و موجودی امروز» block is extracted into the
            // buildSourceVerificationSection helper and rendered in BOTH
            // the reference-selection and confirmation paths).
            const wfBaseLines = holdGateWorkflowsSrc.split('\n');
            const wfNewLines = src.split('\n');
            const wfRemoved = wfBaseLines.filter(l => !wfNewLines.includes(l));
            const wfAdded = wfNewLines.filter(l => !wfBaseLines.includes(l));
            check('(g) workflows.js diff vs db80c25: exactly the render-merged-cases + ai-confirm-section-visible + verification-section + source-post-status removals',
                wfRemoved, [
                    "                    parentName:product.name, parentChannel:product.channel, parentImageId:product.ai[0]?.image_file_id",
                    "                    content.replaceChildren(node('p','در حال بررسی مرجع این پیشنهاد…'));",
                    "                        content.replaceChildren(renderCase());",
                    "                        content.replaceChildren(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry,renderCase());",
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
                    "            refreshConfirmationGates();",
                ]);
            check('(g) workflows.js diff vs db80c25: exactly the render-merged-cases + ai-confirm-section-visible + verification-section + detected-vs-confirmed-comparison + source-post-status additions',
                wfAdded, [
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
                    "            // had no confirm/dismiss to offer.)",
                ]);
        }
    }
    try {
        const diffStat = execSync(`git diff ${BASE} --stat -- shop-wp/`, { cwd: repoRoot, encoding: 'utf8' });
        const files = diffStat.split('\n').filter((line) => line.includes('|')).map((line) => line.trim().split(/\s+/)[0]);
        // Since 4576b70, dashboard.js changed only by the hold-gate and
        // workflows.js only by the render-merged-cases fix (proven by the
        // exact-diff check above) — plus, since 2026-11-26, the two PHP
        // files of the detected-vs-confirmed comparison (confirmed_sale_price
        // lookup + $skip entry), and the streamline funnel view's dashboard
        // CSS (.md-streamline* / .md-funnel*), and the two source-post-status
        // PHP files (the link-check attach helper in products.php and the
        // seed + classifier fix in supplier-link-checks.php). The '.../'
        // prefixes are git --stat's middle-truncation of the long PHP paths,
        // in the order --stat emits.
        check('(g) only the two JS files + the dashboard CSS + the two comparison PHP files + the two source-post-status PHP files changed under shop-wp/ since 4576b70', files, [
            'shop-wp/molavi-shop/includes/owner-dashboard.css',
            'shop-wp/molavi-shop/includes/owner-dashboard.js',
            'shop-wp/molavi-shop/includes/owner-workflows.js',
            '.../molavi-domain-ai-confirmations.php',
            '.../runtime-host/molavi-domain-ai-review-model.php',
            '.../runtime-host/molavi-domain-products.php',
            '.../molavi-domain-supplier-link-checks.php',
        ]);
        const dashDiff = execSync(`git diff ${BASE} -- shop-wp/molavi-shop/includes/owner-dashboard.js`, { cwd: repoRoot, encoding: 'utf8' });
        const dashDeleted = dashDiff.split('\n').filter((line) => line.startsWith('-') && !line.startsWith('---'))
            .map((line) => line.slice(1).trim()).filter(Boolean);
        // The hold-gate rewrote one helper line; the streamline funnel view
        // (2026-11-26) rewrote eight more vs the 4576b70 base: the view
        // whitelist, the nav list, the saveURL param-clear list, the
        // scope-button handler, the active-filter chip, the action-select
        // handler, the paint guard, and the click handler — each gained the
        // 'streamline' view / the step+reason filter. Those nine lines, in
        // the order git diff emits them (file order), are the only deletions.
        check('(g) dashboard.js deletions vs the base are exactly the one helper line + the eight streamline-view lines',
            dashDeleted, [
                "view:['products','references','sources','system','pricing'].includes(params.get('view')) ? params.get('view')",
                "[['products','محصولات'],['references','فهرست مرجع'],['sources','پیام‌های منبع'],['system','وضعیت سیستم']].forEach(([key,label])=>{",
                "['view','scope','q','channel','workbench_action','product_id','offer_id','case_id','conflict_id','hold_id','reference_id','risk','held'].forEach(key=>url.searchParams.delete(key));",
                "if (state==='not_visible'||state==='not_purchasable') return {kind:'republish'};",
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
