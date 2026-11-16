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
header('Location: '.$url,true,302);
