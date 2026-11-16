<?php
// Load with wp eval 'require "/tmp/cargo-acceptance.php";' on staging.
// A separate require preserves strict_types; eval-file rewrites the file body.
declare(strict_types=1);
use CargoUTM\{Config,Schema,Store,Qr,Rules};
if (Config::production()) throw new RuntimeException('Acceptance fixtures are staging-only.');
global $wpdb;
$beforeTable=$wpdb->prefix.'cargo_utm_placements';
$baseline=get_option('cargo_utm_schema_version') ? $wpdb->get_results("SELECT id,short_code,target_url,qr_hash,rules_version FROM {$beforeTable} ORDER BY id",ARRAY_A) : [];
Schema::migrate(); Schema::migrate(); Schema::seed();
$after=$wpdb->get_results("SELECT id,short_code,target_url,qr_hash,rules_version FROM {$beforeTable} ORDER BY id",ARRAY_A);
if ($baseline && $baseline!==$after) throw new RuntimeException('Migration changed existing placements');
$store=new Store(); global $wpdb;
function verify(bool $condition,string $label): void { if (!$condition) throw new RuntimeException('FAIL: '.$label); echo 'PASS: '.$label."\n"; }
$user=get_user_by('login','cargo_acceptance_editor');
if (!$user) { $id=wp_insert_user(['user_login'=>'cargo_acceptance_editor','user_pass'=>wp_generate_password(40),'role'=>'cargo_editor']); if (is_wp_error($id)) throw new RuntimeException('Cannot create test editor'); $user=get_user_by('id',$id); }
wp_set_current_user($user->ID);
verify(current_user_can('cargo_create') && current_user_can('cargo_view') && !current_user_can('cargo_manage'),'editor capabilities');
$units=$wpdb->get_results('SELECT * FROM '.$store->table('units').' WHERE active=1 ORDER BY id',ARRAY_A); $unit=$units[0];
$campaign=$store->campaign(['unit_id'=>$unit['id'],'name'=>'Логистика Экспо · приёмка '.bin2hex(random_bytes(3)),'year'=>2027]);
$c=$store->row('campaigns',$campaign); verify((bool)preg_match('/^cg-[a-f0-9]{16}$/D',$c['utm_id']),'campaign stable ID');
$brands=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$store->table('brand_versions').' WHERE active=1 AND (unit_id=%d OR unit_id IS NULL) ORDER BY unit_id DESC',$unit['id']),ARRAY_A);
$brand=$brands[0]; $ids=[];
foreach (Rules::TYPES as $type=>$label) {
    $details=['event'=>'Логистика Экспо','event_date'=>'2027-05-10','city'=>'Москва','object_code'=>'A12','surface'=>'Стойка у входа','zone'=>'Вход','region'=>'Москва','route'=>'Парк 07','address'=>'Тестовый адрес','venue'=>'Экспоцентр','moving'=>'да','issue'=>'№ 3, апрель 2027','position'=>'Полоса 12'];
    $in=['unit_id'=>$unit['id'],'campaign_id'=>$campaign,'type'=>$type,'url'=>'https://fuel.cargo.example/cards?product=diesel#request','brand_id'=>$brand['id'],'place_slug'=>'entrance-a12','utm_source'=>'yandex','utm_medium'=>'cpc','utm_content'=>'тест','utm_term'=>'дизель','details'=>$details,'idempotency_key'=>bin2hex(random_bytes(16))];
    $id=$store->issue($in); $ids[$type]=$id;
    verify($id===$store->issue($in),'idempotency '.$type);
    $in['utm_term']='changed'; $conflict=false;
    try { $store->issue($in); } catch (DomainException $e) { $conflict=$e->getCode()===409; }
    verify($conflict,'idempotency conflict '.$type);
    $in['idempotency_key']=bin2hex(random_bytes(16)); $second=$store->issue($in);
    verify($store->row('placements',$second)['short_code']!==$store->row('placements',$id)['short_code'],'new code '.$type);
}
$old=$store->row('placements',$ids['exhibition']);
$store->change((int)$old['id'],['url'=>'https://fuel.cargo.example/new?x=1','reason'=>'Тест сохранения печатного кода','status'=>'archived']);
$new=$store->row('placements',(int)$old['id']);
foreach (array_merge(['short_code','qr_svg','qr_hash'],CargoUTM\UrlBuilder::FIELDS) as $key) verify($new[$key]===$old[$key],'destination preserves '.$key);
verify(str_contains($new['target_url'],'/new?x=1&'),'destination changed');
verify((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$store->table('audit').' WHERE placement_id=%d AND action=%s',$old['id'],'change'))===1,'change audit');
$store->change($ids['outdoor'],['status'=>'revoked']);
verify($store->row('placements',$ids['outdoor'])['status']==='revoked','revocation');
$partnerIn=['unit_id'=>$unit['id'],'campaign_id'=>$campaign,'type'=>'online','url'=>'https://fuel.cargo.example/cards','brand_id'=>$brand['id'],'utm_source'=>'partner.ru','utm_medium'=>'referral','details'=>[],'preset'=>'partner_site','idempotency_key'=>bin2hex(random_bytes(16))];
$partnerId=$store->issue($partnerIn);
$partnerRow=$store->row('placements',$partnerId);
verify($partnerRow['utm_source']==='partner.ru' && $partnerRow['utm_medium']==='referral','partner site referral tags');
verify($store->issue($partnerIn)===$partnerId,'partner site idempotency');
$partnerSnapshot=json_decode($partnerRow['tracking_json'],true,512,JSON_THROW_ON_ERROR);
$partnerPreset=$store->row('template_versions',(int)$partnerSnapshot['preset_id']);
verify($partnerPreset['template_code']==='partner_site','partner site preset snapshot');
$trackingIn=['unit_id'=>$unit['id'],'campaign_id'=>$campaign,'type'=>'online','url'=>'https://fuel.cargo.example/cards?product=diesel&roistat=old&rs=old#request','brand_id'=>$brand['id'],'utm_source'=>'vk.ads','utm_medium'=>'cpc','utm_content'=>'banner','utm_term'=>'','details'=>[],'preset'=>'vk','use_roistat'=>true,'roistat_value'=>'12','idempotency_key'=>bin2hex(random_bytes(16))];
$trackingId=$store->issue($trackingIn); $tracked=$store->row('placements',$trackingId);
verify(str_contains($tracked['target_url'],'rs=vkads12_{{banner_id}}_{{search_phrase}}'),'Roistat macros and rs');
verify(!str_contains($tracked['target_url'],'roistat=old') && !str_contains($tracked['target_url'],'rs=old'),'old tracking replaced');
verify($trackingId===$store->issue($trackingIn),'tracking idempotency');
$trackingIn['roistat_value']='13'; $conflict=false;
try { $store->issue($trackingIn); } catch (DomainException $e) { $conflict=$e->getCode()===409; }
verify($conflict,'tracking idempotency conflict');
$store->change($trackingId,['url'=>'https://fuel.cargo.example/new?x=1','reason'=>'Roistat regression']);
$changed=$store->row('placements',$trackingId);
verify($changed['tracking_json']===$tracked['tracking_json'] && str_contains($changed['target_url'],'rs=vkads12_{{banner_id}}_{{search_phrase}}'),'tracking snapshot preserved on destination change');
$workspace=new CargoUTM\Workspace();
$card=$workspace->record($trackingId);
verify($card['tags']['rs']==='vkads12_{{banner_id}}_{{search_phrase}}' && !isset($card['qr_svg'],$card['request_hash']),'workspace card tags and payload');
$list=$workspace->registry(['q'=>$tracked['short_code'],'status'=>'all']);
verify($list['count']===1 && (int)$list['rows'][0]['id']===$trackingId,'workspace registry real data');
verify(count($baseline)===count($after),'migration preserves placement count');
$dir='/tmp/cargo-acceptance'; if (!is_dir($dir)) mkdir($dir,0700,true);
$manifest=[];
$allBrands=$wpdb->get_results('SELECT * FROM '.$store->table('brand_versions').' WHERE active=1 ORDER BY id',ARRAY_A);
foreach ($allBrands as $b) {
    $profileUnit=$b['unit_id']?:$unit['id'];
    $profileCampaign=$store->campaign(['unit_id'=>$profileUnit,'name'=>'QR-профиль '.bin2hex(random_bytes(5)),'year'=>2027]);
    $in['unit_id']=$profileUnit; $in['campaign_id']=$profileCampaign;
    $in['type']='exhibition'; $in['details']=$details; $in['brand_id']=$b['id']; $in['idempotency_key']=bin2hex(random_bytes(16));
    $id=$store->issue($in); $r=$store->row('placements',$id); $stem='brand-'.$b['id'];
    file_put_contents($dir.'/'.$stem.'.svg',$r['qr_svg']);
    foreach ([1024,2048,4096] as $size) file_put_contents($dir.'/'.$stem.'-'.$size.'.png',Qr::png($r['qr_svg'],$size));
    preg_match('/viewBox="0 0 (\d+) \1"/',$r['qr_svg'],$dimensions);
    $qrVersion=intdiv((int)$dimensions[1]-8-17,4);
    $matrix=(new chillerlan\QRCode\QRCode(new chillerlan\QRCode\QROptions(['eccLevel'=>chillerlan\QRCode\Common\EccLevel::H,'version'=>$qrVersion,'addQuietzone'=>false])))->addByteSegment(Config::short($r['short_code']))->getQRMatrix()->getMatrix();
    $protected=[];
    foreach ($matrix as $y=>$row) foreach ($row as $x=>$module) if (($module & ~chillerlan\QRCode\Data\QRMatrix::IS_DARK)!==chillerlan\QRCode\Data\QRMatrix::M_DATA) $protected[]=[$x,$y,(bool)($module & chillerlan\QRCode\Data\QRMatrix::IS_DARK)];
    $manifest[]=['stem'=>$stem,'url'=>Config::short($r['short_code']),'modules'=>count($matrix)+8,'protected'=>$protected];
}
file_put_contents($dir.'/manifest.json',wp_json_encode($manifest));
file_put_contents($dir.'/http-fixtures.json',wp_json_encode(['active'=>$store->row('placements',$ids['conference']),'archived'=>$new,'revoked'=>$store->row('placements',$ids['outdoor'])]));
wp_set_current_user(0);
$viewer=get_user_by('login','cargo_acceptance_viewer');
if (!$viewer) { $id=wp_insert_user(['user_login'=>'cargo_acceptance_viewer','user_pass'=>wp_generate_password(40),'role'=>'cargo_viewer']); if (is_wp_error($id)) throw new RuntimeException('Cannot create viewer'); $viewer=get_user_by('id',$id); }
wp_set_current_user($viewer->ID);
verify(current_user_can('cargo_view') && current_user_can('cargo_export') && !current_user_can('cargo_create') && !current_user_can('cargo_manage'),'viewer capabilities');
echo "Integration fixtures ready in /tmp/cargo-acceptance\n";
