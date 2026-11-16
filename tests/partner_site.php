<?php
declare(strict_types=1);
require __DIR__.'/../plugin/cargo-utm/src/Store.php';
require __DIR__.'/../plugin/cargo-utm/src/Tracking.php';
require __DIR__.'/../plugin/cargo-utm/src/Schema.php';
$options=[];
function get_option(string $key): mixed { global $options; return $options[$key]??false; }
function update_option(string $key,mixed $value,bool $autoload): void { global $options; $options[$key]=$value; }
function wp_json_encode(mixed $value): string { return json_encode($value,JSON_THROW_ON_ERROR); }
function get_current_user_id(): int { return 1; }
$wpdb=new class {
    public string $prefix='wp_';
    public int $insert_id=0;
    public array $inserts=[];
    public int $existing=0;
    public function prepare(string $sql,mixed ...$args): string { return $sql; }
    public function get_var(string $sql): int {
        if (str_contains($sql,'GET_LOCK') || str_contains($sql,'RELEASE_LOCK')) return 1;
        if ($sql==='SELECT id FROM wp_cargo_utm_template_versions WHERE template_code=%s LIMIT 1') return $this->existing;
        throw new RuntimeException('Unexpected query');
    }
    public function query(string $sql): int {
        if (!in_array($sql,['START TRANSACTION','COMMIT','ROLLBACK'],true)) throw new RuntimeException('Unexpected mutation');
        return 1;
    }
    public function insert(string $table,array $row): int {
        if (!in_array($table,['wp_cargo_utm_template_versions','wp_cargo_utm_audit'],true)) throw new RuntimeException('Existing data modified');
        $this->inserts[]=['table'=>$table,'row'=>$row]; $this->insert_id++;
        if ($table==='wp_cargo_utm_template_versions') $this->existing=$this->insert_id;
        return 1;
    }
    public function __call(string $method,array $args): never { throw new RuntimeException('Unexpected database operation '.$method); }
};
CargoUTM\Schema::partnerSiteDefault();
if (count($wpdb->inserts)!==2) throw new RuntimeException('One template and audit required');
$template=$wpdb->inserts[0]['row'];
if ($template['template_code']!=='partner_site' || json_decode($template['settings_json'],true)!==['label'=>'Сайт','source'=>'','medium'=>'referral']) throw new RuntimeException('Partner preset defaults');
CargoUTM\Schema::partnerSiteDefault();
if (count($wpdb->inserts)!==2) throw new RuntimeException('Migration must be idempotent');
$options=[]; $wpdb->inserts=[]; $wpdb->existing=45;
CargoUTM\Schema::partnerSiteDefault();
if ($wpdb->inserts!==[]) throw new RuntimeException('Existing customized/inactive preset must be preserved');
echo "PASS: partner preset additive migration, repeat safety and existing settings preserved\n";
