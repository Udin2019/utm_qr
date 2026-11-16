<?php
declare(strict_types=1);
require __DIR__.'/../plugin/cargo-utm/src/Config.php';
require __DIR__.'/../plugin/cargo-utm/src/SitePolicy.php';
use CargoUTM\SitePolicy;
if (isset($argv[1])) {
    $case=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR);
    function is_feed(): bool { global $case; return $case['feed']??false; }
    function is_robots(): bool { return false; }
    function is_front_page(): bool { global $case; return $case['front']??true; }
    function is_home(): bool { return false; }
    function is_user_logged_in(): bool { global $case; return $case['logged']??false; }
    function current_user_can(string $cap): bool { global $case; return $case['can']??false; }
    function nocache_headers(): void {}
    function admin_url(string $path): string { return 'https://utmqr.kit-rb.com/wp-admin/'.$path; }
    function home_url(string $path): string { return 'https://utmqr.kit-rb.com'.$path; }
    function wp_login_url(string $target): string { return 'https://utmqr.kit-rb.com/wp-login.php?redirect_to='.rawurlencode($target); }
    function wp_safe_redirect(string $url,int $status): void { echo json_encode(['status'=>$status,'url'=>$url]); }
    function wp_die(string $message,string $title,array $options): never { echo json_encode(['status'=>$options['response']]); exit; }
    class WP_User {
        public function __construct(private bool $can) {}
        public function has_cap(string $cap): bool { return $this->can; }
    }
    $policy=new SitePolicy();
    if (($case['op']??'entry')==='login') {
        echo json_encode(['url'=>$policy->afterLogin('original',$case['requested'],new WP_User($case['can']))]);
        exit;
    }
    $policy->entry(); echo json_encode(['unchanged'=>true]); exit;
}
$policy=new SitePolicy();
$headers=$policy->headers(['Content-Type'=>'text/html']);
if ($headers['X-Robots-Tag']!=='noindex, nofollow, noarchive' || $headers['Content-Type']!=='text/html') throw new RuntimeException('Response headers');
$robots=$policy->robots(['index'=>true,'follow'=>true,'max-image-preview'=>'large']);
if (isset($robots['index'],$robots['follow']) || empty($robots['noindex']) || empty($robots['nofollow'])) throw new RuntimeException('Robots meta');
if ($policy->robotsTxt('Sitemap: old',true)!=="User-agent: *\nDisallow:\n") throw new RuntimeException('Robots must allow reading noindex');
$target='https://utmqr.kit-rb.com/wp-admin/admin.php?page=cargo-utm';
$cases=[
    [['logged'=>false],['status'=>302,'url'=>'https://utmqr.kit-rb.com/wp-login.php?redirect_to='.rawurlencode($target)]],
    [['logged'=>true,'can'=>true],['status'=>302,'url'=>$target]],
    [['logged'=>true,'can'=>false],['status'=>403]],
    [['front'=>false],['unchanged'=>true]],
    [['feed'=>true],['unchanged'=>true]],
    [['op'=>'login','requested'=>'','can'=>true],['url'=>$target]],
    [['op'=>'login','requested'=>'https://utm.cargo.test/wp-admin/','can'=>true],['url'=>$target]],
    [['op'=>'login','requested'=>'https://utmqr.kit-rb.com/','can'=>true],['url'=>$target]],
    [['op'=>'login','requested'=>'','can'=>false],['url'=>'original']],
    [['op'=>'login','requested'=>'https://utmqr.kit-rb.com/wp-admin/admin.php?page=cargo-utm&view=7','can'=>true],['url'=>'original']],
];
foreach ($cases as [$case,$expected]) {
    $p=proc_open([PHP_BINARY,__FILE__,json_encode($case,JSON_THROW_ON_ERROR)],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($p);
    if ($code!==0 || json_decode($out,true)!==$expected) throw new RuntimeException('Entry policy: '.$out.' '.$error);
}
echo "PASS: site noindex headers/meta/robots and 10 entry/login/permission cases\n";
