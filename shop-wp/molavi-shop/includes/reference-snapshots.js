(() => {
    const root = document.getElementById('molavi-reference-snapshots');
    const config = window.molaviReferenceSnapshots;
    if (!root) return;

    function element(tag, text, className) {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = String(text);
        if (className) node.className = className;
        return node;
    }

    function messageText(error) {
        const messages = {
            unauthorized: 'نشست شما منقضی شده است. صفحه را تازه کنید.',
            owner_required: 'این حساب اجازه بررسی قیمت‌های مرجع را ندارد.',
            workflow_proxy_unavailable: 'اتصال امن وردپرس به سرویس پردازش آماده نیست؛ صفحه را تازه کنید.',
            upstream_unavailable: 'ارتباط وردپرس با سرویس پردازش برقرار نشد؛ چند لحظه بعد دوباره تلاش کنید.',
            invalid_nonce: 'نشست مدیریت منقضی شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
            active_snapshot_changed: 'نسخه فعال تغییر کرده است. فهرست را دوباره بررسی کنید.',
            snapshot_changed_or_unreviewed: 'وضعیت نسخه تغییر کرده است. فهرست را دوباره بررسی کنید.',
            snapshot_not_found: 'این نسخه دیگر در دسترس نیست.',
            snapshot_incomplete: 'کامل بودن استخراج این نسخه تأیید نشده است و نمی‌توان آن را بررسی یا فعال کرد.',
            sheets_unavailable: 'ثبت قیمت‌ها موقتاً در دسترس نیست.',
            refresh_state_changed: 'وضعیت دریافت تغییر کرده است. فهرست را دوباره بررسی کنید.',
            refresh_cooldown: 'برای تلاش دوباره هنوز باید کمی صبر کنید.',
            refresh_not_retryable: 'این تلاش در حال حاضر قابل تکرار نیست.',
            review_note_required: 'یادداشت بررسی را وارد کنید.',
            invalid_body: 'اطلاعات درخواست معتبر نیست.',
            invalid_fields: 'اطلاعات درخواست معتبر نیست.',
            invalid_receipt: 'نتیجه عملیات تأیید نشد. فهرست را دوباره بارگذاری کنید.'
        };
        return messages[error] || 'فهرست قیمت بارگذاری نشد. اتصال را بررسی کنید و دوباره تلاش کنید.';
    }

    function notice(text, kind) {
        const className = kind === 'error' ? 'notice notice-error'
            : (kind === 'success' ? 'notice notice-success' : 'notice notice-info');
        return element('p', text, className);
    }

    function formatDate(value) {
        if (!value) return 'ثبت نشده';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return 'ثبت نشده';
        try {
            return new Intl.DateTimeFormat('fa-IR', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
        } catch (_) {
            return date.toLocaleString();
        }
    }

    function formatAmount(value) {
        if (value === null || value === undefined || value === '') return 'ثبت نشده';
        const amount = Number(value);
        if (!Number.isFinite(amount) || amount <= 0) return 'ثبت نشده';
        return new Intl.NumberFormat('fa-IR').format(amount) + ' تومان';
    }

    function formatCount(value, unitLabel) {
        const count = Number(value);
        if (!Number.isFinite(count) || count <= 0) return 'ثبت نشده';
        return new Intl.NumberFormat('fa-IR').format(count) + (unitLabel ? ' ' + unitLabel : ' عدد');
    }

    function refreshLabel(state) {
        return ({
            updated: 'مرجع با تغییرات تازه به‌روز شد',
            unchanged: 'تغییری پیدا نشد؛ مرجع فعلی معتبر است',
            staged: 'نسخه تازه آماده بررسی است',
            failed: 'دریافت فهرست مرجع رزوان کامل نشد',
            running: 'دریافت فهرست مرجع رزوان در حال انجام است'
        })[state] || 'وضعیت دریافت مشخص نیست';
    }

    function refreshErrorText(errorCode) {
        const code = String(errorCode || '');
        const baseCode = code.replace(/_page_\d+_(left|right)$/, '');
        const batch = code.match(/^extraction_batch_row_count_mismatch_page_(\d+)_(left|right)$/);
        if (batch) {
            return 'جدول سمت ' + (batch[2] === 'left' ? 'چپ' : 'راست') + ' صفحه ' + batch[1]
                + ' کامل خوانده نشد؛ پیش‌نویس تازه ساخته نشد.';
        }
        const rowNumbers = code.match(/^extraction_batch_row_numbers_mismatch_page_(\d+)_(left|right)$/);
        if (rowNumbers) {
            return 'شماره ردیف‌های جدول سمت ' + (rowNumbers[2] === 'left' ? 'چپ' : 'راست')
                + ' صفحه ' + rowNumbers[1] + ' با PDF تطبیق نداشت؛ پیش‌نویس تازه ساخته نشد.';
        }
        const duplicate = code.match(/^extraction_duplicate_row_number_(\d+)_page_(\d+)_(left|right)$/);
        if (duplicate) {
            return 'مدل در جدول سمت ' + (duplicate[3] === 'left' ? 'چپ' : 'راست')
                + ' صفحه ' + duplicate[2] + ' شماره ردیف ' + duplicate[1]
                + ' را تکرار کرد؛ پیش‌نویس تازه ساخته نشد.';
        }
        if (/^extraction_duplicate_row_number(?:_page_\d+_(?:left|right))?$/.test(code)) {
            return 'یک شماره ردیف تکراری بود؛ پیش‌نویس تازه ساخته نشد.';
        }
        const finalPage = code.match(/^extraction_final_page_empty_page_(\d+)$/);
        if (finalPage) {
            return 'از صفحه پایانی ' + finalPage[1] + ' هیچ کالایی خوانده نشد؛ پیش‌نویس تازه ساخته نشد.';
        }
        if (code === 'extraction_row_gap' || code === 'extraction_row_sequence_invalid'
            || code === 'extraction_too_few_rows') {
            return 'تلاش قدیمی خواندن PDF کامل نبود؛ فهرست تازه از API رزوان دریافت می‌شود.';
        }
        if (code.startsWith('extraction_') || baseCode.startsWith('gemini_')) {
            return 'تلاش قبلی PDF/Gemini کامل نشد؛ فهرست تازه از API رزوان دریافت می‌شود.';
        }
        const transport = code.match(/^woocommerce_transport_(read_timeout|connect_timeout|timeout|tls_failed|connection_failed)_page_(\d+)$/);
        if (transport) {
            const page = 'صفحه ' + transport[2];
            if (transport[1] === 'read_timeout') return 'API رزوان صفحه ' + page + ' را کامل نفرستاد.';
            if (transport[1] === 'connect_timeout') return 'اتصال به API رزوان برای ' + page + ' به‌موقع برقرار نشد.';
            if (transport[1] === 'tls_failed') return 'ارتباط امن با API رزوان برای ' + page + ' برقرار نشد.';
            return 'ارتباط با API رزوان برای ' + page + ' قطع شد.';
        }
        if (code === 'woocommerce_transport_failed') return 'ارتباط با API رزوان پایدار نبود.';
        if (code === 'woocommerce_currency_unsupported' || code === 'woocommerce_currency_mismatch') {
            return 'واحد پول فهرست مرجع پشتیبانی نمی‌شود؛ نسخه تازه ساخته نشد.';
        }
        if (code === 'woocommerce_pagination_changed' || code === 'woocommerce_pagination_invalid'
            || code.startsWith('woocommerce_page_incomplete_')
            || code === 'woocommerce_product_coverage_incomplete') {
            return 'همه صفحه‌های فهرست رزوان دریافت نشد.';
        }
        if (/^woocommerce_http_\d+$/.test(code)) {
            return 'API رزوان پاسخ نداد (' + code.replace('woocommerce_http_', 'HTTP ') + ').';
        }
        return 'استخراج کامل نشد؛ پیش‌نویس تازه ساخته نشد. کد پیگیری: ' + (code || 'نامشخص');
    }

    async function request(method, payload, url) {
        if (!config.apiProxyUrl || !config.apiProxyNonce) throw new Error('workflow_proxy_unavailable');
        const target = new URL(url || config.apiUrl, window.location.href);
        const apiBase = new URL(config.apiUrl, window.location.href);
        if (target.origin !== apiBase.origin || target.pathname !== apiBase.pathname) {
            throw new Error('workflow_route_unavailable');
        }
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
            throw new Error(body && typeof body.error === 'string' ? body.error : 'request_failed');
        }
        return body;
    }

    function addReloadButton(parent) {
        const button = element('button', 'بارگذاری دوباره', 'button');
        button.type = 'button';
        button.addEventListener('click', () => load());
        parent.appendChild(button);
    }

    function appendActionForm(parent, action, snapshot, activeId) {
        const form = element('form');
        const isActive = snapshot.snapshot_id === activeId;
        const buttonText = action === 'review'
            ? 'ثبت بررسی'
            : (isActive ? 'ثبت دوباره در فهرست قیمت‌ها' : 'فعال‌سازی و ثبت قیمت‌ها');
        const button = element('button', buttonText, action === 'activate' ? 'button button-primary' : 'button');
        button.type = 'submit';
        let noteField = null;
        if (action === 'review') {
            const label = element('label', 'یادداشت بررسی');
            noteField = element('textarea');
            noteField.name = 'note';
            noteField.required = true;
            noteField.maxLength = 1000;
            noteField.rows = 3;
            noteField.className = 'large-text';
            noteField.placeholder = 'بنویسید نمونه کالاها و قیمت‌ها را بررسی کردید.';
            label.appendChild(noteField);
            form.appendChild(label);
        }
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (action === 'activate' && !window.confirm(
                'با این کار، قیمت‌های این نسخه در فهرست مرجع ثبت و برای استفاده فعال می‌شوند. ادامه می‌دهید؟')) {
                return;
            }
            button.disabled = true;
            try {
                const payload = {
                    action,
                    snapshot_id: snapshot.snapshot_id,
                    expected_active_id: activeId || null
                };
                if (noteField) payload.note = noteField.value.trim();
                const receipt = await request('POST', payload);
                if (receipt.action !== action || receipt.snapshot_id !== snapshot.snapshot_id) {
                    throw new Error('invalid_receipt');
                }
                if (action === 'activate' && (receipt.active_snapshot_id !== snapshot.snapshot_id
                    || receipt.projection_state !== 'written' || Number(receipt.row_count) < 1)) {
                    throw new Error('invalid_receipt');
                }
                await load(action === 'review'
                    ? 'یادداشت بررسی ثبت شد. برای وارد شدن قیمت‌ها به فهرست، نسخه را فعال کنید.'
                    : 'قیمت‌ها در فهرست مرجع ثبت شد و این نسخه فعال شد.');
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
            }
        });
        parent.appendChild(form);
    }

    function appendRefreshForm(parent, refresh) {
        const form = element('form');
        const button = element('button', 'به‌روزرسانی فهرست مرجع', 'button');
        button.type = 'submit';
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm(
                'فهرست مرجع از منبع رزوان دریافت می‌شود. فقط کالاها و بخش‌هایی که تغییر کرده‌اند در دادهٔ مقایسه و ReferencePrices به‌روز می‌شوند؛ کالاهای bot با همین دادهٔ ذخیره‌شده مقایسه می‌شوند. ادامه می‌دهید؟')) {
                return;
            }
            button.disabled = true;
            try {
                const receipt = await request('POST', {
                    action: 'refresh',
                    expected_last_attempt_at: refresh.last_attempt_at || null
                });
                if (receipt.action !== 'refresh' || !['updated', 'unchanged', 'staged', 'failed'].includes(receipt.state)
                    || typeof receipt.last_attempt_at !== 'string') {
                    throw new Error('invalid_receipt');
                }
                const summary = receipt.change_summary || {};
                const count = value => new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
                const success = receipt.state === 'updated'
                    ? (summary.metadata_upgrade
                        ? 'شناسه‌های پایدار و دادهٔ خام به نسخهٔ مرجع فعال پیوند داده شدند.'
                        : `مرجع به‌روز شد: ${count(summary.added_count)} کالای تازه، ${count(summary.updated_count)} کالای تغییرکرده و ${count(summary.removed_count)} کالای حذف‌شده.`)
                    : receipt.state === 'unchanged'
                        ? 'تغییری نبود؛ دادهٔ مرجع قبلی برای مقایسه باقی ماند.'
                        : receipt.state === 'staged'
                            ? 'دریافت کامل شد؛ این نسخهٔ اولیه برای بررسی آماده است.'
                            : refreshErrorText(receipt.error_code);
                await load(success);
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
            }
        });
        parent.appendChild(form);
    }

    function appendRefreshStatus(parent, refresh) {
        if (!refresh || typeof refresh !== 'object') return;
        const card = element('section', undefined, 'card');
        card.appendChild(element('h2', 'دریافت فهرست مرجع رزوان'));
        const label = refreshLabel(refresh.state);
        card.appendChild(element('p', 'وضعیت: ' + label));
        if (refresh.last_attempt_at) {
            card.appendChild(element('p', 'آخرین تلاش: ' + formatDate(refresh.last_attempt_at), 'description'));
        }
        if (refresh.state === 'failed' && refresh.error_code) {
            card.appendChild(element('p', 'علت: ' + refreshErrorText(refresh.error_code), 'description'));
        }
        if (refresh.state === 'updated' || refresh.state === 'unchanged') {
            const summary = refresh.change_summary || {};
            const count = value => new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
            if (refresh.state === 'updated') {
                card.appendChild(element('p', `${count(summary.updated_count)} کالا تغییر کرد؛ ${count(summary.added_count)} کالا اضافه و ${count(summary.removed_count)} کالا حذف شد.`, 'description'));
            }
            card.appendChild(element('p', 'مقایسهٔ کالاهای bot از دادهٔ مرجع ذخیره‌شده انجام می‌شود؛ ووکامرس فروشگاه شما در این مقایسه خوانده نمی‌شود.', 'description'));
        }
        if (['failed', 'staged', 'updated', 'unchanged'].includes(refresh.state)) {
            appendRefreshForm(card, refresh);
        }
        parent.appendChild(card);
    }

    function attributeList(item) {
        const attributes = [
            ['برند', item.brand], ['نوع / طعم', item.variant], ['حجم', item.volume],
            ['وزن', item.weight], ['تعداد در مجموعه', item.set_count],
            ['دسته', item.category], ['سایر جزئیات عنوان', item.raw_name_details]
        ].filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '' && String(value) !== '0');
        return attributes.map(([label, value]) => label + ': ' + value).join(' · ')
            || 'مشخصه جداگانه‌ای از API یا نشانگرهای عنوان موجود نیست؛ عنوان کامل حفظ شده است.';
    }

    function appendPreview(card, snapshot) {
        const preview = Array.isArray(snapshot.preview) ? snapshot.preview.slice(0, 8) : [];
        if (!preview.length) return;

        card.appendChild(element('h3', 'نمونه کالاها'));
        const table = element('table', undefined, 'widefat striped');
        const head = element('thead');
        const headerRow = element('tr');
        ['شناسه پایدار / کد کالا', 'عنوان کامل مرجع', 'مشخصات منبع یا عنوان', 'قیمت فعلی', 'تعداد در بسته'].forEach((label, index) => {
            const th = element('th', label);
            th.setAttribute('scope', 'col');
            if (index === 0) th.className = 'column-primary';
            headerRow.appendChild(th);
        });
        head.appendChild(headerRow);
        table.appendChild(head);

        const body = element('tbody');
        preview.forEach((item) => {
            if (!item || typeof item !== 'object') return;
            const row = element('tr');
            const idCell = element('td');
            idCell.appendChild(element('strong', 'شناسه مرجع: ' + (item.source_product_id || 'ثبت نشده')));
            idCell.appendChild(element('p', 'ردیف منبع ' + (item.source_row_number || '—'), 'description'));
            idCell.appendChild(element('p', 'کد کالا: ' + (item.product_code || 'ثبت نشده'), 'description'));
            row.appendChild(idCell);

            const nameCell = element('td');
            nameCell.appendChild(element('strong', item.product_name || 'عنوان پیدا نشد'));
            nameCell.appendChild(element('p', 'عنوان کامل مرجع؛ کلید جست‌وجو: ' + (item.normalized_key || item.clean_name || 'ثبت نشده'), 'description'));
            row.appendChild(nameCell);
            row.appendChild(element('td', attributeList(item)));

            const prices = element('td');
            prices.appendChild(element('p', 'قیمت بسته: ' + formatAmount(item.package_price)));
            prices.appendChild(element('p', 'قیمت هر عدد: ' + formatAmount(item.unit_price)));
            prices.appendChild(element('p', 'قیمت فروش تک‌عدد: ' + formatAmount(item.consumer_unit_price)));
            row.appendChild(prices);
            row.appendChild(element('td', formatCount(item.package_count, item.unit_label)));
            body.appendChild(row);
        });
        table.appendChild(body);
        card.appendChild(table);
        card.appendChild(element('p', 'این نمونه ' + new Intl.NumberFormat('fa-IR').format(preview.length)
            + ' ردیف از ' + new Intl.NumberFormat('fa-IR').format(Number(snapshot.row_count) || preview.length)
            + ' محصول را نشان می‌دهد. فایل CSV همه ردیف‌ها، عنوان‌های کامل و مشخصات موجود را دارد.', 'description'));
        if (snapshot.coverage_verified === true) {
            card.appendChild(element('p', 'تعداد محصولات با تعداد اعلام‌شدهٔ منبع و شماره ردیف تطبیق دارد؛ این بررسی درستی اطلاعات فروشنده را ثابت نمی‌کند. عنوان، کد و قیمت را با منبع مرجع مقایسه کنید.', 'description'));
        }
        try {
            const source = new URL(snapshot.source_url);
            if (source.protocol === 'https:' && source.hostname === 'rezvanpakhsh.com'
                && source.pathname === '/wp-json/wc/store/v1/products') {
                const link = element('a', 'باز کردن API محصولات');
                link.href = source.toString();
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                card.appendChild(link);
            }
        } catch (_) { /* An absent or invalid source link does not block local review. */ }
        appendDownloadButton(card, snapshot);
    }

    function csvCell(value) {
        return '"' + String(value === null || value === undefined ? '' : value).replace(/"/g, '""') + '"';
    }

    function appendDownloadButton(parent, snapshot) {
        const button = element('button', 'دانلود همه ردیف‌ها برای بررسی (CSV)', 'button');
        button.type = 'button';
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                const url = new URL(config.apiUrl);
                url.searchParams.set('snapshot_id', snapshot.snapshot_id);
                url.searchParams.set('include_rows', '1');
                const result = await request('GET', undefined, url.toString());
                if (result.snapshot_id !== snapshot.snapshot_id || !Array.isArray(result.rows)
                    || result.rows.length !== Number(snapshot.row_count)) {
                    throw new Error('invalid_receipt');
                }
                const columns = [
                    ['شناسه پایدار منبع', 'source_product_id'],
                    ['ردیف منبع', 'source_row_number'], ['کد کالا', 'product_code'],
                    ['عنوان کامل مرجع', 'product_name'], ['نام مرتب‌شده', 'clean_name'],
                    ['برند', 'brand'], ['نوع / طعم', 'variant'], ['دسته', 'category'],
                    ['حجم', 'volume'], ['وزن', 'weight'], ['تعداد در مجموعه', 'set_count'],
                    ['تعداد در بسته', 'package_count'], ['واحد', 'unit_label'],
                    ['قیمت بسته (تومان)', 'package_price'], ['قیمت هر عدد (تومان)', 'unit_price'],
                    ['قیمت فروش تک‌عدد (تومان)', 'consumer_unit_price'], ['سایر جزئیات عنوان', 'raw_name_details']
                ];
                const csv = '\uFEFF' + [
                    columns.map(([label]) => csvCell(label)).join(','),
                    ...result.rows.map((item) => columns.map(([, key]) => csvCell(item[key])).join(','))
                ].join('\r\n');
                const blobUrl = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
                const link = element('a');
                link.href = blobUrl;
                link.download = 'molavi-reference-review.csv';
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
            } finally {
                button.disabled = false;
            }
        });
        parent.appendChild(button);
    }

    function render(data, successMessage) {
        root.replaceChildren();
        if (successMessage) root.appendChild(notice(successMessage, 'success'));
        appendRefreshStatus(root, data.refresh_state);

        const snapshots = Array.isArray(data.snapshots) ? data.snapshots : [];
        if (snapshots.length === 0) {
            root.appendChild(element('p', 'نسخه قیمتی برای بررسی وجود ندارد.'));
            return;
        }

        snapshots.forEach((snapshot) => {
            if (!snapshot || typeof snapshot.snapshot_id !== 'string'
                || !/^[a-f0-9]{64}$/.test(snapshot.snapshot_id)) return;
            const card = element('section', undefined, 'card');
            const active = data.active_snapshot_id === snapshot.snapshot_id || Boolean(snapshot.active);
            const reviewed = Boolean(snapshot.reviewed_by);
            const supportedSource = snapshot.source_type === 'woocommerce_store_api';
            const complete = supportedSource && snapshot.coverage_verified === true;
            const status = active && !supportedSource ? 'فعالِ قدیمی از PDF/Gemini؛ نیازمند جایگزینی'
                : (active ? 'فعال و در حال استفاده'
                : (!complete ? 'کامل بودن استخراج تأیید نشده'
                    : (snapshot.automatic_update ? 'به‌روزرسانی خودکار ثبت شد'
                    : (reviewed ? 'بررسی شده؛ آماده فعال‌سازی' : 'نیاز به بررسی'))));

            card.appendChild(element('h2', supportedSource ? (snapshot.automatic_update
                ? 'به‌روزرسانی خودکار مرجع' : 'نسخهٔ فهرست مرجع')
                : 'پیش‌نویس قدیمی PDF/Gemini'));
            card.appendChild(element('p', 'وضعیت: ' + status, 'description'));
            card.appendChild(element('p', (snapshot.active ? 'آخرین دریافت: ' : 'زمان نسخه: ')
                + formatDate(snapshot.last_checked_at || snapshot.extracted_at)
                + ' · تعداد ردیف: ' + new Intl.NumberFormat('fa-IR').format(Number(snapshot.row_count) || 0)
                + (snapshot.source_page_count ? ' · صفحات API: ' + new Intl.NumberFormat('fa-IR').format(snapshot.source_page_count) : '')
                + (snapshot.first_source_row_number && snapshot.last_source_row_number
                    ? ' · ردیف‌های منبع: ' + snapshot.first_source_row_number + ' تا ' + snapshot.last_source_row_number : '')
                + (snapshot.source_currency ? ' · واحد قیمت API: ' + snapshot.source_currency : '')));
            appendPreview(card, snapshot);

            if (!supportedSource) {
                card.appendChild(notice('این پیش‌نویس قدیمی از PDF/Gemini ساخته شده است. نسخهٔ مرجع فعلی از API عمومی رزوان دریافت می‌شود؛ این نسخهٔ قدیمی فعال نمی‌شود.', 'error'));
            } else if (!complete) {
                card.appendChild(notice(active
                    ? 'این نسخه در حال حاضر فعال است، اما کامل بودن استخراج آن قابل تأیید نیست؛ از این صفحه نمی‌توان دوباره آن را ثبت یا فعال کرد.'
                    : 'کامل بودن فهرست دریافتی تأیید نشده؛ بررسی و فعال‌سازی این نسخه غیرفعال است. فهرست تازه را از API رزوان دریافت کنید.', 'error'));
            } else if (snapshot.automatic_update) {
                card.appendChild(notice('این نسخه فقط فیلدهای تغییرکرده را به‌روز کرده است؛ کالاهای بدون تغییر دوباره پردازش نشدند.', 'info'));
            } else if (reviewed) {
                if (snapshot.review_note) {
                    card.appendChild(element('p', 'یادداشت بررسی: ' + snapshot.review_note, 'notice notice-info'));
                }
                appendActionForm(card, 'activate', snapshot, data.active_snapshot_id);
            } else {
                appendActionForm(card, 'review', snapshot, data.active_snapshot_id);
            }
            root.appendChild(card);
        });
    }

    async function load(successMessage) {
        if (!config || !config.apiUrl || !config.apiProxyUrl || !config.apiProxyNonce) {
            root.replaceChildren(notice('اتصال فهرست قیمت تنظیم نشده است.', 'error'));
            return;
        }
        root.replaceChildren(element('p', 'در حال بارگذاری قیمت‌ها…'));
        try {
            const data = await request('GET');
            if (!Array.isArray(data.snapshots) || !Object.prototype.hasOwnProperty.call(data, 'active_snapshot_id')) {
                throw new Error('invalid_response');
            }
            render(data, successMessage);
        } catch (error) {
            root.replaceChildren(notice(messageText(error.message), 'error'));
            addReloadButton(root);
        }
    }

    load();
})();
