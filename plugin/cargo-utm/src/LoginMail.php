<?php
declare(strict_types=1);
namespace CargoUTM;

final class LoginMail {
    public static function ready(): bool {
        $host=Config::env('CARGO_SMTP_HOST');
        return Config::env('CARGO_EMAIL_LOGIN_ENABLED')==='1'
            && (bool)preg_match('/\A[a-zA-Z0-9.-]+\z/D',$host)
            && in_array(Config::env('CARGO_SMTP_SECURITY'),['tls','ssl'],true)
            && ctype_digit(Config::env('CARGO_SMTP_PORT'))
            && (int)Config::env('CARGO_SMTP_PORT')>0 && (int)Config::env('CARGO_SMTP_PORT')<=65535
            && Config::env('CARGO_SMTP_USER')!=='' && Config::env('CARGO_SMTP_PASSWORD')!==''
            && (bool)filter_var(Config::env('CARGO_SMTP_FROM'),FILTER_VALIDATE_EMAIL);
    }
    public static function configure(object $mailer): void {
        $mailer->isSMTP();
        $mailer->Host=Config::env('CARGO_SMTP_HOST');
        $mailer->Port=(int)Config::env('CARGO_SMTP_PORT');
        $mailer->SMTPAuth=true;
        $mailer->Username=Config::env('CARGO_SMTP_USER');
        $mailer->Password=Config::env('CARGO_SMTP_PASSWORD');
        $mailer->SMTPSecure=Config::env('CARGO_SMTP_SECURITY');
        $mailer->SMTPAutoTLS=true;
        $mailer->SMTPDebug=0;
        $mailer->Timeout=15;
        $mailer->SMTPKeepAlive=false;
        $mailer->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]];
        $mailer->setFrom(Config::env('CARGO_SMTP_FROM'),'Каргономика · UTM и QR');
    }
    public static function send(string $email,string $code): bool {
        if (!self::ready()) return false;
        add_action('phpmailer_init',[self::class,'configure']);
        try {
            return wp_mail($email,'Код входа в Каргономику',"Ваш код входа: {$code}\n\nКод действует 10 минут и используется один раз.\nНе передавайте его другим людям. Если вы не запрашивали вход, проигнорируйте письмо.",['Content-Type: text/plain; charset=UTF-8']);
        } finally { remove_action('phpmailer_init',[self::class,'configure']); }
    }
}
