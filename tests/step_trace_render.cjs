// Step-trace card rendering (owner request 2026-11-25): Node harness.
// Run: node tests/step_trace_render.cjs
// Extracts the real renderStepTrace / stepTraceFactValue (plus the helpers
// they call) from includes/owner-workflows.js by brace matching, evaluates
// them against a minimal DOM stub, and asserts the card section renders the
// trace values. No browser, no jsdom — the functions are pure DOM builders.
'use strict';
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(path.join(__dirname, '..', 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js'), 'utf8');

function extractFunction(name) {
    const start = src.indexOf(`    function ${name}(`);
    if (start < 0) throw new Error('function not found: ' + name);
    const open = src.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) return src.slice(start, i + 1);
        }
    }
    throw new Error('unbalanced braces in ' + name);
}

// Const arrow functions / object literals (the round-six risk helpers and
// the autopilot card renderer are consts, not function declarations).
// Brace counting stays correct: every '{' in a template literal ('${…}')
// or object literal has its matching '}' in the source text.
function extractConst(name) {
    const start = src.indexOf(`const ${name} = `);
    if (start < 0) throw new Error('const not found: ' + name);
    const open = src.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < src.length; i++) {
        if (src[i] === '{') depth++;
        else if (src[i] === '}') {
            depth--;
            if (depth === 0) {
                let end = i + 1;
                if (src[end] === ';') end++;
                return src.slice(start, end);
            }
        }
    }
    throw new Error('unbalanced braces in ' + name);
}

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
        click() { return Promise.all((this.listeners.click || []).map((fn) => fn())); },
        appendChild(c) { this.children.push(c); return c; },
        append(...items) { items.forEach((item) => this.children.push(item)); return this; },
        setAttribute() {},
    };
    return el;
}
const document = { createElement: makeEl, createElementNS: (_ns, tag) => makeEl(tag) };

const fns = ['node', 'amount', 'reviewFacts', 'formatSourceTimestamp', 'stepTraceFactValue', 'renderStepTrace']
    .map(extractFunction).join('\n');
const sandbox = new Function('document', `${fns}\nreturn {node, amount, reviewFacts, formatSourceTimestamp, stepTraceFactValue, renderStepTrace};`);
const { node, amount, reviewFacts, formatSourceTimestamp, stepTraceFactValue, renderStepTrace } = sandbox(document);

// --- Autopilot card sandbox (owner request 2026-11-26) ---
// The card renderer and the history rows it builds call the autopilot API
// (request) and refresh the dashboard (dashboard); both are stubs that
// record their calls. 'data' carries the holds list (for the owner-held
// check). errorText is the real helper's shape (a plain message string).
const requestCalls = [];
const dashboardCalls = [];
const request = async (method, body, _url, apiKey) => {
    requestCalls.push({ method, body, apiKey });
    return {};
};
const dashboard = async (message, kind, keys) => {
    dashboardCalls.push({ message, kind, keys });
};
const errorText = (code) => `خطا: ${code}`;
const autoFns = [
    ...['node', 'reviewFacts', 'formatSourceTimestamp'].map(extractFunction),
    ...['autopilotRiskLabels', 'autopilotGateLabels', 'autopilotRuleLabels', 'autopilotActionLabels', 'autopilotVerdictLabels',
        'autopilotRiskTier', 'autopilotRiskIcon', 'autopilotRiskBadge', 'autopilotStateExplanation', 'autopilotWaitLine',
        'renderAutopilotHistoryRow', 'renderAutopilotCard'].map(extractConst),
].join('\n');
const autoSandbox = new Function('document', 'data', 'request', 'dashboard', 'errorText',
    `${autoFns}\nreturn {renderAutopilotCard, renderAutopilotHistoryRow, autopilotStateExplanation};`);
const autoData = { autopilot: { holds: [] } };
const { renderAutopilotCard, renderAutopilotHistoryRow, autopilotStateExplanation } = autoSandbox(document, autoData, request, dashboard, errorText);

let passed = 0;
function check(condition, label) {
    if (!condition) throw new Error('FAIL ' + label);
    passed++;
    console.log('PASS ' + label);
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
    // Exact match: 'رفع توقف پذیرش' (release) contains 'توقف پذیرش'
    // (hold) as a substring, so a substring match would count it twice.
    return findAll(el, 'BUTTON').filter((b) => textOf(b) === label);
}

// --- stepTraceFactValue formats each kind ---
check(stepTraceFactValue(['قیمت', 190000, 'amount']).includes('۱۹۰٬۰۰۰') && stepTraceFactValue(['قیمت', 190000, 'amount']).includes('تومان'), 'amount formats fa-IR + تومان');
check(stepTraceFactValue(['اختلاف', 0.3448, 'pct']).includes('۳۴') && stepTraceFactValue(['اختلاف', 0.3448, 'pct']).includes('٪'), 'pct formats as percent');
check(stepTraceFactValue(['تعداد', 3, 'int']) === '۳', 'int formats fa-IR');
check(stepTraceFactValue(['وضعیت', 'جاری', 'text']) === 'جاری', 'text passes through');
check(stepTraceFactValue(['تاریخ', null, 'date']) === 'ثبت نشده', 'missing date says ثبت نشده');
check(stepTraceFactValue(['قیمت', 'ثبت نشده', 'amount']) === 'ثبت نشده', 'missing amount passes through');
const dated = stepTraceFactValue(['تأیید شده در', '2026-11-24T09:00:00+00:00', 'date']);
check(dated !== '2026-11-24T09:00:00+00:00' && dated.length > 0, 'date formats fa-IR, not raw ISO');

// --- renderStepTrace on a tuna-shaped trace (as the PHP produces it) ---
const tunaTrace = {
    product_id: 'p_bae3dc55fca5c1df9d6383f0',
    generated_at: '2026-11-25T00:00:00+00:00',
    steps: [
        { key: 'supplier_price', label: 'قیمت تأمین‌کننده', status: 'ok', status_label: 'جاری',
          facts: [['قیمت ثبت‌شده', 190000, 'amount'], ['روش تأیید', 'تأیید مالک', 'text'], ['بررسی لینک', 'بدون تغییر', 'text']], note: null },
        { key: 'regular_price', label: 'قیمت عادی', status: 'ok', status_label: 'جاری',
          facts: [['قیمت عادی', 290000, 'amount'], ['اختلاف با قیمت فروش', 0.3448, 'pct']], note: null },
        { key: 'market_agreement', label: 'توافق بازار', status: 'ok', status_label: 'همخوان',
          facts: [['تعداد نقل‌قول', 2, 'int'], ['اختلاف ضمنی', 0.345, 'pct']], note: null },
        { key: 'availability', label: 'موجودی', status: 'ok', status_label: 'موجود',
          facts: [['وضعیت اعلام‌شده', 'نامشخص (در پیام ذکر نشده)', 'text']], note: 'پیام تأمین‌کننده مبناست؛ کالا همیشه موجود است.' },
        { key: 'admission', label: 'پذیرش خودکار (gA)', status: 'ok', status_label: 'پذیرفته شد',
          facts: [['نتیجه', 'پذیرفته شد (زنده)', 'text'], ['نزدیکی به آستانه', 0.8593, 'pct'], ['درجهٔ ریسک', 'کم‌ریسک', 'text']], note: null },
        { key: 'verdict', label: 'داوری', status: 'ok', status_label: 'تأیید شد',
          facts: [['داوری', 'تأیید شد', 'text']], note: null },
        { key: 'publication', label: 'انتشار / فروشگاه', status: 'ok', status_label: 'منتشر شده',
          facts: [['وضعیت فروشگاه', 'منتشر شده', 'text'], ['شناسهٔ ووکامرس', 100, 'int']], note: null },
    ],
};
const details = renderStepTrace(tunaTrace);
check(details !== null, 'renderStepTrace returns a section');
check(details.tagName === 'DETAILS', 'trace is a <details> element');
check(details.open === false, 'trace is collapsed by default');
check(details.className.includes('molavi-step-trace'), 'trace carries molavi-step-trace class');
const summary = findAll(details, 'SUMMARY')[0];
check(summary && textOf(summary).includes('مراحل انتشار این کالا'), 'summary names the trace');
check(summary && textOf(summary).includes('منتشر شده'), 'summary shows the publication state');
check(summary && textOf(summary).includes('۷ سبز'), 'summary counts the ok steps');
const sections = findAll(details, 'SECTION');
check(sections.length === 7, 'seven step blocks rendered');
const headings = findAll(details, 'H4');
check(headings.length === 7, 'seven step headings');
const badges = findAll(details, 'SPAN').filter((s) => (s.className || '').includes('molavi-risk-badge'));
check(badges.length === 7, 'seven status badges');
check(badges.every((b) => (b.className || '').includes('molavi-risk-green')), 'all tuna badges green');
const body = textOf(details);
check(body.includes('۱۹۰٬۰۰۰') && body.includes('تومان'), 'tuna sale price rendered as amount');
check(body.includes('۲۹۰٬۰۰۰'), 'tuna regular price rendered');
check(body.includes('۳۴٫۵٪') || body.includes('۳۴٫۴۸٪') || body.includes('۳۴٫۵'), 'tuna spread rendered as percent');
check(body.includes('پذیرفته شد (زنده)'), 'tuna admission result rendered');
check(body.includes('۸۵٫۹'), 'tuna closeness rendered as percent');
check(body.includes('۱۰۰'), 'tuna woo id rendered');
check(body.includes('پیام تأمین‌کننده مبناست'), 'availability note rendered');

// --- A blocked product shows red badges ---
const blockedTrace = JSON.parse(JSON.stringify(tunaTrace));
blockedTrace.steps[4] = { key: 'admission', label: 'پذیرش خودکار (gA)', status: 'bad', status_label: 'مسدود',
    facts: [['توقف فعلی', 'اختلاف قیمت برای تخفیف کافی نیست.', 'text']], note: 'یک بازوی پذیرش رد شد.' };
blockedTrace.steps[5] = { key: 'verdict', label: 'داوری', status: 'info', status_label: 'ثبت نشده',
    facts: [['داوری', 'هنوز داوری ثبت نشده', 'text']], note: null };
blockedTrace.steps[6] = { key: 'publication', label: 'انتشار / فروشگاه', status: 'warn', status_label: 'پیش‌نویس',
    facts: [['وضعیت فروشگاه', 'پیش‌نویس / پنهان (رکورد ووکامرس هست، منتشر نیست)', 'text'], ['مانع انتشار', 'اختلاف قیمت برای تخفیف کافی نیست', 'text']], note: null };
const blocked = renderStepTrace(blockedTrace);
const blockedBadges = findAll(blocked, 'SPAN').filter((s) => (s.className || '').includes('molavi-risk-badge'));
check(blockedBadges.some((b) => (b.className || '').includes('molavi-risk-red')), 'blocked admission shows a red badge');
check(blockedBadges.some((b) => (b.className || '').includes('molavi-risk-none')), 'no-verdict step shows a neutral badge');
check(textOf(blocked).includes('اختلاف قیمت برای تخفیف کافی نیست'), 'blocker reason rendered');

// --- Empty / missing trace renders nothing ---
check(renderStepTrace(null) === null, 'null trace renders nothing');
check(renderStepTrace({ steps: [] }) === null, 'empty trace renders nothing');

// --- renderAutopilotCard on a tuna-shaped product (live admitted) ---
// The autopilot summary is the shape the PHP trace builder returns
// (see tests/native_product_step_trace.php): state, tier, verdict, the
// one-line reason, and the decision history.
const tunaAuto = {
    active: true, master_on: true, rule_mode: 'live', mode_label: 'فعال — پذیرش زنده',
    state: 'admitted', state_label: 'پذیرفته شد (زنده)',
    tier: 'green', tier_label: 'کم‌ریسک',
    verdict: 'ok', verdict_label: 'تأیید شد',
    verdict_reason: 'قیمت مصرف‌کننده و تأمین‌کننده با قوانین حاشیه سود مطابقت دارد و انتشار محصول تأیید می‌شود.',
    reason: 'نزدیک‌ترین آستانه: انقضای قیمت عادی — حاشیه 0.859',
    hold_reason: '', closeness: 0.8593, arm: 'regular_expiry', created_at: 1764000000,
    history: [{
        id: 31, rule_id: 'gA', gate: 'admission', product_id: 'p_bae3dc55fca5c1df9d6383f0',
        action: 'live', result: 'admitted', verdict: 'ok',
        verdict_reason: 'قیمت مصرف‌کننده و تأمین‌کننده با قوانین حاشیه سود مطابقت دارد و انتشار محصول تأیید می‌شود.',
        confidence: 0.8593, created_at: 1764000000, reviewed_at: 0, reviewed_by: '',
        inputs: { spread: 0.3448, supplier_price: 190000, regular_price: 290000, closeness: 0.8593, closeness_arm: 'regular_expiry', risk_tier: 'green' },
        risk_tier: 'green', risk_tier_stored: 'green',
        risk_reason: 'نزدیک‌ترین آستانه: انقضای قیمت عادی — حاشیه 0.859',
    }],
};
const tunaProduct = { product_id: 'p_bae3dc55fca5c1df9d6383f0', status: { step_trace: { product_id: 'p_bae3dc55fca5c1df9d6383f0', steps: [], autopilot: tunaAuto } } };
const tunaCard = renderAutopilotCard(tunaProduct);
check(tunaCard !== null, 'card renders an autopilot section for a traced product');
check(tunaCard.tagName === 'SECTION' && tunaCard.className.includes('molavi-autopilot-state'), 'autopilot section is a section with the right class');
const tunaCardText = textOf(tunaCard);
check(tunaCardText.includes('خلبان خودکار'), 'card heading names the autopilot');
check(tunaCardText.includes('فعال — پذیرش زنده'), 'card shows the live mode label');
check(tunaCardText.includes('پذیرفته شد (زنده)'), 'card shows the admitted state');
check(tunaCardText.includes('تأیید شد'), 'card shows the verdict');
check(tunaCardText.includes('انقضای قیمت عادی'), 'card shows the one-line reason (tightest arm)');
const tunaBadge = findAll(tunaCard, 'SPAN').filter((s) => (s.className || '').includes('molavi-risk-badge'));
check(tunaBadge.some((b) => (b.className || '').includes('molavi-risk-green')), 'card badge is green for the tuna');
check(tunaCardText.includes('کم‌ریسک'), 'card badge label is کم‌ریسک');
const tunaHistory = findAll(tunaCard, 'DETAILS')[0];
check(tunaHistory && tunaHistory.open === false, 'history is collapsed by default');
check(textOf(findAll(tunaHistory, 'SUMMARY')[0]).includes('سابقهٔ تصمیم‌ها (۱)'), 'history summary counts one decision');
check(textOf(tunaHistory).includes('بازبینی‌نشده: ۱'), 'history summary counts one unreviewed decision');
check(textOf(tunaHistory).includes('پذیرش کالا') && textOf(tunaHistory).includes('انجام شد'), 'history row names the rule and action');
check(textOf(tunaHistory).includes('۱۹۰٬۰۰۰') && textOf(tunaHistory).includes('۲۹۰٬۰۰۰'), 'history row shows the supplier and regular prices');
check(findButtons(tunaCard, 'بازگردانی پذیرش').length === 1, 'admitted row offers revert');
check(findButtons(tunaCard, 'توقف پذیرش').length === 1, 'admitted row offers hold');
check(findButtons(tunaCard, 'تأیید بازبینی').length === 1, 'unreviewed row offers confirm');
check(findButtons(tunaCard, 'رفع توقف پذیرش').length === 0, 'no release button when nothing is held');

// --- Cappuccino: blocked, verdict flagged, hold stands ---
const cappAuto = {
    active: true, master_on: true, rule_mode: 'live', mode_label: 'فعال — پذیرش زنده',
    state: 'blocked', state_label: 'مسدود',
    tier: 'red', tier_label: 'پرخطر',
    verdict: 'flag', verdict_label: 'پرچم خورد',
    verdict_reason: 'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.',
    reason: 'نزدیک‌ترین آستانه: پراکندگی نقل‌قول‌ها — حاشیه -3.721',
    hold_reason: 'کالا در توقف است.', closeness: -3.7207, arm: 'quote_spread', created_at: 1764000000,
    history: [{
        id: 22, rule_id: 'gA', gate: 'admission', product_id: 'p_cappuccino',
        action: 'shadow', result: 'would_admit', verdict: 'flag',
        verdict_reason: 'قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.',
        confidence: 0.0, created_at: 1763990000, reviewed_at: 0, reviewed_by: '',
        inputs: { spread: 0.2737, supplier_price: 800000, regular_price: 1101520, closeness: -3.7207, closeness_arm: 'quote_spread', risk_tier: 'red' },
        risk_tier: 'red', risk_tier_stored: 'red',
        risk_reason: 'نزدیک‌ترین آستانه: پراکندگی نقل‌قول‌ها — حاشیه -3.721',
    }],
};
const cappProduct = { product_id: 'p_cappuccino', status: { step_trace: { product_id: 'p_cappuccino', steps: [], autopilot: cappAuto } } };
const cappCard = renderAutopilotCard(cappProduct);
const cappCardText = textOf(cappCard);
check(cappCardText.includes('مسدود'), 'cappuccino card shows blocked');
check(cappCardText.includes('پرچم خورد'), 'cappuccino card shows the flagged verdict');
check(cappCardText.includes('قیمت تأمین‌کننده بسیار بالاتر از نقل‌قول‌های بازار است.'), 'cappuccino explanation is the verdict reason (flag wins over the arm reason)');
check(findAll(cappCard, 'SPAN').filter((s) => (s.className || '').includes('molavi-risk-badge')).some((b) => (b.className || '').includes('molavi-risk-red')), 'cappuccino card badge is red');
check(findButtons(cappCard, 'بازگردانی پذیرش').length === 1, 'would_admit row offers revert');
check(findButtons(cappCard, 'توقف پذیرش').length === 1, 'would_admit row offers hold');
check(findButtons(cappCard, 'تأیید بازبینی').length === 1, 'cappuccino unreviewed row offers confirm');

// --- Coffee: no decision row, Arm 7 hold, no verdict, empty history ---
const coffeeAuto = {
    active: true, master_on: true, rule_mode: 'live', mode_label: 'فعال — پذیرش زنده',
    state: 'blocked', state_label: 'مسدود',
    tier: 'none', tier_label: 'بدون درجه‌بندی',
    verdict: '', verdict_label: 'ثبت نشده',
    verdict_reason: '', reason: '', hold_reason: 'اختلاف قیمت برای تخفیف کافی نیست.',
    closeness: null, arm: '', created_at: 0, history: [],
};
const coffeeProduct = { product_id: 'p_coffee_black', status: { step_trace: { product_id: 'p_coffee_black', steps: [], autopilot: coffeeAuto } } };
const coffeeCard = renderAutopilotCard(coffeeProduct);
const coffeeCardText = textOf(coffeeCard);
check(coffeeCardText.includes('مسدود'), 'coffee card shows blocked');
check(coffeeCardText.includes('ثبت نشده'), 'coffee card says no verdict recorded');
check(coffeeCardText.includes('اختلاف قیمت برای تخفیف کافی نیست.'), 'coffee explanation is the hold reason');
check(coffeeCardText.includes('بدون درجه‌بندی'), 'coffee card badge is untiered');
check(coffeeCardText.includes('هنوز تصمیمی برای این کالا ثبت نشده است.'), 'coffee history says no decision recorded');
check(findButtons(coffeeCard, 'بازگردانی پذیرش').length === 0, 'no decision row means no revert button');

// --- No trace, no section; the explanation helper's priorities ---
check(renderAutopilotCard({ product_id: 'p_no_trace', status: {} }) === null, 'product without a trace renders no autopilot section');
check(renderAutopilotCard(null) === null, 'null product renders no autopilot section');
check(autopilotStateExplanation({ verdict: 'flag', verdict_reason: 'دلیل داوری', reason: 'دلیل ریسک', hold_reason: 'دلیل توقف', state: 'blocked', state_label: 'مسدود' }) === 'دلیل داوری', 'explanation prefers the verdict reason when flagged');
check(autopilotStateExplanation({ verdict: 'ok', verdict_reason: '', reason: 'دلیل ریسک', hold_reason: 'دلیل توقف', state: 'admitted', state_label: 'پذیرفته شد (زنده)' }) === 'دلیل ریسک', 'explanation falls back to the risk reason');
check(autopilotStateExplanation({ verdict: '', verdict_reason: '', reason: '', hold_reason: 'دلیل توقف', state: 'blocked', state_label: 'مسدود' }) === 'دلیل توقف', 'explanation falls back to the hold reason');
check(autopilotStateExplanation({ verdict: '', verdict_reason: '', reason: '', hold_reason: '', state: 'not_evaluated', state_label: 'ارزیابی نشده' }) === 'هنوز ارزیابی پذیرش ثبت نشده است.', 'not evaluated says so plainly');

// --- Owner-held product: release instead of a second hold ---
// G7: the card now offers release twice — surfaced under the explanation
// and in the history row. Both call the same release_hold endpoint.
const heldData = { autopilot: { holds: [{ product_id: 'p_cappuccino', gate: 'admission_owner', reason: 'توقف مالک' }] } };
const { renderAutopilotCard: renderCardHeld } = autoSandbox(document, heldData, request, dashboard, errorText);
const cappCardHeld = renderCardHeld(cappProduct);
check(findButtons(cappCardHeld, 'رفع توقف پذیرش').length === 2, 'owner-held card offers release twice: surfaced under the explanation + in the history row');
{
    const outsideHistory = cappCardHeld.children.filter((c) => c.tagName !== 'DETAILS');
    const surfaced = [];
    outsideHistory.forEach((c) => { findButtons(c, 'رفع توقف پذیرش').forEach((b) => surfaced.push(b)); });
    check(surfaced.length === 1, 'surfaced release button sits outside the collapsed history');
    const heldHistory = findAll(cappCardHeld, 'DETAILS')[0];
    check(findButtons(heldHistory, 'رفع توقف پذیرش').length === 1, 'history row keeps its own release button');
}
check(findButtons(cappCardHeld, 'توقف پذیرش').length === 0, 'owner-held row does not offer a second hold');

// --- The tab is gone; the dashboard carries its function ---
const dashSrc = fs.readFileSync(path.join(__dirname, '..', 'shop-wp', 'molavi-shop', 'includes', 'owner-dashboard.js'), 'utf8');
check(!dashSrc.includes("'autopilot','تصمیم‌های خودکار'"), 'nav no longer lists the decisions tab');
check(!dashSrc.includes('molavi-autopilot-decisions'), 'no decisions anchor in the dashboard');
check(!dashSrc.includes("view==='autopilot'"), 'no autopilot view dispatch in the dashboard');
check(dashSrc.includes('model.renderAutopilotCard(product)'), 'product card renders the autopilot section');
check(dashSrc.includes("state.risk") && dashSrc.includes("params.get('risk')"), 'products view carries a risk filter');
check(dashSrc.includes('فقط توقف‌دار'), 'products view has the held-only filter');
check(dashSrc.includes('بازبینی‌نشده'), 'products view shows the unreviewed count');
check(!src.includes('const renderAutopilot ='), 'the decisions tab function is gone from the workflows');
check(!src.includes('autopilotRiskFilter'), 'the tab risk filter state is gone');
const historyRowSrc = extractConst('renderAutopilotHistoryRow');
check(!historyRowSrc.includes('override_log') && !historyRowSrc.includes('suspend_rule'), 'the card history row has no rule-level actions (override/suspend stay in settings)');

// --- The card calls it with the product status trace ---
const callSite = src.includes('status.step_trace ? renderStepTrace(status.step_trace) : null');
check(callSite, 'renderAction calls renderStepTrace with status.step_trace');
const hookSite = src.includes('addWorkflowSection(stepTrace)');
check(hookSite, 'renderAction appends the trace section to the card');

// --- Clicking an action calls the same autopilot endpoint as the tab ---
// The handlers are async (request, then a dashboard refresh), so the
// assertions run in an async block and the final line moves here.
(async () => {
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    await findButtons(tunaCard, 'بازگردانی پذیرش')[0].click();
    check(requestCalls.length === 1 && requestCalls[0].body.action === 'revert_decision' && requestCalls[0].body.log_id === 31, 'revert calls the autopilot revert_decision endpoint with the log id');
    check(requestCalls[0].apiKey === 'autopilot', 'revert calls the autopilot API section');
    check(dashboardCalls.length === 1 && JSON.stringify(dashboardCalls[0].keys) === JSON.stringify(['product_status', 'autopilot', 'operations']), 'revert refreshes product_status + autopilot so the card re-renders');
    check(dashboardCalls[0].message.includes('پذیرش بازگردانده شد'), 'revert reports the outcome');
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    await findButtons(tunaCard, 'توقف پذیرش')[0].click();
    check(requestCalls.length === 1 && requestCalls[0].body.action === 'hold_decision' && requestCalls[0].body.log_id === 31, 'hold calls the autopilot hold_decision endpoint with the log id');
    check(dashboardCalls.length === 1 && dashboardCalls[0].message.includes('متوقف شد'), 'hold reports the outcome');
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    await findButtons(tunaCard, 'تأیید بازبینی')[0].click();
    check(requestCalls.length === 1 && requestCalls[0].body.action === 'confirm_reviewed' && requestCalls[0].body.log_id === 31, 'confirm calls the autopilot confirm_reviewed endpoint with the log id');
    check(dashboardCalls.length === 1 && dashboardCalls[0].message.includes('بازبینی ثبت شد'), 'confirm reports the outcome');
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    // G7: [0] is the surfaced button (appended before the history), [1] is
    // the history-row button. Both call the same endpoint.
    await findButtons(cappCardHeld, 'رفع توقف پذیرش')[0].click();
    check(requestCalls.length === 1 && requestCalls[0].body.action === 'release_hold' && requestCalls[0].body.product_id === 'p_cappuccino', 'surfaced release calls the autopilot release_hold endpoint with the product id');
    check(dashboardCalls.length === 1 && dashboardCalls[0].message.includes('توقف پذیرش رفع شد'), 'surfaced release reports the outcome');
    requestCalls.length = 0;
    dashboardCalls.length = 0;
    await findButtons(cappCardHeld, 'رفع توقف پذیرش')[1].click();
    check(requestCalls.length === 1 && requestCalls[0].body.action === 'release_hold' && requestCalls[0].body.product_id === 'p_cappuccino', 'history-row release still calls the autopilot release_hold endpoint with the product id');
    check(dashboardCalls.length === 1 && dashboardCalls[0].message.includes('توقف پذیرش رفع شد'), 'history-row release still reports the outcome');

    console.log(`ALL STEP-TRACE RENDER CHECKS PASSED (${passed})`);
})().catch((error) => { console.error(error); process.exit(1); });
