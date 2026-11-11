(() => {
    const root = document.getElementById('molavi-owner-workflow');
    const config = window.molaviOwnerWorkflow;
    if (!root || !config) return;
    const verifiedCurrentPriceCases = new Set();

    function currentPriceVerificationKey(item) {
        return `${item && item.case_id || ''}:${item && item.signature || ''}`;
    }

    function aiSourceCurrentCheckMessage(item) {
        const basis = String(item && item.source_timestamp_basis || '').trim();
        const trusted = ['supplier_channel_update','supplier_channel_forward'].includes(basis);
        const sourceTime = String(item && (item.source_updated_at || item.source_update_at
            || item.message_timestamp || item.source_created_at) || '').trim();
        if (!trusted) {
            return `زمان ثبت این پیام${sourceTime ? ` (${sourceAgeText(sourceTime)})` : ''} به‌تنهایی تازگی قیمت تأمین‌کننده را ثابت نمی‌کند؛ قیمت را در منبع فعلی بررسی کنید.`;
        }
        if (!sourceTime) return 'زمان معتبر منبع ثبت نشده است؛ پیش از تأیید، قیمت را در منبع فعلی بررسی کنید.';
        return `آخرین زمان معتبر منبع ${sourceAgeText(sourceTime)} است؛ پیش از تأیید، قیمت را در پیام جدیدتر یا منبع فعلی بررسی کنید.`;
    }

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

    function reviewCardHeader(title, state = '') {
        const header = node('header', null, 'molavi-review-card-header');
        if (title) header.appendChild(node('h2', title));
        if (state) header.appendChild(node('span', state, 'molavi-review-card-state'));
        return header;
    }

    function reviewFacts(rows, className = 'molavi-review-facts') {
        const facts = node('dl', null, className);
        rows.filter(row => row && row[0] && row[1] !== null && row[1] !== undefined && row[1] !== '')
            .forEach(([label, value]) => {
                const fact = node('div', null, 'molavi-review-fact');
                fact.append(node('dt', label), node('dd', value));
                facts.appendChild(fact);
            });
        return facts;
    }

    function reviewActions(className = 'molavi-review-actions') {
        return node('div', null, className);
    }

    function ownerWorkflowLink(page, label, hash = '') {
        const path = config.pageUrls && config.pageUrls[page];
        if (!path) return null;
        const link = node('a', label, 'button button-secondary');
        const url = new URL(path, window.location.href);
        if (hash) url.hash = hash;
        link.href = url.toString();
        link.target = '_top';
        return link;
    }

    function reviewNextStep(text, link = null) {
        const step = node('section', null, 'molavi-review-next-step');
        step.appendChild(node('strong', 'گام بعدی'));
        step.appendChild(node('p', text));
        if (link) step.appendChild(link);
        return step;
    }

    function ownerWorkflowAnchor(target, label) {
        const link = node('a', label, 'button button-secondary');
        link.href = `#${target}`;
        link.addEventListener('click', event => {
            event.preventDefault();
            const element = document.getElementById(target);
            if (!element) return;
            const details = element.closest && element.closest('details');
            if (details) details.open = true;
            element.scrollIntoView({behavior:'smooth',block:'start'});
        });
        return link;
    }

    function displayBatchState(value) {
        const labels = {queued:'در صف',pending:'در انتظار',running:'در حال پردازش',processing:'در حال پردازش',
            complete:'کامل',completed:'کامل',done:'کامل',failed:'ناموفق',cancelled:'لغوشده',
            waiting_reference:'منتظر تطبیق مرجع',waiting_publication:'منتظر رفع توقف انتشار',
            waiting_owner:'منتظر تصمیم مالک',idle:'بی‌کار'};
        const key = String(value || '').trim().toLowerCase();
        return labels[key] || (key ? 'نیازمند بررسی' : 'نامشخص');
    }

    function displayBatchError(value) {
        const key = String(value || '').trim();
        const labels = {JobConflict:'پردازش تکراری تشخیص داده شد؛ نتیجه با پیام‌های ثبت‌شده تطبیق داده شود.',
            batch_incomplete:'بعضی پیام‌های این دسته ثبت نشده‌اند؛ منبع‌ها را بررسی کنید.',
            ai_extraction_failed:'استخراج هوش مصنوعی ناموفق بود؛ منبع را بازبینی و سپس دسته را دوباره اجرا کنید.',
            sheet_write_failed:'ثبت در فهرست کالاها کامل نشد؛ دسته را با منبع اصلی تطبیق دهید.',
            reference_decision_required:'برای ادامه، مرجع کالا را در همین کارت انتخاب کنید.',
            publication_held_for_review:'ابتدا توقف انتشار این دسته را در کارت خودش رفع کنید.'};
        return labels[key] || 'پردازش این دسته کامل نشده است؛ پیام‌های منبع و تعداد ثبت‌شده را بررسی کنید.';
    }

    function aiDisplayKey(value) {
        return String(value || '').normalize('NFKC').toLocaleLowerCase('fa')
            .replace(/[يى]/g, 'ی').replace(/ك/g, 'ک')
            .replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
            .replace(/ـ/g, '').replace(/[\s\u200c\u200d\p{P}\p{S}]+/gu, '');
    }

    function aiTitleContainsAttribute(title, value, label = '') {
        const name = aiDisplayKey(title);
        const text = aiDisplayKey(value);
        if (!name || !text) return false;
        if (/^\d+$/.test(text)) {
            if (!/(?:تعداد|بسته|کارتن|تایی|عدد)/u.test(label)) return false;
            return new RegExp(`${text}(?:عدد|عددی|تایی|کارتن|بسته)`).test(name);
        }
        return name.includes(text);
    }

    function aiStockLabel(value) {
        const state = String(value || '').trim().toLowerCase().replace(/[_-]+/g, ' ');
        if (!state || ['unknown','نامشخص','not stated'].includes(state)) return 'در پیام مشخص نشده';
        if (['available','in stock','موجود'].includes(state)) return 'موجود اعلام شده';
        if (['out of stock','sold out','unavailable','ناموجود','اتمام موجودی'].includes(state)) return 'ناموجود اعلام شده';
        return String(value);
    }

    function aiReviewReason(value) {
        const raw = String(value || '').trim();
        const key = raw.toLowerCase();
        if (/historical price|current supplier verification|stale price/.test(key)) {
            return 'قیمت از پیام قدیمی آمده؛ پیش از ثبت نهایی آن را از منبع فعلی تأیید کنید.';
        }
        if (/unusual discount|discount/.test(key)) {
            return 'فاصلهٔ قیمت فروش و قیمت مصرف‌کننده غیرعادی است؛ هر دو مبلغ را با پیام مقایسه کنید.';
        }
        if (/re-extraction|reextract|awaiting owner review/.test(key)) {
            return 'نتیجهٔ بازپردازش آماده است و باید با پیام اصلی تطبیق داده شود.';
        }
        if (/check source price/.test(key)) return 'قیمت را با پیام اصلی تأمین‌کننده مقایسه کنید.';
        if (/[\u0600-\u06ff]/.test(raw)) return raw;
        return 'این پیشنهاد برای بررسی مالک در صف مانده است.';
    }

    function readableOwnerNote(value) {
        const note = String(value || '').trim();
        if (!note || /(?:reextract|owner-confirm|(?:^|\s)ai:)[^\s]{24,}/i.test(note)
            || /^[a-f0-9]{32,}$/i.test(note)) return '';
        return note;
    }

    function readableWorkflowStatus(value) {
        const labels = {
            approved:'تأیید شده', blocked:'قیمت کهنه؛ سفارش متوقف', current:'به‌روز',
            held_for_review:'متوقف برای بررسی', imported:'در فهرست کالاها', match:'تطبیق ثبت‌شده',
            needs_review:'نیازمند بررسی', no_match:'بدون تطبیق', not_product:'کالا نیست',
            pending:'در انتظار تصمیم', pending_verification:'ثبت در فهرست تأیید نشده',
            awaiting_ai_confirmation:'در انتظار تأیید استخراج', warning:'بررسی تازه لازم است',
            verified:'تأیید شده', sell:'قابل فروش'
        };
        const key = String(value || '');
        return labels[key] || key || 'ثبت نشده';
    }

    function referenceComparisonSummary(candidate) {
        const labels = {brand:'برند',category:'دسته',variant:'طعم/مدل',
            package_count:'تعداد بسته',volume:'حجم',weight:'وزن'};
        const states = {match:'یکسان',close:'نزدیک',conflict:'اختلاف',reference_missing:'در مرجع ثبت نشده'};
        return (Array.isArray(candidate.comparisons) ? candidate.comparisons : [])
            .filter(item => item && item.key !== 'name' && item.source)
            .map(item => {
                const label = labels[item.key] || item.label || item.key;
                if (item.state === 'match') return `${label}: ${states.match} (${item.source})`;
                if (item.state === 'reference_missing') return `${label}: ${states.reference_missing}`;
                if (item.state === 'conflict') return `${label}: اختلاف، پیام ${item.source} / مرجع ${item.reference || '—'}`;
                return `${label}: ${states[item.state] || 'بررسی لازم'}${item.reference ? ` · مرجع ${item.reference}` : ''}`;
            }).slice(0, 4);
    }

    function referencePriceSummary(candidate, fallbackSupplierPrice = '') {
        const supplierPrice = candidate.supplier_price || fallbackSupplierPrice;
        const values = [supplierPrice ? `پیام ${amount(supplierPrice)}` : ''].concat([
            candidate.unit_price ? `واحد ${amount(candidate.unit_price)}` : '',
            candidate.package_price ? `بسته ${candidate.package_count || '?'}‌تایی ${amount(candidate.package_price)}` : ''
        ]).filter(Boolean);
        const hint = candidate.price_role_hint || {};
        if (supplierPrice && hint.role === 'unit') {
            const distance = Number(hint.unit_distance);
            values.push(`قیمت پیام به واحد نزدیک‌تر است${Number.isFinite(distance) ? ` (${(distance * 100).toLocaleString('fa-IR', {maximumFractionDigits:1})}٪ فاصله)` : ''}`);
        }
        else if (supplierPrice && hint.role === 'package') {
            const distance = Number(hint.package_distance);
            values.push(`قیمت پیام به بسته نزدیک‌تر است${Number.isFinite(distance) ? ` (${(distance * 100).toLocaleString('fa-IR', {maximumFractionDigits:1})}٪ فاصله)` : ''}`);
        }
        else if (supplierPrice) values.push('مبنای قیمت از این مقایسه روشن نیست');
        return values.join(' · ');
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
            source_current_verification_required: 'زمان پیام برای اثبات تازگی قیمت کافی نیست؛ منبع فعلی را بررسی و گزینهٔ تأیید منبع را علامت بزنید.',
            package_count_required: 'برای قیمت بسته، تعداد داخل بسته را مشخص کنید.',
            unknown_reference: 'نام دقیق یا شناسه کالای مرجع پیدا نشد؛ یکی از پیشنهادها را انتخاب کنید.',
            ambiguous_reference_name: 'این نام با چند کالای مرجع تطبیق دارد؛ یکی از پیشنهادها را انتخاب کنید یا شناسه دقیق را وارد کنید.',
            invalid_price_role: 'نقش قیمت را انتخاب کنید.',
            stale_or_conflicting_decision: 'وضعیت تصمیم تغییر کرده است؛ صفحه را تازه کنید.',
            review_unavailable: 'ثبت تصمیم موقتاً انجام نشد. صفحه را تازه کنید و دوباره تلاش کنید.',
            workflow_route_unavailable: 'اتصال این بخش تنظیم نشده است؛ از مدیریت سایت کمک بگیرید.',
            workflow_proxy_unavailable: 'اتصال امن وردپرس به سرویس پردازش آماده نیست؛ صفحه را تازه کنید.',
            upstream_unavailable: 'ارتباط وردپرس با سرویس پردازش برقرار نشد؛ چند لحظه بعد دوباره تلاش کنید.',
            invalid_nonce: 'نشست مدیریت منقضی شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            conflict_or_invalid: 'وضعیت توقف تغییر کرده است؛ صفحه را تازه کنید و دوباره بررسی کنید.',
            offer_changed: 'نسخه قیمت تغییر کرده است؛ دوباره منبع را بررسی کنید.',
            invalid_body: 'درخواست تأیید قیمت ناقص بود؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            invalid_product_id: 'شناسه این کالا معتبر نیست؛ فهرست را تازه کنید.',
            invalid_source_revision: 'نسخه منبع معتبر نیست؛ فهرست را تازه کنید و منبع فعلی را دوباره بررسی کنید.',
            invalid_note: 'یادداشت بررسی قیمت معتبر نیست؛ دوباره تلاش کنید.',
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
            telegram_account_changed: 'درخواست دسترسی تغییر کرده است؛ فهرست تلگرام را تازه کنید.',
            telegram_operator_unavailable: 'ثبت دسترسی تلگرام ناموفق بود؛ وضعیت درخواست را تازه کنید.',
            invalid_records: 'ثبت اطلاعات بررسی معتبر نبود؛ صفحه را تازه کنید.',
            reconciliation_unavailable: 'گزارش تطبیق موقتاً در دسترس نیست؛ وضعیت اتصال شیت و فروشگاه را بررسی کنید.',
            backlog_changed: 'صف هنگام اجرا تغییر کرد؛ صفحه را تازه کنید و وضعیت جدید را بررسی کنید.',
            catalog_projection_changed: 'شیت هنگام همگام‌سازی تغییر کرد؛ همگام‌سازی تازه در صف قرار گرفت و خطاها باز ماندند.',
            catalog_sync_incomplete: 'همگام‌سازی کامل نشد؛ تلاش‌های قبلی باز ماندند تا دوباره بررسی شوند.',
            backlog_reconcile_unavailable: 'تطبیق خطاهای قدیمی موقتاً انجام نشد؛ صف را تازه کنید و دوباره بررسی کنید.',
            reextraction_active: 'یک بازاستخراج دیگر هنوز در صف یا در حال اجراست؛ ابتدا وضعیت همان اجرا را پیگیری کنید.',
            reextraction_preview_changed: 'فهرست پیام‌ها تغییر کرده است؛ تعداد تازه را بررسی و دوباره شروع کنید.',
            invalid_reextraction_filter: 'ترکیب دامنه، کانال یا تاریخ معتبر نیست؛ محدودیت‌ها را اصلاح کنید.',
            no_reextraction_messages: 'پیامی برای بازاستخراج در این گروه پیدا نشد.',
            invalid_dismissal_request: 'درخواست رد گروهی معتبر نیست؛ صفحه را تازه کنید.',
            dismiss_failed: 'رد کردن همه موارد انجام نشد؛ صفحه را تازه کنید و موارد باقی‌مانده را بررسی کنید.',
            ai_extraction_disabled: 'برای استفاده از استخراج فقط با هوش مصنوعی، ابتدا سرویس سرور را به‌روزرسانی کنید.',
            ai_extraction_unconfigured: 'برای استخراج کالا، کلید GOOGLE_API_KEY را در تنظیمات سرویس اجرا تنظیم کنید.',
            unknown_reextraction_run: 'اجرای بازاستخراج پیدا نشد؛ فهرست را تازه کنید.',
            reextraction_sources_unavailable: 'بعضی کارت‌ها به پیام اصلی متصل نیستند؛ چیزی اجرا نشد تا منبع همه موارد مشخص شود.',
            reextraction_conflict: 'صف بازاستخراج تغییر کرده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            stale_media_review: 'این رسانه تغییر کرده یا قبلاً تعیین تکلیف شده است؛ فهرست را تازه کنید.',
            invalid_media_decision: 'درخواست تصمیم رسانه معتبر نیست؛ فهرست را تازه کنید.',
            invalid_media_page: 'صفحه رسانه‌ها معتبر نیست؛ فهرست را تازه کنید.',
            reextraction_price_not_verified: 'برای ثبت نهایی، قیمت را در منبع فعلی بررسی و گزینهٔ بررسی قیمت را علامت بزنید.',
            package_count_required: 'برای انتخاب قیمت بسته، تعداد در بسته یا کارتن باید مشخص باشد.',
            unknown_reference: 'این مرجع در نسخه فعال فهرست نیست؛ فهرست را تازه کنید.'
        };
        return labels[code] || 'ارتباط با سرور برقرار نشد. کد پیگیری: ' + (code || 'نامشخص');
    }

    async function request(method = 'GET', payload = undefined, url = '', apiKey = '') {
        const scopedApi = apiKey && config.workflowApis && config.workflowApis[apiKey];
        if (apiKey && !scopedApi) throw new Error('workflow_route_unavailable');
        if (!config.apiProxyUrl || !config.apiProxyNonce) throw new Error('workflow_proxy_unavailable');
        const target = new URL(url || (scopedApi && scopedApi.url) || config.apiUrl, window.location.href);
        const apiBase = new URL(config.apiUrl, window.location.href);
        if (target.origin !== apiBase.origin) throw new Error('workflow_route_unavailable');
        const formBody = new URLSearchParams({
            action: 'molavi_owner_workflow_proxy',
            nonce: config.apiProxyNonce,
            method: String(method).toUpperCase(),
            path: target.pathname,
            query: target.search,
        });
        if (payload !== undefined) formBody.set('payload', JSON.stringify(payload));
        const response = await fetch(config.apiProxyUrl, {method:'POST', mode:'same-origin',
            credentials:'same-origin', cache:'no-store',
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8',
                'Accept':'application/json'}, body:formBody});
        const body = await response.json().catch(() => null);
        if (!response.ok || !body || body.ok !== true) {
            const error = new Error(body && typeof body.error === 'string' ? body.error : 'request_failed');
            error.requestStatus = response.status;
            error.responseBody = body;
            error.remoteRejected = response.status >= 400 && response.status < 500
                && Boolean(body && body.ok === false);
            throw error;
        }
        if (method === 'POST' && window.parent !== window) {
            window.parent.postMessage({ type: 'molavi-owner-workflow-updated' }, window.location.origin);
        }
        return body;
    }

    async function requestSheetConflict(payload, apiKey = '') {
        try { return await request('POST', payload, undefined, apiKey); }
        catch (error) {
            // Keep the dashboard usable during the staggered release. The expected
            // revision remains in WordPress; newer Railway builds enforce it before acting.
            if (!payload || !Object.prototype.hasOwnProperty.call(payload, 'expected_revision')
                || !error.responseBody || error.responseBody.error !== 'invalid_body') throw error;
            const compatiblePayload = {...payload};
            delete compatiblePayload.expected_revision;
            return request('POST', compatiblePayload, undefined, apiKey);
        }
    }

    async function requestSupplierPrice(payload, apiKey = 'supplier_prices') {
        try { return await request('POST', payload, undefined, apiKey); }
        catch (error) {
            if (!payload || !Object.prototype.hasOwnProperty.call(payload, 'command_id')
                || !error.responseBody || error.responseBody.error !== 'invalid_body') throw error;
            const compatiblePayload = {...payload};
            delete compatiblePayload.command_id;
            const receipt = await request('POST', compatiblePayload, undefined, apiKey);
            return {...receipt,command_id:payload.command_id,compatibility_retry:true};
        }
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
        if (type === 'ai_media_review' && context && typeof context === 'object') {
            return {
                message_id:String(context.message_id || '').slice(0, 100),
                batch_id:String(context.batch_id || '').slice(0, 300),
                channel:String(context.channel || '').slice(0, 100),
                timestamp:String(context.timestamp || '').slice(0, 60),
                source_post_url:String(context.source_post_url || '').slice(0, 500),
                media_type:String(context.media_type || '').slice(0, 40),
                image_file_unique_id:String(context.image_file_unique_id || '').slice(0, 300)
            };
        }
        if (type === 'ai_reextraction' && context && typeof context === 'object') {
            return {
                run_id:String(context.run_id || '').slice(0, 64),
                scope:String(context.scope || 'open_cases').slice(0, 40),
                case_count:Math.max(0, Math.min(10000, Number(context.case_count) || 0)),
                message_count:Math.max(0, Math.min(10000, Number(context.message_count) || 0)),
                digest:String(context.digest || '').slice(0, 64),
                policy_version:String(context.policy_version || '').slice(0, 64),
                window_days:Math.max(0, Math.min(365, Number(context.window_days) || 0)),
                channels:Array.isArray(context.channels)
                    ? context.channels.slice(0, 30).map(value => String(value || '').slice(0, 200)) : [],
                date_from:String(context.date_from || '').slice(0, 10),
                date_to:String(context.date_to || '').slice(0, 10)
            };
        }
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
                source_timestamp_basis:String(context.source_timestamp_basis || '').slice(0, 80),
                source_created_at:String(context.source_created_at || '').slice(0, 60),
                source_updated_at:String(context.source_updated_at || context.source_update_at || '').slice(0, 60),
                source_post_url:String(context.source_post_url || '').slice(0, 500),
                media_ocr_text:String(context.media_ocr_text || '').slice(0, 3000),
                image_file_unique_id:String(context.image_file_unique_id || '').slice(0, 300),
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
                source_current_check_required:Boolean(context.source_current_check_required),
                previous_decision_id:String(context.previous_decision_id || '').slice(0, 64),
                duplicate_count:Math.max(1, Math.min(100, Number(context.duplicate_count) || 1)),
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
        const commands = {};
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
            if (body.data.commands && typeof body.data.commands === 'object') Object.assign(commands, body.data.commands);
        }
        return {stored:records.length,commands};
    }

    async function drainWordPressWorkflowOutbox() {
        if (!config.reviewStoreUrl || !config.reviewStoreNonce) return {attempted:0,delivered:0};
        const response = await fetch(config.reviewStoreUrl, {
            method:'POST', mode:'same-origin', credentials:'same-origin', cache:'no-store',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:new URLSearchParams({action:'molavi_owner_review_outbox_pending', nonce:config.reviewStoreNonce,
                limit:'10'}).toString()
        });
        const body = await response.json().catch(() => null);
        if (!response.ok || !body || body.success !== true || !body.data
            || !Array.isArray(body.data.commands)) throw new Error('review_store_failed');
        const commands = body.data.commands;
        let delivered = 0;
        for (const command of commands) {
            if (!command || !command.payload || !command.context || !command.decision
                || typeof command.event_id !== 'string' || typeof command.review_id !== 'string') continue;
            const type = command.command_type || command.review_type;
            const apiKey = type === 'supplier_price' ? 'supplier_prices' : 'sheet_conflicts';
            const selectedApiKey = config.workflowApis && config.workflowApis[apiKey] ? apiKey
                : (type === 'supplier_price' && config.page === 'molavi-supplier-prices' ? '' : null);
            if (selectedApiKey === null) continue;
            try {
                const receipt = type === 'supplier_price'
                    ? await requestSupplierPrice(command.payload, selectedApiKey)
                    : await requestSheetConflict(command.payload, selectedApiKey);
                const validReceipt = type === 'supplier_price'
                    ? receipt.product_id === command.payload.product_id
                        && receipt.source_revision === command.payload.expected_revision
                        && receipt.command_id === command.command_id && receipt.verified_at && receipt.publish_job
                    : receipt.conflict_id === command.payload.conflict_id
                        && receipt.choice === command.payload.choice
                        && (receipt.server_owned === true || receipt.sheet_preserved === true || receipt.publish_job);
                if (!validReceipt) {
                    const error = new Error('invalid_receipt');
                    error.remoteRejected = true;
                    throw error;
                }
                await persistOwnerReviews([reviewRecord(command.event_id,type,command.review_id,
                    'accepted',command.context,command.decision,receipt)]);
                delivered += 1;
            } catch (error) {
                try {
                    await persistOwnerReviews([reviewRecord(command.event_id,type,command.review_id,
                        error.remoteRejected ? 'failed' : 'pending',command.context,command.decision,
                        {error:String(error.message || 'request_failed')})]);
                } catch (_ignored) {}
            }
        }
        return {attempted:commands.length,delivered};
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

    let workbenchQueue = '';
    let workbenchStage = '';
    const workbenchFilters = {search:'', channelKeys:null, state:'all', catalogStatus:'all', from:'', to:'', includeUndated:false};
    let workbenchView = window.location.hash === '#reextract' ? 'reextract' : 'tasks';
    let workbenchIndex = 0;
    let workbenchSelectedKey = '';
    let workbenchLoadId = 0;
    let workbenchMounted = false;
    let workbenchBoard = null;
    let workbenchRefresh = null;
    let workbenchLiveStatus = null;
    let workbenchData = null;
    let workbenchErrors = {};
    let workbenchProductCardSequence = 0;

    function workbenchNameKey(name) {
        return String(name || '').normalize('NFKC').toLocaleLowerCase()
            .replace(/[\u200c\u200d\s،,؛;:()[\]{}\-_]+/g, ' ').trim();
    }

    function workbenchProductKey(item) {
        const name = workbenchNameKey(item && (item.product_name || item.name));
        const channel = String(item && (item.channel || item.channel_username) || '').trim().toLocaleLowerCase();
        return name ? `${channel}|${name}` : '';
    }

    function appendStatusPill(parent, label, value, kind = '') {
        const pill = node('span', `${label}: ${value}`, `molavi-status-pill${kind ? ` is-${kind}` : ''}`);
        parent.appendChild(pill);
        return pill;
    }

    function actionStatus(container, text, kind = 'info') {
        const status = node('div', null, `notice notice-${kind} molavi-ajax-status`);
        status.setAttribute('role', 'status');
        status.appendChild(node('p', text));
        container.prepend(status);
        return status;
    }

    function updateActionStatus(status, text, kind = 'info') {
        if (!status) return;
        status.className = `notice notice-${kind} molavi-ajax-status`;
        status.replaceChildren(node('p', text));
    }

    function workbenchPendingSupplierPrices(product) {
        return product.supplierPrices.filter(offer => offer.acknowledged !== true).length;
    }

    function workbenchActionKey(product) {
        if (product.status && Array.isArray(product.status.source_integrity_issues)
            && product.status.source_integrity_issues.length) return 'source_integrity';
        if (product.ai.length) return 'ai';
        if (product.references.length) return 'references';
        if (product.conflicts.length) return 'conflicts';
        if (workbenchPendingSupplierPrices(product)) return 'supplier_prices';
        if (product.holds.length) return 'holds';
        if (product.status && product.status.update_needed) return 'status';
        return 'next';
    }

    const workbenchStageModel = [
        {key:'intake',label:'ورودی و استخراج',hint:'پیام‌ها و بررسی مالک',queues:['ai']},
        {key:'identity',label:'هویت محصول',hint:'پیوند منبع و تطبیق مرجع',queues:['source_integrity','references']},
        {key:'price',label:'قیمت و مدرک',hint:'تازگی قیمت تأمین‌کننده',queues:['supplier_prices']},
        {key:'publish',label:'انتشار و همگام‌سازی',hint:'توقف و تعارض کاتالوگ',queues:['conflicts','holds','status']}
    ];

    function workbenchQueueMatches(product, key) {
        if (key === 'source_integrity') return Boolean(product.status
            && Array.isArray(product.status.source_integrity_issues)
            && product.status.source_integrity_issues.length);
        if (key === 'ai') return product.ai.length > 0;
        if (key === 'references') return product.references.length > 0;
        if (key === 'conflicts') return product.conflicts.length > 0;
        if (key === 'supplier_prices') return workbenchPendingSupplierPrices(product) > 0;
        if (key === 'holds') return product.holds.length > 0;
        if (key === 'status') return Boolean(product.status && product.status.update_needed);
        return false;
    }

    function workbenchActionForQueue(product, queue = workbenchQueue) {
        if (queue === 'next') return workbenchActionKey(product);
        const stage = workbenchStageModel.find(item => queue === `stage_${item.key}`);
        if (stage) return stage.queues.find(key => workbenchQueueMatches(product, key))
            || workbenchActionKey(product);
        return queue;
    }

    function workbenchNextStep(product, queue = workbenchQueue) {
        const key = workbenchActionForQueue(product, queue);
        if (key === 'source_integrity') return 'پیوند پیشنهاد منبع را بازیابی کنید؛ تا آن زمان کالا روی سایت متوقف می‌ماند.';
        if (key === 'ai') {
            const cases = Array.isArray(product.ai) ? product.ai : [];
            if (cases.some(item => item.publication_held)) return 'جزئیات و اقدام دستهٔ متوقف‌شده را باز کنید؛ تصمیم این استخراج تا رفع همان توقف قفل است.';
            if (cases.some(item => !normalizedAiInteger(item.sale_price || item.detected_price || item.package_price))) {
                return 'قیمت فروش در پیام ثبت نشده؛ مبلغ را حدس نزنید و این مورد را تا رسیدن قیمت باز بگذارید.';
            }
            if (cases.some(item => item.source_current_check_required || item.historical_reextract)) return 'زمان منبع به آستانهٔ ۱۴ روز رسیده یا قابل اتکا نیست؛ قیمت و موجودی را از منبع فعلی بررسی کنید.';
            if (cases.some(item => !item.reference_assignment && item.reference_snapshot_id)) return 'مرجع و مبنای قیمت واحد/بسته را انتخاب کنید؛ سپس مبلغ و موجودی را با پیام بسنجید.';
            if (cases.some(item => !item.reference_snapshot_id)) return 'فهرست مرجع فعال نیست؛ ابتدا فهرست مرجع کالاها را به‌روزرسانی کنید.';
            return 'قیمت و موجودی را با پیام اصلی بسنجید و مشخصات درست را تأیید کنید.';
        }
        if (key === 'references') return 'مرجع درست و مبنای قیمت را انتخاب کنید.';
        if (key === 'conflicts') return 'مقدار سرور را نگه دارید؛ فقط اگر خودتان شیت را دستی تغییر داده‌اید، اصلاح شیت را به فروشگاه بفرستید.';
        if (key === 'supplier_prices') return 'منبع پیام را باز کنید و تازگی قیمت را تأیید کنید.';
        if (key === 'holds') return 'جزئیات پیام‌های همان دسته را باز کنید و یک‌بار اقدام بازیابی را اجرا کنید.';
        if (key === 'status') return 'وضعیت انتشار کالا را در جزئیات فنی بررسی کنید.';
        return 'برای این کالا اقدام بازی ثبت نشده است.';
    }

    function workbenchTaskMeta(product, key) {
        const status = product.status || {};
        const issueCounts = {
            source_integrity:Array.isArray(status.source_integrity_issues) ? status.source_integrity_issues.length : 0,
            ai:Array.isArray(product.ai) ? product.ai.length : 0,
            references:Array.isArray(product.references) ? product.references.length : 0,
            conflicts:Array.isArray(product.conflicts) ? product.conflicts.length : 0,
            supplier_prices:workbenchPendingSupplierPrices(product),
            holds:Array.isArray(product.holds) ? product.holds.length : 0,
            status:status.update_needed ? 1 : 0
        };
        const count = issueCounts[key] || 0;
        const number = new Intl.NumberFormat('fa-IR').format(count);
        const labels = {
            source_integrity:'پیوند منبع', ai:'استخراج محصول', references:'تطبیق مرجع',
            supplier_prices:'قیمت تأمین‌کننده', conflicts:'تعارض همگام‌سازی',
            holds:'توقف انتشار', status:'وضعیت کالا', next:'کار مرتبط'
        };
        const titles = {
            source_integrity:'بازیابی پیوند پیشنهاد منبع',
            ai:`بازبینی ${number} مورد استخراج‌شده`,
            references:`تطبیق ${number} مورد با مرجع`,
            supplier_prices:`بررسی تازگی ${number} قیمت`,
            conflicts:`رسیدگی به ${number} تعارض همگام‌سازی`,
            holds:`بررسی ${number} توقف انتشار`,
            status:'بررسی وضعیت و انتشار کالا',
            next:'کار باز دیگری ثبت نشده است'
        };
        const stage = workbenchStageModel.find(item => item.queues.includes(key));
        return {
            label:labels[key] || labels.next,
            stage:stage ? stage.label : 'سایر موارد',
            stageKey:stage ? stage.key : 'other',
            title:titles[key] || titles.next,
            countLabel:count ? `${number} مورد باز` : 'در انتظار بررسی'
        };
    }

    function workbenchTaskKeysForProduct(product, queue) {
        if (queue === 'next') return workbenchStageModel.flatMap(stage => stage.queues)
            .filter((key, index, keys) => keys.indexOf(key) === index && workbenchQueueMatches(product, key));
        const stage = workbenchStageModel.find(item => queue === `stage_${item.key}`);
        if (stage) return stage.queues.filter(key => workbenchQueueMatches(product, key));
        return workbenchQueueMatches(product, queue) ? [queue] : [];
    }

    function workbenchTaskDates(product, queue) {
        const status = product.status || {};
        const values = [];
        const add = (...items) => items.forEach(value => {
            const raw = String(value || '').trim();
            if (raw) values.push(raw);
        });
        workbenchTaskKeysForProduct(product, queue).forEach(key => {
            if (key === 'source_integrity') add(status.source_timestamp, status.updated_at, status.changed_at);
            if (key === 'ai') product.ai.forEach(item => add(item.source_updated_at, item.source_update_at,
                item.message_timestamp, item.source_timestamp, item.source_created_at));
            if (key === 'references') product.references.forEach(item => add(item.source_timestamp,
                item.message_timestamp, item.timestamp, item.created_at, item.updated_at));
            if (key === 'supplier_prices') product.supplierPrices.forEach(item => add(item.source_timestamp));
            if (key === 'conflicts') product.conflicts.forEach(item => add(item.created_at, item.updated_at));
            if (key === 'holds') product.holds.forEach(item => add(item.created_at,
                item.context && item.context.created_at));
            if (key === 'status') add(status.source_timestamp, status.updated_at,
                status.last_updated_at, status.changed_at, status.last_changed_at);
        });
        return [...new Set(values)];
    }

    function workbenchTehranBoundary(day, end = false) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(day || ''))) return NaN;
        return Date.parse(`${day}T${end ? '23:59:59.999' : '00:00:00.000'}+03:30`);
    }

    function workbenchMatchesState(product, selectedState) {
        if (!selectedState || selectedState === 'all') return true;
        const status = product.status || {};
        const sourceIssues = Array.isArray(status.source_integrity_issues) && status.source_integrity_issues.length > 0;
        const staleAi = product.ai.some(item => item.source_current_check_required || item.historical_reextract);
        const stalePrices = product.supplierPrices.some(item => item.acknowledged !== true
            && ['warning','blocked','needs_review'].includes(item.state));
        const missingReference = product.references.length > 0 || product.ai.some(item =>
            !item.reference_snapshot_id || !item.reference_assignment);
        if (selectedState === 'blocked') return product.holds.length > 0 || sourceIssues
            || product.ai.some(item => item.publication_held)
            || product.supplierPrices.some(item => item.state === 'blocked' && item.acknowledged !== true);
        if (selectedState === 'owner_review') return product.ai.length > 0;
        if (selectedState === 'source_link') return sourceIssues;
        if (selectedState === 'stale_source') return staleAi || stalePrices;
        if (selectedState === 'reference') return missingReference;
        if (selectedState === 'conflict') return product.conflicts.length > 0;
        if (selectedState === 'publication_hold') return product.holds.length > 0;
        if (selectedState === 'catalog_update') return Boolean(status.update_needed);
        return true;
    }

    function workbenchMatchesFilters(product, queue) {
        const search = workbenchNameKey(workbenchFilters.search);
        if (search && !workbenchNameKey([product.name, product.product_id, product.channel].join(' ')).includes(search)) {
            return false;
        }
        if (workbenchFilters.channelKeys instanceof Set) {
            const channelKey = workbenchNameKey(product.channel) || '__unassigned__';
            if (!workbenchFilters.channelKeys.has(channelKey)) return false;
        }
        if (!workbenchMatchesState(product, workbenchFilters.state)) return false;
        if (workbenchFilters.catalogStatus === '__no_record__' && product.status) return false;
        if (workbenchFilters.catalogStatus === '__blank__'
            && (!product.status || String(product.status.status || '').trim())) return false;
        if (workbenchFilters.catalogStatus !== 'all'
            && !['__no_record__','__blank__'].includes(workbenchFilters.catalogStatus)
            && (!product.status || String(product.status.status || '').trim() !== workbenchFilters.catalogStatus)) return false;
        if (workbenchFilters.from || workbenchFilters.to) {
            const start = workbenchFilters.from ? workbenchTehranBoundary(workbenchFilters.from) : -Infinity;
            const end = workbenchFilters.to ? workbenchTehranBoundary(workbenchFilters.to, true) : Infinity;
            if (start > end) return false;
            const parsedDates = workbenchTaskDates(product, queue).map(sourceTimestampMillis);
            const dates = parsedDates.filter(Number.isFinite);
            const hasUndated = parsedDates.length === 0 || dates.length < parsedDates.length;
            if (!dates.some(value => value >= start && value <= end)
                && !(workbenchFilters.includeUndated && hasUndated)) return false;
        }
        return true;
    }

    function workbenchActiveFilterCount() {
        return Number(Boolean(String(workbenchFilters.search || '').trim()))
            + Number(workbenchFilters.channelKeys instanceof Set)
            + Number(workbenchFilters.state !== 'all')
            + Number(workbenchFilters.catalogStatus !== 'all')
            + Number(Boolean(workbenchFilters.from || workbenchFilters.to));
    }

    function workbenchPriority(product) {
        if (product.status && Array.isArray(product.status.source_integrity_issues)
            && product.status.source_integrity_issues.length) return 0;
        if (product.ai.length) return 1;
        if (product.references.length) return 2;
        if (product.conflicts.length) return 3;
        if (workbenchPendingSupplierPrices(product)) return 4;
        if (product.holds.length) return 5;
        if (product.status && product.status.update_needed) return 6;
        return 7;
    }

    async function dashboard(message = '', messageKind = 'info', refreshKeys = null) {
        if (!workbenchMounted) {
            shell('میز کار مولوی‌سل', 'کارها را بر اساس مرحله دنبال کنید؛ هر کارت یک گام بعدی روشن دارد.');
            const toolbar = node('div', null, 'molavi-workbench-toolbar');
            workbenchRefresh = node('button', 'به‌روزرسانی همه صف‌ها', 'button button-primary');
            workbenchRefresh.type = 'button';
            workbenchRefresh.addEventListener('click', () => dashboard());
            toolbar.appendChild(workbenchRefresh);
            root.appendChild(toolbar);
            workbenchLiveStatus = node('p', 'در حال آماده‌سازی صف رسیدگی…', 'molavi-workbench-live-status');
            workbenchLiveStatus.setAttribute('role', 'status');
            workbenchLiveStatus.setAttribute('aria-live', 'polite');
            root.appendChild(workbenchLiveStatus);
            workbenchBoard = node('section', null, 'molavi-workbench-board');
            workbenchBoard.appendChild(node('p', 'در حال دریافت موارد نیازمند رسیدگی…'));
            root.appendChild(workbenchBoard);
            workbenchMounted = true;
        }
        const thisLoad = ++workbenchLoadId;
        workbenchRefresh.disabled = true;
        workbenchRefresh.textContent = 'در حال تازه‌سازی همه صف‌ها…';
        try {
            const outbox = await drainWordPressWorkflowOutbox();
            if (outbox.delivered) {
                message = `${new Intl.NumberFormat('fa-IR').format(outbox.delivered)} تصمیم ذخیره‌شده در وردپرس به Railway تحویل شد.`;
                messageKind = 'success';
            }
        } catch (_error) {
            if (!message) {
                message = 'صف تصمیم‌های ذخیره‌شده در وردپرس موقتاً خوانده نشد؛ موارد باز همچنان در انتظار می‌مانند.';
                messageKind = 'warning';
            }
        }
        if (message) {
            workbenchLiveStatus.className = `notice notice-${messageKind} molavi-workbench-live-status`;
            workbenchLiveStatus.textContent = message;
        } else {
            workbenchLiveStatus.className = 'molavi-workbench-live-status';
            workbenchLiveStatus.textContent = 'در حال تازه‌سازی صف رسیدگی…';
        }
        const board = workbenchBoard;
        let refreshQueueCounts = () => 0;

        const labels = {
            product_status:'وضعیت کاتالوگ', ai_confirmations:'استخراج', reference_items:'تطبیق مرجع',
            supplier_prices:'تازگی قیمت تأمین‌کننده', sheet_conflicts:'تعارض همگام‌سازی',
            publication_holds:'توقف دسته', reference_decisions:'وضعیت فهرست مرجع', operations:'صف پردازش'
        };
        const load = async (key, requestKey = key) => {
            try { return {data:await request('GET', undefined, undefined, requestKey), error:''}; }
            catch (error) { return {data:null, error:`${labels[key] || key}: ${errorText(error.message)}`}; }
        };
        const keys = ['product_status','ai_confirmations','reference_items','supplier_prices',
            'sheet_conflicts','publication_holds','reference_decisions'];
        const allKeys = [...keys, 'operations'];
        const requested = new Set(Array.isArray(refreshKeys) ? refreshKeys : allKeys);
        const loaded = await Promise.all(allKeys.map(key => {
            if (!requested.has(key) && workbenchData && Object.prototype.hasOwnProperty.call(workbenchData, key)) {
                return Promise.resolve({data:workbenchData[key], error:''});
            }
            return load(key, key === 'operations' ? '' : key);
        }));
        if (thisLoad !== workbenchLoadId) return;
        const data = {...(workbenchData || {})};
        allKeys.forEach((key,index) => {
            if (loaded[index].data !== null) data[key] = loaded[index].data;
            else if (!Object.prototype.hasOwnProperty.call(data, key)) data[key] = null;
        });
        workbenchData = data;
        const errors = {...workbenchErrors};
        allKeys.forEach((key,index) => {
            if (requested.has(key)) errors[key] = loaded[index].error;
        });
        workbenchErrors = errors;
        const operations = data.operations;
        const unavailable = Object.values(workbenchErrors).filter(Boolean);
        board.replaceChildren();
        const overview = node('div', null, 'molavi-workbench-overview');
        workbenchRefresh.disabled = false;
        workbenchRefresh.textContent = 'به‌روزرسانی همه صف‌ها';
        if (!message) {
            workbenchLiveStatus.className = 'molavi-workbench-live-status';
            workbenchLiveStatus.textContent = 'صف آماده است؛ اقدام‌ها بدون خروج از این صفحه ثبت می‌شوند.';
        }

        const products = [];
        const byProductId = new Map();
        const byOfferId = new Map();
        const byNameChannel = new Map();
        const rememberName = product => {
            const key = workbenchProductKey(product);
            if (!key) return;
            if (!byNameChannel.has(key)) byNameChannel.set(key, new Set());
            byNameChannel.get(key).add(product);
        };
        const ensureProduct = item => {
            const productId = String(item && item.product_id || '').trim();
            const offerId = String(item && (item.latest_offer_id || item.offer_id) || '').trim();
            let product = productId && byProductId.get(productId);
            if (!product && offerId) product = byOfferId.get(offerId);
            const nameKey = workbenchProductKey(item);
            if (!product && nameKey && byNameChannel.has(nameKey)) {
                const matches = byNameChannel.get(nameKey);
                if (matches.size === 1) product = [...matches][0];
            }
            if (!product) {
                const key = productId ? `product:${productId}` : offerId ? `offer:${offerId}`
                    : nameKey ? `name:${nameKey}` : `item:${products.length}`;
                product = {key, product_id:productId, name:String(item && (item.product_name || item.name) || 'کالای بدون نام'),
                    channel:String(item && (item.channel || item.channel_username) || ''),
                    status:null, ai:[], references:[], supplierPrices:[], conflicts:[], holds:[]};
                products.push(product);
            }
            if (!product.product_id && productId) product.product_id = productId;
            if ((!product.name || product.name === 'کالای بدون نام') && item && (item.product_name || item.name)) {
                product.name = String(item.product_name || item.name);
            }
            if (!product.channel && item && (item.channel || item.channel_username)) {
                product.channel = String(item.channel || item.channel_username);
            }
            if (productId) byProductId.set(productId, product);
            if (offerId) byOfferId.set(offerId, product);
            rememberName(product);
            return product;
        };

        (data.product_status && Array.isArray(data.product_status.products) ? data.product_status.products : [])
            .forEach(item => { const product = ensureProduct(item); product.status = item; });
        (data.ai_confirmations && Array.isArray(data.ai_confirmations.cases) ? data.ai_confirmations.cases : [])
            .forEach(item => ensureProduct(item).ai.push(item));
        (data.reference_items && Array.isArray(data.reference_items.cases) ? data.reference_items.cases : [])
            .forEach(item => ensureProduct(item).references.push(item));
        (data.supplier_prices && Array.isArray(data.supplier_prices.offers) ? data.supplier_prices.offers : [])
            .forEach(item => ensureProduct(item).supplierPrices.push(item));
        (data.sheet_conflicts && Array.isArray(data.sheet_conflicts.conflicts) ? data.sheet_conflicts.conflicts : [])
            .forEach(item => ensureProduct(item).conflicts.push(item));

        const aiCasesBySource = new Map();
        const allAiCases = [
            ...((data.ai_confirmations && Array.isArray(data.ai_confirmations.cases)) ? data.ai_confirmations.cases : []),
            ...((data.ai_confirmations && Array.isArray(data.ai_confirmations.recent_decisions)) ? data.ai_confirmations.recent_decisions : [])
        ];
        allAiCases.forEach(item => {
            const key = aiSourceMessageKey(item);
            if (!key) return;
            if (!aiCasesBySource.has(key)) aiCasesBySource.set(key, new Map());
            const identifier = String(item.offer_id || item.case_id || '');
            if (identifier) aiCasesBySource.get(key).set(identifier, item);
        });
        const aiSourceCoverageLead = new Map();
        ((data.ai_confirmations && Array.isArray(data.ai_confirmations.cases)) ? data.ai_confirmations.cases : [])
            .forEach(item => {
                const key = aiSourceMessageKey(item);
                if (key && !aiSourceCoverageLead.has(key)) aiSourceCoverageLead.set(key, item.case_id);
            });

        const holds = data.publication_holds && Array.isArray(data.publication_holds.holds)
            ? data.publication_holds.holds : [];
        const holdReviewRecords = holds.map(hold => reviewRecord(
            `context:publication_hold:${hold.hold_id}`, 'publication_hold', hold.hold_id,
            'observed', storedReviewContext('publication_hold', hold.context || {reason:hold.reason, created_at:hold.created_at})
        ));
        let holdsContextStored = true;
        if (holdReviewRecords.length) {
            try { await persistOwnerReviews(holdReviewRecords); }
            catch (_error) { holdsContextStored = false; }
        }
        const batchCards = [];
        holds.forEach(hold => {
            const related = products.filter(product => product.ai.some(item => String(item.batch_id || '') === String(hold.hold_id || '')));
            related.forEach(product => product.holds.push(hold));
            // A batch can cover multiple products. Keep its shared retry decision in
            // one batch card, while showing the held state on each affected product.
            batchCards.push({hold, relatedCount:related.length});
        });

        // Merge only extraction-only siblings whose source, name base, price,
        // package and review terms match exactly. Each case remains a separate
        // server decision, but the owner can review equal-price flavors together.
        groupAiVariantCases((data.ai_confirmations && Array.isArray(data.ai_confirmations.cases))
            ? data.ai_confirmations.cases : []).forEach(items => {
            if (items.length < 2) return;
            const siblings = items.map(item => byOfferId.get(String(item.offer_id || '')))
                .filter(Boolean);
            if (siblings.length !== items.length || new Set(siblings).size !== siblings.length) return;
            if (siblings.some(product => product.product_id || product.status || product.references.length
                || product.supplierPrices.length || product.conflicts.length || product.holds.length
                || product.ai.length !== 1)) return;
            const primary = siblings[0];
            primary.name = aiVariantBaseName(items[0]);
            primary.ai = items.slice();
            const siblingSet = new Set(siblings.slice(1));
            for (let index = products.length - 1; index >= 0; index--) {
                if (siblingSet.has(products[index])) products.splice(index, 1);
            }
        });

        const contextGroups = [
            ['ai_confirmations','ai_confirmation',(data.ai_confirmations && data.ai_confirmations.cases) || [], item => item.case_id, item => item],
            ['reference_items','reference_item',(data.reference_items && data.reference_items.cases) || [], item => item.case_id, item => item],
            ['sheet_conflicts','sheet_conflict',(data.sheet_conflicts && data.sheet_conflicts.conflicts) || [], item => item.conflict_id, item => storedReviewContext('sheet_conflict', item)]
        ];
        const contextStored = {ai_confirmations:true,reference_items:true,sheet_conflicts:true,supplier_prices:true};
        for (const [source, type, rows, getId, getContext] of contextGroups) {
            if (!rows.length) continue;
            try {
                await persistOwnerReviews(rows.map(item => reviewRecord(
                    `context:${type}:${getId(item)}`, type,
                    getId(item), 'observed', getContext(item)
                )));
            } catch (_error) { contextStored[source] = false; }
        }
        const supplierCases = data.supplier_prices && Array.isArray(data.supplier_prices.offers)
            ? data.supplier_prices.offers : [];
        if (supplierCases.length) {
            try {
                await persistOwnerReviews(supplierCases.map(item => reviewRecord(
                    `context:supplier_price:${item.product_id}`,'supplier_price',item.product_id,'observed',{
                        product_id:item.product_id,name:item.name,channel:item.channel,sale_price:item.sale_price,
                        source_timestamp:item.source_timestamp,source_revision:item.source_revision
                    }
                )));
            } catch (_error) { contextStored.supplier_prices = false; }
        }

        const attention = product => Boolean(product.status && product.status.update_needed)
            || product.ai.length > 0 || product.references.length > 0 || workbenchPendingSupplierPrices(product) > 0
            || product.conflicts.length > 0 || product.holds.length > 0;
        products.sort((a,b) => Number(attention(b))-Number(attention(a))
            || workbenchPriority(a)-workbenchPriority(b) || a.name.localeCompare(b.name,'fa'));
        const openProducts = products.filter(attention);
        const summary = node('section', null, 'card molavi-workbench-summary');
        summary.appendChild(node('h2', 'نمای کلی کارها'));
        summary.appendChild(node('p', openProducts.length
            ? `${new Intl.NumberFormat('fa-IR').format(openProducts.length)} کالا دست‌کم یک گام باز دارد. مرحله را انتخاب کنید تا فقط همان کارها دیده شوند.`
            : 'فعلاً موردی برای رسیدگی وجود ندارد.', 'molavi-workbench-summary-copy'));
        if (data.product_status) {
            const snapshots = Array.isArray(data.product_status.snapshots) ? data.product_status.snapshots : [];
            const latestSnapshot = snapshots.find(item => item.snapshot_id === data.product_status.latest_snapshot_id);
            const stamp = latestSnapshot && latestSnapshot.captured_at || data.product_status.generated_at || '';
            if (stamp) summary.appendChild(node('small', `آخرین ثبت وضعیت: ${stamp}`));
        }
        overview.appendChild(summary);
        if (unavailable.length) {
            const failedSources = node('details', null, 'molavi-source-health');
            failedSources.appendChild(node('summary', `${unavailable.length} منبع وضعیت در دسترس نیست`));
            unavailable.forEach(text => failedSources.appendChild(node('p', text)));
            overview.appendChild(failedSources);
        }

        const renderProduct = product => {
            const primaryKey = workbenchActionForQueue(product, workbenchQueue);
            const taskMeta = workbenchTaskMeta(product, primaryKey);
            const card = node('article', null, 'card molavi-review-card molavi-product-card');
            const primaryContent = node('div', null, 'molavi-product-primary-actions');
            const secondaryContent = node('div', null, 'molavi-secondary-product-checks');
            const relatedTaskKeys = new Set();
            const primaryTaskTargetId = `molavi-product-action-${++workbenchProductCardSequence}`;
            let primaryTargetAssigned = false;
            const secondaryChecks = document.createElement('details');
            secondaryChecks.className = 'molavi-secondary-product-details';
            const secondarySummary = node('summary', 'موارد مرتبط همین کالا');
            secondaryChecks.appendChild(secondarySummary);
            const taskSummary = node('section', null, 'molavi-product-task-summary');
            taskSummary.dataset.taskStage = taskMeta.stageKey;
            taskSummary.dataset.taskType = primaryKey;
            const taskCopy = node('div', null, 'molavi-product-task-copy');
            taskCopy.appendChild(node('p', `${taskMeta.stage} · ${taskMeta.label}`, 'molavi-product-task-kicker'));
            const taskTitle = node('h3', taskMeta.title);
            taskCopy.append(taskTitle, node('p', workbenchNextStep(product), 'molavi-product-next-step'));
            const taskActions = node('div', null, 'molavi-product-task-actions');
            const taskCount = node('span', taskMeta.countLabel, 'molavi-product-task-count');
            taskActions.appendChild(taskCount);
            const actionJump = ownerWorkflowAnchor(primaryTaskTargetId, 'رفتن به گزینه‌های اقدام');
            actionJump.hidden = true;
            taskActions.appendChild(actionJump);
            taskSummary.append(taskCopy, taskActions);
            const addWorkflowSection = (section, key) => {
                section.dataset.taskType = key;
                if (key === primaryKey) {
                    section.classList.add('molavi-product-primary-action');
                    if (!primaryTargetAssigned) {
                        section.id = primaryTaskTargetId;
                        primaryTargetAssigned = true;
                        actionJump.hidden = false;
                    }
                    primaryContent.appendChild(section);
                } else {
                    section.classList.add('molavi-product-related-action');
                    relatedTaskKeys.add(key);
                    secondaryContent.appendChild(section);
                }
            };
            if (product.holds.length) card.classList.add('is-held');
            if (product.conflicts.length) card.classList.add('has-conflict');
            const header = node('div', null, 'molavi-product-card-heading');
            header.appendChild(node('h2', product.name));
            const state = product.holds.length ? 'انتشار متوقف'
                : product.conflicts.length ? 'تعارض همگام‌سازی'
                    : attention(product) ? 'نیازمند اقدام' : 'وضعیت ثبت‌شده';
            appendStatusPill(header, 'کار اصلی', taskMeta.label, attention(product) ? 'warning' : '');
            const statePill = appendStatusPill(header, 'وضعیت', state, attention(product) ? 'warning' : 'success');
            card.appendChild(header);
            card.appendChild(taskSummary);
            const nextStep = taskCopy.querySelector('.molavi-product-next-step');
            const status = product.status || {};
            const identityFacts = [];
            if (product.channel) identityFacts.push(['تأمین‌کننده', product.channel]);
            if (identityFacts.length) card.appendChild(reviewFacts(identityFacts,
                'molavi-review-facts molavi-product-identity'));

            const stateRow = node('div', null, 'molavi-product-state-row');
            let supplierPricePill = null;
            if (status.reference_match_id || status.reference_match_name) appendStatusPill(stateRow, 'مرجع',
                status.reference_match_name || 'تطبیق ثبت شده');
            else if (status.reference_approval_state) appendStatusPill(stateRow, 'مرجع', readableWorkflowStatus(status.reference_approval_state),
                status.reference_update_needed ? 'warning' : '');
            if (status.reference_price_role) appendStatusPill(stateRow, 'مبنای قیمت مرجع',
                status.reference_price_role === 'package' ? 'بسته' : status.reference_price_role === 'unit' ? 'واحد' : status.reference_price_role);
            if (status.reference_price_change_percent) appendStatusPill(stateRow, 'تغییر مرجع', `${status.reference_price_change_percent}٪`, status.reference_update_needed ? 'warning' : '');
            if (status.supplier_price_state) supplierPricePill = appendStatusPill(stateRow, 'قیمت تأمین‌کننده', readableWorkflowStatus(status.supplier_price_state),
                ['blocked','needs_review'].includes(status.supplier_price_state) ? 'warning' : '');
            if (status.update_needed) appendStatusPill(stateRow, 'به‌روزرسانی', 'لازم است', 'warning');
            if (product.holds.length) appendStatusPill(stateRow, 'انتشار', 'متوقف', 'warning');
            if (stateRow.childNodes.length) card.appendChild(stateRow);
            const refreshInPlace = async (message, kind, statusNode, refreshKeys) => {
                const scrollX = window.scrollX;
                const scrollY = window.scrollY;
                updateActionStatus(statusNode, message, kind);
                await dashboard(message, kind, refreshKeys);
                window.scrollTo({left:scrollX,top:scrollY,behavior:'instant'});
            };
            groupAiVariantCases(product.ai).forEach(relatedItems => {
                const item = relatedItems[0];
                const sourceKey = aiSourceMessageKey(item);
                const sourceCases = sourceKey && aiCasesBySource.has(sourceKey)
                    ? [...aiCasesBySource.get(sourceKey).values()] : [item];
                const isSourceLead = sourceKey && aiSourceCoverageLead.get(sourceKey) === item.case_id;
                const sourcePriceGap = isSourceLead ? aiSourcePriceGap(item, sourceCases) : null;
                const sourceVariantGap = isSourceLead ? aiSourceVariantGap(item, sourceCases) : null;
                const sourceStockGap = isSourceLead ? aiSourceStockGap(item, sourceCases) : null;
                const ai = aiCaseCard(item, null, contextStored.ai_confirmations,
                    !relatedItems.some(row => row.publication_held), relatedItems, {
                    embedded:true, apiKey:'ai_confirmations', refresh:refreshInPlace,
                    sourcePriceGap, sourceVariantGap, sourceStockGap,
                    holdTarget:product.holds.length
                        ? `molavi-batch-${batchCards.findIndex(entry => entry.hold === product.holds[0])}` : '',
                    parentName:product.name, parentChannel:product.channel
                });
                const title = node('h3', 'استخراج از پیام تأمین‌کننده');
                ai.prepend(title);
                addWorkflowSection(ai, 'ai');
            });
            product.references.forEach(item => {
                const reference = referenceItemCard(item, contextStored.reference_items, {
                    embedded:true, apiKey:'reference_items', refresh:refreshInPlace,
                    parentName:product.name, parentChannel:product.channel
                });
                reference.prepend(node('h3', 'تطبیق و مبنای قیمت مرجع'));
                addWorkflowSection(reference, 'references');
            });
            product.supplierPrices.forEach(offer => {
                const section = node('section', null, 'molavi-product-subsection molavi-review-block molavi-supplier-price-review');
                section.appendChild(node('h3', 'تازگی قیمت تأمین‌کننده'));
                const statusText = offer.acknowledged
                    ? 'قیمت تأیید شد؛ انتشار دوباره در صف است'
                    : ({warning:'نیاز به بررسی تازه',blocked:'قیمت کهنه؛ سفارش متوقف است',needs_review:'مدرک قیمت نیاز به بررسی دارد'}[offer.state] || 'نیازمند بررسی');
                const timeLabel=offer.source_timestamp_trusted?'آخرین به‌روزرسانی منبع':'زمان پیام ورودی';
                section.appendChild(reviewFacts([
                    ...(!offer.channel && !product.channel ? [['تأمین‌کننده', 'نامشخص']] : []),
                    ['قیمت ثبت‌شده', amount(offer.sale_price)],
                    ['وضعیت', statusText],
                    [timeLabel, offer.source_timestamp
                        ? `${formatSourceTimestamp(offer.source_timestamp)} · ${sourceAgeText(offer.source_timestamp)}`
                        : 'ثبت نشده']
                ]));
                const sourceLink = safeSourceLink(offer.source_post_url);
                if (sourceLink) {
                    const tools = node('div', null, 'molavi-card-source-tools');
                    tools.appendChild(sourceLink);
                    section.appendChild(tools);
                }
                if (!offer.source_timestamp_trusted && !offer.acknowledged) {
                    section.appendChild(notice('این پیام از حساب مالک رسیده یا زمان اصلی تأمین‌کننده را ندارد؛ تاریخ ارسال به‌تنهایی تازگی قیمت را ثابت نمی‌کند. قیمت را از منبع فعلی بررسی کنید. این تأیید فقط قیمت است و وضعیت موجودی را تغییر نمی‌دهد.', 'warning'));
                }
                const sourceRevision = String(offer.source_revision || '').trim();
                const verify = node('button', offer.acknowledged ? 'قیمت تأیید شد' : 'منبع فعلی را دیدم؛ قیمت تأیید است', 'button button-primary');
                verify.type = 'button';
                verify.disabled = offer.acknowledged || !sourceRevision || !contextStored.supplier_prices;
                if (!offer.acknowledged && !sourceRevision) {
                    section.appendChild(notice('برای این ردیف قیمت فروش معتبر در منبع فعلی ثبت نشده است؛ ابتدا داده قیمت را اصلاح کنید. این مورد قابل تأیید نیست.', 'warning'));
                } else if (!offer.acknowledged && !contextStored.supplier_prices) {
                    section.appendChild(notice('ثبت زمینه این بررسی در پایگاه داده وردپرس ناموفق بود؛ اقدام غیرفعال است.', 'error'));
                }
                if (!offer.acknowledged && sourceRevision) verify.addEventListener('click', async () => {
                    verify.disabled = true;
                    const originalLabel = verify.textContent;
                    verify.textContent = 'در حال ثبت…';
                    const progressStatus = actionStatus(section, 'در حال ثبت تأیید قیمت با درخواست AJAX…');
                    const eventId = reviewEventId('supplier_price', offer.product_id);
                    const context = {product_id:offer.product_id,name:offer.name,channel:offer.channel,
                        sale_price:offer.sale_price,source_timestamp:offer.source_timestamp,
                        source_revision:offer.source_revision};
                    const decision = {action:'verify_current_supplier_price',note:'Owner checked the current supplier source.'};
                    try {
                        const stored = await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,'pending',context,decision)]);
                        const commandId = stored.commands[eventId];
                        if (!commandId) throw new Error('review_store_failed');
                        const receipt = await requestSupplierPrice({product_id:offer.product_id,
                            expected_revision:offer.source_revision,note:'مالک منبع فعلی قیمت را از پنل بررسی تأیید کرد.',
                            command_id:commandId},'supplier_prices');
                        if (receipt.product_id !== offer.product_id || receipt.source_revision !== offer.source_revision
                            || receipt.command_id !== commandId || !receipt.verified_at || !receipt.publish_job) throw new Error('invalid_receipt');
                        let warning = '';
                        try { await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,'accepted',context,decision,receipt)]); }
                        catch (_error) { warning = ' قیمت تأیید شد، اما رسید محلی وردپرس هنوز همگام نشده است.'; }
                        offer.acknowledged = true;
                        verify.textContent = 'قیمت تأیید شد';
                        if (supplierPricePill && !workbenchPendingSupplierPrices(product)) {
                            supplierPricePill.textContent = 'قیمت تأمین‌کننده: تأیید شد · انتشار در صف';
                            supplierPricePill.className = 'molavi-status-pill is-success';
                        }
                        const remainingPrices = workbenchPendingSupplierPrices(product);
                        if (remainingPrices) {
                            const updatedTask = workbenchTaskMeta(product, 'supplier_prices');
                            taskTitle.textContent = updatedTask.title;
                            taskCount.textContent = updatedTask.countLabel;
                            nextStep.textContent = workbenchNextStep(product, 'supplier_prices');
                        } else {
                            taskSummary.classList.add('is-complete');
                            taskTitle.textContent = 'بررسی قیمت تکمیل شد';
                            taskCount.textContent = 'تأیید ثبت شد';
                            nextStep.textContent = 'منبع بررسی شد و انتشار دوباره در صف قرار گرفت.';
                            actionJump.hidden = true;
                        }
                        const remaining = refreshQueueCounts();
                        const message = `بررسی قیمت ثبت شد و انتشار دوباره در صف قرار گرفت.${warning}`
                            + (workbenchQueue === 'supplier_prices' && remaining === 0
                                ? ' همه موارد این صف رسیدگی شدند.' : '');
                        updateActionStatus(progressStatus, message, warning ? 'warning' : 'success');
                        if (workbenchLiveStatus) {
                            workbenchLiveStatus.className = `notice notice-${warning ? 'warning' : 'success'} molavi-workbench-live-status`;
                            workbenchLiveStatus.textContent = message;
                        }
                        const needsAttention = attention(product);
                        statePill.textContent = `وضعیت: ${needsAttention ? 'نیازمند اقدام' : 'وضعیت ثبت‌شده'}`;
                        statePill.className = `molavi-status-pill${needsAttention ? ' is-warning' : ' is-success'}`;
                    } catch (error) {
                        try { await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,error.remoteRejected?'failed':'pending',context,decision,{error:String(error.message||'request_failed')})]); }
                        catch (_ignored) {}
                        verify.textContent = originalLabel;
                        updateActionStatus(progressStatus, errorText(error.message), 'error');
                        errorBox(errorText(error.message), section); verify.disabled = !sourceRevision || !contextStored.supplier_prices;
                    }
                });
                const actions = reviewActions();
                actions.appendChild(verify);
                section.appendChild(actions);
                addWorkflowSection(section, 'supplier_prices');
            });
            product.conflicts.forEach(conflict => {
                const section = node('section', null, 'molavi-product-subsection molavi-review-block molavi-product-conflict');
                section.appendChild(node('h3', `تعارض داده · ${conflict.field || 'فیلد نامشخص'}`));
                section.appendChild(reviewFacts([
                    ['آخرین مقدار هماهنگ‌شده', conflictValue(conflict.base)],
                    ['مقدار فعلی سرور', conflictValue(conflict.server)],
                    ['مقدار فعلی شیت', conflictValue(conflict.sheet)]
                ], 'molavi-review-facts molavi-conflict-values'));
                const serverOwned = conflict.server_owned === true;
                const sheetPreserved = conflict.sheet_preserved === true;
                if (!serverOwned && !sheetPreserved) {
                    section.appendChild(notice('هر دو مقدار از آخرین هماهنگی تغییر کرده‌اند. مقدار سرور پیش‌فرض است؛ فقط اگر خودتان این سلول را دستی در شیت اصلاح کرده‌اید، مقدار شیت را به فروشگاه بفرستید.', 'info'));
                }
                const sourceRevision = String(conflict.source_revision || '');
                const relatedDecision = sourceRevision.startsWith('owner-confirm:')
                    ? ((data.ai_confirmations && Array.isArray(data.ai_confirmations.recent_decisions)
                        ? data.ai_confirmations.recent_decisions : []).find(item =>
                            String(item && item.decision && item.decision.decision_id || '')
                                === sourceRevision.slice('owner-confirm:'.length)))
                    : null;
                if (relatedDecision) {
                    const choiceLabel = relatedDecision.decision.choice === 'not_product'
                        ? 'مالک قبلاً «کالا نیست» را ثبت کرده است'
                        : relatedDecision.decision.choice === 'confirm'
                            ? 'مالک قبلاً استخراج را تأیید کرده است'
                            : `تصمیم قبلی مالک: ${relatedDecision.decision.choice || 'نامشخص'}`;
                    const context = node('details', null, 'molavi-conflict-source-context');
                    context.appendChild(node('summary', 'منشأ مقدار سرور'));
                    const when = formatSourceTimestamp(relatedDecision.decision.created_at);
                    context.appendChild(node('p', `${choiceLabel}${when ? ` · ${when}` : ''}`));
                    if (relatedDecision.source_post_url) {
                        const sourceLink = node('a', 'بازکردن پیام منبع');
                        sourceLink.href = String(relatedDecision.source_post_url);
                        sourceLink.target = '_blank';
                        sourceLink.rel = 'noopener noreferrer';
                        context.appendChild(sourceLink);
                    }
                    section.appendChild(context);
                }
                const actions = reviewActions('molavi-product-actions molavi-review-actions');
                const useServer = node('button', sheetPreserved ? 'حفظ هویت اصلی کالا'
                    : serverOwned ? 'همگام‌سازی مقدار سیستمی از سرور'
                    : 'اعمال مقدار فعلی سرور', 'button button-primary');
                const useSheet = node('button', 'فرستادن مقدار شیت به فروشگاه', 'button button-secondary');
                useServer.type = 'button'; useSheet.type = 'button';
                useServer.disabled = !contextStored.sheet_conflicts;
                useSheet.disabled = serverOwned || sheetPreserved || !contextStored.sheet_conflicts;
                const submitConflictChoice = async choice => {
                    useServer.disabled = true; useSheet.disabled = true;
                    const progressStatus = actionStatus(section, 'در حال ثبت تصمیم تعارض با درخواست AJAX…');
                    const eventId = reviewEventId('sheet_conflict', conflict.conflict_id);
                    const note = choice === 'server'
                        ? (serverOwned ? 'Synchronize this server-owned field back to the Sheet.' : 'Auto: keep the current server-computed value.')
                        : choice === 'preserve' ? 'Preserve the existing canonical Sheet identity or import timestamp.'
                        : 'Owner confirmed this was a manual Sheet edit; publish it to WordPress.';
                    const decision = {choice,attempt_id:eventId,note};
                    try {
                        await persistOwnerReviews([reviewRecord(eventId,'sheet_conflict',conflict.conflict_id,'pending',conflict,decision)]);
                        const receipt = await requestSheetConflict({conflict_id:conflict.conflict_id,choice,note,
                            expected_revision:conflict.source_revision},'sheet_conflicts');
                        if (receipt.conflict_id !== conflict.conflict_id || receipt.choice !== choice
                            || (serverOwned ? receipt.server_owned !== true : sheetPreserved ? receipt.sheet_preserved !== true : !receipt.publish_job)) throw new Error('invalid_receipt');
                        let warning = '';
                        try { await persistOwnerReviews([reviewRecord(eventId,'sheet_conflict',conflict.conflict_id,'accepted',conflict,decision,receipt)]); }
                        catch (_error) { warning = ' تصمیم ثبت شد، اما رسید وردپرس هنوز همگام نشده است.'; }
                        const message = `${serverOwned ? 'مقدار سیستمی با سرور همگام شد.' : sheetPreserved ? 'مقدار اصلی شیت حفظ شد.' : 'اصلاح دستی به فروشگاه فرستاده شد.'}${warning}`;
                        updateActionStatus(progressStatus, message, warning ? 'warning' : 'success');
                        await dashboard(message, warning ? 'warning' : 'success',
                            ['sheet_conflicts','product_status','supplier_prices','operations']);
                    } catch (error) {
                        try { await persistOwnerReviews([reviewRecord(eventId,'sheet_conflict',conflict.conflict_id,error.remoteRejected?'failed':'pending',conflict,decision,{error:String(error.message||'request_failed')})]); }
                        catch (_ignored) {}
                        if (error.message === 'source_changed' && error.responseBody
                            && error.responseBody.refreshed === true) {
                            await dashboard('نسخهٔ این تعارض کهنه بود؛ اطلاعات تازه بارگذاری شد. موردهای باقی‌مانده را بررسی کنید.', 'info', ['sheet_conflicts']);
                            return;
                        }
                        if (error.message === 'conflict_changed') {
                            await dashboard('این تعارض هم‌زمان تغییر کرد؛ صف تازه شد تا فقط موارد باز را ببینید.', 'info', ['sheet_conflicts']);
                            return;
                        }
                        updateActionStatus(progressStatus, errorText(error.message), 'error');
                        errorBox(errorText(error.message),section); useServer.disabled = !contextStored.sheet_conflicts;
                        useSheet.disabled = serverOwned || sheetPreserved || !contextStored.sheet_conflicts;
                    }
                };
                useServer.addEventListener('click',() => submitConflictChoice(sheetPreserved?'preserve':'server'));
                useSheet.addEventListener('click',() => submitConflictChoice('sheet'));
                actions.append(useServer);
                if (!serverOwned && !sheetPreserved) actions.append(useSheet);
                section.appendChild(actions);
                addWorkflowSection(section, 'conflicts');
            });
            if (product.holds.length) {
                const section = node('section', null, 'molavi-product-subsection molavi-product-hold');
                section.appendChild(node('h3', 'انتشار این محصول در یک دسته متوقف شده است'));
                product.holds.forEach(hold => {
                    const labels = {batch_in_progress:'نتیجهٔ پردازش نیازمند تطبیق است',
                        batch_unverified:'نتیجهٔ پردازش نیازمند بررسی است',
                        batch_write_unverified:'ثبت پیام‌های دسته تأیید نشده است'};
                    const row = node('div', null, 'molavi-hold-next-step');
                    row.appendChild(node('span', labels[hold.reason] || 'دستهٔ پردازش متوقف شده است'));
                    const holdIndex = batchCards.findIndex(entry => entry.hold === hold);
                    if (holdIndex >= 0) row.appendChild(ownerWorkflowAnchor(
                        `molavi-batch-${holdIndex}`, 'دیدن پیام‌ها و اقدام بازیابی'));
                    section.appendChild(row);
                });
                addWorkflowSection(section, 'holds');
            }
            if (Array.isArray(status.source_integrity_issues) && status.source_integrity_issues.length) {
                const section = node('section', null, 'molavi-product-subsection molavi-product-hold');
                section.appendChild(node('h3', 'پیوند پیشنهاد منبع شکسته است'));
                const labels={missing_product_id:'شناسه کالا ثبت نشده',duplicate_product_id:'شناسه کالا تکراری است',
                    missing_latest_offer:'شناسه پیشنهاد منبع ثبت نشده',offer_not_found:'پیشنهاد منبع در Offers پیدا نشد',
                    duplicate_offer_id:'شناسه پیشنهاد تکراری است',product_id_mismatch:'پیشنهاد به شناسه کالای دیگری وصل شده است',
                    source_provenance_mismatch:'اطلاعات مبدأ با پیشنهاد ثبت‌شده نمی‌خواند'};
                section.appendChild(node('p',status.source_integrity_issues.map(code=>labels[code]
                    || 'پیوند منبع نیازمند بررسی فنی است').join(' · ')));
                const source = safeSourceLink(status.source_post_url);
                if (source) section.appendChild(source);
                section.appendChild(notice('این کالا تا بازیابی پیوند منبع در فروشگاه منتشر نمی‌شود. جزئیات و پست اصلی را بررسی کنید؛ دادهٔ Offer حدس زده یا بازسازی خودکار نمی‌شود.', 'warning'));
                addWorkflowSection(section, 'source_integrity');
            }
            if (status.update_needed || status.review_reason || status.source_post_url) {
                const details = document.createElement('details');
                details.open = primaryKey === 'status';
                details.className = 'molavi-product-source-details';
                details.appendChild(node('summary', 'دلیل بررسی و پیام منبع'));
                if (status.review_reason) details.appendChild(node('p', aiReviewReason(status.review_reason)));
                const source = safeSourceLink(status.source_post_url);
                if (source) details.appendChild(source);
                if (status.supplier_price_verified_at) details.appendChild(node('p',
                    `آخرین بررسی قیمت تأمین‌کننده: ${formatSourceTimestamp(status.supplier_price_verified_at) || 'ثبت شده'}`));
                addWorkflowSection(details, 'status');
            }
            if (primaryContent.childNodes.length) card.appendChild(primaryContent);
            if (secondaryContent.childNodes.length) {
                const sectionCount = new Intl.NumberFormat('fa-IR').format(secondaryContent.childElementCount);
                const relatedLabels = [...relatedTaskKeys].map(key => workbenchTaskMeta(product, key).label);
                secondarySummary.textContent = `موارد مرتبط همین کالا (${sectionCount})`
                    + (relatedLabels.length ? ` · ${relatedLabels.join('، ')}` : '');
                secondaryChecks.appendChild(secondaryContent);
                card.appendChild(secondaryChecks);
            }
            return card;
        };

        const queueDefinitions = [
            {key:'next',label:'همه کارهای باز',matches:attention},
            {key:'source_integrity',label:'پیوند منبع',matches:product=>Boolean(product.status && Array.isArray(product.status.source_integrity_issues) && product.status.source_integrity_issues.length)},
            {key:'ai',label:'استخراج',matches:product=>product.ai.length>0},
            {key:'references',label:'تطبیق مرجع',matches:product=>product.references.length>0},
            {key:'conflicts',label:'تعارض شیت',matches:product=>product.conflicts.length>0},
            {key:'supplier_prices',label:'قیمت تأمین‌کننده',matches:product=>workbenchPendingSupplierPrices(product)>0},
            {key:'holds',label:'توقف انتشار',matches:product=>product.holds.length>0},
            {key:'status',label:'وضعیت کالا',matches:product=>Boolean(product.status && product.status.update_needed)}
        ].map(queue=>({...queue,count:products.filter(queue.matches).length}));
        workbenchStageModel.forEach(stage => {
            const matches = product => stage.queues.some(key => {
                const queue = queueDefinitions.find(item => item.key === key);
                return Boolean(queue && queue.matches(product));
            });
            queueDefinitions.push({key:`stage_${stage.key}`,label:`همه کارهای این مرحله`,matches,
                count:products.filter(matches).length});
        });
        const openChannelCounts = new Map();
        openProducts.forEach(product => {
            const label = String(product.channel || '').trim();
            const key = workbenchNameKey(label) || '__unassigned__';
            const current = openChannelCounts.get(key) || {key,label:label || 'منبع ثبت‌نشده',count:0};
            current.count += 1;
            openChannelCounts.set(key, current);
        });
        const channelOptions = [...openChannelCounts.values()].sort((a,b) => a.label.localeCompare(b.label, 'fa'));
        const catalogStateCounts = new Map();
        openProducts.forEach(product => {
            const value = product.status ? String(product.status.status || '').trim() || '__blank__' : '__no_record__';
            catalogStateCounts.set(value, (catalogStateCounts.get(value) || 0) + 1);
        });
        const catalogStatusChoices = [['all', 'همه وضعیت‌های فهرست فعلی']];
        if (catalogStateCounts.has('__no_record__')) catalogStatusChoices.push(['__no_record__',
            `بدون رکورد در فهرست فعلی · ${catalogStateCounts.get('__no_record__').toLocaleString('fa-IR')}`]);
        if (catalogStateCounts.has('__blank__')) catalogStatusChoices.push(['__blank__',
            `وضعیت ثبت‌نشده · ${catalogStateCounts.get('__blank__').toLocaleString('fa-IR')}`]);
        [...catalogStateCounts.entries()].filter(([state]) => !['__no_record__','__blank__'].includes(state))
            .sort(([a],[b]) => readableWorkflowStatus(a).localeCompare(readableWorkflowStatus(b), 'fa'))
            .forEach(([state,count]) => catalogStatusChoices.push([state,
                `${readableWorkflowStatus(state)} · ${count.toLocaleString('fa-IR')}`]));
        if (workbenchFilters.channelKeys instanceof Set) {
            workbenchFilters.channelKeys = new Set([...workbenchFilters.channelKeys]
                .filter(key => openChannelCounts.has(key)));
        }
        let currentQueue = queueDefinitions.find(queue=>queue.key===workbenchQueue);
        if (!currentQueue || (workbenchQueue !== 'next' && currentQueue.count === 0)) {
            const firstStage = workbenchStageModel.find(stage => stage.queues.some(key =>
                (queueDefinitions.find(queue => queue.key === key)?.count || 0) > 0));
            workbenchStage = firstStage ? firstStage.key : '';
            workbenchQueue = firstStage ? `stage_${firstStage.key}` : 'next';
            workbenchSelectedKey = '';
            workbenchIndex = 0;
        }
        const activeStageForQueue = queue => workbenchStageModel.find(stage =>
            queue === `stage_${stage.key}` || stage.queues.includes(queue));
        if (workbenchQueue === 'next') workbenchStage = '';
        else workbenchStage = activeStageForQueue(workbenchQueue)?.key || workbenchStage;

        const stageGrid = node('div', null, 'molavi-workbench-stages');
        stageGrid.setAttribute('aria-label', 'مراحل کار');
        const stageButtons = new Map();
        const queueButtons = new Map();
        workbenchStageModel.forEach((stage, index) => {
            const stageQueue = queueDefinitions.find(queue => queue.key === `stage_${stage.key}`);
            const button = node('button', null, 'molavi-workbench-stage');
            button.type = 'button';
            button.setAttribute('aria-pressed', String(workbenchStage === stage.key));
            button.append(node('span', `۰${index + 1}`, 'molavi-stage-index'),
                node('strong', stage.label),
                node('span', stage.hint, 'molavi-stage-hint'),
                node('b', (stageQueue?.count || 0).toLocaleString('fa-IR'), 'molavi-stage-count'));
            button.addEventListener('click', () => {
                workbenchStage = stage.key;
                workbenchQueue = `stage_${stage.key}`;
                workbenchView = 'tasks';
                workbenchIndex = 0;
                workbenchSelectedKey = '';
                workbenchLiveStatus.className = 'molavi-workbench-live-status';
                workbenchLiveStatus.textContent = `مرحله «${stage.label}»؛ ${new Intl.NumberFormat('fa-IR').format(stageQueue?.count || 0)} کالا برای رسیدگی.`;
                stageButtons.forEach((item, key) => item.setAttribute('aria-pressed', String(key === workbenchStage)));
                renderQueueNavigation();
                renderView();
                renderQueue();
            });
            stageButtons.set(stage.key, button);
            stageGrid.appendChild(button);
        });
        const tabs = node('nav', null, 'molavi-workbench-tabs');
        tabs.setAttribute('aria-label', 'نمایش میز کار');
        const taskView = node('section', null, 'molavi-workbench-view');
        const reextractView = node('section', null, 'molavi-workbench-view');
        const storeView = node('section', null, 'molavi-workbench-view');
        const viewPanels = new Map([['tasks',taskView],['reextract',reextractView],['store',storeView]]);
        const viewButtons = new Map();
        const renderView = () => {
            viewPanels.forEach((panel, key) => { panel.hidden = key !== workbenchView; });
            viewButtons.forEach((button, key) => {
                const selected = key === workbenchView;
                button.classList.toggle('is-active', selected);
                button.setAttribute('aria-pressed', String(selected));
            });
        };
        [['tasks','کارهای این مرحله'],['reextract','بازاستخراج پیام‌ها'],['store','عملیات فروشگاه']].forEach(([key,label]) => {
            const button = node('button', label, 'molavi-workbench-tab');
            button.type = 'button';
            button.setAttribute('aria-pressed', String(key === workbenchView));
            button.addEventListener('click', () => {
                workbenchView = key;
                renderView();
            });
            viewButtons.set(key, button);
            tabs.appendChild(button);
        });
        const queueNavigation = node('div', null, 'molavi-workbench-queues');
        queueNavigation.setAttribute('role', 'group');
        queueNavigation.setAttribute('aria-label', 'انتخاب صف همین مرحله');
        const taskSearchRow = node('div', null, 'molavi-workbench-search-row');
        const taskSearchField = field('جست‌وجوی نام کالا، شناسه یا کانال', 'workbench_search',
            workbenchFilters.search, 'search');
        const taskSearchInput = taskSearchField.querySelector('input');
        taskSearchInput.autocomplete = 'off';
        taskSearchInput.addEventListener('input', () => {
            workbenchFilters.search = taskSearchInput.value;
            updateFilterPresentation();
            renderQueue();
        });
        taskSearchRow.appendChild(taskSearchField);
        const taskFilterDetails = document.createElement('details');
        taskFilterDetails.className = 'molavi-workbench-filter-panel';
        const taskFilterSummary = node('summary', 'فیلترهای دقیق');
        taskFilterDetails.appendChild(taskFilterSummary);
        const taskFilterBody = node('div', null, 'molavi-workbench-filter-body');
        const taskFilterGrid = node('div', null, 'molavi-workbench-filter-grid');
        const taskStateField = field('وضعیت کاری', 'workbench_state', workbenchFilters.state, 'select', {choices:[
            ['all','همه وضعیت‌ها'],['blocked','مسدود یا متوقف'],['owner_review','در انتظار بازبینی استخراج'],
            ['source_link','پیوند منبع ناقص'],['stale_source','قیمت یا منبع نیازمند بررسی تازه'],
            ['reference','تطبیق مرجع لازم است'],['conflict','تعارض همگام‌سازی'],
            ['publication_hold','توقف انتشار'],['catalog_update','به‌روزرسانی کاتالوگ']
        ]});
        const taskStateSelect = taskStateField.querySelector('select');
        taskStateSelect.addEventListener('change', () => {
            workbenchFilters.state = taskStateSelect.value;
            updateFilterPresentation();
            renderQueue();
        });
        const taskCatalogStatusField = field('وضعیت در فهرست فعلی کالاها', 'workbench_catalog_status',
            workbenchFilters.catalogStatus, 'select', {choices:catalogStatusChoices});
        const taskCatalogStatusSelect = taskCatalogStatusField.querySelector('select');
        taskCatalogStatusSelect.addEventListener('change', () => {
            workbenchFilters.catalogStatus = taskCatalogStatusSelect.value;
            updateFilterPresentation();
            renderQueue();
        });
        const taskDateFromField = field('از تاریخ تهران', 'workbench_date_from', workbenchFilters.from, 'date');
        const taskDateToField = field('تا تاریخ تهران', 'workbench_date_to', workbenchFilters.to, 'date');
        const taskDateFrom = taskDateFromField.querySelector('input');
        const taskDateTo = taskDateToField.querySelector('input');
        const includeUndated = document.createElement('input');
        const onTaskDateChange = () => {
            workbenchFilters.from = taskDateFrom.value;
            workbenchFilters.to = taskDateTo.value;
            if (!workbenchFilters.from && !workbenchFilters.to) {
                workbenchFilters.includeUndated = false;
                includeUndated.checked = false;
            }
            includeUndated.disabled = !workbenchFilters.from && !workbenchFilters.to;
            updateFilterPresentation();
            renderQueue();
        };
        taskDateFrom.addEventListener('change', onTaskDateChange);
        taskDateTo.addEventListener('change', onTaskDateChange);
        taskFilterGrid.append(taskStateField, taskCatalogStatusField, taskDateFromField, taskDateToField);
        taskFilterBody.appendChild(taskFilterGrid);
        taskFilterBody.appendChild(node('small', 'بازهٔ تاریخ بر اساس زمان ثبت‌شدهٔ پیام یا رویداد و با روزهای تقویمی تهران اعمال می‌شود.'));
        const includeUndatedLabel = node('label', null, 'molavi-workbench-include-undated');
        includeUndated.type = 'checkbox';
        includeUndated.checked = workbenchFilters.includeUndated;
        includeUndated.disabled = !workbenchFilters.from && !workbenchFilters.to;
        includeUndated.addEventListener('change', () => {
            workbenchFilters.includeUndated = includeUndated.checked;
            updateFilterPresentation();
            renderQueue();
        });
        includeUndatedLabel.append(includeUndated, node('span', 'همراه با مواردی که تاریخشان ثبت نشده'));
        taskFilterBody.appendChild(includeUndatedLabel);
        const channelFieldset = document.createElement('fieldset');
        channelFieldset.className = 'molavi-workbench-channel-filter';
        channelFieldset.appendChild(node('legend', 'منبع و کانال'));
        const channelFilterDetails = document.createElement('details');
        const channelFilterSummary = node('summary', 'همه کانال‌ها');
        channelFilterDetails.appendChild(channelFilterSummary);
        const channelSearchLabel = node('label', 'جست‌وجوی کانال');
        const channelSearchInput = document.createElement('input');
        channelSearchInput.type = 'search';
        channelSearchInput.autocomplete = 'off';
        channelSearchLabel.appendChild(channelSearchInput);
        channelFilterDetails.appendChild(channelSearchLabel);
        const channelActions = node('div', null, 'molavi-workbench-channel-actions');
        const chooseAllChannels = node('button', 'همه');
        const clearChannels = node('button', 'هیچ‌کدام');
        [chooseAllChannels, clearChannels].forEach(button => {
            button.type = 'button';
            button.className = 'button button-link';
        });
        channelActions.append(chooseAllChannels, clearChannels);
        channelFilterDetails.appendChild(channelActions);
        const channelList = node('div', null, 'molavi-workbench-channel-list');
        const channelCheckboxes = [];
        channelOptions.forEach(option => {
            const label = node('label', null, 'molavi-workbench-channel-option');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = option.key;
            checkbox.checked = !(workbenchFilters.channelKeys instanceof Set)
                || workbenchFilters.channelKeys.has(option.key);
            checkbox.addEventListener('change', () => {
                const selected = new Set(channelCheckboxes.filter(item => item.checkbox.checked)
                    .map(item => item.option.key));
                workbenchFilters.channelKeys = selected.size === channelOptions.length ? null : selected;
                updateFilterPresentation();
                renderQueue();
            });
            label.append(checkbox, node('span', `${option.label} · ${option.count.toLocaleString('fa-IR')}`));
            channelCheckboxes.push({option,checkbox,label});
            channelList.appendChild(label);
        });
        channelSearchInput.addEventListener('input', () => {
            const query = workbenchNameKey(channelSearchInput.value);
            channelCheckboxes.forEach(item => { item.label.hidden = Boolean(query)
                && !workbenchNameKey(item.option.label).includes(query); });
        });
        chooseAllChannels.addEventListener('click', () => {
            workbenchFilters.channelKeys = null;
            channelCheckboxes.forEach(item => { item.checkbox.checked = true; });
            updateFilterPresentation();
            renderQueue();
        });
        clearChannels.addEventListener('click', () => {
            workbenchFilters.channelKeys = new Set();
            channelCheckboxes.forEach(item => { item.checkbox.checked = false; });
            updateFilterPresentation();
            renderQueue();
        });
        channelFilterDetails.appendChild(channelList);
        channelFieldset.appendChild(channelFilterDetails);
        taskFilterBody.appendChild(channelFieldset);
        taskFilterDetails.appendChild(taskFilterBody);
        const filterResultRow = node('div', null, 'molavi-workbench-filter-result');
        const filterResult = node('p', '', 'molavi-workbench-filter-result-text');
        filterResult.setAttribute('role', 'status');
        filterResult.setAttribute('aria-live', 'polite');
        const clearTaskFilters = node('button', 'پاک‌کردن فیلترها', 'button button-secondary');
        clearTaskFilters.type = 'button';
        clearTaskFilters.hidden = true;
        clearTaskFilters.addEventListener('click', () => {
            workbenchFilters.search = '';
            workbenchFilters.channelKeys = null;
            workbenchFilters.state = 'all';
            workbenchFilters.catalogStatus = 'all';
            workbenchFilters.from = '';
            workbenchFilters.to = '';
            workbenchFilters.includeUndated = false;
            taskSearchInput.value = '';
            taskStateSelect.value = 'all';
            taskCatalogStatusSelect.value = 'all';
            taskDateFrom.value = '';
            taskDateTo.value = '';
            includeUndated.checked = false;
            includeUndated.disabled = true;
            channelSearchInput.value = '';
            channelCheckboxes.forEach(item => { item.checkbox.checked = true; item.label.hidden = false; });
            updateFilterPresentation();
            renderQueue();
        });
        filterResultRow.append(filterResult, clearTaskFilters);
        const updateFilterPresentation = () => {
            const active = workbenchActiveFilterCount();
            const activeLabel = active ? ` · ${new Intl.NumberFormat('fa-IR').format(active)} فیلتر فعال` : '';
            taskFilterSummary.textContent = `فیلترهای دقیق: کانال، وضعیت کار و فهرست، تاریخ${activeLabel}`;
            clearTaskFilters.hidden = active === 0 && !workbenchFilters.includeUndated;
            if (workbenchFilters.channelKeys instanceof Set) {
                channelFilterSummary.textContent = `${new Intl.NumberFormat('fa-IR').format(workbenchFilters.channelKeys.size)} کانال انتخاب‌شده`;
            } else channelFilterSummary.textContent = `همه کانال‌های دارای کار باز (${channelOptions.length})`;
        };
        updateFilterPresentation();
        const queueContent = node('div', null, 'molavi-workbench-queue-content');
        const sourceGroup = node('section', null, 'molavi-workbench-source-group');
        sourceGroup.setAttribute('aria-label', 'انتخاب پیام‌ها برای بازاستخراج');
        reextractView.appendChild(sourceGroup);
        taskView.append(queueNavigation, taskSearchRow, taskFilterDetails, filterResultRow, queueContent);
        board.append(overview, stageGrid, tabs, taskView, reextractView, storeView);

        refreshQueueCounts = () => {
            queueDefinitions.forEach(queue => {
                queue.count = products.filter(queue.matches).length;
                const button = queueButtons.get(queue.key);
                if (!button) return;
                button.textContent = `${queue.label} · ${queue.count.toLocaleString('fa-IR')}`;
                button.disabled = queue.count === 0;
            });
            workbenchStageModel.forEach(stage => {
                const button = stageButtons.get(stage.key);
                const queue = queueDefinitions.find(item => item.key === `stage_${stage.key}`);
                if (!button || !queue) return;
                button.querySelector('.molavi-stage-count').textContent = queue.count.toLocaleString('fa-IR');
                button.disabled = queue.count === 0;
            });
            return queueDefinitions.find(queue => queue.key === 'supplier_prices')?.count || 0;
        };
        function renderQueueNavigation() {
            queueNavigation.replaceChildren();
            queueButtons.clear();
            const activeStage = workbenchStageModel.find(stage => stage.key === workbenchStage);
            const choices = [queueDefinitions.find(queue => queue.key === 'next')];
            if (activeStage) {
                choices.push(queueDefinitions.find(queue => queue.key === `stage_${activeStage.key}`));
                activeStage.queues.forEach(key => choices.push(queueDefinitions.find(queue => queue.key === key)));
            }
            choices.filter(Boolean).forEach(queue => {
                const button = node('button', `${queue.label} · ${queue.count.toLocaleString('fa-IR')}`,
                    'button button-secondary molavi-workbench-queue');
                button.type = 'button';
                button.disabled = queue.count === 0;
                button.setAttribute('aria-pressed', String(queue.key === workbenchQueue));
                button.addEventListener('click', () => {
                    if (button.disabled || workbenchQueue === queue.key) return;
                    workbenchQueue = queue.key;
                    workbenchStage = queue.key === 'next' ? '' : (activeStageForQueue(queue.key)?.key || '');
                    workbenchView = 'tasks';
                    workbenchIndex = 0;
                    workbenchSelectedKey = '';
                    workbenchLiveStatus.className = 'molavi-workbench-live-status';
                    workbenchLiveStatus.textContent = `صف «${queue.label}»؛ ${queue.count.toLocaleString('fa-IR')} کالا برای رسیدگی.`;
                    stageButtons.forEach((item, key) => item.setAttribute('aria-pressed', String(key === workbenchStage)));
                    queueButtons.forEach((item, key) => item.setAttribute('aria-pressed', String(key === workbenchQueue)));
                    if (queue.key === 'next') renderQueueNavigation();
                    renderView();
                    renderQueue();
                });
                queueButtons.set(queue.key, button);
                queueNavigation.appendChild(button);
            });
        }
        renderQueueNavigation();
        renderView();
        const renderQueue = () => {
            const queue=queueDefinitions.find(item=>item.key===workbenchQueue) || queueDefinitions[0];
            const queueProducts=products.filter(queue.matches);
            const shownProducts=queueProducts.filter(product => workbenchMatchesFilters(product, queue.key));
            const reversedDateRange = workbenchFilters.from && workbenchFilters.to
                && workbenchFilters.from > workbenchFilters.to;
            filterResult.textContent = reversedDateRange
                ? 'تاریخ شروع باید پیش از تاریخ پایان باشد.'
                : `نمایش ${shownProducts.length.toLocaleString('fa-IR')} از ${queueProducts.length.toLocaleString('fa-IR')} کالا در این صف`
                    + (workbenchActiveFilterCount() ? ' · فیلترها با هم ترکیب می‌شوند.' : '');
            clearTaskFilters.hidden = workbenchActiveFilterCount() === 0 && !workbenchFilters.includeUndated;
            queueContent.replaceChildren();
            if (!products.length) {
                queueContent.appendChild(notice(unavailable.length
                    ? 'وضعیت کالا دریافت نشد. منابع بالا را باز کنید تا اتصال ناموفق مشخص شود.'
                    : 'کالایی برای نمایش ثبت نشده است.',unavailable.length?'warning':'info'));
                return;
            }
            if (!shownProducts.length) {
                const hasActiveFilters = workbenchActiveFilterCount() > 0 || workbenchFilters.includeUndated;
                queueContent.appendChild(notice(reversedDateRange
                    ? 'بازه تاریخ معتبر نیست؛ تاریخ‌ها را اصلاح یا فیلترها را پاک کنید.'
                    : queueProducts.length && hasActiveFilters
                        ? 'با ترکیب فعلی فیلترها موردی پیدا نشد؛ یک فیلتر را بردارید یا همه را پاک کنید.'
                        : unavailable.length
                            ? 'این صف خالی است؛ منابعی که دریافت نشدند را در وضعیت اتصال بررسی کنید.'
                            : 'همه کارهای این صف انجام شده‌اند.',
                    reversedDateRange || (queueProducts.length && hasActiveFilters) ? 'info'
                        : unavailable.length ? 'warning' : 'success'));
                return;
            }
            if (workbenchSelectedKey) {
                const previousIndex=shownProducts.findIndex(product=>product.key===workbenchSelectedKey);
                if (previousIndex>=0) workbenchIndex=previousIndex;
            }
            workbenchIndex=Math.max(0,Math.min(workbenchIndex,shownProducts.length-1));
            const navigation=node('nav',null,'molavi-product-navigation');
            navigation.setAttribute('aria-label','مرور کالاهای صف انتخاب‌شده');
            const previous=node('button','قبلی','button button-secondary');
            const position=node('span','', 'molavi-product-position');
            const next=node('button','بعدی','button button-primary');
            previous.type='button'; next.type='button';
            const current=node('div',null,'molavi-current-product');
            const renderCurrent=()=>{
                const product=shownProducts[workbenchIndex];
                workbenchSelectedKey=product.key;
                previous.disabled=workbenchIndex<=0;
                next.disabled=workbenchIndex>=shownProducts.length-1;
                position.textContent=`${queue.label} · کالای ${(workbenchIndex+1).toLocaleString('fa-IR')} از ${shownProducts.length.toLocaleString('fa-IR')}`;
                current.replaceChildren(renderProduct(product));
            };
            previous.addEventListener('click',()=>{if(workbenchIndex>0){workbenchIndex-=1;renderCurrent();}});
            next.addEventListener('click',()=>{if(workbenchIndex+1<shownProducts.length){workbenchIndex+=1;renderCurrent();}});
            navigation.append(previous,position,next);
            queueContent.append(navigation,current);
            renderCurrent();
        };
        renderQueue();

        if (batchCards.length) {
            const batchGroup = document.createElement('details');
            batchGroup.className = 'card molavi-batch-group';
            batchGroup.appendChild(node('summary', `دسته‌های پردازش متوقف‌شده (${batchCards.length})`));
            batchGroup.appendChild(node('p', 'دسته ممکن است چند پیام و چند محصول داشته باشد؛ اقدام روی هر دسته فقط یک‌بار اجرا می‌شود.'));
            for (const [holdIndex, {hold,relatedCount}] of batchCards.entries()) {
                const context = hold.context && typeof hold.context === 'object' ? hold.context : {};
                const messages = Array.isArray(context.messages) ? context.messages : [];
                const reconcileWithoutRetry = hold.reason === 'batch_in_progress'
                    && context.batch_state === 'failed' && context.batch_error === 'JobConflict'
                    && context.expected_messages > 0 && messages.length === context.expected_messages;
                const card = node('section', null, 'molavi-batch-card molavi-review-card');
                card.id = `molavi-batch-${holdIndex}`;
                card.appendChild(reviewCardHeader(({batch_in_progress:'نتیجه دسته نیازمند تطبیق است',batch_unverified:'نتیجه پردازش نیاز به بررسی دارد',batch_write_unverified:'ثبت پیام‌های دسته تأیید نشده'}[hold.reason]) || 'دسته متوقف‌شده', 'اقدام دسته‌ای'));
                card.appendChild(reviewFacts([
                    ['زمان توقف', formatSourceTimestamp(hold.created_at) || 'نامشخص'],
                    ['محصولات متصل', new Intl.NumberFormat('fa-IR').format(relatedCount)],
                    ['وضعیت پردازش', displayBatchState(context.batch_state)],
                    ['پیام‌های ثبت‌شده', `${context.written_messages || 0} از ${context.expected_messages || 0}`],
                    ['پیام‌های پردازش‌شده', context.processed_messages || 0]
                ]));
                if (context.processing_error || context.batch_error) card.appendChild(notice(
                    displayBatchError(context.processing_error || context.batch_error), 'warning'));
                const sources = node('details');
                sources.appendChild(node('summary', `پیام‌های منبع (${messages.length})`));
                messages.forEach(source => {
                    const item = node('div', null, 'molavi-review-source');
                    item.appendChild(node('strong', source.channel || 'کانال نامشخص'));
                    const sourceDates = aiSourceDateFacts(source);
                    if (sourceDates.length) item.appendChild(reviewFacts(sourceDates,
                        'molavi-review-facts molavi-ai-source-date-facts'));
                    const sourceLink = safeSourceLink(source.source_url);
                    if (sourceLink) item.appendChild(sourceLink);
                    if (source.content) item.appendChild(node('p', source.content.length > 450 ? source.content.slice(0,450)+'…' : source.content, 'molavi-source-excerpt'));
                    else item.appendChild(node('p', source.has_media ? 'این پیام رسانه دارد؛ متن جداگانه ثبت نشده است.' : 'متن پیام ثبت نشده است.'));
                    sources.appendChild(item);
                });
                card.appendChild(sources);
                const action = node('button', reconcileWithoutRetry ? 'بستن توقف تکراری بدون اجرای دوباره'
                    : 'اجرای دوباره این دسته، فقط یک بار', 'button button-primary');
                action.type = 'button';
                action.disabled = !holdsContextStored || !messages.length;
                if (!holdsContextStored) card.appendChild(notice('ثبت زمینه این تصمیم در پایگاه داده وردپرس ناموفق بود؛ اقدام غیرفعال است.', 'error'));
                if (!messages.length) card.appendChild(notice('تا نمایش پیام‌های مبدأ، اقدام روی این دسته غیرفعال می‌ماند.', 'warning'));
                action.addEventListener('click', async () => {
                    action.disabled = true;
                    const actionLabel = action.textContent;
                    action.textContent = 'در حال ثبت…';
                    const progressStatus = actionStatus(card, 'در حال ثبت اقدام دسته با درخواست AJAX…');
                    const eventId = reviewEventId('publication_hold', hold.hold_id);
                    const note = 'Owner reviewed the linked source messages and authorized one batch retry.';
                    const decision = reconcileWithoutRetry
                        ? {action:'reconcile_without_retry',expected_reason:hold.reason,expected_batch_state:context.batch_state,
                            expected_batch_error:context.batch_error,attempt_id:eventId,
                            note:'Reconciled the existing Sheet messages and AI review cases; clear the stale hold without replaying the batch.'}
                        : {action:'retry_once',expected_reason:hold.reason,attempt_id:eventId,note};
                    try {
                        await persistOwnerReviews([reviewRecord(eventId,'publication_hold',hold.hold_id,'pending',
                            storedReviewContext('publication_hold',context),decision)]);
                        const payload = reconcileWithoutRetry
                            ? {hold_id:hold.hold_id,expected_reason:hold.reason,
                                expected_batch_state:context.batch_state,expected_batch_error:context.batch_error,
                                note:decision.note,resume_batch:false}
                            : {action:'resume_selected',holds:[{hold_id:hold.hold_id,expected_reason:hold.reason}]};
                        const receipt = await request('POST',payload,undefined,'publication_holds');
                        if (reconcileWithoutRetry) {
                            if (receipt.hold_id !== hold.hold_id || receipt.batch_resumed !== false || receipt.reconciled !== true) throw new Error('invalid_receipt');
                        } else if (receipt.resumed_count !== 1 || !Array.isArray(receipt.resumed_hold_ids)
                            || receipt.resumed_hold_ids[0] !== hold.hold_id) throw new Error('invalid_receipt');
                        let warning = '';
                        try { await persistOwnerReviews([reviewRecord(reviewEventId('publication_hold',hold.hold_id),
                            'publication_hold',hold.hold_id,'accepted',{},decision,receipt)]); }
                        catch (_error) { warning = ' اقدام ثبت شد، اما رسید وردپرس هنوز همگام نشده است.'; }
                        action.textContent = reconcileWithoutRetry ? 'توقف بسته شد' : 'دسته در صف قرار گرفت';
                        const message = `${reconcileWithoutRetry?'توقف تکراری بسته شد و دسته دوباره اجرا نشد.':'دسته برای یک بار پردازش دوباره در صف قرار گرفت.'}${warning}`;
                        updateActionStatus(progressStatus, message, warning ? 'warning' : 'success');
                        await dashboard(message, warning ? 'warning' : 'success',
                            ['publication_holds','operations','ai_confirmations','product_status','sheet_conflicts','supplier_prices']);
                    } catch (error) {
                        try { await persistOwnerReviews([reviewRecord(reviewEventId('publication_hold',hold.hold_id),
                            'publication_hold',hold.hold_id,error.remoteRejected?'failed':'pending',{},decision,{error:String(error.message||'request_failed')})]); }
                        catch (_ignored) {}
                        action.textContent = actionLabel;
                        updateActionStatus(progressStatus, errorText(error.message), 'error');
                        errorBox(errorText(error.message),card); action.disabled = !holdsContextStored || !messages.length;
                    }
                });
                const batchActions = reviewActions();
                batchActions.appendChild(action);
                card.appendChild(batchActions);
                batchGroup.appendChild(card);
            }
            taskView.appendChild(batchGroup);
        }

        const globalTasks = document.createElement('details');
        globalTasks.className = 'card molavi-global-workflows';
        globalTasks.appendChild(node('summary', 'کارهای سراسری و غیر وابسته به یک کالا'));
        const refEvents = data.reference_decisions && Array.isArray(data.reference_decisions.events)
            ? data.reference_decisions.events : [];
        globalTasks.appendChild(node('p', refEvents.length
            ? `منبع قیمت مرجع ${refEvents.length} تصمیم در انتظار دارد.`
            : loaded[keys.indexOf('reference_decisions')].error || 'منبع قیمت مرجع تصمیم بازی ندارد.'));
        const globalLinks = node('div', null, 'molavi-global-links');
        [
            ['molavi-reference-snapshots','فهرست مرجع کالاها'],
            ['molavi-reference-decisions','اعتبار و تازگی فهرست مرجع'],
            ['molavi-inventory-management','بچ‌های موجودی'],
            ['molavi-clearance-pricing','پیشنهادهای قیمت حراج'],
            ['molavi-paid-orders','سفارش‌های پرداخت‌شده']
        ].forEach(([slug,text]) => {
            const url = config.pageUrls && config.pageUrls[slug];
            if (!url) return;
            const link = node('a',text,'button button-secondary'); link.href = url; link.target = '_top'; globalLinks.appendChild(link);
        });
        globalTasks.appendChild(globalLinks);
        storeView.appendChild(globalTasks);

        const technical = document.createElement('details');
        technical.className = 'card molavi-technical-details';
        technical.appendChild(node('summary','صف پردازش و گزارش سلامت فروشگاه'));
        if (!operations || !operations.operations) {
            technical.appendChild(notice(loaded[loaded.length-1].error || 'وضعیت صف در دسترس نیست.','warning'));
        } else {
            const ops = operations.operations;
            const counts = ops.by_state || {};
            technical.appendChild(node('p',`در انتظار: ${ops.queued || counts.queued || 0} · در حال اجرا: ${counts.running || 0} · خطا: ${counts.failed || 0} · نیازمند بررسی: ${counts.needs_review || 0} · توقف انتشار: ${ops.publication_holds || 0}`));
            const worker = ops.worker || {};
            const workerLabels = {alive:'فعال',stale:'بی‌پاسخ',stopped:'متوقف',unknown:'وضعیت نامشخص'};
            const workerProgressLabels = {active:'فعال',idle:'صف بدون کار باز است',stalled:'بیش از ۵ دقیقه بدون پیشرفت',
                stopped:'متوقف',unknown:'نامشخص'};
            const workerAge = worker.heartbeat_age_seconds !== null && worker.heartbeat_age_seconds !== undefined
                && Number.isFinite(Number(worker.heartbeat_age_seconds))
                ? ` · آخرین علامت ${Math.round(Number(worker.heartbeat_age_seconds))} ثانیه پیش` : '';
            const progressAge = worker.progress_age_seconds !== null && worker.progress_age_seconds !== undefined
                && Number.isFinite(Number(worker.progress_age_seconds))
                ? `، ${Math.round(Number(worker.progress_age_seconds))} ثانیه از آخرین پیشرفت` : '';
            const workerJob = worker.current_job_id ? ` · رسید ${worker.current_job_id}` : '';
            const workerError = worker.last_error_code ? ` · آخرین کد خطا ${worker.last_error_code}` : '';
            const workerProgress=workerProgressLabels[worker.progress_status]||'پیشرفت نامشخص';
            technical.appendChild(node('p',`کارگر صف: ${workerLabels[worker.status] || worker.status || 'وضعیت نامشخص'} · پیشرفت: ${workerProgress}${progressAge}${workerAge}${workerJob}${workerError}`));
            const pendingTelegramChats = Array.isArray(ops.pending_telegram_chats) ? ops.pending_telegram_chats : [];
            if (pendingTelegramChats.length) {
                const telegramDetails = document.createElement('details');
                const telegramSummary = node('summary', `حساب‌های تلگرام منتظر تأیید (${pendingTelegramChats.length})`);
                telegramDetails.appendChild(telegramSummary);
                telegramDetails.appendChild(node('p', 'پیام‌های حساب‌های ناشناس ذخیره یا پردازش نمی‌شوند؛ فقط شناسه و زمان درخواست نگه‌داری می‌شود.'));
                pendingTelegramChats.forEach(chat => {
                    const row = document.createElement('section');
                    row.className = 'molavi-product-subsection';
                    const firstSeen = Number(chat.first_seen_at);
                    row.appendChild(node('p', `شناسه تلگرام: ${chat.chat_id} · درخواست‌ها: ${chat.seen_count || 1}`
                        + (Number.isFinite(firstSeen) ? ` · نخستین درخواست: ${new Date(firstSeen * 1000).toLocaleString('fa-IR')}` : '')));
                    const actionStatusBox = node('p', '');
                    row.appendChild(actionStatusBox);
                    for (const [action,label] of [['approve','اجازه دسترسی'],['reject','رد حساب']]) {
                        const button = node('button', label, 'button button-secondary');
                        button.type = 'button';
                        button.addEventListener('click', async () => {
                            const revision = Number(chat.revision);
                            const targetState = action === 'approve' ? 'approved' : 'rejected';
                            const eventId = `decision:telegram-operator:${chat.chat_id}:${revision}:${action}`;
                            const context = {chat_id:String(chat.chat_id),revision,
                                first_seen_at:chat.first_seen_at,last_seen_at:chat.last_seen_at,
                                seen_count:chat.seen_count};
                            const decision = {action,expected_revision:revision};
                            button.disabled = true;
                            actionStatusBox.textContent = 'در حال ثبت تصمیم…';
                            try {
                                await persistOwnerReviews([reviewRecord(eventId,'telegram_operator',String(chat.chat_id),'pending',context,decision)]);
                                const receipt = await request('POST',{action,chat_id:String(chat.chat_id),expected_revision:revision},undefined,'telegram_operators');
                                if (receipt.chat_id !== String(chat.chat_id) || receipt.state !== targetState
                                    || Number(receipt.revision) !== revision) throw new Error('invalid_receipt');
                                let warning = '';
                                try { await persistOwnerReviews([reviewRecord(eventId,'telegram_operator',String(chat.chat_id),'accepted',context,decision,receipt)]); }
                                catch (_error) { warning = ' تصمیم ثبت شد، اما رسید آن هنوز در پایگاه داده وردپرس همگام نشده است.'; }
                                await dashboard(action === 'approve'
                                    ? `دسترسی حساب تلگرام ${chat.chat_id} ثبت شد.${warning}`
                                    : `درخواست حساب تلگرام ${chat.chat_id} رد شد.${warning}`,
                                    warning ? 'warning' : 'success',['operations']);
                            } catch (error) {
                                try { await persistOwnerReviews([reviewRecord(eventId,'telegram_operator',String(chat.chat_id),error.remoteRejected?'failed':'pending',context,decision,{error:String(error.message||'request_failed')})]); }
                                catch (_ignored) {}
                                actionStatusBox.textContent = errorText(error.message);
                                button.disabled = false;
                            }
                        });
                        row.appendChild(button);
                    }
                    telegramDetails.appendChild(row);
                });
                technical.appendChild(telegramDetails);
            }
            const failedPublicationIds = Array.isArray(ops.failed_publication_ids) ? ops.failed_publication_ids : [];
            const failedBatchIds = Array.isArray(ops.failed_batch_ids) ? ops.failed_batch_ids : [];
            if (failedPublicationIds.length || failedBatchIds.length) {
                technical.appendChild(node('p',`${failedPublicationIds.length} خطای انتشار و ${failedBatchIds.length} رسید دسته قدیمی باقی مانده است.`));
                const reconcileButton = node('button','همگام‌سازی کاتالوگ و بستن خطاهای قابل اثبات','button button-secondary');
                reconcileButton.type='button'; reconcileButton.disabled=!config.reviewStoreUrl||!config.reviewStoreNonce;
                reconcileButton.addEventListener('click',async()=>{
                    if (!window.confirm('فهرست فعلی شیت یک‌بار با ووکامرس همگام شود؟ فقط خطاهایی بسته می‌شوند که نتیجه‌شان کامل و قابل اثبات باشد.')) return;
                    reconcileButton.disabled=true;
                    const eventId=reviewEventId('publication_backlog','current');
                    const context={failed_publication_ids:failedPublicationIds,failed_batch_ids:failedBatchIds,
                        queue_counts:counts,observed_at:new Date().toISOString()};
                    const decision={action:'sync_current_catalog_and_reconcile_failed_receipts',
                        note:'Owner authorized one current Sheet-to-Woo catalog sync; reconcile only matching failed receipts.'};
                    const endpoint=new URL('/pipeline/reconcile-publication-backlog',config.apiUrl).toString();
                    try {
                        await persistOwnerReviews([reviewRecord(eventId,'publication_backlog','failed-publication-backlog','pending',context,decision)]);
                        const receipt=await request('POST',{expected_publish_ids:failedPublicationIds,expected_batch_ids:failedBatchIds},endpoint);
                        const catalog=receipt.catalog_sync||{};
                        if (!/^[a-f0-9]{64}$/.test(receipt.sheet_digest||'')||receipt.failed_publications_reconciled!==failedPublicationIds.length
                            ||!Array.isArray(receipt.failed_publication_ids)||receipt.failed_publication_ids.length!==failedPublicationIds.length
                            ||!Array.isArray(receipt.batch_job_ids_reconciled)||!Array.isArray(receipt.batch_job_ids_left_open)
                            ||receipt.batch_jobs_reconciled!==receipt.batch_job_ids_reconciled.length||Number(catalog.images_left||0)!==0) throw new Error('invalid_receipt');
                        const open=receipt.batch_job_ids_left_open.length;
                        await persistOwnerReviews([reviewRecord(eventId,'publication_backlog','failed-publication-backlog','accepted',context,decision,receipt)]);
                        await dashboard(`کاتالوگ همگام شد؛ ${receipt.failed_publications_reconciled} خطای انتشار بسته شد و ${receipt.batch_jobs_reconciled} دسته تأییدشده تطبیق یافت.${open?` ${open} دسته برای بررسی باز ماند.`:''}`,
                            open?'warning':'success', ['operations','product_status','supplier_prices','publication_holds','sheet_conflicts','ai_confirmations']);
                    } catch(error) {
                        try { await persistOwnerReviews([reviewRecord(eventId,'publication_backlog','failed-publication-backlog',error.remoteRejected?'failed':'pending',context,decision,{error:String(error.message||'request_failed')})]); }
                        catch(_ignored) {}
                        errorBox(errorText(error.message),technical); reconcileButton.disabled=false;
                    }
                });
                technical.appendChild(reconcileButton);
            }
            const queueItems = Array.isArray(ops.items) ? ops.items : [];
            if (queueItems.length || ops.next_cursor) {
                const details = document.createElement('details');
                const summary=node('summary','');
                const rows=document.createElement('div');
                const pageState={items:[...queueItems],cursor:ops.next_cursor||null,loading:false};
                const updateSummary=()=>{summary.textContent=`موارد صف (${ops.actionable_count || queueItems.length}) · نمایش ${pageState.items.length}`;};
                const nextSteps={automatic_retry:'در نوبت پردازش خودکار',wait_for_processing:'در حال پردازش؛ نتیجهٔ worker را دنبال می‌کنیم',
                    recover_expired_lease:'مهلت worker تمام شده؛ رسید برای بازیابی خودکار آماده است',
                    resolve_reference_decision:'برای ادامه، تصمیم مرجع را در کارت تطبیق ثبت کنید',
                    resolve_publication_hold:'توقف انتشار را رفع کنید؛ صف پس از آن ادامه می‌دهد',
                    inspect_failure_and_reconcile:'علت شکست و رسید را تطبیق دهید؛ این مورد خودکار بسته نمی‌شود',
                    inspect_result_and_decide:'نتیجهٔ ناقص را در کارت استخراج بررسی کنید'};
                const appendItem=item=>{
                    const age=Number(item.age_seconds);
                    const ageLabel=Number.isFinite(age)?` · ${age<3600?`${Math.floor(age/60)} دقیقه`:`${Math.floor(age/3600)} ساعت`} در صف`:'';
                    const next=nextSteps[item.next_action]||'رسید را بررسی کنید';
                    const row = node('div', null, 'molavi-operation-queue-item');
                    row.appendChild(node('p',`${item.kind === 'batch' ? 'دسته' : item.kind === 'publication' ? 'انتشار' : 'کار'} · ${displayBatchState(item.state)} · تلاش ${item.attempts || 0}${ageLabel} · گام بعدی: ${next}`));
                    if (item.error) row.appendChild(notice(displayBatchError(item.error), 'warning'));
                    if (item.id) {
                        const technical = document.createElement('details');
                        technical.appendChild(node('summary', 'شناسه فنی رسید'));
                        technical.appendChild(node('code', item.id));
                        row.appendChild(technical);
                    }
                    rows.appendChild(row);
                    const itemId=String(item.id || '');
                    if (item.kind==='batch' && item.state==='failed' && failedBatchIds.includes(itemId)) {
                        const reconcileOne=node('button','همگام‌سازی و تطبیق همین دسته','button button-secondary');
                        reconcileOne.type='button';
                        reconcileOne.disabled=!config.reviewStoreUrl||!config.reviewStoreNonce;
                        reconcileOne.addEventListener('click',async()=>{
                            if (!window.confirm('کاتالوگ فعلی یک‌بار با ووکامرس همگام شود؟ این دسته فقط اگر همهٔ پیام‌های منبع ثبت شده باشند بسته می‌شود.')) return;
                            reconcileOne.disabled=true;
                            const auditId=('failed-batch:'+itemId).slice(0,180);
                            const eventId=reviewEventId('publication_backlog',auditId);
                            const context={failed_publication_ids:[],failed_batch_ids:[itemId],target_job_id:itemId,
                                previous_error:String(item.error || ''),queue_counts:counts,observed_at:new Date().toISOString()};
                            const decision={action:'reconcile_one_failed_batch_after_source_verification',
                                note:'Owner authorized a current catalog sync; close this batch only after source verification.'};
                            const endpoint=new URL('/pipeline/reconcile-publication-backlog',config.apiUrl).toString();
                            try {
                                await persistOwnerReviews([reviewRecord(eventId,'publication_backlog',auditId,'pending',context,decision)]);
                                const receipt=await request('POST',{expected_publish_ids:[],expected_batch_ids:[itemId]},endpoint);
                                const catalog=receipt.catalog_sync||{};
                                const reconciled=receipt.batch_job_ids_reconciled||[];
                                const open=receipt.batch_job_ids_left_open||[];
                                if (!/^[a-f0-9]{64}$/.test(receipt.sheet_digest||'')
                                    ||receipt.failed_publications_reconciled!==0
                                    ||!Array.isArray(receipt.failed_publication_ids)||receipt.failed_publication_ids.length
                                    ||!Array.isArray(reconciled)||!Array.isArray(open)
                                    ||receipt.batch_jobs_reconciled!==reconciled.length
                                    ||reconciled.length+open.length!==1
                                    ||[...reconciled,...open].some(id=>id!==itemId)
                                    ||Number(catalog.images_left||0)!==0) throw new Error('invalid_receipt');
                                let auditWarning='';
                                try {
                                    await persistOwnerReviews([reviewRecord(eventId,'publication_backlog',auditId,'accepted',context,decision,receipt)]);
                                } catch (_error) {
                                    auditWarning=' رسید عملیات در پایگاه دادهٔ وردپرس نهایی نشد.';
                                }
                                await dashboard(reconciled.includes(itemId)
                                    ? 'کاتالوگ همگام شد و رسید این دسته کامل بود؛ تطبیق ثبت شد.'+auditWarning
                                    : 'کاتالوگ همگام شد، اما اطلاعات منبع این دسته کامل نیست؛ رسید باز ماند.'+auditWarning,
                                    open.length||auditWarning?'warning':'success',
                                    ['operations','product_status','supplier_prices','publication_holds','sheet_conflicts','ai_confirmations']);
                            } catch(error) {
                                try {
                                    await persistOwnerReviews([reviewRecord(eventId,'publication_backlog',auditId,
                                        error.remoteRejected?'failed':'pending',context,decision,
                                        {error:String(error.message||'request_failed')})]);
                                } catch(_ignored) {}
                                errorBox(errorText(error.message),rows);
                                reconcileOne.disabled=false;
                            }
                        });
                        rows.appendChild(reconcileOne);
                    }
                };
                updateSummary();
                pageState.items.forEach(appendItem);
                details.appendChild(summary);
                details.appendChild(rows);
                const pageStatus=node('p','');
                const loadMore=node('button','دریافت موارد بعدی','button button-secondary');
                loadMore.type='button';
                loadMore.hidden=!pageState.cursor;
                loadMore.addEventListener('click',async()=>{
                    if(!pageState.cursor||pageState.loading)return;
                    pageState.loading=true;loadMore.disabled=true;loadMore.textContent='در حال دریافت…';pageStatus.textContent='';
                    try {
                        const url=new URL(config.apiUrl);url.searchParams.set('limit','100');url.searchParams.set('cursor',pageState.cursor);
                        const result=await request('GET',undefined,url.toString());
                        const next=result.operations||{};
                        const seen=new Set(pageState.items.map(item=>String(item.id||'')));
                        (Array.isArray(next.items)?next.items:[]).forEach(item=>{
                            const id=String(item.id||'');if(!id||seen.has(id))return;
                            seen.add(id);pageState.items.push(item);appendItem(item);
                        });
                        pageState.cursor=next.next_cursor||null;loadMore.hidden=!pageState.cursor;updateSummary();
                    } catch(error) {
                        pageStatus.className='notice notice-warning';
                        pageStatus.textContent=`دریافت صفحه بعدی ناموفق بود: ${errorText(error.message)}`;
                    } finally {
                        pageState.loading=false;loadMore.disabled=false;loadMore.textContent=pageState.cursor?'دریافت موارد بعدی':'همه موارد بارگذاری شد';
                    }
                });
                details.appendChild(loadMore);
                details.appendChild(pageStatus);
                technical.appendChild(details);
            }
            const reportButton=node('button','بررسی اتصال کاتالوگ به فروشگاه','button button-secondary');
            reportButton.type='button';
            const report=node('div');
            reportButton.addEventListener('click',async()=>{
                reportButton.disabled=true; report.replaceChildren(node('p','در حال تطبیق رسیدها، کالاها و فروشگاه…'));
                try {
                    const url=new URL('/pipeline/reconciliation?limit=200',config.apiUrl).toString();
                    const result=await request('GET',undefined,url); const summary=result.summary||{}; const validation=result.validation||{};
                    const sourceIntegrity=result.source_integrity||{};
                    const catalogWoo=result.catalog_woo||{};
                    report.replaceChildren(node('p',`پیام‌ها: ${summary.receipts||0} · کالاهای فعلی: ${summary.products||0} · نیازمند بررسی: ${summary.offers_needing_review||0} · پیوند منبع شکسته: ${sourceIntegrity.products_with_issues||0} · انتشار کامل: ${summary.publication_done||0} · انتظار/خطا: ${summary.publication_pending||0} · SKU ناموجود: ${summary.woo_missing||0} · زمان: ${result.generated_at||'نامشخص'}`));
                    report.appendChild(node('p',`وضعیت اتصال فروشگاه: ${({checked:'بررسی شد',truncated:'بررسی بخشی',unavailable:'در دسترس نیست',not_configured:'تنظیم نشده'}[validation.woo_state])||validation.woo_state||'نامشخص'}`));
                    report.appendChild(node('p',`بررسی کامل کاتالوگ: ${catalogWoo.products_checked||0} · قابل خرید: ${catalogWoo.purchasable||0} · نگه‌داشته: ${catalogWoo.held||0} · در سایت پیدا نشد: ${catalogWoo.not_visible||0} · قابل خرید با وجود توقف: ${catalogWoo.held_but_purchasable||0}`));
                    const catalogIssues=Array.isArray(catalogWoo.issues)?catalogWoo.issues:[];
                    if(catalogIssues.length){
                        const details=document.createElement('details');
                        details.appendChild(node('summary',`جزئیات ناهماهنگی کاتالوگ (${catalogIssues.length})`));
                        const list=document.createElement('ul');
                        const labels={not_visible:'در فروشگاه پیدا نشد',held_but_purchasable:'متوقف است اما هنوز قابل خرید است',ambiguous_product_id:'شناسه محصول تکراری است',no_catalog_projection:'پروژکشن کاتالوگ در دسترس نیست',not_checked:'اتصال فروشگاه بررسی نشد',not_checked_catalog_truncated:'فهرست فروشگاه کامل دریافت نشد'};
                        catalogIssues.forEach(issue=>{
                            const item=document.createElement('li');
                            item.appendChild(node('span',`${issue.product_name||issue.product_id||'کالای بدون شناسه'} · ${labels[issue.state]||issue.state} · شناسه: ${issue.product_id||'—'} · SKU: ${issue.woo_sku||'—'}`));
                            const sourceLink=safeSourceLink(issue.source_post_url);
                            if(sourceLink)item.appendChild(sourceLink);
                            if(Array.isArray(issue.source_integrity_codes)&&issue.source_integrity_codes.length){
                                item.appendChild(node('small',` · پیوند منبع: ${issue.source_integrity_codes.join('، ')}`));
                            }
                            list.appendChild(item);
                        });
                        details.appendChild(list);report.appendChild(details);
                    }
                    const linkIssues=Array.isArray(sourceIntegrity.issues)?sourceIntegrity.issues:[];
                    if(linkIssues.length){
                        const details=document.createElement('details');
                        details.appendChild(node('summary',`محصولات نیازمند بازیابی پیشنهاد منبع (${sourceIntegrity.products_with_issues||linkIssues.length})`));
                        const labels={missing_product_id:'شناسه کالا ثبت نشده',duplicate_product_id:'شناسه کالا تکراری است',duplicate_product_identity:'کالا با مشخصات یکسان دوباره ثبت شده',missing_latest_offer:'شناسه پیشنهاد منبع ثبت نشده',offer_not_found:'پیشنهاد منبع در فهرست Offers پیدا نشد',duplicate_offer_id:'شناسه پیشنهاد تکراری است',product_id_mismatch:'پیشنهاد به شناسه کالای دیگری وصل شده است',source_provenance_mismatch:'اطلاعات مبدأ با پیشنهاد ثبت‌شده نمی‌خواند'};
                        linkIssues.forEach(item=>{
                            const line=document.createElement('li');
                            const codes=(item.codes||[]).map(code=>labels[code]||code).join('، ');
                            line.appendChild(document.createTextNode(`${item.product_name||'کالای بدون نام'} · ${item.channel||'کانال نامشخص'} · ${codes}`));
                            let sourceUrl='';
                            try { const parsed=new URL(item.source_post_url||''); if(parsed.protocol==='https:'&&['t.me','www.t.me','bale.ai','www.bale.ai'].includes(parsed.hostname)) sourceUrl=parsed.href; } catch(_ignored) {}
                            if(sourceUrl){ const link=document.createElement('a');link.href=sourceUrl;link.target='_blank';link.rel='noopener noreferrer';link.textContent=' · پست منبع';line.appendChild(link); }
                            details.appendChild(line);
                        });
                        report.appendChild(details);
                    }
                } catch(error) { report.replaceChildren(notice(errorText(error.message),'error')); }
                finally { reportButton.disabled=false; }
            });
            technical.append(reportButton,report);
        }
        storeView.appendChild(technical);
        reextractionPanel(sourceGroup);
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
        const assignedRole = item.reference_assignment && item.reference_assignment.price_role;
        const role = String(fields.price_role || assignedRole || item.price_type || item.reference_price_role_hint || '').toLowerCase();
        if (['unit', 'single', 'per_unit'].includes(role)) return 'unit';
        if (['pack', 'package', 'carton'].includes(role)) return 'package';
        return '';
    }

    function aiSourceDigits(value) {
        return String(value || '')
            .replace(/[۰-۹]/g, digit => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
            .replace(/[٠-٩]/g, digit => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
            .replace(/\D/g, '');
    }

    function aiSourceMessageKey(item) {
        const messageId = String(item && item.message_id || '').trim();
        if (!messageId) return '';
        return JSON.stringify([
            String(item.source_channel || '').trim().toLowerCase(),
            String(item.channel || item.channel_username || '').trim().toLowerCase(),
            messageId,
            String(item.batch_id || '').trim()
        ]);
    }

    function aiSourceSalePrices(content) {
        const prices = new Set();
        const pattern = /(?:قیمت\s*فروش(?:\s*ما)?|قیمت\s*هر\s*(?:یک\s*)?(?:بسته|کارتن|عدد|واحد))(?:\s+[^:：\n]+?)?\s*[:：]\s*([\d۰-۹٠-٩][\d۰-۹٠-٩\s٬,./٫]*)/giu;
        for (const match of String(content || '').matchAll(pattern)) {
            const digits = aiSourceDigits(match[1]);
            if (digits && Number.isSafeInteger(Number(digits)) && Number(digits) > 0) prices.add(digits);
        }
        return prices;
    }

    function aiSourcePriceGap(item, cases) {
        const sourcePrices = aiSourceSalePrices(item && item.original_message);
        if (!sourcePrices.size) {
            const hasExtractedPrice = cases.some(candidate => ['detected_price','sale_price','package_price']
                .some(fieldName => normalizedAiInteger(candidate && candidate[fieldName])));
            return hasExtractedPrice ? {unverified:true} : null;
        }
        const extractedPrices = new Set();
        const packageTotals = new Set();
        const matchedByCount = new Map();
        cases.forEach(candidate => {
            ['detected_price','sale_price','package_price'].forEach(fieldName => {
                const digits = aiSourceDigits(candidate && candidate[fieldName]);
                if (digits) extractedPrices.add(digits);
            });
            const salePrice = normalizedAiInteger(candidate && candidate.sale_price);
            const packageCount = normalizedAiInteger(candidate && candidate.package_count);
            if (salePrice && packageCount > 1) {
                const total = String(salePrice * packageCount);
                packageTotals.add(total);
                if (sourcePrices.has(total) && !sourcePrices.has(String(salePrice))) {
                    matchedByCount.set(`${total}:${salePrice}:${packageCount}`, {
                        source:total, unit:String(salePrice), count:packageCount
                    });
                }
            }
        });
        const missing = [...sourcePrices].filter(price => !extractedPrices.has(price) && !packageTotals.has(price));
        return missing.length || matchedByCount.size
            ? {sourceCount:sourcePrices.size, missing, matchedByCount:[...matchedByCount.values()]} : null;
    }

    function aiSourceVariantGap(item, cases) {
        const source = String(item && item.original_message || '');
        if (!source) return null;
        const sourceLines = source.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
        const extractedLabels = new Set(cases.map(candidate => normalizeAiVariant(
            candidate.variation_type || aiProductAttributes(candidate).variationType || ''))
            .map(value => value.toLocaleLowerCase('fa')).filter(Boolean));
        const countMatch = source.match(/در\s*([0-9۰-۹٠-٩]+)\s*(?:طعم|مزه|رنگ|مدل|نوع)\s*(?=[(（:：])/iu);
        const countHint = normalizedAiInteger(countMatch && countMatch[1]);
        const listLinePattern = /(?:طعم|مزه|رنگ|مدل|نوع)[\s\u200c]*(?:های|ها)[^\n]*[:：]/iu;
        let listLine = sourceLines.find(line => listLinePattern.test(line));
        let variants = [];
        if (listLine) {
            const list = listLine.split(/[:：]/).slice(1).join(':').trim();
            variants = splitAiVariantValues(list);
        } else {
            const parenthetical = sourceLines.find(line => /(?:در\s*[0-9۰-۹٠-٩]+\s*)?(?:طعم|مزه|رنگ|مدل|نوع)\s*[（(][^()]+[)）]/iu.test(line));
            if (parenthetical) {
                const match = parenthetical.match(/(?:در\s*[0-9۰-۹٠-٩]+\s*)?(?:طعم|مزه|رنگ|مدل|نوع)\s*[（(]([^()]+)[)）]/iu);
                variants = splitAiVariantValues(match && match[1]);
            }
        }
        if (!variants.length && extractedLabels.size) {
            listLine = sourceLines.find(line => /[،,؛;]/.test(line) && !/[\d۰-۹٠-٩]/.test(line)
                && !/(?:قیمت|تومان|تومن|ریال|تعداد|کارتن|باکس|بسته)/u.test(line)
                && [...extractedLabels].filter(label => aiVariantOccursInLine(line, label)).length >= 2);
            if (listLine) variants = splitAiVariantValues(listLine);
        }
        const sourceLabels = new Set(variants.map(value => value.toLocaleLowerCase('fa')).filter(Boolean));
        const sourceCount = countHint || sourceLabels.size;
        const extractedCount = extractedLabels.size;
        const missingLabels = [...sourceLabels].filter(label => !extractedLabels.has(label));
        return sourceCount > extractedCount || missingLabels.length
            ? {sourceCount, extractedCount, missingLabels} : null;
    }

    function aiSourceStockGap(item, cases) {
        const source = String(item && item.original_message || '');
        if (!/(?:اتمام\s*موجودی|موجودی\s*(?:ندارد|تمام\s*شده)|ناموجود|sold\s*out|out\s+of\s+stock)/iu.test(source)) return null;
        const captured = cases.length > 0 && cases.every(candidate => /(?:out\s*of\s*stock|sold\s*out|unavailable|ناموجود|اتمام\s*موجودی)/iu
            .test(String(candidate && candidate.stock_status || '')));
        return captured ? null : {historical:true};
    }

    function aiSourceVariantLabel(item) {
        const explicit = aiProductAttributes(item).variationType;
        if (explicit) return explicit;
        const source = String(item && item.original_message || '');
        if (!source) return '';
        const expected = new Set([
            aiSourceDigits(item.detected_price || item.sale_price),
            aiSourceDigits(item.actual_price || item.consumer_price)
        ].filter(Boolean));
        if (!expected.size) return '';
        const labels = new Set();
        const linePattern = /(?:قیمت\s*)?(فروش(?:\s+ما)?|مصرف\s*کننده)(?:\s+([^:：\n]+?))?\s*[:：]\s*([\d۰-۹٠-٩][\d۰-۹٠-٩\s٬,./٫]*)/giu;
        for (const match of source.matchAll(linePattern)) {
            const label = String(match[2] || '').trim().replace(/\s+/g, ' ');
            if (label && expected.has(aiSourceDigits(match[3]))) labels.add(label);
        }
        return labels.size === 1 ? [...labels][0] : labels.size > 1 ? [...labels].join(' / ') : '';
    }

    function aiTelegramUsername(item) {
        if (String(item && item.source_channel || '').toLowerCase() !== 'telegram') return '';
        const username = String(item && (item.channel || item.channel_username) || '').trim().replace(/^@/, '');
        return /^[A-Za-z0-9_]{5,32}$/.test(username) ? username : '';
    }

    function aiSourcePostUrl(item) {
        const direct = String(item && item.source_post_url || '');
        if (direct) return direct;
        const username = aiTelegramUsername(item);
        const messageId = String(item && item.message_id || '').trim();
        if (!username || !/^\d+$/.test(messageId)) return '';
        return `https://t.me/${username}/${messageId}`;
    }

    function aiSourceChannelUrl(item) {
        const username = aiTelegramUsername(item);
        return username ? `https://t.me/s/${username}` : '';
    }

    function formatSourceTimestamp(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        let date;
        if (/^\d{9,13}$/.test(raw)) {
            const timestamp = Number(raw);
            date = new Date(timestamp > 1e12 ? timestamp : timestamp * 1000);
        } else {
            date = new Date(raw);
        }
        if (Number.isNaN(date.getTime())) return raw;
        try {
            return new Intl.DateTimeFormat('fa-IR', {
                timeZone:'Asia/Tehran', dateStyle:'medium', timeStyle:'short'
            }).format(date);
        } catch (_error) { return date.toLocaleString('fa-IR'); }
    }

    function sourceTimestampMillis(value) {
        const raw = String(value || '').trim();
        if (!raw) return NaN;
        if (/^\d{9,13}$/.test(raw)) {
            const numeric = Number(raw);
            return numeric > 1e12 ? numeric : numeric * 1000;
        }
        const parsed = new Date(raw).getTime();
        return Number.isFinite(parsed) ? parsed : NaN;
    }

    function sourceAgeText(value, now = Date.now()) {
        const stamp = sourceTimestampMillis(value);
        if (!Number.isFinite(stamp)) return 'سن منبع نامشخص است';
        const age = now - stamp;
        if (age < 0) return 'تاریخ منبع در آینده است؛ تازگی را بررسی کنید';
        const days = Math.floor(age / 86400000);
        const relative = days < 1 ? 'کمتر از یک روز پیش'
            : `${new Intl.NumberFormat('fa-IR').format(days)} روز پیش`;
        if (age >= 14 * 86400000) {
            return `${relative} · ${days === 14 ? 'به آستانهٔ ۱۴ روز رسیده' : 'از آستانهٔ ۱۴ روز گذشته'}`;
        }
        return `${relative} · کمتر از آستانهٔ ۱۴ روز`;
    }

    function aiSourceDateFacts(item) {
        const created = String(item && item.source_created_at || '').trim();
        const updated = String(item && (item.source_updated_at || item.source_update_at) || '').trim();
        const effective = String(item && (item.source_updated_at || item.source_update_at
            || item.message_timestamp || item.source_timestamp || item.source_created_at) || '').trim();
        const basis = String(item && item.source_timestamp_basis || '').trim();
        const trusted = ['supplier_channel_update','supplier_channel_forward'].includes(basis);
        const describe = value => `${formatSourceTimestamp(value)} · ${sourceAgeText(value)}${trusted ? '' : ' · مبنای منبع تأییدنشده'}`;
        const createdMillis = sourceTimestampMillis(created);
        const effectiveMillis = sourceTimestampMillis(effective);
        const effectiveDiffersFromCreated = created && effective
            && Number.isFinite(createdMillis) && Number.isFinite(effectiveMillis)
            && createdMillis !== effectiveMillis;
        const rows = [];
        if (created) rows.push(['تاریخ انتشار اولیه', describe(created)]);
        if (updated) rows.push(['آخرین ویرایش منبع', describe(updated)]);
        else if (created && basis === 'supplier_channel_update' && effectiveDiffersFromCreated) {
            rows.push(['وضعیت ویرایش منبع', 'زمان آخرین منبع با انتشار اولیه فرق دارد؛ تاریخ ویرایش جداگانه در دادهٔ قدیمی ثبت نشده است']);
        } else if (created && basis === 'supplier_channel_update') {
            rows.push(['وضعیت ویرایش منبع', 'ویرایشی ثبت نشده؛ تاریخ انتشار اولیه مبنای تازگی است']);
        } else if (created && basis === 'supplier_channel_forward') {
            rows.push(['وضعیت ویرایش منبع', 'زمان ویرایش در دادهٔ پیام فورواردشده در دسترس نیست']);
        }
        if (!created && !updated && effective) {
            const label = basis === 'supplier_channel_update' ? 'آخرین زمان منبع ثبت‌شده'
                : basis === 'supplier_channel_forward' ? 'تاریخ پیام منبع ثبت‌شده' : 'زمان ثبت‌شدهٔ پیام';
            rows.push([label, describe(effective)]);
        } else if (!updated && effectiveDiffersFromCreated) {
            const label = basis === 'supplier_channel_update' ? 'آخرین زمان منبع ثبت‌شده'
                : 'آخرین زمان ثبت‌شدهٔ پیام';
            rows.push([label, describe(effective)]);
        }
        return rows;
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

    function cleanAiVariantToken(value) {
        return String(value || '').normalize('NFKC')
            .replace(/^\s*(?:(?:موجود|در\s*دسترس|available)\s*[:：]?\s*)+/iu, '')
            .trim().replace(/^[\s:：،,؛;*–—()（）-]+|[\s:：،,؛;*–—()（）-]+$/gu, '');
    }

    function splitAiVariantValues(value) {
        const parts = String(value || '').split(/[،,؛;]+/);
        if (parts.length === 1 && /\s+و\s+/u.test(parts[0])) {
            parts.splice(0, 1, ...parts[0].split(/\s+و\s+/u));
        } else if (parts.length && /\s+و\s+/u.test(parts[parts.length - 1])) {
            parts.splice(parts.length - 1, 1, ...parts[parts.length - 1].split(/\s+و\s+/u));
        }
        return [...new Set(parts.map(cleanAiVariantToken).filter(Boolean))];
    }

    function normalizeAiVariant(value) {
        const raw = String(value || '').normalize('NFKC').trim();
        const parenthetical = raw.match(/[（(]([^()]+)[)）]\s*(.+)$/u);
        if (parenthetical) {
            const listed = splitAiVariantValues(parenthetical[1]);
            if (listed.length > 1) {
                const tail = cleanAiVariantToken(parenthetical[2]);
                return listed.some(item => item.toLocaleLowerCase('fa') === tail.toLocaleLowerCase('fa'))
                    ? tail : '';
            }
        }
        let text = cleanAiVariantToken(raw);
        const fullList = text.match(/[（(]([^()]+)[)）]$/u);
        if (fullList && splitAiVariantValues(fullList[1]).length > 1) return '';
        return text.replace(/^(?:طعم|مزه|رنگ|مدل|نوع)\s*[:：]\s*/u, '').trim();
    }

    function aiVariantOccursInLine(line, variant) {
        const normalize = value => String(value || '').normalize('NFKC')
            .replace(/[،,؛;:()[\]{}]/g, ' ').replace(/\s+/g, ' ').trim().toLocaleLowerCase('fa');
        const source = normalize(line).split(' ').filter(Boolean);
        const value = normalize(variant).split(' ').filter(Boolean);
        if (!value.length) return false;
        return source.some((_, index) => value.every((token, offset) => source[index + offset] === token));
    }

    function aiProductAttributes(item) {
        const title = String(item.product_name || '');
        const variantMatch = title.match(/(?:با\s*)?(?:طعم|مزه|رنگ|مدل|نوع)\s*[:：]?\s*([^،,؛;\n]+)/i);
        const inferredVariant = variantMatch
            ? variantMatch[1].split(/\s+(?:قیمت|price|تومان|تومن|ریال|تعداد)\b/i)[0].trim()
            : '';
        const volumeMatch = title.match(/[\d۰-۹٠-٩]+(?:[.,/٫][\d۰-۹٠-٩]+)?\s*(?:میلی\s*[\u200c\s]*لیتر|میلیلیتر|میل|مل|ml|cc|لیتر|ل)(?![\w])/i);
        const packagingMatch = title.match(/تترا[\s\u200c-]*پک|\btetra[\s-]*pack\b|\btetrapak\b/i);
        const explicitPackaging = packagingMatch ? 'تتراپک' : '';
        const modelPackaging = String(item.packaging || '');
        return {
            variationType: normalizeAiVariant(item.variation_type || inferredVariant),
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
        const list = name.match(/(?:در\s*[0-9۰-۹٠-٩]+\s*)?(?:طعم|مزه|رنگ|مدل|نوع)\s*[（(]([^()]+)[)）]/u);
        if (list && splitAiVariantValues(list[1]).length > 1) {
            name = name.replace(list[0], ' ');
        }
        if (variant) {
            const escaped = variant.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            name = name.replace(new RegExp(`(?:با\\s*)?(?:طعم|مزه)\\s*[:：]?\\s*${escaped}`, 'iu'), ' ');
            name = name.replace(new RegExp(`(^|[\\s،,])${escaped}(?=$|[\\s،,])`, 'giu'), '$1 ');
        }
        name = name.replace(/(?:^|[\s،,])موجود\s*[:：]?\s*$/u, ' ');
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
            item.stock_status, Boolean(item.historical_reextract), Boolean(item.source_current_check_required),
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

    async function submitAiCaseDecisions(items, choice, fields, card, controls, sourceVerified = false, options = {}) {
        if (!items.length) return;
        const note = choice === 'confirm' ? aiDecisionNote : '';
        const requests = items.map(item => {
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, signature: item.signature,
                choice, note, expected_decision_id: item.previous_decision_id || null,
                source_current_verified: Boolean(choice === 'confirm' && sourceVerified
                    && (item.historical_reextract || item.source_current_check_required || options.sourceStockGap)),
                fields: choice === 'confirm' ? {...fields} : {}
            };
            return {item, payload, eventId: reviewEventId('ai_confirmation', item.offer_id),
                context: storedReviewContext('ai_confirmation', item)};
        });
        const priorDisabled = controls.map(button => button.disabled);
        controls.forEach(button => { button.disabled = true; });
        const progressStatus = actionStatus(card, 'در حال ثبت تصمیم با درخواست AJAX…');
        try {
            await persistOwnerReviews(requests.map(({item, payload, eventId, context}) => reviewRecord(
                eventId, 'ai_confirmation', item.case_id, 'pending', context,
                {choice, offer_id:item.offer_id, signature:item.signature,
                    expected_decision_id:payload.expected_decision_id, note,
                    source_current_verified:payload.source_current_verified, fields:payload.fields}
            )));
        } catch (_error) {
            updateActionStatus(progressStatus, 'ذخیره زمینه در وردپرس ناموفق بود؛ هیچ تغییری ارسال نشد.', 'error');
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
                receipt = await request('POST', payload, undefined, options.apiKey || '');
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
            verifiedCurrentPriceCases.delete(currentPriceVerificationKey(item));
            try {
                await persistOwnerReviews([reviewRecord(eventId, 'ai_confirmation', item.case_id,
                    'accepted', context, decision, receipt)]);
            } catch (_error) { localWarning = true; }
        }
        const label = choice === 'confirm' ? 'تأیید شد' : 'به‌عنوان «کالا نیست» ثبت شد';
        const summary = `${accepted} از ${items.length} مورد ${label}${failed ? `؛ ${failed} مورد ناموفق ماند${firstError ? ` (${firstError})` : ''}` : ''}${localWarning ? ' رسید محلی وردپرس به همگام‌سازی نیاز دارد.' : ''}`;
        updateActionStatus(progressStatus, summary, failed || localWarning ? 'warning' : 'success');
        if (typeof options.refresh === 'function') {
            await options.refresh(summary, failed || localWarning ? 'warning' : 'success', progressStatus,
                ['ai_confirmations','product_status','supplier_prices','sheet_conflicts','operations']);
        } else {
            await aiConfirmations(summary, failed || localWarning ? 'warning' : 'success');
        }
    }

    function aiReferenceAssignmentSection(item, contextStored, decisionsEnabled, options = {}) {
        if (!item.reference_snapshot_id) return null;
        const candidates = Array.isArray(item.reference_candidates)
            ? item.reference_candidates.filter(candidate => candidate && candidate.reference_id
                && String(candidate.name || '').trim()).slice(0, 12) : [];
        const section = node('section', null, 'molavi-ai-reference-assignment');
        if (!options.groupedVariants) {
            section.appendChild(node('strong', 'تطبیق با کالای مرجع — انتخاب را همین‌جا ثبت کنید'));
            section.appendChild(node('p', 'این انتخاب فقط مرجع و مبنای مقایسه را ذخیره می‌کند؛ قیمت، موجودی و انتشار جداگانه می‌مانند.'));
        }
        const assignment = item.reference_assignment || null;
        if (assignment && assignment.choice === 'match') {
            section.appendChild(notice(`مرجع ${assignment.reference_name ? `«${assignment.reference_name}»` : 'انتخاب‌شده'} ذخیره شد · ${assignment.price_role === 'package' ? 'قیمت بسته/کارتن' : 'قیمت هر واحد'}؛ قیمت و موجودی هنوز جداگانه بررسی می‌شوند.`, 'success'));
        } else if (item.reference_match_id) {
            section.appendChild(notice(`مرجع فعلی: ${item.reference_match_name || 'تطبیق ثبت‌شده'}. برای تغییر، یک گزینهٔ زیر را انتخاب کنید.`, 'info'));
        }
        if (!candidates.length) {
            section.appendChild(notice('برای این مورد از نسخه فعال فهرست، گزینهٔ مرجع مشابهی پیدا نشد.', 'info'));
            const link = ownerWorkflowLink('molavi-reference-snapshots', 'به‌روزرسانی فهرست مرجع کالاها');
            if (link) section.appendChild(link);
            return section;
        }

        const chooser = node('div', null, 'molavi-ai-reference-chooser');
        const subject = options.groupedVariants ? 'کالای مرجع'
            : item.variation_type || (options.parentName
                && aiDisplayKey(options.parentName) === aiDisplayKey(item.product_name) ? 'این مورد'
                    : item.product_name || 'این مورد');
        chooser.appendChild(node('label', `مرجع برای ${subject}`));
        const referenceSelect = document.createElement('select');
        referenceSelect.setAttribute('aria-label', 'انتخاب کالای مرجع');
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = `${new Intl.NumberFormat('fa-IR').format(candidates.length)} گزینهٔ پیشنهادی؛ انتخاب کنید`;
        referenceSelect.appendChild(placeholder);
        const candidateById = new Map();
        candidates.forEach(candidate => {
            const referenceId = String(candidate.reference_id || '');
            if (!referenceId) return;
            candidateById.set(referenceId, candidate);
            const option = document.createElement('option');
            option.value = referenceId;
            const hint = candidate.price_role_hint && candidate.price_role_hint.role;
            const priceText = hint === 'unit' ? ' · قیمت مرجع واحد'
                : hint === 'package' ? ' · قیمت مرجع بسته' : '';
            option.textContent = `${candidate.name}${priceText}`;
            referenceSelect.appendChild(option);
        });
        const roleSelect = document.createElement('select');
        roleSelect.setAttribute('aria-label', 'مبنای قیمت مرجع');
        [['', 'انتخاب مبنای قیمت'], ['unit', 'قیمت هر واحد'], ['package', 'قیمت کل بسته / کارتن']]
            .forEach(([value, label]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                roleSelect.appendChild(option);
            });
        referenceSelect.value = assignment && candidateById.has(assignment.reference_id)
            ? assignment.reference_id : '';
        roleSelect.value = assignment && ['unit', 'package'].includes(assignment.price_role)
            ? assignment.price_role : '';
        let roleTouched = Boolean(assignment && ['unit', 'package'].includes(assignment.price_role));
        const compareDetails = document.createElement('details');
        compareDetails.className = 'molavi-reference-compare-details';
        compareDetails.appendChild(node('summary', 'مقایسه نام، ویژگی‌ها و قیمت'));
        const compare = node('div', null, 'molavi-ai-reference-comparison');
        const renderComparison = () => {
            compare.replaceChildren();
            const candidate = candidateById.get(referenceSelect.value);
            if (!candidate) {
                compare.appendChild(node('span', 'برای دیدن تطبیق نام، طعم، اندازه، تعداد و قیمت، یک مرجع را انتخاب کنید.'));
                return;
            }
            compare.appendChild(node('strong', candidate.name));
            const prices = referencePriceSummary(candidate, item.sale_price || item.detected_price);
            if (prices) compare.appendChild(node('p', prices));
            const suggestedRole = candidate.price_role_hint && candidate.price_role_hint.role;
            const savedRole = assignment && assignment.choice === 'match'
                && assignment.reference_id === candidate.reference_id ? assignment.price_role : '';
            if (['unit','package'].includes(savedRole) && ['unit','package'].includes(suggestedRole)
                && savedRole !== suggestedRole) {
                const roleName = value => value === 'package' ? 'قیمت کل بسته/کارتن' : 'قیمت هر واحد';
                compare.appendChild(notice(`نقش ثبت‌شده «${roleName(savedRole)}» با راهنمای مقایسهٔ مرجع («${roleName(suggestedRole)}») متفاوت است. مبلغ پیام به قیمت ${roleName(suggestedRole)} نزدیک‌تر است؛ پیش از تأیید نهایی، این نقش را در منوی بالا بازبینی کنید.`, 'warning'));
            }
            const comparisons = referenceComparisonSummary(candidate);
            if (comparisons.length) {
                const list = document.createElement('ul');
                comparisons.forEach(value => list.appendChild(node('li', value)));
                compare.appendChild(list);
            } else compare.appendChild(node('p', 'ویژگی قابل مقایسهٔ دیگری در مرجع ثبت نشده است.'));
            compare.appendChild(node('small', 'شباهت و قیمت پیشنهادی فقط راهنما هستند؛ مرجع و نقش قیمت را خودتان انتخاب کنید.'));
        };
        const save = node('button', assignment ? 'ذخیرهٔ مرجع این مورد' : 'ثبت مرجع این مورد', 'button button-secondary');
        save.type = 'button';
        const updateEnabled = () => {
            save.disabled = !contextStored || !decisionsEnabled || !referenceSelect.value || !roleSelect.value;
        };
        referenceSelect.addEventListener('change', () => {
            if (!roleTouched) {
                const selected = candidateById.get(referenceSelect.value);
                const suggestedRole = selected && selected.price_role_hint && selected.price_role_hint.role;
                roleSelect.value = ['unit','package'].includes(suggestedRole) ? suggestedRole : '';
            }
            renderComparison(); updateEnabled();
        });
        roleSelect.addEventListener('change', () => { roleTouched = true; updateEnabled(); });
        save.addEventListener('click', async () => {
            const selected = candidateById.get(referenceSelect.value);
            if (!selected || !roleSelect.value) return;
            save.disabled = true;
            const originalLabel = assignment ? 'ذخیرهٔ مرجع این مورد' : 'ثبت مرجع این مورد';
            save.textContent = 'در حال ذخیره…';
            const status = actionStatus(section, 'در حال ذخیره مرجع در وردپرس و نسخه فعال فهرست…');
            const eventId = reviewEventId('reference_item', item.offer_id);
            const payload = {
                action: 'assign_ai_reference', case_id: item.case_id, offer_id: item.offer_id,
                signature: item.signature, snapshot_id: item.reference_snapshot_id,
                reference_id: String(selected.reference_id), price_role: roleSelect.value,
                expected_decision_id: assignment && assignment.decision_id || null
            };
            const context = storedReviewContext('ai_confirmation', item);
            const decision = {action:payload.action, reference_id:payload.reference_id,
                price_role:payload.price_role, snapshot_id:payload.snapshot_id,
                expected_decision_id:payload.expected_decision_id};
            try {
                await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.offer_id,
                    'pending', context, decision)]);
            } catch (_error) {
                updateActionStatus(status, 'ذخیرهٔ سابقه در وردپرس ناموفق بود؛ درخواست مرجع ارسال نشد.', 'error');
                errorBox('مرجع ارسال نشد چون ذخیرهٔ محلی وردپرس ناموفق بود.', section);
                save.textContent = originalLabel;
                updateEnabled();
                return;
            }
            try {
                const receipt = await request('POST', payload, undefined, 'reference_items');
                if (receipt.case_id !== item.case_id || receipt.offer_id !== item.offer_id
                    || receipt.snapshot_id !== item.reference_snapshot_id
                    || receipt.reference_id !== payload.reference_id || receipt.price_role !== payload.price_role
                    || !/^[a-f0-9]{64}$/.test(receipt.decision_id || '')
                    || receipt.projection_state !== 'awaiting_ai_confirmation' || receipt.publish_job !== null) {
                    throw new Error('invalid_receipt');
                }
                let localWarning = false;
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.offer_id,
                        'accepted', context, decision, receipt)]);
                } catch (_error) { localWarning = true; }
                const message = `مرجع «${receipt.reference_name || receipt.reference_id}» ذخیره شد؛ قیمت، موجودی و انتشار هنوز تأیید نشده‌اند.`;
                if (typeof options.refresh === 'function') {
                    await options.refresh(message, localWarning ? 'warning' : 'success', status, ['ai_confirmations']);
                } else {
                    updateActionStatus(status, message, localWarning ? 'warning' : 'success');
                    save.textContent = 'مرجع ذخیره شد';
                }
            } catch (error) {
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.offer_id,
                        error.remoteRejected ? 'failed' : 'pending', context, decision,
                        {error:String(error.message || 'request_failed'), request_status:error.requestStatus || null})]);
                } catch (_ignored) {}
                updateActionStatus(status, errorText(error.message), 'error');
                errorBox(errorText(error.message), section);
                save.textContent = originalLabel;
                updateEnabled();
            }
        });
        renderComparison();
        updateEnabled();
        chooser.append(referenceSelect, roleSelect, save);
        compareDetails.appendChild(compare);
        section.append(chooser, compareDetails);
        if (!contextStored) section.appendChild(notice('ثبت سابقهٔ این بررسی در وردپرس ناموفق بود؛ ابتدا صفحه را تازه کنید.', 'error'));
        return section;
    }

    function aiCaseCard(item, onSelect = null, contextStored = true, decisionsEnabled = true,
        relatedItems = [item], options = {}) {
        const card = node('section', null, options.embedded
            ? 'molavi-product-subsection molavi-review-card molavi-ai-review-card'
            : 'card molavi-review-card molavi-ai-review-card');
        if (relatedItems.length > 1) card.classList.add('molavi-ai-variant-group');
        const cardName = relatedItems.length > 1 ? aiVariantBaseName(item) || item.product_name : item.product_name;
        const duplicateParentName = Boolean(options.embedded && options.parentName
            && aiDisplayKey(options.parentName) === aiDisplayKey(cardName));
        const header = reviewCardHeader(duplicateParentName ? '' : cardName || 'کالای بدون نام', relatedItems.length > 1
            ? `گروه ${new Intl.NumberFormat('fa-IR').format(relatedItems.length)} نوع هم‌قیمت` : 'استخراج');
        if (typeof onSelect === 'function') {
            const selection = node('label', null, 'molavi-ai-case-select');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.disabled = !contextStored || !decisionsEnabled;
            checkbox.addEventListener('change', () => relatedItems.forEach(row => onSelect(row, checkbox.checked, checkbox)));
            selection.append(checkbox, document.createTextNode(' انتخاب برای رد گروهی'));
            header.appendChild(selection);
        }
        card.appendChild(header);
        if (relatedItems.some(row => row.publication_held)) {
            card.appendChild(notice('این منبع به دستهٔ متوقف‌شده وصل است؛ پس از رفع همان توقف می‌توانید تصمیم بگیرید. موارد مستقل همچنان قابل رسیدگی‌اند.', 'warning'));
        }
        if (Number(item.duplicate_count) > 1) {
            card.appendChild(notice(`این پیشنهاد از همان پیام ${new Intl.NumberFormat('fa-IR').format(item.duplicate_count)} بار استخراج شده است؛ یک تصمیم همه نسخه‌های تکراری را از صف بررسی کنار می‌گذارد.`, 'info'));
        }
        const extractedAttributes = [];
        const displayAttributes = aiProductAttributes(item);
        const sourceVariant = aiSourceVariantLabel(item);
        const addAttribute = (label, value) => {
            if (value && !aiTitleContainsAttribute(cardName, value, label)) extractedAttributes.push([label, value]);
        };
        if (relatedItems.length === 1) {
            addAttribute('طعم / مدل', displayAttributes.variationType);
            if (sourceVariant && aiDisplayKey(sourceVariant) !== aiDisplayKey(displayAttributes.variationType)
                && !aiTitleContainsAttribute(cardName, sourceVariant, 'طعم / مدل')) {
                addAttribute('نوع ذکرشده در متن', sourceVariant);
            }
        }
        addAttribute('حجم', displayAttributes.volume);
        addAttribute('وزن', displayAttributes.weight);
        addAttribute('بسته‌بندی', displayAttributes.packaging);
        const packageCount = normalizedAiInteger(item.package_count);
        if (packageCount) addAttribute('تعداد در بسته / کارتن', new Intl.NumberFormat('fa-IR').format(packageCount));
        addAttribute('برند', displayAttributes.brand);
        addAttribute('دسته', displayAttributes.category);
        if (extractedAttributes.length) {
            card.appendChild(reviewFacts(extractedAttributes, 'molavi-review-facts molavi-ai-product-attributes'));
        }
        if (relatedItems.length > 1) {
            const variantBlock = node('section', null, 'molavi-review-block molavi-ai-variant-block');
            variantBlock.appendChild(node('h3', 'انواع هم‌قیمت در همین پیام'));
            const variants = node('ul', null, 'molavi-ai-variant-list');
            relatedItems.forEach(row => variants.appendChild(node('li', aiProductAttributes(row).variationType || row.product_name || 'نوع نامشخص')));
            variantBlock.appendChild(variants);
            card.appendChild(variantBlock);
        }
        const assignmentOptions = {...options, groupedVariants:relatedItems.length > 1};
        const assignmentSections = relatedItems.map(referenceItem => aiReferenceAssignmentSection(referenceItem,
            contextStored, decisionsEnabled, assignmentOptions)).filter(Boolean);
        if (assignmentSections.length > 1) {
            const assignments = document.createElement('details');
            assignments.className = 'molavi-ai-group-reference-details';
            assignments.appendChild(node('summary', `تطبیق مرجع هر نوع (${new Intl.NumberFormat('fa-IR').format(assignmentSections.length)})`));
            assignments.appendChild(node('p', 'برای هر طعم مرجع همان نوع را انتخاب کنید؛ این مرحله قیمت یا موجودی را تأیید نمی‌کند.'));
            assignmentSections.forEach((section, index) => {
                section.prepend(node('h4', aiProductAttributes(relatedItems[index]).variationType
                    || relatedItems[index].product_name || `نوع ${index + 1}`));
                assignments.appendChild(section);
            });
            card.appendChild(assignments);
        } else assignmentSections.forEach(section => card.appendChild(section));
        const roleLabels = {unit: 'هر واحد', single: 'هر واحد', per_unit: 'هر واحد',
            pack: 'هر بسته', package: 'هر بسته', carton: 'هر کارتن'};
        const roleLabel = roleLabels[String(item.price_type || '').toLowerCase()]
            || 'مبنای هر واحد یا بسته هنوز مشخص نیست';
        const detectedSupplierPrice = normalizedAiInteger(item.sale_price)
            || (['pack','package','carton'].includes(String(item.price_type || '').toLowerCase())
                ? normalizedAiInteger(item.package_price) || normalizedAiInteger(item.detected_price)
                : normalizedAiInteger(item.detected_price));
        const packagePrice = normalizedAiInteger(item.package_price);
        const visibleSupplierPrice = detectedSupplierPrice || packagePrice;
        const regularPrice = normalizedAiInteger(item.actual_price || item.consumer_price);
        const regularPackagePrice = normalizedAiInteger(item.double_pack_consumer_price
            || item.consumer_package_price || item.regular_package_price);
        const priceFacts = [
            ['قیمت فروش تأمین‌کننده', visibleSupplierPrice ? amount(visibleSupplierPrice) : 'در پیام پیدا نشد'],
            ['مبنای قیمت در پیام', roleLabel],
            ['قیمت مصرف‌کننده / عادی', regularPrice ? amount(regularPrice) : 'در پیام پیدا نشد'],
            ['موجودی اعلام‌شده', aiStockLabel(item.stock_status)],
            ['حداقل خرید', item.min_qty || 'ثبت نشده']
        ];
        if (!options.embedded) priceFacts.push(['تأمین‌کننده', item.channel || 'نامشخص']);
        if (packagePrice && packagePrice !== visibleSupplierPrice) {
            priceFacts.push(['قیمت کل بسته / کارتن تأمین‌کننده', amount(packagePrice)]);
        }
        if (regularPackagePrice) priceFacts.push(['قیمت عادی کل بسته / کارتن', amount(regularPackagePrice)]);
        card.appendChild(reviewFacts(priceFacts, 'molavi-review-facts molavi-ai-price-facts'));
        card.appendChild(reviewFacts([['دلیل بررسی', aiReviewReason(item.reason)]], 'molavi-review-facts molavi-review-reason'));
        const sourceDates = aiSourceDateFacts(item);
        if (sourceDates.length) card.appendChild(reviewFacts(sourceDates,
            'molavi-review-facts molavi-ai-source-date-facts'));
        const sourceTools = node('div', null, 'molavi-card-source-tools');
        const sourcePostUrl = aiSourcePostUrl(item);
        const originalPost = safeSourceLink(sourcePostUrl,
            item.historical_reextract ? 'باز کردن پیام مبدأ (تاریخی)'
                : sourcePostUrl && sourcePostUrl !== item.source_post_url ? 'باز کردن پست تلگرام' : 'باز کردن پیام اصلی');
        if (originalPost) sourceTools.appendChild(originalPost);
        if (sourceTools.childNodes.length) card.appendChild(sourceTools);
        const sourcePriceNeedsReview = Boolean(options.sourcePriceGap
            && (options.sourcePriceGap.unverified
                || (Array.isArray(options.sourcePriceGap.missing) && options.sourcePriceGap.missing.length)));
        const sourceVariantNeedsReview = Boolean(options.sourceVariantGap);
        const sourceStockNeedsReview = Boolean(options.sourceStockGap);
        if (item.image_file_id) {
            const mediaDetails = node('details', null, 'molavi-review-media-details');
            mediaDetails.appendChild(node('summary', 'دیدن تصویر پیام مبدأ'));
            const sourceImage=node('img',null,'molavi-ai-source-image');
            sourceImage.src=new URL('/media/file/'+encodeURIComponent(String(item.image_file_id)),config.apiUrl).toString();
            sourceImage.alt='תמונת منبع پیشنهاد'; sourceImage.loading='lazy'; sourceImage.decoding='async';
            sourceImage.referrerPolicy='no-referrer';
            sourceImage.onerror=()=>sourceImage.remove();
            mediaDetails.appendChild(sourceImage);
            card.appendChild(mediaDetails);
        }
        if (item.media_ocr_text) {
            card.appendChild(notice('این متن از روی تصویر خوانده شده و هنوز تصمیم نهایی نیست؛ نام و همه مبلغ‌ها را با عکس مقایسه کنید.','warning'));
            const ocrDetails=document.createElement('details');
            ocrDetails.appendChild(node('summary','متن خوانده‌شده از تصویر (OCR)'));
            ocrDetails.appendChild(node('pre',item.media_ocr_text));
            card.appendChild(ocrDetails);
        }
        if (item.historical_reextract) {
            const recentSupplierPosts = safeSourceLink(aiSourceChannelUrl(item), 'دیدن پست‌های جدیدتر تأمین‌کننده');
            if (recentSupplierPosts) card.appendChild(recentSupplierPosts);
            card.appendChild(notice(`آخرین زمان مؤثر منبع ${sourceAgeText(item.source_updated_at || item.source_update_at || item.message_timestamp || item.source_created_at)} است؛ پست قدیمی به‌تنهایی قیمت امروز را ثابت نمی‌کند. پیش از ثبت نهایی، قیمت را از منبع فعلی بررسی کنید.`, 'warning'));
        } else if (item.source_current_check_required) {
            card.appendChild(notice(aiSourceCurrentCheckMessage(item), 'warning'));
        }
        if (options.sourcePriceGap) {
            if (options.sourcePriceGap.unverified) {
                card.appendChild(notice('متن پیام مبدأ مبلغ فروش تأمین‌کننده را با برچسب روشن نشان نمی‌دهد؛ پیش از تأیید، قیمت فعلی را در منبع بررسی و در فرم اصلاح کنید.', 'warning'));
            } else {
                if (options.sourcePriceGap.missing.length) {
                    const missing = options.sourcePriceGap.missing.map(value => amount(Number(value))).join('، ');
                    card.appendChild(notice(`متن پیام ${options.sourcePriceGap.sourceCount} مبلغ فروش متمایز دارد، اما ${missing} در هیچ مورد استخراج‌شده‌ای دیده نمی‌شود؛ بازاستخراج این پیام برای بررسی کامل لازم است.`, 'warning'));
                }
                (options.sourcePriceGap.matchedByCount || []).forEach(match => {
                    card.appendChild(notice(`مبلغ منبع ${amount(Number(match.source))} با ${amount(Number(match.unit))} × ${new Intl.NumberFormat('fa-IR').format(match.count)} برابر است؛ این تطبیق محاسباتی به‌تنهایی مبنای قیمت را ثابت نمی‌کند.`, 'info'));
                });
            }
        }
        if (options.sourceVariantGap) {
            const missing = Array.isArray(options.sourceVariantGap.missingLabels)
                ? options.sourceVariantGap.missingLabels.join('، ') : '';
            card.appendChild(notice(`متن منبع ${options.sourceVariantGap.sourceCount} طعم یا نوع را مشخص می‌کند، اما فقط ${options.sourceVariantGap.extractedCount} مورد در صف استخراج دیده می‌شود${missing ? `؛ موارد جاافتاده یا ناهماهنگ: ${missing}` : ''}؛ پیش از تأیید گروه، استخراج این پیام باید کامل شود.`, 'warning'));
        }
        if (options.sourceStockGap) {
            card.appendChild(notice('پست مبدأ تاریخی صریحاً اتمام موجودی را ذکر می‌کند، اما وضعیت استخراج‌شده نامشخص است؛ وضعیت امروز را در منبع فعلی بررسی کنید.', 'warning'));
        }
        const referenceAvailable = relatedItems.every(row => {
            const hasSaved = row.reference_assignment && row.reference_assignment.choice === 'match'
                || row.reference_match_id;
            const hasNamedCandidate = Array.isArray(row.reference_candidates)
                && row.reference_candidates.some(candidate => candidate && candidate.reference_id
                    && String(candidate.name || '').trim());
            return hasSaved || (row.reference_snapshot_id && hasNamedCandidate);
        });
        const held = relatedItems.some(row => row.publication_held);
        const missingPrice = !visibleSupplierPrice;
        const blockedBySourceGap = sourcePriceNeedsReview || sourceVariantNeedsReview;
        const sourceVerificationRequired = relatedItems.some(row => row
            && (row.historical_reextract || row.source_current_check_required)) || sourceStockNeedsReview;
        if (!options.embedded || held || missingPrice || blockedBySourceGap || !referenceAvailable || sourceVerificationRequired) {
            let nextText = 'قیمت، تعداد و موجودی را با پیام اصلی بسنجید و سپس مشخصات را ثبت کنید.';
            let nextLink = null;
            if (held) {
                nextText = 'ابتدا توقف همین دسته را بررسی و رفع کنید؛ این تصمیم تا آن زمان ثبت نمی‌شود.';
                nextLink = options.holdTarget
                    ? ownerWorkflowAnchor(options.holdTarget, 'باز کردن پیام‌ها و اقدام دسته')
                    : ownerWorkflowLink('molavi-publication-holds', 'باز کردن دسته‌های متوقف‌شده');
            } else if (blockedBySourceGap) {
                nextText = 'اطلاعات استخراج‌شده با پیام کامل تطبیق ندارد؛ پست‌های اخیر را دوباره بازبینی و بازپردازش کنید. دکمهٔ تأیید تا رفع اختلاف قفل است.';
                nextLink = ownerWorkflowLink('molavi-ai-confirmations', 'رفتن به ابزار بازپردازش پست‌های اخیر', 'molavi-reextract-panel');
            } else if (missingPrice) {
                nextText = 'قیمت فروش تأمین‌کننده در داده پیدا نشده؛ مبلغ را حدس نزنید و کالا را تا ثبت قیمت معتبر باز بگذارید.';
                nextLink = ownerWorkflowLink('molavi-ai-confirmations', 'بازبینی و بازپردازش پست‌های اخیر', 'molavi-reextract-panel');
            } else if (sourceVerificationRequired) {
                nextText = sourceStockNeedsReview && !item.historical_reextract
                    ? 'پیام قدیمی اتمام موجودی را می‌گوید؛ وضعیت امروز را از منبع فعلی بررسی کنید، یا نامشخص بگذارید.'
                    : 'زمان منبع به آستانهٔ ۱۴ روز رسیده یا از آن گذشته؛ قیمت امروز را از منبع فعلی بررسی کنید.';
            } else if (!referenceAvailable) {
                nextText = 'مرجع نام‌دار و قابل انتخابی برای این مورد نیست؛ فهرست مرجع را به‌روزرسانی کنید یا مورد را باز نگه دارید.';
                nextLink = ownerWorkflowLink('molavi-reference-snapshots', 'بازبینی فهرست مرجع کالاها');
            } else if (relatedItems.some(row => !(row.reference_assignment && row.reference_assignment.choice === 'match')
                && !row.reference_match_id)) {
                nextText = 'ابتدا مرجع کالا و نقش قیمت واحد/بسته را ثبت کنید؛ سپس مبلغ و موجودی را از روی پیام تأیید کنید.';
            }
            card.appendChild(reviewNextStep(nextText, nextLink));
        }
        if (item.original_message) {
            const details = document.createElement('details'); details.appendChild(node('summary', 'متن اصلی پیام تأمین‌کننده'));
            details.appendChild(node('pre', item.original_message)); card.appendChild(details);
        }
        if (item.decision) {
            const choiceLabels = {confirm:'تأیید کالا',not_product:'کالا نیست'};
            const note = readableOwnerNote(item.decision.note);
            card.appendChild(node('p', `آخرین تصمیم: ${choiceLabels[item.decision.choice] || item.decision.choice || ''}${note ? ` · ${note}` : ''}`));
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
                const note = readableOwnerNote(decision.note);
                list.appendChild(node('li', `${stamp} · ${choice}${note ? ` · ${note}` : ''}`));
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
        let manualConfirmButton = null;
        const sourceVerificationItems = relatedItems.filter(row => row
            && (row.historical_reextract || row.source_current_check_required || sourceStockNeedsReview));
        if (sourceVerificationRequired) {
            const checkLabel = node('label', null, 'molavi-reextract-current-check');
            currentPriceVerified = document.createElement('input');
            currentPriceVerified.type = 'checkbox';
            currentPriceVerified.checked = sourceVerificationItems.length > 0
                && sourceVerificationItems.every(row => verifiedCurrentPriceCases.has(currentPriceVerificationKey(row)));
            const stockNeedsCurrentCheck = sourceStockNeedsReview || relatedItems.some(row =>
                !['','Unknown','unknown'].includes(String(row && row.stock_status || '')));
            checkLabel.append(currentPriceVerified, document.createTextNode(stockNeedsCurrentCheck
                ? 'قیمت و وضعیت موجودی را در منبع فعلی بررسی کردم؛ اگر موجودی ذکر نشده، نامشخص می‌ماند.'
                : 'قیمت را در پیام جدیدتر یا مستقیماً از تأمین‌کننده بررسی کردم؛ اگر موجودی ذکر نشده، نامشخص می‌ماند.'));
            card.appendChild(checkLabel);
            card.appendChild(notice('پس از بررسی منبع فعلی، گزینهٔ بالا را علامت بزنید تا ثبت نهایی فعال شود. مرجع، قیمت و موجودی در رسید همان تصمیم ذخیره می‌شوند.', 'info'));
        }
        const refreshConfirmationGates = () => {
            quickButtons.forEach(button => {
                if (button.dataset.choice === 'not_product') {
                    button.disabled = !contextStored || !decisionsEnabled;
                    return;
                }
                button.disabled = !contextStored || !decisionsEnabled
                    || !aiDecisionFields(item, button.dataset.priceRole)
                    || Boolean(sourceVerificationRequired && (!currentPriceVerified || !currentPriceVerified.checked))
                    || sourcePriceNeedsReview || sourceVariantNeedsReview;
            });
            if (manualConfirmButton) {
                manualConfirmButton.disabled = !contextStored || !decisionsEnabled
                    || Boolean(sourceVerificationRequired && (!currentPriceVerified || !currentPriceVerified.checked))
                    || sourcePriceNeedsReview || sourceVariantNeedsReview;
                manualConfirmButton.title = sourceVerificationRequired && (!currentPriceVerified || !currentPriceVerified.checked)
                    ? 'برای ثبت نهایی، قیمت فعلی را در منبع بررسی و گزینهٔ بررسی قیمت بالاتر را علامت بزنید.'
                    : '';
            }
        };
        if (currentPriceVerified) currentPriceVerified.addEventListener('change', () => {
            sourceVerificationItems.forEach(row => {
                const key = currentPriceVerificationKey(row);
                if (currentPriceVerified.checked) verifiedCurrentPriceCases.add(key);
                else verifiedCurrentPriceCases.delete(key);
            });
            refreshConfirmationGates();
        });
        const addQuickConfirm = (selectedRole, label) => {
            const selectedFields = aiDecisionFields(item, selectedRole);
            const button = node('button', label, 'button button-primary');
            button.type = 'button';
            button.dataset.priceRole = selectedRole;
            button.disabled = !contextStored || !decisionsEnabled || !selectedFields
                || Boolean(sourceVerificationRequired && (!currentPriceVerified || !currentPriceVerified.checked))
                || sourcePriceNeedsReview || sourceVariantNeedsReview;
            button.addEventListener('click', () => submitAiCaseDecisions(relatedItems, 'confirm', selectedFields,
                card, quickButtons, !sourceVerificationRequired || currentPriceVerified.checked, options));
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
            const packageFields = quickRole === 'unit' ? aiDecisionFields(item, 'package') : null;
            const packageUnitPrice = packageFields && packageFields.package_count
                ? Math.round(packageFields.sale_price / packageFields.package_count) : 0;
            if (packageFields && packageFields.package_count > 1 && regularPrice > 0
                && visibleSupplierPrice > regularPrice && packageUnitPrice > 0
                && packageUnitPrice < regularPrice) {
                quickActions.appendChild(node('p',
                    `اگر ${amount(packageFields.sale_price)} قیمت کل بستهٔ ${new Intl.NumberFormat('fa-IR').format(packageFields.package_count)}‌تایی باشد، معادل ${amount(packageUnitPrice)} برای هر واحد است؛ مبنا را در پیام جدیدتر تأمین‌کننده بررسی کنید.`,
                    'molavi-ai-price-basis-hint'));
                addQuickConfirm('package',
                    `تأیید مبلغ به‌عنوان قیمت کل بستهٔ ${new Intl.NumberFormat('fa-IR').format(packageFields.package_count)}‌تایی`);
            }
        } else {
            addQuickConfirm('unit', `تأیید ${relatedItems.length > 1 ? 'همه انواع' : 'کالا'} به‌عنوان قیمت هر واحد`);
            const packageButton = addQuickConfirm('package', `تأیید ${relatedItems.length > 1 ? 'همه انواع' : 'کالا'} به‌عنوان قیمت بسته`);
            if (!aiDecisionFields(item, 'package')) {
                packageButton.disabled = true;
                packageButton.title = 'تعداد داخل بسته در داده استخراج‌شده موجود نیست.';
            }
        }
        const notProduct = node('button', relatedItems.length > 1
            ? `ردّ هر ${relatedItems.length} نوع؛ کالا نیست` : 'این مورد کالا نیست', 'button');
        notProduct.type = 'button';
        notProduct.dataset.choice = 'not_product';
        notProduct.disabled = !contextStored || !decisionsEnabled;
        notProduct.addEventListener('click', () => submitAiCaseDecisions(relatedItems, 'not_product', {}, card,
            quickButtons, false, options));
        quickActions.appendChild(notProduct);
        quickButtons.push(notProduct);
        refreshConfirmationGates();
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
        manualConfirmButton = confirm;
        refreshConfirmationGates();
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
            if (sourceVerificationRequired && (!currentPriceVerified || !currentPriceVerified.checked)) {
                errorBox(sourceStockNeedsReview
                    ? 'برای این منبع قدیمی، قیمت و وضعیت موجودی را بررسی کنید و گزینهٔ تأیید منبع را علامت بزنید.'
                    : 'زمان منبع به آستانهٔ ۱۴ روز رسیده یا ثبت نشده است؛ قیمت را از منبع فعلی بررسی و گزینهٔ بررسی را علامت بزنید.', card);
                return;
            }
            if (options.sourceVariantGap || (options.sourcePriceGap && options.sourcePriceGap.missing
                && options.sourcePriceGap.missing.length)) {
                errorBox('تعداد طعم‌ها یا مبلغ‌های پیام با موارد استخراج‌شده برابر نیست؛ ابتدا همان پیام را دوباره استخراج کنید.', card);
                return;
            }
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, signature: item.signature,
                choice: 'confirm', note: aiDecisionNote, expected_decision_id: item.previous_decision_id || null,
                source_current_verified: Boolean(sourceVerificationRequired && currentPriceVerified.checked),
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
                [confirm, ...quickButtons], payload.source_current_verified, options);
        });
        return card;
    }

    function reextractionUrl(action, runId = '') {
        const params = new URLSearchParams({ reextract: action });
        if (runId) params.set('run_id', runId);
        const url = config.workflowApis && config.workflowApis.ai_confirmations
            && config.workflowApis.ai_confirmations.url || config.apiUrl;
        return url + '?' + params.toString();
    }

    function reextractionRequest(method = 'GET', payload = undefined, url = '') {
        const apiKey = config.workflowApis && config.workflowApis.ai_confirmations
            ? 'ai_confirmations' : '';
        return request(method,payload,url || reextractionUrl('preview'),apiKey);
    }

    async function showReextractionProgress(panel, runId, auditContext = {}) {
        if (!root.contains(panel)) return;
        try {
            const status = await reextractionRequest('GET', undefined, reextractionUrl('run', runId));
            const counts = status.counts || {};
            const finished = Number(status.completed || 0);
            const total = Number(status.total || 0);
            const box = notice(`بازاستخراج پیام‌ها: ${finished} از ${total} · در صف ${counts.queued || 0} · در حال اجرا ${counts.running || 0} · پایان‌یافته ${counts.done || 0} · لغوشده ${counts.cancelled || 0} · ناموفق ${counts.failed || 0}`, status.state === 'complete' ? (counts.failed ? 'warning' : 'success') : 'info');
            panel.querySelector('.molavi-reextract-progress')?.replaceWith(box);
            box.classList.add('molavi-reextract-progress');
            if (status.state === 'complete') {
                const outcomes = status.outcomes || {};
                box.appendChild(node('p', `کالاهای استخراج‌شده: ${status.products_found || 0} · ردیف‌های ثبت‌شده: ${status.offers_written || 0} · موارد باقی‌مانده برای بررسی: ${status.open_cases_remaining || 0} · پیام بدون نتیجه: ${outcomes.no_products || 0}`));
                if (String(auditContext.scope || '') === 'recent_policy') {
                    box.appendChild(node('p', `نسخه قواعد اجراشده: ${status.policy_version || auditContext.policy_version || 'نامشخص'}`));
                    if (outcomes.policy_changed) box.appendChild(notice('قواعد استخراج هنگام شروع کار تغییر کرده بود؛ این پیام‌ها بدون ثبت تغییر محصول متوقف شدند. صف را تازه کنید و نسخه جدید را اجرا کنید.','warning'));
                }
                const mediaOcrRun=Boolean(auditContext && auditContext.media_review_key);
                if (mediaOcrRun) {
                    const labels={reprocessed:'متن تصویر استخراج شد؛ پیشنهادها در بررسی کالا نمایش داده می‌شوند.',
                        ocr_empty:'متن خوانایی از تصویر پیدا نشد؛ پیام برای بررسی دستی باز می‌ماند.',
                        ocr_no_products:'متن خوانده شد اما پیشنهاد کالایی پیدا نشد؛ پیام برای بررسی دستی باز می‌ماند.',
                        media_unavailable:'تصویر از تلگرام در دسترس نیست؛ پیام برای بررسی دستی باز می‌ماند.',
                        media_unsupported:'نوع این رسانه برای OCR پشتیبانی نمی‌شود؛ پیام برای بررسی باز می‌ماند.',
                        no_text:'متن و تصویر قابل بازخوانی در پیام نیست؛ پیام برای بررسی باز می‌ماند.'};
                    Object.entries(outcomes).forEach(([outcome,count])=>{
                        if (labels[outcome] && count) box.appendChild(notice(labels[outcome],'warning'));
                    });
                    if (status.offers_written) box.appendChild(notice('هیچ قیمت یا کالایی با OCR تأیید نهایی نمی‌شود؛ عکس، متن خوانده‌شده و همه قیمت‌ها را در کارت استخراج با هم بررسی کنید.','warning'));
                }
                const publicationState = String((status.catalog_publication || {}).state || '');
                if (status.offers_written > 0) {
                    const publicationLabel = publicationState === 'done' ? 'همگام‌سازی با وردپرس کامل شد.'
                        : publicationState === 'waiting_publication' ? 'همگام‌سازی وردپرس پشت توقف انتشار منتظر بررسی است.'
                        : publicationState === 'failed' ? 'همگام‌سازی وردپرس ناموفق ماند؛ خطای صف انتشار را بررسی کنید.'
                        : publicationState ? 'همگام‌سازی تغییرها با وردپرس در صف است.'
                        : 'تغییر محصولی برای همگام‌سازی با وردپرس ثبت نشد.';
                    box.appendChild(node('p', publicationLabel, publicationState === 'done' ? '' : 'notice'));
                }
                if (outcomes.source_changed) box.appendChild(node('p', `${outcomes.source_changed} پیام پس از پیش‌نمایش تغییر کرده یا چند نسخه مبهم داشته؛ نتیجه‌ای برای آن‌ها ثبت نشد. پیش‌نمایش را تازه کنید و دوباره اجرا کنید.`, 'notice'));
                if (counts.cancelled) box.appendChild(node('p', 'کارهای در صف لغو شدند؛ پیامِ در حال اجرا ممکن است کامل شده باشد.'));
                box.appendChild(node('p', 'نتیجه‌های روشن و تازه با کنترل کیفیت ثبت شدند؛ موردهای نامطمئن و قیمت‌های قدیمی در همین فهرست برای بررسی می‌مانند.'));
                if (counts.failed) box.appendChild(node('p', 'پیام‌های ناموفق را پس از بررسی صف می‌توانید با اجرای دوباره گروه مربوط پردازش کنید.'));
                if (auditContext && (auditContext.run_id || auditContext.media_review_key)
                        && !panel.dataset.reextractAuditSaved) {
                    panel.dataset.reextractAuditSaved = '1';
                    try {
                        const mediaRun=Boolean(auditContext.media_review_key);
                        const reviewType=mediaRun?'ai_media_review':'ai_reextraction';
                        const reviewId=mediaRun?auditContext.media_review_key:runId;
                        const eventId=mediaRun?'media-ocr:'+runId:'reextraction-run:'+runId;
                        const policyRun=String(auditContext.scope || '')==='recent_policy';
                        const decision=mediaRun?{action:'retry_media_ocr',run_id:runId}
                            :policyRun?{action:'reprocess_recent_posts_with_current_policy',
                                policy_version:String(auditContext.policy_version || '')}
                                :{action:'reprocess_open_extraction_cases'};
                        await persistOwnerReviews([reviewRecord(eventId, reviewType, reviewId,
                            counts.failed ? 'failed' : 'accepted',
                            storedReviewContext(reviewType,auditContext),decision, {
                                state:status.state, total, completed:finished, outcomes,
                                products_found:Number(status.products_found || 0),
                                offers_written:Number(status.offers_written || 0),
                                open_cases_remaining:Number(status.open_cases_remaining || 0)
                            })]);
                    } catch (_error) {
                        box.appendChild(notice('نتیجه پردازش انجام شد اما ثبت گزارش آن در پایگاه داده وردپرس ناموفق بود.', 'error'));
                    }
                }
                const refreshWorkflow = config.page === 'molavi-operations' ? dashboard : aiConfirmations;
                setTimeout(() => refreshWorkflow(counts.failed
                    ? 'بازاستخراج تمام شد؛ بخشی از پیام‌ها ناموفق ماند.'
                    : 'بازاستخراج تمام شد؛ موردهای نامطمئن باقی‌مانده برای بررسی نمایش داده می‌شوند.',
                    counts.failed ? 'warning' : 'success'), 1200);
                return;
            }
            const cancel = node('button', 'توقف پیام‌های باقی‌مانده', 'button');
            cancel.type = 'button';
            cancel.addEventListener('click', async () => {
                if (!window.confirm('پیام‌های در صف لغو می‌شوند؛ پیام در حال اجرا ممکن است کامل شود. ادامه می‌دهید؟')) return;
                cancel.disabled = true;
                try {
                    const receipt=await reextractionRequest('POST',{action:'cancel_reextraction',run_id:runId});
                    box.appendChild(node('p', `لغو شد: ${receipt.cancelled || 0} پیام در صف · ${receipt.running || 0} پیام در حال اجرا`));
                } catch (error) {
                    box.appendChild(notice(errorText(error.message),'error'));
                    cancel.disabled=false;
                }
            });
            box.appendChild(cancel);
            setTimeout(() => showReextractionProgress(panel, runId, auditContext), 3500);
        } catch (error) {
            panel.querySelector('.molavi-reextract-progress')?.replaceWith(notice(errorText(error.message), 'error'));
        }
    }

    async function advancedReextractionTools(panel) {
        panel.className = 'card molavi-reextract-panel';
        panel.appendChild(node('h2', 'ابزارهای ویژهٔ استخراج'));
        panel.appendChild(node('p', 'آلبوم‌ها در انتخاب عادی با OCR همهٔ عکس‌هایشان با هم پردازش می‌شوند. این کارت‌ها مسیر بررسی جداگانهٔ رسانه‌ها هستند؛ بازپردازش با سیاست فعلی هم جداست.'));
        try {
            const preview = await reextractionRequest('GET', undefined, reextractionUrl('preview'));
            const counts = preview.counts || {};
            const openCases = preview.open_cases || {};
            const recentPolicy = preview.recent_policy || {};
            const supportsAiOnly = typeof preview.ai_configured === 'boolean';
            const aiConfigured = supportsAiOnly && preview.ai_configured;
            panel.appendChild(node('p', `در صف بازبینی مالک: ${counts.pending || 0} پست · همهٔ پست‌های قابل‌بازاستخراج: ${counts.all_available || 0}`));
            panel.appendChild(node('p', `کارت‌های باز استخراج: ${openCases.case_count || 0} · پیام‌های اصلی یکتا: ${openCases.message_count || 0} · قبلاً بازاستخراج‌شده: ${counts.already_reprocessed || 0}`));
            panel.appendChild(node('p', `بازپردازش با قواعد فعلی: ${recentPolicy.eligible_count || 0} پست در ${recentPolicy.window_days || 14} روز اخیر · شامل ${recentPolicy.media_ocr_count || 0} پست تصویری و ${recentPolicy.media_ocr_image_count || 0} تصویر برای OCR؛ خروجی فقط به بررسی مالک می‌رود · نسخه استخراج ${recentPolicy.policy_version || 'نامشخص'}. تصمیم‌های مالک و نتیجه‌های همین نسخه حفظ می‌شوند.`));
            panel.appendChild(node('p', `گروه‌های رسانه‌ای نیازمند OCR: ${counts.media_review || 0} · تصویر بدون فایل قابل‌خواندن: ${counts.media_without_file || 0} · رسانهٔ پشتیبانی‌نشده برای OCR: ${counts.unsupported_media || 0} · خروجی بدون نامزد محصول: ${counts.no_product_candidates || 0} · تصمیم‌های مالک حفظ‌شده: ${counts.owner_decisions_preserved || 0} · ردشده توسط مالک: ${counts.dismissed_by_owner || 0} · قبلاً در صف بررسی بازاستخراج‌شده: ${counts.already_staged || 0}`));
            const policyPosts = Array.isArray(recentPolicy.source_posts) ? recentPolicy.source_posts : [];
            if (policyPosts.length) {
                const sourceList = document.createElement('details');
                sourceList.appendChild(node('summary', `نمونه پست‌های آماده (${policyPosts.length} از ${recentPolicy.eligible_count || policyPosts.length})`));
                for (const item of policyPosts) {
                    const row = node('p', `${item.channel || 'منبع نامشخص'} · پیام ${item.message_id || '—'} · ${item.timestamp || 'زمان نامشخص'}${item.media_ocr ? ` · OCR ${item.image_count || 1} تصویر` : ''}`);
                    const link = safeSourceLink(item.source_post_url);
                    if (link) row.appendChild(link);
                    sourceList.appendChild(row);
                }
                panel.appendChild(sourceList);
            }
            if (Number(counts.unsupported_media || 0) > 0) {
                panel.appendChild(notice('رسانه‌های غیرعکسی مثل استیکر در صف OCR قرار نمی‌گیرند و خودکار هم رد نمی‌شوند. اگر یکی از آن‌ها آگهی محصول است، پست را با متن یا عکس قابل‌خواندن دوباره بفرستید.', 'info'));
            }
            const mediaItems = Array.isArray(preview.media_review_messages) ? preview.media_review_messages : [];
            if (mediaItems.length) {
                const mediaSection = node('section', null, 'molavi-media-review');
                let mediaTotal = Number(preview.media_review_total || counts.media_review || mediaItems.length);
                let mediaImageTotal = Number(counts.media_review_images || 0);
                const missingImageTotal = Number(counts.media_review_missing_images || 0);
                const mediaHeading = node('h3', `گروه‌های رسانه‌ای نیازمند OCR (${mediaTotal} گروه/پیام · ${mediaImageTotal} تصویر)`);
                mediaSection.appendChild(mediaHeading);
                mediaSection.appendChild(node('p', `هر گروه آلبوم یک‌جا بررسی می‌شود و OCR همه عکس‌های آن را می‌خواند. نتیجه فقط برای بازبینی مالک ثبت می‌شود. ${missingImageTotal ? `${missingImageTotal} پیام آلبوم فایل تصویر کامل ندارد و تا دریافت همه عکس‌ها OCR نمی‌شود. ` : ''}اگر گروهی کالا نیست، تصمیم آن کل همان گروه را کنار می‌گذارد.`));
                const mediaGrid = node('div', null, 'molavi-media-review-grid');
                function renderMedia(items) {
                    for (const item of items) {
                        const imageFileIds = Array.isArray(item.image_file_ids) && item.image_file_ids.length
                            ? item.image_file_ids.map(value => String(value || '').trim()).filter(Boolean)
                            : (item.image_file_id ? [String(item.image_file_id)] : []);
                        const imageCount = Number(item.image_count || imageFileIds.length || 0);
                        const missingImageCount = Number(item.missing_image_count || 0);
                        const messageCount = Array.isArray(item.message_ids) && item.message_ids.length
                            ? item.message_ids.length : 1;
                        const isAlbum = Boolean(item.grouped || item.media_group_id || imageCount > 1);
                        const card = node('article', null, 'molavi-media-review-card');
                        const details = node('div', null, 'molavi-media-review-details');
                        details.appendChild(node('strong', `${item.channel || item.channel_username || 'منبع نامشخص'} · ${isAlbum ? `آلبوم ${imageCount || imageFileIds.length} تصویر` : `پیام ${item.message_id || '—'}`}`));
                        details.appendChild(node('span', `${item.timestamp || 'زمان ثبت نشده'} · ${messageCount} پیام · ${imageCount || imageFileIds.length} تصویر`));
                        const sourceLink = safeSourceLink(item.source_post_url);
                        if (sourceLink) details.appendChild(sourceLink);
                        if (item.content) {
                            const caption = document.createElement('details');
                            caption.appendChild(node('summary','متن همراه پیام'));
                            caption.appendChild(node('pre',String(item.content).slice(0,3000)));
                            details.appendChild(caption);
                        }
                        if (imageFileIds.length) {
                            const imageList = node('div', null, 'molavi-media-review-images');
                            imageFileIds.forEach((fileId,index) => {
                                const image = node('img');
                                image.src = new URL('/media/file/' + encodeURIComponent(fileId), config.apiUrl).toString();
                                image.alt = `تصویر ${index + 1} از ${imageFileIds.length} پیام تأمین‌کننده`;
                                image.loading = 'lazy'; image.decoding = 'async';
                                image.referrerPolicy = 'no-referrer';
                                image.onerror = () => {
                                    image.remove();
                                    imageList.insertBefore(notice(`تصویر ${index + 1} از منبع در دسترس نیست؛ اگر پست باز می‌شود، آن را در همان منبع بررسی کنید.`, 'warning'),imageList.firstChild);
                                };
                                imageList.appendChild(image);
                            });
                            card.appendChild(imageList);
                        } else {
                            card.appendChild(notice('برای این پیام فایل پیش‌نمایش ذخیره نشده است.','warning'));
                        }
                        if (missingImageCount) {
                            card.appendChild(notice(`${missingImageCount} پیام از آلبوم فایل تصویر ندارد؛ OCR متوقف است تا همهٔ عکس‌های منبع در دسترس باشند.`, 'warning'));
                        }
                        if (imageFileIds.length) {
                            const ocr = node('button',imageFileIds.length > 1
                                ? 'بازخوانی OCR همه تصاویر و استخراج' : 'بازخوانی OCR تصویر و استخراج','button button-primary');
                            ocr.type='button'; ocr.disabled=!aiConfigured || missingImageCount > 0;
                            ocr.addEventListener('click',async()=>{
                                const confirmation = `متن قابل‌مشاهده ${imageFileIds.length} تصویر این ${isAlbum ? 'آلبوم' : 'پیام'} خوانده می‌شود. پیشنهادهای حاصل فقط برای بررسی مالک نمایش داده می‌شوند و هیچ کالا یا قیمتی خودکار تأیید نمی‌شود. ادامه می‌دهید؟`;
                                if (!window.confirm(confirmation)) return;
                                const bytes=new Uint8Array(16);
                                if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
                                else for (let index=0;index<bytes.length;index++) bytes[index]=Math.floor(Math.random()*256);
                                const runId=Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');
                                const auditContext={media_review_key:item.message_key,run_id:runId,
                                    message_id:item.message_id,batch_id:item.batch_id,
                                    channel:item.channel || item.channel_username,timestamp:item.timestamp,
                                    source_post_url:item.source_post_url,media_type:item.media_type,
                                    image_file_unique_id:item.image_file_unique_id,
                                    image_count:imageFileIds.length,message_count:messageCount,
                                    grouped:isAlbum};
                                const eventId='media-ocr:'+runId;
                                const decision={action:'retry_media_ocr',run_id:runId};
                                ocr.disabled=true;
                                try {
                                    await persistOwnerReviews([reviewRecord(eventId,'ai_media_review',
                                        item.message_key,'pending',storedReviewContext('ai_media_review',auditContext),decision,{})]);
                                    const receipt=await reextractionRequest('POST',{action:'start_media_reextraction',
                                        message_key:item.message_key,run_id:runId});
                                    if (receipt.run_id!==runId || receipt.queued!==1
                                        || receipt.message_key!==item.message_key
                                        || receipt.image_count!==imageFileIds.length
                                        || receipt.message_count!==messageCount) throw new Error('invalid_receipt');
                                    await persistOwnerReviews([reviewRecord(eventId,'ai_media_review',
                                        item.message_key,'accepted',storedReviewContext('ai_media_review',auditContext),decision,receipt)]);
                                    const progress=notice(`OCR برای ${imageFileIds.length} تصویر در صف پردازش قرار گرفت…`,'info');
                                    progress.classList.add('molavi-reextract-progress');
                                    mediaSection.appendChild(progress);
                                    showReextractionProgress(panel,runId,auditContext);
                                } catch (error) {
                                    try { await persistOwnerReviews([reviewRecord(eventId,'ai_media_review',
                                        item.message_key,error.remoteRejected?'failed':'pending',
                                        storedReviewContext('ai_media_review',auditContext),decision,
                                        {error:String(error.message || 'request_failed')})]); } catch (_auditError) { }
                                    ocr.disabled=false;
                                    card.appendChild(notice(errorText(error.message),'error'));
                                }
                            });
                            details.appendChild(ocr);
                        }
                        const dismiss = node('button', isAlbum ? 'این آلبوم کالا نیست' : 'این پیام کالا نیست', 'button button-secondary');
                        dismiss.type = 'button';
                        dismiss.addEventListener('click', async () => {
                            if (!window.confirm(`این تصمیم ${isAlbum ? 'همه پیام‌ها و تصاویر همین آلبوم' : 'همین پیام و تصویر'} را از صف بررسی کنار می‌گذارد. ادامه می‌دهید؟`)) return;
                            dismiss.disabled = true;
                            const eventId = `media-review:${item.message_key}`;
                            const context = {message_id:item.message_id,batch_id:item.batch_id,
                                channel:item.channel || item.channel_username,timestamp:item.timestamp,
                                source_post_url:item.source_post_url,media_type:item.media_type,
                                image_file_unique_id:item.image_file_unique_id,
                                image_count:imageFileIds.length,message_count:messageCount,
                                grouped:isAlbum};
                            const decision = {choice:'not_product'};
                            try {
                                await persistOwnerReviews([reviewRecord(eventId,'ai_media_review',item.message_key,
                                    'pending',context,decision,{})]);
                                const receipt = await reextractionRequest('POST',{action:'dismiss_media_message',message_key:item.message_key});
                                if (receipt.decision_id !== item.message_key || receipt.choice !== 'not_product') throw new Error('invalid_receipt');
                                await persistOwnerReviews([reviewRecord(eventId,'ai_media_review',item.message_key,
                                    'accepted',context,decision,receipt)]);
                                card.remove();
                                mediaTotal=Math.max(0,mediaTotal-1);
                                mediaImageTotal=Math.max(0,mediaImageTotal-imageFileIds.length);
                                mediaHeading.textContent=`گروه‌های رسانه‌ای نیازمند OCR (${mediaTotal} گروه/پیام · ${mediaImageTotal} تصویر)`;
                                mediaSection.insertBefore(notice('تصمیم ثبت شد؛ سابقه آن در وردپرس ذخیره شد.','success'),mediaGrid);
                            } catch (error) {
                                try { await persistOwnerReviews([reviewRecord(eventId,'ai_media_review',item.message_key,
                                    error.remoteRejected ? 'failed' : 'pending',context,decision,
                                    {error:String(error.message || 'request_failed')})]); } catch (_auditError) { }
                                dismiss.disabled = false;
                                card.appendChild(notice(errorText(error.message),'error'));
                            }
                        });
                        details.appendChild(dismiss);
                        card.appendChild(details);
                        mediaGrid.appendChild(card);
                    }
                }
                renderMedia(mediaItems);
                mediaSection.appendChild(mediaGrid);
                if (preview.media_review_next_after !== null && preview.media_review_next_after !== undefined) {
                    const more = node('button', 'نمایش ۱۲ رسانه دیگر', 'button');
                    more.type='button';
                    more.addEventListener('click', async () => {
                        more.disabled=true;
                        try {
                            const nextUrl = reextractionUrl('preview') + '&media_after='
                                + encodeURIComponent(String(preview.media_review_next_after));
                            const nextPage = await reextractionRequest('GET',undefined,nextUrl);
                            renderMedia(Array.isArray(nextPage.media_review_messages)
                                ? nextPage.media_review_messages : []);
                            preview.media_review_next_after = nextPage.media_review_next_after;
                            preview.media_review_after = nextPage.media_review_after;
                            if (nextPage.media_review_next_after === null
                                || nextPage.media_review_next_after === undefined) more.remove();
                            else more.disabled=false;
                        } catch (error) {
                            more.disabled=false;
                            mediaSection.appendChild(notice(errorText(error.message),'error'));
                        }
                    });
                    mediaSection.appendChild(more);
                }
                panel.appendChild(mediaSection);
            }
            if (openCases.unmatched_case_count > 0) {
                panel.appendChild(notice(`${openCases.unmatched_case_count} کارت به متن پیام اصلی وصل نیست و در بازاستخراج گروهی وارد نمی‌شود.`, 'warning'));
            }
            if (!supportsAiOnly) {
                panel.appendChild(notice('نسخه سرویس سرور هنوز از استخراج فقط با هوش مصنوعی پشتیبانی نمی‌کند؛ ابتدا سرور را به‌روزرسانی کنید.', 'error'));
            } else if (!aiConfigured) {
                panel.appendChild(notice('استخراج کالا فقط با هوش مصنوعی انجام می‌شود. کلید GOOGLE_API_KEY در تنظیمات سرویس اجرا تنظیم نشده است.', 'error'));
            }
            if (preview.active_run) {
                const progress = notice('در حال آماده‌سازی وضعیت بازاستخراج…', 'info');
                progress.classList.add('molavi-reextract-progress');
                panel.appendChild(progress);
                const activeAudit = ['open_cases','recent_policy'].includes(preview.active_run.scope) ? {
                    run_id:preview.active_run.run_id, scope:preview.active_run.scope,
                    case_count:Number(preview.active_run.case_count || 0),
                    message_count:Number(preview.active_run.total || 0),
                    digest:String(preview.active_run.digest || ''),
                    policy_version:String(preview.active_run.policy_version || ''),
                    window_days:Number(preview.recent_policy && preview.recent_policy.window_days || 0)
                } : {};
                showReextractionProgress(panel, preview.active_run.run_id, activeAudit);
                return;
            }
            const controls = node('div', null, 'molavi-reextract-actions');
            const recentPolicyButton = node('button', `بازپردازش و به‌روزرسانی پست‌های اخیر (${recentPolicy.eligible_count || 0})`, 'button button-primary');
            const openCasesButton = node('button', `بازاستخراج همه کارت‌های باز (${openCases.case_count || 0} کارت · ${openCases.message_count || 0} پیام)`, 'button button-primary');
            const dismissButton = node('button', `ردّ گروهی پیام‌های منتظر (${counts.pending || 0})`, 'button');
            recentPolicyButton.type = 'button'; openCasesButton.type = 'button'; dismissButton.type = 'button';
            recentPolicyButton.disabled = !aiConfigured || !(recentPolicy.eligible_count > 0)
                || !/^[a-z0-9][a-z0-9._-]{0,63}$/.test(recentPolicy.policy_version || '')
                || !/^[a-f0-9]{64}$/.test(recentPolicy.digest || '');
            openCasesButton.disabled = !aiConfigured || !(openCases.case_count > 0)
                || Number(openCases.unmatched_case_count || 0) > 0;
            dismissButton.disabled = !supportsAiOnly || !(counts.pending > 0);
            recentPolicyButton.addEventListener('click', async () => {
                const count = Number(recentPolicy.eligible_count || 0);
                const windowDays = Number(recentPolicy.window_days || 14);
                const version = String(recentPolicy.policy_version || '');
                const digest = String(recentPolicy.digest || '');
                if (!count || !version || !digest) return;
                const warning = `${count} پست دارای زمان معتبر از ${windowDays} روز اخیر با نسخه فعلی استخراج (${version}) دوباره پردازش می‌شود. نتیجه‌های روشن به کاتالوگ همگام می‌شوند؛ اگر تعداد داخل بسته تغییر کند، گام خانوار و فروشگاه از سیاست فعلی محاسبه می‌شود و تنظیم دستی همان کالا حفظ می‌شود. خروجی نامطمئن و همه نتیجه‌های OCR برای بررسی می‌مانند. تصمیم‌های مالک و پست‌های قدیمی‌تر حفظ می‌شوند. ادامه می‌دهید؟`;
                if (!window.confirm(warning)) return;
                const bytes = new Uint8Array(16);
                if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
                else for (let index=0; index<bytes.length; index++) bytes[index]=Math.floor(Math.random()*256);
                const runId = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
                const auditContext = {run_id:runId,scope:'recent_policy',case_count:0,
                    message_count:count,digest,policy_version:version,window_days:windowDays};
                const eventId = 'reextraction-run:' + runId;
                const decision = {action:'reprocess_recent_posts_with_current_policy',
                    policy_version:version};
                recentPolicyButton.disabled = true;
                try {
                    await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,
                        'pending',auditContext,decision,{})]);
                } catch (error) {
                    panel.appendChild(notice(errorText(error.message),'error'));
                    recentPolicyButton.disabled = false;
                    return;
                }
                let receipt;
                try {
                    receipt = await reextractionRequest('POST',{action:'start_recent_policy_reextraction',
                        run_id:runId,expected_count:count,expected_digest:digest,
                        policy_version:version});
                    if (receipt.run_id!==runId || receipt.queued!==count
                        || receipt.policy_version!==version || receipt.digest!==digest) {
                        throw new Error('invalid_receipt');
                    }
                } catch (error) {
                    try { await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,
                        'failed',auditContext,decision,{error:String(error.message || 'request_failed')})]); }
                    catch (_auditError) { }
                    panel.appendChild(notice(errorText(error.message),'error'));
                    recentPolicyButton.disabled = !(recentPolicy.eligible_count > 0) || !aiConfigured;
                    return;
                }
                let auditWarning = false;
                try { await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,
                    'accepted',auditContext,decision,receipt)]); }
                catch (_error) { auditWarning = true; }
                const progress = notice('بازپردازش پست‌های اخیر در صف قرار گرفت…','info');
                progress.classList.add('molavi-reextract-progress');
                if (auditWarning) progress.appendChild(node('p','اجرا در صف است، اما ذخیره رسید اولیه در وردپرس ناموفق بود.','notice'));
                controls.replaceWith(progress);
                showReextractionProgress(panel,runId,auditContext);
            });
            openCasesButton.addEventListener('click', async () => {
                const caseCount = Number(openCases.case_count || 0);
                const messageCount = Number(openCases.message_count || 0);
                if (!caseCount || !window.confirm(`پیام اصلی ${messageCount} مورد، شامل ${caseCount} کارت باز، دوباره استخراج می‌شود. نتیجه‌های روشن از کنترل کیفیت عبور می‌کنند و موارد نامطمئن برای بررسی می‌مانند. شروع شود؟`)) return;
                const bytes = new Uint8Array(16);
                if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
                else for (let index=0; index<bytes.length; index++) bytes[index]=Math.floor(Math.random()*256);
                const runId = Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
                const auditContext = {run_id:runId, scope:'open_cases', case_count:caseCount,
                    message_count:messageCount, digest:String(openCases.digest || '')};
                const eventId = 'reextraction-run:' + runId;
                openCasesButton.disabled = true;
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'ai_reextraction', runId,
                        'pending', auditContext, {action:'reprocess_open_extraction_cases'}, {})]);
                } catch (error) {
                    panel.appendChild(notice(errorText(error.message), 'error'));
                    openCasesButton.disabled = false;
                    return;
                }
                let receipt;
                try {
                    receipt = await reextractionRequest('POST', {action:'start_open_case_reextraction',
                        run_id:runId, expected_case_count:caseCount,
                        expected_message_count:messageCount, expected_digest:auditContext.digest});
                    if (receipt.run_id !== runId || receipt.queued !== messageCount
                        || receipt.case_count !== caseCount) throw new Error('invalid_receipt');
                } catch (error) {
                    try {
                        await persistOwnerReviews([reviewRecord(eventId, 'ai_reextraction', runId,
                            'failed', auditContext, {action:'reprocess_open_extraction_cases'},
                            {error:String(error.message || 'request_failed')})]);
                    } catch (_auditError) { /* Keep the API error visible. */ }
                    panel.appendChild(notice(errorText(error.message), 'error'));
                    openCasesButton.disabled = !(openCases.case_count > 0)
                        || Number(openCases.unmatched_case_count || 0) > 0;
                    return;
                }
                let auditWarning = false;
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'ai_reextraction', runId,
                        'accepted', auditContext, {action:'reprocess_open_extraction_cases'}, receipt)]);
                } catch (_error) { auditWarning = true; }
                const progress = notice('بازاستخراج پیام‌های اصلی در صف قرار گرفت…', 'info');
                progress.classList.add('molavi-reextract-progress');
                if (auditWarning) progress.appendChild(node('p', 'اجرا در صف است، اما ثبت رسید اولیه در پایگاه داده وردپرس ناموفق بود؛ نتیجه نهایی در تلاش بعدی دوباره ذخیره می‌شود.', 'notice'));
                controls.replaceWith(progress);
                showReextractionProgress(panel, runId, auditContext);
            });
            dismissButton.addEventListener('click', async () => {
                const count = Number(counts.pending || 0);
                if (!count || !window.confirm(`قرار است همه ${count} پیامِ در صف از بازاستخراج کنار گذاشته شوند. متن اصلی پیام‌ها حذف نمی‌شود و یادداشت اجباری نیست. ادامه می‌دهید؟`)) return;
                dismissButton.disabled = true;
                try {
                    const receipt = await reextractionRequest('POST', {
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
            controls.append(recentPolicyButton, openCasesButton, dismissButton);
            if (Number(recentPolicy.eligible_count || 0) > 0) {
                panel.appendChild(notice('این دکمه پست‌های واجد شرایطِ ۱۴ روز اخیر را با نسخه فعلی استخراج دوباره پردازش می‌کند. اگر تعداد داخل بسته تغییر کند، همگام‌سازی کاتالوگ گام‌های فعلی خانوار و فروشگاه را روی محصول به‌روز می‌کند؛ تنظیم دستی همان محصول حفظ می‌شود. برای اعمال صرفِ سیاست جدید نیازی به بازپردازش نیست.', 'info'));
            }
            panel.appendChild(controls);
        } catch (error) {
            panel.appendChild(notice(errorText(error.message), 'error'));
        }
    }

    async function reextractionPanel(panel) {
        panel.className = 'card molavi-reextract-workspace';
        panel.appendChild(node('h2', 'انتخاب پیام‌ها برای بازاستخراج'));
        panel.appendChild(node('p', 'همان مدل استخراج فعلی روی پیام‌های منبع اجرا می‌شود. دامنه، کانال و بازهٔ تاریخ با هم ترکیب می‌شوند؛ نتیجهٔ تازه از کنترل کیفیت موجود عبور می‌کند.'));
        try {
            const initial = await reextractionRequest('GET', undefined, reextractionUrl('preview'));
            const counts = initial.selection_counts || {};
            const linkAvailable = initial.catalog_linking_available === true;
            const filtersCard = node('section', null, 'molavi-reextract-filters');
            const selectionCard = node('section', null, 'molavi-reextract-selection');
            const layout = node('div', null, 'molavi-reextract-layout');
            const scopeFieldset = document.createElement('fieldset');
            scopeFieldset.className = 'molavi-reextract-scope';
            scopeFieldset.appendChild(node('legend', 'دامنهٔ پیام‌ها'));
            const scopeInputs = new Map();
            const scopeOptions = [
                {key:'all',label:'همهٔ پست‌های قابل‌بازاستخراج',hint:'همهٔ متن‌ها و آلبوم‌های واجد شرایط؛ OCR همهٔ عکس‌های آلبوم در همان اجرا انجام می‌شود. کارت‌های رسانه مسیر بررسی جداگانه را هم فراهم می‌کنند.',count:counts.all || 0},
                {key:'pending',label:'پست‌های منتظر بررسی مالک',hint:'استخراج‌هایی که هنوز در صف بررسی مالک هستند؛ هر آلبوم یک پست محسوب می‌شود.',count:counts.pending || 0},
                {key:'linked',label:'پیوندشده به محصول کاتالوگ',hint:'شناسهٔ پیشنهاد منبع به یک محصول در کاتالوگ فعلی وصل است.',count:counts.linked || 0}
            ];
            scopeOptions.forEach((option,index) => {
                const label = node('label', null, 'molavi-reextract-scope-option');
                const input = document.createElement('input');
                input.type = 'radio'; input.name = 'molavi-reextract-scope'; input.value = option.key;
                input.checked = index === 0;
                input.disabled = option.key === 'linked' && !linkAvailable;
                const copy = node('span', null, 'molavi-reextract-scope-copy');
                copy.append(node('strong', option.label), node('small', option.hint));
                label.append(input, copy, node('b', Number(option.count).toLocaleString('fa-IR')));
                scopeFieldset.appendChild(label);
                scopeInputs.set(option.key,input);
            });
            filtersCard.appendChild(scopeFieldset);
            if (!linkAvailable) filtersCard.appendChild(notice('پیوند پیام به کاتالوگ در این اتصال در دسترس نیست؛ این دامنه غیرفعال است.', 'warning'));

            const dateControls = node('div', null, 'molavi-reextract-date-controls');
            const dateFromLabel = node('label', 'از تاریخ پیام', 'molavi-reextract-control');
            const dateFrom = document.createElement('input'); dateFrom.type = 'date';
            dateFromLabel.appendChild(dateFrom);
            const dateToLabel = node('label', 'تا تاریخ پیام', 'molavi-reextract-control');
            const dateTo = document.createElement('input'); dateTo.type = 'date';
            dateToLabel.appendChild(dateTo);
            dateControls.append(dateFromLabel,dateToLabel);
            filtersCard.appendChild(dateControls);

            const channelFieldset = document.createElement('fieldset');
            channelFieldset.className = 'molavi-reextract-channels';
            channelFieldset.appendChild(node('legend', 'کانال منبع · چندانتخابی'));
            const channelSearchLabel = node('label', 'جست‌وجوی کانال', 'molavi-reextract-channel-search');
            const channelSearch = document.createElement('input');
            channelSearch.type = 'search'; channelSearch.autocomplete = 'off';
            channelSearch.placeholder = 'نام یا شناسهٔ کانال';
            channelSearchLabel.appendChild(channelSearch);
            channelFieldset.appendChild(channelSearchLabel);
            const channelList = node('div', null, 'molavi-reextract-channel-list');
            const channelInputs = [];
            const channelEntries = [];
            const channels = initial.selection && Array.isArray(initial.selection.source_channels)
                ? initial.selection.source_channels : [];
            if (!channels.length) channelList.appendChild(node('p', 'کانال واجد شرایطی ثبت نشده است.', 'description'));
            channels.forEach(channel => {
                const value = String(channel.value || '').trim();
                if (!value) return;
                const label = node('label', null, 'molavi-reextract-channel');
                const input = document.createElement('input'); input.type = 'checkbox'; input.value = value;
                channelInputs.push(input);
                channelEntries.push({input,label,search:value.toLocaleLowerCase()});
                label.append(input, node('span', value), node('b', Number(channel.count || 0).toLocaleString('fa-IR')));
                channelList.appendChild(label);
            });
            channelFieldset.appendChild(channelList);
            filtersCard.appendChild(channelFieldset);
            const channelLimit = node('p', 'در هر انتخاب می‌توان حداکثر ۳۰ کانال را ترکیب کرد.', 'description');
            filtersCard.appendChild(channelLimit);
            const clearFilters = node('button', 'پاک‌کردن کانال و تاریخ', 'button button-secondary');
            clearFilters.type = 'button';
            filtersCard.appendChild(clearFilters);

            const previewHeading = node('p', 'پیش‌نمایش انتخاب', 'molavi-reextract-eyebrow');
            const selectedCount = node('strong', '۰', 'molavi-reextract-count');
            const selectedLabel = node('span', 'پست واجد شرایط');
            const countLine = node('div', null, 'molavi-reextract-count-line');
            countLine.append(selectedCount, selectedLabel);
            const filterSummary = node('p', '', 'molavi-reextract-filter-summary');
            const groupControls = node('label', 'گروه‌بندی نتیجه', 'molavi-reextract-group-control');
            const groupBy = document.createElement('select');
            groupBy.append(new Option('کانال منبع','channel'),new Option('روز پیام','date'));
            groupControls.appendChild(groupBy);
            const groupsList = document.createElement('ul'); groupsList.className = 'molavi-reextract-groups';
            const previewStatus = node('p', '', 'molavi-reextract-preview-status');
            previewStatus.setAttribute('role','status');
            previewStatus.setAttribute('aria-live','polite');
            const runButton = node('button', 'اجرای بازاستخراج', 'button button-primary');
            runButton.type = 'button';
            const refreshButton = node('button', 'تازه‌کردن پیش‌نمایش', 'button button-secondary');
            refreshButton.type = 'button';
            const actionRow = node('div', null, 'molavi-reextract-run-actions');
            actionRow.append(runButton,refreshButton);
            const safeRules = node('p', 'تصمیم‌های نهایی یا ردّ مالک، پیام‌های کنارگذاشته‌شده و موارد در حال اجرا از انتخاب عادی خارج می‌مانند. بازه بر اساس تاریخ پیام منبع و به‌وقت تهران محاسبه می‌شود.', 'molavi-reextract-safety-note');
            selectionCard.append(previewHeading,countLine,filterSummary,groupControls,groupsList,previewStatus,actionRow,safeRules);
            layout.append(filtersCard,selectionCard);
            panel.appendChild(layout);
            const activeRunBox = node('div', null, 'molavi-reextract-active-run');
            panel.appendChild(activeRunBox);

            let currentPreview = initial;
            let selectionVersion = 0;
            let progressRunId = '';
            const formatter = new Intl.NumberFormat('fa-IR');
            const selectedScope = () => [...scopeInputs.entries()].find(([,input]) => input.checked)?.[0] || 'all';
            const selectedChannels = () => channelInputs.filter(input => input.checked).map(input => input.value);
            channelSearch.addEventListener('input',() => {
                const query = channelSearch.value.trim().toLocaleLowerCase();
                channelEntries.forEach(({label,search}) => { label.hidden = query !== '' && !search.includes(query); });
            });
            const previewUrl = () => {
                const url = new URL(reextractionUrl('preview'), window.location.href);
                url.searchParams.set('scope', selectedScope());
                url.searchParams.set('group_by', groupBy.value);
                url.searchParams.set('channels',JSON.stringify(selectedChannels()));
                if (dateFrom.value) url.searchParams.set('date_from',dateFrom.value);
                if (dateTo.value) url.searchParams.set('date_to',dateTo.value);
                return url.toString();
            };
            const drawSelection = data => {
                currentPreview = data;
                const selection = data.selection || {};
                const count = Number(selection.count || 0);
                selectedCount.textContent = formatter.format(count);
                const scopeName = scopeOptions.find(option => option.key === selection.scope)?.label || 'همهٔ ورودی‌های واجد شرایط';
                const channelNames = selectedChannels();
                const dateRange = dateFrom.value || dateTo.value
                    ? `${dateFrom.value ? `از ${dateFrom.value}` : 'از ابتدای سابقه'} · ${dateTo.value ? `تا ${dateTo.value}` : 'به بعد'}`
                    : 'همهٔ تاریخ‌ها';
                filterSummary.textContent = `${scopeName} · ${channelNames.length ? channelNames.join('، ') : 'همهٔ کانال‌ها'} · ${dateRange}`;
                groupsList.replaceChildren();
                (Array.isArray(selection.groups) ? selection.groups : []).forEach(group => {
                    const row = document.createElement('li');
                    const label = selection.group_by === 'date' && group.key
                        ? new Intl.DateTimeFormat('fa-IR').format(new Date(`${group.key}T12:00:00`))
                        : String(group.label || 'منبع نامشخص');
                    row.append(node('span',label),node('b',formatter.format(Number(group.count || 0))));
                    groupsList.appendChild(row);
                });
                if (!groupsList.childElementCount) groupsList.appendChild(node('li','موردی با این ترکیب پیدا نشد.'));
                const active = data.active_run;
                const canStart = data.ai_configured === true && count > 0 && !active;
                runButton.disabled = !canStart;
                runButton.textContent = active ? 'یک بازاستخراج در حال اجراست' : `بازاستخراج ${formatter.format(count)} پست`;
                if (data.ai_configured !== true) previewStatus.textContent = 'مدل استخراج فعلی روی سرویس تنظیم نشده است؛ شروع کار غیرفعال است.';
                else if (active) previewStatus.textContent = 'یک بازاستخراج دیگر در صف یا در حال اجراست؛ اجرای هم‌زمان ممکن نیست.';
                else previewStatus.textContent = 'شمارش از سرویس تازه شده و پیش از شروع دوباره کنترل می‌شود.';
                if (active && active.run_id && progressRunId !== active.run_id) {
                    progressRunId = active.run_id;
                    const audit = {run_id:active.run_id,scope:active.scope || 'all',
                        message_count:Number(active.total || count),digest:String(active.digest || '')};
                    const progress = notice('در حال دریافت وضعیت بازاستخراج…','info');
                    progress.classList.add('molavi-reextract-progress');
                    activeRunBox.replaceChildren(progress);
                    showReextractionProgress(panel,active.run_id,audit);
                }
            };
            const updatePreview = async () => {
                const version = ++selectionVersion;
                runButton.disabled = true;
                refreshButton.disabled = true;
                previewStatus.textContent = 'در حال تازه‌کردن شمارش از سرویس…';
                try {
                    const data = await reextractionRequest('GET',undefined,previewUrl());
                    if (version !== selectionVersion) return;
                    drawSelection(data);
                } catch (error) {
                    if (version !== selectionVersion) return;
                    currentPreview = null;
                    groupsList.replaceChildren();
                    selectedCount.textContent = '—';
                    filterSummary.textContent = 'پیش‌نمایش در دسترس نیست.';
                    previewStatus.textContent = errorText(error.message);
                    runButton.disabled = true;
                } finally {
                    if (version === selectionVersion) refreshButton.disabled = false;
                }
            };
            scopeInputs.forEach(input => input.addEventListener('change',updatePreview));
            channelInputs.forEach(input => input.addEventListener('change',() => {
                if (selectedChannels().length > 30) {
                    input.checked = false;
                    previewStatus.textContent = 'حداکثر ۳۰ کانال را هم‌زمان انتخاب کنید.';
                    return;
                }
                updatePreview();
            }));
            dateFrom.addEventListener('change',updatePreview);
            dateTo.addEventListener('change',updatePreview);
            groupBy.addEventListener('change',updatePreview);
            clearFilters.addEventListener('click',() => {
                channelInputs.forEach(input => { input.checked = false; });
                dateFrom.value = ''; dateTo.value = '';
                updatePreview();
            });
            refreshButton.addEventListener('click',updatePreview);
            drawSelection(initial);

            runButton.addEventListener('click',async() => {
                const selection = currentPreview && currentPreview.selection;
                const count = Number(selection && selection.count || 0);
                if (!selection || count < 1 || currentPreview.active_run || currentPreview.ai_configured !== true) return;
                const scope = selectedScope();
                const channels = selectedChannels();
                const dateFromValue = dateFrom.value;
                const dateToValue = dateTo.value;
                const digest = String(selection.digest || '');
                const groupSummary = (selection.groups || []).map(group => `${group.label}: ${group.count}`).join('، ');
                const confirmation = `بازاستخراج ${count} پست با مدل فعلی شروع می‌شود. دامنه: ${scopeOptions.find(item => item.key === scope)?.label || scope}. گروه‌ها: ${groupSummary || 'بدون مورد'}. آلبوم‌ها یک‌جا پردازش می‌شوند و خروجی OCR به بررسی مالک می‌رود. تصمیم‌های نهایی مالک حفظ می‌شوند. ادامه می‌دهید؟`;
                if (!window.confirm(confirmation)) return;
                if (!/^[a-f0-9]{64}$/.test(digest)) {
                    previewStatus.textContent = 'پیش‌نمایش معتبر نیست؛ دوباره تازه کنید.';
                    return;
                }
                const bytes = new Uint8Array(16);
                if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(bytes);
                else for (let index=0; index<bytes.length; index++) bytes[index]=Math.floor(Math.random()*256);
                const runId = Array.from(bytes,value=>value.toString(16).padStart(2,'0')).join('');
                const auditContext = {run_id:runId,scope,message_count:count,digest,
                    channels,date_from:dateFromValue,date_to:dateToValue};
                const eventId = 'reextraction-run:' + runId;
                const decision = {action:'reextract_saved_messages',scope,channels,
                    date_from:dateFromValue,date_to:dateToValue};
                runButton.disabled = true;
                refreshButton.disabled = true;
                try {
                    await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,'pending',
                        storedReviewContext('ai_reextraction',auditContext),decision,{})]);
                    const receipt = await reextractionRequest('POST',{action:'start_reextraction',scope,
                        expected_count:count,channels,date_from:dateFromValue,date_to:dateToValue,
                        expected_digest:digest,run_id:runId});
                    if (receipt.run_id !== runId || receipt.queued !== count || receipt.scope !== scope) {
                        throw new Error('invalid_receipt');
                    }
                    let auditWarning = false;
                    try { await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,
                        'accepted',storedReviewContext('ai_reextraction',auditContext),decision,receipt)]); }
                    catch (_error) { auditWarning = true; }
                    const progress = notice('بازاستخراج در صف پردازش قرار گرفت…','info');
                    progress.classList.add('molavi-reextract-progress');
                    if (auditWarning) progress.appendChild(node('p','اجرا در صف است، اما ذخیرهٔ رسید اولیه در وردپرس ناموفق بود.','notice'));
                    activeRunBox.replaceChildren(progress);
                    showReextractionProgress(panel,runId,auditContext);
                } catch (error) {
                    try { await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,
                        error.remoteRejected?'failed':'pending',storedReviewContext('ai_reextraction',auditContext),
                        decision,{error:String(error.message || 'request_failed')})]); } catch (_auditError) { }
                    previewStatus.textContent = errorText(error.message);
                    runButton.disabled = false;
                    refreshButton.disabled = false;
                    if (error.message === 'reextraction_preview_changed') updatePreview();
                }
            });

            const advancedDetails = document.createElement('details');
            advancedDetails.className = 'molavi-advanced-reextract';
            advancedDetails.appendChild(node('summary','مسیرهای ویژهٔ استخراج'));
            advancedDetails.appendChild(node('p','OCR تصویر، بازپردازش نسخهٔ فعلی سیاست و رسیدگی به کارت‌های باز مسیرهای جداگانه دارند.'));
            const advancedBody = node('div',null,'molavi-advanced-reextract-body');
            advancedDetails.appendChild(advancedBody);
            let advancedLoaded = false;
            advancedDetails.addEventListener('toggle',() => {
                if (!advancedDetails.open || advancedLoaded) return;
                advancedLoaded = true;
                advancedReextractionTools(advancedBody);
            });
            panel.appendChild(advancedDetails);
        } catch (error) {
            panel.appendChild(notice(errorText(error.message),'error'));
        }
    }

    async function aiConfirmations(message = '', messageKind = 'info') {
        shell('تأیید استخراج کالا', 'موارد نامطمئن را با پیام اصلی بررسی کنید؛ قیمت‌های قدیمی تا تأیید منبع فعلی از فهرست کالای جاری کنار می‌مانند.');
        if (message) root.appendChild(notice(message, messageKind));
        const reextract = node('section');
        reextract.id = 'molavi-reextract-panel';
        root.appendChild(reextract);
        await reextractionPanel(reextract);
        try {
            const data = await request();
            if (data.publication_held) root.appendChild(notice('انتشار فروشگاه تا رفع توقف‌های باز متوقف است؛ تصمیم‌های منابع مستقل همچنان قابل ثبت‌اند.', 'warning'));
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
            const decisionsEnabled = contextStored;
            const eligibleOpenCases = openCases.filter(item => !item.publication_held);
            const selected = new Map();
            const caseCheckboxes = new Map();
            if (openCases.length) {
                const toolbar = node('div', null, 'molavi-bulk-dismiss');
                const selectAllLabel = node('label', null, 'molavi-bulk-select-all');
                const selectAll = document.createElement('input');
                selectAll.type = 'checkbox';
                selectAll.disabled = !contextStored || !eligibleOpenCases.length;
                selectAllLabel.append(selectAll, document.createTextNode(' انتخاب همه موارد مستقل و باز'));
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
                    selectAll.checked = eligibleOpenCases.length > 0 && selectedCount === eligibleOpenCases.length;
                    selectAll.indeterminate = selectedCount > 0 && selectedCount < eligibleOpenCases.length;
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
                            ? (item.reference_match_name || 'تطبیق ثبت‌شده')
                            : item.reference_match_state || 'بدون تطبیق مرجع ثبت‌شده';
                        list.appendChild(node('li', `${item.product_name || 'کالای بدون نام'} · ${item.channel || 'کانال نامشخص'} · ${amount(item.detected_price || item.sale_price)} · ${role} · مرجع: ${reference}`));
                    });
                    preview.appendChild(list);
                    preview.appendChild(node('p', 'اثر تصمیم: قیمت یا متن پیام تغییر نمی‌کند؛ پیشنهاد به «کالا نیست» تبدیل می‌شود، فهرست کالا دوباره ساخته و همگام‌سازی فروشگاه در صف قرار می‌گیرد. برای بازگردانی، از تاریخچه همان مورد استفاده کنید.'));
                };
                selectAll.addEventListener('change', () => {
                    selected.clear();
                    eligibleOpenCases.forEach(item => {
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
                const caseGroups = [
                    ...groupAiVariantCases(openCases.filter(item => !item.publication_held)),
                    ...groupAiVariantCases(openCases.filter(item => item.publication_held))
                ];
                caseGroups.forEach(items => {
                    const groupHeld=Boolean(items[0].publication_held);
                    const groupSelection=groupHeld ? null : (row, checked, checkbox) => {
                        caseCheckboxes.set(row.case_id, checkbox);
                        if (checked) selected.set(row.case_id, row);
                        else selected.delete(row.case_id);
                        updateSelection();
                    };
                    root.appendChild(aiCaseCard(items[0], groupSelection, contextStored,
                        decisionsEnabled && !groupHeld, items));
                });
            }
            const seenCaseIds = new Set(openCases.map(item => item.case_id));
            recentCases.filter(item => !seenCaseIds.has(item.case_id))
                .forEach(item => root.appendChild(aiCaseCard(item, null, contextStored,
                    decisionsEnabled && !item.publication_held)));
            if (!openCases.length && !recentCases.length) root.appendChild(node('p', 'موردی برای بررسی وجود ندارد.'));
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    function referenceItemCard(item, contextStored = true, options = {}) {
        const card = node('section', null, options.embedded
            ? 'molavi-product-subsection molavi-review-card molavi-reference-review-card'
            : 'card molavi-review-card molavi-reference-review-card');
        const duplicateParentName = Boolean(options.embedded && options.parentName
            && aiDisplayKey(options.parentName) === aiDisplayKey(item.product_name));
        card.appendChild(reviewCardHeader(duplicateParentName ? '' : item.product_name || 'کالای بدون نام', 'تطبیق مرجع'));
        const referenceFacts = [
            ...(!options.embedded && item.channel ? [['تأمین‌کننده', item.channel]] : []),
            ['قیمت تأمین‌کننده', amount(item.supplier_price)],
            ['نقش پیشنهادی قیمت', item.price_role_hint || 'نیازمند بررسی'],
            ['دلیل بررسی', aiReviewReason(item.reason)]
        ];
        if (item.reference_update) {
            const percent = Number(item.reference_update.change_percent);
            referenceFacts.push(['تغییر قیمت مرجع', Number.isFinite(percent) ? percent.toLocaleString('fa-IR') + '٪' : 'نامشخص']);
            card.appendChild(notice('برای ثبت تغییر مرجع، ابتدا مبلغ را با پیام اصلی تطبیق دهید.', 'warning'));
        }
        card.appendChild(reviewFacts(referenceFacts));
        const referenceSourceDates = aiSourceDateFacts(item);
        if (referenceSourceDates.length) card.appendChild(reviewFacts(referenceSourceDates,
            'molavi-review-facts molavi-ai-source-date-facts'));
        if (!item.decision) card.appendChild(reviewNextStep(
            'گزینهٔ مرجع درست و مبنای قیمت واحد یا بسته را انتخاب کنید؛ این کار قیمت یا انتشار را تأیید نمی‌کند.'
        ));
        const originalPost = safeSourceLink(item.source_post_url);
        if (originalPost) {
            const tools = node('div', null, 'molavi-card-source-tools');
            tools.appendChild(originalPost);
            card.appendChild(tools);
        }
        if (item.decision) card.appendChild(reviewFacts([
            ['تصمیم ذخیره‌شده', item.decision.choice || 'ثبت شده'],
            ['مرجع ثبت‌شده', item.decision.reference_name || (item.decision.reference_id ? 'تطبیق ثبت شده' : 'ندارد')],
            ['نقش قیمت', item.decision.price_role || 'نامشخص'],
            ['یادداشت', item.decision.note || '']
        ], 'molavi-review-facts molavi-review-prior-decision'));
        const form = document.createElement('form');
        async function submitChoice(choice, submitter, retryStoredDecision = false) {
            const previous = item.decision || {};
            const referenceId = retryStoredDecision
                ? (previous.choice === 'match' ? String(previous.reference_id || '') : '')
                : (choice === 'match' ? selectedReferenceId : '');
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, snapshot_id: item.snapshot_id,
                choice: retryStoredDecision ? previous.choice : choice,
                reference_id: referenceId,
                price_role: retryStoredDecision ? String(previous.price_role || 'unresolved')
                    : (choice === 'match' ? selectedPriceRole : 'unresolved'),
                note: retryStoredDecision ? String(previous.note || '')
                    : (choice === 'match' ? 'مالک تطبیق پیشنهادی را از پنل بررسی تأیید کرد.' : 'مالک تأیید کرد که پیشنهادهای فعلی تطبیق ندارند.'),
                expected_decision_id: retryStoredDecision ? previous.decision_id
                    : (previous.decision_id || null)
            };
            if (payload.choice === 'match' && (!payload.reference_id || !['unit', 'package'].includes(payload.price_role))) {
                errorBox('تصمیم قبلی برای تطبیق کامل نیست؛ مورد باز می‌ماند.', card);
                return;
            }
            const eventId = retryStoredDecision
                ? `decision:reference_item:${item.case_id}:${previous.decision_id}`
                : reviewEventId('reference_item', item.case_id);
            const decision = {...payload};
            const controls = Array.from(form.querySelectorAll('button'));
            controls.forEach(button => { button.disabled = true; });
            const progressStatus = actionStatus(card, 'در حال ثبت تصمیم تطبیق با درخواست AJAX…');
            try {
                await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.case_id,
                    'pending', item, decision)]);
                const receipt = await request('POST', payload, undefined, options.apiKey || '');
                if (receipt.case_id !== payload.case_id || receipt.offer_id !== payload.offer_id
                    || receipt.snapshot_id !== payload.snapshot_id || receipt.choice !== payload.choice
                    || !/^[a-f0-9]{64}$/.test(receipt.decision_id || '')
                    || !['imported', 'needs_review', 'held_for_review', 'pending_verification'].includes(receipt.projection_state)
                    || (payload.choice === 'no_match' && !['held_for_review', 'pending_verification'].includes(receipt.projection_state))
                    || (payload.choice === 'match' && receipt.projection_state === 'held_for_review')) {
                    throw new Error('invalid_receipt');
                }
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.case_id,
                        'accepted', item, decision, receipt)]);
                } catch (_error) {
                    updateActionStatus(progressStatus, 'تصمیم در سرویس ثبت شد، اما رسید وردپرس هنوز همگام نشده است.', 'warning');
                    if (typeof options.refresh === 'function') {
                        await options.refresh('تصمیم در سرویس ثبت شد، اما رسید وردپرس هنوز همگام نشده است.', 'warning', progressStatus,
                            ['reference_items','product_status','supplier_prices','ai_confirmations','operations']);
                    } else {
                        await referenceItems('تصمیم در سرویس ثبت شد، اما رسید وردپرس هنوز همگام نشده است.', 'warning');
                    }
                    return;
                }
                if (receipt.projection_state === 'pending_verification') {
                    updateActionStatus(progressStatus, 'تصمیم ذخیره شد؛ ثبت آن در فهرست کالاها هنوز تأیید نشده و مورد باز می‌ماند.', 'warning');
                    if (typeof options.refresh === 'function') {
                        await options.refresh('تصمیم ذخیره شد؛ ثبت آن در فهرست کالاها هنوز تأیید نشده و مورد برای تکمیل باز می‌ماند.', 'warning', progressStatus,
                            ['reference_items','product_status','supplier_prices','ai_confirmations','operations']);
                    } else {
                        await referenceItems('تصمیم ذخیره شد؛ ثبت آن در فهرست کالاها هنوز تأیید نشده و مورد برای تکمیل باز می‌ماند.', 'warning');
                    }
                } else {
                    updateActionStatus(progressStatus, 'تصمیم تطبیق ثبت شد.', 'success');
                    if (typeof options.refresh === 'function') await options.refresh('تصمیم تطبیق ثبت شد.', 'success', progressStatus,
                        ['reference_items','product_status','supplier_prices','ai_confirmations','operations']);
                    else await referenceItems('تصمیم تطبیق ثبت شد.', 'success');
                }
            } catch (error) {
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'reference_item', item.case_id,
                        error.remoteRejected ? 'failed' : 'pending', item, decision,
                        {error:String(error.message || 'request_failed'), request_status:error.requestStatus || null})]);
                } catch (_ignored) {}
                updateActionStatus(progressStatus, errorText(error.message), 'error');
                errorBox(errorText(error.message), card);
                controls.forEach(button => { button.disabled = false; });
                if (submitter) submitter.disabled = false;
            }
        }
        if (item.projection_pending && item.decision) {
            card.appendChild(notice('تصمیم در سرور ذخیره شده، اما ثبت آن در فهرست کالاها هنوز تأیید نشده است. برای تکمیل همان تصمیم، این دکمه را بزنید.', 'warning'));
            const retry = node('button', 'تکمیل ثبت تصمیم قبلی', 'button button-primary');
            retry.type = 'button';
            retry.disabled = !contextStored;
            retry.addEventListener('click', () => submitChoice(item.decision.choice, retry, true));
            form.appendChild(retry);
            card.appendChild(form);
            return card;
        }
        const candidateRows = (item.candidates || []).filter(row => row && row.reference_id
            && String(row.name || '').trim());
        const currentReference = item.decision && item.decision.reference_id
            || (item.reference_update && item.reference_update.reference_id) || '';
        let selectedReferenceId = candidateRows.some(row => row.reference_id === currentReference) ? currentReference : '';
        const requestedReferenceId = String(item.preselected_reference_id
            || new URLSearchParams(window.location.search).get('reference_id') || '');
        if (!selectedReferenceId && candidateRows.some(row => row.reference_id === requestedReferenceId)) {
            selectedReferenceId = requestedReferenceId;
        }
        if (!selectedReferenceId && candidateRows.length && Number(candidateRows[0].score) >= 0.95) {
            selectedReferenceId = candidateRows[0].reference_id;
        }
        const roleHint = String(item.decision && item.decision.price_role || item.price_role_hint || '').toLowerCase();
        let selectedPriceRole = ['package', 'pack', 'carton'].includes(roleHint) ? 'package'
            : ['unit', 'single', 'per_unit'].includes(roleHint) ? 'unit' : '';
        const candidates = node('div', null, 'molavi-reference-candidates');
        if (candidateRows.length) {
            candidates.appendChild(node('p', 'گزینه‌ها با نام و ویژگی‌های پیام مقایسه شده‌اند؛ اگر شباهت ویژگی‌ها زیاد باشد، گزینهٔ اول پیش‌انتخاب می‌شود. هر تطبیق را پیش از ثبت بررسی و تأیید کنید.'));
            candidateRows.slice(0, 6).forEach(row => {
                const option = node('button', null, 'button molavi-reference-candidate');
                option.type = 'button';
                option.setAttribute('aria-pressed', String(row.reference_id === selectedReferenceId));
                option.appendChild(node('strong', row.name));
                const nameScore = row.name_similarity ?? row.component_scores?.name;
                const score = Number(nameScore ?? row.score);
                option.appendChild(node('span', `${nameScore === undefined ? 'امتیاز کلی پیشنهاد' : 'شباهت نام'}: ${Number.isFinite(score) ? Math.round(score * 100).toLocaleString('fa-IR') + '٪' : 'نامشخص'}`));
                const comparisons = referenceComparisonSummary(row);
                if (comparisons.length) option.appendChild(node('span', comparisons.join(' · ')));
                option.appendChild(node('span', referencePriceSummary(row, item.supplier_price)
                    || `قیمت مرجع واحد: ${amount(row.unit_price)} · بسته: ${amount(row.package_price)}`));
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
        const actions = reviewActions();
        actions.append(match, noMatch);
        form.appendChild(actions);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const choice = event.submitter && event.submitter.value;
            if (!['match', 'no_match'].includes(choice) || !contextStored) return;
            if (choice === 'match' && (!selectedReferenceId || !selectedPriceRole)) {
                errorBox('یک پیشنهاد مرجع و نقش قیمت را با دکمه‌ها انتخاب کنید.', card);
                return;
            }
            submitChoice(choice, event.submitter);
        });
        card.appendChild(form);
        return card;
    }

    function safeSourceLink(value, label = 'باز کردن پیام اصلی') {
        if (typeof value !== 'string' || !value) return null;
        try {
            const url = new URL(value);
            if (url.protocol !== 'https:' || !['t.me', 'www.t.me', 'bale.ai', 'www.bale.ai'].includes(url.hostname)) return null;
            const link = node('a', label, 'button button-secondary');
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
            const requestedReferenceId = new URLSearchParams(window.location.search).get('reference_id') || '';
            let cases = [...(data.cases || []), ...(data.recent_decisions || [])];
            if (requestedOfferId) cases = cases.filter(item => String(item.offer_id || '') === requestedOfferId);
            if (requestedReferenceId) cases.forEach(item => { item.preselected_reference_id = requestedReferenceId; });
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
                const card = node('section', null, 'card molavi-review-card molavi-status-snapshot-card');
                card.appendChild(reviewCardHeader(item.product_name || 'کالای بدون نام',
                    item.update_needed ? 'نیازمند بررسی' : 'وضعیت ثبت‌شده'));
                card.appendChild(reviewFacts([
                    ['وضعیت محصول', item.status || 'نامشخص'],
                    ['وضعیت به‌روزرسانی', item.update_needed ? 'نیازمند بررسی' : 'فعلاً نیاز ندارد'],
                    ['تطبیق مرجع', item.reference_approval_state || 'ثبت نشده'],
                    ['تغییر قیمت مرجع', item.reference_price_change_percent ? item.reference_price_change_percent + '٪' : '—'],
                    ['مبنای قیمت مرجع', item.reference_price_role || '—'],
                    ['تازگی قیمت تأمین‌کننده', item.supplier_price_state || 'ثبت نشده'],
                    ['نتیجه بررسی مرجع', item.reference_review_result || 'ثبت نشده']
                ]));
                const originalPost = safeSourceLink(item.source_post_url);
                if (originalPost) {
                    const tools = node('div', null, 'molavi-card-source-tools');
                    tools.appendChild(originalPost);
                    card.appendChild(tools);
                }
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
                const card = node('section', null, 'card molavi-review-card molavi-reference-event-card');
                const reason = ({ stale: 'قیمت مرجع بیش از ۲۴ ساعت قدمت دارد', refresh_failed: 'دریافت قیمت مرجع ناموفق بود', missing: 'قیمت مرجع قبلی موجود نیست' })[event.reason] || 'نیاز به بررسی';
                card.appendChild(reviewCardHeader(reason, 'تصمیم سیستمی'));
                card.appendChild(reviewFacts([
                    ['زمان تشخیص', formatSourceTimestamp(event.created_at) || 'ثبت نشده'],
                    ['نسخه قبلی مرجع', event.snapshot_id ? 'موجود است' : 'موجود نیست']
                ]));
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
                const card = node('section', null, 'card molavi-review-card molavi-publication-hold-card');
                card.appendChild(reviewCardHeader(reasons[hold.reason] || 'این دسته نیاز به بررسی دارد.', 'بازیابی دسته'));
            card.appendChild(reviewFacts([['زمان توقف', formatSourceTimestamp(hold.created_at) || 'نامشخص']],
                    'molavi-review-facts molavi-hold-meta'));
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
                card.appendChild(reviewFacts([
                    ['وضعیت دسته', statusLabels[context.batch_state] || context.batch_state || 'نامشخص'],
                    ['پیام‌های ثبت‌شده', `${context.written_messages || 0} از ${context.expected_messages || 0}`],
                    ['پیام‌های پردازش‌شده', context.processed_messages || 0]
                ], 'molavi-review-facts molavi-batch-progress-facts'));
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
                const sources = node('details', null, 'molavi-review-source-details');
                sources.open = messages.length === 1;
                sources.appendChild(node('summary', `پیام‌های مبدأ (${new Intl.NumberFormat('fa-IR').format(messages.length)})`));
                if (!messages.length) card.appendChild(notice('جزئیات پیام مبدأ از سرویس پردازش دریافت نشد؛ برای جلوگیری از پردازش دوبارهٔ دستهٔ نامشخص، این مورد تا نمایش جزئیات غیرفعال است.', 'warning'));
                for (const source of messages) {
                    const item = node('div', null, 'molavi-review-source');
                    item.appendChild(node('strong', source.channel || 'کانال نامشخص'));
                    const sourceDates = aiSourceDateFacts(source);
                    if (sourceDates.length) item.appendChild(reviewFacts(sourceDates,
                        'molavi-review-facts molavi-ai-source-date-facts'));
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
                    sources.appendChild(item);
                }
                if (messages.length) card.appendChild(sources);
                root.appendChild(card);
            }
            updateSelection();
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    async function supplierPrices(message = '', messageKind = 'info') {
        shell('تازگی قیمت تأمین‌کنندگان', 'قیمت‌های کهنه یا فاقد نسخه منبع برای بررسی نمایش داده می‌شوند. تأیید فقط پس از بررسی واقعی منبع ثبت شود؛ این کار انتشار را به صف می‌فرستد.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const outbox = await drainWordPressWorkflowOutbox();
            if (outbox.delivered) root.appendChild(notice(
                `${new Intl.NumberFormat('fa-IR').format(outbox.delivered)} تصمیم ذخیره‌شده در وردپرس به Railway تحویل شد.`, 'success'));
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
                const card = node('section', null, 'card molavi-review-card molavi-supplier-price-card');
                card.appendChild(reviewCardHeader(offer.name || 'کالای بدون نام', 'تأیید تازگی قیمت'));
                const status = notice(labels[offer.state] || labels.needs_review, 'warning');
                card.appendChild(status);
                card.appendChild(reviewFacts([
                    ['تأمین‌کننده', offer.channel || 'نامشخص'],
                    ['قیمت ثبت‌شده', amount(offer.sale_price)],
                    ['زمان آخرین منبع', offer.source_timestamp
                        ? `${formatSourceTimestamp(offer.source_timestamp)} · ${sourceAgeText(offer.source_timestamp)}`
                        : 'ثبت نشده']
                ]));
                const sourceLink = safeSourceLink(offer.source_post_url);
                if (sourceLink) {
                    const tools = node('div', null, 'molavi-card-source-tools');
                    tools.appendChild(sourceLink);
                    card.appendChild(tools);
                }
                const sourceRevision = String(offer.source_revision || '').trim();
                if (!sourceRevision) card.appendChild(notice(
                    'برای این ردیف قیمت فروش معتبر در منبع فعلی ثبت نشده است؛ ابتدا داده قیمت را اصلاح کنید. این مورد قابل تأیید نیست.',
                    'warning'));
                const context = {product_id:offer.product_id,name:offer.name,channel:offer.channel,
                    sale_price:offer.sale_price,source_timestamp:offer.source_timestamp,source_revision:sourceRevision};
                const decision = {action:'verify_current_supplier_price',note:'Owner checked the current supplier source.'};
                const form = document.createElement('form');
                const submit = node('button', 'قیمت تأمین‌کننده را بررسی کردم', 'button button-primary');
                submit.type = 'submit'; submit.disabled = !sourceRevision;
                const actions = reviewActions();
                actions.appendChild(submit);
                form.appendChild(actions);
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    if (!sourceRevision) return;
                    submit.disabled = true;
                    const eventId = reviewEventId('supplier_price', offer.product_id);
                    try {
                        const stored = await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,
                            'pending',context,decision)]);
                        const commandId = stored.commands[eventId];
                        if (!commandId) throw new Error('review_store_failed');
                        const receipt = await requestSupplierPrice({product_id: offer.product_id,
                            expected_revision: sourceRevision,
                            note: 'مالک منبع فعلی قیمت را از پنل بررسی تأیید کرد.',command_id:commandId},
                            config.workflowApis && config.workflowApis.supplier_prices ? 'supplier_prices' : '');
                        if (receipt.product_id !== offer.product_id || receipt.source_revision !== sourceRevision
                            || receipt.command_id !== commandId || !receipt.verified_at || !receipt.publish_job) throw new Error('invalid_receipt');
                        await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,
                            'accepted',context,decision,receipt)]);
                        status.textContent = 'بررسی قیمت ثبت شد و انتشار دوباره به صف فرستاده شد.';
                        status.className = 'notice notice-success';
                        status.setAttribute('role', 'status');
                        status.setAttribute('aria-live', 'polite');
                        submit.textContent = 'قیمت تأیید شد';
                        submit.disabled = true;
                    } catch (error) {
                        try { await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,
                            error.remoteRejected?'failed':'pending',context,decision,{error:String(error.message||'request_failed')})]); }
                        catch (_ignored) {}
                        errorBox(errorText(error.message), card); submit.disabled = !sourceRevision;
                    }
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
            const outbox = await drainWordPressWorkflowOutbox();
            if (outbox.delivered) root.appendChild(notice(
                `${new Intl.NumberFormat('fa-IR').format(outbox.delivered)} تصمیم ذخیره‌شده در وردپرس به Railway تحویل شد.`, 'success'));
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
                const card = node('section', null, 'card molavi-review-card molavi-conflict-review-card');
                card.appendChild(reviewCardHeader(conflict.product_name || 'کالای بدون نام', 'تعارض داده'));
                card.appendChild(reviewFacts([
                    ['تأمین‌کننده', conflict.channel || 'نامشخص'], ['فیلد', conflict.field || 'نامشخص']
                ], 'molavi-review-facts molavi-conflict-meta'));
                card.appendChild(reviewFacts([
                    ['آخرین مقدار هماهنگ‌شده', conflictValue(conflict.base)],
                    ['مقدار فعلی Railway', conflictValue(conflict.server)],
                    ['مقدار فعلی شیت', conflictValue(conflict.sheet)]
                ], 'molavi-review-facts molavi-conflict-values'));
                const serverOwned = conflict.server_owned === true;
                const sheetPreserved = conflict.sheet_preserved === true;
                const primaryChoice = sheetPreserved ? 'preserve' : 'server';
                if (serverOwned) card.appendChild(notice('این فیلد سیستمی است؛ فقط مقدار Railway می‌تواند در شیت ثبت شود.', 'info'));
                if (sheetPreserved) card.appendChild(notice('هویت و زمان ثبت اولیه حفظ می‌شود؛ این فیلد قابل اصلاح دستی نیست.', 'info'));
                const actions = reviewActions('molavi-reextract-actions molavi-review-actions');
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
                            'pending', conflict, decision)]);
                        const receipt = await requestSheetConflict({conflict_id: conflict.conflict_id, choice,
                            note:decision.note, expected_revision:conflict.source_revision});
                        if (receipt.conflict_id !== conflict.conflict_id || receipt.choice !== choice
                            || (serverOwned ? receipt.server_owned !== true
                                : sheetPreserved ? receipt.sheet_preserved !== true
                                : !receipt.publish_job)) throw new Error('invalid_receipt');
                        let localWarning = '';
                        try {
                            await persistOwnerReviews([reviewRecord(eventId,
                                'sheet_conflict', conflict.conflict_id, 'accepted', conflict, decision, receipt)]);
                        } catch (_error) { localWarning = ' تصمیم در سرویس ثبت شد، اما وضعیت محلی وردپرس در انتظار همگام‌سازی است.'; }
                        const successMessage = serverOwned
                            ? 'مقدار سیستمی با مقدار Railway در شیت همگام شد.'
                            : sheetPreserved ? 'مقدار اصلی شیت حفظ شد.'
                            : 'تصمیم ثبت شد و انتشار به صف رفت.';
                        await sheetConflicts(`${successMessage}${localWarning}`,
                            localWarning ? 'warning' : 'success');
                    } catch (error) {
                        try {
                            await persistOwnerReviews([reviewRecord(eventId,
                                'sheet_conflict', conflict.conflict_id, error.remoteRejected ? 'failed' : 'pending',
                                conflict, decision, {error:error.message})]);
                        } catch (_ignored) {}
                        if (error.message === 'source_changed' && error.responseBody
                            && error.responseBody.refreshed === true) {
                            await sheetConflicts('نسخهٔ این تعارض کهنه بود؛ فهرست تازه شد و موردهای باقی‌مانده نمایش داده می‌شوند.', 'info');
                            return;
                        }
                        if (error.message === 'conflict_changed') {
                            await sheetConflicts('این تعارض هم‌زمان تغییر کرد؛ فهرست تازه شد تا فقط موارد باز را ببینید.', 'info');
                            return;
                        }
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
