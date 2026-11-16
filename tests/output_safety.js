'use strict';
// Exercise the actual text rendering helper. Browser CSP execution is manual QA.
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = readFileSync('plugin/cargo-utm/assets/ui/app.js', 'utf8');
const start = source.indexOf('  function node(');
const end = source.indexOf('\n', start);
const ctx = { document: { createElement: tag => ({ tag, set innerHTML(_) { throw new Error('Unsafe HTML sink'); } }) } };
vm.createContext(ctx); vm.runInContext(source.slice(start, end), ctx);
for (const payload of ['<img src=x onerror=alert(1)>', '</script><script>alert(1)</script>', '" onmouseover="alert(1)', "O'Reilly & Партнёры", "' OR 1=1 --"]) {
  const el = ctx.node('p', payload);
  assert.equal(el.textContent, payload);
}
for (const sink of ['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function(']) assert.equal(source.includes(sink), false, `Unsafe sink: ${sink}`);
console.log('PASS: XSS/SQL-shaped text uses textContent; no HTML/eval sinks in workspace');
