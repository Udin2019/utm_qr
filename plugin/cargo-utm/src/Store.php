<?php
declare(strict_types=1);
namespace CargoUTM;
final class Store {
    public function table(string $name): string { global $wpdb; return $wpdb->prefix.'cargo_utm_'.$name; }
    public function insert(string $table,array $row): int {
        global $wpdb;
        if ($wpdb->insert($this->table($table),$row)===false) throw new \RuntimeException('Не удалось сохранить запись. Проверьте уникальность кодов и состояние БД.');
        return (int)$wpdb->insert_id;
    }
    public function row(string $table,int $id): array {
        global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table($table).' WHERE id=%d',$id),ARRAY_A) ?: [];
    }
    public function transaction(callable $fn): mixed {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION')===false) throw new \RuntimeException('БД недоступна.');
        try { $value=$fn(); if ($wpdb->query('COMMIT')===false) throw new \RuntimeException('Не удалось завершить транзакцию.'); return $value; }
        catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }
    public function audit(?int $id,string $action,array $old,array $new,string $reason=''): void {
        $this->insert('audit',['placement_id'=>$id,'action'=>$action,'author_id'=>get_current_user_id(),'reason'=>$reason,'old_json'=>wp_json_encode($old),'new_json'=>wp_json_encode($new),'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public function campaign(array $in): int {
        global $wpdb;
        $unit=$this->row('units',(int)($in['unit_id']??0));
        if (!$unit || !$unit['active']) throw new \InvalidArgumentException('Выберите активный бизнес-юнит.');
        $name=trim((string)($in['name']??'')); $year=(int)($in['year']??0);
        if ($name==='' || mb_strlen($name)>200 || $year<2000 || $year>2100) throw new \InvalidArgumentException('Укажите название и год кампании (2000–2100).');
        $code=Rules::campaignCode((string)($in['campaign_code']??''),$unit['unit_slug'],$year,$name);
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.$this->table('campaigns').' WHERE utm_campaign=%s',$code))) throw new \InvalidArgumentException('Код кампании занят. Уточните slug до сохранения.');
        $start=trim((string)($in['starts_on']??'')); $end=trim((string)($in['ends_on']??''));
        if ($start) Rules::date($start); if ($end) Rules::date($end);
        if ($start && $end && $start>$end) throw new \InvalidArgumentException('Конец кампании раньше начала.');
        return $this->transaction(function() use ($unit,$name,$year,$code,$start,$end) {
            $row=['unit_id'=>$unit['id'],'name'=>$name,'year'=>$year,'utm_campaign'=>$code,'utm_id'=>'cg-'.bin2hex(random_bytes(8)),'starts_on'=>$start?:null,'ends_on'=>$end?:null,'status'=>'active'];
            $id=$this->insert('campaigns',$row); $this->audit(null,'campaign_create',[],['id'=>$id]+$row); return $id;
        });
    }
    public function prepare(array $in,string $code): array {
        global $wpdb;
        $campaign=$this->row('campaigns',(int)($in['campaign_id']??0));
        if (!$campaign || $campaign['status']!=='active') throw new \InvalidArgumentException('Выберите активную кампанию.');
        $unit=$this->row('units',(int)$campaign['unit_id']);
        if (!$unit || !$unit['active']) throw new \InvalidArgumentException('Бизнес-юнит недоступен.');
        if ((int)($in['unit_id']??0)!==(int)$unit['id']) throw new \InvalidArgumentException('Кампания относится к другому бизнес-юниту.');
        $type=(string)($in['type']??''); $details=Rules::details($type,$in['details']??[]);
        $template=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table('template_versions').' WHERE template_code=%s AND active=1 ORDER BY version DESC LIMIT 1',$type),ARRAY_A);
        if (!$template) throw new \InvalidArgumentException('Нет активного шаблона.');
        $schema=json_decode($template['settings_json'],true,512,JSON_THROW_ON_ERROR);
        if (($schema['required']??[])!==Rules::REQUIRED[$type] || ($schema['visible']??[])!==Rules::VISIBLE[$type]) throw new \RuntimeException('Шаблон несовместим с текущей версией правил.');
        $utm=Rules::utm($campaign,$type,$in,$details,$code);
        $preset=null;
        if (!empty($in['preset'])) {
            $preset=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table('template_versions').' WHERE template_code=%s AND active=1 ORDER BY version DESC LIMIT 1',(string)$in['preset']),ARRAY_A);
            if (!$preset || isset(Rules::TYPES[$preset['template_code']])) throw new \InvalidArgumentException('Пресет недоступен.');
        }
        $tracking=Tracking::fromInput($in,$preset?json_decode($preset['settings_json'],true,512,JSON_THROW_ON_ERROR):null);
        $trackingSnapshot=['tags'=>$tracking,'preset_id'=>$preset?(int)$preset['id']:null,'preset_version'=>$preset?(int)$preset['version']:null];
        $result=Config::builder()->build(trim((string)($in['url']??'')),$utm,$tracking);
        return compact('campaign','unit','type','details','template','utm','trackingSnapshot','result');
    }
    public function issue(array $in): int {
        global $wpdb;
        $key=(string)($in['idempotency_key']??'');
        if (!preg_match('/^[a-f0-9]{32,64}$/D',$key)) throw new \InvalidArgumentException('Некорректный ключ формы.');
        $author=get_current_user_id();
        // Normalize only accepted fields; nonce and transport fields do not affect identity.
        $canonical=[];
        foreach (['campaign_id','unit_id','type','url','place_slug','utm_source','utm_medium','utm_content','utm_term','brand_id'] as $k) $canonical[$k]=(string)($in[$k]??'');
        $canonical['details']=Rules::details($canonical['type'],$in['details']??[]);
        // Preserve v1 idempotency hashes for forms without the new optional fields.
        if (!empty($in['use_roistat'])) {
            $canonical['tracking_input']=['enabled'=>true,'preset'=>(string)($in['preset']??''),'value'=>(string)($in['roistat_value']??'')];
        }
        $hash=hash('sha256',wp_json_encode($canonical));
        $find=fn()=>$wpdb->get_row($wpdb->prepare('SELECT id,request_hash FROM '.$this->table('placements').' WHERE author_id=%d AND idempotency_key=%s',$author,$key),ARRAY_A);
        $existing=$find();
        if ($existing) { if (!hash_equals($existing['request_hash'],$hash)) throw new \DomainException('Конфликт: ключ формы уже использован с другими данными.',409); return (int)$existing['id']; }
        for ($attempt=0;$attempt<4;$attempt++) {
            $code=bin2hex(random_bytes(6));
            try {
                return $this->transaction(function() use ($in,$author,$key,$hash,$code) {
                    $p=$this->prepare($in,$code);
                    $brand=$this->row('brand_versions',(int)($in['brand_id']??0));
                    if (!$brand || !$brand['active'] || ($brand['unit_id']!==null && (int)$brand['unit_id']!==(int)$p['unit']['id'])) throw new \InvalidArgumentException('Профиль бренда недоступен для бизнес-юнита.');
                    $svg=Qr::svg(Config::short($code),$brand);
                    $details=$p['details'];
                    $row=['campaign_id'=>$p['campaign']['id'],'unit_id'=>$p['unit']['id'],'type'=>$p['type'],'event'=>$details['event']??'','event_date'=>($details['event_date']??'')?:null,'city'=>$details['city']??'','object_code'=>$details['object_code']??'','details_json'=>wp_json_encode($details),'tracking_json'=>wp_json_encode($p['trackingSnapshot']),'original_url'=>trim($in['url']),'target_url'=>$p['result']['url'],'short_code'=>$code,'template_id'=>$p['template']['id'],'brand_id'=>$brand['id'],'rules_version'=>Rules::VERSION,'qr_svg'=>$svg,'qr_hash'=>hash('sha256',$svg),'status'=>'active','author_id'=>$author,'idempotency_key'=>$key,'request_hash'=>$hash,'created_at'=>gmdate('Y-m-d H:i:s')]+$p['utm'];
                    $id=$this->insert('placements',$row); $this->audit($id,'issue',[],array_diff_key($row,['qr_svg'=>true])); return $id;
                });
            } catch (\RuntimeException $e) {
                $existing=$find();
                if ($existing) { if (!hash_equals($existing['request_hash'],$hash)) throw new \DomainException('Конфликт данных формы.',409); return (int)$existing['id']; }
                $collision=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$this->table('placements').' WHERE short_code=%s',$code));
                if (!$collision) throw $e;
            }
        }
        throw new \RuntimeException('Не удалось выделить короткий код. Повторите позже.');
    }
    public function change(int $id,array $in): void {
        global $wpdb;
        $this->transaction(function() use ($wpdb,$id,$in) {
            $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$this->table('placements').' WHERE id=%d FOR UPDATE',$id),ARRAY_A);
            if (!$old) throw new \InvalidArgumentException('Запись не найдена.');
            $new=[]; $reason=trim((string)($in['reason']??''));
            if (isset($in['status'])) { if (!in_array($in['status'],['active','archived','revoked'],true)) throw new \InvalidArgumentException('Неверный статус.'); $new['status']=$in['status']; }
            if (!empty($in['url'])) {
                if ($reason==='' || mb_strlen($reason)>1000) throw new \InvalidArgumentException('Укажите причину изменения назначения (до 1000 знаков).');
                $new['original_url']=trim($in['url']);
                $snapshot=json_decode($old['tracking_json']??'{}',true,512,JSON_THROW_ON_ERROR);
                $new['target_url']=Config::builder()->build($new['original_url'],array_intersect_key($old,array_flip(UrlBuilder::FIELDS)),$snapshot['tags']??[])['url'];
            }
            if (!$new) return;
            if ($wpdb->update($this->table('placements'),$new,['id'=>$id])===false) throw new \RuntimeException('Не удалось обновить запись.');
            $this->audit($id,'change',array_intersect_key($old,$new),$new,$reason);
        });
    }
}
