const {chromium}=require('playwright');
const fs=require('fs');const assert=require('node:assert/strict');
const path=require('node:path');
const base=path.resolve(__dirname,'../shop-wp/molavi-shop/includes')+'/';
const now=new Date().toISOString();
const fixtures={
 '/pipeline/product-status-snapshots':{products:[
  {product_id:'1',product_name:'نودل سبزیجات بسته ۱۲ عددی',channel:'@supplier',latest_offer_id:'offer-1',status:'imported',supplier_price_state:'blocked',reference_match_id:'REF-1',reference_approval_state:'approved',source_post_url:'https://t.me/supplier/18',image_file_id:'product-photo'},
  {product_id:'10',product_name:'سس کچاپ',channel:'@supplier',latest_offer_id:'offer-10',status:'imported',reference_match_id:'REF-10',reference_approval_state:'approved'},
  {product_id:'3',product_name:'نوشیدنی پرتقالی',channel:'@other',status:'held',source_integrity_issues:['missing_latest_offer'],source_post_url:'https://t.me/other/8'}],generated_at:now},
 '/pipeline/ai-confirmations':{extraction_policy_version:'gemini-1111111111111111',cases:[{case_id:'ai-4',offer_id:'offer-4',product_name:'بیسکویت شکلاتی',channel:'@supplier',sale_price:120000,detected_price:120000,package_count:12,signature:'sig-4',message_id:'25',batch_id:'batch-4',source_post_url:'https://t.me/supplier/25',original_message:'بیسکویت شکلاتی ۱۲ عدد\nقیمت فروش: ۱۲۰۰۰۰',image_file_id:'extraction-photo',reference_candidates:[],reference_snapshot_id:''}],recent_decisions:[]},
 '/reference/item-reviews':{cases:[],recent_decisions:[],reference_state:{allow_automatic:true}},
 '/pipeline/supplier-price-evidence':{offers:[{product_id:'1',offer_id:'offer-1',name:'نودل سبزیجات بسته ۱۲ عددی',channel:'@supplier',sale_price:120000,state:'blocked',source_revision:'revision-1',source_timestamp:now,source_timestamp_trusted:true,source_post_url:'https://t.me/supplier/18'}]},
 '/pipeline/sheet-conflicts':{conflicts:[]}, '/pipeline/publication-holds':{holds:[]},'/reference/decisions':{events:[]},
 '/pipeline/operations-dashboard':{operations:{by_state:{queued:1,waiting_publication:39},items:[{id:'publish:job',kind:'publish',state:'waiting_publication',next_action:'resolve_publication_hold'}],worker:{status:'alive'},actionable_count:0}},
 '/reference/snapshots':{snapshots:[],active_snapshot_id:null,refresh_state:null},
 '/pipeline/reconciliation':{product_states:[{product_id:'1',woo_state:'purchasable'}]},
};
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1365,height:1000}});const errors=[];const writes=[];let failPrices=false;let acceptPrice=false;let offerPreviews=0;let latestRun='';let runComplete=false;let groupedLoads=0;
 let activeReads=0,maxActiveReads=0,transientStatusOnce=true,statusReads=0,changeReads=0,catalogSearches=0,titleReads=0;
 let marketReads=0,marketSource=null,marketEvents=[],referenceProgress=null,productMessages=[];
 const testPhoto='https://img.example/market.png';
 const blockedPhoto='https://img.example/hotlink.png';
 let referenceRevision=null;
 let pricingRules={household_bp:4500,shop_bp:7000,household_target:5280000,shop_target:21120000};
 const repriceId='e'.repeat(32);
 let repricing=null;
 let lazyCandidates=0, marketWatch='';
 const lookupKey='b'.repeat(64);
 let inboxMessages=[],inboxRevision='initial',inboxOfferReads=0,dashboardDismissals=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request();
  if(req.url()===blockedPhoto) return route.fulfill({status:403,body:'hotlink blocked'});
  if(new URL(req.url()).searchParams.get('action')==='molavi_owner_market_photo')
    return route.fulfill({contentType:'image/png',
      body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf2QAAAAASUVORK5CYII=','base64')});
  if(req.url()===testPhoto||/^https:\/\/api\.test\/media\/file\/(?:product|extraction)-photo$/.test(req.url())) return route.fulfill({contentType:'image/png',
    body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jf2QAAAAASUVORK5CYII=','base64')});
  if(req.url().includes('/admin-ajax.php')){
   const f=new URLSearchParams(req.postData());const path=f.get('path');
   if(f.get('method')==='POST'||f.get('action')!=='molavi_owner_workflow_proxy'){
    writes.push({action:f.get('action'),path,payload:f.get('payload')});
    if(path==='/catalog/discount-policy'){
      const {action,...rules}=JSON.parse(f.get('payload'));
      assert.equal(action,'save_discount_policy');
      assert.deepEqual(rules,{household_bp:4600,shop_bp:7000,household_target:5100000,shop_target:21000000});
      pricingRules=rules;
      repricing={id:repriceId,state:'running',completed:9,total:24};
      return route.fulfill({json:{ok:true,state:'queued',rules,run:{...repricing,state:'queued',completed:0}}});
    }
    if(path==='/pipeline/dashboard-activity'){
      assert.deepEqual(JSON.parse(f.get('payload')),{kind:'market_prices',key:lookupKey});
      marketWatch='cancelled';
      marketSource={state:'cancelled',lookup_key:lookupKey};
      return route.fulfill({json:{ok:true,state:'cancelled'}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='request_market_prices'){
      marketWatch='queued';
      marketSource={state:'queued',lookup_key:lookupKey,prioritized:true};
      return route.fulfill({json:{ok:true,...marketSource}});
    }
    if(path==='/reference/snapshots'&&JSON.parse(f.get('payload')||'{}').action==='decide_reference_use'){
      const payload=JSON.parse(f.get('payload'));
      const snap=fixtures[path];
      assert.equal(payload.snapshot_id,snap.active_snapshot_id);
      assert.equal(payload.decision_token,snap.reference_review.decision_token);
      const applied_count=snap.reference_review.pending_count;
      snap.reference_review={state:'using_saved',pending_count:0,failed_attempts:10,decision_token:null};
      return route.fulfill({json:{ok:true,...payload,applied_count}});
    }
    if(path==='/reference/snapshots'){
      const payload=JSON.parse(f.get('payload'));
      const snap=fixtures[path];
      if(payload.action==='review'){
        assert.equal(payload.snapshot_id,snap.snapshots[0].snapshot_id);
        assert.equal(payload.expected_active_id,null);
        assert.equal(payload.note,'قیمت و مشخصات بررسی شد');
        snap.snapshots[0].reviewed_by='owner';
        return route.fulfill({json:{ok:true,...payload}});
      }
      if(payload.action==='activate'){
        assert.equal(snap.snapshots[0].reviewed_by,'owner');
        snap.active_snapshot_id=payload.snapshot_id;
        return route.fulfill({json:{ok:true,...payload,active_snapshot_id:payload.snapshot_id,projection_state:'written',row_count:3390}});
      }
    }
    if(path==='/pipeline/ai-confirmations'&&JSON.parse(f.get('payload')||'{}').action==='start_offer_reextraction'){
      const payload=JSON.parse(f.get('payload'));
      assert.equal(payload.offer_id,'offer-4');
      assert.equal(payload.policy_version,'gemini-2222222222222222');
      assert.equal(payload.expected_digest,'a'.repeat(64));
      latestRun=payload.run_id;
      return route.fulfill({status:202,json:{ok:true,run_id:payload.run_id,queued:1,policy_version:payload.policy_version,digest:payload.expected_digest}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='proceed_without_reference'){
      const payload=JSON.parse(f.get('payload'));
      fixtures['/pipeline/ai-confirmations'].cases[0].reference_waived='waiver-1';
      return route.fulfill({json:{ok:true,offer_id:payload.offer_id,choice:payload.action,decision_id:'waiver-1'}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='add_market_price_url'){
      const payload=JSON.parse(f.get('payload'));
      assert.equal(payload.offer_id,'offer-4');assert.equal(payload.url,'https://retailer.example/biscuit');
      const quote={title:'بیسکویت فروشگاه',merchant:'retailer.example',url:payload.url,unit_price:145000,regular_unit_price:150000,
        image_url:blockedPhoto,thumbnail_url:blockedPhoto};
      const preferred={title:'بیسکویت ۱۲ عددی از باسلام',merchant:'باسلام',url:'https://basalam.com/p/100',regular_unit_price:160000,unit_price:150000};
      marketSource={state:'ready',checked_at:Date.now()/1000,next_at:Date.now()/1000+86400,
        suggested_price:preferred,candidates:[preferred,quote],product:{title:'بیسکویت کارت',image_url:testPhoto}};
      return route.fulfill({json:{ok:true,...marketSource}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='select_market_price_source'){
      const {url}=JSON.parse(f.get('payload'));
      assert.equal(url,'https://retailer.example/biscuit');
      marketSource={...marketSource,selected_url:url,suggested_price:marketSource.candidates.find(quote=>quote.url===url),
        tracking:{regular_price:150000,next_at:marketSource.next_at}};
      return route.fulfill({json:{ok:true,...marketSource}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='use_market_product_image'){
      const {url}=JSON.parse(f.get('payload'));
      assert.equal(url,'https://retailer.example/biscuit');
      marketSource={...marketSource,product:{...marketSource.product,selected_photo:true,selected_photo_url:blockedPhoto}};
      return route.fulfill({json:{ok:true,...marketSource}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='assign_ai_reference'){
      const payload=JSON.parse(f.get('payload'));
      const decision={choice:'match',reference_id:payload.reference_id,
        price_role:payload.price_role,decision_id:'b'.repeat(64)};
      fixtures['/pipeline/ai-confirmations'].cases[0].reference_assignment=decision;
      return route.fulfill({json:{ok:true,...decision,case_id:payload.case_id,offer_id:payload.offer_id,snapshot_id:payload.snapshot_id,
        projection_state:'awaiting_ai_confirmation',publish_job:null}});
    }
    if(path==='/reference/item-reviews'&&JSON.parse(f.get('payload')||'{}').action==='suggest_reference'){
      const payload=JSON.parse(f.get('payload'));
      return route.fulfill({status:202,json:{ok:true,job_id:'reference_suggest:'+'a'.repeat(64),case_id:payload.case_id,snapshot_id:payload.snapshot_id}});
    }
    if(f.get('action')==='molavi_owner_review_store'){
      const records=JSON.parse(f.get('records')||'[]');return route.fulfill({json:{success:true,data:{stored:records.length,commands:Object.fromEntries(records.map(r=>[r.event_id,'cmd-1']))}}});
    }
    if(f.get('action')==='molavi_owner_workbench_dismissals'){
      const records=JSON.parse(f.get('records')||'[]');
      Object.assign(writes.at(-1),{operation:f.get('operation'),records});
      if(f.get('operation')==='dismiss'){assert.equal(records[0].reason,'later');assert.equal(records[0].scope,'selected');}
      return route.fulfill({json:{success:true,data:{items:f.get('operation')==='restore'?[]:records}}});
    }
    if(path==='/pipeline/ai-confirmations'){
      const payload=JSON.parse(f.get('payload')||'{}');
      if(payload.action==='choose_product_message'){
        assert.equal(payload.woo_product_id,51);
        const message=productMessages.find(item=>item.offer_id===payload.offer_id);
        message.state=payload.choice==='dismiss'?'dismissed':'pending';
        return route.fulfill({json:{ok:true,state:message.state}});
      }
      if(['reopen_products','review_publication'].includes(payload.action)){
        const items=payload.items.map(({product_id,offer_id})=>{
          const product=fixtures['/pipeline/product-status-snapshots'].products.find(row=>row.product_id===product_id);
          assert.ok((product.published||payload.action==='review_publication')&&product.latest_offer_id===offer_id);
          const case_id='reopened-'+product_id;
          fixtures[path].cases.push({...product,offer_id,case_id,reopened:true,source_current_check_required:true,
            sale_price:110000,actual_price:170000,price_type:'unit',signature:'f'.repeat(64),reason:'owner_reopened'});
          return {product_id,offer_id,case_id,state:'opened'};
        });
        return route.fulfill({json:{ok:true,opened:items.length,already_open:0,unavailable:0,items}});
      }
    }
    if(path==='/pipeline/supplier-price-evidence'&&acceptPrice){
      const payload=JSON.parse(f.get('payload'));
      assert.equal(payload.expected_revision,'revision-1');assert.equal(payload.command_id,'cmd-1');
      fixtures[path].offers=[];fixtures['/pipeline/product-status-snapshots'].products[0].supplier_price_state='current';
      return route.fulfill({json:{ok:true,product_id:'1',source_revision:'revision-1',command_id:'cmd-1',verified_at:now,publish_job:'publish-1'}});
    }
    return route.fulfill({status:403,json:{ok:false,error:'write_blocked'}});
   }
   activeReads++;maxActiveReads=Math.max(maxActiveReads,activeReads);
   try{
    await new Promise(resolve=>setTimeout(resolve,10));
    if(path==='/pipeline/product-status-snapshots'){
     statusReads++;
     if(transientStatusOnce){transientStatusOnce=false;return await route.fulfill({status:503,json:{ok:false,error:'request_failed'}});}
    }
    if(failPrices&&path==='/pipeline/supplier-price-evidence')return await route.fulfill({status:503,json:{ok:false,error:'upstream_unavailable'}});
    if (path==='/pipeline/dashboard-activity') {
     return await route.fulfill({json:{ok:true,inbox_revision:inboxRevision,reference_revision:referenceRevision,market_events:marketEvents,
      watched:{...(marketWatch?{[lookupKey]:{state:marketWatch}}:{}),...(repricing?{[repriceId]:repricing}:{})},groups:[
      ...(referenceProgress?[referenceProgress]:[]),
      {kind:'reextract',state:'running',total:8,completed:3},
      {kind:'reference_index',state:'quota_wait',model:'gemini-embedding-001',total:50,completed:20,
       retry_at:Date.now()/1000+3600},
     ],quota:{state:'tracked',total:{used:2,limit:100,remaining:98},reset_at:Date.now()/1000+3600,
      last_models:{extract:{model:'gemini-fixture',at:Date.now()/1000}},
      buckets:[{models:['gemini-fixture'],tasks:['extract'],rpd_used:2,rpd_limit:100,rpd_remaining:98,
       rpm_limit:3,tpm_limit:20000}]}}});
    }
    if(path==='/catalog/discount-policy')return route.fulfill({json:{ok:true,rules:pricingRules,run:repricing||{}}});
    if (path==='/pipeline/dashboard-bootstrap') {
     groupedLoads++;
     const routes={product_status:'/pipeline/product-status-snapshots',ai_confirmations:'/pipeline/ai-confirmations',
      reference_items:'/reference/item-reviews',supplier_prices:'/pipeline/supplier-price-evidence',
      sheet_conflicts:'/pipeline/sheet-conflicts',publication_holds:'/pipeline/publication-holds',
      reference_decisions:'/reference/decisions',operations:'/pipeline/operations-dashboard'};
     return await route.fulfill({json:{ok:true,sources:Object.fromEntries(Object.entries(routes).map(([k,v])=>[k,{ok:true,...fixtures[v]}]))}});
    }
    if(path==='/pipeline/reconciliation'){
     const query=new URLSearchParams(f.get('query'));
     if(query.get('view')==='product-messages') return route.fulfill({json:{ok:true,
      pending:productMessages.filter(row=>row.state!=='dismissed'),history:productMessages.filter(row=>row.state==='dismissed')}});
     if(query.get('view')==='inbox')return await route.fulfill({json:{ok:true,messages:inboxMessages}});
     if(query.get('view')==='inbox-offers'){
      inboxOfferReads++;
      return await route.fulfill({json:query.get('message_id')==='702'?{ok:true,outcome:'no_product',offers:[]}
       :{ok:true,outcome:'offers',offers:[{offer_id:'offer-inbox',product_id:'10',name:'پیشنهاد تازهٔ ربات',relationship:'known'}]}});
     }
    }
    if (path==='/reference/item-reviews'&&new URLSearchParams(f.get('query')).get('online')==='1') {
     catalogSearches++;
     return await route.fulfill({json:{ok:true,results:[{title:'نمونه آنلاین',url:'https://rezvanpakhsh.com/product/example'}]}});
    }
    if (path==='/reference/item-reviews'&&new URLSearchParams(f.get('query')).get('view')==='catalog-search') {
      const query=new URLSearchParams(f.get('query'));
      return route.fulfill({json:{ok:true,snapshot_id:query.get('snapshot_id'),results:[
        {reference_id:'ref-manual',name:'بیسکویت جست‌وجوشده',unit_price:125000,package_count:12}]}});
    }
    if(path==='/reference/item-reviews'&&new URLSearchParams(f.get('query')).get('view')==='market-prices'){
      marketReads++;
      const quote={title:'بیسکویت شکلاتی ۱۲ عددی',merchant:'باسلام',url:'https://basalam.com/p/100',
        regular_unit_price:160000,unit_price:150000,pack_count:12,regular_price:1920000,current_price:1800000};
      return route.fulfill({json:{ok:true,...(marketSource||{state:'ready',suggested_price:quote,candidates:[quote]})}});
    }
    if(path==='/reference/item-reviews'&&new URLSearchParams(f.get('query')).get('view')==='extracted-titles'){
     titleReads++;
     const offer=new URLSearchParams(f.get('query')).get('offer_id');
     assert(['offer-4','offer-10'].includes(offer));
     return await route.fulfill({json:{ok:true,titles:[{title:offer==='offer-10'?'سس کچاپ':'بیسکویت شکلاتی',current:true,
      fields:{sale_price:'120000',consumer_price:'150000',package_count:'12',package_price:'1440000',currency:'IRT'}},
      {title:'سس فلفل تند'},{title:'سس خردل',variant:'بدون شکر',fields:{sale_price:'84000',currency:'IRR',stock_status:'Out of Stock',volume:'۳۳۰ میلی‌لیتر'}}]}});
    }
    if(path==='/pipeline/ai-confirmations'){
     const query=new URLSearchParams(f.get('query'));
     const common={ok:true,ai_configured:true,policy_version:fixtures[path].extraction_policy_version,digest:'a'.repeat(64)};
     if(query.get('view')==='workbench-offer'){
      if(query.get('offer_id')==='offer-focus')return route.fulfill({json:{ok:true,
        cases:[{...fixtures[path].cases[0],offer_id:'offer-focus',product_name:'پیشنهاد خارج از صفحه'}]}});
      if(query.get('offer_id')==='offer-1')return route.fulfill({json:{ok:true,cases:[],
        source_message:{text:'متن واقعی پیام نودل',url:'https://t.me/supplier/18',timestamp:'2026-11-17T05:00:00Z'}}});
      return route.fulfill({json:{ok:true,cases:[],reviewed_offer:{title:'استخراج پیشین بیسکویت',
        fields:{consumer_price:'150000',currency:'IRT'}},recent_decisions:[{offer_id:'offer-old',
          decision:{choice:'confirm',fields:{price_role:'package',sale_price:120000,actual_price:140000,package_count:12}}}],
        titles:[{offer_id:'offer-4',title:'بیسکویت شکلاتی'}]}});
     }
     if(query.get('view')==='workbench-candidates'){
      lazyCandidates++;
      return route.fulfill({json:{ok:true,snapshot_id:query.get('snapshot_id'),candidates:[]}});
     }
     if(query.get('reextract')==='offer'){
      offerPreviews++;
      return await route.fulfill({json:{...common,reason:latestRun?'already_current':'updated_policy',
       latest_run_id:latestRun||null,digest:latestRun?null:common.digest,offer_id:query.get('offer_id')}});
     }
     if(query.get('reextract')==='preview'){
      return await route.fulfill({json:{...common,recent_policy:{...common,eligible_count:2},
       ...(query.get('selection')==='1'?{selection:{count:2,digest:'a'.repeat(64),scope:'all',source_channels:[]}}:{})}});
     }
     if(query.get('reextract')==='run'){
      assert.equal(query.get('summary'),'1');
      return await route.fulfill({json:{...common,total:1,...(runComplete
        ?{state:'complete',completed:1,counts:{done:1},outcomes:{reprocessed:1},products_found:3,offers_written:3,open_cases_remaining:2,
          catalog_publication:{state:'waiting_publication'}}
        :{state:'running',completed:0,counts:{queued:1}})}});
     }
     if(query.get('reextract')==='run-changes'){
      changeReads++;
      assert.equal(query.get('run_id'),latestRun);
      return await route.fulfill({json:{ok:true,offers:[{offer_id:'offer-5',product_name:'بیسکویت شکلاتی تازه',state:'changed',
       changes:[{field:'Consumer Price',before:'',after:135000},{field:'Stock Status',before:'Available',after:'Out of Stock'}]}]}});
     }
    }
    return await route.fulfill({json:{ok:true,...(fixtures[path]||{})}});
   }finally{activeReads--;}
  }
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="fa" dir="rtl"><body style="background:#f0f0f1;margin:20px"><div id="molavi-owner-workflow"></div></body></html>'});
 });
 async function mount(query='', dashboardBootstrap=false, dashboardActivity=false, ownerCacheKey=''){
  await page.goto('http://molavi.test/wp-admin/admin.php?page=molavi-operations'+query);
  await page.addStyleTag({content:fs.readFileSync(base+'owner-workflows.css','utf8')});
  await page.addStyleTag({content:fs.readFileSync(base+'owner-dashboard.css','utf8')});
  await page.evaluate(({dashboardBootstrap,dashboardActivity,dashboardDismissals,ownerCacheKey})=>window.molaviOwnerWorkflow={dashboardBootstrap,dashboardActivity,ownerCacheKey,page:'molavi-operations',apiUrl:'https://api.test/pipeline/operations-dashboard',apiProxyUrl:'/wp-admin/admin-ajax.php',apiProxyNonce:'mock',reviewStoreUrl:'/wp-admin/admin-ajax.php',reviewStoreNonce:'mock',workbenchDismissalNonce:'mock',workbenchDismissals:dashboardDismissals,workflowApis:Object.fromEntries([
    ['product_status','/pipeline/product-status-snapshots'],['ai_confirmations','/pipeline/ai-confirmations'],['reference_items','/reference/item-reviews'],['supplier_prices','/pipeline/supplier-price-evidence'],['sheet_conflicts','/pipeline/sheet-conflicts'],['publication_holds','/pipeline/publication-holds'],['reference_decisions','/reference/decisions'],['reconciliation','/pipeline/reconciliation']].map(([key,path])=>[key,{url:'https://api.test'+path}]))},{dashboardBootstrap,dashboardActivity,dashboardDismissals,ownerCacheKey});
  for(const file of ['reference-snapshots.js','owner-dashboard.js','owner-workflows.js'])await page.addScriptTag({content:fs.readFileSync(base+file,'utf8')});
  await page.waitForSelector('.md-product-card');
 }
 await mount();
 assert.equal(await page.locator('.md-product-card').count(),2,'ready work includes extraction with no active reference');
 assert.match(await page.locator('.md-product-card').first().innerText(),/بیسکویت شکلاتی/);
 const sourceThumbnail=page.locator('.md-card-header').filter({hasText:'بیسکویت شکلاتی'}).getByRole('button',{name:'بزرگ‌نمایی تصویر پیام تأمین‌کننده'});
 await sourceThumbnail.locator('img').evaluate(img=>img.decode());
 assert.equal(await sourceThumbnail.locator('img').getAttribute('loading'),'lazy');
 await sourceThumbnail.click();
 const sourcePhoto=page.getByRole('dialog',{name:'تصویر پیام تأمین‌کننده'});
 await sourcePhoto.locator('img').evaluate(img=>img.decode());
 await page.keyboard.press('Escape');
 await sourcePhoto.waitFor({state:'detached'});
 await page.getByRole('button',{name:/^در انتظار پیگیری/}).click();
 assert.equal(await page.locator('.md-product-card').count(),1,'source repair remains available');
 await page.getByRole('button',{name:/^آمادهٔ بررسی/}).click();assert.equal(writes.length,0);assert.deepEqual(errors,[]);
 assert.equal(maxActiveReads,1,'owner reads leave WordPress workers free for state callbacks');
 assert.equal(statusReads,2,'one temporary read failure gets one bounded retry');
 const first=page.locator('.md-product-card').filter({hasText:'نودل سبزیجات'});
 await first.locator('.md-card-header .molavi-source-thumbnail img').evaluate(img=>img.decode());
 await first.getByRole('button',{name:'تأیید قیمت',exact:true}).click();
 await first.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).waitFor({state:'visible'});
 assert.equal(await page.locator('.md-card-panel:not([hidden])').count(),1);
 await page.getByRole('button',{name:'فهرست مرجع',exact:true}).click();await page.getByText('فهرست قابل استفاده آماده نیست',{exact:true}).waitFor();
 await page.getByRole('button',{name:'محصولات',exact:true}).click();
 await first.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).waitFor({state:'visible'});
 await page.screenshot({path:'/tmp/molavi-dashboard-desktop.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:'/tmp/molavi-dashboard-mobile.png',fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no mobile overflow');
 assert.equal(writes.length,0,'navigation and reads never mutate');
 await mount('&workbench_action=supplier_prices&product_id=1');
 assert.equal(await page.locator('.md-product-card').count(),1,'exact ID, excludes product 10');
 await first.getByRole('tab',{name:'پیام تأمین‌کننده',exact:true}).click();
 await first.getByText('متن واقعی پیام نودل',{exact:true}).waitFor();
 assert.equal(await first.getByText('جست‌وجوی اینترنتی کالا',{exact:true}).count(),0,'supplier evidence stays in its own tab');
 await mount('&workbench_action=ai');
 assert.equal(await page.locator('.md-product-card').count(),1,'extraction without catalog product remains visible');
 await page.locator('.md-product-card').getByRole('button',{name:'بررسی مشخصات',exact:true}).click();
 assert.equal(await page.locator('.md-card-panel .molavi-source-thumbnail').count(),0,'the source image lives beside the card title');
 await page.getByText('اصلاح دستی؛ فقط اگر داده استخراج‌شده اشتباه است',{exact:true}).click();
 await page.locator('input[name="sale_price"]').fill('130000');
 assert.equal(await page.getByRole('tab',{name:'مرجع',exact:true}).count(),0,'later tabs are hidden during extraction');
 await page.getByRole('tab',{name:'پیام تأمین‌کننده',exact:true}).click();
 await page.getByRole('tab',{name:'مشخصات؛ نیازمند اقدام',exact:true}).click();
 assert.equal(await page.locator('input[name="sale_price"]').inputValue(),'130000','draft survives section switch');
 assert.deepEqual(errors,[]);
 await page.getByRole('button',{name:'پیام‌های منبع',exact:true}).click();
 await page.getByText('بازاستخراج با قواعد فعلی',{exact:true}).waitFor();
 await page.getByRole('button',{name:'بازاستخراج ۲ پیام اخیر',exact:true}).waitFor();
 assert.equal(await page.getByText('انتخاب پیام‌ها برای بازاستخراج',{exact:true}).count(),0,'bulk filters open on request');
 await page.getByText('انتخاب بر اساس کانال و تاریخ',{exact:true}).click();
 await page.getByRole('button',{name:'بازاستخراج ۲ پست',exact:true}).waitFor();
 await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 await page.getByText('وضعیت پردازش و اتصال فروشگاه',{exact:true}).waitFor();
 assert.equal(await page.locator('.molavi-technical-details').getAttribute('open'),null,'system diagnostics start collapsed');
 assert.equal(await page.locator('.molavi-operation-queue-item:visible').count(),0,'queue history starts hidden');
 await page.getByText('۱ پردازش خودکار · ۳۹ منتظر مرجع یا انتشار · ۰ خطا یا نیازمند بررسی',{exact:true}).waitFor();
 assert.deepEqual(errors,[]);
 await page.getByRole('button',{name:'محصولات',exact:true}).click();
 assert.equal(await page.locator('input[name="sale_price"]').inputValue(),'130000','draft survives navigation');
 assert.equal(writes.length,0,'all navigation remains read only');
 // Refresh failure keeps old facts visible and blocks decisions based on stale price evidence.
 await mount('&workbench_action=supplier_prices&product_id=1');
 failPrices=true;await page.getByRole('button',{name:'تازه‌سازی',exact:true}).click();
 await page.getByText('بخشی از اطلاعات تازه دریافت نشد؛ وضعیت کامل نیست.').waitFor();
 await page.getByText('دادهٔ این بخش تازه دریافت نشده است.',{exact:false}).waitFor();
 assert.equal(await page.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).count(),0);
 failPrices=false;await page.getByRole('button',{name:'تازه‌سازی',exact:true}).click();
 await page.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).waitFor();
 acceptPrice=true;await page.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).click();
 await page.locator('.md-feedback').filter({hasText:'قیمت تأیید شد و انتشار دوباره در صف قرار گرفت.'}).waitFor();
 assert.equal(await page.locator('.md-product-card').count(),1,'a focused product stays visible after a decision');
 await first.getByRole('button',{name:'تأیید قیمت',exact:true}).waitFor({state:'hidden'});
 assert.equal(writes.filter(w=>w.path==='/pipeline/supplier-price-evidence').length,1,'one deliberate revision-bound write');
 assert.equal(writes.filter(w=>w.action==='molavi_owner_review_store').length,2,'pending and accepted receipts');
 await mount('&workbench_action=ai');
 await page.getByRole('button',{name:'کنارگذاشتن این کار',exact:true}).click();
 await page.getByRole('combobox',{name:'علت کنارگذاری'}).selectOption('later');
 await page.getByRole('button',{name:'کنارگذاشتن',exact:true}).click();
 await page.waitForFunction(()=>document.querySelectorAll('.md-product-card').length===0);
 const waitingScope=page.getByRole('button',{name:/^در انتظار پیگیری/});
 const archivedCard=page.locator('.md-product-card').filter({hasText:'بیسکویت شکلاتی'});
 await waitingScope.click();
 assert.match(await waitingScope.innerText(),/\s۱$/,'archived work is excluded from the follow-up count');
 assert.equal(await page.locator('.md-product-card').count(),1,'genuine follow-ups remain visible');
 assert.equal(await archivedCard.count(),0,'archived work is excluded from the follow-up list');
 await page.getByRole('button',{name:/^همهٔ محصولات/}).click();
 await archivedCard.getByText('کنارگذاشته‌شده',{exact:true}).waitFor();
 await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 await page.getByText('کارهای کنارگذاشته‌شده · ۱').click();
 await page.getByRole('button',{name:'بازگرداندن',exact:true}).click();
 await page.getByRole('button',{name:'محصولات',exact:true}).click();
 await page.getByRole('button',{name:/^آمادهٔ بررسی/}).click();
 await archivedCard.waitFor();
 fixtures['/pipeline/ai-confirmations'].cases[0].reference_snapshot_id='snapshot-A';
 await mount('&workbench_action=ai');
 await page.locator('.md-product-card').getByRole('button',{name:'بررسی مشخصات',exact:true}).click();
 const beforeSuggestion=writes.length;
 await page.locator('.molavi-ai-review-card summary').filter({hasText:/^انتخاب مرجع$/}).click();
 await page.getByText('پیشنهاد خودکار',{exact:true}).click();
 await page.getByRole('button',{name:'درخواست پیشنهاد مرجع',exact:true}).click();
 await page.getByText('درخواست ثبت شد؛ برای دیدن نتیجه صفحه را تازه کنید. تأیید مرجع همچنان با شماست.').waitFor();
 assert.equal(writes.length,beforeSuggestion+1,'requesting a suggestion never submits an approval');
 assert.equal(JSON.parse(writes.at(-1).payload).case_kind,'ai');
 await page.getByRole('searchbox',{name:'جست‌وجو در فهرست مرجع'}).fill('بیسکویت');
 await page.getByRole('button',{name:'جست‌وجوی فهرست',exact:true}).click();
 await page.getByRole('button',{name:'بیسکویت جست‌وجوشده',exact:true}).click();
 const saveManual=page.getByRole('button',{name:'ثبت مرجع این مورد',exact:true});
 assert.equal(await saveManual.isEnabled(),false,'a manual catalog hit still needs a price basis');
 await page.getByRole('combobox',{name:'مبنای قیمت مرجع'}).selectOption('unit');
 await saveManual.click();
 await page.getByText(/مرجع «ref-manual» ذخیره شد/).first().waitFor();
 assert.equal(JSON.parse(writes.findLast(row=>JSON.parse(row.payload||'{}').action==='assign_ai_reference').payload).reference_id,'ref-manual');
 delete fixtures['/pipeline/ai-confirmations'].cases[0].reference_assignment;
 assert.deepEqual(errors,[]);
 fixtures['/pipeline/publication-holds'].holds=[{hold_id:'batch-4',reason:'batch_in_progress',
  context:{product_ids:['1'],messages:[{channel:'@supplier',content:'Source message'}],expected_messages:1}}];
 await mount('&hold_id=batch-4');
 await page.getByRole('button',{name:'باز کردن دستهٔ متوقف‌شده',exact:true}).click();
 await page.locator('.md-publication-holds[open] #molavi-batch-batch-4').waitFor();
 assert.equal(await page.getByRole('button',{name:'اجرای دوباره این دسته، فقط یک بار',exact:true}).count(),1);
 const originalOperations=fixtures['/pipeline/operations-dashboard'].operations;
 const archivedPublish={kind:'publish',id:'publish:job',state:'waiting_publication'};
 dashboardDismissals=['operations|publication_hold%3Abatch-4','operations|publish%3Apublish%3Ajob'].map(task_key=>({task_key}));
 fixtures['/pipeline/operations-dashboard'].operations={by_state:{waiting_publication:1},items:[archivedPublish]};
 await mount();await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 assert.equal(await page.locator('.md-publication-holds').count(),0,'archived holds stay quiet when their waiting jobs were also archived');
 fixtures['/pipeline/operations-dashboard'].operations={by_state:{waiting_publication:2},items:[archivedPublish,{...archivedPublish,id:'publish:new'}]};
 await page.getByRole('button',{name:'تازه‌سازی',exact:true}).click();
 await page.getByRole('button',{name:'بررسی توقف انتشار · ۱ در انتظار',exact:true}).click();
 await page.locator('.md-publication-holds[open] #molavi-batch-batch-4').waitFor();
 fixtures['/pipeline/operations-dashboard'].operations=originalOperations;
 dashboardDismissals=[];
 fixtures['/pipeline/publication-holds'].holds=[];
 fixtures['/pipeline/ai-confirmations'].extraction_policy_version='gemini-2222222222222222';
 await mount('&workbench_action=ai');
 await page.getByText('قواعد استخراج به‌روز شده است. پیام‌های واجد شرایط را دوباره پردازش کنید.').waitFor();
 const beforePreview=offerPreviews;
 const beforeReplay=writes.length;
 await page.getByText('بازاستخراج همین پیام',{exact:true}).click();
 await page.getByText('قواعد تازه برای این پیام در دسترس است.',{exact:true}).waitFor();
 assert.equal(offerPreviews,beforePreview+1,'opening the card action checks its exact offer once');
 assert.equal(writes.length,beforeReplay,'preview never starts a run');
 page.once('dialog',dialog=>dialog.accept());
 await page.getByRole('button',{name:'شروع بازاستخراج همین پیام',exact:true}).click();
 await page.getByText('بازاستخراج در صف است · 0 از 1 پیام',{exact:true}).waitFor();
 runComplete=true;
 await page.getByText('3 کالا شناسایی شد · 3 پیشنهاد ذخیره شد · 2 مورد برای بررسی',{exact:true}).waitFor();
 await page.getByRole('button',{name:'دیدن توقف انتشار',exact:true}).waitFor();
 await page.waitForTimeout(1500);
 assert.equal(await page.getByText('بازاستخراج پایان یافت · 1 از 1 پیام',{exact:true}).isVisible(),true,'completion stays visible');
 await mount('&workbench_action=ai');
 await page.getByText('بازاستخراج همین پیام',{exact:true}).click();
 await page.getByText('بازاستخراج پایان یافت · 1 از 1 پیام',{exact:true}).waitFor();
 assert.equal(changeReads,0,'comparison stays off the status polling path');
 await page.getByText('تغییرات استخراج',{exact:true}).click();
 const comparison=page.locator('.molavi-reextract-changes');
 await comparison.getByText(/قیمت مصرف‌کننده.*ثبت نشده.*۱۳۵/).waitFor();
 await comparison.getByText(/موجودی.*موجود.*ناموجود/).waitFor();
 await page.getByRole('button',{name:'تازه‌کردن وضعیت اجرا'}).click();
 await page.locator('.molavi-reextract-progress button:enabled').filter({hasText:'تازه‌کردن وضعیت اجرا'}).waitFor();
 assert.equal(changeReads,1,'refreshing status retains the loaded comparison');
 const cases=fixtures['/pipeline/ai-confirmations'].cases;
 cases.push({...cases[0],case_id:'ai-5',offer_id:'offer-5',product_name:'بیسکویت شکلاتی تازه'});
 await comparison.getByRole('link',{name:'بررسی پیشنهاد بیسکویت شکلاتی تازه'}).click();
 assert.equal(new URL(page.url()).searchParams.get('offer_id'),'offer-5');
 await page.locator('.md-card-panel').getByText(/گام فعلی/).waitFor();
 cases.pop();
 assert.equal(writes.filter(entry=>entry.payload && JSON.parse(entry.payload).action==='start_offer_reextraction').length,1);
 const referenceCases=fixtures['/reference/item-reviews'].cases;
 referenceCases.push({case_id:'reference-10',offer_id:'offer-10',product_id:'10',product_name:'سس کچاپ',snapshot_id:'snapshot-A',candidates:[]});
 await mount('&offer_id=offer-10');
 await page.getByRole('tab',{name:'مرجع؛ نیازمند اقدام'}).waitFor();
 const referenceFacts=page.locator('.md-card-panel .molavi-reference-offer-facts');
 const offerPicker=referenceFacts.getByRole('combobox',{name:'پیشنهاد برای نمایش مشخصات'});
 await offerPicker.waitFor();
 assert.equal(await offerPicker.locator('option').count(),3);
 assert.equal(await offerPicker.locator('option:checked').innerText(),'در حال بررسی · سس کچاپ');
 await referenceFacts.getByText('۱۲۰٬۰۰۰ تومان',{exact:true}).waitFor();
 await referenceFacts.getByText('۱۵۰٬۰۰۰ تومان',{exact:true}).waitFor();
 await referenceFacts.getByText('۱٬۴۴۰٬۰۰۰ تومان',{exact:true}).waitFor();
 assert.equal(await referenceFacts.locator('.molavi-review-fact').filter({hasText:'تعداد در بسته / کارتن'}).locator('dd').innerText(),'12');
 const beforeOfferView=writes.length;
 await offerPicker.selectOption({label:'سس خردل · بدون شکر'});
 await referenceFacts.getByText('بدون شکر',{exact:true}).waitFor();
 await referenceFacts.getByText('۸۴٬۰۰۰ ریال',{exact:true}).waitFor();
 await referenceFacts.getByText('ناموجود اعلام شده',{exact:true}).waitFor();
 assert.equal(await referenceFacts.locator('.molavi-review-fact').filter({hasText:'قیمت عادی / مصرف‌کننده'}).locator('dd').innerText(),'ثبت نشده');
 assert.equal(writes.length,beforeOfferView,'viewing a sibling does not change the reference decision');
 assert.equal(titleReads,1);
 referenceCases.pop();
 const extraction=fixtures['/pipeline/ai-confirmations'].cases[0];
 extraction.reference_candidates=[{name:'مرجع بیسکویت',reference_id:'ref-4',price_role_hint:{role:'unit'}}];
 extraction.source_current_check_required=true;
 fixtures['/pipeline/supplier-price-evidence'].offers=[{product_id:'4',offer_id:'offer-4',name:extraction.product_name,
   source_revision:'revision-4',source_post_url:extraction.source_post_url}];
 await mount('&workbench_action=supplier_prices&offer_id=offer-4');
 assert.equal(await page.getByRole('tab',{name:'قیمت',exact:true}).count(),0,'a deep link cannot skip extraction to verify supplier price');
 assert.equal(await page.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).count(),0);
 await page.getByText('گام فعلی · انتخاب مرجع و مبنای قیمت',{exact:true}).waitFor();
 assert.equal(titleReads,1,'comparison fetch waits until the existing disclosure is opened');
 await page.getByText('مقایسه نام، ویژگی‌ها و قیمت',{exact:true}).click();
 const assignmentFacts=page.locator('.md-card-panel .molavi-reference-compare-details .molavi-reference-offer-facts');
 await assignmentFacts.getByText('۱۵۰٬۰۰۰ تومان',{exact:true}).waitFor();
 assert.equal(await page.getByText('قیمت و موجودی امروز',{exact:true}).count(),0,'freshness follows reference selection');
 assert.equal(await page.locator('.molavi-ai-confirmation-step').count(),0,'reference must be saved before confirmation');
 await page.getByRole('combobox',{name:'انتخاب کالای مرجع'}).selectOption('ref-4');
 await page.getByRole('button',{name:'ثبت مرجع این مورد',exact:true}).click();
 await page.getByText('قیمت و موجودی امروز',{exact:true}).waitFor();
 assert.equal(await page.locator('.molavi-ai-confirmation-step').isVisible(),false,'confirmation remains hidden before current source check');
 const checkedPrice=page.locator('.molavi-reextract-current-check input');
 assert.deepEqual(await checkedPrice.evaluate(input=>[input.offsetWidth,input.offsetHeight]),[18,18]);
 await checkedPrice.check();
 await page.getByText('گام فعلی · تأیید مشخصات',{exact:true}).waitFor();
 delete extraction.reference_assignment;extraction.source_current_check_required=false;
 const beforeGrouped=groupedLoads;
 await mount('&workbench_action=ai',true);
 assert.equal(groupedLoads,beforeGrouped+1,'dashboard bootstrap replaces the individual initial reads');
 assert.equal(marketReads,0,'opening a product does not search the web');
 const market=page.locator('.molavi-market-price-review');
 const marketCard=page.locator('.md-product-card').filter({hasText:extraction.product_name});
 await marketCard.getByRole('tab',{name:'قیمت عادی',exact:true}).click();
 await market.getByText('۱۶۰٬۰۰۰ تومان',{exact:true}).waitFor();
 await market.getByText(/بستهٔ ۱۲ عددی/).waitFor();
 assert.equal(await market.getByRole('button',{name:'انتخاب پیوند',exact:true}).count(),1);
 await market.getByText('ثبت پیوند فروشگاه',{exact:true}).click();
 await market.getByRole('textbox',{name:'پیوند صفحهٔ کالا در فروشگاه'}).fill('https://retailer.example/biscuit');
 await market.getByRole('button',{name:'بررسی پیوند',exact:true}).click();
 await market.getByText('بیسکویت فروشگاه',{exact:true}).waitFor();
 assert.equal(await market.getByText('مرجع انتخاب‌شده',{exact:true}).count(),0,'verifying a link does not select it');
 const alternate=market.locator('.molavi-market-quote').filter({hasText:'بیسکویت فروشگاه'});
 await alternate.getByRole('button',{name:'بزرگ‌نمایی تصویر آگهی'}).click();
 await page.getByRole('dialog',{name:'بیسکویت فروشگاه'}).waitFor();
 await page.getByRole('dialog').getByRole('button',{name:'بستن'}).click();
 await alternate.getByRole('button',{name:'مقایسه',exact:true}).click();
 const photoComparison=page.getByRole('dialog',{name:'مقایسهٔ کالاها'});
 await photoComparison.getByText('بیسکویت کارت',{exact:true}).waitFor();
 await photoComparison.getByText('بیسکویت فروشگاه',{exact:true}).waitFor();
 assert.equal(await photoComparison.locator('figure').count(),2);
 await page.waitForFunction(()=>[...document.querySelectorAll('.molavi-market-comparison img')].filter(img=>img.naturalWidth>0).length===2);
 await photoComparison.getByRole('button',{name:'بستن'}).click();
 await alternate.getByRole('button',{name:'استفاده به‌عنوان تصویر اصلی'}).click();
 await market.getByText('تصویر اصلی انتخاب شده است.',{exact:true}).waitFor();
 assert.equal(await market.getByText('مرجع انتخاب‌شده',{exact:true}).count(),0,'choosing an image does not change the price source');
 assert.equal(await market.getByRole('radio').count(),2,'every quote is visibly selectable');
 await market.getByRole('radio',{name:/بیسکویت فروشگاه/}).check();
 await market.getByRole('button',{name:'انتخاب پیوند',exact:true}).click();
 await market.getByText('مرجع انتخاب‌شده',{exact:true}).waitFor();
 await market.getByText('۱۵۰٬۰۰۰ تومان',{exact:true}).waitFor();
 await market.getByText(/نوبت بعد/).waitFor();
 assert.equal(await market.getByRole('link',{name:'retailer.example'}).getAttribute('href'),'https://retailer.example/biscuit');
 await page.getByText('جست‌وجوی اینترنتی کالا',{exact:true}).click();
 const productSearch=page.locator('.molavi-product-search[open]');
 const query=productSearch.getByRole('searchbox');
 assert.equal(await query.inputValue(),extraction.product_name);
 await query.fill('  نودل سبزیجات & مدل ۱۲ عددی  ');
 await page.context().route('https://www.google.com/search?*',route=>route.fulfill({body:'Search results'}));
 const [google]=await Promise.all([page.context().waitForEvent('page'),productSearch.getByRole('button',{name:'جست‌وجو در گوگل'}).click()]);
 await google.waitForURL('https://www.google.com/search?*');
 const googleUrl=new URL(google.url());
 assert.equal(googleUrl.origin+googleUrl.pathname,'https://www.google.com/search');
 assert.equal(googleUrl.searchParams.get('q'),'نودل سبزیجات & مدل ۱۲ عددی');
 assert.equal(googleUrl.searchParams.get('hl'),'fa');
 assert.equal(await google.evaluate(()=>window.opener),null);
 assert.equal(catalogSearches,0,'public search skips the Rezvan catalog');
 await google.close();
 await query.fill(extraction.product_name);
 await productSearch.getByRole('button',{name:'جست‌وجو در رضوان پخش'}).click();
 await page.getByRole('link',{name:'نمونه آنلاین'}).waitFor();
 assert.equal(catalogSearches,1,'Rezvan uses its separate catalog lookup');
 await marketCard.getByRole('button',{name:'بررسی مشخصات',exact:true}).click();
 await page.getByRole('button',{name:'ادامه بدون مرجع',exact:true}).click();
 await page.getByText('گام فعلی · تأیید مشخصات',{exact:true}).waitFor();
 assert.equal(await page.getByText('گام فعلی · انتخاب مرجع و مبنای قیمت',{exact:true}).count(),0);
 const extractionCases=fixtures['/pipeline/ai-confirmations'].cases;
 const priceCase={
   case_id:'ai-price-basis',offer_id:'offer-price-basis',signature:'sig-price-basis',channel:'@supplier',
   product_name:'آبمیوه نمونه ۴ طعم موجود،۲۴ عددی',package_count:24,
   original_message:'آبمیوه نمونه ۴ طعم موجود،۲۴ عددی\nقیمت فروش ما: ۱۶۸۰/۰۰۰ تومان\nقیمت مصرف کننده: ۱۰۵/۰۰۰ تومان',
   price_type:'unit',sale_price:1680000,detected_price:1680000,actual_price:105000,
   reference_snapshot_id:'snapshot-price-basis',
   reference_candidates:[{name:'مرجع آبمیوه نمونه',reference_id:'ref-price-basis',price_role_hint:{role:'unit'}}]
 };
 fixtures['/pipeline/ai-confirmations'].cases=[priceCase];
 await mount('&workbench_action=ai&offer_id=offer-price-basis');
 const priceFacts=page.locator('.molavi-ai-price-facts');
 const roleSelect=page.getByRole('combobox',{name:'مبنای قیمت مرجع'});
 const referenceSelect=page.getByRole('combobox',{name:'انتخاب کالای مرجع'});
 await priceFacts.getByText('در پیام مشخص نشده؛ نیاز به تأیید',{exact:true}).waitFor();
 const proposal=priceFacts.locator('.molavi-review-fact').filter({hasText:'پیشنهاد اول'});
 await proposal.getByText(/۱٬۶۸۰٬۰۰۰ تومان برای بستهٔ ۲۴.*۷۰٬۰۰۰ تومان برای هر واحد/).waitFor();
 assert.equal(await page.getByRole('button',{name:'ثبت مرجع این مورد',exact:true}).isEnabled(),false);
 await referenceSelect.selectOption('ref-price-basis');
 assert.equal(await roleSelect.inputValue(),'package','source amounts outrank a conflicting comparison hint');
 assert.equal(await roleSelect.locator('option').nth(1).getAttribute('value'),'package');
 await roleSelect.selectOption('unit');
 assert.equal(await roleSelect.inputValue(),'unit','the owner may choose a different basis');
 assert.equal(await page.getByRole('button',{name:'ثبت مرجع این مورد',exact:true}).isEnabled(),true);
 fixtures['/pipeline/ai-confirmations'].cases=[{...priceCase,reference_snapshot_id:'',reference_candidates:[]}];
 await mount('&workbench_action=ai&offer_id=offer-price-basis');
 await page.getByRole('button',{name:'تأیید پیشنهاد اول · قیمت کل بسته',exact:true}).waitFor();
 assert.equal(await page.locator('.molavi-ai-quick-actions button').first().getAttribute('data-price-role'),'package');
 fixtures['/pipeline/ai-confirmations'].cases=[{...priceCase,sale_price:3000000,detected_price:3000000,
   original_message:priceCase.original_message.replace('۱۶۸۰/۰۰۰','۳۰۰۰/۰۰۰')}];
 await mount('&workbench_action=ai&offer_id=offer-price-basis');
 await priceFacts.getByText('در پیام مشخص نشده؛ نیاز به تأیید',{exact:true}).waitFor();
 assert.equal(await proposal.count(),0,'carton basis should not lead if its unit price is also above retail');
 await referenceSelect.selectOption('ref-price-basis');
 assert.equal(await roleSelect.inputValue(),'');
 fixtures['/pipeline/ai-confirmations'].cases=extractionCases;
 await mount('&workbench_action=ai',true,true);
 const bar=page.locator('.md-activity');
 await bar.getByText(/بازاستخراج گروهی.*۳ از ۸.*gemini-fixture.*۹۸/).waitFor();
 await bar.getByText(/نمایه‌سازی مرجع.*منتظر سهمیه/).waitFor();
 assert.equal(await bar.locator('li:visible').count(),2,'both notifications are visible in the bar');
 await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 await page.getByText('۲ از ۱۰۰ درخواست ثبت شد · ۹۸ باقی‌مانده',{exact:true}).waitFor();
 await page.getByText('سهمیهٔ هر مدل',{exact:true}).click();
 await page.locator('.md-quota').getByText('gemini-fixture',{exact:true}).waitFor();
 await bar.getByRole('button',{name:/جزئیات/}).first().click();
 await page.getByRole('dialog').getByRole('button',{name:'باز کردن کار',exact:true}).click();
 await page.getByRole('heading',{name:'پیام‌های منبع',exact:true}).waitFor();
 await page.locator('.molavi-inbox').getByText('هنوز پیامی دریافت نشده است.').waitFor();
 const received={received_at:now,batch_id:'recent-batch',channel:'@supplier',state:'queued'};
 inboxMessages=[{...received,receipt_id:701,source_id:'701',excerpt:'متن آزمایشی پیام ربات'},
  {...received,receipt_id:702,source_id:'702',excerpt:'متن بدون پیشنهاد',state:'done'}];
 inboxRevision='received';
 const inbox=page.locator('.molavi-inbox');
 await inbox.getByText(/در صف استخراج/).waitFor({timeout:15000});
 assert.equal(inboxOfferReads,0,'receipt refresh is local and does not load collapsed offers');
 await inbox.getByText(/در صف استخراج/).click();
 inboxMessages[0]={...inboxMessages[0],state:'done',extraction_state:'success'};
 inboxRevision='completed';
 await inbox.getByRole('button',{name:'بررسی پیشنهاد',exact:true}).waitFor({timeout:15000});
 assert.equal(inboxOfferReads,1,'the open receipt advances to its results automatically');
 await inbox.locator('details').filter({hasText:'متن بدون پیشنهاد'}).locator('summary').click();
 await inbox.getByText('استخراج پایان یافت؛ کالایی در این پیام شناسایی نشد.').waitFor();
 cases.push({...extraction,case_id:'ai-inbox',offer_id:'offer-inbox',product_name:'پیشنهاد تازهٔ ربات'});
 await inbox.getByRole('button',{name:'بررسی پیشنهاد',exact:true}).click();
 await page.locator('.md-product-card').getByRole('heading',{name:'پیشنهاد تازهٔ ربات'}).waitFor();
 assert.equal(new URL(page.url()).searchParams.get('offer_id'),'offer-inbox','refresh prioritizes the new exact offer before a known catalog product');
 cases.pop();
 const refId='a'.repeat(64);
 fixtures['/reference/snapshots']={active_snapshot_id:refId,snapshots:[{
  snapshot_id:refId,source_type:'woocommerce_store_api',coverage_verified:true,row_count:3390,last_checked_at:now,
  preview:[{source_product_id:'12',product_name:'آبمیوه پرتقال',unit_price:12000,package_count:12}]
 }],refresh_state:{state:'running',in_progress:false,last_attempt_at:now},
 reference_review:{state:'awaiting_owner',pending_count:10,failed_attempts:10,decision_token:'b'.repeat(64)}};
 fixtures['/reference/decisions'].events=Array.from({length:10},(_,i)=>({event_id:'failed-'+i,reason:'refresh_failed',snapshot_id:refId}));
 await mount('',true);
 await page.getByRole('button',{name:'فهرست مرجع',exact:true}).click();
 const list=page.locator('.md-reference-catalog');
 await list.getByRole('heading',{name:'دریافت فهرست کامل نشد'}).waitFor();
 assert.equal(await list.locator('.molavi-reference-summary').count(),1);
 assert.equal(await list.getByText('دریافت مرجع نیاز به پیگیری دارد',{exact:true}).count(),0);
 await list.getByRole('button',{name:'دریافت دوباره از رضوان پخش',exact:true}).waitFor();
 assert.equal(await list.getByRole('button').count(),1,'retry is the only visible button before an owner opens alternatives');
 await list.getByText('تصمیم دربارهٔ فهرست ذخیره‌شده',{exact:true}).click();
 await list.getByRole('button',{name:'توقف تطبیق تا دریافت فهرست تازه',exact:true}).waitFor();
 await list.getByRole('button',{name:'ادامه با فهرست ذخیره‌شده',exact:true}).click();
 await list.getByRole('heading',{name:'تطبیق با فهرست ذخیره‌شده انجام می‌شود'}).waitFor();
 assert.equal(writes.filter(write=>write.path==='/reference/snapshots').length,1,'reference decisions send one scoped receipt');
 assert.equal(await list.getByRole('button').count(),0,'a working catalog has no required action');
 await list.getByText('داده‌ها و سابقه',{exact:true}).click();
 await list.getByText('آبمیوه پرتقال',{exact:true}).waitFor();
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'an open reference preview fits mobile');
 await list.getByRole('button',{name:'تازه‌سازی دستی از رضوان پخش',exact:true}).waitFor();

 const catalog=fixtures['/reference/snapshots'];
 catalog.refresh_state={state:'running',in_progress:true,last_attempt_at:now,revision:'running'};
 referenceRevision='running';
 await mount('',true,true);
 await page.getByRole('button',{name:'فهرست مرجع',exact:true}).click();
 await list.getByRole('heading',{name:'دریافت فهرست در جریان است'}).waitFor();
 assert.equal(await list.getByRole('button').count(),0,'activity keeps a running refresh current without presenting controls');
 catalog.refresh_state={state:'updated',last_attempt_at:now,revision:'updated',change_summary:{updated_count:12}};
 catalog.reference_review={state:'ready'};
 referenceRevision='updated';
 await list.getByRole('heading',{name:'فهرست برای تطبیق آماده است'}).waitFor({timeout:15000});
 await list.getByText(/۱۲ تغییر کرد/).waitFor();

 catalog.active_snapshot_id=null;
 catalog.refresh_state={state:'staged',last_attempt_at:now,revision:'staged'};
 catalog.reference_review={state:'missing'};
 await mount('',true);
 await page.getByRole('button',{name:'فهرست مرجع',exact:true}).click();
 await list.getByRole('heading',{name:'نسخهٔ نخست آمادهٔ بررسی است'}).waitFor();
 assert.equal(await list.getByRole('button',{name:'فعال‌سازی مرجع'}).count(),0,'activation waits for review');
 await list.getByText('بررسی نسخهٔ نخست',{exact:true}).click();
 await list.getByLabel('یادداشت بررسی').fill('قیمت و مشخصات بررسی شد');
 await list.getByRole('button',{name:'ثبت بررسی',exact:true}).click();
 await list.getByRole('heading',{name:'فهرست آمادهٔ فعال‌سازی است'}).waitFor();
 page.once('dialog',dialog=>dialog.accept());
 await list.getByRole('button',{name:'فعال‌سازی مرجع',exact:true}).click();
 await list.getByRole('heading',{name:'فهرست برای تطبیق آماده است'}).waitFor();
 const aiSource=fixtures['/pipeline/ai-confirmations'];
 aiSource.candidates_deferred=true;
 extraction.reference_snapshot_id=refId;
 extraction.reference_candidates=[];
 marketSource={state:'idle',lookup_key:lookupKey};
 await mount('',true,true,'fixture-owner:1');
 assert.equal(lazyCandidates,0,'bootstrap never loads per-offer candidates for unopened cards');
 const biscuit=page.locator('.md-product-card').filter({hasText:'بیسکویت شکلاتی'});
 await biscuit.getByRole('tab',{name:'قیمت عادی',exact:true}).click();
 const marketPanel=biscuit.locator('.molavi-market-price-review');
 await marketPanel.getByRole('button',{name:'یافتن قیمت بازار',exact:true}).waitFor();
 assert.equal(lazyCandidates,0,'market prices work without loading the internal shortlist');
 await biscuit.getByRole('button',{name:'تطبیق با فهرست مرجع',exact:true}).click();
 await biscuit.locator('.molavi-ai-review-card').waitFor();
 assert.equal(lazyCandidates,1,'the internal reference opens through the existing review');
 await biscuit.getByRole('tab',{name:'قیمت عادی',exact:true}).click();
 await marketPanel.getByRole('button',{name:'یافتن قیمت بازار',exact:true}).click();
 const marketReceipt=page.locator('.md-activity-action').filter({hasText:'یافتن قیمت بازار'});
 await marketReceipt.getByText(/در صف/).waitFor();
 assert.equal(await marketReceipt.locator('.md-activity-icon').count(),1,'a cancellable notification has one separate icon');
 await page.getByRole('button',{name:'فهرست مرجع',exact:true}).click();
 await marketReceipt.getByRole('button',{name:/رفتن به مبدأ/}).click();
 await biscuit.locator('.molavi-market-price-review[open]').waitFor();
 await marketReceipt.getByRole('button',{name:'لغو',exact:true}).click();
 await marketReceipt.getByText(/لغو شد/).waitFor();
 await mount('',true,true,'fixture-owner:1');
 await marketReceipt.getByText(/لغو شد/).waitFor();
 await marketReceipt.getByRole('button',{name:'بستن اعلان'}).click();
 assert.equal(await marketReceipt.count(),0,'a receipt survives reload until dismissed');
 await mount('&offer_id=offer-old',true,true);
 const old=page.locator('.md-offer-history');
 await old.getByRole('heading',{name:'استخراج پیشین بیسکویت'}).waitFor();
 await old.getByText('۱۴۰٬۰۰۰ تومان',{exact:true}).waitFor();
 assert.equal(await old.getByText('۱۵۰٬۰۰۰ تومان',{exact:true}).isVisible(),false,'saved approval takes precedence over the extracted price');
 await old.getByText('مشخصات استخراج اولیه',{exact:true}).click();
 await old.getByText('۱۵۰٬۰۰۰ تومان',{exact:true}).waitFor();
 await old.getByRole('button',{name:'بررسی بیسکویت شکلاتی'}).click();
 await page.locator('.md-product-card').getByRole('heading',{name:'بیسکویت شکلاتی'}).waitFor();
 assert.equal(new URL(page.url()).searchParams.get('offer_id'),'offer-4','historical link navigates to the current extraction');
 await mount('&offer_id=offer-focus',true,true);
 await page.locator('.md-product-card').getByRole('heading',{name:'پیشنهاد خارج از صفحه'}).waitFor();
 assert.equal(await page.locator('.md-offer-history').count(),0,'an active offer outside the summary limit opens for review');
 const discountGear=page.getByRole('button',{name:'تنظیمات تخفیف',exact:true});
 assert.equal(await discountGear.innerText(),'');
 await discountGear.locator('.dashicons-admin-generic').waitFor();
 await discountGear.click();
 const pricing=page.locator('.md-pricing-form');
 await pricing.waitFor();
 assert.equal(await pricing.locator('[name=household_bp]').inputValue(),'45');
 assert.equal(await pricing.locator('[name=shop_bp]').inputValue(),'70');
 await pricing.locator('[name=household_bp]').fill('46');
 await pricing.locator('[name=household_target]').fill('5100000');
 await pricing.locator('[name=shop_target]').fill('21000000');
 await pricing.getByRole('button',{name:'ذخیره و به‌روزرسانی قیمت‌ها'}).click();
 const discountReceipt=bar.locator('.md-activity-action').filter({hasText:'به‌روزرسانی تخفیف‌ها'});
 await discountReceipt.getByText(/۹ از ۲۴/).waitFor({timeout:15000});
 assert.equal(await discountReceipt.locator('.md-activity-icon').count(),0,'active repricing presents the source link by itself');
 await page.getByRole('button',{name:'محصولات',exact:true}).click();
 await discountReceipt.getByRole('button',{name:/جزئیات پیشرفت/}).click();
 await page.getByRole('dialog').getByRole('progressbar',{name:'پیشرفت ثبت‌شده'}).waitFor();
 await page.getByRole('dialog').getByRole('button',{name:'باز کردن کار',exact:true}).click();
 await pricing.waitFor();
 repricing={...repricing,state:'completed',completed:24};
 await discountReceipt.getByText(/کامل شد.*۲۴ از ۲۴/).waitFor({timeout:16000});
 const products=fixtures['/pipeline/product-status-snapshots'].products;
 products[0].published=true;products[1].published=true;
 fixtures['/pipeline/product-status-snapshots'].published_unlinked=[
    {woo_product_id:30,name:'کالای ووکامرس',supplier:'',edit_url:'/wp-admin/post.php?post=30&action=edit'}
 ];
 await mount('',true,true);
 await page.getByRole('button',{name:/^منتشرشده/}).click();
 const cards=page.locator('.md-product-list .md-product-card');
 assert.equal(await cards.count(),3,'Published lists linked and unlinked storefront products');
 const manual=cards.filter({hasText:'کالای ووکامرس'});
 await manual.getByRole('link',{name:'ویرایش در ووکامرس'}).waitFor();
 assert.equal(await manual.getByRole('button',{name:'بازگشایی بررسی'}).count(),0,'a manually published listing has no synthetic source review');
 const noodle=cards.filter({hasText:products[0].product_name});
 await noodle.getByRole('button',{name:'بازگشایی بررسی',exact:true}).click();
 await page.getByRole('dialog').getByRole('button',{name:'انصراف'}).click();
 await page.getByRole('button',{name:'انتخاب کالاها'}).click();
 await noodle.getByRole('checkbox').check();
 const bulk=page.locator('.md-bulk-actions');
 await bulk.getByRole('button',{name:/بازگشایی بررسی/}).waitFor();
 const publishedSearch=page.getByRole('searchbox',{name:'جست‌وجوی محصولات'});
 await publishedSearch.fill('سس کچاپ');
 await page.waitForFunction(()=>document.querySelectorAll('.md-product-list .md-product-card').length===1);
 assert.equal(await bulk.getByRole('button',{name:/بازگشایی بررسی/}).count(),0,'filtering clears selection');
 await publishedSearch.fill('');
 await page.waitForFunction(()=>document.querySelectorAll('.md-product-list .md-product-card').length===3);
 await bulk.getByRole('button',{name:/انتخاب همهٔ نتایج قابل اقدام/}).click();
 await bulk.getByRole('button',{name:/بازگشایی بررسی.*۲/}).click();
 await page.getByRole('dialog').getByRole('button',{name:'بازگشایی بررسی'}).click();
 const reopenReceipt=bar.locator('.md-activity-action').filter({hasText:'بازگشایی شد'});
 await reopenReceipt.getByText(/۲ بازگشایی شد/).waitFor();
 await page.getByRole('dialog').waitFor({state:'hidden'});
 const reopened=JSON.parse(writes.find(write=>JSON.parse(write.payload||'{}').action==='reopen_products').payload);
 assert.deepEqual(reopened.items.map(item=>item.product_id).sort(),['1','10']);
 await page.getByRole('button',{name:'انتخاب کالاها'}).click();
 await bulk.getByRole('button',{name:/انتخاب همهٔ نتایج قابل اقدام/}).click();
 assert.equal(await bulk.getByRole('button',{name:/بازگشایی بررسی/}).count(),0,'already reopened cards cannot be reopened again');
 await bulk.getByRole('button',{name:/کنارگذاشتن کار.*۲/}).click();
 const dialog=page.getByRole('dialog');
 await dialog.getByRole('combobox',{name:'علت کنارگذاری'}).selectOption('later');
 await dialog.getByRole('button',{name:'کنارگذاشتن',exact:true}).click();
 await dialog.waitFor({state:'hidden'});
 const lastDismiss=writes.filter(write=>write.action==='molavi_owner_workbench_dismissals'&&write.operation==='dismiss').at(-1);
 assert.deepEqual(lastDismiss.records.map(item=>item.product_id).sort(),['1','10']);
 marketEvents=[
    {id:'market-fail',offer_id:'offer-4',title:extraction.product_name,kind:'failed',code:'listing_unavailable'},
    {id:'market-change',offer_id:'offer-4',title:extraction.product_name,kind:'changed',old_price:150000,regular_price:160000}
 ];
 await mount('',true,true,'fixture-owner:1');
 const marketSuccess=bar.locator('.md-activity-action').filter({hasText:'قیمت عادی به‌روز شد'});
 const marketFailure=bar.locator('.md-activity-action').filter({hasText:'بررسی قیمت عادی ناموفق بود'});
 await marketSuccess.getByText(/۱۵۰٬۰۰۰.*۱۶۰٬۰۰۰/).waitFor();
 await marketFailure.getByText(/مشخصاتش تغییر کرده/).waitFor();
 await marketFailure.getByRole('button',{name:'بستن اعلان'}).click();
 await mount('',true,true,'fixture-owner:1');
 await marketSuccess.getByText(/۱۶۰٬۰۰۰/).waitFor();
 assert.equal(await marketFailure.count(),0,'a dismissed automatic notice stays dismissed');
 productMessages=[
    {offer_id:'msg-one',title:'پیام نخست محصول',category:'خوراکی',sale_price:90000,state:'pending'},
    {offer_id:'msg-two',title:'پیام دوم محصول',sale_price:100000,state:'pending'}
 ];
 products[0].woo_product_id=51;products[0].message_count=2;
 referenceProgress={kind:'reference_refresh',state:'running',total:8,completed:3,unit:'pages',
   current:{phase:'fetching',records:270,total_records:712,updated_at:Date.now()/1000}};
 await mount('',true,true,'progress-fixture:1');
 await bar.getByRole('button',{name:/به‌روزرسانی فهرست مرجع.*۳ از ۸ صفحه/}).click();
 const progressDetail=page.getByRole('dialog');
 await progressDetail.getByText('دریافت صفحه‌های فهرست',{exact:true}).waitFor();
 assert.equal(await progressDetail.getByRole('progressbar').getAttribute('value'),'3');
 await progressDetail.getByRole('button',{name:'بستن',exact:true}).click();
 await page.getByRole('button',{name:/^منتشرشده/}).click();
 await noodle.getByRole('tab',{name:/پیام‌های کالا/}).click();
 await noodle.getByText(/پیام نخست محصول/).click();
 await noodle.getByRole('button',{name:'کنارگذاشتن پیام',exact:true}).click();
 await noodle.getByRole('tab',{name:/پیام‌های کالا/}).click();
 await noodle.getByText('پیام‌های استفاده‌شده و کنارگذاشته‌شده',{exact:true}).click();
 await noodle.getByText(/پیام نخست محصول.*کنارگذاشته‌شده/).waitFor();
 products.push({product_id:'publication-test',product_name:'کالای آمادهٔ بازبینی انتشار',channel:'@supplier',
   latest_offer_id:'publication-offer',status:'needs_review',update_needed:true,review_reason:'manual review'});
 await mount('&product_id=publication-test',true);
 await page.getByRole('button',{name:'بازبینی مشخصات برای انتشار',exact:true}).click();
 await page.getByRole('checkbox',{name:/قیمت امروز|قیمت و موجودی امروز/}).check();
 await page.getByText('گام فعلی · تأیید مشخصات',{exact:true}).waitFor();
 assert.equal(await page.getByRole('dialog').count(),0,'publication recovery opens the current form directly');
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,errors,writes:writes.length,captures:['/tmp/molavi-dashboard-desktop.png','/tmp/molavi-dashboard-mobile.png']}));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
