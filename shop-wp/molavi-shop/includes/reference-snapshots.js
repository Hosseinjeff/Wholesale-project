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
            unauthorized: 'نشست مالک منقضی شده است. صفحه را تازه کنید.',
            owner_required: 'این حساب اجازه بررسی قیمت مرجع را ندارد.',
            active_snapshot_changed: 'نسخه فعال تغییر کرده است. فهرست را دوباره بررسی کنید.',
            snapshot_changed_or_unreviewed: 'این نسخه هنوز بررسی نشده یا وضعیت آن تغییر کرده است.',
            snapshot_not_found: 'نسخه مورد نظر دیگر در دسترس نیست.',
            sheets_unavailable: 'اتصال به ReferencePrices در دسترس نیست.',
            refresh_state_changed: 'وضعیت تازه‌سازی تغییر کرده است. فهرست را دوباره بررسی کنید.',
            refresh_cooldown: 'برای تلاش دوباره هنوز باید کمی صبر کنید.',
            refresh_not_retryable: 'این تلاش در حال حاضر قابل تکرار نیست.',
            review_note_required: 'یادداشت بررسی را وارد کنید.',
            invalid_body: 'اطلاعات درخواست معتبر نیست.',
            invalid_fields: 'اطلاعات درخواست معتبر نیست.'
        };
        return messages[error] || 'ارتباط امن با سرور برقرار نشد. صفحه را تازه کنید.';
    }

    function notice(text, kind) {
        return element('p', text, kind === 'error' ? 'notice notice-error' : 'notice notice-info');
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

    function appendActionForm(parent, action, snapshot, activeId) {
        const form = element('form');
        const button = element('button', action === 'review'
            ? 'ثبت بررسی مالک'
            : (snapshot.snapshot_id === activeId
                ? 'تلاش دوباره برای همگام‌سازی ReferencePrices'
                : 'فعال‌سازی و همگام‌سازی ReferencePrices'),
        action === 'activate' ? 'button button-primary' : 'button');
        button.type = 'submit';
        form.appendChild(button);
        let noteField = null;
        if (action === 'review') {
            const label = element('label', 'یادداشت بررسی پوشش و قیمت‌ها');
            noteField = element('textarea');
            noteField.name = 'note';
            noteField.required = true;
            noteField.maxLength = 1000;
            label.appendChild(noteField);
            form.insertBefore(label, button);
        }
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
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
                    ? 'بررسی مالک ثبت شد.' : 'رسید همگام‌سازی ReferencePrices تأیید شد.');
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
            }
        });
        parent.appendChild(form);
    }

    function appendRefreshForm(parent, refresh) {
        const form = element('form');
        const button = element('button', 'تلاش دوباره برای دریافت و بررسی PDF', 'button');
        button.type = 'submit';
        form.appendChild(button);
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
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
                await load('تلاش تازه‌سازی ثبت شد.');
            } catch (error) {
                root.prepend(notice(messageText(error.message), 'error'));
                button.disabled = false;
            }
        });
        parent.appendChild(form);
    }

    function render(data, successMessage) {
        root.replaceChildren();
        if (successMessage) root.appendChild(notice(successMessage, 'success'));
        const refresh = data.refresh_state;
        if (refresh && typeof refresh === 'object') {
            const lastAttempt = refresh.last_attempt_at || 'هنوز اجرا نشده';
            const errorCode = refresh.error_code ? ' · کد خطا: ' + refresh.error_code : '';
            root.appendChild(element('p', 'وضعیت آخرین تلاش: '
                + (refresh.state || 'نامشخص') + ' · ' + lastAttempt + errorCode));
            if (refresh.state === 'failed') appendRefreshForm(root, refresh);
        }
        const snapshots = Array.isArray(data.snapshots) ? data.snapshots : [];
        if (snapshots.length === 0) {
            root.appendChild(element('p', 'نسخه استخراج‌شده‌ای در انتظار بررسی نیست.'));
            return;
        }
        snapshots.forEach((snapshot) => {
            if (!snapshot || typeof snapshot.snapshot_id !== 'string'
                || !/^[a-f0-9]{64}$/.test(snapshot.snapshot_id)) return;
            const card = element('div', undefined, 'card');
            const id = snapshot.snapshot_id;
            const reviewed = Boolean(snapshot.reviewed_by);
            const active = data.active_snapshot_id === id || Boolean(snapshot.active);
            card.appendChild(element('h2', 'نسخه ' + id.slice(0, 12)));
            card.appendChild(element('p', 'استخراج: ' + (snapshot.extracted_at || '')
                + ' · تعداد ردیف: ' + (snapshot.row_count || '')
                + ' · مدل: ' + (snapshot.model || '')));
            card.appendChild(element('p', 'هش PDF: ' + String(snapshot.pdf_sha256 || '').slice(0, 16)
                + ' · وضعیت: ' + (active ? 'فعال' : (reviewed ? 'بررسی‌شده' : 'در انتظار بررسی'))));
            const preview = Array.isArray(snapshot.preview) ? snapshot.preview.slice(0, 8) : [];
            if (preview.length) {
                card.appendChild(element('h3', 'نمونه ردیف‌های استخراج‌شده'));
                const list = element('ul');
                preview.forEach((item) => {
                    if (!item || typeof item !== 'object') return;
                    list.appendChild(element('li', String(item.product_name || '') + ' · '
                        + 'قیمت بسته: ' + String(item.package_price || '') + ' · '
                        + 'قیمت واحد: ' + String(item.unit_price || '') + ' · '
                        + 'تعداد بسته: ' + String(item.package_count || '') + ' '
                        + String(item.unit_label || '') + ' · کد: ' + String(item.product_code || '')
                        + ' · ردیف PDF: ' + String(item.pdf_row_number || '')));
                });
                card.appendChild(list);
            }
            if (reviewed) {
                card.appendChild(element('p', 'بررسی مالک: ' + String(snapshot.reviewed_by)
                    + ' · ' + String(snapshot.review_note || '')));
                appendActionForm(card, 'activate', snapshot, data.active_snapshot_id);
            } else {
                appendActionForm(card, 'review', snapshot, data.active_snapshot_id);
            }
            root.appendChild(card);
        });
    }

    async function load(successMessage) {
        if (!config || !config.apiUrl || !config.token) {
            root.replaceChildren(notice('اتصال امن فهرست مرجع تنظیم نشده است.', 'error'));
            return;
        }
        root.replaceChildren(element('p', 'در حال دریافت نسخه‌های قیمت مرجع…'));
        try {
            const data = await request('GET');
            if (!Array.isArray(data.snapshots) || !Object.prototype.hasOwnProperty.call(data, 'active_snapshot_id')) {
                throw new Error('invalid_response');
            }
            render(data, successMessage);
        } catch (error) {
            root.replaceChildren(notice(messageText(error.message), 'error'));
        }
    }

    load();
})();
