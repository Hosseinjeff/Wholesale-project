// Source-post status on the card (cappuccino t.me/bonakdarjavan/1337).
// The owner found the card never says the source post is gone: the
// stored copy and link stay on the card with only a staleness warning
// (173 days, past the 14-day threshold). The live post is DELETED
// (t.me answers 200 "Post not found"; the channel itself is alive).
// The fix carries the link-check status on the product payload
// (product.link_check, attached server-side for the current source
// revision) and renders it in aiCaseCard's source section — built
// before the reference-selection early return, so it shows in BOTH ai
// paths (fall-through confirmation path AND matching
// reference-selection path after the candidates fetch resolves).
// This harness extracts the REAL renderAction + aiCaseCard (plus
// their transitive closure) from owner-workflows.js, runs them
// against a minimal DOM stub with a controllable request stub, and
// asserts on the DOM (probe-exact cappuccino fixture: source URL
// t.me/bonakdarjavan/1337, link-check status post_not_found, detected
// 450000, confirmed 800000, source 173 days old):
//   (a) deleted post: the «پست منبع حذف شده است» line + the
//       fresh-message path forward are present in both paths. On the
//       6fc9a4f base file NEITHER line renders (negative control —
//       the harness auto-detects fixed vs base from the source and
//       asserts the matching outcome, so running it on the base file
//       PASSES by proving the gap, and running it on the fixed file
//       PASSES by proving the fix);
//   (b) no link-check row: the «پیام منبع هنوز از لینک اصلی‌اش
//       خوانده نشده است» line is present (fixed) / absent (base);
//   (c) unchanged check: the checked/unchanged line is present and
//       NO new warning appears (fixed) / nothing renders (base);
//   (d) check URL differs from the case's source URL: NO deleted
//       line for the wrong source; the never-checked line IS shown
//       (fixed) / nothing renders (base);
//   (e) case with NO source URL: no link-check line at all in
//       either mode — the existing no-source handling is untouched.
// Run: node tests/source_post_status_on_card.cjs [workflows.js path]
//   Negative control: git show 6fc9a4f:shop-wp/molavi-shop/includes/owner-workflows.js > /tmp/workflows-6fc9a4f.js
//   then node tests/source_post_status_on_card.cjs /tmp/workflows-6fc9a4f.js
'use strict';
const fs = require('fs');
const path = require('path');

const workflowsPath = process.argv[2]
    || path.join(__dirname, '..', 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const src = fs.readFileSync(workflowsPath, 'utf8');
const isFixed = src.includes('parentLinkCheck');
console.log('MODE:', isFixed ? 'FIXED (product.link_check renders in aiCaseCard source section)' : 'BASE 6fc9a4f (negative control: no link-check status on the card)');

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

// --- Fixtures: the probe-exact cappuccino live state --------------------
// Product: «کاپوچینو ۲۰ عددی تیمز», p_2495a6cc4a8a643c0946ca0d, Woo 105.
// Source URL https://t.me/bonakdarjavan/1337 — DELETED on Telegram
// (t.me answers 200 "Post not found", zero message-text divs; the
// channel itself is alive). The card showed the stored copy + link
// with only a staleness warning (173 days, past the 14-day threshold),
// never that the post is gone. The fix carries the link-check status
// on the product payload (product.link_check) and renders it in
// aiCaseCard's source section — built before the reference-selection
// early return, so it shows in BOTH ai paths.
const CAP_ID = 'p_2495a6cc4a8a643c0946ca0d';
const CAP_OFFER = 'owner-confirm:8153eb11fee7c30961bcc192df38faa0a489be18372771f9528503bd05f2edde';
const CAP_URL = 'https://t.me/bonakdarjavan/1337';
const CAP_URL_SCHEMELESS = 't.me/bonakdarjavan/1337';
const SNAP_CAP = 'cap-snapshot-1337';
const CHECKED_AT = 1764074700; // 2026-11-26 15:45 UTC, the probe time.
function capCase() {
    return {
        case_id: 'ai:cap-1337', offer_id: CAP_OFFER, product_id: CAP_ID,
        product_name: 'کاپوچینو ۲۰ عددی تیمز', channel: '@bonakdarjavan',
        message_id: '1337', batch_id: '',
        sale_price: '450000', detected_price: '450000', confirmed_sale_price: 800000,
        publication_held: false, message_review: null, reopened: null, decision: null,
        reference_snapshot_id: SNAP_CAP, reference_match_id: '',
        reference_assignment: null, reference_candidates: [],
        historical_reextract: false, source_current_check_required: true,
        source_post_url: CAP_URL_SCHEMELESS,
        original_message: 'کاپوچینو ۲۰ عددی تیمز\nقیمت فروش ما: ۴۵۰/۰۰۰ تومان\nقیمت مصرف کننده: ندارد',
        reason: 'قیمت از پیام قدیمی آمده؛ پیش از ثبت نهایی آن را از منبع فعلی تأیید کنید.',
        source_updated_at: '2026-06-06T08:24:00+00:00',
    };
}
function capLinkCheckDeleted() {
    return {
        product_id: CAP_ID, offer_id: CAP_OFFER, title: 'کاپوچینو ۲۰ عددی تیمز',
        url: CAP_URL, source_revision: 'cap-revision',
        outcome: 'uncheckable', reason_code: 'post_not_found',
        reason_label: 'پست منبع حذف شده (C-S3)',
        live_price: null, checked_at: CHECKED_AT, next_at: CHECKED_AT + 86400,
        route: 'railway', check_ref: 'link-check:' + CAP_ID + '@' + CHECKED_AT,
        confirmed_at: null, confirmed_verified_at: null,
    };
}
function capLinkCheckUnchanged() {
    const c = capLinkCheckDeleted();
    c.outcome = 'unchanged'; c.reason_code = 'identical';
    c.reason_label = 'متن پیام بدون تغییر';
    c.live_price = '800000';
    return c;
}
function capProduct(cases, linkCheck) {
    return {
        product_id: CAP_ID, key: 'product:' + CAP_ID,
        name: 'کاپوچینو ۲۰ عددی تیمز', channel: '@bonakdarjavan',
        holds: [], ai: cases, references: [], conflicts: [], supplierPrices: [],
        link_check: linkCheck === undefined ? null : linkCheck,
        status: {
            status: 'imported', sale_offer_blocker: 'regular_price',
            latest_offer_id: CAP_OFFER,
            publication_needed: false, update_needed: false, regular_price_missing: false,
            regular_price_state: 'current', source_integrity_issues: [],
            supplier_price_state: 'current', reference_price_role: 'needs_review',
            source_post_url: CAP_URL,
        },
    };
}
function capData(cases) {
    return {
        ai_confirmations: { candidates_deferred: true, cases },
        autopilot: { holds: [] },
    };
}
const NAMED_CANDIDATE = { reference_id: 'ref-1', name: 'مرجع آزمون' };

// --- Runner --------------------------------------------------------------
let pass = 0; let fail = 0;
function check(label, cond, extra) {
    if (cond) { pass++; console.log('PASS', label); }
    else {
        fail++;
        console.log('FAIL', label, extra !== undefined ? ('\n  ' + JSON.stringify(extra)) : '');
    }
}
function referenceStepPresent(card) { return hasText(card, 'گام فعلی · انتخاب مرجع و مبنای قیمت'); }

// --- Link-check status helpers -------------------------------------------
const DELETED_LINE = 'پست منبع حذف شده است';
const FRESH_MESSAGE = 'پیام تازه را به ربات بفرستید';
const NEVER_CHECKED = 'پیام منبع هنوز از لینک اصلی‌اش خوانده نشده است';
const UNCHANGED_LINE = 'لینک منبع بررسی شد؛ قیمت بدون تغییر است';
function hasDeletedLine(card) { return hasText(card, DELETED_LINE); }
function hasFreshMessage(card) { return hasText(card, FRESH_MESSAGE); }
function hasNeverChecked(card) { return hasText(card, NEVER_CHECKED); }
function hasUnchangedLine(card) { return hasText(card, UNCHANGED_LINE); }

async function scenarioDeletedPostBothPaths() {
    // (a) Cappuccino live state: source post deleted, check recorded.
    // The candidates fetch resolves with one NAMED candidate, which
    // flips the re-render into the matching (reference-selection) early
    // return. The link-check status is built before that return, so
    // the deleted-post line must survive it — in both paths.
    const sb = buildSandbox({
        data: capData([capCase()]), dismissals: [], requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_CAP, candidates: [NAMED_CANDIDATE] },
    });
    const product = capProduct([capCase()], capLinkCheckDeleted());
    check('deleted: routing is ai', sb.workbenchActionKey(product) === 'ai',
        { key: sb.workbenchActionKey(product) });
    const card = sb.renderAction(product, 'ai');
    check('deleted: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    // Before the fetch resolves: fall-through path.
    if (isFixed) {
        check('(a) FIXED: before the fetch resolves, the deleted-post line is present',
            hasDeletedLine(card));
        check('(a) FIXED: before the fetch resolves, the path forward (fresh message) is present',
            hasFreshMessage(card));
        check('(a) FIXED: before the fetch resolves, NO never-checked line (a check exists)',
            !hasNeverChecked(card));
    } else {
        check('(a) BASE (negative control): before the fetch resolves, NO deleted-post line',
            !hasDeletedLine(card));
        check('(a) BASE (negative control): before the fetch resolves, NO never-checked line either',
            !hasNeverChecked(card));
    }
    await tick(); await tick(); await tick(); await tick(); await tick(); await tick();
    check('deleted: the fetch was attempted with the case snapshot id',
        sb.requestCalls.some((c) => String(c.url).includes('workbench-candidates')
            && String(c.url).includes(encodeURIComponent(SNAP_CAP))));
    // After the fetch resolves: matching (reference-selection) path.
    check('(a) after the fetch resolves, «گام فعلی · انتخاب مرجع و مبنای قیمت» is present',
        referenceStepPresent(card));
    if (isFixed) {
        check('(a) FIXED: after the fetch resolves, the deleted-post line is STILL present',
            hasDeletedLine(card));
        check('(a) FIXED: after the fetch resolves, the path forward is STILL present',
            hasFreshMessage(card));
        check('(a) FIXED: after the fetch resolves, STILL no never-checked line',
            !hasNeverChecked(card));
    } else {
        check('(a) BASE (negative control): after the fetch resolves, STILL no deleted-post line',
            !hasDeletedLine(card));
        check('(a) BASE (negative control): after the fetch resolves, STILL no never-checked line',
            !hasNeverChecked(card));
    }
}

async function scenarioNeverChecked() {
    // (b) No link-check row for the product (never seeded / never run):
    // the card says the source was never read — it must not pretend a
    // check happened, and must not claim the post is deleted either.
    const sb = buildSandbox({
        data: capData([capCase()]), dismissals: [], requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_CAP, candidates: [NAMED_CANDIDATE] },
    });
    const product = capProduct([capCase()], null);
    const card = sb.renderAction(product, 'ai');
    check('(b) never-checked: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    if (isFixed) {
        check('(b) FIXED: the never-checked line is present', hasNeverChecked(card));
        check('(b) FIXED: NO deleted-post line without a check', !hasDeletedLine(card));
    } else {
        check('(b) BASE (negative control): NO never-checked line', !hasNeverChecked(card));
        check('(b) BASE (negative control): NO deleted-post line', !hasDeletedLine(card));
    }
    await tick(); await tick(); await tick(); await tick(); await tick(); await tick();
    if (isFixed) {
        check('(b) FIXED: after the fetch resolves, the never-checked line is STILL present',
            hasNeverChecked(card));
    } else {
        check('(b) BASE (negative control): after the fetch resolves, STILL no never-checked line',
            !hasNeverChecked(card));
    }
}

async function scenarioUnchanged() {
    // (c) The source was checked and is unchanged: the card shows the
    // checked state (the supplier-prices view's exact line) and NO new
    // warning — neither deleted nor never-checked.
    const sb = buildSandbox({
        data: capData([capCase()]), dismissals: [], requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_CAP, candidates: [NAMED_CANDIDATE] },
    });
    const product = capProduct([capCase()], capLinkCheckUnchanged());
    const card = sb.renderAction(product, 'ai');
    check('(c) unchanged: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    if (isFixed) {
        check('(c) FIXED: the checked/unchanged line is present', hasUnchangedLine(card));
        check('(c) FIXED: NO deleted-post line for a checked source', !hasDeletedLine(card));
        check('(c) FIXED: NO never-checked line for a checked source', !hasNeverChecked(card));
    } else {
        check('(c) BASE (negative control): NO unchanged line on the card', !hasUnchangedLine(card));
        check('(c) BASE (negative control): NO deleted-post line', !hasDeletedLine(card));
        check('(c) BASE (negative control): NO never-checked line', !hasNeverChecked(card));
    }
    await tick(); await tick(); await tick(); await tick(); await tick(); await tick();
    if (isFixed) {
        check('(c) FIXED: after the fetch resolves, the unchanged line is STILL present',
            hasUnchangedLine(card));
    } else {
        check('(c) BASE (negative control): after the fetch resolves, STILL no unchanged line',
            !hasUnchangedLine(card));
    }
}

async function scenarioUrlMismatch() {
    // (d) The check is for a DIFFERENT source post than this case's:
    // the deleted line must NOT be shown for the wrong source — the
    // honest display for this source is "never checked".
    const other = capLinkCheckDeleted();
    other.url = 'https://t.me/bonakdarjavan/9999';
    const sb = buildSandbox({
        data: capData([capCase()]), dismissals: [], requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_CAP, candidates: [NAMED_CANDIDATE] },
    });
    const product = capProduct([capCase()], other);
    const card = sb.renderAction(product, 'ai');
    check('(d) url-mismatch: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    if (isFixed) {
        check('(d) FIXED: NO deleted-post line for a different source URL', !hasDeletedLine(card));
        check('(d) FIXED: the never-checked line IS shown for this source', hasNeverChecked(card));
    } else {
        check('(d) BASE (negative control): NO deleted-post line', !hasDeletedLine(card));
        check('(d) BASE (negative control): NO never-checked line', !hasNeverChecked(card));
    }
}

async function scenarioNoSourceUrl() {
    // (e) The case has NO source URL: no link-check line at all — the
    // existing no-source handling is untouched.
    const noSource = capCase();
    noSource.source_post_url = '';
    const sb = buildSandbox({
        data: capData([noSource]), dismissals: [], requestMode: 'ok',
        requestResult: { ok: true, snapshot_id: SNAP_CAP, candidates: [NAMED_CANDIDATE] },
    });
    const product = capProduct([noSource], null);
    const card = sb.renderAction(product, 'ai');
    check('(e) no-source: renderAction returns a card (not null)', !!card);
    if (!card) return;
    sb.document.body.appendChild(card);
    check('(e) no-source: NO deleted-post line', !hasDeletedLine(card));
    check('(e) no-source: NO never-checked line', !hasNeverChecked(card));
    check('(e) no-source: NO unchanged line', !hasUnchangedLine(card));
}

(async () => {
    try {
        await scenarioDeletedPostBothPaths();
        await scenarioNeverChecked();
        await scenarioUnchanged();
        await scenarioUrlMismatch();
        await scenarioNoSourceUrl();
    } catch (error) {
        fail++;
        console.log('FAIL harness error:', error && error.stack ? error.stack : String(error));
    }
    console.log(`\n${pass} PASS, ${fail} FAIL`);
    if (fail) process.exitCode = 1;
    else console.log('ALL SOURCE-POST-STATUS CHECKS PASSED');
})();
