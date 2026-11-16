'use strict';
// Exercise the production draft functions with browser storage/navigation doubles.
// DOM layout and actual WordPress login still require browser acceptance.
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('plugin/cargo-utm/assets/ui/app.js', 'utf8');
function productionFunction(name) {
  const start = source.indexOf(`  function ${name}(`);
  const end = source.indexOf('\n  }', start) + 4;
  assert(start >= 0 && end > start);
  return source.slice(start, end);
}
function browser(guest) {
  const storage = new Map(); const elements = {};
  for (const id of ['business', 'profile', 'destination', 'place-slug', 'guest-campaign-name', 'guest-campaign-year', 'guest-campaign-code']) elements[id] = { value: '', options: [] };
  elements.business.options = [{ value: '7' }];
  const ctx = {
    URL, Date, Number, JSON, String,
    config: { isGuest: guest, links: { home: 'https://utmqr.kit-rb.com/', login: 'https://utmqr.kit-rb.com/wp-login.php?redirect_to=old' } },
    state: { data: { visible: { exhibition: [], outdoor: [], online: [] }, brands: [{ id: guest ? 'corporate' : 42, unit_id: null }] }, mode: 'offline', carrier: 'exhibition', details: {}, online: {} },
    draftKey: 'cargo-utm-public-draft.v1',
    $: id => elements[id],
    sessionStorage: { getItem: key => storage.get(key) ?? null, setItem: (key, value) => storage.set(key, value) },
    window: { location: { assign: url => { ctx.redirect = url; } } },
    selectUnit: () => { elements.profile.value = 'unit-default'; },
    notify: () => {}, renderFields: () => {}, dirty: () => { ctx.dirtied = true; },
    payload: () => ({ unit_id: '7', brand_id: 'corporate', url: 'https://fuel.cargo.example/', place_slug: 'banner', campaign_name: 'Выставка', campaign_year: '2026', campaign_code: 'banner_25' }),
  };
  vm.createContext(ctx);
  vm.runInContext(productionFunction('authenticate') + '\n' + productionFunction('restoreDraft'), ctx);
  return { ctx, storage, elements };
}
const guest = browser(true);
guest.ctx.state.mode = 'online'; guest.ctx.state.online = { utm_source: 'yandex', utm_medium: 'cpc' };
guest.ctx.authenticate('register');
const login = new URL(guest.ctx.redirect);
assert.equal(login.pathname, '/wp-login.php');
assert.equal(login.searchParams.get('redirect_to'), 'https://utmqr.kit-rb.com/?tab=registry');
guest.ctx.authenticate('generator', 'https://utmqr.kit-rb.com/wp-admin/admin.php?page=cargo-utm&tab=settings');
assert.equal(new URL(guest.ctx.redirect).searchParams.get('redirect_to'), 'https://utmqr.kit-rb.com/wp-admin/admin.php?page=cargo-utm&tab=settings');
const draft = guest.storage.get(guest.ctx.draftKey);
assert(draft);
const editor = browser(false);
editor.storage.set(editor.ctx.draftKey, draft); editor.ctx.restoreDraft();
assert.equal(editor.ctx.state.mode, 'online');
assert.equal(editor.ctx.state.online.utm_source, 'yandex');
assert.equal(editor.elements.profile.value, '42');
assert.equal(editor.elements.destination.value, 'https://fuel.cargo.example/');
assert.equal(editor.ctx.state.restoredCampaign.code, 'banner_25');
assert.equal(editor.ctx.dirtied, true);
guest.ctx.restoreDraft();
assert.equal(guest.elements.profile.value, 'corporate');
assert.equal(guest.elements['guest-campaign-code'].value, 'banner_25');
for (const value of ['{', 'null', JSON.stringify({ time: Date.now() - 86400001, input: {} }), JSON.stringify({ time: Date.now() + 60000, input: {} })]) {
  const invalid = browser(false); invalid.storage.set(invalid.ctx.draftKey, value); invalid.ctx.restoreDraft();
  assert.equal(invalid.ctx.dirtied, undefined);
}
const offline = browser(false);
offline.storage.set(offline.ctx.draftKey, JSON.stringify({ time: Date.now(), input: { unit_id: 7, url: 'https://fuel.cargo.example/', place_slug: 'street' }, mode: 'offline', carrier: 'outdoor', details: { outdoor: { city: 'Москва' } } }));
offline.ctx.restoreDraft();
assert.equal(offline.ctx.state.carrier, 'outdoor');
assert.equal(offline.ctx.state.details.outdoor.city, 'Москва');
assert.equal(offline.elements['place-slug'].value, 'street');
console.log('PASS: guest login target, online/offline draft restoration, brand/code preservation, invalid/expired drafts');
