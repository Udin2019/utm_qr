<?php
declare(strict_types=1);
namespace CargoUTM;
final class Decoder {
    public static function parse(string $input,string $goHost): array {
        if (strlen($input)>8192) throw new \InvalidArgumentException('Слишком длинная ссылка.');
        if (preg_match('/^[a-f0-9]{12}$/D',$input)) return ['code'=>$input,'utm'=>[]];
        $p=parse_url($input); if (!$p || !isset($p['scheme'],$p['host'])) throw new \InvalidArgumentException('Введите абсолютный URL или короткий код.');
        $code=null; $utm=[]; $tracking=[];
        if (strtolower($p['scheme'])==='https' && strtolower($p['host'])===$goHost && !isset($p['user']) && !isset($p['pass']) && ($p['port']??443)===443 && preg_match('~^/r/([a-f0-9]{12})$~D',$p['path']??'',$m)) return ['code'=>$m[1],'utm'=>[]];
        foreach (explode('&',$p['query']??'') as $pair) {
            [$k,$v]=array_pad(explode('=',$pair,2),2,''); $k=strtolower(urldecode($k));
            if (in_array($k,['roistat','rs'],true)) {
                if (isset($tracking[$k])) throw new \InvalidArgumentException('Неоднозначная ссылка: повторяющаяся метка '.$k);
                $tracking[$k]=urldecode($v);
            }
            if (in_array($k,UrlBuilder::FIELDS,true)) {
                if (isset($utm[$k])) throw new \InvalidArgumentException('Неоднозначная ссылка: повторяющаяся метка '.$k);
                $utm[$k]=urldecode($v);
            }
        }
        if (!$code && preg_match('/-p-([a-f0-9]{12})$/D',$utm['utm_content']??'',$m)) $code=$m[1];
        return compact('code','utm')+($tracking?['tracking'=>$tracking]:[]);
    }
}
