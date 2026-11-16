<?php
declare(strict_types=1);
namespace CargoUTM;

/** Anonymous, read-only generator. Never issues codes or reads saved campaigns. */
final class PublicGenerator {
    public function boot(): void {
        add_action('wp_ajax_nopriv_cargo_public_generator',[$this,'api']);
        add_action('wp_ajax_cargo_public_generator',[$this,'api']);
    }
    public function api(): void {
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_send_json_error(['message'=>'Требуется POST.'],405);
        // The URL action is mandatory so the origin rate limiter cannot be
        // bypassed by moving the action solely into the POST body.
        if (($_GET['action']??'')!=='cargo_public_generator' || ($_SERVER['QUERY_STRING']??'')!=='action=cargo_public_generator') wp_send_json_error(['message'=>'Неверный маршрут.'],400);
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        $length=$_SERVER['CONTENT_LENGTH']??'0';
        if (!is_string($length) || !ctype_digit($length)) wp_send_json_error(['message'=>'Некорректный размер запроса.'],400);
        if (strlen($length)>8 || (int)$length>PublicInput::MAX_REQUEST_BYTES) wp_send_json_error(['message'=>'Слишком большой запрос.'],413);
        $contentType=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'application/x-www-form-urlencoded')[0]));
        if ($contentType!=='application/x-www-form-urlencoded') wp_send_json_error(['message'=>'Неподдерживаемый формат запроса.'],415);
        $origin=$_SERVER['HTTP_ORIGIN']??null;
        $expectedOrigin=rtrim(Config::env('CARGO_PUBLIC_UTM_URL','https://'.Config::hosts()[0]),'/');
        if (($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site' || ($origin!==null && $origin!==$expectedOrigin)) wp_send_json_error(['message'=>'Запрос с другого сайта запрещён.'],403);
        if (!is_string($_POST['nonce']??null) || strlen($_POST['nonce'])>128) wp_send_json_error(['message'=>'Обновите страницу.'],403);
        check_ajax_referer('cargo_public_generator','nonce');
        $op=$_POST['op']??null;
        if (!is_string($op) || !in_array($op,['bootstrap','preview'],true)) wp_send_json_error(['message'=>'Для этой операции войдите в сервис.'],403);
        $payload=$_POST['payload']??'{}';
        if (!is_string($payload)) wp_send_json_error(['message'=>'Некорректная форма.'],400);
        if (strlen($payload)>PublicInput::MAX_JSON_BYTES) wp_send_json_error(['message'=>'Слишком большая форма.'],413);
        try {
            $object=json_decode(wp_unslash($payload),false,16,JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) throw new \InvalidArgumentException('Форма должна быть объектом.');
            if ($op==='bootstrap' && get_object_vars($object)!==[]) throw new \InvalidArgumentException('Каталог не принимает поля формы.');
            wp_send_json_success($op==='bootstrap'?$this->catalog():$this->preview(PublicInput::preview($object)));
        } catch (\JsonException|\InvalidArgumentException $e) {
            wp_send_json_error(['message'=>$e instanceof \JsonException?'Некорректный JSON формы.':$e->getMessage()],400);
        } catch (\Throwable $e) {
            error_log('cargo_public_preview failed');
            wp_send_json_error(['message'=>'Предпросмотр временно недоступен.'],503);
        }
    }
    public function catalog(): array {
        global $wpdb; $store=new Store();
        $units=$wpdb->get_results('SELECT id,unit_slug,name FROM '.$store->table('units').' WHERE active=1 ORDER BY id',ARRAY_A);
        // Public form choices only: no saved campaigns, placements, authors,
        // statistics, profile assets, audit, or authenticated download URLs.
        $brands=[['id'=>'corporate','unit_id'=>null,'name'=>'Каргономика']];
        foreach ($units as $unit) $brands[]=['id'=>'unit-'.$unit['id'],'unit_id'=>$unit['id'],'name'=>$unit['name']];
        $presets=[];
        $rows=$wpdb->get_results('SELECT template_code,version,settings_json FROM '.$store->table('template_versions').' WHERE active=1 ORDER BY version DESC',ARRAY_A);
        foreach ($rows as $row) {
            $key=$row['template_code'];
            if (isset(Rules::TYPES[$key]) || isset($presets[$key])) continue;
            $settings=json_decode($row['settings_json'],true,512,JSON_THROW_ON_ERROR);
            $presets[$key]=array_intersect_key($settings,array_flip(['label','source','medium','key','template','default']))+['version'=>(int)$row['version']];
        }
        return ['units'=>$units,'campaigns'=>[],'brands'=>$brands,'presets'=>$presets,'types'=>Rules::TYPES,'visible'=>Rules::VISIBLE,'required'=>Rules::REQUIRED,'labels'=>Rules::LABELS,'allowedHosts'=>explode(',',Config::env('CARGO_ALLOWED_HOSTS','fuel.cargo.example'))];
    }
    public function preview(array $in): array {
        $catalog=$this->catalog(); $unit=null;
        foreach ($catalog['units'] as $candidate) if ((int)$candidate['id']===(int)($in['unit_id']??0)) $unit=$candidate;
        if (!$unit) throw new \InvalidArgumentException('Выберите бизнес-юнит.');
        $name=trim((string)($in['campaign_name']??'')); $year=(int)($in['campaign_year']??0);
        if ($name==='' || mb_strlen($name)>200 || $year<2000 || $year>2100) throw new \InvalidArgumentException('Укажите название и год кампании.');
        $campaign=['utm_campaign'=>Rules::campaignCode((string)($in['campaign_code']??''),$unit['unit_slug'],$year,$name),'utm_id'=>''];
        $type=(string)($in['type']??'online'); $details=Rules::details($type,$in['details']??[]);
        $utm=Rules::utm($campaign,$type,$in,$details,'000000000000');
        $preset=null;
        if (!empty($in['preset'])) {
            $preset=$catalog['presets'][(string)$in['preset']]??null;
            if (!$preset) throw new \InvalidArgumentException('Пресет недоступен.');
        }
        $tracking=Tracking::fromInput($in,$preset);
        $result=Config::builder()->build(trim((string)($in['url']??'')),$utm,$tracking);
        return ['url'=>$result['url'],'removed'=>$result['removed'],'tags'=>$utm+$tracking,'provisional'=>true,'campaignIdPending'=>true];
    }
}
