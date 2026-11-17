'use strict';
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const script = readFileSync('go/tracker.js', 'utf8');
function run(mode) {
  const events = [];
  const ctx = {
    tracking: { target: 'https://landing.example/?utm_source=conference', ticket: 'signed-event' },
    window: { screen: { width: 1920, height: 1080, colorDepth: 24 }, devicePixelRatio: 2, location: { replace: value => events.push(['redirect', value]) } },
    navigator: { language: 'ru-RU', hardwareConcurrency: 8, maxTouchPoints: 0, sendBeacon: (path, blob) => { if (mode === 'throw') throw new Error('blocked'); events.push(['beacon', path, blob]); return mode === 'beacon'; } },
    Blob: class { constructor(parts) { this.payload = JSON.parse(parts[0]); } },
    fetch(path, opts) { events.push(['fetch', path, opts]); if (mode === 'fetch-throw') throw new Error('blocked'); return new Promise(() => {}); },
    Intl,
  };
  if (mode === 'restricted') Object.defineProperty(ctx.window, 'screen', { get() { throw new Error('restricted'); } });
  vm.runInNewContext(script, ctx, { timeout: 1000 });
  assert.equal(events.at(-1)[0], 'redirect');
  assert.equal(events.at(-1)[1], ctx.tracking.target);
  return events;
}
const beacon = run('beacon'); assert.equal(beacon.length, 2); assert.equal(beacon[0][2].payload.signals.width, 1920);
for (const mode of ['fallback', 'throw', 'fetch-throw', 'restricted']) {
  const events = run(mode); const fetch = events.find(event => event[0] === 'fetch');
  assert.equal(fetch[2].keepalive, true); assert.equal(fetch[2].credentials, 'same-origin');
}
assert.ok(!/setTimeout|setInterval|await\s|\.then\(/.test(script));
console.log('PASS: immediate navigation with queued beacon, unresolved fetch, blocked APIs and synchronous failures; no timers or response waits');
