(function () {
  'use strict';

  function notice(container, kind, message) {
    var existing = container.querySelector('.molavi-ajax-form-notice');
    if (existing) existing.remove();

    var item = document.createElement('div');
    item.className = 'notice notice-' + kind + ' molavi-ajax-form-notice';
    item.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    item.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
    item.tabIndex = -1;
    var text = document.createElement('p');
    text.textContent = message;
    item.appendChild(text);
    container.insertBefore(item, container.firstChild);
    return item;
  }

  function responseError(documentNode, response) {
    var messageNode = documentNode.querySelector(
      '#error-page .wp-die-message, .wp-die-message, #error-page p, .wrap .notice-error p'
    );
    var message = messageNode ? messageNode.textContent.trim() : '';
    if (message) return message;
    if (response.status === 403) return 'اجازه انجام این تغییر وجود ندارد. صفحه را تازه کنید و دوباره بررسی کنید.';
    if (response.status === 404) return 'صفحه مقصد پیدا نشد. فهرست را تازه کنید و دوباره تلاش کنید.';
    return 'ذخیره انجام نشد. اطلاعات فرم را بررسی کنید و دوباره تلاش کنید.';
  }

  function isSameOwnerPage(url, page) {
    return url.origin === window.location.origin
      && url.pathname === new URL(window.location.href).pathname
      && url.searchParams.get('page') === page;
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.molaviAjaxBusy === '1') return;

    var page = new URL(window.location.href).searchParams.get('page') || '';
    var action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    if (!page.startsWith('molavi-') || action.origin !== window.location.origin
      || !/\/admin-post\.php$/.test(action.pathname) || form.method.toLowerCase() !== 'post'
      || (form.target && form.target !== '_self')) return;

    var container = form.closest('.wrap');
    if (!container) return;

    event.preventDefault();
    var submitter = event.submitter;
    var data;
    try {
      data = submitter ? new FormData(form, submitter) : new FormData(form);
    } catch (error) {
      data = new FormData(form);
    }
    if (submitter && submitter.name
      && !data.getAll(submitter.name).some(function (value) { return value === submitter.value; })) {
      data.append(submitter.name, submitter.value);
    }

    form.dataset.molaviAjaxBusy = '1';
    form.setAttribute('aria-busy', 'true');
    var buttons = Array.prototype.slice.call(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'));
    var priorStates = buttons.map(function (button) { return button.disabled; });
    buttons.forEach(function (button) { button.disabled = true; });
    notice(container, 'info', 'در حال ذخیره…');

    fetch(action.href, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'follow',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
      body: data
    }).then(function (response) {
      return response.text().then(function (html) {
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var finalUrl = new URL(response.url || action.href, window.location.href);
        var replacement = parsed.querySelector('.wrap');
        if (!response.ok || !isSameOwnerPage(finalUrl, page) || !replacement) {
          throw new Error(responseError(parsed, response));
        }

        var current = document.querySelector('.wrap');
        if (!current) throw new Error('صفحه برای نمایش نتیجه ذخیره‌سازی آماده نیست.');
        var scrollX = window.scrollX;
        var scrollY = window.scrollY;
        current.replaceWith(replacement);
        if (finalUrl.href !== window.location.href) {
          window.history.replaceState({}, '', finalUrl.href);
        }
        var updated = document.querySelector('.wrap');
        var savedNotice = notice(updated, 'success', 'ذخیره شد؛ فهرست به‌روزرسانی شد.');
        savedNotice.focus({ preventScroll: true });
        window.scrollTo({ left: scrollX, top: scrollY, behavior: 'instant' });
      });
    }).catch(function (error) {
      var liveContainer = document.querySelector('.wrap') || container;
      notice(liveContainer, 'error', error && error.message
        ? error.message
        : 'نتیجه ذخیره‌سازی تأیید نشد؛ وضعیت را در فهرست بررسی کنید.');
      if (form.isConnected) {
        delete form.dataset.molaviAjaxBusy;
        form.removeAttribute('aria-busy');
        buttons.forEach(function (button, index) { button.disabled = priorStates[index]; });
      }
    });
  });
}());
