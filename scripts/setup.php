<?php
declare(strict_types=1);
$stage='start';
$completed=false;
// WordPress may terminate via exit(0) inside its database error page.
// Only explicit completion may satisfy Compose's setup dependency.
register_shutdown_function(static function() use (&$completed,&$stage): void {
    if (!$completed) {
        fwrite(STDERR,"Cargo setup incomplete at stage: $stage\n");
        exit(1);
    }
});
function stage(string $name): void {
    $GLOBALS['stage']=$name;
    fwrite(STDOUT,"Cargo setup stage: $name\n");
}
function run(array $args): void {
    $p=proc_open(array_merge(['wp','--allow-root','--path=/var/www/html'], $args),[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('WP-CLI process unavailable');
    $exit=proc_close($p);
    if ($exit!==0) {
        fwrite(STDERR,"Cargo WP-CLI exit code: $exit\n");
        throw new RuntimeException('WP-CLI setup command failed');
    }
}
try {
    stage('wait-wordpress-config');
    $ready='/var/www/html/wp-content/plugins/cargo-utm/.cargo-ready';
    for ($i=0;$i<60;$i++) {
        clearstatcache();
        if (is_file('/var/www/html/wp-config.php') && is_file($ready) && trim(file_get_contents($ready))==='0.2.0') break;
        sleep(1);
    }
    if (!is_file('/var/www/html/wp-config.php') || !is_file($ready) || trim(file_get_contents($ready))!=='0.2.0') throw new RuntimeException('WordPress plugin copy not ready');
    stage('load-wordpress');
    define('WP_INSTALLING',true); require '/var/www/html/wp-load.php';
    if (!is_blog_installed()) {
        stage('install-wordpress');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $password=getenv('CARGO_WP_ADMIN_PASSWORD');
        if (!$password || strlen($password)<16) throw new RuntimeException('Use WP password of at least 16 characters');
        wp_install('Каргономика · UTM и QR',getenv('CARGO_WP_ADMIN_USER'),getenv('CARGO_WP_ADMIN_EMAIL'),false,'',$password,'ru_RU');
    }
    stage('activate-plugin');
    run(['plugin','activate','cargo-utm']);
    stage('migrate-plugin');
    run(['cargo-utm','migrate']);
    if (getenv('CARGO_ENV')!=='production') {
        stage('seed-staging');
        run(['cargo-utm','seed']);
    }
    stage('validate-database-identifiers');
    $dbName=getenv('CARGO_DB_NAME'); $prefix=getenv('CARGO_TABLE_PREFIX')?:'wp_'; $user=getenv('CARGO_GO_DB_USER');
    foreach ([$dbName,$prefix,$user] as $name) if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new RuntimeException('Unsafe database identifier');
    stage('connect-database-admin');
    $db=new PDO('mysql:host=db;charset=utf8mb4','root',getenv('CARGO_DB_ROOT_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $account=$db->quote($user)."@'%'"; $pass=$db->quote(getenv('CARGO_GO_DB_PASSWORD'));
    stage('configure-go-user');
    $db->exec("CREATE USER IF NOT EXISTS $account IDENTIFIED BY $pass");
    $db->exec("ALTER USER $account IDENTIFIED BY $pass");
    $db->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $account");
    stage('grant-go-placement-read');
    $db->exec("GRANT SELECT (id,short_code,target_url,status) ON `$dbName`.`{$prefix}cargo_utm_placements` TO $account");
    stage('grant-go-hit-counter');
    // Native prepared upsert on this MariaDB deployment checks UPDATE on the
    // insert key columns too (observed 1143: UPDATE denied on placement_id).
    // Limit these permissions to the aggregate's three columns; the query
    // itself only increments get_count and never changes the key.
    $db->exec("GRANT INSERT (placement_id,day_utc,get_count), UPDATE (placement_id,day_utc,get_count), SELECT (placement_id,day_utc,get_count) ON `$dbName`.`{$prefix}cargo_utm_daily_hits` TO $account");
    stage('grant-go-visit-tracking');
    // Only pseudonymous tracking state/events; no access to WP users or campaigns.
    $db->exec("GRANT SELECT, INSERT, UPDATE ON `$dbName`.`{$prefix}cargo_utm_visitor_state` TO $account");
    $db->exec("GRANT SELECT, INSERT ON `$dbName`.`{$prefix}cargo_utm_visit_events` TO $account");
    $completed=true;
    echo "Cargo setup completed\n";
} catch (Throwable $e) {
    $type=get_class($e);
    $file=basename($e->getFile());
    $line=$e->getLine();
    fwrite(STDERR,"Cargo setup failed at stage: $stage; type: $type; file: $file:$line\n");
    if ($e instanceof PDOException && preg_match('/^[A-Z0-9]{5}$/D',(string)$e->getCode())) {
        fwrite(STDERR,'Cargo database SQLSTATE: '.$e->getCode()."\n");
    }
    exit(1);
}
