// Duplicate-card merge (owner request 2026-11-26): ONE product showed as TWO
// cards. «آب جو بلک لیبل،۱۲ عددی» appears twice — card 1 is the confirmed
// pipeline product (Product ID _____________________167421, HAS an autopilot
// block) and card 2 is an AI-confirmation CASE for the unconfirmed re-extract
// offer (message 7218, @bonakdarjavan, status needs_review, NO autopilot
// block, NO step trace). The offer row's Product ID IS _____________________167421,
// but the case object never carried it, so ensureProduct could only match on
// offer_id — and the re-extract offer_id differs from the product's
// latest_offer_id (the owner-confirm ID), so a second entry was created (key
// offer:<reextract id>) with status null. The fix (PHP,
// molavi_domain_ai_case) carries the offer row's Product ID on the case; this
// harness proves the JS side: with the key present, the real ensureProduct
// merges the case into its product, and without it the bug reproduces.
// No JS change was needed — the merge is ensureProduct's existing product_id
// lookup. Extracts the real ensureProduct + aiSourceMessageKey from
// includes/owner-workflows.js by brace matching, evaluates them against
// stubbed maps, and replays the loader's merge order (product_status first,
// then ai cases). No browser, no jsdom.
// Run: node tests/duplicate_card_merge.cjs
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const repoRoot = path.join(__dirname, '..');
const workflowsPath = path.join(repoRoot, 'shop-wp', 'molavi-shop', 'includes', 'owner-workflows.js');
const src = fs.readFileSync(workflowsPath, 'utf8');

function extractFunction(source, name) {
    const start = source.indexOf(`    function ${name}(`);
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

// --- Model sandbox: the real ensureProduct + aiSourceMessageKey ---
function buildModelSandbox() {
    const pieces = [];
    pieces.push(extractFunction(src, 'aiSourceMessageKey'));
    pieces.push(extractConstStatement(src, 'ensureProduct'));
    const code = `
const byProductId = new Map();
const byOfferId = new Map();
const products = [];
${pieces.join('\n')}
function mergeProductStatus(items) {
    items.forEach(item => { const product = ensureProduct(item, 'product_status'); product.status = item; });
}
function mergeAiCases(items) {
    items.forEach(item => ensureProduct(item, 'ai').ai.push(item));
}
return { ensureProduct, mergeProductStatus, mergeAiCases, products, byProductId, byOfferId };
`;
    return new Function(code)();
}

// --- Routing sandbox: the real router, for the first-view consequence ---
const ROUTING_FNS = ['workbenchPendingAction', 'workbenchActionKey', 'workbenchNextStep',
    'workbenchActionForQueue', 'workbenchQueueMatches', 'workbenchReadyAction',
    'workbenchAiCaseIsDismissed', 'workbenchPendingSupplierPrices',
    'workbenchSupplierSource', 'aiReviewReason', 'normalizedAiInteger',
    'workbenchNeedsPriceRegistration'];
const HOLD_FNS = ['workbenchHoldRoute', 'workbenchAutopilotHolds'];
function buildRoutingSandbox(source) {
    const pieces = [];
    pieces.push(extractConstStatement(source, 'workbenchActionOrder'));
    pieces.push(extractConstStatement(source, 'workbenchActionSections'));
    for (const name of ROUTING_FNS) pieces.push(extractFunction(source, name));
    for (const name of HOLD_FNS) pieces.push(extractFunction(source, name));
    if (source.includes('function workbenchHasUnchangedLinkCheck(')) {
        pieces.push(extractFunction(source, 'workbenchHasUnchangedLinkCheck'));
    }
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
    workbenchActionKey, workbenchNextStep, workbenchQueueMatches,
};`;
    return new Function(code)();
}

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

// --- Fixtures: the live beer case, verbatim ids where the brief gives them ---
const BEER_ID = '_____________________167421';
const BEER_OWNER_CONFIRM = 'owner-confirm:66844eca-beer';
const BEER_REEXTRACT = 'reextract:50115195ed438942d3490cb3e0011d64:ceed4d46b118a0472f7de841e739fa7be683c90edc42723050dae2a3024079ab:7218:0';
const COFFEE_ID = 'coffee-black-teams-40'; // synthetic id; the live coffee id is not recorded — the mechanism is identical
const COFFEE_REEXTRACT = 'reextract:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:7218:0';

function productStatusRow(id, name, channel, latestOfferId, blocker) {
    return {
        product_id: id, product_name: name, channel, status: 'imported',
        latest_offer_id: latestOfferId, sale_offer_blocker: blocker,
        update_needed: false, publication_needed: false, regular_price_missing: false,
        source_integrity_issues: [], source_post_url: '',
    };
}
function aiCase(offerId, productName, channel, extra = {}) {
    return {
        case_id: 'ai:' + crypto.createHash('sha256').update(offerId).digest('hex'),
        offer_id: offerId, product_name: productName, channel,
        message_id: '7218', batch_id: 'reextract:50115195ed438942d3490cb3e0011d64',
        sale_price: 450000, ...extra,
    };
}

const beerRow = productStatusRow(BEER_ID, 'آب جو بلک لیبل،۱۲ عددی', 'bonakdarjavan', BEER_OWNER_CONFIRM, 'regular_price');
const coffeeRow = productStatusRow(COFFEE_ID, 'قهوه بلک تیمز ۴۰ عددی', 'bonakdarjavan', 'owner-confirm:coffee-1', 'regular_price');
const tunaRow = productStatusRow('tuna-100', 'تن ماهی خوش خوراک', 'bonakdarjavan', 'offer-tuna', '');

// Post-fix payload: the case carries its offer row's Product ID.
const beerCaseFixed = aiCase(BEER_REEXTRACT, 'آب جو بلک لیبل،۱۲ عددی', 'bonakdarjavan', { product_id: BEER_ID });
const coffeeCaseFixed = aiCase(COFFEE_REEXTRACT, 'قهوه بلک تیمز ۴۰ عددی', 'bonakdarjavan', { product_id: COFFEE_ID });
// Pre-fix payload: the case carries no product_id (the live shape before the fix).
const { product_id: _dropped, ...beerCaseBroken } = beerCaseFixed;

// (a) Post-fix beer: ONE card, with status, autopilot source and the case.
{
    const model = buildModelSandbox();
    model.mergeProductStatus([beerRow]);
    model.mergeAiCases([beerCaseFixed]);
    check('(a) post-fix beer: one product, not two', model.products.length, 1);
    const beer = model.products[0];
    check('(a) post-fix beer: the card key is the product key', beer.key, 'product:' + BEER_ID);
    check('(a) post-fix beer: the product_id is the pipeline id', beer.product_id, BEER_ID);
    check('(a) post-fix beer: the status is the product row (autopilot block + step trace source)', beer.status, beerRow);
    check('(a) post-fix beer: the pending re-extract case is on the card', beer.ai.length, 1);
    check('(a) post-fix beer: the case on the card is the re-extract case', beer.ai[0].offer_id, BEER_REEXTRACT);
    check('(a) post-fix beer: the re-extract offer_id now resolves to the beer product', model.byOfferId.get(BEER_REEXTRACT) === beer, true);
    check('(a) post-fix beer: the product_id still resolves to the beer product', model.byProductId.get(BEER_ID) === beer, true);
}

// (a-repro) Pre-fix beer: the bug — the same case WITHOUT product_id builds a second card.
{
    const model = buildModelSandbox();
    model.mergeProductStatus([beerRow]);
    model.mergeAiCases([beerCaseBroken]);
    check('(a-repro) pre-fix beer: two products (the bug)', model.products.length, 2);
    const orphan = model.products[1];
    check('(a-repro) pre-fix beer: the second card is keyed by the re-extract offer', orphan.key, 'offer:' + BEER_REEXTRACT);
    check('(a-repro) pre-fix beer: the second card has no status (no trace, no autopilot block)', orphan.status, null);
    check('(a-repro) pre-fix beer: the second card has no product_id', orphan.product_id, '');
    check('(a-repro) pre-fix beer: the second card carries the case', orphan.ai.length, 1);
}

// (d) Coffee: the same pattern merges the same way.
{
    const model = buildModelSandbox();
    model.mergeProductStatus([coffeeRow]);
    model.mergeAiCases([coffeeCaseFixed]);
    check('(d) post-fix coffee: one product, not two', model.products.length, 1);
    check('(d) post-fix coffee: the case is on the coffee card', model.products[0].ai.length, 1);
    check('(d) post-fix coffee: the card keeps its status', model.products[0].status, coffeeRow);
}

// (b) A case whose offer Product ID matches NO product still creates its own card.
{
    const model = buildModelSandbox();
    model.mergeProductStatus([beerRow]);
    const standalone = aiCase('offer-standalone-1', 'کالای بی‌صاحب', 'bonakdarjavan', { product_id: 'product-does-not-exist' });
    model.mergeAiCases([standalone]);
    check('(b) unmatched product_id: two products (beer + standalone)', model.products.length, 2);
    const own = model.products[1];
    check('(b) unmatched product_id: the standalone card is keyed by its product id', own.key, 'product:product-does-not-exist');
    check('(b) unmatched product_id: the standalone card has no status', own.status, null);
    check('(b) unmatched product_id: the standalone card carries the case', own.ai.length, 1);
    check('(b) unmatched product_id: the beer card is untouched', model.products[0].ai.length, 0);
}

// (c) A case with a matching product_id merges as before (the normal path).
{
    const model = buildModelSandbox();
    model.mergeProductStatus([tunaRow]);
    const tunaCase = aiCase('offer-tuna-new', 'تن ماهی خوش خوراک', 'bonakdarjavan', { product_id: 'tuna-100' });
    model.mergeAiCases([tunaCase]);
    check('(c) matching product_id: one product', model.products.length, 1);
    check('(c) matching product_id: the case is on the tuna card', model.products[0].ai.length, 1);
}

// (e) A genuinely new product's case (no product_id, unmatched offer_id) still creates its own card.
{
    const model = buildModelSandbox();
    const fresh = aiCase('offer-brand-new', 'کالای تازه', 'bonakdarjavan', {});
    delete fresh.product_id;
    model.mergeAiCases([fresh]);
    check('(e) new product case: one card', model.products.length, 1);
    check('(e) new product case: keyed by its offer', model.products[0].key, 'offer:offer-brand-new');
    check('(e) new product case: no status (as before)', model.products[0].status, null);
}

// (g) First-view consequence: the merged beer card's preferred action becomes
// 'ai' — the pending re-extract review surfaces first, per the established
// queue order (ai before market_prices). The market work stays queued.
{
    const model = buildModelSandbox();
    model.mergeProductStatus([beerRow]);
    model.mergeAiCases([beerCaseFixed]);
    const mergedBeer = model.products[0];
    const routing = buildRoutingSandbox(src);
    routing.setData({ autopilot: { holds: [] } });
    check('(g) merged beer: preferred action is ai (the pending review surfaces first)',
        routing.workbenchActionKey(mergedBeer), 'ai');
    check('(g) merged beer: next-step is the spec text',
        routing.workbenchNextStep(mergedBeer, 'ai'), 'مشخصات استخراج‌شده را با پیام منبع بررسی کنید.');
    check('(g) merged beer: market_prices still matches (one tab away)',
        routing.workbenchQueueMatches(mergedBeer, 'market_prices'), true);
    const model2 = buildModelSandbox();
    model2.mergeProductStatus([beerRow]);
    const unmergedBeer = model2.products[0];
    check('(g) unmerged beer: preferred action is still market_prices',
        routing.workbenchActionKey(unmergedBeer), 'market_prices');
}

// The JS merge is ensureProduct's existing product_id lookup — no new helper.
checkSource('ensureProduct still looks up by product_id first',
    src, 'let product = productId && byProductId.get(productId);');

console.log(`\n${pass} PASS, ${fail} FAIL`);
if (fail) process.exit(1);
console.log('ALL DUPLICATE-CARD MERGE CHECKS PASSED');
