'use strict';
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('plugin/cargo-utm/assets/ui/app.js', 'utf8');
const start = source.indexOf('  function channelFields(');
const end = source.indexOf('\n  }', start) + 4;
const title = { textContent: '' };
const elements = {
  'field-preset': { value: 'partner_site' },
  'field-utm_source': { value: 'yandex', closest: () => ({ querySelector: () => title }) },
  'field-utm_medium': { value: 'cpc' }, 'field-roistat_value': { value: '123' },
};
const ctx = { $: id => elements[id], state: { data: { presets: {
  partner_site: { source: '', medium: 'referral' }, yandex: { source: 'yandex', medium: 'cpc' },
} } } };
vm.createContext(ctx); vm.runInContext(source.slice(start, end), ctx);
ctx.channelFields(true);
assert.equal(elements['field-utm_source'].value, '');
assert.equal(elements['field-utm_medium'].value, 'referral');
assert.match(title.textContent, /Название сайта партнёра/);
elements['field-utm_source'].value = 'partner.ru'; ctx.channelFields();
assert.equal(elements['field-utm_source'].value, 'partner.ru', 'Restoring a draft must preserve the partner');
elements['field-preset'].value = 'yandex'; ctx.channelFields(true);
assert.equal(elements['field-utm_source'].value, 'yandex');
assert.equal(elements['field-utm_medium'].value, 'cpc');
assert.equal(elements['field-utm_source'].placeholder, '');
elements['field-preset'].value = ''; elements['field-utm_source'].value = 'custom'; ctx.channelFields(true);
assert.equal(elements['field-utm_source'].value, 'custom');
console.log('PASS: partner channel defaults, source label, draft preservation and channel switching');
