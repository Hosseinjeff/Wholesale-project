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
      channel:'@supplier',latest_offer_id:'owner-confirm:owner-decision-1',status:'imported',update_needed:false,
      supplier_price_state:'blocked',reference_match_id:'REF-12',reference_approval_state:'approved'},
      {product_id:'product-2',product_name:'Ketchup',channel:'@supplier',status:'sell',update_needed:true}],
      snapshots:[{captured_at:'2026-11-03T08:00:00Z'}],latest_snapshot_id:'snapshot-1'},
    'ai-confirmations': {cases:[],recent_decisions:[{product_name:'Noodle 12 pack',channel:'@supplier',
      source_post_url:'https://t.me/supplier/18',decision:{decision_id:'owner-decision-1',
        choice:'not_product',created_at:'2026-11-03T08:00:00Z'}}]},
    'item-reviews': {cases:[]},
    'supplier-price-evidence': {offers:[{product_id:'product-1',name:'Noodle 12 pack',channel:'@supplier',
      sale_price:120000,state:'blocked',source_revision:'revision-1',source_timestamp:'2026-11-03T07:00:00Z'}]},
    'sheet-conflicts': {conflicts:[{conflict_id:'sheet:one',product_id:'product-1',product_name:'Noodle 12 pack',
      channel:'@supplier',field:'Status',base:'needs_review',server:'not_product',sheet:'imported',
      source_revision:'owner-confirm:owner-decision-1',server_owned:false,sheet_preserved:false}]},
    'publication-holds': {holds:[]},
    'decisions': {events:[]},
    operations: {operations:{by_state:{},items:[],failed_publication_ids:[],failed_batch_ids:[],publication_holds:0,sheet_conflicts:1}},
  };
  const calls = [];
  const storedReviewRows = [];
  const referenceAssignments = [];
  const fetch = async (url, options = {}) => {
    calls.push({url:String(url),options});
    if (String(url) === '/admin-ajax.php') {
      const records = new URLSearchParams(options.body).get('records');
      const parsed = JSON.parse(records);
      storedReviewRows.push(...parsed);
      return {ok:true,status:200,json:async()=>({success:true,data:{stored:parsed.length}})};
    }
    const path = new URL(String(url),base).pathname;
    if (path.endsWith('/sheet-conflicts') && options.method === 'POST') {
      data['sheet-conflicts'].conflicts = [];
      return {ok:false,status:409,json:async()=>({ok:false,error:'source_changed',refreshed:true})};
    }
    if (path.endsWith('/supplier-price-evidence') && options.method === 'POST') {
      const payload = JSON.parse(options.body);
      return {ok:true,status:200,json:async()=>({ok:true,product_id:payload.product_id,
        source_revision:payload.expected_revision,verified_at:'2026-11-03T08:30:00Z',publish_job:'publish:one'})};
    }
    if (path.endsWith('/item-reviews') && options.method === 'POST') {
      const payload = JSON.parse(options.body);
      referenceAssignments.push({payload,authorization:options.headers.Authorization});
      const receipt = {ok:true,case_id:payload.case_id,offer_id:payload.offer_id,
        snapshot_id:payload.snapshot_id,choice:'match',reference_id:payload.reference_id,
        reference_name:'آبمیوه تتراپک سون ۲۴ عددی',decision_id:'a'.repeat(64),
        price_role:payload.price_role,projection_state:'awaiting_ai_confirmation',publish_job:null};
      data['ai-confirmations'].cases = data['ai-confirmations'].cases.map(item => item.offer_id === payload.offer_id
        ? {...item,reference_assignment:{choice:'match',reference_id:payload.reference_id,
          reference_name:receipt.reference_name,decision_id:receipt.decision_id,
          price_role:payload.price_role,snapshot_id:payload.snapshot_id}} : item);
      return {ok:true,status:200,json:async()=>receipt};
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
  assert.match(cards[0].text,/آخرین مقدار هماهنگ‌شده: needs_review/,
    'the conflict card shows the baseline needed to tell which side changed');
  assert.match(cards[0].text,/هر دو مقدار از آخرین هماهنگی تغییر کرده‌اند/,
    'the card explains why the Sheet value needs an explicit manual-edit confirmation');
  assert.match(cards[0].text,/مالک قبلاً «کالا نیست» را ثبت کرده است/,
    'a server status derived from an earlier owner decision shows that provenance');
  assert(walk(cards[0]).some(element => element.tagName === 'A'
    && element.href === 'https://t.me/supplier/18'),
  'the conflict card links the related prior decision back to its source post');
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
  secondaryDetails.open = true;
  const serverConflictChoice = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'اعمال مقدار فعلی سرور');
  assert(serverConflictChoice,'the Sheet conflict can be acted on from its product card');
  await serverConflictChoice.listeners.click();
  assert.match(root.text,/نسخهٔ این تعارض کهنه بود؛ اطلاعات تازه بارگذاری شد/,
    'a stale source conflict refreshes in place instead of becoming an error wall');
  assert(!root.text.includes('این تعارض قبلاً تغییر کرده یا تعیین تکلیف شده است'),
    'the dashboard does not leave a stale conflict error visible after refreshing');
  const conflictAttemptRows = storedReviewRows.filter(item => item.review_type === 'sheet_conflict'
    && item.review_id === 'sheet:one' && ['pending','failed'].includes(item.state));
  assert.equal(conflictAttemptRows.length,2,'the local review log records both the attempt and stale response');
  assert.equal(conflictAttemptRows[0].event_id,conflictAttemptRows[1].event_id,
    'the final conflict result updates the same WordPress event instead of stranding a pending record');
  assert.equal(conflictAttemptRows[1].state,'failed');
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

  const juiceMessage = 'آبمیوه تکه میوه سانی نس،۲۴ عددی\nقیمت فروش ما: ۱/۲۰۰/۰۰۰ تومان\nقیمت مصرف کننده: ۹۵/۰۰۰ تومان';
  data['ai-confirmations'].cases = [{
    case_id:`ai:${'e'.repeat(64)}`,offer_id:'historical:juice',signature:'e'.repeat(64),
    product_name:'آبمیوه تکه میوه سانی نس،۲۴ عددی',channel:'@bonakdarjavan',source_channel:'telegram',
    message_id:'7286',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:'1200000',sale_price:'1200000',actual_price:'95000',
    package_count:'24',stock_status:'Unknown',reason:'Unusual discount -1163%; Historical price needs current supplier verification',
    original_message:juiceMessage,reference_snapshot_id:'snapshot-current',reference_candidates:[{
      reference_id:'REF-JUICE-24',name:'آبمیوه تتراپک سون ۲۴ عددی',score:.96,name_similarity:.96,
      unit_price:50000,package_price:1200000,package_count:24,price_role_hint:{role:'unit'},
      comparisons:[{key:'package_count',label:'تعداد بسته',source:'24',reference:'24',state:'match'},
        {key:'volume',label:'حجم',source:'220 میلی لیتر',reference:'220 میلی لیتر',state:'match'}]
    }]
  }];
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1);
  assert.match(visibleCards[0].text,/۵۰٬۰۰۰ تومان برای هر واحد/,
    'the owner card shows the per-unit calculation if the extracted amount is a 24-count package total');
  const packageConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.dataset.priceRole === 'package');
  assert(packageConfirm && packageConfirm.disabled,
    'the alternate package-price confirmation remains gated until the current historical price is checked');
  const currentSourceCheck = walk(visibleCards[0]).find(element => element.tagName === 'INPUT');
  assert(currentSourceCheck,'historical price decisions require an explicit current-source check');
  let referenceSelect = walk(visibleCards[0]).find(element => element.tagName === 'SELECT'
    && element.attributes['aria-label'] === 'انتخاب کالای مرجع');
  let referenceRole = walk(visibleCards[0]).find(element => element.tagName === 'SELECT'
    && element.attributes['aria-label'] === 'مبنای قیمت مرجع');
  assert(referenceSelect && referenceRole,'the extraction card exposes in-progress reference selection controls');
  referenceSelect.value = 'REF-JUICE-24';
  referenceSelect.listeners.change();
  assert.match(visibleCards[0].text,/تعداد بسته: یکسان/,'the selected reference comparison shows matching pack data');
  referenceRole.value = 'unit';
  referenceRole.listeners.change();
  const assignReference = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'ثبت مرجع برای این مورد');
  assert(assignReference && !assignReference.disabled,'reference assignment is available before source-price confirmation');
  await assignReference.listeners.click();
  assert.equal(referenceAssignments.length,1);
  assert.equal(referenceAssignments[0].authorization,'Bearer scoped-reference_items',
    'in-card reference assignment uses its own route-scoped AJAX token');
  assert.equal(referenceAssignments[0].payload.action,'assign_ai_reference');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'assigning a reference does not confirm historical price or stock');
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.match(visibleCards[0].text,/مرجع در حال بررسی: آبمیوه تتراپک سون ۲۴ عددی/,
    'the saved assignment remains visible in the same workbench card');
  const stillGatedConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.dataset.priceRole === 'package');
  assert(stillGatedConfirm && stillGatedConfirm.disabled,
    'saving a reference does not unlock historical price confirmation');
  const sourceCheckAfterAssignment = walk(visibleCards[0]).find(element => element.tagName === 'INPUT');
  sourceCheckAfterAssignment.checked = true;
  sourceCheckAfterAssignment.listeners.change();
  const confirmAfterVerification = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.dataset.priceRole === 'package');
  assert(confirmAfterVerification && !confirmAfterVerification.disabled,
    'the existing current-price guard still unlocks confirmation only after its own check');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'reviewing the ambiguous package basis never submits an owner decision');

  const mookokoMessage = 'آبمیوه شیشه ای موکوکوکو(تیکه نارگیل)\nطعم های موجود : بلوبری،هندوانه،نارگیل،پرتقال،طالبی،توت فرنگی و انبه\nکارتن ۱۲ عددی\nقیمت فروش ما : ۵۵/۰۰۰ تومان\nقیمت مصرف کننده : ۱۰۰/۰۰۰ تومان';
  const mookokoVariants = ['بلوبری','هندوانه','نارگیل','پرتقال','طالبی','توت فرنگی','انبه'];
  data['ai-confirmations'].cases = mookokoVariants.map((variant,index) => ({
    case_id:`ai:${(index+1).toString(16).repeat(64)}`,offer_id:`historical:mookoko:${index}`,
    signature:(index+1).toString(16).repeat(64),product_name:`آبمیوه شیشه ای موکوکوکو(تیکه نارگیل) ${index === 0 ? 'موجود : ' : ''}${variant}`,
    variation_type:variant,channel:'@bonakdarjavan',source_channel:'telegram',message_id:'7335',
    batch_id:'reextract:mookoko',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:'55000',sale_price:'55000',actual_price:'100000',package_count:'12',
    stock_status:'Unknown',reason:'Historical price needs current supplier verification',brand:'موکوکوکو',
    packaging:'شیشه ای',original_message:mookokoMessage
  }));
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,
    'same-source, same-price, same-package flavors share one dashboard review card');
  assert.match(visibleCards[0].text,/انواع هم‌قیمت از یک پیام/,
    'the grouped card tells the owner exactly why variants are reviewed together');
  for (const variant of mookokoVariants) assert(visibleCards[0].text.includes(variant),`grouped card shows ${variant}`);
  const groupedConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && /تأیید هر (۷|7) نوع/.test(element.textContent));
  assert(groupedConfirm && groupedConfirm.disabled,
    'one grouped decision action covers all equal-price variants and still requires a current-price check');
  const groupedSourceCheck = walk(visibleCards[0]).find(element => element.tagName === 'INPUT');
  groupedSourceCheck.checked = true;
  groupedSourceCheck.listeners.change();
  assert(!groupedConfirm.disabled,'the grouped confirmation unlocks after checking a current supplier source');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'grouping variants for review does not submit any owner decisions');

  const noodleMessage = 'نودل لیوانی شف هو،۱۲ عددی\nگوشت،مرغ،فلفلی،قارچ پنیر،سبزیجات\nقیمت فروش ما: ۷۵/۰۰۰ تومان\nقیمت مصرف کننده: ۱۳۰/۰۰۰ تومان';
  const noodleVariants = ['سبزیجات','قارچ پنیر','فلفلی','مرغ'];
  data['ai-confirmations'].cases = noodleVariants.map((variant,index) => ({
    case_id:`ai:${(index+1).toString(16).repeat(64)}`,offer_id:`historical:noodle:${index}`,
    signature:(index+1).toString(16).repeat(64),product_name:`نودل لیوانی شف هو،۱۲ عددی ${variant}`,
    variation_type:variant,channel:'@bonakdarjavan',source_channel:'telegram',message_id:'7346',
    batch_id:'reextract:noodle',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:'75000',sale_price:'75000',actual_price:'130000',package_count:'12',
    stock_status:'Unknown',reason:'Historical price needs current supplier verification',original_message:noodleMessage
  }));
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'four extracted noodle flavors remain one same-price review card');
  assert.match(visibleCards[0].text,/متن منبع 5 طعم یا نوع را مشخص می‌کند، اما فقط 4 مورد/,
    'the card flags the source-listed fifth flavor omitted from extraction');
  const incompleteNoodleConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && /تأیید هر (۴|4) نوع/.test(element.textContent));
  const noodleSourceCheck = walk(visibleCards[0]).find(element => element.tagName === 'INPUT');
  noodleSourceCheck.checked = true;
  noodleSourceCheck.listeners.change();
  assert(incompleteNoodleConfirm && incompleteNoodleConfirm.disabled,
    'an incomplete source variant group cannot be confirmed as complete');

  const cakeMessage = 'کیکس مکس قوطی در ۳ طعم(زرد و آبی و بنفش) :\nباکس :۲۴عددی\nبنفش با قیمت مصرف: ۱۴۵/۰۰۰تومن\nآبی و زرد بدون قیمت مصرف';
  data['ai-confirmations'].cases = ['زرد','بنفش'].map((variant,index) => ({
    case_id:`ai:${(index+1).toString(16).repeat(64)}`,offer_id:`historical:cake:${index}`,
    signature:(index+1).toString(16).repeat(64),product_name:`کیکس مکس قوطی در ۳ طعم(زرد و آبی و بنفش) ${variant}`,
    variation_type:`(زرد و آبی و بنفش) ${variant}`,channel:'@bonakdarjavan',source_channel:'telegram',message_id:'7171',
    batch_id:'reextract:cake',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:'1590000',sale_price:'66250',package_price:'1590000',actual_price:'145000',
    package_count:'24',stock_status:'Unknown',reason:'Historical price needs current supplier verification',original_message:cakeMessage
  }));
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'the two same-price cake flavors are grouped for review');
  assert.match(visibleCards[0].text,/فقط 2 مورد در صف استخراج/,
    'the source says three flavors while only two extracted cases exist');
  assert.match(visibleCards[0].text,/قیمت فروش تأمین‌کننده: ۶۶٬۲۵۰ تومان/,
    'the card shows the per-unit sale field instead of its raw carton total');
  assert.match(visibleCards[0].text,/قیمت کل بسته\/کارتن تأمین‌کننده: ۱٬۵۹۰٬۰۰۰ تومان/);
  assert.match(visibleCards[0].text,/مبلغ فروش تأمین‌کننده را با برچسب روشن نشان نمی‌دهد/,
    'an extracted price absent from labeled source text is flagged for verification');
  const unsupportedCakeConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && /تأیید هر (۲|2) نوع/.test(element.textContent));
  assert(unsupportedCakeConfirm && unsupportedCakeConfirm.disabled,
    'the card cannot fast-confirm an ungrounded extracted price');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'source mismatch checks never submit owner decisions');

  const packMessage = 'پیک: اتمام موجودی\nدر کارتن ۱۲ تایی\nقیمت هر یک بسته: ۲/۵۲۰/۰۰۰ تومان\nقیمت مصرف: ۲۶۰/۰۰۰ تومان';
  data['ai-confirmations'].cases = [{
    case_id:`ai:${'f'.repeat(64)}`,offer_id:'historical:pack',signature:'f'.repeat(64),
    product_name:'پیک',channel:'@bonakdarjavan',source_channel:'telegram',message_id:'7306',
    batch_id:'reextract:pack',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:'210000',sale_price:'210000',actual_price:'260000',package_count:'12',
    stock_status:'Unknown',reason:'Re-extraction result awaiting owner review',original_message:packMessage
  }];
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.match(visibleCards[0].text,/مبلغ منبع ۲٬۵۲۰٬۰۰۰ تومان با ۲۱۰٬۰۰۰ تومان × ۱۲ برابر است/,
    'a package amount divided by the explicit count is shown as arithmetic evidence, not proof of price basis');
  assert.match(visibleCards[0].text,/مبنای قیمت را ثابت نمی‌کند/);
  assert(!visibleCards[0].text.includes('قیمت فروش تأمین‌کننده: ۲٬۵۲۰٬۰۰۰ تومان'),
    'a derived unit sale is not mislabeled with the raw package total');
  assert.match(visibleCards[0].text,/پست مبدأ تاریخی صریحاً اتمام موجودی را ذکر می‌کند/,
    'the card surfaces source stock text that the extraction left unknown');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'package-price arithmetic does not submit an owner decision');

  const partialStockMessage = 'آبمیوه تست در ۲ طعم (قرمز و آبی)\nاتمام موجودی\nقیمت فروش ما: ۱۰/۰۰۰ تومان';
  data['ai-confirmations'].cases = ['Unknown','Out of Stock'].map((stock,index) => ({
    case_id:`ai:${(index+1).toString(16).repeat(64)}`,offer_id:`historical:stock:${index}`,
    signature:(index+1).toString(16).repeat(64),product_name:`آبمیوه تست ${index ? 'قرمز' : 'آبی'}`,
    variation_type:index ? 'قرمز' : 'آبی',channel:'@bonakdarjavan',source_channel:'telegram',message_id:'7307',
    batch_id:'reextract:stock',source_post_url:'',message_timestamp:'1787571328',historical_reextract:true,
    price_type:'unit',detected_price:'10000',sale_price:'10000',package_count:'12',stock_status:stock,
    reason:'Historical price needs current supplier verification',original_message:partialStockMessage
  }));
  await refreshButton.listeners.click();
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.match(visibleCards[0].text,/وضعیت استخراج‌شده نامشخص است/,
    'one variant with an extracted stock value does not hide another variant whose stock is unknown');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
