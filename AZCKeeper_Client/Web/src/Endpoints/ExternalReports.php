<?php
namespace Keeper\Endpoints;

use Keeper\Config;
use Keeper\Db;
use Keeper\Http;
use Keeper\Repos\ExternalReportsRepo;
use Keeper\Repos\ProductivityRepo;

/**
 * ExternalReports — adaptadores de lectura de los reportes operativos del
 * panel para MOAZC/One (K3-ADP-01…06 del inventario de One, E08B).
 *
 * Identidad y ámbito (patrón «cuenta actuante»):
 *   - X-Bridge-Secret  = MOAZC_BRIDGE_SECRET (.env), como el resto de /api/external.
 *   - X-Acting-Admin   = correo de una cuenta ACTIVA del panel (keeper_admin_accounts
 *                        ⋈ keeper_users.email). El ámbito lo aplica KEEPER con las
 *                        mismas reglas del panel (rol, firma, área, sede, sociedad y
 *                        piso de historial `keeper_firmas.historial_desde`), no el
 *                        cliente: un secreto amplio no convierte un filtro en
 *                        autorización. Sin cuenta actuante válida → 403.
 *   - scope_kind / scope_ref (query): recorte ADICIONAL pedido por el cliente
 *                        (firm | area | sede | sociedad). Una firma/sociedad/sede
 *                        distinta a la de la cuenta se RECHAZA (403 scope_forbidden);
 *                        un área se intersecta con el ámbito de la cuenta (en K3 las
 *                        áreas no pertenecen a una firma).
 *
 * Solo lectura. Las fórmulas, cortes y periodos son los de las páginas del panel
 * (index.php, users.php, user-dashboard.php, productivity.php, install-coverage.php,
 * sedes-dashboard.php, dual-job-alerts.php): el consumidor no recalcula nada.
 *
 *   GET /api/external/reports/contract
 *   GET /api/external/reports/summary?period=&from=&to=[&scope_kind=&scope_ref=]
 *   GET /api/external/reports/users?period=&from=&to=&page=
 *   GET /api/external/reports/users/{keeper_user_id}?period=&from=&to=&ep_from=&ep_to=&ep_page=
 *   GET /api/external/reports/productivity?period=&from=&to=&page=&sort=
 *   GET /api/external/reports/coverage
 *   GET /api/external/reports/presence?period=&from=&to=
 *   GET /api/external/reports/alerts?type=&severity=&status=&page=
 */
class ExternalReports
{
    /** Versión del contrato que One comprueba para marcar «contrato verificado». */
    public const CONTRACT_VERSION = 'k3-reports-1';

    public const REPORTS = ['summary', 'users', 'user_detail', 'productivity', 'coverage', 'presence', 'alerts'];

    private const PAGE_SIZE = 50;

    // ── rutas ────────────────────────────────────────────────────────────

    public static function contract(): void
    {
        self::requireSecret();
        Http::json(200, [
            'ok'                  => true,
            'contract'            => self::CONTRACT_VERSION,
            'reports'             => self::REPORTS,
            'acting_admin_header' => 'X-Acting-Admin',
            'scope_kinds'         => ['firm', 'area', 'sede', 'sociedad'],
            'periods'             => ['today', 'week', 'month', 'custom'],
            'page_size'           => self::PAGE_SIZE,
            'timezone'            => 'America/Bogota',
        ]);
    }

    public static function summary(): void
    {
        [$pdo, $admin, $scope] = self::context();
        $period = self::period($admin);
        self::respond('summary', $period, $scope, ExternalReportsRepo::summary($pdo, $scope, $period['from'], $period['to']));
    }

    public static function users(): void
    {
        [$pdo, $admin, $scope] = self::context();
        $period = self::period($admin);
        $page   = self::page();
        $rows   = ExternalReportsRepo::users($pdo, $scope, $period['from'], $period['to']);
        $total  = count($rows);
        self::respond('users', $period, $scope, [
            'users' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'page'  => $page,
            'pages' => max(1, (int)ceil($total / self::PAGE_SIZE)),
            'total' => $total,
        ]);
    }

    public static function userDetail(int $keeperUserId): void
    {
        [$pdo, $admin, $scope] = self::context();
        $period = self::period($admin);
        if (!ExternalReportsRepo::userInScope($pdo, $scope, $keeperUserId)) {
            // Fuera del ámbito de la cuenta actuante o inexistente: la misma respuesta (no se revela cuál).
            Http::json(404, ['ok' => false, 'error' => 'not_found']);
        }
        $epFrom = self::dateParam('ep_from') ?? date('Y-m-d', strtotime('-30 days'));
        $epTo   = self::dateParam('ep_to') ?? date('Y-m-d');
        if ($epFrom > $epTo) { [$epFrom, $epTo] = [$epTo, $epFrom]; }
        $epFrom = self::clampFrom($admin, $epFrom);
        $epPage = self::page('ep_page');
        $episodes = ExternalReportsRepo::episodes($pdo, $keeperUserId, $epFrom, $epTo);
        $data = ExternalReportsRepo::userDetail($pdo, $keeperUserId, $period['from'], $period['to']);
        $data['episodes']       = array_slice($episodes, ($epPage - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $data['episodes_page']  = $epPage;
        $data['episodes_pages'] = max(1, (int)ceil(count($episodes) / self::PAGE_SIZE));
        $data['episodes_range'] = ['from' => $epFrom, 'to' => $epTo];
        self::respond('user_detail', $period, $scope, $data);
    }

    public static function productivity(): void
    {
        [$pdo, $admin, $scope] = self::context();
        $period = self::period($admin, 'week');
        $page   = self::page();
        $sort   = (($_GET['sort'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
        $total  = ProductivityRepo::getUserRankingCount($pdo, $period['from'], $period['to'], $scope['sql'], $scope['params']);
        $pages  = max(1, (int)ceil($total / self::PAGE_SIZE));
        $page   = min($page, $pages);
        $rows   = ProductivityRepo::getUserRanking($pdo, $period['from'], $period['to'], $scope['sql'], $scope['params'], $sort, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        $cfg    = ExternalReportsRepo::focusConfig($pdo);
        self::respond('productivity', $period, $scope, [
            'rows' => array_map(static fn(array $r): array => [
                'keeper_user_id'     => (int)$r['user_id'],
                'full_name'          => $r['display_name'],
                'firm_id'            => $r['firm_id'] !== null ? (int)$r['firm_id'] : null,
                'area_id'            => $r['area_id'] !== null ? (int)$r['area_id'] : null,
                'focus_score'        => (int)round((float)$r['avg_focus']),
                'productivity_pct'   => (int)round((float)$r['avg_productivity']),
                'constancy_pct'      => (int)round((float)$r['avg_constancy']),
                'deep_work_seconds'  => (int)$r['avg_deep_work_sec'],
                'context_switches'   => (int)$r['avg_switches'],
                'distraction_seconds' => (int)($r['avg_distraction_sec'] ?? 0),
                'punctuality_minutes' => (int)round((float)($r['avg_punctuality'] ?? 0)),
                'days'               => (int)$r['days_tracked'],
            ], $rows),
            'weights'                    => $cfg['weights'],
            'deep_work_threshold_minutes' => $cfg['deep_work_threshold_minutes'],
            'kpis'                       => ProductivityRepo::getGlobalKPIs($pdo, $period['from'], $period['to'], $scope['sql'], $scope['params']),
            'page'                       => $page,
            'pages'                      => $pages,
            'total'                      => $total,
        ]);
    }

    public static function coverage(): void
    {
        [$pdo, $admin, $scope] = self::context();
        self::respond('coverage', null, $scope, ExternalReportsRepo::coverage($pdo, $scope));
    }

    public static function presence(): void
    {
        [$pdo, $admin, $scope] = self::context();
        $period = self::period($admin);
        self::respond('presence', $period, $scope, ExternalReportsRepo::presence($pdo, $scope, $period['from'], $period['to']));
    }

    public static function alerts(): void
    {
        [$pdo, $admin, $scope] = self::context();
        $type     = self::enumParam('type', ['after_hours_pattern', 'foreign_app', 'remote_desktop', 'suspicious_idle']);
        $severity = self::enumParam('severity', ['low', 'medium', 'high']);
        $status   = self::enumParam('status', ['pending', 'reviewed']);
        $reviewed = $status === null ? null : ($status === 'reviewed');
        $page     = self::page();
        $floor    = $admin['firm_floor'] ?? null;
        $rows     = ProductivityRepo::getAlerts($pdo, $scope['sql'], $scope['params'], $type, $severity, $reviewed, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $floor);
        $total    = ExternalReportsRepo::alertsCount($pdo, $scope, $type, $severity, $reviewed, $floor);
        self::respond('alerts', null, $scope, [
            'alerts' => array_map(static fn(array $a): array => [
                'id'             => (int)$a['id'],
                'keeper_user_id' => (int)$a['user_id'],
                'full_name'      => $a['display_name'],
                'day_date'       => $a['day_date'],
                'alert_type'     => $a['alert_type'],
                'severity'       => $a['severity'],
                'is_reviewed'    => (bool)$a['is_reviewed'],
                'reviewed_at'    => $a['reviewed_at'],
                'evidence'       => $a['evidence_json'] !== null ? json_decode((string)$a['evidence_json'], true) : null,
            ], $rows),
            'page'  => $page,
            'pages' => max(1, (int)ceil($total / self::PAGE_SIZE)),
            'total' => $total,
        ]);
    }

    // ── identidad y ámbito ───────────────────────────────────────────────

    /** @return array{0: \PDO, 1: array, 2: array{sql: string, params: array, requested: ?array}} */
    private static function context(): array
    {
        self::requireSecret();
        // Zona del panel: America/Bogota en PHP y en MySQL, como admin_auth.php.
        date_default_timezone_set('America/Bogota');
        try {
            $pdo = Db::pdo();
            $pdo->exec("SET time_zone = '-05:00'");
            // Las consultas del panel (p. ej. ProductivityRepo::getUserRanking) agrupan por u.id y leen columnas de
            // ua: funcionan porque el servidor del panel no impone ONLY_FULL_GROUP_BY. Aquí se garantiza por sesión.
            $pdo->exec("SET SESSION sql_mode = (SELECT REPLACE(REPLACE(@@SESSION.sql_mode, 'ONLY_FULL_GROUP_BY,', ''), 'ONLY_FULL_GROUP_BY', ''))");
        } catch (\Throwable $e) {
            error_log('ExternalReports: BD no disponible — ' . $e->getMessage());
            Http::json(503, ['ok' => false, 'error' => 'unavailable']);
        }
        $admin = self::actingAdmin($pdo);
        $scope = self::scope($admin);
        return [$pdo, $admin, $scope];
    }

    private static function requireSecret(): void
    {
        $expected = (string)Config::get('MOAZC_BRIDGE_SECRET', '');
        $given    = (string)($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            Http::json($expected === '' ? 503 : 401, ['ok' => false, 'error' => 'unauthorized']);
        }
    }

    /**
     * La cuenta del panel con la que se actúa: activa, hallada por correo. Devuelve la
     * misma forma que AdminAuthRepo::validateSession más `firm_floor`.
     */
    private static function actingAdmin(\PDO $pdo): array
    {
        $email = strtolower(trim((string)($_SERVER['HTTP_X_ACTING_ADMIN'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::json(400, ['ok' => false, 'error' => 'acting_admin_required']);
        }
        $st = $pdo->prepare("
            SELECT a.id AS admin_id, a.keeper_user_id, a.panel_role, a.firm_scope_id, a.area_scope_id,
                   a.sede_scope_id, a.sociedad_scope_id, u.display_name, u.email
            FROM keeper_admin_accounts a
            INNER JOIN keeper_users u ON u.id = a.keeper_user_id
            WHERE LOWER(u.email) = :e AND a.is_active = 1 AND u.status = 'active'
            LIMIT 1
        ");
        $st->execute([':e' => $email]);
        $admin = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$admin) {
            Http::json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'acting_admin_unknown']);
        }
        // Piso de historial: igual que admin_auth.php (solo firma-admins no superadmin).
        $admin['firm_floor'] = null;
        if ($admin['panel_role'] !== 'superadmin' && !empty($admin['firm_scope_id'])) {
            try {
                $st = $pdo->prepare("SELECT historial_desde FROM keeper_firmas WHERE id = ? LIMIT 1");
                $st->execute([$admin['firm_scope_id']]);
                $admin['firm_floor'] = $st->fetchColumn() ?: null;
            } catch (\Throwable $e) { /* columna aún no migrada → sin piso */ }
        }
        return $admin;
    }

    /**
     * Ámbito efectivo = ámbito de la cuenta (scopeFilter() del panel) ∩ ámbito pedido.
     * Firma, sede o sociedad distintas a las de la cuenta se rechazan; el área se intersecta.
     *
     * @return array{sql: string, params: array, requested: ?array}
     */
    private static function scope(array $admin): array
    {
        $sql = '';
        $params = [];
        $super = ($admin['panel_role'] ?? '') === 'superadmin';

        if (!$super) {
            if (!empty($admin['firm_scope_id']))     { $sql .= ' AND ua.firm_id = :scope_firm';         $params[':scope_firm']     = (int)$admin['firm_scope_id']; }
            if (!empty($admin['area_scope_id']))     { $sql .= ' AND ua.area_id = :scope_area';         $params[':scope_area']     = (int)$admin['area_scope_id']; }
            if (!empty($admin['sede_scope_id']))     { $sql .= ' AND ua.sede_id = :scope_sede';         $params[':scope_sede']     = (int)$admin['sede_scope_id']; }
            if (!empty($admin['sociedad_scope_id'])) { $sql .= ' AND ua.sociedad_id = :scope_sociedad'; $params[':scope_sociedad'] = (int)$admin['sociedad_scope_id']; }
        }

        $kind = (string)($_GET['scope_kind'] ?? '');
        $ref  = $_GET['scope_ref'] ?? null;
        if ($kind === '' && ($ref === null || $ref === '')) {
            return ['sql' => $sql, 'params' => $params, 'requested' => null];
        }
        $columns = ['firm' => 'firm_id', 'area' => 'area_id', 'sede' => 'sede_id', 'sociedad' => 'sociedad_id'];
        if (!isset($columns[$kind]) || !ctype_digit((string)$ref) || (int)$ref <= 0) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_scope']);
        }
        $ref = (int)$ref;
        $own = ['firm' => 'firm_scope_id', 'area' => 'area_scope_id', 'sede' => 'sede_scope_id', 'sociedad' => 'sociedad_scope_id'][$kind];
        if (!$super && !empty($admin[$own]) && (int)$admin[$own] !== $ref) {
            Http::json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'scope_forbidden']);
        }
        // Una cuenta con alcance de firma/sociedad/sede no lee OTRA firma/sociedad aunque no tenga fijada esa dimensión.
        if (!$super && in_array($kind, ['firm', 'sociedad'], true) && empty($admin[$own]) && (!empty($admin['firm_scope_id']) || !empty($admin['sociedad_scope_id']))) {
            Http::json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'scope_forbidden']);
        }
        $sql .= " AND ua.{$columns[$kind]} = :req_scope";
        $params[':req_scope'] = $ref;
        return ['sql' => $sql, 'params' => $params, 'requested' => ['kind' => $kind, 'ref' => $ref]];
    }

    // ── periodo y parámetros ────────────────────────────────────────────

    /** Mismo cálculo que las páginas del panel (zona America/Bogota fijada en bootstrap del endpoint). */
    private static function period(array $admin, string $default = 'today'): array
    {
        $period = (string)($_GET['period'] ?? $default);
        if (!in_array($period, ['today', 'week', 'month', 'custom'], true)) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_period']);
        }
        switch ($period) {
            case 'week':
                $from = date('Y-m-d', strtotime('monday this week'));
                $to   = date('Y-m-d');
                break;
            case 'month':
                $from = date('Y-m-01');
                $to   = date('Y-m-d');
                break;
            case 'custom':
                $from = self::dateParam('from');
                $to   = self::dateParam('to');
                if ($from === null || $to === null || $from > $to) {
                    Http::json(400, ['ok' => false, 'error' => 'invalid_period']);
                }
                break;
            default:
                $from = date('Y-m-d');
                $to   = date('Y-m-d');
        }
        return ['from' => self::clampFrom($admin, $from), 'to' => $to, 'period' => $period];
    }

    private static function clampFrom(array $admin, string $from): string
    {
        $floor = $admin['firm_floor'] ?? null;
        return ($floor && $floor > $from) ? $floor : $from;
    }

    private static function dateParam(string $name): ?string
    {
        $v = (string)($_GET[$name] ?? '');
        if ($v === '') return null;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_date', 'field' => $name]);
        }
        return $v;
    }

    private static function page(string $name = 'page'): int
    {
        $v = $_GET[$name] ?? '1';
        if (!ctype_digit((string)$v) || (int)$v < 1) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_page']);
        }
        return (int)$v;
    }

    private static function enumParam(string $name, array $allowed): ?string
    {
        $v = (string)($_GET[$name] ?? '');
        if ($v === '') return null;
        if (!in_array($v, $allowed, true)) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_' . $name]);
        }
        return $v;
    }

    private static function respond(string $report, ?array $period, array $scope, array $data): void
    {
        Http::json(200, [
            'ok'           => true,
            'contract'     => self::CONTRACT_VERSION,
            'report'       => $report,
            'generated_at' => (new \DateTime('now', new \DateTimeZone('America/Bogota')))->format(DATE_ATOM),
            'period'       => $period === null ? null : ['from' => $period['from'], 'to' => $period['to']],
            'scope'        => $scope['requested'],
            'data'         => $data,
        ]);
    }
}
