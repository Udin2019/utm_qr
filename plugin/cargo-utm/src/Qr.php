<?php
declare(strict_types=1);
namespace CargoUTM;
use chillerlan\QRCode\{QRCode,QROptions};
use chillerlan\QRCode\Common\{EccLevel,Version};
use chillerlan\QRCode\Data\{QRMatrix,QRCodeDataException};

final class Qr {
    public static function svg(string $url, array $brand): string {
        if (!class_exists(QRCode::class)) throw new \RuntimeException('Установите Composer-зависимости плагина.');
        if (Config::production() && empty($brand['approved'])) throw new \InvalidArgumentException('Для production нужен утверждённый профиль.');
        $color = $brand['color'];
        if (!preg_match('/^#[a-f0-9]{6}$/iD', $color)) throw new \InvalidArgumentException('Неверный HEX-цвет.');
        $asset = json_decode($brand['asset_json'],true,512,JSON_THROW_ON_ERROR);
        $serialized = json_encode($asset,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (!hash_equals($brand['asset_hash'],hash('sha256',$serialized))) throw new \RuntimeException('Контрольная сумма логотипа не совпала.');
        if (!isset($asset['paths']) || !is_array($asset['paths']) || !$asset['paths'] || count($asset['paths'])>100) throw new \InvalidArgumentException('Нужны векторные контуры логотипа.');
        $logo = '';
        foreach ($asset['paths'] as $path) {
            if (!is_string($path) || strlen($path)>20000 || !preg_match('/^[MmZzLlHhVvCcSsQqTtAa0-9., eE+\-]+$/D',$path)) throw new \InvalidArgumentException('Небезопасный векторный контур.');
            $logo .= '<path d="'.htmlspecialchars($path,ENT_QUOTES,'UTF-8').'"/>';
        }
        $vb = $asset['viewBox'] ?? [];
        if (count($vb)!==4 || array_filter($vb,fn($v)=>!is_numeric($v) || !is_finite((float)$v) || abs((float)$v)>10000) || $vb[2]<=0 || $vb[3]<=0) throw new \InvalidArgumentException('Неверный viewBox.');
        $layout=self::centeredMatrix($url);
        $data=$layout['data']; $n=count($data); $window=$layout['window'];
        [$wx,$wy,$size]=$window; $total=$n+8; $d='';
        foreach ($data as $y=>$row) foreach ($row as $x=>$module) {
            if (($module & QRMatrix::IS_DARK)!==0) $d.='M'.($x+4).' '.($y+4).'h1v1h-1z';
        }
        $logoBox=implode(' ',array_map(fn($v)=>(string)(float)$v,$vb));
        $center=$total/2; $logoSize=$layout['logo_size']; $plateSize=$layout['plate_size'];
        $logoStart=$center-$logoSize/2; $plateStart=$center-$plateSize/2;
        // Exact 10% white surround on each side; QR modules outside this plate
        // remain untouched, including partial data cells on the plate boundary.
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$total.'" height="'.$total.'" viewBox="0 0 '.$total.' '.$total.'"><rect width="100%" height="100%" fill="#fff"/><path fill="#111111" shape-rendering="crispEdges" d="'.$d.'"/><rect data-role="logo-plate" x="'.$plateStart.'" y="'.$plateStart.'" width="'.$plateSize.'" height="'.$plateSize.'" fill="#fff"/><svg x="'.$logoStart.'" y="'.$logoStart.'" width="'.$logoSize.'" height="'.$logoSize.'" viewBox="'.$logoBox.'" preserveAspectRatio="xMidYMid meet" overflow="hidden"><g fill="'.$color.'">'.$logo.'</g></svg></svg>';

    }

    /** Select a strictly centered data-only window, increasing QR version if needed. */
    public static function centeredMatrix(string $url): array {
        static $windows=[];
        for ($version=7;$version<=40;$version++) {
            if (!array_key_exists($version,$windows)) {
                $patterns=(new QRMatrix(new Version($version),new EccLevel(EccLevel::H)))->initFunctionalPatterns()->getMatrix();
                $n=count($patterns); $windows[$version]=null;
                $max=(int)floor($n*0.15);
                // Both QR width and window width are odd: the center is exact.
                if ($max%2===0) $max--;
                for ($size=$max;$size>=3;$size-=2) {
                    $start=intdiv($n-$size,2); $safe=true;
                    for ($y=$start;$y<$start+$size && $safe;$y++) {
                        for ($x=$start;$x<$start+$size;$x++) {
                            if (($patterns[$y][$x] & ~QRMatrix::IS_DARK)!==QRMatrix::M_NULL) { $safe=false; break; }
                        }
                    }
                    if ($safe) { $windows[$version]=[$start,$start,$size]; break; }
                }
                if ($windows[$version]!==null) {
                    // Baseline is the previous centered logo's viewport size.
                    $baselineLogoSize=$windows[$version][2]-1;
                    $logoSize=$baselineLogoSize*1.25;
                    $plateSize=$logoSize*1.2;
                    $enclosing=(int)ceil($plateSize);
                    if ($enclosing%2===0) $enclosing++;
                    $start=intdiv($n-$enclosing,2); $safe=true;
                    for ($y=$start;$y<$start+$enclosing && $safe;$y++) {
                        for ($x=$start;$x<$start+$enclosing;$x++) {
                            if (($patterns[$y][$x] & ~QRMatrix::IS_DARK)!==QRMatrix::M_NULL) { $safe=false; break; }
                        }
                    }
                    $windows[$version]=$safe?['window'=>[$start,$start,$enclosing],'logo_size'=>$logoSize,'plate_size'=>$plateSize,'baseline_logo_size'=>$baselineLogoSize]:null;
                }
            }
            if ($windows[$version]===null) continue;
            try {
                $qr=new QRCode(new QROptions(['eccLevel'=>EccLevel::H,'version'=>$version,'addQuietzone'=>false]));
                $data=$qr->addByteSegment($url)->getQRMatrix()->getMatrix();
            } catch (QRCodeDataException $e) { continue; }
            [$x,$y,$size]=$windows[$version]['window'];
            // Verify against the final matrix too, before hiding any module.
            for ($dy=0;$dy<$size;$dy++) for ($dx=0;$dx<$size;$dx++) {
                if (($data[$y+$dy][$x+$dx] & ~QRMatrix::IS_DARK)!==QRMatrix::M_DATA) {
                    throw new \RuntimeException('Центральное окно затрагивает служебные модули QR.');
                }
            }
            return ['data'=>$data,'version'=>$version]+$windows[$version];
        }
        throw new \InvalidArgumentException('Не удалось разместить логотип строго по центру без перекрытия служебных модулей. Сократите короткий адрес.');
    }

    public static function png(string $svg, int $size): string {
        if (!in_array($size,[1024,2048,4096],true)) throw new \InvalidArgumentException('Разрешены PNG 1024, 2048, 4096.');
        if (!preg_match('/viewBox="0 0 (\d+) \1"/', $svg,$m)) throw new \RuntimeException('Неизвестный формат сохранённого QR.');
        $modules=(int)$m[1];
        $scale=intdiv($size,$modules);
        // Odd module count + even canvas: an even scale gives equal integer
        // padding on both sides, without a half-pixel shift or blurred modules.
        if ($scale%2!==0) $scale--;
        $pixels=$scale*$modules;
        $command=['/usr/bin/prlimit','--as=268435456','--cpu=6','--','/usr/bin/timeout','--signal=KILL','8','/usr/bin/rsvg-convert','--format=png','--width='.(string)$pixels,'--height='.(string)$pixels];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);
        if (!is_resource($process)) throw new \RuntimeException('Конвертер PNG недоступен.');
        fwrite($pipes[0],$svg); fclose($pipes[0]);
        $png=stream_get_contents($pipes[1],16*1024*1024); fclose($pipes[1]); $exit=proc_close($process);
        if ($exit!==0 || !$png || !str_starts_with($png,"\x89PNG")) throw new \RuntimeException('Не удалось растеризовать QR.');
        $source=imagecreatefromstring($png); if (!$source) throw new \RuntimeException('PNG повреждён.');
        $canvas=imagecreatetruecolor($size,$size); imagefill($canvas,0,0,imagecolorallocate($canvas,255,255,255));
        imagecopy($canvas,$source,intdiv($size-$pixels,2),intdiv($size-$pixels,2),0,0,$pixels,$pixels);
        ob_start(); imagepng($canvas); $out=ob_get_clean(); imagedestroy($source); imagedestroy($canvas); return $out;
    }
}
