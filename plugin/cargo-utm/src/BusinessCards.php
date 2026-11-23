<?php
declare(strict_types=1);
namespace CargoUTM;

final class BusinessCards {
    private BusinessCardsStore $store;
    private const PATH='/business-cards';
    private const STATUS=['waiting'=>'Ожидание клиента','viewed'=>'Клиент открыл страницу','completed'=>'Контакт получен','expired'=>'Время истекло','cancelled'=>'Отменено'];

    public function boot(): void {
        add_action('template_redirect',[$this,'route'],-10);
        add_action('wp_ajax_cargo_business_cards',[$this,'api']);
        add_action('wp_ajax_nopriv_cargo_business_cards',static function(): void {
            self::headers();
            wp_send_json_error(['message'=>'Войдите в сервис.'],401);
        });
    }

    public static function headers(): void {
        nocache_headers();
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        foreach ((new SitePolicy())->publicHeaders() as $name=>$value) header($name.': '.$value);
    }

    private function connect(): void { global $wpdb; $this->store=new BusinessCardsStore($wpdb); }
    public static function canUse(): bool { return current_user_can('cargo_manage') || current_user_can('cargo_create'); }
    public static function synthetic(): bool {
        return !Config::production() && Config::env('CARGO_CARDS_SYNTHETIC')==='1' &&
            preg_match('/(?:^localhost$|^127[.]0[.]0[.]1$|[.]test$)/D',(string)wp_parse_url(home_url('/'),PHP_URL_HOST))===1;
    }
    private static function documents(): array {
        if (self::synthetic()) return [
            'pd'=>['version'=>'synthetic-1','text'=>'ТЕСТ. Используйте только вымышленные данные.'],
            'marketing'=>['version'=>'synthetic-1','text'=>'ТЕСТ. Рекламные сообщения не отправляются.'],
            'operator'=>'ТЕСТОВЫЙ СТЕНД','withdrawal'=>'Обратитесь к администратору тестового стенда.',
        ];
        if (Config::env('LEGAL_TEXTS_APPROVED','false')!=='true') return [];
        $value=json_decode(Config::env('CARGO_CARDS_LEGAL_JSON'),true);
        if (!is_array($value)) return [];
        foreach (['pd','marketing'] as $purpose) {
            if (!is_array($value[$purpose]??null) || !is_string($value[$purpose]['version']??null) ||
                !is_string($value[$purpose]['text']??null) ||
                !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D',$value[$purpose]['version']) ||
                trim($value[$purpose]['text'])==='' || strlen($value[$purpose]['text'])>30000) return [];
        }
        foreach (['operator','withdrawal'] as $key) {
            if (!is_string($value[$key]??null) || trim($value[$key])==='' || strlen($value[$key])>4000) return [];
        }
        return $value;
    }

    private static function policy(array $documents): ?array {
        $policy=$documents['policy']??null;
        if (!is_array($policy) || !is_string($policy['version']??null) || !is_string($policy['text']??null) ||
            !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D',$policy['version']) ||
            trim($policy['text'])==='' || strlen($policy['text'])>30000) return null;
        return $policy;
    }

    private static function signature(string $key,array $documents): string {
        return hash_hmac('sha256',$key.'|'.json_encode($documents,JSON_THROW_ON_ERROR),wp_salt('nonce'));
    }

    private static function sameOrigin(): bool {
        $url=wp_parse_url(home_url('/'));
        $origin=$url['scheme'].'://'.$url['host'].(isset($url['port'])?':'.$url['port']:'');
        return ($_SERVER['HTTP_ORIGIN']??'')===$origin;
    }

    private static function body(): array {
        $body=file_get_contents('php://input',false,null,0,8193);
        if ($body===false || strlen($body)>8192) throw new \InvalidArgumentException('Форма слишком большая.');
        $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \InvalidArgumentException('Некорректная форма.');
        return $data;
    }

    private static function method(): string { return $_SERVER['REQUEST_METHOD']??'GET'; }

    public function route(): void {
        $path=wp_parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
        if ($path===self::PATH.'/privacy' || $path===self::PATH.'/privacy/') {
            self::headers();
            if (self::method()!=='GET') wp_die('Требуется GET.','Политика',['response'=>405]);
            $policy=self::policy(self::documents());
            if (!$policy) wp_die('Политика пока не опубликована.','Политика',['response'=>404]);
            status_header(200);
            require dirname(__DIR__).'/views/business-cards-privacy.php';
            exit;
        }
        $manager=$path===self::PATH || $path===self::PATH.'/';
        if (!$manager && (!is_string($path) || !preg_match('~^/c/([ms])([a-f0-9]{32})(/vcard)?/?$~D',$path,$matches))) return;
        self::headers();
        status_header(200);
        if ($manager) {
            if (!is_user_logged_in()) { wp_safe_redirect(home_url('/?login=business-cards')); exit; }
            if (!self::canUse()) wp_die('Недостаточно прав.','Обмен визиток',['response'=>403]);
            if (self::method()!=='GET') wp_die('Требуется GET.','Обмен визиток',['response'=>405]);
            $documents=self::documents();
            $configuration=['endpoint'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('cargo_business_cards'),
                'admin'=>current_user_can('cargo_manage'),'synthetic'=>self::synthetic(),'collection'=>$documents!==[]];
            require dirname(__DIR__).'/views/business-cards.php';
            exit;
        }
        $key=$matches[1].$matches[2];
        $vcard=($matches[3]??'')==='/vcard';
        try {
            $this->connect();
            if (self::method()==='POST' && !$vcard) {
                if (!self::sameOrigin()) wp_send_json_error(['message'=>'Откройте форму на сайте.'],403);
                $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
                if ($type!=='application/json') wp_send_json_error(['message'=>'Ожидается форма JSON.'],415);
                if (!$this->store->limit('submit',240) || !$this->store->limit('submit:'.$key,10)) {
                    wp_send_json_error(['message'=>'Слишком много запросов. Повторите позже.'],429);
                }
                wp_send_json_success($this->submit($key,self::body()));
            }
            if (self::method()!=='GET') wp_die('Требуется GET.','Визитка',['response'=>405]);
            $card=$this->publicCard($key);
            $state=$card?$this->state($card):'missing';
            if (isset($card['expires_at']) && $card['expires_at']<=gmdate('Y-m-d H:i:s')) $state='expired';
            if (!in_array($state,['permanent','waiting','viewed','completed'],true)) {
                status_header($state==='missing'?404:410);
                $card=null;
            }
            $profile=$card?json_decode($card['data'],true,16,JSON_THROW_ON_ERROR):[];
            if ($vcard && $card) self::download('visitka.vcf','text/vcard; charset=UTF-8',BusinessCardData::vcard($profile));
            if ($card && !$vcard && !str_contains((string)($_SERVER['HTTP_SEC_PURPOSE']??''),'prefetch') && $this->store->limit('open',600) && $this->store->limit('open:'.$key,30)) {
                $id=(int)$card['id'];
                $session=isset($card['session_id'])?(int)$card['session_id']:null;
                if ($state==='waiting') {
                    $table=$this->store->table('sessions');
                    $this->store->run("UPDATE $table SET status='viewed' WHERE id=%d AND status='waiting' AND expires_at>UTC_TIMESTAMP()",$session);
                }
                $this->store->event($id,$session?'session_open':'permanent_open',$session);
            }
            $documents=$card?self::documents():[];
            $policy=self::policy($documents);
            $configuration=['signature'=>$documents?self::signature($key,$documents):'','synthetic'=>self::synthetic()];
            require dirname(__DIR__).'/views/business-cards.php';
        } catch (\DomainException|\InvalidArgumentException|\JsonException $error) {
            wp_send_json_error(['message'=>$error instanceof \JsonException?'Некорректная форма.':$error->getMessage()],$error instanceof \DomainException?409:400);
        } catch (\Throwable) {
            wp_die('Сервис временно недоступен.','Визитка',['response'=>503]);
        }
        exit;
    }

    private function publicCard(string $key,bool $lock=false): ?array {
        $profiles=$this->store->table('profiles');
        $sessions=$this->store->table('sessions');
        $suffix=$lock?' FOR UPDATE':'';
        return $key[0]==='m'
            ? $this->store->row("SELECT id,data FROM $profiles WHERE slug=%s AND active=1".$suffix,substr($key,1))
            : $this->store->row("SELECT p.id,p.data,s.id AS session_id,s.status,s.expires_at FROM $profiles p JOIN $sessions s ON s.profile_id=p.id WHERE s.token_hash=%s AND p.active=1".$suffix,hash('sha256',substr($key,1)));
    }

    private function state(array $row): string {
        $status=$row['status']??'permanent';
        if (in_array($status,['waiting','viewed'],true) && $row['expires_at']<=gmdate('Y-m-d H:i:s')) return 'expired';
        return $status;
    }

    private function submit(string $key,array $in): array {
        $documents=self::documents();
        if (!$documents) throw new \DomainException('Приём контактов сейчас выключен.');
        if (!is_string($in['signature']??null) || !hash_equals(self::signature($key,$documents),$in['signature'])) throw new \DomainException('Документы обновились. Откройте страницу заново.');
        if (($in['trap']??'')!=='' || ($in['pd']??false)!==true) throw new \InvalidArgumentException('Подтвердите согласие на обработку данных.');
        if (!is_string($in['submission']??null) || !preg_match('/^[0-9a-f]{32}$/D',$in['submission'])) throw new \InvalidArgumentException('Откройте форму заново.');
        $contact=BusinessCardData::person($in,false);
        $marketing=($in['marketing']??false)===true;
        $candidate=$this->publicCard($key);
        if (!$candidate) throw new \DomainException('Визитка недоступна.');
        $profile=(int)$candidate['id'];
        return $this->store->transaction(function () use ($key,$in,$contact,$documents,$marketing,$profile): array {
            $profiles=$this->store->table('profiles');
            // Тот же порядок блокировок, что при смене QR и выключении визитки.
            $this->store->row("SELECT id FROM $profiles WHERE id=%d FOR UPDATE",$profile);
            $card=$this->publicCard($key,true);
            if (!$card || (int)$card['id']!==$profile) throw new \DomainException('Визитка недоступна.');
            $session=isset($card['session_id'])?(int)$card['session_id']:null;
            $leads=$this->store->table('leads');
            $consents=$this->store->table('consents');
            $fingerprint=hash_hmac('sha256',json_encode([$profile,$contact,$marketing,$in['signature']],JSON_THROW_ON_ERROR),wp_salt('auth'));
            $existing=$this->store->row("SELECT l.session_id,l.fingerprint,c.granted FROM $leads l JOIN $consents c ON c.lead_id=l.id AND c.purpose='marketing' WHERE l.profile_id=%d AND l.submission=%s",$profile,$in['submission']);
            if ($existing && ($existing['session_id']===null?null:(int)$existing['session_id'])===$session) {
                if ($existing['fingerprint']!=='' && !hash_equals($existing['fingerprint'],$fingerprint)) throw new \DomainException('По этой отправке уже получены другие данные. Откройте форму заново.');
                return ['marketing'=>(bool)$existing['granted']];
            }
            if ($existing || !in_array($this->state($card),['permanent','waiting','viewed'],true)) throw new \DomainException('Ссылка использована, истекла или отменена.');
            $created=gmdate('Y-m-d H:i:s');
            $similar=$session===null?$this->store->row("SELECT id FROM $leads WHERE profile_id=%d AND session_id IS NULL AND fingerprint=%s AND created_at>=%s LIMIT 1",
                $profile,$fingerprint,gmdate('Y-m-d H:i:s',time()-60)):null;
            $lead=$this->store->insert('leads',$contact+['profile_id'=>$profile,'session_id'=>$session,'source_type'=>$session?'session':'permanent_card',
                'submission'=>$in['submission'],'fingerprint'=>$fingerprint,'created_at'=>$created,'updated_at'=>$created]);
            foreach (['pd'=>true,'marketing'=>$marketing] as $purpose=>$granted) {
                $doc=$documents[$purpose];
                $text=$documents['operator']."\n\n".$doc['text']."\n\n".$documents['withdrawal'];
                $this->store->insert('consents',['lead_id'=>$lead,'purpose'=>$purpose,'granted'=>(int)$granted,'version'=>$doc['version'],
                    'document'=>$text,'document_hash'=>hash('sha256',$text),'agreed_at'=>$created]);
            }
            if ($session) $this->store->update('sessions',['status'=>'completed'],['id'=>$session]);
            $this->store->event($profile,'contact_submitted',$session,$lead);
            if ($similar) $this->store->event($profile,'possible_duplicate',null,$lead);
            return ['marketing'=>$marketing];
        });
    }

    public function api(): void {
        self::headers();
        if (!self::canUse()) wp_send_json_error(['message'=>'Недостаточно прав.'],403);
        if (self::method()!=='POST') wp_send_json_error(['message'=>'Требуется POST.'],405);
        if (!check_ajax_referer('cargo_business_cards','nonce',false)) wp_send_json_error(['message'=>'Обновите страницу и войдите снова.'],403);
        try {
            $this->connect();
            $in=json_decode(wp_unslash((string)($_POST['payload']??'{}')),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($in)) throw new \InvalidArgumentException('Некорректная форма.');
            $op=sanitize_key($_POST['op']??'');
            $owner=get_current_user_id();
            $result=match($op) {
                'profile'=>$this->profile($owner),
                'save'=>$this->save($owner,$in),
                'start','poll','cancel'=>$this->session($owner,$op,$in),
                'leads','csv'=>$this->leads($owner,$op,$in),
                'lead','edit','vcard','withdraw','erase'=>$this->lead($owner,$op,$in),
                'stats'=>$this->stats($owner),
                default=>throw new \InvalidArgumentException('Неизвестное действие.'),
            };
            wp_send_json_success($result);
        } catch (\DomainException|\InvalidArgumentException|\JsonException $error) {
            wp_send_json_error(['message'=>$error instanceof \JsonException?'Некорректная форма.':$error->getMessage()],$error instanceof \DomainException?409:400);
        } catch (\Throwable) {
            wp_send_json_error(['message'=>'Сервис визиток временно недоступен.'],503);
        }
    }

    private function profile(int $owner): array {
        $table=$this->store->table('profiles');
        $row=$this->store->row("SELECT data,slug,active FROM $table WHERE owner=%d",$owner);
        if (!$row) return ['profile'=>null];
        $data=json_decode($row['data'],true,16,JSON_THROW_ON_ERROR);
        $url=home_url('/c/m'.$row['slug'],'https');
        return ['profile'=>$data,'active'=>(bool)$row['active'],'url'=>$url,'qr'=>Qr::plainSvg($url)];
    }

    private function save(int $owner,array $in): array {
        $data=json_encode(BusinessCardData::person($in,true),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $table=$this->store->table('profiles');
        $this->store->transaction(function () use ($table,$owner,$data,$in): void {
            $active=(int)(($in['active']??false)===true);
            $profile=$this->store->row("SELECT id FROM $table WHERE owner=%d FOR UPDATE",$owner);
            $values=['data'=>$data,'active'=>$active,'updated_at'=>gmdate('Y-m-d H:i:s')];
            if ($profile) {
                $this->store->update('profiles',$values,['id'=>(int)$profile['id']]);
                if (!$active) $this->cancelPrevious((int)$profile['id']);
            } else {
                $this->store->insert('profiles',$values+['owner'=>$owner,'slug'=>bin2hex(random_bytes(16)),'created_at'=>$values['updated_at']]);
            }
        });
        return $this->profile($owner);
    }

    private function cancelPrevious(int $profile): void {
        $sessions=$this->store->table('sessions');
        $open=$this->store->rows("SELECT id,expires_at,status FROM $sessions WHERE profile_id=%d AND status IN ('waiting','viewed') FOR UPDATE",$profile);
        foreach ($open as $row) {
            $id=(int)$row['id'];
            $status=$this->state($row)==='expired'?'expired':'cancelled';
            $this->store->update('sessions',['status'=>$status],['id'=>$id]);
            $this->store->event($profile,'session_'.$status,$id);
        }
    }

    private function session(int $owner,string $op,array $in): array {
        $profiles=$this->store->table('profiles');
        $sessions=$this->store->table('sessions');
        $id=(int)($in['id']??0);
        return $this->store->transaction(function () use ($profiles,$sessions,$owner,$op,$in,$id): array {
            $profile=$this->store->row("SELECT id,active FROM $profiles WHERE owner=%d FOR UPDATE",$owner);
            if (!$profile) throw new \DomainException('Сначала создайте визитку.');
            $pid=(int)$profile['id'];
            if ($op==='start') {
                if (!(int)$profile['active']) throw new \DomainException('Сначала включите визитку.');
                $meeting=BusinessCardData::fields($in,['meeting'=>160])['meeting'];
                $this->cancelPrevious($pid);
                $token=bin2hex(random_bytes(16));
                $ttl=max(60,min(3600,(int)Config::env('CARGO_CARDS_TTL','600')));
                $expires=gmdate('Y-m-d H:i:s',time()+$ttl);
                $newId=$this->store->insert('sessions',['profile_id'=>$pid,'token_hash'=>hash('sha256',$token),'status'=>'waiting',
                    'meeting'=>$meeting,'created_at'=>gmdate('Y-m-d H:i:s'),'expires_at'=>$expires]);
                $this->store->event($pid,'session_created',$newId);
                $url=home_url('/c/s'.$token,'https');
                return ['id'=>$newId,'status'=>'waiting','label'=>self::STATUS['waiting'],'url'=>$url,'qr'=>Qr::plainSvg($url),'expires'=>$expires.'Z'];
            }
            $row=$this->store->row("SELECT status,expires_at FROM $sessions WHERE profile_id=%d AND id=%d FOR UPDATE",$pid,$id);
            if (!$row) throw new \DomainException('Сессия недоступна.');
            $status=$this->state($row);
            if ($op==='cancel' && in_array($status,['waiting','viewed'],true)) $status='cancelled';
            if ($status!==$row['status']) {
                $this->store->update('sessions',['status'=>$status],['id'=>$id]);
                $this->store->event($pid,'session_'.$status,$id);
            }
            $leads=$this->store->table('leads');
            $consents=$this->store->table('consents');
            $lead=$status==='completed'?$this->store->row("SELECT l.id,l.full_name,l.phone,l.email,l.created_at,CASE WHEN c.withdrawn_at IS NULL THEN c.granted ELSE 0 END AS marketing
                FROM $leads l JOIN $consents c ON c.lead_id=l.id AND c.purpose='marketing'
                WHERE l.profile_id=%d AND l.session_id=%d AND l.status NOT IN ('restricted','erased')",$pid,$id):null;
            return ['id'=>$id,'status'=>$status,'label'=>self::STATUS[$status],'lead'=>$lead];
        });
    }

    private function scope(int $owner): string {
        return current_user_can('cargo_manage')?'1=1':sprintf('p.owner=%d',$owner);
    }

    private function leads(int $owner,string $op,array $in): array {
        if ($op==='csv' && !current_user_can('cargo_export')) throw new \DomainException('Экспорт недоступен.');
        $leads=$this->store->table('leads');
        $profiles=$this->store->table('profiles');
        $filters=BusinessCardData::fields($in,['search'=>160,'from'=>10,'to'=>10,'source'=>20]);
        $where=[$this->scope($owner),"l.status NOT IN ('restricted','erased')"];
        $args=[];
        if ($filters['search']!=='') {
            global $wpdb;
            $like='%'.$wpdb->esc_like($filters['search']).'%';
            $where[]='(l.full_name LIKE %s OR l.phone LIKE %s OR l.company LIKE %s)';
            array_push($args,$like,$like,$like);
        }
        foreach (['from'=>'>=','to'=>'<='] as $name=>$operator) {
            $date=$filters[$name];
            if ($date==='') continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
                throw new \InvalidArgumentException('Проверьте даты.');
            }
            $where[]="l.created_at $operator %s";
            $args[]=$date.($name==='from'?' 00:00:00':' 23:59:59');
        }
        if (in_array($filters['source'],['session','permanent_card'],true)) {
            $where[]='l.source_type=%s';
            $args[]=$filters['source'];
        }
        $sql="SELECT l.id,l.full_name,l.phone,l.email,l.company,l.position,l.source_type,l.status,l.created_at,p.owner FROM $leads l JOIN $profiles p ON p.id=l.profile_id WHERE ".implode(' AND ',$where).' ORDER BY l.id DESC';
        if ($op==='leads') {
            $page=max(0,min(100000,(int)($in['page']??0)));
            $rows=$this->store->rows($sql.' LIMIT 51 OFFSET '.($page*50),...$args);
            return ['items'=>array_slice($rows,0,50),'more'=>count($rows)>50];
        }
        $rows=$this->store->rows($sql.' LIMIT 5001',...$args);
        if (count($rows)>5000) throw new \DomainException('Уточните фильтр. В одной выгрузке не более 5000 контактов.');
        $stream=fopen('php://temp','w+');
        fwrite($stream,"\xEF\xBB\xBF");
        fputcsv($stream,['Имя','Телефон','Почта','Компания','Должность','Источник','Время UTC'],';','"','');
        foreach ($rows as $row) {
            $values=[];
            foreach (['full_name','phone','email','company','position','source_type','created_at'] as $field) $values[]=BusinessCardData::csvCell((string)$row[$field]);
            fputcsv($stream,$values,';','"','');
        }
        rewind($stream);
        $csv=stream_get_contents($stream);
        fclose($stream);
        self::download('contacts.csv','text/csv; charset=UTF-8',$csv);
    }

    private function lead(int $owner,string $op,array $in): array {
        $leads=$this->store->table('leads');
        $profiles=$this->store->table('profiles');
        $consents=$this->store->table('consents');
        $admin=current_user_can('cargo_manage');
        $id=(int)($in['id']??0);
        return $this->store->transaction(function () use ($leads,$profiles,$consents,$admin,$owner,$op,$in,$id): array {
            $row=$this->store->row("SELECT l.*,p.owner FROM $leads l JOIN $profiles p ON p.id=l.profile_id WHERE l.id=%d AND ".$this->scope($owner).' FOR UPDATE',$id);
            if (!$row || (!$admin && in_array($row['status'],['restricted','erased'],true))) throw new \DomainException('Контакт недоступен.');
            if ($op==='vcard') {
                if (!current_user_can('cargo_export') || in_array($row['status'],['restricted','erased'],true)) throw new \DomainException('Экспорт недоступен.');
                return ['vcard'=>BusinessCardData::vcard($row)];
            }
            if ($op==='edit') {
                if (in_array($row['status'],['restricted','erased'],true)) throw new \DomainException('Контакт ограничен.');
                $changes=BusinessCardData::fields($in,['note'=>600,'status'=>16]);
                if (!in_array($changes['status'],['new','processed'],true)) throw new \InvalidArgumentException('Проверьте статус.');
                $this->store->update('leads',$changes+['updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
                $row=array_replace($row,$changes);
            }
            if ($op==='withdraw' || $op==='erase') {
                if (!$admin) throw new \DomainException('Требуются права администратора.');
                $purpose=$in['purpose']??'';
                if ($op==='erase') {
                    $this->store->update('leads',array_fill_keys(array_keys(BusinessCardData::CONTACT), '')+['note'=>'','fingerprint'=>'','status'=>'erased','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
                    $this->store->run("UPDATE $consents SET withdrawn_at=COALESCE(withdrawn_at,UTC_TIMESTAMP()) WHERE lead_id=%d AND granted=1",$id);
                    $this->store->event((int)$row['profile_id'],'lead_erased',null,$id);
                    return ['erased'=>true];
                }
                if (!in_array($purpose,['pd','marketing'],true)) throw new \InvalidArgumentException('Укажите вид согласия.');
                $changed=$this->store->run("UPDATE $consents SET withdrawn_at=UTC_TIMESTAMP() WHERE lead_id=%d AND purpose=%s AND granted=1 AND withdrawn_at IS NULL",$id,$purpose);
                if ($changed>0) $this->store->event((int)$row['profile_id'],$purpose==='pd'?'pd_withdrawn':'marketing_withdrawn',null,$id);
                if ($purpose==='pd' && $row['status']!=='erased') {
                    $this->store->update('leads',['status'=>'restricted','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$id]);
                    $row['status']='restricted';
                }
            }
            unset($row['submission'],$row['fingerprint']);
            $events=$this->store->table('events');
            $row['possible_duplicate']=$this->store->row("SELECT id FROM $events WHERE lead_id=%d AND kind='possible_duplicate'",$id)!==null;
            $row['consents']=$this->store->rows("SELECT purpose,granted,version,document_hash,document,agreed_at,withdrawn_at FROM $consents WHERE lead_id=%d",$id);
            return ['lead'=>$row];
        });
    }

    private function stats(int $owner): array {
        $events=$this->store->table('events');
        $profiles=$this->store->table('profiles');
        $sessions=$this->store->table('sessions');
        $rows=$this->store->rows("SELECT p.owner,COALESCE(s.meeting,'') AS meeting,e.kind,
            CASE WHEN e.session_id IS NULL THEN 'permanent_card' ELSE 'session' END AS source_type,COUNT(*) AS total
            FROM $events e JOIN $profiles p ON p.id=e.profile_id LEFT JOIN $sessions s ON s.id=e.session_id
            WHERE ".$this->scope($owner)." AND e.kind IN ('session_created','session_open','permanent_open','contact_submitted')
            GROUP BY p.owner,meeting,e.kind,source_type ORDER BY p.owner,meeting,e.kind");
        $cards=current_user_can('cargo_manage')?$this->store->rows("SELECT owner,active,slug,data FROM $profiles ORDER BY owner LIMIT 1000"):[];
        foreach ($cards as &$card) {
            $card['name']=json_decode($card['data'],true,16,JSON_THROW_ON_ERROR)['full_name'];
            $card['url']=(int)$card['active']?home_url('/c/m'.$card['slug'],'https'):null;
            unset($card['data'],$card['slug']);
        }
        unset($card);
        return compact('rows','cards');
    }

    private static function download(string $name,string $mime,string $content): never {
        header('Content-Type: '.$mime);
        header('Content-Disposition: attachment; filename="'.$name.'"');
        echo $content;
        exit;
    }
}
