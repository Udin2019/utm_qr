<?php
/**
 * Plugin Name: Каргономика — UTM и QR
 * Description: Корпоративные кампании, реестр размещений и брендированные QR.
 * Version: 0.2.0
 * Requires PHP: 8.3
 */
declare(strict_types=1);
if (!defined('ABSPATH')) exit;
$autoload=__DIR__.'/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'CargoUTM\\')) {
        $file=__DIR__.'/src/'.substr($class,9).'.php';
        if (is_file($file)) require_once $file;
    }
});
register_activation_hook(__FILE__,[CargoUTM\Schema::class,'migrate']);
add_action('plugins_loaded',static function(): void {
    if ((int)get_option('cargo_utm_schema_version')!==CargoUTM\Schema::VERSION) CargoUTM\Schema::migrate();
    (new CargoUTM\SitePolicy())->boot();
    (new CargoUTM\PublicGenerator())->boot();
    (new CargoUTM\Admin())->boot();
});
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('cargo-utm migrate',static function() { CargoUTM\Schema::migrate(); WP_CLI::success('Схема проверена и обновлена.'); });
    WP_CLI::add_command('cargo-utm seed',static function() { CargoUTM\Schema::seed(); WP_CLI::success('Тестовые справочники созданы.'); });
}
