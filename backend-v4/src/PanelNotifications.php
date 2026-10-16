<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Icono de notificaciones de la barra superior del panel (docs/design/2026-10-15-encargo-notificaciones.md).
 * Alertas: doble empleo y apps sospechosas sin revisar de los ultimos 30 dias (hasta mañana), en el alcance de la cuenta.
 * Jornada de hoy: resumen del dia (llegadas tarde con la regla de /reports/presence, ausencias registradas), no una por persona.
 * Cada seccion pasa por el mismo AdminAccess que su pantalla (permiso del rol, funcion comprada, alcance) y, ademas, por la
 * funcion del cliente (tambien para superadmin: lo que el cliente no compro no aparece).
 * badge_count = alertas sin revisar + resumenes del dia que esta cuenta no ha visto. Lo visto se guarda por cuenta del panel
 * en esta empresa (principal), no por navegador. Las llegadas tarde se cachean 5 minutos por cuenta (panel_notification_state):
 * el contador se pide en cada pagina y recalcular la puntualidad de ~300 personas en cada una seria caro.
 */
final class PanelNotifications
{
    public const OPERATIONS = ['listNotifications', 'markNotificationsSeen'];
    private const TTL = 300;
    private const WINDOW_DAYS = 30;
    private string $tenant;

    public function __construct(private Database $db, private array $c) { $this->tenant = $c['tenant_id']; }

    public function dispatch(string $op): array
    {
        $state = $this->db->one('SELECT * FROM panel_notification_state WHERE tenant_id=? AND principal_id=? FOR UPDATE', [$this->tenant, $this->c['principal_id']]) ?: null;
        $data = $this->collect($state);
        if ($op === 'markNotificationsSeen') {
            $this->db->run('INSERT INTO panel_notification_state (tenant_id,principal_id,seen_at,seen_day,seen_late,seen_absent) VALUES (?,?,UTC_TIMESTAMP(6),?,?,?)
                ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at),seen_day=VALUES(seen_day),seen_late=VALUES(seen_late),seen_absent=VALUES(seen_absent)',
                [$this->tenant, $this->c['principal_id'], $data['day'], $data['late'] ?? 0, $data['absent'] ?? 0]);
            $state = $this->db->one('SELECT * FROM panel_notification_state WHERE tenant_id=? AND principal_id=?', [$this->tenant, $this->c['principal_id']]);
        }
        return AdminApi::response($this->render($data, $state), 200, ['Cache-Control' => 'no-store']);
    }

    /** Conteos de las secciones que esta cuenta puede ver (null = seccion no disponible). */
    private function collect(?array $state): array
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
        $from = (new \DateTimeImmutable($today))->modify('-' . (self::WINDOW_DAYS - 1) . ' days')->format('Y-m-d');
        $to = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $out = ['day' => $today, 'from' => $from, 'to' => $to, 'dual_job' => null, 'suspicious_apps' => null, 'late' => null, 'absent' => null];
        if ($access = $this->section('cumplimiento', 'cumplimiento.ver', 'listDualJobAlerts')) {
            $out['dual_job'] = $this->pending('dual_job_alerts', $access, $from, $to);
            $out['suspicious_apps'] = $this->pending('suspicious_app_detections', $access, $from, $to);
        }
        if ($access = $this->section('presencia', 'reportes.ver', 'getPresenceReport')) { $out['late'] = $this->late($access, $state, $today); }
        if ($access = $this->section('ausencias', 'presencia.ver', 'listAbsences')) { $out['absent'] = $this->absent($access, $today, $to); }
        return $out;
    }

    /** El AdminAccess de la pantalla de esa seccion, o null si el rol no la permite o el cliente no compro la funcion. */
    private function section(string $feature, string $permission, string $operation): ?AdminAccess
    {
        if (!Features::has($this->db, $this->tenant, $feature)) { return null; }
        try { return new AdminAccess($this->db, $this->c, $permission, $operation); }
        catch (ApiError $e) { if ($e->status === 403) { return null; } throw $e; }
    }

    /** Sin revisar en la ventana del listado (mismo filtro de retencion que OperationsApi::signals). */
    private function pending(string $table, AdminAccess $access, string $from, string $to): int
    {
        [$scope, $args] = $access->usersSql('u');
        return (int) $this->db->one("SELECT COUNT(*) n FROM $table s JOIN users u ON u.tenant_id=s.tenant_id AND u.id=s.user_id JOIN retention_settings r ON r.tenant_id=s.tenant_id
            WHERE s.tenant_id=? AND s.reviewed=FALSE AND s.day>=? AND s.day<? AND s.day>=DATE_SUB(UTC_DATE(),INTERVAL r.aggregate_months MONTH) AND $scope", [$this->tenant, $from, $to, ...$args])['n'];
    }

    /** Llegadas tarde de hoy: la misma regla que /reports/presence (margen de Horarios), cacheada TTL segundos por cuenta. */
    private function late(AdminAccess $access, ?array $state, string $today): int
    {
        if ($state !== null && $state['digest_day'] === $today && $state['digest_late'] !== null && (int) $state['digest_revision'] === $this->c['auth_revision']
            && $state['digest_at'] !== null && strtotime($state['digest_at'] . ' UTC') > time() - self::TTL) {
            return (int) $state['digest_late'];
        }
        $late = (new ActivityReports($this->db, $this->c, $access))->lateToday();
        $this->db->run('INSERT INTO panel_notification_state (tenant_id,principal_id,digest_day,digest_late,digest_revision,digest_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE digest_day=VALUES(digest_day),digest_late=VALUES(digest_late),digest_revision=VALUES(digest_revision),digest_at=VALUES(digest_at)',
            [$this->tenant, $this->c['principal_id'], $today, $late, $this->c['auth_revision']]);
        return $late;
    }

    /** Personas del alcance con una ausencia aprobada que toca hoy (dia local de la empresa). Consulta barata: sin cache. */
    private function absent(AdminAccess $access, string $today, string $tomorrow): int
    {
        [$scope, $args] = $access->usersSql('u');
        $zone = new \DateTimeZone($this->c['timezone']); $utc = new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable($today, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = (new \DateTimeImmutable($tomorrow, $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        return (int) $this->db->one("SELECT COUNT(DISTINCT a.user_id) n FROM absences a JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id
            WHERE a.tenant_id=? AND a.status='approved' AND a.starts_at<? AND a.ends_at>? AND $scope", [$this->tenant, $end, $start, ...$args])['n'];
    }

    private function render(array $d, ?array $state): array
    {
        $seenDay = $state['seen_day'] ?? null;
        // Un resumen del dia esta «sin ver» si hoy no se abrio la lista o si crecio desde que se abrio.
        $fresh = static fn (int $now, string $seenField): bool => $now > 0 && ($seenDay !== $d['day'] || $now > (int) ($state[$seenField] ?? 0));
        $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
        $items = []; $available = [];
        if ($d['dual_job'] !== null) {
            $available[] = 'dual_job';
            if ($d['dual_job'] > 0) { $items[] = ['type' => 'dual_job', 'group' => 'alerts', 'count' => $d['dual_job'], 'unseen' => true,
                'title' => $plural($d['dual_job'], 'alerta de doble empleo sin revisar', 'alertas de doble empleo sin revisar'),
                'href' => '/doble-empleo.php?reviewed=0&from=' . $d['from'] . '&to=' . $d['to']]; }
        }
        if ($d['suspicious_apps'] !== null) {
            $available[] = 'suspicious_apps';
            if ($d['suspicious_apps'] > 0) { $items[] = ['type' => 'suspicious_apps', 'group' => 'alerts', 'count' => $d['suspicious_apps'], 'unseen' => true,
                'title' => $plural($d['suspicious_apps'], 'detección de apps sospechosas sin revisar', 'detecciones de apps sospechosas sin revisar'),
                'href' => '/apps-sospechosas.php?reviewed=0']; }
        }
        if ($d['late'] !== null) {
            $available[] = 'late_arrivals';
            if ($d['late'] > 0) { $items[] = ['type' => 'late_arrivals', 'group' => 'today', 'count' => $d['late'], 'unseen' => $fresh($d['late'], 'seen_late'),
                'title' => $plural($d['late'], 'llegada tarde hoy', 'llegadas tarde hoy'),
                'href' => '/reportes.php?desde=' . $d['day'] . '&hasta=' . $d['day'] . '&pestana=presencia&estado=tarde']; }
        }
        if ($d['absent'] !== null) {
            $available[] = 'absences';
            if ($d['absent'] > 0) { $items[] = ['type' => 'absences', 'group' => 'today', 'count' => $d['absent'], 'unseen' => $fresh($d['absent'], 'seen_absent'),
                'title' => $plural($d['absent'], 'ausencia hoy', 'ausencias hoy'),
                'href' => '/ausencias.php?dia=' . $d['day']]; }
        }
        $badge = 0; foreach ($items as $item) { $badge += $item['group'] === 'alerts' ? $item['count'] : (int) $item['unseen']; }
        return ['generated_at' => Util::now(), 'day' => $d['day'], 'badge_count' => $badge,
            'seen_at' => ($state['seen_at'] ?? null) === null ? null : Util::time($state['seen_at']), 'available' => $available, 'items' => $items];
    }
}
