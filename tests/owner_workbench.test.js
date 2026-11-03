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
  assert.match(root.text,/تعارض شیت · کالای ۱ از ۱/,'the first screen opens directly on the highest-priority non-empty queue');
  assert.match(cards[0].text,/تازگی قیمت تأمین‌کننده/);
  assert.match(cards[0].text,/تعارض داده/);
  assert.match(cards[0].text,/REF-12/);
  assert(!walk(root).some(element => element.tagName === 'IFRAME'));
  assert(!walk(root).some(element => element.className.split(/\s+/).includes('molavi-admin-tabs')));
  const allQueue = walk(root).find(element => element.tagName === 'BUTTON'
    && element.textContent.startsWith('همه کارهای باز'));
  await allQueue.listeners.click();
  assert.match(root.text,/کالای ۱ از ۲/,'the all-work queue keeps one product visible while navigating');
  const nextButton = walk(root).find(element => element.tagName === 'BUTTON' && element.textContent === 'بعدی');
  await nextButton.listeners.click();
  let visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'next navigation swaps the active card instead of loading a product list');
  assert.match(visibleCards[0].text,/Ketchup/,'next moves to the next product without fetching the page again');
  const previousButton = walk(root).find(element => element.tagName === 'BUTTON' && element.textContent === 'قبلی');
  await previousButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.match(visibleCards[0].text,/Noodle 12 pack/);
  const priceQueue = walk(root).find(element => element.tagName === 'BUTTON'
    && element.textContent.startsWith('قیمت تأمین‌کننده'));
  assert(priceQueue && !priceQueue.disabled, 'each workflow queue has its own visible product count');
  const callsBeforeQueueSwitch = calls.length;
  await priceQueue.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'queue filters keep the unified product view to one card');
  assert.equal(priceQueue.attributes['aria-pressed'],'true','the active queue is visibly selected');
  assert.match(visibleCards[0].text,/تازگی قیمت تأمین‌کننده/,'the selected queue opens its matching action');
  const secondaryDetails = walk(visibleCards[0]).find(element => element.className
    .split(/\s+/).includes('molavi-secondary-product-details'));
  assert(secondaryDetails && secondaryDetails.open !== true,
    'other product checks stay collapsed until requested');
  assert.equal(calls.length,callsBeforeQueueSwitch,'switching queues is immediate and does not reload data');
  for (const key of Object.keys(workflowApis)) {
    const call = calls.find(item => item.url === workflowApis[key].url);
    assert(call, `${key} API must load independently`);
    assert.equal(call.options.headers.Authorization,`Bearer scoped-${key}`);
  }
  assert(calls.some(item => item.url === '/admin-ajax.php'), 'source context is retained in WordPress');
  const verifyButton = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent.includes('منبع فعلی را دیدم'));
  assert(verifyButton && !verifyButton.disabled, 'one-click supplier price review stays inside its product card');
  const mountedBoard = walk(root).find(element => element.className.split(/\s+/).includes('molavi-workbench-board'));
  await verifyButton.listeners.click();
  const verification = calls.find(item => item.options.method === 'POST'
    && item.url === workflowApis.supplier_prices.url);
  assert(verification, 'supplier verification submits asynchronously without a page form post');
  assert.equal(verification.options.headers.Authorization,'Bearer scoped-supplier_prices');
  assert(calls.filter(item => item.url === workflowApis.product_status.url).length >= 2,
    'successful action refreshes the dashboard data asynchronously');
  assert.match(root.text,/بررسی قیمت ثبت شد/,'success feedback remains visible after the in-place refresh');
  assert.equal(walk(root).find(element => element.className.split(/\s+/).includes('molavi-workbench-board')),
    mountedBoard,'AJAX updates the mounted dashboard instead of replacing or reloading the page');
  assert.equal(window.location.href,'https://site.test/wp-admin/admin.php?page=molavi-operations',
    'review actions do not navigate or reload the browser page');

  const teaMessage = 'چای شهرزاد فلزی ۳۵۰ گرم،۶ عدد\n\nقیمت فروش ما عطری: ۷۸۰/۰۰۰ تومان\nقیمت مصرف کننده عطری:۱۰۳۰/۰۰۰ت\nقیمت فروش ما ساده: ۶۸۰/۰۰۰ تومان\nقیمت مصرف کننده ساده: ۸۷۰/۰۰۰ تومان';
  const teaCase = (letter, salePrice, actualPrice) => ({
    case_id:`ai:${letter.repeat(64)}`,offer_id:`historical:${letter}`,signature:letter.repeat(64),
    product_name:'چای شهرزاد فلزی ۳۵۰ گرم،۶ عدد',channel:'@bonakdarjavan',source_channel:'telegram',
    message_id:'7289',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:String(salePrice),sale_price:String(salePrice),
    actual_price:String(actualPrice),package_count:'6',stock_status:'Unknown',
    reason:'Historical price needs current supplier verification',original_message:teaMessage
  });
  data['ai-confirmations'].cases = [teaCase('a',680000,870000),teaCase('b',780000,1030000)];
  const refreshButton = walk(root).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'به‌روزرسانی صف');
  await refreshButton.listeners.click();
  const extractionQueue = walk(root).find(element => element.tagName === 'BUTTON'
    && element.textContent.startsWith('استخراج'));
  await extractionQueue.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'AI review also keeps only the current product card visible');
  const variantLabels = walk(visibleCards[0]).filter(element => element.className
    .split(/\s+/).includes('molavi-ai-source-variant')).map(element => element.textContent);
  assert.deepEqual(new Set(variantLabels),new Set([
    'برچسب نوع در متن پیام: ساده','برچسب نوع در متن پیام: عطری'
  ]),'distinct source-labeled offers sharing one product title remain distinguishable');
  const postLink = walk(visibleCards[0]).find(element => element.tagName === 'A'
    && element.href === 'https://t.me/bonakdarjavan/7289');
  assert(postLink,'a missing Telegram URL is safely recovered from its public channel and message ID');
  assert.match(postLink.textContent,/تاریخی/,'old source posts are labeled so they are not mistaken for current prices');
  assert(walk(visibleCards[0]).some(element => element.tagName === 'A'
    && element.href === 'https://t.me/s/bonakdarjavan'),
  'historical cards also link directly to newer supplier posts');
  assert.match(visibleCards[0].text,/پست مبدأ به‌تنهایی تازگی قیمت را ثابت نمی‌کند/);
  assert.match(visibleCards[0].text,/قیمت را در پیام جدیدتر/);
  assert.match(visibleCards[0].text,/زمان پیام منبع:/);
  assert(!visibleCards[0].text.includes('1787571328'),'source times are shown as readable dates');
  const aiPosts = calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url);
  assert.equal(aiPosts.length,0,'inspecting the card never submits an owner decision');

  const aloeMessage = 'نوشيدنى آلوورا بر پايه عسل\n۱/۵ لیتر تعداد ۶ عددی\nقیمت فروش ما: ۱۰۵/۰۰۰ تومان\nقیمت مصرف کننده: ۱۵۰/۰۰۰ تومان\n۵۰۰ میل تعداد ۲۰ عددی\nقیمت فروش ما: ۶۰/۰۰۰ تومان\nقیمت مصرف کننده: ۹۰/۰۰۰ تومان\n۲۵۰ میل تعداد ۲۴ عددی\nقیمت فروش ما: ۵۰/۰۰۰ تومان\nقیمت مصرف کننده: ۷۰/۰۰۰ تومان';
  const aloeCase = (letter, salePrice, actualPrice, volume, packageCount) => ({
    case_id:`ai:${letter.repeat(64)}`,offer_id:`historical:${letter}`,signature:letter.repeat(64),
    product_name:'نوشيدنى آلوورا بر پايه عسل',channel:'@bonakdarjavan',source_channel:'telegram',
    message_id:'7281',batch_id:'reextract:aloe',source_post_url:'',message_timestamp:'1787571328',
    historical_reextract:true,price_type:'unit',detected_price:String(salePrice),sale_price:String(salePrice),
    actual_price:String(actualPrice),volume,package_count:String(packageCount),stock_status:'Unknown',
    reason:'Historical price needs current supplier verification',original_message:aloeMessage
  });
  data['ai-confirmations'].cases = [
    aloeCase('c',105000,150000,'1.5 لیتر',6),aloeCase('d',50000,70000,'250 میل',24)
  ];
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1);
  assert.match(visibleCards[0].text,/۶۰٬۰۰۰ تومان/,
    'the card identifies supplier prices present in the source but missing from extraction');
  assert.match(visibleCards[0].text,/بازاستخراج این پیام برای بررسی کامل لازم است/);
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'source completeness checks do not approve or modify extracted offers');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
