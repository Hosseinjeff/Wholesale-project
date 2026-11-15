// Synthetic Woo HTML and intercepted APIs; no live customer/order/payment writes.
const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
const source=path.resolve(__dirname,'../shop-wp/molavi-shop'),fixture=process.env.MOLAVI_FIXTURE_DIR||'/tmp/molavi-storefront-fixture';
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
 const page=await browser.newPage({viewport:{width:1365,height:1000}}),errors=[],writes=[],reads=[];
 const data=JSON.parse(fs.readFileSync(fixture+'/storefront.json'));let quote=structuredClone(data.initial),holdMutation=null,failRead=false;
 page.on('pageerror',error=>errors.push(error.message));
 const heart='<button class="molavi-header-icon" data-molavi-open-library aria-label="علاقه‌مندی‌ها" aria-controls="molavi-favorites-dialog" aria-expanded="false"><svg viewBox="0 0 24 24"><path d="M20.8 8.8c0 4.4-8.8 10-8.8 10s-8.8-5.6-8.8-10a4.6 4.6 0 0 1 8.8-1.9 4.6 4.6 0 0 1 8.8 1.9Z"></path></svg></button>';
 function field(req,name){const match=(req.postData()||'').match(new RegExp('name="'+name+'"\\r?\\n\\r?\\n([^\\r\\n]*)'));return match&&match[1];}
 function totals(){quote.count=Object.values(quote.qtys).reduce((a,b)=>a+b,0);quote.kinds=Object.keys(quote.qtys).length;quote.subtotal=Object.entries(quote.qtys).reduce((sum,[id,qty])=>sum+Number(quote.units[id])*qty,0);quote.reference_total=quote.count*100000;quote.savings=quote.reference_total-quote.subtotal;}
 await page.route('http://molavi.test/**',async route=>{
  const req=route.request(),url=new URL(req.url()),action=url.searchParams.get('wc-ajax');
  if(action==='molavi_qty'){writes.push('quantity');await new Promise(resolve=>{holdMutation=resolve;});quote.qtys={'52':4};totals();return route.fulfill({json:{success:true,data:{quote,fragments:{}}}});}
  if(action==='molavi_restore_list'){
   const mode=field(req,'mode'),items=JSON.parse(field(req,'items'));writes.push(mode);
   if(mode==='replace')quote.qtys={};for(const [id,qty] of Object.entries(items))quote.qtys[id]=(quote.qtys[id]||0)+qty;totals();
   return route.fulfill({json:{success:true,data:{quote,catalog:data.catalog,added:Object.keys(items).length,skipped:[],adjusted:[]}}});
  }
  if(action==='molavi_catalog'){
   if(url.searchParams.get('format')==='collection'){
    reads.push('collection');if(failRead){failRead=false;return route.fulfill({status:503,json:{success:false}});}
    const ids=(url.searchParams.get('ids')||'').split(','),p=Number(url.searchParams.get('catalog_page')||1),catalog={};ids.slice((p-1)*24,p*24).forEach(id=>{if(data.catalog[id])catalog[id]=data.catalog[id];});
    return route.fulfill({json:{success:true,data:{catalog,quote,page:p,total:ids.length,has_more:p*24<ids.length,buyer_type:'household'}}});
   }
   return route.fulfill({json:{success:true,data:{html:'<article data-molavi-card data-product="2"><h3>کالای بیشتر</h3></article>',catalog:{},page:2,total:25,has_more:false,buyer_type:'household'}}});
  }
  if(url.pathname.endsWith('.woff2'))return route.fulfill({body:fs.readFileSync(source+'/fonts/'+path.basename(url.pathname)),contentType:'font/woff2'});
  if(url.pathname.endsWith('.svg'))return route.fulfill({body:'<svg xmlns="http://www.w3.org/2000/svg" width="280" height="280"><rect width="280" height="280" fill="#f1f3f6"/></svg>',contentType:'image/svg+xml'});
  return route.fulfill({body:'<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8"><style>.screen-reader-text{position:absolute;clip:rect(0,0,0,0);width:1px;height:1px;overflow:hidden}</style></head><body class="molavi-site-page molavi-storefront-page woocommerce-shop"><header class="molavi-site-header"><div class="molavi-site-header-inner"><strong class="molavi-brand">فروشگاه مولوی‌سل</strong><nav class="molavi-main-nav"><a href="/">فروشگاه</a><button data-molavi-open-help>راهنمای خرید</button></nav><div class="molavi-header-actions">'+heart+'</div></div></header><main class="molavi-custom-shop-main">'+fs.readFileSync(fixture+'/storefront.html','utf8')+'</main><dialog id="molavi-shopping-help"><h2>روش خرید</h2><form method="dialog"><button>بستن</button></form></dialog></body></html>',contentType:'text/html;charset=utf-8'});
 });
 await page.goto('http://molavi.test/');for(const file of ['shop.css','storefront.css','customer.css'])await page.addStyleTag({content:fs.readFileSync(source+'/'+file,'utf8')});
 await page.evaluate(config=>window.molaviShop=config,{catalog:data.catalog,initial:quote,qtys:{},buyerType:'household',nonce:'test-nonce',qty:'/?wc-ajax=molavi_qty',restoreList:'/?wc-ajax=molavi_restore_list',catalogEndpoint:'/?wc-ajax=molavi_catalog',checkout:'/checkout/'});
 await page.addScriptTag({content:fs.readFileSync(source+'/shop.js','utf8')});
 const library=page.locator('#molavi-favorites-dialog'),first=page.locator('[data-molavi-grid] [data-molavi-card]').first();
 assert.equal(await page.locator('[data-molavi-grid] [data-molavi-card]').count(),24);assert.equal(writes.length,0,'page load is read only');
 assert.equal(await page.locator('[data-molavi-basket] [data-molavi-lists]').count(),0,'cart contains no saved-cart system');
 assert.equal(await page.locator('[data-molavi-mobile-count]').isVisible(),false,'empty-cart badge is absent');
 await page.locator('[data-molavi-open-library]').click();await library.locator('.molavi-library-empty').first().waitFor();assert.equal(await library.evaluate(node=>node.open),true,'heart opens library');
 await page.keyboard.press('Escape');assert.equal(await page.locator('[data-molavi-open-library]').evaluate(node=>node===document.activeElement),true,'closing restores heart focus');
 assert.equal(await first.locator('.molavi-add span').innerText(),'افزودن ۴ عدد');await first.locator('.molavi-add').click();await page.waitForFunction(()=>document.querySelector('[data-molavi-checkout]').getAttribute('aria-disabled')==='true');
 await page.locator('[data-molavi-checkout]').click({force:true});assert.equal(new URL(page.url()).pathname,'/','checkout waits for save');holdMutation();await page.waitForFunction(()=>document.querySelector('[data-molavi-checkout]').getAttribute('aria-disabled')==='false');
 assert.equal(await page.locator('[data-molavi-delivery-remaining],[data-molavi-selection-cost]').count(),0);assert.match(await page.locator('[data-molavi-mobile-rate]').innerText(),/۵٪/);
 await first.locator('[data-molavi-favorite]').click();await page.evaluate(()=>{localStorage.setItem('molavi-wishlist-v1','["52","1"]');dispatchEvent(new StorageEvent('storage',{key:'molavi-wishlist-v1'}));});
 await page.locator('[data-molavi-open-library]').click();await library.locator('.molavi-library-product .molavi-control').first().waitFor();await page.waitForFunction(()=>document.querySelector('[data-molavi-save-favorites]').disabled===false);
 assert.equal(await library.locator('.molavi-library-product').count(),2,'favorites include off-page products');
 assert.equal(await library.locator('.molavi-library-product[data-product="1"] .molavi-add span').innerText(),'افزودن ۴ عدد','new favorite controls initialize without an existing button');
 const beforeSave=writes.length;await library.locator('[data-molavi-save-favorites]').click();assert.equal(writes.length,beforeSave,'favorites become a saved cart without changing active cart');
 let lists=await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1')));assert.deepEqual(lists[0].items,{'1':4,'52':4});assert.equal(lists[0].name,'سبد ۱');assert.equal(lists[0].price,undefined);
 let row=library.locator('[data-molavi-saved-cart]').first();await row.locator('summary').click();await row.locator('[data-molavi-rename-list]').click();await row.locator('input[name=name]').fill('خرید هفتگی');await row.locator('button[type=submit]').click();
 assert.equal(await library.locator('summary strong').first().innerText(),'خرید هفتگی','rename is optional and inline');
 await library.locator('[data-molavi-save-list]').click();assert.equal(await library.locator('[data-molavi-saved-cart]').count(),2,'active cart can also be saved without a naming prompt');
 assert.deepEqual(await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-wishlist-v1'))),['52','1'],'saving cart does not pollute favorite products');
 row=library.locator('[data-molavi-saved-cart]').filter({hasText:'خرید هفتگی'});if(!await row.evaluate(node=>node.open))await row.locator('summary').click();await row.locator('[data-molavi-load-list]').click();await library.locator('[data-molavi-lists-status]').filter({hasText:'۲ کالا اضافه شد'}).waitFor();
 assert.equal(writes.at(-1),'merge');assert.equal(quote.qtys['52'],8);
 await row.locator('[data-molavi-replace-list]').click();await library.locator('[data-molavi-lists-status]').filter({hasText:'سبد خرید جدید آماده شد'}).waitFor();assert.deepEqual(quote.qtys,{'1':4,'52':4});
 lists=await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1')));assert.deepEqual(lists[0].items,{'1':4,'52':8},'replacement preserves previous cart');
 await page.screenshot({path:'/tmp/molavi-library-desktop.png'});await page.keyboard.press('Escape');
 await first.locator('h3 a').evaluate(node=>{node.textContent='عنوان کامل و بسیار بلند محصول برای بررسی خوانایی در هر دو حالت شبکه‌ای و فهرستی بدون بریده شدن بخش پایانی';});
 async function titleFits(){return first.locator('h3 a').evaluate(node=>node.scrollHeight<=node.clientHeight+1&&getComputedStyle(node).overflow!=='hidden');}
 assert.equal(await titleFits(),true);await page.locator('[data-molavi-view=list]').click();assert.equal(await titleFits(),true);await page.screenshot({path:'/tmp/molavi-polish-list-desktop.png'});await page.locator('[data-molavi-view=grid]').click();await page.screenshot({path:'/tmp/molavi-polish-desktop.png'});
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(200);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);assert.equal(await titleFits(),true);
 await page.locator('[data-molavi-view=list]').click();assert.equal(await titleFits(),true);await page.screenshot({path:'/tmp/molavi-polish-list-mobile.png'});await page.locator('[data-molavi-view=grid]').click();
 await page.waitForTimeout(4400);const compact=await page.locator('.molavi-mobile-basket').boundingBox(),badge=await page.locator('[data-molavi-mobile-count]').boundingBox(),icon=await page.locator('.molavi-mobile-cart-icon').boundingBox();
 assert.ok(compact.width<=60&&compact.x<30&&compact.y>700);assert.ok(badge.x+badge.width>icon.x+icon.width-4&&badge.y<icon.y+4&&badge.y>=compact.y,'badge sits at icon upper right without clipping');
 await page.setViewportSize({width:320,height:760});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'320px catalog fits');
 await page.locator('[data-molavi-open-library]').click();assert.equal(await library.evaluate(node=>node.getBoundingClientRect().right<=innerWidth),true,'320px library fits');await page.keyboard.press('Escape');
 await page.setViewportSize({width:390,height:844});await page.waitForTimeout(150);
 await page.screenshot({path:'/tmp/molavi-polish-mobile.png'});await page.locator('.molavi-mobile-basket').click();await page.locator('#molavi-basket-dialog.is-open').waitFor();assert.equal(await page.locator('#molavi-basket-dialog [data-molavi-lists]').count(),0);
 await page.keyboard.press('Escape');await page.locator('[data-molavi-open-library]').click();await library.locator('[data-molavi-library-tab=products]').click();await page.screenshot({path:'/tmp/molavi-library-mobile.png'});
 await library.locator('[data-molavi-library-tab=products]').focus();await page.keyboard.press('ArrowLeft');assert.equal(await library.locator('[data-molavi-library-tab=carts]').getAttribute('aria-selected'),'true','tabs support keyboard navigation');await page.keyboard.press('Escape');
 failRead=true;await page.locator('[data-molavi-open-library]').click();await library.locator('[data-molavi-library-retry]').waitFor();assert.deepEqual(await page.evaluate(()=>JSON.parse(localStorage.getItem('molavi-wishlist-v1'))),['52','1']);await library.locator('[data-molavi-library-retry]').click();await page.waitForFunction(()=>document.querySelector('[data-molavi-library-retry]').hidden);await page.keyboard.press('Escape');
 await page.locator('.molavi-purchase-intro [data-molavi-open-help]').click();await page.locator('#molavi-shopping-help[open]').waitFor();await page.keyboard.press('Escape');await page.locator('[data-molavi-more]').click();await page.waitForFunction(()=>document.querySelectorAll('[data-molavi-grid] [data-molavi-card]').length===25);
 const collections=await library.evaluate(node=>node.outerHTML),native=await browser.newPage({viewport:{width:1000,height:900}});
 await native.route('http://molavi-native.test/**',route=>route.fulfill({body:'<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"></head><body class="molavi-site-page woocommerce-cart">'+heart+'<main class="molavi-customer-main"><form class="woocommerce-cart-form"><div class="woocommerce-cart-form__cart-item"><a data-product_id="52"></a><input class="qty" value="12"></div><button name="update_cart">به‌روزرسانی</button></form></main>'+collections+'</body></html>',contentType:'text/html;charset=utf-8'}));
 await native.goto('http://molavi-native.test/cart/');await native.evaluate(config=>window.molaviShop=config,{catalog:data.catalog,initial:quote,qtys:{'52':4},buyerType:'household',nonce:'test-nonce'});await native.addScriptTag({content:fs.readFileSync(source+'/shop.js','utf8')});
 await native.locator('[data-molavi-open-library]').click();await native.locator('[data-molavi-library-tab=carts]').click();await native.locator('[data-molavi-save-list]').click();assert.deepEqual(await native.evaluate(()=>JSON.parse(localStorage.getItem('molavi-shopping-lists-v1'))[0].items),{'52':12},'native cart saves current form quantities');
 await native.keyboard.press('Escape');await native.locator('input.qty').fill('16');await native.locator('input.qty').dispatchEvent('change');await native.locator('[data-molavi-open-library]').click();assert.equal(await native.locator('[data-molavi-save-list]').isDisabled(),true,'saved cart waits for native quantity update');
 await native.close();assert.deepEqual(errors,[]);console.log(JSON.stringify({passed:true,checks:38,cartWrites:writes,collectionReads:reads.length,errors}));await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
