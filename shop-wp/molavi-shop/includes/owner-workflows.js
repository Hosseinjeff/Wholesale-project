(() => {
    const root = document.getElementById('molavi-owner-workflow');
    const config = window.molaviOwnerWorkflow;
    if (!root || !config) return;
    const verifiedCurrentPriceCases = new Set();

    function currentPriceVerificationKey(item) {
        return `${item && item.case_id || ''}:${item && item.signature || ''}`;
    }

    function aiSourceCurrentCheckMessage(item) {
        if (item?.reopened) return 'پیش از ثبت تصمیم تازه، قیمت و موجودی را با منبع فعلی تأمین‌کننده بررسی کنید.';
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

    function sourceImageThumbnail(fileId, fallback = null) {
        if (typeof fileId!=='string'||!/^[-\w]{8,300}$/.test(fileId)) return fallback;
        const url=new URL('/media/file/'+encodeURIComponent(fileId),config.apiUrl).href;
        const thumbnail=node('button',null,'molavi-source-thumbnail');
        thumbnail.type='button';
        thumbnail.setAttribute('aria-label','بزرگ‌نمایی تصویر پیام تأمین‌کننده');
        thumbnail.setAttribute('aria-haspopup','dialog');
        const image=node('img');
        image.alt='';image.loading='lazy';image.decoding='async';image.fetchPriority='low';image.referrerPolicy='no-referrer';
        image.addEventListener('error',()=>{
            if (fallback) thumbnail.replaceWith(fallback);else thumbnail.remove();
        },{once:true});
        thumbnail.append(image);
        image.src=url;
        thumbnail.addEventListener('click',()=>{
            const dialog=node('dialog',null,'md-dialog molavi-source-photo-dialog');
            dialog.setAttribute('aria-label','تصویر پیام تأمین‌کننده');
            const full=node('img');
            full.alt='تصویر پیام تأمین‌کننده';full.decoding='async';full.referrerPolicy='no-referrer';
            full.addEventListener('error',()=>full.replaceWith(node('p','تصویر در دسترس نیست. پیام اصلی را بررسی کنید.')),{once:true});
            full.src=url;
            const close=node('button','بستن','button');close.type='button';
            close.addEventListener('click',()=>dialog.close());
            dialog.addEventListener('close',()=>dialog.remove(),{once:true});
            dialog.append(full,close);root.append(dialog);dialog.showModal();
        });
        return thumbnail;
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

    // Per-product step-status trace (owner request 2026-11-25): what the
    // system inferred or compared at each step on the way to publication,
    // with the actual values. Read-only rendering of product.status.step_trace
    // (attached by the product-status API); no decision logic here.
    // Fact kinds: 'amount' (تومان), 'date' (fa-IR), 'pct' (0.236 -> ۲۳٫۶٪),
    // 'int', 'text'. A missing value arrives as plain text, never invented.
    function stepTraceFactValue(fact) {
        const value = fact[1];
        const kind = fact[2] || 'text';
        if (value === null || value === undefined || value === '') return 'ثبت نشده';
        if (kind === 'amount') return amount(value);
        if (kind === 'date') return formatSourceTimestamp(value) || String(value);
        if (kind === 'pct') {
            const number = Number(value);
            return Number.isFinite(number)
                ? `${new Intl.NumberFormat('fa-IR', {maximumFractionDigits:1}).format(number * 100)}٪`
                : String(value);
        }
        if (kind === 'int') {
            const number = Number(value);
            return Number.isFinite(number) ? new Intl.NumberFormat('fa-IR').format(number) : String(value);
        }
        return String(value);
    }

    function renderStepTrace(trace) {
        if (!trace || !Array.isArray(trace.steps) || !trace.steps.length) return null;
        const details = document.createElement('details');
        details.className = 'molavi-product-subsection molavi-step-trace';
        const publication = trace.steps.find(step => step.key === 'publication');
        const counts = {ok:0, warn:0, bad:0, info:0};
        trace.steps.forEach(step => { if (counts[step.status] !== undefined) counts[step.status]++; });
        const summary = node('summary',
            `مراحل انتشار این کالا${publication ? ` — ${publication.status_label || ''}` : ''} · `
            + `${new Intl.NumberFormat('fa-IR').format(counts.ok)} سبز · `
            + `${new Intl.NumberFormat('fa-IR').format(counts.warn)} هشدار · `
            + `${new Intl.NumberFormat('fa-IR').format(counts.bad)} قرمز`);
        details.appendChild(summary);
        const tierClass = {ok:'green', warn:'amber', bad:'red', info:'none'};
        trace.steps.forEach(step => {
            const block = node('section', null, 'molavi-step-trace-step');
            const heading = node('h4', null, 'molavi-step-trace-heading');
            heading.appendChild(node('span', step.label || 'مرحله'));
            const badge = node('span', step.status_label || '', `molavi-risk-badge molavi-risk-${tierClass[step.status] || 'none'}`);
            heading.appendChild(badge);
            block.appendChild(heading);
            const facts = (Array.isArray(step.facts) ? step.facts : [])
                .map(fact => [fact[0], stepTraceFactValue(fact)]);
            if (facts.length) block.appendChild(reviewFacts(facts, 'molavi-review-facts molavi-step-trace-facts'));
            if (step.note) block.appendChild(node('p', step.note, 'molavi-step-trace-note'));
            details.appendChild(block);
        });
        return details;
    }

    function reviewActions(className = 'molavi-review-actions') {
        return node('div', null, className);
    }

    const unifiedProductPageQueues = {
        'molavi-ai-confirmations':'ai',
        'molavi-reference-items':'references',
        'molavi-supplier-prices':'supplier_prices',
        'molavi-sheet-conflicts':'conflicts',
        'molavi-publication-holds':'holds'
    };

    function ownerWorkflowLink(page, label, hash = '', query = {}) {
        const isReextraction = page === 'molavi-ai-confirmations'
            && String(hash || '').toLowerCase().includes('reextract');
        const referencePage = ['molavi-reference-snapshots','molavi-reference-decisions'].includes(page);
        const targetPage = unifiedProductPageQueues[page] || referencePage ? 'molavi-operations' : page;
        const path = config.pageUrls && config.pageUrls[targetPage];
        if (!path) return null;
        const link = node('a', label, 'button button-secondary');
        const url = new URL(path, window.location.href);
        if (referencePage) url.searchParams.set('view','references');
        if (unifiedProductPageQueues[page]) {
            url.searchParams.set('workbench_action', isReextraction ? 'reextract' : unifiedProductPageQueues[page]);
        }
        Object.entries(query && typeof query === 'object' ? query : {}).forEach(([key, value]) => {
            if (value !== null && value !== undefined && String(value) !== '') url.searchParams.set(key, String(value));
        });
        if (isReextraction) url.hash = 'reextract';
        else if (hash) url.hash = hash;
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
            publication_held_for_review:'ابتدا توقف انتشار این دسته را در کارت خودش رفع کنید.',
            publication_incomplete:'انتشار انجام شد اما کامل نشد؛ معمولاً یک عکس ضمیمه نشده است.',
            native_publication_failed:'انتشار به‌خاطر خطای داخلی سرور ناموفق بود.'};
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
        if (key==='owner_reopened') return 'مالک بررسی کالای منتشرشده را بازگشایی کرد.';
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
            verified:'تأیید شده', sell:'قابل فروش', not_checked:'بررسی نشده', unknown:'نامشخص',
            approved_within_margin:'تأیید در محدودهٔ قیمت',outside_margin:'اختلاف قیمت نیازمند بررسی',
            reference_missing:'مرجع در دسترس نیست',price_unavailable:'قیمت مرجع موجود نیست',
            missing:'ثبت نشده',not_required:'لازم نیست'
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
            invalid_market_price_link: 'پیوند مستقیم و امن صفحهٔ کالا را وارد کنید.',
            market_price_source_not_verified: 'قیمت این کالا و تعداد در بسته از این منبع تأیید نشد.',
            market_image_unavailable: 'ذخیرهٔ این تصویر ممکن نبود. تصویر آگهی را بررسی کنید.',
            product_message_target_changed: 'پیوند کالا تغییر کرده است. فهرست پیام‌های کالا را تازه کنید.',
            product_message_decision_pending: 'یک پیام در حال بررسی است. ابتدا تصمیم فعلی را ثبت یا پیام را کنار بگذارید.',
            use_product_message_queue: 'این پیشنهاد به کالای ثبت‌شده مربوط است. از بخش پیام‌های همان کالا آن را انتخاب کنید.',
            market_price_sources_unavailable: 'صفحهٔ فروشگاه در دسترس نیست. پیوند دیگری را بررسی کنید.',
            stale_market_price_source: 'قیمت این منبع تازه نیست. پس از بررسی دوباره آن را انتخاب کنید.',
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
            product_publication_prerequisite: 'شرایط انتشار تغییر کرده است. کارت را تازه کنید و گام فعلی را بررسی کنید.',
            backlog_reconcile_unavailable: 'تطبیق خطاهای قدیمی موقتاً انجام نشد؛ صف را تازه کنید و دوباره بررسی کنید.',
            reextraction_active: 'یک بازاستخراج دیگر هنوز در صف یا در حال اجراست؛ ابتدا وضعیت همان اجرا را پیگیری کنید.',
            reextraction_preview_changed: 'فهرست پیام‌ها تغییر کرده است؛ تعداد تازه را بررسی و دوباره شروع کنید.',
            invalid_reextraction_filter: 'ترکیب دامنه، کانال یا تاریخ معتبر نیست؛ محدودیت‌ها را اصلاح کنید.',
            no_reextraction_messages: 'پیامی برای بازاستخراج در این گروه پیدا نشد.',
            invalid_dismissal_request: 'درخواست رد گروهی معتبر نیست؛ صفحه را تازه کنید.',
            dismiss_failed: 'رد کردن همه موارد انجام نشد؛ صفحه را تازه کنید و موارد باقی‌مانده را بررسی کنید.',
            dismissal_unavailable: 'امکان ثبت فهرست شخصی کنارگذاشته‌ها در این صفحه آماده نیست.',
            dismissal_limit: 'بایگانی کنارگذاشته‌ها به سقف خود رسیده است؛ ابتدا بخشی از آن را بازگردانید.',
            invalid_records: 'فهرست کنارگذاری معتبر نبود؛ صفحه را تازه کنید و دوباره بررسی کنید.',
            ai_extraction_disabled: 'برای استفاده از استخراج فقط با هوش مصنوعی، ابتدا سرویس سرور را به‌روزرسانی کنید.',
            ai_extraction_unconfigured: 'برای استخراج کالا، کلید GOOGLE_API_KEY را در تنظیمات سرویس اجرا تنظیم کنید.',
            unknown_reextraction_run: 'اجرای بازاستخراج پیدا نشد؛ فهرست را تازه کنید.',
            reextraction_in_progress: 'مقایسه پس از پایان بازاستخراج آماده می‌شود.',
            reextraction_sources_unavailable: 'بعضی کارت‌ها به پیام اصلی متصل نیستند؛ چیزی اجرا نشد تا منبع همه موارد مشخص شود.',
            reextraction_conflict: 'صف بازاستخراج تغییر کرده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            image_extraction_disabled: 'تصویر فقط پیوست است؛ برای استخراج کالا متن یا کپشن بفرستید.',
            stale_media_review: 'این رسانه تغییر کرده یا قبلاً تعیین تکلیف شده است؛ فهرست را تازه کنید.',
            invalid_media_decision: 'درخواست تصمیم رسانه معتبر نیست؛ فهرست را تازه کنید.',
            invalid_media_page: 'صفحه رسانه‌ها معتبر نیست؛ فهرست را تازه کنید.',
            reextraction_price_not_verified: 'برای ثبت نهایی، قیمت را در منبع فعلی بررسی و گزینهٔ بررسی قیمت را علامت بزنید.',
            package_count_required: 'برای انتخاب قیمت بسته، تعداد در بسته یا کارتن باید مشخص باشد.',
            unknown_reference: 'این مرجع در نسخه فعال فهرست نیست؛ فهرست را تازه کنید.',
            online_reference_unavailable: 'کاتالوگ آنلاین رضوان پخش در دسترس نیست؛ دوباره تلاش کنید.',
            extracted_titles_unavailable: 'جزئیات استخراج این پیشنهاد در دسترس نیست.',
            invalid_search_query: 'برای جست‌وجو بین ۲ تا ۱۲۰ حرف وارد کنید.',
            invalid_discount_policy: 'درصدها باید بین صفر تا صد باشند و آستانهٔ سبد باید عدد مثبت باشد.',
            discount_update_running: 'قیمت‌های منتشرشده هنوز در حال به‌روزرسانی هستند.',
            discount_policy_unavailable: 'تنظیمات تخفیف در دسترس نیست. دوباره تلاش کنید.'
        };
        return labels[code] || 'ارتباط با سرور برقرار نشد. کد پیگیری: ' + (code || 'نامشخص');
    }

    const readCache = new Map();
    const pendingReads = new Map();
    let readGeneration = 0;
    let workflowReadQueue = Promise.resolve();

    const actionNames = {
        request_market_prices:'یافتن قیمت بازار',add_market_price_url:'بررسی پیوند فروشگاه',
        select_market_price_source:'انتخاب منبع قیمت عادی',use_market_product_image:'تغییر تصویر اصلی کالا',suggest_reference:'درخواست پیشنهاد مرجع',
        assign_ai_reference:'انتخاب مرجع',proceed_without_reference:'ادامه بدون مرجع',
        start_offer_reextraction:'بازاستخراج پیام',start_reextraction:'بازاستخراج گروهی',
        start_open_case_reextraction:'بازاستخراج موارد باز',start_recent_policy_reextraction:'بازاستخراج با قواعد فعلی',
        start_media_reextraction:'بازاستخراج رسانه',cancel_reextraction:'لغو پیام‌های باقی‌مانده',
        refresh:'تازه‌سازی فهرست مرجع',review:'ثبت بررسی فهرست',activate:'فعال‌سازی مرجع',
        pause_review:'توقف تطبیق',use_last_snapshot:'ادامه با فهرست ذخیره‌شده',capture:'ثبت وضعیت محصولات',
        confirm:'تأیید مشخصات',not_product:'رد پیشنهاد',save_discount_policy:'به‌روزرسانی تخفیف‌ها',
        reopen_products:'بازگشایی بررسی',review_publication:'بازبینی برای انتشار',request_publication:'درخواست انتشار',choose_product_message:'رسیدگی به پیام کالا',
    };
    const routeNames = {supplier_prices:'تأیید قیمت تأمین‌کننده',sheet_conflicts:'رفع تعارض',
        publication_holds:'ثبت تصمیم انتشار',telegram_operators:'ثبت تصمیم حساب ربات',
        reference_items:'ثبت تصمیم مرجع',ai_confirmations:'ثبت تصمیم مشخصات'};
    function startAction(label,payload,controller) {
        return dashboardApp?.startAction(label,payload,controller);
    }
    async function runAction(label,payload,execute,controller) {
        const action=startAction(label,payload,controller);
        try {
            const result=await execute();
            action?.finish(result);
            return result;
        } catch(error) {
            action?.fail(controller?.signal.aborted || error.name==='AbortError' ? null : errorText(error.message),
                error.remoteRejected || Boolean(controller));
            throw error;
        }
    }
    function invalidateReads() {
        readGeneration++;
        readCache.clear();
        pendingReads.clear();
    }
    async function request(method = 'GET', payload = undefined, url = '', apiKey = '', options = {}) {
        if (method !== 'GET') {
            const label=actionNames[payload?.choice] || actionNames[payload?.action] || routeNames[apiKey] || 'ثبت تغییر';
            const send=async()=>{
                const result=await workflowRequest(method,payload,url,apiKey);
                invalidateReads();
                return result;
            };
            return options.quiet ? send() : runAction(label,payload,send);
        }
        const key=apiKey+'|'+url+'|'+(options.revision||'');
        const generation=readGeneration;
        const cached=readCache.get(key);
        if (!options.force && cached?.expires>Date.now()) return cached.value;
        if (!options.label && pendingReads.has(key)) return pendingReads.get(key);
        const controller=options.label ? new AbortController() : null;
        const read=async()=>{
            // Keep legacy owner reads serial so callbacks can obtain a PHP worker.
            const previous=workflowReadQueue;
            let release;
            workflowReadQueue=new Promise(resolve=>{release=resolve;});
            await previous;
            try {
                const send=()=>workflowRequest(method,payload,url,apiKey,controller?.signal || options.signal);
                let value;
                try {value=await send();}
                catch(error) {
                    if (![502,503,504].includes(error.requestStatus)) throw error;
                    await new Promise(resolve=>setTimeout(resolve,1000));
                    value=await send();
                }
                if (generation===readGeneration && options.ttl) {
                    if (readCache.size>=96) readCache.delete(readCache.keys().next().value);
                    readCache.set(key,{value,expires:Date.now()+options.ttl});
                }
                return value;
            } finally {release();}
        };
        if (options.label) return runAction(options.label,null,read,controller);
        const pending=read();
        pendingReads.set(key,pending);
        try {return await pending;}
        finally {if(pendingReads.get(key)===pending) pendingReads.delete(key);}
    }

    async function cancelDashboardAction(watch) {
        const result=await workflowRequest('POST',watch,new URL('/pipeline/dashboard-activity',config.apiUrl).href,'');
        invalidateReads();
        return result;
    }

    async function workflowRequest(method, payload, url, apiKey, signal) {
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
            credentials:'same-origin', cache:'no-store', signal,
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
        if (method === 'POST') {
            if (payload?.action?.startsWith('start_') || ['cancel_reextraction','suggest_reference'].includes(payload?.action)) {
                dashboardApp?.refreshActivity();
            }
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'molavi-owner-workflow-updated' }, window.location.origin);
            }
        }
        return body;
    }

    function workbenchDismissalRequest(operation,payload={}) {
        return runAction(operation==='restore'?'بازگرداندن کار':'کنارگذاشتن کار',{action:operation,...payload},
            ()=>writeWorkbenchDismissal(operation,payload));
    }
    async function writeWorkbenchDismissal(operation, payload) {
        if (!config.apiProxyUrl || !config.workbenchDismissalNonce) throw new Error('dismissal_unavailable');
        const body = new URLSearchParams({
            action:'molavi_owner_workbench_dismissals', nonce:config.workbenchDismissalNonce,
            operation:String(operation)
        });
        if (Array.isArray(payload.records)) body.set('records', JSON.stringify(payload.records));
        if (Array.isArray(payload.keys)) body.set('keys', JSON.stringify(payload.keys));
        const response = await fetch(config.apiProxyUrl, {method:'POST', mode:'same-origin',
            credentials:'same-origin', cache:'no-store',
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'application/json'},
            body});
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || result.success !== true || !result.data || !Array.isArray(result.data.items)) {
            throw new Error(result && result.data && result.data.code || 'dismiss_failed');
        }
        workbenchSetDismissals(result.data.items);
        return workbenchDismissals;
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
            const saved = await runAction('ثبت سابقهٔ بررسی',null,async()=>{
                const response = await fetch(config.reviewStoreUrl, {
                    method:'POST', mode:'same-origin', credentials:'same-origin', cache:'no-store',
                    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body:new URLSearchParams({action:'molavi_owner_review_store', nonce:config.reviewStoreNonce,
                        records:JSON.stringify(batch)}).toString()
                });
                const body = await response.json().catch(() => null);
                if (!response.ok || !body || body.success !== true
                    || !body.data || body.data.stored !== batch.length) {
                    const error=new Error(body?.data?.code || 'review_store_failed');
                    error.remoteRejected=body?.success===false;
                    throw error;
                }
                return body.data;
            });
            if (saved.commands && typeof saved.commands === 'object') Object.assign(commands,saved.commands);
        }
        return {stored:records.length,commands};
    }

    function drainWordPressWorkflowOutbox() {
        return runAction('ارسال دوبارهٔ تصمیم‌های ثبت‌شده',null,deliverWordPressWorkflowOutbox);
    }
    async function deliverWordPressWorkflowOutbox() {
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
        const back = node('a', 'بازگشت به محصولات');
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

    const workbenchQueue = 'next';
    let workbenchLoadId = 0;
    let workbenchData = null;
    // Review surface (round six): the tab's risk filter survives dashboard
    // reloads (renderAutopilot is rebuilt on every dashboard() call, so
    // state kept next to it would reset after every review action).
    let autopilotRiskFilter = 'all';
    const workbenchErrors = {};
    let workbenchReconciliation = null;
    let workbenchReconciliationError = '';
    let workbenchDismissals = Array.isArray(config.workbenchDismissals) ? config.workbenchDismissals : [];
    let workbenchDismissalIndex = new Map(workbenchDismissals
        .filter(item => item && typeof item.task_key === 'string').map(item => [item.task_key,item]));

    const workbenchDismissalTaxonomy = [
        {stage:'intake', label:'دریافت و پردازش', queues:[['operations','صف پردازش Railway']]},
        {stage:'extract', label:'استخراج و بازبینی', queues:[['ai','پیشنهاد استخراج']]},
        {stage:'identity', label:'هویت و تطبیق', queues:[['source_integrity','پیوند Offer و Product'],['references','تطبیق مرجع']]},
        {stage:'evidence', label:'قیمت و شواهد', queues:[['supplier_prices','تازگی قیمت'],['conflicts','تعارض شیت'],['stock','پیگیری موجودی']]},
        {stage:'publish', label:'کاتالوگ و انتشار', queues:[['holds','توقف دسته'],['status','به‌روزرسانی محصول']]},
        {stage:'storefront', label:'فروشگاه', queues:[['storefront','قابل‌نمایش/خرید بودن']]}
    ];

    function workbenchDismissalToken(value) {
        return encodeURIComponent(String(value || '')).replace(/[!'()*~]/g, char =>
            '%' + char.charCodeAt(0).toString(16).toUpperCase());
    }

    function workbenchDismissalStage(queue) {
        return workbenchDismissalTaxonomy.find(stage => stage.queues.some(item => item[0] === queue))?.stage || '';
    }
    function workbenchMediaDismissalKey(messageKey) {
        const identity = String(messageKey || '').trim();
        if (!identity) return '';
        const key = `media_review|${workbenchDismissalToken(identity)}`;
        return key.length <= 520 ? key : '';
    }
    function workbenchOperationDismissalTask(item) {
        const id = String(item && item.id || '').trim();
        if (!id) return null;
        const kind = String(item && item.kind || 'work').trim() || 'work';
        const taskKey = `operations|${workbenchDismissalToken(`${kind}:${id}`)}`;
        if (taskKey.length > 520) return null;
        const label = kind === 'batch' ? 'صف پردازش · دسته'
            : kind === 'publication' ? 'صف پردازش · انتشار' : 'صف پردازش · کار';
        return {task_key:taskKey,queue:'operations',stage:'intake',label};
    }

    function workbenchOperationIsDismissed(item) {
        return workbenchDismissalIndex.has(workbenchOperationDismissalTask(item)?.task_key);
    }

    function workbenchAiCaseIsDismissed(item) {
        return workbenchDismissalIndex.has(`ai|case:${item.case_id}`);
    }
    const workbenchDismissalReasons = {
        duplicate:'تکراری در این صف', no_action:'اقدامی از این صف لازم نیست',
        outside_scope:'فعلاً خارج از دامنه رسیدگی', later:'برای پیگیری بعدی کنار گذاشته شود',
        other:'دلیل دیگر'
    };

    function workbenchSetDismissals(items) {
        workbenchDismissals = Array.isArray(items) ? items : [];
        workbenchDismissalIndex = new Map(workbenchDismissals
            .filter(item => item && typeof item.task_key === 'string').map(item => [item.task_key, item]));
    }

    function workbenchNameKey(name) {
        return String(name || '').normalize('NFKC').toLocaleLowerCase()
            .replace(/[\u200c\u200d\s،,؛;:()[\]{}\-_]+/g, ' ').trim();
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

    function workbenchSourceRecords(product) {
        return [...(product.ai || []), ...(product.references || []), ...(product.supplierPrices || [])];
    }

    function workbenchSourceRecord(product) {
        return workbenchSourceRecords(product).find(item => item && (item.message_id || item.source_post_url
            || item.channel || item.channel_username)) || {};
    }

    function workbenchDismissalKey(product, queue) {
        const productId = String(product.product_id || product.status && product.status.product_id || '').trim();
        let identity = productId ? `product:${productId}` : '';
        if (!identity) {
            const groups = {
                source_integrity:[product.status], ai:product.ai, reextract:product.ai,
                references:product.references, supplier_prices:product.supplierPrices,
                conflicts:product.conflicts, holds:product.holds, status:[product.status],
                storefront:[product.status]
            };
            const records = Array.isArray(groups[queue]) ? groups[queue] : [];
            const ids = records.map(item => String(item && (item.case_id || item.conflict_id || item.review_id
                || item.hold_id || item.latest_offer_id || item.offer_id || item.id) || '').trim())
                .filter(Boolean).sort();
            if (ids.length) identity = `${queue}:${ids.join(',')}`;
        }
        if (!identity) {
            const source = workbenchSourceRecord(product);
            const messageId = String(source.message_id || source.messageId || '').trim();
            const channel = String(source.channel || source.channel_username || product.channel || '').trim();
            if (messageId && channel) identity = `message:${channel}:${messageId}`;
        }
        if (!identity) return '';
        const key = `${queue}|${workbenchDismissalToken(identity)}`;
        return key.length <= 520 ? key : '';
    }

    function workbenchIsDismissed(product, queue) {
        if (queue==='ai' && product.ai.some(item=>!item.publication_held)
            && product.ai.filter(item=>!item.publication_held).every(workbenchAiCaseIsDismissed)) return true;
        if (queue==='ai' && product.ai.some(item=>item.reopened)) {
            return workbenchDismissalIndex.has(workbenchCurrentDismissalKey(product,queue));
        }
        const key = workbenchDismissalKey(product, queue);
        return Boolean(key && (workbenchDismissalIndex.has(key) || workbenchDismissalIndex.has(workbenchCurrentDismissalKey(product,queue))));
    }

    function workbenchCurrentDismissalKey(product,queue) {
        const base=workbenchDismissalKey(product,queue);
        if (!base) return '';
        const evidence={
            ai:()=>product.ai.map(item=>item.case_id),
            references:()=>product.references.map(item=>item.signature || item.case_id),
            supplier_prices:()=>product.supplierPrices.map(item=>item.source_revision || item.offer_id),
            conflicts:()=>product.conflicts.map(item=>item.conflict_id),
            holds:()=>product.holds.map(item=>item.hold_id)
        };
        const revisions=[...(evidence[queue]?.() || []),product.status?.latest_offer_id].filter(Boolean).sort();
        if (!revisions.length) return base;
        const key=`${base}:${workbenchDismissalToken(revisions.join(','))}`;
        return key.length<=queue.length+481 ? key : base;
    }

    function workbenchSourceMessageKey(product) {
        const source = workbenchSourceRecord(product);
        return aiSourceMessageKey(source);
    }

    function workbenchReceiptForProduct(product) {
        if (!workbenchReconciliation || !Array.isArray(workbenchReconciliation.receipts)) return null;
        const sourceKey = workbenchSourceMessageKey(product);
        const source = workbenchSourceRecord(product);
        const productId = String(product.product_id || '').trim();
        const latestOfferId = String(product.status && product.status.latest_offer_id || '').trim();
        const matches = workbenchReconciliation.receipts.filter(receipt => {
            const details = Array.isArray(receipt.product_details) ? receipt.product_details : [];
            const sourceChannel = String(source.source_channel || '').trim().toLowerCase();
            const receiptSourceChannel = String(receipt.source_channel || '').trim().toLowerCase();
            const sourceBatch = String(source.batch_id || '').trim();
            const receiptBatch = String(receipt.batch_id || '').trim();
            const exactSource = source.message_id && String(receipt.message_id || '') === String(source.message_id)
                && String(receipt.channel || '').toLowerCase() === String(source.channel || source.channel_username || '').toLowerCase()
                && (!sourceChannel || !receiptSourceChannel || sourceChannel === receiptSourceChannel)
                && (!sourceBatch || !receiptBatch || sourceBatch === receiptBatch);
            return (productId && details.some(item => String(item.product_id || '') === productId))
                || (latestOfferId && details.some(item => String(item.latest_offer_id || '') === latestOfferId))
                || (sourceKey && aiSourceMessageKey(receipt) === sourceKey)
                || exactSource;
        });
        if (!matches.length) return null;
        return matches.sort((a,b) => sourceTimestampMillis(b.received_at) - sourceTimestampMillis(a.received_at))[0];
    }

    function workbenchWooState(product) {
        const productId = String(product.product_id || product.status && product.status.product_id || '').trim();
        const productStates = workbenchReconciliation && Array.isArray(workbenchReconciliation.product_states)
            ? workbenchReconciliation.product_states : [];
        const stateRow = productId && productStates.find(item => String(item.product_id || '') === productId);
        if (stateRow) return String(stateRow.woo_state || 'not_checked');
        const receipt = workbenchReceiptForProduct(product);
        const details = receipt && Array.isArray(receipt.product_details) ? receipt.product_details : [];
        const detail = productId
            ? details.find(item => String(item.product_id || '') === productId)
            : (details.length === 1 ? details[0] : null);
        if (detail) return String(detail.woo_state || 'not_checked');
        if (workbenchReconciliation) return workbenchReconciliation.product_states_truncated
            ? 'not_checked_catalog_truncated' : 'not_checked';
        return '';
    }

    function workbenchWooLabel(state) {
        const labels = {
            purchasable:'در فروشگاه و قابل‌خرید', held:'در فروشگاه متوقف و غیرقابل‌خرید',
            not_visible:'در کاتالوگ عمومی دیده نمی‌شود', not_purchasable:'نمایش دارد اما قابل‌خرید نیست',
            supplier_out_of_stock:'ناموجود طبق وضعیت منبع',
            held_but_purchasable:'توقف ثبت شده اما هنوز قابل‌خرید است',
            sku_mismatch:'کالا پیدا شد؛ SKU با شناسهٔ منبع فرق دارد',
            ambiguous_product_id:'شناسهٔ کالا در چند ردیف تکرار شده',
            no_catalog_projection:'پروژکشن کاتالوگ موجود نیست',
            not_checked:'هنوز با فروشگاه تطبیق نشده',
            not_checked_catalog_truncated:'گزارش فروشگاه محدود مانده'
        };
        return labels[String(state || '')] || 'وضعیت فروشگاه ثبت نشده';
    }

    const workbenchActionOrder = ['holds','product_messages','source_integrity','ai','references','conflicts','stock',
        'supplier_prices','market_prices','status','storefront','deferred','next'];
    const workbenchActionSections = {
        holds:'publication_holds',ai:'ai_confirmations',references:'reference_items',
        conflicts:'sheet_conflicts',supplier_prices:'supplier_prices',product_messages:'ai_confirmations',
        market_prices:'product_status',status:'product_status'
    };

    function workbenchSupplierSource(product, offer) {
        return safeSourceLink(offer.source_post_url)
            || safeSourceLink(product.status?.source_post_url, 'باز کردن آخرین پیام ثبت‌شدهٔ کالا');
    }

    function workbenchReadyAction(product,key=workbenchActionKey(product)) {
        const source=workbenchActionSections[key];
        if (!source || workbenchErrors[source] || !config.reviewStoreUrl || !config.reviewStoreNonce) return '';
        if (key==='holds' && !product.holds.some(hold => hold.context?.messages?.length)) return '';
        if (key==='ai' && !product.ai.some(item => !item.publication_held && !workbenchAiCaseIsDismissed(item))) return '';
        if (key==='status'&&(!product.product_id||!product.status?.latest_offer_id)) return '';
        if (key==='supplier_prices' && !product.supplierPrices.some(offer =>
            !offer.acknowledged && offer.source_revision && workbenchSupplierSource(product,offer))
            && !product.status?.latest_offer_id) return '';
        if (key==='market_prices'&&!product.status?.latest_offer_id) return '';
        return key;
    }

    function workbenchPendingAction(product) {
        if (!product.holds.length&&product.ai.some(item=>item.message_review&&!item.publication_held&&!workbenchAiCaseIsDismissed(item)))
            return 'ai';
        return workbenchActionOrder.find(key=>workbenchQueueMatches(product,key,true))||'next';
    }

    function workbenchActionKey(product) {
        const current=workbenchPendingAction(product);
        return current!=='next'&&workbenchIsDismissed(product,current)?'deferred':current;
    }

    function workbenchRestoreKeys(product) {
        const key=workbenchPendingAction(product);
        const candidates=[workbenchDismissalKey(product,key),workbenchCurrentDismissalKey(product,key)];
        if (key==='ai') candidates.push(...product.ai.filter(caseRow=>!caseRow.publication_held).map(caseRow=>`ai|case:${caseRow.case_id}`));
        return [...new Set(candidates)].filter(taskKey=>workbenchDismissalIndex.has(taskKey));
    }

    const workbenchStageModel = [
        {key:'intake',label:'دریافت و صف',hint:'پیام ← رسید Railway ← کارگر',queues:[],view:'operations'},
        {key:'extract',label:'استخراج و تصمیم مالک',hint:'متن، چندتصویری، Offer و بازبینی',queues:['ai']},
        {key:'identity',label:'هویت و تطبیق',hint:'Offer منبع ← Product و مرجع',queues:['source_integrity','references']},
        {key:'evidence',label:'قیمت و همگام‌سازی داده',hint:'تازگی قیمت، تعارض و موجودی',queues:['supplier_prices','market_prices','conflicts','stock']},
        {key:'publish',label:'انتشار کاتالوگ',hint:'توقف‌ها، رسید انتشار و به‌روزرسانی',queues:['holds','status']},
        {key:'storefront',label:'قفسهٔ فروشگاه',hint:'قابل‌مشاهده و قابل‌خرید بودن در WooCommerce',queues:[],view:'storefront'}
    ];

    function workbenchQueueMatches(product, key, includeDeferred = false) {
        let matched = false;
        if (key === 'source_integrity') matched = Boolean(product.status
            && Array.isArray(product.status.source_integrity_issues)
            && product.status.source_integrity_issues.length);
        if (key === 'ai') matched = !product.holds.length && product.ai.some(item => !item.publication_held);
        if (key === 'product_messages') matched = Boolean(product.status?.message_count&&!product.ai.some(item=>item.message_review));
        if (key === 'references') matched = product.references.length > 0;
        if (key === 'conflicts') matched = product.conflicts.length > 0;
        if (key === 'stock') matched = Boolean(product.status?.supplier_out_of_stock);
        if (key === 'supplier_prices') matched = product.status?.sale_offer_blocker==='supplier_price'
            ||(!product.status?.sale_offer_blocker&&workbenchPendingSupplierPrices(product)>0);
        if (key === 'market_prices') matched = product.status?.sale_offer_blocker==='regular_price';
        if (key === 'holds') matched = product.holds.length > 0;
        if (key === 'status') matched = Boolean(product.status && product.status.status!=='not_product'
            && (product.status.update_needed||product.status.publication_needed||product.status.regular_price_missing||['no_spread','thin_spread'].includes(product.status.sale_offer_blocker)));
        if (key === 'storefront') matched = ['not_visible','held_but_purchasable','ambiguous_product_id',
            'no_catalog_projection','not_checked','not_checked_catalog_truncated','sku_mismatch','not_purchasable'].includes(workbenchWooState(product));
        return matched && (includeDeferred || !workbenchIsDismissed(product, key));
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
        if (key==='product_messages') return 'پیام‌های رسیده برای این کالا را مقایسه کنید و یک پیام را برای بررسی انتخاب کنید.';
        if (key === 'source_integrity') return 'انتشار متوقف است؛ پیام اصلی یا پیشنهاد تازهٔ همین کالا را به ربات بفرستید.';
        if (key === 'ai') {
            const cases = product.ai.filter(item=>!workbenchAiCaseIsDismissed(item));
            if (cases.some(item => item.publication_held)) return 'جزئیات و اقدام دستهٔ متوقف‌شده را باز کنید؛ تصمیم این استخراج تا رفع همان توقف قفل است.';
            if (cases.some(item => item.reopened)) return 'قیمت، تعداد و موجودی کالا را بررسی و تصمیم تازه را ثبت کنید.';
            if (cases.some(item => !normalizedAiInteger(item.sale_price || item.detected_price || item.package_price))) {
                return 'قیمت تازه را از تأمین‌کننده بگیرید و در فرم اصلاح مشخصات وارد کنید.';
            }
            if (cases.some(item => item.source_current_check_required || item.historical_reextract)) return 'زمان منبع به آستانهٔ ۱۴ روز رسیده یا قابل اتکا نیست؛ قیمت و موجودی را از منبع فعلی بررسی کنید.';
            return 'مشخصات استخراج‌شده را با پیام منبع بررسی کنید.';
        }
        if (key === 'references') return 'مرجع درست و مبنای قیمت را انتخاب کنید.';
        if (key === 'conflicts') return 'مقدار سرور را نگه دارید؛ فقط اگر خودتان شیت را دستی تغییر داده‌اید، اصلاح شیت را به فروشگاه بفرستید.';
        if (key === 'stock') return 'پس از اعلام موجودی، پیام تازهٔ تأمین‌کننده را به ربات بفرستید.';
        if (key === 'supplier_prices') return workbenchReadyAction(product) === key
            ? 'قیمت تأمین‌کننده را با پیشنهاد فعلی بررسی و تأیید کنید.'
            : 'برای تأیید، پیام فعلی تأمین‌کننده و نسخهٔ معتبر قیمت لازم است.';
        if (key === 'market_prices') return ({missing:'قیمت عادی را از یک منبع به‌روز پیدا و تأیید کنید.',
            expired:'اعتبار قیمت عادی پایان یافته است. منبع قیمت را به‌روزرسانی کنید.',
            unverified:'قیمت عادی ثبت‌شده به مدرک به‌روز نیاز دارد.'})[product.status?.regular_price_state]||'قیمت عادی را بررسی کنید.';
        if (key === 'holds') return 'جزئیات پیام‌های همان دسته را باز کنید و یک‌بار اقدام بازیابی را اجرا کنید.';
        if (key === 'deferred') return 'گام فعلی کنار گذاشته شده است؛ برای ادامه آن را در وضعیت سیستم بازگردانید.';
        if (key === 'status'&&product.status?.sale_offer_blocker==='thin_spread') return 'اختلاف قیمت تأمین‌کننده و قیمت عادی برای تخفیف کافی نیست؛ قیمت‌ها را بازبینی کنید.';
        if (key === 'status'&&product.status?.sale_offer_blocker==='no_spread') return 'برای عرضه، قیمت عادی باید از قیمت تأمین‌کننده بیشتر باشد.';
        if (key === 'status'&&product.status?.publication_needed) return 'قیمت‌ها و مشخصات تأیید شده‌اند. انتشار را به صف بفرستید.';
        if (key === 'status'&&(!product.product_id||!product.status?.latest_offer_id)) return 'برای ادامه، پیام اصلی یا پیام تازهٔ همین کالا را پیدا کنید.';
        if (key === 'status') return product.status?.review_reason
            ? aiReviewReason(product.status.review_reason)
            : 'مشخصات و قیمت‌های فعلی را بازبینی کنید تا درخواست انتشار بررسی شود.';
        if (key === 'storefront') return 'رسید انتشار را با کاتالوگ عمومی ووکامرس تطبیق کنید؛ تا گزارش کامل نباشد وضعیت فروشگاه قطعی نیست.';
        return 'برای این کالا اقدام بازی ثبت نشده است.';
    }

    function workbenchTaskMeta(product, key) {
        const status = product.status || {};
        const issueCounts = {
            source_integrity:Array.isArray(status.source_integrity_issues) ? status.source_integrity_issues.length : 0,
            ai:Array.isArray(product.ai) ? product.ai.filter(item => !item.publication_held).length : 0,
            references:Array.isArray(product.references) ? product.references.length : 0,
            conflicts:Array.isArray(product.conflicts) ? product.conflicts.length : 0,
            supplier_prices:workbenchPendingSupplierPrices(product),
            stock:product.status?.supplier_out_of_stock ? 1 : 0,
            holds:Array.isArray(product.holds) ? product.holds.length : 0,
            status:status.update_needed ? 1 : 0,
            storefront:workbenchQueueMatches(product,'storefront') ? 1 : 0
        };
        const count = issueCounts[key] || 0;
        const number = new Intl.NumberFormat('fa-IR').format(count);
        const labels = {
            source_integrity:'پیوند منبع', ai:'استخراج محصول', references:'تطبیق مرجع',
            supplier_prices:'قیمت تأمین‌کننده', conflicts:'تعارض همگام‌سازی',
            stock:'پیگیری موجودی',
            holds:'توقف انتشار', status:'وضعیت کالا', storefront:'تطبیق فروشگاه', next:'کار مرتبط'
        };
        const titles = {
            source_integrity:'بازیابی پیوند پیشنهاد منبع',
            ai:`بازبینی ${number} مورد استخراج‌شده`,
            references:`تطبیق ${number} مورد با مرجع`,
            supplier_prices:`بررسی تازگی ${number} قیمت`,
            conflicts:`رسیدگی به ${number} تعارض همگام‌سازی`,
            stock:'پیگیری موجودی تأمین‌کننده',
            holds:`بررسی ${number} توقف انتشار`,
            status:'بررسی وضعیت و انتشار کالا',
            storefront:'تطبیق رسید با وضعیت WooCommerce',
            next:'کار باز دیگری ثبت نشده است'
        };
        const stage = workbenchStageModel.find(item => item.queues.includes(key)
            || (key === 'storefront' && item.key === 'storefront'));
        return {
            label:labels[key] || labels.next,
            stage:stage ? stage.label : 'سایر موارد',
            stageKey:stage ? stage.key : 'other',
            title:titles[key] || titles.next,
            countLabel:count ? `${number} مورد باز` : 'در انتظار بررسی'
        };
    }

    let dashboardApp = null;
    function renderReferenceCatalog(panel) {
        const catalog=node('div',null,'molavi-reference-panel');
        panel.appendChild(catalog);
        const url=new URL('/reference/snapshots',config.apiUrl);
        window.MolaviReferenceCatalog.mount(catalog,{
            apiUrl:url.href,apiProxyUrl:config.apiProxyUrl,apiProxyNonce:config.apiProxyNonce,
            liveActivity:Boolean(config.dashboardActivity),
            dashboardRequest:(method,payload,target,options)=>request(method,payload,target||url.href,'',options),
            onUpdated:()=>dashboard('','info',['reference_items','product_status','reference_decisions','operations'])
        });
    }

    // The autopilot settings form lives in the settings view (gear icon),
    // not in the autopilot tab: the tab shows only the decisions list, the
    // holds box and a read-only master-state line. This builds the settings
    // section for renderDiscountPolicy; the form, field values, validation
    // and the save_settings POST are unchanged from the tab version, and
    // after saving dashboard() refreshes so the tab's master-state line
    // updates. It reads workbenchData (module level), not dashboard()'s
    // local data copy, because it runs from the pricing view.
    function renderAutopilotSettingsSection() {
        const section = document.createElement('section');
        section.className = 'card molavi-product-subsection';
        section.appendChild(node('h2','تنظیمات خلبان خودکار'));
        const autopilot = workbenchData?.autopilot || null;
        if (!autopilot || !autopilot.ok) {
            section.appendChild(notice(workbenchErrors.autopilot || 'تنظیمات خلبان خودکار در دسترس نیست.','warning'));
            return section;
        }
        const settings = autopilot.settings || {};
        const ruleLabels = {g5:'درخواست انتشار',g4:'انتخاب نقل‌قول بازار',g3:'تأیید قیمت تأمین‌کننده',gA:'پذیرش کالا'};
        section.appendChild(node('p', settings.master_enabled
            ? 'خلبان خودکار روشن است؛ قانون‌هایی که حالت «فعال» دارند اقدام می‌کنند.'
            : 'خلبان خودکار خاموش است؛ همهٔ قانون‌ها فقط در حالت سایه محاسبه و ثبت می‌شوند.'));
        const form = document.createElement('div');
        form.className = 'molavi-autopilot-settings';
        const fields = document.createElement('div');
        fields.className = 'md-pricing-fields';
        const settingsGroup = legendText => {
            const fieldset = document.createElement('fieldset');
            fieldset.appendChild(node('legend', legendText));
            fields.appendChild(fieldset);
            return fieldset;
        };
        const percentField = (group,key,label,hintText) => {
            const row = document.createElement('label');
            row.appendChild(node('span',label));
            const input = document.createElement('input');
            input.type='number'; input.step='0.1'; input.min='0'; input.max='50';
            input.value = String(Math.round(Number(settings[key] ?? 0) * 1000) / 10);
            input.dataset.key = key;
            row.appendChild(input);
            if (hintText) row.appendChild(node('small',hintText));
            group.appendChild(row);
        };
        const intField = (group,key,label,hintText) => {
            const row = document.createElement('label');
            row.appendChild(node('span',label));
            const input = document.createElement('input');
            input.type='number'; input.step='1'; input.min='1';
            input.value = String(settings[key] ?? '');
            input.dataset.key = key;
            row.appendChild(input);
            if (hintText) row.appendChild(node('small',hintText));
            group.appendChild(row);
        };
        const thresholdsGroup = settingsGroup('آستانه‌های تصمیم');
        percentField(thresholdsGroup,'quote_band','بازهٔ هم‌خوانی نقل‌قول‌ها (٪)','نقل‌قول‌ها باید تا این حد به هم نزدیک باشند تا یکی خودکار انتخاب شود.');
        percentField(thresholdsGroup,'ai_price_tolerance','تلرانس قیمت مشخصات (٪)','بیشترین اختلاف مجاز قیمت ثبت‌شده در مشخصات کالا.');
        percentField(thresholdsGroup,'supplier_price_tolerance','تلرانس قیمت تأمین‌کننده (٪)','بیشترین اختلاف مجاز قیمت تأمین‌کننده با قیمت تأییدشده.');
        percentField(thresholdsGroup,'reference_margin','حاشیهٔ تطبیق با مرجع (٪)','حاشیهٔ لازم برای اینکه قیمت با مرجع هم‌خوان دانسته شود.');
        const safetyGroup = settingsGroup('ترمزهای ایمنی');
        intField(safetyGroup,'cap_per_gate_per_day','سقف اقدام روزانهٔ هر دروازه','در یک روز، هر دروازه بیشتر از این اقدام نمی‌کند.');
        intField(safetyGroup,'admission_cap_per_day','سقف پذیرش روزانه','در یک روز، حداکثر چند کالا به‌صورت خودکار پذیرفته شود.');
        intField(safetyGroup,'consecutive_auto_limit','سقف تأیید پیاپی خودکار','بعد از این تعداد تأیید پیاپی، مورد بعدی به شما برمی‌گردد.');
        intField(safetyGroup,'precedent_mechanical','پیشینهٔ لازم برای قانون مکانیکی','چند تصمیم گذشته برای قانون‌های ساده دیده شود.');
        intField(safetyGroup,'precedent_judgement','پیشینهٔ لازم برای قانون داوری','چند تصمیم گذشته برای قانون‌های داوری دیده شود.');
        const modeGroup = settingsGroup('حالت کلی');
        const masterRow = document.createElement('label');
        const masterInput = document.createElement('input');
        masterInput.type='checkbox'; masterInput.checked=!!settings.master_enabled; masterInput.dataset.key='master_enabled';
        masterRow.appendChild(masterInput); masterRow.appendChild(node('span','خلبان خودکار روشن')); masterRow.appendChild(node('small','روشن یعنی قانون‌های «فعال» واقعاً اقدام می‌کنند.'));
        modeGroup.appendChild(masterRow);
        const shadowRow = document.createElement('label');
        const shadowInput = document.createElement('input');
        shadowInput.type='checkbox'; shadowInput.checked=settings.shadow_enabled !== false; shadowInput.dataset.key='shadow_enabled';
        shadowRow.appendChild(shadowInput); shadowRow.appendChild(node('span','ثبت حالت سایه')); shadowRow.appendChild(node('small','تصمیم‌های سایه محاسبه و ثبت می‌شوند، ولی انجام نمی‌شوند.'));
        modeGroup.appendChild(shadowRow);
        const samplingRow = document.createElement('label');
        samplingRow.appendChild(node('span','نمونه‌برداری داوری'));
        const sampling = document.createElement('select');
        sampling.dataset.key='verdict_sampling';
        for (const [value,label] of [['all','همه'],['sampled','نمونه‌ای'],['off','خاموش']]) {
            const option = document.createElement('option');
            option.value=value; option.textContent=label;
            if (settings.verdict_sampling===value) option.selected=true;
            sampling.appendChild(option);
        }
        samplingRow.appendChild(sampling);
        samplingRow.appendChild(node('small','داوری هوش مصنوعی روی همهٔ تصمیم‌ها انجام شود یا نمونه‌ای از آن‌ها.'));
        modeGroup.appendChild(samplingRow);
        const rulesGroup = settingsGroup('حالت هر قانون');
        rulesGroup.appendChild(node('p','سایه یعنی فقط محاسبه و ثبت؛ فعال یعنی اقدام می‌کند.','description'));
        (settings.rule_modes ? Object.keys(settings.rule_modes) : []).forEach(gate => {
            const row = document.createElement('label');
            row.appendChild(node('span',`حالت قانون ${ruleLabels[gate] || gate}`));
            const select = document.createElement('select');
            select.dataset.rule = gate;
            for (const [value,label] of [['shadow','سایه'],['live','فعال']]) {
                const option = document.createElement('option');
                option.value=value; option.textContent=label;
                if (settings.rule_modes[gate]===value) option.selected=true;
                select.appendChild(option);
            }
            row.appendChild(select);
            rulesGroup.appendChild(row);
        });
        form.appendChild(fields);
        const saveStatus = node('p','');
        const saveButton = node('button','ذخیرهٔ تنظیمات','button button-primary');
        saveButton.type='button';
        saveButton.addEventListener('click', async () => {
            const next = {};
            form.querySelectorAll('input[data-key]').forEach(input => {
                const key = input.dataset.key;
                if (input.type==='checkbox') { next[key]=input.checked; return; }
                const value = Number(input.value);
                if (!Number.isFinite(value)) return;
                next[key]=['quote_band','ai_price_tolerance','supplier_price_tolerance','reference_margin'].includes(key)
                    ? value/100 : Math.round(value);
            });
            form.querySelectorAll('select[data-key]').forEach(select => { next[select.dataset.key]=select.value; });
            const modes = {};
            form.querySelectorAll('select[data-rule]').forEach(select => { modes[select.dataset.rule]=select.value; });
            next.rule_modes = modes;
            saveButton.disabled=true; saveStatus.textContent='در حال ذخیره…';
            try {
                await request('POST',{action:'save_settings',settings:next},undefined,'autopilot');
                await dashboard('تنظیمات خلبان خودکار ذخیره شد.','success',['autopilot']);
            } catch(error) { saveStatus.textContent=errorText(error.message); saveButton.disabled=false; }
        });
        form.appendChild(saveButton);
        form.appendChild(saveStatus);
        section.appendChild(form);
        return section;
    }

    async function renderDiscountPolicy(panel) {
        const endpoint=new URL('/catalog/discount-policy',config.apiUrl).href;
        const feedback=node('p','در حال دریافت تنظیمات…');
        feedback.setAttribute('role','status');
        panel.replaceChildren(feedback);
        try {
            const result=await request('GET',undefined,endpoint);
            const form=node('form',null,'md-pricing-form');
            const fields=node('div',null,'md-pricing-fields');
            const save=node('button','ذخیره و به‌روزرسانی قیمت‌ها','button button-primary');
            save.type='submit';
            for (const [buyer,label] of [['household','خانوار'],['shop','فروشگاه']]) {
                const group=node('fieldset');
                group.append(node('legend',label));
                for (const [suffix,title,min,max,step,value] of [
                    ['bp','سقف تخفیف از اختلاف قیمت خرید و مرجع (٪)',0,100,0.01,result.rules[buyer+'_bp']/100],
                    ['target','ارزش سبد برای رسیدن به سقف تخفیف (تومان)',1,1000000000000,1,result.rules[buyer+'_target']]
                ]) {
                    const labelNode=node('label',title);
                    const input=node('input');
                    Object.assign(input,{type:'number',name:buyer+'_'+suffix,min,max,step,value,required:true,dir:'ltr'});
                    input.inputMode=suffix==='bp'?'decimal':'numeric';
                    labelNode.append(input);
                    group.append(labelNode);
                }
                fields.append(group);
            }
            form.append(fields,save,feedback);
            feedback.textContent='';
            form.addEventListener('submit',async(event)=>{
                event.preventDefault();
                const payload={action:'save_discount_policy'};
                for (const [key,value] of new FormData(form)) {
                    payload[key]=key.endsWith('_bp')?Math.round(Number(value)*100):Number(value);
                }
                save.disabled=true;
                feedback.textContent='';
                try {
                    const response=await request('POST',payload,endpoint);
                    feedback.textContent=response.state==='unchanged'?'تنظیمات فعلی ثبت شده است.':'تنظیمات ذخیره شد.';
                } catch(error) {
                    feedback.textContent=errorText(error.message);
                } finally {save.disabled=false;}
            });
            // The settings view holds both settings sections: discount
            // settings first, then the autopilot settings (moved here from
            // the autopilot tab). The discount form itself is unchanged.
            panel.replaceChildren(node('h2','تنظیمات تخفیف'),form,renderAutopilotSettingsSection());
        } catch(error) {
            const retry=node('button','دریافت دوباره','button');
            retry.type='button';
            retry.addEventListener('click',()=>renderDiscountPolicy(panel));
            feedback.textContent=errorText(error.message);
            panel.append(retry,renderAutopilotSettingsSection());
        }
    }

    async function readOfferHistory(offerId) {
        const url=new URL('/pipeline/ai-confirmations',config.apiUrl);
        url.searchParams.set('view','workbench-offer');
        url.searchParams.set('offer_id',offerId);
        const result=await request('GET',undefined,url.href,'ai_confirmations',{ttl:15000});
        const current=result.cases?.find(row=>row.offer_id===offerId);
        if(current && new URL(location.href).searchParams.get('offer_id')===offerId && workbenchData?.ai_confirmations) {
            const source=workbenchData.ai_confirmations;
            if (!source.cases.some(row=>row.offer_id===offerId)) {
                source.cases=[...source.cases,current];
                await dashboard('','info',[]);
            }
            return null;
        }
        return result;
    }

    function supplierMessagePanel(offerId, record) {
        const panel=node('section');
        const render=message=>{
            const link=safeSourceLink(message.url);
            const timestamp=formatSourceTimestamp(message.timestamp);
            panel.replaceChildren();
            if (timestamp) panel.append(reviewFacts([['تاریخ پیام',timestamp]]));
            if (link) panel.append(link);
            if (message.text) panel.append(node('pre',message.text));
            if (!link&&!message.text) panel.append(node('p','متن یا پیوند پیام ذخیره نشده است.'));
        };
        const load=async()=>{
            panel.textContent='در حال دریافت پیام تأمین‌کننده…';
            try {
                const reply=await readOfferHistory(offerId);
                render({url:record.url,...reply.source_message});
            } catch (error) {
                render(record);
                const retry=node('button','دریافت دوبارهٔ پیام','button');retry.type='button';
                retry.addEventListener('click',load);
                panel.append(notice(errorText(error.message),'error'),retry);
            }
        };
        if (record.text||!offerId) render(record);else load();
        return panel;
    }

    async function dashboard(message = '', messageKind = 'info', refreshKeys = null, force = false, signal) {
        let warmStart = false;
        const cacheKey=config.ownerCacheKey && 'molavi.dashboard.'+config.ownerCacheKey;
        if (!window.MolaviDashboard) {
            root.replaceChildren(notice('نمای مدیریت بارگذاری نشد؛ صفحه را تازه کنید.', 'error'));
            return;
        }
        if (!dashboardApp) {
            dashboardApp = window.MolaviDashboard.create(root, {
                refresh:()=>dashboard(),
                manualRefresh:()=>{
                    const controller=new AbortController();
                    return runAction('تازه‌سازی داشبورد',null,
                        ()=>dashboard('','info',null,true,controller.signal),controller).catch(()=>{});
                },
                actionStore:config.ownerCacheKey?.split(':')[0],
                renderReferences:renderReferenceCatalog,
                renderPricing:renderDiscountPolicy,
                renderSources:sourceReextractionWorkspace,
                readOfferHistory,
                renderOfferFacts:offer=>reviewFacts(referenceExtractionRows(offer)),
                renderOfferApproval:fields=>reviewFacts([
                    ['قیمت فروش تأییدشده',amount(fields.sale_price)],
                    ['مبنای قیمت تأییدشده',fields.price_role==='package'?'بسته / کارتن':'هر واحد'],
                    ['قیمت مصرف‌کننده',amount(fields.actual_price)],
                    ['تعداد در بسته',fields.package_count],
                ]),
                cancelAction:cancelDashboardAction,
                readActivity: config.dashboardActivity
                    ? watches => {
                        const url=new URL('/pipeline/dashboard-activity',config.apiUrl);
                        if(watches.length) url.searchParams.set('watch',JSON.stringify(watches));
                        return workflowRequest('GET',undefined,url.href,'');
                    }
                    : null,
                renderMessages:productMessagePanel,
                reopen: async (items, recovery=false) => {
                    const result=await request('POST',{action:recovery?'review_publication':'reopen_products',items},undefined,'ai_confirmations');
                    if (result.opened||result.already_open) await dashboard('', 'info', ['ai_confirmations','product_status']);
                    return result;
                },
                dismiss: async (records, reason, note) => {
                    for (let offset=0;offset<records.length;offset+=1000) {
                        await workbenchDismissalRequest('dismiss', {records:records.slice(offset,offset+1000).map(item => ({
                            ...item, reason:reason, scope:'selected', note:note || ''
                        }))});
                    }
                    await dashboard('', 'info', []);
                },
                restore: async keys => {
                    await workbenchDismissalRequest('restore', {keys});
                    await dashboard('کار به فهرست برگشت.', 'success', []);
                },
                dismissReasons: workbenchDismissalReasons,
                pageUrls: config.pageUrls || {}
            });
        }
        if (!workbenchData && cacheKey && !force && !refreshKeys) {
            try {
                const saved=JSON.parse(sessionStorage.getItem(cacheKey));
                if (saved && Date.now()-saved.at<300000 && saved.data?.ai_confirmations) {
                    workbenchData=saved.data;
                    refreshKeys=[];
                    warmStart=true;
                }
            } catch (_) {}
        }
        const thisLoad = ++workbenchLoadId;
        dashboardApp.loading(!warmStart, message, messageKind);
        const labels = {
            product_status:'محصولات', ai_confirmations:'استخراج', reference_items:'مرجع کالا',
            supplier_prices:'قیمت تأمین‌کننده', supplier_link_checks:'بررسی لینک تأمین‌کننده', market_agreement:'توافق بازار', sheet_conflicts:'همگام‌سازی',
            publication_holds:'انتشار', reference_decisions:'اعتبار مرجع', operations:'پردازش',
            autopilot:'تصمیم‌های خودکار'
        };
        const keys = ['product_status','ai_confirmations','reference_items','supplier_prices',
            'supplier_link_checks','market_agreement','sheet_conflicts','publication_holds','reference_decisions'];
        const allKeys = [...keys, 'operations', 'autopilot'];
        const requested = new Set(Array.isArray(refreshKeys) ? refreshKeys : allKeys);
        const fallback = key => {
            if (!requested.has(key) && workbenchData && Object.prototype.hasOwnProperty.call(workbenchData,key)) {
                return {data:workbenchData[key], error:workbenchErrors[key] || ''};
            }
            return request('GET',undefined,undefined,key === 'operations' ? '' : key,{signal})
                .then(data=>({data,error:''}))
                .catch(error=>({data:null,error:`${labels[key]}: ${errorText(error.message)}`}));
        };
        let loaded;
        if (config.dashboardBootstrap && requested.size) {
            const url=new URL('/pipeline/dashboard-bootstrap',config.apiUrl);
            if (force) url.searchParams.set('force','1');
            if (Array.isArray(refreshKeys)) url.searchParams.set('sections',[...requested].join(','));
            try {
                const grouped=await workflowRequest('GET',undefined,url.toString(),'',signal);
                loaded=allKeys.map(key=>{
                    if (!requested.has(key)) return {data:workbenchData?.[key] || null,error:workbenchErrors[key] || ''};
                    const source=grouped.sources?.[key];
                    return source?.ok ? {data:source,error:''}
                        : {data:null,error:`${labels[key]}: ${errorText(source?.error || 'request_failed')}`};
                });
            } catch (_error) { /* Older hosts can still use the individual routes. */ }
        }
        if (!loaded && !signal?.aborted) loaded=await Promise.all(allKeys.map(fallback));
        if (signal?.aborted) {
            if (thisLoad===workbenchLoadId) dashboardApp.loading(false);
            throw new DOMException('Aborted','AbortError');
        }
        if (thisLoad !== workbenchLoadId) return {};
        const data = {...(workbenchData || {})};
        allKeys.forEach((key,index) => {
            // Failed refreshes retain the last read and explicitly mark it stale.
            if (loaded[index].data !== null) data[key] = loaded[index].data;
            else if (!Object.prototype.hasOwnProperty.call(data,key)) data[key] = null;
            workbenchErrors[key] = loaded[index].error;
        });
        workbenchData = data;
        if (cacheKey && !warmStart && !Object.values(workbenchErrors).some(Boolean)) {
            try {sessionStorage.setItem(cacheKey,JSON.stringify({at:Date.now(),data}));} catch (_) {}
        }
        const operations = data.operations;
        const products = [];
        const byProductId = new Map();
        const byOfferId = new Map();
        const ensureProduct = (item, recordType = 'item') => {
            const productId = String(item && item.product_id || '').trim();
            const offerId = String(item && (item.latest_offer_id || item.offer_id) || '').trim();
            let product = productId && byProductId.get(productId);
            if (!product && offerId) product = byOfferId.get(offerId);
            if (!product) {
                const sourceKey = aiSourceMessageKey(item);
                const recordId = String(item && (item.case_id || item.conflict_id || item.review_id || item.id) || '').trim();
                const key = productId ? `product:${productId}` : offerId ? `offer:${offerId}`
                    : sourceKey ? `source:${recordType}:${sourceKey}:${recordId || products.length}`
                        : `${recordType}:${recordId || products.length}`;
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
            return product;
        };

        (data.product_status && Array.isArray(data.product_status.products) ? data.product_status.products : [])
            .forEach(item => { const product = ensureProduct(item,'product_status'); product.status = item; });
        (data.ai_confirmations && Array.isArray(data.ai_confirmations.cases) ? data.ai_confirmations.cases : [])
            .forEach(item => ensureProduct(item,'ai').ai.push(item));
        (data.reference_items && Array.isArray(data.reference_items.cases) ? data.reference_items.cases : [])
            .forEach(item => ensureProduct(item,'reference').references.push(item));
        (data.supplier_prices && Array.isArray(data.supplier_prices.offers) ? data.supplier_prices.offers : [])
            .forEach(item => ensureProduct(item,'supplier_price').supplierPrices.push(item));
        (data.sheet_conflicts && Array.isArray(data.sheet_conflicts.conflicts) ? data.sheet_conflicts.conflicts : [])
            .forEach(item => ensureProduct(item,'conflict').conflicts.push(item));

        const aiCasesBySource = new Map();
        const allAiCases = [
            ...((data.ai_confirmations && Array.isArray(data.ai_confirmations.cases)) ? data.ai_confirmations.cases : []),
            ...((data.ai_confirmations && Array.isArray(data.ai_confirmations.recent_decisions)) ? data.ai_confirmations.recent_decisions : [])
        ];
        allAiCases.forEach(item => {
            const key = aiSourceMessageKey(item);
            if (!key||item.reopened) return;
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
        const holdsContextStored = Boolean(config.reviewStoreUrl && config.reviewStoreNonce);
        holds.forEach(hold => {
            const related = products.filter(product => product.ai.some(item => String(item.batch_id || '') === String(hold.hold_id || '')));
            related.forEach(product => product.holds.push(hold));
            // A batch can cover multiple products. Keep its shared retry decision in
            // one batch card, while showing the held state on each affected product.
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

        const reviewStoreAvailable = Boolean(config.reviewStoreUrl && config.reviewStoreNonce);
        const contextStored = {ai_confirmations:reviewStoreAvailable,reference_items:reviewStoreAvailable,
            sheet_conflicts:reviewStoreAvailable,supplier_prices:reviewStoreAvailable};


        const reconcileStorefront = async button => {
            if (button) { button.disabled = true; button.textContent = 'در حال بررسی فروشگاه…'; }
            try {
                workbenchReconciliation = await request('GET',undefined,undefined,'reconciliation',{label:'بررسی فروشگاه'});
                await dashboard('وضعیت فروشگاه بررسی شد.', 'success', []);
            } catch (error) {
                if (button) { button.disabled = false; button.textContent = 'بررسی دوبارهٔ فروشگاه'; }
                errorBox(errorText(error.message), button && button.parentElement || root);
            }
        };
        const openSourceReviewTool = product => dashboardApp.reviewSource(product);
        const batchCardId = hold => `molavi-batch-${encodeURIComponent(String(hold.hold_id))}`;
        const renderBatch = hold => {
                const context = hold.context && typeof hold.context === 'object' ? hold.context : {};
                const messages = Array.isArray(context.messages) ? context.messages : [];
                const reconcileWithoutRetry = hold.reason === 'batch_in_progress'
                    && context.batch_state === 'failed' && context.batch_error === 'JobConflict'
                    && context.expected_messages > 0 && messages.length === context.expected_messages;
                const card = node('section', null, 'molavi-batch-card molavi-review-card');
                card.id = batchCardId(hold);
                card.tabIndex = -1;
                card.appendChild(reviewCardHeader(({batch_in_progress:'نتیجه دسته نیازمند تطبیق است',batch_unverified:'نتیجه پردازش نیاز به بررسی دارد',batch_write_unverified:'ثبت پیام‌های دسته تأیید نشده'}[hold.reason]) || 'دسته متوقف‌شده', 'اقدام دسته‌ای'));
                card.appendChild(reviewFacts([
                    ['زمان توقف', formatSourceTimestamp(hold.created_at) || 'نامشخص'],
                    ['محصولات متصل', new Intl.NumberFormat('fa-IR').format(products.filter(product => product.holds.includes(hold)).length)],
                    ['وضعیت پردازش', displayBatchState(context.batch_state)],
                    ['پیام‌های ثبت‌شده', `${context.written_messages || 0} از ${context.expected_messages || 0}`],
                    ['پیام‌های پردازش‌شده', context.processed_messages || 0]
                ]));
                card.appendChild(notice(reconcileWithoutRetry
                    ? 'اقدام بعدی: تطبیق رسیدهای موجود و بستن توقف، بدون اجرای دوبارهٔ پردازش.'
                    : 'اقدام بعدی: پس از دیدن فهرست پیام‌ها، فقط یک‌بار اجرای دوباره را مجاز کنید.', 'info'));
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
                action.disabled = !holdsContextStored || !messages.length || Boolean(workbenchErrors.publication_holds);
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
                        try { await persistOwnerReviews([reviewRecord(eventId,
                            'publication_hold',hold.hold_id,'accepted',{},decision,receipt)]); }
                        catch (_error) { warning = ' اقدام ثبت شد، اما رسید وردپرس هنوز همگام نشده است.'; }
                        action.textContent = reconcileWithoutRetry ? 'توقف بسته شد' : 'دسته در صف قرار گرفت';
                        const message = `${reconcileWithoutRetry?'توقف تکراری بسته شد و دسته دوباره اجرا نشد.':'دسته برای یک بار پردازش دوباره در صف قرار گرفت.'}${warning}`;
                        updateActionStatus(progressStatus, message, warning ? 'warning' : 'success');
                        await dashboard(message, warning ? 'warning' : 'success',
                            ['publication_holds','operations','ai_confirmations','product_status','sheet_conflicts','supplier_prices']);
                    } catch (error) {
                        try { await persistOwnerReviews([reviewRecord(eventId,
                            'publication_hold',hold.hold_id,error.remoteRejected?'failed':'pending',{},decision,{error:String(error.message||'request_failed')})]); }
                        catch (_ignored) {}
                        action.textContent = actionLabel;
                        updateActionStatus(progressStatus, errorText(error.message), 'error');
                        errorBox(errorText(error.message),card); action.disabled = !holdsContextStored || !messages.length || Boolean(workbenchErrors.publication_holds);
                    }
                });
                const batchActions = reviewActions();
                batchActions.appendChild(action);
                card.appendChild(batchActions);

            return card;
        };
        const renderAction = (product, primaryKey) => {
            if (workbenchActionKey(product)!==primaryKey) return null;
            const dependency = workbenchActionSections[primaryKey]
                || (['source_integrity','status'].includes(primaryKey) ? 'product_status' : '');
            if (dependency && workbenchErrors[dependency]) {
                const section=node('section');
                const retry=node('button','دریافت دوبارهٔ این مرحله','button');retry.type='button';
                retry.addEventListener('click',()=>dashboard('','info',[dependency]));
                section.append(notice('دادهٔ این بخش تازه دریافت نشده است.','warning'),retry);
                return section;
            }
            const card = node('div',null,'molavi-action-content');
            const status = product.status || {};
            const addWorkflowSection = section => card.appendChild(section);
            const refreshInPlace = async (message, kind, statusNode, refreshKeys) => {
                updateActionStatus(statusNode,message,kind);
                await dashboard(message,kind,refreshKeys);
            };
            if (primaryKey === 'ai') groupAiVariantCases(product.ai.filter(item=>!workbenchAiCaseIsDismissed(item))).forEach(relatedItems => {
                const item = relatedItems[0];
                const sourceKey = aiSourceMessageKey(item);
                const sourceCases = sourceKey && aiCasesBySource.has(sourceKey)
                    ? [...aiCasesBySource.get(sourceKey).values()] : [item];
                const isSourceLead = sourceKey && aiSourceCoverageLead.get(sourceKey) === item.case_id;
                const sourcePriceGap = isSourceLead ? aiSourcePriceGap(item, sourceCases) : null;
                const sourceVariantGap = isSourceLead ? aiSourceVariantGap(item, sourceCases) : null;
                const sourceStockGap = isSourceLead ? aiSourceStockGap(item, sourceCases) : null;
                const renderCase = () => aiCaseCard(item, null, contextStored.ai_confirmations,
                    !relatedItems.some(row => row.publication_held), relatedItems, {
                    embedded:true, apiKey:'ai_confirmations', refresh:refreshInPlace,
                    sourcePriceGap, sourceVariantGap, sourceStockGap,
                    holdTarget:'',
                    parentName:product.name, parentChannel:product.channel, parentImageId:product.ai[0]?.image_file_id
                });
                const title = node('h3', item.message_review?'بررسی پیام انتخاب‌شده':item.publication_review?'بازبینی برای انتشار'
                    :item.reopened?'بازبینی کالای منتشرشده':'استخراج از پیام تأمین‌کننده');
                if (!data.ai_confirmations?.candidates_deferred || !relatedItems.some(row=>row.reference_snapshot_id
                    && !row.reference_match_id && row.reference_assignment?.choice!=='match')) {
                    const ai=renderCase();
                    ai.prepend(title);
                    addWorkflowSection(ai, 'ai');
                    return;
                }
                const panel=node('section',null,'molavi-ai-candidate-panel');
                panel.append(title);
                const content=node('div');
                panel.append(content);
                const readCandidates=async()=>{
                    content.replaceChildren(node('p','در حال بررسی مرجع این پیشنهاد…'));
                    try {
                        await Promise.all(relatedItems.map(async row=>{
                            if (!row.reference_snapshot_id || row.reference_match_id || row.reference_assignment?.choice==='match') return;
                            const url=new URL('/pipeline/ai-confirmations',config.apiUrl);
                            url.searchParams.set('view','workbench-candidates');
                            url.searchParams.set('offer_id',row.offer_id);
                            url.searchParams.set('snapshot_id',row.reference_snapshot_id);
                            const result=await request('GET',undefined,url.href,'ai_confirmations',{ttl:45000});
                            const merged=[...(row.reference_candidates||[]),...(result.candidates||[])];
                            row.reference_candidates=[...new Map(merged.map(candidate=>[candidate.reference_id,candidate])).values()].slice(0,12);
                            row.reference_snapshot_id=result.snapshot_id || '';
                        }));
                        content.replaceChildren(renderCase());
                    } catch(error) {
                        const retry=node('button','تلاش دوباره','button');
                        retry.type='button';
                        retry.addEventListener('click',readCandidates);
                        content.replaceChildren(notice('پیشنهادهای خودکار دریافت نشد. می‌توانید فهرست را جست‌وجو کنید یا بدون مرجع ادامه دهید.','warning'),retry,renderCase());
                    }
                };
                addWorkflowSection(panel, 'ai');
                readCandidates();
            });
            if (primaryKey === 'references') product.references.forEach(item => {
                const renderCase=row=>{
                    const reference = referenceItemCard(row, contextStored.reference_items, {
                        embedded:true, apiKey:'reference_items', refresh:refreshInPlace,
                        parentName:product.name, parentChannel:product.channel
                    });
                    reference.prepend(node('h3', 'تطبیق و مبنای قیمت مرجع'));
                    return reference;
                };
                if (!data.reference_items?.candidates_deferred) {
                    addWorkflowSection(renderCase(item));
                    return;
                }
                const panel=node('section',null,'molavi-reference-lazy-case');
                const load=async()=>{
                    panel.replaceChildren(node('p','در حال دریافت گزینه‌های مرجع…'));
                    try {
                        const url=new URL('/reference/item-reviews',config.apiUrl);
                        url.searchParams.set('offer_id',item.offer_id);
                        const {cases}=await request('GET',undefined,url.href,'reference_items',{ttl:30000});
                        const current=cases?.find(row=>row.case_id===item.case_id);
                        if(!current) throw new Error('stale_case');
                        panel.replaceChildren(renderCase(current));
                    } catch(error) {
                        const retry=node('button','تلاش دوباره','button');
                        retry.type='button';retry.addEventListener('click',load);
                        panel.replaceChildren(notice(errorText(error.message),'error'),retry,renderCase(item));
                    }
                };
                addWorkflowSection(panel);
                load();
            });
            if (primaryKey === 'supplier_prices') product.supplierPrices.forEach(offer => {
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
                const sourceLink = workbenchSupplierSource(product, offer);
                if (sourceLink) {
                    const tools = node('div', null, 'molavi-card-source-tools');
                    tools.appendChild(sourceLink);
                    section.appendChild(tools);
                    if (!offer.acknowledged && !String(offer.source_revision || '').trim()) {
                        const findSource = node('button', 'یافتن پیام تأمین‌کننده', 'button button-secondary');
                        findSource.type = 'button';
                        findSource.addEventListener('click', () => openSourceReviewTool(product));
                        section.appendChild(findSource);
                    }
                } else if (!offer.acknowledged) {
                    const findSource = node('button', 'یافتن پیام تأمین‌کننده', 'button button-secondary');
                    findSource.type = 'button';
                    findSource.addEventListener('click', () => openSourceReviewTool(product));
                    section.appendChild(findSource);
                }
                // Supplier link check (round eight): what the last live
                // re-read of the original link found, if any.
                const linkCheck = offer.link_check || null;
                if (linkCheck && linkCheck.outcome) {
                    if (linkCheck.outcome === 'unchanged') {
                        section.appendChild(notice(`لینک منبع بررسی شد؛ قیمت بدون تغییر است (${linkCheck.reason_label || ''} · ${linkCheck.checked_at ? formatSourceTimestamp(new Date(linkCheck.checked_at * 1000).toISOString()) : 'زمان ثبت نشده'} · مرجع: ${linkCheck.check_ref || '—'}). تأیید شما با تأمین‌کننده است که قیمت را تازه می‌کند.`, 'info'));
                    } else if (linkCheck.outcome === 'changed') {
                        section.appendChild(notice(`قیمت در لینک منبع تغییر کرده است: ثبت‌شده ${amount(offer.sale_price)}، تازه ${amount(linkCheck.live_price)} — قیمت تازه جایگزین نشده است و کالا همچنان متوقف است.`, 'warning'));
                    } else if (linkCheck.outcome === 'uncheckable') {
                        section.appendChild(notice(`لینک منبع قابل بررسی نیست: ${linkCheck.reason_label || linkCheck.reason_code || 'نامشخص'}.`, 'warning'));
                    }
                }
                const marketAgreement = offer.market_agreement || null;
                if (marketAgreement && marketAgreement.outcome) {
                    if (marketAgreement.outcome === 'agree') {
                        section.appendChild(notice(`توافق بازار: قیمت تأمین‌کننده با نقل‌قول‌های بازار همخوان است (اختلاف ضمنی ${Number.isFinite(Number(marketAgreement.implied_spread)) ? `${(Number(marketAgreement.implied_spread) * 100).toFixed(1)}٪` : '—'} · ${marketAgreement.verified_at ? formatSourceTimestamp(marketAgreement.verified_at) : 'زمان ثبت نشده'}). این تأیید خودکار است و قیمت عادی را تازه نمی‌کند.`, 'info'));
                    } else if (marketAgreement.outcome === 'chain_limit') {
                        section.appendChild(notice('توافق بازار: سه بار پیاپی خودکار تأیید شده؛ نوبت بررسی دستی است.', 'warning'));
                    } else if (marketAgreement.outcome === 'disagree') {
                        section.appendChild(notice(`توافق بازار: نقل‌قول‌ها همخوان نبود (${marketAgreement.reason_label || marketAgreement.reason_code || 'نامشخص'}).`, 'warning'));
                    }
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
                    // A no-change link check makes the confirmation auditable:
                    // the note carries the check reference, URL, and time.
                    const checkedLink = linkCheck && linkCheck.outcome === 'unchanged' && !linkCheck.confirmed_at && linkCheck.check_ref ? linkCheck : null;
                    const verifyNote = checkedLink ? linkCheckConfirmNote(checkedLink) : 'مالک منبع فعلی قیمت را از پنل بررسی تأیید کرد.';
                    try {
                        const stored = await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,'pending',context,decision)]);
                        const commandId = stored.commands[eventId];
                        if (!commandId) throw new Error('review_store_failed');
                        const receipt = await requestSupplierPrice({product_id:offer.product_id,
                            expected_revision:offer.source_revision,note:verifyNote,
                            command_id:commandId},'supplier_prices');
                        if (receipt.product_id !== offer.product_id || receipt.source_revision !== offer.source_revision
                            || receipt.command_id !== commandId || !receipt.verified_at || !receipt.publish_job) throw new Error('invalid_receipt');
                        let warning = '';
                        try { await persistOwnerReviews([reviewRecord(eventId,'supplier_price',offer.product_id,'accepted',context,decision,receipt)]); }
                        catch (_error) { warning = ' قیمت تأیید شد، اما رسید محلی وردپرس هنوز همگام نشده است.'; }
                        if (checkedLink) {
                            try { await request('POST', {action:'confirm', product_id:offer.product_id,
                                check_ref:checkedLink.check_ref, verified_at:receipt.verified_at}, undefined, 'supplier_link_checks', {quiet:true}); }
                            catch (_error) { warning = ' قیمت تأیید شد، اما ثبت بررسی لینک هنوز همگام نشده است.'; }
                        }
                        offer.acknowledged = true;
                        verify.textContent = 'قیمت تأیید شد';
                        const message = `قیمت تأیید شد و انتشار دوباره در صف قرار گرفت.${warning}`;
                        updateActionStatus(progressStatus, message, warning ? 'warning' : 'success');
                        await dashboard(message, warning ? 'warning' : 'success',
                            ['supplier_prices','supplier_link_checks','product_status','operations']);
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
            if (primaryKey === 'conflicts') product.conflicts.forEach(conflict => {
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
                        const message = `${serverOwned ? 'مقدار سیستمی با سرور همگام شد.' : sheetPreserved ? 'مقدار اصلی شیت حفظ شد.' : 'تصمیم ذخیره شد؛ همگام‌سازی فروشگاه در صف است.'}${warning}`;
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
            if (primaryKey === 'holds') product.holds.forEach(hold => {
                const openBatch = node('button', 'باز کردن دستهٔ متوقف‌شده', 'button button-secondary');
                openBatch.type = 'button';
                openBatch.addEventListener('click', () => dashboardApp.reviewHold(batchCardId(hold)));
                addWorkflowSection(openBatch, 'holds');
            });
            if (primaryKey === 'source_integrity' && Array.isArray(status.source_integrity_issues) && status.source_integrity_issues.length) {
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
                section.appendChild(node('p','پیام اصلی یا یک پیشنهاد تازه برای همین کالا را به ربات بفرستید. وقتی استخراج آماده شد، پیشنهاد را از پیام‌های ربات برای همین کالا بررسی کنید.'));
                const recoveryTools = node('button', 'دیدن پیام‌های ربات', 'button button-primary');
                recoveryTools.type = 'button';
                recoveryTools.addEventListener('click', () => openSourceReviewTool(product));
                section.appendChild(recoveryTools);
                addWorkflowSection(section, 'source_integrity');
            }
            if (primaryKey === 'storefront' && workbenchQueueMatches(product,'storefront')) {
                const section = node('section', null, 'molavi-product-subsection molavi-review-block molavi-storefront-reconciliation');
                section.appendChild(node('h3', 'مغایرت وضعیت قفسهٔ WooCommerce'));
                section.appendChild(node('p', `وضعیت گزارش‌شده: ${workbenchWooLabel(workbenchWooState(product))}. این مورد برای دیدن رسید و وضعیت عمومی نیازمند تطبیق است.`));
                const audit = node('button', workbenchReconciliation ? 'تازه‌کردن تطبیق خواندنی' : 'بررسی رسید و قفسه', 'button button-secondary');
                audit.type = 'button';
                audit.addEventListener('click', () => reconcileStorefront(audit));
                const actions = reviewActions();
                actions.appendChild(audit);
                section.appendChild(actions);
                addWorkflowSection(section, 'storefront');
            }
            if (primaryKey === 'status' && (status.update_needed || status.review_reason || status.source_post_url || ['no_spread','thin_spread'].includes(status.sale_offer_blocker))) {
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
            // Step-status trace (owner request 2026-11-25): the seven steps
            // on the way to publication, with the actual values compared.
            // Collapsed by default, on every card (published or not) that
            // carries a trace from the product-status API.
            const stepTrace = status.step_trace ? renderStepTrace(status.step_trace) : null;
            if (stepTrace) addWorkflowSection(stepTrace);

            return card;
        };
        // Review surface (round six): risk badges, filter and sort. Pure
        // helpers, so the ordering rule is one place: red first, then
        // amber, then green, then untiered; within a tier the API order
        // (newest first) is kept. A tier the server did not send fails
        // closed to red. Tiering is defined only for admission rows; other
        // gates render 'none' (neutral, never green, never red).
        const autopilotRiskOrder = {red:0, amber:1, green:2, none:3};
        const autopilotRiskLabels = {red:'پرخطر', amber:'ریسک متوسط', green:'کم‌ریسک', none:'بدون درجه‌بندی'};
        const autopilotRiskTier = row => {
            const tier = String(row && row.risk_tier || '');
            return ['red','amber','green','none'].includes(tier) ? tier : 'red';
        };
        const autopilotRiskSort = rows => [...rows].sort((a,b) => autopilotRiskOrder[autopilotRiskTier(a)] - autopilotRiskOrder[autopilotRiskTier(b)]);
        const autopilotRiskFilterRows = (rows, filter) => filter === 'all' ? [...rows] : rows.filter(row => autopilotRiskTier(row) === filter);
        const autopilotRiskIcon = tier => {
            const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
            svg.setAttribute('viewBox','0 0 16 16');
            svg.setAttribute('aria-hidden','true');
            svg.setAttribute('class','molavi-risk-icon');
            const shapes = {
                red:[['path',{d:'M8 1.5 14.5 13.5h-13Z',fill:'none',stroke:'currentColor','stroke-width':'1.5','stroke-linejoin':'round'}],['path',{d:'M8 6v3.2',stroke:'currentColor','stroke-width':'1.5','stroke-linecap':'round'}],['circle',{cx:'8',cy:'11.6',r:'0.85',fill:'currentColor'}]],
                amber:[['circle',{cx:'8',cy:'8',r:'6.4',fill:'none',stroke:'currentColor','stroke-width':'1.5'}],['path',{d:'M8 4.8v3.4',stroke:'currentColor','stroke-width':'1.5','stroke-linecap':'round'}],['circle',{cx:'8',cy:'10.8',r:'0.85',fill:'currentColor'}]],
                green:[['circle',{cx:'8',cy:'8',r:'6.4',fill:'none',stroke:'currentColor','stroke-width':'1.5'}],['path',{d:'M5.2 8.2 7.2 10.2 11 6.2',fill:'none',stroke:'currentColor','stroke-width':'1.5','stroke-linecap':'round','stroke-linejoin':'round'}]],
                none:[['circle',{cx:'8',cy:'8',r:'6.4',fill:'none',stroke:'currentColor','stroke-width':'1.5'}],['path',{d:'M5 8h6',stroke:'currentColor','stroke-width':'1.5','stroke-linecap':'round'}]],
            }[tier] || [];
            shapes.forEach(([tag,attrs]) => {
                const shape = document.createElementNS('http://www.w3.org/2000/svg',tag);
                Object.entries(attrs).forEach(([key,value]) => shape.setAttribute(key,String(value)));
                svg.appendChild(shape);
            });
            return svg;
        };
        const autopilotRiskBadge = row => {
            const tier = autopilotRiskTier(row);
            const badge = node('span', null, `molavi-risk-badge molavi-risk-${tier}`);
            badge.appendChild(autopilotRiskIcon(tier));
            badge.appendChild(node('span', autopilotRiskLabels[tier]));
            return badge;
        };
        const autopilotHold = product => {
            const holds = data?.autopilot?.holds || [];
            return holds.find(hold => String(hold.product_id) === String(product.product_id)) || null;
        };
        // Supplier link checks (round eight): the owner's confirm list
        // (outcome a), the changed-price list (outcome b), and the
        // uncheckable report (outcome c) — three separate sections, so a
        // (c) item can never reach the confirm action. A confirm here runs
        // the existing manual supplier-price verification with a structured
        // provenance note (link-check reference + URL + checked time), then
        // marks the check confirmed so it leaves the list.
        const linkCheckConfirmNote = item => {
            const at = item.checked_at ? new Date(item.checked_at * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z') : '';
            return `بررسی لینک تأمین‌کننده: قیمت بدون تغییر · ${item.check_ref || ''} · ${item.url || ''} · ${at} — مالک با تأمین‌کننده تأیید کرد.`;
        };
        const confirmLinkCheckPrice = async (item, control, statusHost) => {
            control.disabled = true;
            const originalLabel = control.textContent;
            control.textContent = 'در حال ثبت…';
            const progressStatus = actionStatus(statusHost, 'در حال ثبت تأیید قیمت با درخواست AJAX…');
            const eventId = reviewEventId('supplier_price', item.product_id);
            const context = {product_id:item.product_id, name:item.title, sale_price:item.stored_sale_price,
                source_revision:item.source_revision, source_post_url:item.url, link_check_ref:item.check_ref || ''};
            const decision = {action:'verify_current_supplier_price', note:'Owner confirmed the supplier price with the supplier after a no-change link check.'};
            try {
                const stored = await persistOwnerReviews([reviewRecord(eventId, 'supplier_price', item.product_id, 'pending', context, decision)]);
                const commandId = stored.commands[eventId];
                if (!commandId) throw new Error('review_store_failed');
                const receipt = await requestSupplierPrice({product_id:item.product_id,
                    expected_revision:item.source_revision, note:linkCheckConfirmNote(item),
                    command_id:commandId}, 'supplier_prices');
                if (receipt.product_id !== item.product_id || receipt.source_revision !== item.source_revision
                    || receipt.command_id !== commandId || !receipt.verified_at || !receipt.publish_job) throw new Error('invalid_receipt');
                let warning = '';
                try {
                    await persistOwnerReviews([reviewRecord(eventId, 'supplier_price', item.product_id, 'accepted', context, decision, receipt)]);
                    await request('POST', {action:'confirm', product_id:item.product_id,
                        check_ref:item.check_ref, verified_at:receipt.verified_at}, undefined, 'supplier_link_checks', {quiet:true});
                } catch (_error) { warning = ' قیمت تأیید شد، اما ثبت بررسی لینک هنوز همگام نشده است.'; }
                updateActionStatus(progressStatus, `قیمت تأیید شد و انتشار دوباره در صف قرار گرفت.${warning}`, warning ? 'warning' : 'success');
                await dashboard('قیمت تأیید شد و انتشار دوباره در صف قرار گرفت.', warning ? 'warning' : 'success',
                    ['supplier_prices', 'supplier_link_checks', 'product_status', 'operations']);
            } catch (error) {
                try { await persistOwnerReviews([reviewRecord(eventId, 'supplier_price', item.product_id, error.remoteRejected ? 'failed' : 'pending', context, decision, {error:String(error.message || 'request_failed')})]); }
                catch (_ignored) {}
                control.textContent = originalLabel;
                updateActionStatus(progressStatus, errorText(error.message), 'error');
                errorBox(errorText(error.message), statusHost);
                control.disabled = false;
            }
        };
        const renderLinkChecks = () => {
            const wrap = document.createElement('div');
            wrap.className = 'molavi-link-check-view';
            const checks = data?.supplier_link_checks || null;
            if (!checks || !checks.ok) {
                wrap.appendChild(notice(workbenchErrors.supplier_link_checks || 'بررسی لینک تأمین‌کننده در دسترس نیست.', 'warning'));
                return wrap;
            }
            const counts = checks.counts || {};
            const faNum = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
            wrap.appendChild(node('h2', 'بررسی لینک تأمین‌کننده'));
            wrap.appendChild(node('p', `بدون تغییر: ${faNum(counts.unchanged)} · قیمت تغییر کرده: ${faNum(counts.changed)} · قابل بررسی نیست: ${faNum(counts.uncheckable)} · تأییدشده: ${faNum(counts.confirmed)} · در انتظار بررسی: ${faNum(counts.pending)}`));
            wrap.appendChild(node('p', 'هر کالا حداکثر روزی یک‌بار از لینک اصلی‌اش خوانده می‌شود. «بدون تغییر» یعنی متن یا قیمت پیام منبع با ثبت ما یکی است؛ تأیید شما با تأمین‌کننده است که ساعت ۱۴ روزه را تازه می‌کند، نه خود بررسی. قیمت تغییرکرده هیچ‌وقت خودبه‌خود جایگزین نمی‌شود.'));
            const runButton = node('button', 'اجرای یک بررسی تازه', 'button button-secondary');
            runButton.type = 'button';
            runButton.addEventListener('click', async () => {
                runButton.disabled = true;
                try {
                    const result = await request('POST', {action:'run'}, undefined, 'supplier_link_checks', {quiet:true});
                    await dashboard(result.ran ? 'یک بررسی لینک انجام شد.' : 'موردی برای بررسی نبود.', 'info', ['supplier_link_checks', 'supplier_prices']);
                } catch (error) { errorBox(errorText(error.message), wrap); runButton.disabled = false; }
            });
            wrap.appendChild(runButton);
            const linkFor = url => {
                if (!url) return null;
                try { const parsed = new URL(url); if (parsed.protocol !== 'https:' || !['t.me', 'bale.ai'].includes(parsed.hostname)) return null; }
                catch (_ignored) { return null; }
                const link = node('a', 'پست منبع', 'button button-secondary');
                link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                return link;
            };
            // (a) Owner's confirm list — checked, no change. The only place a confirm action exists.
            const confirmSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            confirmSection.appendChild(node('h3', `فهرست تأیید شما — لینک بررسی شد، قیمت بدون تغییر (${faNum(counts.unchanged)})`));
            if (!checks.confirm_list.length) confirmSection.appendChild(node('p', 'موردی در این فهرست نیست.'));
            checks.confirm_list.forEach(item => {
                const row = node('div', null, 'molavi-link-check-row');
                row.appendChild(reviewFacts([
                    ['کالا', item.title || item.product_id],
                    ['قیمت ثبت‌شده', amount(item.stored_sale_price)],
                    ['قیمت در لینک', amount(item.live_price)],
                    ['بررسی‌شده', item.checked_at ? formatSourceTimestamp(new Date(item.checked_at * 1000).toISOString()) : 'ثبت نشده'],
                    ['مرجع بررسی', item.check_ref || '—']
                ]));
                const sourceLink = linkFor(item.url);
                if (sourceLink) row.appendChild(sourceLink);
                const confirm = node('button', 'با تأمین‌کننده تأیید کردم؛ قیمت تأیید است', 'button button-primary');
                confirm.type = 'button';
                confirm.addEventListener('click', () => confirmLinkCheckPrice(item, confirm, row));
                row.appendChild(confirm);
                confirmSection.appendChild(row);
            });
            wrap.appendChild(confirmSection);
            // (b) Changed prices — surfaced only, never adopted, item stays held.
            const changedSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            changedSection.appendChild(node('h3', `قیمت در منبع تغییر کرده (${faNum(counts.changed)})`));
            changedSection.appendChild(notice('قیمت تازه فقط نمایش داده می‌شود؛ جایگزین قیمت ثبت‌شده نمی‌شود و کالا همچنان متوقف است تا دوباره بررسی شود.', 'warning'));
            if (!checks.changed.length) changedSection.appendChild(node('p', 'موردی نیست.'));
            checks.changed.forEach(item => {
                const row = node('div', null, 'molavi-link-check-row');
                row.appendChild(reviewFacts([
                    ['کالا', item.title || item.product_id],
                    ['قیمت ثبت‌شده', amount(item.stored_sale_price)],
                    ['قیمت تازه در لینک', amount(item.live_price)],
                    ['بررسی‌شده', item.checked_at ? formatSourceTimestamp(new Date(item.checked_at * 1000).toISOString()) : 'ثبت نشده']
                ]));
                const sourceLink = linkFor(item.url);
                if (sourceLink) row.appendChild(sourceLink);
                changedSection.appendChild(row);
            });
            wrap.appendChild(changedSection);
            // (c) Uncheckable report — grouped by reason, structurally separate, no confirm action.
            const uncheckableSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            uncheckableSection.appendChild(node('h3', `قابل بررسی نیست (${faNum(counts.uncheckable)})`));
            uncheckableSection.appendChild(node('p', 'این موارد در فهرست تأیید شما نیستند؛ لینک‌شان خوانده نشد یا قیمت از متن خوانا نبود.'));
            const byReason = checks.uncheckable_by_reason || {};
            if (!checks.uncheckable.length) uncheckableSection.appendChild(node('p', 'موردی نیست.'));
            Object.entries(byReason).forEach(([code, items]) => {
                const details = document.createElement('details');
                const label = (items[0] && items[0].reason_label) || code;
                details.appendChild(node('summary', `${label} (${faNum(items.length)})`));
                const list = document.createElement('ul');
                items.forEach(item => {
                    const line = document.createElement('li');
                    line.appendChild(document.createTextNode(`${item.title || item.product_id} · قیمت ثبت‌شده: ${item.stored_sale_price || 'ثبت نشده'}`));
                    const sourceLink = linkFor(item.url);
                    if (sourceLink) { line.appendChild(document.createTextNode(' · ')); line.appendChild(sourceLink); }
                    list.appendChild(line);
                });
                details.appendChild(list);
                uncheckableSection.appendChild(details);
            });
            const download = node('button', 'دانلود گزارش موارد غیرقابل‌بررسی (JSON)', 'button button-secondary');
            download.type = 'button';
            download.disabled = !checks.uncheckable.length;
            download.addEventListener('click', () => {
                const blob = new Blob([JSON.stringify({generated_at:new Date().toISOString(), uncheckable_by_reason:byReason}, null, 2)], {type:'application/json'});
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = 'supplier-link-checks-uncheckable.json';
                link.click();
                setTimeout(() => URL.revokeObjectURL(link.href), 1000);
            });
            uncheckableSection.appendChild(download);
            wrap.appendChild(uncheckableSection);
            return wrap;
        };
        // Market agreement (round nine): the simple report. No confirm
        // action — an 'agree' row already re-verified the price with
        // evidence method 'market'; every other outcome leaves the item held.
        const renderMarketAgreement = () => {
            const wrap = document.createElement('div');
            wrap.className = 'molavi-market-agreement-view';
            const agreement = data?.market_agreement || null;
            if (!agreement || !agreement.ok) {
                wrap.appendChild(notice(workbenchErrors.market_agreement || 'توافق بازار در دسترس نیست.', 'warning'));
                return wrap;
            }
            const counts = agreement.counts || {};
            const faNum = value => new Intl.NumberFormat('fa-IR').format(Number(value || 0));
            const pct = value => Number.isFinite(Number(value)) ? `${(Number(value) * 100).toFixed(1)}٪` : '—';
            wrap.appendChild(node('h2', 'توافق بازار'));
            wrap.appendChild(node('p', `تأییدشده: ${faNum(counts.verified)} · همخوان نیست: ${faNum(counts.disagree)} · بدون نقل‌قول: ${faNum(counts.no_quotes)} · نیازمند بررسی دستی: ${faNum(counts.chain_limit)} · در انتظار بررسی: ${faNum(counts.pending)}`));
            wrap.appendChild(node('p', 'قیمت تأمین‌کننده با نقل‌قول‌های روز بازار مقایسه می‌شود: وقتی دست‌کم دو نقل‌قول هم‌بسته اختلافشان زیر ۵٪ باشد (یا یک نقل‌قول با مرجع رضوان همخوان باشد) و اختلاف ضمنی قیمت تأمین‌کننده با بازار بین ۱۲٪ تا ۶۰٪ باشد، قیمت خودکار تأیید می‌شود و ساعت ۱۴ روزه تازه می‌شود. این تأیید، قیمت عادی را تازه نمی‌کند؛ کالا برای انتشار همچنان به قیمت عادی روز نیاز دارد.'));
            const runButton = node('button', 'اجرای یک بررسی تازه', 'button button-secondary');
            runButton.type = 'button';
            runButton.addEventListener('click', async () => {
                runButton.disabled = true;
                try {
                    const result = await request('POST', {action:'run'}, undefined, 'market_agreement', {quiet:true});
                    await dashboard(result.ran ? 'یک بررسی توافق بازار انجام شد.' : 'موردی برای بررسی نبود.', 'info', ['market_agreement', 'supplier_prices']);
                } catch (error) { errorBox(errorText(error.message), wrap); runButton.disabled = false; }
            });
            wrap.appendChild(runButton);
            const quoteLink = url => {
                if (!url) return null;
                try { const parsed = new URL(url); if (parsed.protocol !== 'https:') return null; }
                catch (_ignored) { return null; }
                const link = node('a', 'نقل‌قول بازار', 'button button-secondary');
                link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                return link;
            };
            const agreementFacts = item => reviewFacts([
                ['کالا', item.title || item.product_id],
                ['قیمت تأمین‌کننده', amount(item.stored_sale_price)],
                ['قیمت بازار (بسته)', amount(item.quote_price)],
                ['اختلاف ضمنی', pct(item.implied_spread)],
                ['مبنا', item.basis === 'rezvan_crosscheck' ? 'همخوانی با مرجع رضوان' : (item.basis === 'agreement' ? 'توافق نقل‌قول‌ها' : '—')],
                ['بررسی‌شده', item.checked_at ? formatSourceTimestamp(new Date(item.checked_at * 1000).toISOString()) : 'ثبت نشده'],
                ['تأییدشده', item.verified_at ? formatSourceTimestamp(item.verified_at) : '—'],
                ['دلیل', item.reason_label || item.reason_code || '—']
            ]);
            const verifiedSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            verifiedSection.appendChild(node('h3', `با توافق بازار تأیید شد (${faNum(counts.verified)})`));
            if (!agreement.verified.length) verifiedSection.appendChild(node('p', 'موردی در این فهرست نیست.'));
            agreement.verified.forEach(item => {
                const row = node('div', null, 'molavi-link-check-row');
                row.appendChild(agreementFacts(item));
                const link = quoteLink(item.quote_url);
                if (link) row.appendChild(link);
                verifiedSection.appendChild(row);
            });
            wrap.appendChild(verifiedSection);
            const disagreeSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            disagreeSection.appendChild(node('h3', `نقل‌قول‌ها همخوان نبود (${faNum(counts.disagree)})`));
            disagreeSection.appendChild(notice('این کالاها همچنان متوقف‌اند؛ هیچ مدرکی برایشان نوشته نشده است.', 'warning'));
            if (!agreement.disagree.length) disagreeSection.appendChild(node('p', 'موردی نیست.'));
            agreement.disagree.forEach(item => {
                const row = node('div', null, 'molavi-link-check-row');
                row.appendChild(agreementFacts(item));
                const link = quoteLink(item.quote_url);
                if (link) row.appendChild(link);
                disagreeSection.appendChild(row);
            });
            wrap.appendChild(disagreeSection);
            const noQuotesSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            noQuotesSection.appendChild(node('h3', `بدون نقل‌قول قابل‌استفاده (${faNum(counts.no_quotes)})`));
            if (!agreement.no_quotes.length) noQuotesSection.appendChild(node('p', 'موردی نیست.'));
            agreement.no_quotes.forEach(item => {
                const row = node('div', null, 'molavi-link-check-row');
                row.appendChild(agreementFacts(item));
                noQuotesSection.appendChild(row);
            });
            wrap.appendChild(noQuotesSection);
            const chainSection = node('section', null, 'molavi-product-subsection molavi-review-block');
            chainSection.appendChild(node('h3', `نیازمند بررسی دستی (${faNum(counts.chain_limit)})`));
            chainSection.appendChild(notice('این کالاها سه بار پیاپی خودکار تأیید شده‌اند؛ تا یک تأیید دستی، توافق بازار برایشان مدرک تازه نمی‌نویسد.', 'warning'));
            if (!agreement.chain_limit.length) chainSection.appendChild(node('p', 'موردی نیست.'));
            agreement.chain_limit.forEach(item => {
                const row = node('div', null, 'molavi-link-check-row');
                row.appendChild(agreementFacts(item));
                chainSection.appendChild(row);
            });
            wrap.appendChild(chainSection);
            return wrap;
        };
        const renderAutopilot = () => {
            const wrap = document.createElement('div');
            wrap.className = 'molavi-autopilot-view';
            const autopilot = data?.autopilot || null;
            if (!autopilot || !autopilot.ok) {
                wrap.appendChild(notice(workbenchErrors.autopilot || 'تصمیم‌های خودکار در دسترس نیست.','warning'));
                return wrap;
            }
            const settings = autopilot.settings || {};
            const rules = autopilot.rules || [];
            const log = autopilot.log || [];
            const holds = autopilot.holds || [];
            const gateLabels = {publish:'انتشار',market:'قیمت عادی',supplier_price:'قیمت تأمین‌کننده',admission:'پذیرش',admission_owner:'توقف مالک',settings:'تنظیمات'};
            const ruleLabels = {g5:'درخواست انتشار',g4:'انتخاب نقل‌قول بازار',g3:'تأیید قیمت تأمین‌کننده',gA:'پذیرش کالا'};
            const actionLabels = {shadow:'سایه (انجام نشد)',live:'انجام شد',override:'لغو شد',revert:'بازگردانده شد',hold:'متوقف شد',release_hold:'توقف رفع شد',confirm:'بازبینی شد',settings:'تنظیمات'};
            const verdictLabels = {pending:'در انتظار',approved:'تأیید شد',flagged:'نیازمند بررسی',ok:'تأیید شد',flag:'نیازمند بررسی',not_run:'اجرا نشد'};
            const productName = id => byProductId.get(String(id))?.name || String(id || '—');
            const holdsBox = document.createElement('section');
            holdsBox.className = 'card molavi-product-subsection';
            holdsBox.appendChild(node('h2',`موارد نیازمند تصمیم (${holds.length})`));
            if (!holds.length) holdsBox.appendChild(node('p','موردی در انتظار تصمیم نیست.'));
            holds.forEach(hold => {
                const row = document.createElement('div');
                row.className='molavi-autopilot-hold';
                row.appendChild(node('p',`${productName(hold.product_id)} · ${gateLabels[hold.gate] || hold.gate} · ${hold.reason}`));
                if (Number.isFinite(Number(hold.score))) row.appendChild(node('p',`اطمینان: ${Math.round(Number(hold.score)*100)}٪`));
                holdsBox.appendChild(row);
            });
            const logBox = document.createElement('section');
            logBox.className = 'card molavi-product-subsection';
            logBox.id = 'molavi-autopilot-decisions';
            logBox.appendChild(node('h2','تصمیم‌های خودکار'));
            // Read-only master state: the settings form itself moved to the
            // settings view (gear icon), so the tab shows only whether the
            // autopilot is on, not a control to change it.
            logBox.appendChild(node('p', settings.master_enabled
                ? 'خلبان خودکار روشن است؛ قانون‌های «فعال» اقدام می‌کنند.'
                : 'خلبان خودکار خاموش است؛ همهٔ قانون‌ها فقط در حالت سایه محاسبه و ثبت می‌شوند.'));
            if (Number(autopilot.unreviewed_decisions) > 0) logBox.appendChild(node('p',`بازبینی‌نشده: ${Number(autopilot.unreviewed_decisions).toLocaleString('fa-IR')} تصمیم`));
            if (!log.length) logBox.appendChild(node('p','هنوز تصمیمی ثبت نشده است.'));
            // Risk filter + risk-first sort are client-side over the rows
            // the API returns (the tab has no server-side filter; the
            // dashboard fetch loads the whole section at once). Within a
            // tier the API order (newest first) is kept.
            const riskCounts = {all:log.length, red:0, amber:0, green:0, none:0};
            log.forEach(row => { riskCounts[autopilotRiskTier(row)]++; });
            const filterBox = document.createElement('div');
            filterBox.className = 'molavi-risk-filter';
            filterBox.appendChild(node('span','پالایش بر پایهٔ ریسک:'));
            [['all','همه'],['red','پرخطر'],['amber','ریسک متوسط'],['green','کم‌ریسک']].forEach(([value,label]) => {
                const button = node('button',`${label} (${riskCounts[value].toLocaleString('fa-IR')})`,'button button-secondary');
                button.type='button';
                if (autopilotRiskFilter===value) button.classList.add('is-active');
                button.addEventListener('click', () => {
                    autopilotRiskFilter=value;
                    filterBox.querySelectorAll('button').forEach(candidate => candidate.classList.toggle('is-active', candidate===button));
                    renderLogRows();
                });
                filterBox.appendChild(button);
            });
            logBox.appendChild(filterBox);
            const logList = document.createElement('div');
            logBox.appendChild(logList);
            const renderLogRows = () => {
                while (logList.firstChild) logList.removeChild(logList.firstChild);
                autopilotRiskSort(autopilotRiskFilterRows(log, autopilotRiskFilter)).forEach(row => {
                const item = document.createElement('div');
                item.className='molavi-autopilot-log-row';
                const inputs = row.inputs || {};
                let detail = '';
                if (row.gate==='market' && inputs.url) detail = `نقل‌قول: ${inputs.url}${Number.isFinite(Number(inputs.spread)) ? ` · اختلاف ${(Number(inputs.spread)*100).toLocaleString('fa-IR',{maximumFractionDigits:1})}٪` : ''}${inputs.basis ? ` · مبنا: ${inputs.basis === 'agreement' ? 'هم‌خوانی نقل‌قول‌ها' : 'تطبیق با مرجع'}` : ''}`;
                else if (row.gate==='supplier_price' && inputs.source_revision) detail = `بازبینی: ${String(inputs.source_revision).slice(0,12)}…`;
                else if (row.gate==='publish' && inputs.publish_job) detail = `کار صف: ${inputs.publish_job}`;
                else if (row.gate==='admission' && Number.isFinite(Number(inputs.supplier_price))) detail = `قیمت تأمین‌کننده: ${Number(inputs.supplier_price).toLocaleString('fa-IR')} · قیمت عادی: ${Number(inputs.regular_price).toLocaleString('fa-IR')}${inputs.spec_via ? ` · مشخصات: ${inputs.spec_via === 'auto-import' ? 'واردشدهٔ خودکار' : inputs.spec_via === 'owner-confirm' ? 'تأیید مالک' : 'بازبینی هوش مصنوعی'}` : ''}`;
                else if (row.action==='settings') detail = `تغییر تنظیمات: ${Object.keys(inputs.changed || {}).join('، ') || '—'}`;
                else if (row.action==='override') detail = `لغو تصمیم شمارهٔ ${inputs.overrides_log_id ?? '—'}`;
                item.appendChild(node('p',`${productName(row.product_id)} · ${ruleLabels[row.rule_id] || gateLabels[row.gate] || row.gate || '—'} · ${actionLabels[row.action] || row.action}`));
                item.appendChild(autopilotRiskBadge(row));
                if (detail) item.appendChild(node('p',detail));
                if (row.risk_reason) item.appendChild(node('p',row.risk_reason));
                if (row.gate==='admission' && row.verdict==='pending') item.appendChild(node('p','داوری در انتظار است؛ تا زمان داوری، این تصمیم پرخطر شمرده می‌شود.','molavi-risk-pending'));
                if (Number(row.reviewed_at) > 0) item.appendChild(node('p',`بازبینی شد${row.reviewed_by ? ` — ${row.reviewed_by}` : ''} · ${formatSourceTimestamp(Math.floor(Number(row.reviewed_at)))}`));
                const verdictText = verdictLabels[row.verdict] || row.verdict || 'در انتظار';
                const verdictLine = row.verdict_reason ? `داوری: ${verdictText} — ${row.verdict_reason}` : `داوری: ${verdictText}`;
                item.appendChild(node('p',`${Number.isFinite(Number(row.confidence)) ? `اطمینان: ${Math.round(Number(row.confidence)*100)}٪ · ` : ''}${verdictLine}${row.created_at ? ` · ${formatSourceTimestamp(Math.floor(Number(row.created_at)))}` : ''}${row.action==='shadow' ? ' · سایه' : ''}`));
                const statusBox = node('p','');
                item.appendChild(statusBox);
                const ownerHeld = holds.some(hold => hold.gate==='admission_owner' && String(hold.product_id)===String(row.product_id));
                const isDecision = ['shadow','live'].includes(row.action);
                const isUndone = ['overridden','reverted'].includes(row.result);
                if (row.action!=='override' && row.action!=='settings' && row.result!=='overridden' && row.result!=='reverted') {
                    const overrideButton = node('button','لغو و توقف قانون','button button-secondary');
                    overrideButton.type='button';
                    overrideButton.addEventListener('click', async () => {
                        overrideButton.disabled=true; statusBox.textContent='در حال ثبت لغو…';
                        try {
                            await request('POST',{action:'override_log',log_id:Number(row.id)},undefined,'autopilot');
                            await dashboard('تصمیم لغو و قانون متوقف شد.','success',['autopilot']);
                        } catch(error) { statusBox.textContent=errorText(error.message); overrideButton.disabled=false; }
                    });
                    item.appendChild(overrideButton);
                }
                const rule = rules.find(rule => rule.id === row.rule_id);
                if (rule && rule.state!=='suspended' && row.action!=='settings') {
                    const suspendButton = node('button','توقف قانون','button button-secondary');
                    suspendButton.type='button';
                    suspendButton.addEventListener('click', async () => {
                        suspendButton.disabled=true; statusBox.textContent='در حال توقف قانون…';
                        try {
                            await request('POST',{action:'suspend_rule',rule_id:row.rule_id},undefined,'autopilot');
                            await dashboard('قانون متوقف شد.','success',['autopilot']);
                        } catch(error) { statusBox.textContent=errorText(error.message); suspendButton.disabled=false; }
                    });
                    item.appendChild(suspendButton);
                }
                // Review actions (round six): revert undoes the admission
                // itself (un-publish + owner hold), hold suspends the
                // product's admission, confirm marks the decision reviewed
                // so the digest stops listing it as new.
                if (row.gate==='admission' && isDecision && ['would_admit','admitted'].includes(row.result) && !isUndone) {
                    const revertButton = node('button','بازگردانی پذیرش','button button-secondary');
                    revertButton.type='button';
                    revertButton.addEventListener('click', async () => {
                        revertButton.disabled=true; statusBox.textContent='در حال بازگردانی پذیرش…';
                        try {
                            const result = await request('POST',{action:'revert_decision',log_id:Number(row.id)},undefined,'autopilot');
                            await dashboard(result.hidden===true ? 'پذیرش بازگردانده شد؛ کالا پنهان شد.' : 'پذیرش بازگردانده شد؛ توقف پذیرش ثبت شد.','success',['autopilot']);
                        } catch(error) { statusBox.textContent=errorText(error.message); revertButton.disabled=false; }
                    });
                    item.appendChild(revertButton);
                    if (!ownerHeld) {
                        const holdButton = node('button','توقف پذیرش','button button-secondary');
                        holdButton.type='button';
                        holdButton.addEventListener('click', async () => {
                            holdButton.disabled=true; statusBox.textContent='در حال توقف پذیرش…';
                            try {
                                await request('POST',{action:'hold_decision',log_id:Number(row.id)},undefined,'autopilot');
                                await dashboard('پذیرش کالا متوقف شد.','success',['autopilot']);
                            } catch(error) { statusBox.textContent=errorText(error.message); holdButton.disabled=false; }
                        });
                        item.appendChild(holdButton);
                    }
                }
                if (row.gate==='admission' && ownerHeld) {
                    const releaseButton = node('button','رفع توقف پذیرش','button button-secondary');
                    releaseButton.type='button';
                    releaseButton.addEventListener('click', async () => {
                        releaseButton.disabled=true; statusBox.textContent='در حال رفع توقف پذیرش…';
                        try {
                            await request('POST',{action:'release_hold',product_id:String(row.product_id)},undefined,'autopilot');
                            await dashboard('توقف پذیرش رفع شد.','success',['autopilot']);
                        } catch(error) { statusBox.textContent=errorText(error.message); releaseButton.disabled=false; }
                    });
                    item.appendChild(releaseButton);
                }
                if (isDecision && !isUndone && !Number(row.reviewed_at)) {
                    const confirmButton = node('button','تأیید بازبینی','button button-secondary');
                    confirmButton.type='button';
                    confirmButton.addEventListener('click', async () => {
                        confirmButton.disabled=true; statusBox.textContent='در حال ثبت بازبینی…';
                        try {
                            await request('POST',{action:'confirm_reviewed',log_id:Number(row.id)},undefined,'autopilot');
                            await dashboard('بازبینی ثبت شد.','success',['autopilot']);
                        } catch(error) { statusBox.textContent=errorText(error.message); confirmButton.disabled=false; }
                    });
                    item.appendChild(confirmButton);
                }
                logList.appendChild(item);
            });
            };
            renderLogRows();
            // Decisions first: the holds box used to sit above this list,
            // pushing it about two phone screens down. The settings form
            // moved to the settings view (gear icon); the tab keeps only a
            // read-only master-state line above the list.
            wrap.appendChild(logBox);
            wrap.appendChild(holdsBox);
            return wrap;
        };
        const renderSystem = () => {
        const technical = document.createElement('details');
        technical.className = 'card molavi-technical-details';
        technical.appendChild(node('summary','وضعیت پردازش و اتصال فروشگاه'));

        if (!operations || !operations.operations) {
            technical.appendChild(notice(workbenchErrors.operations || 'وضعیت صف در دسترس نیست.','warning'));
        } else {
            const ops = operations.operations;
            const counts = ops.by_state || {};
            technical.appendChild(node('p',`در انتظار: ${ops.queued || counts.queued || 0} · در حال اجرا: ${counts.running || 0} · منتظر انتشار: ${counts.waiting_publication || 0} · خطا: ${counts.failed || 0} · نیازمند بررسی: ${counts.needs_review || 0} · توقف انتشار: ${ops.publication_holds || 0}`));
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
            const failedPublicationIds = (ops.failed_publication_ids || []).filter(id=>!workbenchOperationIsDismissed({id,kind:'publish'}));
            const failedBatchIds = (ops.failed_batch_ids || []).filter(id=>!workbenchOperationIsDismissed({id,kind:'batch'}));
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
            const queueItems = (ops.items || []).filter(item=>!workbenchOperationIsDismissed(item));
            const operationsTaskFor = item => workbenchOperationDismissalTask(item);
            const nextSteps={automatic_retry:'در نوبت پردازش خودکار',wait_for_processing:'در حال پردازش؛ نتیجهٔ worker را دنبال می‌کنیم',
                recover_expired_lease:'مهلت worker تمام شده؛ رسید برای بازیابی خودکار آماده است',
                resolve_reference_decision:'برای ادامه، تصمیم مرجع را در کارت تطبیق ثبت کنید',
                resolve_publication_hold:'توقف انتشار را رفع کنید؛ صف پس از آن ادامه می‌دهد',
                inspect_failure_and_reconcile:'علت شکست و رسید را تطبیق دهید؛ این مورد خودکار بسته نمی‌شود',
                inspect_result_and_decide:'نتیجهٔ ناقص را در کارت استخراج بررسی کنید'};
            // One row builder for both the active queue and the failed list, so a
            // failed item keeps its card (with the reason) after leaving the queue.
            const appendQueueItem=(container,item)=>{
                const dismissalTask=operationsTaskFor(item);
                if (dismissalTask && workbenchDismissalIndex.has(dismissalTask.task_key)) return;
                const age=Number(item.age_seconds);
                const ageLabel=Number.isFinite(age)?` · ${age<3600?`${Math.floor(age/60)} دقیقه`:`${Math.floor(age/3600)} ساعت`} در صف`:'';
                const next=nextSteps[item.next_action]||'رسید را بررسی کنید';
                const row = node('div', null, 'molavi-operation-queue-item');
                row.appendChild(node('p',`${item.kind === 'batch' ? 'دسته' : ['publish','publication'].includes(item.kind) ? 'انتشار' : 'کار'} · ${displayBatchState(item.state)} · تلاش ${item.attempts || 0}${ageLabel} · گام بعدی: ${next}`));
                if (item.error) row.appendChild(notice(displayBatchError(item.error), 'warning'));
                if (item.id) {
                    const technical = document.createElement('details');
                    technical.appendChild(node('summary', 'شناسه فنی رسید'));
                    technical.appendChild(node('code', item.id));
                    row.appendChild(technical);
                }
                if (dismissalTask) {
                    const hide=node('button','پنهان‌کردن از فهرست این حساب','button button-link-delete');
                    hide.type='button';
                    hide.addEventListener('click',()=>dashboardApp.dismissTask(dismissalTask));
                    row.append(hide);
                }
                container.appendChild(row);
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
                            errorBox(errorText(error.message),container);
                            reconcileOne.disabled=false;
                        }
                    });
                    container.appendChild(reconcileOne);
                }
            };
            if (queueItems.length || ops.next_cursor) {
                const details = document.createElement('details');
                details.className = 'molavi-operation-queue-details';
                const summary=node('summary','');
                const rows=document.createElement('div');
                const pageState={items:[...queueItems],cursor:ops.next_cursor||null,loading:false};
                const updateSummary=()=>{
                    const shown=pageState.items.filter(item=>{
                        const task=operationsTaskFor(item);
                        return !task || !workbenchDismissalIndex.has(task.task_key);
                    }).length;
                    summary.textContent=`سابقهٔ کارهای صف (${ops.actionable_count || queueItems.length}) · نمایش ${shown} از ${pageState.items.length} بارگیری‌شده`;
                };
                const appendItem=item=>appendQueueItem(rows,item);
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
                        const result=await request('GET',undefined,url.toString(),'',{label:'دریافت گزارش بررسی'});
                        const next=result.operations||{};
                        const seen=new Set(pageState.items.map(item=>String(item.id||'')));
                        (Array.isArray(next.items)?next.items:[]).forEach(item=>{
                            const id=String(item.id||'');if(!id||seen.has(id))return;
                            seen.add(id);pageState.items.push(item);appendItem(item);
                        });
                        pageState.cursor=next.next_cursor||null;loadMore.hidden=!pageState.cursor;

                        updateSummary();
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
            // Failed jobs no longer sit in the queue above: they live here, each
            // with its reason on its card, until reconciled or hidden.
            const failedItems = (ops.failed_items || []).filter(item=>!workbenchOperationIsDismissed(item));
            if (failedItems.length) {
                const failedDetails=document.createElement('details');
                failedDetails.className='molavi-operation-queue-details';
                failedDetails.appendChild(node('summary',`موارد ناموفق (${new Intl.NumberFormat('fa-IR').format(failedItems.length)})`));
                const failedRows=document.createElement('div');
                failedItems.forEach(item=>appendQueueItem(failedRows,item));
                failedDetails.appendChild(failedRows);
                technical.appendChild(failedDetails);
            }
            const reportButton=node('button','بررسی اتصال کاتالوگ به فروشگاه','button button-secondary');
            reportButton.type='button';
            const report=node('div');
            reportButton.addEventListener('click',async()=>{
                reportButton.disabled=true; report.replaceChildren(node('p','در حال تطبیق رسیدها، کالاها و فروشگاه…'));
                try {
                    const result=await request('GET',undefined,undefined,'reconciliation',{label:'بررسی رسید و فروشگاه'});
                    workbenchReconciliation=result;
                    workbenchReconciliationError='';

                    const summary=result.summary||{}; const validation=result.validation||{};
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

            const delivery = node('button','ارسال دوبارهٔ تصمیم‌های ثبت‌شده','button button-secondary');
            delivery.type='button';
            delivery.disabled=!config.reviewStoreNonce;
            delivery.addEventListener('click',async()=>{
                delivery.disabled=true;
                try {
                    const result=await drainWordPressWorkflowOutbox();
                    await dashboard(`${result.delivered} تصمیم ثبت‌شده تحویل داده شد.`,'success');
                } catch(error) { errorBox(errorText(error.message),technical); delivery.disabled=false; }
            });
            technical.appendChild(delivery);
            return technical;
        };
        const actionSteps = new Map(products.map(product => [product,workbenchActionKey(product)]));
        const readyActions = new Map(products.map(product => [product,workbenchReadyAction(product,actionSteps.get(product))]));
        const operationItems=operations?.operations?.items || [];
        const publicationWaiting=operationItems.some(item=>item.state==='waiting_publication'&&!workbenchOperationIsDismissed(item));
        dashboardApp.update({
            products,data,errors:workbenchErrors,dismissals:workbenchDismissals,
            systemHolds:holds.filter(hold=>publicationWaiting||!workbenchOperationIsDismissed({id:hold.hold_id,kind:'publication_hold'})),
            archivedOperations:operationItems.filter(workbenchOperationIsDismissed),
            primaryAction:product=>actionSteps.get(product),
            readyAction:product=>readyActions.get(product),actionRank:key=>workbenchActionOrder.indexOf(key),
            nextStep:(product,key)=>workbenchNextStep(product,key),
            wooState:workbenchWooState,wooLabel:workbenchWooLabel,
            renderAction,renderBatch,renderSystem,renderAutopilot,renderLinkChecks,renderMarketAgreement,
            publishFailureReason: error => displayBatchError(error),
            autopilotHold,
            renderOfferReextraction: offerReextraction,
            renderSupplierMessage: supplierMessagePanel,
            renderSourceImage: sourceImageThumbnail,
            renderProductSearch: onlineProductSearch,
            renderMarketPrice: marketPriceReview,
            marketPriceOffers:(product,key)=>{
                const status=product.status;
                if (product.ai.length&&(key==='ai'||!status?.latest_offer_id)) {
                    return product.ai.filter(item=>item.offer_id&&(item.market_tracked||!normalizedAiInteger(item.actual_price||item.consumer_price)))
                        .map(item=>({offer_id:item.offer_id,case_id:item.case_id,name:item.product_name||product.name}));
                }
                return (status?.regular_price_missing||status?.regular_price_due||status?.market_tracked||['no_spread','thin_spread'].includes(status?.sale_offer_blocker))&&status.latest_offer_id
                    ? [{offer_id:status.latest_offer_id,name:product.name}] : [];
            },
            renderReferenceTools: productReferenceTools,
            reconcile:reconcileStorefront,
            restoreKeys:workbenchRestoreKeys,
            requestPublication:async product=>{
                const response=await request('POST',{action:'request_publication',product_id:product.product_id,
                    offer_id:product.status.latest_offer_id},undefined,'product_status');
                if (response.product_id!==product.product_id||!['queued','published'].includes(response.state)) throw new Error('invalid_receipt');
                await dashboard(response.state==='queued'?'انتشار در صف است. نتیجه را در اعلان بالای صفحه ببینید.':'کالا در فروشگاه قابل خرید است.',
                    'success',['product_status','operations']);
            },
            dismissalTask:(product,key)=>({task_key:workbenchCurrentDismissalKey(product,key),queue:key,
                stage:workbenchDismissalStage(key),label:workbenchTaskMeta(product,key).label,
                product_name:product.name,product_id:product.product_id,channel:product.channel}),
            sourceLink:safeSourceLink,formatDate:formatSourceTimestamp,readable:readableWorkflowStatus
        });
        dashboardApp.loading(false,message,messageKind);
        if (warmStart) dashboard();
        return {issues:Object.values(workbenchErrors).filter(Boolean).length};
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

    function aiPriceRole(item, useExtracted = true) {
        const fields = item.decision && item.decision.fields || {};
        const assignedRole = item.reference_assignment && item.reference_assignment.price_role;
        const extractedRole = useExtracted ? item.price_type || item.reference_price_role_hint : '';
        const role = String(fields.price_role || assignedRole || extractedRole || '').toLowerCase();
        if (['unit', 'single', 'per_unit'].includes(role)) return 'unit';
        if (['pack', 'package', 'carton'].includes(role)) return 'package';
        return '';
    }

    function aiPriceRoleChoices(placeholder, preferredRole) {
        const unit = ['unit', 'قیمت هر واحد'];
        const carton = ['package', 'قیمت کل بسته / کارتن'];
        if (preferredRole !== 'package') return [['', placeholder], unit, carton];
        return [['', placeholder], ['package', `${carton[1]} · پیشنهاد اول`], unit];
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
        const prices = new Map();
        const source = String(content || '');
        const pattern = /((?:قیمت\s*فروش(?:\s*ما)?|قیمت\s*هر\s*(?:یک\s*)?(?:بسته|کارتن|عدد|واحد))(?:\s+[^:：\n]+?)?)\s*[:：]\s*([\d۰-۹٠-٩][\d۰-۹٠-٩\s٬,./٫]*)/giu;
        for (const match of source.matchAll(pattern)) {
            const digits = aiSourceDigits(match[2]);
            if (!digits || !Number.isSafeInteger(Number(digits)) || Number(digits) <= 0) continue;
            const afterAmount = source.slice(match.index + match[0].trimEnd().length).split(/\r?\n/, 1)[0].trim();
            const unspecified = /^قیمت\s*فروش(?:\s*ما)?$/u.test(match[1].trim())
                && /^(?:(?:تومان|تومن|ریال)\s*)?[^\p{L}\p{N}]*$/u.test(afterAmount);
            prices.set(digits, unspecified && prices.get(digits) !== false);
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
        const missing = [...sourcePrices.keys()].filter(price => !extractedPrices.has(price) && !packageTotals.has(price));
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
            const key = item.reopened ? `single:${item.case_id}` : hasSupplierPrice && item.variant_group_id ? `variant:${item.variant_group_id}`
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
                    && (item.historical_reextract || item.source_current_check_required || options.sourceStockGap || options.sourcePriceGap?.unverified)),
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
        const label = choice === 'confirm' ? 'تأیید شد؛ بررسی شرایط انتشار در صف است' : 'به‌عنوان «کالا نیست» ثبت شد';
        const summary = `${accepted} از ${items.length} مورد ${label}${failed ? `؛ ${failed} مورد ناموفق ماند${firstError ? ` (${firstError})` : ''}` : ''}${localWarning ? ' رسید محلی وردپرس به همگام‌سازی نیاز دارد.' : ''}`;
        updateActionStatus(progressStatus, summary, failed || localWarning ? 'warning' : 'success');
        if (typeof options.refresh === 'function') {
            await options.refresh(summary, failed || localWarning ? 'warning' : 'success', progressStatus,
                ['ai_confirmations','reference_items','product_status','supplier_prices','sheet_conflicts','operations']);
        } else {
            await dashboard(summary, failed || localWarning ? 'warning' : 'success');
        }
    }

    function referenceSuggestionSection(item, kind, enabled = true) {
        const snapshotId = item.reference_snapshot_id || item.snapshot_id;
        if (!snapshotId) return null;
        const section = node('section', null, 'molavi-reference-suggestion');
        const receipt = item.reference_suggestion;
        const result = receipt && receipt.result;
        if (result) {
            const selected = (result.candidates || []).find(row => row.reference_id === result.reference_id);
            section.appendChild(notice(selected
                ? `مرجع پیشنهادی: ${selected.name}؛ پیش از ثبت، مشخصات و مبنای قیمت را بررسی کنید.`
                : 'پیشنهاد یکتایی پیدا نشد؛ گزینه‌های مرجع را بررسی کنید.', 'info'));
        } else if (receipt) {
            section.appendChild(notice(['queued','running'].includes(receipt.state)
                ? 'درخواست پیشنهاد ثبت شده است؛ نتیجه را با تازه‌سازی صفحه ببینید.'
                : 'پیشنهاد آماده نشد؛ وضعیت این مورد را پس از تازه‌سازی بررسی کنید.', 'info'));
        }
        const button = node('button', 'درخواست پیشنهاد مرجع', 'button');
        button.type = 'button';
        button.disabled = !enabled || Boolean(receipt);
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const response = await request('POST', {action:'suggest_reference', case_kind:kind,
                    case_id:item.case_id, offer_id:item.offer_id, signature:item.signature,
                    snapshot_id:snapshotId}, new URL('/reference/item-reviews', config.apiUrl).href, 'reference_items');
                if (response.case_id !== item.case_id || response.snapshot_id !== snapshotId
                    || !/^reference_suggest:[a-f0-9]{64}$/.test(response.job_id || '')) {
                    throw new Error('invalid_receipt');
                }
                section.appendChild(notice('درخواست ثبت شد؛ برای دیدن نتیجه صفحه را تازه کنید. تأیید مرجع همچنان با شماست.', 'success'));
            } catch (error) {
                errorBox(errorText(error.message), section);
                button.disabled = !enabled;
            }
        });
        section.appendChild(button);
        return section;
    }

    function onlineProductSearch(name) {
        const tools=node('details',null,'molavi-product-search');
        const form=node('form');
        form.action='https://www.google.com/search';form.method='get';form.target='_blank';
        form.acceptCharset='UTF-8';
        form.setAttribute('rel','noopener noreferrer');
        const field=node('input');
        field.type='search';field.name='q';field.value=String(name || '').slice(0,120);field.required=true;
        field.minLength=2;field.maxLength=120;field.setAttribute('aria-label','نام کالا برای جست‌وجوی اینترنتی');
        field.addEventListener('input',()=>field.setCustomValidity(''));
        const locale=node('input');locale.type='hidden';locale.name='hl';locale.value='fa';
        const web=node('button','جست‌وجو در گوگل','button button-primary');web.type='submit';
        const catalog=node('button','جست‌وجو در رضوان پخش','button');catalog.type='submit';
        const status=node('p');
        status.setAttribute('role','status');
        tools.append(node('summary','جست‌وجوی اینترنتی کالا'),form,status);
        form.append(field,locale,web,catalog);
        const results=node('ul');
        tools.appendChild(results);
        form.addEventListener('submit',async event=>{
            const query=field.value.trim();
            if (query.length<2) {
                event.preventDefault();
                field.setCustomValidity(errorText('invalid_search_query'));field.reportValidity();
                return;
            }
            if (event.submitter!==catalog) {
                field.value=query;
                return;
            }
            event.preventDefault();
            catalog.disabled=true;status.textContent='در حال جست‌وجو در رضوان پخش…';results.replaceChildren();
            try {
                const url=new URL('/reference/item-reviews',config.apiUrl);
                url.searchParams.set('online','1');url.searchParams.set('q',query);
                const reply=await request('GET',undefined,url.toString(),'reference_items',{label:'جست‌وجو در رضوان پخش',ttl:30000});
                for (const result of reply.results || []) {
                    const link=node('a',result.title);
                    link.href=result.url;link.target='_blank';link.rel='noopener noreferrer';
                    const row=node('li');row.appendChild(link);results.appendChild(row);
                }
                status.textContent=results.childNodes.length
                    ? (reply.matched_query && reply.matched_query!==query ? `نتیجه‌های رضوان پخش برای «${reply.matched_query}»` : 'نتیجه‌های رضوان پخش')
                    : 'رضوان پخش نتیجه‌ای برنگرداند. نام برند یا یک واژهٔ دیگر را امتحان کنید.';
            } catch (error) { status.textContent=errorText(error.message); }
            finally { catalog.disabled=false; }
        });
        return tools;
    }

    function referenceCatalogSearch(snapshotId, onSelect) {
        const panel=node('section',null,'molavi-reference-catalog-search');
        const form=node('form');
        const input=node('input');
        input.type='search';input.required=true;input.minLength=2;input.maxLength=120;
        input.placeholder='نام کالا، برند یا کد مرجع';
        input.setAttribute('aria-label','جست‌وجو در فهرست مرجع');
        const search=node('button','جست‌وجوی فهرست','button');search.type='submit';
        const status=node('p');status.setAttribute('role','status');status.hidden=true;
        const results=node('ul');results.hidden=true;
        form.append(input,search);panel.append(form,status,results);
        form.addEventListener('submit',async event=>{
            event.preventDefault();
            const query=input.value.trim();
            if (query.length<2) return;
            search.disabled=true;results.hidden=true;results.replaceChildren();
            status.hidden=false;status.textContent='در حال جست‌وجوی فهرست مرجع…';
            try {
                const url=new URL('/reference/item-reviews',config.apiUrl);
                url.search=new URLSearchParams({view:'catalog-search',snapshot_id:snapshotId,q:query});
                const result=await request('GET',undefined,url.href,'reference_items',{label:'جست‌وجوی فهرست مرجع',ttl:45000});
                if (result.snapshot_id!==snapshotId) throw new Error('stale_snapshot');
                for (const row of result.results || []) {
                    const option=node('button',row.name,'button button-secondary');option.type='button';
                    option.addEventListener('click',()=>{
                        results.hidden=true;status.textContent=`برای بررسی انتخاب شد: ${row.name}`;
                        onSelect(row);
                    });
                    const item=node('li');item.append(option);results.append(item);
                }
                results.hidden=!results.childElementCount;
                status.textContent=results.hidden?'نتیجه‌ای پیدا نشد. نام کوتاه‌تر یا کد کالا را امتحان کنید.':'یک کالا را برای مقایسه انتخاب کنید.';
            } catch (error) {status.textContent=errorText(error.message);}
            finally {search.disabled=false;}
        });
        return panel;
    }

    function proceedWithoutReference(items, kind, opts = {}) {
        const button=node('button','ادامه بدون مرجع','button button-secondary');
        button.type='button';button.disabled=opts.enabled===false;
        const container=node('div');
        const status=node('p');status.setAttribute('role','status');
        container.append(button,status);
        button.addEventListener('click',async()=>{
            button.disabled=true;
            status.textContent='در حال ثبت انتخاب…';
            let saved=0;
            try {
                for (const item of items) {
                    const receipt=await request('POST',{action:'proceed_without_reference',case_kind:kind,
                        case_id:item.case_id || '',offer_id:item.offer_id,signature:kind==='ai'?item.signature:''},
                        undefined,'reference_items');
                    if (receipt.offer_id!==item.offer_id || receipt.choice!=='proceed_without_reference' || !receipt.decision_id)
                        throw new Error('invalid_receipt');
                    saved++;
                }
                const message='ادامه بدون مرجع ثبت شد.';
                if (opts.refresh) await opts.refresh(message,'success',status,['reference_items','ai_confirmations','product_status','supplier_prices','operations']);
                else await dashboard(message);
            } catch (error) {
                if (saved) {
                    await dashboard(`${saved} انتخاب ثبت شد؛ ${errorText(error.message)}`,'warning');
                    return;
                }
                status.textContent=errorText(error.message);
                button.disabled=false;
            }
        });
        return container;
    }

    function referenceExtractionRows(offer) {
        const fields=offer.fields || {};
        const number=new Intl.NumberFormat('fa-IR');
        const currency={IRT:'تومان',IRR:'ریال',TOMAN:'تومان',RIAL:'ریال'}[String(fields.currency || '').toUpperCase()] || fields.currency;
        const money=value=>{
            if (!value || /^0+$/.test(aiDisplayKey(value))) return null;
            const whole=normalizedAiInteger(value);
            return [whole ? number.format(whole) : String(value),currency].filter(Boolean).join(' ');
        };
        const sale=money(fields.sale_price);
        const consumer=money(fields.consumer_price);
        const actual=money(fields.actual_price);
        const legacyPackage=money(fields.double_pack_price);
        const packagePrice=money(fields.package_price) || legacyPackage;
        const consumerPackage=money(fields.consumer_package_price);
        const detected=money(fields.detected_price);
        const listed=money(fields.price);
        const role=aiPriceRole(fields);
        const confidence=Number(fields.confidence);
        return [
            ['عنوان استخراج‌شده',offer.title],['طعم / مدل',offer.variant],
            ['قیمت فروش تأمین‌کننده',sale || 'ثبت نشده'],['قیمت عادی / مصرف‌کننده',consumer || actual || 'ثبت نشده'],
            ['قیمت عادی در ستون قدیمی',consumer && actual!==consumer ? actual : null],
            ['قیمت کل بسته / کارتن',packagePrice],['قیمت عادی کل بسته / کارتن',consumerPackage],
            ['قیمت بسته در ستون قدیمی',legacyPackage!==packagePrice ? legacyPackage : null],
            ['مبلغ خوانده‌شده از پیام',detected && detected!==sale && detected!==packagePrice ? detected : null],
            ['قیمت ثبت‌شده',listed && listed!==sale && listed!==detected && listed!==packagePrice ? listed : null],
            ['مبنای ثبت‌شده در استخراج',role==='unit' ? 'هر واحد' : role==='package' ? 'بسته / کارتن' : fields.price_type ? 'نامشخص؛ نیازمند بررسی' : null],
            ['واحد پول',sale || consumer || actual || packagePrice || consumerPackage || detected || listed ? null : currency],
            ['بسته‌بندی',fields.packaging],['تعداد در بسته / کارتن',fields.package_count],['حداقل خرید',fields.min_qty],
            ['حجم',fields.volume],['وزن',fields.weight],['موجودی',fields.stock_status ? aiStockLabel(fields.stock_status) : null],
            ['برند',fields.brand],['دسته',fields.category],
            ['اطمینان استخراج',fields.confidence!==undefined && confidence>=0 && confidence<=1
                ? new Intl.NumberFormat('fa-IR',{style:'percent',maximumFractionDigits:1}).format(confidence) : fields.confidence],
            ['دلیل بررسی استخراج',fields.review_reason ? aiReviewReason(fields.review_reason) : null],['توضیحات',fields.description]
        ];
    }

    function referenceOfferFacts(offerId, fallbackRows, reviewRows = []) {
        const container=node('div',null,'molavi-reference-offer-facts');
        const pickerLabel=node('label',null,'molavi-reference-offer-picker');pickerLabel.hidden=true;
        const picker=node('select');picker.setAttribute('aria-label','پیشنهاد برای نمایش مشخصات');
        pickerLabel.append(node('span','نمایش مشخصات پیشنهاد'),picker);
        const status=node('p');status.hidden=true;status.setAttribute('role','status');
        const facts=node('div');facts.appendChild(reviewFacts([...fallbackRows,...reviewRows]));
        container.append(pickerLabel,facts,status);
        let requested=false;
        const load=async()=>{
            if (requested || !offerId) return;
            requested=true;status.hidden=false;status.textContent='در حال دریافت مشخصات استخراج‌شده…';
            try {
                const url=new URL('/reference/item-reviews',config.apiUrl);
                url.searchParams.set('view','extracted-titles');url.searchParams.set('offer_id',offerId);
                const {titles,reviewed_offer:reviewed}=await request('GET',undefined,url.toString(),'reference_items',{ttl:30000});
                const offers=[...titles];
                let index=offers.findIndex(offer=>offer.current);
                if (index<0 && reviewed?.title) { offers.unshift(reviewed);index=0; }
                if (!offers.length) {
                    status.textContent='مشخصات استخراج‌شده‌ای برای این پیام ثبت نشده است.';
                    return;
                }
                offers.forEach((offer,position)=>{
                    const name=aiTitleContainsAttribute(offer.title,offer.variant) || !offer.variant
                        ? offer.title : `${offer.title} · ${offer.variant}`;
                    const option=node('option',offer.current ? `در حال بررسی · ${name}` : name);
                    option.value=String(position);picker.appendChild(option);
                });
                picker.value=String(Math.max(0,index));pickerLabel.hidden=offers.length===1;
                const render=()=>{
                    const offer=offers[Number(picker.value)];
                    facts.replaceChildren(reviewFacts([...referenceExtractionRows(offer),...(offer.current ? reviewRows : [])]));
                };
                picker.addEventListener('change',render);render();
                status.hidden=true;
            } catch (error) {
                requested=false;
                const retry=node('button','دریافت دوبارهٔ مشخصات','button');retry.type='button';
                retry.addEventListener('click',load);
                status.replaceChildren(document.createTextNode(`${errorText(error.message)} `),retry);
            }
        };
        return {container,load};
    }

    function productReferenceTools(product) {
        const offerId=product.status?.latest_offer_id;
        if (!offerId) return null;
        const tools=node('section');
        const load=async()=>{
            tools.textContent='در حال دریافت مرجع کالا…';
            try {
                const url=new URL('/reference/item-reviews',config.apiUrl);
                url.search=new URLSearchParams({view:'reference-choose',offer_id:offerId});
                const {cases}=await request('GET',undefined,url.href,'reference_items',{ttl:30000});
                if (!cases?.length) {
                    tools.replaceChildren(node('p','مرجع این کالا در حال حاضر قابل انتخاب نیست.'));
                    const link=ownerWorkflowLink('molavi-reference-snapshots','دیدن وضعیت فهرست مرجع');
                    if (link) tools.append(link);
                    return;
                }
                tools.replaceChildren(referenceItemCard(cases[0],Boolean(config.reviewStoreNonce),{embedded:true,apiKey:'reference_items',parentName:product.name}));
            } catch (error) {
                const retry=node('button','دریافت دوباره','button');retry.type='button';retry.addEventListener('click',load);
                tools.replaceChildren(notice(errorText(error.message),'error'),retry);
            }
        };
        load();
        return tools;
    }

    function marketPriceReview(offerId, productName) {
        const section = node('details', null, 'molavi-market-price-review');
        section.dataset.offerId=offerId;
        const heading = node('summary','قیمت عادی');
        heading.hidden=true;
        const body = node('div',null,'molavi-market-price-body');
        const endpoint = new URL('/reference/item-reviews',config.apiUrl);
        const readUrl = new URL(endpoint);
        readUrl.searchParams.set('view','market-prices');
        readUrl.searchParams.set('offer_id',offerId);
        let timer = null, remaining = 0, busy = false;
        section.append(heading,body);

        function photo(product,thumbnail=false,onLoad=null) {
            const fallback=product.image_file_id && /^[\w-]{8,300}$/.test(product.image_file_id)
                ?new URL('/media/file/'+encodeURIComponent(product.image_file_id),config.apiUrl).href:'';
            const direct=thumbnail?(product.thumbnail_url||product.image_url||fallback):(product.image_url||fallback);
            let proxy=null;
            if (product.url&&config.apiProxyUrl&&config.apiProxyNonce) {
                proxy=new URL(config.apiProxyUrl,location.href);
                proxy.search=new URLSearchParams({action:'molavi_owner_market_photo',nonce:config.apiProxyNonce,offer_id:offerId,listing:product.url});
            }
            if (!/^https:\/\//i.test(direct||'')&&!proxy) return node('span','تصویر در دسترس نیست','molavi-market-photo-empty');
            const image=node('img');
            image.alt=product.title||productName;
            image.loading='lazy';
            image.decoding='async';
            image.referrerPolicy='no-referrer';
            if (onLoad) image.addEventListener('load',onLoad,{once:true});
            let fallbackTried=!direct;
            image.addEventListener('error',()=>{
                if (!fallbackTried&&proxy) {
                    fallbackTried=true;
                    image.src=proxy.href;
                } else image.replaceWith(node('span','تصویر در دسترس نیست','molavi-market-photo-empty'));
            });
            image.src=direct||proxy.href;
            return image;
        }
        function showPhotos(quote,current=null) {
            const dialog=node('dialog',null,'md-dialog molavi-market-dialog');
            const close=node('button','بستن','button');close.type='button';
            close.addEventListener('click',()=>dialog.close());
            const title=node('h2',current?'مقایسهٔ کالاها':quote.title);
            title.id='molavi-market-dialog-title';
            dialog.setAttribute('aria-labelledby',title.id);
            const images=node('div',null,current?'molavi-market-comparison':'molavi-market-preview');
            for (const [product,label] of current?[[current,'کالای کارت'],[quote,'آگهی فروشگاه']]:[[quote,'']]) {
                const figure=node('figure');
                figure.append(photo(product));
                if (label) {
                    const caption=node('figcaption');
                    caption.append(node('small',label),node('strong',product.title||productName));
                    figure.append(caption);
                }
                images.append(figure);
            }
            dialog.append(title,images,close);
            root.append(dialog);
            dialog.addEventListener('close',()=>dialog.remove(),{once:true});
            dialog.showModal();
        }
        function quoteRow(quote, result, selector) {
            const row = node('section',null,'molavi-market-quote');
            const link = node('a',quote.merchant,'molavi-market-source');
            const url = new URL(quote.url);
            if (url.protocol !== 'https:') return row;
            link.href = url.href;link.target = '_blank';link.rel = 'noopener noreferrer';
            link.title = quote.title;
            const selected = quote.url === result.selected_url;
            const regular = Number(quote.regular_unit_price);
            const pack = Number(quote.pack_count);
            const heading=node('div',null,'molavi-market-quote-heading');
            let choose;
            if (quote.url) {
                const preview=node('button',null,'molavi-market-thumbnail');
                preview.type='button';preview.setAttribute('aria-label','بزرگ‌نمایی تصویر آگهی');
                preview.append(photo(quote,true,()=>{if(choose) choose.hidden=false;}));preview.addEventListener('click',()=>showPhotos(quote));
                heading.append(preview);
            }
            const listing=node('div');
            const choice=node('label',null,'molavi-market-choice');
            choice.append(selector,node('strong',quote.title));listing.append(choice,link);
            heading.append(listing);row.append(heading);
            const observed = node('div',null,'molavi-market-observed');
            observed.append(node('span',regular>0?'قیمت عادی هر واحد':'قیمت فروش مشاهده‌شده برای هر واحد'),
                node('strong',amount(regular>0?regular:quote.unit_price)));
            row.append(observed);
            const notes = [];
            if (regular>0 && Number(quote.unit_price)!==regular) notes.push(`فروش هر واحد ${amount(quote.unit_price)}`);
            if (pack>1&&Number(quote.regular_price)>0) {
                const count=new Intl.NumberFormat('fa-IR').format(pack);
                notes.push(`بستهٔ ${count} عددی · قیمت عادی بسته ${amount(quote.regular_price)}`);
                if (Number(quote.current_price)!==Number(quote.regular_price))
                    notes.push(`فروش بسته ${amount(quote.current_price)}`);
            }
            if (notes.length) row.append(node('small',notes.join(' · ')));
            const source = node('div',null,'molavi-market-source-line');
            row.append(source);
            if (selected) source.append(node('span','مرجع انتخاب‌شده'));
            const compare=node('button','مقایسه','button');compare.type='button';
            compare.addEventListener('click',()=>showPhotos(quote,result.product||{title:productName}));
            source.append(compare);
            if ((!quote.image_url||quote.image_url!==result.product?.selected_photo_url) && (!result.stale||selected)) {
                choose=node('button','استفاده به‌عنوان تصویر اصلی','button');choose.type='button';choose.hidden=!quote.image_url;
                choose.addEventListener('click',()=>load({action:'use_market_product_image',offer_id:offerId,url:quote.url}));
                source.append(choose);
            }
            return row;
        }
        function quoteChoices(result) {
            const recommended=result.suggested_price;
            const quotes=[recommended,...(result.candidates||[]).filter(row=>row.url!==recommended.url)];
            const form=node('form',null,'molavi-market-choice-form');
            const group=node('fieldset');
            group.append(node('legend','منبع قیمت عادی'));
            const submit=node('button','انتخاب پیوند','button button-primary');submit.type='submit';
            const selected=quotes.some(row=>row.url===result.selected_url)?result.selected_url:recommended.url;
            for (const quote of quotes) {
                const input=document.createElement('input');
                input.type='radio';input.name='market_quote';input.value=quote.url;input.checked=quote.url===selected;
                input.setAttribute('aria-label',[`${quote.title} · ${quote.merchant}`,
                    amount(quote.regular_unit_price||quote.unit_price)].filter(Boolean).join(' · '));
                group.append(quoteRow(quote,result,input));
            }
            function update() {
                const url=form.elements.market_quote?.value;
                submit.disabled=!url||url===result.selected_url;
            }
            form.append(group,submit);update();
            form.addEventListener('change',update);
            form.addEventListener('submit',event=>{
                event.preventDefault();
                const url=form.elements.market_quote?.value;
                if (url&&url!==result.selected_url) load({action:'select_market_price_source',offer_id:offerId,url});
            });
            return form;
        }
        function linkForm() {
            const details = node('details');
            details.append(node('summary','ثبت پیوند فروشگاه'));
            const form = node('form',null,'molavi-market-link-form');
            const input = document.createElement('input');
            input.type='url';input.required=true;input.dir='ltr';input.placeholder='https://…';
            input.setAttribute('aria-label','پیوند صفحهٔ کالا در فروشگاه');
            const save = node('button','بررسی پیوند','button');save.type='submit';
            form.append(input,save);
            form.addEventListener('submit',event=>{
                event.preventDefault();
                load({action:'add_market_price_url',offer_id:offerId,url:input.value.trim()});
            });
            details.append(form);
            return details;
        }
        function render(result) {
            section.dataset.lookupKey=result.lookup_key||'';
            body.replaceChildren();
            const messages = {
                idle:'برای بررسی قیمت عادی، جست‌وجوی بازار را شروع کنید یا پیوند یک فروشگاه را وارد کنید.',
                unconfigured:'اتصال به سرویس جست‌وجوی وب هنوز تنظیم نشده است.',
                not_needed:'این کالا در حال حاضر به پیشنهاد قیمت بازار نیاز ندارد.',
                ambiguous_identity:'برای پیدا کردن قیمت قابل مقایسه، ابتدا نوع دقیق کالا را مشخص کنید.',
                queued:'در صف بررسی قیمت بازار',searching:'در حال بررسی قیمت بازار…',cancelled:'بررسی دستی لغو شد.',
                no_match:'برای این کالا قیمت قابل مقایسه‌ای تأیید نشد.',
                retry:'بررسی منابع کامل نشد. در نوبت بعد دوباره بررسی می‌شود.',
                provider_auth:'اتصال سرویس جست‌وجوی وب نیاز به بررسی دارد.',
                quota:'سهمیهٔ جست‌وجو پر شده است. بررسی در نوبت بعد ادامه می‌یابد.'
            };
            const tracking=result.tracking;
            if (tracking) {
                const status=node('p',tracking.failure
                    ? 'بررسی منبع ناموفق بود. '+(tracking.regular_price?'آخرین قیمت تأییدشده '+amount(tracking.regular_price):'قیمت عادی در دسترس نیست.')
                    : 'بررسی روزانه فعال است'+(tracking.regular_price?' · '+amount(tracking.regular_price):''));status.setAttribute('role','status');
                body.append(status);
            } else if (messages[result.state]) {
                const status=node('p',messages[result.state]);status.setAttribute('role','status');body.append(status);
            }
            const quote = result.suggested_price;
            if (quote) {
                if (result.stale) body.append(node('small','آخرین قیمت معتبر ثبت‌شده'));
                body.append(quoteChoices(result));
            }
            if (result.selected_url && !result.candidates?.some(row=>row.url===result.selected_url))
                body.append(node('p','قیمت منبع انتخاب‌شده در آخرین بررسی تأیید نشد.'));
            const times = [];
            const last=tracking?.checked_at||result.checked_at;
            const next=tracking?.next_at||result.next_at;
            if (last) times.push(`آخرین بررسی ${formatSourceTimestamp(Math.floor(last))}`);
            if (next && next*1000>Date.now()) times.push(`نوبت بعد ${formatSourceTimestamp(Math.floor(next))}`);
            if (times.length) body.append(node('small',times.join(' · ')));
            if (result.product?.selected_photo) body.append(node('small','تصویر اصلی انتخاب شده است.'));
            const due=result.next_at&&result.next_at*1000<=Date.now();
            if (['idle','cancelled'].includes(result.state)||(result.state==='queued'&&!result.prioritized)
                ||(['ready','no_match','retry'].includes(result.state)&&due)) {
                const search = node('button',result.state==='queued'?'بررسی این کالا زودتر':due?'جست‌وجوی دوبارهٔ قیمت بازار':'یافتن قیمت بازار',
                    'button button-primary');search.type = 'button';
                search.addEventListener('click',()=>{
                    remaining=6;load({action:'request_market_prices',offer_id:offerId});
                });
                body.append(search);
            }
            if (!['not_needed','ambiguous_identity'].includes(result.state)) body.append(linkForm());
            if (remaining>0&&['queued','searching'].includes(result.state)) {
                remaining--;
                timer=setTimeout(()=>{if(section.open&&section.isConnected)load();},12000);
            }
        }
        async function load(payload) {
            if (busy) return;
            busy=true;clearTimeout(timer);
            const buttons=payload?[...body.querySelectorAll('button:not(:disabled)')]:[];
            buttons.forEach(button=>button.disabled=true);
            const progress=node('p',payload?.action==='add_market_price_url'?'در حال بررسی صفحهٔ فروشگاه…'
                :payload?.action==='use_market_product_image'?'در حال ذخیرهٔ تصویر…':'در حال دریافت قیمت‌های بررسی‌شده…');
            progress.setAttribute('role','status');
            if (payload||!body.childNodes.length) body.prepend(progress);
            try {render(await request(payload?'POST':'GET',payload,(payload?endpoint:readUrl).href,'reference_items',{ttl:remaining===6?10000:0}));}
            catch(error) {
                body.querySelector('.notice-error')?.remove();
                body.prepend(notice(errorText(error.message),'error'));
            }
            finally {progress.remove();buttons.forEach(button=>button.disabled=false);busy=false;}
        }
        section.addEventListener('molavi-market-activity',()=>{
            if (section.open) { remaining=0;load(); }
        });
        section.addEventListener('toggle',()=>{
            if (section.open) { remaining=6;load(); }
            else clearTimeout(timer);
        });
        section.open=true;
        return section;
    }

    function aiReferenceAssignmentSection(item, contextStored, decisionsEnabled, options = {}) {
        if (!item.reference_snapshot_id) return null;
        const candidates = Array.isArray(item.reference_candidates)
            ? item.reference_candidates.filter(candidate => candidate && candidate.reference_id
                && String(candidate.name || '').trim()).slice(0, 12) : [];
        const section = node('section', null, 'molavi-ai-reference-assignment');
        const suggestion=referenceSuggestionSection(item,'ai',decisionsEnabled);
        const assignment = item.reference_assignment || null;
        if (assignment && assignment.choice === 'match') {
            section.appendChild(notice(`مرجع ${assignment.reference_name ? `«${assignment.reference_name}»` : 'انتخاب‌شده'} ذخیره شد · ${assignment.price_role === 'package' ? 'قیمت بسته/کارتن' : 'قیمت هر واحد'}؛ قیمت و موجودی هنوز جداگانه بررسی می‌شوند.`, 'success'));
        } else if (item.reference_match_id) {
            section.appendChild(notice(`مرجع فعلی: ${item.reference_match_name || 'تطبیق ثبت‌شده'}. برای تغییر، یک گزینهٔ زیر را انتخاب کنید.`, 'info'));
        }
        const compareDetails=node('details',null,'molavi-reference-compare-details');
        const sourceFacts=referenceOfferFacts(item.offer_id,referenceExtractionRows({
            title:item.product_name,variant:item.variation_type,fields:item
        }));
        compareDetails.append(node('summary','مقایسه نام، ویژگی‌ها و قیمت'),sourceFacts.container);
        compareDetails.addEventListener('toggle',()=>{if(compareDetails.open) sourceFacts.load();});

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
        placeholder.textContent = 'یک کالا انتخاب کنید';
        referenceSelect.appendChild(placeholder);
        const candidateById = new Map();
        function addCandidate(candidate, suggested = false) {
            const referenceId = String(candidate.reference_id || '');
            if (!referenceId || candidateById.has(referenceId)) return;
            candidateById.set(referenceId, candidate);
            const option = document.createElement('option');
            option.value = referenceId;
            const hint = candidate.price_role_hint && candidate.price_role_hint.role;
            const priceText = hint === 'unit' ? ' · قیمت مرجع واحد'
                : hint === 'package' ? ' · قیمت مرجع بسته' : '';
            option.textContent = `${candidate.name}${priceText}${suggested ? ' · پیشنهاد خودکار' : ''}`;
            referenceSelect.appendChild(option);
        }
        candidates.forEach(candidate => addCandidate(candidate));
        const suggestionResult = item.reference_suggestion && item.reference_suggestion.result;
        let suggestedCandidate = null;
        if (suggestionResult && suggestionResult.reference_id) {
            suggestedCandidate = (suggestionResult.candidates || []).find(row => row
                && row.reference_id === suggestionResult.reference_id
                && String(row.name || '').trim()) || null;
            if (suggestedCandidate) addCandidate(suggestedCandidate, true);
        }
        const roleSelect = document.createElement('select');
        roleSelect.setAttribute('aria-label', 'مبنای قیمت مرجع');
        aiPriceRoleChoices('انتخاب مبنای قیمت', options.preferredPriceRole)
            .forEach(([value, label]) => {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                roleSelect.appendChild(option);
            });
        referenceSelect.value = assignment && candidateById.has(assignment.reference_id)
            ? assignment.reference_id
            : (suggestedCandidate && candidateById.has(String(suggestedCandidate.reference_id))
                ? String(suggestedCandidate.reference_id) : '');
        roleSelect.value = assignment && ['unit', 'package'].includes(assignment.price_role)
            ? assignment.price_role : '';
        let roleTouched = Boolean(assignment && ['unit', 'package'].includes(assignment.price_role));
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
            } else compare.appendChild(reviewFacts([['برند',candidate.brand],['نوع',candidate.variant],
                ['حجم',candidate.volume],['وزن',candidate.weight],['تعداد در بسته',candidate.package_count]]));
        };
        const save = node('button', assignment ? 'ذخیرهٔ مرجع این مورد' : 'ثبت مرجع این مورد', 'button button-secondary');
        save.type = 'button';
        const updateEnabled = () => {
            save.disabled = !contextStored || !decisionsEnabled || !referenceSelect.value || !roleSelect.value;
        };
        referenceSelect.addEventListener('change', () => {
            if (!roleTouched) {
                const selected = candidateById.get(referenceSelect.value);
                const suggestedRole = selected && (options.preferredPriceRole
                    || (!options.priceBasisNeedsReview && selected.price_role_hint?.role));
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
        if (suggestedCandidate && !assignment && referenceSelect.value === String(suggestedCandidate.reference_id))
            referenceSelect.dispatchEvent(new Event('change'));
        renderComparison();
        updateEnabled();
        chooser.append(referenceSelect, roleSelect, save);
        chooser.hidden=!candidateById.size;
        compare.hidden=!referenceSelect.value;
        referenceSelect.addEventListener('change',()=>{compare.hidden=!referenceSelect.value;});
        section.append(referenceCatalogSearch(item.reference_snapshot_id, candidate=>{
            addCandidate(candidate);chooser.hidden=false;referenceSelect.value=candidate.reference_id;
            referenceSelect.dispatchEvent(new Event('change'));roleSelect.focus();
        }),chooser,compare,compareDetails);
        if (suggestion) {
            const extras=node('details');extras.append(node('summary','پیشنهاد خودکار'),suggestion);section.append(extras);
        }
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
            ? `گروه ${new Intl.NumberFormat('fa-IR').format(relatedItems.length)} نوع هم‌قیمت` : item.reopened?'بازگشایی':'استخراج');
        if (item.image_file_id!==options.parentImageId) {
            const photo=sourceImageThumbnail(item.image_file_id);
            if (photo) header.prepend(photo);
        }
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
        extractedAttributes.push(['دسته',displayAttributes.category||'ثبت نشده']);
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
        const priceBasisNeedsReview = !item.reopened && packageCount > 1 && regularPrice > 0 && visibleSupplierPrice > regularPrice
            && aiSourceSalePrices(item.original_message).get(String(visibleSupplierPrice)) === true;
        const cartonUnitPrice = priceBasisNeedsReview ? visibleSupplierPrice / packageCount : 0;
        const plausibleCartonPrice = Math.round(cartonUnitPrice) > 0 && cartonUnitPrice <= regularPrice
            && (!packagePrice || packagePrice === visibleSupplierPrice);
        const preferredPriceRole = plausibleCartonPrice ? 'package' : '';
        const priceFacts = [
            ['قیمت فروش تأمین‌کننده', visibleSupplierPrice ? amount(visibleSupplierPrice) : 'در پیام پیدا نشد'],
            priceBasisNeedsReview ? ['مبنای قیمت', 'در پیام مشخص نشده؛ نیاز به تأیید']
                : [item.reopened?'مبنای قیمت ثبت‌شده':'مبنای قیمت در استخراج', roleLabel]
        ];
        if (preferredPriceRole) {
            const count = new Intl.NumberFormat('fa-IR').format(packageCount);
            priceFacts.push(['پیشنهاد اول', `${amount(visibleSupplierPrice)} برای بستهٔ ${count}‌تایی · ${amount(Math.round(cartonUnitPrice))} برای هر واحد`]);
        }
        priceFacts.push(
            ['قیمت مصرف‌کننده / عادی', regularPrice ? amount(regularPrice) : null],
            ['موجودی اعلام‌شده', aiStockLabel(item.stock_status)],
            ['حداقل خرید', item.min_qty || 'ثبت نشده']
        );
        if (!options.embedded) priceFacts.push(['تأمین‌کننده', item.channel || 'نامشخص']);
        if (packagePrice && packagePrice !== visibleSupplierPrice) {
            priceFacts.push(['قیمت کل بسته / کارتن تأمین‌کننده', amount(packagePrice)]);
        }
        if (regularPackagePrice) priceFacts.push(['قیمت عادی کل بسته / کارتن', amount(regularPackagePrice)]);
        const priceEvidence = node('section',null,'molavi-product-price-evidence');
        priceEvidence.appendChild(reviewFacts(priceFacts, 'molavi-review-facts molavi-ai-price-facts'));
        const reviewReason = priceBasisNeedsReview
            ? 'اگر مبلغ فروش برای یک واحد باشد، از قیمت مصرف‌کننده بیشتر است.'
            : aiReviewReason(item.reason);
        priceEvidence.appendChild(reviewFacts([['دلیل بررسی', reviewReason]], 'molavi-review-facts molavi-review-reason'));
        card.appendChild(priceEvidence);
        const sourceDates = aiSourceDateFacts(item);
        const sourceTools = node('div', null, 'molavi-card-source-tools');
        const sourcePostUrl = aiSourcePostUrl(item);
        const originalPost = safeSourceLink(sourcePostUrl,
            item.historical_reextract ? 'باز کردن پیام مبدأ (تاریخی)'
                : sourcePostUrl && sourcePostUrl !== item.source_post_url ? 'باز کردن پست تلگرام' : 'باز کردن پیام اصلی');
        if (originalPost) sourceTools.appendChild(originalPost);
        if (sourceTools.childNodes.length) card.appendChild(sourceTools);
        const sourcePriceNeedsReview = Boolean(options.sourcePriceGap?.missing?.length);
        const sourcePriceUnclear = Boolean(options.sourcePriceGap?.unverified);
        const sourceVariantNeedsReview = Boolean(options.sourceVariantGap);
        const sourceStockNeedsReview = Boolean(options.sourceStockGap);
        if (options.sourcePriceGap) {
            if (options.sourcePriceGap.unverified) {
                card.appendChild(notice('برچسب مبلغ فروش روشن نیست. پیام و قیمت فعلی تأمین‌کننده را بررسی کنید.', 'warning'));
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
        const held = relatedItems.some(row => row.publication_held);
        const blockedBySourceGap = sourcePriceNeedsReview || sourceVariantNeedsReview;
        const sourceVerificationRequired = relatedItems.some(row => row
            && (row.historical_reextract || row.source_current_check_required)) || sourceStockNeedsReview || sourcePriceUnclear;
        if (held) {
            const link=options.holdTarget
                ? ownerWorkflowAnchor(options.holdTarget,'باز کردن دسته')
                : ownerWorkflowLink('molavi-publication-holds','باز کردن دسته‌های متوقف‌شده');
            card.appendChild(reviewNextStep('ابتدا توقف همین دسته را بررسی کنید.',link));
            return card;
        }
        const reextraction=item.offer_id&&!item.reopened?offerReextraction(item.offer_id):null;
        if (item.original_message || sourceDates.length) {
            const evidence = node('details');
            evidence.appendChild(node('summary',item.original_message?'متن و تاریخ پیام مبدأ':'تاریخ پیام مبدأ'));
            if (sourceDates.length) evidence.appendChild(reviewFacts(sourceDates,'molavi-review-facts molavi-ai-source-date-facts'));
            if (item.original_message) evidence.appendChild(node('pre',item.original_message));
            card.appendChild(evidence);
        }
        if (item.reopened && item.decision) {
            card.appendChild(notice('تصمیم ثبت شد. برای تکمیل به‌روزرسانی دوباره تلاش کنید.', 'warning'));
            const retry = node('button','تکمیل ثبت تصمیم','button button-primary');
            retry.type='button';
            retry.disabled=!contextStored||!decisionsEnabled;
            retry.addEventListener('click',()=>submitAiCaseDecisions([item],item.decision.choice,item.decision.fields,card,[retry],true,options));
            card.appendChild(retry);
            return card;
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
        const reject = node('details');
        const notProduct = node('button', relatedItems.length > 1
            ? `ردّ هر ${relatedItems.length} نوع؛ کالا نیست` : item.reopened?'ثبت رد پیشنهاد':'این مورد کالا نیست', 'button');
        notProduct.type='button';
        notProduct.disabled=!contextStored || !decisionsEnabled;
        notProduct.addEventListener('click',()=>submitAiCaseDecisions(relatedItems,'not_product',{},card,[notProduct],false,options));
        reject.append(node('summary',item.reopened?'رد پیشنهاد منتشرشده':'این پیام پیشنهاد کالا نیست'));
        if (item.reopened) reject.appendChild(node('p','این تصمیم فروش کالا را متوقف می‌کند.'));
        reject.appendChild(notProduct);
        if (blockedBySourceGap) {
            card.appendChild(node('h4','گام فعلی · تکمیل استخراج پیام'));
            if (reextraction) {reextraction.open=true;card.appendChild(reextraction);}
            card.appendChild(node('p','اگر بازاستخراج دادهٔ جاافتاده را بازیابی نکرد، پیام تازه یا روشن‌تر را به ربات بفرستید.'));
            if (!item.message_review) card.appendChild(reject);
            return card;
        }
        if (!visibleSupplierPrice) {
            card.appendChild(notice('قیمت فروش ثبت نشده است. پس از بررسی پیام فعلی، مبلغ را در فرم اصلاح مشخصات وارد کنید.','warning'));
        }

        const referenceEligible=relatedItems.filter(row=>!row.reopened);
        const unmatched = referenceEligible.filter(row=>!row.reference_waived
            && row.reference_assignment?.choice!=='match' && !row.reference_match_id);
        const matching = unmatched.filter(row=>row.reference_snapshot_id && row.reference_candidates?.some(candidate=>
            candidate?.reference_id && String(candidate.name || '').trim()));
        const assignmentOptions={...options,groupedVariants:relatedItems.length>1,priceBasisNeedsReview,preferredPriceRole};
        if (matching.length) {
            card.appendChild(node('h4','گام فعلی · انتخاب مرجع و مبنای قیمت'));
            matching.forEach(row=>{
                if (relatedItems.length>1) card.appendChild(node('h5',row.variation_type || row.product_name));
                card.appendChild(aiReferenceAssignmentSection(row,contextStored,decisionsEnabled,assignmentOptions));
            });
            card.append(proceedWithoutReference(unmatched,'ai',{
                enabled:contextStored&&decisionsEnabled,refresh:options.refresh}));
            if (reextraction) card.appendChild(reextraction);
            return card;
        }
        if (unmatched.length || referenceEligible.some(row=>row.reference_waived)) {
            const suggestions=node('details');
            suggestions.append(node('summary','انتخاب مرجع'));
            const searchable=referenceEligible.filter(row=>row.reference_snapshot_id&&(unmatched.includes(row)||row.reference_waived));
            searchable.forEach(row=>suggestions.appendChild(aiReferenceAssignmentSection(row,contextStored,decisionsEnabled,assignmentOptions)));
            if (!searchable.length) {
                suggestions.appendChild(node('p','فهرست مرجع در حال حاضر قابل استفاده نیست.'));
                const link=ownerWorkflowLink('molavi-reference-snapshots','دیدن وضعیت فهرست مرجع');
                if (link) suggestions.appendChild(link);
            }
            if (unmatched.length) suggestions.appendChild(proceedWithoutReference(unmatched,'ai',{
                enabled:contextStored&&decisionsEnabled,refresh:options.refresh}));
            card.appendChild(suggestions);
        }

        const fields = item.decision && item.decision.fields || {};
        const role = aiPriceRole(item, !priceBasisNeedsReview);
        const isPackage = ['pack', 'package', 'carton'].includes(String(role).toLowerCase());
        const price = fields.sale_price ?? (isPackage && item.package_price ? item.package_price : (item.sale_price || item.detected_price || ''));
        const confirmation=node('div',null,'molavi-ai-confirmation-step');
        const quickActions = node('div', null, 'molavi-ai-quick-actions');
        confirmation.appendChild(node('h4','گام فعلی · تأیید مشخصات'));
        const quickButtons = [notProduct];
        let currentPriceVerified = null;
        let manualConfirmButton = null;
        const sourceVerificationItems = relatedItems.filter(row => row
            && (row.historical_reextract || row.source_current_check_required || sourceStockNeedsReview || sourcePriceUnclear));
        if (sourceVerificationRequired) {
            const verification=node('section',null,'molavi-source-confirmation');
            verification.appendChild(node('h4','قیمت و موجودی امروز'));
            verification.appendChild(node('p','پس از بررسی منبع، تأیید را علامت بزنید تا فرم مشخصات باز شود.'));
            if (item.historical_reextract && !item.reopened) {
                const recentSupplierPosts = safeSourceLink(aiSourceChannelUrl(item), 'دیدن پست‌های جدیدتر تأمین‌کننده');
                if (recentSupplierPosts) verification.appendChild(recentSupplierPosts);
                verification.appendChild(notice(`آخرین زمان مؤثر منبع ${sourceAgeText(item.source_updated_at || item.source_update_at || item.message_timestamp || item.source_created_at)} است. قیمت را از منبع فعلی بررسی کنید.`, 'warning'));
            } else if (item.source_current_check_required) {
                verification.appendChild(notice(aiSourceCurrentCheckMessage(item), 'warning'));
            }
            if (options.sourceStockGap) {
                verification.appendChild(notice('پیام قبلی اتمام موجودی را ذکر می‌کند. موجودی امروز را از تأمین‌کننده بررسی کنید.', 'warning'));
            }

            const checkLabel = node('label', null, 'molavi-reextract-current-check');
            currentPriceVerified = document.createElement('input');
            currentPriceVerified.type = 'checkbox';
            currentPriceVerified.checked = sourceVerificationItems.length > 0
                && sourceVerificationItems.every(row => verifiedCurrentPriceCases.has(currentPriceVerificationKey(row)));
            const stockNeedsCurrentCheck = sourceStockNeedsReview || relatedItems.some(row =>
                !['','Unknown','unknown'].includes(String(row && row.stock_status || '')));
            checkLabel.append(currentPriceVerified, document.createTextNode(stockNeedsCurrentCheck
                ? 'قیمت و موجودی امروز را بررسی کردم.' : 'قیمت امروز را از تأمین‌کننده بررسی کردم.'));
            verification.append(checkLabel);card.appendChild(verification);
        }
        const refreshConfirmationGates = () => {
            confirmation.hidden=sourceVerificationRequired&&!currentPriceVerified?.checked;
            quickButtons.forEach(button => {
                if (button===notProduct) return;
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
        const addQuickConfirm = (selectedRole, label, style = 'button-primary') => {
            const selectedFields = aiDecisionFields(item, selectedRole);
            const button = node('button', label, `button ${style}`);
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
        if (sourcePriceUnclear) {
            confirmation.appendChild(node('p','مبلغی را که از تأمین‌کننده بررسی کردید در فرم اصلاح وارد کنید.'));
        } else if (quickRole) {
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
        } else if (preferredPriceRole === 'package') {
            addQuickConfirm('package', 'تأیید پیشنهاد اول · قیمت کل بسته');
            addQuickConfirm('unit', 'تأیید به‌عنوان قیمت هر واحد', 'button-secondary');
        } else {
            addQuickConfirm('unit', `تأیید ${relatedItems.length > 1 ? 'همه انواع' : 'کالا'} به‌عنوان قیمت هر واحد`);
            const packageButton = addQuickConfirm('package', `تأیید ${relatedItems.length > 1 ? 'همه انواع' : 'کالا'} به‌عنوان قیمت بسته`);
            if (!aiDecisionFields(item, 'package')) {
                packageButton.disabled = true;
                packageButton.title = 'تعداد داخل بسته در داده استخراج‌شده موجود نیست.';
            }
        }
        confirmation.appendChild(quickActions);

        const form = document.createElement('form');
        const row1 = node('p');
        row1.appendChild(field('نقش قیمت', 'price_role', role || preferredPriceRole, 'select', {
            required: true, choices: aiPriceRoleChoices('انتخاب نقش قیمت', preferredPriceRole)
        }));
        row1.appendChild(field('قیمت تأییدشده به تومان', 'sale_price', price, 'number', { required: true, min: 1, step: 1 }));
        form.appendChild(row1);
        const row2 = node('p');
        const actualPriceField = field('قیمت مصرف‌کننده (اختیاری)', 'actual_price', fields.actual_price ?? item.actual_price ?? '', 'number', { min: 1, step: 1 });
        row2.appendChild(actualPriceField);
        if (!normalizedAiInteger(fields.actual_price ?? item.actual_price) && item.offer_id) {
            // Unified acknowledgment: the regular price already confirmed in the قیمت عادی tab
            // fills this field, so the owner does not acknowledge the same value twice.
            const marketUrl = new URL('/reference/item-reviews', config.apiUrl);
            marketUrl.searchParams.set('view','market-prices');
            marketUrl.searchParams.set('offer_id', item.offer_id);
            form.addEventListener('focusin', async () => {
                const input = actualPriceField.querySelector('input');
                if (input.value !== '') return;
                try {
                    const result = await request('GET', undefined, marketUrl.href, 'reference_items', {ttl: 60000});
                    if (!input.isConnected || input.value !== '' || !result?.selected_url) return;
                    const quotes = [result.suggested_price, ...(result.candidates || [])];
                    const selected = quotes.find(quote => quote?.url === result.selected_url);
                    const value = Number(selected?.regular_unit_price);
                    if (!Number.isSafeInteger(value) || value <= 0) return;
                    input.value = String(value);
                    row2.appendChild(node('small', `قیمت عادی تأییدشده در بخش قیمت عادی: ${amount(value)}`));
                } catch {
                    // The optional price remains editable if the saved quote is unavailable.
                }
            }, {once: true});
        }
        row2.appendChild(field('تعداد داخل بسته', 'package_count', fields.package_count ?? item.package_count ?? '', 'number', { min: 1, step: 1 }));
        row2.appendChild(field('حداقل خرید', 'min_qty', fields.min_qty ?? item.min_qty ?? '', 'number', { min: 1, step: 1 }));
        form.appendChild(row2);
        const category=field('دستهٔ کالا','category',fields.category||item.category||'','text');
        category.querySelector('input').maxLength=80;
        form.appendChild(category);
        form.appendChild(field('وضعیت موجودی تأمین‌کننده', 'stock_status', fields.stock_status || item.stock_status || 'Unknown', 'select', {
            choices: [['Available', 'موجود'], ['Out of Stock', 'ناموجود'], ['Unknown', 'نامشخص']]
        }));
        const edit = document.createElement('details');
        edit.className = 'molavi-ai-manual-edit';
        edit.open=sourcePriceUnclear||!visibleSupplierPrice;
        edit.appendChild(node('summary', item.reopened?'اصلاح مشخصات ثبت‌شده':'اصلاح دستی؛ فقط اگر داده استخراج‌شده اشتباه است'));
        const confirmLabel = item.reopened?'ثبت تصمیم تازه':item.decision && item.decision.choice === 'not_product'
            ? 'بازگردانی به کالا و تأیید مشخصات'
            : item.decision ? 'ثبت اصلاح و تأیید دوباره' : 'تأیید مشخصات استخراج‌شده';
        const confirm = node('button', confirmLabel, 'button button-primary');
        confirm.type = 'submit'; confirm.value = 'confirm';
        manualConfirmButton = confirm;
        refreshConfirmationGates();
        confirm.textContent = relatedItems.length > 1 ? `ثبت اصلاح برای هر ${relatedItems.length} نوع` : confirmLabel;
        form.append(confirm);
        edit.appendChild(form);
        confirmation.appendChild(edit);
        const savedReferences=relatedItems.filter(row=>row.reference_snapshot_id && (row.reference_assignment?.choice==='match'||row.reference_match_id));
        if (savedReferences.length) {
            const saved=node('details');
            saved.appendChild(node('summary','مرجع ثبت‌شده'));
            savedReferences.forEach(row=>saved.appendChild(aiReferenceAssignmentSection(row,contextStored,decisionsEnabled,assignmentOptions)));
            confirmation.appendChild(saved);
        }
        card.appendChild(confirmation);
        if (reextraction) card.appendChild(reextraction);
        if (!item.message_review) card.appendChild(reject);
        form.addEventListener('submit', async event => {
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
                    ...(form.elements.category.value.trim()&&form.elements.category.value.trim()!==String(item.category||'').trim()
                        ?{category:form.elements.category.value.trim()}:{}),
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

    function reextractionRequest(method = 'GET', payload = undefined, url = '', options = {}) {
        const apiKey = config.workflowApis && config.workflowApis.ai_confirmations
            ? 'ai_confirmations' : '';
        return request(method,payload,url || reextractionUrl('preview'),apiKey,options);
    }

    function reextractionChanges(runId) {
        const fields={'Sale Price':'قیمت فروش','Consumer Price':'قیمت مصرف‌کننده','Package Price':'قیمت بسته',
            Currency:'واحد پول','Stock Status':'موجودی','Package Count':'تعداد در بسته','Min Qty':'حداقل سفارش',
            'Variation Type':'نوع','Volume':'حجم','Weight':'وزن'};
        const stock={Available:'موجود','Out of Stock':'ناموجود',Unknown:'نامشخص'};
        const format=new Intl.NumberFormat('fa-IR',{maximumFractionDigits:6});
        const value=(field,cell)=>field==='Stock Status' ? stock[cell] || cell
            : cell==='' || cell==null ? 'ثبت نشده' : typeof cell==='number' ? format.format(cell) : String(cell);
        const details=node('details',null,'molavi-reextract-changes');
        const feedback=node('p');feedback.setAttribute('role','status');
        const list=node('ul');
        details.append(node('summary','تغییرات استخراج'),feedback,list);
        let requested=false;
        const load=async()=>{
            if (requested) return;
            requested=true;feedback.textContent='در حال دریافت تغییرات…';
            try {
                const report=await reextractionRequest('GET',undefined,reextractionUrl('run-changes',runId),{label:'دریافت تغییرات بازاستخراج',ttl:30000});
                feedback.textContent='';
                for (const offer of report.offers || []) {
                    const item=node('li');
                    const link=node('a',offer.product_name || 'بررسی پیشنهاد');
                    const url=new URL(config.pageUrls?.['molavi-operations'] || '?page=molavi-operations',window.location.href);
                    url.searchParams.set('scope','all');url.searchParams.set('offer_id',offer.offer_id);
                    link.href=url.href;
                    link.setAttribute('aria-label',`بررسی پیشنهاد ${offer.product_name || ''}`.trim());
                    item.append(link);
                    if (offer.state==='changed') {
                        const changes=node('ul');
                        for (const change of offer.changes) {
                            const line=node('li',`${fields[change.field]} · `);
                            line.append(node('bdi',value(change.field,change.before)),document.createTextNode(' ← '),
                                node('bdi',value(change.field,change.after)));
                            changes.append(line);
                        }
                        item.append(changes);
                    } else {
                        item.append(node('small',offer.state==='unmatched'?'مقایسهٔ مطمئن با پیشنهاد قبلی ممکن نبود.':'بدون تغییر در داده‌های اصلی'));
                    }
                    list.append(item);
                }
                if (report.omitted) feedback.append(`${format.format(report.omitted)} پیشنهاد دیگر در این خلاصه نمایش داده نمی‌شود. `);
                if (report.missing) feedback.append(`ردیف ذخیره‌شده برای ${format.format(report.missing)} پیشنهاد پیدا نشد.`);
                feedback.hidden=!feedback.textContent;
            } catch (error) {
                requested=false;
                const retry=node('button','دریافت دوبارهٔ تغییرات','button button-secondary');retry.type='button';
                retry.addEventListener('click',load);
                feedback.replaceChildren(document.createTextNode(errorText(error.message)+' '),retry);
            }
        };
        details.addEventListener('toggle',()=>{if (details.open) load();});
        return details;
    }

    async function showReextractionProgress(panel, runId, auditContext = {}, manual = false) {
        if (!root.contains(panel)) return;
        let timer;
        const refreshStatus = (manual = false) => {
            clearTimeout(timer);
            return showReextractionProgress(panel,runId,auditContext,manual);
        };
        try {
            const runUrl=reextractionUrl('run',runId)+'&summary=1';
            const status = await reextractionRequest('GET',undefined,runUrl,manual?{label:'تازه‌سازی وضعیت بازاستخراج'}:{});
            const counts = status.counts || {};
            const finished = Number(status.completed || 0);
            const total = Number(status.total || 0);
            if (!root.contains(panel)) return;
            const complete=status.state==='complete';
            const outcomes=status.outcomes || {};
            const errors=counts.failed || counts.needs_review || outcomes.policy_changed || outcomes.source_changed;
            const label=complete ? (errors?'بازاستخراج نیاز به پیگیری دارد':counts.cancelled?'بازاستخراج پایان یافت؛ بخشی لغو شد':'بازاستخراج پایان یافت')
                : (counts.running?'بازاستخراج در حال انجام است':'بازاستخراج در صف است');
            const box = notice(`${label} · ${finished} از ${total} پیام`, complete ? (errors?'warning':'success') : 'info');
            panel.querySelector('.molavi-reextract-progress')?.replaceWith(box);
            box.classList.add('molavi-reextract-progress');
            const statusButton=node('button','تازه‌کردن وضعیت اجرا','button button-secondary');
            statusButton.type='button';
            statusButton.addEventListener('click',() => {
                statusButton.disabled=true;
                refreshStatus(true);
            });
            if (complete) {
                box.appendChild(node('p', `${status.products_found || 0} کالا شناسایی شد · ${status.offers_written || 0} پیشنهاد ذخیره شد · ${status.open_cases_remaining || 0} مورد برای بررسی`));
                if (outcomes.no_products) box.appendChild(node('p','در متن پیام کالایی شناسایی نشد.'));
                if (counts.failed) box.appendChild(node('p',`${counts.failed} پیام ناموفق بود؛ علت را در وضعیت سیستم ببینید.`));
                if (String(auditContext.scope || '') === 'recent_policy') {
                    box.appendChild(node('p', `نسخه قواعد اجراشده: ${status.policy_version || auditContext.policy_version || 'نامشخص'}`));
                    if (outcomes.policy_changed) box.appendChild(notice('قواعد استخراج هنگام شروع کار تغییر کرده بود؛ این پیام‌ها بدون ثبت تغییر محصول متوقف شدند. صف را تازه کنید و نسخه جدید را اجرا کنید.','warning'));
                }
                if (outcomes.text_required || outcomes.image_extraction_disabled) {
                    box.appendChild(notice('تصاویر فقط پیوست هستند؛ برای استخراج کالا متن یا کپشن لازم است.','info'));
                }
                const publicationState = String((status.catalog_publication || {}).state || '');
                if (status.offers_written > 0) {
                    if (!box.nextElementSibling?.classList.contains('molavi-reextract-changes')) box.after(reextractionChanges(runId));
                    const publicationLabel = publicationState === 'done' ? 'همگام‌سازی با وردپرس کامل شد.'
                        : publicationState === 'waiting_publication' ? 'همگام‌سازی وردپرس پشت توقف انتشار منتظر بررسی است.'
                        : publicationState === 'failed' ? 'همگام‌سازی وردپرس ناموفق ماند؛ خطای صف انتشار را بررسی کنید.'
                        : publicationState ? 'همگام‌سازی تغییرها با وردپرس در صف است.'
                        : 'خروجی ثبت شد؛ در انتظار ثبت کار همگام‌سازی وردپرس است.';
                    box.appendChild(node('p', publicationLabel));
                    if (publicationState==='waiting_publication') {
                        const hold=node('button','دیدن توقف انتشار','button button-secondary');
                        hold.type='button';hold.addEventListener('click',()=>dashboardApp.reviewHold());
                        box.appendChild(hold);
                    }
                }
                if (outcomes.source_changed) box.appendChild(node('p', `${outcomes.source_changed} پیام پس از پیش‌نمایش تغییر کرده یا چند نسخه مبهم داشته؛ نتیجه‌ای برای آن‌ها ثبت نشد. پیش‌نمایش را تازه کنید و دوباره اجرا کنید.`, 'notice'));
                if (counts.cancelled) box.appendChild(node('p', 'کارهای در صف لغو شدند؛ پیامِ در حال اجرا ممکن است کامل شده باشد.'));
                if (status.offers_written > 0) {
                    const products=node('button','دیدن محصولات به‌روز','button button-secondary');
                    products.type='button';
                    products.addEventListener('click',()=>dashboardApp.showReviewResults());
                    box.append(products);
                    if (!publicationState || ['queued','running'].includes(publicationState)) {
                        timer=setTimeout(refreshStatus,8000);
                    }
                }
                box.append(statusButton);
                if (auditContext && auditContext.run_id
                        && !panel.dataset.reextractAuditSaved) {
                    panel.dataset.reextractAuditSaved = '1';
                    try {
                        const reviewType='ai_reextraction';
                        const reviewId=runId;
                        const eventId='reextraction-run:'+runId;
                        const policyRun=String(auditContext.scope || '')==='recent_policy';
                        const decision=auditContext.decision || (policyRun?{action:'reprocess_recent_posts_with_current_policy',
                                policy_version:String(auditContext.policy_version || '')}
                                :{action:'reprocess_open_extraction_cases'});
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
            box.append(cancel,statusButton);
            timer=setTimeout(refreshStatus,3500);
        } catch (error) {
            const retry=node('button','دریافت دوبارهٔ وضعیت اجرا','button button-secondary');
            retry.type='button';
            retry.addEventListener('click',() => {
                retry.disabled=true;
                refreshStatus(true);
            });
            const errorBox=notice(`دریافت وضعیت بازاستخراج ناموفق بود. ${errorText(error.message)}`,'warning');
            errorBox.classList.add('molavi-reextract-progress');
            errorBox.appendChild(retry);
            panel.querySelector('.molavi-reextract-progress')?.replaceWith(errorBox);
        }
    }

    function activeReextraction(preview) {
        return preview.active_run || preview.open_cases?.active_run;
    }

    function followReextraction(panel, runId, context = {}) {
        const progress = notice('بازاستخراج در صف پردازش است…','info');
        progress.classList.add('molavi-reextract-progress');
        panel.replaceChildren(progress);
        showReextractionProgress(panel,runId,context);
    }

    function policyReextractionButton(panel, preview, offerId = '') {
        const policy = offerId ? preview : preview.recent_policy || {};
        const count = offerId ? 1 : Number(policy.eligible_count || 0);
        const version = policy.policy_version || '';
        const digest = policy.digest || '';
        if (!preview.ai_configured || !count || !version || !/^[a-f0-9]{64}$/.test(digest)) return;
        const formattedCount = new Intl.NumberFormat('fa-IR').format(count);
        const button = node('button',offerId ? 'شروع بازاستخراج همین پیام' : `بازاستخراج ${formattedCount} پیام اخیر`,'button button-primary');
        button.type = 'button';
        const feedback = node('p');
        feedback.setAttribute('role','status');
        button.addEventListener('click',async () => {
            const subject = offerId ? 'همین پیام' : `${formattedCount} پیام اخیر`;
            if (!window.confirm(`${subject} با قواعد فعلی دوباره پردازش می‌شود. خروجی از کنترل کیفیت می‌گذرد و تصمیم مالک حفظ می‌شود. شروع شود؟`)) return;
            button.disabled=true;
            feedback.textContent='در حال ثبت درخواست…';
            const runId=Array.from(window.crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');
            const context={run_id:runId,scope:'recent_policy',message_count:count,digest,policy_version:version,
                window_days:offerId ? 0 : Number(policy.window_days || 14)};
            const eventId='reextraction-run:'+runId;
            const decision={action:offerId?'reprocess_product_source':'reprocess_recent_posts_with_current_policy',policy_version:version};
            try {
                await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,'pending',context,decision,{})]);
                const selection=offerId ? {action:'start_offer_reextraction',offer_id:offerId}
                    : {action:'start_recent_policy_reextraction',expected_count:count};
                const receipt=await reextractionRequest('POST',{...selection,run_id:runId,expected_digest:digest,policy_version:version});
                if (receipt.run_id!==runId || receipt.queued!==count || receipt.policy_version!==version
                    || receipt.digest!==digest) throw new Error('invalid_receipt');
                let auditWarning=false;
                try { await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,'accepted',context,decision,receipt)]); }
                catch (_error) { auditWarning=true; }
                followReextraction(panel,runId,{...context,decision});
                if (auditWarning) panel.appendChild(notice('بازاستخراج در صف است؛ ثبت رسید وردپرس کامل نشد.','warning'));
            } catch (error) {
                try { await persistOwnerReviews([reviewRecord(eventId,'ai_reextraction',runId,
                    error.remoteRejected?'failed':'pending',context,decision,{error:String(error.message || 'request_failed')})]); }
                catch (_error) {}
                const changed=['extraction_policy_changed','reextraction_preview_changed','reextraction_active'].includes(error.message);
                feedback.textContent=changed?'انتخاب یا وضعیت اجرا تغییر کرده است؛ صفحه را تازه کنید.':errorText(error.message);
                button.disabled=changed;
            }
        });
        panel.append(button,feedback);
    }

    function offerReextraction(offerId) {
        const details = node('details',null,'molavi-advanced-reextract');
        details.appendChild(node('summary','بازاستخراج همین پیام'));
        const panel=node('div');
        panel.setAttribute('aria-live','polite');
        details.appendChild(panel);
        let loaded=false;
        details.addEventListener('toggle',async () => {
            if (!details.open || loaded) return;
            loaded=true;
            panel.textContent='در حال بررسی پیام و نسخهٔ استخراج…';
            try {
                const url=new URL(reextractionUrl('offer'),window.location.href);
                url.searchParams.set('offer_id',offerId);
                const preview=await reextractionRequest('GET',undefined,url.toString(),{label:'بررسی امکان بازاستخراج پیام',ttl:15000});
                if (preview.offer_id!==offerId) throw new Error('invalid_receipt');
                panel.replaceChildren();
                const active=activeReextraction(preview);
                if (preview.latest_run_id) {
                    const result=node('div');
                    panel.appendChild(result);
                    followReextraction(result,preview.latest_run_id);
                    if (preview.latest_run_id===active?.run_id) return;
                }
                if (active?.run_id) {
                    const progress=node('div');
                    panel.append(node('p','یک بازاستخراج در جریان است. پس از پایان آن، وضعیت این پیام را تازه کنید.'),progress);
                    followReextraction(progress,active.run_id);
                    return;
                }
                const reasons={
                    updated_policy:'قواعد تازه برای این پیام در دسترس است.',
                    ready:'این پیام با قواعد فعلی قابل بازاستخراج است.',
                    already_current:'این پیام قبلاً با همین نسخه بازاستخراج شده است؛ نتیجه را در مشخصات بررسی کنید.',
                    owner_decision:'برای این پیام تصمیم نهایی ثبت شده است؛ تصمیم در بازاستخراج حفظ می‌شود.',
                    missing_offer:'پیوند پیشنهاد این کالا پیدا نشد.',
                    source_unavailable:'متن ذخیره‌شدهٔ قابل‌پردازش برای این پیشنهاد پیدا نشد.',
                    ambiguous_source:'چند نسخه از پیام منبع پیدا شد؛ پیام اصلی را بررسی کنید.',
                    held:'ابتدا توقف انتشار این پیام را بررسی کنید.',
                };
                panel.appendChild(node('p',preview.ai_configured===true
                    ? reasons[preview.reason] || 'وضعیت بازاستخراج در دسترس نیست.'
                    : 'سرویس استخراج آماده نیست.'));
                const source=safeSourceLink(preview.source?.source_post_url);
                if (source) panel.appendChild(source);
                policyReextractionButton(panel,preview,offerId);
            } catch (error) {
                loaded=false;
                panel.replaceChildren(notice(errorText(error.message),'error'));
            }
        });
        return details;
    }

    async function loadInboxOffers(container,item) {
        container.textContent='در حال دریافت پیشنهادها…';
        try {
            const url=new URL('/pipeline/reconciliation',config.apiUrl);
            url.search=new URLSearchParams({
                view:'inbox-offers',batch_id:item.batch_id,message_id:item.source_id,channel:item.channel,
            });
            const {offers,outcome}=await request('GET',undefined,url.toString(),'reconciliation',
                {ttl:15000,revision:[item.receipt_id,item.state,item.extraction_state].join(':')});
            container.replaceChildren();
            const labels={known:'سابقهٔ همین کالا',compare:'کالاهای مشابه برای مقایسه',new:'پیشنهاد تازه'};
            for (const offer of offers || []) {
                const card=node('section',null,'molavi-product-subsection');
                const open=node('button','بررسی پیشنهاد','button button-secondary');
                open.type='button';
                open.addEventListener('click',()=>dashboardApp.reviewInboxItem('offer_id',offer.offer_id,offer.product_id));
                card.append(node('p',`${offer.name} · ${labels[offer.relationship]}`));
                if (offer.registered?.length) for (const listing of offer.registered) {
                    const related=node('button','پیام‌های '+listing.name,'button');related.type='button';
                    related.addEventListener('click',()=>openProductMessages(listing));card.append(related);
                } else card.append(open);
                if (offer.similar?.length) {
                    const compare=node('details');
                    compare.appendChild(node('summary','مقایسه با کالاهای مشابه'));
                    for (const match of offer.similar) {
                        const label=[match.name,...(match.attributes || [])].join(' · ');
                        const button=node('button',label,'button button-secondary');
                        button.type='button';
                        button.addEventListener('click',()=>dashboardApp.reviewInboxItem('product_id',match.product_id));
                        compare.appendChild(button);
                    }
                    card.appendChild(compare);
                }
                container.appendChild(card);
            }
            if (!container.childNodes.length) {
                container.textContent={no_product:'استخراج پایان یافت؛ کالایی در این پیام شناسایی نشد.',
                    attachment:'برای استخراج کالا از این پیام، متن یا کپشن لازم است.',dismissed:'این پیام از صف استخراج کنار گذاشته شده است.'}[outcome]
                    || 'پیشنهاد ذخیره‌شده‌ای برای این پیام پیدا نشد.';
            }
        } catch (error) {
            const retry=node('button','دریافت دوبارهٔ نتیجه','button');retry.type='button';
            retry.addEventListener('click',()=>loadInboxOffers(container,item));
            container.replaceChildren(node('p',errorText(error.message)),retry);
        }
    }

    function productMessagePanel(wooId) {
        const panel=node('section',null,'molavi-product-messages');
        const endpoint=new URL('/pipeline/reconciliation',config.apiUrl);
        endpoint.searchParams.set('view','product-messages');
        endpoint.searchParams.set('woo_product_id',wooId);
        function messageRow(item) {
            const row=node('details',null,'molavi-product-subsection');
            const state={review:'در حال بررسی',dismissed:'کنارگذاشته‌شده',used:'استفاده شده'}[item.state]||'منتظر انتخاب';
            row.append(node('summary',[item.title,amount(item.sale_price),state].filter(Boolean).join(' · ')),
                reviewFacts([['تأمین‌کننده',item.channel],['دریافت',formatSourceTimestamp(item.received_at)],
                    ['تاریخ پیام',item.source_created_at?`${formatSourceTimestamp(item.source_created_at)} · ${sourceAgeText(item.source_created_at)}`:null],
                    ['دسته',item.category||'ثبت نشده'],['مبنای قیمت',aiPriceRole(item)==='package'?'بسته':aiPriceRole(item)==='unit'?'واحد':null],
                    ['تعداد در بسته',item.package_count],['قیمت مصرف‌کننده',amount(item.consumer_price)]]));
            if (item.excerpt) row.append(node('p',item.excerpt,'md-source-text'));
            const link=safeSourceLink(item.source_url);if(link) row.append(link);
            if (item.state==='used') return row;
            const actions=reviewActions();
            async function act(choice) {
                actions.querySelectorAll('button').forEach(control=>control.disabled=true);
                try {
                    const result=await request('POST',{action:'choose_product_message',woo_product_id:Number(wooId),offer_id:item.offer_id,choice},
                        undefined,'ai_confirmations');
                    await dashboard('','info',['ai_confirmations','product_status']);
                    if (result.state==='review'&&result.case_id) {
                        panel.closest('dialog')?.close();
                        dashboardApp.reviewInboxItem('case_id',result.case_id);
                    } else if (panel.isConnected) read();
                } catch (error) {
                    actions.querySelector('.notice')?.remove();
                    actions.append(notice(errorText(error.message),'error'));
                    actions.querySelectorAll('button').forEach(control=>control.disabled=false);
                }
            }
            const add=(label,choice)=>{
                const control=node('button',label,choice==='review'?'button button-primary':'button');
                control.type='button';control.addEventListener('click',()=>act(choice));actions.append(control);
            };
            if (item.state==='dismissed') add('بازگرداندن پیام','restore');
            else {
                add(item.state==='review'?'ادامهٔ بررسی':'بررسی این پیام','review');
                add('کنارگذاشتن پیام','dismiss');
            }
            row.append(actions);
            return row;
        }
        async function read() {
            panel.replaceChildren(node('p','در حال دریافت پیام‌های کالا…'));
            try {
                const {pending,history}=await request('GET',undefined,endpoint.href,'reconciliation',{ttl:15000});
                panel.replaceChildren();
                if (!pending?.length) panel.append(node('p','پیام تازهٔ این کالا را به ربات بفرستید. وقتی استخراج آماده شد، همین بخش را باز کنید و پیام را برای بررسی انتخاب کنید.'));
                else panel.append(...pending.map(messageRow));
                if (history?.length) {
                    const archived=node('details');
                    archived.append(node('summary','پیام‌های استفاده‌شده و کنارگذاشته‌شده'),...history.map(messageRow));
                    panel.append(archived);
                }
            } catch (error) {
                const retry=node('button','دریافت دوبارهٔ پیام‌ها','button');retry.type='button';retry.addEventListener('click',read);
                panel.replaceChildren(notice(errorText(error.message),'error'),retry);
            }
        }
        read();
        return panel;
    }

    function openProductMessages(listing) {
        const dialog=node('dialog',null,'md-dialog');
        const close=node('button','بستن','button');close.type='button';close.addEventListener('click',()=>dialog.close());
        dialog.append(node('h2',listing.name),productMessagePanel(listing.woo_product_id),close);
        dialog.addEventListener('close',()=>dialog.remove(),{once:true});
        root.append(dialog);dialog.showModal();
    }

    function recentBotMessages(product=null) {
        const inbox=node('section',null,'molavi-inbox');
        const header=node('div',null,'molavi-inbox-header');
        const refresh=node('button','تازه‌کردن پیام‌ها','button');refresh.type='button';
        header.append(node('h2','آخرین پیام‌های ربات'),refresh);
        const status=node('p');status.setAttribute('role','status');
        const contents=node('div');
        inbox.append(header,status,contents);
        let rows=new Map();
        let refreshPending=false;
        const woo=product?.listing?.woo_product_id||product?.status?.woo_product_id;
        const sourceUrl=product?.status?.source_post_url;
        const name=workbenchNameKey(product?.name);
        const related=item=>Boolean((sourceUrl&&sourceUrl===item.source_url)
            ||(woo&&item.related_products?.some(row=>row.woo_product_id===woo))
            ||(name&&workbenchNameKey(item.excerpt).includes(name)));
        function inboxRow(item) {
            const state=item.hold_id?'پردازش متوقف شده': item.retry_at ? 'منتظر سهمیه' : item.extraction_state==='error' ? 'خطا در استخراج'
                : ({done:'پردازش پایان یافت',queued:item.batch_id?'در صف استخراج':'دریافت شد',running:'در حال استخراج'}[item.state]||displayBatchState(item.state));
            const label=[item.channel,formatSourceTimestamp(item.received_at),state].filter(Boolean).join(' · ');
            const row=node('details');
            row.append(node('summary',label),node('p',item.excerpt || 'متن جداگانه ثبت نشده است.','md-source-text'));
            const source=safeSourceLink(item.source_url);if(source) row.appendChild(source);
            for (const listing of item.related_products||[]) {
                const related=node('button','پیام‌های '+listing.name,'button');related.type='button';
                related.addEventListener('click',()=>openProductMessages(listing));row.append(related);
            }
            if (item.retry_at) row.appendChild(node('p',`نوبت بعد از ${formatSourceTimestamp(new Date(item.retry_at*1000).toISOString())}`));
            const followup=item.hold_id ? ['بررسی توقف دسته',()=>dashboardApp.reviewHold(`molavi-batch-${encodeURIComponent(item.hold_id)}`)]
                : item.state==='waiting_reference' ? ['فهرست مرجع',()=>dashboardApp.navigate('references')]
                : item.state==='failed'||item.extraction_state==='error' ? ['بررسی وضعیت پردازش',()=>dashboardApp.navigate('system')] : null;
            if (followup) {
                const button=node('button',followup[0],'button');button.type='button';button.addEventListener('click',followup[1]);row.appendChild(button);
            } else if (item.batch_id && item.source_id && ['done','needs_review'].includes(item.state)) {
                const offers=node('div');
                row.appendChild(offers);
                row.addEventListener('toggle',()=>{
                    if (row.open && !offers.childNodes.length) loadInboxOffers(offers,item);
                });
            }
            return row;
        }
        async function readInbox(manual=false) {
            if (refresh.disabled) return;
            refreshPending=false;refresh.disabled=true;
            if (!rows.size) {status.hidden=false;status.textContent='در حال دریافت پیام‌ها…';}
            try {
                const url=new URL('/pipeline/reconciliation',config.apiUrl);
                url.searchParams.set('view','inbox');
                const {messages}=await request('GET',undefined,url.toString(),'reconciliation',manual?{label:'تازه‌سازی پیام‌های منبع'}:{});
                const nextRows=new Map();
                const visible=(messages||[]).filter(item=>!product||related(item));
                for (const item of visible) {
                    const key=item.receipt_id || JSON.stringify([item.received_at,item.channel,item.source_id]);
                    const signature=JSON.stringify(item);
                    const previous=rows.get(key);
                    const row=previous?.signature===signature ? previous.row : inboxRow(item);
                    if (row!==previous?.row && previous?.row.open) row.open=true;
                    nextRows.set(key,{row,signature});
                }
                const elements=[...nextRows.values()].map(entry=>entry.row);
                if (elements.length!==rows.size || elements.some((row,index)=>row!==contents.children[index]))
                    contents.replaceChildren(...elements);
                rows=nextRows;status.hidden=rows.size>0;
                status.textContent=rows.size?'':product
                    ? 'در پیام‌های اخیر، پیامی برای این کالا پیدا نشد. پیام تازه را به ربات بفرستید و دوباره بررسی کنید.'
                    : 'هنوز پیامی دریافت نشده است.';
            } catch (error) {
                status.hidden=false;status.textContent=errorText(error.message);
            } finally {
                refresh.disabled=false;
                if (refreshPending&&root.contains(inbox)) readInbox();
            }
        }
        refresh.addEventListener('click',()=>readInbox(true));
        inbox.addEventListener('molavi-inbox-refresh',event=>{
            if (!refresh.disabled) readInbox();
            else if (event.detail) refreshPending=true;
        });
        return {inbox,readInbox};
    }

    async function sourceReextractionWorkspace(panel, product) {
        if (product) {
            const offer=String(product.status?.latest_offer_id || product.ai[0]?.offer_id || product.references[0]?.offer_id || '');
            if (offer&&!product.status?.source_integrity_issues?.length) panel.appendChild(offerReextraction(offer));
            const woo=product.listing?.woo_product_id||product.status?.woo_product_id;
            if (woo) {
                const messages=node('button','پیام‌های آمادهٔ انتخاب','button button-primary');messages.type='button';
                messages.addEventListener('click',()=>openProductMessages({woo_product_id:woo,name:product.name}));
                panel.appendChild(messages);
            }
        }
        const filters=node('details',null,'molavi-advanced-reextract');
        filters.appendChild(node('summary','انتخاب بر اساس کانال و تاریخ'));
        const filtered=node('div');
        filters.appendChild(filtered);
        filters.addEventListener('toggle',() => {
            if (!filters.open || filtered.childNodes.length) return;
            reextractionPanel(filtered,{initialChannel:product?.channel || ''});
        });
        if (product) {
            const {inbox,readInbox}=recentBotMessages(product);
            panel.append(inbox,filters);
            await readInbox();
            return;
        }
        const recent=node('section',null,'card molavi-reextract-panel');
        const {inbox,readInbox}=recentBotMessages();
        panel.append(inbox,recent,filters);
        recent.textContent='در حال دریافت وضعیت پیام‌ها…';
        await readInbox();
        try {
            const preview=await reextractionRequest('GET',undefined,reextractionUrl('preview')+'&latest_run=1',{ttl:15000});
            recent.replaceChildren(node('h2','بازاستخراج با قواعد فعلی'));
            const active=activeReextraction(preview);
            if (active?.run_id) {
                followReextraction(recent,active.run_id);
                return;
            }
            if (preview.latest_run_id) {
                const latest=node('details',null,'molavi-advanced-reextract');
                const result=node('div');
                latest.append(node('summary','نتیجهٔ آخرین بازاستخراج'),result);
                latest.addEventListener('toggle',() => {
                    if (latest.open && !result.childNodes.length) followReextraction(result,preview.latest_run_id);
                });
                recent.appendChild(latest);
            }
            const count=Number(preview.recent_policy?.eligible_count || 0);
            recent.appendChild(node('p',preview.ai_configured!==true ? 'سرویس استخراج آماده نیست.'
                : count ? 'پیام‌های واجد شرایط ۱۴ روز اخیر را بررسی و دوباره پردازش کنید.'
                    : 'پیام تازه‌ای برای بازاستخراج گروهی آماده نیست. برای یک پیام از کارت همان محصول شروع کنید.'));
            const actions=node('div',null,'molavi-reextract-actions');
            policyReextractionButton(actions,preview);
            recent.appendChild(actions);
            const posts=preview.recent_policy?.source_posts || [];
            if (posts.length) {
                const sample=node('details');
                sample.appendChild(node('summary','نمونه پیام‌های آماده'));
                for (const post of posts) {
                    const row=node('p',`${post.channel || 'منبع نامشخص'} · ${formatSourceTimestamp(post.timestamp)}`);
                    const link=safeSourceLink(post.source_post_url);
                    if (link) row.appendChild(link);
                    sample.appendChild(row);
                }
                recent.appendChild(sample);
            }
            if (preview.open_cases?.case_count || preview.counts?.pending) {
                const tools=node('details',null,'molavi-advanced-reextract');
                tools.appendChild(node('summary','کارهای گروهی دیگر'));
                const body=node('div');
                tools.appendChild(body);
                advancedReextractionTools(body,preview);
                panel.appendChild(tools);
            }
        } catch (error) {
            recent.replaceChildren(notice(errorText(error.message),'error'));
        }
    }

    async function advancedReextractionTools(panel, preview) {
        panel.className = 'card molavi-reextract-panel';
        try {
            const counts = preview.counts || {};
            const openCases = preview.open_cases || {};
            const aiConfigured = preview.ai_configured === true;
            if (openCases.unmatched_case_count > 0) {
                panel.appendChild(notice(`${openCases.unmatched_case_count} کارت به متن پیام اصلی وصل نیست.`, 'warning'));
            }
            const controls = node('div', null, 'molavi-reextract-actions');
            const openCasesButton = node('button', `بازاستخراج پیام‌های کارت‌های باز (${openCases.message_count || 0})`, 'button');
            const dismissButton = node('button', `کنارگذاشتن پیام‌های منتظر (${counts.pending || 0})`, 'button');
            openCasesButton.type = 'button'; dismissButton.type = 'button';
            openCasesButton.disabled = !aiConfigured || !(openCases.case_count > 0)
                || Number(openCases.unmatched_case_count || 0) > 0;
            dismissButton.disabled = !(counts.pending > 0);
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
                    await dashboard(`${receipt.dismissed_count} پیام از صف بازاستخراج کنار گذاشته شد.`, 'success');
                } catch (error) {
                    panel.appendChild(notice(errorText(error.message), 'error'));
                    dismissButton.disabled = !(counts.pending > 0);
                }
            });
            controls.append(openCasesButton, dismissButton);
            panel.appendChild(controls);
        } catch (error) {
            panel.appendChild(notice(errorText(error.message), 'error'));
        }
    }

    async function reextractionPanel(panel, dismissalHooks = {}) {
        panel.className = 'card molavi-reextract-workspace';
        panel.appendChild(node('h2', 'انتخاب پیام‌ها برای بازاستخراج'));
        panel.appendChild(node('p', 'کانال و تاریخ پیام را انتخاب کنید؛ تعداد پیش از اجرا نمایش داده می‌شود.'));
        try {
            const initial = await reextractionRequest('GET', undefined, reextractionUrl('preview')+'&selection=1',{ttl:15000});
            const counts = initial.selection_counts || {};
            const filtersCard = node('section', null, 'molavi-reextract-filters');
            const selectionCard = node('section', null, 'molavi-reextract-selection');
            const layout = node('div', null, 'molavi-reextract-layout');
            const scopeFieldset = document.createElement('fieldset');
            scopeFieldset.className = 'molavi-reextract-scope';
            scopeFieldset.appendChild(node('legend', 'دامنهٔ پیام‌ها'));
            const scopeInputs = new Map();
            const scopeOptions = [
                {key:'all',label:'همهٔ پست‌های قابل‌بازاستخراج',hint:'همهٔ پست‌های دارای متن یا کپشن که واجد شرایط بازاستخراج هستند؛ تصاویر فقط پیوست می‌مانند.',count:counts.all || 0},
                {key:'pending',label:'پست‌های منتظر بررسی مالک',hint:'استخراج‌هایی که هنوز در صف بررسی مالک هستند.',count:counts.pending || 0}
            ];
            scopeOptions.forEach((option,index) => {
                const label = node('label', null, 'molavi-reextract-scope-option');
                const input = document.createElement('input');
                input.type = 'radio'; input.name = 'molavi-reextract-scope'; input.value = option.key;
                input.checked = index === 0;
                const copy = node('span', null, 'molavi-reextract-scope-copy');
                copy.append(node('strong', option.label), node('small', option.hint));
                label.append(input, copy, node('b', Number(option.count).toLocaleString('fa-IR')));
                scopeFieldset.appendChild(label);
                scopeInputs.set(option.key,input);
            });
            filtersCard.appendChild(scopeFieldset);

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
                url.searchParams.set('selection','1');
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
                const active = activeReextraction(data);
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
                    const data = await reextractionRequest('GET',undefined,previewUrl(),{ttl:10000});
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
            if(dismissalHooks.initialChannel){
                const selected=channelInputs.find(input=>input.value.replace(/^@/,'').toLowerCase()===String(dismissalHooks.initialChannel).replace(/^@/,'').toLowerCase());
                if(selected){selected.checked=true;await updatePreview();}
                else panel.prepend(notice('کانال این کالا در پیام‌های قابل‌بازاستخراج پیدا نشد؛ پیام اصلی را بررسی کنید.', 'warning'));
            }

            runButton.addEventListener('click',async() => {
                const selection = currentPreview && currentPreview.selection;
                const count = Number(selection && selection.count || 0);
                if (!selection || count < 1 || activeReextraction(currentPreview) || currentPreview.ai_configured !== true) return;
                const scope = selectedScope();
                const channels = selectedChannels();
                const dateFromValue = dateFrom.value;
                const dateToValue = dateTo.value;
                const digest = String(selection.digest || '');
                const groupSummary = (selection.groups || []).map(group => `${group.label}: ${group.count}`).join('، ');
                const confirmation = `بازاستخراج ${count} پست با مدل فعلی شروع می‌شود. دامنه: ${scopeOptions.find(item => item.key === scope)?.label || scope}. گروه‌ها: ${groupSummary || 'بدون مورد'}. فقط متن و کپشن‌ها پردازش می‌شوند. تصمیم‌های نهایی مالک حفظ می‌شوند. ادامه می‌دهید؟`;
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

        } catch (error) {
            panel.appendChild(notice(errorText(error.message),'error'));
        }
    }

    function referenceItemCard(item, contextStored = true, options = {}) {
        const card = node('section', null, options.embedded
            ? 'molavi-product-subsection molavi-review-card molavi-reference-review-card'
            : 'card molavi-review-card molavi-reference-review-card');
        const suggestionSection = item.decision?null:referenceSuggestionSection(item, 'reference');
        const duplicateParentName = Boolean(options.embedded && options.parentName
            && aiDisplayKey(options.parentName) === aiDisplayKey(item.product_name));
        card.appendChild(reviewCardHeader(duplicateParentName ? '' : item.product_name || 'کالای بدون نام', 'تطبیق مرجع'));
        const referenceFacts = [
            ...(!options.embedded && item.channel ? [['تأمین‌کننده', item.channel]] : []),
            ['نقش پیشنهادی قیمت', item.price_role_hint || 'نیازمند بررسی'],
            ['دلیل بررسی', aiReviewReason(item.reason)]
        ];
        if (item.reference_update) {
            const percent = Number(item.reference_update.change_percent);
            referenceFacts.push(['تغییر قیمت مرجع', Number.isFinite(percent) ? percent.toLocaleString('fa-IR') + '٪' : 'نامشخص']);
            card.appendChild(notice('برای ثبت تغییر مرجع، ابتدا مبلغ را با پیام اصلی تطبیق دهید.', 'warning'));
        }
        const sourceFacts=referenceOfferFacts(item.offer_id,
            [['عنوان استخراج‌شده',item.product_name],['قیمت تأمین‌کننده',amount(item.supplier_price)]],referenceFacts);
        card.appendChild(sourceFacts.container);
        sourceFacts.load();
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
                        await dashboard('تصمیم در سرویس ثبت شد، اما رسید وردپرس هنوز همگام نشده است.', 'warning');
                    }
                    return;
                }
                if (receipt.projection_state === 'pending_verification') {
                    updateActionStatus(progressStatus, 'تصمیم ذخیره شد؛ ثبت آن در فهرست کالاها هنوز تأیید نشده و مورد باز می‌ماند.', 'warning');
                    if (typeof options.refresh === 'function') {
                        await options.refresh('تصمیم ذخیره شد؛ ثبت آن در فهرست کالاها هنوز تأیید نشده و مورد برای تکمیل باز می‌ماند.', 'warning', progressStatus,
                            ['reference_items','product_status','supplier_prices','ai_confirmations','operations']);
                    } else {
                        await dashboard('تصمیم ذخیره شد؛ ثبت آن در فهرست کالاها هنوز تأیید نشده و مورد برای تکمیل باز می‌ماند.', 'warning');
                    }
                } else {
                    updateActionStatus(progressStatus, 'تصمیم تطبیق ثبت شد.', 'success');
                    if (typeof options.refresh === 'function') await options.refresh('تصمیم تطبیق ثبت شد.', 'success', progressStatus,
                        ['reference_items','product_status','supplier_prices','ai_confirmations','operations']);
                    else await dashboard('تصمیم تطبیق ثبت شد.', 'success');
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
            || (item.reference_update && item.reference_update.reference_id) || item.current_reference_id || '';
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
        const rendered=new Map();
        function addReferenceOption(row, fromSearch=false, suggested=false) {
                if (rendered.has(row.reference_id)) return rendered.get(row.reference_id);
                const option = node('button', null, 'button molavi-reference-candidate');
                option.type = 'button';
                option.setAttribute('aria-pressed', String(row.reference_id === selectedReferenceId));
                option.appendChild(node('strong', row.name));
                if (suggested) option.appendChild(node('span','پیشنهاد خودکار'));
                const nameScore = row.name_similarity ?? row.component_scores?.name;
                const score = Number(nameScore ?? row.score);
                if (Number.isFinite(score)) option.appendChild(node('span',`شباهت نام ${Math.round(score * 100).toLocaleString('fa-IR')}٪`));
                const comparisons = referenceComparisonSummary(row);
                if (comparisons.length) option.appendChild(node('span', comparisons.join(' · ')));
                option.appendChild(node('span', referencePriceSummary(row, item.supplier_price)
                    || `قیمت مرجع واحد: ${amount(row.unit_price)} · بسته: ${amount(row.package_price)}`));
                option.addEventListener('click', () => {
                    selectedReferenceId = row.reference_id;
                    candidates.querySelectorAll('.molavi-reference-candidate').forEach(button =>
                        button.setAttribute('aria-pressed', String(button === option)));
                    updateChoice();
                });
                if (fromSearch) candidates.prepend(option); else candidates.appendChild(option);
                rendered.set(row.reference_id,option);
                return option;
        }
        candidateRows.slice(0, 6).forEach(row=>addReferenceOption(row));
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
                updateChoice();
            });
            roleButtons.push(button);
            roles.appendChild(button);
        });
        form.appendChild(roles);
        const match = node('button', item.decision ? 'ثبت اصلاح تطبیق' : 'تأیید پیشنهاد انتخاب‌شده', 'button button-primary');
        match.type = 'submit'; match.value = 'match';
        const noMatch = node('button', 'تأیید نبود تطبیق و نگه داشتن کالا در بررسی', 'button');
        noMatch.type = 'submit'; noMatch.value = 'no_match'; noMatch.formNoValidate = true;
        function updateChoice() {
            roles.hidden=!selectedReferenceId;
            match.hidden=!selectedReferenceId;
            match.disabled=!contextStored||!selectedReferenceId||!selectedPriceRole;
        }
        updateChoice();
        const referenceSuggestionResult = !item.decision && item.reference_suggestion
            && item.reference_suggestion.result;
        if (referenceSuggestionResult && referenceSuggestionResult.reference_id) {
            const suggestedRow = (referenceSuggestionResult.candidates || []).find(row => row
                && row.reference_id === referenceSuggestionResult.reference_id
                && String(row.name || '').trim());
            if (suggestedRow) {
                if (!rendered.has(suggestedRow.reference_id)) addReferenceOption(suggestedRow, true, true);
                if (!selectedReferenceId) {
                    const hint = suggestedRow.price_role_hint && suggestedRow.price_role_hint.role;
                    if (['unit','package'].includes(hint)) {
                        selectedPriceRole = hint;
                        roleButtons.forEach(button => button.setAttribute('aria-pressed',
                            String(button === roleButtons[hint === 'package' ? 1 : 0])));
                    }
                    rendered.get(suggestedRow.reference_id).click();
                }
            }
        }
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
        if (item.snapshot_id) card.appendChild(referenceCatalogSearch(item.snapshot_id,row=>{
            if (row.reference_id!==selectedReferenceId) {
                selectedPriceRole='';roleButtons.forEach(button=>button.setAttribute('aria-pressed','false'));
            }
            addReferenceOption(row,true).click();roleButtons[0].focus();
        }));
        card.appendChild(form);
        if (!item.reference_waived) card.appendChild(proceedWithoutReference([item],'reference',{
            enabled:contextStored,refresh:options.refresh}));
        if (suggestionSection) {
            const extra=node('details');extra.append(node('summary','پیشنهاد خودکار'),suggestionSection);card.appendChild(extra);
        }
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

    async function productStatusSnapshots(message = '', messageKind = 'info') {
        shell('سوابق وضعیت کالا', 'این صفحه تاریخچه را نشان می‌دهد و اگر از آخرین ثبت بیش از ۲۴ ساعت گذشته باشد، نسخهٔ روزانه ثبت می‌کند. برای رسیدگی روزمره از کارت‌های زندهٔ محصولات استفاده کنید.');
        if (message) root.appendChild(notice(message, messageKind));
        const liveProductsLink = ownerWorkflowLink('molavi-operations', 'بازگشت به محصولات زنده');
        if (liveProductsLink) root.appendChild(liveProductsLink);
        const refresh = node('button', 'ثبت وضعیت فعلی در تاریخچه', 'button button-primary');
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
            const data = await request('GET',undefined,snapshotUrl.toString(),'',{ttl:30000});
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

    function conflictValue(value) {
        if (value === null || value === undefined || value === '') return 'خالی';
        if (typeof value === 'object') {
            try { return JSON.stringify(value); } catch (_error) { return 'مقدار ثبت‌شده'; }
        }
        return String(value);
    }

    const actions = {
        'molavi-operations': dashboard,
        'molavi-ai-confirmations': dashboard,
        'molavi-reference-items': dashboard,
        'molavi-reference-decisions': dashboard,
        'molavi-publication-holds': dashboard,
        'molavi-supplier-prices': dashboard,
        'molavi-sheet-conflicts': dashboard,
        'molavi-product-status-snapshots': productStatusSnapshots
    };
    if (actions[config.page]) actions[config.page]();
})();
