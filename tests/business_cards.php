<?php
declare(strict_types=1);

use CargoUTM\BusinessCardData as Card;
use CargoUTM\BusinessCards;
use CargoUTM\BusinessCardsStore;

spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'CargoUTM\\')) require __DIR__.'/../plugin/cargo-utm/src/'.substr($class,9).'.php';
});
function wp_parse_url(string $value,int $component=-1): mixed { return parse_url($value,$component); }
function home_url(string $path,string $scheme='https'): string { return $scheme.'://utm.cargo.test'.$path; }
function wp_salt(string $scheme): string { return 'test-only-'.$scheme; }
function current_user_can(string $capability): bool { return $GLOBALS['admin'] && in_array($capability,['cargo_manage','cargo_export'],true); }
$GLOBALS['admin']=false;

function expect(bool $condition,string $name): void {
    if (!$condition) throw new RuntimeException('FAIL '.$name);
    echo "PASS: $name\n";
}
function rejects(callable $fn,string $name): void {
    try { $fn(); }
    catch (InvalidArgumentException|DomainException) { expect(true,$name); return; }
    expect(false,$name);
}
function invoke(BusinessCards $service,string $method,mixed ...$args): mixed {
    return (new ReflectionMethod($service,$method))->invoke($service,...$args);
}
$service=new BusinessCards();
$contact=['full_name'=>'Анна Тест','phone'=>'8 (900) 000-00-00','email'=>'Test@EXAMPLE.TEST'];
$person=Card::person($contact,false);
expect($person['phone']==='+79000000000' && $person['email']==='Test@example.test','Нормализация телефона и почты');
expect(Card::person(['full_name'=>'Test','phone'=>'+1 (613) 555-0123'],false)['phone']==='+16135550123','Международный телефон');
rejects(fn()=>Card::person(['full_name'=>'Test'],false),'Нет канала связи');
rejects(fn()=>Card::person(['full_name'=>str_repeat('я',161),'phone'=>'+79000000000'],false),'Ограничение длины');
rejects(fn()=>Card::person($contact+['website'=>'javascript:alert(1)'],true),'Недопустимый протокол ссылки');
rejects(fn()=>Card::person(array_replace($contact,['full_name'=>"Name\r\nX:abc"]),false),'Управляющие символы');
$vcard=Card::vcard(['full_name'=>'Тест, Анна;','description'=>str_repeat('я',100)."\nX: value",'owner'=>'private','comment'=>'private']);
expect(str_contains($vcard,'FN:Тест\\, Анна\\;') && str_contains(str_replace("\r\n ",'',$vcard),'\\nX: value') && !str_contains($vcard,'private') &&
    max(array_map('strlen',explode("\r\n",$vcard)))<=75 && preg_match('//u',$vcard)===1,'VCF — экранирование, переносы UTF-8 и список публичных полей');
foreach (['=SUM(A1)','  +2','-2','@name',"\tvalue","\rvalue"] as $value) {
    if (Card::csvCell($value)!=="'".$value) throw new RuntimeException('CSV injection');
}
expect(Card::csvCell('Анна')==='Анна','Защита CSV от формул');
putenv('CARGO_ENV=production');
putenv('CARGO_CARDS_SYNTHETIC=1');
putenv('LEGAL_TEXTS_APPROVED=false');
expect(invoke($service,'documents')===[],'Production блокирует синтетические данные и включает сбор только после утверждения');
expect(invoke($service,'policy',['policy'=>['version'=>'bad version','text'=>'Тест']])===null &&
    invoke($service,'policy',['policy'=>['version'=>'v1','text'=>'Тест']])['version']==='v1',
    'Политика отображается при наличии текста и допустимой версии');
rejects(fn()=>invoke($service,'submit','m'.str_repeat('0',32),$contact),'Production отклоняет отправку до доступа к БД');
expect(invoke($service,'state',['status'=>'waiting','expires_at'=>'2000-01-01 00:00:00'])==='expired','Серверная проверка TTL');
expect(str_contains(BusinessCardsStore::tables()['cards_leads'],'UNIQUE KEY session_id (session_id)'),'Ограничение одной передачи на сессию');

if (!isset($argv[1])) exit;
if (!preg_match('~^/tmp/cargo-cards-db\.[a-zA-Z0-9]+/test[.]sock$~D',$argv[1]) || !file_exists($argv[1])) throw new RuntimeException('Тест БД требует отдельный временный Unix socket.');
require __DIR__.'/../plugin/cargo-utm/vendor/autoload.php';
const ARRAY_A='ARRAY_A';
final class wpdb {
    public string $prefix='fixture_';
    public string $last_error='';
    public int $insert_id=0;
    public function __construct(private mysqli $db) {}
    public function hide_errors(): void {}
    public function suppress_errors(bool $enabled): void {}
    public function prepare(string $query,mixed ...$args): string {
        return preg_replace_callback('/%[sd]/',function(array $match) use (&$args): string {
            $arg=array_shift($args);
            return $match[0]==='%d'?(string)(int)$arg:"'".$this->db->real_escape_string((string)$arg)."'";
        },$query);
    }
    public function query(string $query): int { $this->db->query($query); return $this->db->affected_rows; }
    public function get_results(string $query,string $mode): array { return $this->db->query($query)->fetch_all(MYSQLI_ASSOC); }
    public function get_row(string $query,string $mode): ?array { return $this->db->query($query)->fetch_assoc(); }
    public function insert(string $table,array $data): int {
        $values=array_map(fn($value)=>$value===null?'NULL':$this->prepare('%s',$value),$data);
        $count=$this->query("INSERT INTO $table (".implode(',',array_keys($data)).') VALUES ('.implode(',',$values).')');
        $this->insert_id=$this->db->insert_id;
        return $count;
    }
    public function update(string $table,array $data,array $where): int {
        $clauses=fn(array $fields)=>array_map(fn($key,$value)=>$key.'='.$this->prepare('%s',$value),array_keys($fields),$fields);
        return $this->query("UPDATE $table SET ".implode(',',$clauses($data)).' WHERE '.implode(' AND ',$clauses($where)));
    }
}
$db=new mysqli('localhost','root','','',0,$argv[1]);
$db->set_charset('utf8mb4');
$db->query('CREATE DATABASE cards_fixture');
$db->select_db('cards_fixture');
foreach (BusinessCardsStore::tables() as $name=>$shape) {
    $db->query("CREATE TABLE fixture_cargo_utm_$name ($shape) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
$store=new BusinessCardsStore(new wpdb($db));
(new ReflectionProperty($service,'store'))->setValue($service,$store);

putenv('CARGO_ENV=staging');
$profile=invoke($service,'save',11,$contact+['active'=>true]);
$other=invoke($service,'save',12,['full_name'=>'Другой менеджер','email'=>'other@example.test','active'=>true]);
expect($profile['profile']['full_name']==='Анна Тест' && $profile['url']!==$other['url'],'Изолированные профили двух менеджеров');
$session=invoke($service,'session',11,'start',['meeting'=>'Синтетическая встреча']);
expect(preg_match('~^https://utm.cargo.test/c/s[a-f0-9]{32}$~D',$session['url'])===1 && $session['url']!==$profile['url'] && str_contains($session['qr'],'<svg'),'HTTPS, постоянный и сессионный QR');
rejects(fn()=>invoke($service,'session',12,'poll',['id'=>$session['id']]),'Чужая сессия недоступна');
$key=basename($session['url']);
$public=invoke($service,'publicCard',$key);
expect(isset($public['data']) && !isset($public['owner']) && !isset($public['token_hash']),'Публичная проекция без владельческих сведений');
$documents=invoke($service,'documents');
$payload=$contact+['signature'=>invoke($service,'signature',$key,$documents),'submission'=>str_repeat('a',32),'pd'=>true];
rejects(fn()=>invoke($service,'submit',$key,array_replace($payload,['pd'=>false])),'Нет обязательного согласия');
expect(invoke($service,'submit',$key,$payload)===['marketing'=>false],'Запись без рекламного согласия');
expect(invoke($service,'submit',$key,$payload)===['marketing'=>false],'Идемпотентный сетевой повтор');
rejects(fn()=>invoke($service,'submit',$key,array_replace($payload,['submission'=>str_repeat('b',32)])),'Вторая отправка в закрытую сессию');
$poll=invoke($service,'session',11,'poll',['id'=>$session['id']]);
$leadId=(int)$poll['lead']['id'];
expect($poll['status']==='completed' && $leadId>0,'Polling возвращает факт передачи и доступный лид');
rejects(fn()=>invoke($service,'lead',12,'lead',['id'=>$leadId]),'Чужой лид недоступен');
$lead=invoke($service,'lead',11,'lead',['id'=>$leadId])['lead'];
expect(count($lead['consents'])===2 && $lead['consents'][0]['version']==='synthetic-1' &&
    hash('sha256',$lead['consents'][0]['document'])===$lead['consents'][0]['document_hash'],'Неизменяемый снимок документа и хеш');
$next=invoke($service,'session',11,'start',[]);
invoke($service,'session',11,'cancel',['id'=>$next['id']]);
expect(invoke($service,'session',11,'poll',['id'=>$next['id']])['status']==='cancelled','Отмена сессии');
$next=invoke($service,'session',11,'start',[]);
$store->update('sessions',['expires_at'=>'2000-01-01 00:00:00'],['id'=>$next['id']]);
expect(invoke($service,'session',11,'poll',['id'=>$next['id']])['status']==='expired','Истечение и фиксация статуса в БД');
$key=basename($profile['url']);
$payload=array_replace($payload,['signature'=>invoke($service,'signature',$key,$documents),'submission'=>str_repeat('c',32),'marketing'=>true]);
expect(invoke($service,'submit',$key,$payload)===['marketing'=>true],'Постоянная ссылка и отдельное рекламное согласие');
invoke($service,'submit',$key,array_replace($payload,['submission'=>str_repeat('d',32)]));
$items=invoke($service,'leads',11,'leads',['source'=>'permanent_card'])['items'];
$duplicate=(int)$items[0]['id'];
expect(count($items)===2 && invoke($service,'lead',11,'lead',['id'=>$duplicate])['lead']['possible_duplicate'],'Повтор по постоянной ссылке помечается, согласия сохраняются отдельно');
$GLOBALS['admin']=true;
invoke($service,'lead',12,'withdraw',['id'=>$duplicate,'purpose'=>'marketing']);
expect(invoke($service,'lead',12,'lead',['id'=>$duplicate])['lead']['status']==='new','Отзыв рекламы независим');
invoke($service,'lead',12,'withdraw',['id'=>$duplicate,'purpose'=>'pd']);
$GLOBALS['admin']=false;
rejects(fn()=>invoke($service,'lead',11,'lead',['id'=>$duplicate]),'Отзыв ПД исключает контакт из обычного доступа');
$GLOBALS['admin']=true;
invoke($service,'lead',12,'erase',['id'=>$duplicate]);
expect(invoke($service,'lead',12,'lead',['id'=>$duplicate])['lead']['phone']==='','Обезличивание убирает контактные поля');
expect(count(invoke($service,'stats',12)['cards'])===2,'Администратор видит корпоративные визитки');
