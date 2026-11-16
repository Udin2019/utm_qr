<?php
declare(strict_types=1);
// One-time recovery for the initial staging installation with empty URL options.
// Does not load WordPress, reset passwords, or modify populated URL options.
$db=null;
try {
    if ((getenv('CARGO_ENV')?:'staging')!=='staging') throw new RuntimeException('Staging only');
    $host=getenv('CARGO_UTM_HOST');
    if (!$host || !filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) || str_ends_with($host,'.')) throw new RuntimeException('Invalid host');
    $name=getenv('CARGO_DB_NAME'); $prefix=getenv('CARGO_TABLE_PREFIX')?:'wp_';
    foreach ([$name,$prefix] as $identifier) if (!$identifier || !preg_match('/^[a-zA-Z0-9_]+$/D',$identifier)) throw new RuntimeException('Invalid database identifier');
    $url='https://'.strtolower($host);
    $db=new PDO('mysql:host='.(getenv('CARGO_DB_HOST')?:'db').';dbname='.$name.';charset=utf8mb4',getenv('CARGO_DB_USER'),getenv('CARGO_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->beginTransaction();
    $rows=$db->query("SELECT option_name,option_value FROM `{$prefix}options` WHERE option_name IN ('home','siteurl','db_version') FOR UPDATE")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($rows['home'],$rows['siteurl'],$rows['db_version']) || !ctype_digit($rows['db_version']) || (int)$rows['db_version']<=0 || (int)$db->query("SELECT COUNT(*) FROM `{$prefix}users`")->fetchColumn()<1) throw new RuntimeException('Incomplete initial installation');
    if ($rows['home']===$url && $rows['siteurl']===$url) {
        $db->commit(); echo "Site URLs already match configuration; no changes.\n"; exit(0);
    }
    if ($rows['home']!=='' || $rows['siteurl']!=='') throw new RuntimeException('Existing URLs must not be overwritten');
    $stmt=$db->prepare("UPDATE `{$prefix}options` SET option_value=? WHERE option_name IN ('home','siteurl') AND option_value=''");
    $stmt->execute([$url]);
    if ($stmt->rowCount()!==2) throw new RuntimeException('Unexpected number of updated options');
    $db->commit();
    echo "Recovered home and siteurl from CARGO_UTM_HOST. Existing users and tables preserved.\n";
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,"Site URL recovery failed; no changes committed. Check staging mode, configured host, DB connection and existing option values.\n");
    exit(1);
}
