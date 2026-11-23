'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const script = fs.readFileSync('plugin/cargo-utm/assets/ui/cards.js', 'utf8');
function fixture(contacts, online = true) {
  const node = () => ({
    events: {}, hidden: true, textContent: '',
    addEventListener(name, callback) { this.events[name] = callback; },
    setAttribute() {}, focus() {}, remove() {}, replaceChildren() {},
  });
  const ids = Object.fromEntries(['bc-config', 'bc-message', 'bc-guest-form', 'bc-open', 'bc-picker'].map(id => [id, node()]));
  ids['bc-config'].textContent = '{"signature":"synthetic"}';
  const form = ids['bc-guest-form'];
  form.elements = { full_name: node(), phone: node(), email: node(), pd: { checked: true }, marketing: { checked: false } };
  form.querySelector = () => null;
  form.reset = () => {};
  const requests = [];
  const context = {
    document: { getElementById: id => ids[id], querySelector: () => ({ dataset: { mode: 'guest' } }) },
    navigator: { contacts, onLine: online },
    window: { addEventListener() {} },
    location: { pathname: '/c/s00000000000000000000000000000000' },
    crypto: webcrypto,
    FormData: class {
      constructor(current) { this.values = Object.entries(current.elements).filter(([, field]) => field.value).map(([key, field]) => [key, field.value]); }
      [Symbol.iterator]() { return this.values[Symbol.iterator](); }
    },
    fetch: async (url, options) => {
      requests.push({ url, payload: JSON.parse(options.body), cache: options.cache });
      return { ok: true, json: async () => ({ success: true, data: { marketing: false } }) };
    },
  };
  vm.runInNewContext(script, context);
  return { ids, requests };
}
async function main() {
  const manual = fixture(undefined, false);
  assert.equal(manual.ids['bc-picker'].hidden, true);
  assert.match(manual.ids['bc-message'].textContent, /Нет соединения/);
  manual.ids['bc-open'].events.click();
  assert.equal(manual.ids['bc-guest-form'].hidden, false);
  let selection = 0;
  const mobile = fixture({
    getProperties: async () => ['name', 'tel', 'address'],
    select: async (keys, options) => {
      assert.equal(keys.join(','), 'name,tel');
      assert.equal(options.multiple, false);
      return ++selection === 1 ? [] : [{ name: ['Анна Тест'], tel: ['+79000000000'] }];
    },
  });
  await Promise.resolve();
  assert.equal(selection, 0);
  assert.equal(mobile.ids['bc-picker'].hidden, false);
  await mobile.ids['bc-picker'].events.click();
  assert.match(mobile.ids['bc-message'].textContent, /Выбор отменён/);
  await mobile.ids['bc-picker'].events.click();
  const form = mobile.ids['bc-guest-form'];
  assert.equal(form.elements.full_name.value, 'Анна Тест');
  assert.equal(mobile.requests.length, 0);
  form.events.submit({ preventDefault() {}, submitter: {} });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(mobile.requests.length, 1);
  const request = mobile.requests[0];
  assert.equal(request.cache, 'no-store');
  assert.doesNotMatch(request.url, /79000000000/);
  assert.equal(request.payload.pd, true);
  assert.equal(request.payload.marketing, false);
  assert.match(request.payload.submission, /^[0-9a-f]{32}$/);
  assert.match(mobile.ids['bc-message'].textContent, /Контакт передан/);
  console.log('PASS: Contact Picker по нажатию, отмена, ручной fallback, offline и явная отправка формы');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
