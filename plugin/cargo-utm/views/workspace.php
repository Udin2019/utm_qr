
  <svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <symbol id="i-generator" viewBox="0 0 24 24"><path d="M9 7H6a4 4 0 0 0 0 8h3m6-8h3a4 4 0 0 1 0 8h-3m-7-4h8"/></symbol>
    <symbol id="i-register" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 9h16M10 9v11m0-6h10"/></symbol>
    <symbol id="i-decode" viewBox="0 0 24 24"><path d="M8 4H4v4m12-4h4v4m0 8v4h-4m-8 0H4v-4M3 12h18m-13 4h7"/></symbol>
    <symbol id="i-briefcase" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M3 13h18m-9-1v3"/></symbol>
    <symbol id="i-booth" viewBox="0 0 24 24"><path d="M4 8h16l-2-4H6L4 8Zm1 1v11m14-11v11M3 20h18M8 16h8v4M8 9v3m4-3v3m4-3v3"/></symbol>
    <symbol id="i-conference" viewBox="0 0 24 24"><path d="M5 4h14v9H5zM4 4h16M12 13v3m0 0-4 4m4-4 4 4M8 9h8"/></symbol>
    <symbol id="i-board" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="10" rx="1"/><path d="M8 14v6m8-6v6M6 20h12M7 8h10m-10 3h7"/></symbol>
    <symbol id="i-truck" viewBox="0 0 24 24"><path d="M2 6h12v11H2zm12 4h4l4 4v3h-8"/><circle cx="6" cy="18" r="2" fill="currentColor" stroke="none"/><circle cx="18" cy="18" r="2" fill="currentColor" stroke="none"/></symbol>
    <symbol id="i-print" viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M7 8h4v4H7zM14 8h3m-3 4h3M7 16h10"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M19 10c0 5-7 10-7 10S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/></symbol>
    <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M4 12h15m-6-6 6 6-6 6"/></symbol>
    <symbol id="i-chevron" viewBox="0 0 24 24"><path d="m9 6 6 6-6 6"/></symbol>
    <symbol id="i-down" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"/></symbol>
    <symbol id="i-copy" viewBox="0 0 24 24"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></symbol>
    <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m-4-4 4 4 4-4M5 16v4h14v-4"/></symbol>
    <symbol id="i-scan" viewBox="0 0 24 24"><path d="M9 4H4v5m11-5h5v5m0 6v5h-5m-6 0H4v-5"/><path d="M8 8h3v3H8zm5 0h3v3h-3zm-5 5h3v3H8zm5 0h3v3h-3z"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12 4 4 10-10"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 5 5"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 11v5m0-8v.1"/></symbol>
  </svg>

  <div class="shell">
    <aside class="sidebar" aria-label="Главное меню">
      <a class="brand" id="generator-home" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Каргономика — генератор на главной">
        <span class="brand-mark" aria-hidden="true"></span>
        <img class="brand-logo" src="<?php echo esc_url(plugins_url('assets/ui/cargonomica.svg', dirname(__DIR__).'/cargo-utm.php')); ?>" width="204" height="32" alt="">
      </a>
      <nav class="navigation" aria-label="Разделы">
        <button type="button" class="nav-item is-active" data-view="generator" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#i-generator"/></svg>Генератор</button>
        <button type="button" class="nav-item" data-view="register"><svg class="icon" aria-hidden="true"><use href="#i-register"/></svg>Реестр</button>
        <button type="button" class="nav-item" data-view="decode"><svg class="icon" aria-hidden="true"><use href="#i-decode"/></svg>Расшифровка</button>
      </nav>
      <div class="side-footer">
        <span class="workspace-mark"><svg class="icon" aria-hidden="true"><use href="#i-briefcase"/></svg></span>
        <span>Маркетинг<small>Рабочее пространство</small></span>
      </div>
    </aside>

    <div class="workspace">
      <header class="topbar">
        <div class="breadcrumbs"><span>Маркетинг</span><svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg><span id="breadcrumb-current">Генератор</span></div>
        <span class="environment"><span aria-hidden="true"></span>Тестовый стенд</span>
      </header>

      <main class="main"><p id="workspace-error" class="form-error" role="alert" hidden></p><noscript><p class="form-error">Для интерактивного интерфейса включите JavaScript.</p></noscript>
        <section id="view-generator" aria-labelledby="generator-title">
          <header class="page-heading">
            <h1 id="generator-title" tabindex="-1">Генератор UTM и QR</h1>
            <p>Создайте ссылку с метками и QR-код для вашего размещения.</p>
          </header>

          <div class="mode-tabs" role="group" aria-label="Канал размещения">
            <button class="mode-tab is-active" type="button" data-mode="offline" aria-pressed="true"><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg>Офлайн-размещение</button>
            <button class="mode-tab" type="button" data-mode="online" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-generator"/></svg>Онлайн-кампания</button>
          </div>

          <div class="generator-grid">
            <form class="card generator-form" id="generator-form" novalidate>
              <div class="card-body">
                <h2 id="placement-title">Параметры размещения</h2>
                <fieldset id="carrier-group">
                  <legend>Тип носителя</legend>
                  <div class="carrier-options">
                    <button type="button" class="carrier is-active" data-carrier="exhibition" aria-pressed="true"><svg class="icon" aria-hidden="true"><use href="#i-booth"/></svg><span>Выставка</span></button>
                    <button type="button" class="carrier" data-carrier="conference" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-conference"/></svg><span>Конференция</span></button>
                    <button type="button" class="carrier" data-carrier="outdoor" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-board"/></svg><span>Баннер</span></button>
                    <button type="button" class="carrier" data-carrier="transport" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-truck"/></svg><span>Транспорт</span></button>
                    <button type="button" class="carrier" data-carrier="offline_point" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg><span>Офлайн-точка</span></button>
                    <button type="button" class="carrier" data-carrier="print_media" aria-pressed="false"><svg class="icon" aria-hidden="true"><use href="#i-print"/></svg><span>Печатные издания</span></button>
                  </div>
                </fieldset>

                <div class="form-grid base-fields">
                  <label><span>Бизнес-юнит</span>
                    <select id="business" name="business">
                      <option value="">Загрузка…</option>
                    </select>
                  </label>
                  <label id="saved-campaign-field"><span>Кампания</span>
                    <select id="campaign" name="campaign_id" required><option value="">Выберите кампанию</option></select>
                    <button type="button" id="create-campaign" class="button text-button">Создать кампанию</button>
                  </label>
                  <label class="full"><span>Целевой URL</span><input id="destination" name="destination" type="url" inputmode="url" placeholder="https://…" autocomplete="url" spellcheck="false" maxlength="2048" required></label>
                </div>

                <details class="advanced-fields"><summary>Метка места / носителя</summary><label><span>Код места (необязательно)</span><input id="place-slug" name="place_slug" maxlength="80" pattern="[a-z0-9][a-z0-9_-]*" placeholder="stand или street-banner"><small class="field-help">Для разных QR одной выставки используйте одну кампанию, а здесь — stand, street-banner или свой номер. Если не заполнить, код места соберётся из полей носителя. Каждый QR также получает уникальный короткий код.</small></label></details>
                <div id="guest-campaign-fields" class="form-grid" hidden>
                  <label><span>Название кампании *</span><input id="guest-campaign-name" maxlength="200" placeholder="Название выставки или кампании"></label>
                  <label><span>Год кампании *</span><input id="guest-campaign-year" type="number" min="2000" max="2100"></label>
                  <label class="full"><span>Код кампании (необязательно)</span><input id="guest-campaign-code" maxlength="120" pattern="[a-z0-9][a-z0-9_-]*" placeholder="Пусто — автоматически"></label>
                  <p class="field-help full">Это черновик. После входа выберите существующую кампанию или создайте новую; тогда будут назначены постоянный ID и рабочий QR.</p>
                </div>
                <div class="form-divider"></div>
                <div class="detail-heading"><h3 id="detail-heading">Данные выставки</h3><span id="auto-label" title="Источник, канал и остальные метки собираются из полей формы"><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>UTM автоматически</span></div>
                <div id="detail-fields" class="form-grid"></div>
                <p class="field-note" id="carrier-note" hidden></p>
                <p class="form-error" id="form-error" role="alert" hidden></p>
              </div>
              <div class="card-footer form-actions">
                <button type="submit" class="button primary" id="save-placement">Сохранить и получить QR <svg class="icon" aria-hidden="true"><use href="#i-arrow"/></svg></button>
                <button type="button" class="button text-button" id="clear-form">Очистить</button>
                <span class="save-status" id="save-status" hidden><svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>В реестре</span>
              </div>
            </form>

            <aside class="card preview-card" aria-labelledby="preview-title">
              <div class="card-body">
                <div class="preview-heading"><h2 id="preview-title">Предпросмотр QR</h2><svg class="icon" aria-hidden="true"><use href="#i-scan"/></svg></div>
                <div class="qr-stage">
                  <img id="qr-image" class="qr-image" alt="QR сохранённого размещения" hidden>
                  <p id="qr-empty" class="qr-empty"><svg class="icon" aria-hidden="true"><use href="#i-scan"/></svg>QR появится после сохранения размещения</p>
                </div>
                <label class="profile-field"><span>Логотип в QR</span>
                  <select id="profile" name="profile" aria-label="Логотип в QR">
                    <option value="">Выберите профиль</option>
                  </select>
                </label>
                <div class="link-field">
                  <span id="short-label">Короткая ссылка <small id="release-label">после сохранения</small></span>
                  <div class="copy-field">
                    <input id="short-url" aria-labelledby="short-label" readonly spellcheck="false" hidden>
                    <button class="icon-button" id="copy-short" disabled type="button" aria-label="Скопировать короткую ссылку" title="Скопировать короткую ссылку"><svg class="icon" aria-hidden="true"><use href="#i-copy"/></svg></button>
                  </div>
                </div>
                <label class="profile-field"><span>Размер PNG</span><select id="png-size"><option value="1024">1024 px</option><option value="2048" selected>2048 px</option><option value="4096">4096 px</option></select></label>
                <div class="export-actions">
                  <button type="button" class="button outline" id="download-svg"><svg class="icon" aria-hidden="true"><use href="#i-download"/></svg>SVG<span class="format-description">Вектор</span></button>
                  <button type="button" class="button outline" id="download-png"><svg class="icon" aria-hidden="true"><use href="#i-download"/></svg>PNG<span class="format-description">2048 px</span></button>
                </div>
              </div>
              <details class="utm-details" id="utm-details">
                <summary><svg class="icon" aria-hidden="true"><use href="#i-generator"/></svg><span id="tracking-summary">UTM-ссылка и параметры</span><svg class="icon details-chevron" aria-hidden="true"><use href="#i-down"/></svg></summary>
                <div id="utm-output" class="utm-output"></div>
              </details>
            </aside>
          </div>
        </section>

        <section class="single-view" id="view-register" aria-labelledby="register-title" hidden>
          <header class="page-heading with-action">
            <div><h1 id="register-title" tabindex="-1">Реестр размещений</h1><p>Ссылки, QR-коды и UTM-метки для всех носителей.</p></div>
            <button type="button" id="new-placement" class="button primary"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>Новое размещение</button>
          </header>
          <div class="card registry-card">
            <div class="registry-tools">
              <label class="search-field"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg><input id="registry-search" type="search" aria-label="Поиск по реестру" placeholder="Кампания, город или код" autocomplete="off"></label>
              <select id="registry-filter" aria-label="Фильтр по носителю"><option value="">Все носители</option><option value="exhibition">Выставки</option><option value="conference">Конференции</option><option value="outdoor">Баннеры</option><option value="transport">Транспорт</option><option value="offline_point">Офлайн-точки</option><option value="print_media">Печатные издания</option><option value="online">Онлайн</option></select>
              <button type="button" class="button outline" id="export-csv"><svg class="icon" aria-hidden="true"><use href="#i-download"/></svg>CSV</button>
            </div>
            <details class="registry-extra"><summary>Дополнительные фильтры</summary><div class="form-grid">
<label><span>Бизнес-юнит</span><select id="filter-unit"></select></label>
<label><span>Кампания</span><select id="filter-campaign"></select></label>
<label><span>Статус</span><select id="filter-status"><option value="active">Активно</option><option value="archived">В архиве</option><option value="revoked">Отозвано</option><option value="all">Все</option></select></label>
<label><span>Город</span><input id="filter-city"></label>
<label><span>Канал (utm_medium)</span><input id="filter-medium"></label>
<label><span>Создано с (UTC)</span><input id="filter-from" type="date"></label>
<label><span>Создано по (UTC)</span><input id="filter-to" type="date"></label>
</div><button type="button" class="button outline" id="apply-filters">Применить</button></details>
<div class="table-wrap">
              <table>
                <thead><tr><th scope="col">Кампания</th><th scope="col">Носитель</th><th scope="col">Бизнес-юнит</th><th scope="col">Короткий код</th><th scope="col">Переходы¹</th><th scope="col"><span class="visually-hidden">Действия</span></th></tr></thead>
                <tbody id="registry-rows"></tbody>
              </table>
            </div>
            <div class="registry-empty" id="registry-empty" hidden>По этому запросу размещений нет.</div>
            <div class="card-footer registry-footer"><span id="registry-count"></span><div id="registry-pagination"></div><span>¹ Переходы через короткую ссылку (GET), даты в UTC</span></div>
          </div>
        </section>

        <section class="single-view" id="view-decode" aria-labelledby="decode-title" hidden>
          <header class="page-heading">
            <h1 id="decode-title" tabindex="-1">Расшифровка</h1>
            <p>Найдите размещение по короткому коду или посмотрите значения UTM в ссылке.</p>
          </header>
          <div class="card decode-card">
            <form id="decode-form" class="card-body decode-form">
              <label><span>Ссылка или код размещения</span><input id="decode-input" placeholder="https://go.cargo.example/r/… или UTM-ссылка" required spellcheck="false" autocomplete="off"></label>
              <button type="submit" class="button primary"><svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>Расшифровать</button>
            </form>
            <div id="decode-result" class="decode-result" aria-live="polite" hidden></div>
          </div>
        </section>
      </main>
    </div>
  </div>

  <dialog id="campaign-dialog" class="detail-dialog" aria-labelledby="campaign-title">
    <div class="dialog-header"><h2 id="campaign-title">Новая кампания</h2><button type="button" class="icon-button" id="close-campaign" aria-label="Закрыть">×</button></div>
    <form id="campaign-form" class="dialog-body form-grid">
      <label class="full"><span>Русское название</span><input name="name" required maxlength="200"></label>
      <label><span>Бизнес-юнит</span><select name="unit_id" required></select></label>
      <label><span>Год</span><input name="year" type="number" min="2000" max="2100" required></label>
      <label class="full"><span>Код кампании (необязательно)</span><input name="campaign_code" maxlength="120" pattern="[a-z0-9][a-z0-9_-]*" placeholder="Оставьте пустым — код создастся автоматически"><small class="field-help">Пусто — код из бизнес-юнита, года и названия. Свой код: строчные латинские буквы, цифры, дефис или подчёркивание; например expo-2027 или 123. Код кампании должен быть уникальным.</small></label>
      <label><span>Начало</span><input name="starts_on" type="date"></label><label><span>Конец</span><input name="ends_on" type="date"></label>
      <p id="campaign-error" role="alert" class="form-error full" hidden></p>
      <button class="button primary" type="submit">Создать кампанию</button>
    </form>
  </dialog>
  <dialog class="detail-dialog" id="record-dialog" aria-labelledby="record-heading">
    <div class="dialog-header"><h2 id="record-heading">Карточка размещения</h2><button type="button" class="icon-button" id="close-dialog" aria-label="Закрыть карточку"><svg class="icon" aria-hidden="true"><use href="#i-close"/></svg></button></div>
    <div id="record-body" class="dialog-body"></div>
  </dialog>
  <div id="toast" class="toast" role="status" aria-live="polite" hidden></div>
