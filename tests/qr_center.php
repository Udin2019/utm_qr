<?php
declare(strict_types=1);
$autoload=__DIR__.'/../plugin/cargo-utm/vendor/autoload.php';
if (!is_file($autoload)) { echo "SKIP: centered QR checks need Composer dependencies\n"; exit; }
require $autoload;
use CargoUTM\Qr;
use chillerlan\QRCode\Data\QRMatrix;
$asset=json_decode(file_get_contents(__DIR__.'/../plugin/cargo-utm/assets/ui/brand-profile.json'),true,512,JSON_THROW_ON_ERROR);
$json=json_encode($asset,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
$brand=['asset_json'=>$json,'asset_hash'=>hash('sha256',$json),'approved'=>0,'color'=>'#20212b'];
$urls=['https://go.cargo.test/r/123456789abc','https://go.cargo.test/r/abcdefabcdef','https://go.cargonomica.example/r/0123456789ab','https://'.str_repeat('a',60).'.example/r/abcdef123456'];
foreach ($urls as $url) {
    $layout=Qr::centeredMatrix($url); $data=$layout['data']; $n=count($data); [$x,$y,$size]=$layout['window'];
    if (2*$x+$size!==$n || 2*$y+$size!==$n || $size>$n*0.23) throw new RuntimeException('Invalid centered window geometry');
    if ($layout['logo_size']!==$layout['baseline_logo_size']*1.25 || abs(($layout['plate_size']-$layout['logo_size'])/2-$layout['logo_size']*0.1)>1e-9) throw new RuntimeException('Incorrect logo growth or surround');
    for ($dy=0;$dy<$size;$dy++) for ($dx=0;$dx<$size;$dx++) if (($data[$y+$dy][$x+$dx]&~QRMatrix::IS_DARK)!==QRMatrix::M_DATA) throw new RuntimeException('Function pattern overwritten');
    $svg=Qr::svg($url,$brand);
    if (!preg_match('/<svg x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)"/',$svg,$m)) throw new RuntimeException('Missing logo viewport');
    if ((float)$m[1]+(float)$m[3]/2!==($n+8)/2 || (float)$m[2]+(float)$m[4]/2!==($n+8)/2) throw new RuntimeException('Logo is off-center');
    if ((float)$m[3]!==$layout['logo_size']) throw new RuntimeException('Logo SVG dimensions differ');
    if (!preg_match('/<rect data-role="logo-plate" x="([\d.]+)" y="([\d.]+)" width="([\d.]+)" height="([\d.]+)" fill="#fff"/',$svg,$plate)) throw new RuntimeException('Missing white surround');
    if (abs((float)$m[1]-(float)$plate[1]-(float)$m[3]*0.1)>1e-9 || (float)$plate[3]!==$layout['plate_size']) throw new RuntimeException('White surround SVG geometry');
    foreach ($data as $dy=>$row) foreach ($row as $dx=>$module) {
        if (($module & ~QRMatrix::IS_DARK)===QRMatrix::M_DATA) continue;
        $path='M'.($dx+4).' '.($dy+4).'h1v1h-1z';
        if (str_contains($svg,$path)!==(bool)($module & QRMatrix::IS_DARK)) throw new RuntimeException('Function module mismatch in SVG');
    }
}
echo 'PASS: '.count($urls)." centered logo SVGs; +25% size, 10% white surround, functional patterns intact\n";
