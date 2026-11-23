<?php
declare(strict_types=1);
namespace CargoUTM;

final class SitePolicy {
    public function boot(): void {
        add_filter('wp_headers',[$this,'headers']);
        add_filter('wp_robots',[$this,'robots'],PHP_INT_MAX);
        add_filter('robots_txt',[$this,'robotsTxt'],PHP_INT_MAX,2);
        add_filter('option_blog_public',static fn($value)=>'0');
        add_action('template_redirect',[$this,'entry'],0);
        add_filter('login_redirect',[$this,'afterLogin'],10,3);
    }
    public function headers(array $headers): array {
        $headers['X-Robots-Tag']='noindex, nofollow, noarchive';
        return $headers;
    }
    public function robots(array $robots): array {
        unset($robots['index'],$robots['follow'],$robots['all']);
        return array_merge($robots,['noindex'=>true,'nofollow'=>true,'noarchive'=>true]);
    }
    public function robotsTxt(string $output,mixed $public=null): string {
        // Crawlers must be able to read noindex headers on public responses.
        return "User-agent: *\nDisallow:\n";
    }
    public function publicHeaders(): array {
        return [
            'Content-Security-Policy'=>"default-src 'self'; script-src 'self'; script-src-attr 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            'X-Frame-Options'=>'DENY',
            'X-Content-Type-Options'=>'nosniff',
            'Permissions-Policy'=>'camera=(), microphone=(), geolocation=()',
        ];
    }
    public function entry(): void {
        if (is_feed() || is_robots() || (!is_front_page() && !is_home())) return;
        nocache_headers();
        $guest=!is_user_logged_in();
        if (!$guest && !current_user_can('cargo_view')) wp_die('Нет доступа к сервису. Обратитесь к администратору.','Доступ запрещён',['response'=>403]);
        if ($guest) foreach ($this->publicHeaders() as $name=>$value) header($name.': '.$value);
        show_admin_bar(false);
        $base=dirname(__DIR__).'/cargo-utm.php';
        wp_enqueue_style('cargo-workspace',plugins_url('assets/ui/app.css',$base),[],(string)filemtime(dirname(__DIR__).'/assets/ui/app.css'));
        wp_enqueue_script('cargo-workspace',plugins_url('assets/ui/app.js',$base),[],(string)filemtime(dirname(__DIR__).'/assets/ui/app.js'),true);
        require dirname(__DIR__).'/views/site-shell.php';
        exit;
    }

    public function afterLogin(string $redirect,string $requested,mixed $user): string {
        if (!$user instanceof \WP_User || !$user->has_cap('cargo_view')) return $redirect;
        $cards=home_url('/business-cards');
        if (rtrim($requested,'/')===$cards) return $cards;
        $host=parse_url($requested,PHP_URL_HOST);
        if ($requested==='' || $host===Config::hosts()[0] || rtrim($requested,'/')===rtrim(home_url('/'),'/')) {
            return home_url('/');
        }
        return $redirect;
    }
}
