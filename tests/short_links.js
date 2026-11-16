'use strict';
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('plugin/cargo-utm/assets/ui/app.js', 'utf8');
const start = source.indexOf('  function showLinks(');
const end = source.indexOf('\n  }', start) + 4;
const elements = {};
for (const id of ['utm-output', 'qr-image', 'qr-empty', 'short-url', 'copy-short', 'release-label', 'download-svg', 'download-png', 'save-status']) {
  elements[id] = { replaceChildren() {}, append() {}, removeAttribute(name) { delete this[name]; } };
}
const ctx = { config: { shortLinksEnabled: false }, state: { mode: 'online' }, $: id => elements[id], node() {}, button() {}, tagGrid() {} };
vm.createContext(ctx); vm.runInContext(source.slice(start, end), ctx);
const record = { url: 'https://landing.example/', short_url: 'https://go.cargo.test/r/123456789abc', tags: {}, downloads: { preview: '/preview' } };
ctx.showLinks(record, true);
assert.equal(elements['copy-short'].disabled, true);
assert.equal(elements['short-url'].value, '');
assert.equal(elements['short-url'].hidden, true);
assert.equal(elements['release-label'].textContent, 'временно недоступна');
ctx.config.shortLinksEnabled = true; ctx.showLinks(record, true);
assert.equal(elements['copy-short'].disabled, false);
assert.equal(elements['short-url'].value, record.short_url);
ctx.showLinks(null);
assert.equal(elements['copy-short'].disabled, true);
assert.equal(elements['short-url'].value, '');
console.log('PASS: disabled short-link control and hidden URL, explicit enable and reset');
