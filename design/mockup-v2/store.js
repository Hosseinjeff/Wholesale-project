(function () {
    'use strict';
    const data = JSON.parse(document.getElementById('demo-data').textContent);
    const products = data.products;
    const byId = Object.fromEntries(products.map(p => [p.id, p]));
    let quantities = {...data.initialCart};
    let category = 'all', query = '', sort = 'default', toastTimer, lastCartTrigger;
    const grid = document.getElementById('products');
    const basket = document.getElementById('basket');
    const overlay = document.getElementById('basket-overlay');
    const number = new Intl.NumberFormat('fa-IR', {maximumFractionDigits:0});
    const percentNumber = new Intl.NumberFormat('fa-IR', {maximumFractionDigits:1});
    const fa = n => number.format(n);
    const pct = n => percentNumber.format(n * 100) + '٪';
    const money = n => fa(n) + ' <span class="currency">تومان</span>';
    const escape = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const normalize = s => s.replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/\u200c/g,' ').trim().toLowerCase();
    const getQuote = () => MolaviDemoPricing.quote(products, quantities, data.unlockTarget);
    const categories = ['all', ...new Set(products.map(p => p.category))];
    document.getElementById('categories').innerHTML = categories.map(c => '<button type="button" data-category="'+escape(c)+'" aria-pressed="'+(c==='all')+'">'+escape(c==='all'?'همه کالاها':c)+'</button>').join('');

    function stepper(p, qty) {
        return '<div class="stepper" role="group" aria-label="تعداد '+escape(p.name)+'"><button type="button" data-step="1" data-id="'+p.id+'" aria-label="افزودن یک عدد '+escape(p.name)+'"'+(qty>=999?' disabled':'')+'>+</button><span><b>'+fa(qty)+'</b> <span class="quantity-suffix">عدد</span></span><button type="button" data-step="-1" data-id="'+p.id+'" aria-label="'+(qty<=p.min?'حذف '+escape(p.name)+' از سبد':'کم کردن یک عدد '+escape(p.name))+'">−</button></div>';
    }

    function renderGrid(q, focusKey) {
        let shown = products.filter(p => (category==='all'||p.category===category)&&normalize(p.name+' '+p.supplier).includes(normalize(query)));
        if (sort==='price') shown.sort((a,b)=>q.units[a.id]-q.units[b.id]);
        if (sort==='maximum') shown.sort((a,b)=>b.maxDiscount-a.maxDiscount);
        document.getElementById('results-count').textContent = fa(shown.length)+' کالای نمونه';
        document.getElementById('no-results').hidden = shown.length>0;
        grid.innerHTML = shown.map(p => {
            const qty = quantities[p.id] || 0;
            const rate = q.rates[p.id];
            return '<article class="product-card'+(qty?' is-selected':'')+'" data-product="'+p.id+'">'
                +'<div class="product-picture"><span class="max-badge'+(p.discountable?'':' fixed-badge')+'">'+(p.discountable?'تا '+pct(p.maxDiscount)+' تخفیف':'قیمت ثابت')+'</span><img src="'+p.image+'" alt="تصویر نمونه '+escape(p.name)+'" width="240" height="240" loading="lazy"></div>'
                +'<div class="product-copy"><h3>'+escape(p.name)+'</h3><p class="product-meta">'+escape(p.pack)+' · '+escape(p.supplier)+'</p><p class="product-min">حداقل خرید: '+fa(p.min)+' عدد</p></div>'
                +'<div class="price-block"><div class="regular-price"><span>قیمت مصرف</span><'+(p.discountable?'s':'span')+'>'+fa(p.regular)+'</'+(p.discountable?'s':'span')+'></div><div class="current-price" data-price="'+p.id+'">'+money(q.units[p.id])+'</div>'
                +'<div class="current-discount">'+(p.discountable?'تخفیف فعلی <span data-rate="'+p.id+'">'+pct(rate)+(q.progress>=1?' · سقف فعال':'')+'</span>':'بدون تخفیف پلکانی')+'</div></div>'
                +'<div class="card-actions">'+(qty?stepper(p,qty):'<button type="button" class="add-button" data-step="1" data-id="'+p.id+'" aria-label="افزودن '+fa(p.min)+' عدد '+escape(p.name)+'"><span aria-hidden="true">+</span> افزودن'+(p.min>1?' '+fa(p.min)+' عدد':'')+'</button>')+'</div></article>';
        }).join('');
        if (focusKey) {
            const target = grid.querySelector('[data-id="'+focusKey.id+'"][data-step="'+focusKey.step+'"]');
            if (target) target.focus({preventScroll:true});
        }
    }

    function renderBasket(q, focusKey) {
        document.querySelectorAll('[data-count]').forEach(n=>n.textContent=fa(q.count));
        document.querySelectorAll('[data-kinds]').forEach(n=>n.textContent=q.lines.length?'('+fa(q.lines.length)+' کالا)':'');
        document.querySelectorAll('[data-total]').forEach(n=>n.innerHTML=money(q.payTotal));
        document.querySelectorAll('[data-saved]').forEach(n=>n.innerHTML=money(q.saved));
        document.querySelectorAll('[data-regular]').forEach(n=>n.innerHTML=money(q.regularTotal));
        document.querySelectorAll('[data-average]').forEach(n=>n.textContent=pct(q.rate));
        document.querySelector('[data-progress-percent]').textContent=fa(Math.round(q.progress*100))+'٪';
        document.querySelector('[data-progress-title]').textContent=q.progress>=1?'بیشترین تخفیف‌ها فعال شد!':'در مسیر بیشترین تخفیف';
        document.getElementById('progress').value=q.progress*100;
        document.querySelector('[data-progress-description]').textContent=q.progress>=1?'هر کالای تخفیف‌دار به کمترین قیمت خودش رسیده.':fa(q.remaining)+' تومان دیگر تا بیشترین تخفیف‌ها';
        const lines=document.getElementById('basket-lines');
        lines.innerHTML=q.lines.map(line=>{
            const p=byId[line.id];
            return '<article class="basket-line"><img class="basket-thumb" src="'+p.image+'" width="47" height="54" alt=""><div class="basket-line-main"><div class="basket-line-title"><h3>'+escape(p.name)+'</h3><button type="button" class="remove-item" data-remove="'+p.id+'" aria-label="حذف '+escape(p.name)+'">×</button></div><p class="line-rate">'+(p.discountable?'تخفیف فعلی '+pct(q.rates[p.id]):'قیمت ثابت')+'</p><div class="basket-line-bottom">'+stepper(p,line.qty)+'<strong>'+money(line.total)+'</strong></div></div></article>';
        }).join('')||'<div class="empty-basket"><b>سبدت منتظر انتخاب توست.</b>از کالاها شروع کن؛ قیمت‌ها با سبدت تغییر می‌کنند.</div>';
        if(focusKey){
            const target=lines.querySelector('[data-id="'+focusKey.id+'"][data-step="'+focusKey.step+'"]');
            if(target)target.focus({preventScroll:true});
        }
        document.querySelector('[data-review]').disabled=q.lines.length===0;
        document.getElementById('review-lines').innerHTML=q.lines.map(line=>'<div class="review-item"><div>'+escape(byId[line.id].name)+'<small>'+fa(line.qty)+' عدد · تخفیف فعلی '+pct(q.rates[line.id])+'</small></div><strong>'+money(line.total)+'</strong></div>').join('');
    }

    function render(focusKey) {
        const q=getQuote();
        renderGrid(q,focusKey&&focusKey.area==='grid'?focusKey:null);
        renderBasket(q,focusKey&&focusKey.area==='basket'?focusKey:null);
    }
    function toast(text) {
        const node=document.getElementById('toast');
        node.textContent=text;node.classList.add('is-visible');
        clearTimeout(toastTimer);toastTimer=setTimeout(()=>node.classList.remove('is-visible'),3500);
    }
    function closeCart() {
        basket.classList.remove('is-open');overlay.hidden=true;document.body.classList.remove('body-cart-open');
        basket.removeAttribute('aria-modal');basket.removeAttribute('role');
        if(lastCartTrigger)lastCartTrigger.focus({preventScroll:true});
    }
    function openCart(trigger) {
        if(window.matchMedia('(max-width:740px)').matches){
            lastCartTrigger=trigger;basket.classList.add('is-open');overlay.hidden=false;document.body.classList.add('body-cart-open');
            basket.setAttribute('role','dialog');basket.setAttribute('aria-modal','true');
            basket.querySelector('[data-close-cart]').focus({preventScroll:true});
        }else{
            basket.scrollIntoView({behavior:'smooth',block:'center'});
            basket.setAttribute('tabindex','-1');basket.focus({preventScroll:true});
        }
    }
    function applyFilter(){
        document.querySelectorAll('[data-category]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.category===category)));
        render();
    }
    document.addEventListener('click',event=>{
        const b=event.target.closest('button');if(!b)return;
        if(b.hasAttribute('data-step')||b.hasAttribute('data-remove')){
            const id=b.dataset.id||b.dataset.remove,p=byId[id];
            const previous=quantities[id]||0;
            let next=b.hasAttribute('data-remove')?0:previous===0?p.min:previous+Number(b.dataset.step);
            const underMinimum=next>0&&next<p.min;
            if(next<p.min)next=0;
            next=Math.min(999,next);
            if(next)quantities[id]=next;else delete quantities[id];
            const focusKey={id,step:b.dataset.step||'1',area:b.closest('#basket')?'basket':'grid'};
            render(focusKey);
            if(underMinimum)toast('حداقل این کالا '+fa(p.min)+' عدد است؛ از سبد حذف شد و قیمت‌ها به‌روز شدند.');
            else if(next===0)toast('کالا حذف شد؛ قیمت‌ها با سبد جدید به‌روز شدند.');
            else if(previous===0)toast(fa(p.min)+' عدد به سبد اضافه شد؛ تخفیف همه کالاها به‌روز شد.');
            return;
        }
        if(b.hasAttribute('data-category')){category=b.dataset.category;applyFilter();return;}
        if(b.hasAttribute('data-help')){document.getElementById('help-dialog').showModal();return;}
        if(b.hasAttribute('data-open-cart')){openCart(b);return;}
        if(b.hasAttribute('data-close-cart')){closeCart();return;}
        if(b.hasAttribute('data-close-dialog')){b.closest('dialog').close();return;}
        if(b.hasAttribute('data-review')){document.getElementById('request-result').hidden=true;document.getElementById('review-dialog').showModal();return;}
        if(b.id==='demo-submit'){document.getElementById('request-result').hidden=false;document.getElementById('request-result').scrollIntoView({block:'nearest'});return;}
        if(b.hasAttribute('data-account')){toast('ورود و پیگیری سفارش در نسخه نهایی به حساب واقعی فروشگاه متصل می‌شود.');return;}
        if(b.id==='reset-demo'){quantities={...data.initialCart};render();toast('سبد نمونه به حالت شروع برگشت.');return;}
        if(b.id==='clear-search'){category='all';query='';document.getElementById('search').value='';applyFilter();}
    });
    document.getElementById('search-form').addEventListener('submit',event=>{event.preventDefault();query=document.getElementById('search').value;applyFilter();document.getElementById('catalog').scrollIntoView({behavior:'smooth'});});
    document.getElementById('search').addEventListener('input',event=>{query=event.target.value;render();});
    document.getElementById('sort').addEventListener('change',event=>{sort=event.target.value;render();});
    overlay.addEventListener('click',closeCart);
    document.addEventListener('keydown',event=>{
        if(!basket.classList.contains('is-open'))return;
        if(event.key==='Escape'&&!document.querySelector('dialog[open]')){event.preventDefault();closeCart();return;}
        if(event.key==='Tab'&&!document.querySelector('dialog[open]')){
            const nodes=[...basket.querySelectorAll('button:not(:disabled),a[href],select')].filter(n=>n.offsetParent!==null);
            if(!nodes.length)return;
            const first=nodes[0],last=nodes[nodes.length-1];
            if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
            else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
        }
    });
    window.addEventListener('resize',()=>{
        if(!window.matchMedia('(max-width:740px)').matches){closeCart();basket.removeAttribute('aria-modal');basket.removeAttribute('role');}
    });
    // Exposed for read-only verification of this isolated prototype.
    window.molaviDemo={quote:getQuote,products:()=>products.map(p=>({...p})),quantities:()=>({...quantities})};
    render();
})();
