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
   writes.push('list');const replacing=/name="mode"\r?\n\r?\nreplace/.test(req.postData()||'');quote.qtys=replacing?{'52':4,'51':4}:{'52':8};quote.count=8;quote.kinds=replacing?2:1;quote.subtotal=720000;quote.savings=80000;quote.delivery.remaining=4280000;
   return route.fulfill({json:{success:true,data:{quote,catalog:data.catalog,added:1,skipped:[999],adjusted:[]}}});
  }
  if(url.searchParams.get('wc-ajax')==='molavi_catalog'){
   return route.fulfill({json:{success:true,data:{html:'<article data-molavi-card data-product="1" data-name="نوشیدنی کیوی"><h3>نوشیدنی کیوی</h3></article>',catalog:data.catalog,page:Number(url.searchParams.get('catalog_page')),total:1,has_more:false,buyer_type:'household'}}});
  }
  if(url.pathname.endsWith('.woff2'))return route.fulfill({body:fs.readFileSync(source+'/fonts/'+path.basename(url.pathname)),contentType:'font/woff2'});
  if(url.pathname.endsWith('.svg'))return route.fulfill({body:'<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280"><rect width="280" height="280" fill="#f2eee6"/></svg>',contentType:'image/svg+xml'});
  return route.fulfill({body:'<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><style>.screen-reader-text{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}</style></head><body class="molavi-site-page molavi-storefront-page woocommerce-shop"><header class="molavi-site-header"><div class="molavi-site-header-inner"><strong class="molavi-brand">فروشگاه مولوی‌سل</strong><nav class="molavi-main-nav"><a href="/">فروشگاه</a><a href="/my-account/">حساب کاربری</a></nav></div></header><main class="molavi-custom-shop-main">'+fs.readFileSync(fixture+'/storefront.html','utf8')+'</main><dialog id="molavi-shopping-help"><h2>روش خرید</h2><form method="dialog"><button>بستن</button></form></dialog></body></html>',contentType:'text/html;charset=utf-8'});
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
 assert.equal(await page.locator('[data-molavi-delivery-remaining]').count(),0,'cart has no delivery estimate');
 assert.equal(await page.locator('[data-molavi-selection-cost]').count(),0,'cards omit extra calculated totals');
 await page.waitForFunction(()=>document.querySelector('.molavi-mobile-basket').classList.contains('is-peeking'));
 assert.match(await page.locator('[data-molavi-mobile-rate]').innerText(),/۵٪/,'peek emphasizes actual current discount');
 await page.locator('[data-molavi-lists] summary').click();
 await page.locator('[data-molavi-save-list]').click();assert.equal(await page.locator('dialog[open]').count(),0,'saving needs no naming prompt');
 assert.equal(await page.locator('[data-molavi-lists]').evaluate(node=>!!node.closest('[data-molavi-basket]')),true,'collections live inside cart');
 await page.locator('[data-molavi-rename-list]').click();await page.locator('dialog input[name="name"]').fill('خرید هفتگی');await page.locator('dialog button[type="submit"]').click();
 const saved=await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1')));
 assert.equal(saved[0].name,'خرید هفتگی');assert.deepEqual(saved[0].items,{'52':4});assert.equal(saved[0].price,undefined,'saved list has no stale prices');
 await page.locator('[data-molavi-load-list]').click();await page.locator('[data-molavi-lists-status]').filter({hasText:'قابل افزودن نبود'}).waitFor();
 assert.equal(writes.filter(x=>x==='list').length,1);assert.match(await page.locator('[data-molavi-basket-lines]').innerText(),/۸/);
 await first.locator('h3 a').evaluate(node=>{node.textContent='عنوان کامل و بسیار بلند محصول برای بررسی خوانایی در هر دو حالت شبکه‌ای و فهرستی بدون بریده شدن بخش پایانی';});
 async function titleFits(){return first.locator('h3 a').evaluate(node=>node.scrollHeight<=node.clientHeight+1&&getComputedStyle(node).overflow!=='hidden');}
 assert.equal(await titleFits(),true,'long grid title remains complete');
 await page.locator('[data-molavi-view="list"]').click();assert.equal(await titleFits(),true,'long list title remains complete');
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-list-desktop.png',fullPage:false});
 await page.locator('[data-molavi-view="grid"]').click();
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-desktop.png',fullPage:false});
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(150);
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'390px fits viewport');
 assert.equal(await titleFits(),true,'mobile grid title remains complete');
 await page.locator('[data-molavi-view="list"]').click();assert.equal(await titleFits(),true,'mobile list title remains complete');
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-list-mobile.png',fullPage:false});
 await page.locator('[data-molavi-view="grid"]').click();
 await page.waitForTimeout(4400);
 const compact=await page.locator('.molavi-mobile-basket').boundingBox();assert.ok(compact.width<=60&&compact.x<30&&compact.y>700,'cart retracts to lower-left compact button');
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-mobile.png',fullPage:false});
 await page.locator('.molavi-mobile-basket').click();await page.locator('#molavi-basket-dialog.is-open').waitFor();
 await page.locator('[data-molavi-favorites-cart]').click();await page.locator('[data-molavi-lists-status]').filter({hasText:'سبد جدید ساخته شد'}).waitFor();
 assert.equal(writes.filter(x=>x==='list').length,2);
 const backups=await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1')));
 assert.deepEqual(backups[0].items,{'52':8},'old cart is saved before new favorites cart');
 assert.equal(await page.locator('[data-molavi-favorites-count]').innerText(),'۱','saved cart items share favorites');
 await page.screenshot({path:'/tmp/molavi-storefront-upgrade-cart-mobile.png',fullPage:false});
 await page.keyboard.press('Escape');
 await page.waitForTimeout(200);
 await page.locator('[data-molavi-open-help]').click();await page.locator('dialog[open]').waitFor();await page.keyboard.press('Escape');
 await page.locator('[data-molavi-more]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-molavi-card]').length===25);
 // Native cart uses the same collection component and reads the current form quantities.
 const collections=await page.locator('[data-molavi-lists]').evaluate(node=>node.outerHTML);
 const native=await browser.newPage({viewport:{width:1000,height:900}});
 await native.route('http://molavi-native.test/**',route=>route.fulfill({body:'<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head><body class="molavi-site-page woocommerce-cart"><main class="molavi-customer-main"><form class="woocommerce-cart-form"><div class="woocommerce-cart-form__cart-item"><a data-product_id="52"></a><input class="qty" value="12"></div><button name="update_cart">به‌روزرسانی</button></form>'+collections+'</main></body></html>',contentType:'text/html;charset=utf-8'}));
 await native.goto('http://molavi-native.test/cart/');
 await native.evaluate(config=>window.molaviShop=config,{catalog:data.catalog,initial:quote,qtys:{'52':4},buyerType:'household',nonce:'test-nonce'});
 await native.addScriptTag({content:fs.readFileSync(source+'/shop.js','utf8')});
 const nativeDetails=native.locator('[data-molavi-lists]');if(!await nativeDetails.evaluate(node=>node.open))await nativeDetails.locator('summary').click();
 await native.locator('[data-molavi-save-list]').click();
 assert.deepEqual(await native.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1'))[0].items),{'52':12},'native cart saves current form quantities');
 await native.locator('input.qty').fill('16');await native.locator('input.qty').dispatchEvent('change');
 assert.equal(await native.locator('[data-molavi-save-list]').isDisabled(),true,'list writes wait for native cart updates');
 await native.close();
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({passed:true,checks:25,writes:writes.length,errors}));await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
