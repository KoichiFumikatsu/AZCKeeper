<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Formulas de productividad, focus y distraccion (decision de Koichi 2026-10-09; reemplaza la del 2026-10-01, que
 * dividia por el uso medido y dejaba fuera el tiempo lejos del equipo).
 *
 *   J = jornada esperada: franja del horario de los dias laborables, menos almuerzo y ausencias aprobadas, solo la
 *       parte ya transcurrida (ActivityCalendar::workday). Festivo, dia sin franja o ausencia de dia completo: J=0 y
 *       el dia queda FUERA del periodo (su tiempo tampoco suma a P ni a N).
 *   P = segundos en uso (teclado o raton) en apps y sitios productivos.
 *   N = segundos en uso en apps neutrales; lo SIN CLASIFICAR cuenta como neutral. N = uso medido en ventanas - P - D,
 *       con D = uso en apps poco productivas. Dias importados de K3 (sin ventanas): uso total - P - D.
 *   Productividad = P / J        Focus = (P + N) / J        Distraccion = 100 - Focus = (J - P - N) / J
 * Un dia laborable sin actividad cuenta (J>0, P=N=0 => 0 %), nunca «sin dato». Un periodo suma tiempos y jornadas de
 * todos sus dias antes de dividir (no promedia porcentajes diarios). Cada porcentaje queda entre 0 y 100; J=0 => null
 * («Sin jornada en el periodo»). El focus anterior (bloques de 25 min o mas: focus_seconds, deep_work_*) sigue en la
 * base y en la API, y el panel lo llama «Trabajo profundo».
 */
final class ProductivityFormula
{
    /** Campos de jornada que cada fila (persona, dia) lleva y que un periodo suma. */
    public const FIELDS = ['workday_expected_seconds', 'workday_productive_seconds', 'workday_neutral_seconds'];

    /** Partes de jornada de un dia de una persona. Dia sin jornada: todo 0 (fuera del periodo). */
    public static function day(int $workday, int $productive, int $windowActive, int $unproductive): array
    {
        if ($workday <= 0) { return array_fill_keys(self::FIELDS, 0); }
        return ['workday_expected_seconds' => $workday, 'workday_productive_seconds' => max(0, $productive),
            'workday_neutral_seconds' => max(0, $windowActive - $productive - $unproductive)];
    }

    /** Porcentajes de un periodo (o un dia) a partir de sus sumas de jornada. */
    public static function percents(array $sums): array
    {
        $j = (int) ($sums['workday_expected_seconds'] ?? 0); $p = (int) ($sums['workday_productive_seconds'] ?? 0); $n = (int) ($sums['workday_neutral_seconds'] ?? 0);
        if ($j <= 0) { return ['productivity_percent' => null, 'focus_score' => null, 'distraction_percent' => null]; }
        $focus = round(min(100, 100 * ($p + $n) / $j), 2);
        return ['productivity_percent' => round(min(100, 100 * $p / $j), 2), 'focus_score' => $focus, 'distraction_percent' => round(100 - $focus, 2)];
    }

    /**
     * Tabla temporal workday_facts: una fila por (dia, persona) del calendario de las asignaciones en alcance, con los
     * dias sin actividad incluidos (en cero). La usan /reports/productivity y la API externa; /users/{id}/activity,
     * /reports/people y el tablero arman la suya (ActivityReports) con las mismas columnas de jornada.
     * $where: condiciones extra sobre a (user_assignments) y u (users), empezando por " AND".
     */
    public static function facts(Database $db, string $tenant, string $from, string $to, string $where, array $args, bool $prepared = false): void
    {
        $raw = [self::rawSelect($where), [$tenant, $from, $to, $tenant, ...$args]];
        if ($prepared) {
            // API externa: transaccion de solo lectura (sin DDL); las tablas las creo prepare() antes y aqui solo se llenan.
            ActivityCalendar::create($db, $tenant, $from, $to, true);
            foreach (['workday_raw', 'workday_facts'] as $t) { $db->run("DELETE FROM $t"); }
            $db->run('INSERT INTO workday_raw ' . $raw[0], $raw[1]);
            $db->run('INSERT INTO workday_facts ' . self::FACTS_SELECT);
            return;
        }
        ActivityCalendar::create($db, $tenant, $from, $to);
        try {
            $db->run('DROP TEMPORARY TABLE IF EXISTS workday_raw');
            $db->run('DROP TEMPORARY TABLE IF EXISTS workday_facts');
            $db->run('CREATE TEMPORARY TABLE workday_raw ENGINE=InnoDB AS ' . $raw[0], $raw[1]);
            $db->run('CREATE TEMPORARY TABLE workday_facts ENGINE=InnoDB AS ' . self::FACTS_SELECT);
        } finally {
            $db->run('DROP TEMPORARY TABLE IF EXISTS workday_raw');
            $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
        }
    }

    /**
     * Crea vacias las tablas temporales de facts() FUERA de una transaccion de solo lectura (MySQL no permite DDL dentro,
     * ni siquiera de tablas temporales; si permite llenarlas). Mismas consultas con 1=0: mismos tipos de columna.
     */
    public static function prepare(Database $db): void
    {
        ActivityCalendar::table($db);
        foreach (['workday_raw', 'workday_facts'] as $t) { $db->run("DROP TEMPORARY TABLE IF EXISTS $t"); }
        $none = str_repeat("\0", 16);
        $db->run('CREATE TEMPORARY TABLE workday_raw ENGINE=InnoDB AS ' . self::rawSelect(' AND 1=0'), [$none, '2000-01-01', '2000-01-02', $none]);
        $db->run('CREATE TEMPORARY TABLE workday_facts ENGINE=InnoDB AS ' . self::FACTS_SELECT);
    }

    /** Por (dia, persona): la jornada es la mayor de sus asignaciones del dia (un cambio de ficha no la duplica). */
    private const FACTS_SELECT = "SELECT g.*,
        IF(g.workday_seconds>0,g.productive_seconds,0) workday_productive_seconds,
        IF(g.workday_seconds>0,GREATEST(0,CAST(g.window_active_seconds AS SIGNED)-CAST(g.productive_seconds AS SIGNED)-CAST(g.unproductive_seconds AS SIGNED)),0) workday_neutral_seconds
        FROM (SELECT day,user_id,COALESCE(MAX(IF(measured,area_id,NULL)),MAX(area_id)) area_id,MAX(measured) measured,
          MAX(workday_seconds) workday_seconds,MAX(expected_seconds) expected_seconds,SUM(active_seconds) active_seconds,SUM(idle_seconds) idle_seconds,
          SUM(productive_seconds) productive_seconds,SUM(window_active_seconds) window_active_seconds,SUM(unproductive_seconds) unproductive_seconds,
          SUM(unclassified_seconds) unclassified_seconds,SUM(simulated_seconds) simulated_seconds,SUM(locked_seconds) locked_seconds,
          SUM(summary_active_seconds) summary_active_seconds,SUM(summary_expected_seconds) summary_expected_seconds,MIN(calculated_at) calculated_at,MIN(data_through) data_through,GROUP_CONCAT(DISTINCT versions) versions,GROUP_CONCAT(DISTINCT focus_version) focus_versions
        FROM workday_raw GROUP BY day,user_id) g";

    /** Primero por asignacion (MariaDB no agrupa bien sobre las subconsultas correlacionadas del calendario). */
    private static function rawSelect(string $where): string
    {
        return 'SELECT c.day,a.user_id,a.area_id,' . ActivityCalendar::workday() . ' workday_seconds,' . ActivityCalendar::expected() . " expected_seconds,
            d.user_id IS NOT NULL OR ed.user_id IS NOT NULL measured,IF(d.user_id IS NULL,0,d.active_seconds) summary_active_seconds,COALESCE(d.expected_seconds,0) summary_expected_seconds,
            COALESCE(d.active_seconds,ed.active_seconds,0) active_seconds,COALESCE(d.idle_seconds,ed.idle_seconds,0) idle_seconds,
            COALESCE(d.productive_seconds,ed.productive_seconds,0) productive_seconds,
            COALESCE(d.window_active_seconds,d.active_seconds,ed.active_seconds,0) window_active_seconds,
            COALESCE(d.unproductive_seconds,f.distraction_seconds,ed.unproductive_seconds,0) unproductive_seconds,
            d.unclassified_seconds,d.simulated_seconds,d.locked_seconds,
            COALESCE(LEAST(d.calculated_at,COALESCE(f.calculated_at,d.calculated_at)),ed.calculated_at) calculated_at,
            COALESCE(LEAST(d.data_through,COALESCE(f.data_through,d.data_through)),ed.data_through) data_through,
            COALESCE(d.calculation_version,ed.versions) versions,f.calculation_version focus_version
            FROM user_assignments a JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id
            JOIN activity_calendar c ON c.schedule_id=COALESCE(a.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=a.tenant_id))
            JOIN retention_settings retention ON retention.tenant_id=a.tenant_id
            LEFT JOIN day_summary d ON d.tenant_id=a.tenant_id AND d.user_assignment_id=a.id AND d.user_id=a.user_id AND d.day=c.day
            LEFT JOIN focus_daily f ON f.tenant_id=a.tenant_id AND f.user_assignment_id=a.id AND f.user_id=a.user_id AND f.day=c.day
            LEFT JOIN (SELECT tenant_id,day,user_id,user_assignment_id,SUM(active_seconds) active_seconds,SUM(idle_seconds) idle_seconds,
                SUM(IF(category='productive',active_seconds,0)) productive_seconds,SUM(IF(category='unproductive',active_seconds,0)) unproductive_seconds,
                MIN(calculated_at) calculated_at,MIN(data_through) data_through,GROUP_CONCAT(DISTINCT calculation_version) versions
              FROM episode_daily WHERE tenant_id=? AND day>=? AND day<? GROUP BY tenant_id,day,user_id,user_assignment_id) ed
              ON ed.tenant_id=a.tenant_id AND ed.user_assignment_id=a.id AND ed.user_id=a.user_id AND ed.day=c.day
            WHERE a.tenant_id=? AND c.day>=DATE_SUB(UTC_DATE(),INTERVAL retention.aggregate_months MONTH)
              -- Dias de la asignacion; y, como antes, los datos ya calculados de la asignacion aunque caigan fuera de su vigencia.
              AND (a.starts_at<c.ends_at AND (a.ends_at IS NULL OR a.ends_at>c.starts_at) OR d.user_id IS NOT NULL OR ed.user_id IS NOT NULL)$where";
    }

    /** Columnas agregadas de workday_facts (alias x) para un SELECT ... GROUP BY. */
    public static function sums(string $x = 'x'): string
    {
        return "SUM($x.active_seconds) active_seconds,SUM($x.idle_seconds) idle_seconds,SUM($x.productive_seconds) productive_seconds,
            SUM($x.window_active_seconds) window_active_seconds,SUM($x.unproductive_seconds) unproductive_seconds,SUM($x.unclassified_seconds) unclassified_seconds,
            SUM($x.simulated_seconds) simulated_seconds,SUM($x.locked_seconds) locked_seconds,SUM($x.expected_seconds) expected_seconds,
            SUM($x.summary_active_seconds) summary_active_seconds,SUM($x.summary_expected_seconds) summary_expected_seconds,
            SUM($x.workday_seconds) workday_expected_seconds,SUM($x.workday_productive_seconds) workday_productive_seconds,SUM($x.workday_neutral_seconds) workday_neutral_seconds,
            MAX($x.measured) measured,MIN($x.calculated_at) calculated_at,MIN($x.data_through) data_through,
            GROUP_CONCAT(DISTINCT $x.versions) versions,GROUP_CONCAT(DISTINCT $x.focus_versions) focus_versions";
    }

    /** Punto de una serie (dia) a partir de una fila agregada de workday_facts. */
    public static function point(array $row, string $day): array
    {
        $jornada = [];
        foreach (self::FIELDS as $field) { $jornada[$field] = (int) $row[$field]; }
        $w = (int) $row['window_active_seconds'];
        return ['day' => $day, 'active_seconds' => (int) $row['active_seconds'], 'idle_seconds' => (int) $row['idle_seconds']] + self::percents($jornada) + $jornada + [
            'unclassified_percent' => $w > 0 && $row['unclassified_seconds'] !== null ? min(100, round(100 * (int) $row['unclassified_seconds'] / $w, 2)) : null,
            'simulated_seconds' => $row['simulated_seconds'] === null ? null : (int) $row['simulated_seconds'],
            'locked_seconds' => $row['locked_seconds'] === null ? null : (int) $row['locked_seconds']];
    }
}
