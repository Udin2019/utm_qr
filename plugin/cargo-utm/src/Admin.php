<?php
declare(strict_types=1);
namespace CargoUTM;
final class Admin {
    private Store $store;
    public function __construct() { $this->store=new Store(); }
    public function boot(): void {
        (new Workspace())->boot();
        add_action('admin_menu',function() {
            add_menu_page('UTM и QR','UTM и QR','cargo_view','cargo-utm',[$this,'page'],'dashicons-admin-links',25);
        });
        add_action('admin_enqueue_scripts',function() {
            if (($_GET['page']??'')!=='cargo-utm') return;
            $modern=!in_array($_GET['tab']??'',['settings','analytics','legacy'],true);
            $base=dirname(__DIR__).'/cargo-utm.php';
            if ($modern) {
                wp_enqueue_style('cargo-workspace',plugins_url('assets/ui/app.css',$base),[], (string)filemtime(dirname(__DIR__).'/assets/ui/app.css'));
                wp_enqueue_script('cargo-workspace',plugins_url('assets/ui/app.js',$base),[], (string)filemtime(dirname(__DIR__).'/assets/ui/app.js'),true);
            } else {
                wp_enqueue_style('cargo-utm',plugins_url('assets/admin.css',$base),[], '0.2.0');
                wp_enqueue_script('cargo-utm',plugins_url('assets/admin.js',$base),[], '0.2.0',true);
            }
        });
        add_action('admin_post_cargo_utm',[$this,'post']);
        add_action('admin_post_cargo_download',[$this,'download']);
        add_action('admin_post_cargo_csv',[$this,'csv']);
    }
    private function cap(string $cap): void { if (!current_user_can($cap)) wp_die('Недостаточно прав.', 'Доступ запрещён',['response'=>403]); }
    private static function e(mixed $v): string { return esc_html((string)$v); }
    private function link(array $args=[]): string { return add_query_arg(['page'=>'cargo-utm']+$args,admin_url('admin.php')); }
    private function all(string $table): array { global $wpdb; return $wpdb->get_results('SELECT * FROM '.$this->store->table($table).' ORDER BY id DESC',ARRAY_A) ?: []; }
    private function form(string $op): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('cargo_'.$op);
        echo '<input type="hidden" name="action" value="cargo_utm"><input type="hidden" name="op" value="'.esc_attr($op).'">';
    }
    private function input(string $name,string $label,mixed $value='',string $type='text',bool $required=false): void {
        echo '<label class="cargo-field">'.self::e($label).'<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" '.($required?'required':'').'></label>';
    }
    private function select(string $name,string $label,array $options,mixed $selected='',array $attrs=[]): void {
        echo '<label class="cargo-field">'.self::e($label).'<select name="'.esc_attr($name).'"';
        foreach ($attrs as $k=>$v) echo ' '.esc_attr($k).'="'.esc_attr($v).'"';
        echo '>';
        foreach ($options as $value=>$title) echo '<option value="'.esc_attr((string)$value).'" '.selected((string)$selected,(string)$value,false).'>'.self::e($title).'</option>';
        echo '</select></label>';
    }
    public function post(): void {
        $op=sanitize_key($_POST['op']??'');
        if ($op==='issue' && isset($_POST['preview'])) $op='preview';
        $this->cap(in_array($op,['issue','campaign','preview'],true)?'cargo_create':'cargo_manage');
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_die('Требуется POST.','Метод',['response'=>405]);
        check_admin_referer('cargo_'.($op==='preview'?'issue':$op));
        $in=wp_unslash($_POST);
        try {
            if ($op==='issue') $redirect=$this->link(['view'=>$this->store->issue($in)]);
            elseif ($op==='preview') {
                $p=$this->store->prepare($in,'000000000000');
                $this->preview($p,$in); return;
            } elseif ($op==='campaign') { $this->store->campaign($in); $redirect=$this->link(['tab'=>'new','saved'=>1]); }
            elseif ($op==='change') { $this->store->change((int)$in['id'],$in); $redirect=$this->link(['view'=>(int)$in['id']]); }
            elseif ($op==='unit') {
                $slug=Rules::identifier(trim($in['unit_slug']??''),80); $name=trim($in['name']??'');
                if (!$name || mb_strlen($name)>200) throw new \InvalidArgumentException('Укажите название юнита.');
                $this->store->transaction(function() use ($slug,$name) { $id=$this->store->insert('units',['unit_slug'=>$slug,'name'=>$name]); $this->store->audit(null,'unit_create',[],['id'=>$id,'slug'=>$slug,'name'=>$name]); });
                $redirect=$this->link(['tab'=>'settings']);
            } elseif ($op==='brand') { $this->brand($in); $redirect=$this->link(['tab'=>'settings']); }
            elseif ($op==='template') { $this->template($in); $redirect=$this->link(['tab'=>'settings']); }
            else throw new \InvalidArgumentException('Неизвестная операция.');
            wp_safe_redirect($redirect); exit;
        } catch (\Throwable $e) {
            status_header($e instanceof \DomainException?409:400);
            require_once ABSPATH.'wp-admin/admin-header.php';
            echo '<div class="wrap"><h1>Операция не выполнена</h1><div class="notice notice-error"><p>'.self::e($e->getMessage()).'</p></div>';
            if (in_array($op,['issue','preview'],true)) $this->newForm($in);
            else echo '<p><a href="'.esc_url($this->link()).'">Вернуться в реестр</a>. Данные формы можно исправить, вернувшись назад в браузере.</p>';
            echo '</div>'; require_once ABSPATH.'wp-admin/admin-footer.php';
        }
    }
    private function preview(array $p,array $in): void {
        require_once ABSPATH.'wp-admin/admin-header.php';
        echo '<div class="wrap"><h1>Предпросмотр</h1><p>Офлайн-код 000000000000 — пример. Настоящий код и utm_content будут присвоены после выпуска.</p><p class="cargo-url">'.self::e($p['result']['url']).'</p>';
        if ($p['result']['removed']) echo '<p>Будут заменены входные метки: '.self::e(implode(', ',$p['result']['removed'])).'</p>';
        $this->newForm($in); echo '</div>'; require_once ABSPATH.'wp-admin/admin-footer.php';
    }
    public function page(): void {
        $this->cap('cargo_view');
        if (!in_array($_GET['tab']??'',['settings','analytics','legacy'],true)) { (new Workspace())->render(); return; }
        echo '<div class="wrap cargo-wrap"><h1>Каргономика · UTM и QR</h1>';
        if (!Config::production()) echo '<div class="notice notice-warning inline"><p><strong>Тестовый стенд.</strong> Ссылки и условные знаки не предназначены для рабочего тиража.</p></div>';
        echo '<nav class="nav-tab-wrapper">';
        foreach (['registry'=>'Реестр','new'=>'Создать','decode'=>'Расшифровка','analytics'=>'Переходы · UTC','settings'=>'Справочники'] as $tab=>$label) {
            if ($tab==='new' && !current_user_can('cargo_create') || $tab==='settings' && !current_user_can('cargo_manage')) continue;
            echo '<a class="nav-tab" href="'.esc_url($this->link(['tab'=>$tab])).'">'.self::e($label).'</a>';
        }
        echo '</nav>';
        try {
            if (!empty($_GET['view'])) $this->card((int)$_GET['view']);
            else switch ($_GET['tab']??'registry') {
                case 'new': $this->cap('cargo_create'); $copy=[]; if (!empty($_GET['duplicate'])) { $row=$this->store->row('placements',(int)$_GET['duplicate']); $copy=$row; $copy['url']=$row['original_url']??''; $copy['details']=json_decode($row['details_json']??'{}',true); unset($copy['idempotency_key']); } $this->newForm($copy); break;
                case 'decode': $this->decode(); break;
                case 'analytics': $this->analytics(); break;
                case 'settings': $this->cap('cargo_manage'); $this->settings(); break;
                default: $this->registry();
            }
        } catch (\Throwable $e) { echo '<div class="notice notice-error"><p>'.self::e($e->getMessage()).'</p></div>'; }
        echo '</div>';
    }
    private function newForm(array $in=[]): void {
        $units=[]; foreach ($this->all('units') as $u) if ($u['active']) $units[$u['id']]=$u['name'];
        $campaigns=[]; foreach ($this->all('campaigns') as $c) if ($c['status']==='active') $campaigns[$c['id']]=$c['name'].' · '.$c['year'].' · '.$c['utm_campaign'];
        $brands=[]; foreach ($this->all('brand_versions') as $b) if ($b['active']) $brands[$b['id']]=$b['name'].' v'.$b['version'].' · '.($b['unit_id']?($units[$b['unit_id']]??'Юнит'):'Общий бренд');
        echo '<h2>Новое размещение</h2><p>Заполните поля, проверьте метки и выпустите ссылку. Для офлайна метки носителя формируются автоматически.</p>';
        $this->form('issue');
        echo '<input type="hidden" name="idempotency_key" value="'.esc_attr($in['idempotency_key']??bin2hex(random_bytes(16))).'">';
        echo '<div class="cargo-grid">';
        $this->select('unit_id','Бизнес-юнит',$units,$in['unit_id']??'',['id'=>'cargo-unit']);
        $campaignUnits=[]; foreach ($this->all('campaigns') as $c) $campaignUnits[$c['id']]=$c['unit_id'];
        $this->select('campaign_id','Кампания',$campaigns,$in['campaign_id']??'',['id'=>'cargo-campaign','data-units'=>wp_json_encode($campaignUnits)]);
        $this->input('url','Целевой HTTPS URL',$in['url']??'','url',true);
        $this->select('type','Тип размещения',Rules::TYPES,$in['type']??'online',['id'=>'cargo-type']);
        $brandUnits=[]; foreach ($this->all('brand_versions') as $b) $brandUnits[$b['id']]=$b['unit_id'];
        $this->select('brand_id','Профиль бренда',$brands,$in['brand_id']??'',['id'=>'cargo-brand','data-units'=>wp_json_encode($brandUnits)]);
        echo '</div><div id="cargo-online"><h3>Онлайн-метки</h3>';
        $presets=[''=>'Вручную']; $presetData=[];
        foreach ($this->all('template_versions') as $t) if ($t['active'] && !isset(Rules::TYPES[$t['template_code']])) { $data=json_decode($t['settings_json'],true); $presets[$t['template_code']]=$data['source'].' / '.$data['medium']; $presetData[$t['template_code']]=$data; }
        $this->select('preset','Пресет канала',$presets,'',['id'=>'cargo-preset','data-presets'=>wp_json_encode($presetData)]);
        echo '<div class="cargo-grid">';
        foreach (['utm_source','utm_medium','utm_content','utm_term'] as $field) $this->input($field,$field,$in[$field]??'');
        echo '</div></div><div id="cargo-offline"><h3>Параметры носителя</h3><p id="cargo-hint"></p><div class="cargo-grid">';
        foreach (Rules::LABELS as $field=>$label) {
            echo '<div data-cargo-detail="'.esc_attr($field).'">';
            $this->input('details['.$field.']',$label,$in['details'][$field]??'',$field==='event_date'?'date':'text'); echo '</div>';
        }
        echo '</div>';
        $this->input('place_slug','Код места (до 80 знаков; можно уточнить)',$in['place_slug']??'');
        echo '<p>Пример: entrance-a12. Итоговый utm_content: код-места-p-короткий-код.</p></div>';
        echo '<script type="application/json" id="cargo-rules">'.wp_json_encode(['visible'=>Rules::VISIBLE,'required'=>Rules::REQUIRED],JSON_HEX_TAG|JSON_HEX_AMP).'</script>';
        echo '<p><button class="button" name="preview" value="1" type="submit" data-cargo-preview>Предпросмотр UTM</button> <button class="button button-primary" type="submit">Выпустить ссылку и QR</button></p></form>';
        echo '<details><summary>Создать кампанию</summary>'; $this->form('campaign'); echo '<div class="cargo-grid">';
        $unitSlugs=[]; foreach ($this->all('units') as $u) $unitSlugs[$u['id']]=$u['unit_slug'];
        $this->select('unit_id','Бизнес-юнит',$units,'',['data-unit-slugs'=>wp_json_encode($unitSlugs)]); $this->input('name','Русское название','','text',true); $this->input('year','Год',gmdate('Y'),'number',true);
        $this->input('campaign_code','Код UTM (пусто = автоматически)'); $this->input('starts_on','Начало','','date'); $this->input('ends_on','Конец','','date');
        echo '</div><p>Автоматический код: unit_slug-год-slug_названия. При совпадении задайте другой код.</p><button class="button">Создать кампанию</button></form></details>';
    }
    private function filters(): array {
        global $wpdb; $where=['1=1']; $args=[];
        foreach (['unit_id'=>'p.unit_id','type'=>'p.type','city'=>'p.city','status'=>'p.status','medium'=>'p.utm_medium','campaign_id'=>'p.campaign_id'] as $name=>$col) {
            $value=trim((string)($_GET[$name]??''));
            if ($name==='status' && $value==='') $value='active';
            if ($value!=='' && $value!=='all') { $where[]="$col=%s"; $args[]=$value; }
        }
        foreach (['from'=>'>=','to'=>'<='] as $name=>$op) if (!empty($_GET[$name])) { $date=Rules::date((string)$_GET[$name]); $where[]="p.created_at $op %s"; $args[]=$date.($name==='from'?' 00:00:00':' 23:59:59'); }
        if (!empty($_GET['q'])) { $like='%'.$wpdb->esc_like((string)$_GET['q']).'%'; $where[]='(p.event LIKE %s OR c.name LIKE %s OR p.short_code LIKE %s OR p.target_url LIKE %s OR p.object_code LIKE %s)'; array_push($args,$like,$like,$like,$like,$like); }
        $sql=implode(' AND ',$where); return [$args?$wpdb->prepare($sql,$args):$sql,$args];
    }
    private function filterForm(string $tab): void {
        echo '<form method="get" class="cargo-filters"><input type="hidden" name="page" value="cargo-utm"><input type="hidden" name="tab" value="'.esc_attr($tab).'">';
        $this->input('q','Поиск',$_GET['q']??'');
        $units=['all'=>'Все']; foreach ($this->all('units') as $u) $units[$u['id']]=$u['name'];
        $campaigns=['all'=>'Все']; foreach ($this->all('campaigns') as $c) $campaigns[$c['id']]=$c['name'];
        $this->select('unit_id','Бизнес-юнит',$units,$_GET['unit_id']??'all');
        $this->select('campaign_id','Кампания',$campaigns,$_GET['campaign_id']??'all');
        $this->select('type','Носитель',['all'=>'Все']+Rules::TYPES,$_GET['type']??'all');
        $this->select('status','Статус',['active'=>'Активно','archived'=>'В архиве','revoked'=>'Отозвано','all'=>'Все'],$_GET['status']??'active');
        $this->input('city','Город',$_GET['city']??''); $this->input('medium','Канал (utm_medium)',$_GET['medium']??'');
        $this->input('from',$tab==='analytics'?'GET с (UTC)':'Создано с (UTC)',$_GET['from']??'','date'); $this->input('to','По (UTC)',$_GET['to']??'','date');
        echo '<button class="button">Применить</button></form>';
    }
    private function csvLink(string $kind): void {
        if (!current_user_can('cargo_export')) return;
        $args=array_intersect_key($_GET,array_flip(['q','unit_id','campaign_id','type','status','city','medium','from','to']));
        $url=wp_nonce_url(add_query_arg(['action'=>'cargo_csv','kind'=>$kind]+$args,admin_url('admin-post.php')),'cargo_csv');
        echo '<a class="button" href="'.esc_url($url).'">CSV · '.($kind==='hits'?'Переходы по дням (UTC)':'Размещения').'</a> ';
    }
    private function registry(): void {
        global $wpdb; $this->filterForm('registry'); $this->csvLink('placements'); $this->csvLink('hits');
        [$where]=$this->filters(); $page=max(1,(int)($_GET['paged']??1));
        $join=' FROM '.$this->store->table('placements').' p JOIN '.$this->store->table('campaigns').' c ON c.id=p.campaign_id JOIN '.$this->store->table('units').' u ON u.id=p.unit_id WHERE '.$where;
        $count=(int)$wpdb->get_var('SELECT COUNT(*)'.$join);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT p.*,c.name campaign_name,u.name unit_name'.$join.' ORDER BY p.id DESC LIMIT 25 OFFSET %d',($page-1)*25),ARRAY_A);
        echo '<table class="widefat striped"><thead><tr><th>ID / код</th><th>Кампания / юнит</th><th>Носитель / место</th><th>UTM</th><th>Статус / создано UTC</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td><a href="'.esc_url($this->link(['view'=>$r['id']])).'">#'.self::e($r['id']).' · '.self::e($r['short_code']).'</a></td><td>'.self::e($r['campaign_name']).'<br>'.self::e($r['unit_name']).'</td><td>'.self::e(Rules::TYPES[$r['type']]).'<br>'.self::e(implode(' · ',array_filter([$r['event'],$r['city'],$r['object_code']]))).'</td><td>'.self::e($r['utm_source'].' / '.$r['utm_medium']).'<br>'.self::e($r['utm_content']).'</td><td>'.self::e($r['status']).'<br>'.self::e($r['created_at']).'</td></tr>';
        echo '</tbody></table><p>Найдено: '.$count.'</p>';
        echo wp_kses_post(paginate_links(['base'=>add_query_arg('paged','%#%'),'total'=>(int)ceil($count/25),'current'=>$page]));
    }
    private function card(int $id): void {
        global $wpdb; $r=$this->store->row('placements',$id); if (!$r) throw new \InvalidArgumentException('Запись не найдена.');
        $brand=$this->store->row('brand_versions',(int)$r['brand_id']); $unit=$this->store->row('units',(int)$r['unit_id']);
        echo '<h2>Размещение #'.self::e($id).' · '.self::e($r['short_code']).'</h2><p>'.self::e($unit['name']).' · '.self::e($brand['name']).' v'.self::e($brand['version']).' · '.self::e($r['status']).'</p>';
        $links=['UTM-ссылка'=>$r['target_url']];
        if (Config::shortLinksEnabled()) $links['Короткая ссылка']=Config::short($r['short_code']);
        foreach ($links as $label=>$url) echo '<p><strong>'.self::e($label).'</strong></p><div class="cargo-copy"><input readonly value="'.esc_attr($url).'"><button type="button" class="button" data-copy>Копировать</button></div>';
        $file=fn($format,$size=0)=>wp_nonce_url(add_query_arg(['action'=>'cargo_download','id'=>$id,'format'=>$format,'size'=>$size],admin_url('admin-post.php')),'cargo_download_'.$id);
        echo '<img class="cargo-qr" alt="QR размещения" src="'.esc_url($file('preview')).'"><p><a class="button" href="'.esc_url($file('svg')).'">SVG</a> ';
        foreach ([1024,2048,4096] as $size) echo '<a class="button" href="'.esc_url($file('png',$size)).'">PNG '.$size.'</a> ';
        echo '</p><table class="widefat">';
        foreach (array_merge(array_intersect_key($r,array_flip(UrlBuilder::FIELDS)),['Правила'=>$r['rules_version'],'Шаблон ID'=>$r['template_id'],'Автор ID'=>$r['author_id'],'Создано UTC'=>$r['created_at']],json_decode($r['details_json'],true)) as $k=>$v) echo '<tr><th>'.self::e(Rules::LABELS[$k]??$k).'</th><td>'.self::e($v).'</td></tr>';
        echo '</table>';
        if (current_user_can('cargo_create')) echo '<p><a class="button" href="'.esc_url($this->link(['tab'=>'new','duplicate'=>$id])).'">Дублировать с новым кодом</a></p>';
        if (in_array($r['type'],['outdoor','transport'],true)) echo '<p class="notice notice-info inline">Для печати скачайте SVG, сохраните белое поле, добавьте призыв. Проверьте пробный макет на материале с iOS и Android. Для транспорта проверяйте безопасный сценарий с позиции пешехода.</p>';
        if (current_user_can('cargo_manage')) {
            echo '<h3>Изменить назначение или статус</h3>'; $this->form('change'); echo '<input type="hidden" name="id" value="'.$id.'">';
            $this->input('url','Новое назначение (UTM сохраняются)'); $this->input('reason','Причина изменения назначения');
            $this->select('status','Статус',['active'=>'Активно','archived'=>'В архиве','revoked'=>'Отозвано'],$r['status']); echo '<button class="button">Сохранить</button></form>';
        }
        echo '<h3>История изменений</h3><ul>';
        $audit=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->store->table('audit').' WHERE placement_id=%d ORDER BY id DESC',$id),ARRAY_A);
        foreach ($audit as $a) echo '<li>'.self::e($a['created_at'].' UTC · '.$a['action'].' · автор '.$a['author_id'].' · '.$a['reason']).'<details><summary>Данные</summary><pre>'.self::e($a['old_json'].' → '.$a['new_json']).'</pre></details></li>';
        echo '</ul>';
    }
    public function download(): void {
        $this->cap('cargo_view'); $id=(int)($_GET['id']??0); check_admin_referer('cargo_download_'.$id);
        try {
            $r=$this->store->row('placements',$id); if (!$r) { status_header(404); exit; }
            if (!hash_equals($r['qr_hash'],hash('sha256',$r['qr_svg']))) throw new \RuntimeException('Контрольная сумма QR не совпала.');
            $format=(string)($_GET['format']??''); $size=(int)($_GET['size']??0);
            if (!in_array($format,['svg','png','preview'],true)) throw new \InvalidArgumentException('Неизвестный формат.');
            $data=$format==='png'?Qr::png($r['qr_svg'],$size):$r['qr_svg'];
            $unit=$this->store->row('units',(int)$r['unit_id']);
            $name=(Config::production()?'':'test-').$unit['unit_slug'].'-'.$r['type'].'-'.$r['short_code'].($format==='png'?'-'.$size.'.png':'.svg');
            nocache_headers(); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
            header('Content-Type: '.($format==='png'?'image/png':'image/svg+xml'));
            header('Content-Disposition: '.($format==='preview'?'inline':'attachment').'; filename="'.$name.'"'); echo $data; exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'Ошибка экспорта',['response'=>400]); }
    }
    private function decode(): void {
        global $wpdb;
        echo '<h2>Расшифровка ссылки</h2><form method="get"><input type="hidden" name="page" value="cargo-utm"><input type="hidden" name="tab" value="decode">';
        $this->input('value','Короткий код, короткая ссылка или URL с UTM',$_GET['value']??''); echo '<button class="button">Найти</button></form>';
        $input=trim((string)($_GET['value']??'')); if ($input==='') return;
        $parsed=Decoder::parse($input,Config::hosts()[1]); $code=$parsed['code']; $utm=$parsed['utm']; $rows=[];
        if ($code) {
            $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->store->table('placements').' WHERE short_code=%s',$code),ARRAY_A);
            if ($row && (!$utm || !array_diff_assoc($utm,array_intersect_key($row,$utm)))) $rows[]=$row;
        }
        if (!$rows && $utm) {
            $where=[]; $args=[];
            foreach ($utm as $k=>$v) { $where[]="$k=%s"; $args[]=$v; }
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->store->table('placements').' WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 100',$args),ARRAY_A);
        }
        if (!$rows) echo '<p>В реестре не найдено.</p>';
        else foreach ($rows as $r) echo '<p><a href="'.esc_url($this->link(['view'=>$r['id']])).'">#'.self::e($r['id']).' · '.self::e($r['short_code'].' · '.$r['event'].' · '.$r['utm_campaign']).'</a></p>';
        if ($utm) echo '<pre>'.self::e(wp_json_encode($utm,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    }
    private function hitsQuery(): string {
        global $wpdb;
        $copy=$_GET; unset($_GET['from'],$_GET['to']);
        try { [$where]=$this->filters(); } finally { $_GET=$copy; }
        foreach (['from'=>'>=','to'=>'<='] as $k=>$op) if (!empty($_GET[$k])) $where.=$wpdb->prepare(" AND h.day_utc $op %s",Rules::date((string)$_GET[$k]));
        return ' FROM '.$this->store->table('daily_hits').' h JOIN '.$this->store->table('placements').' p ON p.id=h.placement_id JOIN '.$this->store->table('campaigns').' c ON c.id=p.campaign_id JOIN '.$this->store->table('units').' u ON u.id=p.unit_id WHERE '.$where;
    }
    private function analytics(): void {
        global $wpdb; echo '<h2>Переходы через короткую ссылку (GET)</h2><p>Суточная агрегация в UTC. Включает повторы, роботов и предпросмотры. Визиты и конверсии измеряются на лендинге.</p>';
        $this->filterForm('analytics'); $this->csvLink('hits');
        $page=max(1,(int)($_GET['paged']??1)); $query=$this->hitsQuery();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT h.*,p.short_code,c.name campaign,u.name unit'.$query.' ORDER BY h.day_utc DESC,h.placement_id DESC LIMIT 100 OFFSET %d',($page-1)*100),ARRAY_A);
        $count=(int)$wpdb->get_var('SELECT COUNT(*)'.$query);
        echo '<table class="widefat striped"><tr><th>Дата UTC</th><th>Размещение</th><th>Бизнес-юнит / кампания</th><th>GET</th></tr>';
        foreach ($rows as $r) echo '<tr><td>'.self::e($r['day_utc']).'</td><td><a href="'.esc_url($this->link(['view'=>$r['placement_id']])).'">'.self::e($r['short_code']).'</a></td><td>'.self::e($r['unit'].' / '.$r['campaign']).'</td><td>'.self::e($r['get_count']).'</td></tr>';
        echo '</table>'.wp_kses_post(paginate_links(['base'=>add_query_arg('paged','%#%'),'total'=>(int)ceil($count/100),'current'=>$page]));
    }
    public function csv(): void {
        $this->cap('cargo_export'); check_admin_referer('cargo_csv'); global $wpdb;
        try {
            $kind=(string)($_GET['kind']??'');
            if (!in_array($kind,['hits','placements'],true)) throw new \InvalidArgumentException('Неизвестная выгрузка.');
            if ($kind==='hits') {
                $columns=['placement_id'=>'ID размещения','day_utc'=>'Дата UTC','get_count'=>'Переходы через короткую ссылку (GET)'];
                $query='SELECT h.placement_id,h.day_utc,h.get_count'.$this->hitsQuery().' ORDER BY h.day_utc,h.placement_id';
            } else {
                [$where]=$this->filters();
                $columns=['id'=>'placement_id','unit_id'=>'ID бизнес-юнита','unit_name'=>'Бизнес-юнит','campaign_id'=>'ID кампании','campaign_name'=>'Кампания','type'=>'Тип носителя','event'=>'Событие','event_date'=>'Дата события','city'=>'Город','object_code'=>'Код объекта','details_json'=>'Параметры носителя JSON','original_url'=>'Целевой URL','target_url'=>'UTM URL','short_url'=>'Короткая ссылка','short_code'=>'Код','template_id'=>'ID версии шаблона','template_version'=>'Версия шаблона','brand_id'=>'ID профиля','brand_name'=>'Профиль бренда','brand_version'=>'Версия бренда','tracking_json'=>'Дополнительные параметры и версия пресета JSON','roistat'=>'roistat','rs'=>'rs','online_preset_id'=>'ID онлайн-пресета','online_preset_version'=>'Версия онлайн-пресета','rules_version'=>'Версия правил','status'=>'Статус','author_id'=>'Автор ID','created_at'=>'Создано UTC']+array_combine(UrlBuilder::FIELDS,UrlBuilder::FIELDS);
                if (!Config::shortLinksEnabled()) unset($columns['short_url']);
                $query='SELECT p.*,u.name unit_name,c.name campaign_name,t.version template_version,b.name brand_name,b.version brand_version FROM '.$this->store->table('placements').' p JOIN '.$this->store->table('units').' u ON u.id=p.unit_id JOIN '.$this->store->table('campaigns').' c ON c.id=p.campaign_id JOIN '.$this->store->table('template_versions').' t ON t.id=p.template_id JOIN '.$this->store->table('brand_versions').' b ON b.id=p.brand_id WHERE '.$where.' ORDER BY p.id';
            }
            nocache_headers(); header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="cargo-'.$kind.'.csv"'); header('X-Content-Type-Options: nosniff');
            $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,array_values($columns),';','"','');
            for ($offset=0;;$offset+=500) {
                $rows=$wpdb->get_results($wpdb->prepare($query.' LIMIT 500 OFFSET %d',$offset),ARRAY_A);
                if (!$rows) break;
                foreach ($rows as $r) {
                    if ($kind==='placements') {
                        $tracking=json_decode($r['tracking_json']??'{}',true)?:[];
                        $r['roistat']=$tracking['tags']['roistat']??''; $r['rs']=$tracking['tags']['rs']??'';
                        $r['online_preset_id']=$tracking['preset_id']??''; $r['online_preset_version']=$tracking['preset_version']??'';
                        $r['short_url']=Config::shortLinksEnabled()?Config::short($r['short_code']):''; $r['type']=Rules::TYPES[$r['type']]??$r['type']; $r['status']=['active'=>'Активно','archived'=>'В архиве','revoked'=>'Отозвано'][$r['status']]??$r['status']; }
                    fputcsv($out,array_map(fn($key)=>Rules::csvCell($r[$key]??''),array_keys($columns)),';','"','');
                }
                if (count($rows)<500) break;
            }
            fclose($out); exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'Ошибка CSV',['response'=>400]); }
    }
    private function brand(array $in): void {
        global $wpdb;
        $unit=(int)($in['unit_id']??0); if ($unit && !$this->store->row('units',$unit)) throw new \InvalidArgumentException('Юнит не найден.');
        $asset=json_decode((string)($in['asset_json']??''),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($asset)) throw new \InvalidArgumentException('Нужен объект JSON с viewBox и paths.');
        $json=wp_json_encode($asset,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $name=trim($in['name']??''); if (!$name || mb_strlen($name)>200) throw new \InvalidArgumentException('Укажите название профиля.');
        $row=['unit_id'=>$unit?:null,'name'=>$name,'color'=>trim($in['color']??''),'asset_json'=>$json,'asset_hash'=>hash('sha256',$json),'approved'=>empty($in['approved'])?0:1,'active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')];
        Qr::svg(Config::short('000000000000'),$row);
        $lock='cargo_brand_'.($unit?:'common');
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,5)',$lock))!==1) throw new \RuntimeException('Профиль занят. Повторите позже.');
        try {
            $this->store->transaction(function() use ($wpdb,$row,$unit) {
                $where=$unit?$wpdb->prepare('unit_id=%d',$unit):'unit_id IS NULL';
                $version=1+(int)$wpdb->get_var('SELECT MAX(version) FROM '.$this->store->table('brand_versions').' WHERE '.$where);
                if ($wpdb->query('UPDATE '.$this->store->table('brand_versions').' SET active=0 WHERE '.$where)===false) throw new \RuntimeException('Ошибка БД.');
                $id=$this->store->insert('brand_versions',$row+['version'=>$version]);
                $this->store->audit(null,'brand_publish',[],['id'=>$id,'version'=>$version]+$row);
            });
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }

    private function template(array $in): void {
        global $wpdb; $code=trim($in['template_code']??''); Rules::identifier($code,40);
        $settings=json_decode((string)($in['settings_json']??''),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($settings)) throw new \InvalidArgumentException('Нужен JSON-объект.');
        if (isset(Rules::TYPES[$code])) {
            if (($settings['required']??null)!==Rules::REQUIRED[$code] || ($settings['visible']??null)!==Rules::VISIBLE[$code] || empty($settings['label'])) throw new \InvalidArgumentException('Схема носителя должна соответствовать обязательным и видимым полям текущей версии правил.');
        } else {
            Rules::source((string)($settings['source']??'')); Rules::identifier((string)($settings['medium']??''),80);
            Tracking::validatePreset($settings);
        }
        $this->store->transaction(function() use ($wpdb,$code,$settings) {
            $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$this->store->table('template_versions').' WHERE template_code=%s FOR UPDATE',$code),ARRAY_A);
            $version=1; foreach ($rows as $r) $version=max($version,(int)$r['version']+1);
            if ($wpdb->update($this->store->table('template_versions'),['active'=>0],['template_code'=>$code])===false) throw new \RuntimeException('Ошибка БД.');
            $id=$this->store->insert('template_versions',['template_code'=>$code,'version'=>$version,'settings_json'=>wp_json_encode($settings),'created_at'=>gmdate('Y-m-d H:i:s')]);
            $this->store->audit(null,'template_publish',[],['id'=>$id,'code'=>$code,'version'=>$version,'settings'=>$settings]);
        });
    }
    private function settings(): void {
        echo '<h2>Справочники и версии</h2><p>Опубликованные версии сохраняются. Ранее выпущенные QR используют сохранённый SVG и прежние метки.</p><h3>Бизнес-юниты</h3><ul>';
        $units=['0'=>'Общий бренд']; foreach ($this->all('units') as $u) { $units[$u['id']]=$u['name']; echo '<li>'.self::e($u['name'].' · '.$u['unit_slug']).'</li>'; } echo '</ul>';
        $this->form('unit'); $this->input('name','Название'); $this->input('unit_slug','Неизменяемый код'); echo '<button class="button">Добавить юнит</button></form>';
        echo '<h3>Профили бренда</h3><ul>';
        foreach ($this->all('brand_versions') as $b) echo '<li>'.self::e('#'.$b['id'].' '.$b['name'].' v'.$b['version'].' · '.($b['active']?'Действует':'История').' · '.($b['approved']?'Утверждён':'Тестовый')).'</li>';
        echo '</ul>'; $this->form('brand'); $this->select('unit_id','Бизнес-юнит',$units); $this->input('name','Название профиля'); $this->input('color','HEX-цвет','#1265a8');
        echo '<label class="cargo-field">Проверенные векторные контуры JSON<textarea name="asset_json" rows="5" required placeholder="{&quot;viewBox&quot;:[0,0,100,100],&quot;paths&quot;:[&quot;M10 10H90V90H10Z&quot;]}"></textarea></label><label><input type="checkbox" name="approved" value="1"> Геометрия и цвет утверждены компанией</label><p><button class="button">Опубликовать новую версию</button></p></form>';
        echo '<h3>Шаблоны и онлайн-пресеты</h3>';
        foreach ($this->all('template_versions') as $t) echo '<details><summary>'.self::e($t['template_code'].' v'.$t['version'].' · '.($t['active']?'Действует':'История')).'</summary><pre>'.self::e($t['settings_json']).'</pre></details>';
        $this->form('template'); $this->input('template_code','Код шаблона / пресета');
        echo '<label class="cargo-field">Настройки JSON<textarea name="settings_json" rows="5" required></textarea></label><p>Для онлайн-пресета: {"source":"yandex","medium":"cpc"}. Для носителей скопируйте текущую схему; изменение набора полей требует обновления версии правил в коде.</p><button class="button">Опубликовать редакцию</button></form>';
    }
}
