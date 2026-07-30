<?php
namespace Keeper\Services;

use PDO;

/**
 * Definicion UNICA de foco y productividad para Keeper 4. En Keeper 3 habia cuatro
 * formulas en dos escalas (0-10 y 0-100) que daban numeros distintos en pantallas
 * distintas. Aqui hay una sola, en escala 0-100.
 *
 * REGLA DURA: sin cobertura de ventanas no hay foco. Si window_tracked es falso o no
 * hay episodios, focus_score y productivity_pct son NULL, nunca un numero. En K3 un
 * equipo sin monitorear producia focus 55 y 90% de productividad -el mejor de la
 * flota- porque los subpuntajes sin datos puntuaban 100. Eso ya no puede pasar.
 *
 * El split productivo/ocio sale de keeper_app_classification (que el seed puebla),
 * no de una lista que nunca se sembraba como en K3.
 */
class Metrics
{
    // Pesos por defecto de los componentes del foco. Editables desde el panel
    // (keeper_panel_settings['productivity.focus_weights']) a futuro.
    private const W_SWITCHES = 20;
    private const W_DEEP_WORK = 25;
    private const W_DISTRACTION = 20;
    private const W_PUNCTUALITY = 15;
    private const W_CONSTANCY = 20;

    /**
     * Calcula las metricas de un usuario para un dia. Devuelve un arreglo listo para
     * upsert en keeper_focus_daily. focus_score/productivity_pct pueden ser null.
     */
    public static function computeDay(PDO $pdo, int $userId, string $day): array
    {
        // 1. Cobertura: el resumen del dia dice si se monitorearon ventanas.
        $st = $pdo->prepare("
            SELECT window_tracked, work_active_seconds, first_event_at
            FROM keeper_day_summary WHERE user_id = :u AND day_date = :d LIMIT 1
        ");
        $st->execute([':u' => $userId, ':d' => $day]);
        $summary = $st->fetch();

        $windowTracked = $summary ? (int)$summary['window_tracked'] : 0;

        // 2. Episodios del dia.
        $st = $pdo->prepare("
            SELECT process_name, window_title, duration_seconds, start_at
            FROM keeper_episode WHERE user_id = :u AND day_date = :d ORDER BY start_at
        ");
        $st->execute([':u' => $userId, ':d' => $day]);
        $episodes = $st->fetchAll();

        // SIN COBERTURA -> foco NULL. Se registra la fila con la bandera, sin fabricar.
        if (!$windowTracked || !$episodes) {
            return [
                'user_id' => $userId, 'day_date' => $day, 'window_tracked' => 0,
                'focus_score' => null, 'productivity_pct' => null,
                'deep_work_seconds' => 0, 'distraction_seconds' => 0, 'switch_count' => 0,
            ];
        }

        // 3. Clasificacion de apps (ocio).
        $leisure = self::leisurePatterns($pdo);

        $distraction = 0; $deepBest = 0; $deepRun = 0; $switches = 0; $prevProc = null;
        foreach ($episodes as $ep) {
            $dur = (int)$ep['duration_seconds'];
            $isLeisure = self::isLeisure($ep['process_name'], $ep['window_title'], $leisure);
            if ($isLeisure) {
                $distraction += $dur;
                $deepRun = 0;
            } else {
                $deepRun += $dur;
                if ($deepRun > $deepBest) $deepBest = $deepRun;
            }
            if ($prevProc !== null && $prevProc !== $ep['process_name']) $switches++;
            $prevProc = $ep['process_name'];
        }

        $workTotal = $summary ? (int)$summary['work_active_seconds'] : 0;
        if ($workTotal <= 0) {
            $workTotal = array_sum(array_map(fn($e) => (int)$e['duration_seconds'], $episodes));
        }
        $workTotal = max(1, $workTotal);

        // 4. Productividad = trabajo activo menos ocio, sobre el total.
        $productivityPct = (int)round(min(100, max(0, ($workTotal - $distraction) / $workTotal * 100)));

        // 5. Componentes del foco (mismas curvas que K3).
        $switchScore      = max(0, 100 - ($switches * 100 / 80));
        $deepWorkScore    = min(100, ($deepBest / $workTotal) * 100);
        $distractionPct   = $distraction / $workTotal * 100;
        $distractionScore = max(0, 100 - ($distractionPct * 100 / 30));
        $punctualityScore = self::punctualityScore($pdo, $userId, $summary['first_event_at'] ?? null);
        $constancyScore   = min(100, max(0, $productivityPct)); // proxy razonable de constancia diaria

        $wTotal = self::W_SWITCHES + self::W_DEEP_WORK + self::W_DISTRACTION + self::W_PUNCTUALITY + self::W_CONSTANCY;
        $focus = (int)round((
            $switchScore      * self::W_SWITCHES +
            $deepWorkScore    * self::W_DEEP_WORK +
            $distractionScore * self::W_DISTRACTION +
            $punctualityScore * self::W_PUNCTUALITY +
            $constancyScore   * self::W_CONSTANCY
        ) / $wTotal);
        $focus = min(100, max(0, $focus));

        return [
            'user_id' => $userId, 'day_date' => $day, 'window_tracked' => 1,
            'focus_score' => $focus, 'productivity_pct' => $productivityPct,
            'deep_work_seconds' => $deepBest, 'distraction_seconds' => $distraction, 'switch_count' => $switches,
        ];
    }

    private static function leisurePatterns(PDO $pdo): array
    {
        $st = $pdo->query("SELECT match_type, LOWER(pattern) AS pattern FROM keeper_app_classification WHERE category = 'leisure'");
        $proc = []; $kw = [];
        foreach ($st->fetchAll() as $r) {
            if ($r['match_type'] === 'title_keyword') $kw[] = $r['pattern'];
            else $proc[] = $r['pattern'];
        }
        return ['proc' => $proc, 'kw' => $kw];
    }

    private static function isLeisure(?string $proc, ?string $title, array $leisure): bool
    {
        $p = strtolower((string)$proc);
        foreach ($leisure['proc'] as $lp) { if ($p === $lp) return true; }
        $t = strtolower((string)$title);
        if ($t !== '') foreach ($leisure['kw'] as $k) { if (str_contains($t, $k)) return true; }
        return false;
    }

    /** Puntualidad: primer evento vs inicio de jornada. Neutral (100) si no hay horario. */
    private static function punctualityScore(PDO $pdo, int $userId, ?string $firstEventAt): float
    {
        if (!$firstEventAt) return 100.0;
        $st = $pdo->prepare("
            (SELECT work_start_time FROM keeper_work_schedules WHERE user_id = :u AND is_active = 1 LIMIT 1)
            UNION ALL
            (SELECT work_start_time FROM keeper_work_schedules WHERE user_id IS NULL AND is_active = 1 LIMIT 1)
            LIMIT 1
        ");
        $st->execute([':u' => $userId]);
        $start = $st->fetchColumn();
        if (!$start) return 100.0;

        $firstTs = strtotime($firstEventAt);
        $startTs = strtotime(date('Y-m-d', $firstTs) . ' ' . $start);
        $diffMin = ($startTs - $firstTs) / 60; // positivo = llego temprano
        // Temprano o a tiempo = 100; cada minuto tarde resta, 0 a los -30 min.
        return $diffMin >= 0 ? 100.0 : max(0.0, 100.0 + ($diffMin * 100 / 30));
    }
}
