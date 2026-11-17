<?php
declare(strict_types=1);
// Executed by the dedicated public FPM pool; WordPress is never loaded.
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Type: text/plain; charset=UTF-8');
$method=$_SERVER['REQUEST_METHOD']??'';
$host=strtolower($_SERVER['HTTP_HOST']??'');
$expected=getenv('CARGO_GO_HOST')?:'go.cargo.test';
$code=$_SERVER['CARGO_SHORT_CODE']??'';
if ($host!==$expected || !preg_match('/^[a-f0-9]{12}$/D',$code)) { http_response_code(404); exit; }
if (!in_array($method,['GET','HEAD'],true)) { header('Allow: GET, HEAD'); http_response_code(405); exit; }
$prefix=getenv('CARGO_TABLE_PREFIX')?:'wp_';
if (!preg_match('/^[a-zA-Z0-9_]+$/D',$prefix)) { http_response_code(503); exit; }
try {
    $db=new PDO('mysql:host='.(getenv('CARGO_DB_HOST')?:'db').';dbname='.(getenv('CARGO_DB_NAME')?:'cargo_staging').';charset=utf8mb4',getenv('CARGO_GO_DB_USER'),getenv('CARGO_GO_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]);
    $stmt=$db->prepare("SELECT id,target_url,status FROM {$prefix}cargo_utm_placements WHERE short_code=? LIMIT 1");
    $stmt->execute([$code]); $row=$stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('cargo_go lookup_failed'); http_response_code(503); exit; }
if (!$row) { http_response_code(404); exit; }
if ($row['status']==='revoked') { http_response_code(410); exit; }
if (!in_array($row['status'],['active','archived'],true)) { http_response_code(404); exit; }
$url=$row['target_url'];
// Defence in depth for corrupted data; full validation occurs at issuance/change.
$p=parse_url($url);
if (!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || ($p['port']??443)!==443 || preg_match('/[\x00-\x20\x7f\\\\]/',$url) || !in_array(strtolower($p['host']),explode(',',getenv('CARGO_ALLOWED_HOSTS')?:''),true) || in_array(strtolower($p['host']),[$expected,getenv('CARGO_UTM_HOST')],true)) { error_log('cargo_go invalid_destination'); http_response_code(503); exit; }
if ($method==='GET') {
    try {
        $stmt=$db->prepare("INSERT INTO {$prefix}cargo_utm_daily_hits (placement_id,day_utc,get_count) VALUES (?,UTC_DATE(),1) ON DUPLICATE KEY UPDATE get_count=get_count+1");
        $stmt->execute([$row['id']]);
    } catch (Throwable $e) {
        // Only SQLSTATE and numeric driver code; no SQL, credentials or visitor data.
        $state=$e instanceof PDOException ? (string)($e->errorInfo[0]??$e->getCode()) : '';
        $driver=$e instanceof PDOException ? (string)($e->errorInfo[1]??'') : '';
        $diagnostic='cargo_go counter_failed';
        if (preg_match('/^[A-Z0-9]{5}$/D',$state)) $diagnostic.=' sqlstate='.$state;
        if (preg_match('/^[0-9]{1,6}$/D',$driver)) $diagnostic.=' driver='.$driver;
        $detail=$e instanceof PDOException ? (string)($e->errorInfo[2]??'') : '';
        if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE) command denied\b/i',$detail,$match)) {
            $diagnostic.=' operation='.strtoupper($match[1]);
        }
        if (preg_match("/for column '([a-z_]+)'/i",$detail,$match)
            && in_array($match[1],['placement_id','day_utc','get_count'],true)) {
            $diagnostic.=' column='.$match[1];
        }
        error_log($diagnostic);
    }
}
require __DIR__.'/Tracker.php';
if ($method==='GET' && \CargoTracking\Tracker::enabled() && !\CargoTracking\Tracker::bot((string)($_SERVER['HTTP_USER_AGENT']??''))) {
    try {
        $tracker=new \CargoTracking\Tracker((string)getenv('CARGO_TRACKING_KEY'));
        $id=$tracker->cookieId($_COOKIE[\CargoTracking\Tracker::COOKIE]??null)??bin2hex(random_bytes(16));
        $ticket=$tracker->ticket($code,$id,time());
        $nonce=base64_encode(random_bytes(18));
        $config=json_encode(['ticket'=>$ticket,'target'=>$url],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $script=file_get_contents(__DIR__.'/tracker.js');
        if ($script===false) throw new RuntimeException('Tracking script unavailable');
        setcookie(\CargoTracking\Tracker::COOKIE,$tracker->cookie($id),['expires'=>time()+\CargoTracking\Tracker::RETENTION,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
        header('Referrer-Policy: no-referrer'); header('X-Frame-Options: DENY');
        $escaped=htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta http-equiv="refresh" content="1;url='.$escaped.'"><title>Переход</title></head><body><a href="'.$escaped.'">Перейти на сайт</a><noscript><meta http-equiv="refresh" content="0;url='.$escaped.'"></noscript><script nonce="'.$nonce.'">const tracking='.$config.';'.$script.'</script></body></html>';
        exit;
    } catch (Throwable $e) { error_log('cargo_tracking page_failed'); }
}
header('Location: '.$url,true,302);
