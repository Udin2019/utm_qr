(() => {
  'use strict';
  const root = document.getElementById('cargo-app');
  if (!root) return;
  const $ = id => root.querySelector('#' + id);
  const config = JSON.parse($('cargo-config').textContent);
  const form = $('generator-form');
  const fieldArea = $('detail-fields');
  const campaignForm = $('campaign-form');
  const state = { data: null, mode: 'offline', carrier: 'exhibition', details: {}, online: {}, key: newKey(), submitted: false, current: null, previewSeq: 0, registrySeq: 0, previewTimer: 0, page: 1, exports: {}, saving: false };
  function newKey() { return [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join(''); }
  function node(tag, text, cls) { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (cls) el.className = cls; return el; }
  function button(text, callback, cls = 'button outline') { const el = node('button', text, cls); el.type = 'button'; el.addEventListener('click', callback); return el; }
  function error(el, message = '') { el.textContent = message; el.hidden = !message; }
  function notify(message) { const el = $('toast'); el.textContent = message; el.hidden = false; clearTimeout(state.toastTimer); state.toastTimer = setTimeout(() => { el.hidden = true; }, 3500); }
  async function api(op, payload = {}) {
    const body = new URLSearchParams({ action: config.isGuest ? 'cargo_public_generator' : 'cargo_workspace', op, nonce: config.nonce, payload: JSON.stringify(payload) });
    const response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', body });
    let json;
    try { json = await response.json(); } catch { throw new Error('Сервер вернул неожиданный ответ. Обновите страницу и проверьте авторизацию.'); }
    if (!response.ok || !json.success) throw new Error(json.data?.message || 'Операция недоступна. Обновите страницу и повторите.');
    return json.data;
  }
  function guarded(fn) { return (...args) => { if (args[0]?.type === 'submit') args[0].preventDefault(); return Promise.resolve().then(() => fn(...args)).catch(e => error($('workspace-error'), e.message)); }; }
  async function copy(text) {
    try { await navigator.clipboard.writeText(text); notify('Ссылка скопирована'); }
    catch { const area = node('textarea'); area.value = text; root.append(area); area.select(); const ok = document.execCommand('copy'); area.remove(); notify(ok ? 'Ссылка скопирована' : 'Выделите ссылку и скопируйте вручную'); }
  }
  function choices(select, rows, label, empty = null) {
    const previous = select.value; select.replaceChildren();
    if (empty !== null) select.add(new Option(empty, ''));
    rows.forEach(row => select.add(new Option(label(row), String(row.id))));
    if ([...select.options].some(o => o.value === previous)) select.value = previous;
  }
  function selectUnit() {
    const unit = $('business').value;
    choices($('campaign'), state.data.campaigns.filter(c => String(c.unit_id) === unit), c => `${c.name} · ${c.year}`, 'Выберите кампанию');
    const brands = state.data.brands.filter(b => b.unit_id === null || String(b.unit_id) === unit);
    choices($('profile'), brands, b => config.isGuest ? b.name : `${b.name} · v${b.version}${b.unit_id === null ? ' · общий' : ''}`);
    const own = brands.find(b => String(b.unit_id) === unit);
    if (own) $('profile').value = String(own.id);
  }
  function fillCatalogs() {
    choices($('business'), state.data.units, u => u.name);
    selectUnit();
    choices(campaignForm.elements.unit_id, state.data.units, u => u.name);
    choices($('filter-unit'), state.data.units, u => u.name, 'Все юниты');
    choices($('filter-campaign'), state.data.campaigns, c => c.name, 'Все кампании');
  }
  function field(name, label, type = 'text', required = false, value = '', full = false) {
    const wrapper = node('label', undefined, full ? 'full' : '');
    const title = node('span', label + (required ? ' *' : ''));
    const input = document.createElement(type === 'select' ? 'select' : 'input');
    if (type !== 'select') input.type = type;
    input.name = name; input.id = 'field-' + name; input.required = required;
    if (type === 'checkbox') { input.checked = Boolean(value) && value !== '0'; wrapper.classList.add('check-field'); wrapper.append(input, title); }
    else { input.value = value; input.maxLength = ({ roistat_value: 280, utm_source: 80, utm_medium: 80, utm_content: 160, utm_term: 160 })[name] || 250; wrapper.append(title, input); }
    fieldArea.append(wrapper); return input;
  }
  function rememberFields() {
    const values = state.mode === 'online' ? state.online : (state.details[state.carrier] ||= {});
    fieldArea.querySelectorAll('input,select').forEach(el => { values[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
  }
  function trackingField() {
    const enabled = Boolean($('field-use_roistat')?.checked);
    const preset = state.data.presets[$('field-preset')?.value];
    const input = $('field-roistat_value'); if (!input) return;
    input.closest('label').hidden = !enabled; input.disabled = !enabled; input.required = enabled;
    input.inputMode = preset?.template ? 'numeric' : 'text';
    input.closest('label').querySelector('span').textContent = preset?.template ? 'ID канала в Roistat *' : 'Метка Roistat *';
    const hint = $('roistat-hint');
    hint.textContent = preset?.template ? `${preset.key}=${preset.template} · Площадка подставит макросы. Реальная интеграция требует проверки в рекламном кабинете.` : 'Значение метки или готовый параметр roistat=… / rs=…';
    hint.hidden = !enabled;
  }
  function channelFields(apply = false) {
    const select = $('field-preset'); if (!select) return;
    const preset = state.data.presets[select.value];
    const source = $('field-utm_source'); const partner = select.value === 'partner_site';
    source.closest('label').querySelector('span').textContent = partner ? 'Название сайта партнёра · utm_source *' : 'Источник · utm_source *';
    source.placeholder = partner ? 'partner.ru — латиница, без https://' : '';
    if (apply && preset) {
      source.value = preset.source;
      $('field-utm_medium').value = preset.medium;
      $('field-roistat_value').value = preset.default || '';
    }
  }
  function renderFields() {
    const offline = state.mode === 'offline'; const type = offline ? state.carrier : 'online';
    fieldArea.replaceChildren();
    $('carrier-group').hidden = !offline;
    root.querySelector('.advanced-fields').hidden = !offline;
    $('auto-label').hidden = !offline;
    $('placement-title').textContent = offline ? 'Параметры размещения' : 'Параметры кампании';
    $('detail-heading').textContent = offline ? `Данные · ${state.data.types[type]}` : 'UTM и дополнительные метки';
    root.querySelectorAll('.mode-tab,.carrier').forEach(el => { const active = el.dataset.mode ? el.dataset.mode === state.mode : el.dataset.carrier === state.carrier; el.classList.toggle('is-active', active); el.setAttribute('aria-pressed', String(active)); });
    if (offline) {
      const values = state.details[type] ||= {};
      const overrides = {
        exhibition: { object_code: 'Идентификатор места QR (например, стенд A12)', surface: 'Носитель (например, стойка у входа)' },
        conference: { object_code: 'Идентификатор места QR', surface: 'Носитель', speaker: 'Докладчик (необязательно)' },
        outdoor: { object_code: 'Код поверхности' },
        transport: { object_code: 'Код машины или группы', surface: 'Место на транспорте' },
        offline_point: { object_code: 'Код точки', zone: 'Зона размещения' },
        print_media: { event: 'Название издания' },
      };
      state.data.visible[type].forEach(name => field(name, overrides[type]?.[name] || state.data.labels[name], name === 'moving' ? 'checkbox' : name === 'event_date' ? 'date' : 'text', state.data.required[type].includes(name), values[name] || '', name === 'address' || name === 'moving'));
    } else {
      const values = state.online;
      const preset = field('preset', 'Шаблон канала', 'select', false, '', true);
      preset.add(new Option('Вручную', ''));
      Object.entries(state.data.presets).forEach(([key, p]) => preset.add(new Option(`${p.label || p.source + ' / ' + p.medium} · v${p.version}`, key)));
      preset.value = values.preset || '';
      field('utm_source', 'Источник · utm_source', 'text', true, values.utm_source || '');
      field('utm_medium', 'Канал · utm_medium', 'text', true, values.utm_medium || '');
      field('utm_content', 'Объявление · utm_content', 'text', false, values.utm_content || '');
      field('utm_term', 'Ключевая фраза · utm_term', 'text', false, values.utm_term || '');
      field('use_roistat', 'Добавить метку Roistat', 'checkbox', false, values.use_roistat || false, true);
      const value = field('roistat_value', 'Метка Roistat', 'text', false, values.roistat_value || '', true);
      const hint = node('small', '', 'tracking-hint'); hint.id = 'roistat-hint'; value.closest('label').append(hint); channelFields(); trackingField();
    }
    const note = state.carrier === 'transport' ? 'Выпускайте отдельный код на каждую машину для раздельной статистики. Считывание на ходу проверяется только в безопасном контролируемом сценарии.' : ['outdoor', 'print_media'].includes(state.carrier) ? 'До тиража проверьте SVG в макете и QR на реальном материале с iOS и Android.' : '';
    $('carrier-note').textContent = offline ? note : ''; $('carrier-note').hidden = !offline || !note;
  }
  function payload() {
    rememberFields();
    return { unit_id: $('business').value, campaign_id: $('campaign').value, brand_id: $('profile').value, type: state.mode === 'offline' ? state.carrier : 'online', url: $('destination').value.trim(), place_slug: $('place-slug').value.trim(), details: state.mode === 'offline' ? { ...state.details[state.carrier] } : {}, ...(state.mode === 'online' ? state.online : {}), ...(config.isGuest ? { campaign_name: $('guest-campaign-name').value.trim(), campaign_year: $('guest-campaign-year').value, campaign_code: $('guest-campaign-code').value.trim() } : {}), idempotency_key: state.key };
  }
  function tagGrid(tags) {
    const list = node('dl', undefined, 'tag-grid');
    Object.entries(tags).forEach(([key, value]) => { if (value) list.append(node('dt', key), node('dd', value)); }); return list;
  }
  function showLinks(result, saved = false) {
    const output = $('utm-output'); output.replaceChildren();
    if (result) {
      output.append(node('code', result.url || result.target_url, 'url-output'), button('Копировать полную ссылку', () => copy(result.url || result.target_url)), tagGrid(result.tags));
      if (result.removed?.length) output.append(node('p', 'Будут заменены метки: ' + result.removed.join(', '), 'field-note'));
      if (!saved && result.campaignIdPending) output.append(node('p', 'ID кампании и рабочий QR будут назначены после входа и сохранения.', 'field-note'));
      if (!saved) output.append(node('p', state.mode === 'offline' ? 'Код 000000000000 — пример. Итоговые метки и рабочий QR появятся после сохранения.' : 'Предпросмотр. Для получения рабочего QR сохраните размещение.', 'field-note'));
    } else output.append(node('p', 'Метки появятся после заполнения обязательных полей.', 'result-meta'));
    $('qr-image').hidden = !saved; $('qr-empty').hidden = saved;
    if (saved) $('qr-image').src = result.downloads.preview; else $('qr-image').removeAttribute('src');
    const shortAvailable = Boolean(config.shortLinksEnabled && saved && result.short_url);
    $('short-url').hidden = !shortAvailable;
    $('short-url').value = shortAvailable ? result.short_url : '';
    $('copy-short').disabled = !shortAvailable;
    $('release-label').textContent = config.shortLinksEnabled ? (saved ? 'выпущена' : 'после сохранения') : 'временно недоступна';
    for (const id of ['download-svg', 'download-png']) $(id).disabled = !saved;
    $('save-status').hidden = !saved;
  }
  function dirty() {
    if (state.submitted) { state.key = newKey(); state.submitted = false; }
    state.current = null; showLinks(null); error($('form-error'));
    const seq = ++state.previewSeq; clearTimeout(state.previewTimer);
    if (!config.canCreate && !config.isGuest) return;
    state.previewTimer = setTimeout(async () => {
      if (!form.checkValidity()) return;
      try { const result = await api('preview', payload()); if (seq === state.previewSeq && !state.current) showLinks(result); }
      catch (e) { if (seq === state.previewSeq) error($('form-error'), e.message); }
    }, 400);
  }
  async function save(event) {
    event.preventDefault(); if (config.isGuest) { authenticate('generator'); return; } if (state.saving || !form.reportValidity()) return;
    if (state.current) { notify('Это размещение уже сохранено'); return; }
    const input = payload(); state.submitted = true; state.saving = true; ++state.previewSeq;
    const controls = [...form.querySelectorAll('input,select,button'), $('profile')]; const disabled = controls.map(el => el.disabled);
    controls.forEach(el => { el.disabled = true; });
    try { const result = await api('issue', input); state.current = result; try { sessionStorage.removeItem(draftKey); } catch {} showLinks(result, true); notify('Размещение добавлено в реестр'); }
    catch (e) { error($('form-error'), e.message); }
    finally { state.saving = false; controls.forEach((el, i) => { el.disabled = disabled[i]; }); }
  }
  async function setView(view, focus = true) {
    if (config.isGuest && view !== 'generator') { showDemo(view, focus); return; }
    if (!config.canCreate && !config.isGuest && view === 'generator') view = 'register';
    const names = { generator: 'Генератор', register: 'Реестр', decode: 'Расшифровка' };
    root.querySelectorAll('main > section').forEach(el => { el.hidden = el.id !== 'view-' + view; });
    root.querySelectorAll('.nav-item[data-view]').forEach(el => { const on = el.dataset.view === view; el.classList.toggle('is-active', on); if (on) el.setAttribute('aria-current', 'page'); else el.removeAttribute('aria-current'); });
    $('breadcrumb-current').textContent = names[view]; error($('workspace-error'));
    if (view === 'register') await registry();
    if (focus) $(view + '-title').focus({ preventScroll: true });
  }
  function demoTags(value) {
    const url = new URL(value);
    if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Введите HTTP или HTTPS ссылку с UTM-метками.');
    return Object.fromEntries([...url.searchParams].filter(([key]) => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'].includes(key)));
  }
  function showDemo(view, focus = true) {
    const names = { register: 'Реестр', decode: 'Расшифровка', analytics: 'Переходы · UTC', settings: 'Справочники' };
    if (!Object.hasOwn(names, view)) return;
    let section = $('view-demo');
    if (!section) { section = node('section', undefined, 'single-view'); section.id = 'view-demo'; section.setAttribute('aria-labelledby', 'demo-title'); root.querySelector('main').append(section); }
    root.querySelectorAll('main > section').forEach(el => { el.hidden = el !== section; });
    root.querySelectorAll('.nav-item[data-view]').forEach(el => { const on = el.dataset.view === view; el.classList.toggle('is-active', on); if (on) el.setAttribute('aria-current', 'page'); else el.removeAttribute('aria-current'); });
    $('breadcrumb-current').textContent = names[view]; error($('workspace-error'));
    const heading = node('header', undefined, 'page-heading'); const title = node('h1', names[view]); title.id = 'demo-title'; title.tabIndex = -1;
    heading.append(title, node('p', 'Демонстрационный режим · Все примеры вымышлены. Изменения, экспорт и выпуск QR доступны после входа.'));
    const card = node('div', undefined, 'card'); section.replaceChildren(heading, card);
    function table(headers, rows) {
      const wrap = node('div', undefined, 'table-wrap'); const el = node('table'); const head = node('thead'); const tr = node('tr');
      headers.forEach(text => { const th = node('th', text); th.scope = 'col'; tr.append(th); }); head.append(tr);
      const body = node('tbody'); rows.forEach(values => { const row = node('tr'); values.forEach(text => row.append(node('td', String(text)))); body.append(row); });
      el.append(head, body); wrap.append(el); card.append(wrap);
    }
    if (view === 'register') table(['Кампания', 'Носитель', 'Бизнес-юнит', 'Статус', 'Переходы'], [
      ['Демо: выставка', 'Выставки', 'Демо-юнит', 'Активно', 42],
      ['Демо: партнёрский сайт', 'Онлайн', 'Демо-юнит', 'Активно', 18],
      ['Демо: печатное издание', 'Печатные издания', 'Демо-юнит', 'В архиве', 7],
    ]);
    if (view === 'analytics') {
      card.append(node('p', 'Вымышленный пример: 67 переходов, 52 визита и 36 посетителей по cookie за период. Визит заканчивается после 30 минут без обращений к трекеру. Посетители по дням могут повторяться, поэтому дневные уникальные не суммируются. Это не показатели работающего сайта.', 'card-body'));
      table(['Дата · UTC', 'Переходы GET', 'Визиты (cookie)', 'Посетители (cookie)', 'Без cookie, оценка посетителей'], [
        ['2026-01-01', 19, 15, 12, 2], ['2026-01-02', 26, 20, 16, 3], ['2026-01-03', 22, 17, 14, 2],
      ]);
    }
    if (view === 'settings') {
      table(['Справочник', 'Пример', 'Параметры'], [
        ['Бизнес-юниты', 'Демо-юнит', 'demo'], ['Кампании', 'Демо: выставка', 'demo-expo'],
        ['Профили QR', 'Демонстрационный профиль', 'Пример, не утверждён для печати'],
        ['Онлайн-пресеты', 'Сайт партнёра', 'utm_source=partner.example · utm_medium=referral'],
      ]);
      card.append(node('p', 'Добавление и редактирование справочников доступны администратору после входа.', 'card-body'));
    }
    if (view === 'decode') {
      const form = node('form', undefined, 'card-body decode-form'); const label = node('label'); const input = node('input'); input.type = 'url'; input.required = true;
      input.value = 'https://landing.example/?utm_source=partner.example&utm_medium=referral&utm_campaign=demo';
      label.append(node('span', 'UTM-ссылка'), input); const submit = node('button', 'Расшифровать', 'button primary'); submit.type = 'submit';
      const result = node('div', undefined, 'card-body'); result.setAttribute('aria-live', 'polite');
      form.append(label, submit); card.append(node('p', 'Локальный разбор ссылки без обращения к базе. Поиск размещений по короткому коду доступен после входа.', 'card-body'), form, result);
      const parse = () => { try { const tags = demoTags(input.value); result.replaceChildren(Object.keys(tags).length ? tagGrid(tags) : node('p', 'В ссылке нет UTM-меток.')); } catch { result.replaceChildren(node('p', 'Введите корректную HTTP или HTTPS ссылку.')); } };
      form.addEventListener('submit', event => { event.preventDefault(); parse(); }); parse();
    }
    if (focus) title.focus({ preventScroll: true });
  }
  function filters() { return { q: $('registry-search').value, type: $('registry-filter').value, unit_id: $('filter-unit').value, campaign_id: $('filter-campaign').value, status: $('filter-status').value, city: $('filter-city').value, utm_medium: $('filter-medium').value, from: $('filter-from').value, to: $('filter-to').value, page: state.page }; }
  async function registry() {
    const seq = ++state.registrySeq; const data = await api('registry', filters()); if (seq !== state.registrySeq) return;
    state.page = data.page; state.exports = data.exports;
    const tbody = $('registry-rows'); tbody.replaceChildren();
    data.rows.forEach(record => {
      const row = node('tr'); const title = node('td');
      title.append(node('span', record.campaign_name, 'record-name'), node('small', [record.event, record.city, record.object_code].filter(Boolean).join(' · '), 'record-detail'), node('span', statusLabel(record.status), 'status-badge'));
      const actions = node('td'); actions.append(button('Открыть ›', guarded(() => openRecord(record)), 'record-open'));
      row.append(title, node('td', state.data.types[record.type]), node('td', record.unit_name), node('td', record.short_code, 'record-code'), node('td', String(record.hits)), actions); tbody.append(row);
    });
    $('registry-empty').hidden = data.count !== 0;
    $('registry-count').textContent = `Записей: ${data.count} · страница ${data.page} из ${data.pages}`;
    const pages = $('registry-pagination'); pages.replaceChildren();
    const prev = button('←', guarded(async () => { state.page--; await registry(); })); prev.disabled = data.page <= 1;
    const next = button('→', guarded(async () => { state.page++; await registry(); })); next.disabled = data.page >= data.pages;
    pages.append(prev, next);
    $('export-csv').hidden = !config.canExport; $('export-hits').hidden = !config.canExport;
  }
  function statusLabel(status) { return { active: 'Активно', archived: 'В архиве', revoked: 'Отозвано' }[status] || status; }
  function link(text, href) { const el = node('a', text, 'button outline'); el.href = href; return el; }
  function download(record, format) {
    const size = $('png-size').value; const key = format === 'svg' ? 'svg' : size === '2048' ? 'png' : 'png' + size;
    window.location.assign(record.downloads[key]);
  }
  async function duplicate(record) {
    $('record-dialog').close(); await setView('generator');
    $('business').value = String(record.unit_id); selectUnit(); $('campaign').value = String(record.campaign_id);
    $('destination').value = record.original_url; $('place-slug').value = '';
    state.mode = record.type === 'online' ? 'online' : 'offline'; if (state.mode === 'offline') state.carrier = record.type;
    if (state.mode === 'offline') state.details[record.type] = { ...record.details };
    else {
      state.online = { ...record.tags, use_roistat: Boolean(record.tracking.tags && Object.keys(record.tracking.tags).length), roistat_value: Object.entries(record.tracking.tags || {}).map(([k, v]) => k + '=' + v)[0] || '', preset: '' };
    }
    state.key = newKey(); state.submitted = false; renderFields(); dirty();
  }
  function recordBody(container, record, manage = true) {
    container.replaceChildren(node('p', record.campaign_name, 'result-title'), node('p', `${record.unit_name} · ${state.data.types[record.type]} · ${statusLabel(record.status)}`, 'result-meta'));
    const image = document.createElement('img'); image.src = record.downloads.preview; image.alt = 'QR размещения'; image.className = 'detail-qr';
    container.append(image);
    if (config.shortLinksEnabled && record.short_url) container.append(node('code', record.short_url, 'url-output'));
    container.append(node('code', record.target_url, 'url-output'), tagGrid(record.tags));
    const details = { ...record.details, 'Профиль': `${record.brand_name} v${record.brand_version}`, 'Шаблон / правила': `${record.template_version} / ${record.rules_version}`, 'Создано UTC': record.created_at, 'Автор ID': record.author_id, 'Переходы GET': record.hits };
    if (record.tracking.preset_id) details['Версия онлайн-пресета'] = `${record.tracking.preset_id} / v${record.tracking.preset_version}`;
    const dl = node('dl', undefined, 'tag-grid'); Object.entries(details).forEach(([k, v]) => { if (v) dl.append(node('dt', state.data.labels[k] || k), node('dd', String(v))); }); container.append(dl);
    const actions = node('div', undefined, 'result-actions');
    actions.append(button('Копировать UTM', () => copy(record.target_url)));
    if (config.shortLinksEnabled && record.short_url) actions.append(button('Копировать короткую', () => copy(record.short_url)));
    actions.append(link('SVG', record.downloads.svg), link('PNG 1024', record.downloads.png1024), link('PNG 2048', record.downloads.png), link('PNG 4096', record.downloads.png4096));
    if (config.canCreate) actions.append(button('Дублировать', guarded(() => duplicate(record)))); container.append(actions);
    if (['outdoor', 'transport', 'print_media'].includes(record.type)) container.append(node('p', 'Сохраните защитное поле и добавьте подпись. Перед печатью проверьте макет в реальном масштабе на iOS и Android.', 'field-note'));
    const history = node('details'); history.append(node('summary', 'История изменений'));
    record.audit.forEach(a => { const entry = node('details'); entry.append(node('summary', `${a.created_at} UTC · ${a.action} · автор ${a.author_id}`), node('p', a.reason), node('pre', a.old_json + ' → ' + a.new_json)); history.append(entry); }); container.append(history);
    if (!config.canManage || !manage) return;
    const change = node('form', undefined, 'record-change form-grid');
    const urlLabel = node('label', undefined, 'full'); urlLabel.append(node('span', 'Новое назначение (оставьте пустым, если меняете только статус)')); const url = document.createElement('input'); url.type = 'url'; urlLabel.append(url);
    const reasonLabel = node('label', undefined, 'full'); reasonLabel.append(node('span', 'Причина изменения назначения')); const reason = document.createElement('input'); reason.maxLength = 1000; reasonLabel.append(reason);
    const status = document.createElement('select'); ['active', 'archived', 'revoked'].forEach(s => status.add(new Option(statusLabel(s), s))); status.value = record.status;
    const submit = node('button', 'Сохранить изменения', 'button primary'); submit.type = 'submit';
    const err = node('p', '', 'form-error full'); err.hidden = true;
    change.append(urlLabel, reasonLabel, status, submit, err);
    change.addEventListener('submit', async event => {
      event.preventDefault(); submit.disabled = true;
      try { const updated = await api('change', { id: record.id, url: url.value.trim(), reason: reason.value.trim(), status: status.value }); recordBody(container, updated); notify('Изменения сохранены'); }
      catch (e) { error(err, e.message); submit.disabled = false; }
    }); container.append(change);
  }
  async function openRecord(record) { recordBody($('record-body'), record); if (!$('record-dialog').open) $('record-dialog').showModal(); }
  async function decode(event) {
    event.preventDefault(); const data = await api('decode', { value: $('decode-input').value });
    const out = $('decode-result'); out.hidden = false; out.replaceChildren();
    if (!data.rows.length) out.append(node('p', 'В реестре не найдено.', 'result-title'), tagGrid(data.tags));
    else if (data.rows.length === 1) recordBody(out, data.rows[0], false);
    else data.rows.forEach(r => out.append(button(`${r.campaign_name} · ${r.short_code}`, guarded(() => openRecord(r)))));
  }
  let campaignSlugSequence = 0;
  async function campaignSlug() {
    const seq = ++campaignSlugSequence; const f = campaignForm.elements;
    const unit = state.data.units.find(u => String(u.id) === f.unit_id.value); if (!unit) return;
    const result = await api('slug', { text: f.name.value });
    if (seq === campaignSlugSequence) f.campaign_code.placeholder = result.slug ? `${unit.unit_slug}-${f.year.value}-${result.slug}` : 'Оставьте пустым — код создастся автоматически';
  }
  async function createCampaign(event) {
    event.preventDefault(); ++campaignSlugSequence; const submit = campaignForm.querySelector('[type=submit]'); submit.disabled = true;
    try {
      const data = await api('campaign', Object.fromEntries(new FormData(campaignForm))); state.data.campaigns.unshift(data.campaign);
      $('business').value = String(data.campaign.unit_id); selectUnit(); $('campaign').value = String(data.campaign.id);
      choices($('filter-campaign'), state.data.campaigns, c => c.name, 'Все кампании');
      state.restoredCampaign = null; $('campaign-dialog').close(); dirty(); notify('Кампания создана');
    } catch (e) { error($('campaign-error'), e.message); } finally { submit.disabled = false; }
  }
  const draftKey = 'cargo-utm-public-draft.v1';
  function authenticate(view = 'generator', destination = null) {
    try { sessionStorage.setItem(draftKey, JSON.stringify({ time: Date.now(), input: payload(), mode: state.mode, carrier: state.carrier, details: state.details, online: state.online })); }
    catch { notify('Черновик не удалось сохранить в браузере. Скопируйте UTM-ссылку перед входом.'); }
    const target = new URL(destination || config.links.home);
    if (!destination && view !== 'generator') target.searchParams.set('tab', view === 'register' ? 'registry' : view);
    if (config.emailLogin) { window.dispatchEvent(new CustomEvent('cargo-login', { detail: { target: target.href } })); return; }
    const login = new URL(config.links.login); login.searchParams.set('redirect_to', target.href);
    window.location.assign(login.href);
  }
  function setupGuestFields() {
    const account = node('div', undefined, 'account-actions');
    if (config.isGuest) account.append(button('Войти', () => authenticate()));
    else account.append(link('Выйти', config.links.logout));
    root.querySelector('.topbar').append(account);
    $('guest-campaign-fields').hidden = !config.isGuest;
    $('saved-campaign-field').hidden = Boolean(config.isGuest);
    $('campaign').disabled = Boolean(config.isGuest);
    $('guest-campaign-name').required = Boolean(config.isGuest);
    $('guest-campaign-year').required = Boolean(config.isGuest);
    ['guest-campaign-name', 'guest-campaign-year', 'guest-campaign-code'].forEach(id => { $(id).disabled = !config.isGuest; });
    $('guest-campaign-year').value = new Date().getFullYear();
    if (config.isGuest) {
      $('create-campaign').hidden = true;
      $('save-placement').replaceChildren(document.createTextNode('Войти и получить QR'));
      $('qr-empty').textContent = 'Рабочий QR появится после входа и сохранения';
    }
  }
  function draftValues(raw, fields) {
    const clean = {};
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return clean;
    for (const [key, max] of Object.entries(fields)) {
      if (!Object.hasOwn(raw, key)) continue;
      const value = raw[key];
      if (max === 0) {
        if ([true, false, 0, 1, '0', '1', ''].includes(value)) clean[key] = value === true || value === 1 || value === '1';
      } else if (typeof value === 'string' && [...value].length <= max && !/[\x00-\x1f\x7f]/.test(value)) clean[key] = value;
    }
    return clean;
  }
  function restoreDraft() {
    let draft;
    try { const raw = sessionStorage.getItem(draftKey) || 'null'; if (raw.length > 32768) return; draft = JSON.parse(raw); } catch { return; }
    if (!draft || !Number.isFinite(draft.time) || draft.time > Date.now() || Date.now() - draft.time > 86400000 || !draft.input || typeof draft.input !== 'object' || Array.isArray(draft.input)) return;
    const input = draftValues(draft.input, { url: 2048, place_slug: 80, brand_id: 80, campaign_name: 200, campaign_code: 120 });
    input.unit_id = typeof draft.input.unit_id === 'string' || Number.isSafeInteger(draft.input.unit_id) ? String(draft.input.unit_id) : '';
    const year = typeof draft.input.campaign_year === 'string' || Number.isSafeInteger(draft.input.campaign_year) ? String(draft.input.campaign_year) : '';
    input.campaign_year = /^[0-9]{4}$/.test(year) ? year : '';
    if ([...$('business').options].some(o => o.value === String(input.unit_id))) $('business').value = String(input.unit_id);
    selectUnit();
    if (['online', 'offline'].includes(draft.mode)) state.mode = draft.mode;
    if (typeof draft.carrier === 'string' && draft.carrier !== 'online' && Object.hasOwn(state.data.visible, draft.carrier)) state.carrier = draft.carrier;
    state.details = {};
    for (const [type, fields] of Object.entries(state.data.visible)) {
      if (type !== 'online') state.details[type] = draftValues(draft.details?.[type], Object.fromEntries(fields.map(key => [key, key === 'moving' ? 0 : 250])));
    }
    state.online = draftValues(draft.online, { preset: 40, utm_source: 80, utm_medium: 80, utm_content: 160, utm_term: 160, use_roistat: 0, roistat_value: 280 });
    $('destination').value = input.url || ''; $('place-slug').value = input.place_slug || '';
    if (input.brand_id === 'corporate') {
      const common = state.data.brands.find(b => b.unit_id === null);
      if (common) $('profile').value = String(common.id);
    }
    if (config.isGuest) {
      $('guest-campaign-name').value = input.campaign_name || '';
      $('guest-campaign-year').value = input.campaign_year || new Date().getFullYear();
      $('guest-campaign-code').value = input.campaign_code || '';
    } else {
      state.restoredCampaign = { name: input.campaign_name || '', year: input.campaign_year, code: input.campaign_code || '' };
      notify('Черновик восстановлен. Выберите кампанию или нажмите «Создать кампанию».');
    }
    renderFields(); dirty();
  }
  async function init() {
    state.data = await api('bootstrap'); fillCatalogs();
    root.querySelector('.environment').hidden = config.production;
    const nav = root.querySelector('.navigation');
    if (config.isGuest) {
      for (const [view, title] of [['analytics', 'Переходы · UTC'], ['settings', 'Справочники']]) {
        const item = button(title, () => {}, 'nav-item'); item.dataset.view = view; nav.append(item);
      }
    } else {
      nav.append(link('Переходы · UTC', config.links.analytics));
      if (config.canManage) nav.append(link('Справочники', config.links.settings));
    }
    nav.querySelectorAll('a').forEach(a => { a.className = 'nav-item'; });
    if (!config.canCreate && !config.isGuest) root.querySelectorAll('[data-view=generator],#new-placement,#create-campaign').forEach(el => { el.hidden = true; });
    const hits = button('CSV · переходы по дням', () => { if (state.exports.hits) window.location.assign(state.exports.hits); }); hits.id = 'export-hits'; $('export-csv').after(hits);
    $('export-csv').addEventListener('click', () => { if (state.exports.placements) window.location.assign(state.exports.placements); });
    root.querySelectorAll('[data-view]').forEach(el => el.addEventListener('click', guarded(() => setView(el.dataset.view))));
    root.querySelectorAll('.mode-tab,.carrier').forEach(el => el.addEventListener('click', () => { if (state.saving) return; rememberFields(); if (el.dataset.mode) state.mode = el.dataset.mode; if (el.dataset.carrier) state.carrier = el.dataset.carrier; renderFields(); dirty(); }));
    $('business').addEventListener('change', () => { selectUnit(); dirty(); });
    form.addEventListener('input', event => { if (event.target === $('business')) return; rememberFields(); if (event.target.name === 'use_roistat') trackingField(); dirty(); });
    form.addEventListener('change', event => {
      if (event.target.name !== 'preset') return;
      channelFields(true);
      rememberFields(); trackingField(); dirty();
    });
    form.addEventListener('submit', save);
    $('profile').addEventListener('change', dirty);
    $('clear-form').addEventListener('click', () => { $('destination').value = ''; $('campaign').value = ''; $('place-slug').value = ''; $('guest-campaign-name').value = ''; $('guest-campaign-code').value = ''; state.details = {}; state.online = {}; state.restoredCampaign = null; try { sessionStorage.removeItem(draftKey); } catch {} renderFields(); dirty(); });
    $('copy-short').addEventListener('click', () => { if (config.shortLinksEnabled && state.current?.short_url) copy(state.current.short_url); });
    ['svg', 'png'].forEach(format => $('download-' + format).addEventListener('click', () => { if (state.current) download(state.current, format); }));
    $('png-size').addEventListener('change', () => { $('download-png').querySelector('.format-description').textContent = $('png-size').value + ' px'; });
    $('new-placement').addEventListener('click', guarded(() => setView('generator')));
    let timer; $('registry-search').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(guarded(() => { state.page = 1; return registry(); }), 300); });
    ['registry-filter', 'filter-unit', 'filter-campaign', 'filter-status'].forEach(id => $(id).addEventListener('change', guarded(() => { state.page = 1; return registry(); })));
    $('apply-filters').addEventListener('click', guarded(() => { state.page = 1; return registry(); }));
    $('decode-form').addEventListener('submit', guarded(decode));
    $('close-dialog').addEventListener('click', () => $('record-dialog').close());
    $('close-campaign').addEventListener('click', () => $('campaign-dialog').close());
    $('create-campaign').addEventListener('click', () => { campaignForm.reset(); campaignForm.elements.unit_id.value = $('business').value; campaignForm.elements.year.value = state.restoredCampaign?.year || new Date().getFullYear(); campaignForm.elements.name.value = state.restoredCampaign?.name || ''; campaignForm.elements.campaign_code.value = state.restoredCampaign?.code || ''; ++campaignSlugSequence; campaignForm.elements.campaign_code.placeholder = 'Оставьте пустым — код создастся автоматически'; error($('campaign-error')); $('campaign-dialog').showModal(); campaignForm.elements.name.focus(); });
    campaignForm.addEventListener('input', guarded(event => { if (['name', 'unit_id', 'year'].includes(event.target.name)) return campaignSlug(); })); campaignForm.addEventListener('submit', createCampaign);
    setupGuestFields(); renderFields(); showLinks(null); restoreDraft();
    if (!state.data.units.length) error($('workspace-error'), 'Добавьте бизнес-юнит и профиль бренда в справочниках.');
    $('destination').placeholder = 'https://' + (state.data.allowedHosts[0] || '…') + '/…';
    await setView(config.initialView, false);
    if (!config.isGuest && config.initialRecord) await openRecord(await api('record', { id: config.initialRecord }));
    if (!config.isGuest && config.duplicate && config.canCreate) await duplicate(await api('record', { id: config.duplicate }));
  }
  // Block form submission until the authenticated catalog has loaded.
  $('save-placement').disabled = true;
  init().then(() => { $('save-placement').disabled = !config.canCreate && !config.isGuest; }).catch(e => error($('workspace-error'), e.message));
})();
