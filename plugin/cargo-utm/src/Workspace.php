<?php
declare(strict_types=1);
namespace CargoUTM;

final class Workspace {
    private Store $store;
    public function __construct() { $this->store=new Store(); }
    public function boot(): void { add_action('wp_ajax_cargo_workspace',[$this,'api']); }
    public function render(bool $guest=false): void {
        $authBase=dirname(__DIR__).'/cargo-utm.php';
        wp_enqueue_script('cargo-email-login',plugins_url('assets/ui/auth.js',$authBase),[],(string)filemtime(dirname(__DIR__).'/assets/ui/auth.js'),true);
        $config=['emailLogin'=>['endpoint'=>admin_url('admin-ajax.php?action=cargo_email_login'),'nonce'=>wp_create_nonce('cargo_email_login'),'ready'=>LoginMail::ready()],'shortLinksEnabled'=>Config::shortLinksEnabled(),'isGuest'=>$guest,'endpoint'=>$guest?admin_url('admin-ajax.php?action=cargo_public_generator'):admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce($guest?'cargo_public_generator':'cargo_workspace'),'canCreate'=>current_user_can('cargo_create'),'canExport'=>current_user_can('cargo_export'),'canManage'=>current_user_can('cargo_manage'),'production'=>Config::production(),'initialView'=>($_GET['tab']??'')==='registry'?'register':(($_GET['tab']??'')==='decode'?'decode':'generator'),'initialRecord'=>max(0,(int)($_GET['view']??0)),'duplicate'=>max(0,(int)($_GET['duplicate']??0)),'links'=>['home'=>home_url('/'),'login'=>wp_login_url(home_url('/')),'logout'=>html_entity_decode(wp_logout_url(home_url('/')),ENT_QUOTES,'UTF-8'),'analytics'=>admin_url('admin.php?page=cargo-utm&tab=analytics'),'settings'=>admin_url('admin.php?page=cargo-utm&tab=settings')]];
        if (!$guest && ($config['canCreate'] || $config['canManage'])) $config['links']['cards']=home_url('/business-cards');
        if ($guest && ($_GET['login']??null)==='business-cards') $config['links']['loginTarget']=home_url('/business-cards');
        echo '<div class="cargo-app" id="cargo-app"><script type="application/json" id="cargo-config">'.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).'</script>';
        require dirname(__DIR__).'/views/workspace.php';
        echo '</div>';
    }
    public function api(): void {
        if (!current_user_can('cargo_view')) wp_send_json_error(['message'=>'Недостаточно прав.'],403);
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_send_json_error(['message'=>'Требуется POST.'],405);
        check_ajax_referer('cargo_workspace','nonce');
        $op=sanitize_key($_POST['op']??'');
        $caps=['bootstrap'=>'cargo_view','registry'=>'cargo_view','record'=>'cargo_view','decode'=>'cargo_view','preview'=>'cargo_create','issue'=>'cargo_create','campaign'=>'cargo_create','slug'=>'cargo_create','change'=>'cargo_manage'];
        if (!isset($caps[$op]) || !current_user_can($caps[$op])) wp_send_json_error(['message'=>'Операция недоступна.'],403);
        try {
            $in=json_decode(wp_unslash((string)($_POST['payload']??'{}')),true,32,JSON_THROW_ON_ERROR);
            if (!is_array($in)) throw new \InvalidArgumentException('Некорректная форма.');
            $data=match($op) {
                'bootstrap'=>$this->bootstrap(),
                'registry'=>$this->registry($in),
                'record'=>$this->record((int)($in['id']??0)),
                'decode'=>$this->decode((string)($in['value']??'')),
                'preview'=>$this->preview($in),
                'issue'=>$this->record($this->store->issue($in)),
                'campaign'=>$this->createCampaign($in),
                'slug'=>['slug'=>Rules::slug((string)($in['text']??''))],
                'change'=>$this->change($in),
            };
            wp_send_json_success($data);
        } catch (\JsonException|\InvalidArgumentException|\DomainException $e) {
            wp_send_json_error(['message'=>$e->getMessage()],$e instanceof \DomainException?409:400);
        } catch (\RuntimeException $e) {
            wp_send_json_error(['message'=>$e->getMessage()],400);
        } catch (\Throwable $e) {
            error_log('cargo_workspace failed');
            wp_send_json_error(['message'=>'Не удалось выполнить операцию. Проверьте данные и состояние сервиса.'],500);
        }
    }
    public function bootstrap(): array {
        global $wpdb;
        $units=$wpdb->get_results('SELECT id,unit_slug,name FROM '.$this->store->table('units').' WHERE active=1 ORDER BY id',ARRAY_A);
        $campaigns=$wpdb->get_results('SELECT id,unit_id,name,year,utm_campaign,utm_id FROM '.$this->store->table('campaigns')." WHERE status='active' ORDER BY id DESC",ARRAY_A);
        $brands=$wpdb->get_results('SELECT id,unit_id,name,version,approved FROM '.$this->store->table('brand_versions').' WHERE active=1 ORDER BY id DESC',ARRAY_A);
        $templates=$wpdb->get_results('SELECT id,template_code,version,settings_json FROM '.$this->store->table('template_versions').' WHERE active=1 ORDER BY version DESC,id DESC',ARRAY_A);
        $presets=[];
        foreach ($templates as $row) {
            if (isset(Rules::TYPES[$row['template_code']]) || isset($presets[$row['template_code']])) continue;
            $settings=json_decode($row['settings_json'],true,512,JSON_THROW_ON_ERROR);
            $presets[$row['template_code']]=$settings+['id'=>$row['id'],'version'=>$row['version']];
        }
        return compact('units','campaigns','brands','presets')+['types'=>Rules::TYPES,'visible'=>Rules::VISIBLE,'required'=>Rules::REQUIRED,'labels'=>Rules::LABELS,'allowedHosts'=>explode(',',Config::env('CARGO_ALLOWED_HOSTS','fuel.cargo.example'))];
    }
    private function createCampaign(array $in): array {
        $id=$this->store->campaign($in); return ['campaign'=>$this->store->row('campaigns',$id)];
    }
    private function preview(array $in): array {
        $p=$this->store->prepare($in,'000000000000');
        return ['url'=>$p['result']['url'],'removed'=>$p['result']['removed'],'tags'=>$p['utm']+($p['trackingSnapshot']['tags']??[]),'provisional'=>true];
    }
    private function change(array $in): array {
        $id=(int)($in['id']??0); $this->store->change($id,$in); return $this->record($id);
    }
    public function record(int $id): array {
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare('SELECT p.*,c.name campaign_name,u.name unit_name,u.unit_slug,b.name brand_name,b.version brand_version,t.version template_version FROM '.$this->store->table('placements').' p JOIN '.$this->store->table('campaigns').' c ON c.id=p.campaign_id JOIN '.$this->store->table('units').' u ON u.id=p.unit_id JOIN '.$this->store->table('brand_versions').' b ON b.id=p.brand_id JOIN '.$this->store->table('template_versions').' t ON t.id=p.template_id WHERE p.id=%d',$id),ARRAY_A);
        if (!$r) throw new \InvalidArgumentException('Размещение не найдено.');
        $keys=['id','campaign_id','unit_id','type','event','event_date','city','object_code','original_url','target_url','short_code','brand_id','brand_name','brand_version','template_id','template_version','rules_version','status','author_id','created_at','campaign_name','unit_name','unit_slug'];
        $out=array_intersect_key($r,array_flip($keys));
        $out['tags']=array_intersect_key($r,array_flip(UrlBuilder::FIELDS));
        $out['tracking']=json_decode($r['tracking_json']??'{}',true,512,JSON_THROW_ON_ERROR)?:[];
        $out['tags']+=($out['tracking']['tags']??[]);
        $out['details']=json_decode($r['details_json'],true,512,JSON_THROW_ON_ERROR);
        if (Config::shortLinksEnabled()) $out['short_url']=Config::short($r['short_code']);
        $out['hits']=(int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(get_count),0) FROM '.$this->store->table('daily_hits').' WHERE placement_id=%d',$id));
        foreach (['preview'=>0,'svg'=>0,'png'=>2048,'png1024'=>1024,'png4096'=>4096] as $format=>$size) {
            $out['downloads'][$format]=add_query_arg(['action'=>'cargo_download','id'=>$id,'format'=>$size?'png':$format,'size'=>$size,'_wpnonce'=>wp_create_nonce('cargo_download_'.$id)],admin_url('admin-post.php'));
        }
        $out['audit']=$wpdb->get_results($wpdb->prepare('SELECT action,author_id,reason,old_json,new_json,created_at FROM '.$this->store->table('audit').' WHERE placement_id=%d ORDER BY id DESC LIMIT 100',$id),ARRAY_A);
        return $out;
    }
    public function registry(array $in): array {
        global $wpdb;
        $where=['1=1']; $args=[]; $filter=[];
        foreach (['unit_id','campaign_id','type','city','status','utm_medium'] as $key) {
            $v=trim((string)($in[$key]??($key==='status'?'active':'')));
            if ($v!=='' && $v!=='all') { $where[]='p.'.$key.'=%s'; $args[]=$v; $filter[$key==='utm_medium'?'medium':$key]=$v; }
            elseif ($key==='status') $filter['status']='all';
        }
        foreach (['from'=>'>=','to'=>'<='] as $key=>$op) if (!empty($in[$key])) {
            $date=Rules::date((string)$in[$key]); $filter[$key]=$date;
            $where[]='p.created_at '.$op.' %s'; $args[]=$date.($key==='from'?' 00:00:00':' 23:59:59');
        }
        $q=trim((string)($in['q']??''));
        if ($q!=='') {
            $q=mb_substr($q,0,250); $filter['q']=$q; $like='%'.$wpdb->esc_like($q).'%';
            $where[]='(c.name LIKE %s OR p.event LIKE %s OR p.city LIKE %s OR p.object_code LIKE %s OR p.short_code LIKE %s OR p.target_url LIKE %s)';
            array_push($args,$like,$like,$like,$like,$like,$like);
        }
        $join=' FROM '.$this->store->table('placements').' p JOIN '.$this->store->table('campaigns').' c ON c.id=p.campaign_id';
        $condition=implode(' AND ',$where); if ($args) $condition=$wpdb->prepare($condition,$args);
        $count=(int)$wpdb->get_var('SELECT COUNT(*)'.$join.' WHERE '.$condition);
        $pages=max(1,(int)ceil($count/25)); $page=min($pages,max(1,(int)($in['page']??1)));
        $ids=$wpdb->get_col($wpdb->prepare('SELECT p.id'.$join.' WHERE '.$condition.' ORDER BY p.id DESC LIMIT 25 OFFSET %d',($page-1)*25));
        $rows=array_map(fn($id)=>$this->record((int)$id),$ids);
        $exports=[];
        if (current_user_can('cargo_export')) foreach (['placements','hits'] as $kind) $exports[$kind]=add_query_arg(['action'=>'cargo_csv','kind'=>$kind,'_wpnonce'=>wp_create_nonce('cargo_csv')]+$filter,admin_url('admin-post.php'));
        return compact('rows','count','page','pages','exports');
    }
    private function decode(string $value): array {
        global $wpdb;
        $parsed=Decoder::parse(trim($value),Config::hosts()[1]); $rows=[];
        if ($parsed['code']) {
            $id=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$this->store->table('placements').' WHERE short_code=%s',$parsed['code']));
            if ($id) {
                $r=$this->record((int)$id);
                if (!array_diff_assoc($parsed['utm'],$r['tags']) && !array_diff_assoc($parsed['tracking']??[],$r['tags'])) $rows[]=$r;
            }
        }
        if (!$rows && ($parsed['utm'] || !empty($parsed['tracking']))) {
            $where=[]; $args=[];
            foreach ($parsed['utm'] as $key=>$v) { $where[]=$key.'=%s'; $args[]=$v; }
            foreach ($parsed['tracking']??[] as $key=>$v) { $where[]="JSON_UNQUOTE(JSON_EXTRACT(tracking_json, '$.tags.$key'))=%s"; $args[]=$v; }
            $ids=$wpdb->get_col($wpdb->prepare('SELECT id FROM '.$this->store->table('placements').' WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 100',$args));
            $rows=array_map(fn($id)=>$this->record((int)$id),$ids);
        }
        return ['rows'=>$rows,'tags'=>$parsed['utm']+($parsed['tracking']??[])];
    }
}
