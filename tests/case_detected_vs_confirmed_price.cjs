// Detected-vs-confirmed price comparison (beer message 7218).
// The re-extract detects 75000; the owner-confirmed Sale Price is
// 85000. The case as rendered showed the detected 75000 (in the price
// facts) but NO explicit comparison against the confirmed price, so
// the owner could not tell at a glance what changed. The case carries
// no confirmed-price field (actual_price '', consumer_price None);
// the confirmed price is the product record's Sale Price —
// server-side only, not in the JS render context. The fix includes
// it in the case payload (confirmed_sale_price, a WP-side product
// lookup, not extraction content) and renders an explicit comparison
// line in aiCaseCard's price facts — which are built before the
// reference-selection early return, so the line shows in BOTH ai
// paths (fall-through confirmation path AND matching
// reference-selection path after the candidates fetch resolves).
// This harness extracts the REAL renderAction + aiCaseCard (plus
// their transitive closure) from owner-workflows.js, runs them
// against a minimal DOM stub with a controllable request stub, and
// asserts on the DOM (probe-exact beer fixture: candidates_deferred:
// true, reference_candidates: [], snapshot present,
// historical_reextract: true, message_review null, publication_held
// false, holds empty, blocker regular_price; detected 75000,
// confirmed 85000):
//   (a) fall-through path (before the candidates fetch resolves):
//       the comparison line «قیمت شناسایی‌شده در برابر تأییدشده»
//       is present, with ۷۵٬۰۰۰ and ۸۵٬۰۰۰;
//   (b) matching path (after the fetch resolves with ONE NAMED
//       candidate): the comparison line is STILL present, with the
//       reference step «گام فعلی · انتخاب مرجع و مبنای قیمت»
//       present. On the 5a771a8 base file the line is ABSENT in both
//       paths (negative control — the harness auto-detects fixed vs
//       base from the source and asserts the matching outcome, so
//       running it on the base file PASSES by proving the bug, and
//       running it on the fixed file PASSES by proving the fix);
//   (c) control: a case with NO confirmed price renders NO
//       comparison line in either path (unchanged);
//   (d) control: a case where detected == confirmed (75000 vs
//       75000) renders NO comparison line in either path.
// Run: node tests/case_detected_vs_confirmed_price.cjs [workflows.js path]
//   Negative control: git show 5a771a8:shop-wp/molavi-shop/includes/owner-workflows.js > /tmp/workflows-5a771a8.js
//   then node tests/case_detected_vs_confirmed_price.cjs /tmp/workflows-5a771a8.js
'use strict';
const fs = require('fs');
const path = require('path');

const workflowsPath = process.argv[2]
    || path.join(__dirname, '..', 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const isFixed = src.includes('confirmed_sale_price');
console.log('MODE:', isFixed ? 'FIXED (case carries confirmed_sale_price; comparison line renders)' : 'BASE 5a771a8 (negative control: no confirmed price, no comparison line)');

// --- Source extraction -------------------------------------------------
// Lexically aware scanner: strings, template literals (treated as opaque
// — their ${...} interpolations are balanced, so brace depth is unaffected),
// comments, and REGEX literals (a regex may contain quotes and braces,
// e.g. /[!'()*~]/g, which a naive brace counter miscounts).
function isRegexStart(source, slashPos) {
    let j = slashPos - 1;
    while (j >= 0 && /\s/.test(source[j])) j--;
    if (j < 0) return true;
    const prev = source[j];
    if ('(,=:[!&|?{};'.includes(prev)) return true;
    if (/[A-Za-z_$]/.test(prev)) {
        let k = j;
        while (k >= 0 && /[A-Za-z0-9_$]/.test(source[k])) k--;
        const word = source.slice(k + 1, j + 1);
        return ['return', 'typeof', 'case', 'delete', 'void', 'in', 'of',
            'new', 'throw', 'else', 'do', 'yield', 'await'].includes(word);
    }
    return false;
}
// If source[i] starts a regex literal, return the index just past it
// (including flags); otherwise return i.
function skipRegex(source, i) {
    if (source[i] !== '/' || !isRegexStart(source, i)) return i;
    let j = i + 1;
    let inClass = false;
    while (j < source.length) {
        const ch = source[j];
        if (ch === '\\') { j += 2; continue; }
        if (ch === '[') inClass = true;
        else if (ch === ']') inClass = false;
        else if (ch === '/' && !inClass) {
            j++;
            while (j < source.length && /[a-z]/i.test(source[j])) j++;
            return j;
        } else if (ch === '\n') return i; // not a regex after all
        j++;
    }
    return i;
}
// Brace-aware, string/comment/regex-aware scanner. Returns [start, end) of
// the function body for a top-level `function NAME(` (skipping the parameter
// list, which may contain `options = {}` defaults).
function scanBody(source, bracePos) {
    let depth = 0; let quote = null; let esc = false;
    let lineComment = false; let blockComment = false;
    for (let i = bracePos; i < source.length; i++) {
        const ch = source[i]; const nx = source[i + 1] || '';
        if (lineComment) { if (ch === '\n') lineComment = false; continue; }
        if (blockComment) { if (ch === '*' && nx === '/') { blockComment = false; i++; } continue; }
        if (quote) {
            if (esc) esc = false;
            else if (ch === '\\') esc = true;
            else if (ch === quote) quote = null;
            continue;
        }
        if (ch === '/' && nx === '/') { lineComment = true; continue; }
        if (ch === '/' && nx === '*') { blockComment = true; i++; continue; }
        if (ch === '/') { const after = skipRegex(source, i); if (after !== i) { i = after - 1; continue; } }
        if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
        if (ch === '{') depth++;
        else if (ch === '}') { depth--; if (depth === 0) return i + 1; }
    }
    throw new Error('unbalanced braces at ' + bracePos);
}
function extractFunction(source, name) {
    const start = source.indexOf(`    function ${name}(`);
    if (start < 0) throw new Error('function not found: ' + name);
    // Skip the parameter list to its matching ')'.
    let i = source.indexOf('(', start); let depth = 0; let quote = null; let esc = false;
    for (; i < source.length; i++) {
        const ch = source[i];
        if (quote) {
            if (esc) esc = false;
            else if (ch === '\\') esc = true;
            else if (ch === quote) quote = null;
            continue;
        }
        if (ch === '/' && source[i + 1] === '/') { while (i < source.length && source[i] !== '\n') i++; continue; }
        if (ch === '/' && source[i + 1] === '*') { i += 2; while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) i++; i++; continue; }
        if (ch === '/') { const after = skipRegex(source, i); if (after !== i) { i = after - 1; continue; } }
        if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
        if (ch === '(') depth++;
        else if (ch === ')') { depth--; if (depth === 0) break; }
    }
    const open = source.indexOf('{', i);
    return source.slice(start, scanBody(source, open));
}
// Extract a top-level `const NAME = ...;` / `let NAME ...;` statement.
function extractStatement(source, name, kind) {
    const start = source.indexOf(`    ${kind} ${name}`);
    if (start < 0) throw new Error(kind + ' statement not found: ' + name);
    let i = source.indexOf('=', start);
    if (i < 0) { // bare `let NAME;`
        const semi = source.indexOf(';', start);
        return source.slice(start, semi + 1);
    }
    i++;
    let depth = 0; let quote = null; let esc = false;
    for (; i < source.length; i++) {
        const ch = source[i];
        if (quote) {
            if (esc) esc = false;
            else if (ch === '\\') esc = true;
            else if (ch === quote) quote = null;
            continue;
        }
        if (ch === '/' && source[i + 1] === '/') { while (i < source.length && source[i] !== '\n') i++; continue; }
        if (ch === '/' && source[i + 1] === '*') { i += 2; while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) i++; i++; continue; }
        if (ch === '/') { const after = skipRegex(source, i); if (after !== i) { i = after - 1; continue; } }
        if (ch === "'" || ch === '"' || ch === '`') { quote = ch; continue; }
        if (ch === '{' || ch === '[' || ch === '(') depth++;
        else if (ch === '}' || ch === ']' || ch === ')') depth--;
        else if (ch === ';' && depth === 0) return source.slice(start, i + 1);
    }
    throw new Error('unterminated statement: ' + name);
}

// Extract an inner `const NAME = ...;` (any indent — renderAction lives
// inside dashboard(), not at the top level). Handles an arrow-function
// value; returns the full const statement.
function extractInnerConst(source, name) {
    const start = source.indexOf(`const ${name} = `);
    if (start < 0) throw new Error('inner const not found: ' + name);
    // Find the value's opening brace/paren after the '='.
    const eq = source.indexOf('=', start);
    const arrow = source.indexOf('=>', eq);
    const open = source.indexOf('{', arrow < 0 ? eq : arrow);
    const end = scanBody(source, open);
    let stop = end;
    if (source[stop] === ';') stop++;
    return source.slice(start, stop);
}

// Map of top-level definitions (4-space indent inside the module IIFE).
const defs = new Map();
for (const m of src.matchAll(/^    function ([A-Za-z_$][A-Za-z0-9_$]*)\(/gm)) defs.set(m[1], 'fn');
for (const m of src.matchAll(/^    const ([A-Za-z_$][A-Za-z0-9_$]*) = /gm)) defs.set(m[1], 'const');
for (const m of src.matchAll(/^    let ([A-Za-z_$][A-Za-z0-9_$]*)\b/gm)) {
    if (!defs.has(m[1])) defs.set(m[1], 'let');
}

// Names provided by the harness (module-scope state / browser / API stubs),
// never extracted even when a top-level definition exists.
const PROVIDED = new Set([
    'request', 'dashboard', 'config', 'data', 'workbenchData', 'contextStored',
    'workbenchErrors', 'workbenchDismissals', 'workbenchDismissalIndex',
    'aiCasesBySource', 'aiSourceCoverageLead', 'workbenchQueue',
    'workbenchStageModel', 'workbenchWooState', 'safeSourceLink', 'root',
    'document', 'window', 'location', 'navigator', 'localStorage',
    'sessionStorage', 'fetch', 'console',
]);
const JS_KEYWORDS = new Set(['if', 'for', 'while', 'switch', 'catch', 'return',
    'function', 'typeof', 'new', 'await', 'else', 'do', 'const', 'let', 'var',
    'class', 'extends', 'in', 'of', 'instanceof', 'void', 'delete', 'yield',
    'async', 'true', 'false', 'null', 'undefined', 'this']);

// Transitive closure of top-level definitions referenced by the seeds.
// `extra` is an array of pre-extracted pieces {name, text} (for
// renderAction, which is an inner const, not a top-level definition).
function closure(seeds, extra) {
    const seen = new Set();
    const queue = [...seeds];
    const pieces = [];
    const scan = (text) => {
        const locals = new Set();
        const sigEnd = text.indexOf('{');
        if (sigEnd >= 0) {
            const sig = text.slice(0, sigEnd);
            for (const id of sig.matchAll(/[A-Za-z_$][A-Za-z0-9_$]*/g)) locals.add(id[0]);
        }
        for (const m of text.matchAll(/(?:const|let|var|function)\s+([A-Za-z_$][A-Za-z0-9_$]*)/g)) locals.add(m[1]);
        for (const m of text.matchAll(/(?<![.\w$])([A-Za-z_$][A-Za-z0-9_$]*)/g)) {
            const id = m[1];
            if (JS_KEYWORDS.has(id) || locals.has(id) || PROVIDED.has(id)) continue;
            if (defs.has(id) && !seen.has(id)) queue.push(id);
        }
    };
    for (const item of (extra || [])) {
        if (seen.has(item.name)) continue;
        seen.add(item.name);
        pieces.push(item.text);
        scan(item.text);
    }
    while (queue.length) {
        const name = queue.shift();
        if (seen.has(name) || PROVIDED.has(name)) continue;
        const kind = defs.get(name);
        if (!kind) continue; // a global (Intl, URL, ...) or a local — skip
        seen.add(name);
        const text = kind === 'fn' ? extractFunction(src, name)
            : extractStatement(src, name, kind);
        pieces.push(text);
        scan(text);
    }
    return { names: seen, pieces };
}

// --- Minimal DOM stub ---------------------------------------------------
// Enough for renderAction + aiCaseCard's synchronous render path: element
// creation, text, classes, attributes, dataset, style, tree ops, listeners
// (recorded, never fired during render), and a simple selector match.
function makeEl(tag) {
    const el = {
        tagName: String(tag).toUpperCase(),
        nodeType: 1,
        children: [],
        parentNode: null,
        className: '',
        _text: '',
        style: {},
        dataset: {},
        attributes: {},
        listeners: {},
        disabled: false,
        checked: false,
        value: '',
        type: '',
        open: false,
        src: '',
        href: '',
        alt: '',
        loading: '',
        decoding: '',
        fetchPriority: '',
        referrerPolicy: '',
        _isRoot: false,
        get textContent() {
            let out = this._text || '';
            for (const c of this.children) out += (c.textContent || '');
            return out;
        },
        set textContent(v) {
            this.children = [];
            this._text = (v === undefined || v === null) ? '' : String(v);
        },
        get isConnected() {
            let n = this;
            while (n) { if (n._isRoot) return true; n = n.parentNode; }
            return false;
        },
        get childNodes() { return this.children; },
        get firstChild() { return this.children[0] || null; },
        get lastChild() { return this.children[this.children.length - 1] || null; },
        get parentElement() { return this.parentNode && this.parentNode.nodeType === 1 ? this.parentNode : null; },
        get nextSibling() {
            if (!this.parentNode) return null;
            const i = this.parentNode.children.indexOf(this);
            return this.parentNode.children[i + 1] || null;
        },
        get previousSibling() {
            if (!this.parentNode) return null;
            const i = this.parentNode.children.indexOf(this);
            return this.parentNode.children[i - 1] || null;
        },
        setAttribute(k, v) { this.attributes[k] = String(v); },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k) ? this.attributes[k] : null; },
        hasAttribute(k) { return Object.prototype.hasOwnProperty.call(this.attributes, k); },
        removeAttribute(k) { delete this.attributes[k]; },
        addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); },
        removeEventListener(type, fn) {
            if (!this.listeners[type]) return;
            this.listeners[type] = this.listeners[type].filter((f) => f !== fn);
        },
        click() { (this.listeners.click || []).forEach((fn) => fn.call(this, { preventDefault() {} })); },
        appendChild(c) { return this.append(c); },
        append(...items) {
            for (let item of items) {
                if (typeof item === 'string') item = makeText(item);
                if (item.parentNode) item.parentNode.removeChild(item);
                item.parentNode = this;
                this.children.push(item);
            }
            return this;
        },
        prepend(...items) {
            const nodes = items.map((item) => (typeof item === 'string' ? makeText(item) : item));
            for (const item of nodes) if (item.parentNode) item.parentNode.removeChild(item);
            for (const item of nodes) item.parentNode = this;
            this.children.unshift(...nodes);
            return this;
        },
        replaceChildren(...items) {
            for (const c of this.children) c.parentNode = null;
            this.children = [];
            this._text = '';
            if (items.length) this.append(...items);
            return this;
        },
        removeChild(c) {
            const i = this.children.indexOf(c);
            if (i >= 0) { this.children.splice(i, 1); c.parentNode = null; }
            return c;
        },
        remove() { if (this.parentNode) this.parentNode.removeChild(this); },
        replaceWith(...items) {
            if (!this.parentNode) return;
            const i = this.parentNode.children.indexOf(this);
            if (i < 0) return;
            this.parentNode.children.splice(i, 1);
            this.parentNode = null;
            const nodes = items.map((item) => (typeof item === 'string' ? makeText(item) : item));
            for (const item of nodes) item.parentNode = this.parentNode;
            this.parentNode.children.splice(i, 0, ...nodes);
        },
        querySelector(sel) { const all = this.querySelectorAll(sel); return all[0] || null; },
        querySelectorAll(sel) {
            const out = [];
            const walk = (n) => {
                for (const c of (n.children || [])) {
                    if (c.nodeType === 1 && matchesSel(c, sel)) out.push(c);
                    walk(c);
                }
            };
            walk(this);
            return out;
        },
        closest(sel) {
            let n = this;
            while (n) { if (n.nodeType === 1 && matchesSel(n, sel)) return n; n = n.parentNode; }
            return null;
        },
        showModal() {}, close() {}, focus() {}, blur() {},
        getBoundingClientRect() { return { top: 0, left: 0, width: 0, height: 0 }; },
    };
    el.classList = {
        add(...c) { for (const x of c) if (!el.classList.contains(x)) el.className = (el.className + ' ' + x).trim(); },
        remove(...c) { el.className = el.className.split(/\s+/).filter((x) => x && !c.includes(x)).join(' '); },
        toggle(c, force) {
            const has = el.classList.contains(c);
            const next = force === undefined ? !has : !!force;
            if (next) el.classList.add(c); else el.classList.remove(c);
            return next;
        },
        contains(c) { return el.className.split(/\s+/).includes(c); },
    };
    return el;
}
function makeText(text) {
    return {
        nodeType: 3, tagName: '#text', textContent: String(text),
        children: [], parentNode: null,
        remove() { if (this.parentNode) this.parentNode.removeChild(this); },
    };
}
function matchesSel(el, sel) {
    // Simple: comma list of [tag][.class...] with optional [attr] / [attr="v"].
    return String(sel).split(',').some((part) => {
        part = part.trim();
        if (!part) return false;
        const attrMatch = part.match(/\[([^\]=]+)(?:="([^"]*)")?\]/);
        let attr = null; let attrVal = null;
        if (attrMatch) { attr = attrMatch[1]; attrVal = attrMatch[2]; part = part.slice(0, attrMatch.index); }
        const bits = part.split('.').filter(Boolean);
        let tag = null;
        if (bits.length && !part.startsWith('.')) tag = bits.shift();
        if (tag && el.tagName !== tag.toUpperCase()) return false;
        for (const c of bits) if (!el.classList.contains(c)) return false;
        if (attr) {
            const v = el.getAttribute(attr);
            if (v === null) return false;
            if (attrVal !== undefined && attrVal !== null && v !== attrVal) return false;
        }
        return true;
    });
}

// --- Sandbox ------------------------------------------------------------
// Builds the real renderAction + aiCaseCard closure in a fresh sandbox.
// opts: { data, contextStored, dismissals, requestMode ('hang'|'ok'|'fail'),
//         requestResult } — requestMode controls the workbench-candidates
// fetch: 'hang' never resolves, 'ok' resolves with requestResult, 'fail'
// rejects. Returns { renderAction, document, requestCalls, dashboardCalls }.
function buildSandbox(opts) {
    const document = {
        createElement: (tag) => makeEl(tag),
        createElementNS: (_ns, tag) => makeEl(tag),
        createTextNode: (text) => makeText(text),
        querySelector: () => null,
        querySelectorAll: () => [],
        body: null,
    };
    document.body = makeEl('body');
    document.body._isRoot = true;
    const window = { location: null, addEventListener() {}, removeEventListener() {} };
    const location = {
        href: 'https://example.test/wp-admin/admin.php?page=molavi-operations',
        search: '', pathname: '/wp-admin/admin.php',
    };
    window.location = location;
    const requestCalls = [];
    const dashboardCalls = [];
    let requestImpl = null;
    const request = (method, body, url, apiKey, options) => {
        requestCalls.push({ method, body, url, apiKey, options });
        return requestImpl(method, body, url, apiKey, options);
    };
    const dashboard = async (message, kind, keys) => {
        dashboardCalls.push({ message, kind, keys });
    };
    const seeds = ['aiCaseCard', 'workbenchSetDismissals',
        'workbenchPendingAction', 'workbenchActionKey', 'workbenchQueueMatches',
        'workbenchIsDismissed', 'workbenchAiCaseIsDismissed',
        'workbenchDismissalKey', 'workbenchCurrentDismissalKey',
        'workbenchDismissalToken', 'groupAiVariantCases'];
    const renderActionText = extractInnerConst(src, 'renderAction');
    const { names, pieces } = closure(seeds, [{ name: 'renderAction', text: renderActionText }]);
    const code = `
let workbenchData = null;
let data = null;
const workbenchErrors = {};
let workbenchDismissals = [];
let workbenchDismissalIndex = new Map();
const config = { apiUrl: 'https://example.test', reviewStoreUrl: 'https://example.test', reviewStoreNonce: 'nonce' };
const workbenchQueue = 'next';
const workbenchStageModel = [];
const workbenchWooState = (product) => product._wooState || '';
const safeSourceLink = () => null;
const contextStored = ${JSON.stringify(opts.contextStored || { ai_confirmations: true, reference_items: true })};
const aiCasesBySource = new Map();
const aiSourceCoverageLead = new Map();
const root = document.body;
${pieces.join('\n')}
workbenchData = ${JSON.stringify(opts.data || {})};
data = workbenchData;
workbenchSetDismissals(${JSON.stringify(opts.dismissals || [])});
return { renderAction, workbenchActionKey };
`;
    const fn = new Function('document', 'window', 'location', 'navigator',
        'localStorage', 'sessionStorage', 'fetch', 'request', 'dashboard', code);
    const localStorage = { getItem: () => null, setItem() {}, removeItem() {} };
    const sessionStorage = { getItem: () => null, setItem() {}, removeItem() {} };
    const api = fn(document, window, location, {}, localStorage, sessionStorage,
        async () => { throw new Error('fetch must not be called'); }, request, dashboard);
    // Wire the controllable request implementation.
    requestImpl = (method, body, url, apiKey) => {
        if (opts.requestMode === 'hang') return new Promise(() => {});
        if (opts.requestMode === 'fail') return Promise.reject(new Error('boom'));
        return Promise.resolve(opts.requestResult || { ok: true, snapshot_id: '', candidates: [] });
    };
    return { ...api, document, requestCalls, dashboardCalls };
}

// --- DOM query helpers ---------------------------------------------------
function textOf(el) {
    if (!el) return '';
    return el.textContent || '';
}
function findAll(el, pred) {
    const out = [];
    const walk = (n) => {
        if (n && n.nodeType === 1 && pred(n)) out.push(n);
        for (const c of (n.children || [])) walk(c);
    };
    walk(el);
    return out;
}
function findButtons(el, substr) {
    return findAll(el, (n) => n.tagName === 'BUTTON' && textOf(n).includes(substr));
}
function hasText(el, substr) { return textOf(el).includes(substr); }
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

// --- Fixtures: the exact probe #3/#4 live state -------------------------
const BEER_ID = '_____________________167421';
const CASE_A_ID = 'ai:4de46c523f579de0a69fa13c79d0798d4399d7c0f53a06fca137de62e25b2a50';
const CASE_B_ID = 'ai:50c431a0cdf8406400227241527514d51ca62fca259e6714ee09461dd4f37870';
const CASE_A_OFFER = 'reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab:7218:0';
const CASE_B_OFFER = 'reextract:50115195ed438942d3490cb3e0011d64:11df1b60b5de96b08f6c27906c5074da6c4d45b60f501ef6123a6d029435d4d4:15:0';
const SNAP_A = '0938f15edc83bc5b7317d0cfccfbaf3f299ecc9634bbb0cd9bd6eec63423ca93';
function probeCaseA() {
    return {
        case_id: CASE_A_ID, offer_id: CASE_A_OFFER, product_id: BEER_ID,
        product_name: 'آب جو بلک لیبل،۱۲ عددی', channel: '@bonakdarjavan',
        message_id: '7218',
        batch_id: 'reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab',
        sale_price: '75000', detected_price: '75000', confirmed_sale_price: 85000, publication_held: false,
        message_review: null, reopened: null, decision: null,
        reference_snapshot_id: SNAP_A, reference_match_id: '',
        reference_assignment: null, reference_candidates: [],
        historical_reextract: true, source_current_check_required: true,
        source_post_url: 't.me/bonakdarjavan/1523',
        reason: 'Historical price needs current supplier verification; reference: no_compatible_reference',
    };
}
function probeCaseB() {
    const c = probeCaseA();
    c.case_id = CASE_B_ID; c.offer_id = CASE_B_OFFER; c.message_id = '15';
    c.batch_id = 'reextract:50115195ed438942d3490cb3e0011d64:11df1b60b5de96b08f6c27906c5074da6c4d45b60f501ef6123a6d029435d4d4';
    c.sale_price = '85000'; c.detected_price = '85000';
    return c;
}
function beerProduct(cases) {
    return {
        product_id: BEER_ID, key: 'product:' + BEER_ID,
        name: 'آب جو بلک لیبل،۱۲ عددی', channel: '@bonakdarjavan',
        holds: [], ai: cases, references: [], conflicts: [], supplierPrices: [],
        status: {
            status: 'imported', sale_offer_blocker: 'regular_price',
            latest_offer_id: 'owner-confirm:66844eca5300460bfe2b67ea180aed17127822b2519d056845e993223af54f06',
            publication_needed: false, update_needed: false, regular_price_missing: true,
            regular_price_state: 'missing', source_integrity_issues: [],
            supplier_price_state: 'current', reference_price_role: 'needs_review',
        },
    };
}
function beerData() {
    return {
        ai_confirmations: { candidates_deferred: true, cases: [probeCaseA(), probeCaseB()] },
        autopilot: { holds: [
            { product_id: BEER_ID, gate: 'market', reason: 'جست‌وجوی بازار نتیجه‌ای نداشت.' },
            { product_id: BEER_ID, gate: 'admission', reason: 'قیمت عادی جاری نیست.' },
        ] },
    };
}
const BEER_DISMISSALS = [{ task_key: 'ai|case:' + CASE_B_ID }];

// --- Runner --------------------------------------------------------------
let pass = 0; let fail = 0;
function check(label, cond, extra) {
    if (cond) { pass++; console.log('PASS', label); }
    else {
        fail++;
        console.log('FAIL', label, extra !== undefined ? ('\n  ' + JSON.stringify(extra)) : '');
    }
}
function caseVisible(card) {
    // The rendered case: a confirm button + a dismiss button + case A's price.
    // Confirm labels are «تأیید کالا به‌عنوان قیمت هر واحد» /
    // «تأیید کالا به‌عنوان قیمت بسته» (single case); the dismiss is
    // «این مورد کالا نیست». For a historical re-extract the confirm is
    // disabled until the owner checks «قیمت امروز را از تأمین‌کننده
    // بررسی کردم.» — presence (not enabled state) is what this harness asserts.
    const confirm = findButtons(card, 'به‌عنوان قیمت');
    const dismiss = findButtons(card, 'کالا نیست');
    const price = hasText(card, '۷۵٬۰۰۰');
    return { confirm: confirm.length, dismiss: dismiss.length, price, ok: confirm.length > 0 && dismiss.length > 0 && price };
}
function loadingVisible(card) { return hasText(card, 'در حال بررسی مرجع این پیشنهاد'); }


// --- Comparison-line helpers --------------------------------------------
const COMPARE_LABEL = 'قیمت شناسایی‌شده در برابر تأییدشده';
function hasComparisonLine(card) { return hasText(card, COMPARE_LABEL); }
function hasConfirmedPrice(card) { return hasText(card, '۸۵٬۰۰۰'); }
function referenceStepPresent(card) { return hasText(card, 'گام فعلی · انتخاب مرجع و مبنای قیمت'); }
const NAMED_CANDIDATE = { reference_id: 'ref-1', name: 'مرجع آزمون' };
function dataFor(cases) {
    return {
        ai_confirmations: { candidates_deferred: true, cases },
        autopilot: { holds: [
            { product_id: BEER_ID, gate: 'market', reason: 'جست‌وجوی بازار نتیجه‌ای نداشت.' },
            { product_id: BEER_ID, gate: 'admission', reason: 'قیمت عادی جاری نیست.' },
        ] },
    };
}

async function scenarioComparisonBothPaths() {
    // (a)(b) Beer live state: detected 75000 vs confirmed 85000. The
    // candidates fetch resolves with one NAMED candidate, which flips
    // the re-render into the matching (reference-selection) early
    // return. The price facts — and the comparison line — are built
    // before that return, so the line must survive it.
    const sb = buildSandbox({
        data: beerData(), dismissals: BEER_DISMISSALS, requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_A, candidates: [NAMED_CANDIDATE] },
    });
    const product = beerProduct([probeCaseA(), probeCaseB()]);
    check('comparison: routing is ai (matrix P0, re-checked in the DOM sandbox)',
        sb.workbenchActionKey(product) === 'ai', { key: sb.workbenchActionKey(product) });
    const card = sb.renderAction(product, 'ai');
    check('comparison: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    // (a) Before the fetch resolves: fall-through path.
    if (isFixed) {
        check('(a) FIXED: before the fetch resolves, the comparison line is present',
            hasComparisonLine(card));
        check('(a) FIXED: before the fetch resolves, the confirmed ۸۵٬۰۰۰ is shown',
            hasConfirmedPrice(card));
    } else {
        check('(a) BASE (negative control): before the fetch resolves, NO comparison line',
            !hasComparisonLine(card));
        check('(a) BASE (negative control): before the fetch resolves, NO confirmed ۸۵٬۰۰۰',
            !hasConfirmedPrice(card));
    }
    await tick(); await tick(); await tick(); await tick(); await tick(); await tick();
    check('comparison: the fetch was attempted with the case snapshot id',
        sb.requestCalls.some((c) => String(c.url).includes('workbench-candidates')
            && String(c.url).includes(encodeURIComponent(SNAP_A))));
    // (b) After the fetch resolves: matching (reference-selection) path.
    check('(b) after the fetch resolves, «گام فعلی · انتخاب مرجع و مبنای قیمت» is present',
        referenceStepPresent(card));
    if (isFixed) {
        check('(b) FIXED: after the fetch resolves, the comparison line is STILL present',
            hasComparisonLine(card));
        check('(b) FIXED: after the fetch resolves, the confirmed ۸۵٬۰۰۰ is STILL shown',
            hasConfirmedPrice(card));
    } else {
        check('(b) BASE (negative control): after the fetch resolves, STILL no comparison line',
            !hasComparisonLine(card));
        check('(b) BASE (negative control): after the fetch resolves, STILL no confirmed ۸۵٬۰۰۰',
            !hasConfirmedPrice(card));
    }
}

async function scenarioNoConfirmedPrice() {
    // (c) Control: a case with NO confirmed price renders NO
    // comparison line in either path — unchanged in both modes.
    const noConfirmed = probeCaseA();
    delete noConfirmed.confirmed_sale_price;
    const sb = buildSandbox({
        data: dataFor([noConfirmed]), dismissals: BEER_DISMISSALS, requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_A, candidates: [NAMED_CANDIDATE] },
    });
    const product = beerProduct([noConfirmed]);
    const card = sb.renderAction(product, 'ai');
    check('(c) no-confirmed: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    check('(c) before the fetch resolves, NO comparison line without a confirmed price',
        !hasComparisonLine(card));
    await tick(); await tick(); await tick(); await tick(); await tick(); await tick();
    check('(c) after the fetch resolves, STILL no comparison line without a confirmed price',
        !hasComparisonLine(card));
    check('(c) the reference step still appears after the fetch resolves',
        referenceStepPresent(card));
}

async function scenarioDetectedEqualsConfirmed() {
    // (d) Control: detected == confirmed (75000 vs 75000) renders NO
    // comparison line in either path — there is nothing to compare.
    const equal = probeCaseA();
    equal.confirmed_sale_price = 75000;
    const sb = buildSandbox({
        data: dataFor([equal]), dismissals: BEER_DISMISSALS, requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_A, candidates: [NAMED_CANDIDATE] },
    });
    const product = beerProduct([equal]);
    const card = sb.renderAction(product, 'ai');
    check('(d) equal: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    check('(d) before the fetch resolves, NO comparison line when detected == confirmed',
        !hasComparisonLine(card));
    await tick(); await tick(); await tick(); await tick(); await tick(); await tick();
    check('(d) after the fetch resolves, STILL no comparison line when detected == confirmed',
        !hasComparisonLine(card));
}

(async () => {
    try {
        await scenarioComparisonBothPaths();
        await scenarioNoConfirmedPrice();
        await scenarioDetectedEqualsConfirmed();
    } catch (error) {
        fail++;
        console.log('FAIL harness error:', error && error.stack ? error.stack : String(error));
    }
    console.log(`\n${pass} PASS, ${fail} FAIL`);
    if (fail) process.exitCode = 1;
    else console.log('ALL DETECTED-VS-CONFIRMED PRICE CHECKS PASSED');
})();
