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
        ['source_integrity','منبع'],['ai','مشخصات'],['references','مرجع'],
        ['supplier_prices','قیمت'],['conflicts','همگام‌سازی'],['holds','انتشار'],['storefront','فروشگاه']
    ];
    const actionLabels = {source_integrity:'بررسی منبع',ai:'بررسی مشخصات',references:'انتخاب مرجع',
        supplier_prices:'تأیید قیمت',conflicts:'رفع تعارض',holds:'بررسی توقف',status:'بررسی انتشار',storefront:'بررسی فروشگاه'};
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
            view:['products','references','sources','system'].includes(params.get('view')) ? params.get('view')
                : params.get('workbench_action') === 'reextract' || location.hash.includes('reextract') ? 'sources' : 'products',
            scope:params.get('scope') || 'ready',action:params.get('workbench_action') || '',
            focus:['product_id','offer_id','case_id','conflict_id','hold_id','reference_id'].map(key=>params.get(key)).find(Boolean) || '',
            search:params.get('q') || '',channel:params.get('channel') || '',limit:24,
            active:'',section:'',panels:new Map(),pageCache:new Map()
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
        let updatingReference = false;
        let activitySignature = '';
        root.className = 'molavi-dashboard';
        root.removeAttribute('aria-live');
        root.dir = 'rtl';
        const app = el('div',null,'md-app');
        const head = el('header',null,'md-header');
        const brand = el('div',null,'md-brand');
        brand.append(el('span','مولوی‌سل','md-wordmark'),el('span','مدیریت فروشگاه','md-subtitle'));
        const refresh = button('تازه‌سازی',()=>hooks.refresh(),'md-button md-button-quiet');
        head.append(brand,refresh);
        const nav = el('nav',null,'md-navigation');
        nav.setAttribute('aria-label','مدیریت فروشگاه');
        const navButtons = new Map();
        [['products','محصولات'],['references','فهرست مرجع'],['sources','پیام‌های منبع'],['system','وضعیت سیستم']].forEach(([key,label])=>{
            const item=button(label,()=>navigate(key),'md-nav-item');
            navButtons.set(key,item); nav.appendChild(item);
        });
        const status = el('p','','md-feedback');
        status.setAttribute('role','status'); status.setAttribute('aria-live','polite'); status.hidden=true;
        const activityBar = el('aside',null,'md-activity');
        activityBar.setAttribute('aria-label','کارهای در جریان');
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
        const activityLabels={
            reextract:'بازاستخراج پیام',batch:'پردازش پیام‌های ربات',intake:'پیام ورودی',
            reference_suggest:'پیشنهاد مرجع',reference_index:'نمایه‌سازی مرجع',reference_refresh:'به‌روزرسانی فهرست مرجع',
        };
        const activityStates={running:'در حال اجرا',queued:'در صف',quota_wait:'منتظر سهمیه',needs_reference:'منتظر تصمیم مرجع'};
        const taskLabels={extract:'استخراج',match:'تطبیق مرجع',embed:'نمایه‌سازی'};
        const localClock=new Intl.DateTimeFormat('fa-IR',{timeZone:'Asia/Tehran',hour:'2-digit',minute:'2-digit'});
        const localDateTime=new Intl.DateTimeFormat('fa-IR',{timeZone:'Asia/Tehran',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
        function activityDescription(entry,quota) {
            const label=entry.kind==='reextract'
                ? (entry.total===1?'بازاستخراج یک پیام':'بازاستخراج گروهی') : activityLabels[entry.kind];
            const text=[label,activityStates[entry.state]];
            if (entry.total) text.push(`${fa(entry.completed)} از ${fa(entry.total)}`);
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
            if (['reextract','batch','intake'].includes(kind)) {
                state.sourceContext=null;
                state.pageCache.delete('sources');
                navigate('sources');
            } else navigate('references');
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
        function paintActivity() {
            if (!hooks.readActivity) return;
            const groups=[...(activityReport?.groups || [])];
            if (updatingReference && !groups.some(entry=>entry.kind==='reference_refresh')) {
                groups.unshift({kind:'reference_refresh',state:'running'});
            }
            groups.sort((a,b)=>Number(b.state==='running')-Number(a.state==='running'));
            const quota=activityReport?.quota || {};
            const signature=JSON.stringify([groups,quota,activityStale]);
            if (signature===activitySignature) return;
            activitySignature=signature;
            const visible=groups.slice(0,2);
            const description=visible.map(entry=>activityDescription(entry,quota));
            const announcement=description.join(' | ');
            if (announcement!==activityLive.textContent) activityLive.textContent=announcement;
            const all=el('ul',null,'md-activity-list');
            const top=el('ul',null,'md-activity-list');
            groups.forEach((entry,index)=>{
                const row=el('li');
                row.append(el('span',activityDescription(entry,quota)),
                    button('جزئیات',()=>openActivity(entry.kind),'md-link'));
                all.appendChild(row);
                if (index<visible.length) {
                    const short=el('li');
                    short.append(el('span',description[index]),button('جزئیات',()=>openActivity(entry.kind),'md-link'));
                    top.appendChild(short);
                }
            });
            activityBar.replaceChildren(top);
            activityBar.hidden=!groups.length;
            if (groups.length>2) activityBar.appendChild(button(`همهٔ کارها (${fa(groups.length)})`,()=>navigate('system'),'md-link'));
            if (activityStale && groups.length) activityBar.appendChild(el('small','آخرین وضعیت دریافت‌شده'));
            const nextQuota=quotaView(quota);
            systemActivity.replaceChildren();
            if (groups.length) systemActivity.appendChild(all);
            systemActivity.appendChild(nextQuota);
            if (activityStale) systemActivity.appendChild(el('small','تازه‌سازی وضعیت موقتاً در دسترس نیست.'));
        }
        function refreshActivity() {
            clearTimeout(activityTimer);
            if (!hooks.readActivity || document.hidden || activityRequest) return;
            activityRequest=hooks.readActivity().then(report=>{
                activityReport=report;
                activityStale=false;
            }).catch(()=>{activityStale=true;}).finally(()=>{
                activityRequest=null;
                paintActivity();
                if (!document.hidden) {
                    const active=updatingReference || activityReport?.groups?.some(entry=>['running','queued'].includes(entry.state));
                    activityTimer=setTimeout(refreshActivity,active?7000:30000);
                }
            });
        }
        function setReferenceRefresh(running) {
            updatingReference=running;
            paintActivity();
            refreshActivity();
        }
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
            url.hash=''; history.replaceState({},'',url);
        }
        function navigate(view, product=null) {
            if(view==='sources'&&product){state.sourceContext=product;state.pageCache.delete('sources');}
            state.view=view;saveURL();paint();main.scrollIntoView({block:'start',behavior:'smooth'});
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
        function next(product) { return model.primaryAction(product); }
        function ready(product) { return model.readyAction(product); }
        function stateLabel(product) {
            if(product.holds.length)return ['انتشار متوقف','blocked'];
            if(ready(product))return ['آمادهٔ بررسی','attention'];
            if(next(product)!=='next')return ['در انتظار پیگیری','unknown'];
            const woo=model.wooState(product);
            if(woo==='purchasable')return ['قابل خرید','good'];
            if(!product.status)return ['در انتظار تکمیل','unknown'];
            return ['اقدام بازی ثبت نشده','neutral'];
        }
        function matches(product) {
            const text=normalize([product.name,product.channel,...identities(product)].join(' '));
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
            if(state.action&&!state.focus&&next(product)!==state.action)return false;
            if(state.action||state.focus)return true;
            if(state.scope==='ready')return Boolean(ready(product));
            if(state.scope==='waiting')return next(product)!=='next'&&!ready(product);
            return true;
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
            const pendingBatches=model.data.publication_holds?.holds || [];
            if (pendingBatches.length&&!model.errors.publication_holds) {
                const label=`توقف‌های انتشار · ${fa(pendingBatches.length)}`;
                view.append(button(label,()=>reviewHold(),'md-button md-link'));
            }
            const scopeBar=el('div',null,'md-scopes');scopeBar.setAttribute('aria-label','فهرست محصولات');
            [['ready','آمادهٔ بررسی',model.products.filter(ready).length],
                ['waiting','در انتظار پیگیری',model.products.filter(p=>next(p)!=='next'&&!ready(p)).length],
                ['all','همهٔ محصولات',model.products.length]].forEach(([key,label,count])=>{
                const item=button(`${label}  ${fa(count)}`,()=>{
                    state.scope=key;state.action='';state.focus='';state.limit=24;saveURL();paint();
                },'md-scope');item.setAttribute('aria-pressed',String(!state.action&&state.scope===key));scopeBar.append(item);
            });view.append(scopeBar);
            const filters=el('div',null,'md-filters');
            const search=el('input');search.type='search';search.value=state.search;search.placeholder='نام کالا، شناسه یا تأمین‌کننده…';
            search.setAttribute('aria-label','جست‌وجوی محصولات');search.autocomplete='off';
            const action=el('select');action.setAttribute('aria-label','نوع رسیدگی');
            [['','همهٔ کارها'],...Object.entries(actionLabels)].forEach(([key,label])=>{
                const option=el('option',label);option.value=key;action.append(option);
            });action.value=state.action;
            const channel=el('select');channel.setAttribute('aria-label','تأمین‌کننده');
            const all=el('option','همهٔ تأمین‌کنندگان');all.value='';channel.append(all);
            [...new Set(model.products.map(p=>p.channel).filter(Boolean))].sort().forEach(value=>{const o=el('option',value);o.value=value;channel.append(o);});
            channel.value=state.channel;
            filters.append(search,action,channel);view.append(filters);
            const focus=el('div',null,'md-active-filter');focus.hidden=!state.focus;
            if(state.focus)focus.append(el('span',`شناسه: ${state.focus}`),button('نمایش همه',()=>{state.focus='';saveURL();paint();},'md-link'));
            view.append(focus);
            const count=el('p',null,'md-result-count');count.setAttribute('role','status');
            const list=el('div',null,'md-product-list');
            const more=button('نمایش بیشتر',()=>{state.limit+=24;paintList();},'md-button md-more');
            const paintList=()=>{
                const rows=model.products.filter(matches).sort((a,b)=>model.actionRank(next(a))-model.actionRank(next(b))||a.name.localeCompare(b.name,'fa'));
                count.textContent=`${fa(rows.length)} کالا`;
                list.replaceChildren();
                rows.slice(0,state.limit).forEach(product=>list.append(productCard(product)));
                if(!rows.length) {
                    const readyScope = state.scope==='ready'&&!state.action&&!state.search&&!state.channel;
                    list.append(empty(readyScope
                        ? (Object.values(model.errors).some(Boolean) ? 'فهرست کارهای آماده کامل نیست' : 'کار آمادهٔ بررسی در این فهرست نیست')
                        : 'موردی در این فهرست نیست'));
                }
                more.hidden=rows.length<=state.limit;
            };
            let searchTimer;
            search.addEventListener('input',()=>{
                state.search=search.value;state.limit=24;clearTimeout(searchTimer);
                searchTimer=setTimeout(()=>{saveURL();paintList();},160);
            });
            action.addEventListener('change',()=>{state.action=action.value;state.limit=24;saveURL();paintList();});
            channel.addEventListener('change',()=>{state.channel=channel.value;state.limit=24;saveURL();paintList();});
            view.append(count,list,more);paintList();return view;
        }
        function productCard(product) {
            const record=product.status||{};
            const source=sourceFor(product);
            const preferred=next(product);
            const [label,tone]=stateLabel(product);
            const card=el('article',null,'md-product-card');card.dataset.productKey=product.key;
            const header=el('header',null,'md-card-header');
            const icon=el('span',(product.name||'ک').slice(0,1),'md-product-mark');icon.setAttribute('aria-hidden','true');
            const identity=el('div',null,'md-product-identity');identity.append(el('h2',product.name),el('p',product.channel||'تأمین‌کننده ثبت نشده'));
            header.append(icon,identity,el('span',label,`md-badge md-${tone}`));card.append(header);
            const nextLine=el('div',null,'md-next-action');
            if(preferred==='deferred'){
                nextLine.append(el('p',model.nextStep(product,preferred)),button('کارهای کنارگذاشته‌شده',()=>navigate('system'),'md-link'));
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
                    const sourceURL=record.source_post_url||source.source_post_url||source.source_url;
                    if(key==='source_integrity'){
                        if(!hasAction){
                            panel.append(facts([['زمان منبع',model.formatDate(source.source_timestamp||source.message_timestamp)],['پیوند منبع',sourceURL?'ثبت شده':'ثبت نشده']]));
                            if(sourceURL){const link=model.sourceLink(sourceURL);if(link)panel.append(link);}
                            if(!sourceURL&&!source.original_message)panel.append(el('p','متن یا پیوند پیام در این کارت ثبت نشده است.'));
                            if(source.original_message){const text=el('details');text.append(el('summary','متن کامل پیام'),el('p',source.original_message,'md-source-text'));panel.append(text);}
                            const offer=record.latest_offer_id||source.offer_id;
                            if (offer) panel.append(model.renderOfferReextraction(String(offer)));
                            else panel.append(button('جست‌وجوی پیام منبع',()=>navigate('sources',product)));
                        }
                    }
                    else if(key==='storefront'){
                        panel.append(el('p',model.wooLabel(model.wooState(product))));
                        panel.append(button('بررسی وضعیت فروشگاه',event=>model.reconcile(event.currentTarget)));
                    }
                    if(hasAction&&key!=='storefront'){
                        const action=model.renderAction(product,key);
                        if (action) panel.append(action);
                        panel.append(button('کنارگذاشتن این کار',()=>dismissTask(model.dismissalTask(product,key)),'md-link md-muted'));
                    }
                    state.panels.set(cacheKey,panel);
                }
                details.append(panel);
            }
            productSections.forEach(([key,title],index)=>{
                const tab=button(title,()=>activate(key),'md-card-tab');
                tab.title={
                    source_integrity:'متن پیام و بازاستخراج',ai:'مشخصات خوانده‌شده و اصلاح آن‌ها',
                    references:'تطبیق کالا و قیمت واحد یا بسته',supplier_prices:'اعتبار قیمت تأمین‌کننده',
                    conflicts:'اختلاف داده‌های ذخیره‌شده',holds:'توقف‌های انتشار',status:'دلیل وضعیت انتشار',
                    storefront:'آنچه در ووکامرس دیده یا قابل‌خرید است'
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
            if (!['holds','source_integrity','ai','references'].includes(preferred)) {
                const referenceTools=model.renderReferenceTools(product);
                if (referenceTools) technical.appendChild(referenceTools);
            }
            card.append(technical);
            return card;
        }
        function referencesView() {
            const view=el('section');view.append(el('h1','فهرست مرجع'),el('p','دادهٔ ذخیره‌شدهٔ رزوان برای مقایسه با کالاهای پیام‌های منبع.'));
            const panel=el('div',null,'md-reference-catalog');view.append(panel);model.renderReferences(panel);return view;
        }
        function sourcesView() {
            const view=el('section');view.append(el('h1','پیام‌های منبع'),el('p','برای یک پیام، از منبع یا مشخصاتِ کارت همان کالا شروع کنید. برای چند پیام، از گزینه‌های این صفحه استفاده کنید.'));
            const context=state.sourceContext;
            if(context){
                const hint=el('div',null,'md-source-context');
                hint.append(el('strong',`منبع ${context.name}`),el('p',`تأمین‌کننده: ${context.channel||'ثبت نشده'}`));
                const link=model.sourceLink(context.status?.source_post_url||sourceFor(context).source_post_url);
                if(link)hint.append(link);
                hint.append(button('نمایش همهٔ پیام‌ها',()=>{state.sourceContext=null;state.pageCache.delete('sources');paint();},'md-link'));view.append(hint);
            }
            const panel=el('div');view.append(panel);model.renderSources(panel,context);return view;
        }
        function systemView() {
            const view=el('section');view.append(el('h1','وضعیت سیستم'));
            if (hooks.readActivity) view.appendChild(systemActivity);
            const counts=model.data.operations?.operations?.by_state;
            if (counts) {
                const running=Number(counts.queued || 0)+Number(counts.running || 0);
                const waiting=Number(counts.waiting_publication || 0)+Number(counts.waiting_reference || 0);
                const issues=Number(counts.failed || 0)+Number(counts.needs_review || 0);
                const overview=el('div',null,'md-source-context');
                overview.append(el('p',`${fa(running)} پردازش خودکار · ${fa(waiting)} منتظر مرجع یا انتشار · ${fa(issues)} خطا یا نیازمند بررسی`),
                    el('p','پردازش‌های خودکار اقدامی از شما نمی‌خواهند. برای یک تصمیم محصول به محصولات بروید؛ برای توقف یک دسته، بخش زیر را باز کنید.'));
                view.append(overview);
            }
            if(model.data.publication_holds?.holds?.length){
                const group=el('details');group.className='md-publication-holds';group.tabIndex=-1;group.append(el('summary',`توقف‌های مشترک پردازش · ${fa(model.data.publication_holds.holds.length)}`));
                model.data.publication_holds.holds.forEach(hold=>group.append(model.renderBatch(hold)));view.append(group);
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
            if(!model)return;
            navButtons.forEach((button,key)=>button.setAttribute('aria-current',key===state.view?'page':'false'));
            if(state.view==='products')main.replaceChildren(productsView());
            else {
                if(!state.pageCache.has(state.view))state.pageCache.set(state.view,
                    state.view==='references'?referencesView():state.view==='sources'?sourcesView():systemView());
                main.replaceChildren(state.pageCache.get(state.view));
            }
        }
        async function dismissTask(task) {
            if(!task?.task_key)return;
            const dialog=el('dialog',null,'md-dialog');
            const form=el('form');form.method='dialog';form.append(el('h2','کنارگذاشتن کار'),el('p','این کار فقط از فهرست این حساب پنهان می‌شود و قابل بازگرداندن است.'));
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
                try{await hooks.dismiss([task],reason.value,note.value.trim());dialog.close();}
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
            if(url.searchParams.get('view'))navigate(url.searchParams.get('view'));
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
                if(focused){state.active=focused.key;state.section=next(focused)==='next'?'source_integrity':next(focused);}
            }
            state.panels.clear();state.pageCache.clear();healthView();paint();
        }
        function reviewHold(id) {
            navigate('system');
            const group=main.querySelector('.md-publication-holds');
            if (!group) return;
            group.open=true;
            const batch=id ? document.getElementById(id) : group;
            if (!batch) return;
            batch.scrollIntoView({block:'start',behavior:'smooth'});
            batch.focus({preventScroll:true});
        }
        function showReviewResults() {
            state.scope='ready';state.action='';state.focus='';state.search='';state.channel='';
            navigate('products');
            hooks.refresh();
        }
        function reviewInboxItem(kind,id,fallbackProduct='') {
            state.scope='all';state.action='';state.search='';state.channel='';
            state.focusType=kind;state.focus=id;
            let product=model.products.find(matches);
            if (!product && fallbackProduct) {
                state.focusType='product_id';state.focus=fallbackProduct;
                product=model.products.find(matches);
            }
            state.active=product?.key || '';
            state.section=product && !['next','deferred'].includes(next(product)) ? next(product) : 'source_integrity';
            navigate('products');
        }
        return {update,loading,navigate,dismissTask,reviewHold,showReviewResults,reviewInboxItem,refreshActivity,setReferenceRefresh,reviewSource:product=>navigate('sources',product)};
    }
    window.MolaviDashboard={create,normalize,identities};
})();
