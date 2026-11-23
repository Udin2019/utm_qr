<?php
/** @var bool $manager */
$plugin=dirname(__DIR__).'/cargo-utm.php';
$asset=static fn(string $name): string=>esc_url(add_query_arg('v',(string)filemtime(dirname(__DIR__).'/assets/ui/'.$name),plugins_url('assets/ui/'.$name,$plugin)));
$fields=['full_name'=>['Имя и фамилия','name','text',160],'company'=>['Компания','organization','text',160],
    'position'=>['Должность','organization-title','text',120],'phone'=>['Телефон','tel','tel',40],
    'email'=>['Электронная почта','email','email',254],'website'=>['Рабочий сайт','url','url',500],
    'description'=>['Описание','off','text',600],'comment'=>['Комментарий','off','text',600]];
$renderFields=static function(array $names) use ($fields): void {
    foreach ($names as $name) {
        [$label,$complete,$type,$limit]=$fields[$name];
        printf('<label>%s<input name="%s" autocomplete="%s" type="%s" inputmode="%s" maxlength="%d"%s></label>',
            esc_html($label),esc_attr($name),esc_attr($complete),esc_attr($type),esc_attr(in_array($type,['email','tel','url'],true)?$type:'text'),$limit,$name==='full_name'?' required':'');
    }
};
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo $manager?'Обмен визиток':'Визитка'; ?> · Каргономика</title>
<link rel="stylesheet" href="<?php echo $asset('app.css'); ?>">
<link rel="stylesheet" href="<?php echo $asset('cards.css'); ?>">
<script src="<?php echo $asset('cards.js'); ?>" defer></script>
</head>
<body class="cargo-standalone">
<div class="cargo-app bc-root" data-mode="<?php echo $manager?'manager':'guest'; ?>">
<script type="application/json" id="bc-config"><?php echo wp_json_encode($configuration,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
<?php if ($manager): ?>
<div class="shell bc-shell">
<aside class="sidebar bc-sidebar">
    <a class="brand" href="<?php echo esc_url(home_url('/')); ?>"><img class="brand-logo" src="<?php echo $asset('cargonomica.svg'); ?>" alt="Каргономика" width="204" height="32"></a>
    <nav class="navigation" aria-label="Главное меню">
        <a class="nav-item" href="<?php echo esc_url(home_url('/')); ?>">Генератор</a>
        <a class="nav-item" href="<?php echo esc_url(home_url('/?tab=registry')); ?>">Реестр</a>
        <a class="nav-item" href="<?php echo esc_url(home_url('/?tab=decode')); ?>">Расшифровка</a>
        <a class="nav-item" href="<?php echo esc_url(admin_url('admin.php?page=cargo-utm&tab=analytics')); ?>">Переходы · UTC</a>
        <?php if ($configuration['admin']): ?><a class="nav-item" href="<?php echo esc_url(admin_url('admin.php?page=cargo-utm&tab=settings')); ?>">Справочники</a><?php endif; ?>
        <a class="nav-item is-active" aria-current="page" href="<?php echo esc_url(home_url('/business-cards')); ?>">Обмен визиток</a>
    </nav>
</aside>
<div class="workspace">
<header class="topbar bc-topbar"><a href="<?php echo esc_url(home_url('/')); ?>">Каргономика</a><a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">Выйти</a></header>
<main class="main bc-main">
<h1>Обмен визиток</h1>
<nav class="bc-actions" aria-label="Разделы обмена">
    <button type="button" data-tab="exchange" aria-controls="bc-exchange" aria-pressed="true">Обмен</button>
    <button type="button" data-tab="profile" aria-controls="bc-profile" aria-pressed="false">Моя визитка</button>
    <button type="button" data-tab="leads" aria-controls="bc-leads" aria-pressed="false">Контакты</button>
    <button type="button" data-tab="stats" aria-controls="bc-stats" aria-pressed="false">История и аналитика</button>
</nav>
<?php if ($configuration['synthetic']): ?><p class="bc-warning">Тестовый режим. Используйте только вымышленные данные.</p>
<?php elseif (!$configuration['collection']): ?><p class="bc-warning">Приём контактов отключён до настройки утверждённых документов.</p><?php endif; ?>
<p id="bc-message" role="status"></p>
<section id="bc-exchange" class="bc-panel">
    <h2>QR для текущей встречи</h2>
    <p id="bc-owner"></p>
    <form method="post" id="bc-start"><label>Название встречи (необязательно, без персональных данных)<input name="meeting" maxlength="160"></label>
        <div class="bc-actions"><button type="submit" id="bc-start-button">Начать обмен</button><button type="button" data-tab="profile">Постоянный QR моей визитки</button></div>
    </form>
    <div id="bc-session" hidden>
        <p role="status" id="bc-state"></p><p id="bc-clock"></p><p id="bc-new-contact" hidden></p>
        <div id="bc-session-qr"></div>
        <div class="bc-actions"><button type="button" id="bc-cancel">Завершить</button><button type="button" id="bc-session-lead" hidden>Открыть новый контакт</button></div>
    </div>
</section>
<section id="bc-profile" class="bc-panel" hidden>
    <h2>Моя визитка</h2><p>Все поля этой формы, кроме переключателя, видны по публичной ссылке.</p>
    <form method="post" id="bc-profile-form">
        <?php $renderFields(array_keys(\CargoUTM\BusinessCardData::PROFILE)); ?>
        <label class="bc-check"><input type="checkbox" name="active" checked> Публичная визитка включена</label>
        <button type="submit">Сохранить визитку</button>
    </form>
    <div id="bc-permanent" hidden><h3>Мой постоянный QR</h3><div id="bc-permanent-qr"></div></div>
</section>
<section id="bc-leads" class="bc-panel" hidden>
    <h2>Полученные контакты</h2>
    <p>Номера и адреса почты введены клиентами и не подтверждены.</p>
    <form method="post" id="bc-filters">
        <label>Имя, телефон или компания<input name="search" maxlength="160" autocomplete="off"></label>
        <label>С даты (UTC)<input name="from" type="date"></label>
        <label>По дату (UTC)<input name="to" type="date"></label>
        <label>Источник<select name="source"><option value="">Все</option><option value="session">Личная встреча / QR</option><option value="permanent_card">Постоянная визитка</option></select></label>
        <div class="bc-actions"><button type="submit">Найти</button><?php if (current_user_can('cargo_export')): ?><button type="button" id="bc-csv">Выгрузить CSV</button><?php endif; ?></div>
    </form>
    <?php if ($configuration['admin']): ?><form method="post" id="bc-lead-lookup"><label>Номер контакта, в том числе ограниченного<input required name="id" type="number" min="1"></label><button type="submit">Открыть по номеру</button></form><?php endif; ?>
    <div id="bc-lead-detail" hidden></div>
    <ul id="bc-list" class="bc-list"></ul>
    <div class="bc-actions"><button type="button" id="bc-prev" hidden>Назад</button><button type="button" id="bc-next" hidden>Далее</button></div>
</section>
<section id="bc-stats" class="bc-panel" hidden><h2>История и аналитика</h2><p>Просмотры — открытия страницы, включая повторные. Группировка по источнику, встрече и менеджеру.</p><?php if ($configuration['admin']): ?><h3>Корпоративные визитки</h3><ul id="bc-company-cards" class="bc-list"></ul><?php endif; ?><ul id="bc-stats-list" class="bc-list"></ul></section>
</main>
</div>
</div>
<?php else: ?>
<main class="bc-guest bc-panel">
<img class="bc-logo" src="<?php echo $asset('cargonomica.svg'); ?>" alt="Каргономика" width="204" height="32">
<?php if (!$card): ?>
    <h1>Визитка недоступна</h1>
    <p>Ссылка могла истечь или быть отключена. Попросите менеджера показать новый QR.</p>
<?php else: ?>
    <h1><?php echo esc_html($profile['full_name']); ?></h1>
    <?php foreach (['position','company','phone','email','description'] as $field): ?>
        <?php if ($profile[$field]!==''): ?><p><?php echo esc_html($profile[$field]); ?></p><?php endif; ?>
    <?php endforeach; ?>
    <?php if ($profile['website']!==''): ?><p><a href="<?php echo esc_url($profile['website']); ?>" rel="noreferrer">Рабочий сайт</a></p><?php endif; ?>
    <div class="bc-actions">
        <a class="bc-button" href="<?php echo esc_url(home_url('/c/'.$key.'/vcard','https')); ?>">Сохранить визитку (.vcf)</a>
        <?php if ($documents && $state!=='completed'): ?><button type="button" id="bc-open" aria-expanded="false" aria-controls="bc-guest-form">Передать мой контакт</button><?php endif; ?>
    </div>
    <p>После скачивания откройте файл для импорта контакта на устройстве.</p>
    <?php if ($state==='completed'): ?><p>По этой ссылке контакт уже передан.</p>
    <?php elseif (!$documents): ?><p>Приём контактов сейчас недоступен.</p>
    <?php else: ?>
    <noscript><p>Чтобы передать свой контакт, включите JavaScript. Скачивание визитки доступно без него.</p></noscript>
    <?php if ($configuration['synthetic']): ?><p class="bc-warning">Тестовый режим. Используйте только вымышленные данные.</p><?php endif; ?>
    <form method="post" id="bc-guest-form" hidden>
        <h2>Ваш контакт</h2>
        <p>Проверьте поля перед отправкой. Укажите свой телефон или электронную почту. Если выбрали чужую карточку, замените данные своими.</p>
        <button type="button" id="bc-picker" hidden>Выбрать контакт из телефона</button>
        <?php $renderFields(array_keys(\CargoUTM\BusinessCardData::CONTACT)); ?>
        <label hidden>Оставьте пустым<input name="trap" autocomplete="off" tabindex="-1"></label>
        <p><?php echo esc_html($documents['operator']); ?></p>
        <?php if ($policy): ?><p><a href="<?php echo esc_url(home_url('/business-cards/privacy','https')); ?>">Политика обработки персональных данных</a></p><?php endif; ?>
        <details><summary>Открыть согласие на обработку персональных данных</summary><p class="bc-document"><?php echo esc_html($documents['pd']['text']); ?></p><p>Версия <?php echo esc_html($documents['pd']['version']); ?></p></details>
        <label class="bc-check"><input name="pd" type="checkbox" required> Согласен на обработку персональных данных</label>
        <details><summary>Открыть согласие на рекламные сообщения и звонки</summary><p class="bc-document"><?php echo esc_html($documents['marketing']['text']); ?></p><p>Версия <?php echo esc_html($documents['marketing']['version']); ?></p></details>
        <label class="bc-check"><input name="marketing" type="checkbox"> Согласен на рекламные сообщения и звонки (необязательно)</label>
        <p class="bc-document"><?php echo esc_html($documents['withdrawal']); ?></p>
        <button type="submit">Передать контакт</button>
    </form>
    <p id="bc-message" role="status"></p>
    <?php endif; ?>
<?php endif; ?>
</main>
<?php endif; ?>
</div>
</body>
</html>
