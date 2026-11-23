(() => {
  'use strict';
  const byId = id => document.getElementById(id);
  const config = JSON.parse(byId('bc-config').textContent);
  const message = byId('bc-message');
  const text = (tag, value, parent) => {
    const node = document.createElement(tag);
    node.textContent = value;
    parent.append(node);
    return node;
  };
  const button = (label, parent, action) => {
    const node = text('button', label, parent);
    node.type = 'button';
    node.addEventListener('click', () => perform(action, node));
    return node;
  };
  const read = form => Object.fromEntries(new FormData(form));
  const offline = 'Нет соединения: обмен и приём контактов временно недоступны.';
  function report(value) { if (message) message.textContent = value; }
  async function perform(action, control) {
    if (control) control.disabled = true;
    try {
      report('');
      await action();
    } catch (error) {
      report(navigator.onLine && !(error instanceof TypeError) ? error.message : offline);
    } finally {
      if (control) control.disabled = false;
    }
  }
  async function result(response) {
    let body;
    try { body = await response.json(); } catch { throw new Error('Сервис временно недоступен. Попробуйте ещё раз.'); }
    if (!response.ok || !body.success) throw new Error(body.data?.message || 'Не удалось выполнить запрос.');
    return body.data;
  }
  function bind(form, action) {
    form.addEventListener('submit', event => {
      event.preventDefault();
      perform(() => action(form), event.submitter);
    });
  }
  function localDate(value) {
    return new Date(value.replace(' ', 'T').replace(/Z?$/, 'Z')).toLocaleString('ru-RU');
  }
  if (message) {
    window.addEventListener('offline', () => report(offline));
    window.addEventListener('online', () => report('Соединение восстановлено.'));
  }

  function guest() {
    const form = byId('bc-guest-form');
    if (!form) return;
    const open = byId('bc-open');
    const picker = byId('bc-picker');
    const submission = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
    open.addEventListener('click', () => {
      form.hidden = !form.hidden;
      open.setAttribute('aria-expanded', String(!form.hidden));
      if (!form.hidden) form.elements.full_name.focus();
    });
    const contacts = navigator.contacts;
    if (typeof contacts?.select === 'function' && typeof contacts.getProperties === 'function') {
      contacts.getProperties().then(supported => {
        const keys = ['name', 'tel', 'email'].filter(key => supported.includes(key));
        if (!keys.length) return;
        picker.hidden = false;
        picker.addEventListener('click', async () => {
          try {
            const [selected] = await contacts.select(keys, { multiple: false });
            if (!selected) { report('Выбор отменён. Можно заполнить форму вручную.'); return; }
            for (const [field, key] of [['full_name', 'name'], ['phone', 'tel'], ['email', 'email']]) {
              const values = selected[key] || [];
              const input = form.elements[field];
              if (values.length) input.value = values[0];
              form.querySelector('[data-options="' + field + '"]')?.remove();
              if (values.length < 2) continue;
              const label = text('label', 'Выберите значение из карточки', input.parentElement.parentElement);
              label.dataset.options = field;
              input.parentElement.after(label);
              const select = text('select', '', label);
              for (const value of values) text('option', value, select).value = value;
              select.addEventListener('change', () => { input.value = select.value; });
            }
            report('Проверьте контакт и при необходимости исправьте поля.');
          } catch {
            report('Не удалось выбрать контакт. Можно заполнить форму вручную.');
          }
        });
      }).catch(() => {});
    }
    bind(form, async () => {
      if (!form.elements.phone.value.trim() && !form.elements.email.value.trim()) {
        form.elements.phone.focus();
        throw new Error('Укажите телефон или электронную почту.');
      }
      const response = await fetch(location.pathname, {
        method: 'POST', cache: 'no-store', credentials: 'omit',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ...read(form), signature: config.signature, submission,
          pd: form.elements.pd.checked, marketing: form.elements.marketing.checked }),
      });
      const data = await result(response);
      form.reset();
      form.replaceChildren();
      form.hidden = true;
      open.hidden = true;
      report('Контакт передан. Согласие на рекламу ' + (data.marketing ? 'получено.' : 'не получено.'));
    });
  }

  async function api(op, payload = {}, file = false) {
    const response = await fetch(config.endpoint, {
      method: 'POST', cache: 'no-store', credentials: 'same-origin',
      body: new URLSearchParams({ action: 'cargo_business_cards', nonce: config.nonce, op, payload: JSON.stringify(payload) }),
    });
    if (file && response.ok && response.headers.get('Content-Type')?.startsWith('text/csv')) return response.blob();
    return result(response);
  }
  function saveFile(blob, name) {
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = name;
    document.body.append(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  }
  function qr(container, data) {
    container.replaceChildren();
    if (!data?.url) return;
    const image = text('img', '', container);
    image.className = 'bc-qr';
    image.alt = 'QR-код визитки';
    image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(data.qr);
    const link = text('a', data.url, container);
    link.href = data.url;
    link.rel = 'noreferrer';
    const actions = text('div', '', container);
    actions.className = 'bc-actions';
    if (navigator.clipboard) button('Скопировать ссылку', actions, async () => {
      await navigator.clipboard.writeText(data.url);
      report('Ссылка скопирована.');
    });
    if (navigator.share) button('Поделиться ссылкой', actions, () => nativeShare({ url: data.url }));
  }
  async function nativeShare(payload) {
    try { await navigator.share(payload); } catch (error) { if (error.name !== 'AbortError') throw error; }
  }

  function manager() {
    const filters = byId('bc-filters');
    const profileForm = byId('bc-profile-form');
    let session, timer, page = 0, tab = 'exchange';
    const detail = byId('bc-lead-detail');
    const labels = { full_name: 'Имя', phone: 'Телефон', email: 'Почта', company: 'Компания',
      position: 'Должность', comment: 'Комментарий клиента' };
    const sourceLabel = source => source === 'session' ? 'Личная встреча / QR' : 'Постоянная визитка';

    function profile(data) {
      byId('bc-owner').textContent = data.profile?.full_name || 'Сначала заполните раздел «Моя визитка».';
      for (const [key, value] of Object.entries(data.profile || {})) profileForm.elements[key].value = value;
      if (!data.profile) profileForm.elements.company.value = 'Каргономика';
      profileForm.elements.active.checked = data.active ?? true;
      byId('bc-permanent').hidden = !data.active;
      qr(byId('bc-permanent-qr'), data.active ? data : null);
      if (data.active) {
        const preview = byId('bc-permanent-qr');
        text('p', [data.profile.full_name, data.profile.position, data.profile.company, data.profile.phone,
          data.profile.email, data.profile.website, data.profile.description].filter(Boolean).join(' · '), preview);
      }
    }
    bind(profileForm, async form => {
      profile(await api('save', { ...read(form), active: form.elements.active.checked }));
      report('Визитка сохранена.');
    });
    function showSession(data) {
      if (data.id !== session?.id) return;
      const active = ['waiting', 'viewed'].includes(data.status);
      byId('bc-state').textContent = data.label;
      byId('bc-cancel').hidden = !active;
      byId('bc-start-button').textContent = active ? 'Обновить QR' : 'Следующий клиент';
      byId('bc-session-qr').hidden = !active;
      byId('bc-clock').hidden = !active;
      const summary = byId('bc-new-contact');
      summary.hidden = !data.lead;
      summary.textContent = data.lead ? [data.lead.full_name, data.lead.phone, data.lead.email,
        localDate(data.lead.created_at), 'Личная встреча / QR', 'Согласие на обработку ПД получено',
        Number(data.lead.marketing) ? 'Согласие на рекламу получено' : 'Действующего согласия на рекламу нет'].filter(Boolean).join(' · ') : '';
      const lead = byId('bc-session-lead');
      lead.hidden = !data.lead;
      lead.onclick = () => perform(async () => { await changeTab('leads'); await openLead(data.lead.id); }, lead);
      if (!active) { clearTimeout(timer); session = null; }
    }
    async function poll() {
      clearTimeout(timer);
      if (!session || document.hidden || tab !== 'exchange') return;
      const id = session.id;
      await perform(async () => {
        showSession(await api('poll', { id }));
      });
      if (session?.id === id && !document.hidden && tab === 'exchange') timer = setTimeout(poll, 4000);
    }
    function clock() {
      if (!session || document.hidden) return;
      const seconds = Math.max(0, Math.ceil((new Date(session.expires.replace(' ', 'T')) - Date.now()) / 1000));
      byId('bc-clock').textContent = 'До истечения: ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    }
    bind(byId('bc-start'), async form => {
      session = await api('start', read(form));
      byId('bc-session').hidden = false;
      qr(byId('bc-session-qr'), session);
      showSession(session);
      clock();
      clearTimeout(timer);
      timer = setTimeout(poll, 4000);
    });
    byId('bc-cancel').onclick = () => perform(async () => {
      if (session) showSession(await api('cancel', { id: session.id }));
    }, byId('bc-cancel'));
    document.addEventListener('visibilitychange', () => {
      clearTimeout(timer);
      if (!document.hidden) { clock(); poll(); }
    });
    setInterval(clock, 1000);

    async function openLead(id) {
      const { lead } = await api('lead', { id });
      detail.replaceChildren();
      detail.hidden = false;
      text('h3', (lead.full_name || 'Ограниченный контакт') + ' · №' + lead.id, detail);
      text('p', localDate(lead.created_at) + ' · ' + sourceLabel(lead.source_type) + (config.admin ? ' · Менеджер №' + lead.owner : ''), detail);
      for (const [key, label] of Object.entries(labels)) if (lead[key]) text('p', label + ': ' + lead[key], detail);
      if (lead.possible_duplicate) text('p', 'Возможный повтор по постоянной визитке. Согласия сохранены отдельно.', detail);
      for (const consent of lead.consents) {
        const item = text('details', '', detail);
        const state = consent.withdrawn_at ? 'отозвано ' + localDate(consent.withdrawn_at) : Number(consent.granted) ? 'получено' : 'не получено';
        text('summary', (consent.purpose === 'pd' ? 'Обработка ПД' : 'Реклама') + ': ' + state + ' · ' + consent.version, item);
        text('p', localDate(consent.agreed_at) + ' · SHA-256 ' + consent.document_hash, item);
        text('p', consent.document, item).className = 'bc-document';
        if (config.admin && Number(consent.granted) && !consent.withdrawn_at) button('Зарегистрировать отзыв', item, async () => {
          if (!confirm('Зарегистрировать подтверждённый отзыв согласия?')) return;
          await api('withdraw', { id, purpose: consent.purpose });
          await list();
          await openLead(id);
        });
      }
      if (!['restricted', 'erased'].includes(lead.status)) {
        const form = text('form', '', detail);
        const note = text('label', 'Заметка менеджера', form);
        const input = text('input', '', note);
        input.name = 'note'; input.maxLength = 600; input.value = lead.note;
        const status = text('select', '', text('label', 'Статус', form));
        status.name = 'status';
        text('option', 'Новый', status).value = 'new';
        text('option', 'Обработан', status).value = 'processed';
        status.value = lead.status;
        text('button', 'Сохранить изменения', form).type = 'submit';
        bind(form, async () => { await api('edit', { id, ...read(form) }); await list(); report('Контакт обновлён.'); });
        if (byId('bc-csv')) {
          const actions = text('div', '', detail);
          actions.className = 'bc-actions';
          button('Скачать .vcf', actions, async () => {
            const { vcard } = await api('vcard', { id });
            saveFile(new Blob([vcard], { type: 'text/vcard' }), 'contact.vcf');
          });
          if (navigator.canShare && navigator.share) {
            let file;
            const prepare = button('Подготовить .vcf для отправки', actions, async () => {
              const { vcard } = await api('vcard', { id });
              file = new File([vcard], 'contact.vcf', { type: 'text/vcard' });
              if (navigator.canShare({ files: [file] })) share.hidden = false;
              else report('Отправка файла не поддерживается. Можно скачать .vcf.');
            });
            const share = button('Поделиться .vcf', actions, () => nativeShare({ files: [file] }));
            share.hidden = true;
            prepare.before(share);
          }
        }
      }
      if (config.admin && lead.status !== 'erased') button('Обезличить контакт', detail, async () => {
        if (!confirm('Удалить поля контакта из рабочей БД? Это действие необратимо.')) return;
        await api('erase', { id });
        detail.replaceChildren();
        detail.hidden = true;
        await list();
        report('Контакт обезличен.');
      });
    }

    async function list() {
      const data = await api('leads', { ...read(filters), page });
      const target = byId('bc-list');
      target.replaceChildren();
      if (!data.items.length) text('li', 'Контактов по этому фильтру нет.', target);
      for (const lead of data.items) {
        const item = text('li', '', target);
        button(lead.full_name + ' · №' + lead.id, item, () => openLead(lead.id));
        text('p', [lead.company, lead.phone, lead.email, sourceLabel(lead.source_type), localDate(lead.created_at)].filter(Boolean).join(' · '), item);
      }
      byId('bc-prev').hidden = page === 0;
      byId('bc-next').hidden = !data.more;
    }
    bind(filters, async () => { page = 0; await list(); });
    const lookup = byId('bc-lead-lookup');
    if (lookup) bind(lookup, form => openLead(Number(form.elements.id.value)));
    byId('bc-prev').onclick = () => perform(async () => { page--; await list(); });
    byId('bc-next').onclick = () => perform(async () => { page++; await list(); });
    const csv = byId('bc-csv');
    if (csv) csv.onclick = () => perform(async () => saveFile(await api('csv', read(filters), true), 'contacts.csv'), csv);

    async function stats() {
      const { rows, cards } = await api('stats');
      const company = byId('bc-company-cards');
      if (company) {
        company.replaceChildren();
        for (const card of cards) {
          const item = text('li', card.name + ' · Менеджер №' + card.owner + ' · ', company);
          if (card.url) text('a', 'Открыть визитку', item).href = card.url;
          else item.append('Визитка отключена');
        }
      }
      const groups = new Map();
      for (const row of rows) {
        const key = JSON.stringify([row.owner, row.meeting, row.source_type]);
        const group = groups.get(key) || {};
        group[row.kind] = Number(row.total);
        groups.set(key, group);
      }
      const target = byId('bc-stats-list');
      target.replaceChildren();
      if (!groups.size) text('li', 'Событий пока нет.', target);
      for (const [key, group] of groups) {
        const [owner, meeting, source] = JSON.parse(key);
        const views = group.session_open || group.permanent_open || 0;
        const submitted = group.contact_submitted || 0;
        const rate = views ? (100 * submitted / views).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + '%' : '—';
        text('li', [sourceLabel(source), meeting, config.admin ? 'Менеджер №' + owner : '',
          'Сессии: ' + (group.session_created || 0), 'Открытия: ' + views, 'Передачи: ' + submitted, 'Передачи / открытия: ' + rate].filter(Boolean).join(' · '), target);
      }
    }
    async function changeTab(next) {
      tab = next;
      document.querySelectorAll('[data-tab]').forEach(node => {
        if (node.hasAttribute('aria-pressed')) node.setAttribute('aria-pressed', String(node.dataset.tab === next));
      });
      for (const key of ['exchange', 'profile', 'leads', 'stats']) byId('bc-' + key).hidden = key !== next;
      clearTimeout(timer);
      if (next === 'exchange') await poll();
      if (next === 'leads') await list();
      if (next === 'stats') await stats();
    }
    document.querySelectorAll('[data-tab]').forEach(node => node.addEventListener('click', () => perform(() => changeTab(node.dataset.tab))));
    perform(async () => profile(await api('profile')));
  }
  if (document.querySelector('.bc-root').dataset.mode === 'manager') manager();
  else guest();
  if (message && !navigator.onLine) report(offline);
})();
