'use strict';
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('plugin/cargo-utm/assets/ui/app.js', 'utf8');
function extract(name) {
  const start = source.indexOf(`  function ${name}(`);
  return source.slice(start, source.indexOf('\n  }', start) + 4);
}
const ids = {};
class Element {
  constructor(tag, text) { this.tag = tag; this.textContent = text; this.children = []; this.listeners = {}; this.dataset = {}; this.classList = { toggle() {} }; }
  set id(value) { this._id = value; ids[value] = this; }
  get id() { return this._id; }
  append(...elements) { this.children.push(...elements); }
  replaceChildren(...elements) { this.children = elements; }
  setAttribute() {}
  removeAttribute() {}
  addEventListener(name, fn) { this.listeners[name] = fn; }
  focus() { this.focused = true; }
}
const main = new Element('main');
for (const name of ['generator', 'register', 'decode']) { const el = new Element('section'); el.id = 'view-' + name; main.append(el); }
for (const name of ['breadcrumb-current', 'workspace-error']) { const el = new Element('div'); el.id = name; }
const nav = ['generator', 'register', 'decode', 'analytics', 'settings'].map(view => { const el = new Element('button'); el.dataset.view = view; return el; });
const ctx = {
  URL, config: { isGuest: true },
  $: id => ids[id],
  root: { querySelector: () => main, querySelectorAll: selector => selector === 'main > section' ? main.children : nav },
  node: (tag, text) => new Element(tag, text), error() {}, tagGrid: tags => new Element('tags', JSON.stringify(tags)),
  api() { throw new Error('Demo must not call server API'); },
};
vm.createContext(ctx);
vm.runInContext(extract('demoTags') + '\n' + extract('showDemo'), ctx);
const start = source.indexOf('  async function setView(');
vm.runInContext(source.slice(start, source.indexOf('\n  }', start) + 4), ctx);
function descendants(el) { return [el, ...el.children.flatMap(descendants)]; }
(async () => {
  for (const view of ['register', 'decode', 'analytics', 'settings', 'register']) {
    await ctx.setView(view);
    assert.equal(ids['view-demo'].hidden, false);
    assert.equal(ids['view-generator'].hidden, true);
    assert.equal(ids['demo-title'].focused, true);
    assert.ok(descendants(ids['view-demo']).some(el => String(el.textContent).includes('Все примеры вымышлены')));
    assert.equal(main.children.filter(el => el.id === 'view-demo').length, 1);
  }
  await ctx.setView('decode');
  const elements = descendants(ids['view-demo']);
  const input = elements.find(el => el.tag === 'input');
  const form = elements.find(el => el.tag === 'form');
  input.value = 'https://example.com/?utm_source=%3Cscript%3E&other=hidden';
  form.listeners.submit({ preventDefault() {} });
  const tags = descendants(ids['view-demo']).find(el => el.tag === 'tags');
  assert.deepEqual(JSON.parse(tags.textContent), { utm_source: '<script>' });
  assert.throws(() => ctx.demoTags('javascript:alert(1)'));
  assert.throws(() => ctx.demoTags('not-a-url'));
  assert.equal(Object.keys(ctx.demoTags('https://example.com/')).length, 0);
  console.log('PASS: four guest demo views, repeat navigation, local UTM decoding and no server API calls');
})().catch(error => { console.error(error); process.exitCode = 1; });
