<?php
declare(strict_types=1);
namespace CargoUTM;

/** Strict schema for anonymous previews; never HTML-escape stored/input values. */
final class PublicInput {
    public const MAX_JSON_BYTES=16384;
    public const MAX_REQUEST_BYTES=65536;
    private const TEXT_LIMITS=[
        'campaign_name'=>200,'campaign_code'=>120,'brand_id'=>80,'type'=>40,
        'url'=>2048,'place_slug'=>80,'preset'=>40,'utm_source'=>80,
        'utm_medium'=>80,'utm_content'=>160,'utm_term'=>160,'roistat_value'=>280,
        'idempotency_key'=>64,
    ];
    public static function preview(\stdClass $object): array {
        $in=get_object_vars($object);
        $allowed=array_merge(array_keys(self::TEXT_LIMITS),['unit_id','campaign_id','campaign_year','use_roistat','details']);
        if (array_diff(array_keys($in),$allowed)) throw new \InvalidArgumentException('Форма содержит неизвестные поля.');
        foreach (self::TEXT_LIMITS as $key=>$max) {
            if (array_key_exists($key,$in)) $in[$key]=self::text($in[$key],$max);
        }
        $in['unit_id']=self::integer($in['unit_id']??null,1,PHP_INT_MAX);
        $in['campaign_year']=self::integer($in['campaign_year']??null,2000,2100);
        if (array_key_exists('campaign_id',$in) && $in['campaign_id']!=='') $in['campaign_id']=self::integer($in['campaign_id'],1,PHP_INT_MAX);
        if (isset($in['url']) && strlen($in['url'])>2048) throw new \InvalidArgumentException('Слишком длинный URL.');
        if (array_key_exists('use_roistat',$in)) $in['use_roistat']=self::flag($in['use_roistat']);
        $type=$in['type']??'online';
        if (!isset(Rules::TYPES[$type])) throw new \InvalidArgumentException('Неизвестный тип размещения.');
        $details=array_key_exists('details',$in)?$in['details']:new \stdClass();
        if (!$details instanceof \stdClass) throw new \InvalidArgumentException('Параметры размещения должны быть объектом.');
        $details=get_object_vars($details);
        if (array_diff(array_keys($details),Rules::VISIBLE[$type])) throw new \InvalidArgumentException('Неизвестные параметры размещения.');
        foreach ($details as $key=>$value) $details[$key]=$key==='moving'?self::flag($value):self::text($value,250);
        $in['details']=$details;
        return $in;
    }
    private static function text(mixed $value,int $max): string {
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value,'UTF-8')>$max || preg_match('/[\x00-\x1f\x7f]/',$value)) {
            throw new \InvalidArgumentException('Некорректный тип, длина или символы поля.');
        }
        return $value;
    }
    private static function integer(mixed $value,int $min,int $max): int {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,19}$/D',(string)$value)) throw new \InvalidArgumentException('Некорректное числовое поле.');
        $number=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);
        if ($number===false) throw new \InvalidArgumentException('Числовое поле вне допустимого диапазона.');
        return $number;
    }
    private static function flag(mixed $value): bool {
        if (!in_array($value,[true,false,0,1,'0','1'],true)) throw new \InvalidArgumentException('Некорректный переключатель.');
        return in_array($value,[true,1,'1'],true);
    }
}
