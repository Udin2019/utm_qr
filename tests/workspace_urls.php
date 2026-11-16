<?php
declare(strict_types=1);
// A Workspace record goes to JSON/DOM properties, never through an HTML parser.
function admin_url(string $path): string { return 'https://utm.cargo.test/wp-admin/'.$path; }
function wp_create_nonce(string $action): string { return 'nonce123'; }
function add_query_arg(array $args,string $url): string { return $url.'?'.http_build_query($args,'','&',PHP_QUERY_RFC3986); }
function wp_nonce_url(string $url,string $action): string { return htmlspecialchars($url.'&_wpnonce='.wp_create_nonce($action),ENT_QUOTES,'UTF-8'); }
function current_user_can(string $cap): bool { return true; }
define('ARRAY_A','ARRAY_A');
spl_autoload_register(static function(string $class): void { if (str_starts_with($class,'CargoUTM\\')) require __DIR__.'/../plugin/cargo-utm/src/'.substr($class,9).'.php'; });
$wpdb=new class {
    public string $prefix='wp_';
    public function prepare(string $sql,mixed ...$args): string { return $sql; }
    public function get_var(string $sql): int { return str_contains($sql,'COUNT(*)')?1:0; }
    public function get_col(string $sql): array { return [7]; }
    public function get_results(string $sql,mixed $mode=null): array { return []; }
    public function get_row(string $sql,mixed $mode=null): array {
        return ['id'=>7,'short_code'=>'123456789abc','details_json'=>'{}','tracking_json'=>null,'target_url'=>'https://fuel.cargo.example/','utm_source'=>'exhibition','utm_medium'=>'qr','utm_campaign'=>'campaign','utm_content'=>'place','utm_term'=>'','utm_id'=>'cg-1234567890abcdef'];
    }
};
$workspace=new CargoUTM\Workspace();
putenv('CARGO_SHORT_LINKS_ENABLED=0');
$record=$workspace->record(7);
if (isset($record['short_url'])) throw new RuntimeException('Disabled short URL exposed');
putenv('CARGO_SHORT_LINKS_ENABLED=1');
if ($workspace->record(7)['short_url']!==CargoUTM\Config::short('123456789abc')) throw new RuntimeException('Enabled short URL missing');
putenv('CARGO_SHORT_LINKS_ENABLED=0');
foreach ($record['downloads'] as $format=>$url) {
    if (str_contains($url,'&amp;') || str_contains($url,'&#')) throw new RuntimeException('HTML entity in JSON download URL: '.$format);
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    if (($query['_wpnonce']??'')!=='nonce123' || ($query['id']??'')!=='7' || ($query['format']??'')!==($format==='preview'?'preview':($format==='svg'?'svg':'png'))) throw new RuntimeException('Invalid download query: '.$format);
}
$list=$workspace->registry(['status'=>'all']);
foreach ($list['exports'] as $kind=>$url) {
    if (str_contains($url,'&amp;')) throw new RuntimeException('HTML entity in JSON CSV URL');
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    if (($query['_wpnonce']??'')!=='nonce123' || ($query['kind']??'')!==$kind) throw new RuntimeException('Invalid CSV query');
}
echo "PASS: 5 download and 2 CSV URLs retain raw query parameters and nonce\n";
