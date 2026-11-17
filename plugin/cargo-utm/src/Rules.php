<?php
declare(strict_types=1);
namespace CargoUTM;

final class Rules {
    public const VERSION = 2;
    public const TYPES = ['online'=>'Онлайн-кампания','exhibition'=>'Выставка','conference'=>'Конференция','outdoor'=>'Наружный баннер','transport'=>'Транспорт','offline_point'=>'Офлайн-точка','print_media'=>'Печатные издания'];
    public const LABELS = ['event'=>'Название события','event_date'=>'Дата события','city'=>'Город','venue'=>'Площадка','speaker'=>'Докладчик','object_code'=>'Код места, поверхности, точки или транспорта','surface'=>'Носитель / поверхность','zone'=>'Зона размещения','address'=>'Адрес / ориентир','region'=>'Регион','route'=>'Парк / маршрут','side'=>'Сторона','period'=>'Период','moving'=>'Сканирование на ходу','issue'=>'Номер выпуска или дата','position'=>'Полоса или место QR'];
    public const REQUIRED = ['online'=>[], 'exhibition'=>['event','event_date','city','object_code'], 'conference'=>['event','event_date','city','object_code'], 'outdoor'=>['city','object_code'], 'transport'=>['object_code','surface'], 'offline_point'=>['city','object_code','zone'], 'print_media'=>['event','issue','position']];
    public const VISIBLE = ['online'=>[], 'exhibition'=>['event','event_date','city','venue','object_code','surface'], 'conference'=>['event','event_date','city','venue','speaker','object_code','surface'], 'outdoor'=>['city','address','object_code','side','period'], 'transport'=>['region','route','object_code','surface','moving'], 'offline_point'=>['city','object_code','address','zone'], 'print_media'=>['event','issue','position','city']];
    public static function slug(string $text): string {
        $from = preg_split('//u', 'абвгдеёжзийклмнопрстуфхцчшщъыьэюя', -1, PREG_SPLIT_NO_EMPTY);
        $to = ['a','b','v','g','d','e','yo','zh','z','i','y','k','l','m','n','o','p','r','s','t','u','f','kh','ts','ch','sh','shch','','y','','e','yu','ya'];
        $text = strtr(mb_strtolower(trim($text), 'UTF-8'), array_combine($from,$to));
        return trim(preg_replace('/[-_]+/', '-', preg_replace('/[^a-z0-9_-]+/', '-', $text)), '-');
    }
    public static function identifier(string $value, int $max): string {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value) || strlen($value)>$max) {
            throw new \InvalidArgumentException('Уточните код: латинские строчные буквы, цифры, дефис, подчёркивание; максимум '.$max.' знаков.');
        }
        return $value;
    }
    public static function campaignCode(string $custom,string $unit,int $year,string $name): string {
        $code=trim($custom);
        if ($code==='') $code=$unit.'-'.$year.'-'.self::slug($name);
        return self::identifier($code,120);
    }
    public static function source(string $value): string {
        if (strlen($value)>80 || !preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/D',$value)) {
            throw new \InvalidArgumentException('Источник: строчные латинские буквы, цифры, дефис, подчёркивание и точки между сегментами.');
        }
        return $value;
    }
    public static function details(string $type, array $input): array {
        if (!isset(self::TYPES[$type])) throw new \InvalidArgumentException('Неизвестный тип размещения.');
        $out = [];
        foreach (self::VISIBLE[$type] as $key) {
            $out[$key] = trim((string)($input[$key] ?? ''));
            if (mb_strlen($out[$key])>250 || preg_match('/[\x00-\x1f\x7f]/', $out[$key])) throw new \InvalidArgumentException('Недопустимое поле: '.self::LABELS[$key]);
        }
        foreach (self::REQUIRED[$type] as $key) if ($out[$key]==='') throw new \InvalidArgumentException('Заполните: '.self::LABELS[$key]);
        if (!empty($out['event_date'])) self::date($out['event_date']);
        return $out;
    }
    public static function date(string $value): string {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Некорректная дата.');
        return $value;
    }
    public static function utm(array $campaign, string $type, array $input, array $details, string $code): array {
        $utm = ['utm_source'=>(string)($input['utm_source']??''),'utm_medium'=>(string)($input['utm_medium']??''),'utm_campaign'=>$campaign['utm_campaign'],'utm_content'=>(string)($input['utm_content']??''),'utm_term'=>(string)($input['utm_term']??''),'utm_id'=>$campaign['utm_id']];
        if ($type === 'online') {
            self::source($utm['utm_source']); self::identifier($utm['utm_medium'],80);
        }
        if ($type !== 'online') {
            $place = trim((string)($input['place_slug'] ?? ''));
            if ($place==='') $place = $type==='print_media'
                ? self::slug(implode('-',[$details['event'],$details['issue'],$details['position']]))
                : self::slug((($details['surface']??'')?:($details['zone']??$type)).'-'.($details['object_code']??''));
            $utm['utm_source'] = $type; $utm['utm_medium'] = 'qr'; $utm['utm_term'] = '';
            $utm['utm_content'] = self::identifier($place,80).'-p-'.$code;
        }
        return $utm;
    }
    public static function csvCell(mixed $value): string {
        $v = (string)$value;
        return preg_match('/^[\s\x{FEFF}]*[=+@-]/u', $v) || preg_match('/^[\t\r\n]/', $v) ? "'".$v : $v;
    }
}
