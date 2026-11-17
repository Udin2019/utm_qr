<?php
declare(strict_types=1);
// Staging only. Run via scripts/integration.sh in the WordPress container.
if (getenv('CARGO_ENV')==='production') throw new RuntimeException('Tracking fixtures forbidden in production');
require_once __DIR__.'/cargo-tracking-Tracker.php';
$trackingDb=new PDO('mysql:host='.getenv('WORDPRESS_DB_HOST').';dbname='.getenv('WORDPRESS_DB_NAME').';charset=utf8mb4',getenv('WORDPRESS_DB_USER'),getenv('WORDPRESS_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$trackingPrefix=getenv('CARGO_TABLE_PREFIX')?:'wp_';
if (!preg_match('/\A[a-zA-Z0-9_]+\z/D',$trackingPrefix)) throw new RuntimeException('Unsafe prefix');
$trackingPlacement=(int)$trackingDb->query("SELECT id FROM {$trackingPrefix}cargo_utm_placements ORDER BY id LIMIT 1")->fetchColumn();
if (!$trackingPlacement) throw new RuntimeException('Run placement fixtures first');
$tracking=new CargoTracking\Tracker(str_repeat('a',64));
$trackingIds=[]; $trackingVisitors=[];
$trackingCheck=static function(bool $condition,string $label): void { if (!$condition) throw new RuntimeException('Tracking DB: '.$label); };
$trackingRecord=static function(string $cookieId,int $time,?array $ticket=null) use ($tracking,$trackingDb,$trackingPrefix,$trackingPlacement,&$trackingIds,&$trackingVisitors): array {
    $ticket??=$tracking->readTicket($tracking->ticket('123456789abc',$cookieId,$time),$time);
    $identity=$tracking->identity($ticket,$tracking->cookie($cookieId),CargoTracking\Tracker::signals((object)['width'=>1280,'height'=>720,'language'=>'ru','timezone'=>'UTC']),'Mozilla/5.0 Chrome/130 Windows');
    $trackingIds[$ticket['event']]=$ticket['event']; $trackingVisitors[$identity['visitor']]=$identity['visitor'];
    $ok=$tracking->record($trackingDb,$trackingPrefix,$trackingPlacement,$ticket,$identity,$time);
    return [$ticket,$ok,$identity];
};
try {
    $cookieId=bin2hex(random_bytes(16)); $time=time();
    [$ticket,$ok,$identity]=$trackingRecord($cookieId,$time); $trackingCheck($ok,'first event inserted');
    [, $ok]=$trackingRecord($cookieId,$time+1,$ticket); $trackingCheck(!$ok,'replay not inserted');
    $trackingRecord($cookieId,$time+1799); // Activity extends session.
    $trackingRecord($cookieId,$time+3599); // 1800 seconds since previous event.
    $other=bin2hex(random_bytes(16)); $trackingRecord($other,$time+3600);
    $stmt=$trackingDb->prepare("SELECT COUNT(*) events,COUNT(DISTINCT session_id) sessions,COUNT(DISTINCT visitor_hash) visitors,COUNT(DISTINCT fingerprint_hash) fingerprints FROM {$trackingPrefix}cargo_utm_visit_events WHERE event_id IN (".implode(',',array_fill(0,count($trackingIds),'?')).')');
    $stmt->execute(array_values($trackingIds)); $row=$stmt->fetch(PDO::FETCH_ASSOC);
    $trackingCheck((int)$row['events']===4,'replay is idempotent');
    $trackingCheck((int)$row['sessions']===3,'session timeout and other browser');
    $trackingCheck((int)$row['visitors']===2 && (int)$row['fingerprints']===1,'equal fingerprints do not merge cookies');
    // Duplicate must roll back visitor changes, even when submitted out of order.
    $state=$trackingDb->prepare("SELECT last_seen FROM {$trackingPrefix}cargo_utm_visitor_state WHERE visitor_hash=?");
    $state->execute([$identity['visitor']]); $before=$state->fetchColumn();
    $trackingRecord($cookieId,$time+7200,$ticket);
    $state->execute([$identity['visitor']]); $trackingCheck($before===$state->fetchColumn(),'duplicate rolls back session mutation');
    echo "PASS: tracking real database writes, replay rollback, session boundaries and cookie separation\n";
} finally {
    foreach ($trackingIds as $eventId) { $stmt=$trackingDb->prepare("DELETE FROM {$trackingPrefix}cargo_utm_visit_events WHERE event_id=?"); $stmt->execute([$eventId]); }
    foreach ($trackingVisitors as $visitor) { $stmt=$trackingDb->prepare("DELETE FROM {$trackingPrefix}cargo_utm_visitor_state WHERE visitor_hash=?"); $stmt->execute([$visitor]); }
}
