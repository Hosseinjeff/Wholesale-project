/* Product management application. One state owner, one card renderer, lazy action panels. */
(() => {
    'use strict';
    const el = (tag, text, name) => {
        const item = document.createElement(tag);
        if (text !== undefined && text !== null) item.textContent = String(text);
        if (name) item.className = name;
        return item;
    };
    const fa = value => Number(value || 0).toLocaleString('fa-IR');
    const normalize = value => String(value || '').normalize('NFKC').toLocaleLowerCase('fa')
        .replace(/[يى]/g,'ی').replace(/ك/g,'ک').replace(/[۰-۹]/g,c=>'۰۱۲۳۴۵۶۷۸۹'.indexOf(c))
        .replace(/[٠-٩]/g,c=>'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/[\s\u200c]+/g,' ').trim();
    const sections = [
        ['source_integrity','پیام تأمین‌کننده'],['ai','مشخصات'],['references','مرجع'],['product_messages','پیام‌های کالا'],
        ['supplier_prices','قیمت تأمین‌کننده'],['market_prices','قیمت عادی'],['conflicts','همگام‌سازی'],['holds','انتشار'],['stock','موجودی'],['storefront','فروشگاه']
    ];
    const actionLabels = {source_integrity:'بررسی منبع',ai:'بررسی مشخصات',references:'انتخاب مرجع',product_messages:'پیام‌های کالا',
        supplier_prices:'تأیید قیمت',market_prices:'بررسی قیمت عادی',conflicts:'رفع تعارض',holds:'بررسی توقف',status:'بررسی انتشار',stock:'پیگیری موجودی',storefront:'بررسی فروشگاه'};
    const sourceFor = product => product.ai[0] || product.supplierPrices[0] || product.references[0] || {};
    function identities(product) {
        return [product.product_id,product.status?.latest_offer_id,product.status?.reference_match_id,
            ...product.ai.flatMap(row=>[row.case_id,row.offer_id,...(row.reference_candidates || []).map(c=>c.reference_id)]),
            ...product.references.flatMap(row=>[row.case_id,row.offer_id,...(row.candidates || []).map(c=>c.reference_id)]),
            ...product.conflicts.map(row=>row.conflict_id),...product.holds.map(row=>row.hold_id),
            ...product.supplierPrices.flatMap(row=>[row.offer_id,row.product_id])].filter(Boolean).map(String);
    }
    function create(root, hooks) {
        const params = new URLSearchParams(location.search);
        const focusType=['product_id','offer_id','case_id','conflict_id','hold_id','reference_id'].find(key=>params.get(key)) || 'product_id';
        const state = {
            focusType,
            view:['products','references','sources','system','pricing','autopilot'].includes(params.get('view')) ? params.get('view')
                : params.get('workbench_action') === 'reextract' || location.hash.includes('reextract') ? 'sources' : 'products',
            scope:params.get('scope') || 'ready',action:params.get('workbench_action') || '',
            focus:['product_id','offer_id','case_id','conflict_id','hold_id','reference_id'].map(key=>params.get(key)).find(Boolean) || '',
            search:params.get('q') || '',channel:params.get('channel') || '',limit:24,
            active:'',section:'',panels:new Map(),pageCache:new Map(),selecting:false,selected:new Set()
        };
        if (state.scope === 'attention') state.scope = 'ready';
        if (!Object.hasOwn(actionLabels,state.action)) state.action = '';
        if (state.focus) state.scope = 'all';
        let model = null;
        let busy = false;
        let sequence = 0;
        let activityReport = null;
        let activityTimer = 0;
        let activityRequest = null;
        let activityStale = false;
        let activityReadAt=0;
        let activitySignature = '';
        let watchOffset=0;
        const activityKey=hooks.actionStore && 'molavi.activity.'+hooks.actionStore;
        const actions=new Map();
        const readDismissedMarket=()=>{
            try {return JSON.parse(localStorage.getItem(activityKey+'.dismissed-market')||'[]');}
            catch (_) {return [];}
        };
        const dismissedMarket=new Set(activityKey?readDismissedMarket():[]);
        let activityHistoryOpen=false;
        let openProgress=null;
        if (activityKey) {
            try {
                for (const item of JSON.parse(localStorage.getItem(activityKey) || '[]')) {
                    if (item.state==='submitting') item.state='unknown';
                    actions.set(item.id,item);
                }
            } catch (_) {}
        }
        root.className = 'molavi-dashboard';
        root.removeAttribute('aria-live');
        root.dir = 'rtl';
        const app = el('div',null,'md-app');
        const head = el('header',null,'md-header');
        const brand = el('div',null,'md-brand');
        brand.append(el('span','مولوی‌سل','md-wordmark'),el('span','مدیریت فروشگاه','md-subtitle'));
        const refresh = button('تازه‌سازی',()=> (hooks.manualRefresh||hooks.refresh)(),'md-button md-button-quiet');
        const headerActions=el('div',null,'md-header-actions');
        if(hooks.renderPricing) {
            const gear=activityButton('تنظیمات','admin-generic',()=>navigate('pricing'));
            gear.classList.add('md-header-gear');
            headerActions.append(gear);
        }
        headerActions.append(refresh);
        head.append(brand,headerActions);
        const nav = el('nav',null,'md-navigation');
        nav.setAttribute('aria-label','مدیریت فروشگاه');
        const navButtons = new Map();
        [['products','محصولات'],['references','فهرست مرجع'],['sources','پیام‌های منبع'],['system','وضعیت سیستم'],['autopilot','تصمیم‌های خودکار']].forEach(([key,label])=>{
            const item=button(label,()=>navigate(key),'md-nav-item');
            navButtons.set(key,item); nav.appendChild(item);
        });
        const status = el('p','','md-feedback');
        status.setAttribute('role','status'); status.setAttribute('aria-live','polite'); status.hidden=true;
        const activityBar = el('aside',null,'md-activity');
        activityBar.setAttribute('aria-label','فعالیت داشبورد');
        activityBar.hidden=true;
        const activityLive = el('span',null,'screen-reader-text');
        activityLive.setAttribute('role','status');
        const systemActivity = el('div',null,'md-system-activity');
        const health = el('div',null,'md-health');
        const main = el('main',null,'md-main');
        app.append(head,nav,activityBar,activityLive,status,health,main); root.replaceChildren(app);
        function button(text, onClick, name='md-button') {
            const item=el('button',text,name);item.type='button';item.addEventListener('click',onClick);return item;
        }
        function activityButton(label, icon, onClick, text='') {
            const control=button(null,onClick,text?'md-activity-source':'md-activity-icon');
            control.setAttribute('aria-label',text?`${text} · ${label}`:label);
            control.title=label;
            if(text) control.append(el('span',text));
            const glyph=el('span',null,'dashicons dashicons-'+icon);
            glyph.setAttribute('aria-hidden','true');
            control.append(glyph);
            return control;
        }
        const activityLabels={
            reextract:'بازاستخراج پیام',batch:'پردازش پیام‌های ربات',intake:'پیام ورودی',
            reference_suggest:'پیشنهاد مرجع',reference_index:'نمایه‌سازی مرجع',reference_refresh:'به‌روزرسانی فهرست مرجع',
            market_prices:'بررسی قیمت بازار',repricing:'به‌روزرسانی تخفیف‌ها',publish:'انتشار فروشگاه',
        };
        const activityStates={running:'در حال اجرا',queued:'در صف',quota_wait:'منتظر سهمیه',needs_reference:'منتظر تصمیم مرجع',
            submitting:'در حال ارسال',completed:'کامل شد',failed:'ناموفق',cancelled:'لغو شد',
            partial:'با موارد نیازمند بررسی پایان یافت',unknown:'نتیجه تأیید نشد',cancelling:'در حال لغو',
            waiting_publication:'منتظر رفع توقف انتشار'};
        const taskLabels={extract:'استخراج',match:'تطبیق مرجع',embed:'نمایه‌سازی'};
        const localClock=new Intl.DateTimeFormat('fa-IR',{timeZone:'Asia/Tehran',hour:'2-digit',minute:'2-digit'});
        const localDateTime=new Intl.DateTimeFormat('fa-IR',{timeZone:'Asia/Tehran',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
        function activityDescription(entry,quota) {
            const label=entry.kind==='reextract'
                ? (entry.total===1?'بازاستخراج یک پیام':'بازاستخراج گروهی') : activityLabels[entry.kind];
            const text=[label,activityStates[entry.state]];
            if (entry.total) text.push(`${fa(entry.completed)} از ${fa(entry.total)}${entry.unit==='pages'?' صفحه':''}`);
            else if (entry.running || entry.queued || entry.needs_reference) {
                text.push(...[entry.running&&`${fa(entry.running)} در اجرا`,entry.queued&&`${fa(entry.queued)} در صف`,
                    entry.needs_reference&&`${fa(entry.needs_reference)} منتظر مرجع`].filter(Boolean));
            }
            if (entry.retry_at) text.push(`نوبت بعد از ${localDateTime.format(entry.retry_at*1000)}`);
            const task=entry.kind==='reference_suggest'?'match':['reextract','batch'].includes(entry.kind)?'extract':'';
            const lastModel=entry.state==='running'?quota.last_models?.[task]?.model:'';
            const model=entry.model || lastModel || (task==='extract'?quota.fixed_extraction_model:'');
            if (model) {
                text.push(lastModel?`آخرین مدل ${model}`:model);
                const bucket=quota.buckets?.find(item=>item.models.includes(model));
                if (bucket && quota.state==='tracked') text.push(`${fa(bucket.rpd_remaining)} درخواست باقی‌مانده`);
            }
            return text.filter(Boolean).join(' · ');
        }
        function openActivity(kind) {
            if(kind==='repricing')return navigate('pricing');
            if(kind==='publish')return reviewHold();
            if (['reextract','batch','intake'].includes(kind)) {
                state.sourceContext=null;
                state.pageCache.delete('sources');
                navigate('sources');
            } else navigate('references');
        }
        function progressDialog(resolve,go) {
            const dialog=el('dialog',null,'md-dialog');
            const content=el('div',null,'md-progress-report');
            const actions=el('div',null,'md-header-actions');
            const openButton=button('باز کردن کار',()=>{dialog.close();go();});
            const retryButton=button('تلاش دوباره',()=>{dialog.close();go();},'md-button');
            actions.append(openButton,retryButton,button('بستن',()=>dialog.close(),'md-link'));
            dialog.append(content,actions);
            const render=()=>{
                const item=resolve()||{};
                const current=item.current||{};
                const stuck=referenceRefreshStuck(item);
                retryButton.hidden=!stuck;
                openButton.hidden=stuck;
                const phases={connecting:'اتصال به API رضوان پخش',fetching:'دریافت صفحه‌های فهرست',validating:'اعتبارسنجی شمارش و داده‌ها',
                    comparing:'مقایسه با فهرست فعلی',applying:'اعمال فهرست و بازبینی کالاها',saved:'ثبت نسخهٔ دریافت‌شده',
                    retrying:'تلاش دوباره برای دریافت فهرست',running:'پردازش در کارگر',queued:'منتظر کارگر',
                    waiting_reference:'منتظر تصمیم دربارهٔ مرجع',waiting_publication:'منتظر رفع توقف انتشار',quota_wait:'منتظر سهمیهٔ سرویس'};
                const date=value=>value>0?localDateTime.format(value*1000):'';
                const done=Number(item.completed||0);
                content.replaceChildren(el('h2',item.label||activityLabels[item.kind]||'وضعیت کار'),
                    el('p',phases[current.phase]||activityStates[item.state]||'در انتظار گزارش کارگر'));
                if (item.total>0) {
                    const bar=el('progress');bar.max=item.total;bar.value=done;
                    bar.setAttribute('aria-label','پیشرفت ثبت‌شده');
                    content.append(bar,el('p',`${fa(done)} از ${fa(item.total)}${item.unit==='pages'?' صفحه':' مورد'} · ${fa(Math.round(done*100/item.total))}٪`));
                }
                content.append(facts([
                    ['ردیف‌های دریافت‌شده',current.records?fa(current.records)+(current.total_records?` از ${fa(current.total_records)}`:''):''],
                    ['پیام فعلی',[current.channel,current.message_id].filter(Boolean).join(' · ')],
                    ['در حال اجرا',item.running?fa(item.running):''],['در صف',item.queued?fa(item.queued):''],
                    ['ناموفق',item.failed?fa(item.failed):''],['لغوشده',item.cancelled?fa(item.cancelled):''],
                    ['شروع',date(current.started_at)],['آخرین پیشرفت',date(current.updated_at)],
                    ['نوبت بعد',date(item.retry_at||current.retry_at)],['مدل',item.model],['نتیجه',item.detail],
                ]));
            };
            openProgress=render;
            dialog.addEventListener('close',()=>{openProgress=null;dialog.remove();},{once:true});
            root.append(dialog);render();dialog.showModal();refreshActivity();
        }
        function quotaView(quota) {
            const section=el('section',null,'md-quota');
            section.appendChild(el('h2','سهمیهٔ روزانهٔ Google'));
            if (!quota?.total) {
                section.appendChild(el('p',quota?.fixed_extraction_model
                    ? `مدل استخراج: ${quota.fixed_extraction_model} · مصرف ثبت‌شده در دسترس نیست.`
                    : 'در انتظار گزارش تنظیمات مدل از سرویس استخراج.'));
                return section;
            }
            const {used,limit,remaining}=quota.total;
            section.appendChild(el('p',quota.state==='tracked'
                ? `${fa(used)} از ${fa(limit)} درخواست ثبت شد · ${fa(remaining)} باقی‌مانده`
                : `${fa(limit)} درخواست تخصیص‌یافته · در انتظار دفتر مصرف`));
            section.appendChild(el('small',`سهمیهٔ همین محیط · بازنشانی ${localDateTime.format(quota.reset_at*1000)} به وقت تهران`));
            const details=el('details');
            details.open=Boolean(systemActivity.querySelector('.md-quota details')?.open);
            details.appendChild(el('summary','سهمیهٔ هر مدل'));
            const rows=el('ul',null,'md-quota-models');
            (quota.buckets || []).forEach(bucket=>{
                const row=el('li');
                const ids=el('bdi',bucket.models.join(' / '));
                row.append(ids,el('span',bucket.tasks.map(task=>taskLabels[task]).join('، ')));
                const daily=quota.state==='tracked'
                    ? `${fa(bucket.rpd_used)} از ${fa(bucket.rpd_limit)} درخواست امروز` : `${fa(bucket.rpd_limit)} درخواست در روز`;
                const minute=`${fa(bucket.rpm_limit)} درخواست و ${fa(bucket.tpm_limit)} توکن در دقیقه`;
                row.append(el('span',`${daily} · ${minute}`));
                if (bucket.reserve_rpd) row.appendChild(el('small',`${fa(bucket.reserve_rpd)} درخواست محفوظ برای کار تعاملی`));
                if (bucket.blocked_until) row.appendChild(el('small',`محدودیت موقت تا ${localDateTime.format(bucket.blocked_until*1000)}`));
                rows.appendChild(row);
            });
            details.appendChild(rows);section.appendChild(details);
            const models=Object.entries(quota.last_models || {}).map(([task,value])=>
                `${taskLabels[task]}: ${value.model}، ${localClock.format(value.at*1000)}`);
            if (models.length) section.appendChild(el('small',`آخرین مدل‌های استفاده‌شده · ${models.join(' | ')}`));
            return section;
        }
        const activeAction = item => ['queued','running','submitting','quota_wait','waiting_publication','needs_reference','cancelling'].includes(item.state);
        function saveActions() {
            if (activityKey) {
                try {
                    localStorage.setItem(activityKey,JSON.stringify([...actions.values()].map(({controller,...item})=>item)));
                } catch (_) {}
            }
            paintActivity();
        }
        function actionOrigin(payload) {
            const card=document.activeElement?.closest('.md-product-card');
            const batch=payload?.action==='reopen_products' || payload?.action==='dismiss' && payload.records?.length>1;
            const itemId=payload?.action==='reopen_products'&&payload.items?.length===1 ? payload.items[0].product_id : '';
            const product=state.view==='products' && model?.products.find(row=>
                itemId ? row.product_id===itemId : !batch && (
                    (card && row.key===card.dataset.productKey) || (payload?.offer_id && identities(row).includes(payload.offer_id))
                    || (!card && !payload?.offer_id && row.key===state.active)));
            const market=Boolean(payload?.action?.includes('market_price') || document.activeElement?.closest('.molavi-market-price-review'));
            const filter=batch ? {scope:state.scope,action:state.action,search:state.search,channel:state.channel,focus:state.focus,focusType:state.focusType} : null;
            return {view:state.view,key:product?.key||'',section:payload?.action==='reopen_products'?'ai':state.section,product:product?.name||'',filter,
                offer:payload?.offer_id||product?.status?.latest_offer_id||(product?sourceFor(product).offer_id:'')||'',
                hold:payload?.hold_id||'',market};
        }
        function openOrigin(origin) {
            if(origin.hold && origin.view==='system') return reviewHold('molavi-batch-'+encodeURIComponent(origin.hold));
            clearSelection(true);
            if(origin.filter&&!origin.key) Object.assign(state,origin.filter,{limit:24});
            if(origin.view!=='products'||!origin.key||!model) return navigate(origin.view);
            const product=model.products.find(row=>row.key===origin.key || (origin.offer && identities(row).includes(origin.offer)));
            if (!product) return origin.offer ? reviewInboxItem('offer_id',origin.offer) : navigate('products');
            state.scope='all';state.action='';state.search='';state.channel='';
            const offer=identities(product).includes(origin.offer)?origin.offer:'';
            state.focusType=offer?'offer_id':'product_id';
            state.focus=offer||product.product_id;
            state.active=product.key;
            state.section=origin.section || (origin.market?'market_prices':'source_integrity');
            navigate('products');
            const card=main.querySelector('.md-selected') || [...main.querySelectorAll('.md-product-card')].find(row=>row.dataset.productKey===product.key);
            if(origin.market) {
                const price=card?.querySelector('.molavi-market-price-review');
                if(price) price.open=true;
            }
            card?.scrollIntoView({block:'nearest',behavior:'smooth'});
        }
        function startAction(label,payload,controller) {
            const item={id:window.crypto?.randomUUID?.() || Math.random().toString(36),label,
                origin:actionOrigin(payload),state:'submitting',controller};
            if(payload?.action==='refresh'&&Object.hasOwn(payload,'expected_last_attempt_at')) item.progressKind='reference_refresh';
            actions.set(item.id,item);
            saveActions();
            return {
                finish(result) {
                    item.controller=null;
                    if(['reopen_products','review_publication'].includes(payload?.action)) {
                        const {opened,already_open:already,unavailable}=result;
                        item.state=unavailable?(opened||already?'partial':'failed'):'completed';
                        item.detail=[opened?`${fa(opened)} بازگشایی شد`:'',
                            already?`${fa(already)} از قبل در صف بود`:'',
                            unavailable?`${fa(unavailable)} قابل بازگشایی نبود`:''].filter(Boolean).join(' · ');
                    } else if(payload?.action==='save_discount_policy' && ['queued','running'].includes(result.state) && result.run?.id) {
                        item.watch={kind:'repricing',key:result.run.id};
                        item.state=result.state;
                        item.detail=`${fa(result.run.completed)} از ${fa(result.run.total)}`;
                    } else if(payload?.action?.startsWith('start_') && result.run_id) {
                        item.watch={kind:'reextract',key:result.run_id};item.state='queued';
                    } else if(payload?.action==='request_market_prices' && result.lookup_key
                        && ['queued','searching','quota'].includes(result.state)) {
                        item.watch={kind:'market_prices',key:result.lookup_key};item.state=result.state==='quota'?'quota_wait':'queued';
                    } else if(typeof result.job_id==='string' || typeof result.publish_job==='string') {
                        const key=result.job_id || result.publish_job;
                        const kind=key.startsWith('reference_suggest:')?'reference_suggest':'job';
                        item.watch={kind,key};item.state='queued';
                    } else {
                        item.state=result.state==='failed'||result.issues?'failed':'completed';
                        const outcomes={no_match:'قیمت قابل مقایسه پیدا نشد.',not_needed:'قیمت عادی در دادهٔ کالا موجود است.',
                            ambiguous_identity:'ابتدا نوع دقیق کالا را مشخص کنید.',unchanged:'فهرست تغییری نداشت.',
                            staged:'فهرست آمادهٔ بررسی است.',updated:'فهرست به‌روز شد.',failed:'جزئیات خطا در مبدأ قابل مشاهده است.'};
                        if (payload?.action==='save_discount_policy') outcomes.unchanged='تنظیمات فعلی ثبت شده است.';
                        item.detail=outcomes[result.state] || (Array.isArray(result.results)?fa(result.results.length)+' نتیجه':'');
                    }
                    saveActions();
                    if(item.watch) refreshActivity();
                },
                fail(message,known) {
                    item.controller=null;
                    item.state=message ? (known?'failed':'unknown') : 'cancelled';
                    item.detail=message || '';
                    saveActions();
                }
            };
        }
        function actionRow(item) {
            const row=el('li',null,'md-activity-action');
            const text=[item.label,item.origin.product,activityStates[item.state],item.detail].filter(Boolean).join(' · ');
            const detailed=item.progressKind||['reextract','job','reference_suggest','repricing'].includes(item.watch?.kind);
            row.append(activityButton(detailed?'جزئیات پیشرفت':'رفتن به مبدأ',detailed?'info-outline':'arrow-left-alt',()=>{
                if(detailed) progressDialog(()=>({...item,...item.progress,...(item.progressKind
                    ?activityReport?.groups?.find(group=>group.kind===item.progressKind):null)}),()=>openOrigin(item.origin));
                else openOrigin(item.origin);
            },text));
            if (!activeAction(item)) {
                row.append(activityButton('بستن اعلان','no-alt',()=>{
                    actions.delete(item.id);
                    if (item.automaticMarket) {
                        dismissedMarket.add(item.id);
                        if (activityKey) try {
                            localStorage.setItem(activityKey+'.dismissed-market',JSON.stringify([...dismissedMarket].slice(-100)));
                        } catch (_) {}
                    }
                    saveActions();
                }));
                return row;
            }
            const canCancel=item.controller || (item.watch && ['reextract','reference_suggest','market_prices'].includes(item.watch.kind)
                && (item.state!=='running'||item.watch.kind==='reextract'));
            if (!canCancel || item.state==='cancelling') return row;
            row.append(activityButton('لغو','dismiss',async()=>{
                if(item.controller) {item.controller.abort();return;}
                const previous=item.state;
                item.state='cancelling';
                saveActions();
                try {
                    const result=await hooks.cancelAction(item.watch);
                    item.state=result.state || 'cancelled';
                    item.detail=result.running?'لغو پیام‌های در انتظار ثبت شد.':'';
                    if (item.watch.kind==='market_prices') refreshMarket(item.watch.key,item.state);
                    refreshActivity();
                } catch (_) {
                    item.state=previous;
                    item.detail='لغو این کار تأیید نشد.';
                }
                saveActions();
            }));
            return row;
        }
        function referenceRefreshStuck(entry) {
            if(entry.kind!=='reference_refresh')return false;
            if(entry.state==='failed')return true;
            const updated=Number(entry.current?.updated_at||0);
            return entry.state==='running'&&updated>0&&(Date.now()/1000-updated)>600;
        }
        function groupRow(entry,quota) {
            const row=el('li');
            row.append(activityButton('جزئیات پیشرفت','info-outline',()=>progressDialog(
                ()=>activityReport?.groups?.find(group=>group.kind===entry.kind)
                    ||(entry.kind==='reference_refresh'?activityReport?.reference_progress:null)||{...entry,state:'unknown',current:{}},
                ()=>openActivity(entry.kind)),activityDescription(entry,quota)));
            if(referenceRefreshStuck(entry)) row.append(button('تلاش دوباره',()=>openActivity(entry.kind),'md-button'));
            return row;
        }
        function paintActivity() {
            if (openProgress) openProgress();
            const groups=[...(activityReport?.groups || [])];
            groups.sort((a,b)=>Number(b.state==='running')-Number(a.state==='running'));
            const receipts=[...actions.values()].reverse().sort((a,b)=>Number(activeAction(b))-Number(activeAction(a)));
            const quota=activityReport?.quota || {};
            const signature=JSON.stringify([groups,quota,activityStale,receipts]);
            if (signature===activitySignature) return;
            activitySignature=signature;
            const watched=new Set(receipts.filter(activeAction).map(item=>item.watch?.kind));
            const automatic=groups.filter(entry=>!watched.has(entry.kind));
            const top=el('ul',null,'md-activity-list');
            const all=el('ul',null,'md-activity-list');
            const featured=automatic.filter(entry=>['repricing','reference_refresh'].includes(entry.kind)&&entry.state==='running');
            const rows=[...featured.map(entry=>groupRow(entry,quota)),
                ...receipts.map(actionRow),...automatic.filter(entry=>!featured.includes(entry)).map(entry=>groupRow(entry,quota))];
            const focused=activityBar.contains(document.activeElement);
            const focusIndex=focused?[...activityBar.querySelectorAll('button')].indexOf(document.activeElement):-1;
            const visible=rows.slice(0,2);
            top.append(...visible);
            const announcement=visible.map(row=>row.firstChild.textContent).join(' | ');
            if (activityLive.textContent!==announcement) activityLive.textContent=announcement;
            activityBar.replaceChildren(top);
            activityBar.hidden=!rows.length;
            if (rows.length>2) {
                const history=el('details',null,'md-activity-history');
                history.open=activityHistoryOpen;
                history.addEventListener('toggle',()=>{if(history.isConnected) activityHistoryOpen=history.open;});
                const list=el('ul',null,'md-activity-list');
                list.append(...rows.slice(2));
                history.append(el('summary',`فعالیت‌های دیگر (${fa(rows.length-2)})`),list);
                activityBar.append(history);
            }
            if (focusIndex>=0) activityBar.querySelectorAll('button')[focusIndex]?.focus({preventScroll:true});
            if (activityStale && groups.length) activityBar.appendChild(el('small','آخرین وضعیت دریافت‌شده'));
            if (!hooks.readActivity) return;
            all.append(...groups.map(entry=>groupRow(entry,quota)));
            const nextQuota=quotaView(quota);
            systemActivity.replaceChildren();
            if (groups.length) systemActivity.appendChild(all);
            systemActivity.appendChild(nextQuota);
            if (activityStale) systemActivity.appendChild(el('small','تازه‌سازی وضعیت موقتاً در دسترس نیست.'));
        }
        function refreshActivity() {
            clearTimeout(activityTimer);
            if (!hooks.readActivity || document.hidden || activityRequest) return;
            const pending=[...actions.values()].filter(item=>item.watch&&activeAction(item));
            const start=watchOffset%Math.max(1,pending.length);
            const watching=[...pending.slice(start),...pending.slice(0,start)].slice(0,12);
            watchOffset=start+watching.length;
            activityRequest=hooks.readActivity(watching.map(item=>item.watch)).then(report=>{
                for (const item of watching) {
                    const result=report.watched?.[item.watch?.key];
                    if (!result || item.state==='cancelling' || !activeAction(item)) continue;
                    if (item.watch.kind==='market_prices'&&item.state!==result.state) refreshMarket(item.watch.key,result.state);
                    item.state=result.state;
                    item.progress=result;
                    item.detail=result.code==='no_match'?'قیمت قابل مقایسه پیدا نشد.'
                        : result.code==='unavailable'?'منبع قیمت در دسترس نبود.'
                        : result.code==='no_suggestion'?'مرجع قابل پیشنهاد پیدا نشد.'
                        : result.total?`${fa(result.completed)} از ${fa(result.total)}${result.failed?' · '+fa(result.failed)+' ناموفق':''}`:'';
                }
                let received=false;
                for (const event of [...(report.market_events||[])].reverse()) {
                    const id='market:'+event.id;
                    if (actions.has(id)||dismissedMarket.has(id)) continue;
                    const failed=event.kind==='failed';
                    const prices=[event.old_price,event.regular_price].filter(price=>price>0).map(price=>fa(price));
                    const failure={listing_unavailable:'آگهی در دسترس نیست یا مشخصاتش تغییر کرده است.',
                        regular_price_unverified:'قیمت عادی قابل تأیید نبود.',market_price_quota:'سهمیهٔ منبع پر شده است.'};
                    actions.set(id,{id,automaticMarket:true,state:failed?'failed':'completed',
                        label:failed?'بررسی قیمت عادی ناموفق بود':event.kind==='restored'?'منبع قیمت دوباره در دسترس است':'قیمت عادی به‌روز شد',
                        detail:failed?(failure[event.code]||'منبع قیمت پاسخ معتبر نداد.'):prices.join(' ← ')+' تومان',
                        origin:{view:'products',key:event.product_id?'product:'+event.product_id:'offer:'+event.offer_id,
                            offer:event.offer_id,product:event.title,section:'market_prices',market:true}});
                    for (const panel of main.querySelectorAll('.molavi-market-price-review[open]')) {
                        if (panel.dataset.offerId===event.offer_id
                            ||panel.closest('.md-product-card')?.dataset.productKey==='product:'+event.product_id)
                            panel.dispatchEvent(new Event('molavi-market-activity'));
                    }
                    received=true;
                }
                if (watching.length||received) saveActions();
                const inboxChanged=report.inbox_revision && report.inbox_revision!==activityReport?.inbox_revision;
                activityReport=report;
                activityReadAt=Date.now();
                activityStale=false;
                if (inboxChanged) refreshInbox(report.inbox_revision);
                refreshReferenceView(report.reference_revision);
            }).catch(()=>{activityStale=true;}).finally(()=>{
                activityRequest=null;
                paintActivity();
                if (!document.hidden) {
                    const active=state.view==='sources'||[...actions.values()].some(item=>item.watch&&activeAction(item)&&item.state!=='quota_wait')||activityReport?.groups?.some(entry=>['running','queued'].includes(entry.state));
                    activityTimer=setTimeout(refreshActivity,active?7000:30000);
                }
            });
        }
        function refreshMarket(key,state) {
            for(const panel of main.querySelectorAll('.molavi-market-price-review[open]')){
                if(panel.dataset.lookupKey!==key||panel.dataset.activityState===state)continue;
                panel.dataset.activityState=state;
                panel.dispatchEvent(new Event('molavi-market-activity'));
            }
        }
        function refreshInbox(revision) {
            const inbox=main.querySelector('.molavi-inbox');
            if(!inbox||!revision||inbox.dataset.activityRevision===revision)return;
            inbox.dataset.activityRevision=revision;
            inbox.dispatchEvent(new CustomEvent('molavi-inbox-refresh',{detail:revision}));
        }
        function refreshReferenceView(revision) {
            if (!revision||state.view!=='references') return;
            main.querySelector('.molavi-reference-panel')?.dispatchEvent(new CustomEvent('molavi-reference-refresh',{detail:revision}));
        }
        paintActivity();
        if (hooks.readActivity) {
            document.addEventListener('visibilitychange',refreshActivity);
            paintActivity();
            refreshActivity();
        }
        function saveURL() {
            const url=new URL(location.href);
            ['view','scope','q','channel','workbench_action','product_id','offer_id','case_id','conflict_id','hold_id','reference_id'].forEach(key=>url.searchParams.delete(key));
            if(state.view!=='products')url.searchParams.set('view',state.view);
            if(state.scope!=='attention')url.searchParams.set('scope',state.scope);
            if(state.search)url.searchParams.set('q',state.search);
            if(state.channel)url.searchParams.set('channel',state.channel);
            if(state.action)url.searchParams.set('workbench_action',state.action);
            if(state.focus)url.searchParams.set(state.focusType,state.focus);
            // Keep the decisions anchor while on the autopilot tab, so the
            // direct link ?view=autopilot#molavi-autopilot-decisions survives
            // navigation; every other hash is still cleared as before.
            if(state.view!=='autopilot'||url.hash!=='#molavi-autopilot-decisions')url.hash='';
            history.replaceState({},'',url);
        }
        function navigate(view, product=null) {
            if(view!==state.view) clearSelection(true);
            if(view==='sources'&&product){state.sourceContext=product;state.pageCache.delete('sources');}
            state.view=view;saveURL();paint();
            // An anchor link must win over the usual scroll-to-top-of-tab:
            // without this, navigate() would scroll back past the list.
            // (When the data has not loaded yet there is no element; the
            // paint() hook below scrolls once the tab mounts.)
            const decisions=view==='autopilot'&&location.hash==='#molavi-autopilot-decisions'?document.getElementById('molavi-autopilot-decisions'):null;
            if(decisions)decisions.scrollIntoView({block:'start',behavior:'smooth'});
            else main.scrollIntoView({block:'start',behavior:'smooth'});
            if(view==='products') for(const item of actions.values()) {
                if(item.watch?.kind==='market_prices'&&!activeAction(item)) refreshMarket(item.watch.key,item.state);
            }
            if(view==='references') refreshReferenceView(activityReport?.reference_revision);
            if((view==='sources'||view==='references')&&Date.now()-activityReadAt>5000) refreshActivity();
        }
        function facts(rows) {
            const list=el('dl',null,'md-facts');
            rows.filter(row=>row[1]!==undefined&&row[1]!==null&&row[1]!=='').forEach(([label,value])=>{
                const fact=el('div');fact.append(el('dt',label),el('dd',value));list.appendChild(fact);
            });return list;
        }
        function empty(text, detail) {
            const item=el('section',null,'md-empty');item.append(el('h2',text));
            if(detail)item.append(el('p',detail));return item;
        }
        function healthView() {
            health.replaceChildren();
            const errors=Object.values(model.errors).filter(Boolean);
            if(!errors.length)return;
            const box=el('details',null,'md-warning');
            box.append(el('summary','بخشی از اطلاعات تازه دریافت نشد؛ وضعیت کامل نیست.'));
            errors.forEach(error=>box.append(el('p',error)));
            box.append(button('دریافت دوباره',()=>hooks.refresh()));health.append(box);
        }
        const policyStorageKey='molavi-seen-extraction-policy';
        function extractionPolicyNotice() {
            if (model.errors.ai_confirmations) return null;
            const version=model.data.ai_confirmations?.extraction_policy_version;
            if (!version) return null;
            try {
                const seen=localStorage.getItem(policyStorageKey);
                if (!seen) localStorage.setItem(policyStorageKey,version);
                if (!seen || seen===version) return null;
            } catch (_error) { return null; }
            const note=el('div',null,'md-source-context');
            note.setAttribute('role','status');
            note.append(el('p','قواعد استخراج به‌روز شده است. پیام‌های واجد شرایط را دوباره پردازش کنید.'),
                button('بررسی پیام‌ها',() => {
                    try { localStorage.setItem(policyStorageKey,version); } catch (_error) {}
                    state.sourceContext=null;
                    state.pageCache.delete('sources');
                    navigate('sources');
                },'md-link'));
            return note;
        }
        function next(product) { return product.listing ? (product.listing.message_count?'product_messages':priceBlocked(product)?'source_integrity':'next') : model.primaryAction(product); }
        function ready(product) { return product.listing ? (product.listing.message_count?'product_messages':'') : model.readyAction(product); }
        function published(product) { return Boolean(product.status?.published); }
        function priceBlocked(product) { return Boolean(product.status?.sale_offer_blocker); }
        function reopenable(product) {
            return !model.errors.product_status&&!model.errors.ai_confirmations&&
                published(product)&&product.product_id&&product.status.latest_offer_id&&
                !product.status.source_integrity_issues?.length&&
                !product.ai.some(item=>item.reopened);
        }
        function dismissal(product) {
            const key=next(product);
            return key&&!['next','deferred','product_messages','market_prices'].includes(key) ? model.dismissalTask(product,key) : null;
        }
        function clearSelection(exit=false) {
            state.selected.clear();
            if(exit) state.selecting=false;
        }
        function waiting(product) {
            const action=next(product);
            return action!=='next'&&action!=='deferred'&&!ready(product);
        }
        function stateLabel(product) {
            if(product.holds.length)return ['انتشار متوقف','blocked'];
            if(next(product)==='stock')return ['منتظر موجودی','unknown'];
            if(product.status?.status==='not_product'&&next(product)==='next')return ['رد شده','neutral'];
            if(priceBlocked(product)) {
                const blocker=product.status?.sale_offer_blocker;
                if(blocker==='no_spread')return ['قیمت عادی با قیمت تأمین‌کننده هم‌خوانی ندارد','unknown'];
                if(blocker==='thin_spread')return ['اختلاف قیمت برای تخفیف کافی نیست','unknown'];
                if(blocker==='supplier_price')return ['در انتظار قیمت تأمین‌کننده','unknown'];
                if(blocker==='regular_price')return ['در انتظار قیمت عادی','unknown'];
                return ['در انتظار قیمت','unknown'];
            }
            if(ready(product))return ['آمادهٔ بررسی','attention'];
            if(next(product)==='deferred')return ['کنارگذاشته‌شده','neutral'];
            if(waiting(product))return ['در انتظار پیگیری','unknown'];
            if(published(product))return ['منتشرشده','good'];
            const woo=model.wooState(product);
            if(woo==='purchasable')return ['قابل خرید','good'];
            if(!product.status)return ['در انتظار تکمیل','unknown'];
            return ['اقدام بازی ثبت نشده','neutral'];
        }
        function matches(product) {
            const text=normalize([product.name,product.channel,product.listing?.woo_product_id,...identities(product)].join(' '));
            if(state.search&&!text.includes(normalize(state.search)))return false;
            if(state.channel&&product.channel!==state.channel)return false;
            if(state.focus){
                const groups={
                    product_id:[product.product_id],
                    offer_id:[product.status?.latest_offer_id,...product.ai.map(r=>r.offer_id),...product.references.map(r=>r.offer_id),...product.supplierPrices.map(r=>r.offer_id)],
                    case_id:[...product.ai,...product.references].map(r=>r.case_id),
                    conflict_id:product.conflicts.map(r=>r.conflict_id),hold_id:product.holds.map(r=>r.hold_id),
                    reference_id:[product.status?.reference_match_id,...product.ai.flatMap(r=>(r.reference_candidates||[]).map(c=>c.reference_id)),...product.references.flatMap(r=>(r.candidates||[]).map(c=>c.reference_id))]
                };
                if(!(groups[state.focusType]||[]).filter(Boolean).map(String).includes(state.focus))return false;
            }
            if(state.scope==='published'&&(!published(product)||next(product)==='deferred'))return false;
            if(state.scope==='pricing'&&(!priceBlocked(product)||next(product)==='deferred'))return false;
            if(state.scope==='other'&&(ready(product)||waiting(product)||priceBlocked(product)||published(product)))return false;
            if(state.action&&!state.focus&&next(product)!==state.action)return false;
            if(state.action||state.focus)return true;
            if(state.scope==='ready')return Boolean(ready(product));
            if(state.scope==='waiting')return waiting(product);
            return true;
        }
        async function showOfferHistory(list,offerId) {
            if (!hooks.readOfferHistory) return;
            const panel=el('article',null,'md-product-card md-offer-history');
            panel.append(el('p','در حال دریافت پیشنهاد پیوندشده…'));
            list.replaceChildren(panel);
            try {
                const record=await hooks.readOfferHistory(offerId);
                if(!record)return;
                const previous=record.reviewed_offer;
                if(!previous) throw new Error('missing_offer');
                const decision=record.recent_decisions?.find(row=>row.offer_id===offerId)?.decision;
                const title=el('h2',previous.title||'پیشنهاد پیوندشده');
                const facts=hooks.renderOfferFacts(previous);
                if (decision) {
                    const confirmed=decision.choice==='confirm';
                    const heading=el('p',confirmed?'تأیید مشخصات ثبت شده است.':'تصمیم رد پیشنهاد ثبت شده است.');
                    const raw=el('details');
                    raw.append(el('summary','مشخصات استخراج اولیه'),facts);
                    panel.replaceChildren(title,heading);
                    if(confirmed) panel.append(hooks.renderOfferApproval(decision.fields||{}));
                    panel.append(raw);
                } else panel.replaceChildren(title,el('p','پیشنهاد ذخیره‌شده','description'),facts);
                if (record.current_product_id) panel.append(button('ادامه در کارت فعلی کالا',()=>
                    reviewInboxItem('product_id',record.current_product_id),'md-button md-primary'));
                const candidates=(record.titles||[]).filter(row=>
                    row.offer_id && row.offer_id!==offerId && model.products.some(product=>identities(product).includes(row.offer_id)));
                if(candidates.length) {
                    panel.append(el('h3','پیشنهادهای آخرین استخراج'));
                    for(const offer of candidates) {
                        panel.append(button('بررسی '+offer.title,()=>reviewInboxItem('offer_id',offer.offer_id),'md-button'));
                    }
                }
            } catch (_) {
                panel.replaceChildren(el('p','پیشنهاد پیوندشده در دسترس نیست.'),
                    button('بررسی دوباره',()=>showOfferHistory(list,offerId)));
            }
        }
        function productsView() {
            const view=el('section',null,'md-products');
            const title=el('div',null,'md-page-heading');
            const copy=el('div');copy.append(el('h1','محصولات'));
            title.append(copy);
            const stamp=model.data.product_status?.generated_at;
            if(stamp)title.append(el('small',`آخرین دریافت: ${model.formatDate(stamp)}`,'md-last-read'));
            view.append(title);
            const policyNotice=extractionPolicyNotice();
            if (policyNotice) view.append(policyNotice);
            const pendingBatches=model.systemHolds || [];
            if (pendingBatches.length&&!model.errors.publication_holds) {
                const label=`توقف‌های انتشار · ${fa(pendingBatches.length)}`;
                view.append(button(label,()=>reviewHold(),'md-button md-link'));
            }
            const linkedWooIds=new Set(model.products.map(p=>p.status?.woo_product_id).filter(Boolean).map(String));
            const unlinked=(model.data.product_status?.published_unlinked||[]).map(listing=>({
                key:`woo:${listing.woo_product_id}`,name:listing.name,channel:listing.supplier,listing,
                status:{published:true,sale_offer_blocker:listing.sale_offer_blocker},ai:[],references:[],conflicts:[],holds:[],supplierPrices:[]
            })).filter(item=>!linkedWooIds.has(String(item.listing.woo_product_id)));
            const scopeBar=el('div',null,'md-scopes');scopeBar.setAttribute('aria-label','فهرست محصولات');
            const allProducts=[...model.products,...unlinked];
            [['ready','آمادهٔ بررسی',allProducts.filter(ready).length],
                ['waiting','در انتظار پیگیری',allProducts.filter(waiting).length],
                ['pricing','در انتظار قیمت',model.products.filter(priceBlocked).length+unlinked.filter(priceBlocked).length],
                ['published','منتشرشده',model.products.filter(published).length+unlinked.length],
                ['other','سایر',allProducts.filter(p=>!ready(p)&&!waiting(p)&&!priceBlocked(p)&&!published(p)).length],
                ['all','همهٔ محصولات',allProducts.length]].forEach(([key,label,count])=>{
                const item=button(`${label}  ${fa(count)}`,()=>{
                    clearSelection();state.scope=key;state.action='';state.focus='';state.limit=24;saveURL();paint();
                },'md-scope');item.setAttribute('aria-pressed',String(state.scope===key&&(!state.action||key==='published')));scopeBar.append(item);
            });view.append(scopeBar);
            const filters=el('div',null,'md-filters');
            const search=el('input');search.type='search';search.value=state.search;search.placeholder='نام کالا، شناسه یا تأمین‌کننده…';
            search.setAttribute('aria-label','جست‌وجوی محصولات');search.autocomplete='off';
            const action=el('select');action.setAttribute('aria-label','نوع رسیدگی');
            [['','همهٔ کارها'],...Object.entries(actionLabels)].forEach(([key,label])=>{
                const option=el('option',key?`${label} · ${fa(allProducts.filter(p=>next(p)===key).length)}`:label);option.value=key;action.append(option);
            });action.value=state.action;
            const channel=el('select');channel.setAttribute('aria-label','تأمین‌کننده');
            const all=el('option','همهٔ تأمین‌کنندگان');all.value='';channel.append(all);
            const candidates=allProducts;
            [...new Set(candidates.map(p=>p.channel).filter(Boolean))].sort().forEach(value=>{const o=el('option',value);o.value=value;channel.append(o);});
            channel.value=state.channel;
            filters.append(search,action,channel);view.append(filters);
            // Supplier link checks (round eight) and market agreement
            // (round nine): the owner's confirm list, changed prices, the
            // uncheckable report, and the market-agreement report sit above
            // the product list while the supplier-price queue is open.
            if (state.action==='supplier_prices'&&model.renderLinkChecks) view.append(model.renderLinkChecks());
            if (state.action==='supplier_prices'&&model.renderMarketAgreement) view.append(model.renderMarketAgreement());
            const focus=el('div',null,'md-active-filter');focus.hidden=!state.focus;
            if(state.focus)focus.append(el('span',`شناسه: ${state.focus}`),button('نمایش همه',()=>{clearSelection();state.focus='';saveURL();paint();},'md-link'));
            view.append(focus);
            const count=el('p',null,'md-result-count');count.setAttribute('role','status');
            const controls=el('div',null,'md-result-controls');
            const toggle=button('انتخاب کالاها',()=>{state.selecting=!state.selecting;clearSelection();paintList();});
            controls.append(count,toggle);
            const selection=el('div',null,'md-bulk-actions');
            const selectedCount=el('span');selectedCount.setAttribute('role','status');
            let eligible=[];
            const selectAll=button('انتخاب همهٔ نتایج',()=>{
                if(state.selected.size===eligible.length) clearSelection();
                else state.selected=new Set(eligible.map(product=>product.key));
                list.querySelectorAll('.md-select-product').forEach(input=>{input.checked=state.selected.has(input.value);});
                paintSelection();
            },'md-link');
            const selectedProducts=()=>eligible.filter(product=>state.selected.has(product.key));
            const reopen=button('بازگشایی بررسی',()=>reopenReview(selectedProducts().filter(reopenable)));
            const dismiss=button('کنارگذاشتن کار',()=>dismissTask(selectedProducts().map(dismissal).filter(task=>task?.task_key)));
            selection.append(selectedCount,selectAll,reopen,dismiss);
            function paintSelection() {
                const products=selectedProducts();
                const reopenCount=products.filter(reopenable).length;
                const dismissCount=products.filter(product=>dismissal(product)?.task_key).length;
                toggle.hidden=!eligible.length&&!state.selecting;
                toggle.textContent=state.selecting?'پایان انتخاب':'انتخاب کالاها';
                toggle.setAttribute('aria-pressed',String(state.selecting));
                selection.hidden=!state.selecting||!eligible.length;
                selectedCount.textContent=`${fa(products.length)} انتخاب‌شده`;
                selectAll.textContent=products.length===eligible.length?'لغو انتخاب همه':`انتخاب همهٔ نتایج قابل اقدام (${fa(eligible.length)})`;
                reopen.hidden=!reopenCount;
                reopen.textContent=`بازگشایی بررسی (${fa(reopenCount)})`;
                dismiss.hidden=!dismissCount;
                dismiss.textContent=`کنارگذاشتن کار (${fa(dismissCount)})`;
            }
            const list=el('div',null,'md-product-list');
            const more=button('نمایش بیشتر',()=>{state.limit+=24;paintList();},'md-button md-more');
            const paintList=()=>{
                const rows=candidates.filter(matches).sort((a,b)=>{
                    const holdA=model.autopilotHold?model.autopilotHold(a):null;
                    const holdB=model.autopilotHold?model.autopilotHold(b):null;
                    if (holdA&&holdB) return holdA.priority-holdB.priority;
                    if (holdA) return -1;
                    if (holdB) return 1;
                    return Number(Boolean(a.listing))-Number(Boolean(b.listing))
                        ||model.actionRank(next(a))-model.actionRank(next(b))||a.name.localeCompare(b.name,'fa');
                });
                eligible=rows.filter(product=>reopenable(product)||dismissal(product)?.task_key);
                const eligibleKeys=new Set(eligible.map(product=>product.key));
                state.selected=new Set([...state.selected].filter(key=>eligibleKeys.has(key)));
                count.textContent=`${fa(rows.length)} کالا`;
                list.replaceChildren();
                rows.slice(0,state.limit).forEach(product=>list.append(productCard(
                    product,state.selecting&&eligibleKeys.has(product.key)?paintSelection:null)));
                paintSelection();
                if(!rows.length&&state.focusType==='offer_id'&&state.focus&&hooks.readOfferHistory) {
                    showOfferHistory(list,state.focus);
                } else if(!rows.length) {
                    const readyScope = state.scope==='ready'&&!state.action&&!state.search&&!state.channel;
                    const emptyView=empty(readyScope
                        ? (Object.values(model.errors).some(Boolean) ? 'فهرست کارهای آماده کامل نیست' : 'کار آمادهٔ بررسی در این فهرست نیست')
                        : state.focusType==='offer_id'&&state.focus ? 'کارت قابل بررسی برای این پیشنهاد پیدا نشد.' : 'موردی در این فهرست نیست');
                    if(state.action&&!state.focus) {
                        emptyView.append(button('نمایش همهٔ کارها',()=>{clearSelection();state.action='';state.limit=24;saveURL();paint();},'md-button'));
                    }
                    list.append(emptyView);
                }
                more.hidden=rows.length<=state.limit;
            };
            let searchTimer;
            search.addEventListener('input',()=>{
                clearSelection();paintSelection();list.querySelectorAll('.md-select-product').forEach(input=>{input.checked=false;input.disabled=true;});
                selectAll.disabled=true;
                state.search=search.value;state.limit=24;clearTimeout(searchTimer);
                searchTimer=setTimeout(()=>{selectAll.disabled=false;saveURL();paintList();},160);
            });
            action.addEventListener('change',()=>{clearSelection();state.action=action.value;state.limit=24;saveURL();paintList();});
            channel.addEventListener('change',()=>{clearSelection();state.channel=channel.value;state.limit=24;saveURL();paintList();});
            view.append(controls,selection,list,more);paintList();return view;
        }
        function productCard(product,selectionChanged=null) {
            if(product.listing) {
                const card=el('article',null,'md-product-card');card.dataset.productKey=product.key;
                const header=el('header',null,'md-card-header md-unlinked-header');
                const identity=el('div',null,'md-product-identity');identity.append(el('h2',product.name));
                if(product.channel) identity.append(el('p',product.channel));
                header.append(identity,el('span',priceBlocked(product)?'قیمت تأییدنشده':'بدون پیوند بررسی','md-badge md-neutral'));
                if(product.listing.edit_url) {
                    const link=el('a','ویرایش در ووکامرس','md-link');
                    link.href=product.listing.edit_url;header.append(link);
                }
                card.append(header);
                if (priceBlocked(product)&&!product.listing.message_count) {
                    const sourceStep=el('div',null,'md-next-action');
                    sourceStep.append(el('p','پیام تازهٔ این کالا را به ربات بفرستید تا بتوانید آن را برای بررسی انتخاب کنید.'),
                        button('پیگیری پیام ربات',()=>navigate('sources',product),'md-button'));
                    card.append(sourceStep);
                }
                if (priceBlocked(product)||product.listing.message_count!==undefined) {
                    const messages=el('details',null,'md-card-action');
                    const summary=el('summary',product.listing.message_count?`انتخاب پیام کالا · ${fa(product.listing.message_count)}`:'پیام‌های کالا');
                    if (ready(product)==='product_messages') summary.classList.add('md-button','md-primary');
                    messages.append(summary);
                    messages.addEventListener('toggle',()=>{
                        if (messages.open&&messages.childElementCount===1) messages.append(hooks.renderMessages(product.listing.woo_product_id));
                    });
                    card.append(messages);
                }
                return card;
            }
            const record=product.status||{};
            const source=sourceFor(product);
            const preferred=next(product);
            const sourceImage=preferred==='ai'||!record.latest_offer_id ? source.image_file_id
                : record.image_file_id||(source.offer_id===record.latest_offer_id?source.image_file_id:null);
            const priceOffers=model.marketPriceOffers(product,preferred);
            const [label,tone]=stateLabel(product);
            const card=el('article',null,'md-product-card');card.dataset.productKey=product.key;
            const header=el('header',null,'md-card-header');
            const icon=el('span',(product.name||'ک').slice(0,1),'md-product-mark');icon.setAttribute('aria-hidden','true');
            const identity=el('div',null,'md-product-identity');identity.append(el('h2',product.name),el('p',product.channel||'تأمین‌کننده ثبت نشده'));
            if(selectionChanged) {
                const checkbox=el('input',null,'md-select-product');checkbox.type='checkbox';checkbox.value=product.key;
                checkbox.setAttribute('aria-label',`انتخاب ${product.name}`);
                checkbox.checked=state.selected.has(product.key);
                checkbox.addEventListener('change',()=>{
                    if(checkbox.checked) state.selected.add(product.key);
                    else state.selected.delete(product.key);
                    selectionChanged();
                });
                header.append(checkbox);
            }
            header.append(model.renderSourceImage(sourceImage,icon),identity,el('span',label,`md-badge md-${tone}`));card.append(header);
            if(state.scope==='published'&&!state.selecting&&reopenable(product))
                card.append(button('بازگشایی بررسی',()=>reopenReview([product]),'md-link md-card-action'));
            // A failed confirm-publish leaves a note at the top of the product's
            // own card, on every tab: the action panel alone cannot carry it,
            // because a product whose current step is the regular-price tab
            // never renders through renderAction. The server only lists
            // products outside the published-review catalog, so a product that
            // went live despite its job failing gets no warning.
            const publishFailure = model.data.operations?.operations?.failed_publish_by_product?.[product.product_id];
            if (publishFailure) {
                const reason = model.publishFailureReason ? model.publishFailureReason(publishFailure.error) : String(publishFailure.error || '');
                card.append(el('div', `آخرین انتشار این کالا کامل نشد: ${reason}`, 'notice notice-warning'));
            }
            // An autopilot hold surfaces first in the list and on the card, so
            // the owner sees the exact reason before anything else.
            const autopilotHold = model.autopilotHold ? model.autopilotHold(product) : null;
            if (autopilotHold) {
                card.append(el('div', `نیازمند تصمیم: ${autopilotHold.reason}`, 'notice notice-warning'));
            }
            const nextLine=el('div',null,'md-next-action');
            if(preferred==='deferred'){
                const keys=model.restoreKeys(product);
                nextLine.append(el('p','بررسی این کالا کنار گذاشته شده است. برای ادامه آن را بازگردانید.'));
                if (keys.length) nextLine.append(button('بازگرداندن همین بررسی',async event=>{
                    const control=event.currentTarget;control.disabled=true;state.active=product.key;
                    try {await hooks.restore(keys);} catch (error) {loading(false,error.message,'error');control.disabled=false;}
                },'md-button md-primary'));
                else nextLine.append(button('کارهای کنارگذاشته‌شده',()=>navigate('system'),'md-link'));
            }else if(preferred!=='next'){
                const reason=el('div');reason.append(el('p',model.nextStep(product,preferred)));
                const actionable=ready(product)===preferred;
                const label=!actionable&&preferred==='supplier_prices'?'مدرک قیمت':actionLabels[preferred];
                const proceed=button(label,()=>activate(preferred),actionable?'md-button md-primary':'md-button');
                nextLine.append(reason,proceed);
            }
            if (nextLine.childNodes.length) card.append(nextLine);
            const detailsGroup=el('div',null,'md-card-sections');
            const tabBar=el('div',null,'md-card-tabs');tabBar.setAttribute('role','tablist');
            tabBar.setAttribute('aria-label',`بخش‌های ${product.name}`);
            const panelHost=el('div',null,'md-card-panel');panelHost.setAttribute('role','tabpanel');
            panelHost.id=`md-panel-${++sequence}`;panelHost.hidden=true;
            const detailsMap=new Map();
            const currentSection=preferred==='status'?['status','انتشار']:sections.find(([key])=>key===preferred);
            const sourceSection=sections[0];
            const productSections=currentSection
                ? (currentSection===sourceSection?[sourceSection]:[currentSection,sourceSection])
                : preferred==='deferred'?[sourceSection]:[sourceSection,sections.at(-1)];
            if(record.woo_product_id&&preferred!=='product_messages') productSections.push(['product_messages','پیام‌های کالا']);
            if (preferred==='next'&&record.latest_offer_id&&!record.source_integrity_issues?.length&&record.status!=='not_product')
                productSections.push(['references','مرجع']);
            if(priceOffers.length&&preferred!=='market_prices') productSections.splice(currentSection?1:0,0,['market_prices','قیمت عادی']);
            function activate(key, scroll=true) {
                state.active=product.key;state.section=key;
                main.querySelectorAll('.md-card-panel').forEach(item=>{if(item!==panelHost)item.hidden=true;});
                main.querySelectorAll('.md-card-tab').forEach(item=>{
                    item.setAttribute('aria-selected','false');item.tabIndex=item.parentElement.firstElementChild===item?0:-1;
                });
                const tab=detailsMap.get(key);
                if(!tab)return;
                tab.setAttribute('aria-selected','true');tab.tabIndex=0;
                panelHost.hidden=false;panelHost.dataset.loaded='';panelHost.replaceChildren();
                panelHost.setAttribute('aria-labelledby',tab.id);
                main.querySelectorAll('.md-selected').forEach(item=>item.classList.remove('md-selected'));
                fill(key,panelHost);card.classList.add('md-selected');
                if(scroll)panelHost.scrollIntoView({behavior:'smooth',block:'nearest'});
            }
            function fill(key,details) {
                if(details.dataset.loaded)return;
                details.dataset.loaded='true';
                const cacheKey=`${product.key}|${key}`;
                let panel=state.panels.get(cacheKey);
                if(!panel){
                    panel=el('div',null,'md-section-body');
                    const hasAction=key===preferred;
                    if(key==='source_integrity'){
                        if(!hasAction){
                            const offer=preferred==='ai'?(source.offer_id||record.latest_offer_id):(record.latest_offer_id||source.offer_id);
                            panel.append(model.renderSupplierMessage(offer,{
                                url:source.source_post_url||source.source_url||record.source_post_url,
                                text:source.original_message,timestamp:source.source_timestamp||source.message_timestamp
                            }));
                            if (offer&&!record.source_integrity_issues?.length) panel.append(model.renderOfferReextraction(String(offer)));
                            else panel.append(button('یافتن پیام تأمین‌کننده',()=>navigate('sources',product)));
                        }
                    }
                    else if(key==='storefront'){
                        panel.append(el('p',model.wooLabel(model.wooState(product))));
                        panel.append(button('بررسی وضعیت فروشگاه',event=>model.reconcile(event.currentTarget)));
                    }
                    else if (key==='stock') {
                        panel.append(el('p','پس از دریافت پیام تازه، موجودی و قیمت را در همان پیشنهاد بررسی کنید.'),
                            button('پیگیری پیام ربات',()=>navigate('sources',product)));
                    }
                    else if (key==='references'&&!hasAction) {
                        const reference=model.renderReferenceTools(product);
                        if (reference) panel.append(reference);
                    }
                    else if(key==='market_prices') {
                        if (['ai','references'].includes(preferred))
                            panel.append(button('تطبیق با فهرست مرجع',()=>activate(preferred),'md-link'));
                        const host=el('div');
                        const showPrice=offer=>host.replaceChildren(model.renderMarketPrice(offer.offer_id,offer.name));
                        const selected=priceOffers.find(offer=>offer[state.focusType]===state.focus)||priceOffers[0];
                        if (priceOffers.length>1) {
                            const select=el('select');select.setAttribute('aria-label','پیشنهاد مورد بررسی برای قیمت عادی');
                            priceOffers.forEach(({offer_id,name})=>{
                                const option=el('option',name);option.value=offer_id;select.append(option);
                            });
                            select.value=selected.offer_id;
                            select.addEventListener('change',()=>showPrice(priceOffers.find(row=>row.offer_id===select.value)));
                            panel.append(select);
                        }
                        if (selected) {showPrice(selected);panel.append(host);}
                        if (record.latest_offer_id&&product.product_id)
                            panel.append(recoveryAction(product,'تأیید دستی قیمت عادی'));
                        else if (!selected) panel.append(button('جست‌وجوی پیام منبع',()=>navigate('sources',product)));
                        if (selected) panel.append(model.renderProductSearch(product.name));
                    }
                    if(key==='product_messages') panel.append(hooks.renderMessages(record.woo_product_id));
                    if(hasAction&&!['storefront','product_messages','market_prices'].includes(key)){
                        const action=model.renderAction(product,key);
                        if (action) panel.append(action);
                        if (key==='status') {
                            if (record.publication_needed) panel.append(button('درخواست انتشار',async event=>{
                                const control=event.currentTarget;control.disabled=true;
                                try {await model.requestPublication(product);} catch (error) {loading(false,error.message,'error');control.disabled=false;}
                            },'md-button md-primary'));
                            else {
                                if (priceOffers.length) panel.append(button('بررسی قیمت عادی',()=>activate('market_prices')));
                                if (record.latest_offer_id&&product.product_id) panel.append(recoveryAction(product,'بازبینی مشخصات برای انتشار'));
                                else panel.append(button('یافتن پیام تأمین‌کننده',()=>navigate('sources',product),'md-button md-primary'));
                            }
                        }
                        if (key==='supplier_prices'&&record.sale_offer_blocker==='supplier_price'&&record.latest_offer_id&&product.product_id)
                            panel.append(recoveryAction(product,'بازبینی قیمت از پیام تأمین‌کننده'));
                        if (key==='supplier_prices'&&!product.supplierPrices.length&&!record.latest_offer_id)
                            panel.append(button('یافتن پیام تأمین‌کننده',()=>navigate('sources',product),'md-button md-primary'));
                        panel.append(button('کنارگذاشتن این کار',()=>dismissTask(model.dismissalTask(product,key)),'md-link md-muted'));
                    }
                    state.panels.set(cacheKey,panel);
                }
                details.append(panel);
            }
            productSections.forEach(([key,title],index)=>{
                const tab=button(title,()=>activate(key),'md-card-tab');
                tab.title={
                    source_integrity:'متن، تاریخ و بازاستخراج پیام تأمین‌کننده',ai:'مشخصات خوانده‌شده و اصلاح آن‌ها',
                    references:'تطبیق کالا و قیمت واحد یا بسته',supplier_prices:'اعتبار قیمت تأمین‌کننده',
                    conflicts:'اختلاف داده‌های ذخیره‌شده',holds:'توقف‌های انتشار',status:'دلیل وضعیت انتشار',
                    market_prices:'قیمت پیشنهادی، منبع و زمان بررسی',
                    product_messages:'انتخاب پیام ورودی برای همین کالا',
                    stock:'پیام جدید دربارهٔ موجودی تأمین‌کننده',storefront:'آنچه در ووکامرس دیده یا قابل‌خرید است'
                }[key];
                tab.id=`${panelHost.id}-${key}`;tab.setAttribute('role','tab');
                tab.setAttribute('aria-controls',panelHost.id);tab.setAttribute('aria-selected','false');
                tab.tabIndex=index===0?0:-1;
                if(key===preferred){tab.dataset.attention='true';tab.setAttribute('aria-label',`${title}؛ نیازمند اقدام`);}
                tab.addEventListener('keydown',event=>{
                    if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;
                    event.preventDefault();const buttons=[...detailsMap.values()];
                    const position=buttons.indexOf(tab);
                    const target=event.key==='Home'?0:event.key==='End'?buttons.length-1:
                        (position+(event.key==='ArrowLeft'?1:-1)+buttons.length)%buttons.length;
                    buttons[target].focus();buttons[target].click();
                });detailsMap.set(key,tab);tabBar.append(tab);
            });
            detailsGroup.append(tabBar,panelHost);card.append(detailsGroup);
            if(state.active===product.key&&detailsMap.has(state.section))activate(state.section,false);
            const technical=el('details',null,'md-identifiers');technical.append(el('summary','شناسه‌ها و سابقهٔ داده'));
            technical.append(facts([['شناسهٔ کالا',product.product_id||'هنوز ساخته نشده'],['شناسهٔ پیشنهاد',record.latest_offer_id||source.offer_id],
                ['وضعیت ثبت‌شده',model.readable(record.status)],['آخرین خواندن',model.formatDate(model.data.product_status?.generated_at)]]));
            card.append(technical);
            return card;
        }
        function pricingView() {
            const view=el('section');
            view.append(el('h1','تنظیمات'));
            const panel=el('div');
            view.append(panel);
            hooks.renderPricing(panel);
            return view;
        }
        function referencesView() {
            const view=el('section');view.append(el('h1','فهرست مرجع'));
            const panel=el('div',null,'md-reference-catalog');view.append(panel);(hooks.renderReferences||model.renderReferences)(panel);return view;
        }
        function sourcesView() {
            const view=el('section');view.append(el('h1','پیام‌های منبع'));
            const context=state.sourceContext;
            if(context){
                const hint=el('div',null,'md-source-context');
                hint.append(el('strong',`منبع ${context.name}`),el('p',`تأمین‌کننده: ${context.channel||'ثبت نشده'}`));
                const link=model?.sourceLink(context.status?.source_post_url||sourceFor(context).source_post_url);
                if(link)hint.append(link);
                if (context.product_id) hint.append(button('بازگشت به کارت کالا',()=>reviewInboxItem('product_id',context.product_id),'md-link'));
                hint.append(button('نمایش همهٔ پیام‌ها',()=>{state.sourceContext=null;state.pageCache.delete('sources');paint();},'md-link'));view.append(hint);
            }
            const panel=el('div');view.append(panel);(hooks.renderSources||model.renderSources)(panel,context);return view;
        }
        function systemView() {
            const view=el('section');view.append(el('h1','وضعیت سیستم'));
            if (hooks.readActivity) view.appendChild(systemActivity);
            const operations=model.data.operations?.operations;
            const counts=operations?.by_state && {...operations.by_state};
            if (counts) {
                model.archivedOperations.forEach(item=>{counts[item.state]=Math.max(0,Number(counts[item.state] || 0)-1);});
                const running=Number(counts.queued || 0)+Number(counts.running || 0);
                const waiting=Number(counts.waiting_publication || 0)+Number(counts.waiting_reference || 0);
                const issues=Number(counts.failed || 0)+Number(counts.needs_review || 0);
                const overview=el('div',null,'md-source-context');
                overview.append(el('p',`${fa(running)} پردازش خودکار · ${fa(waiting)} منتظر مرجع یا انتشار · ${fa(issues)} خطا یا نیازمند بررسی`),
                    el('p','پردازش‌های خودکار اقدامی از شما نمی‌خواهند. برای یک تصمیم محصول به محصولات بروید؛ برای توقف یک دسته، بخش زیر را باز کنید.'));
                if(counts.waiting_publication&&model.systemHolds?.length){
                    overview.append(button(`بررسی توقف انتشار · ${fa(counts.waiting_publication)} در انتظار`,()=>reviewHold(),'md-button'));
                }
                view.append(overview);
            }
            if(model.systemHolds?.length){
                const group=el('details');group.className='md-publication-holds';group.tabIndex=-1;group.append(el('summary',`توقف‌های مشترک پردازش · ${fa(model.systemHolds.length)}`));
                model.systemHolds.forEach(hold=>group.append(model.renderBatch(hold)));view.append(group);
            }
            view.append(model.renderSystem());
            const hidden=el('details');hidden.append(el('summary',`کارهای کنارگذاشته‌شده · ${fa(model.dismissals.length)}`));
            model.dismissals.forEach(item=>{
                const row=el('div',null,'md-hidden-task');row.append(el('span',item.product_name||item.label||item.task_key),
                    button('بازگرداندن',async event=>{const control=event.currentTarget;control.disabled=true;try{await hooks.restore([item.task_key]);}catch(error){loading(false,error.message,'error');control.disabled=false;}}));hidden.append(row);
            });if (model.dismissals.length) view.append(hidden);
            const utilities=el('details');utilities.append(el('summary','موجودی، سفارش‌ها و تاریخچه'));
            [['molavi-inventory-management','بچ‌های موجودی'],['molavi-clearance-pricing','قیمت حراج موجودی'],['molavi-paid-orders','سفارش‌های پرداخت‌شده'],['molavi-product-status-snapshots','تاریخچهٔ وضعیت']].forEach(([key,label])=>{
                if(hooks.pageUrls[key]){const link=el('a',label,'md-utility-link');link.href=hooks.pageUrls[key];utilities.append(link);}
            });view.append(utilities);return view;
        }
        function paint() {
            navButtons.forEach((button,key)=>button.setAttribute('aria-current',key===state.view?'page':'false'));
            // Keep the active tab visible in the horizontally scrollable nav row: the
            // autopilot tab is last, so on a narrow screen it can sit off the edge.
            // block:'nearest' so this never scrolls the page vertically.
            navButtons.get(state.view)?.scrollIntoView({block:'nearest',inline:'nearest'});
            if(!model&&['products','system','autopilot'].includes(state.view)) {
                main.replaceChildren(state.view==='system'?systemActivity:el('p','در حال دریافت محصولات…'));
                return;
            }
            if(state.view==='products')main.replaceChildren(productsView());
            else {
                if(!state.pageCache.has(state.view))state.pageCache.set(state.view,
                    state.view==='pricing'?pricingView():state.view==='references'?referencesView()
                        :state.view==='sources'?sourcesView():state.view==='autopilot'?model.renderAutopilot():systemView());
                const page=state.pageCache.get(state.view);
                const mounted=main.firstElementChild!==page;
                if(mounted) main.replaceChildren(page);
                // Direct link to the decisions list: the tab mounts only once
                // the dashboard data arrives, so the anchor scroll has to
                // happen here (on mount), not only in navigate(). Only on a
                // fresh mount, so paints that reuse the cached page do not
                // yank the owner away from content already on screen.
                // (A data refresh rebuilds the tab from scratch, so the
                // scroll repeats then — consistent, since the rebuilt DOM
                // loses scroll position and any open <details> anyway.)
                if(mounted&&state.view==='autopilot'&&location.hash==='#molavi-autopilot-decisions') document.getElementById('molavi-autopilot-decisions')?.scrollIntoView({block:'start'});
                if(state.view==='sources') refreshInbox(activityReport?.inbox_revision);
            }
        }
        function recoveryAction(product, label) {
            const panel=el('div');
            const status=el('p');status.setAttribute('role','status');status.hidden=true;
            const action=button(label,async()=>{
                action.disabled=true;status.hidden=false;status.textContent='در حال باز کردن فرم مشخصات…';
                try {
                    const result=await hooks.reopen([{product_id:product.product_id,offer_id:product.status.latest_offer_id}],true);
                    const caseId=result.items?.[0]?.case_id;
                    if (!caseId) {
                        panel.replaceChildren(el('p','پیشنهاد فعلی تغییر کرده یا پیوند پیام در دسترس نیست.'),
                            button('یافتن پیام تأمین‌کننده',()=>navigate('sources',product)));
                        return;
                    }
                    await reviewInboxItem('case_id',caseId,product.product_id);
                } catch (error) {status.textContent=error.message;action.disabled=false;}
            },'md-button md-primary');
            panel.append(action,status);
            return panel;
        }
        function reopenReview(products,recovery=false) {
            if(!products.length)return;
            const dialog=el('dialog',null,'md-dialog');
            const form=el('form');form.method='dialog';
            form.append(el('h2',products.length===1?products[0].name:`بازگشایی بررسی ${fa(products.length)} کالا`),
                el('p','مشخصات کالا به صف بررسی برمی‌گردد. تصمیم تازه را پس از بررسی هر کارت ثبت کنید.'));
            const feedback=el('p');feedback.setAttribute('role','status');
            const cancel=button('انصراف',()=>dialog.close());
            const submit=el('button',recovery?'بازبینی برای انتشار':'بازگشایی بررسی','md-button md-primary');submit.type='submit';
            form.append(feedback,cancel,submit);dialog.append(form);root.append(dialog);
            dialog.addEventListener('close',()=>dialog.remove());
            dialog.addEventListener('cancel',event=>{if(submit.disabled)event.preventDefault();});
            form.addEventListener('submit',async event=>{
                event.preventDefault();submit.disabled=true;cancel.disabled=true;
                const items=products.map(product=>({product_id:product.product_id,offer_id:product.status.latest_offer_id}));
                try{
                    const result=await hooks.reopen(items,recovery);
                    clearSelection(true);
                    dialog.close();
                    if(products.length===1&&(result.opened||result.already_open)) {
                        reviewInboxItem('case_id',result.items[0].case_id,products[0].product_id);
                    } else paint();
                } catch(error){feedback.textContent=error.message;submit.disabled=false;cancel.disabled=false;}
            });
            dialog.showModal();
        }
        function dismissTask(task) {
            const tasks=Array.isArray(task)?task:task?.task_key?[task]:[];
            if(!tasks.length)return;
            const dialog=el('dialog',null,'md-dialog');
            const form=el('form');form.method='dialog';
            form.append(el('h2',tasks.length===1?'کنارگذاشتن کار':`کنارگذاشتن ${fa(tasks.length)} کار`),
                el('p','کارهای انتخاب‌شده از فهرست این حساب کنار می‌روند. می‌توانید آن‌ها را از «وضعیت سیستم» بازگردانید.'));
            const reason=el('select');reason.required=true;reason.setAttribute('aria-label','علت کنارگذاری');
            const placeholder=el('option','دلیل را انتخاب کنید');placeholder.value='';reason.append(placeholder);
            Object.entries(hooks.dismissReasons).forEach(([key,label])=>{const o=el('option',label);o.value=key;reason.append(o);});
            const note=el('textarea');note.maxLength=400;note.placeholder='یادداشت';note.setAttribute('aria-label','یادداشت کنارگذاری');
            const feedback=el('p');feedback.setAttribute('role','status');
            const cancel=button('انصراف',()=>dialog.close());const submit=el('button','کنارگذاشتن','md-button md-primary');submit.type='submit';
            reason.addEventListener('change',()=>{note.required=reason.value==='other';});
            form.append(reason,note,feedback,cancel,submit);dialog.append(form);root.append(dialog);
            dialog.addEventListener('close',()=>dialog.remove());
            form.addEventListener('submit',async event=>{
                event.preventDefault();submit.disabled=true;
                try{await hooks.dismiss(tasks,reason.value,note.value.trim());clearSelection(true);dialog.close();paint();}
                catch(error){feedback.textContent=error.message;submit.disabled=false;}
            });dialog.showModal();
        }
        function loading(value,message='',kind='info') {
            busy=value;refresh.disabled=busy;refresh.textContent=busy?'در حال تازه‌سازی…':'تازه‌سازی';
            // Existing form drafts stay mounted during refresh; show stale-data status if the request fails.
            app.setAttribute('aria-busy',String(busy));
            status.hidden=!message&&!busy;status.textContent=message||(busy?'در حال دریافت اطلاعات…':'');status.dataset.kind=kind;
        }
        root.addEventListener('click',event=>{
            const link=event.target.closest('a[href]');
            if(!link||event.ctrlKey||event.metaKey||event.shiftKey||event.button)return;
            const url=new URL(link.href,location.href);
            if(url.origin!==location.origin||url.searchParams.get('page')!=='molavi-operations')return;
            event.preventDefault();
            const view=url.searchParams.get('view');
            const focusKey=['offer_id','product_id','case_id','hold_id','conflict_id','reference_id'].find(key=>url.searchParams.has(key));
            if(['products','sources','references','system','pricing','autopilot'].includes(view)) {
                // Carry a decisions-anchor click through: navigate() reads
                // location.hash, and the click's hash is not in it yet.
                if(url.hash==='#molavi-autopilot-decisions') history.replaceState({},'',url.href);
                navigate(view);
            }
            else if(focusKey==='hold_id') reviewHold('molavi-batch-'+encodeURIComponent(url.searchParams.get(focusKey)));
            else if(focusKey&&model) reviewInboxItem(focusKey,url.searchParams.get(focusKey));
            else if(url.searchParams.get('workbench_action')==='reextract'||url.hash.includes('reextract'))navigate('sources');
            else {state.view='products';state.action=url.searchParams.get('workbench_action')||'';saveURL();paint();}
        });
        function update(value) {
            const previous=model?.products.find(product=>product.key===state.active);
            const previousStep=previous&&next(previous);
            model=value;
            const current=model.products.find(product=>product.key===state.active);
            if(current&&previousStep&&previousStep!==next(current)&&next(current)!=='deferred'){
                if(state.action===previousStep && next(current)!=='next')state.action='';
                state.section=['next','deferred'].includes(next(current))?'source_integrity':next(current);
                if(!matches(current)){
                    state.scope='all';
                }
                saveURL();
            }
            if(!state.active&&(state.action||state.focus)){
                const focused=model.products.find(matches);
                if(focused){state.active=focused.key;state.section=['next','deferred'].includes(next(focused))?'source_integrity':next(focused);}
            }
            const references=state.pageCache.get('references');
            const sources=state.pageCache.get('sources');
            state.panels.clear();
            const pricing=state.pageCache.get('pricing');
            state.pageCache.clear();
            if(pricing) state.pageCache.set('pricing',pricing);
            if(references) state.pageCache.set('references',references);
            if(sources) state.pageCache.set('sources',sources);
            healthView();paint();
        }
        async function reviewHold(id) {
            navigate('system');
            const target=()=>id ? document.getElementById(id) : main.querySelector('.md-publication-holds');
            if(!target()) {
                await hooks.refresh();
                if(state.view!=='system') return;
            }
            const group=main.querySelector('.md-publication-holds');
            const batch=target();
            if (!group||!batch) return;
            group.open=true;
            batch.scrollIntoView({block:'start',behavior:'smooth'});
            batch.focus({preventScroll:true});
        }
        function showReviewResults() {
            clearSelection(true);
            state.scope='ready';state.action='';state.focus='';state.search='';state.channel='';
            navigate('products');
            hooks.refresh();
        }
        async function reviewInboxItem(kind,id,fallbackProduct='') {
            clearSelection(true);
            state.scope='all';state.action='';state.search='';state.channel='';
            state.focusType=kind;state.focus=id;
            const product=model.products.find(matches);
            function openCard(current) {
                state.active=current?.key || '';
                state.section=current && !['next','deferred'].includes(next(current)) ? next(current) : 'source_integrity';
                navigate('products');
            }
            openCard(product);
            if (product) return;
            await hooks.refresh();
            if (state.view!=='products'||state.focusType!==kind||state.focus!==id||state.active) return;
            if (fallbackProduct) {
                state.focusType='product_id';state.focus=fallbackProduct;
                const fallback=model.products.find(matches);
                if (fallback) openCard(fallback);
                else {state.focusType=kind;state.focus=id;}
            }
        }
        paint();
        return {update,loading,navigate,dismissTask,reviewHold,showReviewResults,reviewInboxItem,refreshActivity,startAction,reviewSource:product=>navigate('sources',product)};
    }
    window.MolaviDashboard={create,normalize,identities};
})();
