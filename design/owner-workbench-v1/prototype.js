(() => {
  const messages = [
    {name:'نمونه محصول یک', channel:'alpha', date:'2026-11-10', state:'pending', linked:true, eligible:true},
    {name:'نمونه محصول دو', channel:'beta', date:'2026-11-09', state:'pending', linked:false, eligible:true},
    {name:'نمونه محصول سه', channel:'alpha', date:'2026-11-07', state:'open', linked:true, eligible:true},
    {name:'نمونه محصول چهار', channel:'gamma', date:'2026-11-06', state:'ready', linked:true, eligible:true},
    {name:'نمونه پیام پنج', channel:'beta', date:'2026-11-05', state:'pending', linked:false, eligible:true},
    {name:'نمونه محصول شش', channel:'alpha', date:'2026-11-03', state:'ready', linked:true, eligible:true},
    {name:'نمونه پیام هفت', channel:'gamma', date:'2026-11-01', state:'open', linked:false, eligible:true},
    {name:'نمونه محصول هشت', channel:'beta', date:'2026-10-31', state:'final', linked:true, eligible:false},
  ];
  const channelNames = {alpha:'کانال نمونه الف', beta:'کانال نمونه ب', gamma:'کانال نمونه ج'};
  const scopeNames = {all:'همه ورودی‌های واجد شرایط', pending:'منتظر بررسی مالک', linked:'پیوندشده به محصول فروشگاه', recent:'قواعد فعلی · ۱۴ روز اخیر'};
  const panels = [...document.querySelectorAll('[data-panel]')];
  const tabs = [...document.querySelectorAll('[data-view]')];
  const stageButtons = [...document.querySelectorAll('[data-stage]')];
  const countNode = document.getElementById('selection-count');
  const channelNode = document.getElementById('selection-channels');
  const dateNode = document.getElementById('selection-dates');
  const scopeNode = document.getElementById('selection-scope');
  const listNode = document.getElementById('result-list');
  const captionNode = document.getElementById('result-caption');
  const fromNode = document.getElementById('date-from');
  const toNode = document.getElementById('date-to');
  const groupByNode = document.getElementById('group-by');
  const toast = document.querySelector('.toast');
  let activeStage = 'inbox';
  let toastTimer;
  const stageContent = {
    inbox: {
      tag:'نیازمند بررسی استخراج', tagClass:'tag-orange', source:'کانال نمونه الف · امروز ۱۰:۳۵',
      name:'نمونه محصول شماره یک', detail:'پیام اصلی برای بررسی قیمت و بسته‌بندی پیوند شده است.',
      facts:[['قیمت فروش','نیازمند تطبیق با پیام'],['بسته‌بندی','ثبت شده در پیشنهاد'],['گام بعدی','بررسی اطلاعات استخراج‌شده']],
      action:'بررسی پیشنهاد', actionMessage:'پیش‌نمونه است؛ هیچ تصمیمی ثبت نمی‌شود.',
      filters:['همه · ۲۱','تأیید استخراج · ۱۲','رسانه و OCR · ۴'],
      queues:[['تأیید استخراج','۱۲'],['خطای پیوند منبع','۲'],['بازبینی رسانه','۴']]
    },
    identity: {
      tag:'نیازمند تعیین هویت', tagClass:'tag-violet', source:'کانال نمونه ب · دیروز ۱۵:۲۰',
      name:'نمونه کالا برای تطبیق', detail:'یک پیشنهاد مشابه پیدا شده؛ تطبیق خودکار انجام نشده است.',
      facts:[['شناسه محصول','هنوز تعیین نشده'],['مرجع قیمتی','نیازمند انتخاب مالک'],['گام بعدی','انتخاب کالای دقیق']],
      action:'تطبیق محصول', actionMessage:'این انتخاب نمایشی است؛ مرجعی ثبت نشد.',
      filters:['همه · ۸','تطبیق کالا · ۶','مبنای قیمت مرجع · ۲'],
      queues:[['تطبیق کالا','۶'],['مبنای قیمت مرجع','۲'],['موردهای مبهم','۳']]
    },
    price: {
      tag:'نیازمند بررسی مدرک قیمت', tagClass:'tag-teal', source:'کانال نمونه ج · ۲ روز پیش',
      name:'نمونه پیشنهاد قیمت', detail:'زمان پیام به‌تنهایی تازگی قیمت را ثابت نمی‌کند.',
      facts:[['قیمت ثبت‌شده','در پیام منبع'],['آخرین بررسی','نیازمند تأیید منبع فعلی'],['گام بعدی','بازکردن منبع و بررسی قیمت']],
      action:'بررسی منبع قیمت', actionMessage:'پیش‌نمونه است؛ تأیید قیمت ثبت نشد.',
      filters:['همه · ۱۲','تازگی قیمت · ۹','تعارض همگام‌سازی · ۳'],
      queues:[['تازگی قیمت تأمین‌کننده','۹'],['تعارض اطلاعات','۳'],['منابع نامشخص','۲']]
    },
    publish: {
      tag:'توقف یا همگام‌سازی', tagClass:'tag-orange', source:'کاتالوگ فروشگاه · آخرین بررسی نمونه',
      name:'نمونه محصول در مرحله انتشار', detail:'یک مانع مشخص باید بررسی شود؛ اقدام روی همان مرحله متمرکز می‌ماند.',
      facts:[['وضعیت کاتالوگ','نیازمند بررسی'],['مانع فعلی','در صف همگام‌سازی'],['گام بعدی','رفع مانع پیش از انتشار']],
      action:'دیدن مانع انتشار', actionMessage:'پیش‌نمونه است؛ انتشار یا همگام‌سازی اجرا نشد.',
      filters:['همه · ۵','توقف انتشار · ۲','وضعیت کاتالوگ · ۳'],
      queues:[['توقف انتشار','۲'],['تعارض همگام‌سازی','۱'],['وضعیت کاتالوگ','۲']]
    }
  };

  function showToast(message) {
    toast.textContent = message;
    toast.hidden = false;
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => { toast.hidden = true; }, 3200);
  }

  function setView(view) {
    panels.forEach(panel => { panel.hidden = panel.dataset.panel !== view; });
    tabs.forEach(tab => {
      const selected = tab.dataset.view === view;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-pressed', String(selected));
    });
  }

  tabs.forEach(tab => tab.addEventListener('click', () => setView(tab.dataset.view)));
  document.querySelectorAll('[data-open-view]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault();
    setView(link.dataset.openView);
    document.getElementById('reextract').scrollIntoView({behavior:'smooth',block:'start'});
  }));
  function setStage(button) {
    activeStage = button.dataset.stage;
    stageButtons.forEach(item => {
      const selected = item === button;
      item.classList.toggle('is-active', selected);
      item.setAttribute('aria-pressed', String(selected));
    });
    const title = button.querySelector('.stage-title').textContent;
    document.querySelector('[data-panel="tasks"] .section-heading .eyebrow').textContent = `مرحله ${button.querySelector('.stage-index').textContent} · ${title}`;
    document.querySelector('[data-panel="tasks"] h2').textContent = `کارهای ${title}`;
    const content = stageContent[activeStage];
    const task = document.querySelector('.focus-card');
    const tag = task.querySelector('.stage-tag');
    tag.textContent = content.tag;
    tag.className = `stage-tag ${content.tagClass}`;
    task.querySelector('.task-source').textContent = content.source;
    task.querySelector('.task-main h3').textContent = content.name;
    task.querySelector('.task-main .muted').textContent = content.detail;
    task.querySelectorAll('.task-summary > div').forEach((fact,index) => {
      fact.querySelector('span').textContent = content.facts[index][0];
      fact.querySelector('strong').textContent = content.facts[index][1];
    });
    const action = task.querySelector('.task-actions .primary-button');
    action.textContent = content.action;
    action.dataset.message = content.actionMessage;
    const queueItems = [...document.querySelectorAll('.side-rail .work-link')];
    queueItems.forEach((item,index) => {
      item.querySelector('span').textContent = content.queues[index][0];
      item.querySelector('b').textContent = content.queues[index][1];
    });
    document.querySelectorAll('.queue-filters .filter-chip').forEach((item,index) => {
      item.textContent = content.filters[index];
      item.classList.toggle('is-selected',index===0);
    });
    document.querySelector('.secondary-details summary').textContent = `سایر کارهای مرتبط با این محصول`;
    showToast(`نمایش صف «${title}» در این پیش‌نمونه آماده است.`);
    setView('tasks');
  }
  stageButtons.forEach(button => button.addEventListener('click', () => setStage(button)));

  document.querySelectorAll('[data-message]').forEach(button => button.addEventListener('click', () => showToast(button.dataset.message)));
  document.querySelectorAll('[data-toggle]').forEach(button => button.addEventListener('click', () => {
    const target = document.getElementById(button.dataset.toggle);
    target.hidden = !target.hidden;
    button.textContent = target.hidden ? 'جزئیات منبع' : 'بستن جزئیات';
  }));

  function getSelection() {
    const scope = document.querySelector('input[name="scope"]:checked')?.value || 'all';
    const selectedChannels = [...document.querySelectorAll('.channel-fieldset input:checked')].map(input => input.value);
    const from = fromNode.value;
    const to = toNode.value;
    const today = '2026-11-11';
    const recentFloor = '2026-10-29';
    return messages.filter(item => {
      if (!item.eligible) return false;
      if (scope === 'pending' && !['pending','open'].includes(item.state)) return false;
      if (scope === 'linked' && !item.linked) return false;
      if (scope === 'recent' && item.date < recentFloor) return false;
      if (selectedChannels.length && !selectedChannels.includes(item.channel)) return false;
      if (from && item.date < from) return false;
      if (to && item.date > to) return false;
      if (item.date > today) return false;
      return true;
    });
  }

  function renderSelection() {
    const scope = document.querySelector('input[name="scope"]:checked')?.value || 'all';
    const channels = [...document.querySelectorAll('.channel-fieldset input:checked')].map(input => input.value);
    const selected = getSelection();
    countNode.textContent = new Intl.NumberFormat('fa-IR').format(selected.length);
    channelNode.textContent = channels.length ? channels.map(item => channelNames[item]).join('، ') : 'همه کانال‌ها';
    dateNode.textContent = fromNode.value || toNode.value
      ? `${fromNode.value || 'ابتدا'} تا ${toNode.value || 'امروز'}` : 'همه تاریخ‌ها';
    scopeNode.textContent = scopeNames[scope];
    captionNode.textContent = `${new Intl.NumberFormat('fa-IR').format(selected.length)} پیام · گروه‌بندی بر اساس ${groupByNode.value === 'date' ? 'روز پیام' : 'کانال منبع'}`;
    listNode.replaceChildren();
    const grouped = new Map();
    selected.slice().sort((a,b) => b.date.localeCompare(a.date)).slice(0,5).forEach(item => {
      const key = groupByNode.value === 'date' ? item.date : item.channel;
      if (!grouped.has(key)) grouped.set(key,[]);
      grouped.get(key).push(item);
    });
    grouped.forEach((items,key) => {
      const group = document.createElement('section'); group.className = 'result-group';
      const heading = document.createElement('h4');
      heading.className = 'result-group-title';
      heading.textContent = groupByNode.value === 'date'
        ? new Intl.DateTimeFormat('fa-IR').format(new Date(`${key}T12:00:00`))
        : channelNames[key];
      group.append(heading);
      items.forEach(item => {
        const row = document.createElement('div'); row.className = 'result-row';
        const name = document.createElement('span'); name.className = 'result-name'; name.textContent = item.name;
        const channel = document.createElement('span'); channel.className = 'result-meta'; channel.textContent = channelNames[item.channel];
        const date = document.createElement('span'); date.className = 'result-date'; date.textContent = new Intl.DateTimeFormat('fa-IR').format(new Date(`${item.date}T12:00:00`));
        row.append(name,channel,date); group.append(row);
      });
      listNode.append(group);
    });
    if (!selected.length) {
      const empty = document.createElement('p'); empty.className = 'muted'; empty.textContent = 'با این ترکیب، مورد واجد شرایطی پیدا نشد.';
      listNode.append(empty);
    }
  }

  document.querySelectorAll('input[name="scope"], .channel-fieldset input').forEach(input => input.addEventListener('change', renderSelection));
  fromNode.addEventListener('change', renderSelection);
  toNode.addEventListener('change', renderSelection);
  groupByNode.addEventListener('change', renderSelection);
  document.getElementById('clear-filters').addEventListener('click', () => {
    fromNode.value = ''; toNode.value = '';
    document.querySelectorAll('.channel-fieldset input').forEach(input => { input.checked = false; });
    renderSelection();
  });
  document.querySelectorAll('.filter-chip').forEach(button => button.addEventListener('click', () => {
    document.querySelectorAll('.filter-chip').forEach(item => item.classList.toggle('is-selected', item === button));
  }));
  renderSelection();
  window.ownerWorkbenchPrototype = {get activeStage() { return activeStage; }, getSelection};
})();
