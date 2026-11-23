<?php
declare(strict_types=1);
namespace CargoUTM;

final class BusinessCardData {
    public const PROFILE = ['full_name'=>160,'company'=>160,'position'=>120,'phone'=>40,'email'=>254,'website'=>500,'description'=>600];
    public const CONTACT = ['full_name'=>160,'phone'=>40,'email'=>254,'company'=>160,'position'=>120,'comment'=>600];

    public static function fields(array $input, array $limits): array {
        $result=[];
        foreach ($limits as $key=>$max) {
            $value=$input[$key]??'';
            if (!is_string($value) || !preg_match('//u',$value) || preg_match('/[\x00-\x1F\x7F]/u',$value) || mb_strlen($value)>$max) {
                throw new \InvalidArgumentException('Проверьте длину и формат полей.');
            }
            $result[$key]=trim($value);
        }
        return $result;
    }

    public static function person(array $input, bool $profile): array {
        $data=self::fields($input,$profile?self::PROFILE:self::CONTACT);
        if ($data['full_name']==='') throw new \InvalidArgumentException('Укажите имя.');
        if ($data['phone']!=='') {
            if (!preg_match('/^\+?[0-9 ().-]+$/D',$data['phone'])) throw new \InvalidArgumentException('Проверьте номер телефона.');
            $digits=preg_replace('/\D/','',$data['phone']);
            if (strlen($digits)<7 || strlen($digits)>15) throw new \InvalidArgumentException('Проверьте номер телефона.');
            $data['phone']=match(true) {
                str_starts_with($data['phone'],'+')=>'+'.$digits,
                strlen($digits)===11 && $digits[0]==='8'=>'+7'.substr($digits,1),
                strlen($digits)===11 && $digits[0]==='7'=>'+'.$digits,
                default=>$digits,
            };
        }
        if ($data['email']!=='') {
            if (!filter_var($data['email'],FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Проверьте адрес электронной почты.');
            $separator=strrpos($data['email'],'@');
            $data['email']=substr($data['email'],0,$separator+1).strtolower(substr($data['email'],$separator+1));
        }
        if ($data['email']==='' && $data['phone']==='') throw new \InvalidArgumentException('Укажите телефон или почту.');
        if ($profile && $data['website']!=='' && (!filter_var($data['website'],FILTER_VALIDATE_URL) ||
            !in_array(parse_url($data['website'],PHP_URL_SCHEME),['http','https'],true) ||
            parse_url($data['website'],PHP_URL_USER)!==null)) throw new \InvalidArgumentException('Укажите ссылку на рабочий сайт с https:// или http://.');
        return $data;
    }

    public static function vcard(array $data): string {
        $lines=['BEGIN:VCARD','VERSION:3.0'];
        foreach (['N'=>'full_name','FN'=>'full_name','ORG'=>'company','TITLE'=>'position','TEL'=>'phone','EMAIL'=>'email','URL'=>'website','NOTE'=>'description'] as $name=>$key) {
            if (($data[$key]??'')==='') continue;
            $value=strtr($data[$key],["\\"=>"\\\\","\r\n"=>"\\n","\r"=>"\\n","\n"=>"\\n",";"=>"\\;",","=>"\\,"]);
            $line=$name.':'.$value.($name==='N'?';;;;':'');
            while (strlen($line)>75) {
                $chunk=mb_strcut($line,0,75,'UTF-8');
                $lines[]=$chunk;
                $line=' '.substr($line,strlen($chunk));
            }
            $lines[]=$line;
        }
        $lines[]='END:VCARD';
        return implode("\r\n",$lines)."\r\n";
    }

    public static function csvCell(string $value): string {
        return preg_match('/^(?:[\p{Z}\p{C}]*[=+@\-]|[\t\r\n])/u',$value) ? "'".$value : $value;
    }
}
