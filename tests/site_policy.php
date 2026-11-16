<?php
declare(strict_types=1);
require __DIR__.'/../plugin/cargo-utm/src/Config.php';
require __DIR__.'/../plugin/cargo-utm/src/SitePolicy.php';
require __DIR__.'/../plugin/cargo-utm/src/Store.php';
require __DIR__.'/../plugin/cargo-utm/src/Workspace.php';
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
    function show_admin_bar(bool $show): void { if ($show) throw new RuntimeException('Admin bar exposed'); }
    function wp_enqueue_style(mixed ...$args): void {}
    function wp_enqueue_script(mixed ...$args): void {}
    function plugins_url(string $path,string $base): string { return 'https://utmqr.kit-rb.com/wp-content/plugins/cargo-utm/'.$path; }
    function language_attributes(): void { echo 'lang="ru-RU"'; }
    function get_bloginfo(string $key): string { return 'UTF-8'; }
    function esc_attr(string $text): string { return htmlspecialchars($text,ENT_QUOTES); }
    function esc_url(string $text): string { return htmlspecialchars($text,ENT_QUOTES); }
    function wp_head(): void { echo '<meta name="robots" content="noindex,nofollow">'; }
    function wp_footer(): void {}
    function wp_create_nonce(string $action): string { return $action.'-nonce'; }
    function wp_logout_url(string $target): string { return 'https://utmqr.kit-rb.com/wp-login.php?action=logout&amp;redirect_to='.rawurlencode($target); }
    function wp_json_encode(mixed $value,int $flags=0): string { return json_encode($value,$flags|JSON_THROW_ON_ERROR); }
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
$target='https://utmqr.kit-rb.com/';
$cases=[
    [['logged'=>false],['html'=>true,'guest'=>true]],
    [['logged'=>true,'can'=>true],['html'=>true,'guest'=>false]],
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
    if ($expected['html']??false) {
        if ($code!==0 || !str_starts_with($out,'<!doctype html>') || !str_contains($out,'class="cargo-standalone"')) throw new RuntimeException('Standalone shell: '.$error);
        if (!str_contains($out,'id="generator-home" href="https://utmqr.kit-rb.com/"')) throw new RuntimeException('Generator root link');
        preg_match('/<script type="application\/json" id="cargo-config">(.*?)<\/script>/s',$out,$matches);
        $config=json_decode($matches[1]??'',true,32,JSON_THROW_ON_ERROR);
        if ($config['isGuest']!==$expected['guest'] || str_contains($config['links']['logout'],'&amp;')) throw new RuntimeException('Guest config/logout');
        $action=$expected['guest']?'cargo_public_generator':'cargo_workspace';
        if ($config['nonce']!==$action.'-nonce' || ($expected['guest'] && !str_ends_with($config['endpoint'],'?action='.$action))) throw new RuntimeException('Endpoint isolation');
    } elseif ($code!==0 || json_decode($out,true)!==$expected) throw new RuntimeException('Entry policy: '.$out.' '.$error);
}
echo "PASS: site noindex headers/meta/robots and 10 entry/login/permission cases\n";
