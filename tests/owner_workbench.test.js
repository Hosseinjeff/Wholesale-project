const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');

class Element {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase();
    this.childNodes = [];
    this.attributes = {};
    this.listeners = {};
    this.dataset = {};
    this.textContent = '';
    this.className = '';
    this.classList = {add: name => {
      const names = new Set(this.className.split(/\s+/).filter(Boolean));
      names.add(name);
      this.className = [...names].join(' ');
    }};
  }
  appendChild(child) { this.childNodes.push(child); return child; }
  append(...children) { children.forEach(child => this.appendChild(child)); }
  prepend(child) { this.childNodes.unshift(child); }
  replaceChildren(...children) { this.childNodes = [...children]; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  addEventListener(name, listener) { this.listeners[name] = listener; }
  get text() { return this.textContent + this.childNodes.map(child => child.text || child.textContent || '').join(''); }
}

async function main() {
  const root = new Element('div');
  const document = {
    documentElement: {dir:'rtl'},
    getElementById: id => id === 'molavi-owner-workflow' ? root : null,
    createElement: tag => new Element(tag),
    createTextNode: text => ({textContent:String(text), text:String(text)}),
  };
  const base = 'https://api.example.test';
  const workflowApis = Object.fromEntries([
    ['product_status','/pipeline/product-status-snapshots'],
    ['ai_confirmations','/pipeline/ai-confirmations'],
    ['reference_items','/reference/item-reviews'],
    ['supplier_prices','/pipeline/supplier-price-evidence'],
    ['sheet_conflicts','/pipeline/sheet-conflicts'],
    ['publication_holds','/pipeline/publication-holds'],
    ['reference_decisions','/reference/decisions'],
  ].map(([key,path]) => [key,{url:base+path,token:`scoped-${key}`} ]));
  const data = {
    'product-status-snapshots': {products:[{product_id:'product-1',product_name:'Noodle 12 pack',
      channel:'@supplier',latest_offer_id:'offer-1',status:'sell',update_needed:false,
      supplier_price_state:'blocked',reference_match_id:'REF-12',reference_approval_state:'approved'},
      {product_id:'product-2',product_name:'Ketchup',channel:'@supplier',status:'sell',update_needed:true}],
      snapshots:[{captured_at:'2026-11-03T08:00:00Z'}],latest_snapshot_id:'snapshot-1'},
    'ai-confirmations': {cases:[]},
    'item-reviews': {cases:[]},
    'supplier-price-evidence': {offers:[{product_id:'product-1',name:'Noodle 12 pack',channel:'@supplier',
      sale_price:120000,state:'blocked',source_revision:'revision-1',source_timestamp:'2026-11-03T07:00:00Z'}]},
    'sheet-conflicts': {conflicts:[{conflict_id:'sheet:one',product_id:'product-1',product_name:'Noodle 12 pack',
      channel:'@supplier',field:'Sale Price',base:100,server:120,sheet:140,server_owned:false,sheet_preserved:false}]},
    'publication-holds': {holds:[]},
    'decisions': {events:[]},
    operations: {operations:{by_state:{},items:[],failed_publication_ids:[],failed_batch_ids:[],publication_holds:0,sheet_conflicts:1}},
  };
  const calls = [];
  const fetch = async (url, options = {}) => {
    calls.push({url:String(url),options});
    if (String(url) === '/admin-ajax.php') {
      const records = new URLSearchParams(options.body).get('records');
      return {ok:true,status:200,json:async()=>({success:true,data:{stored:JSON.parse(records).length}})};
    }
    const path = new URL(String(url),base).pathname;
    if (path.endsWith('/supplier-price-evidence') && options.method === 'POST') {
      const payload = JSON.parse(options.body);
      return {ok:true,status:200,json:async()=>({ok:true,product_id:payload.product_id,
        source_revision:payload.expected_revision,verified_at:'2026-11-03T08:30:00Z',publish_job:'publish:one'})};
    }
    const key = path.split('/').pop();
    const response = key === 'operations-dashboard' ? data.operations
      : key === 'product-status-snapshots' ? data['product-status-snapshots']
      : key === 'ai-confirmations' ? data['ai-confirmations']
      : key === 'item-reviews' ? data['item-reviews']
      : key === 'supplier-price-evidence' ? data['supplier-price-evidence']
      : key === 'sheet-conflicts' ? data['sheet-conflicts']
      : key === 'publication-holds' ? data['publication-holds']
      : key === 'decisions' ? data.decisions : null;
    assert(response, `unexpected API path: ${path}`);
    return {ok:true,status:200,json:async()=>({ok:true,...response})};
  };
  const window = {molaviOwnerWorkflow:{page:'molavi-operations',apiUrl:base+'/pipeline/operations-dashboard',
    token:'scoped-operations',workflowApis,pageUrls:{},reviewStoreUrl:'/admin-ajax.php',reviewStoreNonce:'nonce'},
    parent:null,location:{href:'https://site.test/wp-admin/admin.php?page=molavi-operations',
      pathname:'/wp-admin/admin.php',search:'?page=molavi-operations'},crypto:webcrypto,history:{replaceState(){}},
    addEventListener(){},confirm:()=>true};
  window.parent = window;
  vm.runInNewContext(fs.readFileSync('shop-wp/molavi-shop/includes/owner-workflows.js','utf8'),
    {window,document,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,Number,String,Boolean,Array,Object,Error,
      URLSearchParams,console, setTimeout, clearTimeout});
  for (let attempt=0; attempt<20; attempt++) {
    if (root.text.includes('Noodle 12 pack')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }

  const walk = element => [element,...element.childNodes.filter(child=>child instanceof Element).flatMap(walk)];
  const cards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(cards.length,1,'the review queue renders one product card at a time');
  assert.match(root.text,/کالای ۱ از ۲/,'only the current product is rendered in the review queue');
  assert.match(cards[0].text,/تازگی قیمت تأمین‌کننده/);
  assert.match(cards[0].text,/تعارض داده/);
  assert.match(cards[0].text,/REF-12/);
  assert(!walk(root).some(element => element.tagName === 'IFRAME'));
  assert(!walk(root).some(element => element.className.split(/\s+/).includes('molavi-admin-tabs')));
  const nextButton = walk(root).find(element => element.tagName === 'BUTTON' && element.textContent === 'بعدی');
  await nextButton.listeners.click();
  let visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'next navigation swaps the active card instead of loading a product list');
  assert.match(visibleCards[0].text,/Ketchup/,'next moves to the next product without fetching the page again');
  const previousButton = walk(root).find(element => element.tagName === 'BUTTON' && element.textContent === 'قبلی');
  await previousButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.match(visibleCards[0].text,/Noodle 12 pack/);
  for (const key of Object.keys(workflowApis)) {
    const call = calls.find(item => item.url === workflowApis[key].url);
    assert(call, `${key} API must load independently`);
    assert.equal(call.options.headers.Authorization,`Bearer scoped-${key}`);
  }
  assert(calls.some(item => item.url === '/admin-ajax.php'), 'source context is retained in WordPress');
  const verifyButton = walk(cards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent.includes('منبع فعلی را دیدم'));
  assert(verifyButton && !verifyButton.disabled, 'one-click supplier price review stays inside its product card');
  await verifyButton.listeners.click();
  const verification = calls.find(item => item.options.method === 'POST'
    && item.url === workflowApis.supplier_prices.url);
  assert(verification, 'supplier verification submits asynchronously without a page form post');
  assert.equal(verification.options.headers.Authorization,'Bearer scoped-supplier_prices');
  assert(calls.filter(item => item.url === workflowApis.product_status.url).length >= 2,
    'successful action refreshes the dashboard data asynchronously');
  assert.match(root.text,/بررسی قیمت ثبت شد/,'success feedback remains visible after the in-place refresh');
  assert.equal(window.location.href,'https://site.test/wp-admin/admin.php?page=molavi-operations',
    'review actions do not navigate or reload the browser page');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
