<?php
declare(strict_types=1);
namespace CargoUTM;
final class Config {
    public static function env(string $key, string $default=''): string { return getenv($key) ?: $default; }
    public static function production(): bool { return self::env('CARGO_ENV','staging') === 'production'; }
    public static function hosts(): array { return [self::env('CARGO_UTM_HOST','utm.cargo.test'), self::env('CARGO_GO_HOST','go.cargo.test')]; }
    public static function builder(): UrlBuilder {
        $blocked=self::hosts();
        $publicHost=parse_url(self::env('CARGO_PUBLIC_UTM_URL'),PHP_URL_HOST);
        if ($publicHost) $blocked[]=$publicHost;
        return new UrlBuilder(explode(',',self::env('CARGO_ALLOWED_HOSTS','fuel.cargo.example')),$blocked);
    }
    public static function shortLinksEnabled(): bool {
        return self::env('CARGO_SHORT_LINKS_ENABLED','0')==='1';
    }
    public static function short(string $code): string { return 'https://'.self::hosts()[1].'/r/'.$code; }
}
