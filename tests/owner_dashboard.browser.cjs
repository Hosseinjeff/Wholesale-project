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
 '/pipeline/ai-confirmations':{cases:[{case_id:'ai-4',offer_id:'offer-4',product_name:'بیسکویت شکلاتی',channel:'@supplier',sale_price:120000,detected_price:120000,package_count:12,signature:'sig-4',message_id:'25',batch_id:'batch-4',source_post_url:'https://t.me/supplier/25',original_message:'بیسکویت شکلاتی ۱۲ عدد\nقیمت فروش: ۱۲۰۰۰۰',reference_candidates:[],reference_snapshot_id:''}],recent_decisions:[]},
 '/reference/item-reviews':{cases:[],recent_decisions:[],reference_state:{allow_automatic:true}},
 '/pipeline/supplier-price-evidence':{offers:[{product_id:'1',offer_id:'offer-1',name:'نودل سبزیجات بسته ۱۲ عددی',channel:'@supplier',sale_price:120000,state:'blocked',source_revision:'revision-1',source_timestamp:now,source_timestamp_trusted:true,source_post_url:'https://t.me/supplier/18'}]},
 '/pipeline/sheet-conflicts':{conflicts:[]}, '/pipeline/publication-holds':{holds:[]},'/reference/decisions':{events:[]},
 '/pipeline/operations-dashboard':{operations:{by_state:{queued:0},items:[],worker:{status:'alive'},actionable_count:0}},
 '/reference/snapshots':{snapshots:[],active_snapshot_id:null,refresh_state:null},
 '/pipeline/reconciliation':{product_states:[{product_id:'1',woo_state:'purchasable'}]},
};
(async()=>{
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1365,height:1000}});const errors=[];const writes=[];let failPrices=false;let acceptPrice=false;
 let activeReads=0,maxActiveReads=0,transientStatusOnce=true,statusReads=0;
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',async route=>{
  const req=route.request();if(req.url().includes('/admin-ajax.php')){
   const f=new URLSearchParams(req.postData());const path=f.get('path');
   if(f.get('method')==='POST'||f.get('action')!=='molavi_owner_workflow_proxy'){
    writes.push({action:f.get('action'),path,payload:f.get('payload')});
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
    return await route.fulfill({json:{ok:true,...(fixtures[path]||{})}});
   }finally{activeReads--;}
  }
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html lang="fa" dir="rtl"><body style="background:#f0f0f1;margin:20px"><div id="molavi-owner-workflow"></div></body></html>'});
 });
 async function mount(query=''){
  await page.goto('http://molavi.test/wp-admin/admin.php?page=molavi-operations'+query);
  await page.addStyleTag({content:fs.readFileSync(base+'owner-workflows.css','utf8')});
  await page.addStyleTag({content:fs.readFileSync(base+'owner-dashboard.css','utf8')});
  await page.evaluate(()=>window.molaviOwnerWorkflow={page:'molavi-operations',apiUrl:'https://api.test/pipeline/operations-dashboard',apiProxyUrl:'/wp-admin/admin-ajax.php',apiProxyNonce:'mock',reviewStoreUrl:'/wp-admin/admin-ajax.php',reviewStoreNonce:'mock',workbenchDismissalNonce:'mock',workbenchDismissals:[],workflowApis:Object.fromEntries([
    ['product_status','/pipeline/product-status-snapshots'],['ai_confirmations','/pipeline/ai-confirmations'],['reference_items','/reference/item-reviews'],['supplier_prices','/pipeline/supplier-price-evidence'],['sheet_conflicts','/pipeline/sheet-conflicts'],['publication_holds','/pipeline/publication-holds'],['reference_decisions','/reference/decisions'],['reconciliation','/pipeline/reconciliation']].map(([key,path])=>[key,{url:'https://api.test'+path}]))});
  for(const file of ['reference-snapshots.js','owner-dashboard.js','owner-workflows.js'])await page.addScriptTag({content:fs.readFileSync(base+file,'utf8')});
  await page.waitForSelector('.md-product-card');
 }
 await mount();
 assert.equal(await page.locator('.md-product-card').count(),3);assert.equal(writes.length,0);assert.deepEqual(errors,[]);
 assert.equal(maxActiveReads,1,'owner reads leave WordPress workers free for state callbacks');
 assert.equal(statusReads,2,'one temporary read failure gets one bounded retry');
 const first=page.locator('.md-product-card').filter({hasText:'نودل سبزیجات'});
 await first.getByRole('button',{name:'رسیدگی',exact:true}).click();
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
 await mount('&workbench_action=ai');
 assert.equal(await page.locator('.md-product-card').count(),1,'extraction without catalog product remains visible');
 await page.locator('.md-product-card').getByRole('button',{name:'رسیدگی',exact:true}).click();
 await page.getByText('اصلاح دستی؛ فقط اگر داده استخراج‌شده اشتباه است',{exact:true}).click();
 await page.locator('input[name="sale_price"]').fill('130000');
 await page.getByRole('tab',{name:'مرجع',exact:true}).click();
 await page.getByRole('tab',{name:'مشخصات؛ نیازمند اقدام',exact:true}).click();
 assert.equal(await page.locator('input[name="sale_price"]').inputValue(),'130000','draft survives section switch');
 assert.deepEqual(errors,[]);
 await page.getByRole('button',{name:'پیام‌های منبع',exact:true}).click();
 await page.getByText('انتخاب پیام‌ها برای بازاستخراج',{exact:true}).waitFor();
 await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 await page.getByText('وضعیت پردازش و اتصال فروشگاه',{exact:true}).waitFor();assert.deepEqual(errors,[]);
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
 await page.waitForFunction(()=>document.querySelectorAll('.md-product-card').length===0);
 assert.equal(writes.filter(w=>w.path==='/pipeline/supplier-price-evidence').length,1,'one deliberate revision-bound write');
 assert.equal(writes.filter(w=>w.action==='molavi_owner_review_store').length,2,'pending and accepted receipts');
 await mount('&workbench_action=ai');
 await page.getByRole('button',{name:'کنارگذاشتن این کار',exact:true}).click();
 await page.getByRole('combobox',{name:'علت کنارگذاری'}).selectOption('later');
 await page.getByRole('button',{name:'کنارگذاشتن',exact:true}).click();
 await page.waitForFunction(()=>document.querySelectorAll('.md-product-card').length===0);
 await page.getByRole('button',{name:'وضعیت سیستم',exact:true}).click();
 await page.getByText('کارهای کنارگذاشته‌شده · ۱').click();
 await page.getByRole('button',{name:'بازگرداندن',exact:true}).click();
 await page.getByRole('button',{name:'محصولات',exact:true}).click();
 await page.locator('.md-product-card').waitFor();
 fixtures['/pipeline/ai-confirmations'].cases[0].reference_snapshot_id='snapshot-A';
 await mount('&workbench_action=ai');
 await page.locator('.md-product-card').getByRole('button',{name:'رسیدگی',exact:true}).click();
 const beforeSuggestion=writes.length;
 await page.getByRole('button',{name:'درخواست پیشنهاد مرجع',exact:true}).click();
 await page.getByText('درخواست ثبت شد؛ برای دیدن نتیجه صفحه را تازه کنید. تأیید مرجع همچنان با شماست.').waitFor();
 assert.equal(writes.length,beforeSuggestion+1,'requesting a suggestion never submits an approval');
 assert.equal(JSON.parse(writes.at(-1).payload).case_kind,'ai');
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,errors,writes:writes.length,captures:['/tmp/molavi-dashboard-desktop.png','/tmp/molavi-dashboard-mobile.png']}));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
