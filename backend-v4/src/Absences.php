<?php
declare(strict_types=1);
namespace Keeper;

// Ausencias de RR. HH. (spec de integraciones §3.6). Si la empresa las recibe de One (dominio "ausencias") no se
// editan aqui. Cada cambio recalcula los dias afectados: una ausencia aprobada que cubre la jornada deja el dia sin
// tiempo esperado (ActivityCalendar::expected).
final class Absences
{
    public const KINDS = ['vacaciones', 'licencia_remunerada', 'licencia_no_remunerada', 'incapacidad', 'permiso', 'calamidad', 'otro'];
    private string $tenant;
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) { $this->tenant = $c['tenant_id']; }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        return match ($op) {
            'listAbsences' => $this->list(),
            'createAbsence' => $this->save(null, $b),
            'patchAbsence' => $this->save($id, $b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }
    public static function dto(array $a): array
    {
        return ['id' => Util::id($a['id']), 'user_id' => Util::id($a['user_id']), 'kind' => $a['kind'], 'starts_at' => Util::time($a['starts_at']), 'ends_at' => Util::time($a['ends_at']),
            'all_day' => (bool) $a['all_day'], 'status' => $a['status'], 'note' => $a['note'], 'version' => (int) $a['version']];
    }
    /** Dias (locales del horario de la persona) que toca una ausencia: los que hay que recalcular. */
    public static function days(Database $db, string $tenant, string $user, string $from, string $to): array
    {
        $tz = $db->one('SELECT s.timezone FROM users u JOIN schedules s ON s.tenant_id=u.tenant_id AND s.id=u.schedule_id WHERE u.tenant_id=? AND u.id=?', [$tenant, $user])['timezone'] ?? 'UTC';
        $zone = new \DateTimeZone($tz); $out = [];
        $d = (new \DateTimeImmutable($from . ' UTC'))->setTimezone($zone)->setTime(0, 0); $end = (new \DateTimeImmutable($to . ' UTC'))->setTimezone($zone);
        for ($n = 0; $d < $end && $n < 120; $d = $d->modify('+1 day'), $n++) { $out[] = $d->format('Y-m-d'); }
        return $out;
    }
    private function list(): array
    {
        [$where, $args] = $this->access->usersSql('u');
        $sql = "SELECT a.* FROM absences a JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND $where";
        $params = [$this->tenant, ...$args];
        if (isset($_GET['user_id'])) { $sql .= ' AND a.user_id=?'; $params[] = Util::bin($_GET['user_id']); }
        if (isset($_GET['from'])) { $sql .= ' AND a.ends_at>?'; $params[] = $_GET['from'] . ' 00:00:00'; }
        if (isset($_GET['to'])) { $sql .= ' AND a.starts_at<?'; $params[] = $_GET['to'] . ' 00:00:00'; }
        return AdminApi::response(['data' => array_map([self::class, 'dto'], $this->db->run($sql . ' ORDER BY a.starts_at DESC LIMIT 500', $params)->fetchAll())]);
    }
    private function save(?string $id, object $b): array
    {
        $old = null;
        if ($id !== null) {
            $old = $this->db->one('SELECT * FROM absences WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant, $id]);
            if (!$old) { throw new ApiError(404, 'resource_not_found'); }
            $this->access->user($old['user_id']);
            if ($this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        }
        DataSources::guardWrite($this->db, $this->tenant, 'ausencias', 'absence', $id);
        $user = isset($b->user_id) ? Util::bin($b->user_id) : $old['user_id'];
        $this->access->user($user);
        $starts = isset($b->starts_at) ? Util::sqlTime($b->starts_at) : $old['starts_at'];
        $ends = isset($b->ends_at) ? Util::sqlTime($b->ends_at) : $old['ends_at'];
        if ($ends <= $starts || strtotime($ends . ' UTC') - strtotime($starts . ' UTC') > 400 * 86400) { throw new ApiError(422, 'validation_failed', [], 'invalid_range'); }
        $values = ['user_id' => $user, 'kind' => $b->kind ?? $old['kind'], 'starts_at' => $starts, 'ends_at' => $ends, 'all_day' => (int) ($b->all_day ?? $old['all_day'] ?? true),
            'status' => $b->status ?? $old['status'] ?? 'approved', 'note' => property_exists($b, 'note') ? $b->note : ($old['note'] ?? null)];
        if ($id === null) {
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO absences (tenant_id,id,user_id,kind,starts_at,ends_at,all_day,status,note) VALUES (?,?,?,?,?,?,?,?,?)', [$this->tenant, $id, ...array_values($values)]);
        } else {
            $this->db->run('UPDATE absences SET user_id=?,kind=?,starts_at=?,ends_at=?,all_day=?,status=?,note=?,version=version+1 WHERE tenant_id=? AND id=?', [...array_values($values), $this->tenant, $id]);
        }
        $days = self::days($this->db, $this->tenant, $user, $starts, $ends);
        if ($old) { $days = array_merge($days, self::days($this->db, $this->tenant, $old['user_id'], $old['starts_at'], $old['ends_at'])); }
        ActivityCalendar::refresh($this->db, $this->tenant, $days);
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'absence', $id, array_keys(get_object_vars($b)));
        return AdminApi::response(self::dto($this->db->one('SELECT * FROM absences WHERE tenant_id=? AND id=?', [$this->tenant, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
}
