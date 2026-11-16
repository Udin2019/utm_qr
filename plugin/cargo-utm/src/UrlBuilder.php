<?php
declare(strict_types=1);
namespace CargoUTM;

final class UrlBuilder {
    public const FIELDS = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term','utm_id'];
    private array $allowed;
    private array $blocked;
    public function __construct(array $allowed, array $blocked) {
        $this->allowed = array_map(self::host(...), $allowed);
        $this->blocked = array_map(self::host(...), $blocked);
    }
    public static function host(string $host): string {
        $host = mb_strtolower($host, 'UTF-8');
        if (preg_match('/[^\x00-\x7f]/', $host)) {
            if (!function_exists('idn_to_ascii')) throw new \InvalidArgumentException('Для IDN требуется PHP intl.');
            $host = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($host === false) throw new \InvalidArgumentException('Некорректный IDN-хост.');
        }
        if (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || str_ends_with($host, '.')) {
            throw new \InvalidArgumentException('Некорректный хост.');
        }
        return $host;
    }
    public function build(string $input, array $utm, array $tracking=[]): array {
        if (!mb_check_encoding($input, 'UTF-8') || preg_match('/[\x00-\x20\x7f\\\\]/', $input)
            || preg_match('/%(?![0-9a-f]{2})/i', $input)
            || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($input))) {
            throw new \InvalidArgumentException('URL содержит пробел, управляющий символ, обратную косую черту или неверное %-кодирование.');
        }
        $p = parse_url($input);
        if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])
            || isset($p['user']) || isset($p['pass']) || ($p['port'] ?? 443) !== 443) {
            throw new \InvalidArgumentException('Нужен абсолютный HTTPS URL без логина и с портом 443.');
        }
        $host = self::host($p['host']);
        if (!in_array($host, $this->allowed, true) || in_array($host, $this->blocked, true)) {
            throw new \InvalidArgumentException('Домен назначения не входит в разрешённый список.');
        }
        foreach (['utm_source','utm_medium','utm_campaign'] as $field) {
            if (trim((string)($utm[$field] ?? '')) === '') throw new \InvalidArgumentException('Обязательное поле: '.$field);
        }
        foreach (['utm_source'=>80,'utm_medium'=>80,'utm_campaign'=>120,'utm_content'=>160,'utm_term'=>160,'utm_id'=>40] as $key=>$max) {
            $v = (string)($utm[$key] ?? '');
            if (!mb_check_encoding($v, 'UTF-8') || mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f]/', $v)) {
                throw new \InvalidArgumentException('Недопустимое значение или длина '.$key);
            }
        }
        $tracking=Tracking::validate($tracking);
        $pairs = []; $removed = [];
        if (isset($p['query']) && $p['query'] !== '') {
            foreach (explode('&', $p['query']) as $pair) {
                $name = rawurldecode(explode('=', $pair, 2)[0]);
                if (str_starts_with(strtolower($name), 'utm_') || ($tracking && in_array(strtolower($name),['roistat','rs'],true))) { $removed[] = $name; continue; }
                $pairs[] = self::encodeRaw($pair);
            }
        }
        foreach (self::FIELDS as $name) {
            if (isset($utm[$name]) && (string)$utm[$name] !== '') $pairs[] = $name.'='.rawurlencode((string)$utm[$name]);
        }
        foreach ($tracking as $name=>$value) $pairs[]=$name.'='.Tracking::encode($value);
        $url = 'https://'.$host.(isset($p['port']) ? ':443' : '').self::encodeRaw($p['path'] ?? '');
        if ($pairs) $url .= '?'.implode('&', $pairs);
        if (isset($p['fragment'])) $url .= '#'.self::encodeRaw($p['fragment']);
        if (strlen($url) > 2048) throw new \InvalidArgumentException('Готовая ссылка длиннее 2048 байт. Сократите URL или метки.');
        return ['url'=>$url, 'removed'=>$removed];
    }
    private static function encodeRaw(string $value): string {
        return preg_replace_callback('/[^A-Za-z0-9\-._~!$&\x27()*+,;=:@\/?%]/', fn($m)=>rawurlencode($m[0]), $value);
    }
}
