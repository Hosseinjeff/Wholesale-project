(() => {
    const root = document.getElementById('molavi-owner-workflow');
    const config = window.molaviOwnerWorkflow;
    if (!root || !config) return;

    const pageLabels = {
        'molavi-ai-confirmations': 'تأیید استخراج کالا',
        'molavi-reference-snapshots': 'نسخه‌های قیمت مرجع',
        'molavi-reference-decisions': 'تازگی و اعتبار قیمت مرجع',
        'molavi-reference-items': 'تطبیق کالای تأمین‌کننده',
        'molavi-publication-holds': 'توقف انتشار و ادامه پردازش',
        'molavi-sheet-conflicts': 'تعارض‌های اطلاعات کالا',
        'molavi-supplier-prices': 'بررسی قیمت تأمین‌کنندگان',
        'molavi-clearance-pricing': 'پیشنهاد قیمت حراج موجودی',
        'molavi-inventory-management': 'اصلاح و نگهداری موجودی',
        'molavi-paid-orders': 'آماده‌سازی سفارش‌ها'
    };

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
            unknown_reference: 'شناسه کالای مرجع معتبر نیست.',
            invalid_price_role: 'نقش قیمت را انتخاب کنید.',
            stale_or_conflicting_decision: 'وضعیت تصمیم تغییر کرده است؛ صفحه را تازه کنید.',
            review_unavailable: 'ثبت تصمیم موقتاً انجام نشد. صفحه را تازه کنید و دوباره تلاش کنید.'
        };
        return labels[code] || 'ارتباط با سرور برقرار نشد. کد پیگیری: ' + (code || 'نامشخص');
    }

    async function request(method = 'GET', payload = undefined) {
        const options = { method, mode: 'cors', cache: 'no-store',
            headers: { Authorization: 'Bearer ' + config.token } };
        if (payload !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }
        const response = await fetch(config.apiUrl, options);
        const body = await response.json().catch(() => null);
        if (!response.ok || !body || body.ok !== true) {
            throw new Error(body && typeof body.error === 'string' ? body.error : 'request_failed');
        }
        return body;
    }

    function shell(title, description) {
        root.replaceChildren();
        const header = node('div', null, 'molavi-workflow-heading');
        header.appendChild(node('h1', title));
        const back = node('a', 'بازگشت به خط لوله');
        back.href = config.pipelineUrl;
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

    function stageCard(title, description, slug) {
        const card = node('a', null, 'card');
        card.href = config.pageUrls[slug] || '#';
        card.style.cssText = 'display:block;min-width:14rem;flex:1;text-decoration:none';
        card.appendChild(node('h2', title));
        card.appendChild(node('p', description));
        return card;
    }

    async function dashboard() {
        shell('خط لوله مولوی‌سل', 'مراحل آماده‌سازی کالا را از دریافت پیام تأمین‌کننده تا تطبیق و انتشار، به‌ترتیب پیگیری کنید.');
        const board = node('div', null, 'card');
        root.appendChild(board);
        board.appendChild(node('h2', 'وضعیت پردازش'));
        board.appendChild(node('p', 'در حال دریافت وضعیت کارهای صف…'));
        const groups = [
            ['۱. بررسی پیام و استخراج', 'مشخصات نامطمئن را با متن اصلی پیام مقایسه و اصلاح کنید.', 'molavi-ai-confirmations'],
            ['۲. دریافت فهرست مرجع', 'نسخه کالاها و قیمت‌های تأمین‌کننده مرجع را بررسی و فعال کنید.', 'molavi-reference-snapshots'],
            ['۳. تصمیم تازگی مرجع', 'برای نسخه قدیمی یا دریافت ناموفق، تصمیم ثبت کنید.', 'molavi-reference-decisions'],
            ['۴. تطبیق پیشنهادها', 'هر کالای تأمین‌کننده را به مرجع درست و نقش قیمت وصل کنید.', 'molavi-reference-items'],
            ['۵. انتشار و بازیابی', 'توقف‌ها، تعارض‌های شیت و کارهای ناموفق را بررسی کنید.', 'molavi-publication-holds'],
            ['قیمت تأمین‌کننده', 'قیمت تازه و شواهد منبع را بررسی کنید.', 'molavi-supplier-prices'],
            ['تعارض اطلاعات', 'تغییرهای هم‌زمان شیت و سرور را تعیین تکلیف کنید.', 'molavi-sheet-conflicts'],
            ['رسید و اصلاح موجودی', 'موجودی واقعی، رسیدها و اصلاح شمارش را ثبت کنید.', 'molavi-inventory-management'],
            ['قیمت حراج', 'برای موجودی قابل فروش پیشنهاد قیمت بررسی کنید.', 'molavi-clearance-pricing'],
            ['آماده‌سازی سفارش', 'موجودی سفارش و کسری‌های تأمین را پیگیری کنید.', 'molavi-paid-orders']
        ];
        const cards = node('div');
        cards.style.cssText = 'display:flex;flex-wrap:wrap;gap:12px;margin-top:18px';
        groups.forEach(([title, desc, slug]) => cards.appendChild(stageCard(title, desc, slug)));
        root.appendChild(cards);
        try {
            const result = await request();
            const ops = result.operations || {};
            const counts = ops.by_state || {};
            const content = node('div');
            content.appendChild(node('p', `در صف: ${ops.actionable_count || 0} · نیازمند توجه: ${ops.attention_count || 0} · توقف انتشار: ${ops.publication_holds || 0} · تعارض شیت: ${ops.sheet_conflicts || 0}`));
            content.appendChild(node('p', `صف: ${counts.queued || 0} · در حال اجرا: ${counts.running || 0} · انتظار مرجع: ${counts.waiting_reference || 0} · خطا: ${counts.failed || 0} · نیازمند بررسی: ${counts.needs_review || 0}`));
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
                    [item.id, item.kind, item.state, item.attempts, item.error].forEach(value => line.appendChild(node('td', value || '')));
                    body.appendChild(line);
                });
                table.appendChild(body); content.appendChild(table);
            }
            board.replaceChildren(node('h2', 'وضعیت پردازش'), content);
        } catch (error) {
            board.replaceChildren(node('h2', 'وضعیت پردازش'), notice(errorText(error.message), 'error'));
        }
    }

    function intValue(form, name) {
        const value = form.elements[name].value.trim();
        return value === '' ? '' : Number.parseInt(value, 10);
    }

    function aiCaseCard(item) {
        const card = node('section', null, 'card');
        card.appendChild(node('h2', item.product_name || 'کالای بدون نام'));
        card.appendChild(node('p', `تأمین‌کننده: ${item.channel || 'نامشخص'} · قیمت استخراج‌شده: ${amount(item.detected_price || item.sale_price)} · نقش: ${item.price_type || 'نامشخص'}`));
        card.appendChild(node('p', `دلیل بررسی: ${item.reason || 'نیازمند بررسی'} · حداقل خرید: ${item.min_qty || 'ثبت نشده'}`));
        if (item.original_message) {
            const details = document.createElement('details'); details.appendChild(node('summary', 'متن اصلی پیام تأمین‌کننده'));
            details.appendChild(node('pre', item.original_message)); card.appendChild(details);
        }
        if (item.decision) card.appendChild(node('p', `تصمیم قبلی: ${item.decision.choice || ''} · ${item.decision.note || ''}`));
        const fields = item.decision && item.decision.fields || {};
        const role = fields.price_role || item.price_type || '';
        const isPackage = ['pack', 'package', 'carton'].includes(String(role).toLowerCase());
        const price = fields.sale_price ?? (isPackage && item.package_price ? item.package_price : (item.sale_price || item.detected_price || ''));
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
        form.appendChild(field('یادداشت مالک', 'note', item.decision && item.decision.note || '', 'textarea', { required: true, maxLength: 1000 }));
        const confirm = node('button', item.decision ? 'ثبت اصلاح و تأیید دوباره' : 'تأیید مشخصات استخراج‌شده', 'button button-primary');
        confirm.type = 'submit'; confirm.value = 'confirm';
        const notProduct = node('button', 'این مورد کالا نیست', 'button');
        notProduct.type = 'submit'; notProduct.value = 'not_product'; notProduct.formNoValidate = true;
        form.append(confirm, notProduct);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const choice = event.submitter && event.submitter.value;
            if (!['confirm', 'not_product'].includes(choice)) return;
            if (choice === 'confirm' && !form.reportValidity()) return;
            if (!form.elements.note.value.trim()) { form.elements.note.reportValidity(); return; }
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, signature: item.signature,
                choice, note: form.elements.note.value, expected_decision_id: item.previous_decision_id || null,
                fields: choice === 'confirm' ? {
                    price_role: form.elements.price_role.value,
                    sale_price: intValue(form, 'sale_price'),
                    actual_price: intValue(form, 'actual_price'),
                    package_count: intValue(form, 'package_count'),
                    min_qty: intValue(form, 'min_qty'),
                    stock_status: form.elements.stock_status.value
                } : {}
            };
            confirm.disabled = true; notProduct.disabled = true;
            try {
                const receipt = await request('POST', payload);
                if (receipt.case_id !== payload.case_id || receipt.offer_id !== payload.offer_id
                    || receipt.signature !== payload.signature || receipt.choice !== choice
                    || !/^[a-f0-9]{64}$/.test(receipt.decision_id || '')
                    || !['imported', 'needs_review', 'not_product'].includes(receipt.projection_state)) {
                    throw new Error('invalid_receipt');
                }
                await aiConfirmations('تصمیم ثبت شد و برای انتشار به صف رفت.', 'success');
            } catch (error) { errorBox(errorText(error.message), card); confirm.disabled = false; notProduct.disabled = false; }
        });
        card.appendChild(form);
        return card;
    }

    async function aiConfirmations(message = '', messageKind = 'info') {
        shell('تأیید استخراج کالا', 'مشخصات نامطمئن را با متن پیام اصلی بررسی کنید. تا ثبت تصمیم مالک، این پیشنهادها در فروشگاه منتشر نمی‌شوند.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            if (data.publication_held) root.appendChild(notice('توقف ایمنی انتشار فعال است؛ ابتدا آن را در بخش انتشار و بازیابی بررسی کنید.', 'warning'));
            const cases = new Map();
            [...(data.cases || []), ...(data.recent_decisions || [])].forEach(item => { if (item && item.case_id) cases.set(item.case_id, item); });
            if (!cases.size) root.appendChild(node('p', 'موردی برای بررسی وجود ندارد.'));
            for (const item of cases.values()) root.appendChild(aiCaseCard(item));
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    function referenceItemCard(item) {
        const card = node('section', null, 'card');
        card.appendChild(node('h2', item.product_name || 'کالای بدون نام'));
        card.appendChild(node('p', `تأمین‌کننده: ${item.channel || ''} · قیمت ثبت‌شده: ${amount(item.supplier_price)}`));
        card.appendChild(node('p', `دلیل بررسی: ${item.reason || ''} · نقش پیشنهادی: ${item.price_role_hint || 'نیازمند بررسی'}`));
        if (item.decision) card.appendChild(node('p', `تصمیم فعلی: ${item.decision.choice || ''} · مرجع: ${item.decision.reference_id || 'ندارد'} · نقش: ${item.decision.price_role || ''} · ${item.decision.note || ''}`));
        const form = document.createElement('form');
        const candidateRows = (item.candidates || []).filter(row => row && row.reference_id);
        const currentReference = item.decision && item.decision.reference_id;
        const choices = [['', 'انتخاب مرجع']];
        if (currentReference && !candidateRows.some(row => row.reference_id === currentReference)) {
            choices.push([currentReference, 'تطبیق فعلی: ' + currentReference]);
        }
        candidateRows.forEach(row => choices.push([row.reference_id,
            `${row.name || row.reference_id} · تطبیق ${row.score || ''} · تکی ${amount(row.unit_price)} · بسته ${amount(row.package_price)}`]));
        form.appendChild(field('نامزدهای پیشنهادی', 'candidate_reference_id', item.decision && item.decision.reference_id || '', 'select', { choices }));
        form.appendChild(field('یا شناسه دقیق مرجع', 'reference_id', '', 'text', { maxLength: 300 }));
        form.appendChild(field('نقش قیمت', 'price_role', item.decision && item.decision.price_role || 'unit', 'select', {
            choices: [['unit', 'قیمت هر واحد'], ['package', 'قیمت بسته / کارتن']]
        }));
        form.appendChild(field('یادداشت مالک', 'note', item.decision && item.decision.note || '', 'textarea', { required: true, maxLength: 1000 }));
        const match = node('button', item.decision ? 'اصلاح تطبیق و نقش قیمت' : 'ثبت تطبیق و نقش قیمت', 'button button-primary');
        match.type = 'submit'; match.value = 'match';
        const noMatch = node('button', 'تأیید نبود تطبیق و نگه داشتن کالا در بررسی', 'button');
        noMatch.type = 'submit'; noMatch.value = 'no_match'; noMatch.formNoValidate = true;
        form.append(match, noMatch);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const choice = event.submitter && event.submitter.value;
            if (!['match', 'no_match'].includes(choice)) return;
            if (choice === 'match' && !form.reportValidity()) return;
            if (!form.elements.note.value.trim()) { form.elements.note.reportValidity(); return; }
            const referenceId = choice === 'match'
                ? (form.elements.reference_id.value.trim() || form.elements.candidate_reference_id.value) : '';
            if (choice === 'match' && !referenceId) {
                errorBox('یک کالای مرجع را از فهرست انتخاب کنید یا شناسه دقیق آن را وارد کنید.', card);
                return;
            }
            const payload = {
                case_id: item.case_id, offer_id: item.offer_id, snapshot_id: item.snapshot_id,
                choice, reference_id: referenceId,
                price_role: choice === 'match' ? form.elements.price_role.value : 'unresolved',
                note: form.elements.note.value,
                expected_decision_id: item.decision && item.decision.decision_id || null
            };
            match.disabled = true; noMatch.disabled = true;
            try {
                const receipt = await request('POST', payload);
                if (receipt.case_id !== payload.case_id || receipt.offer_id !== payload.offer_id
                    || receipt.snapshot_id !== payload.snapshot_id || receipt.choice !== choice
                    || !/^[a-f0-9]{64}$/.test(receipt.decision_id || '')
                    || !['imported', 'needs_review', 'held_for_review'].includes(receipt.projection_state)
                    || (choice === 'no_match' && receipt.projection_state !== 'held_for_review')
                    || (choice === 'match' && receipt.projection_state === 'held_for_review')) {
                    throw new Error('invalid_receipt');
                }
                await referenceItems('تصمیم تطبیق ثبت شد.', 'success');
            } catch (error) { errorBox(errorText(error.message), card); match.disabled = false; noMatch.disabled = false; }
        });
        card.appendChild(form);
        return card;
    }

    async function referenceItems(message = '', messageKind = 'info') {
        shell('تطبیق کالای تأمین‌کننده', 'هر پیشنهاد را به کالای مرجع درست وصل کنید و مشخص کنید قیمت برای یک واحد است یا یک بسته. کالای بدون تطبیق در بررسی می‌ماند.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const state = data.reference_state || {};
            if (!state.allow_automatic) root.appendChild(notice('نسخه قیمت مرجع فعال و معتبر نیست. ابتدا فهرست مرجع را از API ووکامرس دریافت، بررسی و فعال کنید.', 'warning'));
            const cases = [...(data.cases || []), ...(data.recent_decisions || [])];
            if (!cases.length) root.appendChild(node('p', 'مورد تطبیقی برای بررسی وجود ندارد.'));
            cases.forEach(item => root.appendChild(referenceItemCard(item)));
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

    const actions = {
        'molavi-operations': dashboard,
        'molavi-ai-confirmations': aiConfirmations,
        'molavi-reference-items': referenceItems,
        'molavi-reference-decisions': referenceDecisions
    };
    if (actions[config.page]) actions[config.page]();
})();
