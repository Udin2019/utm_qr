<?php
declare(strict_types=1);
namespace CargoUTM;

final class Tracking {
    public const PRESETS = [
        'yandex'=>['label'=>'Яндекс Директ','source'=>'yandex','medium'=>'cpc','key'=>'roistat','template'=>'direct[ID]_{source_type}_{banner_id}_{keyword}'],
        'vk'=>['label'=>'VK Ads','source'=>'vk.ads','medium'=>'cpc','key'=>'rs','template'=>'vkads[ID]_{{banner_id}}_{{search_phrase}}'],
        'avito'=>['label'=>'Avito Ads','source'=>'avito.ads','medium'=>'cpc','key'=>'roistat','template'=>'avitoads[ID]_{campaign_id}_{adgroup_id}_{ad_id}'],
        'tg'=>['label'=>'Telegram Ads','source'=>'tg.ads','medium'=>'cpc','key'=>'roistat','default'=>'telegram_ads'],
        'newsletter'=>['label'=>'Рассылка','source'=>'newsletter','medium'=>'email'],
    ];
    public static function fromInput(array $in,?array $preset=null): array {
        if (empty($in['use_roistat'])) return [];
        if (($in['type']??'online')!=='online') throw new \InvalidArgumentException('Roistat доступен для онлайн-кампаний.');
        $value=trim((string)($in['roistat_value']??''));
        if ($preset && isset($preset['template'])) {
            if (!preg_match('/^[0-9]{1,24}$/D',$value)) throw new \InvalidArgumentException('Введите числовой ID канала в Roistat (до 24 цифр).');
            $tags=[($preset['key']??'roistat')=>str_replace('[ID]',$value,$preset['template'])];
        } else {
            $key='roistat';
            if (preg_match('/^(roistat|rs)=(.*)$/is',$value,$m)) { $key=strtolower($m[1]); $value=$m[2]; }
            $tags=[$key=>$value];
        }
        return self::validate($tags);
    }
    public static function validatePreset(array $settings): void {
        if (!isset($settings['template']) && !isset($settings['key']) && !isset($settings['default'])) return;
        $key=$settings['key']??'roistat';
        if (!in_array($key,['roistat','rs'],true)) throw new \InvalidArgumentException('Параметр пресета должен быть roistat или rs.');
        if (isset($settings['template'])) {
            if (!is_string($settings['template']) || substr_count($settings['template'],'[ID]')!==1) throw new \InvalidArgumentException('Шаблон должен содержать один [ID].');
            self::validate([$key=>str_replace('[ID]','1',$settings['template'])]);
        }
        if (isset($settings['default'])) self::validate([$key=>$settings['default']]);
    }
    public static function validate(array $tags): array {
        if (count($tags)>1) throw new \InvalidArgumentException('Используйте одну метку: roistat или rs.');
        foreach ($tags as $key=>$value) {
            if (!in_array($key,['roistat','rs'],true) || !is_string($value) || $value==='' || !mb_check_encoding($value,'UTF-8') || mb_strlen($value)>280 || preg_match('/[\x00-\x1f\x7f\\\\]/',$value)) {
                throw new \InvalidArgumentException('Некорректная дополнительная метка Roistat.');
            }
            // Braces are retained only for an explicit advertising macro syntax.
            $plain=preg_replace('/\{\{[a-z0-9_.-]+\}\}|\{[a-z0-9_.-]+\}/i','',$value);
            if (strpbrk($plain,'{}')!==false) throw new \InvalidArgumentException('Некорректный рекламный макрос.');
        }
        return $tags;
    }
    public static function encode(string $value): string {
        $parts=preg_split('/(\{\{[a-z0-9_.-]+\}\}|\{[a-z0-9_.-]+\})/i',$value,-1,PREG_SPLIT_DELIM_CAPTURE);
        return implode('',array_map(fn($part,$index)=>$index%2?$part:rawurlencode($part),$parts,array_keys($parts)));
    }
}
