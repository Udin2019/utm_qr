<?php
declare(strict_types=1);
// Проверка реального контроллера с минимальными заменами функций WordPress.
require __DIR__.'/../plugin/cargo-utm/src/SitePolicy.php';
require __DIR__.'/../plugin/cargo-utm/src/BusinessCards.php';

if (isset($argv[1])) {
    $case=json_decode($argv[1],true,16,JSON_THROW_ON_ERROR);
    function nocache_headers(): void {}
    function status_header(int $status): void {}
    function wp_parse_url(string $url,int $part=-1): mixed { return parse_url($url,$part); }
    function is_user_logged_in(): bool { return $GLOBALS['case']['logged']??false; }
    function current_user_can(string $cap): bool { return in_array($cap,$GLOBALS['case']['caps']??[],true); }
    function home_url(string $path): string { return 'https://utm.cargo.test'.$path; }
    function wp_safe_redirect(string $url): void { echo json_encode(['status'=>302,'location'=>$url]); }
    function wp_die(string $message,string $title,array $data): never { echo json_encode(['status'=>$data['response']]); exit; }
    function wp_send_json_error(array $data,int $status): never { echo json_encode(['status'=>$status]); exit; }
    function check_ajax_referer(string $action,string $key,bool $stop): bool { return false; }
    function add_action(string $hook,callable $callback,int $priority=10): void { $GLOBALS['hooks'][$hook]=$callback; }
    $_SERVER['REQUEST_METHOD']=$case['method']??'GET';
    $_SERVER['REQUEST_URI']=$case['path']??'/business-cards';
    $cards=new CargoUTM\BusinessCards();
    $cards->boot();
    match ($case['op']??'route') {
        'guest-api'=>($GLOBALS['hooks']['wp_ajax_nopriv_cargo_business_cards'])(),
        'api'=>$cards->api(),
        default=>$cards->route(),
    };
    echo json_encode(['status'=>0]);
    exit;
}
$cases=[
    [['path'=>'/'],['status'=>0]],
    [['path'=>'/c/invalid'],['status'=>0]],
    [[],['status'=>302,'location'=>'https://utm.cargo.test/?login=business-cards']],
    [['logged'=>true,'caps'=>['cargo_view']],['status'=>403]],
    [['logged'=>true,'caps'=>['cargo_create'],'method'=>'POST'],['status'=>405]],
    [['op'=>'guest-api','method'=>'POST'],['status'=>401]],
    [['op'=>'api','caps'=>['cargo_view'],'method'=>'POST'],['status'=>403]],
    [['op'=>'api','caps'=>['cargo_create']],['status'=>405]],
    [['op'=>'api','caps'=>['cargo_create'],'method'=>'POST'],['status'=>403]],
];
foreach ($cases as [$input,$expected]) {
    $process=proc_open([PHP_BINARY,__FILE__,json_encode($input)], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $output=stream_get_contents($pipes[1]);
    $errors=stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process)!==0 || json_decode($output,true)!==$expected) throw new RuntimeException('FAIL route '.json_encode($input).' '.$output.' '.$errors);
}
echo "PASS: 9 проверок маршрутизации, HTTP-метода, прав и nonce\n";
