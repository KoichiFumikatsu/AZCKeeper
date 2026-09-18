<?php
namespace Keeper\Repos;

use PDO;

/**
 * Consultas de los reportes para /api/external/reports (K3-ADP-01…06).
 *
 * Cada consulta es la de la página del panel que la origina (se cita en cada
 * método), con dos diferencias deliberadas: (1) el ámbito llega ya resuelto
 * (`$scope['sql']` sobre el alias `ua` de keeper_user_assignments, como
 * scopeFilter()); (2) las páginas que solo miran «hoy» (users.php) o que no
 * recortan por ámbito (sedes-dashboard.php) aquí reciben periodo y ámbito,
 * porque el consumidor externo no tiene sesión ni menú que lo limite.
 * Ninguna fórmula se cambia: ocio, productividad, estado online y primer
 * ingreso son los del panel.
 */
class ExternalReportsRepo
{
    /** Estado de un dispositivo según el panel (index.php / users.php): online < 2 min, away < 15 min. */
    private const ONLINE_SECONDS = 120;
    private const AWAY_SECONDS = 900;

    /** Como index.php: KPIs, sumas del periodo, primer ingreso, top apps, ocio, Focus medio y alertas pendientes. */
    public static function summary(PDO $pdo, array $scope, string $from, string $to): array
    {
        $sSql = $scope['sql'];
        $params = $scope['params'];
        $range = [':dfrom' => $from, ':dto' => $to];

        $st = $pdo->prepare("
            SELECT
                COUNT(DISTINCT u.id) AS total_users,
                COUNT(DISTINCT d.id) AS total_devices,
                COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 15 MINUTE THEN d.id END) AS online_now,
                COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 2 MINUTE THEN d.id END) AS active_now
            FROM keeper_users u
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
            WHERE u.status = 'active' {$sSql}
        ");
        $st->execute($params);
        $kpi = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare("
            SELECT
                COALESCE(SUM(a.active_seconds), 0) AS active_seconds,
                COALESCE(SUM(a.idle_seconds), 0) AS idle_seconds,
                COALESCE(SUM(a.call_seconds), 0) AS call_seconds,
                COALESCE(SUM(a.work_hours_active_seconds), 0) AS work_hours_active_seconds,
                COALESCE(SUM(a.work_hours_idle_seconds), 0) AS work_hours_idle_seconds,
                COALESCE(SUM(a.lunch_active_seconds), 0) AS lunch_active_seconds,
                COALESCE(SUM(a.after_hours_active_seconds), 0) AS after_hours_active_seconds,
                COUNT(DISTINCT a.user_id) AS users_with_activity
            FROM keeper_activity_day a
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = a.user_id
            WHERE a.day_date BETWEEN :dfrom AND :dto {$sSql}
        ");
        $st->execute(array_merge($params, $range));
        $tot = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare("
            SELECT MIN(we.start_at)
            FROM keeper_window_episode we
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = we.user_id
            WHERE we.day_date BETWEEN :dfrom AND :dto AND TIME(we.start_at) >= '05:00:00' {$sSql}
        ");
        $st->execute(array_merge($params, $range));
        $firstEvent = $st->fetchColumn() ?: null;

        $st = $pdo->prepare("
            SELECT w.process_name, SUM(w.duration_seconds) AS total_sec
            FROM keeper_window_episode w
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id
            WHERE w.day_date BETWEEN :dfrom AND :dto AND w.process_name IS NOT NULL AND w.process_name != '' {$sSql}
            GROUP BY w.process_name
            ORDER BY total_sec DESC
            LIMIT 6
        ");
        $st->execute(array_merge($params, $range));
        $leisure = self::leisureApps($pdo);
        $topApps = array_map(static fn(array $r): array => [
            'process_name'     => $r['process_name'],
            'duration_seconds' => (int)$r['total_sec'],
            'leisure'          => in_array($r['process_name'], $leisure['apps'], true),
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        $leisureSeconds = self::leisureSeconds($pdo, $leisure, "LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id", $sSql, $params, $from, $to);

        $focusAvg = null;
        try {
            $st = $pdo->prepare("
                SELECT ROUND(AVG(f.focus_score), 0)
                FROM keeper_focus_daily f
                INNER JOIN keeper_users u ON u.id = f.user_id AND u.status = 'active'
                LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
                WHERE f.day_date BETWEEN :dfrom AND :dto {$sSql}
            ");
            $st->execute(array_merge($params, $range));
            $v = $st->fetchColumn();
            $focusAvg = ($v === null || $v === false) ? null : (int)$v;
        } catch (\Throwable $e) { /* tabla aún no migrada */ }

        $alertsPending = 0;
        try {
            $st = $pdo->prepare("
                SELECT COUNT(*) FROM keeper_dual_job_alerts a
                INNER JOIN keeper_users u ON u.id = a.user_id
                LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
                WHERE a.is_reviewed = 0 {$sSql}
            ");
            $st->execute($params);
            $alertsPending = (int)$st->fetchColumn();
        } catch (\Throwable $e) {}

        $workTotal = (int)$tot['work_hours_active_seconds'] + (int)$tot['work_hours_idle_seconds'];
        $productive = max(0, (int)$tot['work_hours_active_seconds'] - $leisureSeconds);

        return [
            'kpis' => [
                'total_users'         => (int)($kpi['total_users'] ?? 0),
                'total_devices'       => (int)($kpi['total_devices'] ?? 0),
                'online_now'          => (int)($kpi['online_now'] ?? 0),
                'active_now'          => (int)($kpi['active_now'] ?? 0),
                'users_with_activity' => (int)($tot['users_with_activity'] ?? 0),
            ],
            'totals' => [
                'active_seconds'             => (int)$tot['active_seconds'],
                'idle_seconds'               => (int)$tot['idle_seconds'],
                'call_seconds'               => (int)$tot['call_seconds'],
                'work_hours_active_seconds'  => (int)$tot['work_hours_active_seconds'],
                'lunch_active_seconds'       => (int)$tot['lunch_active_seconds'],
                'after_hours_active_seconds' => (int)$tot['after_hours_active_seconds'],
                'first_event_at'             => $firstEvent ? self::iso($firstEvent) : null,
            ],
            'top_apps'         => $topApps,
            'leisure_seconds'  => $leisureSeconds,
            'productivity_pct' => $workTotal > 0 ? (int)round(($productive / $workTotal) * 100) : null,
            'focus_avg'        => $focusAvg,
            'alerts_pending'   => $alertsPending,
        ];
    }

    /** Como users.php (Query A + B), con el periodo en vez de CURDATE(). */
    public static function users(PDO $pdo, array $scope, string $from, string $to): array
    {
        $st = $pdo->prepare("
            SELECT
                u.id, u.display_name, u.email, u.employment_status,
                ua.firm_id, ua.area_id, ua.sede_id, ua.sociedad_id,
                dev.last_seen_at,
                CASE
                    WHEN dev.last_seen_at >= NOW() - INTERVAL 120 SECOND THEN 'online'
                    WHEN dev.last_seen_at >= NOW() - INTERVAL 900 SECOND THEN 'away'
                    WHEN dev.last_seen_at IS NULL THEN 'no_device'
                    ELSE 'offline'
                END AS status,
                COALESCE(act.active_sec, 0) AS active_seconds,
                COALESCE(act.idle_sec, 0) AS idle_seconds,
                COALESCE(act.call_sec, 0) AS call_seconds,
                COALESCE(act.work_sec, 0) AS work_seconds,
                COALESCE(act.work_idle_sec, 0) AS work_idle_seconds,
                fs.focus_score
            FROM keeper_users u
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            LEFT JOIN (
                SELECT user_id, MAX(last_seen_at) AS last_seen_at FROM keeper_devices WHERE status = 'active' GROUP BY user_id
            ) dev ON dev.user_id = u.id
            LEFT JOIN (
                SELECT a.user_id, SUM(a.active_seconds) AS active_sec, SUM(a.idle_seconds) AS idle_sec, SUM(a.call_seconds) AS call_sec,
                       SUM(a.work_hours_active_seconds) AS work_sec, SUM(a.work_hours_idle_seconds) AS work_idle_sec
                FROM keeper_activity_day a WHERE a.day_date BETWEEN :dfrom AND :dto GROUP BY a.user_id
            ) act ON act.user_id = u.id
            LEFT JOIN (
                SELECT f.user_id, ROUND(AVG(f.focus_score), 0) AS focus_score
                FROM keeper_focus_daily f WHERE f.day_date BETWEEN :ffrom AND :fto GROUP BY f.user_id
            ) fs ON fs.user_id = u.id
            WHERE u.status = 'active' {$scope['sql']}
            ORDER BY FIELD(status, 'online', 'away', 'offline', 'no_device'), u.display_name ASC
        ");
        $st->execute(array_merge($scope['params'], [':dfrom' => $from, ':dto' => $to, ':ffrom' => $from, ':fto' => $to]));

        return array_map(static fn(array $r): array => [
            'keeper_user_id'  => (int)$r['id'],
            'full_name'       => $r['display_name'],
            'email'           => $r['email'],
            'firm_id'         => $r['firm_id'] !== null ? (int)$r['firm_id'] : null,
            'area_id'         => $r['area_id'] !== null ? (int)$r['area_id'] : null,
            'sede_id'         => $r['sede_id'] !== null ? (int)$r['sede_id'] : null,
            'sociedad_id'     => $r['sociedad_id'] !== null ? (int)$r['sociedad_id'] : null,
            'active_seconds'  => (int)$r['active_seconds'],
            'idle_seconds'    => (int)$r['idle_seconds'],
            'call_seconds'    => (int)$r['call_seconds'],
            'work_seconds'    => (int)$r['work_seconds'],
            'focus_score'     => $r['focus_score'] !== null ? (int)$r['focus_score'] : null,
            'status'          => $r['status'],
            'last_seen_at'    => $r['last_seen_at'] ? self::iso($r['last_seen_at']) : null,
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Como canViewUser(), con el ámbito efectivo ya combinado. */
    public static function userInScope(PDO $pdo, array $scope, int $keeperUserId): bool
    {
        $st = $pdo->prepare("
            SELECT 1 FROM keeper_users u
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            WHERE u.id = :uid AND u.status = 'active' {$scope['sql']}
            LIMIT 1
        ");
        $st->execute(array_merge($scope['params'], [':uid' => $keeperUserId]));
        return (bool)$st->fetch();
    }

    /** Como user-dashboard.php: datos, actividad del periodo, resumen diario, Focus diario y top apps. */
    public static function userDetail(PDO $pdo, int $userId, string $from, string $to): array
    {
        $st = $pdo->prepare("
            SELECT u.id, u.display_name, u.email, ua.firm_id, ua.area_id, ua.sede_id, ua.sociedad_id,
                   f.nombre AS firm_name, ar.nombre AS area_name, s.nombre AS sede_name
            FROM keeper_users u
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            LEFT JOIN keeper_firmas f ON f.id = ua.firm_id
            LEFT JOIN keeper_areas ar ON ar.id = ua.area_id
            LEFT JOIN keeper_sedes s ON s.id = ua.sede_id
            WHERE u.id = :uid LIMIT 1
        ");
        $st->execute([':uid' => $userId]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        $st = $pdo->prepare("
            SELECT a.day_date,
                   SUM(a.active_seconds) AS active_seconds,
                   SUM(a.idle_seconds) AS idle_seconds,
                   SUM(a.call_seconds) AS call_seconds,
                   SUM(a.work_hours_active_seconds) AS work_hours_active_seconds,
                   MIN(a.first_event_at) AS first_event_at,
                   MAX(a.last_event_at) AS last_event_at
            FROM keeper_activity_day a
            WHERE a.user_id = :uid AND a.day_date BETWEEN :from AND :to
            GROUP BY a.day_date ORDER BY a.day_date ASC
        ");
        $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to]);
        $days = array_map(static fn(array $d): array => [
            'day_date'                  => $d['day_date'],
            'active_seconds'            => (int)$d['active_seconds'],
            'idle_seconds'              => (int)$d['idle_seconds'],
            'call_seconds'              => (int)$d['call_seconds'],
            'work_hours_active_seconds' => (int)$d['work_hours_active_seconds'],
            'first_event_at'            => $d['first_event_at'] ? self::iso($d['first_event_at']) : null,
            'last_event_at'             => $d['last_event_at'] ? self::iso($d['last_event_at']) : null,
        ], $st->fetchAll(PDO::FETCH_ASSOC));

        $focus = [];
        try {
            $focus = array_map(static fn(array $f): array => [
                'day_date'            => $f['day_date'],
                'focus_score'         => (int)$f['focus_score'],
                'productivity_pct'    => (int)$f['productivity_pct'],
                'constancy_pct'       => (int)$f['constancy_pct'],
                'context_switches'    => (int)$f['context_switches'],
                'deep_work_seconds'   => (int)$f['deep_work_seconds'],
                'distraction_seconds' => (int)$f['distraction_seconds'],
                'punctuality_minutes' => (int)$f['punctuality_minutes'],
            ], ProductivityRepo::getDailyMetrics($pdo, $userId, $from, $to));
        } catch (\Throwable $e) {}

        $st = $pdo->prepare("
            SELECT w.process_name, SUM(w.duration_seconds) AS total_sec, COUNT(*) AS episodes
            FROM keeper_window_episode w
            WHERE w.user_id = :uid AND w.day_date BETWEEN :from AND :to AND w.process_name IS NOT NULL AND w.process_name != ''
            GROUP BY w.process_name ORDER BY total_sec DESC LIMIT 8
        ");
        $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to]);
        $topApps = array_map(static fn(array $r): array => ['process_name' => $r['process_name'], 'duration_seconds' => (int)$r['total_sec'], 'episodes' => (int)$r['episodes']], $st->fetchAll(PDO::FETCH_ASSOC));

        return [
            'user' => [
                'keeper_user_id' => (int)($u['id'] ?? $userId),
                'full_name'      => $u['display_name'] ?? null,
                'email'          => $u['email'] ?? null,
                'firm_id'        => isset($u['firm_id']) ? (int)$u['firm_id'] : null,
                'area_id'        => isset($u['area_id']) ? (int)$u['area_id'] : null,
                'sede_id'        => isset($u['sede_id']) ? (int)$u['sede_id'] : null,
                'firm_name'      => $u['firm_name'] ?? null,
                'area_name'      => $u['area_name'] ?? null,
                'sede_name'      => $u['sede_name'] ?? null,
            ],
            'days'     => $days,
            'focus'    => $focus,
            'top_apps' => $topApps,
        ];
    }

    /** Episodios de ventana del usuario en el rango (user-dashboard.php ?ajax=episodes), más recientes primero. */
    public static function episodes(PDO $pdo, int $userId, string $from, string $to): array
    {
        $st = $pdo->prepare("
            SELECT w.day_date, w.start_at, w.end_at, w.process_name, w.app_name, w.window_title, w.duration_seconds, w.is_in_call
            FROM keeper_window_episode w
            WHERE w.user_id = :uid AND w.day_date BETWEEN :from AND :to
            ORDER BY w.start_at DESC
        ");
        $st->execute([':uid' => $userId, ':from' => $from, ':to' => $to]);
        return array_map(static fn(array $e): array => [
            'day_date'         => $e['day_date'],
            'start_at'         => self::iso($e['start_at']),
            'end_at'           => self::iso($e['end_at']),
            'process_name'     => $e['process_name'],
            'app_name'         => $e['app_name'],
            'window_title'     => $e['window_title'],
            'duration_seconds' => (int)$e['duration_seconds'],
            'is_in_call'       => (bool)$e['is_in_call'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Pesos del Focus Score y umbral de deep work vigentes (productivity.php). */
    public static function focusConfig(PDO $pdo): array
    {
        $weights = ['context_switches' => 20, 'deep_work' => 25, 'distraction' => 20, 'punctuality' => 15, 'constancy' => 20];
        $threshold = 25;
        try {
            $st = $pdo->prepare("SELECT setting_key, setting_value FROM keeper_panel_settings WHERE setting_key IN ('productivity.focus_weights', 'productivity.deep_work_threshold_minutes')");
            $st->execute();
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['setting_key'] === 'productivity.focus_weights') {
                    $decoded = json_decode($row['setting_value'], true);
                    if (is_array($decoded)) $weights = array_merge($weights, array_map('intval', $decoded));
                }
                if ($row['setting_key'] === 'productivity.deep_work_threshold_minutes') {
                    $threshold = max(5, (int)json_decode($row['setting_value'], true));
                }
            }
        } catch (\Throwable $e) {}
        return ['weights' => $weights, 'deep_work_threshold_minutes' => $threshold];
    }

    /**
     * Como install-coverage.php, sobre los usuarios de Keeper del ámbito (sin cruzar el legacy: la nómina la
     * pondrá One en E08B-T05, y «nunca instalado» se deduce allí comparando su plantilla con este listado).
     */
    public static function coverage(PDO $pdo, array $scope): array
    {
        $days = 7;
        try {
            $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'install_coverage_heartbeat_days' LIMIT 1");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && is_numeric($v)) $days = max(1, (int)$v);
        } catch (\Throwable $e) {}

        $release = null;
        try {
            $release = $pdo->query("SELECT version FROM keeper_client_releases WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
        } catch (\Throwable $e) {}

        $exempt = [];
        try {
            foreach ($pdo->query("SELECT legacy_employee_id, note_text, is_exempt FROM keeper_install_coverage_notes")->fetchAll(PDO::FETCH_ASSOC) as $n) {
                $exempt[(int)$n['legacy_employee_id']] = ['exempt' => (int)$n['is_exempt'] === 1, 'note' => $n['note_text']];
            }
        } catch (\Throwable $e) {}

        $st = $pdo->prepare("
            SELECT u.id, u.legacy_employee_id, u.display_name, u.email,
                   MAX(d.last_seen_at) AS last_seen_at,
                   MAX(CASE WHEN d.status = 'active' THEN d.client_version END) AS latest_client_version,
                   SUM(CASE WHEN d.status = 'active' THEN 1 ELSE 0 END) AS active_devices
            FROM keeper_users u
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            LEFT JOIN keeper_devices d ON d.user_id = u.id
            WHERE u.status = 'active' {$scope['sql']}
            GROUP BY u.id
            ORDER BY u.display_name
        ");
        $st->execute($scope['params']);
        $thresholdTs = strtotime("-{$days} days");
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $eid = $r['legacy_employee_id'] !== null ? (int)$r['legacy_employee_id'] : null;
            $ex = $eid !== null ? ($exempt[$eid] ?? null) : null;
            if ((int)$r['active_devices'] === 0) {
                $coverage = 'no_device';
            } elseif (!$r['last_seen_at'] || strtotime($r['last_seen_at']) < $thresholdTs) {
                $coverage = 'stale';
            } elseif ($release && !empty($r['latest_client_version']) && version_compare($r['latest_client_version'], $release, '<')) {
                $coverage = 'outdated';
            } else {
                $coverage = 'ok';
            }
            $rows[] = [
                'keeper_user_id'        => (int)$r['id'],
                'full_name'             => $r['display_name'],
                'email'                 => $r['email'],
                'last_seen_at'          => $r['last_seen_at'] ? self::iso($r['last_seen_at']) : null,
                'latest_client_version' => $r['latest_client_version'],
                'active_devices'        => (int)$r['active_devices'],
                'coverage'              => $coverage,
                'exempt'                => (bool)($ex['exempt'] ?? false),
                'note'                  => $ex['note'] ?? null,
            ];
        }
        return ['rows' => $rows, 'heartbeat_days' => $days, 'active_release' => $release];
    }

    /**
     * Como sedes-dashboard.php (tarjetas), AÑADIENDO el recorte por ámbito que la página no tiene
     * (allí el módulo es solo de superadmin por menú) y el primer ingreso y el ocio por sede.
     */
    public static function presence(PDO $pdo, array $scope, string $from, string $to): array
    {
        $sSql = $scope['sql'];
        $params = $scope['params'];
        $st = $pdo->prepare("
            SELECT
                ua.sede_id, s.nombre AS sede_name,
                COUNT(DISTINCT u.id) AS total_users,
                COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 2 MINUTE THEN u.id END) AS active_now,
                COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 15 MINUTE AND d.last_seen_at < NOW() - INTERVAL 2 MINUTE THEN u.id END) AS away_now,
                COUNT(DISTINCT CASE WHEN d.id IS NOT NULL THEN u.id END) AS with_device,
                COALESCE(SUM(a.active_seconds), 0) AS total_active,
                COALESCE(SUM(a.idle_seconds), 0) AS total_idle,
                COALESCE(SUM(a.work_hours_active_seconds), 0) AS total_work,
                COALESCE(SUM(a.call_seconds), 0) AS total_calls,
                COUNT(DISTINCT a.user_id) AS users_with_activity
            FROM keeper_users u
            INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id AND ua.sede_id IS NOT NULL
            INNER JOIN keeper_sedes s ON s.id = ua.sede_id AND s.activa = 1
            LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
            LEFT JOIN keeper_activity_day a ON a.user_id = u.id AND a.day_date BETWEEN :dfrom AND :dto
            WHERE u.status = 'active' {$sSql}
            GROUP BY ua.sede_id, s.nombre
            ORDER BY s.nombre
        ");
        $st->execute(array_merge($params, [':dfrom' => $from, ':dto' => $to]));
        $sites = $st->fetchAll(PDO::FETCH_ASSOC);

        $st = $pdo->prepare("
            SELECT ua.sede_id, MIN(we.start_at) AS first_login
            FROM keeper_window_episode we
            INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = we.user_id AND ua.sede_id IS NOT NULL
            WHERE we.day_date BETWEEN :dfrom AND :dto AND TIME(we.start_at) >= '05:00:00' {$sSql}
            GROUP BY ua.sede_id
        ");
        $st->execute(array_merge($params, [':dfrom' => $from, ':dto' => $to]));
        $firstLogin = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $firstLogin[(int)$r['sede_id']] = $r['first_login'];

        $leisure = self::leisureApps($pdo);
        $leisurePerSede = [];
        if ($leisure['apps'] !== [] || $leisure['windows'] !== []) {
            [$orClause, $lParams] = self::leisureClause($leisure);
            $st = $pdo->prepare("
                SELECT ua.sede_id, COALESCE(SUM(w.duration_seconds), 0) AS leisure_sec
                FROM keeper_window_episode w
                INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id AND ua.sede_id IS NOT NULL
                WHERE w.day_date BETWEEN :l_dfrom AND :l_dto AND ({$orClause}) {$sSql}
                GROUP BY ua.sede_id
            ");
            $st->execute(array_merge($lParams, $params, [':l_dfrom' => $from, ':l_dto' => $to]));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $leisurePerSede[(int)$r['sede_id']] = (int)$r['leisure_sec'];
        }

        return ['sites' => array_map(static function (array $s) use ($firstLogin, $leisurePerSede): array {
            $id = (int)$s['sede_id'];
            return [
                'sede_id'             => $id,
                'name'                => $s['sede_name'],
                'users'               => (int)$s['total_users'],
                'active'              => (int)$s['active_now'],
                'away'                => (int)$s['away_now'],
                'offline'             => (int)$s['total_users'] - (int)$s['active_now'] - (int)$s['away_now'],
                'without_device'      => (int)$s['total_users'] - (int)$s['with_device'],
                'users_with_activity' => (int)$s['users_with_activity'],
                'active_seconds'      => (int)$s['total_active'],
                'idle_seconds'        => (int)$s['total_idle'],
                'work_seconds'        => (int)$s['total_work'],
                'call_seconds'        => (int)$s['total_calls'],
                'first_login_at'      => isset($firstLogin[$id]) ? self::iso($firstLogin[$id]) : null,
                'leisure_seconds'     => $leisurePerSede[$id] ?? 0,
            ];
        }, $sites)];
    }

    /** Total de alertas con los mismos filtros que ProductivityRepo::getAlerts (para paginar). */
    public static function alertsCount(PDO $pdo, array $scope, ?string $type, ?string $severity, ?bool $reviewed, ?string $floor): int
    {
        $where = '';
        $params = $scope['params'];
        if ($floor)             { $where .= ' AND a.day_date >= :firm_floor'; $params[':firm_floor'] = $floor; }
        if ($type !== null)     { $where .= ' AND a.alert_type = :type';      $params[':type'] = $type; }
        if ($severity !== null) { $where .= ' AND a.severity = :sev';         $params[':sev'] = $severity; }
        if ($reviewed !== null) { $where .= ' AND a.is_reviewed = :rev';      $params[':rev'] = $reviewed ? 1 : 0; }
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM keeper_dual_job_alerts a
            INNER JOIN keeper_users u ON u.id = a.user_id
            LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            WHERE 1=1 {$scope['sql']} {$where}
        ");
        $st->execute($params);
        return (int)$st->fetchColumn();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Como getLeisureApps() de admin_auth.php. */
    private static function leisureApps(PDO $pdo): array
    {
        $empty = ['apps' => [], 'windows' => []];
        try {
            $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'leisure_apps' LIMIT 1");
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['setting_value'])) {
                $decoded = json_decode($row['setting_value'], true);
                if (is_array($decoded)) {
                    if (isset($decoded[0]) || empty($decoded)) {
                        return ['apps' => array_values($decoded), 'windows' => []];
                    }
                    return ['apps' => array_values($decoded['apps'] ?? []), 'windows' => array_values($decoded['windows'] ?? [])];
                }
            }
        } catch (\Throwable $e) {}
        return $empty;
    }

    /** @return array{0: string, 1: array} cláusula OR y sus parámetros (nombrados, sin chocar con el ámbito) */
    private static function leisureClause(array $leisure): array
    {
        $conditions = [];
        $params = [];
        if ($leisure['apps'] !== []) {
            $ph = [];
            foreach (array_values($leisure['apps']) as $i => $app) { $ph[] = ":lapp_{$i}"; $params[":lapp_{$i}"] = $app; }
            $conditions[] = 'w.process_name IN (' . implode(',', $ph) . ')';
        }
        if ($leisure['windows'] !== []) {
            $likes = [];
            foreach (array_values($leisure['windows']) as $i => $win) { $likes[] = "w.window_title LIKE :lwin_{$i}"; $params[":lwin_{$i}"] = '%' . $win . '%'; }
            $conditions[] = '(' . implode(' OR ', $likes) . ')';
        }
        return [implode(' OR ', $conditions), $params];
    }

    private static function leisureSeconds(PDO $pdo, array $leisure, string $join, string $scopeSql, array $scopeParams, string $from, string $to): int
    {
        if ($leisure['apps'] === [] && $leisure['windows'] === []) return 0;
        [$orClause, $lParams] = self::leisureClause($leisure);
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(w.duration_seconds), 0)
            FROM keeper_window_episode w
            {$join}
            WHERE w.day_date BETWEEN :l_dfrom AND :l_dto AND ({$orClause}) {$scopeSql}
        ");
        $st->execute(array_merge($lParams, $scopeParams, [':l_dfrom' => $from, ':l_dto' => $to]));
        return (int)$st->fetchColumn();
    }

    /** Instante de MySQL (hora del panel, America/Bogota) → ISO 8601 con desfase explícito. */
    private static function iso(string $mysqlDateTime): string
    {
        return (new \DateTime($mysqlDateTime, new \DateTimeZone('America/Bogota')))->format(DATE_ATOM);
    }
}
