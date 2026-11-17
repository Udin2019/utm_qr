<?php
declare(strict_types=1);
namespace CargoTracking;

/** No WordPress dependency. The private key is supplied only to the go service. */
final class Tracker {
    public const COOKIE='__Host-cargo_visitor';
    public const RETENTION=15552000; // 180 days
    public function __construct(private string $secret) {
        if (!preg_match('/\A[a-f0-9]{64}\z/D',$secret)) throw new \InvalidArgumentException('Tracking key unavailable');
    }
    public static function enabled(): bool { return getenv('CARGO_TRACKING_ENABLED')==='1' && (bool)preg_match('/\A[a-f0-9]{64}\z/D',(string)getenv('CARGO_TRACKING_KEY')); }
    public function hash(string $value): string { return hash_hmac('sha256',$value,$this->secret); }
    public function cookie(string $id): string { return $id.'.'.$this->hash('cookie|'.$id); }
    public function cookieId(mixed $cookie): ?string {
        if (!is_string($cookie) || !preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D',$cookie,$m)) return null;
        return hash_equals($this->hash('cookie|'.$m[1]),$m[2])?$m[1]:null;
    }
    public function ticket(string $code,string $cookieId,int $now): string {
        $body=$now.'.'.bin2hex(random_bytes(16)).'.'.$code.'.'.$this->hash('binding|'.$cookieId);
        return $body.'.'.$this->hash('ticket|'.$body);
    }
    public function readTicket(mixed $ticket,int $now): array {
        if (!is_string($ticket) || !preg_match('/\A([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{12})\.([a-f0-9]{64})\.([a-f0-9]{64})\z/D',$ticket,$m)) throw new \InvalidArgumentException('Invalid ticket');
        $body=implode('.',array_slice($m,1,4));
        if (!hash_equals($this->hash('ticket|'.$body),$m[5]) || (int)$m[1]>$now+5 || $now-(int)$m[1]>120) throw new \InvalidArgumentException('Expired ticket');
        return ['event'=>$m[2],'code'=>$m[3],'binding'=>$m[4]];
    }
    public static function signals(mixed $value): array {
        if (!$value instanceof \stdClass) throw new \InvalidArgumentException('Invalid signals');
        $in=get_object_vars($value); $allowed=['width','height','dpr','depth','touch','cores','memory','language','timezone'];
        if (array_diff(array_keys($in),$allowed)) throw new \InvalidArgumentException('Unknown signals');
        $out=[];
        foreach (['width'=>20000,'height'=>20000,'dpr'=>20,'depth'=>64,'touch'=>100,'cores'=>1024,'memory'=>1024] as $key=>$max) {
            $v=$in[$key]??0;
            if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || $v<0 || $v>$max) throw new \InvalidArgumentException('Invalid numeric signal');
            $out[$key]=round((float)$v,2);
        }
        foreach (['language'=>40,'timezone'=>80] as $key=>$max) {
            $v=$in[$key]??'';
            if (!is_string($v) || strlen($v)>$max || !preg_match('/\A[a-zA-Z0-9_+.:\/-]*\z/D',$v)) throw new \InvalidArgumentException('Invalid text signal');
            $out[$key]=$v;
        }
        [$out['width'],$out['height']]=[min($out['width'],$out['height']),max($out['width'],$out['height'])];
        return $out;
    }
    public static function agent(string $ua): array {
        $ua=substr($ua,0,1024);
        $os=match(true) { (bool)preg_match('/iPhone|iPad|iPod/i',$ua)=>'iOS',str_contains($ua,'Android')=>'Android',str_contains($ua,'Windows')=>'Windows',str_contains($ua,'Macintosh')=>'macOS',str_contains($ua,'Linux')=>'Linux',default=>'Other' };
        $browser=match(true) { (bool)preg_match('/Edg[\/A]/',$ua)=>'Edge',(bool)preg_match('/Firefox|FxiOS/',$ua)=>'Firefox',(bool)preg_match('/Chrome|CriOS/',$ua)=>'Chrome',str_contains($ua,'Safari')=>'Safari',default=>'Other' };
        $device=preg_match('/iPad|Tablet/i',$ua)?'tablet':(preg_match('/Mobile|iPhone|Android/i',$ua)?'mobile':'desktop');
        return compact('os','browser','device');
    }
    public static function bot(string $ua): bool { return (bool)preg_match('/bot\b|crawler|spider|preview|facebookexternalhit|headless|curl\//i',$ua); }
    public function identity(array $ticket,mixed $cookie,array $signals,string $ua): array {
        $agent=self::agent($ua);
        $fingerprint=$this->hash('fp|'.json_encode([$agent,$signals],JSON_THROW_ON_ERROR));
        $id=$this->cookieId($cookie);
        if ($id!==null && hash_equals($ticket['binding'],$this->hash('binding|'.$id))) { $kind='cookie'; $visitor=$this->hash('visitor|'.$id); }
        elseif ($signals['width']>0 && $signals['height']>0 && $signals['language']!=='' && $signals['timezone']!=='') { $kind='fingerprint'; $visitor=$this->hash('estimate|'.$fingerprint); }
        else { $kind='unknown'; $visitor=$this->hash('unknown|'.$ticket['event']); }
        return ['kind'=>$kind,'visitor'=>$visitor,'fingerprint'=>$fingerprint]+$agent;
    }
    public static function session(string $previous,int $lastSeen,int $now,string $new): string { return $now-$lastSeen>=1800?$new:$previous; }
    /** Unique event index + visitor row lock prevent beacon replay and session races. */
    public function record(\PDO $db,string $prefix,int $placement,array $ticket,array $identity,int $now): bool {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/D',$prefix)) throw new \InvalidArgumentException('Invalid prefix');
        $state=$prefix.'cargo_utm_visitor_state'; $events=$prefix.'cargo_utm_visit_events';
        $new=bin2hex(random_bytes(16)); $date=gmdate('Y-m-d H:i:s',$now);
        $db->beginTransaction();
        try {
            $stmt=$db->prepare("INSERT INTO $state (visitor_hash,identity_kind,session_id,last_seen) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE visitor_hash=visitor_hash");
            $stmt->execute([$identity['visitor'],$identity['kind'],$new,$date]);
            $stmt=$db->prepare("SELECT session_id,last_seen FROM $state WHERE visitor_hash=? FOR UPDATE"); $stmt->execute([$identity['visitor']]); $row=$stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) throw new \RuntimeException('Missing visitor state');
            $session=self::session($row['session_id'],(new \DateTimeImmutable($row['last_seen'],new \DateTimeZone('UTC')))->getTimestamp(),$now,$new);
            $stmt=$db->prepare("INSERT INTO $events (event_id,placement_id,occurred_at,visitor_hash,identity_kind,session_id,fingerprint_hash,device,os,browser) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$ticket['event'],$placement,$date,$identity['visitor'],$identity['kind'],$session,$identity['fingerprint'],$identity['device'],$identity['os'],$identity['browser']]);
            $stmt=$db->prepare("UPDATE $state SET session_id=?,last_seen=GREATEST(last_seen,?) WHERE visitor_hash=?"); $stmt->execute([$session,$date,$identity['visitor']]);
            $db->commit(); return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof \PDOException && (int)($e->errorInfo[1]??0)===1062) return false;
            throw $e;
        }
    }
}
