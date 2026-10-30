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
            active_snapshot_changed: 'نسخه فعال تغییر کرده است. فهرست را دوباره بررسی کنید.',
            snapshot_changed_or_unreviewed: 'وضعیت نسخه تغییر کرده است. فهرست را دوباره بررسی کنید.',
            snapshot_not_found: 'این نسخه دیگر در دسترس نیست.',
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
            staged: 'نسخه تازه آماده بررسی است',
            failed: 'دریافت PDF ناموفق بود',
            running: 'دریافت و بررسی PDF در حال انجام است'
        })[state] || 'وضعیت دریافت مشخص نیست';
    }

    async function request(method, payload) {
        const options = {
            method,
            mode: 'cors',
            cache: 'no-store',
            headers: { Authorization: 'Bearer ' + config.token }
        };
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
        const button = element('button', 'دریافت دوباره PDF', 'button');
        button.type = 'submit';
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (!window.confirm(
                'PDF رزوان دوباره دریافت و بررسی می‌شود. نتیجه فقط برای بررسی آماده می‌شود و خودکار فعال نخواهد شد. ادامه می‌دهید؟')) {
                return;
            }
            button.disabled = true;
            try {
                const receipt = await request('POST', {
                    action: 'refresh',
                    expected_last_attempt_at: refresh.last_attempt_at || null
                });
                if (receipt.action !== 'refresh' || !['staged', 'failed'].includes(receipt.state)
                    || typeof receipt.last_attempt_at !== 'string') {
                    throw new Error('invalid_receipt');
                }
                await load(receipt.state === 'staged'
                    ? 'نسخه تازه آماده بررسی است.'
                    : 'تلاش انجام شد، اما PDF دریافت نشد. بعداً دوباره تلاش کنید.');
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
        card.appendChild(element('h2', 'دریافت روزانه PDF رزوان'));
        card.appendChild(element('p', 'وضعیت: ' + refreshLabel(refresh.state)));
        if (refresh.last_attempt_at) {
            card.appendChild(element('p', 'آخرین تلاش: ' + formatDate(refresh.last_attempt_at), 'description'));
        }
        if (refresh.state === 'failed') {
            card.appendChild(element('p', 'می‌توانید دریافت فایل را دوباره امتحان کنید.', 'description'));
            appendRefreshForm(card, refresh);
        }
        parent.appendChild(card);
    }

    function appendPreview(card, snapshot) {
        const preview = Array.isArray(snapshot.preview) ? snapshot.preview.slice(0, 8) : [];
        if (!preview.length) return;

        card.appendChild(element('h3', 'نمونه کالاها'));
        const table = element('table', undefined, 'widefat striped');
        const head = element('thead');
        const headerRow = element('tr');
        ['کالا', 'قیمت بسته', 'قیمت هر عدد', 'قیمت فروش تک‌عدد', 'تعداد در بسته'].forEach((label) => {
            headerRow.appendChild(element('th', label, 'column-primary'));
        });
        head.appendChild(headerRow);
        table.appendChild(head);

        const body = element('tbody');
        preview.forEach((item) => {
            if (!item || typeof item !== 'object') return;
            const row = element('tr');
            row.appendChild(element('td', item.product_name || 'بدون نام'));
            row.appendChild(element('td', formatAmount(item.package_price)));
            row.appendChild(element('td', formatAmount(item.unit_price)));
            row.appendChild(element('td', formatAmount(item.consumer_unit_price)));
            row.appendChild(element('td', formatCount(item.package_count, item.unit_label)));
            body.appendChild(row);
        });
        table.appendChild(body);
        card.appendChild(table);
        card.appendChild(element('p', 'این نمونه شامل ' + new Intl.NumberFormat('fa-IR').format(preview.length)
            + ' کالا از ' + new Intl.NumberFormat('fa-IR').format(Number(snapshot.row_count) || preview.length)
            + ' کالای نسخه است.', 'description'));
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
            const status = active ? 'فعال و در حال استفاده'
                : (reviewed ? 'بررسی شده؛ آماده فعال‌سازی' : 'نیاز به بررسی');

            card.appendChild(element('h2', 'نسخه قیمت‌ها'));
            card.appendChild(element('p', 'وضعیت: ' + status, 'description'));
            card.appendChild(element('p', 'زمان استخراج: ' + formatDate(snapshot.extracted_at)
                + ' · تعداد کالا: ' + new Intl.NumberFormat('fa-IR').format(Number(snapshot.row_count) || 0)));
            appendPreview(card, snapshot);

            if (reviewed) {
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
        if (!config || !config.apiUrl || !config.token) {
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
