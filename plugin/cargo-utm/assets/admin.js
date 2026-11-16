(() => {
  'use strict';
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    const input = button.parentElement.querySelector('input');
    try { await navigator.clipboard.writeText(input.value); button.textContent = 'Скопировано'; }
    catch { input.select(); button.textContent = 'Нажмите Ctrl+C / ⌘C'; }
  });
  const type = document.querySelector('#cargo-type');
  if (!type) return;
  const form = type.closest('form');
  const rules = JSON.parse(document.querySelector('#cargo-rules').textContent);
  const place = form.elements.place_slug;
  const ru = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя';
  const latin = ['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya'];
  const slug = value => [...value.trim().toLowerCase()].map(c => ru.includes(c) ? latin[ru.indexOf(c)] : c).join('').replace(/[^a-z0-9_-]+/g, '-').replace(/[-_]+/g, '-').replace(/^-|-$/g, '');
  let edited = Boolean(place.value);
  place.addEventListener('input', () => { edited = true; });
  function update() {
    const online = type.value === 'online';
    document.querySelector('#cargo-online').hidden = !online;
    document.querySelector('#cargo-offline').hidden = online;
    document.querySelectorAll('[data-cargo-detail]').forEach(wrapper => {
      const name = wrapper.dataset.cargoDetail;
      const active = rules.visible[type.value].includes(name);
      wrapper.hidden = !active;
      wrapper.querySelector('input').disabled = !active;
      wrapper.querySelector('input').required = active && rules.required[type.value].includes(name);
    });
    form.elements.utm_source.required = online;
    form.elements.utm_medium.required = online;
    if (!online && !edited) {
      const detail = name => form.elements[`details[${name}]`]?.value || '';
      place.value = slug(`${detail('surface') || detail('zone') || type.value}-${detail('object_code')}`);
    }
    document.querySelector('#cargo-hint').textContent = online ? '' : `Обязательные поля отмечены проверкой формы. utm_source=${type.value}, utm_medium=qr; utm_term пуст.`;
  }
  form.addEventListener('input', event => { if (event.target.name.startsWith('details[')) update(); });
  type.addEventListener('change', () => { edited = false; update(); });
  const presets = document.querySelector('#cargo-preset');
  presets.addEventListener('change', () => {
    const preset = JSON.parse(presets.dataset.presets)[presets.value];
    if (preset) { form.elements.utm_source.value = preset.source; form.elements.utm_medium.value = preset.medium; }
  });
  form.addEventListener('submit', event => {
    if (event.submitter?.hasAttribute('data-cargo-preview')) {
      // A single nonce signs issue and preview; the server selects a read-only preview branch.
      form.querySelector('[name=preview]').value = '1';
    }
  });
  update();
})();
(() => {
  const unit = document.querySelector('#cargo-unit');
  if (!unit) return;
  function filterSelect(id, common) {
    const select = document.querySelector(id);
    const units = JSON.parse(select.dataset.units);
    for (const option of select.options) {
      const matches = String(units[option.value]) === unit.value || common && units[option.value] === null;
      option.disabled = !matches;
      option.hidden = !matches;
    }
    if (select.selectedOptions[0]?.disabled) select.value = [...select.options].find(o => !o.disabled)?.value || '';
    if (common) {
      const own = [...select.options].find(o => !o.disabled && String(units[o.value]) === unit.value);
      if (own) select.value = own.value;
    }
  }
  function update() { filterSelect('#cargo-campaign', false); filterSelect('#cargo-brand', true); }
  unit.addEventListener('change', update); update();
  const campaignUnit = document.querySelector('[data-unit-slugs]');
  if (!campaignUnit) return;
  const form = campaignUnit.closest('form');
  const code = form.elements.campaign_code;
  let edited = false;
  code.addEventListener('input', () => { edited = true; });
  const ru = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя';
  const latin = ['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya'];
  const slug = value => [...value.trim().toLowerCase()].map(c => ru.includes(c) ? latin[ru.indexOf(c)] : c).join('').replace(/[^a-z0-9_-]+/g, '-').replace(/[-_]+/g, '-').replace(/^-|-$/g, '');
  form.addEventListener('input', () => {
    if (!edited) code.value = `${JSON.parse(campaignUnit.dataset.unitSlugs)[campaignUnit.value]}-${form.elements.year.value}-${slug(form.elements.name.value)}`;
  });
})();
