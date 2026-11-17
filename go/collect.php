<?php
declare(strict_types=1);
require __DIR__.'/Tracker.php';
use CargoTracking\Tracker;
header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if (!Tracker::enabled()) { http_response_code(404); exit; }
$host=(string)getenv('CARGO_GO_HOST');
if (($_SERVER['HTTP_HOST']??'')!==$host) { http_response_code(404); exit; }
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); http_response_code(405); exit; }
if (($_SERVER['HTTP_ORIGIN']??'')!=='https://'.$host || ($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') { http_response_code(403); exit; }
$length=$_SERVER['CONTENT_LENGTH']??'';
if (!is_string($length) || !ctype_digit($length) || strlen($length)>5 || (int)$length>4096) { http_response_code(413); exit; }
if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') { http_response_code(415); exit; }
try {
    $data=json_decode(file_get_contents('php://input',false,null,0,4097),false,8,JSON_THROW_ON_ERROR);
    if (!$data instanceof stdClass || array_diff(array_keys(get_object_vars($data)),['ticket','signals'])) throw new InvalidArgumentException('Invalid event');
    $tracker=new Tracker((string)getenv('CARGO_TRACKING_KEY'));
    $now=time(); $ticket=$tracker->readTicket($data->ticket??null,$now); $signals=Tracker::signals($data->signals??null);
    $ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
    if (Tracker::bot($ua)) { http_response_code(204); exit; }
    $identity=$tracker->identity($ticket,$_COOKIE[Tracker::COOKIE]??null,$signals,$ua);
    $prefix=getenv('CARGO_TABLE_PREFIX')?:'wp_';
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)) throw new InvalidArgumentException('Invalid prefix');
    $db=new PDO('mysql:host='.(getenv('CARGO_DB_HOST')?:'db').';dbname='.(getenv('CARGO_DB_NAME')?:'cargo_staging').';charset=utf8mb4',getenv('CARGO_GO_DB_USER'),getenv('CARGO_GO_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>2]);
    $db->exec('SET SESSION innodb_lock_wait_timeout=2');
    $stmt=$db->prepare("SELECT id,status FROM {$prefix}cargo_utm_placements WHERE short_code=? LIMIT 1"); $stmt->execute([$ticket['code']]); $placement=$stmt->fetch(PDO::FETCH_ASSOC);
    if (!$placement || !in_array($placement['status'],['active','archived'],true)) { http_response_code(404); exit; }
    $tracker->record($db,$prefix,(int)$placement['id'],$ticket,$identity,$now);
    http_response_code(204);
} catch (JsonException|InvalidArgumentException $e) { http_response_code(400);
} catch (Throwable $e) { error_log('cargo_tracking record_failed'); http_response_code(503); }
