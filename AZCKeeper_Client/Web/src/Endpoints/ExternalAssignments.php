<?php
namespace Keeper\Endpoints;

use Keeper\Config;
use Keeper\Db;
use Keeper\Http;
use Keeper\Repos\AuditRepo;
use Keeper\Repos\ExternalReportsRepo;

/**
 * ExternalAssignments — sincronización compartida con MOAZC/One (K3-ADP-07 y 08).
 *
 *   PUT /api/external/assignments/{legacy_employee_id}
 *       {firm_id, area_id, cargo_id, sede_id, sociedad_id, intent_version}
 *       → {ok, result: applied | unchanged | kept_override | not_mapped, keeper_values, source, source_version}
 *   GET /api/external/assignments?updated_since=ISO&page=
 *       → identidad + asignación + manual_override + source (paginado, en el ámbito de la cuenta actuante)
 *
 * Identidad como /external/reports: X-Bridge-Secret + X-Acting-Admin (cuenta ACTIVA del panel). Aplicar exige
 * además rol superadmin o admin y, si la cuenta tiene alcance de firma/área/sede/sociedad, que la persona esté
 * dentro de él (como canViewUser()).
 *
 * Precedencia de fuentes (K3-ADP-07c): la asignación aplicada queda con source = 'one' + source_version y el
 * sincronizador legacy no la revierte; manual_override = 1 (excepción manual del panel) manda sobre One y se
 * devuelve como kept_override con los valores de Keeper, sin tocarla. Idempotente: la misma intent_version ya
 * aplicada responde unchanged.
 */
class ExternalAssignments
{
    private const PAGE_SIZE = 200;

    private const FIELDS = ['firm_id' => 'keeper_firmas', 'area_id' => 'keeper_areas', 'cargo_id' => 'keeper_cargos', 'sede_id' => 'keeper_sedes', 'sociedad_id' => 'keeper_sociedades'];

    public static function apply(int $legacyEmployeeId): void
    {
        [$pdo, $admin, $scope] = self::context();
        if (!in_array($admin['panel_role'], ['superadmin', 'admin'], true)) {
            Http::json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'role_cannot_assign']);
        }

        $data = Http::jsonInput();
        $intent = trim((string)($data['intent_version'] ?? ''));
        if ($intent === '' || strlen($intent) > 64 || !preg_match('/^[A-Za-z0-9._:-]+$/', $intent)) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_intent_version']);
        }

        $values = [];
        foreach (self::FIELDS as $field => $table) {
            if (!array_key_exists($field, $data)) {
                Http::json(400, ['ok' => false, 'error' => 'missing_field', 'field' => $field]);
            }
            $v = $data[$field];
            if ($v === null || $v === '') { $values[$field] = null; continue; }
            if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
                Http::json(400, ['ok' => false, 'error' => 'invalid_field', 'field' => $field]);
            }
            $v = (int)$v;
            $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id LIMIT 1");
            $st->execute([':id' => $v]);
            if (!$st->fetchColumn()) {
                Http::json(422, ['ok' => false, 'error' => 'unknown_reference', 'field' => $field, 'value' => $v]);
            }
            $values[$field] = $v;
        }

        $st = $pdo->prepare("SELECT id, status FROM keeper_users WHERE legacy_employee_id = :lid LIMIT 1");
        $st->execute([':lid' => $legacyEmployeeId]);
        $user = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            Http::json(404, ['ok' => false, 'error' => 'not_mapped', 'result' => 'not_mapped', 'legacy_employee_id' => $legacyEmployeeId]);
        }
        $keeperUserId = (int)$user['id'];
        // Fuera del ámbito de la cuenta actuante: como canViewUser(), sin revelar si existe.
        if ($scope['sql'] !== '' && !ExternalReportsRepo::userInScope($pdo, $scope, $keeperUserId)) {
            Http::json(404, ['ok' => false, 'error' => 'not_mapped', 'result' => 'not_mapped', 'legacy_employee_id' => $legacyEmployeeId]);
        }

        $st = $pdo->prepare("SELECT * FROM keeper_user_assignments WHERE keeper_user_id = :uid LIMIT 1");
        $st->execute([':uid' => $keeperUserId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;

        if ($row && (int)$row['manual_override'] === 1) {
            self::audit($pdo, $admin, $keeperUserId, 'external_assignment_kept_override', $intent);
            self::respond('kept_override', $row, $keeperUserId, $legacyEmployeeId);
        }

        if ($row && ($row['source'] ?? '') === 'one' && (string)$row['source_version'] === $intent) {
            self::respond('unchanged', $row, $keeperUserId, $legacyEmployeeId);
        }

        try {
            if ($row) {
                // Condicionado a que siga sin excepción manual: si el panel la fijó entre la lectura y esta escritura, no se
                // pisa y se responde con lo que Keeper tiene (kept_override).
                $st = $pdo->prepare("
                    UPDATE keeper_user_assignments
                    SET firm_id = :fid, area_id = :aid, cargo_id = :cid, sede_id = :sid, sociedad_id = :soc,
                        source = 'one', source_version = :ver, source_applied_at = NOW(), assigned_by = :by, updated_at = NOW()
                    WHERE id = :id AND manual_override = 0
                ");
                $st->execute([':fid' => $values['firm_id'], ':aid' => $values['area_id'], ':cid' => $values['cargo_id'], ':sid' => $values['sede_id'], ':soc' => $values['sociedad_id'], ':ver' => $intent, ':by' => (int)$admin['admin_id'], ':id' => (int)$row['id']]);
                if ($st->rowCount() === 0) {
                    $again = $pdo->prepare("SELECT * FROM keeper_user_assignments WHERE id = :id LIMIT 1");
                    $again->execute([':id' => (int)$row['id']]);
                    $current = $again->fetch(\PDO::FETCH_ASSOC) ?: $row;
                    if ((int)($current['manual_override'] ?? 0) === 1) {
                        self::audit($pdo, $admin, $keeperUserId, 'external_assignment_kept_override', $intent);
                        self::respond('kept_override', $current, $keeperUserId, $legacyEmployeeId);
                    }
                }
            } else {
                $pdo->prepare("
                    INSERT INTO keeper_user_assignments (keeper_user_id, firm_id, area_id, cargo_id, sede_id, sociedad_id, manual_override, source, source_version, source_applied_at, assigned_by, assigned_at, updated_at)
                    VALUES (:uid, :fid, :aid, :cid, :sid, :soc, 0, 'one', :ver, NOW(), :by, NOW(), NOW())
                ")->execute([':uid' => $keeperUserId, ':fid' => $values['firm_id'], ':aid' => $values['area_id'], ':cid' => $values['cargo_id'], ':sid' => $values['sede_id'], ':soc' => $values['sociedad_id'], ':ver' => $intent, ':by' => (int)$admin['admin_id']]);
            }
        } catch (\Throwable $e) {
            error_log('ExternalAssignments::apply error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'server_error', 'result' => 'error']);
        }

        self::audit($pdo, $admin, $keeperUserId, 'external_assignment_applied', $intent);
        $st = $pdo->prepare("SELECT * FROM keeper_user_assignments WHERE keeper_user_id = :uid LIMIT 1");
        $st->execute([':uid' => $keeperUserId]);
        self::respond('applied', $st->fetch(\PDO::FETCH_ASSOC) ?: [], $keeperUserId, $legacyEmployeeId);
    }

    public static function index(): void
    {
        [$pdo, $admin, $scope] = self::context();
        $since = (string)($_GET['updated_since'] ?? '');
        $sinceSql = '';
        $params = $scope['params'];
        if ($since !== '') {
            $ts = strtotime($since);
            if ($ts === false) {
                Http::json(400, ['ok' => false, 'error' => 'invalid_updated_since']);
            }
            $sinceSql = ' AND ua.updated_at >= :since';
            $params[':since'] = (new \DateTime('@' . $ts))->setTimezone(new \DateTimeZone('America/Bogota'))->format('Y-m-d H:i:s');
        }
        $page = $_GET['page'] ?? '1';
        if (!ctype_digit((string)$page) || (int)$page < 1) {
            Http::json(400, ['ok' => false, 'error' => 'invalid_page']);
        }
        $page = (int)$page;

        $st = $pdo->prepare("
            SELECT u.id AS keeper_user_id, u.legacy_employee_id, u.cc, u.email, u.display_name, u.status, u.employment_status,
                   ua.firm_id, ua.area_id, ua.cargo_id, ua.sede_id, ua.sociedad_id, ua.manual_override, ua.source, ua.source_version, ua.updated_at
            FROM keeper_users u
            INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
            WHERE 1=1 {$scope['sql']} {$sinceSql}
            ORDER BY ua.updated_at ASC, u.id ASC
        ");
        $st->execute($params);
        $rows = array_map(static fn(array $r): array => [
            'keeper_user_id'     => (int)$r['keeper_user_id'],
            'legacy_employee_id' => $r['legacy_employee_id'] !== null ? (int)$r['legacy_employee_id'] : null,
            'cc'                 => $r['cc'],
            'email'              => $r['email'],
            'full_name'          => $r['display_name'],
            'status'             => $r['status'],
            'employment_status'  => $r['employment_status'] ?? 'active',
            'firm_id'            => $r['firm_id'] !== null ? (int)$r['firm_id'] : null,
            'area_id'            => $r['area_id'] !== null ? (int)$r['area_id'] : null,
            'cargo_id'           => $r['cargo_id'] !== null ? (int)$r['cargo_id'] : null,
            'sede_id'            => $r['sede_id'] !== null ? (int)$r['sede_id'] : null,
            'sociedad_id'        => $r['sociedad_id'] !== null ? (int)$r['sociedad_id'] : null,
            'manual_override'    => (bool)$r['manual_override'],
            'source'             => $r['source'] ?? 'legacy',
            'source_version'     => $r['source_version'],
            'updated_at'         => ExternalReportsRepo::iso((string)$r['updated_at']),
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
        $total = count($rows);
        Http::json(200, [
            'ok'           => true,
            'contract'     => ExternalReports::CONTRACT_VERSION,
            'generated_at' => (new \DateTime('now', new \DateTimeZone('America/Bogota')))->format(DATE_ATOM),
            'data'         => ['assignments' => array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), 'page' => $page, 'pages' => max(1, (int)ceil($total / self::PAGE_SIZE)), 'total' => $total, 'updated_since' => $since === '' ? null : $since],
        ]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0: \PDO, 1: array, 2: array{sql: string, params: array}} */
    private static function context(): array
    {
        $expected = (string)Config::get('MOAZC_BRIDGE_SECRET', '');
        $given    = (string)($_SERVER['HTTP_X_BRIDGE_SECRET'] ?? '');
        if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
            Http::json($expected === '' ? 503 : 401, ['ok' => false, 'error' => 'unauthorized']);
        }
        date_default_timezone_set('America/Bogota');
        try {
            $pdo = Db::pdo();
            $pdo->exec("SET time_zone = '-05:00'");
        } catch (\Throwable $e) {
            Http::json(503, ['ok' => false, 'error' => 'unavailable']);
        }
        $email = strtolower(trim((string)($_SERVER['HTTP_X_ACTING_ADMIN'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Http::json(400, ['ok' => false, 'error' => 'acting_admin_required']);
        }
        $st = $pdo->prepare("
            SELECT a.id AS admin_id, a.keeper_user_id, a.panel_role, a.firm_scope_id, a.area_scope_id, a.sede_scope_id, a.sociedad_scope_id, u.email
            FROM keeper_admin_accounts a INNER JOIN keeper_users u ON u.id = a.keeper_user_id
            WHERE LOWER(u.email) = :e AND a.is_active = 1 AND u.status = 'active' LIMIT 1
        ");
        $st->execute([':e' => $email]);
        $admin = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$admin) {
            Http::json(403, ['ok' => false, 'error' => 'forbidden', 'reason' => 'acting_admin_unknown']);
        }
        $sql = '';
        $params = [];
        if ($admin['panel_role'] !== 'superadmin') {
            foreach (['firm_scope_id' => 'firm_id', 'area_scope_id' => 'area_id', 'sede_scope_id' => 'sede_id', 'sociedad_scope_id' => 'sociedad_id'] as $scopeKey => $column) {
                if (!empty($admin[$scopeKey])) { $sql .= " AND ua.{$column} = :scope_{$column}"; $params[":scope_{$column}"] = (int)$admin[$scopeKey]; }
            }
        }
        return [$pdo, $admin, ['sql' => $sql, 'params' => $params]];
    }

    private static function respond(string $result, array $row, int $keeperUserId, int $legacyEmployeeId): void
    {
        Http::json(200, [
            'ok'                 => true,
            'contract'           => ExternalReports::CONTRACT_VERSION,
            'result'             => $result,
            'keeper_user_id'     => $keeperUserId,
            'legacy_employee_id' => $legacyEmployeeId,
            'keeper_values'      => [
                'firm_id'     => isset($row['firm_id']) ? (int)$row['firm_id'] : null,
                'area_id'     => isset($row['area_id']) ? (int)$row['area_id'] : null,
                'cargo_id'    => isset($row['cargo_id']) ? (int)$row['cargo_id'] : null,
                'sede_id'     => isset($row['sede_id']) ? (int)$row['sede_id'] : null,
                'sociedad_id' => isset($row['sociedad_id']) ? (int)$row['sociedad_id'] : null,
            ],
            'manual_override'    => (bool)($row['manual_override'] ?? false),
            'source'             => $row['source'] ?? 'legacy',
            'source_version'     => $row['source_version'] ?? null,
            'updated_at'         => isset($row['updated_at']) ? ExternalReportsRepo::iso((string)$row['updated_at']) : null,
        ]);
    }

    private static function audit(\PDO $pdo, array $admin, int $keeperUserId, string $action, string $intent): void
    {
        try {
            AuditRepo::log($pdo, $keeperUserId, null, $action, "One aplicó asignación (intent {$intent}) actuando como {$admin['email']}", ['acting_admin_id' => (int)$admin['admin_id'], 'intent_version' => $intent]);
        } catch (\Throwable $e) { /* la auditoría no bloquea */ }
    }
}
