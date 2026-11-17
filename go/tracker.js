(() => {
  'use strict';
  // `tracking` is encoded by the server with JSON_HEX_* and a CSP nonce.
  try {
    const signals = {};
    try {
      const screen = window.screen || {};
      Object.assign(signals, {
        width: screen.width || 0, height: screen.height || 0,
        dpr: window.devicePixelRatio || 0, depth: screen.colorDepth || 0,
        touch: navigator.maxTouchPoints || 0, cores: navigator.hardwareConcurrency || 0,
        memory: navigator.deviceMemory || 0, language: navigator.language || '',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
      });
    } catch { /* Restricted APIs must never prevent navigation. */ }
    const body = JSON.stringify({ ticket: tracking.ticket, signals });
    let queued = false;
    try { queued = typeof navigator.sendBeacon === 'function' && navigator.sendBeacon('/collect', new Blob([body], { type: 'application/json' })); } catch {}
    if (!queued && typeof fetch === 'function') {
      try { fetch('/collect', { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', keepalive: true }).catch(() => {}); } catch {}
    }
  } finally {
    window.location.replace(tracking.target);
  }
})();
