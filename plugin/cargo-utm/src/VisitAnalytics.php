<?php
declare(strict_types=1);
namespace CargoUTM;

final class VisitAnalytics {
    public function boot(): void {
        add_action('cargo_utm_tracking_cleanup',[$this,'cleanup']);
        add_action('init',static function(): void {
            if (!wp_next_scheduled('cargo_utm_tracking_cleanup')) wp_schedule_event(time()+3600,'daily','cargo_utm_tracking_cleanup');
        });
    }
    public function cleanup(): void {
        global $wpdb; $s=new Store();
        // Bound work per cron run. Expired backlog continues on later runs.
        $wpdb->query('DELETE FROM '.$s->table('visit_events').' WHERE occurred_at < UTC_TIMESTAMP() - INTERVAL 180 DAY LIMIT 10000');
        $wpdb->query('DELETE FROM '.$s->table('visitor_state').' WHERE last_seen < UTC_TIMESTAMP() - INTERVAL 180 DAY LIMIT 10000');
    }
    public static function columns(): array {
        return ['events'=>'События браузера','visits'=>'Визиты (cookie)','visitors'=>'Посетители (cookie)','estimated_visits'=>'Визиты без cookie, оценка','estimated_visitors'=>'Посетители без cookie, оценка','unknown_events'=>'События без идентификации'];
    }
    public static function select(): string {
        return "COUNT(*) events, COUNT(DISTINCT CASE WHEN e.identity_kind='cookie' THEN e.session_id END) visits, COUNT(DISTINCT CASE WHEN e.identity_kind='cookie' THEN e.visitor_hash END) visitors, COUNT(DISTINCT CASE WHEN e.identity_kind='fingerprint' THEN e.session_id END) estimated_visits, COUNT(DISTINCT CASE WHEN e.identity_kind='fingerprint' THEN e.visitor_hash END) estimated_visitors, SUM(CASE WHEN e.identity_kind='unknown' THEN 1 ELSE 0 END) unknown_events";
    }
}
