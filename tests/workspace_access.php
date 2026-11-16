<?php
declare(strict_types=1);
// Exercise the real API permission/nonce dispatch without a WordPress installation.
if (isset($argv[1])) {
    $case=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);
    function current_user_can(string $cap): bool { global $case; return in_array($cap,$case['caps'],true); }
    function wp_send_json_error(mixed $data,int $status=200): never { echo json_encode(['success'=>false,'status'=>$status,'data'=>$data]); exit; }
    function wp_send_json_success(mixed $data,int $status=200): never { echo json_encode(['success'=>true,'status'=>$status,'data'=>$data]); exit; }
    function check_ajax_referer(string $action,string $key): void { if (($_POST[$key]??'')!=='valid') wp_send_json_error(['message'=>'nonce'],403); }
    function wp_unslash(string $value): string { return $value; }
    function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/','',strtolower($value)); }
    spl_autoload_register(static function(string $class): void { if (str_starts_with($class,'CargoUTM\\')) require __DIR__.'/../plugin/cargo-utm/src/'.substr($class,9).'.php'; });
    $wpdb=new class {
        public string $prefix='wp_';
        public function get_var(string $sql): int { return 0; }
        public function get_results(string $sql,mixed $mode=null): array { return []; }
        public function get_col(string $sql): array { return []; }
        public function get_row(string $sql,mixed $mode=null): null { return null; }
        public function prepare(string $sql,mixed ...$args): string { return $sql; }
    };
    define('ARRAY_A','ARRAY_A');
    $_SERVER['REQUEST_METHOD']=$case['method']??'POST';
    $_POST=['op'=>$case['op'],'nonce'=>$case['nonce']??'valid','payload'=>$case['payload']??'{}'];
    (new CargoUTM\Workspace())->api(); exit(2);
}
$cases=[];
$cases[]=['label'=>'anonymous read denied','op'=>'registry','caps'=>[],'status'=>403];
foreach (['issue','preview','campaign','slug','change'] as $op) $cases[]=['label'=>'viewer mutation denied '.$op,'op'=>$op,'caps'=>['cargo_view'],'status'=>403];
$cases[]=['label'=>'editor manage denied','op'=>'change','caps'=>['cargo_view','cargo_create'],'status'=>403];
$cases[]=['label'=>'nonce required','op'=>'registry','caps'=>['cargo_view'],'nonce'=>'bad','status'=>403];
$cases[]=['label'=>'POST required','op'=>'registry','caps'=>['cargo_view'],'method'=>'GET','status'=>405];
$cases[]=['label'=>'unknown operation denied','op'=>'unknown','caps'=>['cargo_view','cargo_manage'],'status'=>403];
$cases[]=['label'=>'viewer can read registry','op'=>'registry','caps'=>['cargo_view'],'status'=>200,'success'=>true];
$cases[]=['label'=>'scalar input rejected','op'=>'registry','caps'=>['cargo_view'],'payload'=>'1','status'=>400];
$cases[]=['label'=>'malformed JSON rejected','op'=>'registry','caps'=>['cargo_view'],'payload'=>'{','status'=>400];
foreach ($cases as $case) {
    $p=proc_open([PHP_BINARY,__FILE__,json_encode($case,JSON_THROW_ON_ERROR)],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($p);
    $result=json_decode($out,true);
    if ($code!==0 || !$result || $result['status']!==$case['status'] || $result['success']!==($case['success']??false)) throw new RuntimeException('FAIL '.$case['label'].' '.$out.' '.$error);
}
echo 'PASS: '.count($cases)." workspace API access/nonce/input checks\n";
