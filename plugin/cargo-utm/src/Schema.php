<?php
declare(strict_types=1);
namespace CargoUTM;
final class Schema {
    public const VERSION=2;
    public static function migrate(): void {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $p=$wpdb->prefix.'cargo_utm_'; $cc='ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        $tables=[
            'units'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, unit_slug varchar(80) NOT NULL, name varchar(200) NOT NULL, active tinyint NOT NULL DEFAULT 1, PRIMARY KEY  (id), UNIQUE KEY unit_slug (unit_slug)",
            'brand_versions'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, unit_id bigint unsigned DEFAULT NULL, version int NOT NULL, name varchar(200) NOT NULL, color varchar(7) NOT NULL, asset_json longtext NOT NULL, asset_hash char(64) NOT NULL, approved tinyint NOT NULL DEFAULT 0, active tinyint NOT NULL DEFAULT 1, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY unit_active (unit_id,active)",
            'template_versions'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, template_code varchar(40) NOT NULL, version int NOT NULL, settings_json longtext NOT NULL, active tinyint NOT NULL DEFAULT 1, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY code_version (template_code,version), KEY active_code (active,template_code)",
            'campaigns'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, unit_id bigint unsigned NOT NULL, name varchar(200) NOT NULL, year smallint NOT NULL, utm_campaign varchar(120) NOT NULL, utm_id varchar(19) NOT NULL, starts_on date DEFAULT NULL, ends_on date DEFAULT NULL, status varchar(16) NOT NULL DEFAULT 'active', PRIMARY KEY  (id), UNIQUE KEY utm_campaign (utm_campaign), UNIQUE KEY utm_id (utm_id), KEY unit_id (unit_id)",
            'placements'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, campaign_id bigint unsigned NOT NULL, unit_id bigint unsigned NOT NULL, type varchar(32) NOT NULL, event varchar(250) NOT NULL DEFAULT '', event_date date DEFAULT NULL, city varchar(250) NOT NULL DEFAULT '', object_code varchar(250) NOT NULL DEFAULT '', details_json longtext NOT NULL, tracking_json longtext DEFAULT NULL, original_url text NOT NULL, target_url text NOT NULL, short_code char(12) NOT NULL, utm_source varchar(80) NOT NULL, utm_medium varchar(80) NOT NULL, utm_campaign varchar(120) NOT NULL, utm_content varchar(160) NOT NULL DEFAULT '', utm_term varchar(160) NOT NULL DEFAULT '', utm_id varchar(19) NOT NULL, template_id bigint unsigned NOT NULL, brand_id bigint unsigned NOT NULL, rules_version int NOT NULL, qr_svg longtext NOT NULL, qr_hash char(64) NOT NULL, status varchar(16) NOT NULL DEFAULT 'active', author_id bigint unsigned NOT NULL, idempotency_key varchar(64) NOT NULL, request_hash char(64) NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY short_code (short_code), UNIQUE KEY author_idempotency (author_id,idempotency_key), KEY campaign_id (campaign_id), KEY unit_status (unit_id,status), KEY type_date (type,event_date), KEY city (city(100)), KEY object_code (object_code(100)), KEY created_at (created_at)",
            'daily_hits'=>"placement_id bigint unsigned NOT NULL, day_utc date NOT NULL, get_count bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (placement_id,day_utc), KEY day_utc (day_utc)",
            'audit'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, placement_id bigint unsigned DEFAULT NULL, action varchar(40) NOT NULL, author_id bigint unsigned NOT NULL, reason text NOT NULL, old_json longtext NOT NULL, new_json longtext NOT NULL, created_at datetime NOT NULL, PRIMARY KEY  (id), KEY placement_id (placement_id), KEY created_at (created_at)",
        ];
        foreach ($tables as $name=>$definition) {
            $definition=str_replace(', ',",\n",$definition);
            dbDelta("CREATE TABLE {$p}{$name} (\n{$definition}\n) {$cc};");
            $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$p.$name));
            if (strtoupper((string)$engine)!=='INNODB') throw new \RuntimeException('Таблица '.$name.' должна быть InnoDB.');
        }
        $indexes=$wpdb->get_results("SHOW INDEX FROM {$p}placements",ARRAY_A);
        $names=array_column($indexes,'Key_name');
        foreach (['short_code','author_idempotency'] as $index) if (!in_array($index,$names,true)) throw new \RuntimeException('Не создан индекс '.$index);
        $columns=$wpdb->get_col("SHOW COLUMNS FROM {$p}placements");
        if (!in_array('tracking_json',$columns,true)) throw new \RuntimeException('Не создан снимок tracking_json.');
        self::uiDefaults();
        update_option('cargo_utm_schema_version',self::VERSION,false);
        self::roles();
    }
    public static function uiDefaults(): void {
        global $wpdb;
        if ((int)get_option('cargo_utm_ui_defaults_version')>=2) return;
        $s=new Store();
        if (!(int)$wpdb->get_var('SELECT COUNT(*) FROM '.$s->table('units'))) return;
        $lock=$wpdb->prefix.'cargo_ui_v2';
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,10)',$lock))!==1) throw new \RuntimeException('Миграция интерфейса занята.');
        try {
            if ((int)get_option('cargo_utm_ui_defaults_version')>=2) return;
            $s->transaction(function() use ($wpdb,$s) {
                $defaults=Tracking::PRESETS;
                $defaults['print_media']=['label'=>Rules::TYPES['print_media'],'required'=>Rules::REQUIRED['print_media'],'visible'=>Rules::VISIBLE['print_media']];
                foreach ($defaults as $code=>$settings) {
                    $previous=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$s->table('template_versions').' WHERE template_code=%s ORDER BY version DESC LIMIT 1',$code),ARRAY_A);
                    $version=$previous?(int)$previous['version']+1:1;
                    if ($wpdb->update($s->table('template_versions'),['active'=>0],['template_code'=>$code])===false) throw new \RuntimeException('Ошибка обновления шаблона.');
                    $id=$s->insert('template_versions',['template_code'=>$code,'version'=>$version,'settings_json'=>wp_json_encode($settings),'created_at'=>gmdate('Y-m-d H:i:s')]);
                    $s->audit(null,'template_publish',[],['id'=>$id,'code'=>$code,'version'=>$version,'migration'=>2]);
                }
                if (!Config::production()) {
                    $asset=json_decode(file_get_contents(dirname(__DIR__).'/assets/ui/brand-profile.json'),true,512,JSON_THROW_ON_ERROR);
                    $json=wp_json_encode($asset,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
                    $profiles=$wpdb->get_results('SELECT * FROM '.$s->table('brand_versions')." WHERE active=1 AND approved=0 AND name='Условный тестовый знак'",ARRAY_A);
                    foreach ($profiles as $old) {
                        if ($wpdb->update($s->table('brand_versions'),['active'=>0],['id'=>$old['id']])===false) throw new \RuntimeException('Ошибка обновления бренда.');
                        $id=$s->insert('brand_versions',['unit_id'=>$old['unit_id'],'version'=>(int)$old['version']+1,'name'=>'Знак из UI-архива (тестовый)','color'=>'#20212b','asset_json'=>$json,'asset_hash'=>hash('sha256',$json),'approved'=>0,'active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
                        $s->audit(null,'brand_publish',['id'=>$old['id']],['id'=>$id,'migration'=>2]);
                    }
                }
                update_option('cargo_utm_ui_defaults_version',2,false);
            });
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
    }
    public static function roles(): void {
        add_role('cargo_viewer','UTM: просмотр',['read'=>true,'cargo_view'=>true,'cargo_export'=>true]);
        add_role('cargo_editor','UTM: сотрудник',['read'=>true,'cargo_view'=>true,'cargo_export'=>true,'cargo_create'=>true]);
        $admin=get_role('administrator');
        foreach (['cargo_view','cargo_export','cargo_create','cargo_manage'] as $cap) $admin?->add_cap($cap);
    }
    public static function seed(): void {
        global $wpdb; $s=new Store();
        if (Config::production()) throw new \RuntimeException('Тестовые данные запрещены в production.');
        $s->transaction(function() use ($wpdb,$s) {
            if ($wpdb->get_var('SELECT COUNT(*) FROM '.$s->table('units'))) return;
            $unitIds=[];
            foreach (['cargo-fuel'=>'Карго.Топливо','cargo'=>'Каргономика'] as $slug=>$name) $unitIds[]=$s->insert('units',['unit_slug'=>$slug,'name'=>$name]);
            $asset=json_encode(['viewBox'=>[0,0,100,100],'paths'=>['M10 10H90V30H30V70H90V90H10Z']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            foreach (array_merge([null],$unitIds) as $unit) $s->insert('brand_versions',['unit_id'=>$unit,'version'=>1,'name'=>'Условный тестовый знак','color'=>'#1265a8','asset_json'=>$asset,'asset_hash'=>hash('sha256',$asset),'approved'=>0,'active'=>1,'created_at'=>gmdate('Y-m-d H:i:s')]);
            foreach (Rules::TYPES as $code=>$label) $s->insert('template_versions',['template_code'=>$code,'version'=>1,'settings_json'=>wp_json_encode(['label'=>$label,'required'=>Rules::REQUIRED[$code],'visible'=>Rules::VISIBLE[$code]]),'created_at'=>gmdate('Y-m-d H:i:s')]);
            foreach (['yandex'=>['yandex','cpc'],'vk'=>['vk','paid_social'],'newsletter'=>['newsletter','email']] as $code=>$values) $s->insert('template_versions',['template_code'=>$code,'version'=>1,'settings_json'=>wp_json_encode(['source'=>$values[0],'medium'=>$values[1]]),'created_at'=>gmdate('Y-m-d H:i:s')]);
            $s->audit(null,'seed',[],['staging'=>true]);
        });
        self::uiDefaults();
    }
}
