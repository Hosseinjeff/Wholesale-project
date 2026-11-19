(() => {
    function mount(root, config) {
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
            reference_review_changed: 'وضعیت مرجع تغییر کرده است. آخرین وضعیت را بخوانید و دوباره تصمیم بگیرید.',
            workflow_busy_retry: 'دریافت مرجع در حال انجام است. کمی بعد وضعیت را دوباره بخوانید.',
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
            return new Intl.DateTimeFormat('fa-IR', { timeZone:'Asia/Tehran',dateStyle:'medium',timeStyle:'short' }).format(date);
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

    function addReloadButton(parent, label = 'بارگذاری دوباره') {
        const button = element('button', label, 'button');
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

    function appendRefreshForm(parent, refresh, label) {
        const form = element('form');
        const button = element('button', label, 'button button-primary');
        button.type = 'submit';
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm('فهرست رزوان دریافت می‌شود و تغییرات آن در مرجع تطبیق اعمال می‌شود. ادامه می‌دهید؟')) {
                return;
            }
            button.disabled = true;
            config.onRefresh?.(true);
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
                            : null;
                await load(success);
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
            } finally { config.onRefresh?.(false); }
        });
        parent.appendChild(form);
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

    function appendReferenceDecision(parent, review, activeId, usable) {
        if (!review.decision_token) return;
        const options = element('details',undefined,'molavi-reference-disclosure');
        options.append(element('summary','گزینه‌های تطبیق تا دریافت فهرست تازه'));
        options.append(element('p',usable
            ? 'یک انتخاب برای همهٔ موارد در انتظارِ همین فهرست ثبت می‌شود.'
            : 'تا دریافت فهرست، می‌توانید توقف تطبیق را ثبت کنید.'));
        const actions = element('div',undefined,'molavi-reference-actions');
        const choices = usable ? [['use_last_snapshot','ادامه با فهرست ذخیره‌شده']] : [];
        choices.push(['pause_review','توقف تطبیق']);
        for (const [choice,label] of choices) {
            const button = element('button',label,'button button-secondary');
            button.type = 'button';
            actions.append(button);
            button.addEventListener('click',async()=>{
                const controls = [...parent.querySelectorAll('button')];
                controls.forEach(control=>control.disabled=true);
                options.querySelector('.molavi-reference-error')?.remove();
                try {
                    const receipt=await request('POST',{action:'decide_reference_use',choice,
                        snapshot_id:activeId||null,decision_token:review.decision_token});
                    if (receipt.action!=='decide_reference_use'||receipt.choice!==choice
                        ||receipt.snapshot_id!==(activeId||null)||receipt.applied_count!==review.pending_count)
                        throw new Error('invalid_receipt');
                    await load(choice==='use_last_snapshot'
                        ? 'ادامهٔ تطبیق با فهرست ذخیره‌شده ثبت شد.' : 'توقف تطبیق ثبت شد.');
                } catch (error) {
                    if (error.message==='reference_review_changed') {
                        await load(messageText(error.message),'error');
                        return;
                    }
                    const message=notice(messageText(error.message),'error');
                    message.classList.add('molavi-reference-error');
                    options.append(message);
                    controls.forEach(control=>control.disabled=false);
                }
            });
        }
        options.append(actions);parent.append(options);
    }

    function render(data, message, kind = 'success') {
        root.replaceChildren();
        if (message) root.appendChild(notice(message,kind));
        const snapshots=data.snapshots.filter(item=>item && /^[a-f0-9]{64}$/.test(item.snapshot_id||''));
        const active=snapshots.find(item=>item.snapshot_id===data.active_snapshot_id);
        const verified=item=>item?.source_type==='woocommerce_store_api'&&item.coverage_verified===true;
        const usable=verified(active);
        const initial=!usable&&snapshots.find(item=>item.snapshot_id!==data.active_snapshot_id
            && verified(item)&&!item.automatic_update);
        const refresh=data.refresh_state||{};
        const review=data.reference_review||{};
        const running=refresh.state==='running'&&refresh.in_progress!==false;
        const current=element('section',undefined,'molavi-reference-summary');
        current.append(element('h2','مرجع رزوان'),
            element('p','مبنای تطبیق کالاهای پیام‌های منبع','description'));
        const statuses={
            awaiting_owner:'تطبیق منتظر تصمیم شماست',
            paused:'تطبیق متوقف شده است',
            using_saved:'تطبیق با فهرست ذخیره‌شده انجام می‌شود',
            refresh_due:'زمان تازه‌سازی فهرست رسیده است',
        };
        const state=statuses[review.state]
            || (usable?'فهرست برای تطبیق آماده است':initial?'نسخهٔ اول آمادهٔ بررسی است':'فهرست قابل استفاده آماده نیست');
        current.append(element('p',state,'molavi-reference-status'));
        const facts=element('dl',undefined,'molavi-reference-facts');
        const lastAttempt=refresh.state==='running'&&!running?'دریافت قبلی نیمه‌تمام ماند':refreshLabel(refresh.state);
        const values=[
            ['نسخهٔ فعال',active?formatCount(active.row_count,'کالا'):'هنوز فعال نشده'],
            ['آخرین دریافت موفق',active?formatDate(active.last_checked_at||active.extracted_at):'ثبت نشده'],
        ];
        if (refresh.state) values.push(['آخرین تلاش',lastAttempt+' · '+formatDate(refresh.last_attempt_at)]);
        for (const [label,value] of values) {
            const fact=element('div');fact.append(element('dt',label),element('dd',value));facts.append(fact);
        }
        current.append(facts);
        if (refresh.state==='failed') current.append(element('p',refreshErrorText(refresh.error_code),'description'));
        if (refresh.state==='updated') {
            const summary=refresh.change_summary||{};
            const changes=[['added_count','کالا اضافه شد'],['updated_count','کالا تغییر کرد'],['removed_count','کالا حذف شد']]
                .filter(([key])=>Number(summary[key])>0)
                .map(([key,label])=>formatCount(summary[key],label));
            if (changes.length) current.append(element('p',changes.join(' · '),'description'));
        }
        if (review.state==='paused') current.append(element('p','دریافت موفق، فهرست را برای تطبیق دوباره آماده می‌کند.','description'));
        if (running) addReloadButton(current,'بررسی وضعیت دریافت');
        else if (initial) appendActionForm(current,initial.reviewed_by?'activate':'review',initial,data.active_snapshot_id);
        else appendRefreshForm(current,refresh,refresh.state==='failed'||refresh.state==='running'
            ? 'دریافت دوباره از رزوان' : 'تازه‌سازی از رزوان');
        appendReferenceDecision(current,review,data.active_snapshot_id,usable);
        const preview=usable?active:(initial||active);
        if (preview) {
            const details=element('details',undefined,'molavi-reference-disclosure');
            details.append(element('summary',preview===active?'نمونه و فایل فهرست فعال':'نمونهٔ نسخهٔ آمادهٔ بررسی'));
            appendPreview(details,preview);current.append(details);
        }
        if (snapshots.length||review.failed_attempts) {
            const history=element('details',undefined,'molavi-reference-disclosure');
            history.append(element('summary','سابقهٔ دریافت'));
            if (review.failed_attempts) history.append(element('p',formatCount(review.failed_attempts,'تلاش ناموفق برای همین فهرست')));
            snapshots.forEach(item=>history.append(element('p',`${formatDate(item.last_checked_at||item.extracted_at)} · ${formatCount(item.row_count,'کالا')}${item===active?' · فعال':''}`)));
            current.append(history);
        }
        root.append(current);
    }

    async function load(message,kind='success') {
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
            render(data,message,kind);
            if (message&&kind==='success') config.onUpdated?.(message);
        } catch (error) {
            root.replaceChildren(notice(messageText(error.message), 'error'));
            addReloadButton(root);
        }
    }

    return load();
    }
    window.MolaviReferenceCatalog = {mount};
    const root = document.getElementById('molavi-reference-snapshots');
    if (root) mount(root, window.molaviReferenceSnapshots);
})();
