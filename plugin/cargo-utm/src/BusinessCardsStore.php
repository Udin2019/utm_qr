<?php
declare(strict_types=1);
namespace CargoUTM;

final class BusinessCardsStore {
    public function __construct(private \wpdb $db) {
        // Ошибки SQL могут содержать значения полей контакта.
        $this->db->hide_errors();
        $this->db->suppress_errors(true);
    }

    public static function tables(): array {
        return [
            'cards_profiles'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, owner bigint unsigned NOT NULL, slug char(32) NOT NULL, data text NOT NULL, active tinyint NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY owner (owner), UNIQUE KEY slug (slug)",
            'cards_sessions'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, profile_id bigint unsigned NOT NULL, token_hash char(64) NOT NULL, status varchar(16) NOT NULL, meeting varchar(160) NOT NULL DEFAULT '', created_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY token_hash (token_hash), KEY profile_status (profile_id,status)",
            'cards_leads'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, profile_id bigint unsigned NOT NULL, session_id bigint unsigned DEFAULT NULL, submission char(32) NOT NULL, fingerprint char(64) NOT NULL DEFAULT '', source_type varchar(20) NOT NULL, full_name varchar(160) NOT NULL, phone varchar(40) NOT NULL, email varchar(254) NOT NULL, company varchar(160) NOT NULL, position varchar(120) NOT NULL, comment varchar(600) NOT NULL, note varchar(600) NOT NULL DEFAULT '', status varchar(16) NOT NULL DEFAULT 'new', created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id), UNIQUE KEY session_id (session_id), UNIQUE KEY profile_submission (profile_id,submission), KEY profile_time (profile_id,created_at), KEY profile_fingerprint (profile_id,fingerprint,created_at), KEY phone (phone), KEY email (email)",
            'cards_consents'=>"lead_id bigint unsigned NOT NULL, purpose varchar(24) NOT NULL, granted tinyint NOT NULL, version varchar(80) NOT NULL, document_hash char(64) NOT NULL, document text NOT NULL, agreed_at datetime NOT NULL, withdrawn_at datetime DEFAULT NULL, PRIMARY KEY  (lead_id,purpose)",
            'cards_events'=>"id bigint unsigned NOT NULL AUTO_INCREMENT, profile_id bigint unsigned NOT NULL, session_id bigint unsigned DEFAULT NULL, lead_id bigint unsigned DEFAULT NULL, kind varchar(24) NOT NULL, occurred_at datetime NOT NULL, PRIMARY KEY  (id), KEY profile_kind (profile_id,kind), KEY lead_id (lead_id), KEY session_id (session_id)",
            'cards_limits'=>"bucket char(64) NOT NULL, window_id bigint unsigned NOT NULL, hits int unsigned NOT NULL, PRIMARY KEY  (bucket,window_id), KEY window_id (window_id)",
        ];
    }

    public function table(string $name): string { return $this->db->prefix.'cargo_utm_cards_'.$name; }

    public function row(string $sql, mixed ...$args): ?array {
        $row=$this->db->get_row($args?$this->db->prepare($sql,...$args):$sql,ARRAY_A);
        $this->check();
        return $row;
    }

    public function rows(string $sql, mixed ...$args): array {
        $rows=$this->db->get_results($args?$this->db->prepare($sql,...$args):$sql,ARRAY_A);
        $this->check();
        return $rows;
    }

    private function check(): void {
        if ($this->db->last_error!=='') throw new \RuntimeException('Не удалось выполнить операцию с визитками.');
    }

    public function run(string $sql, mixed ...$args): int {
        $result=$this->db->query($args?$this->db->prepare($sql,...$args):$sql);
        $this->check();
        if ($result===false) throw new \RuntimeException('Не удалось выполнить операцию с визитками.');
        return (int)$result;
    }

    public function insert(string $table,array $data): int {
        if ($this->db->insert($this->table($table),$data)===false) throw new \RuntimeException('Не удалось сохранить запись.');
        return (int)$this->db->insert_id;
    }

    public function update(string $table,array $data,array $where): void {
        if ($this->db->update($this->table($table),$data,$where)===false) throw new \RuntimeException('Не удалось обновить запись.');
    }

    public function transaction(callable $operation): mixed {
        $this->run('START TRANSACTION');
        try {
            $result=$operation();
            $this->run('COMMIT');
            return $result;
        } catch (\Throwable $error) {
            $this->run('ROLLBACK');
            throw $error;
        }
    }

    public function event(int $profile, string $kind, ?int $session=null, ?int $lead=null): void {
        $this->insert('events',['profile_id'=>$profile,'session_id'=>$session,'lead_id'=>$lead,'kind'=>$kind,'occurred_at'=>gmdate('Y-m-d H:i:s')]);
    }

    public function limit(string $scope,int $max): bool {
        $window=intdiv(time(),60);
        $address=(string)($_SERVER['REMOTE_ADDR']??'');
        $bucket=hash_hmac('sha256',$scope.'|'.$address,wp_salt('nonce'));
        $table=$this->table('limits');
        $this->run("INSERT INTO $table (bucket,window_id,hits) VALUES (%s,%d,1) ON DUPLICATE KEY UPDATE hits=LEAST(hits+1,10000)",$bucket,$window);
        if (random_int(1,50)===1) $this->run("DELETE FROM $table WHERE window_id<%d LIMIT 500",$window-10);
        return (int)$this->row("SELECT hits FROM $table WHERE bucket=%s AND window_id=%d",$bucket,$window)['hits']<=$max;
    }
}
