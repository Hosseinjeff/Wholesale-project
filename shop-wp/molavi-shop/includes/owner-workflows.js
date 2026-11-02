(() => {
    const root = document.getElementById('molavi-owner-workflow');
    const config = window.molaviOwnerWorkflow;
    if (!root || !config) return;

    function node(tag, text, className) {
        const item = document.createElement(tag);
        if (text !== undefined && text !== null) item.textContent = String(text);
        if (className) item.className = className;
        return item;
    }

    function notice(text, kind = 'info') {
        const box = node('div', null, 'notice notice-' + kind);
        box.appendChild(node('p', text));
        return box;
    }

    function amount(value) {
        if (value === null || value === undefined || value === '') return 'ثبت نشده';
        const number = Number(value);
        return Number.isFinite(number) ? new Intl.NumberFormat('fa-IR').format(number) + ' تومان' : String(value);
    }

    function errorText(code) {
        const labels = {
            unauthorized: 'نشست مالک منقضی شده است؛ صفحه را تازه کنید.',
            owner_required: 'این حساب اجازه انجام این کار را ندارد.',
            stale_case: 'اطلاعات این مورد تغییر کرده است؛ فهرست را تازه کنید.',
            stale_snapshot: 'نسخه مرجع تغییر کرده است؛ فهرست را تازه کنید.',
            review_conflict: 'تصمیم دیگری هم‌زمان ثبت شده است؛ فهرست را تازه کنید.',
            decision_conflict: 'تصمیم دیگری هم‌زمان ثبت شده است؛ فهرست را تازه کنید.',
            publication_held_for_review: 'تا بررسی توقف انتشار، این تصمیم ثبت نمی‌شود.',
            package_count_required: 'برای قیمت بسته، تعداد داخل بسته را مشخص کنید.',
            unknown_reference: 'نام دقیق یا شناسه کالای مرجع پیدا نشد؛ یکی از پیشنهادها را انتخاب کنید.',
            ambiguous_reference_name: 'این نام با چند کالای مرجع تطبیق دارد؛ یکی از پیشنهادها را انتخاب کنید یا شناسه دقیق را وارد کنید.',
            invalid_price_role: 'نقش قیمت را انتخاب کنید.',
            stale_or_conflicting_decision: 'وضعیت تصمیم تغییر کرده است؛ صفحه را تازه کنید.',
            review_unavailable: 'ثبت تصمیم موقتاً انجام نشد. صفحه را تازه کنید و دوباره تلاش کنید.',
            conflict_or_invalid: 'وضعیت توقف تغییر کرده است؛ صفحه را تازه کنید و دوباره بررسی کنید.',
            offer_changed: 'نسخه قیمت تغییر کرده است؛ دوباره منبع را بررسی کنید.',
            verification_rejected: 'تأیید قیمت ثبت نشد؛ یادداشت و نسخه منبع را دوباره بررسی کنید.',
            conflict_changed: 'این تعارض قبلاً تغییر کرده یا تعیین تکلیف شده است؛ فهرست را تازه کنید.',
            field_read_only: 'این فیلد فقط از مسیر تأییدشده قابل تغییر است.',
            sheet_unavailable: 'خواندن دوباره شیت ناموفق بود؛ تصمیم ثبت نشد.',
            row_changed: 'ردیف کالا تغییر کرده است؛ فهرست تعارض‌ها را تازه کنید.',
            source_changed: 'پیشنهاد تأمین‌کننده تغییر کرده است؛ تعارض را دوباره بررسی کنید.',
            server_value_changed: 'مقدار فعلی سرور با این تعارض یکسان نیست؛ فهرست را تازه کنید.',
            sheet_value_changed: 'مقدار فعلی شیت با این تعارض یکسان نیست؛ فهرست را تازه کنید.',
            safe_cell_writer_unavailable: 'نوشتن امن روی این سلول در دسترس نیست.',
            write_unconfirmed: 'تغییر شیت تأیید نشد؛ دوباره بررسی کنید.',
            resolution_failed: 'ثبت تصمیم تعارض ناموفق بود؛ وضعیت را تازه کنید.',
            publication_hold_changed: 'وضعیت دسته تغییر کرده؛ فهرست تازه شد تا دوباره بررسی کنید.',
            review_store_failed: 'ثبت اطلاعات بررسی در پایگاه داده وردپرس ناموفق بود؛ تصمیم ارسال نشد.',
            invalid_records: 'ثبت اطلاعات بررسی معتبر نبود؛ صفحه را تازه کنید.',
            reconciliation_unavailable: 'گزارش تطبیق موقتاً در دسترس نیست؛ وضعیت اتصال شیت و فروشگاه را بررسی کنید.',
            backlog_changed: 'صف هنگام اجرا تغییر کرد؛ صفحه را تازه کنید و وضعیت جدید را بررسی کنید.',
            catalog_projection_changed: 'شیت هنگام همگام‌سازی تغییر کرد؛ همگام‌سازی تازه در صف قرار گرفت و خطاها باز ماندند.',
            catalog_sync_incomplete: 'همگام‌سازی کامل نشد؛ تلاش‌های قبلی باز ماندند تا دوباره بررسی شوند.',
            backlog_reconcile_unavailable: 'تطبیق خطاهای قدیمی موقتاً انجام نشد؛ صف را تازه کنید و دوباره بررسی کنید.',
            reextraction_active: 'یک بازاستخراج دیگر هنوز در صف یا در حال اجراست؛ ابتدا وضعیت همان اجرا را پیگیری کنید.',
            reextraction_preview_changed: 'فهرست پیام‌ها تغییر کرده است؛ تعداد تازه را بررسی و دوباره شروع کنید.',
            no_reextraction_messages: 'پیامی برای بازاستخراج در این گروه پیدا نشد.',
            invalid_dismissal_request: 'درخواست رد گروهی معتبر نیست؛ صفحه را تازه کنید.',
            dismiss_failed: 'رد کردن همه موارد انجام نشد؛ صفحه را تازه کنید و موارد باقی‌مانده را بررسی کنید.',
            ai_extraction_disabled: 'برای استفاده از استخراج فقط با هوش مصنوعی، ابتدا سرویس سرور را به‌روزرسانی کنید.',
            ai_extraction_unconfigured: 'برای استخراج کالا، کلید GOOGLE_API_KEY را در تنظیمات سرویس اجرا تنظیم کنید.',
            unknown_reextraction_run: 'اجرای بازاستخراج پیدا نشد؛ فهرست را تازه کنید.',
            reextraction_conflict: 'صف بازاستخراج تغییر کرده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            reextraction_price_not_verified: 'برای تأیید قیمت بازاستخراج‌شده، تازگی آن را در منبع فعلی تأیید کنید.'
        };
        return labels[code] || 'ارتباط با سرور برقرار نشد. کد پیگیری: ' + (code || 'نامشخص');
    }

    async function request(method = 'GET', payload = undefined, url = config.apiUrl) {
        const options = { method, mode: 'cors', cache: 'no-store',
            headers: { Authorization: 'Bearer ' + config.token } };
        if (payload !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }
        const response = await fetch(url, options);
        const body = await response.json().catch(() => null);
        if (!response.ok || !body || body.ok !== true) {
            const error = new Error(body && typeof body.error === 'string' ? body.error : 'request_failed');
            error.requestStatus = response.status;
            error.remoteRejected = response.status >= 400 && response.status < 500
                && Boolean(body && body.ok === false);
            throw error;
        }
        if (method === 'POST' && window.parent !== window) {
            window.parent.postMessage({ type: 'molavi-owner-workflow-updated' }, window.location.origin);
        }
        return body;
    }

    async function requestQueueSummary() {
        const sources = config.queueSummaryApis;
        if (!sources || !Object.keys(sources).length) throw new Error('queue_summary_unavailable');
        const results = await Promise.all(Object.entries(sources).map(async ([key, source]) => {
            try {
                const response = await fetch(source.url, {
                    method:'GET', mode:'cors', cache:'no-store',
                    headers:{ Authorization:'Bearer ' + source.token }
                });
                const body = await response.json().catch(() => null);
                const rows = body && body.ok === true ? body[source.rows_key] : null;
                return [key, response.ok && Array.isArray(rows)
                    ? {status:'ok', count:rows.length}
                    : {status:'unavailable', count:null}];
            } catch (_error) {
                return [key, {status:'unavailable', count:null}];
            }
        }));
        return Object.fromEntries(results);
    }

    function reviewEventId(type, id) {
        const suffix = window.crypto && typeof window.crypto.randomUUID === 'function'
            ? window.crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
        return `decision:${type}:${id}:${suffix}`;
    }

    function reviewRecord(eventId, type, id, state, context, decision = {}, receipt = {}) {
        return {event_id:eventId, review_type:type, review_id:id, state,
            context:context || {}, decision:decision || {}, receipt:receipt || {}};
    }

    function storedReviewContext(type, context) {
        if (type === 'ai_confirmation' && context && typeof context === 'object') {
            const source = String(context.original_message || '');
            const characters = Array.from(source);
            const decisionSnapshot = decision => {
                if (!decision || typeof decision !== 'object' || Array.isArray(decision)) return null;
                const fields = decision.fields && typeof decision.fields === 'object' && !Array.isArray(decision.fields)
                    ? decision.fields : {};
                return {
                    decision_id:String(decision.decision_id || '').slice(0, 120),
                    case_id:String(decision.case_id || context.case_id || '').slice(0, 120),
                    offer_id:String(decision.offer_id || context.offer_id || '').slice(0, 300),
                    signature:String(decision.signature || '').slice(0, 64),
                    choice:String(decision.choice || '').slice(0, 40),
                    actor_id:String(decision.actor_id || '').slice(0, 120),
                    note:String(decision.note || '').slice(0, 1000),
                    created_at:decision.created_at || '',
                    fields:{price_role:fields.price_role || '', sale_price:fields.sale_price || '',
                        actual_price:fields.actual_price || '', package_count:fields.package_count || '',
                        min_qty:fields.min_qty || '', stock_status:fields.stock_status || ''}
                };
            };
            return {
                case_id:String(context.case_id || '').slice(0, 120),
                offer_id:String(context.offer_id || '').slice(0, 300),
                message_id:String(context.message_id || '').slice(0, 100),
                channel:String(context.channel || '').slice(0, 100),
                source_channel:String(context.source_channel || '').slice(0, 100),
                batch_id:String(context.batch_id || '').slice(0, 300),
                message_timestamp:String(context.message_timestamp || '').slice(0, 60),
                source_post_url:String(context.source_post_url || '').slice(0, 500),
                product_name:String(context.product_name || '').slice(0, 1000),
                reason:String(context.reason || '').slice(0, 2000),
                sale_price:context.sale_price || '', detected_price:context.detected_price || '',
                actual_price:context.actual_price || '', package_price:context.package_price || '',
                package_count:context.package_count || '', min_qty:context.min_qty || '',
                price_type:String(context.price_type || '').slice(0, 40),
                currency:String(context.currency || '').slice(0, 40),
                packaging:String(context.packaging || '').slice(0, 300),
                stock_status:String(context.stock_status || '').slice(0, 40),
                reference_match_state:String(context.reference_match_state || '').slice(0, 120),
                reference_match_id:String(context.reference_match_id || '').slice(0, 300),
                reference_price_role_hint:String(context.reference_price_role_hint || '').slice(0, 120),
                historical_reextract:Boolean(context.historical_reextract),
                previous_decision_id:String(context.previous_decision_id || '').slice(0, 64),
                decision:decisionSnapshot(context.decision),
                decision_history:Array.isArray(context.decision_history)
                    ? context.decision_history.slice(-10).map(decisionSnapshot).filter(Boolean) : [],
                original_message:characters.slice(0, 1000).join(''),
                original_message_truncated:Boolean(source.length > 1000)
            };
        }
        if (type !== 'publication_hold' || !context || !Array.isArray(context.messages)) return context || {};
        return {...context, messages:context.messages.map(source => {
            const content = String(source.content || '');
            const characters = Array.from(content);
            return {
                message_id:String(source.message_id || '').slice(0, 100),
                channel:String(source.channel || '').slice(0, 100),
                source_timestamp:String(source.source_timestamp || '').slice(0, 60),
                source_url:String(source.source_url || '').slice(0, 500),
                content:characters.slice(0, 300).join(''),
                content_truncated:Boolean(source.content_truncated || characters.length > 300),
                has_media:Boolean(source.has_media),
                media_type:String(source.media_type || '').slice(0, 40)
            };
        })};
    }

    async function persistOwnerReviews(records) {
        if (!config.reviewStoreUrl || !config.reviewStoreNonce || !Array.isArray(records) || !records.length) {
            throw new Error('review_store_failed');
        }
        const batches = [];
        let current = [];
        let currentBytes = 2;
        for (const record of records) {
            const bytes = new TextEncoder().encode(JSON.stringify(record)).length + 1;
            if (bytes > 450000) throw new Error('review_store_failed');
            if (current.length && (current.length >= 100 || currentBytes + bytes > 450000)) {
                batches.push(current); current = []; currentBytes = 2;
            }
            current.push(record); currentBytes += bytes;
        }
        if (current.length) batches.push(current);
        for (const batch of batches) {
            const response = await fetch(config.reviewStoreUrl, {
                method:'POST', mode:'same-origin', credentials:'same-origin', cache:'no-store',
                headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body:new URLSearchParams({action:'molavi_owner_review_store', nonce:config.reviewStoreNonce,
                    records:JSON.stringify(batch)}).toString()
            });
            const body = await response.json().catch(() => null);
            if (!response.ok || !body || body.success !== true
                || !body.data || body.data.stored !== batch.length) {
                throw new Error(body && body.data && body.data.code || 'review_store_failed');
            }
        }
        return {stored:records.length};
    }

    function shell(title, description) {
        root.replaceChildren();
        const header = node('div', null, 'molavi-workflow-heading');
        header.appendChild(node('h1', title));
        const back = node('a', 'بازگشت به خط لوله');
        back.href = config.pipelineUrl;
        back.target = '_top';
        header.appendChild(back);
        root.appendChild(header);
        if (description) root.appendChild(node('p', description));
    }

    function field(labelText, name, value, type = 'text', options = {}) {
        const label = node('label', labelText + ' ');
        let input;
        if (type === 'select') {
            input = document.createElement('select');
            for (const [val, text] of options.choices || []) {
                const option = node('option', text);
                option.value = val;
                input.appendChild(option);
            }
        } else if (type === 'textarea') {
            input = document.createElement('textarea');
            input.maxLength = options.maxLength || 1000;
            input.rows = options.rows || 3;
        } else {
            input = document.createElement('input');
            input.type = type;
            if (options.min !== undefined) input.min = String(options.min);
            if (options.step !== undefined) input.step = String(options.step);
            if (type === 'number') input.inputMode = 'numeric';
        }
        input.name = name;
        input.value = value === null || value === undefined ? '' : String(value);
        if (options.required) input.required = true;
        if (options.maxLength) input.maxLength = options.maxLength;
        label.appendChild(input);
        return label;
    }

    function errorBox(message, container = root) {
        container.prepend(notice(message, 'error'));
    }

    async function dashboard() {
        shell('خط لوله مولوی‌سل', 'کارهای باز به‌ترتیب اولویت نمایش داده می‌شوند. از اولین مورد شروع کنید.');
        const actionBoard = node('section', null, 'card molavi-next-action');
        actionBoard.appendChild(node('h2', 'کار بعدی'));
        actionBoard.appendChild(node('p', 'در حال بررسی صف‌های باز…'));
        const board = document.createElement('details');
        board.className = 'card molavi-technical-details';
        board.appendChild(node('summary', 'جزئیات فنی صف و گزارش تطبیق'));
        board.appendChild(node('p', 'برای بارگذاری وضعیت فنی صبر کنید…'));
        const sections = [
            ['molavi-ai-confirmations', 'استخراج کالا'],
            ['molavi-reference-snapshots', 'فهرست مرجع'],
            ['molavi-reference-decisions', 'تازگی مرجع'],
            ['molavi-reference-items', 'تطبیق کالا'],
            ['molavi-supplier-prices', 'قیمت تأمین‌کننده'],
            ['molavi-publication-holds', 'انتشار'],
            ['molavi-sheet-conflicts', 'تعارض شیت'],
            ['molavi-inventory-management', 'موجودی'],
            ['molavi-clearance-pricing', 'قیمت حراج'],
            ['molavi-paid-orders', 'سفارش‌ها'],
            ['molavi-product-status-snapshots', 'وضعیت کالا']
        ];
        const tabs = node('div', null, 'molavi-admin-tabs');
        tabs.setAttribute('role', 'tablist');
        tabs.setAttribute('aria-label', 'بخش‌های خط لوله مولوی‌سل');
        const panels = node('div', null, 'molavi-admin-tab-panels');
        const entries = sections.map(([slug, title], index) => {
            const id = 'molavi-tab-' + slug;
            const tab = document.createElement('button');
            tab.type = 'button';
            tab.className = 'molavi-admin-tab';
            tab.id = id;
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', id + '-panel');
            tab.setAttribute('aria-selected', 'false');
            tab.tabIndex = -1;
            const tabLabel = node('span', title);
            const badge = node('span', '', 'molavi-tab-count');
            badge.hidden = true;
            tab.append(tabLabel, badge);

            const panel = node('section', null, 'molavi-admin-tabpanel');
            panel.id = id + '-panel';
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', id);
            panel.tabIndex = 0;
            panel.hidden = true;
            const pageUrl = config.pageUrls[slug] || '';
            const fallback = node('a', 'باز کردن این بخش در صفحه جدا', 'button button-secondary');
            fallback.href = pageUrl || '#';
            fallback.target = '_top';
            fallback.hidden = !pageUrl;
            const frame = document.createElement('iframe');
            frame.title = title + ' — محتوای مولوی‌سل';
            frame.loading = 'lazy';
            frame.dataset.src = pageUrl;
            frame.className = 'molavi-admin-tab-frame';
            frame.addEventListener('load', () => {
                try {
                    const doc = frame.contentDocument;
                    if (!doc || !doc.head || !doc.body) throw new Error('frame_unavailable');
                    const expectedPath = new URL(frame.dataset.src, window.location.href).pathname;
                    if (doc.location.pathname !== expectedPath) throw new Error('frame_not_ready');
                    let chrome = doc.getElementById('molavi-embedded-admin-chrome');
                    if (!chrome) {
                        chrome = doc.createElement('style');
                        chrome.id = 'molavi-embedded-admin-chrome';
                        chrome.textContent = '#wpadminbar,#adminmenumain,#wpfooter,#screen-meta,#screen-meta-links{display:none!important}'
                            + '#wpcontent{margin:0!important;padding:0 12px!important}#wpbody{padding-top:0!important}'
                            + '.wrap{margin:0!important}';
                        doc.head.appendChild(chrome);
                    }
                    doc.querySelectorAll('a[href*="page=molavi-operations"]').forEach(link => { link.target = '_top'; });
                    const fit = () => {
                        const height = Math.max(doc.documentElement.scrollHeight, doc.body.scrollHeight, 600);
                        frame.style.height = height + 'px';
                    };
                    fit();
                    if (!frame.dataset.observing) {
                        frame.dataset.observing = '1';
                        new MutationObserver(fit).observe(doc.body, { childList: true, subtree: true, attributes: true });
                        [250, 900, 2000].forEach(delay => window.setTimeout(fit, delay));
                    }
                    fallback.hidden = true;
                } catch (_error) {
                    fallback.hidden = !pageUrl;
                }
            });
            panel.append(fallback, frame);
            tab.addEventListener('click', () => activate(index));
            tab.addEventListener('keydown', event => {
                const rtl = document.documentElement.dir === 'rtl';
                let next = null;
                if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = entries.length - 1;
                else if (event.key === 'ArrowRight') next = (index + (rtl ? -1 : 1) + entries.length) % entries.length;
                else if (event.key === 'ArrowLeft') next = (index + (rtl ? 1 : -1) + entries.length) % entries.length;
                if (next !== null) { event.preventDefault(); activate(next); entries[next].tab.focus(); }
            });
            tabs.appendChild(tab);
            panels.appendChild(panel);
            return { slug, title, id, tab, badge, panel, frame, index };
        });
        function activate(index) {
            entries.forEach((entry, current) => {
                const selected = current === index;
                entry.tab.setAttribute('aria-selected', String(selected));
                entry.tab.tabIndex = selected ? 0 : -1;
                entry.panel.hidden = !selected;
            });
            const active = entries[index];
            if (active.frame.dataset.src && !active.frame.dataset.loaded) {
                active.frame.dataset.loaded = '1';
                active.frame.src = active.frame.dataset.src;
            }
            window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + active.id);
        }
        root.append(actionBoard, tabs, panels, board);
        const initialTab = window.location.hash.replace(/^#molavi-tab-/, '');
        const initialIndex = Math.max(0, entries.findIndex(entry => entry.slug === initialTab));
        activate(initialIndex);

        const formatCount = value => new Intl.NumberFormat('fa-IR').format(value);
        let actionPlanRequest = 0;
        let backlogCard = null;
        function renderActionPlan(ops, queueSummary) {
            const counts = ops.by_state || {};
            const operationsCount = key => {
                const value = ops[key];
                return value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
                    ? null : Math.max(0, Math.floor(Number(value)));
            };
            const queueCount = key => {
                const item = queueSummary && queueSummary[key];
                return !item || item.status !== 'ok' || !Number.isFinite(Number(item.count))
                    ? null : Math.max(0, Math.floor(Number(item.count)));
            };
            const specifications = [
                { key:'publication_holds', slug:'molavi-publication-holds', title:'بررسی توقف‌های انتشار',
                    detail:'این دسته‌ها تا بررسی نتیجه، متوقف مانده‌اند.', count:operationsCount('publication_holds') },
                { key:'sheet_conflicts', slug:'molavi-sheet-conflicts', title:'حل تعارض‌های شیت',
                    detail:'برای هر مورد، مقدار درست را بر اساس منبع انتخاب کنید.', count:operationsCount('sheet_conflicts') },
                { key:'reference_decisions', slug:'molavi-reference-decisions', title:'تصمیم درباره تازگی فهرست مرجع',
                    detail:'تصمیم درباره نسخه قدیمی یا ناموفق، پردازش پیام‌ها را باز می‌کند.', count:queueCount('reference_decisions') },
                { key:'reference_items', slug:'molavi-reference-items', title:'تطبیق پیشنهادها با کالاهای مرجع',
                    detail:'برای هر پیشنهاد، کالای دقیق و مبنای قیمت را بررسی کنید.', count:queueCount('reference_items') },
                { key:'ai_confirmations', slug:'molavi-ai-confirmations', title:'بررسی استخراج محصولات',
                    detail:'قیمت و مشخصات را با متن اصلی تأمین‌کننده بسنجید.', count:queueCount('ai_confirmations') },
                { key:'supplier_prices', slug:'molavi-supplier-prices', title:'بررسی تازگی قیمت تأمین‌کننده',
                    detail:'فقط قیمت‌هایی را تأیید کنید که منبع فعلی‌شان را بررسی کرده‌اید.', count:queueCount('supplier_prices') }
            ];
            const tasks = specifications.filter(item => item.count === null || item.count > 0);
            const failed = Number(counts.failed || 0);
            if (Number.isFinite(failed) && failed > 0) {
                tasks.push({ key:'failed_jobs', title:'بررسی خطاهای پردازش', count:Math.floor(failed),
                    detail:'جزئیات فنی را باز کنید تا خطاهای صف را ببینید.' });
            }
            const publicationIds = Array.isArray(ops.failed_publication_ids) ? ops.failed_publication_ids : [];
            const batchIds = Array.isArray(ops.failed_batch_ids) ? ops.failed_batch_ids : [];
            if (publicationIds.length || batchIds.length) {
                tasks.unshift({ key:'publication_backlog', title:'پاک‌سازی تلاش‌های قدیمی انتشار',
                    count:publicationIds.length + batchIds.length,
                    detail:'یک همگام‌سازی کامل انجام می‌شود؛ فقط کارهایی بسته می‌شوند که نتیجه‌شان تأیید شود.' });
            }
            const tabCounts = {
                'molavi-publication-holds': operationsCount('publication_holds'),
                'molavi-sheet-conflicts': operationsCount('sheet_conflicts'),
                'molavi-reference-decisions': queueCount('reference_decisions'),
                'molavi-reference-items': queueCount('reference_items'),
                'molavi-ai-confirmations': queueCount('ai_confirmations'),
                'molavi-supplier-prices': queueCount('supplier_prices')
            };
            entries.forEach(entry => {
                const count = tabCounts[entry.slug];
                const unavailable = count === null || count === undefined;
                const hasOpenItems = !unavailable && count > 0;
                entry.badge.hidden = !unavailable && !hasOpenItems;
                entry.badge.classList.toggle('is-unavailable', unavailable);
                entry.badge.textContent = unavailable ? '!' : (hasOpenItems ? formatCount(count) : '');
                entry.badge.setAttribute('aria-hidden', 'true');
                entry.tab.setAttribute('aria-label', unavailable
                    ? `${entry.title}، شمارش در دسترس نیست`
                    : (hasOpenItems ? `${entry.title}، ${formatCount(count)} مورد باز` : entry.title));
            });

            actionBoard.replaceChildren(node('h2', 'کار بعدی'));
            if (!tasks.length) {
                const background = Number(counts.queued || 0) + Number(counts.running || 0)
                    + Number(counts.waiting_reference || 0);
                actionBoard.appendChild(node('p', background > 0
                    ? `کار نیازمند بررسی نمایش‌داده‌شده‌ای باقی نمانده است. ${formatCount(background)} پیام در صف پردازش یا منتظر بررسی فهرست مرجع است.`
                    : 'در حال حاضر کاری برای بررسی شما باقی نمانده است.'));
                return;
            }
            const openTask = task => {
                if (task.key === 'publication_backlog' && backlogCard) {
                    board.open = true;
                    backlogCard.scrollIntoView({ behavior:'smooth', block:'center' });
                    return;
                }
                if (task.slug) {
                    const entry = entries.find(item => item.slug === task.slug);
                    if (entry) { activate(entry.index); entry.tab.focus(); }
                    return;
                }
                board.open = true;
                board.scrollIntoView({ behavior:'smooth', block:'start' });
            };
            const primary = tasks[0];
            actionBoard.appendChild(node('h3', primary.count === null
                ? `${primary.title} · شمارش در دسترس نیست`
                : `${primary.title} · ${formatCount(primary.count)} مورد`));
            actionBoard.appendChild(node('p', primary.detail));
            const start = node('button', primary.count === null ? 'بررسی وضعیت' : 'شروع این بررسی', 'button button-primary');
            start.type = 'button';
            start.addEventListener('click', () => openTask(primary));
            actionBoard.appendChild(start);
            if (tasks.length > 1) {
                actionBoard.appendChild(node('h3', 'بعد از آن'));
                const list = node('ol', null, 'molavi-next-action-list');
                tasks.slice(1).forEach(task => {
                    const item = node('li', null, 'molavi-next-action-item');
                    const description = node('span', task.count === null
                        ? `${task.title} · شمارش در دسترس نیست`
                        : `${task.title} · ${formatCount(task.count)} مورد`);
                    item.appendChild(description);
                    const button = node('button', task.slug ? 'باز کردن' : 'جزئیات فنی', 'button button-secondary');
                    button.type = 'button';
                    button.addEventListener('click', () => openTask(task));
                    item.appendChild(button);
                    list.appendChild(item);
                });
                actionBoard.appendChild(list);
            }
        }

        async function refreshActionPlan() {
            const version = ++actionPlanRequest;
            try {
                const [result, summary] = await Promise.all([
                    request(), requestQueueSummary().catch(() => null)
                ]);
                const ops = result.operations || {};
                if (version === actionPlanRequest) renderActionPlan(ops, summary);
                return ops;
            } catch (error) {
                if (version === actionPlanRequest) throw error;
                return {};
            }
        }
        window.addEventListener('message', event => {
            if (event.origin !== window.location.origin
                    || !entries.some(entry => entry.frame.contentWindow === event.source)
                    || !event.data || event.data.type !== 'molavi-owner-workflow-updated') return;
            refreshActionPlan().catch(() => {
                actionBoard.replaceChildren(node('h2', 'کار بعدی'), notice('وضعیت صف‌ها تازه نشد؛ صفحه را دوباره بارگذاری کنید.', 'warning'));
            });
        });
        try {
            const ops = await refreshActionPlan();
            const counts = ops.by_state || {};
            const stateLabels = {queued: 'در صف', running: 'در حال اجرا', waiting_reference: 'انتظار مرجع',
                waiting_publication: 'انتظار رفع توقف انتشار', failed: 'خطا', needs_review: 'نیازمند بررسی'};
            const kindLabels = {batch: 'دسته پیام', reextract: 'بازاستخراج پیام', publish: 'انتشار کالا', order: 'سفارش', notice: 'اعلان'};
            const errorLabels = {RuntimeError: 'خطای اجرا', publication_held_for_review: 'انتظار بررسی توقف انتشار',
                batch_recovery_requires_owner_review: 'نیازمند بررسی دسته'};
            const content = node('div');
            content.appendChild(node('p', `در صف: ${ops.actionable_count || 0} · نیازمند توجه: ${ops.attention_count || 0} · توقف انتشار: ${ops.publication_holds || 0} · تعارض شیت: ${ops.sheet_conflicts || 0}`));
            content.appendChild(node('p', `صف: ${counts.queued || 0} · در حال اجرا: ${counts.running || 0} · انتظار مرجع: ${counts.waiting_reference || 0} · انتظار رفع توقف انتشار: ${counts.waiting_publication || 0} · خطا: ${counts.failed || 0} · نیازمند بررسی: ${counts.needs_review || 0}`));
            const failedPublicationIds = Array.isArray(ops.failed_publication_ids) ? ops.failed_publication_ids : [];
            const failedBatchIds = Array.isArray(ops.failed_batch_ids) ? ops.failed_batch_ids : [];
            backlogCard = null;
            if (failedPublicationIds.length || failedBatchIds.length) {
                backlogCard = node('section', null, 'card');
                backlogCard.appendChild(node('h2', 'پاک‌سازی خطاهای قدیمی انتشار'));
                backlogCard.appendChild(node('p', `${formatCount(failedPublicationIds.length)} تلاش انتشار و ${formatCount(failedBatchIds.length)} رسید دسته با خطای تعارض ثبت شده است.`));
                backlogCard.appendChild(node('p', 'یک بار فهرست فعلی کالاها از شیت در ووکامرس همگام می‌شود. تلاش‌های انتشار فقط پس از موفقیت کامل و ثابت‌ماندن شیت بسته می‌شوند؛ دسته‌ها فقط وقتی بسته می‌شوند که همه پیام‌هایشان در شیت تأیید شود.'));
                const reconcileButton = node('button', 'همگام‌سازی و بستن خطاهای قابل تأیید', 'button button-primary');
                reconcileButton.type = 'button';
                reconcileButton.disabled = !config.reviewStoreUrl || !config.reviewStoreNonce;
                if (reconcileButton.disabled) {
                    backlogCard.appendChild(notice('ثبت رسید در پایگاه داده وردپرس آماده نیست؛ هیچ تغییری ارسال نمی‌شود.', 'warning'));
                }
                reconcileButton.addEventListener('click', async () => {
                    if (!window.confirm('فهرست فعلی شیت یک بار در ووکامرس همگام شود و فقط خطاهای قابل تأیید بسته شوند؟')) return;
                    reconcileButton.disabled = true;
                    const eventId = reviewEventId('publication_backlog', 'current');
                    const context = {
                        failed_publication_ids:failedPublicationIds,
                        failed_batch_ids:failedBatchIds,
                        queue_counts:counts,
                        observed_at:new Date().toISOString()
                    };
                    const decision = {action:'sync_current_catalog_and_reconcile_failed_receipts',
                        note:'Owner authorized one current Sheet-to-Woo catalog sync; reconcile only matching failed receipts.'};
                    const endpoint = new URL('/pipeline/reconcile-publication-backlog', config.apiUrl).toString();
                    try {
                        await persistOwnerReviews([reviewRecord(eventId, 'publication_backlog', 'failed-publication-backlog',
                            'pending', context, decision)]);
                        const receipt = await request('POST', {expected_publish_ids:failedPublicationIds,
                            expected_batch_ids:failedBatchIds}, endpoint);
                        const catalog = receipt.catalog_sync || {};
                        if (!/^[a-f0-9]{64}$/.test(receipt.sheet_digest || '')
                            || receipt.failed_publications_reconciled !== failedPublicationIds.length
                            || !Array.isArray(receipt.failed_publication_ids)
                            || receipt.failed_publication_ids.length !== failedPublicationIds.length
                            || !Array.isArray(receipt.batch_job_ids_reconciled)
                            || !Array.isArray(receipt.batch_job_ids_left_open)
                            || receipt.batch_jobs_reconciled !== receipt.batch_job_ids_reconciled.length
                            || Number(catalog.images_left || 0) !== 0) throw new Error('invalid_receipt');
                        let localWarning = '';
                        try {
                            await persistOwnerReviews([reviewRecord(eventId, 'publication_backlog', 'failed-publication-backlog',
                                'accepted', context, decision, receipt)]);
                        } catch (_error) { localWarning = ' اقدام در سرور انجام شد، اما رسید وردپرس هنوز در انتظار ثبت است.'; }
                        const summary = `همگام‌سازی کامل شد؛ ${formatCount(receipt.failed_publications_reconciled)} خطای انتشار بسته شد و ${formatCount(receipt.batch_jobs_reconciled)} دسته تأییدشده ثبت شد.`
                            + (receipt.batch_job_ids_left_open.length ? ` ${formatCount(receipt.batch_job_ids_left_open.length)} دسته به‌دلیل نبود تطبیق کامل باز ماند.` : '')
                            + localWarning;
                        await dashboard();
                        const freshBoard = root.querySelector('.molavi-next-action');
                        if (freshBoard) freshBoard.prepend(notice(summary, localWarning || receipt.batch_job_ids_left_open.length ? 'warning' : 'success'));
                    } catch (error) {
                        try {
                            await persistOwnerReviews([reviewRecord(eventId, 'publication_backlog', 'failed-publication-backlog',
                                error.remoteRejected ? 'failed' : 'pending', context, decision,
                                {error:String(error.message || 'request_failed'), request_status:error.requestStatus || null})]);
                        } catch (_ignored) {}
                        await dashboard();
                        const freshBoard = root.querySelector('.molavi-next-action');
                        if (freshBoard) freshBoard.prepend(notice(errorText(error.message), 'warning'));
                    }
                });
                backlogCard.appendChild(reconcileButton);
                content.appendChild(backlogCard);
            }
            const items = Array.isArray(ops.items) ? ops.items : [];
            if (!items.length) content.appendChild(node('p', 'کار متوقف‌شده یا خطاداری در صف نیست.'));
            else {
                const table = node('table', null, 'widefat striped');
                const head = node('thead'); const row = node('tr');
                ['شناسه', 'نوع', 'وضعیت', 'تلاش', 'خطا'].forEach(label => row.appendChild(node('th', label)));
                head.appendChild(row); table.appendChild(head);
                const body = node('tbody');
                items.forEach(item => {
                    const line = node('tr');
                    [item.id, kindLabels[item.kind] || item.kind, stateLabels[item.state] || item.state,
                        item.attempts, errorLabels[item.error] || item.error].forEach(value => line.appendChild(node('td', value || '')));
                    body.appendChild(line);
                });
                table.appendChild(body);
                const details = document.createElement('details');
                details.appendChild(node('summary', `نمایش جزئیات کارهای صف (${items.length})`));
                details.appendChild(table);
                if (ops.items_truncated) details.appendChild(notice('همه کارها در این فهرست جا نشده‌اند؛ شمارش بالا تعداد کل صف را نشان می‌دهد.', 'warning'));
                content.appendChild(details);
            }
            const reconciliation = node('section', null, 'card');
            reconciliation.appendChild(node('h2', 'تطبیق رسید تا فروشگاه'));
            reconciliation.appendChild(node('p', 'گزارش فقط‌خواندنی، پیام دریافتی را به وضعیت صف، استخراج، تصمیم، ردیف کالا و وضعیت قابل مشاهده در ووکامرس وصل می‌کند. متن پیام‌ها نمایش داده نمی‌شود.'));
            const reconcile = node('button', 'ساخت گزارش تطبیق', 'button button-secondary');
            reconcile.type = 'button';
            const report = node('div');
            reconcile.addEventListener('click', async () => {
                reconcile.disabled = true;
                report.replaceChildren(node('p', 'در حال خواندن صف، شیت و فروشگاه…'));
                try {
                    const url = new URL('/pipeline/reconciliation?limit=200', config.apiUrl).toString();
                    const data = await request('GET', undefined, url);
                    const summary = data.summary || {};
                    const validation = data.validation || {};
                    const content = node('div');
                    content.appendChild(node('p', `رسیدها: ${summary.receipts || 0} · ردیف‌های پیام در شیت: ${summary.message_data || 0} · پیشنهادهای استخراج‌شده: ${summary.offers || 0} · موارد نیازمند بررسی: ${summary.offers_needing_review || 0} · کالاهای فعلی: ${summary.products || 0}`));
                    content.appendChild(node('p', `رسیدهای با انتشار کامل: ${summary.publication_done || 0} · رسیدهای در انتظار/خطای انتشار: ${summary.publication_pending || 0} · SKU پیدا‌نشده: ${summary.woo_missing || 0} · کالاهای غیرقابل خرید یا توقف ناسازگار: ${summary.woo_blocked || 0}`));
                    const wooLabels = {checked:'بررسی شد',truncated:'بخشی از فروشگاه بررسی شد',unavailable:'فروشگاه پاسخ نداد',not_configured:'نشانی فروشگاه تنظیم نیست'};
                    content.appendChild(node('p', `وضعیت API فروشگاه: ${wooLabels[validation.woo_state] || validation.woo_state || 'نامشخص'} · کالاهای Woo: ${validation.woo_product_count || 0} · رسیدهای نمایش‌داده‌شده: ${validation.receipt_rows_shown || 0}/${validation.total_receipts || 0} · زمان گزارش: ${data.generated_at || ''}`));
                    if (validation.ai_accuracy_note) content.appendChild(notice(validation.ai_accuracy_note, 'info'));
                    const receiptRows = Array.isArray(data.receipts) ? data.receipts : [];
                    const stateLabels = {queued:'در صف',running:'در حال اجرا',waiting_reference:'انتظار تصمیم مرجع',waiting_publication:'انتظار انتشار',failed:'ناموفق',needs_review:'نیازمند بررسی',done:'انجام شد',unbatched:'هنوز دسته‌بندی نشده',not_queued:'هنوز صف انتشار ندارد'};
                    const aiLabels = {pending_ai:'در انتظار هوش مصنوعی',needs_confirmation:'نیازمند تأیید',ai_processed:'استخراج شد',not_product:'کالا نیست',not_in_sheets:'در شیت ثبت نشده'};
                    const wooStateLabels = {purchasable:'قابل خرید',not_visible:'در فروشگاه پیدا نشد',not_purchasable:'نمایش داده شده، خرید بسته است',supplier_out_of_stock:'ناموجود نزد تأمین‌کننده',held:'متوقف و غیرقابل خرید',held_but_purchasable:'هشدار: کالای متوقف قابل خرید است',not_checked:'وضعیت بررسی نشد',not_checked_catalog_truncated:'صفحه‌بندی فروشگاه ناقص بود',no_catalog_projection:'ردیف انتشار در شیت موجود نیست'};
                    if (!receiptRows.length) content.appendChild(node('p', 'رسید دریافتی برای نمایش وجود ندارد.'));
                    else {
                        const table = node('table', null, 'widefat striped');
                        const head = node('thead'); const heading = node('tr');
                        ['شناسه پیام', 'کانال', 'دریافت', 'صف / استخراج', 'پیشنهاد / بررسی', 'کالا / قیمت پایه', 'انتشار / Woo', 'جزئیات ردیابی'].forEach(label => heading.appendChild(node('th', label)));
                        head.appendChild(heading); table.appendChild(head);
                        const body = node('tbody');
                        receiptRows.forEach(item => {
                            const row = node('tr');
                            const productStates = (item.product_statuses || []).map(value => ({imported:'تأییدشده',needs_review:'نیازمند بررسی',not_product:'کالا نیست',skipped:'کنارگذاشته‌شده'}[value] || value));
                            const actualWoo = (item.woo_states || []).map(value => wooStateLabels[value] || value);
                            [item.message_id || '', item.channel || '', item.received_at || 'قدیمی؛ زمان دریافت ثبت نشده',
                                `${stateLabels[item.batch_state] || item.batch_state} · ${aiLabels[item.extraction_state] || item.extraction_state}`,
                                `${item.offers || 0} · ${item.offers_needing_review || 0}`,
                                `${(productStates.join('، ') || 'بدون ردیف فعلی')} · ${item.price_order_checks || 0}/${item.product_count || 0} قیمت تأمین‌کننده پایین‌تر از مصرف`,
                                `${stateLabels[item.publication_state] || item.publication_state} · تلاش ${item.publication_attempts || 0}${item.publication_pushed ? ' · ثبت شیت تأیید شد' : ''}${actualWoo.length ? ' · ' + actualWoo.join('، ') : ''}${item.publication_error ? ' · خطا: ' + item.publication_error : ''}`]
                                .forEach(value => row.appendChild(node('td', value)));
                            const traceCell = node('td');
                            const trace = document.createElement('details');
                            const traceSummary = item.product_details && item.product_details.length
                                ? `نمایش ${item.product_details.length} ردیف کالا`
                                : 'نمایش وضعیت دریافت و صف';
                            trace.appendChild(node('summary', traceSummary));
                            if (item.batch_attempts || item.batch_error) {
                                trace.appendChild(node('p', `تلاش استخراج: ${item.batch_attempts || 0}${item.batch_error ? ' · خطا: ' + item.batch_error : ''}`));
                            }
                            const productDetails = Array.isArray(item.product_details) ? item.product_details : [];
                            if (productDetails.length) {
                                productDetails.forEach(product => {
                                    const productCard = node('section', null, 'molavi-reconcile-product');
                                    productCard.appendChild(node('strong', product.name || product.product_id || 'کالای بدون نام'));
                                    productCard.appendChild(node('p', `وضعیت: ${product.status || 'ثبت‌نشده'} · شناسه: ${product.product_id || 'نامشخص'} · SKU فروشگاه: ${product.woo_sku || 'نامشخص'} · ووکامرس: ${wooStateLabels[product.woo_state] || product.woo_state || 'نامشخص'}`));
                                    productCard.appendChild(node('p', `قیمت تأمین‌کننده: ${amount(product.supplier_price)} · مبنا: ${product.price_basis || 'نامشخص'} · قیمت مصرف: ${amount(product.customer_price)} · تعداد بسته: ${product.package_count || 'نامشخص'}${product.package_price ? ' · قیمت بسته: ' + amount(product.package_price) : ''}`));
                                    if (product.reference_match_id || product.reference_approval_state || product.reference_price_change_percent) {
                                        productCard.appendChild(node('p', `مرجع: ${product.reference_match_id || 'تطبیق ثبت نشده'} · تأیید: ${product.reference_approval_state || 'ثبت نشده'} · تغییر قیمت مرجع: ${product.reference_price_change_percent || 'ثبت نشده'}`));
                                    }
                                    const sourceLink = safeSourceLink(product.source_post_url);
                                    if (sourceLink) productCard.appendChild(sourceLink);
                                    trace.appendChild(productCard);
                                });
                            } else {
                                trace.appendChild(node('p', 'برای این رسید هنوز ردیف کالای فعلی به دست نیامده است.'));
                            }
                            traceCell.appendChild(trace);
                            row.appendChild(traceCell);
                            body.appendChild(row);
                        });
                        table.appendChild(body);
                        content.appendChild(table);
                        if (validation.receipt_rows_truncated) content.appendChild(notice('فقط ۲۰۰ رسید اخیر نمایش داده شد؛ شمار کل رسیدها بالاتر است.', 'warning'));
                        if (validation.state !== 'checked') content.appendChild(notice('این گزارش بخشی است: اتصال یا صفحه‌بندی Woo کامل تأیید نشد. وضعیت‌های ناموجود را به‌عنوان خطای قطعی تفسیر نکنید.', 'warning'));
                    }
                    report.replaceChildren(content);
                } catch (error) {
                    report.replaceChildren(notice(errorText(error.message), 'error'));
                } finally { reconcile.disabled = false; }
            });
            reconciliation.append(reconcile, report);
            content.appendChild(reconciliation);
            board.replaceChildren(node('summary', 'جزئیات فنی صف و گزارش تطبیق'), content);
        } catch (error) {
            board.replaceChildren(node('summary', 'جزئیات فنی صف و گزارش تطبیق'), notice(errorText(error.message), 'error'));
            actionBoard.replaceChildren(node('h2', 'کار بعدی'), notice(errorText(error.message), 'error'));
        }
    }

    function intValue(form, name) {
        const value = form.elements[name].value.trim();
        return value === '' ? '' : Number.parseInt(value, 10);
    }

    const aiDecisionNote = 'مالک مشخصات استخراج‌شده را از پنل بررسی تأیید کرد.';

    function normalizedAiInteger(value) {
        const digits = String(value === null || value === undefined ? '' : value)
            .replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
            .replace(/[٬,\s]/g, '');
        const number = Number(digits);
        return Number.isSafeInteger(number) && number > 0 ? number : '';
    }

    function aiPriceRole(item) {
        const fields = item.decision && item.decision.fields || {};
        const role = String(fields.price_role || item.price_type || item.reference_price_role_hint || '').toLowerCase();
        if (['unit', 'single', 'per_unit'].includes(role)) return 'unit';
        if (['pack', 'package', 'carton'].includes(role)) return 'package';
        return '';
    }

    function aiDecisionFields(item, role) {
        const fields = item.decision && item.decision.fields || {};
        const packageRole = role === 'package';
        const salePrice = normalizedAiInteger(fields.sale_price)
            || normalizedAiInteger(packageRole && item.package_price ? item.package_price : '')
            || normalizedAiInteger(item.sale_price)
            || normalizedAiInteger(item.detected_price);
        const packageCount = normalizedAiInteger(fields.package_count) || normalizedAiInteger(item.package_count);
        if (!salePrice || (packageRole && !packageCount)) return null;
        return {
            price_role: role,
            sale_price: salePrice,
            actual_price: normalizedAiInteger(fields.actual_price ?? item.actual_price),
            package_count: packageCount,
            min_qty: normalizedAiInteger(fields.min_qty ?? item.min_qty),
            stock_status: fields.stock_status || item.stock_status || 'Unknown'
        };
    }

    function aiProductAttributes(item) {
        const title = String(item.product_name || '');
        const variantMatch = title.match(/(?:با\s*)?(?:طعم|مزه)\s*[:：]?\s*([^،,؛;\n]+)/i);
        const inferredVariant = variantMatch
            ? variantMatch[1].split(/\s+(?:قیمت|price|تومان|تومن|ریال|تعداد)\b/i)[0].trim()
            : '';
        const volumeMatch = title.match(/[\d۰-۹٠-٩]+(?:[.,/٫][\d۰-۹٠-٩]+)?\s*(?:میلی\s*[\u200c\s]*لیتر|میلیلیتر|میل|مل|ml|cc|لیتر|ل)(?![\w])/i);
        const packagingMatch = title.match(/تترا[\s\u200c-]*پک|\btetra[\s-]*pack\b|\btetrapak\b/i);
        const explicitPackaging = packagingMatch ? 'تتراپک' : '';
        const modelPackaging = String(item.packaging || '');
        return {
            variationType: String(item.variation_type || inferredVariant).trim(),
            volume: String(item.volume || (volumeMatch && volumeMatch[0].replace(/\s+/g, ' ')) || ''),
            weight: String(item.weight || ''),
            packaging: explicitPackaging && !modelPackaging.includes(explicitPackaging)
                ? [explicitPackaging, modelPackaging].filter(Boolean).join(' · ')
                : modelPackaging || explicitPackaging,
            brand: String(item.brand || ''),
            category: String(item.category || '')
        };
    }

    function aiVariantBaseName(item, attributes = aiProductAttributes(item)) {
        let name = String(item.product_name || '');
        const variant = attributes.variationType;
        if (variant) {
            const escaped = variant.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            name = name.replace(new RegExp(`(?:با\\s*)?(?:طعم|مزه)\\s*[:：]?\\s*${escaped}`, 'iu'), ' ');
            name = name.replace(new RegExp(`(^|[\\s،,])${escaped}(?=$|[\\s،,])`, 'giu'), '$1 ');
        }
        return name.normalize('NFKC').toLowerCase().replace(/[،,؛;:()[\]{}]/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function aiCaseGroupTerms(item) {
        const attributes = aiProductAttributes(item);
        return JSON.stringify([
            item.channel, item.source_channel, item.batch_id, item.message_id,
            item.reason, item.sale_price,
            item.detected_price, item.actual_price, item.package_price,
            item.package_count, item.min_qty, item.price_type, item.currency,
            attributes.packaging, attributes.volume, attributes.weight,
            attributes.brand, attributes.category,
            item.stock_status, Boolean(item.historical_reextract),
            item.reference_match_state, item.reference_match_id,
            item.reference_price_role_hint, item.previous_decision_id
        ]);
    }

    function groupAiVariantCases(cases) {
        const buckets = new Map();
        cases.forEach(item => {
            const attributes = aiProductAttributes(item);
            const source = [item.channel, item.message_id, item.batch_id];
            const hasSupplierPrice = normalizedAiInteger(item.detected_price)
                || normalizedAiInteger(item.sale_price) || normalizedAiInteger(item.package_price);
            const canGroup = hasSupplierPrice && (item.variation_type || attributes.variationType);
            const localKey = canGroup && source.every(Boolean) && aiVariantBaseName(item, attributes)
                ? JSON.stringify([source, aiVariantBaseName(item, attributes), aiCaseGroupTerms(item)]) : '';
            const key = hasSupplierPrice && item.variant_group_id ? `variant:${item.variant_group_id}`
                : localKey ? `local:${localKey}` : `single:${item.case_id}`;
            if (!buckets.has(key)) buckets.set(key, []);
            buckets.get(key).push(item);
        });
        return [...buckets.values()].flatMap(items => {
            if (items.length < 2 || new Set(items.map(item => aiProductAttributes(item).variationType.toLocaleLowerCase())).size !== items.length
                || new Set(items.map(aiCaseGroupTerms)).size !== 1) {
                return items.map(item => [item]);
            }
            return [items];
        });
    }

    async function submitAiCaseDecisions(items, choice, fields, card, controls, sourceVerified = false) {
        if (!items.length) return;
        const note = choice === 'confirm' ? aiDecisionNote : '';
        const requests = items.map(item => {
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, signature: item.signature,
                choice, note, expected_decision_id: item.previous_decision_id || null,
                source_current_verified: Boolean(item.historical_reextract && choice === 'confirm' && sourceVerified),
                fields: choice === 'confirm' ? {...fields} : {}
            };
            return {item, payload, eventId: reviewEventId('ai_confirmation', item.offer_id),
                context: storedReviewContext('ai_confirmation', item)};
        });
        const priorDisabled = controls.map(button => button.disabled);
        controls.forEach(button => { button.disabled = true; });
        try {
            await persistOwnerReviews(requests.map(({item, payload, eventId, context}) => reviewRecord(
                eventId, 'ai_confirmation', item.case_id, 'pending', context,
                {choice, offer_id:item.offer_id, signature:item.signature,
                    expected_decision_id:payload.expected_decision_id, note,
                    source_current_verified:payload.source_current_verified, fields:payload.fields}
            )));
        } catch (_error) {
            errorBox('تصمیم در پایگاه داده وردپرس ذخیره نشد؛ هیچ تغییری برای این موارد ارسال نشد.', card);
            controls.forEach((button, index) => { button.disabled = priorDisabled[index]; });
            return;
        }

        let accepted = 0;
        let failed = 0;
        let localWarning = false;
        let firstError = '';
        for (const {item, payload, eventId, context} of requests) {
            const decision = {choice, offer_id:item.offer_id, signature:item.signature,
                expected_decision_id:payload.expected_decision_id, note,
                source_current_verified:payload.source_current_verified, fields:payload.fields};
            let receipt;
            try {
                receipt = await request('POST', payload);
                if (receipt.case_id !== payload.case_id || receipt.offer_id !== payload.offer_id
                    || receipt.signature !== payload.signature || receipt.choice !== choice
                    || !/^[a-f0-9]{64}$/.test(receipt.decision_id || '')
                    || !['imported', 'needs_review', 'not_product'].includes(receipt.projection_state)
                    || typeof receipt.publish_job !== 'string' || !receipt.publish_job) {
                    throw new Error('invalid_receipt');
                }
            } catch (error) {
                failed += 1;
                firstError = firstError || errorText(error.message);
                const state = error.remoteRejected ? 'failed' : 'pending';
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'ai_confirmation', item.case_id,
                        state, context, decision, {error:String(error.message || 'request_failed'),
                            request_status:error.requestStatus || null})]);
                } catch (_ignored) { localWarning = true; }
                continue;
            }
            accepted += 1;
            try {
                await persistOwnerReviews([reviewRecord(eventId, 'ai_confirmation', item.case_id,
                    'accepted', context, decision, receipt)]);
            } catch (_error) { localWarning = true; }
        }
        const label = choice === 'confirm' ? 'تأیید شد' : 'به‌عنوان «کالا نیست» ثبت شد';
        const summary = `${accepted} از ${items.length} مورد ${label}${failed ? `؛ ${failed} مورد ناموفق ماند${firstError ? ` (${firstError})` : ''}` : ''}${localWarning ? ' رسید محلی وردپرس به همگام‌سازی نیاز دارد.' : ''}`;
        await aiConfirmations(summary, failed || localWarning ? 'warning' : 'success');
    }

    function aiCaseCard(item, onSelect = null, contextStored = true, decisionsEnabled = true, relatedItems = [item]) {
        const card = node('section', null, 'card');
        if (relatedItems.length > 1) card.classList.add('molavi-ai-variant-group');
        if (typeof onSelect === 'function') {
            const selection = node('label', null, 'molavi-ai-case-select');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.disabled = !contextStored;
            checkbox.addEventListener('change', () => relatedItems.forEach(row => onSelect(row, checkbox.checked, checkbox)));
            selection.append(checkbox, document.createTextNode(' انتخاب برای رد گروهی'));
            card.appendChild(selection);
        }
        card.appendChild(node('h2', item.product_name || 'کالای بدون نام'));
        const extractedAttributes = [];
        const displayAttributes = aiProductAttributes(item);
        if (relatedItems.length === 1 && displayAttributes.variationType) extractedAttributes.push(`طعم/مدل: ${displayAttributes.variationType}`);
        if (displayAttributes.volume) extractedAttributes.push(`حجم: ${displayAttributes.volume}`);
        if (displayAttributes.weight) extractedAttributes.push(`وزن: ${displayAttributes.weight}`);
        if (displayAttributes.packaging) extractedAttributes.push(`بسته‌بندی: ${displayAttributes.packaging}`);
        if (displayAttributes.brand) extractedAttributes.push(`برند: ${displayAttributes.brand}`);
        if (displayAttributes.category) extractedAttributes.push(`دسته: ${displayAttributes.category}`);
        if (extractedAttributes.length) {
            const attributes = node('div', null, 'molavi-ai-product-attributes');
            extractedAttributes.forEach(value => attributes.appendChild(node('span', value)));
            card.appendChild(attributes);
        }
        if (relatedItems.length > 1) {
            card.appendChild(node('p', `انواع هم‌قیمت از یک پیام (${relatedItems.length} مورد)`));
            const variants = node('ul', null, 'molavi-ai-variant-list');
            relatedItems.forEach(row => variants.appendChild(node('li', `${aiProductAttributes(row).variationType || row.product_name || 'نوع نامشخص'}${row.product_name && row.product_name !== item.product_name ? ` · ${row.product_name}` : ''}`)));
            card.appendChild(variants);
        }
        const roleLabels = {unit: 'هر واحد', single: 'هر واحد', per_unit: 'هر واحد',
            pack: 'هر بسته', package: 'هر بسته', carton: 'هر کارتن'};
        const roleLabel = roleLabels[String(item.price_type || '').toLowerCase()]
            || 'مبنای هر واحد یا بسته هنوز مشخص نیست';
        const detectedSupplierPrice = normalizedAiInteger(item.detected_price) || normalizedAiInteger(item.sale_price);
        const packagePrice = normalizedAiInteger(item.package_price);
        const visibleSupplierPrice = detectedSupplierPrice || packagePrice;
        card.appendChild(node('p', `تأمین‌کننده: ${item.channel || 'نامشخص'} · مبلغ فروش تأمین‌کننده: ${visibleSupplierPrice ? amount(visibleSupplierPrice) : 'در پیام پیدا نشد'} · مبنا: ${roleLabel}`));
        card.appendChild(node('p', `دلیل بررسی: ${item.reason || 'نیازمند بررسی'} · حداقل خرید: ${item.min_qty || 'ثبت نشده'}`));
        if (item.message_timestamp) card.appendChild(node('p', `زمان پیام منبع: ${item.message_timestamp}`));
        const originalPost = safeSourceLink(item.source_post_url);
        if (originalPost) card.appendChild(originalPost);
        if (item.historical_reextract) card.appendChild(notice('این پیشنهاد از بازاستخراج یک پیام قبلی آمده است. پیش از تأیید، مطمئن شوید قیمت و موجودی هنوز معتبرند.', 'warning'));
        if (item.original_message) {
            const details = document.createElement('details'); details.appendChild(node('summary', 'متن اصلی پیام تأمین‌کننده'));
            details.appendChild(node('pre', item.original_message)); card.appendChild(details);
        }
        if (item.decision) {
            const choiceLabels = {confirm:'تأیید کالا',not_product:'کالا نیست'};
            card.appendChild(node('p', `آخرین تصمیم: ${choiceLabels[item.decision.choice] || item.decision.choice || ''} · ${item.decision.note || ''}`));
            if (item.decision.choice === 'not_product') {
                card.appendChild(notice('این ردّ قابل بازگشت است: مشخصات را اصلاح کنید و با ثبت یادداشت، کالا را دوباره تأیید کنید. تصمیم قبلی در تاریخچه می‌ماند.', 'warning'));
            }
        }
        if (Array.isArray(item.decision_history) && item.decision_history.length) {
            const history = document.createElement('details');
            history.appendChild(node('summary', `تاریخچه تصمیم‌ها (${item.decision_history.length})`));
            const list = document.createElement('ol');
            item.decision_history.forEach(decision => {
                const choice = {confirm:'تأیید کالا',not_product:'کالا نیست'}[decision.choice] || decision.choice || 'تصمیم';
                const stamp = Number(decision.created_at) > 0
                    ? new Date(Number(decision.created_at) * 1000).toLocaleString('fa-IR') : '';
                list.appendChild(node('li', `${stamp} · ${choice} · ${decision.actor_id || ''} · ${decision.note || ''}`));
            });
            history.appendChild(list);
            card.appendChild(history);
        }
        const fields = item.decision && item.decision.fields || {};
        const role = aiPriceRole(item);
        const isPackage = ['pack', 'package', 'carton'].includes(String(role).toLowerCase());
        const price = fields.sale_price ?? (isPackage && item.package_price ? item.package_price : (item.sale_price || item.detected_price || ''));
        const quickActions = node('div', null, 'molavi-ai-quick-actions');
        const quickButtons = [];
        let currentPriceVerified = null;
        if (item.historical_reextract) {
            const checkLabel = node('label', null, 'molavi-reextract-current-check');
            currentPriceVerified = document.createElement('input');
            currentPriceVerified.type = 'checkbox';
            checkLabel.append(currentPriceVerified, document.createTextNode(' قیمت و موجودی را از منبع فعلی دوباره بررسی کردم.'));
            card.appendChild(checkLabel);
        }
        const addQuickConfirm = (selectedRole, label) => {
            const selectedFields = aiDecisionFields(item, selectedRole);
            const button = node('button', label, 'button button-primary');
            button.type = 'button';
            button.dataset.priceRole = selectedRole;
            button.disabled = !contextStored || !decisionsEnabled || !selectedFields
                || Boolean(item.historical_reextract && !currentPriceVerified.checked);
            button.addEventListener('click', () => submitAiCaseDecisions(relatedItems, 'confirm', selectedFields,
                card, quickButtons, !item.historical_reextract || currentPriceVerified.checked));
            quickActions.appendChild(button);
            quickButtons.push(button);
            return button;
        };
        const quickRole = role || (item.decision && item.decision.fields && item.decision.fields.price_role) || '';
        if (quickRole) {
            const quickLabel = relatedItems.length > 1
                ? `تأیید هر ${relatedItems.length} نوع با قیمت ${amount(aiDecisionFields(item, quickRole)?.sale_price || price)} · ${quickRole === 'package' ? 'بسته' : 'واحد'}`
                : `تأیید با همین مشخصات · ${quickRole === 'package' ? 'قیمت بسته' : 'قیمت واحد'}`;
            addQuickConfirm(quickRole, quickLabel);
        } else {
            addQuickConfirm('unit', `تأیید ${relatedItems.length > 1 ? 'همه انواع' : 'کالا'} به‌عنوان قیمت هر واحد`);
            const packageButton = addQuickConfirm('package', `تأیید ${relatedItems.length > 1 ? 'همه انواع' : 'کالا'} به‌عنوان قیمت بسته`);
            if (!aiDecisionFields(item, 'package')) {
                packageButton.disabled = true;
                packageButton.title = 'تعداد داخل بسته در داده استخراج‌شده موجود نیست.';
            }
        }
        if (currentPriceVerified) currentPriceVerified.addEventListener('change', () => {
            quickButtons.forEach(button => {
                if (button.dataset.choice === 'not_product') {
                    button.disabled = !contextStored || !decisionsEnabled;
                    return;
                }
                button.disabled = !contextStored || !decisionsEnabled
                    || !aiDecisionFields(item, button.dataset.priceRole)
                    || !currentPriceVerified.checked;
            });
        });
        const notProduct = node('button', relatedItems.length > 1
            ? `ردّ هر ${relatedItems.length} نوع؛ کالا نیست` : 'این مورد کالا نیست', 'button');
        notProduct.type = 'button';
        notProduct.dataset.choice = 'not_product';
        notProduct.disabled = !contextStored || !decisionsEnabled;
        notProduct.addEventListener('click', () => submitAiCaseDecisions(relatedItems, 'not_product', {}, card,
            quickButtons));
        quickActions.appendChild(notProduct);
        quickButtons.push(notProduct);
        card.appendChild(quickActions);

        const form = document.createElement('form');
        const row1 = node('p');
        row1.appendChild(field('نقش قیمت', 'price_role', role === 'unit' ? 'unit' : (isPackage ? 'package' : ''), 'select', {
            required: true, choices: [['', 'انتخاب نقش قیمت'], ['unit', 'قیمت هر واحد'], ['package', 'قیمت کل بسته / کارتن']]
        }));
        row1.appendChild(field('قیمت تأییدشده به تومان', 'sale_price', price, 'number', { required: true, min: 1, step: 1 }));
        form.appendChild(row1);
        const row2 = node('p');
        row2.appendChild(field('قیمت مصرف‌کننده (اختیاری)', 'actual_price', fields.actual_price ?? item.actual_price ?? '', 'number', { min: 1, step: 1 }));
        row2.appendChild(field('تعداد داخل بسته', 'package_count', fields.package_count ?? item.package_count ?? '', 'number', { min: 1, step: 1 }));
        row2.appendChild(field('حداقل خرید', 'min_qty', fields.min_qty ?? item.min_qty ?? '', 'number', { min: 1, step: 1 }));
        form.appendChild(row2);
        form.appendChild(field('وضعیت موجودی تأمین‌کننده', 'stock_status', fields.stock_status || item.stock_status || 'Unknown', 'select', {
            choices: [['Available', 'موجود'], ['Out of Stock', 'ناموجود'], ['Unknown', 'نامشخص']]
        }));
        const edit = document.createElement('details');
        edit.className = 'molavi-ai-manual-edit';
        edit.appendChild(node('summary', 'اصلاح دستی؛ فقط اگر داده استخراج‌شده اشتباه است'));
        const confirmLabel = item.decision && item.decision.choice === 'not_product'
            ? 'بازگردانی به کالا و تأیید مشخصات'
            : item.decision ? 'ثبت اصلاح و تأیید دوباره' : 'تأیید مشخصات استخراج‌شده';
        const confirm = node('button', confirmLabel, 'button button-primary');
        confirm.type = 'submit'; confirm.value = 'confirm';
        confirm.disabled = !contextStored || !decisionsEnabled;
        confirm.textContent = relatedItems.length > 1 ? `ثبت اصلاح برای هر ${relatedItems.length} نوع` : confirmLabel;
        form.append(confirm);
        if (visibleSupplierPrice) {
            edit.appendChild(form);
            card.appendChild(edit);
        } else {
            card.appendChild(notice('قیمت تأمین‌کننده در پیام وجود ندارد؛ حجم و طعم مشخص است، اما بدون مبلغ نمی‌توان این پیشنهاد را تأیید یا منتشر کرد. این مورد تا رسیدن قیمت باز می‌ماند.', 'warning'));
        }
        if (visibleSupplierPrice) form.addEventListener('submit', async event => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            if (item.historical_reextract && !currentPriceVerified.checked) {
                errorBox('پیش از تأیید، تازگی قیمت و موجودی را در منبع فعلی بررسی کنید.', card);
                return;
            }
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, signature: item.signature,
                choice: 'confirm', note: aiDecisionNote, expected_decision_id: item.previous_decision_id || null,
                source_current_verified: Boolean(item.historical_reextract && currentPriceVerified.checked),
                fields: {
                    price_role: form.elements.price_role.value,
                    sale_price: intValue(form, 'sale_price'),
                    actual_price: intValue(form, 'actual_price'),
                    package_count: intValue(form, 'package_count'),
                    min_qty: intValue(form, 'min_qty'),
                    stock_status: form.elements.stock_status.value
                }
            };
            await submitAiCaseDecisions(relatedItems, 'confirm', payload.fields, card,
                [confirm, ...quickButtons], payload.source_current_verified);
        });
        return card;
    }

    function reextractionUrl(action, runId = '') {
        const params = new URLSearchParams({ reextract: action });
        if (runId) params.set('run_id', runId);
        return config.apiUrl + '?' + params.toString();
    }

    async function showReextractionProgress(panel, runId) {
        if (!root.contains(panel)) return;
        try {
            const status = await request('GET', undefined, reextractionUrl('run', runId));
            const counts = status.counts || {};
            const finished = Number(status.completed || 0);
            const total = Number(status.total || 0);
            const box = notice(`بازاستخراج پیام‌ها: ${finished} از ${total} · در صف ${counts.queued || 0} · در حال اجرا ${counts.running || 0} · پایان‌یافته ${counts.done || 0} · لغوشده ${counts.cancelled || 0} · ناموفق ${counts.failed || 0}`, status.state === 'complete' ? (counts.failed ? 'warning' : 'success') : 'info');
            panel.querySelector('.molavi-reextract-progress')?.replaceWith(box);
            box.classList.add('molavi-reextract-progress');
            if (status.state === 'complete') {
                const outcomes = status.outcomes || {};
                box.appendChild(node('p', `پیشنهادهای آماده بررسی: ${status.staged_offers || 0} · پیام بدون کالای قابل استخراج: ${outcomes.no_products || 0}`));
                if (outcomes.source_changed) box.appendChild(node('p', `${outcomes.source_changed} پیام پس از پیش‌نمایش تغییر کرده یا چند نسخه مبهم داشته؛ نتیجه‌ای برای آن‌ها ثبت نشد. پیش‌نمایش را تازه کنید و دوباره اجرا کنید.`, 'notice'));
                if (counts.cancelled) box.appendChild(node('p', 'کارهای در صف لغو شدند؛ پیامِ در حال اجرا ممکن است کامل شده باشد.'));
                box.appendChild(node('p', 'نتیجه‌ها به‌صورت پیشنهاد بررسی ثبت شده‌اند؛ برای تصمیم درباره هر مورد، همین فهرست را پایین صفحه ببینید.'));
                if (counts.failed) box.appendChild(node('p', 'پیام‌های ناموفق را پس از بررسی صف می‌توانید با اجرای دوباره گروه مربوط پردازش کنید.'));
                setTimeout(() => aiConfirmations(counts.failed ? 'بازاستخراج تمام شد؛ بخشی از پیام‌ها ناموفق ماند.' : 'بازاستخراج تمام شد؛ پیشنهادها برای بررسی مالک آماده‌اند.', counts.failed ? 'warning' : 'success'), 1200);
                return;
            }
            const cancel = node('button', 'توقف پیام‌های باقی‌مانده', 'button');
            cancel.type = 'button';
            cancel.addEventListener('click', async () => {
                if (!window.confirm('پیام‌های در صف لغو می‌شوند؛ پیام در حال اجرا ممکن است کامل شود. ادامه می‌دهید؟')) return;
                cancel.disabled = true;
                try {
                    const receipt=await request('POST',{action:'cancel_reextraction',run_id:runId});
                    box.appendChild(node('p', `لغو شد: ${receipt.cancelled || 0} پیام در صف · ${receipt.running || 0} پیام در حال اجرا`));
                } catch (error) {
                    box.appendChild(notice(errorText(error.message),'error'));
                    cancel.disabled=false;
                }
            });
            box.appendChild(cancel);
            setTimeout(() => showReextractionProgress(panel, runId), 3500);
        } catch (error) {
            panel.querySelector('.molavi-reextract-progress')?.replaceWith(notice(errorText(error.message), 'error'));
        }
    }

    async function reextractionPanel(panel) {
        panel.className = 'card molavi-reextract-panel';
        panel.appendChild(node('h2', 'بازاستخراج پیام‌های ذخیره‌شده'));
        panel.appendChild(node('p', 'پیام‌های قبلی را با قواعد فعلی استخراج دوباره پردازش کنید. خروجی فقط به پیشنهادهای نیازمند بررسی مالک افزوده می‌شود؛ تا تأیید موردی، فهرست فعلی کالاها و انتشار تغییر نمی‌کند.'));
        try {
            const preview = await request('GET', undefined, reextractionUrl('preview'));
            const counts = preview.counts || {};
            const supportsAiOnly = typeof preview.ai_configured === 'boolean';
            const aiConfigured = supportsAiOnly && preview.ai_configured;
            panel.appendChild(node('p', `در صف بررسی: ${counts.pending || 0} پیام · همه پیام‌های متنی آماده: ${counts.all_available || 0}`));
            panel.appendChild(node('p', `پیام‌های بدون متن: ${counts.without_text || 0} · تصمیم‌های مالک حفظ‌شده: ${counts.owner_decisions_preserved || 0} · موارد «کالا نیست» حفظ‌شده: ${counts.not_product_preserved || 0} · ردشده توسط مالک: ${counts.dismissed_by_owner || 0} · قبلاً بازاستخراج‌شده و هنوز در بررسی: ${counts.already_staged || 0}`));
            if (!supportsAiOnly) {
                panel.appendChild(notice('نسخه سرویس سرور هنوز از استخراج فقط با هوش مصنوعی پشتیبانی نمی‌کند؛ ابتدا سرور را به‌روزرسانی کنید.', 'error'));
            } else if (!aiConfigured) {
                panel.appendChild(notice('استخراج کالا فقط با هوش مصنوعی انجام می‌شود. کلید GOOGLE_API_KEY در تنظیمات سرویس اجرا تنظیم نشده است.', 'error'));
            }
            if (preview.active_run) {
                const progress = notice('در حال آماده‌سازی وضعیت بازاستخراج…', 'info');
                progress.classList.add('molavi-reextract-progress');
                panel.appendChild(progress);
                showReextractionProgress(panel, preview.active_run.run_id);
                return;
            }
            const controls = node('div', null, 'molavi-reextract-actions');
            const pendingButton = node('button', `بازاستخراج پیام‌های در صف بررسی (${counts.pending || 0})`, 'button button-primary');
            const allButton = node('button', `بازاستخراج همه پیام‌های ذخیره‌شده (${counts.all_available || 0})`, 'button');
            const dismissButton = node('button', `ردّ گروهی پیام‌های منتظر (${counts.pending || 0})`, 'button');
            pendingButton.type = 'button'; allButton.type = 'button'; dismissButton.type = 'button';
            pendingButton.disabled = !aiConfigured || !(counts.pending > 0);
            allButton.disabled = !aiConfigured || !(counts.all_available > 0);
            dismissButton.disabled = !supportsAiOnly || !(counts.pending > 0);
            async function start(button, scope, count) {
                const label = scope === 'all' ? 'همه پیام‌های متنی ذخیره‌شده و فاقد تصمیم نهایی مالک' : 'پیام‌هایی که هنوز در صف بررسی یا پردازش هستند';
                const warning = `قرار است ${count} پیام از ${label} با قواعد فعلی دوباره پردازش شوند. هر خروجی به‌صورت پیشنهاد نیازمند بررسی ثبت می‌شود و هیچ کالایی خودکار منتشر نمی‌شود. ادامه می‌دهید؟`;
                if (!window.confirm(warning)) return;
                pendingButton.disabled = true; allButton.disabled = true;
                try {
                    const receipt = await request('POST', {action: 'start_reextraction', scope, expected_count: count});
                    if (!/^[a-f0-9]{32}$/.test(receipt.run_id || '') || receipt.queued !== count) throw new Error('invalid_receipt');
                    const progress = notice('در صف بازاستخراج قرار گرفت…', 'info');
                    progress.classList.add('molavi-reextract-progress');
                    controls.replaceWith(progress);
                    showReextractionProgress(panel, receipt.run_id);
                } catch (error) {
                    panel.appendChild(notice(errorText(error.message), 'error'));
                    pendingButton.disabled = !(counts.pending > 0);
                    allButton.disabled = !(counts.all_available > 0);
                }
            }
            pendingButton.addEventListener('click', () => start(pendingButton, 'pending', counts.pending));
            allButton.addEventListener('click', () => start(allButton, 'all', counts.all_available));
            dismissButton.addEventListener('click', async () => {
                const count = Number(counts.pending || 0);
                if (!count || !window.confirm(`قرار است همه ${count} پیامِ در صف از بازاستخراج کنار گذاشته شوند. متن اصلی پیام‌ها حذف نمی‌شود و یادداشت اجباری نیست. ادامه می‌دهید؟`)) return;
                dismissButton.disabled = true;
                try {
                    const receipt = await request('POST', {
                        action: 'dismiss_pending_messages', expected_count: count,
                        expected_digest: preview.pending_digest, note: ''
                    });
                    if (receipt.dismissed_count !== count) throw new Error('invalid_receipt');
                    await aiConfirmations(`${receipt.dismissed_count} پیام از صف بازاستخراج کنار گذاشته شد.`, 'success');
                } catch (error) {
                    panel.appendChild(notice(errorText(error.message), 'error'));
                    dismissButton.disabled = !(counts.pending > 0);
                }
            });
            controls.append(pendingButton, allButton, dismissButton);
            panel.appendChild(controls);
        } catch (error) {
            panel.appendChild(notice(errorText(error.message), 'error'));
        }
    }

    async function aiConfirmations(message = '', messageKind = 'info') {
        shell('تأیید استخراج کالا', 'مشخصات نامطمئن را با متن پیام اصلی بررسی کنید. تا ثبت تصمیم مالک، این پیشنهادها در فروشگاه منتشر نمی‌شوند.');
        if (message) root.appendChild(notice(message, messageKind));
        const reextract = node('section');
        root.appendChild(reextract);
        await reextractionPanel(reextract);
        try {
            const data = await request();
            if (data.publication_held) root.appendChild(notice('توقف ایمنی انتشار فعال است؛ ابتدا آن را در بخش انتشار و بازیابی بررسی کنید.', 'warning'));
            const openCases = (data.cases || []).filter(item => item && item.case_id);
            const recentCases = (data.recent_decisions || []).filter(item => item && item.case_id);
            const allCases = [...new Map([...openCases, ...recentCases]
                .map(item => [item.case_id, item])).values()];
            let contextStored = true;
            if (allCases.length) {
                try {
                    await persistOwnerReviews(allCases.map(item => reviewRecord(
                        `context:ai_confirmation:${item.case_id}`, 'ai_confirmation', item.case_id,
                        'observed', storedReviewContext('ai_confirmation', item)
                    )));
                } catch (_error) {
                    contextStored = false;
                    root.appendChild(notice('موارد برای بررسی نمایش داده می‌شوند، اما ذخیرهٔ زمینهٔ آن‌ها در پایگاه داده وردپرس تأیید نشد؛ تا رفع خطا، تصمیم تازه‌ای ارسال نمی‌شود.', 'error'));
                }
            }
            const decisionsEnabled = contextStored && !Boolean(data.publication_held);
            const selected = new Map();
            const caseCheckboxes = new Map();
            if (openCases.length) {
                const toolbar = node('div', null, 'molavi-bulk-dismiss');
                const selectAllLabel = node('label', null, 'molavi-bulk-select-all');
                const selectAll = document.createElement('input');
                selectAll.type = 'checkbox';
                selectAll.disabled = !contextStored;
                selectAllLabel.append(selectAll, document.createTextNode(' انتخاب همه موارد باز'));
                const count = node('span', 'انتخاب‌شده: ۰');
                const dismiss = node('button', 'ردّ انتخاب‌شده‌ها به‌عنوان «کالا نیست»', 'button');
                const preview = node('div', null, 'molavi-bulk-preview');
                dismiss.type = 'button';
                dismiss.disabled = true;
                toolbar.append(selectAllLabel, count, dismiss, preview);
                root.appendChild(toolbar);
                const updateSelection = () => {
                    const selectedCount = selected.size;
                    count.textContent = `انتخاب‌شده: ${new Intl.NumberFormat('fa-IR').format(selectedCount)}`;
                    dismiss.disabled = !selectedCount || !decisionsEnabled;
                    selectAll.checked = selectedCount === openCases.length;
                    selectAll.indeterminate = selectedCount > 0 && selectedCount < openCases.length;
                    preview.replaceChildren();
                    if (!selectedCount) {
                        preview.appendChild(node('p', 'برای پیش‌نمایش پیام‌ها و اثر تصمیم، موردها را انتخاب کنید.'));
                        return;
                    }
                    preview.appendChild(node('strong', `پیش‌نمایش ${selectedCount} مورد انتخاب‌شده`));
                    const list = document.createElement('ul');
                    [...selected.values()].forEach(item => {
                        const previewRoleLabels = {unit:'هر واحد',single:'هر واحد',per_unit:'هر واحد',pack:'هر بسته',package:'هر بسته',carton:'هر کارتن'};
                        const role = previewRoleLabels[String(item.price_type || '').toLowerCase()] || 'مبنای قیمت نامشخص';
                        const reference = item.reference_match_id
                            ? `${item.reference_match_state || 'تطبیق ثبت‌شده'} (${item.reference_match_id})`
                            : item.reference_match_state || 'بدون تطبیق مرجع ثبت‌شده';
                        list.appendChild(node('li', `${item.product_name || 'کالای بدون نام'} · ${item.channel || 'کانال نامشخص'} · ${amount(item.detected_price || item.sale_price)} · ${role} · مرجع: ${reference}`));
                    });
                    preview.appendChild(list);
                    preview.appendChild(node('p', 'اثر تصمیم: قیمت یا متن پیام تغییر نمی‌کند؛ پیشنهاد به «کالا نیست» تبدیل می‌شود، فهرست کالا دوباره ساخته و همگام‌سازی فروشگاه در صف قرار می‌گیرد. برای بازگردانی، از تاریخچه همان مورد استفاده کنید.'));
                };
                selectAll.addEventListener('change', () => {
                    selected.clear();
                    openCases.forEach(item => {
                        const checkbox = caseCheckboxes.get(item.case_id);
                        if (checkbox) checkbox.checked = selectAll.checked;
                        if (selectAll.checked) selected.set(item.case_id, item);
                    });
                    updateSelection();
                });
                dismiss.addEventListener('click', async () => {
                    const items = [...selected.values()];
                    if (!items.length || !decisionsEnabled || !window.confirm(`پیش‌نمایش تأیید نهایی\n\n${items.length} پیشنهاد به‌عنوان «کالا نیست» ثبت می‌شود. هیچ مبلغی تأیید یا ویرایش نمی‌شود و پیام اصلی حذف نخواهد شد. فهرست کالا بازسازی و انتشار در صف قرار می‌گیرد. تصمیم هر مورد در تاریخچه می‌ماند و قابل بازبینی است. ادامه می‌دهید؟`)) return;
                    dismiss.disabled = true;
                    const eventIds = new Map(items.map(item => [item.case_id,
                        reviewEventId('ai_confirmation', item.offer_id)]));
                    const pendingRecords = items.map(item => reviewRecord(eventIds.get(item.case_id),
                        'ai_confirmation', item.case_id, 'pending',
                        storedReviewContext('ai_confirmation', item),
                        {choice:'not_product', offer_id:item.offer_id, signature:item.signature,
                            expected_decision_id:item.previous_decision_id || null, note:''}));
                    try {
                        await persistOwnerReviews(pendingRecords);
                    } catch (_error) {
                        root.appendChild(notice('جزئیات تصمیم‌ها در وردپرس ذخیره نشد؛ درخواست رد گروهی ارسال نشد.', 'error'));
                        updateSelection();
                        return;
                    }
                    try {
                        const receipt = await request('POST', {action:'dismiss_cases',note:'',cases:items.map(item => ({
                            case_id:item.case_id, offer_id:item.offer_id, signature:item.signature,
                            expected_decision_id:item.previous_decision_id || null
                        }))});
                        if (receipt.requested !== items.length || !Array.isArray(receipt.failed)
                            || !Array.isArray(receipt.dismissed) || receipt.dismissed_count !== receipt.dismissed.length) {
                            throw new Error('invalid_receipt');
                        }
                        const failedCount = receipt.failed.length;
                        const dismissedById = new Map((receipt.dismissed || []).map(item => [item.case_id, item]));
                        const failedById = new Map(receipt.failed.map(item => [item.case_id, item]));
                        const completedIds = new Set([...dismissedById.keys(), ...failedById.keys()]);
                        if (dismissedById.size !== receipt.dismissed.length
                            || failedById.size !== receipt.failed.length
                            || [...dismissedById.keys()].some(id => failedById.has(id))
                            || completedIds.size !== items.length
                            || items.some(item => !completedIds.has(item.case_id))
                            || receipt.dismissed.some(item => !/^[a-f0-9]{64}$/.test(item.decision_id || '')
                                || typeof item.publish_job !== 'string' || !item.publish_job)) {
                            throw new Error('invalid_receipt');
                        }
                        let localWarning = '';
                        try {
                            await persistOwnerReviews(items.map(item => {
                                const dismissedItem = dismissedById.get(item.case_id);
                                const failedItem = failedById.get(item.case_id);
                                return reviewRecord(eventIds.get(item.case_id), 'ai_confirmation',
                                    item.case_id, dismissedItem ? 'accepted' : 'failed',
                                    storedReviewContext('ai_confirmation', item),
                                    {choice:'not_product', offer_id:item.offer_id, signature:item.signature,
                                        expected_decision_id:item.previous_decision_id || null, note:''},
                                    dismissedItem || {error:(failedItem && failedItem.error) || 'dismiss_failed'});
                            }));
                        } catch (_error) { localWarning = ' رسید تصمیم‌ها در وردپرس به‌روزرسانی نشد و در وضعیت محلی در انتظار همگام‌سازی است.'; }
                        const summary = `${receipt.dismissed_count || 0} مورد رد شد${failedCount ? `؛ ${failedCount} مورد ناموفق ماند` : ''}.${localWarning}`;
                        await aiConfirmations(summary, failedCount || localWarning ? 'warning' : 'success');
                    } catch (error) {
                        const state = error.remoteRejected ? 'failed' : 'pending';
                        try {
                            await persistOwnerReviews(items.map(item => reviewRecord(eventIds.get(item.case_id),
                                'ai_confirmation', item.case_id, state,
                                storedReviewContext('ai_confirmation', item),
                                {choice:'not_product', offer_id:item.offer_id, signature:item.signature,
                                    expected_decision_id:item.previous_decision_id || null, note:''},
                                {error:String(error.message || 'request_failed'), request_status:error.requestStatus || null})));
                        } catch (_ignored) {}
                        root.appendChild(notice(errorText(error.message), 'error'));
                        updateSelection();
                    }
                });
                groupAiVariantCases(openCases).forEach(items => root.appendChild(aiCaseCard(items[0], (row, checked, checkbox) => {
                    caseCheckboxes.set(row.case_id, checkbox);
                    if (checked) selected.set(row.case_id, row);
                    else selected.delete(row.case_id);
                    updateSelection();
                }, contextStored, decisionsEnabled, items)));
            }
            const seenCaseIds = new Set(openCases.map(item => item.case_id));
            recentCases.filter(item => !seenCaseIds.has(item.case_id))
                .forEach(item => root.appendChild(aiCaseCard(item, null, contextStored, decisionsEnabled)));
            if (!openCases.length && !recentCases.length) root.appendChild(node('p', 'موردی برای بررسی وجود ندارد.'));
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    function referenceItemCard(item, contextStored = true) {
        const card = node('section', null, 'card');
        card.appendChild(node('h2', item.product_name || 'کالای بدون نام'));
        card.appendChild(node('p', `تأمین‌کننده: ${item.channel || ''} · قیمت ثبت‌شده: ${amount(item.supplier_price)}`));
        card.appendChild(node('p', `دلیل بررسی: ${item.reason || ''} · نقش پیشنهادی: ${item.price_role_hint || 'نیازمند بررسی'}`));
        if (item.reference_update) {
            const percent = Number(item.reference_update.change_percent);
            card.appendChild(notice(`تغییر قیمت مرجع: ${Number.isFinite(percent) ? percent.toLocaleString('fa-IR') + '٪' : 'نامشخص'}؛ برای به‌روزرسانی، پیام منبع را بازبینی کنید.`, 'warning'));
        }
        const originalPost = safeSourceLink(item.source_post_url);
        if (originalPost) card.appendChild(originalPost);
        if (item.decision) card.appendChild(node('p', `تصمیم فعلی: ${item.decision.choice || ''} · مرجع: ${item.decision.reference_id || 'ندارد'} · نقش: ${item.decision.price_role || ''} · ${item.decision.note || ''}`));
        const form = document.createElement('form');
        const candidateRows = (item.candidates || []).filter(row => row && row.reference_id);
        const currentReference = item.decision && item.decision.reference_id
            || (item.reference_update && item.reference_update.reference_id) || '';
        let selectedReferenceId = candidateRows.some(row => row.reference_id === currentReference) ? currentReference : '';
        if (!selectedReferenceId && candidateRows.length && Number(candidateRows[0].score) >= 0.95) {
            selectedReferenceId = candidateRows[0].reference_id;
        }
        const roleHint = String(item.decision && item.decision.price_role || item.price_role_hint || '').toLowerCase();
        let selectedPriceRole = ['package', 'pack', 'carton'].includes(roleHint) ? 'package'
            : ['unit', 'single', 'per_unit'].includes(roleHint) ? 'unit' : '';
        const candidates = node('div', null, 'molavi-reference-candidates');
        if (candidateRows.length) {
            candidates.appendChild(node('p', 'یک پیشنهاد را انتخاب کنید؛ پیشنهاد با اطمینان بالا از قبل انتخاب شده است.'));
            candidateRows.slice(0, 6).forEach(row => {
                const option = node('button', null, 'button molavi-reference-candidate');
                option.type = 'button';
                option.setAttribute('aria-pressed', String(row.reference_id === selectedReferenceId));
                option.appendChild(node('strong', row.name || row.reference_id));
                const score = Number(row.score);
                option.appendChild(node('span', `امتیاز تطبیق: ${Number.isFinite(score) ? Math.round(score * 100) + '٪' : 'نامشخص'}`));
                option.appendChild(node('span', `تکی: ${amount(row.unit_price)} · بسته: ${amount(row.package_price)}`));
                option.addEventListener('click', () => {
                    selectedReferenceId = row.reference_id;
                    candidates.querySelectorAll('.molavi-reference-candidate').forEach(button =>
                        button.setAttribute('aria-pressed', String(button === option)));
                });
                candidates.appendChild(option);
            });
        } else {
            candidates.appendChild(node('p', 'پیشنهاد مرجع پیدا نشد؛ مورد را باز نگه دارید تا فهرست مرجع بهتر شود.'));
        }
        form.appendChild(candidates);
        const roles = node('div', null, 'molavi-reference-roles');
        roles.appendChild(node('strong', 'این قیمت برای:'));
        const roleButtons = [];
        [['unit', 'یک واحد'], ['package', 'یک بسته / کارتن']].forEach(([value, label]) => {
            const button = node('button', label, 'button molavi-reference-role');
            button.type = 'button';
            button.setAttribute('aria-pressed', String(value === selectedPriceRole));
            button.addEventListener('click', () => {
                selectedPriceRole = value;
                roleButtons.forEach(option => option.setAttribute('aria-pressed', String(option === button)));
            });
            roleButtons.push(button);
            roles.appendChild(button);
        });
        form.appendChild(roles);
        const match = node('button', item.decision ? 'ثبت اصلاح تطبیق' : 'تأیید پیشنهاد انتخاب‌شده', 'button button-primary');
        match.type = 'submit'; match.value = 'match';
        const noMatch = node('button', 'تأیید نبود تطبیق و نگه داشتن کالا در بررسی', 'button');
        noMatch.type = 'submit'; noMatch.value = 'no_match'; noMatch.formNoValidate = true;
        match.disabled = !contextStored;
        noMatch.disabled = !contextStored;
        form.append(match, noMatch);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const choice = event.submitter && event.submitter.value;
            if (!['match', 'no_match'].includes(choice) || !contextStored) return;
            const referenceId = choice === 'match' ? selectedReferenceId : '';
            if (choice === 'match' && (!referenceId || !selectedPriceRole)) {
                errorBox('یک پیشنهاد مرجع و نقش قیمت را با دکمه‌ها انتخاب کنید.', card);
                return;
            }
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, snapshot_id: item.snapshot_id,
                choice, reference_id: referenceId,
                price_role: choice === 'match' ? selectedPriceRole : 'unresolved',
                note: choice === 'match' ? 'مالک تطبیق پیشنهادی را از پنل بررسی تأیید کرد.' : 'مالک تأیید کرد که پیشنهادهای فعلی تطبیق ندارند.',
                expected_decision_id: item.decision && item.decision.decision_id || null
            };
            const eventId = reviewEventId('reference_item', item.case_id);
            const decision = {...payload};
            match.disabled = true; noMatch.disabled = true;
            try {
                await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.case_id,
                    'pending', item, decision)]);
                const receipt = await request('POST', payload);
                if (receipt.case_id !== payload.case_id || receipt.offer_id !== payload.offer_id
                    || receipt.snapshot_id !== payload.snapshot_id || receipt.choice !== choice
                    || !/^[a-f0-9]{64}$/.test(receipt.decision_id || '')
                    || !['imported', 'needs_review', 'held_for_review'].includes(receipt.projection_state)
                    || (choice === 'no_match' && receipt.projection_state !== 'held_for_review')
                    || (choice === 'match' && receipt.projection_state === 'held_for_review')) {
                    throw new Error('invalid_receipt');
                }
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.case_id,
                        'accepted', item, decision, receipt)]);
                } catch (_error) {
                    await referenceItems('تصمیم در سرویس ثبت شد، اما رسید وردپرس هنوز همگام نشده است.', 'warning');
                    return;
                }
                await referenceItems('تصمیم تطبیق ثبت شد.', 'success');
            } catch (error) {
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.case_id,
                        error.remoteRejected ? 'failed' : 'pending', item, decision,
                        {error:String(error.message || 'request_failed'), request_status:error.requestStatus || null})]);
                } catch (_ignored) {}
                errorBox(errorText(error.message), card); match.disabled = false; noMatch.disabled = false;
            }
        });
        card.appendChild(form);
        return card;
    }

    function safeSourceLink(value) {
        if (typeof value !== 'string' || !value) return null;
        try {
            const url = new URL(value);
            if (url.protocol !== 'https:' || !['t.me', 'www.t.me', 'bale.ai', 'www.bale.ai'].includes(url.hostname)) return null;
            const link = node('a', 'باز کردن پیام اصلی', 'button button-secondary');
            link.href = url.href;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            return link;
        } catch (_error) { return null; }
    }

    async function referenceItems(message = '', messageKind = 'info') {
        shell('تطبیق کالای تأمین‌کننده', 'هر پیشنهاد را به کالای مرجع درست وصل کنید و مشخص کنید قیمت برای یک واحد است یا یک بسته. کالای بدون تطبیق در بررسی می‌ماند.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const state = data.reference_state || {};
            if (!state.allow_automatic) root.appendChild(notice('نسخه قیمت مرجع فعال و معتبر نیست. ابتدا فهرست مرجع را از API ووکامرس دریافت، بررسی و فعال کنید.', 'warning'));
            const requestedOfferId = new URLSearchParams(window.location.search).get('offer_id') || '';
            let cases = [...(data.cases || []), ...(data.recent_decisions || [])];
            if (requestedOfferId) cases = cases.filter(item => String(item.offer_id || '') === requestedOfferId);
            let contextStored = true;
            if (cases.length) {
                try {
                    await persistOwnerReviews(cases.map(item => reviewRecord(
                        `context:reference_item:${item.case_id}`, 'reference_item', item.case_id,
                        'observed', item
                    )));
                } catch (_error) {
                    contextStored = false;
                    root.appendChild(notice('موارد تطبیق نمایش داده می‌شوند، اما ثبت زمینهٔ آن‌ها در پایگاه داده وردپرس تأیید نشد؛ تا رفع خطا تصمیم تازه‌ای ثبت نمی‌شود.', 'error'));
                }
            }
            if (!cases.length) root.appendChild(node('p', requestedOfferId
                ? 'برای این پیشنهاد، مورد تطبیق بازی پیدا نشد؛ وضعیت کالا را در فهرست بررسی کنید.'
                : 'مورد تطبیقی برای بررسی وجود ندارد.'));
            cases.forEach(item => root.appendChild(referenceItemCard(item, contextStored)));
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    async function productStatusSnapshots(message = '', messageKind = 'info') {
        shell('سوابق وضعیت کالا', 'از تغییرهای مهم وضعیت، تطبیق مرجع و نیاز به به‌روزرسانی کالا نسخه ثبت می‌شود. با باز کردن این بخش، اگر از آخرین نسخه یک روز گذشته باشد، نسخه روزانه هم ثبت می‌شود؛ از هر زمان می‌توانید snapshot دستی بگیرید.');
        if (message) root.appendChild(notice(message, messageKind));
        const refresh = node('button', 'ثبت snapshot جدید', 'button button-primary');
        refresh.type = 'button';
        refresh.addEventListener('click', async () => {
            refresh.disabled = true;
            try {
                const receipt = await request('POST', {action: 'capture'});
                if (!receipt.capture || !receipt.capture.snapshot_id) throw new Error('invalid_receipt');
                const next = new URL(window.location.href);
                next.searchParams.delete('snapshot_id');
                window.history.replaceState({}, '', next.toString());
                await productStatusSnapshots('نسخه جدید وضعیت کالا ثبت شد.', 'success');
            } catch (error) {
                root.appendChild(notice(errorText(error.message), 'error'));
                refresh.disabled = false;
            }
        });
        root.appendChild(refresh);
        try {
            const requestedSnapshot = new URLSearchParams(window.location.search).get('snapshot_id') || '';
            const snapshotUrl = new URL(config.apiUrl);
            if (requestedSnapshot) snapshotUrl.searchParams.set('snapshot_id', requestedSnapshot);
            const data = await request('GET', undefined, snapshotUrl.toString());
            const products = Array.isArray(data.products) ? data.products : [];
            const snapshots = Array.isArray(data.snapshots) ? data.snapshots : [];
            const selectedSnapshotId = data.selected_snapshot_id || data.latest_snapshot_id || '';
            const selectedSnapshot = snapshots.find(item => item.snapshot_id === selectedSnapshotId) || null;
            const updateNeeded = products.filter(item => item.update_needed).length;
            const retained = products.filter(item => item.reference_review_result === 'due_no_update_within_margin').length;
            const summary = node('section', null, 'card');
            summary.appendChild(node('h2', requestedSnapshot ? 'وضعیت snapshot انتخاب‌شده' : 'آخرین وضعیت ثبت‌شده'));
            summary.appendChild(node('p', `کالاهای ثبت‌شده: ${products.length} · نیازمند به‌روزرسانی یا بررسی: ${updateNeeded} · بازبینی شد و تأیید ماند: ${retained}`));
            root.appendChild(summary);
            const history = node('section', null, 'card');
            history.appendChild(node('h2', 'تاریخچه snapshotها'));
            if (!snapshots.length) history.appendChild(node('p', 'هنوز نسخه‌ای ثبت نشده است.'));
            snapshots.forEach(item => {
                const button = node('button', `${item.captured_at || ''} · ${item.reason || ''} · کالا: ${item.product_count || 0} · تغییر از نسخه قبل: ${item.changed_count || 0}`, 'button button-secondary');
                button.type = 'button';
                button.disabled = item.snapshot_id === selectedSnapshotId;
                button.addEventListener('click', () => {
                    const next = new URL(window.location.href);
                    next.searchParams.set('snapshot_id', item.snapshot_id);
                    window.history.replaceState({}, '', next.toString());
                    productStatusSnapshots();
                });
                history.appendChild(button);
            });
            root.appendChild(history);
            if (selectedSnapshot) {
                const title = node('section', null, 'card');
                title.appendChild(node('h2', 'وضعیت‌های ثبت‌شده در نسخه انتخاب‌شده'));
                title.appendChild(node('p', `${selectedSnapshot.captured_at || ''} · ${selectedSnapshot.reason || ''} · کالا: ${selectedSnapshot.product_count || 0} · تغییر از نسخه قبل: ${selectedSnapshot.changed_count || 0}`));
                root.appendChild(title);
            }
            products.forEach(item => {
                const card = node('section', null, 'card');
                card.appendChild(node('h3', item.product_name || 'کالای بدون نام'));
                card.appendChild(node('p', `وضعیت: ${item.status || 'نامشخص'} · وضعیت به‌روزرسانی: ${item.update_needed ? 'نیازمند بررسی' : 'فعلاً نیاز ندارد'}`));
                card.appendChild(node('p', `تطبیق مرجع: ${item.reference_approval_state || 'ثبت نشده'} · تغییر قیمت: ${item.reference_price_change_percent ? item.reference_price_change_percent + '٪' : '—'} · نقش قیمت: ${item.reference_price_role || '—'}`));
                card.appendChild(node('p', `تازگی قیمت تأمین‌کننده: ${item.supplier_price_state || 'ثبت نشده'} · بررسی مرجع: ${item.reference_review_result || 'ثبت نشده'}`));
                const originalPost = safeSourceLink(item.source_post_url);
                if (originalPost) card.appendChild(originalPost);
                root.appendChild(card);
            });
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    async function referenceDecisions(message = '', messageKind = 'info') {
        shell('تازگی و اعتبار قیمت مرجع', 'قیمت‌های مرجع معمولاً پس از ۲۴ ساعت دوباره بررسی می‌شوند. هر تصمیم فقط برای همان رخداد ثبت می‌شود.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const events = Array.isArray(data.events) ? data.events : [];
            if (!events.length) root.appendChild(node('p', 'تصمیمی در انتظار بررسی وجود ندارد.'));
            for (const event of events) {
                const card = node('section', null, 'card');
                const reason = ({ stale: 'قیمت مرجع بیش از ۲۴ ساعت قدمت دارد', refresh_failed: 'دریافت قیمت مرجع ناموفق بود', missing: 'قیمت مرجع قبلی موجود نیست' })[event.reason] || 'نیاز به بررسی';
                card.appendChild(node('h2', reason));
                card.appendChild(node('p', `شناسه رخداد: ${event.event_id} · زمان UTC: ${event.created_at || ''} · نسخه مرجع: ${event.snapshot_id || 'موجود نیست'}`));
                const form = document.createElement('form');
                const pause = node('button', 'توقف و بررسی موارد مرتبط', 'button'); pause.type = 'submit'; pause.value = 'pause_review';
                form.appendChild(pause);
                if (event.snapshot_id) {
                    const resume = node('button', 'ادامه با نسخه قبلی و ثبت هشدار', 'button button-primary'); resume.type = 'submit'; resume.value = 'use_last_snapshot';
                    form.appendChild(resume);
                }
                form.addEventListener('submit', async e => {
                    e.preventDefault();
                    const choice = e.submitter && e.submitter.value;
                    if (choice === 'use_last_snapshot' && !window.confirm('با نسخه قبلی ادامه دهید و هشدار ثبت کنید؟')) return;
                    pause.disabled = true;
                    const resumeButton = form.querySelector('[value="use_last_snapshot"]'); if (resumeButton) resumeButton.disabled = true;
                    try {
                        const receipt = await request('POST', { event_id: event.event_id, snapshot_id: event.snapshot_id || null, choice });
                        if (receipt.event_id !== event.event_id || receipt.snapshot_id !== (event.snapshot_id || null) || receipt.choice !== choice) throw new Error('invalid_receipt');
                        await referenceDecisions('تصمیم ثبت شد.', 'success');
                    } catch (error) { errorBox(errorText(error.message), card); pause.disabled = false; if (resumeButton) resumeButton.disabled = false; }
                });
                card.appendChild(form); root.appendChild(card);
            }
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    async function publicationHolds(message = '', messageKind = 'info') {
        shell('بررسی و تلاش دوباره', 'پیام‌های اصلی و دلیل توقف را همین‌جا ببینید. دسته‌های انتخاب‌شده با یک اقدام برای یک بار پردازش دوباره صف می‌شوند؛ شرح جداگانه برای هر دسته لازم نیست.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const holds = Array.isArray(data.holds) ? data.holds : [];
            if (!holds.length) root.appendChild(node('p', 'توقف انتشاری در انتظار بررسی نیست.'));
            const reasons = {
                batch_in_progress: 'پردازش دسته کامل نشده و نیاز به بررسی دارد.',
                reference_changed_during_batch: 'اطلاعات مرجع هنگام پردازش تغییر کرد.',
                batch_unverified: 'نتیجه پردازش نیاز به بررسی دارد.',
                batch_write_unverified: 'ثبت اطلاعات کامل تأیید نشد.'
            };
            const contextRecords = holds.map(hold => reviewRecord(
                `context:publication_hold:${hold.hold_id}`, 'publication_hold', hold.hold_id,
                'observed', storedReviewContext('publication_hold', hold.context || {reason:hold.reason, created_at:hold.created_at})
            ));
            let contextStored = true;
            if (contextRecords.length) {
                try { await persistOwnerReviews(contextRecords); }
                catch (_error) { contextStored = false; root.appendChild(notice('جزئیات این دسته در صفحه نمایش داده می‌شود، اما ذخیرهٔ آن در پایگاه داده وردپرس تأیید نشد؛ تا ذخیرهٔ اطلاعات، پردازش دوباره غیرفعال است.', 'warning')); }
            }
            const checks = new Map();
            const toolbar = node('div', null, 'molavi-bulk-dismiss');
            const retrySelected = node('button', 'پردازش دوبارهٔ دسته‌های انتخاب‌شده', 'button button-primary');
            retrySelected.type = 'button';
            const selectionCount = node('span', '');
            toolbar.append(retrySelected, selectionCount);
            const updateSelection = () => {
                const count = [...checks.values()].filter(Boolean).length;
                selectionCount.textContent = `انتخاب‌شده: ${new Intl.NumberFormat('fa-IR').format(count)}`;
                retrySelected.disabled = count < 1 || count > 100 || !contextStored;
                retrySelected.textContent = !contextStored
                    ? 'ذخیرهٔ جزئیات در وردپرس ناموفق بود'
                    : count > 100
                    ? 'حداکثر ۱۰۰ دسته را انتخاب کنید'
                    : `پردازش دوبارهٔ ${new Intl.NumberFormat('fa-IR').format(count)} دسته`;
            };
            if (holds.length) root.appendChild(toolbar);
            retrySelected.addEventListener('click', async () => {
                const selected = holds.filter(hold => checks.get(hold.hold_id));
                if (!selected.length || selected.length > 100) return;
                retrySelected.disabled = true;
                const eventIds = new Map(selected.map(hold => [hold.hold_id,
                    reviewEventId('publication_hold', hold.hold_id)]));
                const decisions = selected.map(hold => reviewRecord(eventIds.get(hold.hold_id),
                    'publication_hold', hold.hold_id, 'pending', {},
                    {action:'retry_once', expected_reason:hold.reason, attempt_id:eventIds.get(hold.hold_id)}));
                try {
                    await persistOwnerReviews(decisions);
                    const receipt = await request('POST', {action:'resume_selected',holds:selected.map(hold => ({
                        hold_id:hold.hold_id, expected_reason:hold.reason
                    }))});
                    if (receipt.resumed_count !== selected.length || !Array.isArray(receipt.resumed_hold_ids)
                        || receipt.resumed_hold_ids.length !== selected.length) throw new Error('invalid_receipt');
                    let localWarning = '';
                    try {
                        await persistOwnerReviews(selected.map(hold => reviewRecord(
                            reviewEventId('publication_hold', hold.hold_id),
                            'publication_hold', hold.hold_id, 'accepted', {},
                            {action:'retry_once', expected_reason:hold.reason, attempt_id:eventIds.get(hold.hold_id)}, receipt)));
                    } catch (_error) { localWarning = ' وضعیت ثبت محلی در وردپرس در انتظار همگام‌سازی است.'; }
                    await publicationHolds(`${receipt.resumed_count} دسته برای یک بار پردازش دوباره صف شد.${localWarning}`,
                        localWarning ? 'warning' : 'success');
                } catch (error) {
                    try {
                        await persistOwnerReviews(selected.map(hold => reviewRecord(
                            reviewEventId('publication_hold', hold.hold_id),
                            'publication_hold', hold.hold_id, 'failed', {},
                            {action:'retry_once', expected_reason:hold.reason, attempt_id:eventIds.get(hold.hold_id)}, {error:error.message})));
                    } catch (_ignored) {}
                    await publicationHolds(errorText(error.message), 'warning');
                }
            });
            updateSelection();
        for (const [holdIndex, hold] of holds.entries()) {
                const card = node('section', null, 'card');
                card.appendChild(node('h2', reasons[hold.reason] || 'این دسته نیاز به بررسی دارد.'));
                const rawTime = hold.created_at;
                const parsedTime = rawTime === null || rawTime === undefined ? NaN
                    : (Number.isFinite(Number(rawTime)) ? Number(rawTime) * 1000 : new Date(rawTime).getTime());
                const timeLabel = Number.isFinite(parsedTime) ? new Date(parsedTime).toLocaleString() : 'نامشخص';
                card.appendChild(node('p', `شناسه دسته: ${hold.hold_id || ''} · زمان ثبت: ${timeLabel}`));
                const context = hold.context && typeof hold.context === 'object' ? hold.context : {};
                const messages = Array.isArray(context.messages) ? context.messages : [];
                const reconcileWithoutRetry = hold.reason === 'batch_in_progress'
                    && context.batch_state === 'failed' && context.batch_error === 'JobConflict'
                    && context.expected_messages > 0 && messages.length === context.expected_messages;
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox'; checkbox.checked = !reconcileWithoutRetry
                    && holdIndex < 100 && messages.length > 0;
                checkbox.disabled = reconcileWithoutRetry;
                checkbox.addEventListener('change', () => { checks.set(hold.hold_id, checkbox.checked); updateSelection(); });
                checks.set(hold.hold_id, checkbox.checked);
                const selectLabel = node('label', reconcileWithoutRetry
                    ? 'این دسته پردازش شده است؛ بازپردازش نکنید'
                    : 'این دسته را برای یک بار پردازش دوباره انتخاب کن', 'molavi-hold-select');
                selectLabel.prepend(checkbox); card.appendChild(selectLabel);
                const processingErrors = {
                    ai_extraction_failed: 'استخراج هوش مصنوعی برای یکی از پیام‌ها ناموفق شد.',
                    ingestion_incomplete: 'همه پیام‌های دسته در جدول دریافت ثبت نشدند.'
                };
                const statusLabels = {needs_review:'نیازمند بررسی',failed:'ناموفق',queued:'در صف',done:'انجام‌شده',running:'در حال اجرا'};
                const stats = `وضعیت: ${statusLabels[context.batch_state] || context.batch_state || 'نامشخص'} · پیام‌های ثبت‌شده: ${context.written_messages || 0}/${context.expected_messages || 0} · پیام‌های پردازش‌شده: ${context.processed_messages || 0}`;
                card.appendChild(node('p', stats));
                if (context.processing_error || context.batch_error) {
                    const code = context.processing_error || context.batch_error;
                    card.appendChild(notice(processingErrors[code] || `دلیل فنی: ${code}`, 'warning'));
                }
                if (context.processing_detail) card.appendChild(node('p', `جزئیات خطای پردازش: ${context.processing_detail}`));
                if (context.rollback) card.appendChild(node('p', 'تغییرهای کاتالوگ این دسته بازگردانده شدند؛ انتشار این دسته انجام نشده است.'));
                if (reconcileWithoutRetry) {
                    card.appendChild(notice('رسید پردازش این دسته در صف خطا خورده، اما پیام و موارد تأیید استخراج آن در شیت ثبت شده‌اند. اقدام زیر فقط توقف تکراری را می‌بندد؛ این دسته را دوباره اجرا یا موارد تأیید را تصویب نمی‌کند.', 'info'));
                    const action = node('button', 'تطبیق و بستن توقف، بدون بازپردازش', 'button button-primary');
                    action.type = 'button'; action.disabled = !contextStored;
                    action.addEventListener('click', async () => {
                        action.disabled = true;
                        const eventId = reviewEventId('publication_hold', hold.hold_id);
                        const note = 'Reconciled the existing Sheet messages and AI review cases; clear the stale hold without replaying the batch.';
                        const decision = {action:'reconcile_without_retry', expected_reason:hold.reason,
                            expected_batch_state:context.batch_state, expected_batch_error:context.batch_error,
                            attempt_id:eventId, note};
                        try {
                            await persistOwnerReviews([reviewRecord(eventId, 'publication_hold', hold.hold_id,
                                'pending', {}, decision)]);
                            const receipt = await request('POST', {hold_id:hold.hold_id,
                                expected_reason:hold.reason, expected_batch_state:context.batch_state,
                                expected_batch_error:context.batch_error, note, resume_batch:false});
                            if (receipt.hold_id !== hold.hold_id || receipt.batch_resumed !== false
                                || receipt.reconciled !== true) throw new Error('invalid_receipt');
                            let localWarning = '';
                            try {
                                await persistOwnerReviews([reviewRecord(reviewEventId('publication_hold', hold.hold_id),
                                    'publication_hold', hold.hold_id, 'accepted', {}, decision, receipt)]);
                            } catch (_error) { localWarning = ' وضعیت ثبت محلی وردپرس در انتظار همگام‌سازی است.'; }
                            await publicationHolds(`توقف تکراری بسته شد و این دسته دوباره اجرا نشد.${localWarning}`,
                                localWarning ? 'warning' : 'success');
                        } catch (error) {
                            try {
                                await persistOwnerReviews([reviewRecord(reviewEventId('publication_hold', hold.hold_id),
                                    'publication_hold', hold.hold_id, 'failed', {}, decision, {error:error.message})]);
                            } catch (_ignored) {}
                            await publicationHolds(errorText(error.message), 'warning');
                        }
                    });
                    card.appendChild(action);
                    const reviewUrl = config.pageUrls && config.pageUrls['molavi-ai-confirmations'];
                    if (reviewUrl) {
                        const link = node('a', 'رفتن به موارد تأیید استخراج');
                        link.href = reviewUrl; link.target = '_top'; card.appendChild(link);
                    }
                }
                card.appendChild(node('h3', `پیام‌های مبدأ این دسته (${messages.length})`));
                if (!messages.length) card.appendChild(notice('جزئیات پیام مبدأ از سرویس پردازش دریافت نشد؛ برای جلوگیری از پردازش دوبارهٔ دستهٔ نامشخص، این مورد تا نمایش جزئیات غیرفعال است.', 'warning'));
                for (const source of messages) {
                    const item = node('div', null, 'molavi-review-source');
                    item.appendChild(node('strong', `${source.channel || 'کانال نامشخص'} · پیام ${source.message_id || 'بدون شناسه'}`));
                    if (source.source_timestamp) item.appendChild(node('p', `زمان پیام: ${source.source_timestamp}`));
                    let sourceUrl = source.source_url || '';
                    if (!sourceUrl && /^\d+$/.test(String(source.message_id || ''))) {
                        const username = String(source.channel || '').replace(/^@/, '');
                        if (/^[A-Za-z0-9_]{5,}$/.test(username)) sourceUrl = `https://t.me/${username}/${source.message_id}`;
                    }
                    const sourceLink = safeSourceLink(sourceUrl);
                    if (sourceLink) item.appendChild(sourceLink);
                    if (source.content) {
                        const excerpt = source.content.length > 260 ? source.content.slice(0, 260) + '…' : source.content;
                        item.appendChild(node('p', excerpt, 'molavi-source-excerpt'));
                        const fullText = document.createElement('details');
                        fullText.appendChild(node('summary', source.content_truncated ? 'نمایش متن ذخیره‌شده (بخشی از متن)' : 'نمایش متن کامل پیام'));
                        const textBody = node('pre', source.content, 'molavi-source-message');
                        fullText.appendChild(textBody); item.appendChild(fullText);
                    } else if (source.has_media) {
                        item.appendChild(node('p', `این پیام رسانه دارد${source.media_type ? ` (${source.media_type})` : ''}؛ متن جداگانه ثبت نشده است.`));
                    } else item.appendChild(node('p', 'متن پیام در رسید این دسته ثبت نشده است.'));
                    card.appendChild(item);
                }
                root.appendChild(card);
            }
            updateSelection();
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    async function supplierPrices(message = '', messageKind = 'info') {
        shell('تازگی قیمت تأمین‌کنندگان', 'قیمت‌های کهنه یا فاقد نسخه منبع برای بررسی نمایش داده می‌شوند. تأیید فقط پس از بررسی واقعی منبع ثبت شود؛ این کار انتشار را به صف می‌فرستد.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const requestedProductId = new URLSearchParams(window.location.search).get('product_id') || '';
            let offers = Array.isArray(data.offers) ? data.offers : [];
            if (requestedProductId) {
                offers = offers.filter(offer => String(offer.product_id || '') === requestedProductId);
            }
            if (!offers.length) root.appendChild(node('p', requestedProductId
                ? 'برای این کالا قیمت تأمین‌کننده‌ای در انتظار بررسی نیست.'
                : 'قیمت تأمین‌کننده‌ای در انتظار بررسی نیست.'));
            const labels = {
                warning: 'بیش از هفت روز از آخرین بررسی گذشته است.',
                blocked: 'قیمت ۱۴ روز یا بیشتر بررسی نشده؛ سفارش تازه متوقف است.',
                needs_review: 'مدرک تاریخ یا نسخه قیمت نیاز به بررسی دارد.'
            };
            for (const offer of offers) {
                const card = node('section', null, 'card');
                card.appendChild(node('h2', offer.name || 'کالای بدون نام'));
                card.appendChild(node('p', `تأمین‌کننده: ${offer.channel || 'نامشخص'} · قیمت ثبت‌شده: ${amount(offer.sale_price)}`));
                card.appendChild(node('p', labels[offer.state] || labels.needs_review));
                const sourceLink = safeSourceLink(offer.source_post_url);
                if (sourceLink) card.appendChild(sourceLink);
                const form = document.createElement('form');
                const submit = node('button', 'قیمت تأمین‌کننده را بررسی کردم', 'button button-primary');
                submit.type = 'submit'; form.appendChild(submit);
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    submit.disabled = true;
                    try {
                        const receipt = await request('POST', {product_id: offer.product_id,
                            expected_revision: offer.source_revision,
                            note: 'مالک منبع فعلی قیمت را از پنل بررسی تأیید کرد.'});
                        if (receipt.product_id !== offer.product_id || receipt.source_revision !== offer.source_revision
                            || !receipt.verified_at || !receipt.publish_job) throw new Error('invalid_receipt');
                        await supplierPrices('بررسی قیمت ثبت و انتشار دوباره به صف فرستاده شد.', 'success');
                    } catch (error) { errorBox(errorText(error.message), card); submit.disabled = false; }
                });
                card.appendChild(form); root.appendChild(card);
            }
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    function conflictValue(value) {
        if (value === null || value === undefined || value === '') return 'خالی';
        if (typeof value === 'object') {
            try { return JSON.stringify(value); } catch (_error) { return 'مقدار ثبت‌شده'; }
        }
        return String(value);
    }

    async function sheetConflicts(message = '', messageKind = 'info') {
        shell('تعارض‌های اطلاعات کالا', 'پیش‌فرض مقدار محاسبه‌شدهٔ Railway است. فقط اگر سلول شیت را دستی تغییر داده‌اید، همان اصلاح را به فروشگاه WordPress بفرستید؛ یادداشت و تأیید اضافه لازم نیست.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const conflicts = Array.isArray(data.conflicts) ? data.conflicts : [];
            let contextStored = true;
            if (conflicts.length) {
                try {
                    await persistOwnerReviews(conflicts.map(conflict => reviewRecord(
                        `context:sheet_conflict:${conflict.conflict_id}`, 'sheet_conflict', conflict.conflict_id,
                        'observed', storedReviewContext('sheet_conflict', conflict)
                    )));
                } catch (_error) {
                    contextStored = false;
                    root.appendChild(notice('تعارض‌ها نمایش داده می‌شوند، اما ثبت محلی آن‌ها در پایگاه داده وردپرس تأیید نشد؛ تا رفع این خطا تصمیم تازه‌ای ثبت نمی‌شود.', 'error'));
                }
            }
            if (!conflicts.length) root.appendChild(node('p', 'تعارض بازی در انتظار بررسی نیست.'));
            for (const conflict of conflicts) {
                const card = node('section', null, 'card');
                card.appendChild(node('h2', conflict.product_name || 'کالای بدون نام'));
                card.appendChild(node('p', `تأمین‌کننده: ${conflict.channel || 'نامشخص'} · فیلد: ${conflict.field || 'نامشخص'}`));
                for (const [label, key] of [['آخرین مقدار هماهنگ‌شده', 'base'],
                    ['مقدار محاسبه‌شده در Railway', 'server'], ['مقدار فعلی شیت', 'sheet']]) {
                    card.appendChild(node('p', `${label}: ${conflictValue(conflict[key])}`));
                }
                const serverOwned = conflict.server_owned === true;
                const sheetPreserved = conflict.sheet_preserved === true;
                const primaryChoice = sheetPreserved ? 'preserve' : 'server';
                if (serverOwned) card.appendChild(notice('این فیلد سیستمی است؛ فقط مقدار Railway می‌تواند در شیت ثبت شود.', 'info'));
                if (sheetPreserved) card.appendChild(notice('هویت و زمان ثبت اولیه حفظ می‌شود؛ این فیلد قابل اصلاح دستی نیست.', 'info'));
                const actions = node('div', null, 'molavi-reextract-actions');
                const chooseServer = node('button', sheetPreserved ? 'حفظ مقدار اصلی شیت'
                    : serverOwned ? 'همگام‌سازی مقدار سیستمی از Railway'
                    : 'اعمال مقدار فعلی Railway', 'button button-primary');
                chooseServer.type = 'button';
                const chooseSheet = node('button', 'ارسال اصلاح دستی شیت به فروشگاه', 'button button-secondary');
                chooseSheet.type = 'button';
                chooseServer.disabled = !contextStored;
                chooseSheet.disabled = serverOwned || sheetPreserved || !contextStored;
                const submitChoice = async choice => {
                    chooseServer.disabled = true; chooseSheet.disabled = true;
                    const eventId = reviewEventId('sheet_conflict', conflict.conflict_id);
                    const decision = {choice, attempt_id:eventId, note:choice === 'server'
                        ? (serverOwned
                            ? 'Synchronize this server-owned field back to the Sheet.'
                            : 'Auto: keep the current server-computed value.')
                        : choice === 'preserve'
                            ? 'Preserve the existing canonical Sheet identity or import timestamp.'
                            : 'Owner confirmed this was a manual Sheet edit; publish it to WordPress.'};
                    try {
                        await persistOwnerReviews([reviewRecord(eventId, 'sheet_conflict', conflict.conflict_id,
                            'pending', {}, decision)]);
                        const receipt = await request('POST', {conflict_id: conflict.conflict_id, choice, note:decision.note});
                        if (receipt.conflict_id !== conflict.conflict_id || receipt.choice !== choice
                            || (serverOwned ? receipt.server_owned !== true
                                : sheetPreserved ? receipt.sheet_preserved !== true
                                : !receipt.publish_job)) throw new Error('invalid_receipt');
                        let localWarning = '';
                        try {
                            await persistOwnerReviews([reviewRecord(reviewEventId('sheet_conflict', conflict.conflict_id),
                                'sheet_conflict', conflict.conflict_id, 'accepted', {}, decision, receipt)]);
                        } catch (_error) { localWarning = ' تصمیم در سرویس ثبت شد، اما وضعیت محلی وردپرس در انتظار همگام‌سازی است.'; }
                        const successMessage = serverOwned
                            ? 'مقدار سیستمی با مقدار Railway در شیت همگام شد.'
                            : sheetPreserved ? 'مقدار اصلی شیت حفظ شد.'
                            : 'تصمیم ثبت شد و انتشار به صف رفت.';
                        await sheetConflicts(`${successMessage}${localWarning}`,
                            localWarning ? 'warning' : 'success');
                    } catch (error) {
                        try {
                            await persistOwnerReviews([reviewRecord(reviewEventId('sheet_conflict', conflict.conflict_id),
                                'sheet_conflict', conflict.conflict_id, 'failed', {}, decision, {error:error.message})]);
                        } catch (_ignored) {}
                        await sheetConflicts(`${errorText(error.message)} فهرست تعارض‌ها تازه شد؛ موردهای باقی‌مانده را دوباره بررسی کنید.`, 'warning');
                    }
                };
                chooseServer.addEventListener('click', () => submitChoice(primaryChoice));
                chooseSheet.addEventListener('click', () => submitChoice('sheet'));
                actions.appendChild(chooseServer);
                if (!serverOwned && !sheetPreserved) actions.appendChild(chooseSheet);
                card.appendChild(actions); root.appendChild(card);
            }
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    const actions = {
        'molavi-operations': dashboard,
        'molavi-ai-confirmations': aiConfirmations,
        'molavi-reference-items': referenceItems,
        'molavi-reference-decisions': referenceDecisions,
        'molavi-publication-holds': publicationHolds,
        'molavi-supplier-prices': supplierPrices,
        'molavi-sheet-conflicts': sheetConflicts,
        'molavi-product-status-snapshots': productStatusSnapshots
    };
    if (actions[config.page]) actions[config.page]();
})();
