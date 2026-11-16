<?php
declare(strict_types=1);
// Execute the real public dispatcher. Any private-table access or write fails.
if (isset($argv[1])) {
    $case=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);
    function wp_send_json_error(mixed $data,int $status=200): never { echo json_encode(['success'=>false,'status'=>$status,'data'=>$data]); exit; }
    function wp_send_json_success(mixed $data,int $status=200): never { echo json_encode(['success'=>true,'status'=>$status,'data'=>$data]); exit; }
    function check_ajax_referer(string $action,string $key): void { if ($action!=='cargo_public_generator' || ($_POST[$key]??'')!=='valid') wp_send_json_error(['message'=>'nonce'],403); }
    function wp_unslash(string $value): string { return $value; }
    function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/','',strtolower($value)); }
    spl_autoload_register(static function(string $class): void { if (str_starts_with($class,'CargoUTM\\')) require __DIR__.'/../plugin/cargo-utm/src/'.substr($class,9).'.php'; });
    define('ARRAY_A','ARRAY_A');
    $wpdb=new class {
        public string $prefix='wp_';
        public function get_results(string $sql,mixed $mode): array {
            if ($sql==='SELECT id,unit_slug,name FROM wp_cargo_utm_units WHERE active=1 ORDER BY id') return [['id'=>'7','unit_slug'=>'cargo-fuel','name'=>'Топливо']];
            if ($sql==='SELECT template_code,version,settings_json FROM wp_cargo_utm_template_versions WHERE active=1 ORDER BY version DESC') return [
                ['template_code'=>'yandex','version'=>2,'settings_json'=>json_encode(CargoUTM\Tracking::PRESETS['yandex']+['internal'=>'hidden'])],
                ['template_code'=>'yandex','version'=>1,'settings_json'=>'{}'],
                ['template_code'=>'exhibition','version'=>1,'settings_json'=>'{}'],
                ['template_code'=>'partner_site','version'=>1,'settings_json'=>json_encode(CargoUTM\Tracking::PRESETS['partner_site'])],
            ];
            throw new RuntimeException('Unexpected database read');
        }
        public function __call(string $name,array $args): never { throw new RuntimeException('Forbidden database operation '.$name); }
    };
    putenv('CARGO_ALLOWED_HOSTS=fuel.cargo.example');
    $_SERVER['REQUEST_METHOD']=$case['method']??'POST';
    $_SERVER['QUERY_STRING']=$case['query']??'action=cargo_public_generator';
    $_GET=['action'=>$case['action']??'cargo_public_generator'];
    $_POST=['op'=>$case['op'],'nonce'=>$case['nonce']??'valid','payload'=>$case['payload']??'{}'];
    (new CargoUTM\PublicGenerator())->api(); exit(2);
}
$input=['unit_id'=>7,'campaign_name'=>'Выставка','campaign_year'=>2026,'type'=>'online','url'=>'https://fuel.cargo.example/cards?utm_source=old#form','utm_source'=>'yandex','utm_medium'=>'cpc'];
$cases=[['label'=>'catalog','op'=>'bootstrap','status'=>200],['label'=>'preview','op'=>'preview','status'=>200,'input'=>$input],['label'=>'custom code and macros','op'=>'preview','status'=>200,'input'=>$input+['campaign_code'=>'banner_25','preset'=>'yandex','use_roistat'=>true,'roistat_value'=>'123']]];
$cases[]=['label'=>'partner referral','op'=>'preview','status'=>200,'input'=>array_replace($input,['preset'=>'partner_site','utm_source'=>'partner.ru','utm_medium'=>'referral'])];
foreach (['registry','record','decode','issue','campaign','change','slug','unknown'] as $op) $cases[]=['label'=>'private '.$op,'op'=>$op,'status'=>403];
$cases[]=['label'=>'GET','op'=>'bootstrap','method'=>'GET','status'=>405];
$cases[]=['label'=>'nonce','op'=>'bootstrap','nonce'=>'bad','status'=>403];
foreach (['','action=cargo_public_generator&action=other','x=1&action=cargo_public_generator','action=cargo_public_generator&extra=1'] as $query) $cases[]=['label'=>'route '.$query,'op'=>'bootstrap','query'=>$query,'status'=>400];
$cases[]=['label'=>'wrong action','op'=>'bootstrap','action'=>'cargo_workspace','status'=>400];
foreach (['{','1','null'] as $payload) $cases[]=['label'=>'bad JSON '.$payload,'op'=>'preview','payload'=>$payload,'status'=>400];
foreach (['unit_id'=>99,'campaign_name'=>'','campaign_year'=>1999,'campaign_code'=>'Кириллица','url'=>'https://evil.example','preset'=>'unknown','type'=>'unknown'] as $field=>$value) $cases[]=['label'=>'invalid '.$field,'op'=>'preview','input'=>array_replace($input,[$field=>$value]),'status'=>400];
foreach ($cases as $case) {
    if (isset($case['input'])) $case['payload']=json_encode($case['input']);
    $p=proc_open([PHP_BINARY,__FILE__,json_encode($case,JSON_THROW_ON_ERROR)],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($p);
    $result=json_decode($out,true);
    if ($code!==0 || !$result || $result['status']!==$case['status'] || $result['success']!==($case['status']===200)) throw new RuntimeException('FAIL '.$case['label'].' '.$out.' '.$error);
    if ($case['status']!==200) continue;
    $data=$result['data'];
    if ($case['op']==='bootstrap') {
        if ($data['campaigns']!==[] || count($data['brands'])!==2 || $data['presets']['yandex']['version']!==2 || isset($data['presets']['yandex']['internal']) || isset($data['presets']['exhibition'])) throw new RuntimeException('Catalog filtering');
        foreach (['placements','audit','authors','downloads','hits'] as $key) if (isset($data[$key])) throw new RuntimeException('Private data exposed');
    } else {
        if (!$data['provisional'] || !$data['campaignIdPending'] || $data['tags']['utm_id']!=='' || str_contains($data['url'],'utm_id=')) throw new RuntimeException('Permanent campaign ID exposed');
        if ($case['label']==='partner referral' && ($data['tags']['utm_source']!=='partner.ru' || $data['tags']['utm_medium']!=='referral')) throw new RuntimeException('Partner referral tags');
        if ($data['removed']!==['utm_source']) throw new RuntimeException('Tracking replacement');
        if ($case['label']==='custom code and macros' && ($data['tags']['utm_campaign']!=='banner_25' || !str_contains($data['url'],'roistat=direct123_{source_type}_{banner_id}_{keyword}'))) throw new RuntimeException('Custom code/macros');
    }
}
echo 'PASS: '.count($cases)." public API read-only/catalog/preview/access checks\n";
