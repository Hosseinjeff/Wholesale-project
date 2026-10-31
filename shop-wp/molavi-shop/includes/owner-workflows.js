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
            unknown_reference: 'شناسه کالای مرجع معتبر نیست.',
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
            resolution_failed: 'ثبت تصمیم تعارض ناموفق بود؛ وضعیت را تازه کنید.'
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

    function stageAction(title, description, slug) {
        const link = node('a', null, 'molavi-pipeline-action');
        link.href = config.pageUrls[slug] || '#';
        link.appendChild(node('strong', title));
        link.appendChild(node('span', description));
        return link;
    }

    function stageGroup(number, title, description, actions) {
        const section = node('section', null, 'card molavi-pipeline-stage');
        const heading = node('div', null, 'molavi-pipeline-stage-heading');
        heading.appendChild(node('span', number, 'molavi-pipeline-step'));
        const copy = node('div');
        copy.appendChild(node('h2', title));
        copy.appendChild(node('p', description));
        heading.appendChild(copy);
        section.appendChild(heading);
        const links = node('div', null, 'molavi-pipeline-actions');
        actions.forEach(([actionTitle, actionDescription, slug]) => {
            links.appendChild(stageAction(actionTitle, actionDescription, slug));
        });
        section.appendChild(links);
        return section;
    }

    async function dashboard() {
        shell('خط لوله مولوی‌سل', 'کالای جدید را از پیام تأمین‌کننده تا انتشار، به‌ترتیب آماده کنید. کارهای موجودی و سفارش پس از انتشار در پایان فهرست آمده‌اند.');
        const board = node('div', null, 'card');
        root.appendChild(board);
        board.appendChild(node('h2', 'وضعیت پردازش'));
        board.appendChild(node('p', 'در حال دریافت وضعیت کارهای صف…'));
        const groups = [
            ['۱', 'دریافت و بررسی پیام', 'پیشنهاد استخراج‌شده را با متن اصلی مقایسه و مشخصات محصول را تأیید کنید.', [
                ['بررسی استخراج کالا', 'اصلاح نام، قیمت، تعداد بسته و وضعیت موجودی تأمین‌کننده.', 'molavi-ai-confirmations']
            ]],
            ['۲', 'بررسی قیمت مرجع', 'پیش از تطبیق، از فعال و معتبر بودن فهرست مرجع مطمئن شوید.', [
                ['دریافت و فعال‌سازی فهرست', 'نسخه دریافت‌شده از API ووکامرس را بررسی کنید.', 'molavi-reference-snapshots'],
                ['رخدادهای تازگی مرجع', 'برای دریافت ناموفق یا مرجع قدیمی، تصمیم موردی ثبت کنید.', 'molavi-reference-decisions']
            ]],
            ['۳', 'تطبیق کالا و قیمت', 'پیشنهاد تأمین‌کننده را به کالای درست وصل و تازگی قیمت را بررسی کنید.', [
                ['تطبیق کالای تأمین‌کننده', 'انتخاب کالای مرجع و نقش قیمت واحد یا بسته.', 'molavi-reference-items'],
                ['اعتبار قیمت تأمین‌کننده', 'قیمت‌های قدیمی یا فاقد مدرک را بررسی کنید.', 'molavi-supplier-prices']
            ]],
            ['۴', 'انتشار و بازیابی', 'پس از تطبیق، پیگیری کنید که اطلاعات به فروشگاه رسیده و مانع حل‌نشده نمانده باشد.', [
                ['توقف‌های انتشار', 'دسته را بررسی کنید؛ ثبت بررسی، آن و انتشارهای منتظر را به صف برمی‌گرداند.', 'molavi-publication-holds'],
                ['تعارض‌های اطلاعات', 'تغییر هم‌زمان سرور و شیت را تعیین تکلیف کنید.', 'molavi-sheet-conflicts']
            ]],
            ['۵', 'موجودی و سفارش', 'این کارها پس از آماده شدن محصول انجام می‌شوند.', [
                ['ثبت و اصلاح موجودی', 'رسیدها و شمارش موجودی را ثبت یا اصلاح کنید.', 'molavi-inventory-management'],
                ['پیشنهاد قیمت حراج', 'برای موجودی واجد شرایط قیمت حراج را بررسی کنید.', 'molavi-clearance-pricing'],
                ['آماده‌سازی سفارش', 'موجودی و کسری‌های سفارش را پیگیری کنید.', 'molavi-paid-orders']
            ]]
        ];
        const cards = node('div', null, 'molavi-pipeline-grid');
        groups.forEach(([number, title, desc, actions]) => cards.appendChild(stageGroup(number, title, desc, actions)));
        root.appendChild(cards);
        try {
            const result = await request();
            const ops = result.operations || {};
            const counts = ops.by_state || {};
            const stateLabels = {queued: 'در صف', running: 'در حال اجرا', waiting_reference: 'انتظار مرجع',
                waiting_publication: 'انتظار رفع توقف انتشار', failed: 'خطا', needs_review: 'نیازمند بررسی'};
            const kindLabels = {batch: 'دسته پیام', publish: 'انتشار کالا', order: 'سفارش', notice: 'اعلان'};
            const errorLabels = {RuntimeError: 'خطای اجرا', publication_held_for_review: 'انتظار بررسی توقف انتشار',
                batch_recovery_requires_owner_review: 'نیازمند بررسی دسته'};
            const content = node('div');
            content.appendChild(node('p', `در صف: ${ops.actionable_count || 0} · نیازمند توجه: ${ops.attention_count || 0} · توقف انتشار: ${ops.publication_holds || 0} · تعارض شیت: ${ops.sheet_conflicts || 0}`));
            content.appendChild(node('p', `صف: ${counts.queued || 0} · در حال اجرا: ${counts.running || 0} · انتظار مرجع: ${counts.waiting_reference || 0} · انتظار رفع توقف انتشار: ${counts.waiting_publication || 0} · خطا: ${counts.failed || 0} · نیازمند بررسی: ${counts.needs_review || 0}`));
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

    async function publicationHolds(message = '', messageKind = 'info') {
        shell('بررسی توقف انتشار', 'ثبت بررسی همان دسته را برای پردازش مجدد صف می‌کند و انتشارهای منتظر را از سر می‌گیرد. پیش از ثبت، دلیل توقف و داده‌های مرتبط را بررسی کنید.');
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
            for (const hold of holds) {
                const card = node('section', null, 'card');
                card.appendChild(node('h2', reasons[hold.reason] || 'این دسته نیاز به بررسی دارد.'));
                const rawTime = hold.created_at;
                const parsedTime = rawTime === null || rawTime === undefined ? NaN
                    : (Number.isFinite(Number(rawTime)) ? Number(rawTime) * 1000 : new Date(rawTime).getTime());
                const timeLabel = Number.isFinite(parsedTime) ? new Date(parsedTime).toLocaleString() : 'نامشخص';
                card.appendChild(node('p', `شناسه دسته: ${hold.hold_id || ''} · زمان ثبت: ${timeLabel}`));
                const form = document.createElement('form');
                form.appendChild(field('شرح بررسی و تطبیق', 'note', '', 'textarea', { required: true, maxLength: 1000 }));
                const submit = node('button', 'ثبت بررسی و اجازه پردازش مجدد', 'button button-primary');
                submit.type = 'submit'; form.appendChild(submit);
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    if (!form.reportValidity()) return;
                    if (!window.confirm('این کار دسته را برای پردازش مجدد صف می‌کند و انتشارهای منتظر را دوباره به صف برمی‌گرداند. ادامه می‌دهید؟')) return;
                    submit.disabled = true;
                    try {
                        const receipt = await request('POST', {hold_id: hold.hold_id,
                            expected_reason: hold.reason, note: form.elements.note.value.trim(), resume_batch: true});
                        if (receipt.hold_id !== hold.hold_id || receipt.batch_resumed !== true) throw new Error('invalid_receipt');
                        await publicationHolds('دسته برای پردازش مجدد صف شد.', 'success');
                    } catch (error) { errorBox(errorText(error.message), card); submit.disabled = false; }
                });
                card.appendChild(form); root.appendChild(card);
            }
        } catch (error) { root.appendChild(notice(errorText(error.message), 'error')); }
    }

    async function supplierPrices(message = '', messageKind = 'info') {
        shell('تازگی قیمت تأمین‌کنندگان', 'قیمت‌های کهنه یا فاقد نسخه منبع برای بررسی نمایش داده می‌شوند. تأیید فقط پس از بررسی واقعی منبع ثبت شود؛ این کار انتشار را به صف می‌فرستد.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const offers = Array.isArray(data.offers) ? data.offers : [];
            if (!offers.length) root.appendChild(node('p', 'قیمت تأمین‌کننده‌ای در انتظار بررسی نیست.'));
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
                const form = document.createElement('form');
                form.appendChild(field('یادداشت بررسی منبع قیمت', 'note', '', 'textarea', {required: true, maxLength: 1000}));
                const submit = node('button', 'قیمت تأمین‌کننده را بررسی کردم', 'button button-primary');
                submit.type = 'submit'; form.appendChild(submit);
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    if (!form.reportValidity()) return;
                    if (!window.confirm('ثبت این بررسی، تأیید می‌کند که منبع فعلی قیمت را بررسی کرده‌اید. ادامه می‌دهید؟')) return;
                    submit.disabled = true;
                    try {
                        const receipt = await request('POST', {product_id: offer.product_id,
                            expected_revision: offer.source_revision, note: form.elements.note.value.trim()});
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
        shell('تعارض‌های اطلاعات کالا', 'هر دو مقدار نگهداری شده‌اند. با بررسی منبع، مقدار شیت یا سرور را برای همان فیلد انتخاب کنید.');
        if (message) root.appendChild(notice(message, messageKind));
        try {
            const data = await request();
            const conflicts = Array.isArray(data.conflicts) ? data.conflicts : [];
            if (!conflicts.length) root.appendChild(node('p', 'تعارض بازی در انتظار بررسی نیست.'));
            for (const conflict of conflicts) {
                const card = node('section', null, 'card');
                card.appendChild(node('h2', conflict.product_name || 'کالای بدون نام'));
                card.appendChild(node('p', `تأمین‌کننده: ${conflict.channel || 'نامشخص'} · فیلد: ${conflict.field || 'نامشخص'}`));
                for (const [label, key] of [['مقدار پایه', 'base'], ['مقدار سرور', 'server'], ['مقدار شیت', 'sheet']]) {
                    card.appendChild(node('p', `${label}: ${conflictValue(conflict[key])}`));
                }
                const form = document.createElement('form');
                form.appendChild(field('یادداشت بررسی و دلیل انتخاب', 'note', '', 'textarea', {required: true, maxLength: 1000}));
                const chooseServer = node('button', 'انتخاب مقدار سرور', 'button');
                chooseServer.type = 'submit'; chooseServer.value = 'server';
                const chooseSheet = node('button', 'انتخاب مقدار شیت', 'button button-primary');
                chooseSheet.type = 'submit'; chooseSheet.value = 'sheet';
                form.append(chooseServer, chooseSheet);
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    const choice = event.submitter && event.submitter.value;
                    if (!['server', 'sheet'].includes(choice) || !form.reportValidity()) return;
                    if (!window.confirm('انتخاب شما مقدار فیلد را تعیین می‌کند و انتشار کالا را صف می‌کند. ادامه می‌دهید؟')) return;
                    chooseServer.disabled = true; chooseSheet.disabled = true;
                    try {
                        const receipt = await request('POST', {conflict_id: conflict.conflict_id,
                            choice, note: form.elements.note.value.trim()});
                        if (receipt.conflict_id !== conflict.conflict_id || receipt.choice !== choice
                            || !receipt.publish_job) throw new Error('invalid_receipt');
                        await sheetConflicts('تصمیم ثبت شد و انتشار کالا به صف رفت.', 'success');
                    } catch (error) {
                        errorBox(errorText(error.message), card);
                        chooseServer.disabled = false; chooseSheet.disabled = false;
                    }
                });
                card.appendChild(form); root.appendChild(card);
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
        'molavi-sheet-conflicts': sheetConflicts
    };
    if (actions[config.page]) actions[config.page]();
})();
