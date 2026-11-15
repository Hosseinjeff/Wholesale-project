// Requires generated synthetic HTML/JSON from customer_storefront_runtime.php and Playwright.
const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const source=path.resolve(__dirname,'../shop-wp/molavi-shop');
const fixture=process.env.MOLAVI_FIXTURE_DIR||'/tmp/molavi-storefront-fixture';
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1365,height:1000}});const errors=[];const writes=[];
 const data=JSON.parse(fs.readFileSync(fixture+'/storefront.json'));let quote=structuredClone(data.initial);let holdMutation=null;let mutations=0;
 page.on('pageerror',error=>errors.push(error.message));
 await page.route('http://molavi.test/**',async route=>{
  const req=route.request(),url=new URL(req.url());
  if(url.searchParams.get('wc-ajax')==='molavi_qty'){
   writes.push('quantity');mutations++;await new Promise(resolve=>{holdMutation=resolve;});
   quote.qtys={'52':4};quote.count=4;quote.kinds=1;quote.subtotal=360000;quote.delivery.remaining=4640000;
   return route.fulfill({json:{success:true,data:{quote,fragments:{}}}});
  }
  if(url.searchParams.get('wc-ajax')==='molavi_restore_list'){
   writes.push('list');quote.qtys={'52':8};quote.count=8;quote.kinds=1;quote.subtotal=720000;quote.delivery.remaining=4280000;
   return route.fulfill({json:{success:true,data:{quote,catalog:data.catalog,added:1,skipped:[999],adjusted:[]}}});
  }
  if(url.searchParams.get('wc-ajax')==='molavi_catalog'){
   return route.fulfill({json:{success:true,data:{html:'<article data-molavi-card data-product="1" data-name="نوشیدنی کیوی"><h3>نوشیدنی کیوی</h3></article>',catalog:data.catalog,page:Number(url.searchParams.get('catalog_page')),total:1,has_more:false,buyer_type:'household'}}});
  }
  if(url.pathname.endsWith('.woff2'))return route.fulfill({body:fs.readFileSync(source+'/fonts/'+path.basename(url.pathname)),contentType:'font/woff2'});
  if(url.pathname.endsWith('.svg'))return route.fulfill({body:'<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280"><rect width="280" height="280" fill="#f2eee6"/></svg>',contentType:'image/svg+xml'});
  return route.fulfill({body:'<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"></head><body class="molavi-site-page molavi-storefront-page woocommerce-shop"><header class="molavi-site-header"><div class="molavi-site-header-inner"><strong class="molavi-brand">فروشگاه مولوی‌سل</strong><nav class="molavi-main-nav"><a href="/">فروشگاه</a><a href="/my-account/">حساب کاربری</a></nav></div></header><main class="molavi-custom-shop-main">'+fs.readFileSync(fixture+'/storefront.html','utf8')+'</main><dialog id="molavi-shopping-help"><h2>روش خرید</h2><form method="dialog"><button>بستن</button></form></dialog></body></html>',contentType:'text/html;charset=utf-8'});
 });
 await page.goto('http://molavi.test/');
 for(const file of ['shop.css','storefront.css','customer.css'])await page.addStyleTag({content:fs.readFileSync(source+'/'+file,'utf8')});
 await page.evaluate(config=>window.molaviShop=config,{catalog:data.catalog,initial:quote,qtys:{},buyerType:'household',nonce:'test-nonce',qty:'/?wc-ajax=molavi_qty',restoreList:'/?wc-ajax=molavi_restore_list',catalogEndpoint:'/?wc-ajax=molavi_catalog',checkout:'/checkout/'});
 await page.addScriptTag({content:fs.readFileSync(source+'/shop.js','utf8')});
 assert.equal(await page.locator('[data-molavi-card]').count(),24,'only 24 tiles rendered initially');
 assert.equal(writes.length,0,'page load is read only');
 const first=page.locator('[data-molavi-card]').first();
 assert.equal(await first.locator('.molavi-add span').innerText(),'افزودن ۴ عدد');
 await first.locator('.molavi-add').click();await page.waitForFunction(()=>document.querySelector('[data-molavi-checkout]').getAttribute('aria-disabled')==='true');
 assert.equal(mutations,1);
 await page.locator('[data-molavi-checkout]').click({force:true});assert.equal(new URL(page.url()).pathname,'/','checkout blocked while price save pending');
 holdMutation();await page.waitForFunction(()=>document.querySelector('[data-molavi-checkout]').getAttribute('aria-disabled')==='false');
 assert.match(await page.locator('[data-molavi-delivery-remaining]').innerText(),/۴٬۶۴۰٬۰۰۰/);
 await page.locator('[data-molavi-lists] summary').click();
 await page.locator('[data-molavi-save-list]').click();await page.locator('dialog input[name="name"]').fill('خرید هفتگی');await page.locator('dialog button[type="submit"]').click();
 const saved=await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1')));
 assert.deepEqual(saved[0].items,{'52':4});assert.equal(saved[0].price,undefined,'saved list has no stale prices');
 await page.locator('[data-molavi-load-list]').click();await page.locator('[data-molavi-lists-status]').filter({hasText:'قابل افزودن نبود'}).waitFor();
 assert.equal(writes.filter(x=>x==='list').length,1);assert.match(await page.locator('[data-molavi-basket-lines]').innerText(),/۸/);
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-desktop.png',fullPage:false});
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(150);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'390px fits viewport');
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-mobile.png',fullPage:false});
 await page.locator('[data-molavi-open-help]').click();await page.locator('dialog[open]').waitFor();await page.keyboard.press('Escape');
 await page.locator('[data-molavi-more]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-molavi-card]').length===25);
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,checks:12,writes:writes.length,errors}));await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
