<?php
declare(strict_types=1);
require __DIR__.'/../plugin/cargo-utm/src/Config.php';
require __DIR__.'/../plugin/cargo-utm/src/LoginMail.php';
require __DIR__.'/../plugin/cargo-utm/src/EmailLogin.php';
$cache=[]; $users=[]; $mail=[]; $mailOk=true; $created=0; $session=null; $assertions=0;
function wp_salt(string $kind): string { return 'unit-test-only-salt'; }
function get_transient(string $key): mixed { global $cache; return $cache[$key]??false; }
function set_transient(string $key,mixed $value,int $ttl): bool { global $cache; $cache[$key]=$value; return true; }
function delete_transient(string $key): bool { global $cache; $exists=isset($cache[$key]); unset($cache[$key]); return $exists; }
function add_action(...$args): void {}
function remove_action(...$args): void {}
function wp_mail(string $email,string $subject,string $body,array $headers): bool { global $mail,$mailOk; $mail=compact('email','subject','body'); return $mailOk; }
function get_user_by(string $kind,mixed $value): mixed { global $users; foreach ($users as $user) if (($kind==='email'?$user->email:$user->ID)===$value) return $user; return false; }
function get_role(string $role): bool { return $role==='cargo_editor'; }
function wp_generate_password(...$args): string { return 'random-test-password'; }
function wp_insert_user(array $data): int {
    global $users,$created; ++$created;
    if ($data['role']!=='cargo_editor') throw new RuntimeException('Unexpected role');
    $user=new TestUser(count($users)+1,$data['user_email'],['cargo_view','cargo_create','cargo_export']); $users[]=$user; return $user->ID;
}
function is_wp_error(mixed $value): bool { return false; }
function is_super_admin(int $id): bool { return false; }
function wp_set_current_user(int $id): void { global $session; $session=$id; }
function wp_set_auth_cookie(int $id,bool $remember,bool $secure): void { if (!$secure || $remember) throw new RuntimeException('Insecure cookie'); }
function do_action(...$args): void {}
final class TestUser {
    public string $user_login='test';
    public function __construct(public int $ID,public string $email,public array $caps) {}
    public function has_cap(string $cap): bool { return in_array($cap,$this->caps,true); }
}
function ok(bool $condition,string $label): void { global $assertions; ++$assertions; if (!$condition) throw new RuntimeException($label); }
function rejects(callable $fn,string $label): void { try { $fn(); } catch (InvalidArgumentException|DomainException|RuntimeException $e) { ok(true,$label); return; } throw new LogicException($label); }
function otp(): string { global $mail; preg_match('/[0-9]{6}/',$mail['body'],$m); return $m[0]; }
function recordKey(): string { global $cache; foreach ($cache as $key=>$value) if (str_starts_with($key,'cargo_login_code_')) return $key; throw new LogicException('No code'); }
foreach (['a@example.com','a@sub.cargonomica.com','a@cargonomica.com.evil.test',"a@cargonomica.com\n",'a@@cargonomica.com','a@cаrgonomica.com',[],null] as $email) rejects(fn()=>CargoUTM\EmailLogin::email($email),'Reject noncorporate/malformed');
ok(CargoUTM\EmailLogin::email('Alice@CARGONOMICA.COM')==='alice@cargonomica.com','Normalize domain');
foreach (['CARGO_EMAIL_LOGIN_ENABLED=0','CARGO_SMTP_HOST=smtp.example.com','CARGO_SMTP_PORT=587','CARGO_SMTP_SECURITY=tls','CARGO_SMTP_USER=test','CARGO_SMTP_PASSWORD=test-only','CARGO_SMTP_FROM=no-reply@utmqr.kit-rb.com'] as $env) putenv($env);
ok(!CargoUTM\LoginMail::ready(),'Disabled by flag');
putenv('CARGO_EMAIL_LOGIN_ENABLED=1'); ok(CargoUTM\LoginMail::ready(),'Configured');
putenv('CARGO_SMTP_SECURITY=none'); ok(!CargoUTM\LoginMail::ready(),'No plaintext SMTP'); putenv('CARGO_SMTP_SECURITY=tls');
$login=new CargoUTM\EmailLogin(); $email='alice@cargonomica.com';
$request=$login->request($email,'127.0.0.1'); $code=otp();
ok(preg_match('/^[0-9]{6}$/',$code)===1 && strlen($request['challenge'])===64,'Six digits and random challenge');
ok($created===0 && $session===null,'No user/session before verification');
$stored=$cache[recordKey()]; ok(!in_array($code,$stored,true) && !in_array($request['challenge'],$stored,true),'No plaintext code/challenge stored');
rejects(fn()=>$login->request($email,'127.0.0.1'),'Resend throttle');
rejects(fn()=>$login->verify($email,str_repeat('a',64),$code),'Wrong browser challenge');
ok($cache[recordKey()]['attempts']===0,'Unknown challenge cannot burn attempts');
$login->verify($email,$request['challenge'],$code); ok($created===1 && $session===1,'Verified employee created');
rejects(fn()=>$login->verify($email,$request['challenge'],$code),'Replay rejected');
$cache=[]; $request=$login->request($email,'127.0.0.1'); $code=otp(); $login->verify($email,$request['challenge'],$code); ok($created===1,'Existing user reused');
$cache=[]; $request=$login->request($email,'127.0.0.1'); $code=otp(); $wrong=$code==='000000'?'111111':'000000';
for ($i=0;$i<5;++$i) rejects(fn()=>$login->verify($email,$request['challenge'],$wrong),'Wrong code');
rejects(fn()=>$login->verify($email,$request['challenge'],$code),'Five failures invalidate code');
$cache=[]; $request=$login->request($email,'127.0.0.1'); $code=otp(); $cache[recordKey()]['expires']=time()-1;
rejects(fn()=>$login->verify($email,$request['challenge'],$code),'Expired code');
$cache=[]; $mailOk=false; rejects(fn()=>$login->request($email,'127.0.0.1'),'Mail failure');
ok(count(array_filter(array_keys($cache),fn($key)=>str_starts_with($key,'cargo_login_code_')))===0,'Mail failure leaves no valid code'); $mailOk=true;
$cache=[]; $users[0]->caps[]='manage_options'; $request=$login->request($email,'127.0.0.1'); $code=otp(); $session=null;
rejects(fn()=>$login->verify($email,$request['challenge'],$code),'Admin blocked'); ok($session===null,'Admin not signed in');
$cache=[]; $users[0]->caps=['read']; $request=$login->request($email,'127.0.0.1');
rejects(fn()=>$login->verify($email,$request['challenge'],otp()),'Existing unauthorized user blocked'); ok($created===1,'Existing role not upgraded');
$cache=[]; $mailOk=true;
for ($i=0;$i<5;++$i) { foreach (array_keys($cache) as $key) if (str_starts_with($key,'cargo_login_minute_')) unset($cache[$key]); $login->request($email,'127.0.0.1'); }
foreach (array_keys($cache) as $key) if (str_starts_with($key,'cargo_login_minute_')) unset($cache[$key]);
rejects(fn()=>$login->request($email,'127.0.0.1'),'Hourly email limit');
// Test the real HTTP dispatcher guards with throwing JSON responses.
final class JsonReply extends Exception { public function __construct(public bool $success,public int $status) { parent::__construct(); } }
function wp_send_json_error(mixed $data,int $status=200): never { throw new JsonReply(false,$status); }
function wp_send_json_success(mixed $data,int $status=200): never { throw new JsonReply(true,$status); }
function nocache_headers(): void {}
function check_ajax_referer(string $action,string $key): void { if (($_POST[$key]??'')!=='valid') wp_send_json_error([],403); }
function is_user_logged_in(): bool { return false; }
putenv('CARGO_PUBLIC_UTM_URL=https://utmqr.kit-rb.com');
$base=['REQUEST_METHOD'=>'POST','QUERY_STRING'=>'action=cargo_email_login','CONTENT_LENGTH'=>'200','CONTENT_TYPE'=>'application/x-www-form-urlencoded','HTTP_ORIGIN'=>'https://utmqr.kit-rb.com'];
foreach ([['REQUEST_METHOD'=>'GET',405],['QUERY_STRING'=>'action=cargo_workspace',400],['CONTENT_LENGTH'=>'99999',413],['CONTENT_TYPE'=>'application/json',415],['HTTP_ORIGIN'=>'https://evil.example',403],['HTTP_SEC_FETCH_SITE'=>'cross-site',403],['HTTP_ORIGIN'=>'',403]] as $case) {
    $status=array_pop($case); $_SERVER=array_replace($base,$case); $_POST=['nonce'=>'valid','op'=>'request','email'=>$email];
    try { $login->api(); throw new LogicException('No JSON reply'); } catch (JsonReply $reply) { ok($reply->status===$status,'HTTP guard'); }
}
$_SERVER=$base; $_POST=['nonce'=>'invalid','op'=>'request','email'=>$email];
try { $login->api(); } catch (JsonReply $reply) { ok($reply->status===403,'Nonce'); }
$_POST=['nonce'=>'valid','op'=>'request','email'=>$email];
$wpdb=new class { public string $prefix='wp_'; };
putenv('CARGO_EMAIL_LOGIN_ENABLED=0');
try { $login->api(); } catch (JsonReply $reply) { ok($reply->status===503,'Feature disabled blocks HTTP API'); }
echo "PASS: {$assertions} email login domain, OTP, role, rate-limit, delivery failure and HTTP guard checks\n";
