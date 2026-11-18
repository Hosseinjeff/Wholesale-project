const {chromium}=require('playwright');
const fs=require('fs');const assert=require('node:assert/strict');
const path=require('node:path');
const base=path.resolve(__dirname,'../shop-wp/molavi-shop/includes')+'/';
const now=new Date().toISOString();
const fixtures={
 '/pipeline/product-status-snapshots':{products:[
  {product_id:'1',product_name:'نودل سبزیجات بسته ۱۲ عددی',channel:'@supplier',latest_offer_id:'offer-1',status:'imported',supplier_price_state:'blocked',reference_match_id:'REF-1',reference_approval_state:'approved',source_post_url:'https://t.me/supplier/18'},
  {product_id:'10',product_name:'سس کچاپ',channel:'@supplier',latest_offer_id:'offer-10',status:'imported',reference_match_id:'REF-10',reference_approval_state:'approved'},
  {product_id:'3',product_name:'نوشیدنی پرتقالی',channel:'@other',status:'held',source_integrity_issues:['missing_latest_offer'],source_post_url:'https://t.me/other/8'}],generated_at:now},
 '/pipeline/ai-confirmations':{extraction_policy_version:'gemini-1111111111111111',cases:[{case_id:'ai-4',offer_id:'offer-4',product_name:'بیسکویت شکلاتی',channel:'@supplier',sale_price:120000,detected_price:120000,package_count:12,signature:'sig-4',message_id:'25',batch_id:'batch-4',source_post_url:'https://t.me/supplier/25',original_message:'بیسکویت شکلاتی ۱۲ عدد\nقیمت فروش: ۱۲۰۰۰۰',reference_candidates:[],reference_snapshot_id:''}],recent_decisions:[]},
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
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request();if(req.url().includes('/admin-ajax.php')){
   const f=new URLSearchParams(req.postData());const path=f.get('path');
   if(f.get('method')==='POST'||f.get('action')!=='molavi_owner_workflow_proxy'){
    writes.push({action:f.get('action'),path,payload:f.get('payload')});
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
      if(f.get('operation')==='dismiss'){assert.equal(records[0].reason,'later');assert.equal(records[0].scope,'selected');}
      return route.fulfill({json:{success:true,data:{items:f.get('operation')==='restore'?[]:records}}});
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
     return await route.fulfill({json:{ok:true,groups:[
      {kind:'reextract',state:'running',total:8,completed:3},
      {kind:'reference_index',state:'quota_wait',model:'gemini-embedding-001',total:50,completed:20,
       retry_at:Date.now()/1000+3600},
     ],quota:{state:'tracked',total:{used:2,limit:100,remaining:98},reset_at:Date.now()/1000+3600,
      last_models:{extract:{model:'gemini-fixture',at:Date.now()/1000}},
      buckets:[{models:['gemini-fixture'],tasks:['extract'],rpd_used:2,rpd_limit:100,rpd_remaining:98,
       rpm_limit:3,tpm_limit:20000}]}}});
    }
    if (path==='/pipeline/dashboard-bootstrap') {
     groupedLoads++;
     const routes={product_status:'/pipeline/product-status-snapshots',ai_confirmations:'/pipeline/ai-confirmations',
      reference_items:'/reference/item-reviews',supplier_prices:'/pipeline/supplier-price-evidence',
      sheet_conflicts:'/pipeline/sheet-conflicts',publication_holds:'/pipeline/publication-holds',
      reference_decisions:'/reference/decisions',operations:'/pipeline/operations-dashboard'};
     return await route.fulfill({json:{ok:true,sources:Object.fromEntries(Object.entries(routes).map(([k,v])=>[k,{ok:true,...fixtures[v]}]))}});
    }
    if (path==='/reference/item-reviews'&&new URLSearchParams(f.get('query')).get('online')==='1') {
     catalogSearches++;
     return await route.fulfill({json:{ok:true,results:[{title:'نمونه آنلاین',url:'https://rezvanpakhsh.com/product/example'}]}});
    }
    if(path==='/reference/item-reviews'&&new URLSearchParams(f.get('query')).get('view')==='extracted-titles'){
     titleReads++;
     const offer=new URLSearchParams(f.get('query')).get('offer_id');
     assert(['offer-4','offer-10'].includes(offer));
     return await route.fulfill({json:{ok:true,titles:[{title:offer==='offer-10'?'سس کچاپ':'بیسکویت شکلاتی',current:true},
      {title:'سس فلفل تند'},{title:'سس خردل',variant:'بدون شکر'}]}});
    }
    if(path==='/pipeline/ai-confirmations'){
     const query=new URLSearchParams(f.get('query'));
     const common={ok:true,ai_configured:true,policy_version:fixtures[path].extraction_policy_version,digest:'a'.repeat(64)};
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
 async function mount(query='', dashboardBootstrap=false, dashboardActivity=false){
  await page.goto('http://molavi.test/wp-admin/admin.php?page=molavi-operations'+query);
  await page.addStyleTag({content:fs.readFileSync(base+'owner-workflows.css','utf8')});
  await page.addStyleTag({content:fs.readFileSync(base+'owner-dashboard.css','utf8')});
  await page.evaluate(({dashboardBootstrap,dashboardActivity})=>window.molaviOwnerWorkflow={dashboardBootstrap,dashboardActivity,page:'molavi-operations',apiUrl:'https://api.test/pipeline/operations-dashboard',apiProxyUrl:'/wp-admin/admin-ajax.php',apiProxyNonce:'mock',reviewStoreUrl:'/wp-admin/admin-ajax.php',reviewStoreNonce:'mock',workbenchDismissalNonce:'mock',workbenchDismissals:[],workflowApis:Object.fromEntries([
    ['product_status','/pipeline/product-status-snapshots'],['ai_confirmations','/pipeline/ai-confirmations'],['reference_items','/reference/item-reviews'],['supplier_prices','/pipeline/supplier-price-evidence'],['sheet_conflicts','/pipeline/sheet-conflicts'],['publication_holds','/pipeline/publication-holds'],['reference_decisions','/reference/decisions'],['reconciliation','/pipeline/reconciliation']].map(([key,path])=>[key,{url:'https://api.test'+path}]))},{dashboardBootstrap,dashboardActivity});
  for(const file of ['reference-snapshots.js','owner-dashboard.js','owner-workflows.js'])await page.addScriptTag({content:fs.readFileSync(base+file,'utf8')});
  await page.waitForSelector('.md-product-card');
 }
 await mount();
 assert.equal(await page.locator('.md-product-card').count(),2,'ready work includes extraction with no active reference');
 assert.match(await page.locator('.md-product-card').first().innerText(),/بیسکویت شکلاتی/);
 await page.getByRole('button',{name:/^در انتظار پیگیری/}).click();
 assert.equal(await page.locator('.md-product-card').count(),1,'source repair remains available');
 await page.getByRole('button',{name:/^آمادهٔ بررسی/}).click();assert.equal(writes.length,0);assert.deepEqual(errors,[]);
 assert.equal(maxActiveReads,1,'owner reads leave WordPress workers free for state callbacks');
 assert.equal(statusReads,2,'one temporary read failure gets one bounded retry');
 const first=page.locator('.md-product-card').filter({hasText:'نودل سبزیجات'});
 await first.getByRole('button',{name:'تأیید قیمت',exact:true}).click();
 await first.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).waitFor({state:'visible'});
 assert.equal(await page.locator('.md-card-panel:not([hidden])').count(),1);
 await page.getByRole('button',{name:'فهرست مرجع',exact:true}).click();await page.getByText('مرجع فعال نیست',{exact:true}).waitFor();
 await page.getByRole('button',{name:'محصولات',exact:true}).click();
 await first.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).waitFor({state:'visible'});
 await page.screenshot({path:'/tmp/molavi-dashboard-desktop.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 await page.screenshot({path:'/tmp/molavi-dashboard-mobile.png',fullPage:true});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no mobile overflow');
 assert.equal(writes.length,0,'navigation and reads never mutate');
 await mount('&workbench_action=supplier_prices&product_id=1');
 assert.equal(await page.locator('.md-product-card').count(),1,'exact ID, excludes product 10');
 await first.getByRole('tab',{name:'منبع',exact:true}).click();
 await first.getByText('جست‌وجوی اینترنتی کالا',{exact:true}).click();
 assert.equal(await first.getByRole('searchbox',{name:'نام کالا برای جست‌وجوی اینترنتی'}).inputValue(),
  'نودل سبزیجات بسته ۱۲ عددی','public search is available on the source tab of an already-matched product');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'search controls fit on mobile');
 await mount('&workbench_action=ai');
 assert.equal(await page.locator('.md-product-card').count(),1,'extraction without catalog product remains visible');
 await page.locator('.md-product-card').getByRole('button',{name:'بررسی مشخصات',exact:true}).click();
 await page.getByText('اصلاح دستی؛ فقط اگر داده استخراج‌شده اشتباه است',{exact:true}).click();
 await page.locator('input[name="sale_price"]').fill('130000');
 assert.equal(await page.getByRole('tab',{name:'مرجع',exact:true}).count(),0,'later tabs are hidden during extraction');
 await page.getByRole('tab',{name:'منبع',exact:true}).click();
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
 await page.getByText('پیشنهاد مرجع',{exact:true}).click();
 await page.getByRole('button',{name:'درخواست پیشنهاد مرجع',exact:true}).click();
 await page.getByText('درخواست ثبت شد؛ برای دیدن نتیجه صفحه را تازه کنید. تأیید مرجع همچنان با شماست.').waitFor();
 assert.equal(writes.length,beforeSuggestion+1,'requesting a suggestion never submits an approval');
 assert.equal(JSON.parse(writes.at(-1).payload).case_kind,'ai');
 assert.deepEqual(errors,[]);
 fixtures['/pipeline/publication-holds'].holds=[{hold_id:'batch-4',reason:'batch_in_progress',
  context:{product_ids:['1'],messages:[{channel:'@supplier',content:'Source message'}],expected_messages:1}}];
 await mount('&hold_id=batch-4');
 await page.getByRole('button',{name:'باز کردن دستهٔ متوقف‌شده',exact:true}).click();
 await page.locator('.md-publication-holds[open] #molavi-batch-batch-4').waitFor();
 assert.equal(await page.getByRole('button',{name:'اجرای دوباره این دسته، فقط یک بار',exact:true}).count(),1);
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
 const referenceTitles=page.locator('.md-card-panel .molavi-extracted-titles');
 assert.equal(titleReads,0,'opening the reference tab leaves the source title fetch on demand');
 await referenceTitles.getByText('عنوان‌های آخرین استخراج این پیام',{exact:true}).click();
 await referenceTitles.getByText('سس خردل · بدون شکر',{exact:true}).waitFor();
 assert.equal(await referenceTitles.locator('li').count(),3);
 await referenceTitles.locator('li').filter({hasText:'این پیشنهاد'}).getByText('سس کچاپ').waitFor();
 assert.equal(titleReads,1);
 referenceCases.pop();
 const extraction=fixtures['/pipeline/ai-confirmations'].cases[0];
 extraction.reference_candidates=[{name:'مرجع بیسکویت',reference_id:'ref-4',price_role_hint:{role:'unit'}}];
 extraction.source_current_check_required=true;
 fixtures['/pipeline/supplier-price-evidence'].offers=[{product_id:'4',offer_id:'offer-4',name:extraction.product_name,
   source_revision:'revision-4',source_post_url:extraction.source_post_url}];
 await mount('&workbench_action=supplier_prices&offer_id=offer-4');
 assert.equal(await page.getByRole('tab',{name:/^قیمت/}).count(),0,'a deep link cannot skip extraction');
 assert.equal(await page.getByRole('button',{name:'منبع فعلی را دیدم؛ قیمت تأیید است'}).count(),0);
 await page.getByText('گام فعلی · انتخاب مرجع و مبنای قیمت',{exact:true}).waitFor();
 const assignmentTitles=page.locator('.md-card-panel .molavi-extracted-titles');
 await assignmentTitles.getByText('عنوان‌های آخرین استخراج این پیام',{exact:true}).click();
 await assignmentTitles.getByText('بیسکویت شکلاتی',{exact:true}).waitFor();
 assert.equal(await page.getByText('قیمت و موجودی امروز',{exact:true}).count(),0,'freshness follows reference selection');
 assert.equal(await page.locator('.molavi-ai-confirmation-step').count(),0,'reference must be saved before confirmation');
 await page.getByRole('combobox',{name:'انتخاب کالای مرجع'}).selectOption('ref-4');
 await page.getByRole('button',{name:'ثبت مرجع این مورد',exact:true}).click();
 await page.getByText('قیمت و موجودی امروز',{exact:true}).waitFor();
 assert.equal(await page.locator('.molavi-ai-confirmation-step').isVisible(),false,'confirmation remains hidden before current source check');
 await page.locator('.molavi-reextract-current-check input').check();
 await page.getByText('گام فعلی · تأیید مشخصات',{exact:true}).waitFor();
 delete extraction.reference_assignment;extraction.source_current_check_required=false;
 const beforeGrouped=groupedLoads;
 await mount('&workbench_action=ai',true);
 assert.equal(groupedLoads,beforeGrouped+1,'dashboard bootstrap replaces the individual initial reads');
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
 await productSearch.getByRole('button',{name:'جست‌وجو در رزوان'}).click();
 await page.getByRole('link',{name:'نمونه آنلاین'}).waitFor();
 assert.equal(catalogSearches,1,'Rezvan uses its separate catalog lookup');
 await page.getByRole('button',{name:'ادامه بدون مرجع',exact:true}).click();
 await page.getByText('گام فعلی · تأیید مشخصات',{exact:true}).waitFor();
 assert.equal(await page.getByText('گام فعلی · انتخاب مرجع و مبنای قیمت',{exact:true}).count(),0);
 await mount('&workbench_action=ai',true,true);
 const bar=page.locator('.md-activity');
 await bar.getByText(/بازاستخراج گروهی.*۳ از ۸.*gemini-fixture.*۹۸/).waitFor();
 await bar.getByText(/نمایه‌سازی مرجع.*منتظر سهمیه/).waitFor();
 await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 await page.getByText('۲ از ۱۰۰ درخواست ثبت شد · ۹۸ باقی‌مانده',{exact:true}).waitFor();
 await page.getByText('سهمیهٔ هر مدل',{exact:true}).click();
 await page.locator('.md-quota').getByText('gemini-fixture',{exact:true}).waitFor();
 await bar.getByRole('button',{name:'جزئیات',exact:true}).first().click();
 await page.getByRole('heading',{name:'پیام‌های منبع',exact:true}).waitFor();
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,errors,writes:writes.length,captures:['/tmp/molavi-dashboard-desktop.png','/tmp/molavi-dashboard-mobile.png']}));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
