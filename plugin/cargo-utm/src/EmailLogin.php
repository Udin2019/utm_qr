<?php
declare(strict_types=1);
namespace CargoUTM;

final class EmailLogin {
    public function boot(): void {
        add_action('wp_ajax_nopriv_cargo_email_login',[$this,'api']);
        add_action('wp_ajax_cargo_email_login',[$this,'api']);
    }
    public static function email(mixed $value): string {
        if (!is_string($value) || strlen($value)>254 || preg_match('/[\x00-\x20\x7f]/',$value)) throw new \InvalidArgumentException('Укажите рабочую почту @cargonomica.com.');
        $value=strtolower($value);
        if (!filter_var($value,FILTER_VALIDATE_EMAIL) || substr(strrchr($value,'@')?:'',1)!=='cargonomica.com') throw new \InvalidArgumentException('Доступ разрешён только для почты @cargonomica.com.');
        return $value;
    }
    private static function digest(string $value): string { return hash_hmac('sha256',$value,wp_salt('auth')); }
    private static function key(string $kind,string $value): string { return 'cargo_login_'.$kind.'_'.self::digest($value); }
    private function limit(string $kind,string $value,int $max,int $seconds): void {
        $key=self::key($kind,$value); $now=time(); $counter=get_transient($key);
        if (!is_array($counter) || $counter['until']<=$now) $counter=['count'=>0,'until'=>$now+$seconds];
        if ($counter['count']>=$max) throw new \DomainException('Слишком много запросов. Попробуйте позже.');
        ++$counter['count'];
        if (!set_transient($key,$counter,max(1,$counter['until']-$now))) throw new \RuntimeException('Вход временно недоступен.');
    }
    public function api(): void {
        nocache_headers(); header('X-Content-Type-Options: nosniff');
        if (($_SERVER['REQUEST_METHOD']??'')!=='POST') wp_send_json_error(['message'=>'Требуется POST.'],405);
        if (($_SERVER['QUERY_STRING']??'')!=='action=cargo_email_login') wp_send_json_error(['message'=>'Неверный маршрут.'],400);
        $length=$_SERVER['CONTENT_LENGTH']??'';
        if (!is_string($length) || !ctype_digit($length) || strlen($length)>5 || (int)$length>4096) wp_send_json_error(['message'=>'Некорректный размер запроса.'],413);
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/x-www-form-urlencoded') wp_send_json_error(['message'=>'Неподдерживаемый формат.'],415);
        $expected=rtrim(Config::env('CARGO_PUBLIC_UTM_URL','https://'.Config::hosts()[0]),'/');
        if (($_SERVER['HTTP_ORIGIN']??'')!==$expected || ($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site') wp_send_json_error(['message'=>'Запрос с другого сайта запрещён.'],403);
        if (!is_string($_POST['nonce']??null) || strlen($_POST['nonce'])>128) wp_send_json_error(['message'=>'Обновите страницу.'],403);
        check_ajax_referer('cargo_email_login','nonce');
        if (is_user_logged_in()) wp_send_json_error(['message'=>'Вы уже вошли. Обновите страницу.'],409);
        $op=$_POST['op']??null;
        if (!is_string($op) || !in_array($op,['request','verify'],true)) wp_send_json_error(['message'=>'Неизвестная операция.'],400);
        global $wpdb;
        $lock=$wpdb->prefix.'cargo_email_login'; $locked=false; $result=null; $status=200;
        try {
            if (!LoginMail::ready()) throw new \RuntimeException('Отправка кодов ещё не настроена. Обратитесь к администратору.');
            $email=self::email($_POST['email']??null);
            $locked=(int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))===1;
            if (!$locked) throw new \RuntimeException('Вход занят. Повторите через несколько секунд.');
            // REMOTE_ADDR only: forwarded headers are not trusted. Behind the
            // current proxy this may be a shared limit, never a per-user promise.
            $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
            $this->limit('traffic',$ip,120,600);
            $result=$op==='request'?$this->request($email,$ip):$this->verify($email,$_POST['challenge']??null,$_POST['code']??null);
        } catch (\InvalidArgumentException $e) { $status=400; $result=['message'=>$e->getMessage()];
        } catch (\DomainException $e) { $status=429; $result=['message'=>$e->getMessage()];
        } catch (\RuntimeException $e) { $status=503; $result=['message'=>$e->getMessage()];
        } catch (\Throwable $e) { $status=503; $result=['message'=>'Вход временно недоступен.'];
        } finally { if ($locked) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
        if ($status!==200) wp_send_json_error($result,$status);
        wp_send_json_success($result);
    }
    public function request(string $email,string $ip): array {
        $this->limit('minute',$email,1,60);
        $this->limit('hour',$email,5,3600);
        $this->limit('send_ip',$ip,30,3600);
        $this->limit('send_global','all',100,3600);
        $challenge=bin2hex(random_bytes(32)); $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $key=self::key('code',$email);
        // Invalidate any prior request even if delivery of its replacement fails.
        delete_transient($key);
        $record=['hash'=>self::digest($email.'|'.$challenge.'|'.$code),'challenge'=>self::digest($challenge),'expires'=>time()+600,'attempts'=>0];
        if (!set_transient($key,$record,600)) throw new \RuntimeException('Вход временно недоступен.');
        try { $sent=LoginMail::send($email,$code); } catch (\Throwable $e) { $sent=false; }
        if (!$sent) { delete_transient($key); throw new \RuntimeException('Не удалось отправить письмо. Попробуйте позже.'); }
        return ['challenge'=>$challenge,'expiresIn'=>600,'retryAfter'=>60,'message'=>'Код отправлен на указанную почту.'];
    }
    public function verify(string $email,mixed $challenge,mixed $code): array {
        if (!is_string($challenge) || !preg_match('/\A[a-f0-9]{64}\z/D',$challenge) || !is_string($code) || !preg_match('/\A[0-9]{6}\z/D',$code)) throw new \InvalidArgumentException('Введите шестизначный код из письма.');
        $key=self::key('code',$email); $record=get_transient($key);
        $invalid='Код неверен или истёк. Запросите новый код.';
        if (!is_array($record) || $record['expires']<=time() || $record['attempts']>=5) { delete_transient($key); throw new \InvalidArgumentException($invalid); }
        // A caller without the random request identifier cannot burn attempts
        // for the genuine browser just by knowing a colleague's email address.
        if (!hash_equals($record['challenge'],self::digest($challenge))) throw new \InvalidArgumentException($invalid);
        ++$record['attempts'];
        if (!hash_equals($record['hash'],self::digest($email.'|'.$challenge.'|'.$code))) {
            if ($record['attempts']>=5) delete_transient($key);
            elseif (!set_transient($key,$record,max(1,$record['expires']-time()))) { delete_transient($key); throw new \RuntimeException('Вход временно недоступен.'); }
            throw new \InvalidArgumentException($invalid);
        }
        if (!delete_transient($key)) throw new \RuntimeException('Вход временно недоступен.');
        $user=get_user_by('email',$email);
        if (!$user) {
            if (!get_role('cargo_editor')) throw new \RuntimeException('Роль сотрудника не настроена.');
            $id=wp_insert_user(['user_login'=>'cargo_'.bin2hex(random_bytes(16)),'user_email'=>$email,'user_pass'=>wp_generate_password(64,true,true),'display_name'=>strstr($email,'@',true),'role'=>'cargo_editor']);
            if (is_wp_error($id)) throw new \RuntimeException('Не удалось создать учётную запись. Обратитесь к администратору.');
            $user=get_user_by('id',$id);
        }
        // Email codes cannot sign in administrators or upgrade existing roles.
        if (!$user || !$user->has_cap('cargo_view') || $user->has_cap('manage_options') || $user->has_cap('cargo_manage') || is_super_admin($user->ID)) throw new \InvalidArgumentException('Вход по коду для этой учётной записи недоступен. Обратитесь к администратору.');
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID,false,true);
        do_action('wp_login',$user->user_login,$user);
        return ['message'=>'Вход выполнен.'];
    }
}
