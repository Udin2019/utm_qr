(() => {
  'use strict';
  const root = document.getElementById('cargo-app');
  if (!root) return;
  const config = JSON.parse(root.querySelector('#cargo-config').textContent);
  if (!config.isGuest || !config.emailLogin) return;
  function el(tag, text, cls) {
    const result = document.createElement(tag);
    if (text !== undefined) result.textContent = text;
    if (cls) result.className = cls;
    return result;
  }
  const dialog = el('dialog', undefined, 'detail-dialog');
  const title = el('h2', 'Вход для сотрудников'); title.id = 'email-login-title'; dialog.setAttribute('aria-labelledby', title.id);
  const header = el('div', undefined, 'dialog-header');
  const close = el('button', '×', 'icon-button'); close.type = 'button'; close.setAttribute('aria-label', 'Закрыть вход'); close.addEventListener('click', () => dialog.close());
  header.append(title, close);
  const form = el('form', undefined, 'dialog-body form-grid');
  const note = el('p', 'Введите рабочую почту @cargonomica.com. Отправим шестизначный код. При первом входе учётная запись сотрудника создаётся автоматически.', 'full');
  const emailLabel = el('label', undefined, 'full'); const email = el('input'); email.type = 'email'; email.autocomplete = 'email'; email.required = true; email.maxLength = 254;
  emailLabel.append(el('span', 'Рабочая почта'), email);
  const codeLabel = el('label', undefined, 'full'); codeLabel.hidden = true;
  const code = el('input'); code.type = 'text'; code.inputMode = 'numeric'; code.autocomplete = 'one-time-code'; code.pattern = '[0-9]{6}'; code.maxLength = 6; code.disabled = true;
  codeLabel.append(el('span', 'Код из письма'), code, el('small', 'Код действует 10 минут. Никому его не сообщайте.'));
  const status = el('p', '', 'full'); status.setAttribute('role', 'status');
  const failure = el('p', '', 'form-error full'); failure.setAttribute('role', 'alert'); failure.hidden = true;
  const submit = el('button', 'Получить код', 'button primary'); submit.type = 'submit';
  const resend = el('button', 'Отправить код повторно', 'button outline'); resend.type = 'button'; resend.hidden = true;
  const change = el('button', 'Изменить почту', 'button outline'); change.type = 'button'; change.hidden = true;
  form.append(note, emailLabel, codeLabel, status, failure, submit, resend, change); dialog.append(header, form); root.append(dialog);
  let challenge = '', target = config.links.home, busy = false, retryAt = 0;
  function refresh() {
    submit.disabled = busy || !config.emailLogin.ready;
    change.disabled = busy;
    const seconds = Math.max(0, Math.ceil((retryAt - Date.now()) / 1000));
    resend.disabled = busy || seconds > 0;
    resend.textContent = seconds ? `Повторная отправка через ${seconds} с` : 'Отправить код повторно';
  }
  async function request(op) {
    const body = new URLSearchParams({ action: 'cargo_email_login', nonce: config.emailLogin.nonce, op, email: email.value.trim(), ...(op === 'verify' ? { challenge, code: code.value } : {}) });
    const response = await fetch(config.emailLogin.endpoint, { method: 'POST', credentials: 'same-origin', body });
    let json;
    try { json = await response.json(); } catch { throw new Error('Не удалось получить ответ. Обновите страницу и повторите вход.'); }
    if (!response.ok || !json.success) throw new Error(json.data?.message || 'Вход временно недоступен.');
    return json.data;
  }
  async function send() {
    // A resend invalidates the previous code even when delivery fails.
    challenge = ''; code.value = ''; code.disabled = true; code.required = false; codeLabel.hidden = true; email.readOnly = false;
    submit.textContent = 'Получить код'; resend.hidden = true; change.hidden = true;
    const data = await request('request');
    challenge = data.challenge; email.readOnly = true; code.disabled = false; code.required = true; codeLabel.hidden = false;
    submit.textContent = 'Войти'; resend.hidden = false; change.hidden = false;
    retryAt = Date.now() + data.retryAfter * 1000; status.textContent = data.message; code.focus();
  }
  async function run(fn) {
    if (busy) return;
    busy = true; failure.hidden = true; status.textContent = ''; refresh();
    try { await fn(); }
    catch (error) { failure.textContent = error.message; failure.hidden = false; }
    finally { busy = false; refresh(); }
  }
  form.addEventListener('submit', event => {
    event.preventDefault(); if (!form.reportValidity()) return;
    run(async () => {
      if (challenge) { await request('verify'); window.location.assign(target); }
      else await send();
    });
  });
  resend.addEventListener('click', () => { if (Date.now() >= retryAt) run(send); });
  change.addEventListener('click', () => {
    challenge = ''; code.value = ''; code.disabled = true; code.required = false; codeLabel.hidden = true;
    email.readOnly = false; resend.hidden = true; change.hidden = true; submit.textContent = 'Получить код'; status.textContent = ''; failure.hidden = true; email.focus();
  });
  window.addEventListener('cargo-login', event => {
    const url = new URL(event.detail?.target || config.links.home, window.location.href);
    const home = new URL(config.links.home);
    // Login always returns to this service, never to a supplied outside host.
    target = url.origin === home.origin ? url.href : home.href;
    if (!config.emailLogin.ready) status.textContent = 'Отправка кодов ещё не настроена. Обратитесь к администратору.';
    refresh(); if (!dialog.open) dialog.showModal();
  });
  setInterval(() => { if (dialog.open) refresh(); }, 1000);
})();
