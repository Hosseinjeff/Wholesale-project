const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {webcrypto} = require('node:crypto');
const workflowScriptPath = process.env.MOLAVI_OWNER_WORKFLOW_SCRIPT
  || 'shop-wp/molavi-shop/includes/owner-workflows.js';

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
  appendChild(child) { this.childNodes.push(child); child.parentNode=this; return child; }
  insertBefore(child, reference) {
    const index=this.childNodes.indexOf(reference);
    if (index<0) return this.appendChild(child);
    this.childNodes.splice(index,0,child); child.parentNode=this; return child;
  }
  remove() {
    if (this.parentNode) this.parentNode.childNodes=this.parentNode.childNodes.filter(child=>child!==this);
  }
  contains(candidate) { return this===candidate || this.childNodes.some(child=>child instanceof Element && child.contains(candidate)); }
  querySelector(selector) {
    const className=String(selector || '').startsWith('.') ? String(selector).slice(1) : '';
    const visit=element=>{
      if (className && element.className.split(/\s+/).includes(className)) return element;
      for (const child of element.childNodes) {
        if (child instanceof Element) { const match=visit(child); if (match) return match; }
      }
      return null;
    };
    return visit(this);
  }
  replaceWith(replacement) {
    if (!this.parentNode) return;
    const siblings=this.parentNode.childNodes;
    const index=siblings.indexOf(this);
    if (index>=0) { siblings.splice(index,1,replacement); replacement.parentNode=this.parentNode; }
  }
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
    ['telegram_operators','/pipeline/telegram-operator-chats'],
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
    operations: {operations:{by_state:{queued:0,running:0},items:[{id:'batch:queue-1',kind:'batch',
      state:'waiting_reference',attempts:2,error:'reference_decision_required',
      next_action:'resolve_reference_decision',age_seconds:3600}],actionable_count:2,
      next_cursor:'cursor-next',has_more:true,failed_publication_ids:['publish:failed-1'],
      failed_batch_ids:['batch:failed-1'],
      publication_holds:0,sheet_conflicts:1,worker:{status:'alive',heartbeat_age_seconds:4,
        state:'idle',progress_status:'active',progress_age_seconds:4,current_job_id:null,last_error_code:''},
      pending_telegram_chats:[]}},
  };
  const calls = [];
  const storedReviewRows = [];
  const referenceAssignments = [];
  let mediaPreview = null;
  const fetch = async (url, options = {}) => {
    calls.push({url:String(url),options});
    if (String(url) === '/admin-ajax.php') {
      const records = new URLSearchParams(options.body).get('records');
      const parsed = JSON.parse(records);
      storedReviewRows.push(...parsed);
      return {ok:true,status:200,json:async()=>({success:true,data:{stored:parsed.length}})};
    }
    const path = new URL(String(url),base).pathname;
    if (path.endsWith('/ai-confirmations') && new URL(String(url),base).searchParams.get('reextract') === 'preview') {
      return {ok:true,status:200,json:async()=>({ok:true,...mediaPreview})};
    }
    if (path.endsWith('/ai-confirmations') && new URL(String(url),base).searchParams.get('reextract') === 'run') {
      const runId=new URL(String(url),base).searchParams.get('run_id');
      return {ok:true,status:200,json:async()=>({ok:true,run_id:runId,state:'complete',
        total:1,completed:1,counts:{done:1},scope:'media_review',case_count:0,digest:'',
        outcomes:{reprocessed:1},products_found:1,offers_written:1,open_cases_remaining:1,
        catalog_publication:{state:'done',result:{ok:true,pushed:true}}})};
    }
    if (path.endsWith('/ai-confirmations') && options.method === 'POST'
        && JSON.parse(options.body).action === 'start_media_reextraction') {
      const payload=JSON.parse(options.body);
      return {ok:true,status:202,json:async()=>({ok:true,run_id:payload.run_id,queued:1,
        message_key:payload.message_key,scope:'media_review'})};
    }
    if (path.endsWith('/ai-confirmations') && options.method === 'POST'
        && JSON.parse(options.body).action === 'dismiss_media_message') {
      const payload=JSON.parse(options.body);
      return {ok:true,status:200,json:async()=>({ok:true,decision_id:payload.message_key,
        choice:'not_product',message_id:'photo-message'})};
    }
    if (path.endsWith('/operations-dashboard') && new URL(String(url),base).searchParams.get('cursor') === 'cursor-next') {
      return {ok:true,status:200,json:async()=>({ok:true,operations:{...data.operations.operations,
        items:[{id:'publish:queue-2',kind:'publish',state:'failed',attempts:8,error:'catalog_write_failed',
          next_action:'inspect_failure_and_reconcile',age_seconds:7200},
          {id:'batch:failed-1',kind:'batch',state:'failed',attempts:8,error:'RuntimeError',
            next_action:'inspect_failure_and_reconcile',age_seconds:9000}],
        next_cursor:null,has_more:false}})};
    }
    if (path.endsWith('/sheet-conflicts') && options.method === 'POST') {
      data['sheet-conflicts'].conflicts = [];
      return {ok:false,status:409,json:async()=>({ok:false,error:'source_changed',refreshed:true})};
    }
    if (path.endsWith('/supplier-price-evidence') && options.method === 'POST') {
      const payload = JSON.parse(options.body);
      data['supplier-price-evidence'].offers = [];
      return {ok:true,status:200,json:async()=>({ok:true,product_id:payload.product_id,
        source_revision:payload.expected_revision,verified_at:'2026-11-03T08:30:00Z',publish_job:'publish:one'})};
    }
    if (path.endsWith('/telegram-operator-chats') && options.method === 'POST') {
      const payload = JSON.parse(options.body);
      data.operations.operations.pending_telegram_chats = [];
      return {ok:true,status:200,json:async()=>({ok:true,chat_id:payload.chat_id,
        state:payload.action === 'approve' ? 'approved' : 'rejected',
        revision:payload.expected_revision,already_done:false})};
    }
    if (path.endsWith('/reconcile-publication-backlog') && options.method === 'POST') {
      const payload = JSON.parse(options.body);
      data.operations.operations.failed_publication_ids = [];
      data.operations.operations.failed_batch_ids = [];
      return {ok:true,status:200,json:async()=>({ok:true,sheet_digest:'a'.repeat(64),
        failed_publications_reconciled:payload.expected_publish_ids.length,
        failed_publication_ids:payload.expected_publish_ids,batch_jobs_reconciled:payload.expected_batch_ids.length,
        batch_job_ids_reconciled:payload.expected_batch_ids,batch_job_ids_left_open:[],
        catalog_sync:{images_left:0}})};
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
  let scrollToCalls = 0;
  const window = {molaviOwnerWorkflow:{page:'molavi-operations',apiUrl:base+'/pipeline/operations-dashboard',
    token:'scoped-operations',workflowApis,pageUrls:{},reviewStoreUrl:'/admin-ajax.php',reviewStoreNonce:'nonce'},
    parent:null,location:{href:'https://site.test/wp-admin/admin.php?page=molavi-operations',
      pathname:'/wp-admin/admin.php',search:'?page=molavi-operations'},crypto:webcrypto,history:{replaceState(){}},
    scrollX:0,scrollY:0,scrollTo(x,y){
      scrollToCalls++;
      if (x && typeof x === 'object') { this.scrollX=x.left; this.scrollY=x.top; }
      else { this.scrollX=x; this.scrollY=y; }
    },
    addEventListener(){},confirm:()=>true};
  window.parent = window;
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
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
  assert.match(root.text,/کارگر صف: فعال · پیشرفت: فعال، 4 ثانیه از آخرین پیشرفت · آخرین علامت 4 ثانیه پیش/,
    'the operations panel distinguishes a live worker from a queue that stopped progressing');
  assert.match(root.text,/batch:queue-1/,'queue rows expose a receipt ID that can be used to locate the blocked work');
  assert.match(root.text,/برای ادامه، تصمیم مرجع را در کارت تطبیق ثبت کنید/,
    'the blocked state tells the owner which decision moves the receipt forward');
  const loadQueuePageButton=walk(root).find(element=>element.tagName==='BUTTON'
    && element.textContent==='دریافت موارد بعدی');
  assert(loadQueuePageButton,'the queue offers an in-place continuation when another page exists');
  await loadQueuePageButton.listeners.click();
  assert.match(root.text,/publish:queue-2/,'the next queue page is appended without rebuilding the dashboard');
  assert(walk(root).some(element=>element.tagName==='BUTTON'
    && element.textContent==='همگام‌سازی و تطبیق همین دسته'),
    'a failed batch receipt has its own one-click source-verification action');
  assert(calls.some(item=>new URL(item.url,base).searchParams.get('cursor')==='cursor-next'),
    'the continuation request sends the opaque server cursor');
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
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.equal(visibleCards.length,1,'the refreshed supplier queue still shows one active product card');
  const conflictAttemptRows = storedReviewRows.filter(item => item.review_type === 'sheet_conflict'
    && item.review_id === 'sheet:one' && ['pending','failed'].includes(item.state));
  assert.equal(conflictAttemptRows.length,2,'the local review log records both the attempt and stale response');
  assert.equal(conflictAttemptRows[0].event_id,conflictAttemptRows[1].event_id,
    'the final conflict result updates the same WordPress event instead of stranding a pending record');
  assert.equal(conflictAttemptRows[1].state,'failed');
  for (const key of Object.keys(workflowApis)) {
    if (key === 'telegram_operators') continue; // write-only endpoint; pending rows arrive with operations state.
    const call = calls.find(item => item.url === workflowApis[key].url);
    assert(call, `${key} API must load independently`);
    assert.equal(call.options.headers.Authorization,`Bearer scoped-${key}`);
  }
  assert(calls.some(item => item.url === '/admin-ajax.php'), 'source context is retained in WordPress');
  const verifyButton = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent.includes('منبع فعلی را دیدم'));
  assert(verifyButton && !verifyButton.disabled, 'one-click supplier price review stays inside its product card');
  const mountedBoard = walk(root).find(element => element.className.split(/\s+/).includes('molavi-workbench-board'));
  const supplierProductCard = visibleCards[0];
  const statusRefreshesBeforeVerification = calls.filter(item => item.url === workflowApis.product_status.url).length;
  const scrollRestoresBeforeVerification = scrollToCalls;
  window.scrollX = 13;
  window.scrollY = 640;
  await verifyButton.listeners.click();
  const verification = calls.find(item => item.options.method === 'POST'
    && item.url === workflowApis.supplier_prices.url);
  assert(verification, 'supplier verification submits asynchronously without a page form post');
  assert.equal(verification.options.headers.Authorization,'Bearer scoped-supplier_prices');
  assert.equal(calls.filter(item => item.url === workflowApis.product_status.url).length,
    statusRefreshesBeforeVerification,'supplier acknowledgement does not rebuild the whole dashboard');
  assert.match(root.text,/بررسی قیمت ثبت شد/,'success feedback remains visible in the existing card');
  assert.equal(walk(root).find(element => element.className.split(/\s+/).includes('molavi-workbench-board')),
    mountedBoard,'supplier acknowledgement leaves the dashboard board mounted');
  assert.equal(walk(root).find(element => element.className.split(/\s+/).includes('molavi-product-card')),
    supplierProductCard,'supplier acknowledgement updates the existing product card instead of rebuilding it');
  assert.equal(scrollToCalls,scrollRestoresBeforeVerification,
    'supplier acknowledgement does not force a scroll restore after shortening the page');
  assert.deepEqual([window.scrollX,window.scrollY],[13,640],
    'supplier acknowledgement keeps the owner at the same scroll position after its in-place refresh');
  const activePriceQueue = walk(root).find(element => element.tagName === 'BUTTON'
    && element.textContent.startsWith('قیمت تأمین‌کننده'));
  assert(activePriceQueue && activePriceQueue.attributes['aria-pressed']==='true',
    'the dashboard keeps the supplier-price queue selected after its last item is acknowledged');
  assert(activePriceQueue.disabled && /۰/.test(activePriceQueue.textContent),
    'the now-empty supplier-price queue remains visible with a zero count');
  assert.match(root.text,/همه موارد این صف رسیدگی شدند/,
    'the active supplier queue reports completion while keeping its acknowledged card in place');
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
      unit_price:50000,package_price:1200000,package_count:24,price_role_hint:{role:'package'},
      comparisons:[{key:'package_count',label:'تعداد بسته',source:'24',reference:'24',state:'match'},
        {key:'volume',label:'حجم',source:'220 میلی لیتر',reference:'220 میلی لیتر',state:'match'}]
    },{
      reference_id:'REF-JUICE-24-ALT',name:'آبمیوه تکه میوه سانی نس ۲۴ عددی',score:.91,name_similarity:.91,
      unit_price:52000,package_price:1248000,package_count:24,price_role_hint:{role:'needs_review'},
      comparisons:[{key:'package_count',label:'تعداد بسته',source:'24',reference:'24',state:'match'}]
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
  const manualConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.type === 'submit' && element.textContent === 'تأیید مشخصات استخراج‌شده');
  assert(manualConfirm && manualConfirm.disabled,
    'manual final price registration is disabled until the current-price check is explicit');
  let referenceSelect = walk(visibleCards[0]).find(element => element.tagName === 'SELECT'
    && element.attributes['aria-label'] === 'انتخاب کالای مرجع');
  let referenceRole = walk(visibleCards[0]).find(element => element.tagName === 'SELECT'
    && element.attributes['aria-label'] === 'مبنای قیمت مرجع');
  assert(referenceSelect && referenceRole,'the extraction card exposes in-progress reference selection controls');
  assert.deepEqual(referenceSelect.childNodes.filter(option => option.tagName === 'OPTION' && option.value)
    .map(option => option.value),['REF-JUICE-24','REF-JUICE-24-ALT'],
  'the in-card selector exposes multiple reference candidates');
  referenceSelect.value = 'REF-JUICE-24';
  referenceSelect.listeners.change();
  assert.match(visibleCards[0].text,/تعداد بسته: یکسان/,'the selected reference comparison shows matching pack data');
  referenceRole.value = 'unit';
  referenceRole.listeners.change();
  const assignReference = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'ثبت مرجع این مورد');
  assert(assignReference && !assignReference.disabled,'reference assignment is available before source-price confirmation');
  currentSourceCheck.checked = true;
  currentSourceCheck.listeners.change();
  assert(!manualConfirm.disabled,'the explicit current-price check enables final registration');
  const callsBeforeReferenceSave = calls.length;
  await assignReference.listeners.click();
  assert.equal(referenceAssignments.length,1);
  assert.equal(referenceAssignments[0].authorization,'Bearer scoped-reference_items',
    'in-card reference assignment uses its own route-scoped AJAX token');
  assert.equal(referenceAssignments[0].payload.action,'assign_ai_reference');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'assigning a reference does not confirm historical price or stock');
  assert.deepEqual(calls.slice(callsBeforeReferenceSave).filter(item => item.options.method === 'GET')
    .map(item => item.url),[workflowApis.ai_confirmations.url],
  'saving a reference refreshes only its owning extraction queue, not every dashboard endpoint');
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  assert.match(visibleCards[0].text,/مرجع ذخیره‌شده برای این بررسی: آبمیوه تتراپک سون ۲۴ عددی/,
    'the saved assignment remains visible in the same workbench card');
  assert.match(visibleCards[0].text,/نقش ثبت‌شده .* با راهنمای مقایسهٔ مرجع/,
    'a saved reference role that conflicts with its price-scale hint is surfaced for review');
  const stillGatedConfirm = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.dataset.priceRole === 'package');
  assert(stillGatedConfirm && !stillGatedConfirm.disabled,
    'the prior explicit current-price check remains effective after reference assignment');
  const sourceCheckAfterAssignment = walk(visibleCards[0]).find(element => element.tagName === 'INPUT');
  assert(sourceCheckAfterAssignment.checked,
    'the explicitly checked source verification survives the in-card reference AJAX refresh');
  referenceRole = walk(visibleCards[0]).find(element => element.tagName === 'SELECT'
    && element.attributes['aria-label'] === 'مبنای قیمت مرجع');
  referenceRole.value = 'package';
  referenceRole.listeners.change();
  const updateReference = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'ذخیرهٔ مرجع این مورد');
  assert(updateReference && !updateReference.disabled,
    'the saved owner can correct a mismatched reference price role in the same card');
  await updateReference.listeners.click();
  assert.equal(referenceAssignments.length,2,'the package role correction is persisted to the reference review');
  assert.equal(referenceAssignments[1].payload.price_role,'package');
  visibleCards = walk(root).filter(element => element.className.split(/\s+/).includes('molavi-product-card'));
  const packageDefault = walk(visibleCards[0]).find(element => element.tagName === 'BUTTON'
    && element.dataset.priceRole === 'package');
  assert(packageDefault && packageDefault.textContent.includes('قیمت بسته'),
    'final price confirmation follows the saved package basis after a reference correction');
  assert.equal(calls.filter(item => item.options.method === 'POST'
    && item.url === workflowApis.ai_confirmations.url).length,0,
  'correcting the reference basis does not silently register historical price or stock');
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
  const groupedTitles = walk(visibleCards[0]).filter(element => element.tagName === 'H2');
  assert(groupedTitles.length >= 1,'the grouped card has a readable product title');
  assert(groupedTitles.every(element => !element.textContent.includes('موجود')),
    'a source availability prefix never leaks into the dashboard product title');
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
  assert.match(visibleCards[0].text,/موارد جاافتاده یا ناهماهنگ: آبی/,
    'the source names the exact blue flavor missing from the two extracted cases');
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

  data['supplier-price-evidence'].offers = [{product_id:'product-no-price',name:'Incomplete offer',
    channel:'@supplier',sale_price:'',state:'needs_review',source_revision:'',source_timestamp:'2026-11-03T07:00:00Z'}];
  const missingWorkRoot = new Element('div');
  const missingWorkDocument = {...document,getElementById:id=>id === 'molavi-owner-workflow' ? missingWorkRoot : null};
  const missingWorkWindow = {...window,molaviOwnerWorkflow:{...window.molaviOwnerWorkflow},location:{...window.location}};
  missingWorkWindow.parent = missingWorkWindow;
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
    {window:missingWorkWindow,document:missingWorkDocument,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,
      Number,String,Boolean,Array,Object,Error,URLSearchParams,console,setTimeout,clearTimeout});
  for (let attempt=0; attempt<20; attempt++) {
    if (missingWorkRoot.text.includes('Incomplete offer')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }
  const missingPriceQueue = walk(missingWorkRoot).find(element => element.tagName === 'BUTTON'
    && element.textContent.startsWith('قیمت تأمین‌کننده'));
  await missingPriceQueue.listeners.click();
  const missingWorkVerify = walk(missingWorkRoot).find(element => element.tagName === 'BUTTON'
    && element.textContent.includes('منبع فعلی را دیدم'));
  assert(missingWorkVerify && missingWorkVerify.disabled,
    'a product card without a current source revision cannot submit verification');
  assert.match(missingWorkRoot.text,/قیمت فروش معتبر/,
    'the product card explains the missing price source before the owner can act');

  data['supplier-price-evidence'].offers = [{product_id:'product-1',name:'Noodle 12 pack',
    channel:'@supplier',sale_price:120000,state:'blocked',source_revision:'revision-1',
    source_timestamp:'2026-11-03T07:00:00Z'}];
  const supplierRoot = new Element('div');
  const supplierDocument = {...document,getElementById:id=>id === 'molavi-owner-workflow' ? supplierRoot : null};
  const supplierWindow = {...window,molaviOwnerWorkflow:{page:'molavi-supplier-prices',
    apiUrl:base+'/pipeline/supplier-price-evidence',token:'scoped-supplier_prices'},
    location:{href:'https://site.test/wp-admin/admin.php?page=molavi-supplier-prices',
      pathname:'/wp-admin/admin.php',search:'?page=molavi-supplier-prices'}};
  supplierWindow.parent = supplierWindow;
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
    {window:supplierWindow,document:supplierDocument,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,
      Number,String,Boolean,Array,Object,Error,URLSearchParams,console,setTimeout,clearTimeout});
  for (let attempt=0; attempt<20; attempt++) {
    if (supplierRoot.text.includes('Noodle 12 pack')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }
  const supplierCard = walk(supplierRoot).find(element => element.className.split(/\s+/).includes('card'));
  const supplierForm = walk(supplierCard).find(element => element.tagName === 'FORM');
  const supplierButton = walk(supplierForm).find(element => element.tagName === 'BUTTON');
  assert(supplierCard && supplierForm && supplierButton,'the supplier acknowledgement screen renders its offer card');
  let acknowledgementPrevented = false;
  await supplierForm.listeners.submit({preventDefault(){acknowledgementPrevented=true;}});
  assert(acknowledgementPrevented,'supplier acknowledgement remains an AJAX action');
  assert.equal(walk(supplierRoot).find(element => element === supplierCard),supplierCard,
    'supplier acknowledgement updates the existing card instead of rebuilding the page');
  assert.match(supplierCard.text,/بررسی قیمت ثبت شد و انتشار دوباره به صف فرستاده شد/,
    'the acknowledged offer shows success in place');
  assert.equal(supplierButton.textContent,'قیمت تأیید شد');
  assert(supplierButton.disabled,'the acknowledged supplier price cannot be submitted twice');
  assert.equal(supplierWindow.location.href,'https://site.test/wp-admin/admin.php?page=molavi-supplier-prices',
    'supplier acknowledgement does not navigate or reload the browser page');

  data['supplier-price-evidence'].offers = [{product_id:'product-no-price',name:'Incomplete offer',
    channel:'@supplier',sale_price:'',state:'needs_review',source_revision:'',source_timestamp:'2026-11-03T07:00:00Z'}];
  const missingPriceRoot = new Element('div');
  const missingPriceDocument = {...document,getElementById:id=>id === 'molavi-owner-workflow' ? missingPriceRoot : null};
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
    {window:supplierWindow,document:missingPriceDocument,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,
      Number,String,Boolean,Array,Object,Error,URLSearchParams,console,setTimeout,clearTimeout});
  for (let attempt=0; attempt<20; attempt++) {
    if (missingPriceRoot.text.includes('Incomplete offer')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }
  const missingPriceButton = walk(missingPriceRoot).find(element => element.tagName === 'BUTTON'
    && element.textContent.includes('قیمت تأمین‌کننده را بررسی کردم'));
  assert(missingPriceButton && missingPriceButton.disabled,
    'an offer without a current price revision cannot submit price verification');
  assert.match(missingPriceRoot.text,/قیمت فروش معتبر/,
    'the card explains that the source price needs correction before verification');

  data.operations.operations.pending_telegram_chats = [{chat_id:'44556677',first_seen_at:1793782800,
    last_seen_at:1793782801,seen_count:2,revision:2}];
  const operatorRoot = new Element('div');
  const operatorDocument = {...document,getElementById:id=>id === 'molavi-owner-workflow' ? operatorRoot : null};
  const operatorWindow = {...window,molaviOwnerWorkflow:{...window.molaviOwnerWorkflow,
    page:'molavi-operations',workflowApis,reviewStoreUrl:'/admin-ajax.php',reviewStoreNonce:'nonce'}};
  operatorWindow.parent = operatorWindow;
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
    {window:operatorWindow,document:operatorDocument,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,
      Number,String,Boolean,Array,Object,Error,URLSearchParams,console,setTimeout,clearTimeout});
  for (let attempt=0; attempt<20; attempt++) {
    if (operatorRoot.text.includes('44556677')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }
  assert.match(operatorRoot.text,/حساب‌های تلگرام منتظر تأیید/,
    'unapproved private Telegram senders appear as owner-only enrollment requests');
  assert.match(operatorRoot.text,/پیام‌های حساب‌های ناشناس ذخیره یا پردازش نمی‌شوند/,
    'the dashboard explains that message contents were discarded');
  const allowTelegram = walk(operatorRoot).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'اجازه دسترسی');
  assert(allowTelegram,'the owner can approve a pending Telegram identity without typing');
  await allowTelegram.listeners.click();
  for (let attempt=0; attempt<20; attempt++) {
    if (operatorRoot.text.includes('دسترسی حساب تلگرام 44556677 ثبت شد')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }
  assert.match(operatorRoot.text,/دسترسی حساب تلگرام 44556677 ثبت شد/,
    'the approval completes in place and refreshes the pending-account queue');
  const accessRequest = calls.find(item => new URL(item.url,base).pathname.endsWith('/telegram-operator-chats')
    && item.options.method === 'POST');
  assert(accessRequest,'approval reaches the owner-scoped Telegram operator endpoint');
  assert.equal(accessRequest.options.headers.Authorization,'Bearer scoped-telegram_operators');
  const accessEvents = storedReviewRows.filter(row => row.review_type === 'telegram_operator');
  assert(accessEvents.some(row => row.state === 'pending') && accessEvents.some(row => row.state === 'accepted'),
    'the approval and its receipt are durably recorded in the WordPress review database');
  data.operations.operations.failed_publication_ids = [];
  data.operations.operations.failed_batch_ids = ['batch:failed-1'];
  data.operations.operations.items = [{id:'batch:failed-1',kind:'batch',state:'failed',attempts:8,
    error:'RuntimeError',next_action:'inspect_failure_and_reconcile',age_seconds:9000}];
  data.operations.operations.next_cursor = null;
  data.operations.operations.has_more = false;
  const batchRoot = new Element('div');
  const batchDocument = {...document,getElementById:id=>id === 'molavi-owner-workflow' ? batchRoot : null};
  const batchWindow = {...window,molaviOwnerWorkflow:{...window.molaviOwnerWorkflow,page:'molavi-operations'}};
  batchWindow.parent = batchWindow;
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
    {window:batchWindow,document:batchDocument,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,
      Number,String,Boolean,Array,Object,Error,URLSearchParams,console,setTimeout,clearTimeout});
  for (let attempt=0; attempt<20; attempt++) {
    if (batchRoot.text.includes('همگام‌سازی و تطبیق همین دسته')) break;
    await new Promise(resolve => setTimeout(resolve,0));
  }
  const singleBatchButton = walk(batchRoot).find(element => element.tagName === 'BUTTON'
    && element.textContent === 'همگام‌سازی و تطبیق همین دسته');
  assert(singleBatchButton,'the failed batch action is visible on its receipt card');
  await singleBatchButton.listeners.click();
  const singleBatchRequest = calls.find(item => new URL(item.url,base).pathname.endsWith('/reconcile-publication-backlog')
    && item.options.method === 'POST'
    && JSON.parse(item.options.body).expected_batch_ids[0] === 'batch:failed-1');
  assert(singleBatchRequest,'the card action sends only its selected batch receipt');
  assert.deepEqual(JSON.parse(singleBatchRequest.options.body),{
    expected_publish_ids:[],expected_batch_ids:['batch:failed-1']
  });
  const singleBatchEvents = storedReviewRows.filter(row => row.review_type === 'publication_backlog'
    && row.context && row.context.target_job_id === 'batch:failed-1');
  assert(singleBatchEvents.some(row => row.state === 'pending') && singleBatchEvents.some(row => row.state === 'accepted'),
    'the per-batch action and its verification receipt are durably audited in WordPress');

  const mediaMessageKey='a'.repeat(64);
  mediaPreview={counts:{media_review:1,pending:0,all_available:0,without_text:1,
      no_product_candidates:0,owner_decisions_preserved:0,dismissed_by_owner:0,already_staged:0},
    ai_configured:true,pending_digest:'b'.repeat(64),media_review_digest:'c'.repeat(64),
    media_review_total:1,media_review_after:'',media_review_next_after:null,
    media_review_messages:[{message_id:'photo-message',batch_id:'photo-batch',
      channel_username:'@supplier',channel:'telegram',timestamp:'2026-11-05T10:00:00Z',
      source_post_url:'https://t.me/supplier/21',media_type:'photo',
      image_file_id:'AgACAgQAAxkBAAIB',image_file_unique_id:'photo-unique',message_key:mediaMessageKey}],
    open_cases:{case_count:0,message_count:0,unmatched_case_count:0,digest:'d'.repeat(64)},active_run:null};
  data['ai-confirmations']={cases:[],recent_decisions:[]};
  const mediaRoot=new Element('div');
  const mediaDocument={...document,getElementById:id=>id==='molavi-owner-workflow'?mediaRoot:null};
  const mediaWindow={...window,molaviOwnerWorkflow:{...window.molaviOwnerWorkflow,
      page:'molavi-ai-confirmations',apiUrl:base+'/pipeline/ai-confirmations',
      token:'scoped-ai-confirmations'}};
  mediaWindow.parent=mediaWindow;
  vm.runInNewContext(fs.readFileSync(workflowScriptPath,'utf8'),
    {window:mediaWindow,document:mediaDocument,fetch,URL,Intl,Map,Set,Promise,TextEncoder,Date,Math,
      Number,String,Boolean,Array,Object,Error,Uint8Array,URLSearchParams,console,setTimeout:()=>0,clearTimeout:()=>{}});
  for (let attempt=0; attempt<20; attempt++) {
    if (mediaRoot.text.includes('رسانه‌های بدون متن')) break;
    await new Promise(resolve=>setTimeout(resolve,0));
  }
  assert.match(mediaRoot.text,/رسانه‌های بدون متن \(1\)/,
    'photo-only messages are visible as a distinct source-review queue');
  const mediaImage=walk(mediaRoot).find(element=>element.tagName==='IMG');
  assert(mediaImage && mediaImage.src.includes('/media/file/AgACAgQAAxkBAAIB'),
    'the owner can preview the saved Telegram image without typing an identifier');
  const runMediaOcr=walk(mediaRoot).find(element=>element.tagName==='BUTTON'
    && element.textContent==='بازخوانی OCR و استخراج');
  assert(runMediaOcr && !runMediaOcr.disabled,
    'the image card exposes one-click OCR only when server extraction is configured');
  await runMediaOcr.listeners.click();
  for (let attempt=0; attempt<20; attempt++) {
    if (mediaRoot.text.includes('پیشنهادها در بررسی کالا نمایش داده می‌شوند')) break;
    await new Promise(resolve=>setTimeout(resolve,0));
  }
  const ocrRequest=calls.find(item=>new URL(item.url,base).pathname.endsWith('/ai-confirmations')
    && item.options.method==='POST'
    && JSON.parse(item.options.body).action==='start_media_reextraction');
  assert(ocrRequest,'the OCR action queues image processing through the authenticated server route');
  assert.equal(JSON.parse(ocrRequest.options.body).message_key,mediaMessageKey,
    'the OCR action is bound to the exact image review identity');
  assert.match(mediaRoot.text,/پیشنهادها در بررسی کالا نمایش داده می‌شوند/,
    'the completed OCR card clearly directs offers to manual extraction review');
  assert.match(mediaRoot.text,/هیچ قیمت یا کالایی با OCR تأیید نهایی نمی‌شود/,
    'the owner sees that OCR cannot approve price or publication');
  const ocrEvents=storedReviewRows.filter(row=>row.review_type==='ai_media_review'
    && row.review_id===mediaMessageKey && row.event_id.startsWith('media-ocr:'));
  assert(ocrEvents.some(row=>row.state==='pending') && ocrEvents.some(row=>row.state==='accepted'),
    'the OCR request and terminal processing receipt are saved in the WordPress review database');
  const rejectMedia=walk(mediaRoot).find(element=>element.tagName==='BUTTON'
    && element.textContent==='این پیام کالا نیست');
  assert(rejectMedia,'the photo-only card offers an explicit owner decision');
  await rejectMedia.listeners.click();
  assert.match(mediaRoot.text,/تصمیم ثبت شد؛ سابقه آن در وردپرس ذخیره شد/);
  const mediaEvents=storedReviewRows.filter(row=>row.review_type==='ai_media_review'
    && row.review_id===mediaMessageKey);
  assert(mediaEvents.some(row=>row.state==='pending') && mediaEvents.some(row=>row.state==='accepted'),
    'the photo decision and server receipt are saved in the WordPress review database');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
