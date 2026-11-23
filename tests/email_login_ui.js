'use strict';
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('plugin/cargo-utm/assets/ui/auth.js', 'utf8');
class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.handlers = {}; this.value = ''; }
  append(...nodes) { this.children.push(...nodes); }
  setAttribute(key, value) { this[key] = value; }
  addEventListener(name, handler) { this.handlers[name] = handler; }
  showModal() { this.open = true; }
  close() { this.open = false; }
  reportValidity() { return true; }
  focus() {}
}
async function browser(ready, loginTarget) {
  const root = new Element('root');
  const handlers = {}; const requests = []; const redirects = [];
  const config = { isGuest: true, emailLogin: { ready, nonce: 'test', endpoint: '/wp-admin/admin-ajax.php?action=cargo_email_login' }, links: { home: 'https://utmqr.kit-rb.com/', loginTarget } };
  root.querySelector = () => ({ textContent: JSON.stringify(config) });
  const ctx = {
    document: { getElementById: () => root, createElement: tag => new Element(tag) },
    window: { location: { href: config.links.home, assign: value => redirects.push(value) }, addEventListener: (name, fn) => { handlers[name] = fn; } },
    URL, URLSearchParams, setInterval() {},
    fetch: async (url, options) => {
      const data = Object.fromEntries(options.body); requests.push(data);
      return { ok: true, json: async () => ({ success: true, data: data.op === 'request' ? { challenge: 'test-challenge', retryAfter: 60, message: 'sent' } : {} }) };
    },
  };
  vm.runInNewContext(source, ctx);
  const all = el => [el, ...el.children.flatMap(all)];
  const elements = all(root); const dialog = elements.find(el => el.tag === 'dialog');
  const form = elements.find(el => el.tag === 'form'); const email = elements.find(el => el.type === 'email');
  const code = elements.find(el => el.autocomplete === 'one-time-code');
  const submit = elements.find(el => el.type === 'submit');
  return { handlers, requests, redirects, dialog, form, email, code, submit, elements };
}
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const off = await browser(false); off.handlers['cargo-login']({ detail: {} });
  assert.equal(off.dialog.open, true); assert.equal(off.submit.disabled, true); assert.equal(off.requests.length, 0);
  const app = await browser(true); app.handlers['cargo-login']({ detail: { target: 'https://evil.example/' } });
  app.email.value = 'person@cargonomica.com'; app.form.handlers.submit({ preventDefault() {} }); await tick();
  assert.equal(app.requests.length, 1); assert.equal(app.requests[0].op, 'request');
  assert.equal(app.code.disabled, false); assert.equal(app.email.readOnly, true);
  app.code.value = '012345'; app.form.handlers.submit({ preventDefault() {} }); await tick();
  assert.equal(app.requests[1].code, '012345'); assert.equal(app.requests[1].challenge, 'test-challenge');
  assert.equal(app.redirects[0], 'https://utmqr.kit-rb.com/');
  const cardsUrl = 'https://utmqr.kit-rb.com/business-cards';
  const cards = await browser(true, cardsUrl);
  assert.equal(cards.dialog.open, true);
  assert.equal(cards.requests.length, 0);
  cards.email.value = 'person@cargonomica.com';
  cards.form.handlers.submit({ preventDefault() {} }); await tick();
  cards.code.value = '012345';
  cards.form.handlers.submit({ preventDefault() {} }); await tick();
  assert.equal(cards.redirects[0], cardsUrl);
  assert.ok(!/innerHTML|console\.|localStorage|sessionStorage/.test(source));
  console.log('PASS: disabled login UI, request/verify flow, leading-zero OTP and outside redirect blocked');
})().catch(error => { console.error(error); process.exitCode = 1; });
