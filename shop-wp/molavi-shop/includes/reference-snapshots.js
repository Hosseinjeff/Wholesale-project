(() => {
    function mount(root, config) {
    if (!root) return;
    let revision = null;
    let loading = false;
    let writing = false;

    function editing() {
        return root.querySelector('.molavi-reference-input[open]');
    }

    root.addEventListener('molavi-reference-refresh', event => {
        if (event.detail && event.detail !== revision && !writing && !editing()) {
            load(null, 'success', true);
        }
    });

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
        const button = element('button', action === 'review' ? 'ثبت بررسی' : 'فعال‌سازی مرجع', 'button button-primary');
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
            noteField.placeholder = 'نتیجهٔ بررسی کالاها و قیمت‌ها';
            label.appendChild(noteField);
            form.appendChild(label);
        }
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (writing) return;
            if (action === 'activate' && !window.confirm(
                'با این کار، قیمت‌های این نسخه در فهرست مرجع ثبت و برای استفاده فعال می‌شوند. ادامه می‌دهید؟')) {
                return;
            }
            writing = true;
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
                    ? 'بررسی ثبت شد.' : 'فهرست مرجع فعال شد.');
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
            } finally { writing = false; }
        });
        parent.appendChild(form);
    }

    function appendRefreshForm(parent, refresh, label, primary = true) {
        const form = element('form');
        const button = element('button', label, primary ? 'button button-primary' : 'button');
        button.type = 'submit';
        form.appendChild(button);
        const delay = (Number(refresh.retry_at) || 0)*1000 - Date.now();
        if (delay > 0 && delay <= 60000) {
            button.disabled = true;
            button.textContent = 'دریافت دوباره پس از ' + formatDate(Number(refresh.retry_at)*1000);
            window.setTimeout(() => {
                button.disabled = false;
                button.textContent = label;
            }, delay);
        }
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (button.disabled || writing || !window.confirm('فهرست رزوان بررسی می‌شود و تغییرات تأییدشده در مرجع اعمال می‌شود. ادامه می‌دهید؟')) return;
            writing = true;
            button.disabled = true;
            button.textContent = 'در حال دریافت فهرست…';
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
                await load(receipt.state === 'failed' ? null : refreshLabel(receipt.state));
            } catch (error) {
                if (['refresh_state_changed', 'workflow_busy_retry'].includes(error.message)) {
                    await load(messageText(error.message),'error');
                    return;
                }
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
                button.textContent = label;
            } finally {
                writing = false;
                config.onRefresh?.(false);
            }
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
        const scroll = element('div', undefined, 'molavi-reference-table');
        scroll.tabIndex = 0;
        scroll.setAttribute('role', 'region');
        scroll.setAttribute('aria-label', 'نمونهٔ کالاهای فهرست');
        scroll.append(table);
        card.appendChild(scroll);
        card.appendChild(element('p', 'این نمونه ' + new Intl.NumberFormat('fa-IR').format(preview.length)
            + ' ردیف از ' + new Intl.NumberFormat('fa-IR').format(Number(snapshot.row_count) || preview.length)
            + ' محصول را نشان می‌دهد.', 'description'));
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

    function disclosure(label, className = '') {
        const details = element('details', undefined, 'molavi-reference-disclosure ' + className);
        details.append(element('summary',label));
        return details;
    }

    function appendReferenceDecision(parent, review, activeId) {
        const options = disclosure('تصمیم دربارهٔ فهرست ذخیره‌شده', 'molavi-reference-input');
        const actions = element('div',undefined,'molavi-reference-actions');
        options.append(element('p','این تصمیم برای همهٔ موارد منتظر همین فهرست اعمال می‌شود.','description'), actions);
        for (const [choice,label] of [
            ['use_last_snapshot','ادامه با فهرست ذخیره‌شده'],
            ['pause_review','توقف تطبیق تا دریافت فهرست تازه']
        ]) {
            const button = element('button',label,'button');
            button.type = 'button';
            actions.append(button);
            button.addEventListener('click',async()=>{
                if (writing) return;
                writing = true;
                for (const control of actions.children) control.disabled = true;
                options.querySelector('.molavi-reference-error')?.remove();
                try {
                    const receipt=await request('POST',{action:'decide_reference_use',choice,
                        snapshot_id:activeId,decision_token:review.decision_token});
                    if (receipt.action!=='decide_reference_use'||receipt.choice!==choice
                        ||receipt.snapshot_id!==activeId||receipt.applied_count!==review.pending_count)
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
                    for (const control of actions.children) control.disabled = false;
                } finally { writing = false; }
            });
        }
        parent.append(options);
    }

    function render(data, message, kind = 'success') {
        root.replaceChildren();
        if (message) root.append(notice(message,kind));
        const snapshots = data.snapshots.filter(item=>item && /^[a-f0-9]{64}$/.test(item.snapshot_id||''));
        const activeId = data.active_snapshot_id;
        const active = snapshots.find(item=>item.snapshot_id===activeId);
        const verified = item=>item?.source_type==='woocommerce_store_api'&&item.coverage_verified===true;
        const usable = verified(active);
        const initial = !usable && snapshots.find(item=>item.snapshot_id!==activeId && verified(item) && !item.automatic_update);
        const refresh = data.refresh_state || {};
        const review = data.reference_review || {};
        const running = refresh.state==='running' && refresh.in_progress!==false;
        const interrupted = refresh.state==='running' && !running;
        const failed = refresh.state==='failed' || interrupted;
        const updating = refresh.state==='staged'
            && snapshots.some(item=>item.snapshot_id===refresh.snapshot_id && item.automatic_update);
        const awaiting = review.state==='awaiting_owner';
        const paused = review.state==='paused';
        const usingSaved = usable && review.state==='using_saved';

        const current = element('section',undefined,'molavi-reference-summary');
        current.setAttribute('aria-label','مرجع رزوان');
        const header = element('header');
        header.append(element('span','رزوان پخش','molavi-reference-source'));
        const status = element('h2',undefined,'molavi-reference-status');
        status.setAttribute('aria-live','polite');
        header.append(status);
        current.append(header);
        const next = element('div',undefined,'molavi-reference-next');
        let description;
        let manualRefresh = false;
        if (running || updating) {
            status.textContent = running ? 'دریافت فهرست در جریان است' : 'فهرست تازه در انتظار ثبت است';
            if (!usable || awaiting || paused) description = 'تطبیق منتظر فهرست تازه است.';
            if (!config.liveActivity) addReloadButton(next,'بررسی وضعیت دریافت');
        } else if (initial) {
            if (initial.reviewed_by) {
                status.textContent = 'فهرست آمادهٔ فعال‌سازی است';
                description = 'با فعال‌سازی، قیمت‌ها در مرجع تطبیق ثبت می‌شوند.';
                appendActionForm(next,'activate',initial,activeId);
            } else {
                status.textContent = 'نسخهٔ نخست آمادهٔ بررسی است';
                const reviewStep = disclosure('بررسی نسخهٔ نخست','molavi-reference-input molavi-reference-review');
                appendPreview(reviewStep,initial);
                appendActionForm(reviewStep,'review',initial,activeId);
                next.append(reviewStep);
            }
        } else if (!usable || paused || awaiting || review.state==='refresh_due' || (failed && !usingSaved)) {
            status.textContent = !usable ? 'فهرست قابل استفاده آماده نیست'
                : paused ? 'تطبیق متوقف است'
                : failed ? 'دریافت فهرست کامل نشد' : 'فهرست به تازه‌سازی نیاز دارد';
            if (!usable) description = 'برای شروع تطبیق، نخستین فهرست رزوان را دریافت و بررسی کنید.';
            else if (awaiting) description = 'تطبیق منتظر فهرست تازه است. در صورت نیاز می‌توانید از فهرست ذخیره‌شده استفاده کنید.';
            else if (paused || failed) description = 'دریافت موفق، فهرست را برای تطبیق آماده می‌کند.';
            appendRefreshForm(next,refresh,failed ? 'دریافت دوباره از رزوان' : 'دریافت فهرست از رزوان');
        } else {
            status.textContent = usingSaved ? 'تطبیق با فهرست ذخیره‌شده انجام می‌شود' : 'فهرست برای تطبیق آماده است';
            manualRefresh = true;
        }
        if (description) header.append(element('p',description,'description'));
        if (usable && awaiting && review.decision_token) appendReferenceDecision(next,review,activeId);
        if (next.childElementCount) current.append(next);

        const facts = element('dl',undefined,'molavi-reference-facts');
        const currentList = usable ? active : initial;
        if (currentList) {
            for (const [label,value] of [
                [usable ? 'فهرست فعال' : 'نسخهٔ آماده', formatCount(currentList.row_count,'کالا')],
                [usable ? 'آخرین بررسی موفق' : 'زمان دریافت', formatDate(currentList.last_checked_at||currentList.extracted_at)]
            ]) {
                const fact = element('div');
                fact.append(element('dt',label),element('dd',value));
                facts.append(fact);
            }
            current.append(facts);
        }
        if (refresh.state==='updated') {
            const summary = refresh.change_summary || {};
            const changes = [['added_count','اضافه شد'],['updated_count','تغییر کرد'],['removed_count','حذف شد']]
                .filter(([key])=>Number(summary[key])>0)
                .map(([key,label])=>formatCount(summary[key],label));
            if (changes.length) current.append(element('p','آخرین تغییرات · '+changes.join(' · '),'description'));
        }

        if (refresh.state || snapshots.length || manualRefresh) {
            const details = disclosure('داده‌ها و سابقه','molavi-reference-history');
            if (refresh.state) {
                const label = interrupted ? 'دریافت قبلی نیمه‌تمام ماند' : refreshLabel(refresh.state);
                details.append(element('p',label+' · '+formatDate(refresh.last_attempt_at)));
                if (refresh.state==='failed') details.append(element('p',refreshErrorText(refresh.error_code),'description'));
            }
            if (review.failed_attempts) {
                details.append(element('p',formatCount(review.failed_attempts,'تلاش ناموفق برای همین فهرست'),'description'));
            }
            const preview = active || (initial?.reviewed_by ? initial : null);
            if (preview) appendPreview(details,preview);
            const past = snapshots.filter(item=>item!==active && item!==initial);
            if (past.length) {
                details.append(element('h3','نسخه‌های قبلی'));
                const history = element('ul');
                for (const item of past) {
                    history.append(element('li',formatDate(item.extracted_at)+' · '+formatCount(item.row_count,'کالا')));
                }
                details.append(history);
            }
            if (manualRefresh) appendRefreshForm(details,refresh,'تازه‌سازی دستی از رزوان',false);
            current.append(details);
        }
        root.append(current);
    }

    async function load(message,kind='success',quiet=false) {
        if (loading && quiet) return;
        if (!config || !config.apiUrl || !config.apiProxyUrl || !config.apiProxyNonce) {
            root.replaceChildren(notice('اتصال فهرست قیمت تنظیم نشده است.', 'error'));
            return;
        }
        const requestId = Symbol();
        loading = requestId;
        if (!quiet) root.replaceChildren(element('p','در حال بارگذاری فهرست…'));
        try {
            const data = await request('GET');
            if (loading!==requestId || (quiet && (writing || editing()))) return;
            if (!Array.isArray(data.snapshots) || !Object.prototype.hasOwnProperty.call(data, 'active_snapshot_id')) {
                throw new Error('invalid_response');
            }
            const hadHistoryOpen = quiet && root.querySelector('.molavi-reference-history[open]');
            const restoreFocus = hadHistoryOpen && hadHistoryOpen.contains(document.activeElement);
            revision = data.refresh_state?.revision ?? null;
            render(data,message,kind);
            const history = hadHistoryOpen && root.querySelector('.molavi-reference-history');
            if (history) {
                history.open = true;
                if (restoreFocus) history.firstElementChild.focus({preventScroll:true});
            }
            if (message && kind==='success') config.onUpdated?.(message);
        } catch (error) {
            if (!quiet && loading===requestId) {
                root.replaceChildren(notice(messageText(error.message), 'error'));
                addReloadButton(root);
            }
        } finally {
            if (loading===requestId) loading = false;
        }
    }

    return load();
    }
    window.MolaviReferenceCatalog = {mount};
    const root = document.getElementById('molavi-reference-snapshots');
    if (root) mount(root, window.molaviReferenceSnapshots);
})();
